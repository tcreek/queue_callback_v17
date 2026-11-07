<?php
/** Isolated request-menu and route checks; never writes Asterisk configuration. */
require_once(dirname(__DIR__) . '/callback_dialplan.php');
require_once(dirname(__DIR__) . '/callback_settings.php');
function expect_setting($condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
}

/** Execute the generated request menu with scripted input, without a call. */
function request_flow(array $config, array $digits, ?bool $enabled = true, string $storage = 'SUCCESS'): array {
    $plan = qcallback_request_dialplan($config);
    $contexts = [];
    $labels = [];
    $context = '';
    $extension = '';
    foreach (explode("\n", $plan) as $line) {
        $line = trim($line);
        if (preg_match('/^\[([^]]+)\]$/', $line, $m)) { $context = $m[1]; continue; }
        if (preg_match('/^exten => ([^,]+),([^,]+),(.*)$/', $line, $m)) {
            $extension = $m[1]; $priority = $m[2]; $command = $m[3];
        } elseif (preg_match('/^same => ([^,]+),(.*)$/', $line, $m)) {
            $priority = $m[1]; $command = $m[2];
        } else { continue; }
        $index = count($contexts[$context][$extension] ?? []);
        if (preg_match('/^n\(([^)]+)\)$/', $priority, $m)) { $labels[$context][$extension][$m[1]] = $index; }
        $contexts[$context][$extension][] = $command;
    }
    $vars = ['QUEUE_CALLBACK_ENABLED' => '1', 'CALLERID(num)' => '5551234567', 'QCB_STORE_SUCCESS' => '1', 'QCB_REQUEST_ID' => '999'];
    $expand = function (string $text) use (&$vars): string {
        for ($i = 0; $i < 5 && strpos($text, '${') !== false; $i++) {
            $text = preg_replace_callback('/\$\{([^{}]+)\}/', function ($m) use (&$vars) {
                if (preg_match('/^FILTER\(0-9,(.*)\)$/', $m[1], $filter)) {
                    return preg_replace('/[^0-9]/', '', $filter[1]);
                }
                return (string)($vars[$m[1]] ?? '');
            }, $text);
        }
        return $text;
    };
    $context = 'queuecallback-exit-' . $config['queue_id'];
    $extension = $config['callback_key'];
    $index = 0;
    $stored = [];
    $reads = 0;
    $spoken = 0;
    $played = [];
    $jump = function (string $target) use (&$context, &$extension, &$index, $labels): void {
        $parts = explode(',', $target);
        if (count($parts) === 3) { [$context, $extension, $priority] = $parts; }
        elseif (count($parts) === 2) { [$extension, $priority] = $parts; }
        else { $priority = $parts[0]; }
        $index = ctype_digit($priority) ? (int)$priority - 1 : ($labels[$context][$extension][$priority] ?? -1);
        if ($index < 0) { throw new RuntimeException('Unknown menu target: ' . $target); }
    };
    for ($step = 0; $step < 150; $step++) {
        $command = $contexts[$context][$extension][$index] ?? null;
        if ($command === null) { throw new RuntimeException("Missing menu priority $context,$extension,$index"); }
        expect_setting(preg_match('/^(\w+)\((.*)\)$/', $command, $m), 'Invalid generated application');
        $app = $m[1]; $args = $expand($m[2]); $index++;
        if ($app === 'Goto') { $jump($args); }
        elseif ($app === 'GotoIf') {
            expect_setting(preg_match('/^\$\["([^"]*)" (=?=|!=) "([^"]*)"\]\?(.*)$/', $args, $test), 'Invalid generated condition: ' . $args);
            $truth = $test[2] === '!=' ? $test[1] !== $test[3] : $test[1] === $test[3];
            $branches = explode(':', $test[4], 2);
            $target = $branches[$truth ? 0 : 1] ?? '';
            if ($target !== '') { $jump($target); }
        } elseif ($app === 'Set') {
            [$name, $value] = explode('=', $args, 2); $vars[$name] = $value;
        } elseif ($app === 'Read') {
            $fields = explode(',', $args); $reads++;
            if (!$digits) { throw new RuntimeException('Unexpected extra prompt'); }
            $digit = array_shift($digits);
            if ($digit === 'HANGUP') { break; }
            if ($fields[0] === 'CONFIRM1') {
                expect_setting($fields[3] === 'nt()', 'Single-key menu must accept # as input');
            }
            $vars[$fields[0]] = $digit;
            $vars['READSTATUS'] = $digit === '' ? 'TIMEOUT' : 'OK';
        } elseif ($app === 'AGI') {
            if ($args === 'queuecallback-check.agi') { if ($enabled !== null) { $vars['QUEUE_CALLBACK_ENABLED'] = $enabled ? '1' : '0'; } }
            elseif ($args === 'queuecallback-store.agi') {
                if ($storage === 'SUCCESS') {
                    $stored[] = $vars['CALLBACK_NUMBER'];
                    $vars['QCB_STORE_SUCCESS'] = '1';
                    $vars['QCB_REQUEST_ID'] = '42';
                    $vars['QCB_STORE_ERROR'] = '';
                } elseif ($storage !== 'MISSING') {
                    $vars['QCB_STORE_SUCCESS'] = '0';
                    $vars['QCB_STORE_ERROR'] = $storage;
                }
            }
            else { throw new RuntimeException('Unexpected AGI'); }
        } elseif ($app === 'SayDigits') { $spoken++; }
        elseif ($app === 'Hangup' || $app === 'Return') { break; }
        elseif ($app === 'Playback') {
            $played[] = $args; $vars['PLAYBACKSTATUS']='SUCCESS';
            if ($args === ($config['confirm_prompt_file'] ?? 'custom/callback_confirm')) {
                while (($digits[0] ?? '') === 'EARLY1') { array_shift($digits); }
                if (($digits[0] ?? '') === 'AUDIO_ERROR') { array_shift($digits); $vars['PLAYBACKSTATUS']='FAILED'; }
            }
        }
        else { throw new RuntimeException('Unexpected menu application: ' . $app); }
    }
    expect_setting($step < 150, 'Menu loop did not terminate');
    return ['stored' => $stored, 'reads' => $reads, 'spoken' => $spoken, 'vars' => $vars, 'played' => $played];
}

$config = ['queue_id' => '3007', 'callback_key' => '#', 'alt_number_key' => '7',
    'confirm_number' => 1, 'breakout_context' => 'ivr-4',
    'confirm_message_file' => 'custom/number', 'confirm_prompt_file' => 'custom/confirm',
    'alt_message_file' => 'custom/alternate', 'initiated_message_file' => 'custom/received'];
$flow = request_flow(array_replace($config, ['confirm_number' => 0]), []);
expect_setting($flow['stored'] === ['5551234567'] && $flow['reads'] === 0 && $flow['spoken'] === 0,
    'Confirmation disabled must store the detected number without prompts');
foreach ([['1',''], ['1','9'], ['1','*'], ['1','#'], ['1','HANGUP']] as $input) {
    expect_setting(request_flow($config, $input)['stored']===[], 'Enabled confirmation must reject missing or wrong final approval');
}
expect_setting(request_flow($config, ['1','EARLY1',''])['stored']===[], 'A digit during prompt playback cannot approve the request');
expect_setting(request_flow($config, ['1','EARLY1','1'])['stored']===['5551234567'], 'A fresh key after the full prompt must approve');
expect_setting(request_flow($config, ['1','AUDIO_ERROR'])['stored']===[], 'Missing confirmation audio must fail closed');
$flow = request_flow($config, ['1', '1']);
expect_setting($flow['stored'] === ['5551234567'] && $flow['reads'] === 2, 'Confirmation must require both choices');
foreach (['0', '7', '*', '#'] as $key) {
    $flow = request_flow(array_replace($config, ['alt_number_key' => $key]), [$key, '5557654321', '1', '1']);
    expect_setting($flow['stored'] === ['5557654321'] && $flow['spoken'] === 2,
        'Selected alternate key must enter and confirm the new number: ' . $key);
}
$flow = request_flow(array_replace($config, ['callback_key' => '0']), ['1', '1']);
expect_setting($flow['stored'] === ['5551234567'], 'Callback key 0 must remain usable');
foreach ([[''], ['HANGUP'], ['1', ''], ['1', 'HANGUP']] as $digits) {
    expect_setting(request_flow($config, $digits)['stored'] === [], 'Timeout/hangup must cancel');
}
expect_setting(request_flow($config, ['9', '1', '1'])['stored'] === ['5551234567'], 'Invalid choice must replay without storing');
expect_setting(request_flow(array_replace($config, ['alt_number_key' => '']), ['7', '1', '1'])['stored'] === ['5551234567'], 'None must disable alternate entry');
expect_setting(request_flow($config, ['7', '*', '5557654321', '1', '1'])['stored'] === ['5557654321'], 'Empty filtered input must be requested again');
expect_setting(request_flow($config, [], false)['stored'] === [], 'Disabled queue must not store');
expect_setting(strpos(qcallback_request_dialplan($config), 'include => ivr-4') !== false, 'Breakout IVR include lost');
echo "PASS: generated menu flows honor confirmation, alternate keys, cancellation, invalid input and key 0\n";
foreach ([0, 1] as $confirm) {
    foreach (['DB_ERROR', 'QUEUE_UNAVAILABLE', 'INVALID_NUMBER', 'MISSING'] as $storage) {
        $flow = request_flow(array_replace($config, ['confirm_number' => $confirm]), $confirm ? ['1', '1'] : [], true, $storage);
        expect_setting($flow['stored'] === [] && $flow['vars']['QCB_STORE_SUCCESS'] === '0'
            && $flow['vars']['QCB_REQUEST_ID'] === '', 'Failed/missing AGI retained stale success');
        expect_setting($flow['played'] === array_merge($confirm ? ['custom/confirm'] : [], ['im-sorry', 'an-error-has-occurred', 'please-try-call-later']),
            'Storage failure played a success announcement');
    }
}
$flow = request_flow($config, ['1', '1']);
expect_setting(in_array('custom/received', $flow['played'], true), 'Saved request did not play success');
$missingCheck = request_flow($config, [], null);
expect_setting(!$missingCheck['stored'] && !in_array('custom/received', $missingCheck['played'], true),
    'Missing check AGI allowed callback storage');
echo "PASS: storage failures and missing AGI take failure announcement path, never stale success\n";


require_once('/etc/freepbx.conf');
$db = FreePBX::Database();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TEMPORARY TABLE users (extension VARCHAR(50) PRIMARY KEY)');
$db->exec('CREATE TEMPORARY TABLE outbound_routes (route_id INT PRIMARY KEY, name VARCHAR(50), time_group_id INT NULL, timezone VARCHAR(50))');
$db->exec('CREATE TEMPORARY TABLE queuecallback_config (
    queue_id VARCHAR(50) PRIMARY KEY, enabled INT, callback_key VARCHAR(10),
    alt_number_key VARCHAR(10), confirm_number INT, outbound_route_id INT,
    announce_id VARCHAR(100) DEFAULT NULL, announce_frequency INT DEFAULT 0,
    confirm_message_id VARCHAR(100) DEFAULT NULL, alt_message_id VARCHAR(100) DEFAULT NULL,
    return_message_id VARCHAR(100) DEFAULT NULL, initiated_message_id VARCHAR(100) DEFAULT NULL,
    confirm_prompt_id VARCHAR(100) DEFAULT NULL)');
$db->exec('CREATE TEMPORARY TABLE queues_config (extension VARCHAR(50), ivr_id INT)');
$db->exec("INSERT INTO users VALUES ('100'), ('123456')");
$db->exec("INSERT INTO outbound_routes VALUES (7, 'Selected', NULL, 'default'), (8, 'Other', NULL, 'default')");
try {
    expect_setting(qcallback_customer_channel($db, '5551234567', 7) === 'Local/5551234567@qcb-route-7/n', 'Selected external route ignored');
    expect_setting(qcallback_customer_channel($db, '5551234567', 8) === 'Local/5551234567@qcb-route-8/n', 'Changing route had no effect');
    expect_setting(qcallback_customer_channel($db, '5551234567', 0) === 'Local/5551234567@from-internal/n', 'Automatic route order ignored');
    expect_setting(qcallback_customer_channel($db, '123456', 7) === 'Local/123456@from-internal/n', 'Long internal extension sent externally');
    expect_setting(qcallback_customer_channel($db, '1234', 7) === 'Local/1234@qcb-route-7/n', 'Short external number assumed to be an extension');
    expect_setting(qcallback_customer_channel($db, '100', 999) === 'Local/100@from-internal/n', 'Selected route must not affect internal extensions');
    try { qcallback_customer_channel($db, '5551234567', 999); throw new LogicException('Deleted route silently fell back'); }
    catch (RuntimeException $e) { expect_setting(!($e instanceof LogicException), $e->getMessage()); }
    echo "PASS: selected/automatic external routing, real internal extensions and deleted-route rejection\n";

    $base = ['enabled' => 1, 'callback_key' => '#', 'confirm_number' => 1, 'alt_number_key' => '0', 'outbound_route_id' => 7];
    expect_setting(qcallback_validate_settings($db, $base)['alt_number_key'] === '0', 'Saving alternate key 0 lost it');
    foreach ([['alt_number_key' => '1'], ['alt_number_key' => '12'], ['outbound_route_id' => 'bad'],
        ['outbound_route_id' => 999], ['confirm_number' => 2]] as $change) {
        $rejected = false;
        try { qcallback_validate_settings($db, array_replace($base, $change)); }
        catch (InvalidArgumentException $e) { $rejected = true; }
        expect_setting($rejected, 'Invalid setting was accepted: ' . json_encode($change));
    }
    expect_setting(qcallback_validate_settings($db, array_replace($base, ['enabled' => 0, 'outbound_route_id' => 999]))['enabled'] === 0,
        'Deleted route must not prevent disabling a queue');
    expect_setting(qcallback_validate_settings($db, array_replace($base, ['alt_number_key' => '']))['alt_number_key'] === '', 'None not saved');
    echo "PASS: settings validation preserves zero/None and rejects reserved keys and missing routes\n";

    $route = qcallback_route($db, 7);
    $context = qcallback_route_context($route);
    expect_setting(strpos($context, 'include => outrt-7') !== false
        && strpos($context, 'outrt-8') === false && strpos($context, 'from-internal') === false, 'Restricted route context leaked other routing');
    $route['time_group_id'] = 4; $route['timezone'] = 'America/Mexico_City';
    $context = qcallback_route_context($route, [[0, '09:00-17:00,mon-fri,*,*']]);
    expect_setting(strpos($context, 'include => outrt-7,09:00-17:00,mon-fri,*,*,America/Mexico_City') !== false, 'Route time window/timezone lost');
    expect_setting(strpos(qcallback_route_context($route), 'include =>') === false, 'Unavailable time configuration opened route');
    $contexts = qcallback_selected_route_contexts($db, [['outbound_route_id' => 7], ['outbound_route_id' => 7], ['outbound_route_id' => 0]]);
    expect_setting(substr_count($contexts, '[qcb-route-7]') === 1, 'Selected context duplicated');
    echo "PASS: selected-route contexts preserve time windows and avoid fallback routes\n";

    // Exercise the real config loader, including PHP's formerly falsey key 0.
    $db->exec("INSERT INTO queues_config VALUES ('3007', 4)");
    $db->exec("INSERT INTO queuecallback_config (queue_id, enabled, callback_key, alt_number_key, confirm_number, outbound_route_id)
        VALUES ('3007', 1, '0', '0', 0, 7)");
    require_once(dirname(__DIR__) . '/Qcallback.class.php');
    $module = new FreePBX\modules\Qcallback((object)['Database' => $db]);
    $method = new ReflectionMethod($module, 'getEnabledQueuesWithConfig');
    $method->setAccessible(true);
    $loaded = $method->invoke($module);
    expect_setting(count($loaded) === 1 && $loaded[0]['callback_key'] === '0'
        && $loaded[0]['alt_number_key'] === '0' && $loaded[0]['confirm_number'] === 0
        && $loaded[0]['outbound_route_id'] === 7 && $loaded[0]['breakout_context'] === 'ivr-4',
        'Real config loader changed saved settings');
    expect_setting(request_flow($loaded[0], [])['stored'] === ['5551234567'], 'Loaded confirmation setting not honored');
    echo "PASS: real configuration loading preserves route, confirmation and zero-valued keys\n";
    // Stop immediately after the real saver binds its INSERT. This covers the
    // settings merge and SQL mapping without running cron/dialplan side effects.
    $capture = (object)['sql'=>null, 'params'=>null];
    $savingDb = new class($capture) {
        function __construct(private $capture) {}
        function prepare($sql) {
            return new class($sql, $this->capture) {
                function __construct(private $sql, private $capture) {}
                function execute($params) {
                    if (str_contains($this->sql, 'INSERT INTO queuecallback_config')) {
                        $this->capture->sql=$this->sql; $this->capture->params=$params;
                        throw new LogicException('Captured configuration save');
                    }
                }
                function fetch($mode) {
                    return str_contains($this->sql,'SELECT extension') ? ['extension'=>'3007']
                        : ['queue_id'=>'3007','enabled'=>0,'return_confirm_timeout'=>23,'return_confirm_repeats'=>2];
                }
            };
        }
    };
    $savingModule = new FreePBX\modules\Qcallback((object)['Database'=>$savingDb]);
    try { $savingModule->setQueueCallbackConfig('3007', ['return_confirm_repeats'=>'0']); }
    catch (LogicException $e) { expect_setting($e->getMessage()==='Captured configuration save','Unexpected saver error'); }
    expect_setting(count($capture->params ?? [])===19 && substr_count($capture->sql,'?')===19
        && $capture->params[17]===23 && $capture->params[18]===0,
        'Config saver must preserve omitted timeout and bind an explicit zero repeat');
    echo "PASS: real saver binds confirmation settings and preserves zero repeats and omitted timeout\n";
} finally {
    $db->exec('DROP TEMPORARY TABLE users, outbound_routes, queuecallback_config, queues_config');
}
