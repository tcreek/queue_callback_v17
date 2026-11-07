<?php
/**
 * Shared callback claim and call-file publisher. GPL-3.0-or-later.
 * All processor entry points must publish through this function.
 */
function qcallback_publish_call($db, array $callback, string $content,
    string $outgoing = '/var/spool/asterisk/outgoing'): bool {
    $staged = null;
    $locked = false;
    $claimed = false;
    $attemptTime = time();
    $attempt = (int)$callback['attempts'] + 1;
    $lockName = 'qcb:' . substr(hash('sha256', $callback['queue_id'] . '|' . $callback['callback_number']), 0, 60);

    try {
        // Connection-scoped locks cover different requests for the same number,
        // including workers started by cron, maintenance, or manual processing.
        $lock = $db->prepare('SELECT GET_LOCK(?, 0)');
        $lock->execute([$lockName]);
        if ((int)$lock->fetchColumn() !== 1) {
            return false;
        }
        $locked = true;

        $active = $db->prepare("SELECT COUNT(*) FROM queuecallback_requests
            WHERE queue_id = ? AND callback_number = ? AND status = 'processing'");
        $active->execute([$callback['queue_id'], $callback['callback_number']]);
        if ((int)$active->fetchColumn() > 0) {
            return false;
        }

        $destination = $outgoing . '/queuecallback_' . (int)$callback['id'] . '_' . $attempt . '.call';
        $legacy = $outgoing . '/queuecallback_' . (int)$callback['id'] . '.call';
        if (file_exists($destination) || is_link($destination) || file_exists($legacy) || is_link($legacy)) {
            throw new RuntimeException('An outgoing call file already exists');
        }

        // Stage on the outgoing filesystem: PHP rename can otherwise fall back
        // to a non-atomic copy across filesystems. Asterisk never watches here.
        $stagingDir = dirname($outgoing) . '/.qcallback-staging';
        $outgoingStat = stat($outgoing);
        if ($outgoingStat === false || is_link($stagingDir)) {
            throw new RuntimeException('Invalid spool directory');
        }
        if (!is_dir($stagingDir)) {
            if (!mkdir($stagingDir, 0770) && !is_dir($stagingDir)) {
                throw new RuntimeException('Cannot create staging directory');
            }
            if (!chgrp($stagingDir, $outgoingStat['gid']) || !chmod($stagingDir, 0770)) {
                throw new RuntimeException('Cannot set staging directory permissions');
            }
        }
        $stagingStat = stat($stagingDir);
        if ($stagingStat === false || $stagingStat['dev'] !== $outgoingStat['dev']) {
            throw new RuntimeException('Staging and outgoing must share a filesystem');
        }
        $staged = tempnam($stagingDir, 'qcb-');
        if ($staged === false || dirname($staged) !== realpath($stagingDir)) {
            throw new RuntimeException('Cannot create staged call file');
        }
        // The DB owns retries. Override caller-supplied values in every path.
        $content = preg_replace('/^(?:MaxRetries:.*|SetVar:\s*_*CALLBACK_ATTEMPT=.*)\R?/mi', '', $content);
        $content = rtrim($content) . "\nMaxRetries: 0\nSetVar: __CALLBACK_ATTEMPT=$attempt\n";
        if (file_put_contents($staged, $content) !== strlen($content)
            || (fileowner($staged) !== $outgoingStat['uid'] && !chown($staged, $outgoingStat['uid']))
            || !chgrp($staged, $outgoingStat['gid']) || !chmod($staged, 0640)) {
            throw new RuntimeException('Cannot finish staged call file');
        }

        // Compare the selected attempt state too: a stale worker must not
        // reclaim a request that another worker has already tried and reset.
        $reservation = array_key_exists('_reserved_agent', $callback);
        $reservationSql = $reservation ? ', r.reserved_agent = ?' : '';
        $claim = $db->prepare("UPDATE queuecallback_requests r
            JOIN queuecallback_config c ON c.queue_id = r.queue_id
            SET r.status = 'processing', r.attempts = r.attempts + 1, r.last_attempt = ? $reservationSql
            WHERE r.id = ? AND r.status = 'pending' AND c.enabled = 1
              AND r.attempts = ? AND r.last_attempt <=> ?
              AND r.attempts < COALESCE(c.max_attempts, r.max_attempts, 3)
              AND ((r.last_attempt IS NULL AND r.time_requested + COALESCE(c.processing_interval, 30) <= ?)
                OR (r.last_attempt IS NOT NULL AND r.last_attempt + COALESCE(c.retry_interval, 60) <= ?))");
        $params = [$attemptTime];
        if ($reservation) { $params[] = $callback['_reserved_agent']; }
        $claim->execute(array_merge($params, [$callback['id'], $callback['attempts'], $callback['last_attempt'], $attemptTime, $attemptTime]));
        if ($claim->rowCount() !== 1) {
            return false;
        }
        $claimed = true;

        if (!rename($staged, $destination)) {
            throw new RuntimeException('Cannot publish staged call file');
        }
        // Do not update the request after publication: Asterisk may already
        // have answered and completed it before this worker resumes.
        $staged = null;
        $claimed = false;
        return true;
    } catch (Throwable $e) {
        error_log('Queue Callback: publication failed for ID ' . (int)$callback['id'] . ': ' . $e->getMessage());
        if ($claimed) {
            try {
                $restoreReservation = $reservation ? ', reserved_agent = NULL' : '';
                $restore = $db->prepare("UPDATE queuecallback_requests
                    SET status = 'pending', attempts = ?, last_attempt = ? $restoreReservation
                    WHERE id = ? AND status = 'processing' AND attempts = ? AND last_attempt = ?");
                $restore->execute([$callback['attempts'], $callback['last_attempt'], $callback['id'],
                    (int)$callback['attempts'] + 1, $attemptTime]);
            } catch (Throwable $restoreError) {
                error_log('Queue Callback: claim recovery failed for ID ' . (int)$callback['id'] . ': ' . $restoreError->getMessage());
            }
        }
        return false;
    } finally {
        if (is_string($staged) && is_file($staged)) {
            unlink($staged);
        }
        if ($locked) {
            try {
                $release = $db->prepare('SELECT RELEASE_LOCK(?)');
                $release->execute([$lockName]);
            } catch (Throwable $e) {
                error_log('Queue Callback: could not release publication lock: ' . $e->getMessage());
            }
        }
    }
}
