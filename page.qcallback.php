<?php
if (!defined('FREEPBX_IS_AUTH')) { die('No direct script access allowed'); }

$request = $_REQUEST;
$dispnum = 'qcallback';

// Handle AJAX requests first
if (isset($_REQUEST['action'])) {
    $action = $_REQUEST['action'];
    $queue_id = $_REQUEST['queue_id'] ?? '';
    
    switch ($action) {
        case 'add_callback':
            if ($queue_id) {
                try {
                    // Enable callback with default settings
                    $default_config = array(
                        'enabled' => 1,
                        'announce_id' => null,
                        'callback_key' => '*',
                        'processing_interval' => 5
                    );
                    
                    FreePBX::Qcallback()->setQueueCallbackConfig($queue_id, $default_config);
                    
                    // Trigger dialplan reload
                    needReload();
                    
                    header('Content-Type: application/json');
                    echo json_encode(['status' => 'success', 'message' => 'Callback enabled for queue ' . $queue_id]);
                    exit;
                } catch (Exception $e) {
                    header('Content-Type: application/json');
                    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
                    exit;
                }
            }
            break;
            
        case 'remove_callback':
            if ($queue_id) {
                try {
                    // Delete callback configuration (this also cancels pending callbacks)
                    FreePBX::Qcallback()->deleteQueueCallbackConfig($queue_id);
                    
                    header('Content-Type: application/json');
                    echo json_encode(['status' => 'success', 'message' => 'Callback removed from queue ' . $queue_id]);
                    exit;
                } catch (Exception $e) {
                    header('Content-Type: application/json');
                    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
                    exit;
                }
            }
            break;
            
        case 'add_request':
            // Handle callback request from dialplan
            $callback_number = $_REQUEST['callback_number'] ?? '';
            $caller_id = $_REQUEST['caller_id'] ?? '';
            $caller_name = $_REQUEST['caller_name'] ?? '';
            $uniqueid = $_REQUEST['uniqueid'] ?? '';
            
            if ($queue_id && $callback_number) {
                try {
                    // Clean up callback number
                    $callback_number = preg_replace('/[^0-9]/', '', $callback_number);
                    
                    if (strlen($callback_number) < 7) {
                        throw new Exception("Invalid callback number");
                    }
                    
                    // Check if callback is enabled for this queue
                    $callback_config = FreePBX::Qcallback()->getQueueCallbackConfig($queue_id);
                    
                    if (empty($callback_config['enabled'])) {
                        throw new Exception("Callback not enabled for queue $queue_id");
                    }
                    
                    // Insert callback request
                    $db = FreePBX::Database();
                    $sql = "INSERT INTO queuecallback_requests 
                            (queue_id, caller_id, callback_number, status, time_requested, uniqueid, position) 
                            VALUES (?, ?, ?, 'pending', ?, ?, ?)";
                    
                    $stmt = $db->prepare($sql);
                    $stmt->execute(array(
                        $queue_id,
                        $caller_name . ' <' . $caller_id . '>',
                        $callback_number,
                        time(),
                        $uniqueid,
                        0
                    ));
                    
                    $callback_id = $db->lastInsertId();
                    
                    // Log the callback request
                    freepbx_log(FPBX_LOG_INFO, "Queue Callback: Request $callback_id created for queue $queue_id, number $callback_number");
                    
                    echo "SUCCESS: Callback request created with ID $callback_id";
                    exit;
                    
                } catch (Exception $e) {
                    freepbx_log(FPBX_LOG_ERROR, "Queue Callback: Failed to create request: " . $e->getMessage());
                    echo "ERROR: " . $e->getMessage();
                    exit;
                }
            }
            break;
            
        case 'save_config':
            // Handle configuration save from standalone config page
            if ($queue_id) {
                try {
                    $new_config = array(
                        'enabled' => $_REQUEST['callback_enabled'] ?? 0,
                        'announce_id' => $_REQUEST['callback_announce_id'] ?: null,
                        'callback_key' => $_REQUEST['callback_key'] ?: '*',
                        'processing_interval' => (int)($_REQUEST['callback_processing_interval'] ?: 5),
                        'max_attempts' => (int)($_REQUEST['callback_max_attempts'] ?: 3),
                        'retry_interval' => (int)($_REQUEST['callback_retry_interval'] ?: 5),
                        'return_message_id' => $_REQUEST['callback_return_message_id'] ?: null,
                        'confirm_message_id' => null, // Hardcoded to use custom/confirm_number.wav
                        'callback_started_message_id' => $_REQUEST['callback_started_message_id'] ?: null,
                        'confirm_number' => 1, // Always enabled (hardcoded)
                        'alt_number_key' => '2', // Hardcoded to key 2 for different number
                        'call_first' => $_REQUEST['callback_call_first'] ?: 'customer'
                    );
                    
                    FreePBX::Qcallback()->setQueueCallbackConfig($queue_id, $new_config);
                    needReload();
                    
                    // Redirect back to config page with success message
                    header("Location: ?display=qcallback&view=config&queue_id=" . urlencode($queue_id) . "&saved=1");
                    exit;
                    
                } catch (Exception $e) {
                    // Redirect back with error
                    header("Location: ?display=qcallback&view=config&queue_id=" . urlencode($queue_id) . "&error=" . urlencode($e->getMessage()));
                    exit;
                }
            }
            break;
            
        case 'export_csv':
            if ($queue_id) {
                try {
                    // Get all callbacks for this queue (not just pending)
                    $db = FreePBX::Database();
                    $sql = "SELECT id, caller_id, callback_number, status, time_requested, time_processed, attempts, max_attempts, uniqueid 
                            FROM queuecallback_requests 
                            WHERE queue_id = ? 
                            ORDER BY time_requested DESC";
                    $stmt = $db->prepare($sql);
                    $stmt->execute(array($queue_id));
                    $callbacks = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    // Create CSV content
                    $csv_content = "ID,Caller ID,Callback Number,Status,Time Requested,Time Processed,Attempts,Max Attempts,Unique ID\n";
                    
                    foreach ($callbacks as $callback) {
                        $csv_content .= sprintf(
                            "%d,\"%s\",\"%s\",\"%s\",\"%s\",\"%s\",%d,%d,\"%s\"\n",
                            $callback['id'],
                            str_replace('"', '""', $callback['caller_id']),
                            str_replace('"', '""', $callback['callback_number']),
                            $callback['status'],
                            date('Y-m-d H:i:s', $callback['time_requested']),
                            $callback['time_processed'] ? date('Y-m-d H:i:s', $callback['time_processed']) : '',
                            $callback['attempts'],
                            $callback['max_attempts'],
                            str_replace('"', '""', $callback['uniqueid'])
                        );
                    }
                    
                    // Create CSV directory if it doesn't exist
                    $csv_dir = '/var/www/html/admin/modules/qcallback/csv';
                    if (!is_dir($csv_dir)) {
                        mkdir($csv_dir, 0755, true);
                        chown($csv_dir, 'asterisk');
                        chgrp($csv_dir, 'asterisk');
                    }
                    
                    // Generate filename
                    $filename = 'queue_' . $queue_id . '_callbacks_' . date('Y-m-d_H-i-s') . '.csv';
                    $filepath = $csv_dir . '/' . $filename;
                    
                    // Write CSV file
                    file_put_contents($filepath, $csv_content);
                    chmod($filepath, 0644);
                    
                    // Send file to browser
                    header('Content-Type: text/csv');
                    header('Content-Disposition: attachment; filename="' . $filename . '"');
                    header('Content-Length: ' . strlen($csv_content));
                    echo $csv_content;
                    exit;
                    
                } catch (Exception $e) {
                    header('Content-Type: text/plain');
                    echo "Error exporting CSV: " . $e->getMessage();
                    exit;
                }
            }
            break;
    }
    
    // If we get here, something went wrong
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'Invalid request']);
    exit;
}

$heading = _("Queue Callback Management");

$view = isset($request['view']) ? $request['view'] : '';
$queue_id = isset($request['queue_id']) ? $request['queue_id'] : '';

switch($view) {
    case "queue":
        if (!empty($queue_id)) {
            $heading .= " - " . _("Queue") . " " . $queue_id;
            $content = load_view(__DIR__.'/views/queue_callbacks.php', array(
                'queue_id' => $queue_id,
                'request' => $request
            ));
        } else {
            $content = '<div class="alert alert-danger">' . _("Queue ID required") . '</div>';
        }
        break;
        
    case "config":
        if (!empty($queue_id)) {
            $heading .= " - " . _("Configure Queue") . " " . $queue_id;
            $content = load_view(__DIR__.'/views/callback_config_standalone.php', array(
                'queue_id' => $queue_id,
                'request' => $request
            ));
        } else {
            $content = '<div class="alert alert-danger">' . _("Queue ID required") . '</div>';
        }
        break;
        
    default:
        $content = load_view(__DIR__.'/views/callback_overview.php', array(
            'request' => $request
        ));
        break;
}

?>
<div class="container-fluid">
    <h1><?php echo $heading ?></h1>
    <div class="row">
        <div class="col-sm-12">
            <div class="fpbx-container">
                <div class="display no-border">
                    <?php echo $content ?>
                </div>
            </div>
        </div>
    </div>
</div>