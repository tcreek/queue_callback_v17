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
?>

#!/usr/bin/php
<?php
/**
 * Automatic Callback Processor
 * Part of the Queue Callback module
 * Runs automatically to process pending callbacks based on database intervals
 */

require_once('/etc/freepbx.conf');

$db = FreePBX::Database();
$current_time = time();

// Clean up duplicates first - keep oldest per queue+number
$db->exec("DELETE r1 FROM queuecallback_requests r1
           INNER JOIN queuecallback_requests r2 
           ON r1.queue_id = r2.queue_id 
           AND r1.callback_number = r2.callback_number 
           AND r1.status = 'pending' 
           AND r2.status = 'pending'
           AND r1.id > r2.id");

// Fix empty queue_ids
$db->exec("UPDATE queuecallback_requests SET status = 'cancelled' 
           WHERE queue_id = '' OR queue_id IS NULL");

// Find callbacks ready for processing based on per-queue configuration
$sql = "SELECT r.*, c.retry_interval, c.max_attempts, c.processing_interval, c.call_first, c.outbound_route_id, c.return_message_id, c.confirm_prompt_id
        FROM queuecallback_requests r 
        JOIN queuecallback_config c ON r.queue_id = c.queue_id 
        WHERE r.status = 'pending' 
        AND c.enabled = 1
        AND r.attempts < COALESCE(c.max_attempts, r.max_attempts, 3)
        AND ((r.last_attempt IS NULL AND r.time_requested + COALESCE(c.processing_interval, c.retry_interval, 30) <= ?)
             OR (r.last_attempt IS NOT NULL AND r.last_attempt + COALESCE(c.retry_interval, 30) <= ?))
        ORDER BY r.time_requested ASC LIMIT 10";

$stmt = $db->prepare($sql);
try {
    $stmt->execute([$current_time, $current_time]);
} catch (PDOException $e) {
    // Fallback: if outbound_route_id column doesn't exist (older schema), retry without it
    $sql_fallback = "SELECT r.*, c.retry_interval, c.max_attempts, c.processing_interval, c.call_first, c.return_message_id, c.confirm_prompt_id
                    FROM queuecallback_requests r 
                    JOIN queuecallback_config c ON r.queue_id = c.queue_id 
                    WHERE r.status = 'pending' 
                    AND c.enabled = 1
                    AND r.attempts < COALESCE(c.max_attempts, r.max_attempts, 3)
                    AND ((r.last_attempt IS NULL AND r.time_requested + COALESCE(c.processing_interval, c.retry_interval, 30) <= ?)
                         OR (r.last_attempt IS NOT NULL AND r.last_attempt + COALESCE(c.retry_interval, 30) <= ?))
                    ORDER BY r.time_requested ASC LIMIT 10";
    $stmt = $db->prepare($sql_fallback);
    $stmt->execute([$current_time, $current_time]);
}
$ready_callbacks = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Filter out duplicates and numbers already being called
$seen = [];
$filtered = [];
foreach ($ready_callbacks as $cb) {
    $key = $cb['queue_id'] . '|' . $cb['callback_number'];
    if (isset($seen[$key])) {
        continue;
    }
    // Check if this number is already being called
    $check = $db->prepare("SELECT COUNT(*) as cnt FROM queuecallback_requests 
                           WHERE queue_id = ? AND callback_number = ? AND status = 'processing'");
    $check->execute([$cb['queue_id'], $cb['callback_number']]);
    if ($check->fetch(PDO::FETCH_ASSOC)['cnt'] > 0) {
        continue;
    }
    $seen[$key] = true;
    $filtered[] = $cb;
}
$ready_callbacks = $filtered;

foreach ($ready_callbacks as $callback) {
    $call_first = $callback['call_first'] ?? 'customer';

    // Determine channel based on whether callback number is internal or extension
    $is_internal = (strlen($callback['callback_number']) <= 4 && is_numeric($callback['callback_number']));
    if ($is_internal) {
        $channel = "PJSIP/{$callback['callback_number']}";
    } else {
        $outbound_route_id = $callback['outbound_route_id'] ?? 1;
        $trunk_row = $db->getRow("SELECT t.name FROM outbound_route_trunks ort JOIN trunks t ON ort.trunk_id = t.trunkid WHERE ort.route_id = ? ORDER BY ort.seq LIMIT 1", [$outbound_route_id], PDO::FETCH_ASSOC);
        $trunk_name = $trunk_row['name'] ?? 'Skyetel-1';
        $channel = "PJSIP/{$callback['callback_number']}@{$trunk_name}";
    }
    
    if ($call_first === 'agent') {
        // Call agent first - get available agents from the queue
        $queue_output = shell_exec("/usr/sbin/asterisk -rx 'queue show {$callback['queue_id']}' 2>/dev/null");
        $agent_extension = '';
        
        if ($queue_output === null) {
            error_log("DEBUG: shell_exec returned null for queue {$callback['queue_id']}");
        }
        
        // Strip ALL ANSI escape sequences (not just SGR color codes), carriage returns, and other control chars
        $queue_output = preg_replace('/\x1b\[[0-9;?]*[a-zA-Z]/', '', $queue_output);
        $queue_output = str_replace("\r", '', $queue_output);
        
        // Debug: log raw output for troubleshooting
        error_log("DEBUG: Queue output for queue {$callback['queue_id']}:\n" . $queue_output);
        
        // Parse all queue members and find available ones
        if ($queue_output) {
            // Match all Local/XXX@from-queue members with 'has taken' text (s flag for multiline)
            preg_match_all('/Local\/(\d+)@from-queue.*?has taken/s', $queue_output, $matches, PREG_SET_ORDER);
            
            // Fallback: if no matches with 'has taken', match any Local/XXX@from-queue line
            if (empty($matches)) {
                preg_match_all('/Local\/(\d+)@from-queue[^\n]+/', $queue_output, $matches, PREG_SET_ORDER);
            }
            
            $agents = [];
            foreach ($matches as $match) {
                $ext = $match[1];
                $line = $match[0];
                
                // Determine status from keywords in the line
                if (strpos($line, 'Not in use') !== false) {
                    $status = 'Not in use';
                } elseif (strpos($line, 'Unavailable') !== false) {
                    $status = 'Unavailable';
                } elseif (strpos($line, 'Ringing') !== false) {
                    $status = 'Ringing';
                } else {
                    $status = 'Unknown';
                }
                
                $agents[] = [
                    'ext' => $ext,
                    'status' => $status
                ];
            }
            
            // Debug: log parsed agents
            if (!empty($agents)) {
                $agent_debug = array_map(function($a) { return "ext={$a['ext']} status={$a['status']}"; }, $agents);
                error_log("DEBUG: Parsed agents: " . implode(", ", $agent_debug));
            } else {
                error_log("DEBUG: No agents matched regex. matches count: " . count($matches));
            }
            
            // Prefer "Not in use" agents
            foreach ($agents as $agent) {
                if ($agent['status'] === 'Not in use') {
                    $agent_extension = $agent['ext'];
                    break;
                }
            }
            
            // If no "Not in use" agent, try any available agent
            if (empty($agent_extension)) {
                foreach ($agents as $agent) {
                    if ($agent['status'] !== 'Unavailable' && $agent['status'] !== 'Ringing') {
                        $agent_extension = $agent['ext'];
                        break;
                    }
                }
            }
        }
        
        if (!empty($agent_extension)) {
            $call_file_content = "Channel: PJSIP/{$agent_extension}\n";
            $call_file_content .= "CallerID: QC Agent <{$callback['queue_id']}>\n";
            $call_file_content .= "Context: queuecallback-agent-outbound\n";
            $call_file_content .= "Extension: s\n";
            $call_file_content .= "SetVar: __CALLBACK_CUSTOMER_NUM={$callback['callback_number']}\n";
            $call_file_content .= "SetVar: __CALLBACK_RETURN_MSG={$callback['return_message_id']}\n";
            $call_file_content .= "SetVar: __CALLBACK_CUSTOMER_CHANNEL={$channel}\n";
            $outbound_route_id = $callback['outbound_route_id'] ?? 1;
            if (!empty($outbound_route_id) && $outbound_route_id != 1) {
                $call_file_content .= "OutboundRouteID: {$outbound_route_id}\n";
            }
        } else {
            // No agent found, skip this callback
            error_log("DEBUG: agent_extension empty after parsing. agents count=" . count($agents ?? []));
            error_log("Callback skipped: No available agent found for queue {$callback['queue_id']}");
            continue;
        }
    } else {
        // Call customer first (default) - dial customer and enter queuecallback-outbound context
        $call_file_content = "Channel: {$channel}\n";
        $call_file_content .= "CallerID: Queue Callback <{$callback['queue_id']}>\n";
        $call_file_content .= "Context: queuecallback-outbound\n";
        $call_file_content .= "Extension: s\n";
        $outbound_route_id = $callback['outbound_route_id'] ?? 1;
        if (!empty($outbound_route_id) && $outbound_route_id != 1) {
            $call_file_content .= "OutboundRouteID: {$outbound_route_id}\n";
        }
    }

    // Common call file content
    $call_file_content .= "MaxRetries: 2\n";
    $call_file_content .= "RetryTime: 60\n";
    $call_file_content .= "WaitTime: 30\n";
    $call_file_content .= "Priority: 1\n";
    $call_file_content .= "Archive: yes\n";
    $call_file_content .= "SetVar: __CALLBACK_ID={$callback['id']}\n";
    $call_file_content .= "SetVar: __CALLBACK_QUEUE_ID={$callback['queue_id']}\n";
    $call_file_content .= "SetVar: __CALLBACK_RETURN_MSG={$callback['return_message_id']}\n";
    $call_file_content .= "SetVar: CHANNEL(hangup_handler_push)=qcb-complete,s,1({$callback['id']})\n";

    $call_file = "/var/spool/asterisk/outgoing/queuecallback_{$callback['id']}.call";

    error_log("DEBUG: Attempting to write call file for callback ID {$callback['id']} to {$call_file} (agent={$agent_extension})");

    if (file_put_contents($call_file, $call_file_content)) {
        chmod($call_file, 0777);
        // Change group to asterisk so Asterisk can process the file
        @chgrp($call_file, 'asterisk');
        // Update database
        $update_sql = "UPDATE queuecallback_requests 
                      SET status = 'processing', attempts = attempts + 1, last_attempt = ? 
                      WHERE id = ?";
        $update_stmt = $db->prepare($update_sql);
        try {
            $update_stmt->execute([time(), $callback['id']]);
        } catch (PDOException $e) {
            error_log("ERROR: Database update failed for callback ID {$callback['id']}: " . $e->getMessage());
        }
            
        // Log the callback processing
        error_log("Callback processed: ID {$callback['id']} - {$callback['callback_number']} -> Queue {$callback['queue_id']}");
    } else {
        // Debug: log why the call file couldn't be written
        $dir = "/var/spool/asterisk/outgoing";
        error_log("ERROR: Failed to write call file to {$call_file}. Directory exists: " . (is_dir($dir) ? 'YES' : 'NO') . ". Writable: " . (is_writable($dir) ? 'YES' : 'NO'));
    }
}


// Cleanup old callback records (run cleanup every time)
$cleanup_age = 24 * 60 * 60; // 24 hours in seconds

// Delete completed callbacks older than 24 hours
$cleanup_sql = "DELETE FROM queuecallback_requests 
                WHERE status IN ('completed', 'failed') 
                AND time_processed IS NOT NULL 
                AND time_processed < ?";
$cleanup_stmt = $db->prepare($cleanup_sql);
$cleanup_stmt->execute([$current_time - $cleanup_age]);
$deleted_count = $cleanup_stmt->rowCount();

if ($deleted_count > 0) {
    error_log("Callback cleanup: Deleted $deleted_count old callback records");
}

// Also clean up very old pending callbacks that have exceeded max attempts
$old_pending_sql = "DELETE FROM queuecallback_requests 
                    WHERE status = 'pending' 
                    AND attempts >= max_attempts 
                    AND time_requested < ?";
$old_pending_stmt = $db->prepare($old_pending_sql);
$old_pending_stmt->execute([$current_time - $cleanup_age]);
$old_deleted = $old_pending_stmt->rowCount();

if ($old_deleted > 0) {
    error_log("Callback cleanup: Deleted $old_deleted old failed callback requests");
}
?>
