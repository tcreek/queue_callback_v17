<?php
/** Callback attempt outcomes and scheduling. GPL-3.0-or-later. */

/** Asterisk reports these durations after the corresponding bridge ends. */
function qcallback_was_connected(string $mode, array $vars): bool {
    if (($vars['QCB_CUSTOMER_CONFIRMED'] ?? '') !== '1') { return false; }
    if (!ctype_digit((string)($vars['ANSWEREDTIME_MS'] ?? ''))
        || (int)$vars['ANSWEREDTIME_MS'] <= 0) {
        return false;
    }
    if ($mode === 'agent') {
        return ($vars['DIALSTATUS'] ?? '') === 'ANSWER';
    }
    return $mode === 'customer'
        && ($vars['QCB_PHASE'] ?? '') === 'queue'
        && ($vars['QUEUEWAIT_MS'] ?? '') !== ''
        && strtoupper($vars['ABANDONED'] ?? '') !== 'TRUE'
        && in_array($vars['QUEUESTATUS'] ?? '', ['', 'CONTINUE'], true);
}

/**
 * Only the current processing attempt can report an outcome. This also makes
 * duplicate hangup handlers, late archives, and post-cancellation reports safe.
 * last_attempt becomes the finish time, so the retry interval starts at the
 * end of the attempt rather than expiring while the phone is still ringing.
 */
function qcallback_finish_attempt($db, int $id, int $attempt, bool $connected,
    string $reason, ?int $finished = null): bool {
    if ($id < 1 || $attempt < 1) { return false; }
    $finished = $finished ?? time();
    $stmt = $db->prepare("UPDATE queuecallback_requests r
        LEFT JOIN queuecallback_config c ON c.queue_id = r.queue_id
        SET r.status = CASE WHEN ? = 1 THEN 'completed'
                           WHEN r.attempts >= COALESCE(c.max_attempts, r.max_attempts, 3)
                             OR COALESCE(c.enabled, 0) = 0 THEN 'failed'
                           ELSE 'pending' END,
            r.time_processed = CASE WHEN ? = 1
                OR r.attempts >= COALESCE(c.max_attempts, r.max_attempts, 3)
                OR COALESCE(c.enabled, 0) = 0 THEN ? ELSE NULL END,
            r.last_attempt = ?
        WHERE r.id = ? AND r.status = 'processing' AND r.attempts = ?");
    $stmt->execute([(int)$connected, (int)$connected, $finished, $finished, $id, $attempt]);
    if ($stmt->rowCount() !== 1) { return false; }
    error_log("Queue Callback: attempt ended ID=$id attempt=$attempt connected="
        . (int)$connected . ' reason=' . preg_replace('/[^A-Za-z0-9_ -]/', '_', $reason));
    return true;
}

/**
 * Unanswered originates never enter the destination dialplan. Their archived
 * call files are the terminal evidence used to release them for retry.
 * An archived Completed means the originate ended, not that an agent bridged.
 */
function qcallback_reconcile_attempts($db, string $spool = '/var/spool/asterisk'): void {
    $rows = $db->query("SELECT id, attempts, last_attempt FROM queuecallback_requests
        WHERE status = 'processing'")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) {
        $id = (int)$row['id'];
        $attempt = (int)$row['attempts'];
        if ($attempt < 1) { continue; }
        $name = "queuecallback_{$id}_{$attempt}.call";
        // Do not release a request while Asterisk still owns its outgoing file.
        if (file_exists($spool . '/outgoing/' . $name)
            || file_exists($spool . "/outgoing/queuecallback_{$id}.call")) { continue; }
        $archive = $spool . '/outgoing_done/' . $name;
        if (!is_file($archive)) {
            // Upgrade compatibility: legacy files lacked an attempt suffix.
            $archive = $spool . "/outgoing_done/queuecallback_{$id}.call";
            if (!is_file($archive) || filemtime($archive) < (int)$row['last_attempt']) { continue; }
        }
        if (filemtime($archive) < (int)$row['last_attempt']) { continue; }
        $content = file_get_contents($archive);
        if ($content === false || !preg_match('/^Status:\s*(Expired|Failed|Completed)\s*$/mi', $content, $match)) {
            continue; // Asterisk appends Status after renaming into outgoing_done.
        }
        $reason = strtoupper($match[1]) === 'COMPLETED' ? 'UNCONFIRMED' : strtoupper($match[1]);
        qcallback_finish_attempt($db, $id, $attempt, false, 'SPOOL_' . $reason);
    }
    // Exhausted pending requests may result from lowering the configured limit.
    // Never fail an active attempt merely because its counter reached the limit.
    $db->exec("UPDATE queuecallback_requests r
        LEFT JOIN queuecallback_config c ON c.queue_id = r.queue_id
        SET r.status = 'failed', r.time_processed = UNIX_TIMESTAMP()
        WHERE r.status = 'pending'
          AND r.attempts >= COALESCE(c.max_attempts, r.max_attempts, 3)");
}

/** The optional DB event is only a heartbeat; cron owns outcome processing. */
function qcallback_trigger_event_sql(int $seconds): string {
    $seconds = max(1, $seconds);
    return "CREATE EVENT queuecallback_trigger ON SCHEDULE EVERY {$seconds} SECOND
        STARTS CURRENT_TIMESTAMP DO
        INSERT INTO queuecallback_trigger (id, last_run) VALUES (1, UNIX_TIMESTAMP())
        ON DUPLICATE KEY UPDATE last_run = UNIX_TIMESTAMP()";
}
