<?php
/** Callback request validation, persistence and AGI results. GPL-3.0-or-later. */

/** Bootstrap from function scope without losing FreePBX's global configuration. */
function qcallback_agi_database() {
    global $amp_conf;
    ob_start(static function ($output) { return ''; });
    try {
        require_once '/etc/freepbx.conf';
        return FreePBX::Database();
    } finally { ob_end_clean(); }
}

function qcallback_validate_request(array $request): array {
    $queue = $request['queue_id'] ?? null;
    $number = $request['callback_number'] ?? null;
    if (!is_string($queue) || !preg_match('/^[0-9]{1,50}$/D', $queue)) {
        throw new InvalidArgumentException('INVALID_QUEUE');
    }
    // Match the customer-channel grammar. Preserve + and short extensions;
    // never turn arbitrary text or a dial string into a different number.
    if (!is_string($number) || strlen($number) > 50 || !preg_match('/^\+?[0-9]+$/D', $number)) {
        throw new InvalidArgumentException('INVALID_NUMBER');
    }
    foreach (['caller_id', 'caller_name', 'uniqueid'] as $field) {
        $value = $request[$field] ?? '';
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8')
            || preg_match('/[\x00-\x1f\x7f]/', $value)) {
            throw new InvalidArgumentException('INVALID_METADATA');
        }
    }
    $caller = $request['caller_id'] ?? '';
    if (($request['caller_name'] ?? '') !== '') { $caller = $request['caller_name'] . ' <' . $caller . '>'; }
    $uniqueid = $request['uniqueid'] ?? '';
    if (mb_strlen($uniqueid, 'UTF-8') > 50) { throw new InvalidArgumentException('INVALID_METADATA'); }
    $joined = $request['queue_joined_at'] ?? null;
    $priority = $request['queue_priority'] ?? 0;
    $position = $request['position'] ?? null;
    foreach (['queue_joined_at' => [$joined, 1, time()], 'queue_priority' => [$priority, -2147483648, 2147483647],
        'position' => [$position, 1, 2147483647]] as $field => [$value, $min, $max]) {
        if ($value !== null && ((!is_int($value) && !is_string($value))
            || !preg_match('/^-?[0-9]{1,10}$/D', (string)$value) || (int)$value < $min || (int)$value > $max)) {
            throw new InvalidArgumentException('INVALID_METADATA');
        }
    }
    return ['queue_joined_at' => $joined === null ? null : (int)$joined,
        'queue_priority' => (int)$priority, 'position' => $position === null ? null : (int)$position,
        'queue_id' => $queue, 'callback_number' => $number,
        'caller_id' => mb_substr($caller, 0, 50, 'UTF-8'), 'uniqueid' => $uniqueid];
}

function qcallback_request_failure(string $error): array {
    return ['success' => false, 'error' => $error, 'id' => null];
}

/** No SQL text, credentials, destination numbers or raw exception messages in logs. */
function qcallback_log_request_error(Throwable $error): void {
    error_log('Queue Callback: request storage error type=' . get_class($error)
        . ' code=' . preg_replace('/[^A-Za-z0-9_-]/', '_', (string)$error->getCode()));
}

function qcallback_create_request($db, array $request): array {
    try {
        $data = qcallback_validate_request($request);
    } catch (InvalidArgumentException $e) {
        return qcallback_request_failure($e->getMessage());
    }
    try {
        // One statement checks enabled configuration and queue existence while
        // inserting. Separate bound comparisons also avoid cross-table collation
        // mismatches between FreePBX queue IDs and this module's queue IDs.
        $stmt = $db->prepare("INSERT INTO queuecallback_requests
            (queue_id, caller_id, callback_number, status, time_requested, attempts,
             max_attempts, uniqueid, position, queue_joined_at, queue_priority)
            SELECT c.queue_id, ?, ?, 'pending', ?, 0, c.max_attempts, ?, ?, ?, ?
            FROM queuecallback_config c
            WHERE c.queue_id = ? AND c.enabled = 1 AND c.max_attempts > 0
              AND EXISTS (SELECT 1 FROM queues_config q WHERE q.extension = ?)");
        if (!$stmt->execute([$data['caller_id'], $data['callback_number'], time(),
            $data['uniqueid'], $data['position'], $data['queue_joined_at'], $data['queue_priority'], $data['queue_id'], $data['queue_id']])) {
            error_log('Queue Callback: request insert returned failure');
            return qcallback_request_failure('DB_ERROR');
        }
        if ($stmt->rowCount() !== 1) { return qcallback_request_failure('QUEUE_UNAVAILABLE'); }
        $id = (string)$db->lastInsertId();
        if (!ctype_digit($id) || (int)$id < 1) {
            error_log('Queue Callback: request insert returned no valid ID');
            return qcallback_request_failure('DB_ERROR');
        }
        return ['success' => true, 'error' => '', 'id' => $id];
    } catch (Throwable $e) {
        qcallback_log_request_error($e);
        return qcallback_request_failure('DB_ERROR');
    }
}

/**
 * $exchange sends one AGI command and returns its response. Keeping the transport
 * and DB factory injectable lets tests exercise the same code without live calls.
 */
function qcallback_store_agi(array $env, callable $exchange, callable $database): array {
    $set = function (string $name, string $value) use ($exchange): void {
        $reply = $exchange('SET VARIABLE ' . $name . ' "' . addcslashes($value, "\\\"") . '"');
        if (!preg_match('/^200 result=1\s*$/D', $reply)) { throw new RuntimeException('AGI_ERROR'); }
    };
    $get = function (string $name) use ($exchange): ?string {
        $reply = $exchange('GET VARIABLE ' . $name);
        if (preg_match('/^200 result=1 \((.*)\)$/D', $reply, $m)) { return $m[1]; }
        if ($reply === '200 result=0') { return null; }
        throw new RuntimeException('AGI_ERROR');
    };
    $result = qcallback_request_failure('AGI_ERROR');
    try {
        $set('QCB_STORE_SUCCESS', '0');
        $set('QCB_REQUEST_ID', '');
        $set('QCB_STORE_ERROR', 'AGI_ERROR');
        $confirmed = $get('QCB_CONFIRMED');
        $source = $get('QCB_CONFIRM_SOURCE');
        if ($confirmed !== '1' || !in_array($source, ['dtmf', 'detected'], true)
            || ($source === 'dtmf' && ($get('CONFIRM2') !== '1' || $get('READSTATUS') !== 'OK'))) {
            $result = qcallback_request_failure('NOT_CONFIRMED');
        } else {
            try {
                $data = qcallback_validate_request([
                    'queue_id' => $get('CALLBACK_QUEUE'),
                    'queue_joined_at' => $get('QUEUEJOINTIME') ?: null,
                    'queue_priority' => $get('QUEUE_PRIO') ?? 0,
                    'position' => $get('QUEUEPOSITION') ?: null,
                    // Only an unset variable falls back to caller ID. An
                    // explicitly empty alternate number is invalid.
                    'callback_number' => $get('CALLBACK_NUMBER') ?? ($env['agi_callerid'] ?? ''),
                    'caller_id' => $env['agi_callerid'] ?? '',
                    'uniqueid' => $env['agi_uniqueid'] ?? '',
                ]);
                try {
                    $db = $database();
                    $allowDetected = false;
                    if ($source === 'detected') {
                        $setting = $db->prepare('SELECT confirm_number FROM queuecallback_config WHERE queue_id = ? AND enabled = 1');
                        $setting->execute([$data['queue_id']]);
                        $allowDetected = (string)$setting->fetchColumn() === '0';
                    }
                    $result = $source === 'detected' && !$allowDetected
                        ? qcallback_request_failure('NOT_CONFIRMED') : qcallback_create_request($db, $data);
                } catch (Throwable $e) {
                    qcallback_log_request_error($e);
                    $result = qcallback_request_failure('DB_ERROR');
                }
            } catch (InvalidArgumentException $e) {
                $result = qcallback_request_failure($e->getMessage());
            }
        }
        $set('QCB_STORE_ERROR', $result['error']);
        $set('QCB_REQUEST_ID', $result['id'] ?? '');
        // Set success last, after the insert and ID have been confirmed.
        $set('QCB_STORE_SUCCESS', $result['success'] ? '1' : '0');
    } catch (Throwable $e) {
        error_log('Queue Callback: request AGI transport failed');
        // The dialplan also resets success before invoking AGI. If the channel
        // goes away, do not perform another insert or retry within this AGI.
    }
    return $result;
}

/** Check configuration through FreePBX's shared connection; never emit DB details. */
function qcallback_check_agi(array $env, callable $exchange, callable $database): array {
    $set = function (string $name, string $value) use ($exchange): void {
        $reply = $exchange('SET VARIABLE ' . $name . ' "' . addcslashes($value, "\\\"") . '"');
        if (!preg_match('/^200 result=1\s*$/D', $reply)) { throw new RuntimeException('AGI_ERROR'); }
    };
    $result = ['enabled' => false, 'error' => 'AGI_ERROR'];
    try {
        $set('QUEUE_CALLBACK_ENABLED', '0');
        $set('QUEUE_CALLBACK_KEY', '');
        $set('QCB_CHECK_ERROR', 'AGI_ERROR');
        $reply = $exchange('GET VARIABLE CALLBACK_QUEUE');
        if (preg_match('/^200 result=1 \((.*)\)$/D', $reply, $m)) { $queue = $m[1]; }
        elseif ($reply === '200 result=0') { $queue = $env['agi_dnid'] ?? ''; }
        else { throw new RuntimeException('AGI_ERROR'); }
        $key = '';
        if (!is_string($queue) || !preg_match('/^[0-9]{1,50}$/D', $queue)) {
            $result['error'] = 'INVALID_QUEUE';
        } else {
            try {
                $db = $database();
                $stmt = $db->prepare("SELECT c.enabled, c.callback_key FROM queuecallback_config c
                    WHERE c.queue_id = ? AND EXISTS (SELECT 1 FROM queues_config q WHERE q.extension = ?)");
                if (!$stmt->execute([$queue, $queue])) { throw new RuntimeException('DB_ERROR'); }
                $config = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$config || (int)$config['enabled'] !== 1) { $result['error'] = 'QUEUE_UNAVAILABLE'; }
                elseif (!is_string($config['callback_key']) || !preg_match('/^[0-9*#]$/D', $config['callback_key'])) {
                    $result['error'] = 'INVALID_CONFIG';
                } else {
                    $key = $config['callback_key'];
                    $result = ['enabled' => true, 'error' => ''];
                }
            } catch (Throwable $e) {
                qcallback_log_request_error($e);
                $result = ['enabled' => false, 'error' => 'DB_ERROR'];
            }
        }
        $set('QUEUE_CALLBACK_KEY', $key);
        $set('QCB_CHECK_ERROR', $result['error']);
        // Permission to request a callback is published only after all checks
        // and other result variables have been successfully returned.
        $set('QUEUE_CALLBACK_ENABLED', $result['enabled'] ? '1' : '0');
    } catch (Throwable $e) {
        error_log('Queue Callback: check AGI transport failed');
        $result = ['enabled' => false, 'error' => 'AGI_ERROR'];
    }
    return $result;
}
