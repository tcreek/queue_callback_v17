<?php
/** Live queue snapshots and conservative dispatch decisions. GPL-3.0-or-later. */

function qcallback_upgrade_queue_schema($db): void {
    $columns = $db->query('SHOW COLUMNS FROM queuecallback_requests')->fetchAll(PDO::FETCH_COLUMN);
    foreach (['queue_joined_at' => 'INT DEFAULT NULL', 'queue_priority' => 'INT NOT NULL DEFAULT 0',
        'reserved_agent' => 'VARCHAR(64) DEFAULT NULL', 'fifo_connected_at' => 'INT DEFAULT NULL'] as $column => $definition) {
        if (!in_array($column, $columns, true)) {
            $db->exec("ALTER TABLE queuecallback_requests ADD COLUMN $column $definition");
        }
    }
    if (!$db->query("SHOW INDEX FROM queuecallback_requests WHERE Key_name = 'idx_qcb_dispatch'")->fetch()) {
        $db->exec('ALTER TABLE queuecallback_requests ADD INDEX idx_qcb_dispatch (status, queue_id, reserved_agent)');
    }
}

function qcallback_queue_int($value, int $min = 0, int $max = 2147483647): int {
    if ((!is_string($value) && !is_int($value)) || !preg_match('/^-?[0-9]{1,10}$/D', (string)$value)
        || (int)$value < $min || (int)$value > $max) { throw new UnexpectedValueException('Invalid queue integer'); }
    return (int)$value;
}

function qcallback_agent_key(array $member): string {
    $state = (string)(($member['StateInterface'] ?? '') !== '' ? $member['StateInterface'] : $member['Location']);
    if (preg_match('~^(?:hint:([0-9]+)@ext-local|Local/([0-9]+)@from-queue(?:/n)?|(?:PJSIP|SIP|IAX2)/([0-9]+))$~D', $state, $m)) {
        $extension = '';
        foreach (array_slice($m, 1) as $part) { if ($part !== '') { $extension = $part; break; } }
        $state = 'extension:' . $extension;
    } elseif (str_starts_with($state, 'Local/')) { $state = preg_replace('~/n$~', '', $state); }
    return hash('sha256', $state);
}

/** A complete list, not CLI summaries or configured membership, proves state. */
function qcallback_queue_snapshot(array $events, array $complete, int $observed): array {
    if (($complete['Event'] ?? '') !== 'QueueStatusComplete'
        || qcallback_queue_int($complete['ListItems'] ?? null) !== count($events)) {
        throw new UnexpectedValueException('Incomplete QueueStatus list');
    }
    $queues = [];
    foreach ($events as $event) {
        if (($event['Event'] ?? '') !== 'QueueParams') { continue; }
        $q = $event['Queue'] ?? '';
        if (!is_string($q) || $q === '' || isset($queues[$q])) { throw new UnexpectedValueException('Invalid QueueParams'); }
        $queues[$q] = ['calls' => qcallback_queue_int($event['Calls'] ?? null), 'members' => [], 'entries' => []];
    }
    foreach ($events as $event) {
        $q = $event['Queue'] ?? '';
        if (!isset($queues[$q])) { throw new UnexpectedValueException('Queue without QueueParams'); }
        switch ($event['Event'] ?? '') {
            case 'QueueParams': break;
            case 'QueueMember':
                $location = $event['Location'] ?? '';
                if (!is_string($location) || strlen($location) > 255
                    || !preg_match('~^[A-Za-z][A-Za-z0-9_]*/[A-Za-z0-9_@./:+-]+$~D', $location)
                    || isset($queues[$q]['members'][$location])) {
                    throw new UnexpectedValueException('Invalid member interface');
                }
                foreach (['Status' => [0, 8], 'Paused' => [0, 1], 'InCall' => [0, 1],
                    'LastCall' => [0, 2147483647], 'Wrapuptime' => [0, 2147483647], 'Penalty' => [0, 2147483647]] as $field => $range) {
                    $event[$field] = qcallback_queue_int($event[$field] ?? null, $range[0], $range[1]);
                }
                $event['StateInterface'] = (string)($event['StateInterface'] ?? '');
                $event['agent_key'] = qcallback_agent_key($event);
                $queues[$q]['members'][$location] = $event;
                break;
            case 'QueueEntry':
                $event['Wait'] = qcallback_queue_int($event['Wait'] ?? null);
                $event['Priority'] = qcallback_queue_int($event['Priority'] ?? null, -2147483648);
                $event['Position'] = qcallback_queue_int($event['Position'] ?? null, 1);
                if (!is_string($event['Uniqueid'] ?? null) || $event['Uniqueid'] === ''
                    || isset($queues[$q]['entries'][$event['Position']])) { throw new UnexpectedValueException('Invalid queue entry'); }
                // Asterisk's Wait is integral seconds. Sampling time before the
                // action makes ties/latency favor the live caller conservatively.
                $event['joined_at'] = $observed - $event['Wait'];
                $queues[$q]['entries'][$event['Position']] = $event;
                break;
            default: throw new UnexpectedValueException('Unexpected QueueStatus event');
        }
    }
    foreach ($queues as $queue) {
        if (count($queue['entries']) !== $queue['calls']) { throw new UnexpectedValueException('Caller count mismatch'); }
        if ($queue['calls'] > 0) {
            $positions = array_keys($queue['entries']); sort($positions);
            if ($positions !== range(1, $queue['calls'])) { throw new UnexpectedValueException('Missing queue position'); }
        }
    }
    return ['observed_at' => $observed, 'queues' => $queues];
}

/** Read-only AMI actions, bounded waits, explicit ActionID and completion check. */
function qcallback_read_queue_state(string $queue, $manager = null, ?callable $send = null): ?array {
    if (!preg_match('/^[0-9]{1,50}$/D', $queue)) { return null; }
    try {
        $manager = $manager ?? FreePBX::create()->astman;
        if (!$manager || !is_resource($manager->socket)) { return null; }
        stream_set_timeout($manager->socket, 2);
        $action = 'qcb-' . bin2hex(random_bytes(12));
        $observed = time(); $deadline = microtime(true) + 5;
        $send = $send ?? function (string $action, array $parameters) use ($manager): void {
            $request = "Action: $action\r\n";
            foreach ($parameters as $name => $value) { $request .= "$name: $value\r\n"; }
            $request .= "\r\n";
            if (fwrite($manager->socket, $request) !== strlen($request)) { throw new RuntimeException('AMI write failed'); }
        };
        // The FreePBX send_request helper waits indefinitely for an initial
        // response on some versions. Read every packet with allow_timeout=true.
        $send('QueueStatus', ['ActionID' => $action]);
        $events = []; $complete = null; $ack = false;
        while (microtime(true) < $deadline && count($events) < 50000) {
            $event = $manager->wait_response(true, true);
            if (!$event) { return null; }
            if (($event['ActionID'] ?? '') !== $action) { continue; }
            if (isset($event['Response'])) {
                if ($event['Response'] !== 'Success' || $ack) { return null; }
                $ack = true; continue;
            }
            if (($event['Event'] ?? '') === 'QueueStatusComplete') { $complete = $event; break; }
            $events[] = $event;
        }
        if (!$ack || !$complete) { return null; }
        $snapshot = qcallback_queue_snapshot($events, $complete, $observed);
        if (!isset($snapshot['queues'][$queue])) { return null; }
        // FIFO-aware queue entries publish their arrival stamp before joining Queue.
        foreach ($snapshot['queues'][$queue]['entries'] as &$entry) {
            $channel=$entry['Channel'] ?? '';
            if (!is_string($channel) || !preg_match('~^[A-Za-z0-9_./@:+\-]+$~D',$channel)) {continue;}
            $stampId=$action.'-stamp-'.$entry['Position'];
            $send('GetVar',['ActionID'=>$stampId,'Channel'=>$channel,'Variable'=>'QCB_FIFO_JOINED_AT']);
            $stamp=null;
            while (microtime(true)<$deadline) {
                $reply=$manager->wait_response(true,true);
                if (!$reply) {return null;}
                if (($reply['ActionID']??'')===$stampId) {$stamp=$reply;break;}
            }
            if (($stamp['Response']??'')!=='Success') {return null;}
            if (($stamp['Value']??'')!=='') {$entry['joined_at']=qcallback_queue_int($stamp['Value'],1);}
        }
        unset($entry);
        // ready excludes inherited queue wrap-up too; QueueMember.Wrapuptime
        // alone is only the member override (zero means inherit).
        $readyId = $action . '-ready';
        $send('GetVar', ['ActionID' => $readyId, 'Variable' => "QUEUE_MEMBER($queue,ready)"]);
        $ready = null;
        while (microtime(true) < $deadline) {
            $reply = $manager->wait_response(true, true);
            if (!$reply) { return null; }
            if (($reply['ActionID'] ?? '') === $readyId) { $ready = $reply; break; }
        }
        if (($ready['Response'] ?? '') !== 'Success') { return null; }
        $snapshot['queues'][$queue]['ready'] = qcallback_queue_int($ready['Value'] ?? null);
        if (microtime(true) > $deadline) { return null; }
        return $snapshot;
    } catch (Throwable $e) {
        return null;
    } finally {
        if ($manager && is_resource($manager->socket)) { stream_set_timeout($manager->socket, 30); }
    }
}

/** Use the same age/tie rule at dispatch and immediately before a live member offer. */
function qcallback_callback_before_live(array $callback, array $entry): bool {
    $priority = (int)($callback['queue_priority'] ?? 0);
    $livePriority = (int)$entry['Priority'];
    if ($priority !== $livePriority) {return $priority > $livePriority;}
    $joined = (int)($callback['queue_joined_at'] ?? $callback['time_requested']);
    if ($joined !== (int)$entry['joined_at']) {return $joined < (int)$entry['joined_at'];}
    // Native unique IDs provide a stable tie-breaker for arrivals in the same second.
    $pattern = '/^([A-Za-z0-9_-]*?)([0-9]+)\.([0-9]+)$/D';
    if (preg_match($pattern,(string)($callback['uniqueid'] ?? ''),$a)
        && preg_match($pattern,(string)($entry['Uniqueid'] ?? ''),$b) && $a[1] === $b[1]) {
        foreach ([2,3] as $part) {
            $left=ltrim($a[$part],'0') ?: '0';$right=ltrim($b[$part],'0') ?: '0';
            $order = strlen($left) <=> strlen($right);
            if (!$order) {$order=strcmp($left,$right);}
            if ($order) {return $order < 0;}
        }
    }
    return false; // Legacy/ambiguous ties consistently favor the live caller.
}

/** Return an idle member or a reason to defer without consuming an attempt. */
function qcallback_queue_decision(array $callback, ?array $snapshot, array $reserved = [], ?int $now = null): array {
    $deny = function ($reason) { return ['ready' => false, 'reason' => $reason]; };
    $now = $now ?? time();
    $q = (string)$callback['queue_id'];
    if (!$snapshot || !isset($snapshot['observed_at'], $snapshot['queues'][$q])
        || $snapshot['observed_at'] > $now || $now - $snapshot['observed_at'] > 5) { return $deny('QUEUE_STATE_UNAVAILABLE'); }
    $queue = $snapshot['queues'][$q];
    $joined = (int)($callback['queue_joined_at'] ?? $callback['time_requested']);
    $priority = (int)($callback['queue_priority'] ?? 0);
    foreach ($queue['entries'] as $entry) {
        if (($callback['uniqueid'] ?? '') !== '' && $entry['Uniqueid'] === $callback['uniqueid']) { return $deny('ORIGINAL_CALL_PRESENT'); }
        if (!qcallback_callback_before_live($callback,$entry)) {
            return $deny('LIVE_CALLER_AHEAD');
        }
    }
    $idle = []; $clear = 0; $blocked = 0;
    foreach ($queue['members'] as $member) {
        if ($member['Status'] !== 1 || $member['Paused'] !== 0) { continue; }
        $known = $member['LastCall'] === 0 || ($member['Wrapuptime'] > 0 && $now - $member['LastCall'] >= $member['Wrapuptime']);
        $wrapping = $member['LastCall'] > 0 && $member['Wrapuptime'] > 0 && !$known;
        $clear += (int)$known; $blocked += (int)$wrapping;
        $member['wrap_clear'] = $known; $member['wrapping'] = $wrapping; $idle[] = $member;
    }
    $ready = $queue['ready'] ?? -1;
    if ($ready < $clear || $ready > count($idle) - $blocked) { return $deny('QUEUE_STATE_CHANGED'); }
    $inheritedClear = $ready === count($idle) - $blocked;
    $eligible = [];
    foreach ($idle as $member) {
        if ($member['InCall'] || $member['LastCall'] > $now || $member['wrapping']
            || (!$member['wrap_clear'] && !$inheritedClear) || in_array($member['agent_key'], $reserved, true)) { continue; }
        // Shared queue members stay available to live callers in other queues.
        $competing = false;
        foreach ($snapshot['queues'] as $otherId => $other) {
            if ((string)$otherId === $q || !$other['entries']) { continue; }
            foreach ($other['members'] as $otherMember) {
                if ($otherMember['agent_key'] === $member['agent_key']) { $competing = true; break 2; }
            }
        }
        if (!$competing) { $eligible[] = $member; }
    }
    if (!$eligible) { return $deny('NO_READY_MEMBER'); }
    usort($eligible, function ($a, $b) { return [$a['Penalty'], $a['LastCall'], $a['Location']] <=> [$b['Penalty'], $b['LastCall'], $b['Location']]; });
    return ['ready' => true, 'reason' => 'READY', 'member' => $eligible[0]];
}
