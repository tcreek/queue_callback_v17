<?php
/** Callback settings and route selection. GPL-3.0-or-later. */

function qcallback_route($db, int $id): ?array {
    $stmt = $db->prepare('SELECT route_id, name, time_group_id, timezone FROM outbound_routes WHERE route_id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** Validate settings before saving or emitting them as dialplan text. */
function qcallback_return_confirmation_settings(array $config): array {
    $values = [];
    foreach (['return_confirm_timeout' => [10, 1, 60], 'return_confirm_repeats' => [1, 0, 5]] as $key => [$default, $min, $max]) {
        $value = filter_var($config[$key] ?? $default, FILTER_VALIDATE_INT,
            ['options' => ['min_range' => $min, 'max_range' => $max]]);
        if ($value === false) { throw new InvalidArgumentException("$key must be an integer from $min to $max"); }
        $values[$key] = $value;
    }
    return $values;
}

function qcallback_validate_settings($db, array $config): array {
    $config = array_replace($config, qcallback_return_confirmation_settings($config));
    $requestKey = (string)($config['callback_key'] ?? '#');
    if (!preg_match('/^[0-9*#]$/D', $requestKey)) {
        throw new InvalidArgumentException('Callback Key must be a single DTMF key');
    }
    $config['callback_key'] = $requestKey;
    $config['confirm_number'] = (string)($config['confirm_number'] ?? 1);
    if (!in_array($config['confirm_number'], ['0', '1'], true)) {
        throw new InvalidArgumentException('Confirm Callback Number must be Yes or No');
    }
    $config['confirm_number'] = (int)$config['confirm_number'];
    $key = (string)($config['alt_number_key'] ?? '2');
    if ($key !== '' && !preg_match('/^[0-9*#]$/D', $key)) {
        throw new InvalidArgumentException('Different Number Key must be a single DTMF key or None');
    }
    if ($config['confirm_number'] && $key === '1') {
        throw new InvalidArgumentException('Different Number Key cannot be 1; 1 confirms the number');
    }
    $config['alt_number_key'] = $key;
    $route = (string)($config['outbound_route_id'] ?? 0);
    if (!ctype_digit($route) || strlen($route) > 10 || (float)$route > 2147483647) {
        throw new InvalidArgumentException('Invalid Outbound Route selection');
    }
    $config['outbound_route_id'] = (int)$route;
    if (!empty($config['enabled']) && (int)$route > 0 && !qcallback_route($db, (int)$route)) {
        throw new InvalidArgumentException('The selected Outbound Route no longer exists; choose an available route or Automatic');
    }
    return $config;
}

/** Route the customer leg identically in customer-first and agent-first modes. */
function qcallback_customer_channel($db, string $number, $routeId): string {
    if (!preg_match('/^\+?[0-9]{1,50}$/D', $number)) {
        throw new InvalidArgumentException('Callback number must contain digits, with an optional leading +');
    }
    // Extension length does not identify an internal destination. Let FreePBX
    // handle registered users' technology, forwarding and extension settings.
    $stmt = $db->prepare('SELECT extension FROM users WHERE extension = ?');
    $stmt->execute([$number]);
    if ($stmt->fetchColumn() !== false) {
        return "Local/{$number}@from-internal/n";
    }
    $rawRoute = (string)($routeId ?? 0);
    if (!ctype_digit($rawRoute) || strlen($rawRoute) > 10 || (float)$rawRoute > 2147483647) {
        throw new InvalidArgumentException('Invalid Outbound Route selection');
    }
    $id = (int)$rawRoute;
    if ($id === 0) {
        return "Local/{$number}@from-internal/n";
    }
    if (!qcallback_route($db, $id)) {
        throw new RuntimeException("Selected Outbound Route $id no longer exists");
    }
    return "Local/{$number}@qcb-route-{$id}/n";
}

/**
 * Restrict the context to one FreePBX route. Mirror the time-qualified includes
 * used by core's outbound-allroutes, rather than bypassing its time windows.
 */
function qcallback_route_context(array $route, ?array $times = null): string {
    $id = (int)$route['route_id'];
    if ($id < 1) { throw new InvalidArgumentException('Invalid route ID'); }
    $context = "\n[qcb-route-$id]\n";
    if ($route['time_group_id'] !== null && $times === null) {
        return $context . "; Time-group configuration unavailable; route closed\n";
    }
    if (!$times) { return $context . "include => outrt-$id\n"; }
    $zone = (string)($route['timezone'] ?? '');
    if ($zone !== '' && $zone !== 'default' && !preg_match('~^[A-Za-z0-9_/+\-]+$~D', $zone)) {
        throw new InvalidArgumentException('Invalid route time zone');
    }
    foreach ($times as $time) {
        $window = (string)($time[1] ?? '');
        if ($window === '' || preg_match('/[\r\n;]/', $window)) {
            throw new InvalidArgumentException('Invalid route time window');
        }
        $context .= "include => outrt-$id,$window";
        if ($zone !== '' && $zone !== 'default') { $context .= ',' . $zone; }
        $context .= "\n";
    }
    return $context;
}

function qcallback_selected_route_contexts($db, array $configs): string {
    $contexts = '';
    $ids = array_unique(array_map(function ($config) {
        return (int)($config['outbound_route_id'] ?? 0);
    }, $configs));
    foreach ($ids as $id) {
        if ($id <= 0) { continue; }
        $route = qcallback_route($db, $id);
        if (!$route) {
            $contexts .= "\n[qcb-route-$id]\n; Selected route no longer exists; route closed\n";
            continue;
        }
        $times = null;
        if ($route['time_group_id'] !== null && function_exists('timeconditions_timegroups_get_times')) {
            $result = timeconditions_timegroups_get_times($route['time_group_id'], true);
            $times = is_array($result) ? $result : [];
        }
        $contexts .= qcallback_route_context($route, $times);
    }
    return $contexts;
}
