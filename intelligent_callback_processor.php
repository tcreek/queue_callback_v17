#!/usr/bin/php
<?php
/**
 * Intelligent Callback Processor
 * Only processes callbacks when:
 * 1. It's actually their turn in the queue
 * 2. Agents are available or not busy
 * 
 * IMPORTANT: Run this script as the asterisk user:
 * sudo -u asterisk php /path/to/intelligent_callback_processor.php
 * 
 * Or add to asterisk user crontab (sudo -u asterisk crontab -e)
 */

require_once('/etc/freepbx.conf');

$db = FreePBX::Database();
$current_time = time();

/**
 * Parse queue status from Asterisk
 * NOTE: This script should be run as the asterisk user for direct access
 */
function getQueueStatus($queue_id) {
  $commands = [
      "/usr/sbin/asterisk -rx 'queue show $queue_id'",
      "asterisk -rx 'queue show $queue_id'"
  ];

  $output = null;
  foreach ($commands as $cmd) {
      $output = shell_exec($cmd . ' 2>/dev/null');
      if ($output && trim($output) !== '' && strpos($output, 'Unable to connect') === false) {
          break;
      }
      $output = null;
  }

  if (!$output) {
      return getDatabaseQueueStatus($queue_id);
  }

  $status = [
      'waiting_calls'   => 0,
      'available_agents'=> 0,
      'agents'          => [],
  ];

  // Normalize newlines early
  $output = str_replace("\r", "", $output);

  // First line summary: ... W:0, ... A:2
  if (preg_match('/W:(\d+).*A:(\d+)/', $output, $m)) {
      $status['waiting_calls']   = (int)$m[1];
      $status['available_agents']= (int)$m[2];
  }

  $lines = explode("\n", $output);
  foreach ($lines as $line) {
      if (strpos($line, 'has taken') === false) {
          continue;
      }

      // ---- Sanitize the line ----
      // Strip ANSI escape sequences
      $line = preg_replace("/\x1B\[[0-9;]*[A-Za-z]/", '', $line);
      // Strip other non-printables
      $line = preg_replace('/[[:^print:]]+/', '', $line);
      // Remove invalid UTF-8 bytes
      $line = iconv('UTF-8', 'UTF-8//IGNORE', $line);
      // Replace NBSP with normal spaces and collapse spaces
      $line = str_replace("\xC2\xA0", ' ', $line);
      $line = preg_replace('/\s+/', ' ', $line);

      echo "  � Cleaned line: " . trim($line) . "\n";

      // Try to match agent status - look for status right before "has taken"
      if (preg_match('/\(([^)]+)\)\s+has taken/u', $line, $m)) {
          $agent_status = $m[1];
          $status['agents'][] = $agent_status;
          echo "  ✅ Parsed status: $agent_status\n";
      } else {
          echo "  ❌ Regex failed to match\n";
      }
  }

  return $status;
}


/**
 * Parse AMI Queue Status Response
 */
function parseAMIQueueResponse($response, $queue_id) {
    $status = [
        'waiting_calls' => 0,
        'available_agents' => 0,
        'agents' => []
    ];
    
    // AMI returns multiple events, parse them
    if (isset($response['Events'])) {
        foreach ($response['Events'] as $event) {
            if ($event['Event'] == 'QueueParams') {
                $status['waiting_calls'] = (int)($event['Calls'] ?? 0);
            } elseif ($event['Event'] == 'QueueMember') {
                $member_status = $event['Status'] ?? '0';
                // Status: 0=Unknown, 1=Not in use, 2=In use, 3=Busy, 4=Invalid, 5=Unavailable
                switch ($member_status) {
                    case '1':
                        $status['agents'][] = 'Available';
                        $status['available_agents']++;
                        break;
                    case '2':
                        $status['agents'][] = 'In use';
                        break;
                    case '3':
                        $status['agents'][] = 'Busy';
                        break;
                    case '5':
                        $status['agents'][] = 'Unavailable';
                        break;
                    default:
                        $status['agents'][] = 'Unknown';
                }
            }
        }
    }
    
    return $status;
}

/**
 * Database fallback for queue status
 */
function getDatabaseQueueStatus($queue_id) {
    global $db;
    
    echo "  📊 Using database fallback for queue status\n";
    
    // Check if queue exists and get basic info
    $queue_sql = "SELECT * FROM queues_config WHERE extension = ?";
    $stmt = $db->prepare($queue_sql);
    $stmt->execute([$queue_id]);
    $queue_info = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$queue_info) {
        echo "  ❌ Queue $queue_id not found in database\n";
        return null;
    }
    
    // Try to get queue members from database
    $members_sql = "SELECT * FROM queues_details WHERE id = ? AND keyword = 'member'";
    $stmt = $db->prepare($members_sql);
    $stmt->execute([$queue_id]);
    $members = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $agent_count = count($members);
    echo "  📊 Found $agent_count queue members in database\n";
    
    // For database fallback, be conservative - don't assume agents are available
    if ($agent_count > 0) {
        $status = [
            'waiting_calls' => 0, // Assume no waiting calls
            'available_agents' => 0, // Don't assume any agents are available
            'agents' => array_fill(0, $agent_count, 'Unavailable') // Assume all unavailable (conservative)
        ];
        echo "  ⚠️ Database fallback: Assuming all $agent_count agents are unavailable (conservative)\n";
    } else {
        // No members found - don't allow processing
        $status = [
            'waiting_calls' => 0,
            'available_agents' => 0,
            'agents' => []
        ];
        echo "  ❌ No queue members found - no agents available\n";
    }
    
    return $status;
}

/**
 * Check if agents are ready to take calls
 */
function areAgentsReady($queue_status) {
    if (!$queue_status) {
        return false;
    }
    
    // Don't trust the A:X summary - check actual agent status lines
    if (empty($queue_status['agents'])) {
        echo "  ⚠️ No individual agent status found - assuming no agents ready\n";
        return false;
    }
    
    $ready_agents = 0;
    foreach ($queue_status['agents'] as $agent_status) {
        // Only count agents that are actually ready to take calls
        if ($agent_status === 'Available' || $agent_status === 'Not in use') {
            $ready_agents++;
        }
        echo "  🔍 Agent status: '$agent_status'\n";
    }
    
    echo "  📊 Found $ready_agents ready agents out of " . count($queue_status['agents']) . " total\n";
    $result = ($ready_agents > 0);
    echo "  📊 areAgentsReady() returning: " . ($result ? "TRUE" : "FALSE") . "\n";
    return $result;
}

/**
 * Calculate virtual queue position for callback
 */
function calculateCallbackPosition($callback, $queue_status) {
    // Their position = current waiting calls + their position among pending callbacks
    $pending_sql = "SELECT COUNT(*) as ahead 
                    FROM queuecallback_requests 
                    WHERE queue_id = ? 
                    AND status = 'pending' 
                    AND time_requested < ?";
    
    global $db;
    $stmt = $db->prepare($pending_sql);
    $stmt->execute([$callback['queue_id'], $callback['time_requested']]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $callbacks_ahead = (int)$result['ahead'];
    $current_waiting = $queue_status['waiting_calls'];
    
    return $current_waiting + $callbacks_ahead + 1; // +1 because they're next after those ahead
}

/**
 * Check if it's this callback's turn
 */
function isCallbackTurn($callback, $queue_status) {
    $position = calculateCallbackPosition($callback, $queue_status);
    
    // It's their turn if:
    // 1. No one is currently waiting in the queue (W:0)
    // 2. AND they are the next callback in line (position 1)
    return ($queue_status['waiting_calls'] === 0 && $position === 1);
}

// Main processing loop
echo "[" . date('Y-m-d H:i:s') . "] Starting intelligent callback processing...\n";

// Get all pending callbacks and processing callbacks ready for retry (strict FIFO)
$sql = "SELECT r.*, c.retry_interval, c.max_attempts, c.processing_interval, c.call_first
        FROM queuecallback_requests r 
        JOIN queuecallback_config c ON r.queue_id = c.queue_id 
        WHERE (r.status = 'pending' 
               OR (r.status = 'processing' 
                   AND r.last_attempt IS NOT NULL 
                   AND r.last_attempt + (c.retry_interval * 60) <= ?))
        AND c.enabled = 1
        AND r.attempts < COALESCE(c.max_attempts, r.max_attempts, 3)
        ORDER BY r.time_requested ASC";

$stmt = $db->prepare($sql);
$stmt->execute([$current_time]);
$pending_callbacks = $stmt->fetchAll(PDO::FETCH_ASSOC);

$processed = 0;

foreach ($pending_callbacks as $callback) {
    echo "[" . date('H:i:s') . "] Checking callback ID {$callback['id']} for queue {$callback['queue_id']}...\n";
    
    // Get current queue status
    $queue_status = getQueueStatus($callback['queue_id']);
    if (!$queue_status) {
        echo "  ❌ Could not get queue status\n";
        continue;
    }
    
    echo "  � Queue status: W:{$queue_status['waiting_calls']}, A:{$queue_status['available_agents']}, Agents:" . count($queue_status['agents']) . "\n";
    
    // Check if agents are ready
    if (!areAgentsReady($queue_status)) {
        echo "  ⏳ No agents available/ready - skipping\n";
        continue;
    }
    
    // Check if it's their turn
    if (!isCallbackTurn($callback, $queue_status)) {
        $position = calculateCallbackPosition($callback, $queue_status);
        echo "  ⏳ Not their turn yet - position $position in callback queue\n";
        continue;
    }
    
    echo "  ✅ Conditions met - processing callback!\n";
    
    // Create call file
    $call_first = $callback['call_first'] ?? 'customer';
    
    // Set variables first
    $call_file_content = "Set: __CALLBACK_ID={$callback['id']}\n";
    $call_file_content .= "Set: __CALLBACK_QUEUE_ID={$callback['queue_id']}\n";
    $call_file_content .= "Set: CALLBACK_QUEUE_ID={$callback['queue_id']}\n";
    
    if ($call_first === 'agent') {
        $call_file_content .= "Set: __CALLBACK_CUSTOMER_NUM={$callback['callback_number']}\n";
        $call_file_content .= "Channel: Local/{$callback['queue_id']}@from-internal\n";
        $call_file_content .= "CallerID: QC Agent <{$callback['queue_id']}>\n";
        $call_file_content .= "Context: queuecallback-agent-outbound\n";
        $call_file_content .= "Extension: s\n";
    } else {
        $call_file_content .= "Channel: Local/{$callback['callback_number']}@from-internal\n";
        $call_file_content .= "CallerID: Queue Callback <{$callback['callback_number']}>\n";
        $call_file_content .= "Context: queuecallback-outbound\n";
        $call_file_content .= "Extension: {$callback['queue_id']}\n";
    }
    
    $call_file_content .= "MaxRetries: 2\n";
    $call_file_content .= "RetryTime: 60\n";
    $call_file_content .= "WaitTime: 30\n";
    $call_file_content .= "Priority: 1\n";
    $call_file_content .= "Archive: yes\n";
    
    $call_file = "/tmp/queuecallback_{$callback['id']}.call";
    
    // Debug: Show call file content
    echo "  📄 Call file content:\n";
    echo str_replace("\n", "\n     ", "     " . trim($call_file_content)) . "\n";
    
    if (file_put_contents($call_file, $call_file_content)) {
        chmod($call_file, 0777);
        if (rename($call_file, "/var/spool/asterisk/outgoing/" . basename($call_file))) {
            // Update database
            $update_sql = "UPDATE queuecallback_requests 
                          SET status = 'processing', attempts = attempts + 1, last_attempt = ? 
                          WHERE id = ?";
            $update_stmt = $db->prepare($update_sql);
            $update_stmt->execute([time(), $callback['id']]);
            
            echo "  � Callback initiated for {$callback['callback_number']}\n";
            $processed++;
            
            // Only process one callback at a time to maintain strict ordering
            break;
        }
    }
}

if ($processed > 0) {
    echo "[" . date('H:i:s') . "] ✅ Processed $processed callback\n";
    error_log("Intelligent Callback: Processed $processed callback with position/agent checking");
} else {
    echo "[" . date('H:i:s') . "] ⏳ No callbacks ready for processing\n";
}

// Cleanup old records (same as before)
$cleanup_age = 24 * 60 * 60; // 24 hours

$cleanup_sql = "DELETE FROM queuecallback_requests 
                WHERE status IN ('completed', 'failed') 
                AND time_processed IS NOT NULL 
                AND time_processed < ?";
$cleanup_stmt = $db->prepare($cleanup_sql);
$cleanup_stmt->execute([$current_time - $cleanup_age]);

echo "[" . date('H:i:s') . "] � Cleanup completed\n";
?>