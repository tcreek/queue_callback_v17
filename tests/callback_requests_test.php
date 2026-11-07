<?php
/** Request persistence/AGI checks: temporary tables, no live calls or config writes. */
require_once(dirname(__DIR__) . '/callback_requests.php');
function request_expect($condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
}

function simulated_store(array $vars, array $env, callable $factory, ?string $badGet = null): array {
    $commands = [];
    $dbCalls = 0;
    $result = qcallback_store_agi($env, function ($command) use (&$vars, &$commands, $badGet): string {
        $commands[] = $command;
        if (preg_match('/^SET VARIABLE ([A-Z0-9_]+) "(.*)"$/D', $command, $m)) {
            $vars[$m[1]] = stripcslashes($m[2]);
            return '200 result=1';
        }
        if (preg_match('/^GET VARIABLE ([A-Z0-9_]+)$/D', $command, $m)) {
            if ($m[1] === $badGet) { return '510 Invalid command'; }
            return array_key_exists($m[1], $vars) ? '200 result=1 (' . $vars[$m[1]] . ')' : '200 result=0';
        }
        throw new LogicException('Unexpected AGI command');
    }, function () use (&$dbCalls, $factory) { $dbCalls++; return $factory(); });
    return ['result' => $result, 'vars' => $vars, 'commands' => $commands, 'db_calls' => $dbCalls];
}

$request = ['queue_id' => '3007', 'callback_number' => '5551234567', 'caller_id' => '5551234567', 'uniqueid' => 'test.1'];
foreach (['', 'anonymous', '123abc', '123@from-internal', '123/456', '123*4', '123#4',
    '555 1234567', '+', '++15551234567', "123\nSetVar: X=1", str_repeat('1', 51), '+' . str_repeat('1', 50), []] as $number) {
    $rejected = false;
    try { qcallback_validate_request(array_replace($request, ['callback_number' => $number])); }
    catch (InvalidArgumentException $e) { $rejected = $e->getMessage() === 'INVALID_NUMBER'; }
    request_expect($rejected, 'Malformed number was accepted: ' . json_encode($number));
}
foreach (['', '3007;id', "3007\n", str_repeat('3', 51), []] as $queue) {
    $rejected = false;
    try { qcallback_validate_request(array_replace($request, ['queue_id' => $queue])); }
    catch (InvalidArgumentException $e) { $rejected = $e->getMessage() === 'INVALID_QUEUE'; }
    request_expect($rejected, 'Malformed queue was accepted');
}
foreach (['0', '100', '123456', '+15551234567', str_repeat('1', 50)] as $number) {
    request_expect(qcallback_validate_request(array_replace($request, ['callback_number' => $number]))['callback_number'] === $number,
        'Valid numeric destination was changed');
}
echo "PASS: strict destination/queue validation rejects injection and malformed input without rewriting numbers\n";

foreach ([['queue_joined_at'=>time()+60], ['queue_joined_at'=>'bad'], ['queue_priority'=>'2147483648'], ['position'=>'0']] as $metadata) {
    $rejected = false;
    try { qcallback_validate_request(array_replace($request, $metadata)); }
    catch (InvalidArgumentException $e) { $rejected = $e->getMessage() === 'INVALID_METADATA'; }
    request_expect($rejected, 'Malformed queue metadata accepted');
}

$vars = ['QCB_CONFIRMED' => '1', 'QCB_CONFIRM_SOURCE'=>'dtmf', 'CONFIRM2'=>'1', 'READSTATUS'=>'OK', 'CALLBACK_QUEUE' => '3007', 'CALLBACK_NUMBER' => '5551234567',
    'QCB_STORE_SUCCESS' => '1', 'QCB_REQUEST_ID' => '999'];
$env = ['agi_callerid' => '5551234567', 'agi_uniqueid' => 'test.agi'];
$neverDb = function () { throw new LogicException('Database should not be requested'); };
foreach ([['QCB_CONFIRMED' => '0'], ['QCB_CONFIRM_SOURCE'=>'bad'], ['QCB_CONFIRM_SOURCE'=>''], ['CONFIRM2'=>'9'], ['CONFIRM2'=>''], ['READSTATUS'=>'TIMEOUT'], ['READSTATUS'=>'ERROR'], ['CALLBACK_QUEUE' => '3007;id'], ['CALLBACK_NUMBER' => 'invalid'], ['CALLBACK_NUMBER' => '']] as $change) {
    $run = simulated_store(array_replace($vars, $change), $env, $neverDb);
    request_expect(!$run['result']['success'] && $run['db_calls'] === 0
        && $run['vars']['QCB_STORE_SUCCESS'] === '0' && $run['vars']['QCB_REQUEST_ID'] === '', 'Invalid request reached DB or retained stale success');
}
$run = simulated_store($vars, $env, $neverDb, 'CALLBACK_NUMBER');
request_expect($run['db_calls'] === 0 && $run['vars']['QCB_STORE_SUCCESS'] === '0', 'Broken AGI response reached DB');
$run = simulated_store($vars, $env, function () { throw new PDOException('sensitive-database-detail'); });
request_expect($run['vars']['QCB_STORE_ERROR'] === 'DB_ERROR' && $run['vars']['QCB_STORE_SUCCESS'] === '0', 'Bootstrap failure not reported');
echo "PASS: unconfirmed/invalid requests avoid DB; stale success, transport errors and DB initialization failures fail closed\n";

// Exercise the actual source AGI over stdin/stdout. Invalid input must be
// rejected before it tries to bootstrap FreePBX or access the real database.
$pipes = [];
$process = proc_open([PHP_BINARY, dirname(__DIR__) . '/agi-bin/queuecallback-store.agi'],
    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
request_expect(is_resource($process), 'Could not start source AGI');
$wireVars = array_replace($vars, ['CALLBACK_NUMBER' => 'invalid']);
try {
    fwrite($pipes[0], "agi_callerid: 5551234567\nagi_uniqueid: test.wire\n\n");
    fflush($pipes[0]);
    stream_set_timeout($pipes[1], 5);
    $count = 0;
    while (($command = fgets($pipes[1])) !== false) {
        request_expect(++$count < 50, 'AGI command loop did not finish');
        $command = rtrim($command, "\r\n");
        if (preg_match('/^SET VARIABLE ([A-Z0-9_]+) "(.*)"$/D', $command, $m)) {
            $wireVars[$m[1]] = stripcslashes($m[2]); $reply = '200 result=1';
        } elseif (preg_match('/^GET VARIABLE ([A-Z0-9_]+)$/D', $command, $m)) {
            $reply = isset($wireVars[$m[1]]) ? '200 result=1 (' . $wireVars[$m[1]] . ')' : '200 result=0';
        } else { throw new RuntimeException('Non-AGI output: ' . $command); }
        fwrite($pipes[0], $reply . "\n"); fflush($pipes[0]);
    }
    request_expect(!stream_get_meta_data($pipes[1])['timed_out'], 'Source AGI timed out');
    fclose($pipes[0]); fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]); fclose($pipes[2]);
    $exit = proc_close($process); $process = null;
    request_expect($exit === 0 && $stderr === '' && $wireVars['QCB_STORE_SUCCESS'] === '0'
        && $wireVars['QCB_STORE_ERROR'] === 'INVALID_NUMBER' && $wireVars['QCB_REQUEST_ID'] === '', 'Source AGI did not report invalid input cleanly');
} finally {
    foreach ($pipes as $pipe) { if (is_resource($pipe)) { fclose($pipe); } }
    if (is_resource($process)) { proc_terminate($process); proc_close($process); }
}
echo "PASS: actual AGI stdin/stdout reports invalid input with no DB connection\n";

require_once('/etc/freepbx.conf');
$db = FreePBX::Database();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TEMPORARY TABLE queuecallback_requests (
    id INT AUTO_INCREMENT PRIMARY KEY, queue_id VARCHAR(50), caller_id VARCHAR(50), callback_number VARCHAR(50),
    status VARCHAR(20), time_requested INT, attempts INT, max_attempts INT, uniqueid VARCHAR(50), position INT NULL, queue_joined_at INT NULL, queue_priority INT DEFAULT 0)');
$db->exec('CREATE TEMPORARY TABLE queuecallback_config (queue_id VARCHAR(50) PRIMARY KEY, enabled INT, max_attempts INT)');
$db->exec('CREATE TEMPORARY TABLE queues_config (extension VARCHAR(50) PRIMARY KEY)');
$db->exec("INSERT INTO queues_config VALUES ('3007'), ('3008'), ('3009'), ('3010')");
$db->exec("INSERT INTO queuecallback_config VALUES ('3007', 1, 4), ('3008', 0, 3), ('3009', 1, 0), ('3011', 1, 3)");
$db->exec('ALTER TABLE queuecallback_config ADD COLUMN confirm_number INT DEFAULT 1');
try {
    $result = qcallback_create_request($db, array_replace($request, ['callback_number' => '+15551234567']));
    request_expect($result['success'] && ctype_digit($result['id']), 'Valid request not stored');
    $row = $db->query('SELECT * FROM queuecallback_requests')->fetch(PDO::FETCH_ASSOC);
    request_expect($row['callback_number'] === '+15551234567' && $row['status'] === 'pending'
        && (int)$row['attempts'] === 0 && (int)$row['max_attempts'] === 4
        && $row['uniqueid'] === 'test.1' && $row['position'] === null, 'Stored request has wrong values');
    foreach (['3008', '3009', '3010', '3011', '9999'] as $queue) {
        $result = qcallback_create_request($db, array_replace($request, ['queue_id' => $queue]));
        request_expect(!$result['success'] && $result['error'] === 'QUEUE_UNAVAILABLE', 'Disabled/missing/orphan queue accepted');
    }
    request_expect((int)$db->query('SELECT COUNT(*) FROM queuecallback_requests')->fetchColumn() === 1, 'Rejected request inserted a row');
    $validated = qcallback_validate_request($request);
    $db->exec("UPDATE queuecallback_config SET enabled = 0 WHERE queue_id = '3007'");
    request_expect(qcallback_create_request($db, $validated)['error'] === 'QUEUE_UNAVAILABLE', 'Stale enabled state accepted');
    $db->exec("UPDATE queuecallback_config SET enabled = 1 WHERE queue_id = '3007'");
    echo "PASS: atomic insert requires existing enabled queue and snapshots its attempt limit\n";

    $before = (int)$db->query('SELECT COUNT(*) FROM queuecallback_requests')->fetchColumn();
    $metadataVars = array_replace($vars, ['QUEUEJOINTIME' => (string)(time()-120), 'QUEUE_PRIO' => '-2', 'QUEUEPOSITION' => '3']);
    $run = simulated_store($metadataVars, $env, function () use ($db) { return $db; });
    $id = $run['vars']['QCB_REQUEST_ID'];
    request_expect($run['result']['success'] && $run['vars']['QCB_STORE_SUCCESS'] === '1'
        && ctype_digit($id) && $run['vars']['QCB_STORE_ERROR'] === '' && $run['db_calls'] === 1, 'AGI did not report saved request');
    $saved = $db->query('SELECT queue_joined_at, queue_priority, position FROM queuecallback_requests WHERE id=' . (int)$id)->fetch(PDO::FETCH_ASSOC);
    request_expect((int)$saved['queue_joined_at'] === (int)$metadataVars['QUEUEJOINTIME']
        && (int)$saved['queue_priority'] === -2 && (int)$saved['position'] === 3, 'Original queue metadata lost');
    request_expect(end($run['commands']) === 'SET VARIABLE QCB_STORE_SUCCESS "1"', 'AGI success was not the final result update');
    request_expect((int)$db->query('SELECT COUNT(*) FROM queuecallback_requests')->fetchColumn() === $before + 1, 'AGI did not insert exactly one request');
    $unset = $vars; unset($unset['CALLBACK_NUMBER']);
    request_expect(simulated_store($unset, $env, function () use ($db) { return $db; })['result']['success'], 'Unset number did not fall back to valid caller ID');
    request_expect(simulated_store($vars, ['agi_callerid' => 'anonymous'], function () use ($db) { return $db; })['result']['success'], 'Valid alternate number rejected for private caller');
    echo "PASS: successful AGI stores one request and returns ID before success; unset-number fallback is explicit\n";

    $detected = array_replace($vars, ['QCB_CONFIRM_SOURCE'=>'detected','CONFIRM2'=>'','READSTATUS'=>'']);
    $count = (int)$db->query('SELECT COUNT(*) FROM queuecallback_requests')->fetchColumn();
    $run = simulated_store($detected, $env, function() use($db){return $db;});
    request_expect($run['result']['error']==='NOT_CONFIRMED'
        && (int)$db->query('SELECT COUNT(*) FROM queuecallback_requests')->fetchColumn()===$count,
        'Enabled confirmation must reject a stale detected-number bypass');
    $db->exec("UPDATE queuecallback_config SET confirm_number=0 WHERE queue_id='3007'");
    $run = simulated_store($detected, $env, function() use($db){return $db;});
    request_expect($run['result']['success'], 'Explicitly disabled confirmation must preserve configured behavior');
    $db->exec("UPDATE queuecallback_config SET confirm_number=1 WHERE queue_id='3007'");
    echo "PASS: saved confirmation setting governs detected-number requests; enabled setting blocks stale bypasses\n";

    $log = tempnam(sys_get_temp_dir(), 'qcb-request-log-');
    $previousLog = ini_get('error_log');
    ini_set('error_log', $log);
    try {
        $fault = new class {
            public function prepare($sql) { throw new PDOException('secret-password SQL customer-number'); }
        };
        $result = qcallback_create_request($fault, $request);
        request_expect($result['error'] === 'DB_ERROR' && $result['id'] === null, 'SQL error reported as success');
        $quietFault = new class {
            public function prepare($sql) { return new class {
                public function execute($params) { return false; }
            }; }
        };
        request_expect(qcallback_create_request($quietFault, $request)['error'] === 'DB_ERROR', 'False execute result ignored');
        $contents = file_get_contents($log);
        request_expect(strpos($contents, 'request storage error') !== false
            && strpos($contents, 'secret-password') === false && strpos($contents, 'customer-number') === false,
            'Storage error was silent or leaked exception details');
    } finally {
        ini_set('error_log', $previousLog); unlink($log);
    }
    echo "PASS: thrown and non-throwing database failures return failure and log without sensitive details\n";
} finally {
    $db->exec('DROP TEMPORARY TABLE queuecallback_requests, queuecallback_config, queues_config');
}
