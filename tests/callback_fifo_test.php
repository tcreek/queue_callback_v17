<?php
/** Mixed live/callback FIFO, including retries and connection release. No real calls. */
require_once dirname(__DIR__).'/callback_fifo.php';
require_once dirname(__DIR__).'/callback_install.php';
require_once dirname(__DIR__).'/callback_requests.php';
require_once dirname(__DIR__).'/callback_dialplan.php';
require_once '/etc/freepbx.conf';
function fifo_expect($ok,$message) {if (!$ok) {throw new RuntimeException($message);}}
$pdo=FreePBX::Database();$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$installer=new class($pdo) {
    function __construct(private $pdo) {}
    function exec($sql) {return $this->pdo->exec(str_replace('CREATE TABLE IF NOT EXISTS','CREATE TEMPORARY TABLE IF NOT EXISTS',$sql));}
    function query($sql) {return $this->pdo->query($sql);}
    function prepare($sql) {return $this->pdo->prepare($sql);}
};
qcallback_install_schema($installer);
$pdo->exec("INSERT INTO queuecallback_config (queue_id,enabled,max_attempts) VALUES ('3007',1,3),('3008',1,3)");
$now=time();
$insert=$pdo->prepare("INSERT INTO queuecallback_requests (id,queue_id,callback_number,status,attempts,max_attempts,queue_joined_at,time_requested,last_attempt,queue_priority)
    VALUES (?,?,'705','pending',1,3,?,?,?,0)");
$insert->execute([1,'3007',$now-120,$now-60,$now-1]);
fifo_expect(!qcallback_fifo_allows($pdo,'3007',$now-30,0),'Younger live caller bypassed callback retry cooldown');
fifo_expect(qcallback_fifo_allows($pdo,'3007',$now-180,0),'Older live caller lost its turn');
fifo_expect(qcallback_fifo_allows($pdo,'3007',$now-120,0),'Ambiguous legacy tie must consistently favor live caller');
$pdo->exec("UPDATE queuecallback_requests SET uniqueid='1000.1' WHERE id=1");
fifo_expect(!qcallback_fifo_allows($pdo,'3007',$now-120,0,0,'1000.2'),'Same-second newer live caller bypassed callback');
fifo_expect(qcallback_fifo_allows($pdo,'3007',$now-120,0,0,'1000.0'),'Same-second older live caller was held');
fifo_expect(qcallback_fifo_allows($pdo,'3007',$now-30,1),'Configured queue priority was lost');
fifo_expect(qcallback_fifo_allows($pdo,'3008',$now-30,0),'Unrelated queue was held');
$pdo->exec("UPDATE queuecallback_requests SET status='processing' WHERE id=1");
fifo_expect(!qcallback_fifo_allows($pdo,'3007',$now-30,0),'In-flight callback did not hold its turn');
fifo_expect(qcallback_fifo_allows($pdo,'3007',$now-120,0,1),'Returning callback blocked itself');
function fifo_run($pdo,array $vars,array $env,?callable $snapshot=null) {
    qcallback_fifo_agi($env,function($command) use (&$vars) {
        if (preg_match('/^SET VARIABLE ([A-Z0-9_]+) "(.*)"$/D',$command,$m)) {
            $vars[ltrim($m[1],'_')]=stripcslashes($m[2]);return '200 result=1';
        }
        if (preg_match('/^GET VARIABLE ([A-Z0-9_]+)$/D',$command,$m)) {return isset($vars[$m[1]])?'200 result=1 ('.$vars[$m[1]].')':'200 result=0';}
        if (str_starts_with($command,'EXEC AGI ')) {$vars['previous_agi_called']=true;return '200 result=0';}
        throw new RuntimeException('Unexpected FIFO protocol');
    },fn()=>$pdo,$snapshot);
    return $vars;
}
$vars=fifo_run($pdo,['CALLBACK_ID'=>'1','CALLBACK_ATTEMPT'=>'1'],['agi_arg_1'=>'connected']);
fifo_expect(qcallback_fifo_allows($pdo,'3007',$now-30,0),'Connected call kept other agents idle');
$pdo->exec("UPDATE queuecallback_requests SET status='pending' WHERE id=1");
fifo_expect(!qcallback_fifo_allows($pdo,'3007',$now-30,0),'Failed attempt lost original FIFO position');
foreach (['completed','cancelled','blocked','failed'] as $status) {
    $pdo->prepare('UPDATE queuecallback_requests SET status=? WHERE id=1')->execute([$status]);
    fifo_expect(qcallback_fifo_allows($pdo,'3007',$now-30,0),'Terminal request held line: '.$status);
}
$pdo->exec("UPDATE queuecallback_requests SET status='pending',attempts=3 WHERE id=1");
fifo_expect(qcallback_fifo_allows($pdo,'3007',$now-30,0),'Exhausted request held line');
$pdo->exec("UPDATE queuecallback_requests SET status='pending',attempts=1 WHERE id=1");
$pdo->exec("INSERT INTO queuecallback_security (pattern,description,enabled) VALUES ('705','Blocked test',1)");
fifo_expect(qcallback_fifo_allows($pdo,'3007',$now-30,0),'Blocked request held line');
$pdo->exec('DELETE FROM queuecallback_security');
echo "PASS: earlier live callers proceed; newer live callers wait through retry/dialing; connected/terminal/blocked/exhausted callbacks release line\n";
[$opts,$previous]=qcallback_fifo_options('tTnb(custom^s^1(foo^bar))R');
fifo_expect($opts==='tTRb(qcb-fifo-member^s^1)' && $previous==='custom,s,1(foo,bar)','Existing Queue pre-dial options were lost');
$vars=fifo_run($pdo,['QUEUE_PRIO'=>'0','VQ_OPTIONS'=>'t'],['agi_arg_1'=>'entry','agi_arg_2'=>'3007']);
fifo_expect($vars['QCB_FIFO_INITIALIZED']==='1' && $vars['QCB_FIFO_SELF']==='0','Live entry not initialized');
$vars=fifo_run($pdo,$vars,['agi_arg_1'=>'check']);
fifo_expect($vars['QCB_FIFO_ALLOW']==='0','Member AGI allowed newer live caller');
$pdo->exec("UPDATE queuecallback_requests SET status='processing',fifo_connected_at=NULL WHERE id=1");
$state=['observed_at'=>$now,'queues'=>['3007'=>['entries'=>[
    ['Priority'=>0,'joined_at'=>$now-180],['Priority'=>0,'joined_at'=>$now-30]]]]];
$vars=fifo_run($pdo,['CALLBACK_RETURN'=>'1','CALLBACK_ID'=>'1','CALLBACK_ATTEMPT'=>'1','QUEUE_PRIO'=>'0','QAGI'=>'custom.agi'],
    ['agi_arg_1'=>'entry','agi_arg_2'=>'3007'],fn()=>$state);
fifo_expect($vars['VQ_POSITION']==='2' && $vars['QCB_FIFO_JOINED_AT']===(string)($now-120),'Returning callback overtook older live caller or lost original time');
fifo_expect($vars['VQ_AGI']==='queuecallback-fifo.agi' && $vars['QCB_FIFO_PREVIOUS_AGI']==='custom.agi','Connected AGI chain lost');
$vars=fifo_run($pdo,$vars,['agi_arg_1'=>'check']);
fifo_expect($vars['QCB_FIFO_ALLOW']==='1','Returning callback did not pass own place');
$vars=fifo_run($pdo,$vars,['agi_arg_1'=>'connected']);
fifo_expect(!empty($vars['previous_agi_called']),'Existing connected AGI was not preserved');
$vars=fifo_run(new class {function prepare($sql){throw new PDOException('Fixture failure');}},
    ['QCB_FIFO_QUEUE'=>'3007','QCB_FIFO_JOINED_AT'=>(string)$now,'QCB_FIFO_PRIORITY'=>'0','QCB_FIFO_SELF'=>'0'],['agi_arg_1'=>'check']);
fifo_expect($vars['QCB_FIFO_ALLOW']==='0','Read failure allowed overtaking');
$plan=qcallback_fifo_dialplan();
fifo_expect(str_contains($plan,'Hangup(21)') && !str_contains($plan,'Queue('),'Member guard must stop only outgoing member leg');
echo "PASS: AGI entry/member/connect flow, returning queue position, prior hooks and fail-closed behavior\n";
