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
/**
 * Queue Callback Module Hooks
 * Integrates callback processing with FreePBX maintenance system
 */

if (!defined('FREEPBX_IS_AUTH')) { die('No direct script access allowed'); }

/**
 * FreePBX maintenance hook - runs periodically to process callbacks
 */
function queuecallback_hook_maintenance() {
    try {
        $db = FreePBX::Database();
        $current_time = time();
        
        // Get processing interval from configuration
        $config_sql = "SELECT processing_interval FROM queuecallback_config WHERE enabled = 1 LIMIT 1";
        $config_stmt = $db->prepare($config_sql);
        $config_stmt->execute();
        $config = $config_stmt->fetch(PDO::FETCH_ASSOC);
        $retry_interval = $config['processing_interval'] ?? 30;
        
        // Find callbacks ready for processing based on database interval
        $sql = "SELECT * FROM queuecallback_requests 
                WHERE status = 'pending' 
                AND attempts < max_attempts 
                AND (time_requested + ? <= ? OR (last_attempt IS NOT NULL AND last_attempt + ? <= ?))
                ORDER BY time_requested ASC LIMIT 10";
        
        $stmt = $db->prepare($sql);
        $stmt->execute([$retry_interval, $current_time, $retry_interval, $current_time]);
        $ready_callbacks = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $processed = 0;
        foreach ($ready_callbacks as $callback) {
            if (queuecallback_process_single_callback($callback)) {
                $processed++;
            }
        }
        
        if ($processed > 0) {
            freepbx_log(FPBX_LOG_INFO, "Queue Callback: Processed $processed callback requests");
        }
        
    } catch (Exception $e) {
        freepbx_log(FPBX_LOG_ERROR, "Queue Callback maintenance error: " . $e->getMessage());
    }
}

/**
 * Process a single callback request
 */
function queuecallback_process_single_callback($callback) {
    try {
        $db = FreePBX::Database();
        
        // Create call file content
        $call_file_content = "Channel: Local/{$callback['callback_number']}@from-internal\n";
        $call_file_content .= "CallerID: Queue Callback <{$callback['queue_id']}>\n";
        $call_file_content .= "MaxRetries: 2\n";
        $call_file_content .= "RetryTime: 60\n";
        $call_file_content .= "WaitTime: 30\n";
        $call_file_content .= "Context: queuecallback-outbound\n";
        $call_file_content .= "Extension: s\n";
        $call_file_content .= "Priority: 1\n";
        $call_file_content .= "Archive: yes\n";
        $call_file_content .= "SetVar: __CALLBACK_ID={$callback['id']}\n";
        $call_file_content .= "SetVar: __CALLBACK_QUEUE_ID={$callback['queue_id']}\n";
        $call_file_content .= "SetVar: __CALLBACK_RETURN_MSG={$callback['return_message_id']}\n";
        $call_file_content .= "SetVar: __CALLBACK_CUSTOMER_NUM={$callback['callback_number']}\n";
        
        $call_file = "/tmp/queuecallback_{$callback['id']}.call";
        
        if (file_put_contents($call_file, $call_file_content)) {
            chmod($call_file, 0777);
            if (rename($call_file, "/var/spool/asterisk/outgoing/" . basename($call_file))) {
                // Update database
                $update_sql = "UPDATE queuecallback_requests 
                              SET status = 'processing', attempts = attempts + 1, last_attempt = ? 
                              WHERE id = ?";
                $update_stmt = $db->prepare($update_sql);
                $update_stmt->execute([time(), $callback['id']]);
                
                return true;
            }
        }
        
        return false;
        
    } catch (Exception $e) {
        freepbx_log(FPBX_LOG_ERROR, "Error processing callback {$callback['id']}: " . $e->getMessage());
        return false;
    }
}

/**
 * Process pending callbacks (can be called manually)
 */
function queuecallback_process_pending() {
    queuecallback_hook_maintenance();
}

/**
 * Hook into queues module configuration page
 */
function queuecallback_hook_queues($viewing_itemid, $target_menuid, $item) {
    if ($target_menuid == 'queues' && !empty($viewing_itemid)) {
        $queue_id = $viewing_itemid;
        
        // Get callback configuration for this queue
        $qcallback = FreePBX::Qcallback();
        $callback_config = $qcallback->getQueueCallbackConfig($queue_id);
        
        // Include callback configuration form
        include(__DIR__ . '/views/callback_config.php');
    }
}

/**
 * Hook to process queue form submissions
 */
function queuecallback_hook_queues_configprocess() {
    if (isset($_POST['action']) && $_POST['action'] == 'edit' && !empty($_POST['extdisplay'])) {
        $queue_id = $_POST['extdisplay'];
        
        // Process callback configuration
        $qcallback = FreePBX::Qcallback();
        $result = $qcallback->processQueueForm($queue_id, $_POST);
        
        // Generate dialplan after configuration changes
        if ($result) {
            $qcallback->generateCallbackDialplan(true);
        }
    }
}

/**
 * Hook called when FreePBX reloads - regenerate dialplan
 */
function queuecallback_hook_core_reload() {
    try {
        $qcallback = FreePBX::Qcallback();
        $qcallback->generateCallbackDialplan(true);
    } catch (Exception $e) {
        freepbx_log(FPBX_LOG_ERROR, "Queue Callback reload error: " . $e->getMessage());
    }
}

/**
 * Hook called when applying configuration changes
 */
function queuecallback_hook_core_configprocess() {
    try {
        $qcallback = FreePBX::Qcallback();
        $qcallback->generateCallbackDialplan(true);
    } catch (Exception $e) {
        freepbx_log(FPBX_LOG_ERROR, "Queue Callback config process error: " . $e->getMessage());
    }
}
?>