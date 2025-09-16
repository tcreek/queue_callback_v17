<?php
/**
 * GUI hook page for queue callback management
 * This page is displayed when the "Callback" tab is clicked on a queue configuration page
 */

if (!defined('FREEPBX_IS_AUTH')) { die('No direct script access allowed'); }

$queue_id = $_REQUEST['extdisplay'] ?? '';

if (empty($queue_id)) {
    echo '<div class="alert alert-danger">No queue specified</div>';
    return;
}

// Get current callback configuration
$callback_config = FreePBX::Qcallback()->getQueueCallbackConfig($queue_id);

// Handle form submission
if ($_POST) {
    $enabled = isset($_POST['callback_enabled']) ? 1 : 0;
    $callback_key = $_POST['callback_key'] ?? '*';
    $processing_interval = (int)($_POST['processing_interval'] ?? 5);
    $announce_id = $_POST['announce_id'] ?? null;
    $return_message_id = $_POST['return_message_id'] ?? null;
    
    $new_config = array(
        'enabled' => $enabled,
        'callback_key' => $callback_key,
        'processing_interval' => $processing_interval,
        'announce_id' => $announce_id,
        'return_message_id' => $return_message_id,
        'max_attempts' => $callback_config['max_attempts'] ?? 3,
        'retry_interval' => $callback_config['retry_interval'] ?? 5,
        'confirm_message_id' => null, // Hardcoded
        'callback_started_message_id' => $callback_config['callback_started_message_id'] ?? null,
        'confirm_number' => 1, // Hardcoded
        'alt_number_key' => '2', // Hardcoded
        'call_first' => $callback_config['call_first'] ?? 'customer'
    );
    
    FreePBX::Qcallback()->setQueueCallbackConfig($queue_id, $new_config);
    
    echo '<div class="alert alert-success">Callback configuration saved successfully!</div>';
    
    // Refresh config
    $callback_config = FreePBX::Qcallback()->getQueueCallbackConfig($queue_id);
    
    // Mark for reload
    needreload();
}

$enabled = !empty($callback_config['enabled']);
$callback_key = $callback_config['callback_key'] ?? '*';
$processing_interval = $callback_config['processing_interval'] ?? 5;
$announce_id = $callback_config['announce_id'] ?? '';
$return_message_id = $callback_config['return_message_id'] ?? '';
?>

<div class="container-fluid">
    <h3>Queue Callback Configuration for Queue <?php echo htmlspecialchars($queue_id); ?></h3>
    
    <form method="post" class="fpbx-submit" data-fpbx-delete="config">
        
        <div class="section-title" data-for="callback_basic">
            <h3><i class="fa fa-phone"></i> Basic Callback Settings</h3>
            <hr>
        </div>
        
        <div class="section" data-id="callback_basic">
            
            <div class="form-group">
                <div class="col-md-3">
                    <label class="control-label" for="callback_enabled">Enable Callback</label>
                    <i class="fa fa-question-circle fpbx-help-icon" data-for="callback_enabled"></i>
                </div>
                <div class="col-md-9">
                    <span class="radioset">
                        <input type="radio" name="callback_enabled" id="callback_enabled_yes" value="1" <?php echo $enabled ? 'checked' : ''; ?>>
                        <label for="callback_enabled_yes">Yes</label>
                        <input type="radio" name="callback_enabled" id="callback_enabled_no" value="0" <?php echo !$enabled ? 'checked' : ''; ?>>
                        <label for="callback_enabled_no">No</label>
                    </span>
                </div>
            </div>
            <div class="help-block" id="callback_enabled-help">
                Enable callback functionality for this queue. When enabled, callers can press the callback key to request a callback instead of waiting.
            </div>
            
            <div class="form-group">
                <div class="col-md-3">
                    <label class="control-label" for="callback_key">Callback Key</label>
                    <i class="fa fa-question-circle fpbx-help-icon" data-for="callback_key"></i>
                </div>
                <div class="col-md-9">
                    <input type="text" class="form-control" id="callback_key" name="callback_key" value="<?php echo htmlspecialchars($callback_key); ?>" maxlength="1">
                </div>
            </div>
            <div class="help-block" id="callback_key-help">
                The key callers press to request a callback (default: *). Must be a single digit or *.
            </div>
            
            <div class="form-group">
                <div class="col-md-3">
                    <label class="control-label" for="processing_interval">Processing Interval</label>
                    <i class="fa fa-question-circle fpbx-help-icon" data-for="processing_interval"></i>
                </div>
                <div class="col-md-9">
                    <input type="number" class="form-control" id="processing_interval" name="processing_interval" value="<?php echo $processing_interval; ?>" min="1" max="60">
                </div>
            </div>
            <div class="help-block" id="processing_interval-help">
                How often (in minutes) to process pending callback requests.
            </div>
            
        </div>
        
        <div class="section-title" data-for="callback_messages">
            <h3><i class="fa fa-volume-up"></i> Message Settings</h3>
            <hr>
        </div>
        
        <div class="section" data-id="callback_messages">
            
            <div class="form-group">
                <div class="col-md-3">
                    <label class="control-label" for="announce_id">Callback Announcement</label>
                    <i class="fa fa-question-circle fpbx-help-icon" data-for="announce_id"></i>
                </div>
                <div class="col-md-9">
                    <input type="text" class="form-control" id="announce_id" name="announce_id" value="<?php echo htmlspecialchars($announce_id); ?>">
                </div>
            </div>
            <div class="help-block" id="announce_id-help">
                Optional custom announcement to play before offering callback option (e.g., "custom/callback-intro").
            </div>
            
            <div class="form-group">
                <div class="col-md-3">
                    <label class="control-label" for="return_message_id">Return Message</label>
                    <i class="fa fa-question-circle fpbx-help-icon" data-for="return_message_id"></i>
                </div>
                <div class="col-md-9">
                    <input type="text" class="form-control" id="return_message_id" name="return_message_id" value="<?php echo htmlspecialchars($return_message_id); ?>">
                </div>
            </div>
            <div class="help-block" id="return_message_id-help">
                Optional custom message to play when calling back (e.g., "custom/callback-return").
            </div>
            
        </div>
        
    </form>
    
    <?php if ($enabled): ?>
    <div class="section-title">
        <h3><i class="fa fa-list"></i> Pending Callbacks</h3>
        <hr>
    </div>
    
    <div class="section">
        <?php
        // Helper function to get pending callbacks
        if (!function_exists('queuecallback_get_pending_requests')) {
            function queuecallback_get_pending_requests($queue_id = '') {
                try {
                    return FreePBX::Qcallback()->getPendingCallbackRequests($queue_id);
                } catch (Exception $e) {
                    error_log("queuecallback_get_pending_requests error: " . $e->getMessage());
                    return [];
                }
            }
        }
        
        // Get pending callbacks for this queue
        $pending_callbacks = queuecallback_get_pending_requests($queue_id);
        
        if (!empty($pending_callbacks)): ?>
            <table class="table table-striped">
                <thead>
                    <tr>
                        <th>Caller Number</th>
                        <th>Requested</th>
                        <th>Status</th>
                        <th>Attempts</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pending_callbacks as $callback): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($callback['callback_number']); ?></td>
                        <td><?php echo date('Y-m-d H:i:s', $callback['time_requested']); ?></td>
                        <td>
                            <span class="label label-<?php echo $callback['status'] == 'pending' ? 'warning' : 'info'; ?>">
                                <?php echo ucfirst($callback['status']); ?>
                            </span>
                        </td>
                        <td><?php echo $callback['attempts']; ?>/<?php echo $callback['max_attempts']; ?></td>
                        <td>
                            <button class="btn btn-sm btn-success" onclick="processCallback(<?php echo $callback['id']; ?>)">
                                <i class="fa fa-play"></i> Process
                            </button>
                            <button class="btn btn-sm btn-danger" onclick="cancelCallback(<?php echo $callback['id']; ?>)">
                                <i class="fa fa-times"></i> Cancel
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <div class="alert alert-info">No pending callbacks for this queue.</div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    
</div>

<script>
function processCallback(callbackId) {
    if (confirm('Process this callback now?')) {
        $.post('?display=qcallback', {
            action: 'process_callback',
            callback_id: callbackId
        }, function(data) {
            if (data.status === 'success') {
                location.reload();
            } else {
                alert('Error processing callback');
            }
        }, 'json');
    }
}

function cancelCallback(callbackId) {
    if (confirm('Cancel this callback request?')) {
        $.post('?display=qcallback', {
            action: 'cancel_callback',
            callback_id: callbackId
        }, function(data) {
            if (data.status === 'success') {
                location.reload();
            } else {
                alert('Error cancelling callback');
            }
        }, 'json');
    }
}
</script>