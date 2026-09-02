<?php
/**
 * Queue Callback Module for FreePBX
 *
 * Copyright (C) 2026 Trent Creekmore 
 * trent@netservisity.com
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */
if (!defined('FREEPBX_IS_AUTH')) { die('No direct script access allowed'); }

/**
 * Helper to distinguish web page views from CLI-driven reloads
 */
function qcb_is_reload_context(): bool {
    return (PHP_SAPI === 'cli');
}

/**
 * Check if we're in an uninstall context to avoid errors
 */
function qcb_is_uninstall_context(): bool {
    return defined('FREEPBX_UNINSTALL') || (isset($_REQUEST['action']) && $_REQUEST['action'] === 'uninstall');
}

/**
 * Hook into our own module configuration page
 */
function queuecallback_configpageinit($pagename) {
    global $currentcomponent;
    
    if ($pagename === 'qcallback' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $currentcomponent->addprocessfunc('queuecallback_process_config');
    }
}

/**
 * Hook called during dialplan generation - this should force our dialplan to be included
 */
function queuecallback_hook_core($viewing_itemid, $target_menuid) {
    // Disabled - was causing reloads on every page visit
    // qcallback_get_config('asterisk');
    return array();
}

/**
 * Alternative hook - try to get called during dialplan reload
 */
function queuecallback_additional_destinations() {
    // Disabled - was causing reloads on every page visit
    // qcallback_get_config('asterisk');
    return array();
}

/**
 * Force dialplan generation - called when module is loaded
 */
function queuecallback_hook_get_config($engine) {
    // Disabled - was causing reloads on every page visit
    // qcallback_get_config($engine);
}

/**
 * Hook function following FreePBX convention: qcallback_hook_queues()
 * This is called by the queues module when it's processing
 */
function qcallback_hook_queues($viewing_itemid, $target_menuid) {
    // We don't add GUI elements to individual queue pages
    // Users manage callbacks via the main "Queue Callback" menu under Applications
    return array();
}

/**
 * Hook function that might be called by queues module during dialplan generation
 * Following convention: queues would call qcallback_hook_queues() for GUI
 * But for dialplan, we need to hook into the queues dialplan generation process
 */
function qcallback_hook_queues_dialplan($queue_id) {
    // Skip during uninstall to avoid rawname errors
    if (qcb_is_uninstall_context()) { return array(); }
    
    // Only provide dialplan mods during actual reload
    if (!qcb_is_reload_context()) { return array(); }
    
    // Check if callback is enabled for this queue
    $callback_config = FreePBX::Qcallback()->getQueueCallbackConfig($queue_id);
    
    if (empty($callback_config['enabled'])) {
        return array();
    }
    
    $callback_key = $callback_config['callback_key'] ?: '*';
    
    // Return dialplan modifications for this queue
    return array(
        'gosub' => 'queuecallback-handler,s,1(' . $callback_key . ')'
    );
}

/**
 * Legacy hook function - keeping for compatibility
 */
function queuecallback_hook_queues_pre($queue_id) {
    // This might not be called by FreePBX, but keeping for compatibility
    return array();
}

/**
 * Create custom dialplan for callback-enabled queues
 * This will override the default queue behavior
 */
function queuecallback_create_queue_dialplan() {
    if (!qcb_is_reload_context() || qcb_is_uninstall_context()) { return; }
    // Get all callback-enabled queues
    $enabled_queues = FreePBX::Qcallback()->getCallbackEnabledQueues();
    
    if (empty($enabled_queues)) {
        return;
    }
    
    // Create custom dialplan file for callback queues
    $dialplan_content = "; Queue Callback Custom Dialplan\n";
    $dialplan_content .= "; This file modifies queues to include callback functionality\n\n";
    
    $dialplan_content .= "[ext-queues-custom]\n";
    
    foreach ($enabled_queues as $queue) {
        $queue_id = $queue['queue_id'];
        $callback_key = $queue['callback_key'] ?: '*';
        
        $dialplan_content .= "; Callback-enabled queue {$queue_id}\n";
        $dialplan_content .= "exten => {$queue_id},1,Gosub(macro-user-callerid,s,1())\n";
        $dialplan_content .= "exten => {$queue_id},n,Answer()\n";
        $dialplan_content .= "exten => {$queue_id},n,Set(__FROMQUEUEEXTEN=\${CALLERID(number)})\n";
        $dialplan_content .= "exten => {$queue_id},n,Set(QUEUECALLBACK_QUEUE={$queue_id})\n";
        $dialplan_content .= "exten => {$queue_id},n,Set(QUEUECALLBACK_KEY={$callback_key})\n";
        
        // Play initial announcement if configured
        if (!empty($queue['announce_id'])) {
            $dialplan_content .= "exten => {$queue_id},n,Playback(custom/{$queue['announce_id']})\n";
        }
        
        // Queue with callback option - proper gosub syntax
        $dialplan_content .= "exten => {$queue_id},n,Queue({$queue_id},tc,,,,,,,,,queuecallback-handler-fixed,s,1({$callback_key}))\n";
        $dialplan_content .= "exten => {$queue_id},n,Hangup()\n\n";
    }
    
    // Write to extensions_custom.conf
    $custom_file = '/etc/asterisk/extensions_custom.conf';
    
    // Read existing content
    $existing_content = '';
    if (file_exists($custom_file)) {
        $existing_content = file_get_contents($custom_file);
    }
    
    // Remove existing ext-queues-custom section if it exists
    if (strpos($existing_content, '[ext-queues-custom]') !== false) {
        $start = strpos($existing_content, '[ext-queues-custom]');
        $end = strpos($existing_content, "\n[", $start + 1);
        if ($end === false) {
            $end = strlen($existing_content);
        }
        $existing_content = substr($existing_content, 0, $start) . substr($existing_content, $end);
    }
    
    // Append our updated dialplan
    file_put_contents($custom_file, $existing_content . "\n" . $dialplan_content, LOCK_EX);
    
    // Dialplan reload removed - only reload when actually needed
}









/**
 * Process our own module configuration
 */
function queuecallback_process_config() {
    $action = $_REQUEST['action'] ?? '';
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
                        'processing_interval' => 30
                    );
                    
                    FreePBX::Qcallback()->setQueueCallbackConfig($queue_id, $default_config);
                    
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
                    // Disable callback
                    $config = FreePBX::Qcallback()->getQueueCallbackConfig($queue_id);
                    $config['enabled'] = 0;
                    
                    FreePBX::Qcallback()->setQueueCallbackConfig($queue_id, $config);
                    
                    // Cancel any pending callbacks
                    queuecallback_cancel_queue_callbacks($queue_id);
                    
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
            
        case 'get_pending_callbacks':
            if ($queue_id) {
                $callbacks = queuecallback_get_pending_requests($queue_id);
                header('Content-Type: application/json');
                echo json_encode(['status' => 'success', 'callbacks' => $callbacks]);
                exit;
            }
            break;
            
        case 'process_callback':
            $callback_id = $_REQUEST['callback_id'] ?? '';
            if ($callback_id) {
                $result = queuecallback_process_request($callback_id);
                header('Content-Type: application/json');
                echo json_encode(['status' => $result ? 'success' : 'error']);
                exit;
            }
            break;
            
        case 'cancel_callback':
            $callback_id = $_REQUEST['callback_id'] ?? '';
            if ($callback_id) {
                $result = queuecallback_cancel_request($callback_id);
                header('Content-Type: application/json');
                echo json_encode(['status' => $result ? 'success' : 'error']);
                exit;
            }
            break;
    }
}

/**
 * Hook to add callback fields to the queues configuration form
 */
function queuecallback_hook_queues_form($queue_data) {
    if (qcb_is_uninstall_context()) { return ''; }
    $queue_id = $queue_data['extension'] ?? '';
    $callback_config = FreePBX::Qcallback()->getQueueCallbackConfig($queue_id);
    
    // Generate callback configuration HTML
    ob_start();
    include(__DIR__ . '/views/callback_config.php');
    $callback_html = ob_get_clean();
    
    return $callback_html;
}

/**
 * Get pending callback requests for a queue
 */
function queuecallback_get_pending_requests($queue_id) {
    $db = FreePBX::Database();
    
    $sql = "SELECT * FROM queuecallback_requests 
            WHERE queue_id = ? AND status IN ('pending', 'processing') 
            ORDER BY time_requested ASC";
    
    $stmt = $db->prepare($sql);
    $stmt->execute(array($queue_id));
    return $stmt->fetchAll(\PDO::FETCH_ASSOC);
}

/**
 * Process a callback request
 */
function queuecallback_process_request($callback_id) {
    $db = FreePBX::Database();
    
    // Get callback details
    $sql = "SELECT * FROM queuecallback_requests WHERE id = ?";
    $stmt = $db->prepare($sql);
    $stmt->execute(array($callback_id));
    $callback = $stmt->fetch(\PDO::FETCH_ASSOC);
    
    if (empty($callback)) {
        return false;
    }
    
    // Update status to processing
    $sql = "UPDATE queuecallback_requests 
            SET status = 'processing', attempts = attempts + 1, last_attempt = ? 
            WHERE id = ?";
    $stmt = $db->prepare($sql);
    $stmt->execute(array(time(), $callback_id));
    
    // Create call file
    return queuecallback_create_call_file($callback);
}

/**
 * Cancel a callback request
 */
function queuecallback_cancel_request($callback_id) {
    $db = FreePBX::Database();
    
    $sql = "UPDATE queuecallback_requests SET status = 'cancelled' WHERE id = ?";
    $stmt = $db->prepare($sql);
    return $stmt->execute(array($callback_id));
}

/**
 * Cancel all pending callbacks for a queue
 */
function queuecallback_cancel_queue_callbacks($queue_id) {
    $db = FreePBX::Database();
    
    $sql = "UPDATE queuecallback_requests SET status = 'cancelled', time_processed = ? 
            WHERE queue_id = ? AND status IN ('pending', 'processing')";
    $stmt = $db->prepare($sql);
    return $stmt->execute(array(time(), $queue_id));
}

/**
 * Delete callback configuration for a queue
 */
function queuecallback_delete_config($queue_id) {
    $db = FreePBX::Database();
    
    try {
        // Start transaction for data consistency
        $db->query("START TRANSACTION");
        
        // Get current configuration before deletion for logging
        $sql = "SELECT * FROM queuecallback_config WHERE queue_id = ?";
        $stmt = $db->prepare($sql);
        $stmt->execute(array($queue_id));
        $existing_config = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        // Delete callback configuration
        $sql = "DELETE FROM queuecallback_config WHERE queue_id = ?";
        $stmt = $db->prepare($sql);
        $stmt->execute(array($queue_id));
        
        // Cancel any pending callbacks
        $sql = "UPDATE queuecallback_requests SET status = 'cancelled', time_processed = ? 
                WHERE queue_id = ? AND status IN ('pending', 'processing')";
        $stmt = $db->prepare($sql);
        $stmt->execute(array(time(), $queue_id));
        
        // Get count of cancelled callbacks for logging
        $sql = "SELECT COUNT(*) FROM queuecallback_requests 
                WHERE queue_id = ? AND status = 'cancelled' 
                AND time_processed = ?";
        $stmt = $db->prepare($sql);
        $stmt->execute(array($queue_id, time()));
        $cancelled_count = $stmt->fetchColumn();
        
        // Commit transaction
        $db->query("COMMIT");
        
        // Log the deletion
        if ($existing_config) {
            freepbx_log(FPBX_LOG_INFO, "Queue Callback: Deleted configuration for queue $queue_id, cancelled $cancelled_count pending callbacks");
        }
        
        // Recreate database events if needed (in case this was the only queue with shortest interval)
        queuecallback_recreate_events();
        
        return true;
        
    } catch (Exception $e) {
        // Rollback on error
        $db->query("ROLLBACK");
        freepbx_log(FPBX_LOG_ERROR, "Queue Callback: Failed to delete configuration for queue $queue_id: " . $e->getMessage());
        return false;
    }
}

/**
 * Create a call file for callback
 */
function queuecallback_create_call_file($callback) {
    // Get callback configuration to get return message
    $callback_config = FreePBX::Qcallback()->getQueueCallbackConfig($callback['queue_id']);
    $return_message = $callback_config['return_message_id'] ?? '';
    
    $call_file_content = "Channel: Local/{$callback['callback_number']}@from-internal\n";
    $call_file_content .= "CallerID: Queue Callback <{$callback['queue_id']}>\n";
    $call_file_content .= "MaxRetries: 2\n";
    $call_file_content .= "RetryTime: 60\n";
    $call_file_content .= "WaitTime: 30\n";
    $call_file_content .= "Context: queuecallback-outbound\n";
    $call_file_content .= "Extension: {$callback['callback_number']}\n";
    $call_file_content .= "Priority: 1\n";
    $call_file_content .= "Archive: yes\n";
    $call_file_content .= "SetVar: __CALLBACK_ID={$callback['id']}\n";
    $call_file_content .= "SetVar: __CALLBACK_QUEUE_ID={$callback['queue_id']}\n";
    $call_file_content .= "SetVar: __CALLBACK_RETURN_MSG={$return_message}\n";
    
    $call_file = "/tmp/queuecallback_{$callback['id']}.call";
    
    if (file_put_contents($call_file, $call_file_content)) {
        chmod($call_file, 0777);
        if (rename($call_file, "/var/spool/asterisk/outgoing/" . basename($call_file))) {
            return true;
        }
    }
    
    return false;
}

/**
 * Process pending callbacks (called by maintenance hook)
 */
function queuecallback_process_pending() {
    $db = FreePBX::Database();
    
    try {
        // Check trigger table
        $sql = "SELECT last_run FROM queuecallback_trigger WHERE id = 1";
        $stmt = $db->prepare($sql);
        $stmt->execute();
        $trigger = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        if (!$trigger) {
            return; // No trigger set
        }
        
        $last_trigger = $trigger['last_run'];
        $last_processed_file = '/tmp/queuecallback_last_processed';
        
        $last_processed = 0;
        if (file_exists($last_processed_file)) {
            $last_processed = (int)file_get_contents($last_processed_file);
        }
        
        // Only process if trigger is newer
        if ($last_trigger <= $last_processed) {
            return;
        }
        
        // Get pending callbacks
        $sql = "SELECT * FROM queuecallback_requests 
                WHERE status = 'pending' 
                AND (last_attempt IS NULL OR last_attempt < ?) 
                AND attempts < max_attempts 
                LIMIT 10";
        
        $stmt = $db->prepare($sql);
        $stmt->execute(array(time() - 300));
        $callbacks = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        
        foreach ($callbacks as $callback) {
            queuecallback_process_request($callback['id']);
        }
        
        // Update last processed time
        file_put_contents($last_processed_file, $last_trigger);
        
    } catch (Exception $e) {
        error_log("Queue Callback processing error: " . $e->getMessage());
    }
}

/**
 * Hook into FreePBX maintenance
 */
function queuecallback_hook_maintenance() {
    if (!qcb_is_reload_context() || qcb_is_uninstall_context()) { return; }
    queuecallback_process_pending();
}

/**
 * Generate dialplan for callback functionality
 * This function is called by FreePBX during dialplan generation
 */
function qcallback_get_config($engine) {
    // Skip during uninstall to avoid rawname errors
    if (qcb_is_uninstall_context()) { return; }
    
    // Do nothing unless this is the real reload run
    if (!qcb_is_reload_context()) { return; }
    
    if ($engine !== 'asterisk') { return; }
    
    global $ext;
    
    switch($engine) {
        case "asterisk":
            // Get all callback-enabled queues from database
            $enabled_queues = queuecallback_get_enabled_queues();
            
            if (!empty($enabled_queues)) {
                // Create individual DTMF handler contexts for each queue
                foreach ($enabled_queues as $queue) {
                    $queue_id = $queue['queue_id'];
                    $callback_key = $queue['callback_key'] ?: '*';
                    
                    // Create queue-specific callback handler context
                    $context = 'qcb-handler-' . $queue_id;
                    
                    // Handler for s extension (called by gosub from Queue)
                    $ext->add($context, 's', '', new ext_noop('DTMF handler for queue ' . $queue_id));
                    $ext->add($context, 's', '', new ext_noop('Key pressed: ${EXTEN}'));
                    $ext->add($context, 's', '', new ext_gotoif('$["${EXTEN}" != "' . $callback_key . '"]', 'ignore'));
                    $ext->add($context, 's', '', new ext_noop('Callback key ' . $callback_key . ' pressed - processing'));
                    $ext->add($context, 's', '', new ext_set('CALLBACK_NUMBER', '${CALLERID(number)}'));
                    $ext->add($context, 's', '', new ext_set('CALLBACK_QUEUE', $queue_id));
                    $ext->add($context, 's', '', new ext_set('QUEUENAME', $queue_id));
                    $ext->add($context, 's', '', new ext_agi('queuecallback-store.agi'));
                    $ext->add($context, 's', '', new ext_playback('thank-you'));
                    $ext->add($context, 's', '', new ext_playback('your-call-will-be-returned'));
                    $ext->add($context, 's', '', new ext_hangup());
                    $ext->add($context, 's', 'ignore', new ext_noop('Non-callback key ignored'));
                    $ext->add($context, 's', '', new ext_return());
                    
                    // Direct handler for the callback key
                    $ext->add($context, $callback_key, '', new ext_noop('Direct callback key ' . $callback_key . ' pressed'));
                    $ext->add($context, $callback_key, '', new ext_set('CALLBACK_NUMBER', '${CALLERID(number)}'));
                    $ext->add($context, $callback_key, '', new ext_set('CALLBACK_QUEUE', $queue_id));
                    $ext->add($context, $callback_key, '', new ext_set('QUEUENAME', $queue_id));
                    $ext->add($context, $callback_key, '', new ext_agi('queuecallback-store.agi'));
                    $ext->add($context, $callback_key, '', new ext_playback('thank-you'));
                    $ext->add($context, $callback_key, '', new ext_playback('your-call-will-be-returned'));
                    $ext->add($context, $callback_key, '', new ext_hangup());
                }
            }
            
            // Create outbound callback context
            $context = 'queuecallback-outbound';
            
            // Handle outbound callback calls
            $ext->add($context, '_X.', '', new ext_noop('Processing callback to ${EXTEN}'));
            $ext->add($context, '_X.', '', new ext_set('CALLERID(name)', 'Queue Callback'));
            $ext->add($context, '_X.', '', new ext_answer());
            $ext->add($context, '_X.', '', new ext_wait('1'));
            
            // Play return message if configured
            $ext->add($context, '_X.', '', new ext_gotoif('$["${CALLBACK_RETURN_MSG}" != ""]', 'custom_msg'));
            
            // Default messages
            $ext->add($context, '_X.', '', new ext_playback('thank-you-for-calling'));
            $ext->add($context, '_X.', '', new ext_playback('pls-hold-while-try'));
            $ext->add($context, '_X.', '', new ext_goto('connect_queue'));
            
            // Custom message
            $ext->add($context, '_X.', 'custom_msg', new ext_playback('custom/${CALLBACK_RETURN_MSG}'));
            
            // Connect to queue - use the queue ID from callback data
            $ext->add($context, '_X.', 'connect_queue', new ext_goto('ext-queues,${CALLBACK_QUEUE_ID},1'));
            
            // DISABLED - Use extensions_custom.conf approach only
            // The FreePBX dialplan generation has syntax issues
            
            // Dialplan write removed - only write when config changes
            
            break;
    }
}

/**
 * Get callback-enabled queues from database
 */
function queuecallback_get_enabled_queues() {
    try {
        $db = FreePBX::Database();
        // Simple query without join to avoid collation issues
        $sql = "SELECT queue_id, callback_key, announce_id, processing_interval
                FROM queuecallback_config
                WHERE enabled = 1";
        $stmt = $db->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        freepbx_log(FPBX_LOG_ERROR, "Queue Callback: Error getting enabled queues: " . $e->getMessage());
        return array();
    }
}

/**
 * Write callback dialplan to extensions_custom.conf
 * This ensures the dialplan persists and takes precedence over FreePBX generated dialplan
 * UPDATED: Now properly implements IN-QUEUE callback handling with gosub
 */
function queuecallback_write_custom_dialplan() {
    if (!qcb_is_reload_context() || qcb_is_uninstall_context()) { return; }
    freepbx_log(FPBX_LOG_INFO, "Queue Callback: Starting IN-QUEUE custom dialplan write");
    
    $enabled_queues = queuecallback_get_enabled_queues();
    
    freepbx_log(FPBX_LOG_INFO, "Queue Callback: Found " . count($enabled_queues) . " enabled queues");
    
    if (empty($enabled_queues)) {
        freepbx_log(FPBX_LOG_INFO, "Queue Callback: No enabled queues, skipping dialplan write");
        return;
    }
    
    $custom_file = '/etc/asterisk/extensions_custom.conf';
    
    // Read existing content
    $existing_content = '';
    if (file_exists($custom_file)) {
        $existing_content = file_get_contents($custom_file);
    }
    
    // Remove existing callback queue sections
    $existing_content = preg_replace('/; Queue Callback Module - Start.*?; Queue Callback Module - End\n/s', '', $existing_content);
    
    // Build new IN-QUEUE callback dialplan
    $callback_dialplan = "\n; Queue Callback Module - Start\n";
    $callback_dialplan .= "; IN-QUEUE callback handling - callbacks work WHILE in queue\n";
    $callback_dialplan .= "; Generated automatically - do not edit manually\n\n";
    
    $callback_dialplan .= "[from-internal-custom]\n";
    
    foreach ($enabled_queues as $queue) {
        $queue_id = $queue['queue_id'];
        $callback_key = $queue['callback_key'] ?: '*';
        $announce_id = $queue['announce_id'];
        
        $callback_dialplan .= "; IN-QUEUE Callback-enabled queue $queue_id (key: $callback_key)\n";
        $callback_dialplan .= "exten => $queue_id,1,NoOp(Entering IN-QUEUE callback queue $queue_id)\n";
        $callback_dialplan .= " same => n,Gosub(macro-user-callerid,s,1())\n";
        $callback_dialplan .= " same => n,Answer()\n";
        $callback_dialplan .= " same => n,Wait(1)\n";
        $callback_dialplan .= " same => n,Set(__FROMQUEUEEXTEN=\${CALLERID(number)})\n";
        $callback_dialplan .= " same => n,Set(__QUEUENAME=$queue_id)\n";
        $callback_dialplan .= " same => n,Set(__CALLBACK_QUEUE=$queue_id)\n";
        $callback_dialplan .= " same => n,Set(__CALLBACK_KEY=$callback_key)\n";
        
        // Add announcement if configured
        if ($announce_id) {
            $callback_dialplan .= " same => n,Playback(custom/$announce_id)\n";
        } else {
            // Default announcement about callback option
            $callback_dialplan .= " same => n,Playback(to-request-callback)\n";
            $callback_dialplan .= " same => n,Playback(press)\n";
            if ($callback_key == '*') {
                $callback_dialplan .= " same => n,Playback(digits/star)\n";
            } elseif ($callback_key == '#') {
                $callback_dialplan .= " same => n,Playback(digits/pound)\n";
            } else {
                $callback_dialplan .= " same => n,SayDigits($callback_key)\n";
            }
            $callback_dialplan .= " same => n,Playback(at-any-time)\n";
        }
        
        // CRITICAL: Use Queue() with 'H' option and gosub for IN-QUEUE DTMF handling
        // The gosub context handles DTMF while caller is IN the queue
        $callback_dialplan .= " same => n,Queue($queue_id,tH,,,,,,,,,qcb-handler-$queue_id,s,1)\n";
        $callback_dialplan .= " same => n,Hangup()\n\n";
        
    }
    
    // Add individual DTMF handler contexts for each queue
    foreach ($enabled_queues as $queue) {
        $queue_id = $queue['queue_id'];
        $callback_key = $queue['callback_key'] ?: '*';
        
        $callback_dialplan .= "[qcb-handler-$queue_id]\n";
        $callback_dialplan .= "; IN-QUEUE DTMF handler for queue $queue_id (key: $callback_key)\n";
        
        // Handler for s extension (called by gosub from Queue)
        $callback_dialplan .= "exten => s,1,NoOp(DTMF handler activated for queue $queue_id)\n";
        $callback_dialplan .= " same => n,NoOp(Key pressed: \${EXTEN})\n";
        $callback_dialplan .= " same => n,GotoIf(\$[\"\${EXTEN}\" != \"$callback_key\"]?ignore)\n";
        $callback_dialplan .= " same => n,NoOp(*** Callback key $callback_key pressed - exiting queue ***)\n";
        $callback_dialplan .= " same => n,Set(CALLBACK_NUMBER=\${CALLERID(number)})\n";
        $callback_dialplan .= " same => n,Set(CALLBACK_QUEUE=$queue_id)\n";
        $callback_dialplan .= " same => n,Set(QUEUENAME=$queue_id)\n";
        $callback_dialplan .= " same => n,AGI(queuecallback-store.agi)\n";
        $callback_dialplan .= " same => n,Playback(thank-you)\n";
        $callback_dialplan .= " same => n,Playback(your-call-will-be-returned)\n";
        $callback_dialplan .= " same => n,Hangup()\n";
        $callback_dialplan .= " same => n(ignore),NoOp(Non-callback key ignored)\n";
        $callback_dialplan .= " same => n,Return()\n\n";
        
        // Direct handler for the callback key
        $callback_dialplan .= "exten => $callback_key,1,NoOp(Direct callback key $callback_key pressed)\n";
        $callback_dialplan .= " same => n,Set(CALLBACK_NUMBER=\${CALLERID(number)})\n";
        $callback_dialplan .= " same => n,Set(CALLBACK_QUEUE=$queue_id)\n";
        $callback_dialplan .= " same => n,Set(QUEUENAME=$queue_id)\n";
        $callback_dialplan .= " same => n,AGI(queuecallback-store.agi)\n";
        $callback_dialplan .= " same => n,Playback(thank-you)\n";
        $callback_dialplan .= " same => n,Playback(your-call-will-be-returned)\n";
        $callback_dialplan .= " same => n,Hangup()\n\n";
    }
    
    $callback_dialplan .= "; Queue Callback Module - End\n";
    
    // Write updated content
    file_put_contents($custom_file, $existing_content . $callback_dialplan, LOCK_EX);
    
    // Dialplan reload removed - only reload when actually needed
    
    freepbx_log(FPBX_LOG_INFO, "Queue Callback: Custom dialplan written for " . count($enabled_queues) . " callback-enabled queues");
}

/**
 * Don't modify existing queue dialplan - let FreePBX handle queues normally
 */
function queuecallback_modify_callback_queues($ext) {
    // Disabled - this was breaking the normal queue functionality
    return;
}

/**
 * Modify queue dialplan to add callback functionality
 * DISABLED - We use ext-queues-custom context instead
 */
function queuecallback_modify_queue_dialplan($queue_id, &$ext, $context) {
    // Disabled - we handle callback queues via ext-queues-custom context
    return;
}

/**
 * Hook called by queues module when generating dialplan
 * DISABLED - We use ext-queues-custom context instead
 */
function queuecallback_queues_dialplan_hook($queue_id) {
    // Disabled - we handle callback queues via ext-queues-custom context
    return array();
}/**
 * 
Recreate database events based on current queue configurations
 */
function queuecallback_recreate_events() {
    try {
        $db = FreePBX::Database();
        
        // Get the shortest processing interval from all enabled queues
        $sql = "SELECT MIN(processing_interval) as min_interval FROM queuecallback_config WHERE enabled = 1";
        $stmt = $db->prepare($sql);
        $stmt->execute();
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        $interval = ($result && $result['min_interval']) ? (int)$result['min_interval'] : null;
        
        // Drop existing events
        $db->query("DROP EVENT IF EXISTS queuecallback_trigger");
        
        // Only create events if there are enabled queues
        if ($interval) {
            // Create the trigger event
            $sql = "
            CREATE EVENT queuecallback_trigger
            ON SCHEDULE EVERY {$interval} MINUTE
            STARTS CURRENT_TIMESTAMP
            DO
            BEGIN
                INSERT INTO queuecallback_trigger (last_run) 
                VALUES (UNIX_TIMESTAMP()) 
                ON DUPLICATE KEY UPDATE last_run = UNIX_TIMESTAMP();
                
                -- Reset stuck processing requests
                UPDATE queuecallback_requests 
                SET status = 'pending' 
                WHERE status = 'processing' 
                AND last_attempt < UNIX_TIMESTAMP() - 3600 
                AND attempts < max_attempts;
                
                -- Mark failed requests
                UPDATE queuecallback_requests 
                SET status = 'failed' 
                WHERE attempts >= max_attempts 
                AND status IN ('pending', 'processing');
            END";
            
            $db->query($sql);
            freepbx_log(FPBX_LOG_INFO, "Queue Callback: Database events recreated with {$interval} minute interval");
        } else {
            freepbx_log(FPBX_LOG_INFO, "Queue Callback: No enabled queues, database events removed");
        }
        
    } catch (Exception $e) {
        freepbx_log(FPBX_LOG_ERROR, "Queue Callback: Failed to recreate database events: " . $e->getMessage());
    }
}

/**
 * Add callback configuration for a new queue (with defaults)
 */
function queuecallback_add_queue_config($queue_id) {
    try {
        // Check if configuration already exists
        $existing = FreePBX::Queuecallback()->getQueueCallbackConfig($queue_id);
        
        // Only add if it doesn't exist (avoid overwriting existing config)
        if (empty($existing) || $existing['queue_id'] != $queue_id) {
            $default_config = array(
                'enabled' => 0,  // Disabled by default
                'announce_id' => null,
                'callback_key' => '*',
                'processing_interval' => 30
            );
            
            FreePBX::Queuecallback()->setQueueCallbackConfig($queue_id, $default_config);
            freepbx_log(FPBX_LOG_INFO, "Queue Callback: Default configuration added for new queue $queue_id");
            return true;
        }
        
    } catch (Exception $e) {
        freepbx_log(FPBX_LOG_ERROR, "Queue Callback: Failed to add configuration for queue $queue_id: " . $e->getMessage());
        return false;
    }
    
    return false;
}

/**
 * Bulk operations for queue management
 */
function queuecallback_bulk_queue_operation($operation, $queue_ids) {
    if (!is_array($queue_ids)) {
        $queue_ids = array($queue_ids);
    }
    
    $success_count = 0;
    $total_count = count($queue_ids);
    
    foreach ($queue_ids as $queue_id) {
        switch ($operation) {
            case 'delete':
                if (queuecallback_delete_config($queue_id)) {
                    $success_count++;
                }
                break;
                
            case 'add':
                if (queuecallback_add_queue_config($queue_id)) {
                    $success_count++;
                }
                break;
                
            case 'disable':
                $config = FreePBX::Queuecallback()->getQueueCallbackConfig($queue_id);
                $config['enabled'] = 0;
                FreePBX::Queuecallback()->setQueueCallbackConfig($queue_id, $config);
                $success_count++;
                break;
        }
    }
    
    freepbx_log(FPBX_LOG_INFO, "Queue Callback: Bulk $operation operation completed: $success_count/$total_count queues processed");
    
    // Recreate events after bulk operations
    if ($operation == 'delete' || $operation == 'disable') {
        queuecallback_recreate_events();
    }
    
    return $success_count;
}

/**
 * Validate queue exists before adding callback configuration
 */
function queuecallback_validate_queue($queue_id) {
    try {
        $db = FreePBX::Database();
        $sql = "SELECT extension FROM queues_config WHERE extension = ?";
        $stmt = $db->prepare($sql);
        $stmt->execute(array($queue_id));
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        return !empty($result);
    } catch (Exception $e) {
        freepbx_log(FPBX_LOG_ERROR, "Queue Callback: Failed to validate queue $queue_id: " . $e->getMessage());
        return false;
    }
}

/**
 * Generate callback-enabled queue contexts with proper DTMF handling
 */
function generateCallbackEnabledQueueContexts($ext) {
    try {
        // Get all callback-enabled queues
        $enabled_queues = FreePBX::Qcallback()->getCallbackEnabledQueues();
        
        if (empty($enabled_queues)) {
            return;
        }
        
        // Create custom context for callback-enabled queues
        $context = 'ext-queues-callback';
        
        foreach ($enabled_queues as $queue) {
            $queue_id = $queue['queue_id'];
            $callback_key = $queue['callback_key'] ?: '*';
            
            // Create custom queue extension with DTMF handling
            $ext->add($context, $queue_id, '', new ext_noop("Callback-enabled queue {$queue_id}"));
            $ext->add($context, $queue_id, '', new ext_gosub('macro-user-callerid,s,1()'));
            $ext->add($context, $queue_id, '', new ext_answer());
            $ext->add($context, $queue_id, '', new ext_set('__FROMQUEUEEXTEN', '${CALLERID(number)}'));
            $ext->add($context, $queue_id, '', new ext_set('QUEUENAME', $queue_id));
            $ext->add($context, $queue_id, '', new ext_set('CALLBACK_QUEUE', $queue_id));
            $ext->add($context, $queue_id, '', new ext_set('CALLBACK_KEY', $callback_key));
            
            // Play initial announcement if configured
            if (!empty($queue['announce_id'])) {
                $ext->add($context, $queue_id, '', new ext_playback("custom/{$queue['announce_id']}"));
            }
            
            // Set up DTMF handling - use H option to enable DTMF during queue wait
            $ext->add($context, $queue_id, '', new ext_set('EXITCONTEXT', 'queuecallback-dtmf'));
            
            // Queue with DTMF handling enabled (H option allows DTMF to break out)
            $ext->add($context, $queue_id, '', new ext_queue($queue_id, 'tcH', '', '', '', '', '', '', '', '', '', ''));
            
            // If queue exits normally (no callback requested)
            $ext->add($context, $queue_id, '', new ext_hangup());
        }
        
        // Also override the main ext-queues context for callback-enabled queues
        foreach ($enabled_queues as $queue) {
            $queue_id = $queue['queue_id'];
            
            // Override the standard queue extension to redirect to our callback-enabled version
            $ext->add('ext-queues', $queue_id, '', new ext_goto("ext-queues-callback,{$queue_id},1"));
        }
        
    } catch (Exception $e) {
        freepbx_log(FPBX_LOG_ERROR, "Queue Callback: Error generating callback queue contexts: " . $e->getMessage());
    }
}

/**
 * Clean up orphaned callback configurations (queues that no longer exist)
 */
function queuecallback_cleanup_orphaned_configs() {
    try {
        $db = FreePBX::Database();
        
        // Find callback configs for queues that no longer exist (with collation fix)
        $sql = "SELECT qc.queue_id
                FROM queuecallback_config qc
                LEFT JOIN queues_config q ON qc.queue_id COLLATE utf8_general_ci = q.extension COLLATE utf8_general_ci
                WHERE q.extension IS NULL";
        
        $stmt = $db->prepare($sql);
        $stmt->execute();
        $orphaned = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        
        if (!empty($orphaned)) {
            $orphaned_ids = array_column($orphaned, 'queue_id');
            $cleanup_count = queuecallback_bulk_queue_operation('delete', $orphaned_ids);
            
            freepbx_log(FPBX_LOG_INFO, "Queue Callback: Cleaned up $cleanup_count orphaned configurations");
            return $cleanup_count;
        }
        
    } catch (Exception $e) {
        // If collation error, try getting all configs and checking manually
        try {
            $sql = "SELECT queue_id FROM queuecallback_config";
            $stmt = $db->prepare($sql);
            $stmt->execute();
            $configs = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            
            $sql = "SELECT extension FROM queues_config";
            $stmt = $db->prepare($sql);
            $stmt->execute();
            $queues = array_column($stmt->fetchAll(\PDO::FETCH_ASSOC), 'extension');
            
            $orphaned_ids = array();
            foreach ($configs as $config) {
                if (!in_array($config['queue_id'], $queues)) {
                    $orphaned_ids[] = $config['queue_id'];
                }
            }
            
            if (!empty($orphaned_ids)) {
                $cleanup_count = queuecallback_bulk_queue_operation('delete', $orphaned_ids);
                freepbx_log(FPBX_LOG_INFO, "Queue Callback: Cleaned up $cleanup_count orphaned configurations");
                return $cleanup_count;
            }
            
        } catch (Exception $e2) {
            freepbx_log(FPBX_LOG_ERROR, "Queue Callback: Failed to cleanup orphaned configurations: " . $e2->getMessage());
        }
    }
    
    return 0;
}

/**
 * Hook into FreePBX daily maintenance
 */
function queuecallback_hook_daily_maintenance() {
    if (!qcb_is_reload_context() || qcb_is_uninstall_context()) { return; }
    try {
        include_once(__DIR__ . '/maintenance.php');
        queuecallback_maintenance(false); // Run without verbose output
    } catch (Exception $e) {
        freepbx_log(FPBX_LOG_ERROR, "Queue Callback: Daily maintenance failed: " . $e->getMessage());
    }
}

/**
 * Hook called when a queue is added via the GUI
 */
function queuecallback_hook_queue_add($queue_id) {
    try {
        // Add default callback configuration for new queue
        $default_config = array(
            'enabled' => 0,
            'announce_id' => null,
            'callback_key' => '*',
            'processing_interval' => 30
        );
        
        FreePBX::Queuecallback()->setQueueCallbackConfig($queue_id, $default_config);
        freepbx_log(FPBX_LOG_INFO, "Queue Callback: Default configuration added for new queue $queue_id");
        
    } catch (Exception $e) {
        freepbx_log(FPBX_LOG_ERROR, "Queue Callback: Failed to add configuration for new queue $queue_id: " . $e->getMessage());
    }
}

/**
 * Hook called when a queue is deleted via the GUI
 */
function queuecallback_hook_queue_delete($queue_id) {
    try {
        FreePBX::Queuecallback()->deleteQueueCallbackConfig($queue_id);
        freepbx_log(FPBX_LOG_INFO, "Queue Callback: Configuration deleted for queue $queue_id");
        
    } catch (Exception $e) {
        freepbx_log(FPBX_LOG_ERROR, "Queue Callback: Failed to delete configuration for queue $queue_id: " . $e->getMessage());
    }
}

/**
 * Database integrity check function
 */
function queuecallback_check_database_integrity() {
    $issues = array();
    
    try {
        $db = FreePBX::Database();
        
        // Check for orphaned callback configurations
        $sql = "SELECT qc.queue_id 
                FROM queuecallback_config qc 
                LEFT JOIN queues_config q ON qc.queue_id = q.extension 
                WHERE q.extension IS NULL";
        $stmt = $db->prepare($sql);
        $stmt->execute();
        $orphaned_configs = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        
        if (!empty($orphaned_configs)) {
            $issues[] = "Found " . count($orphaned_configs) . " orphaned callback configurations";
        }
        
        // Check for callback requests for non-existent queues
        $sql = "SELECT DISTINCT qr.queue_id 
                FROM queuecallback_requests qr 
                LEFT JOIN queues_config q ON qr.queue_id = q.extension 
                WHERE q.extension IS NULL 
                AND qr.status IN ('pending', 'processing')";
        $stmt = $db->prepare($sql);
        $stmt->execute();
        $orphaned_requests = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        
        if (!empty($orphaned_requests)) {
            $issues[] = "Found pending callback requests for " . count($orphaned_requests) . " non-existent queues";
        }
        
        // Check for stuck processing requests
        $sql = "SELECT COUNT(*) FROM queuecallback_requests 
                WHERE status = 'processing' 
                AND last_attempt < ?";
        $stmt = $db->prepare($sql);
        $stmt->execute(array(time() - 3600));
        $stuck_requests = $stmt->fetchColumn();
        
        if ($stuck_requests > 0) {
            $issues[] = "Found $stuck_requests stuck processing requests";
        }
        
        // Check database events
        $sql = "SELECT COUNT(*) FROM queuecallback_config WHERE enabled = 1";
        $stmt = $db->prepare($sql);
        $stmt->execute();
        $enabled_queues = $stmt->fetchColumn();
        
        $stmt = $db->prepare("SHOW EVENTS LIKE 'queuecallback_%'");
        $stmt->execute();
        $events = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        
        if ($enabled_queues > 0 && empty($events)) {
            $issues[] = "Database events missing but callback queues are enabled";
        } elseif ($enabled_queues == 0 && !empty($events)) {
            $issues[] = "Database events exist but no callback queues are enabled";
        }
        
    } catch (Exception $e) {
        $issues[] = "Database integrity check failed: " . $e->getMessage();
    }
    
    return $issues;
}
/**

 * Module hook following FreePBX convention for queues module integration
 * This function might be called by the queues module during various operations
 */
function qcallback_hook_queues_config($queue_id, $queue_data = null) {
    if (!qcb_is_reload_context() || qcb_is_uninstall_context()) { return array(); }
    
    // This could be called when queue configuration is being processed
    $callback_config = FreePBX::Qcallback()->getQueueCallbackConfig($queue_id);
    
    if (!empty($callback_config['enabled'])) {
        $callback_key = $callback_config['callback_key'] ?: '*';
        
        // Return configuration that might be used by queues module
        return array(
            'callback_enabled' => true,
            'callback_key' => $callback_key,
            'callback_gosub' => 'queuecallback-handler,s,1(' . $callback_key . ')'
        );
    }
    
    return array();
}

/**
 * Hook that might be called during queue dialplan generation
 * This follows the pattern of other FreePBX modules
 */
function qcallback_hook_queues_generate($queue_id) {
    // Same guard
    if (!qcb_is_reload_context()) { return array(); }
    return qcallback_hook_queues_dialplan($queue_id);
}/**

 * Generate callback dialplan for enabled queues
 * This automatically creates custom dialplan entries for callback-enabled queues
 */
function generateCallbackQueueDialplan(&$ext) {
    try {
        $callback_queues = FreePBX::Qcallback()->getCallbackEnabledQueues();
        
        if (!empty($callback_queues)) {
            // Update extensions_custom.conf with callback-enabled queues
            $custom_file = '/etc/asterisk/extensions_custom.conf';
            $existing_content = '';
            
            if (file_exists($custom_file)) {
                $existing_content = file_get_contents($custom_file);
            }
            
            // Remove existing callback queue entries
            $existing_content = preg_replace('/; Auto-generated callback queues.*?\n\n/s', '', $existing_content);
            
            // Generate new callback queue dialplan
            $dialplan = "\n; Auto-generated callback queues\n";
            $dialplan .= "[from-internal-custom]\n";
            
            foreach ($callback_queues as $queue) {
                $queue_id = $queue['queue_id'];
                $callback_key = $queue['callback_key'] ?: '*';
                
                $dialplan .= "exten => $queue_id,1,NoOp(Callback-enabled queue $queue_id)\n";
                $dialplan .= "exten => $queue_id,n,Gosub(macro-user-callerid,s,1())\n";
                $dialplan .= "exten => $queue_id,n,Answer()\n";
                $dialplan .= "exten => $queue_id,n,Set(__FROMQUEUEEXTEN=\${CALLERID(number)})\n";
                $dialplan .= "exten => $queue_id,n,Set(QUEUENAME=$queue_id)\n";
                $dialplan .= "exten => $queue_id,n,Set(CALLBACK_QUEUE=$queue_id)\n";
                $dialplan .= "exten => $queue_id,n,Set(CALLBACK_KEY=$callback_key)\n";
                
                // Get queue configuration
                $db = FreePBX::Database();
                $sql = "SELECT * FROM queues_config WHERE extension = ?";
                $stmt = $db->prepare($sql);
                $stmt->execute(array($queue_id));
                $queue_config = $stmt->fetch(\PDO::FETCH_ASSOC);
                
                // Play announcement from callback config if configured
                if (!empty($queue['announce_id'])) {
                    $dialplan .= "exten => $queue_id,n,Playback(custom/{$queue['announce_id']})\n";
                }
                
                $dialplan .= "exten => $queue_id,n,Queue($queue_id,tc,,,,,,,,,,queuecallback-handler-fixed\\,s\\,1($callback_key))\n";
                $dialplan .= "exten => $queue_id,n,Hangup()\n\n";
            }
            
            // Write updated content
            file_put_contents($custom_file, $existing_content . $dialplan, LOCK_EX);
        }
        
    } catch (Exception $e) {
        freepbx_log(FPBX_LOG_ERROR, "Queue Callback: Error in generateCallbackQueueDialplan: " . $e->getMessage());
    }
}