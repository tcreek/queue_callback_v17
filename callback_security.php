<?php
/** Numeric callback blocklist patterns. GPL-3.0-or-later. */

/** Compile a bounded numeric subset of Asterisk patterns, never arbitrary regex. */
function qcallback_compile_security_pattern(string $pattern): array {
    if ($pattern === '' || strlen($pattern) > 20) {
        throw new InvalidArgumentException('Pattern is required and must be at most 20 characters');
    }
    $wild = $pattern[0] === '_';
    $body = $wild ? substr($pattern, 1) : $pattern;
    // A leading + is an equivalent representation of the same destination.
    if (str_starts_with($body, '+')) { $body = substr($body, 1); }
    if ($body === '') { throw new InvalidArgumentException('Pattern must contain a number or numeric pattern'); }
    $regex = '';
    if (!$wild) {
        if (!ctype_digit($body)) { throw new InvalidArgumentException('Exact numbers must contain digits and an optional leading +'); }
        $regex = $body;
    } else {
        for ($i = 0, $length = strlen($body); $i < $length; $i++) {
            $token = $body[$i];
            if (ctype_digit($token)) { $regex .= $token; continue; }
            $classes = ['X' => '[0-9]', 'Z' => '[1-9]', 'N' => '[2-9]'];
            if (isset($classes[strtoupper($token)])) { $regex .= $classes[strtoupper($token)]; continue; }
            if ($token === '[') {
                $end = strpos($body, ']', $i + 1);
                if ($end === false) { throw new InvalidArgumentException('Unclosed digit set'); }
                $set = substr($body, $i + 1, $end - $i - 1);
                if (!preg_match('/^(?:[0-9](?:-[0-9])?)+$/D', $set)) {
                    throw new InvalidArgumentException('Sets support digits and ascending digit ranges only');
                }
                preg_match_all('/([0-9])-([0-9])/', $set, $ranges, PREG_SET_ORDER);
                foreach ($ranges as $range) {
                    if ($range[1] > $range[2]) { throw new InvalidArgumentException('Digit ranges must be ascending'); }
                }
                $regex .= '[' . $set . ']'; $i = $end; continue;
            }
            if (($token === '.' || $token === '!') && $i === $length - 1) {
                $regex .= $token === '.' ? '[0-9]+' : '[0-9]*'; continue;
            }
            throw new InvalidArgumentException('Use digits, X, Z, N, digit sets, and an optional final . or ! after _');
        }
    }
    return ['regex' => '~\A' . $regex . '\z~D', 'dialplan' => ($wild ? '_' : '') . $body,
        'dialplan_plus' => ($wild ? '_+' : '+') . $body];
}

function qcallback_validate_security_entry(array $data): array {
    $pattern = $data['pattern'] ?? null;
    if (!is_string($pattern)) { throw new InvalidArgumentException('Pattern must be text'); }
    qcallback_compile_security_pattern($pattern);
    $description = $data['description'] ?? '';
    if (!is_string($description) || trim($description) === '' || !mb_check_encoding($description, 'UTF-8')
        || mb_strlen($description, 'UTF-8') > 100 || preg_match('/[\x00-\x1f\x7f]/', $description)) {
        throw new InvalidArgumentException('Description is required and must be at most 100 characters without control characters');
    }
    $area = $data['area_code'] ?? '';
    if (!is_string($area) || !preg_match('/^(?:[0-9]{0,10}|NANP)$/D', $area)) {
        throw new InvalidArgumentException('Area/Country Code is an optional label of up to 10 digits or NANP');
    }
    $queue = $data['queue_id'] ?? '';
    if (!is_string($queue) || !preg_match('/^[0-9]{0,20}$/D', $queue)) {
        throw new InvalidArgumentException('Queue must be a numeric queue ID or blank for global');
    }
    return array_replace($data, ['pattern'=>$pattern, 'description'=>$description, 'area_code'=>$area, 'queue_id'=>$queue]);
}

function qcallback_security_blocks_number($db, string $number, ?string $queue = null): bool {
    if (strlen($number) > 50 || !preg_match('/^\+?[0-9]+$/D', $number)) {
        throw new InvalidArgumentException('Invalid callback destination');
    }
    $stmt = $db->prepare("SELECT pattern FROM queuecallback_security
        WHERE enabled = 1 AND (queue_id = '' OR queue_id = ?) ORDER BY sort_order, id");
    if (!$stmt->execute([$queue ?? ''])) { throw new RuntimeException('Cannot read callback blocklist'); }
    $patterns = [];
    // Validate all effective rules before deciding. A bad enabled legacy rule
    // defers dispatch for inspection; it must not silently disappear.
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $patterns[] = qcallback_compile_security_pattern($row['pattern'])['regex'];
    }
    $digits = ltrim($number, '+');
    foreach ($patterns as $regex) { if (preg_match($regex, $digits) === 1) { return true; } }
    return false;
}

/** Auxiliary contexts retain queue scope; actual callback enforcement is in PHP. */
function qcallback_security_dialplan(array $entries): string {
    $destination = 'app-blackhole,hangup,1';
    $groups = ['' => []];
    foreach ($entries as $entry) {
        if (!(int)$entry['enabled']) { continue; }
        $queue = (string)($entry['queue_id'] ?? '');
        if (!preg_match('/^[0-9]{0,20}$/D', $queue)) { throw new InvalidArgumentException('Invalid blocklist queue'); }
        $pattern = qcallback_compile_security_pattern($entry['pattern']);
        $groups[$queue][$pattern['dialplan']] ??= $destination;
        $groups[$queue][$pattern['dialplan_plus']] ??= $destination;
    }
    if (count($groups) === 1 && !$groups['']) { return ''; }
    $buf = "; Auto-generated by Queue Callback - Security Blocklist\n";
    foreach ($groups as $queue => $patterns) {
        $buf .= '[qcallback-security-block' . ((string)$queue === '' ? '' : '-' . $queue) . "]\n";
        if ((string)$queue !== '') { $buf .= "include => qcallback-security-block\n"; }
        foreach ($patterns as $pattern => $destination) { $buf .= "exten => $pattern,1,Goto($destination)\n"; }
        $buf .= "\n";
    }
    return $buf;
}
