<?php
/** Uses only connection-local temporary tables and /tmp files; never dials. */
require_once('/etc/freepbx.conf');
require_once(dirname(__DIR__) . '/callback_lifecycle.php');
require_once(dirname(__DIR__) . '/callback_spool.php');
require_once(dirname(__DIR__) . '/callback_dialplan.php');
function verify($condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
}

$queueVars = ['QCB_CUSTOMER_CONFIRMED' => '1', 'QCB_PHASE' => 'queue', 'ANSWEREDTIME_MS' => '2500',
    'QUEUEWAIT_MS' => '4000', 'QUEUESTATUS' => '', 'ABANDONED' => ''];
verify(qcallback_was_connected('customer', $queueVars), 'Queue bridge should complete');
foreach ([['QCB_CUSTOMER_CONFIRMED' => '0'], ['QCB_CUSTOMER_CONFIRMED' => ''], ['ANSWEREDTIME_MS' => '0'], ['ANSWEREDTIME_MS' => ''],
    ['ABANDONED' => 'TRUE'], ['QUEUESTATUS' => 'TIMEOUT'],
    ['QUEUESTATUS' => 'JOINEMPTY'], ['QUEUESTATUS' => 'FULL'],
    ['QCB_PHASE' => 'announcement'], ['QUEUEWAIT_MS' => '']] as $change) {
    verify(!qcallback_was_connected('customer', array_replace($queueVars, $change)),
        'Non-bridge queue outcome should not complete: ' . json_encode($change));
}
verify(qcallback_was_connected('agent', ['QCB_CUSTOMER_CONFIRMED' => '1', 'DIALSTATUS' => 'ANSWER', 'ANSWEREDTIME_MS' => '2500']), 'Confirmed agent/customer bridge should complete');
foreach (['0', ''] as $confirmed) {
    verify(!qcallback_was_connected('agent', ['QCB_CUSTOMER_CONFIRMED' => $confirmed,
        'DIALSTATUS' => 'ANSWER', 'ANSWEREDTIME_MS' => '2500']), 'Unconfirmed voicemail answer must not complete');
}
foreach (['BUSY', 'NOANSWER', 'CHANUNAVAIL', 'CONGESTION', 'CANCEL', ''] as $status) {
    verify(!qcallback_was_connected('agent', ['QCB_CUSTOMER_CONFIRMED' => '1', 'DIALSTATUS' => $status, 'ANSWEREDTIME_MS' => '2500']), 'Failed dial should not complete');
}
verify(!qcallback_was_connected('agent', ['QCB_CUSTOMER_CONFIRMED' => '1', 'DIALSTATUS' => 'ANSWER', 'ANSWEREDTIME_MS' => '']), 'Answer during announcement is not a bridge');
verify(!qcallback_was_connected('unknown', $queueVars), 'Unknown mode should not complete');
echo "PASS: bridge evidence distinguishes answer, announcement, abandonment, timeout and failed dial\n";

$db = FreePBX::Database();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec("CREATE TEMPORARY TABLE queuecallback_requests (
    id INT PRIMARY KEY, queue_id VARCHAR(50), callback_number VARCHAR(50),
    status VARCHAR(20), attempts INT, last_attempt INT NULL, max_attempts INT,
    time_requested INT, time_processed INT NULL)");
$db->exec("CREATE TEMPORARY TABLE queuecallback_config (
    queue_id VARCHAR(50) PRIMARY KEY, enabled INT, max_attempts INT,
    processing_interval INT, retry_interval INT)");
$queue = 'test-' . bin2hex(random_bytes(8));
$db->prepare('INSERT INTO queuecallback_config VALUES (?, 1, 3, 30, 60)')->execute([$queue]);
$now = time();
$reset = function (string $status = 'processing', int $attempts = 1) use ($db, $queue, $now): array {
    $db->exec('DELETE FROM queuecallback_requests');
    $db->prepare('INSERT INTO queuecallback_requests VALUES (1, ?, ?, ?, ?, ?, 3, ?, NULL)')
        ->execute([$queue, '5551234567', $status, $attempts, $now - 120, $now - 300]);
    return $db->query('SELECT * FROM queuecallback_requests WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
};
$state = function () use ($db): array {
    return $db->query('SELECT * FROM queuecallback_requests WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
};
$root = sys_get_temp_dir() . '/qcb-lifecycle-test-' . bin2hex(random_bytes(8));
mkdir($root, 0700);
mkdir($root . '/outgoing', 0700);
mkdir($root . '/outgoing_done', 0700);
$archive = $root . '/outgoing_done/queuecallback_1_1.call';
try {
    $reset();
    verify(qcallback_finish_attempt($db, 1, 1, false, 'BUSY', $now), 'Busy attempt not recorded');
    verify($state()['status'] === 'pending' && (int)$state()['last_attempt'] === $now
        && $state()['time_processed'] === null, 'Retry must wait from finish time');
    verify(!qcallback_finish_attempt($db, 1, 1, true, 'LATE_BRIDGE'), 'Duplicate outcome overwrote pending state');
    verify(!qcallback_publish_call($db, $state(), "Channel: test\n", $root . '/outgoing'), 'Retry ignored configured delay');
    qcallback_finish_attempt($db, 1, 0, false, 'INVALID');
    echo "PASS: retry waits from finish time and duplicate outcomes are ignored\n";

    $reset('processing', 3);
    qcallback_reconcile_attempts($db, $root);
    verify($state()['status'] === 'processing', 'Active final attempt was failed by counter');
    verify(qcallback_finish_attempt($db, 1, 3, false, 'NOANSWER', $now), 'Final failure not recorded');
    verify($state()['status'] === 'failed' && (int)$state()['time_processed'] === $now, 'Final failure needs terminal timestamp');
    echo "PASS: final attempt stays active until it ends, then fails with timestamp\n";

    $reset('processing', 2);
    verify(!qcallback_finish_attempt($db, 1, 1, true, 'STALE'), 'Old attempt completed a newer attempt');
    verify(qcallback_finish_attempt($db, 1, 2, true, 'BRIDGED', $now), 'Current bridge not recorded');
    verify($state()['status'] === 'completed' && (int)$state()['time_processed'] === $now, 'Bridge should complete with timestamp');
    $reset('cancelled');
    verify(!qcallback_finish_attempt($db, 1, 1, true, 'LATE'), 'Cancellation overwritten');
    echo "PASS: stale outcomes and cancellation cannot be overwritten\n";

    foreach (['Expired', 'Failed', 'Completed'] as $status) {
        $reset();
        file_put_contents($archive, "Status: $status\n");
        qcallback_reconcile_attempts($db, $root);
        verify($state()['status'] === 'pending', 'Terminal archive should release current attempt for retry');
        verify((int)$state()['attempts'] === 1, 'Archive processing consumed an extra attempt');
        unlink($archive);
    }
    echo "PASS: unanswered archives retry; archive Completed alone does not imply agent connection\n";

    $reset();
    file_put_contents($archive, "Channel: test\n");
    qcallback_reconcile_attempts($db, $root);
    verify($state()['status'] === 'processing', 'Archive without final Status released request');
    file_put_contents($archive, "Status: Expired\n");
    file_put_contents($root . '/outgoing/queuecallback_1_1.call', 'active');
    qcallback_reconcile_attempts($db, $root);
    verify($state()['status'] === 'processing', 'Active outgoing file released request');
    unlink($root . '/outgoing/queuecallback_1_1.call');
    touch($archive, $now - 1000);
    clearstatcache();
    qcallback_reconcile_attempts($db, $root);
    verify($state()['status'] === 'processing', 'Old archive released newer attempt');
    unlink($archive);
    echo "PASS: incomplete and stale archives and live outgoing files cannot trigger retries\n";

    $reset('pending', 3);
    qcallback_reconcile_attempts($db, $root);
    verify($state()['status'] === 'failed' && $state()['time_processed'] !== null, 'Exhausted pending request should fail');
    $reset('pending', 1);
    $db->exec('UPDATE queuecallback_requests SET last_attempt = UNIX_TIMESTAMP() - 61');
    verify(qcallback_publish_call($db, $state(), "Channel: test\nMaxRetries: 9\n", $root . '/outgoing'), 'Due retry failed to publish');
    $published = file_get_contents($root . '/outgoing/queuecallback_1_2.call');
    verify(substr_count($published, 'MaxRetries:') === 1 && strpos($published, 'MaxRetries: 0') !== false, 'Hidden call-file retries remain');
    verify(strpos($published, '__CALLBACK_ATTEMPT=2') !== false, 'Attempt identity missing');
    echo "PASS: configured delay permits due retry with exactly one counted originate\n";

    $eventSql = qcallback_trigger_event_sql(30);
    verify(strpos($eventSql, 'EVERY 30 SECOND') !== false && strpos($eventSql, 'UPDATE queuecallback_requests') === false,
        'Heartbeat uses wrong units or mutates active calls');
    $plan = qcallback_outbound_dialplan();
    verify(substr_count($plan, 'Gosub(qcb-return-confirm,s,1)') === 2,
        'Both callback modes must require customer confirmation');
    verify(str_contains($plan, 'Read(QCB_RETURN_DIGIT,${QCB_RETURN_PROMPT},1,nt(),1,${QCB_RETURN_TIMEOUT})')
        && str_contains($plan, '${READSTATUS}" = "OK" & "${QCB_RETURN_DIGIT}" = "1"'),
        'Only a successful press 1 may connect; timeout, # and other digits must fail');
    verify(str_contains($plan, 'Set(GOSUB_RESULT=CONTINUE)')
        && str_contains($plan, 'Playback(custom/failed-to-confirm)'), 'Unconfirmed called leg must be released');
    verify(str_contains($plan, 'U(qcb-customer-confirm^${CHANNEL})')
        && str_contains($plan, 'Set(SHARED(QCB_CUSTOMER_CONFIRMED,${ARG1})=1)')
        && str_contains($plan, 'QCB_CUSTOMER_CONFIRMED=${SHARED(QCB_CUSTOMER_CONFIRMED)}'),
        'Confirmation must reach the exact agent channel completion handler');
    verify(strpos($plan, 'qcb-complete,s,1(${CALLBACK_ID},${CALLBACK_ATTEMPT},agent)') !== false,
        'Agent handler must attach to agent leg before dialing customer');
    verify(strpos($plan, 'AGI(queuecallback-complete.agi,${ARG1},${ARG2},${ARG3})') !== false,
        'Outcome handler lost attempt identity');
    echo "PASS: heartbeat uses seconds; dialplan preserves attempt identity\n";
} finally {
    foreach (['outgoing', 'outgoing_done', '.qcallback-staging'] as $dir) {
        if (!is_dir($root . '/' . $dir)) { continue; }
        foreach (glob($root . '/' . $dir . '/*') ?: [] as $file) { unlink($file); }
        rmdir($root . '/' . $dir);
    }
    rmdir($root);
    $db->exec('DROP TEMPORARY TABLE queuecallback_requests, queuecallback_config');
}
