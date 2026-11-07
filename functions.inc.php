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

    $callback_key = (string)($callback_config['callback_key'] ?? '#');

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
 * Legacy dialplan entry point; canonical generation preserves FreePBX queues.
 */
function queuecallback_create_queue_dialplan() {
    qcallback_get_config('asterisk');
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
                        'callback_key' => '#',
                        'processing_interval' => 30,
                        'alt_message_id' => null,
                        'initiated_message_id' => null,
                        'confirm_prompt_id' => null
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
    try { return FreePBX::Qcallback()->deleteQueueCallbackConfig($queue_id); }
    catch (Throwable $e) {
        error_log('Queue Callback: configuration deletion failed type=' . get_class($e));
        return false;
    }
}

/**
 * Create a call file for callback
 */
function queuecallback_create_call_file($callback) {
    require_once __DIR__ . '/callback_dispatch.php';
    $result = qcallback_dispatch_request(FreePBX::Database(), (int)$callback['id']);
    return $result['published'];
}

/**
 * Process pending callbacks (called by maintenance hook)
 */
function queuecallback_process_pending() {
    FreePBX::Qcallback()->processIntelligentCallbacks();
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
    if ($engine !== 'asterisk' || !qcb_is_reload_context() || qcb_is_uninstall_context()) { return; }
    FreePBX::Qcallback()->generateCallbackDialplan(true, false);
}

/**
 * Get callback-enabled queues from database
 */
function queuecallback_get_enabled_queues() {
    return FreePBX::Qcallback()->getCallbackEnabledQueues();
}

/**
 * Legacy writer name; use the shared BMO generator.
 */
function queuecallback_write_custom_dialplan() {
    qcallback_get_config('asterisk');
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
}

/**
 *
Recreate database events based on current queue configurations
 */
function queuecallback_recreate_events() {
    FreePBX::Qcallback()->refreshCallbackEvents();
}

/**
 * Add callback configuration for a new queue (with defaults)
 */
function queuecallback_add_queue_config($queue_id) {
    try {
        $db = FreePBX::Database();
        $stmt = $db->prepare("SELECT queue_id FROM queuecallback_config WHERE queue_id = ?");
        $stmt->execute([$queue_id]);
        if ($stmt->fetchColumn() !== false) {
            return false;
        }

        $default_config = array(
            'enabled' => 0,
            'announce_id' => null,
            'callback_key' => '#',
            'processing_interval' => 30,
            'alt_message_id' => null,
            'initiated_message_id' => null,
            'confirm_prompt_id' => null
        );

        FreePBX::Qcallback()->setQueueCallbackConfig($queue_id, $default_config);
        freepbx_log(FPBX_LOG_INFO, "Queue Callback: Default configuration added for new queue $queue_id");
        return true;

    } catch (Exception $e) {
        freepbx_log(FPBX_LOG_ERROR, "Queue Callback: Failed to add configuration for queue $queue_id: " . $e->getMessage());
        return false;
    }
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
                $config = FreePBX::Qcallback()->getQueueCallbackConfig($queue_id);
                $config['enabled'] = 0;
                FreePBX::Qcallback()->setQueueCallbackConfig($queue_id, $config);
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
 * Legacy framework generator; delegate instead of overriding ext-queues.
 */
function generateCallbackEnabledQueueContexts($ext) {
    qcallback_get_config('asterisk');
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
    return queuecallback_add_queue_config($queue_id);
}

/**
 * Hook called when a queue is deleted via the GUI
 */
function queuecallback_hook_queue_delete($queue_id) {
    try {
        FreePBX::Qcallback()->deleteQueueCallbackConfig($queue_id);
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
            $issues[] = "Found $stuck_requests long-running processing requests; inspect active calls and archives before recovery";
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
        $callback_key = (string)($callback_config['callback_key'] ?? '#');

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
}

/**

 * Generate callback dialplan for enabled queues
 * This automatically creates custom dialplan entries for callback-enabled queues
 */
function generateCallbackQueueDialplan(&$ext) {
    qcallback_get_config('asterisk');
}

/** Compatibility entry point; manual calls share automatic dispatch checks. */
function queuecallback_process_single_callback($callback) {
    return queuecallback_create_call_file($callback);
}

/** Compatibility GUI hook; queue settings use the same BMO config saver. */
function queuecallback_hook_queues($viewing_itemid, $target_menuid, $item = null) {
    if ($target_menuid !== 'queues' || $viewing_itemid === '') { return; }
    $queue_id = $viewing_itemid;
    $callback_config = FreePBX::Qcallback()->getQueueCallbackConfig($queue_id);
    include __DIR__ . '/views/callback_config.php';
}

function queuecallback_hook_queues_configprocess() {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || ($_POST['action'] ?? '') !== 'edit'
        || !isset($_POST['extdisplay'], $_POST['callback_enabled'])) { return; }
    $config = [];
    foreach (['enabled','announce_id','announce_frequency','processing_interval','call_first',
        'confirm_number','alt_number_key','alt_message_id','confirm_message_id','return_message_id',
        'initiated_message_id','confirm_prompt_id','outbound_route_id','retry_interval','max_attempts',
        'return_confirm_timeout','return_confirm_repeats'] as $field) {
        if (array_key_exists('callback_' . $field, $_POST)) { $config[$field] = $_POST['callback_' . $field]; }
    }
    if (array_key_exists('callback_key', $_POST)) { $config['callback_key'] = $_POST['callback_key']; }
    FreePBX::Qcallback()->setQueueCallbackConfig((string)$_POST['extdisplay'], $config);
}

function queuecallback_hook_core_reload() { qcallback_get_config('asterisk'); }
function queuecallback_hook_core_configprocess() { qcallback_get_config('asterisk'); }
