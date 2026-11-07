<?php
/** Shared queue-order and capacity gate for every callback publisher. GPL-3.0-or-later. */
require_once __DIR__ . '/callback_queue.php';
require_once __DIR__ . '/callback_settings.php';
require_once __DIR__ . '/callback_spool.php';

function qcallback_dispatch_request($db, int $id, $module = null, ?callable $stateProvider = null,
    string $outgoing = '/var/spool/asterisk/outgoing'): array {
    $locked = false;
    $defer = function (string $reason): array { return ['published' => false, 'reason' => $reason]; };
    try {
        // Serialize selection and publication across queues and all entry points.
        $lock = $db->query("SELECT GET_LOCK('qcb:dispatch', 0)");
        if ((int)$lock->fetchColumn() !== 1) { return $defer('DISPATCH_BUSY'); }
        $locked = true;
        $select = $db->prepare("SELECT r.*, c.enabled, c.processing_interval, c.retry_interval,
            c.max_attempts AS configured_max, c.call_first, c.outbound_route_id, c.return_message_id,
            c.return_confirm_timeout, c.return_confirm_repeats
            FROM queuecallback_requests r JOIN queuecallback_config c ON c.queue_id = r.queue_id WHERE r.id = ?");
        $select->execute([$id]); $callback = $select->fetch(PDO::FETCH_ASSOC);
        if (!$callback || $callback['status'] !== 'pending' || !(int)$callback['enabled']) { return $defer('INELIGIBLE'); }
        $now = time();
        // Retain the oldest logical request for each destination, as the old
        // worker did, but preserve discarded requests as auditable records.
        $pending = $db->prepare("SELECT id, callback_number, attempts, max_attempts FROM queuecallback_requests
            WHERE queue_id = ? AND status = 'pending'
            ORDER BY queue_priority DESC, COALESCE(queue_joined_at, time_requested), time_requested, id");
        $pending->execute([$callback['queue_id']]);
        $seen = []; $removed = false;
        foreach ($pending->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $status = null;
            if ((int)$row['attempts'] >= (int)($callback['configured_max'] ?? $row['max_attempts'] ?? 3)) { $status = 'failed'; }
            elseif (isset($seen[$row['callback_number']])) { $status = 'cancelled'; }
            else { $seen[$row['callback_number']] = true; }
            if ($status !== null) {
                $db->prepare("UPDATE queuecallback_requests SET status = ?, time_processed = ? WHERE id = ? AND status = 'pending'")
                    ->execute([$status, $now, $row['id']]);
                if ((int)$row['id'] === $id) { $removed = true; }
            }
        }
        if ($removed) { return $defer('INELIGIBLE'); }

        $due = $callback['last_attempt'] === null
            ? (int)$callback['time_requested'] + (int)($callback['processing_interval'] ?? 30)
            : (int)$callback['last_attempt'] + (int)($callback['retry_interval'] ?? 60);
        if ((int)$callback['attempts'] >= (int)($callback['configured_max'] ?? $callback['max_attempts'] ?? 3)
            || $due > $now) { return $defer('NOT_DUE'); }
        $head = $db->prepare("SELECT id FROM queuecallback_requests WHERE queue_id = ? AND status = 'pending'
            ORDER BY queue_priority DESC, COALESCE(queue_joined_at, time_requested), time_requested, id LIMIT 1");
        $head->execute([$callback['queue_id']]);
        if ((int)$head->fetchColumn() !== $id) { return $defer('CALLBACK_AHEAD'); }
        $active = $db->prepare("SELECT COUNT(*) FROM queuecallback_requests WHERE queue_id = ? AND status = 'processing'");
        $active->execute([$callback['queue_id']]);
        if ((int)$active->fetchColumn() > 0) { return $defer('QUEUE_CALLBACK_ACTIVE'); }
        $module = $module ?? FreePBX::Qcallback();
        if ($module->isNumberBlocked($callback['callback_number'], $callback['queue_id'])) {
            $db->prepare("UPDATE queuecallback_requests SET status='cancelled', time_processed=?
                WHERE id=? AND status='pending'")->execute([$now, $id]);
            return $defer('BLOCKED');
        }
        $reserved = $db->query("SELECT reserved_agent FROM queuecallback_requests
            WHERE status = 'processing' AND reserved_agent IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);
        $snapshot = $stateProvider ? $stateProvider((string)$callback['queue_id']) : qcallback_read_queue_state((string)$callback['queue_id']);
        $decision = qcallback_queue_decision($callback, $snapshot, $reserved);
        if (!$decision['ready']) { return $defer($decision['reason']); }
        $channel = qcallback_customer_channel($db, (string)$callback['callback_number'], $callback['outbound_route_id'] ?? 0);
        $message = $module->resolveReturnMessage($callback['return_message_id'] ?? null);
        if (!is_string($message) || preg_match('/[\r\n\x00]/', $message)) { return $defer('INVALID_MESSAGE'); }
        $agentFirst = ($callback['call_first'] ?? 'customer') === 'agent';
        $confirmation = qcallback_return_confirmation_settings($callback);
        $dial = $agentFirst ? $decision['member']['Location'] : $channel;
        $context = $agentFirst ? 'queuecallback-agent-outbound' : 'queuecallback-outbound';
        $content = "Channel: $dial\nCallerID: Queue Callback <{$callback['queue_id']}>\nContext: $context\nExtension: s\n"
            . "Priority: 1\nWaitTime: 30\nArchive: yes\nSetVar: __CALLBACK_ID=$id\n"
            // FreePBX Local members enter from-queue, which rejects calls
            // without the originating queue in NODEST before dialing QAGENT.
            . ($agentFirst ? "SetVar: __NODEST={$callback['queue_id']}\n" : '')
            . "SetVar: __CALLBACK_QUEUE_ID={$callback['queue_id']}\nSetVar: __CALLBACK_QUEUE_PRIORITY={$callback['queue_priority']}\n"
            . "SetVar: __CALLBACK_CUSTOMER_NUM={$callback['callback_number']}\nSetVar: __CALLBACK_CUSTOMER_CHANNEL=$channel\n"
            . "SetVar: __CALLBACK_RETURN_MSG=$message\n";
        $content .= "SetVar: __CALLBACK_CONFIRM_TIMEOUT={$confirmation['return_confirm_timeout']}\n"
            . "SetVar: __CALLBACK_CONFIRM_PLAYS=" . ($confirmation['return_confirm_repeats'] + 1) . "\n";
        $callback['_reserved_agent'] = $decision['member']['agent_key'];
        $published = qcallback_publish_call($db, $callback, $content, $outgoing);
        return ['published' => $published, 'reason' => $published ? 'PUBLISHED' : 'PUBLICATION_FAILED'];
    } catch (Throwable $e) {
        error_log('Queue Callback: dispatch failed for ID ' . $id . ' type=' . get_class($e));
        return $defer('DISPATCH_ERROR');
    } finally {
        if ($locked) {
            try { $db->query("SELECT RELEASE_LOCK('qcb:dispatch')"); }
            catch (Throwable $e) { error_log('Queue Callback: dispatch lock release failed'); }
        }
    }
}
