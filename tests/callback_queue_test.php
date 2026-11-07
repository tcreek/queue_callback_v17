<?php
require_once dirname(__DIR__) . '/callback_dispatch.php';
function expect_queue($ok, $message) { if (!$ok) { throw new RuntimeException($message); } }
$now = time();
$member = ['Event'=>'QueueMember', 'Queue'=>'3007', 'Location'=>'Local/100@from-queue/n',
    'StateInterface'=>'hint:100@ext-local','Status'=>'1','Paused'=>'0','InCall'=>'0','LastCall'=>'0','Wrapuptime'=>'0','Penalty'=>'0'];
function snapshot_queue($members, $entries = [], $ready = 1) {
    global $now;
    $events = array_merge([['Event'=>'QueueParams','Queue'=>'3007','Calls'=>(string)count($entries)]], $members, $entries);
    $state = qcallback_queue_snapshot($events, ['Event'=>'QueueStatusComplete','ListItems'=>(string)count($events)], $now);
    $state['queues']['3007']['ready'] = $ready;
    return $state;
}
$callback = ['queue_id'=>'3007', 'time_requested'=>$now-60, 'queue_joined_at'=>$now-120, 'queue_priority'=>0,'uniqueid'=>'old.1'];
$state = snapshot_queue([$member]);
expect_queue(qcallback_queue_decision($callback,$state)['ready'], 'Idle member rejected');
foreach (['Status'=>['0','2','3','4','5','6','7','8'], 'Paused'=>['1'], 'InCall'=>['1']] as $field=>$values) {
    foreach ($values as $value) {
        $m = array_replace($member,[$field=>$value]);
        expect_queue(!qcallback_queue_decision($callback,snapshot_queue([$m],[], $field==='InCall'?1:0))['ready'], "$field=$value accepted");
    }
}
$m = array_replace($member,['LastCall'=>(string)($now-10),'Wrapuptime'=>'30']);
expect_queue(!qcallback_queue_decision($callback,snapshot_queue([$m],[],0))['ready'],'Wrap-up accepted');
$m['Wrapuptime']='0';
expect_queue(!qcallback_queue_decision($callback,snapshot_queue([$m],[],0))['ready'],'Inherited wrap-up accepted');
expect_queue(qcallback_queue_decision($callback,snapshot_queue([$m],[],1))['ready'],'Proven ready inherited wrap rejected');
$entry = ['Event'=>'QueueEntry','Queue'=>'3007','Position'=>'1','Priority'=>'0','Wait'=>'180','Uniqueid'=>'live.1'];
expect_queue(!qcallback_queue_decision($callback,snapshot_queue([$member],[$entry]))['ready'],'Earlier live caller bypassed');
$entry['Wait']='30';
expect_queue(qcallback_queue_decision($callback,snapshot_queue([$member],[$entry]))['ready'],'Younger live caller blocked oldest callback');
$entry['Priority']='1';
expect_queue(!qcallback_queue_decision($callback,snapshot_queue([$member],[$entry]))['ready'],'Higher priority bypassed');
$entry['Priority']='0';$entry['Uniqueid']='old.1';
expect_queue(!qcallback_queue_decision($callback,snapshot_queue([$member],[$entry]))['ready'],'Original channel still queued');
expect_queue(!qcallback_queue_decision($callback,$state,[$state['queues']['3007']['members'][$member['Location']]['agent_key']])['ready'],'Reserved member reused');
$other = $state['queues']['3007']; $other['entries']=[['Uniqueid'=>'other']]; $shared=$state; $shared['queues']['3008']=$other;
expect_queue(!qcallback_queue_decision($callback,$shared)['ready'],'Shared member taken from other live queue');
$stale=$state;$stale['observed_at']=$now-6;
expect_queue(!qcallback_queue_decision($callback,$stale)['ready'],'Stale snapshot accepted');
foreach ([null,[]] as $bad) { expect_queue(!qcallback_queue_decision($callback,$bad)['ready'],'Missing state accepted'); }
foreach ([['Event'=>'QueueStatusComplete','ListItems'=>'0'],['Event'=>'Other','ListItems'=>'2']] as $bad) {
    try { qcallback_queue_snapshot([['Event'=>'QueueParams','Queue'=>'3007','Calls'=>'0'],$member],$bad,$now); throw new LogicException('accepted'); }
    catch (UnexpectedValueException $e) {}
}
expect_queue(qcallback_agent_key(['Location'=>'PJSIP/100'])===qcallback_agent_key($member),'Member aliases differ');
expect_queue(qcallback_agent_key(['Location'=>'PJSIP/0'])!==qcallback_agent_key(['Location'=>'PJSIP/1']),'Zero extension identity lost');
echo "PASS: live order, priorities, readiness, wrap-up, shared members, reservations, incomplete/stale snapshots\n";

// The same AMI collector is exercised without connecting to Asterisk.
class QueueManagerFixture {
    public $socket; public $events; public $complete = true; public $foreign = false;
    function __construct() { $this->socket=fopen('php://temp','r+'); }
    function send($action,$params) {
        global $member;
        $id=$params['ActionID'];
        if ($action === 'GetVar') { $this->events=[['Response'=>'Success','Value'=>'1','ActionID'=>$id]]; return; }
        $this->events=[['Response'=>'Success','ActionID'=>$this->foreign?'wrong':$id],
            ['Event'=>'QueueParams','Queue'=>'3007','Calls'=>'0','ActionID'=>$id],array_replace($member,['ActionID'=>$id])];
        if ($this->complete) { $this->events[]=['Event'=>'QueueStatusComplete','ListItems'=>'2','ActionID'=>$id]; }
    }
    function wait_response($timeout,$event) { return array_shift($this->events); }
    function GetVar($channel,$variable) { return ['Response'=>'Success','Value'=>'1']; }
}
$manager=new QueueManagerFixture();
expect_queue(qcallback_read_queue_state('3007',$manager,[$manager,'send'])!==null,'Complete AMI list rejected');
$manager->complete=false;expect_queue(qcallback_read_queue_state('3007',$manager,[$manager,'send'])===null,'Partial AMI list accepted');
$manager->foreign=true;expect_queue(qcallback_read_queue_state('3007',$manager,[$manager,'send'])===null,'Wrong action response accepted');
fclose($manager->socket);
echo "PASS: AMI collector requires matching action and completed list\n";

require_once '/etc/freepbx.conf';
$db=FreePBX::Database();$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$db->exec("CREATE TEMPORARY TABLE queuecallback_requests (id INT PRIMARY KEY, queue_id VARCHAR(50), callback_number VARCHAR(50),
    status VARCHAR(20), attempts INT, last_attempt INT NULL, max_attempts INT, time_requested INT, time_processed INT NULL, uniqueid VARCHAR(50))");
$db->exec("INSERT INTO queuecallback_requests (id,queue_id,status,time_requested) VALUES (99,'3007','pending',123)");
qcallback_upgrade_queue_schema($db);qcallback_upgrade_queue_schema($db);
expect_queue((int)$db->query('SELECT time_requested FROM queuecallback_requests WHERE id=99')->fetchColumn()===123,'Migration modified existing request');
$db->exec('DELETE FROM queuecallback_requests WHERE id=99');
$db->exec("CREATE TEMPORARY TABLE queuecallback_config (queue_id VARCHAR(50) PRIMARY KEY, enabled INT, max_attempts INT,
    processing_interval INT, retry_interval INT, call_first VARCHAR(20), outbound_route_id INT, return_message_id INT, return_confirm_timeout INT DEFAULT 10, return_confirm_repeats INT DEFAULT 1)");
$db->exec("INSERT INTO queuecallback_config VALUES ('3007',1,3,0,30,'customer',0,NULL,10,1),('3008',1,3,0,30,'agent',0,NULL,10,1)");
$db->exec('CREATE TEMPORARY TABLE users (extension VARCHAR(50))');
$insert=$db->prepare("INSERT INTO queuecallback_requests (id,queue_id,callback_number,status,attempts,last_attempt,max_attempts,time_requested,uniqueid,queue_joined_at)
    VALUES (?,?,?,'pending',0,NULL,3,?,'',?)");
$insert->execute([1,'3007','5551234567',$now-90,$now-180]);$insert->execute([2,'3007','5551234568',$now-120,$now-100]);
$module=new class { function isNumberBlocked($number,$queue) {return false;} function resolveReturnMessage($id) {return 'custom/test';} };
$root=sys_get_temp_dir().'/qcb-queue-test-'.bin2hex(random_bytes(8));mkdir($root,0700);mkdir($root.'/outgoing',0700);
$provider=function($q) use ($state) { $s=$state; if ($q!=='3007') {$s['queues'][$q]=$s['queues']['3007'];unset($s['queues']['3007']);} return $s; };
try {
    $dispatch=function($id,$p=null) use ($db,$module,$provider,$root) {return qcallback_dispatch_request($db,$id,$module,$p??$provider,$root.'/outgoing');};
    $peer = new PDO('mysql:host=' . $amp_conf['AMPDBHOST'] . ';dbname=' . $amp_conf['AMPDBNAME'],
        $amp_conf['AMPDBUSER'], $amp_conf['AMPDBPASS'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    expect_queue((int)$peer->query("SELECT GET_LOCK('qcb:dispatch',0)")->fetchColumn()===1,'Could not take peer dispatch lock');
    try { expect_queue($dispatch(1)['reason']==='DISPATCH_BUSY','Concurrent dispatcher bypassed global lock'); }
    finally { $peer->query("SELECT RELEASE_LOCK('qcb:dispatch')"); }
    expect_queue($dispatch(2)['reason']==='CALLBACK_AHEAD','Insertion/request time bypassed original queue order');
    expect_queue(!$dispatch(1,function(){return null;})['published'],'Missing AMI published');
    expect_queue((int)$db->query('SELECT SUM(attempts) FROM queuecallback_requests')->fetchColumn()===0,'Deferral consumed attempt');
    $db->exec('UPDATE queuecallback_requests SET last_attempt='.($now-1).' WHERE id=1');
    expect_queue($dispatch(2)['reason']==='CALLBACK_AHEAD','Retry cooldown bypassed');
    $db->exec('UPDATE queuecallback_requests SET last_attempt=NULL WHERE id=1');
    expect_queue($dispatch(1)['published'],'Ready head failed to publish');
    $file=file_get_contents($root.'/outgoing/queuecallback_1_1.call');
    expect_queue(str_contains($file,'Channel: Local/5551234567@from-internal/n') && str_contains($file,'__CALLBACK_QUEUE_PRIORITY=0'),'Customer file incorrect');
    expect_queue($dispatch(2)['reason']==='QUEUE_CALLBACK_ACTIVE','Second active queue callback published');
    $insert->execute([3,'3008','5551234569',$now-90,$now-180]);
    $db->exec("UPDATE queuecallback_config SET return_confirm_timeout=23,return_confirm_repeats=0 WHERE queue_id='3008'");
    expect_queue($dispatch(3)['reason']==='NO_READY_MEMBER','Shared idle agent reserved twice');
    $db->exec("UPDATE queuecallback_requests SET status='completed' WHERE id=1");
    expect_queue($dispatch(3)['published'],'Reservation not released by terminal status');
    $file=file_get_contents($root.'/outgoing/queuecallback_3_1.call');
    expect_queue(str_contains($file,'Channel: Local/100@from-queue/n') && str_contains($file,'Context: queuecallback-agent-outbound'),'Agent interface guessed instead of live member');
    expect_queue(str_contains($file,"SetVar: __NODEST=3008\n"),'FreePBX from-queue origin missing; agent leg would hang up before dialing');
    expect_queue(str_contains($file,"SetVar: __CALLBACK_CONFIRM_TIMEOUT=23\n")
        && str_contains($file,"SetVar: __CALLBACK_CONFIRM_PLAYS=1\n"),'Configured timeout or zero repeats lost in call file');
    $db->exec("UPDATE queuecallback_requests SET status='completed' WHERE id=3");
    $db->exec('UPDATE queuecallback_requests SET queue_priority=5 WHERE id=2');
    expect_queue($dispatch(2)['published'],'Priority head failed');
    expect_queue(str_contains(file_get_contents($root.'/outgoing/queuecallback_2_1.call'),'__CALLBACK_QUEUE_PRIORITY=5'),'Priority not inherited');
    $insert->execute([4,'3007','5551234568',$now-60,$now-80]);
    $insert->execute([5,'3007','5551234568',$now-40,$now-40]);
    $dispatch(5);
    expect_queue($db->query('SELECT status FROM queuecallback_requests WHERE id=5')->fetchColumn()==='cancelled','Pending duplicate not cancelled');
    $db->exec("UPDATE queuecallback_requests SET status='completed' WHERE id=2");
    $db->exec('UPDATE queuecallback_requests SET attempts=3 WHERE id=4');
    $dispatch(4);
    expect_queue($db->query('SELECT status FROM queuecallback_requests WHERE id=4')->fetchColumn()==='failed','Exhausted pending head retained forever');
    echo "PASS: idempotent schema migration, original FIFO, retries, shared capacity, both modes, no attempt on deferral\n";
} finally {
    foreach (glob($root.'/outgoing/*') as $path) {unlink($path);}rmdir($root.'/outgoing');
    if (is_dir($root.'/.qcallback-staging')) {rmdir($root.'/.qcallback-staging');}rmdir($root);
}
