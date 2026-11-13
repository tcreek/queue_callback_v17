<?php
/** Isolated Asterisk Queue proof: synthetic Local channels only, always remove fixtures. */
if (PHP_SAPI !== 'cli' || !in_array('--run-isolated-asterisk', $argv, true)) {
    fwrite(STDERR, "Explicit --run-isolated-asterisk required; synthetic queue fixtures temporarily reload Asterisk configuration.\n"); exit(2);
}
require '/etc/freepbx.conf';
require dirname(__DIR__).'/callback_install.php';
require dirname(__DIR__).'/callback_dialplan.php';
$pdo=FreePBX::Database();$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$q='998873621';
function fifo_cli($command) {
    $output=[];$code=0;exec('/usr/sbin/asterisk -rx '.escapeshellarg($command).' 2>&1',$output,$code);
    if ($code) {throw new RuntimeException('Asterisk test command failed');}
    return implode("\n",$output);
}
function fifo_assert($ok,$message) {if (!$ok) {throw new RuntimeException($message);}}
fifo_assert(!$pdo->query("SELECT 1 FROM queuecallback_config WHERE queue_id='$q'")->fetchColumn(),'Synthetic queue ID is in use');
fifo_assert(!str_contains(fifo_cli('queue show '.$q),'holdtime'),'Synthetic Asterisk queue already exists');
qcallback_upgrade_queue_schema($pdo);
qcallback_install_agis(dirname(__DIR__).'/agi-bin','/var/lib/asterisk/agi-bin',dirname(__DIR__));
$extensions='/etc/asterisk/extensions_custom.conf';$queues='/etc/asterisk/queues_post_custom.conf';
$originalExtensions=file_get_contents($extensions);$originalQueues=file_get_contents($queues);
$id=null;
try {
    $pdo->prepare('INSERT INTO queuecallback_config (queue_id,enabled,max_attempts,retry_interval) VALUES (?,1,3,86400)')->execute([$q]);
    $now=time();
    $pdo->prepare("INSERT INTO queuecallback_requests (queue_id,callback_number,status,attempts,max_attempts,time_requested,queue_joined_at,last_attempt,uniqueid)
        VALUES (?,'99999999999999999999','pending',1,3,?,?,?,'fifo-engine-test')")->execute([$q,$now-120,$now-120,$now]);
    $id=(int)$pdo->lastInsertId();
    $testplan=<<<'DIALPLAN'

[qcb-fifo-engine-caller]
exten => s,1,Answer()
 same => n,Set(DEVICE_STATE(Custom:QCBFIFOSELFTEST)=NOT_INUSE)
 same => n,ExecIf($["${DB(QCB_FIFO_SELFTEST/callback)}" != ""]?Set(__CALLBACK_ID=${DB(QCB_FIFO_SELFTEST/callback)}))
 same => n,ExecIf($["${DB(QCB_FIFO_SELFTEST/callback)}" != ""]?Set(__CALLBACK_ATTEMPT=1))
 same => n,ExecIf($["${DB(QCB_FIFO_SELFTEST/callback)}" != ""]?Set(__CALLBACK_RETURN=1))
 same => n,Gosub(qcb-fifo-entry,s,1(998873621))
 same => n,Queue(998873621,${VQ_OPTIONS},,,3,${VQ_AGI})
 same => n,Set(DB(QCB_FIFO_SELFTEST/result)=${QUEUESTATUS})
 same => n,Hangup()

[qcb-fifo-engine-agent]
exten => s,1,Set(DB(QCB_FIFO_SELFTEST/agent)=rang)
 same => n,Answer()
 same => n,Wait(1)
 same => n,Hangup()

DIALPLAN;
    file_put_contents($extensions,$originalExtensions.str_replace('exten => s,1,Set(QCB_FIFO_ALLOW=0)', 'exten => s,1,Set(DB(QCB_FIFO_SELFTEST/guard)=1)'."\n".' same => n,Set(QCB_FIFO_ALLOW=0)',qcallback_fifo_dialplan()).$testplan);
    file_put_contents($queues,$originalQueues."\n[$q]\nstrategy=ringall\ntimeout=1\nretry=1\nautofill=yes\njoinempty=yes\nleavewhenempty=no\nmember => Local/s@qcb-fifo-engine-agent/n,0,FIFO Test,Custom:QCBFIFOSELFTEST\n");
    fifo_cli('dialplan reload');fifo_cli('module reload app_queue.so');
    fifo_cli('database deltree QCB_FIFO_SELFTEST');
    fifo_cli('channel originate Local/s@qcb-fifo-engine-caller/n application Wait 5');
    sleep(5);
    $agent=fifo_cli('database get QCB_FIFO_SELFTEST agent');
    $result=fifo_cli('database get QCB_FIFO_SELFTEST result');
    fifo_assert(str_contains(fifo_cli('database get QCB_FIFO_SELFTEST guard'),'1'),'Queue did not execute member gate');
    fifo_assert(!str_contains($agent,'rang'),'Newer live caller rang an agent ahead of pending retry');
    fifo_assert(str_contains($result,'TIMEOUT'),'Live caller did not remain in Queue until normal timeout');
    echo "PASS: real Asterisk Queue kept the newer synthetic caller on hold through retry cooldown; agent never rang\n";
    $pdo->prepare("UPDATE queuecallback_requests SET status='cancelled',time_processed=? WHERE id=?")->execute([time(),$id]);
    fifo_cli('database deltree QCB_FIFO_SELFTEST');
    fifo_cli('channel originate Local/s@qcb-fifo-engine-caller/n application Wait 5');
    sleep(4);
    fifo_assert(str_contains(fifo_cli('database get QCB_FIFO_SELFTEST agent'),'rang'),'Live caller did not proceed after cancellation');
    echo "PASS: same synthetic live flow reached the agent after older callback became terminal\n";
    $pdo->prepare("UPDATE queuecallback_requests SET status='processing',fifo_connected_at=NULL WHERE id=?")->execute([$id]);
    fifo_cli('database deltree QCB_FIFO_SELFTEST');
    fifo_cli('database put QCB_FIFO_SELFTEST callback '.$id);
    fifo_cli('channel originate Local/s@qcb-fifo-engine-caller/n application Wait 5');
    sleep(4);
    fifo_assert(str_contains(fifo_cli('database get QCB_FIFO_SELFTEST agent'),'rang'),'Returning synthetic callback did not reach agent');
    $stamp=$pdo->query('SELECT fifo_connected_at FROM queuecallback_requests WHERE id='.(int)$id)->fetchColumn();
    fifo_assert((int)$stamp>0,'Queue connected hook did not release virtual position');
    echo "PASS: real returning-customer Queue bridge releases its virtual position through the connected AGI\n";

} finally {
    foreach (explode("\n",fifo_cli('core show channels concise')) as $line) {
        $channel=explode('!', $line)[0];
        if (preg_match('~^Local/s@qcb-fifo-engine-(?:caller|agent)-[A-Za-z0-9]+;[12]$~D',$channel)) {fifo_cli('channel request hangup '.$channel);}
    }
    if ($id!==null) {$pdo->prepare('DELETE FROM queuecallback_requests WHERE id=?')->execute([$id]);}
    $pdo->prepare('DELETE FROM queuecallback_config WHERE queue_id=?')->execute([$q]);
    file_put_contents($extensions,$originalExtensions);file_put_contents($queues,$originalQueues);
    fifo_cli('dialplan reload');fifo_cli('module reload app_queue.so');
    fifo_cli('database deltree QCB_FIFO_SELFTEST');
}
