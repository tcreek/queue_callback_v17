<?php
/** Check AGI protocol and credential-safe failures; no live calls. */
require_once dirname(__DIR__).'/callback_requests.php';
function expect_check($ok,$message){if(!$ok){throw new RuntimeException($message);}}
function run_check(array $vars, callable $factory, array $env=[], ?string $badCommand=null): array {
    $commands=[];$dbCalls=0;
    $result=qcallback_check_agi($env,function($command) use (&$vars,&$commands,$badCommand){
        $commands[]=$command;
        if($command===$badCommand){return '510 Invalid command';}
        if(preg_match('/^SET VARIABLE ([A-Z_]+) "(.*)"$/D',$command,$m)){$vars[$m[1]]=stripcslashes($m[2]);return '200 result=1';}
        if($command==='GET VARIABLE CALLBACK_QUEUE'){return array_key_exists('CALLBACK_QUEUE',$vars)?'200 result=1 ('.$vars['CALLBACK_QUEUE'].')':'200 result=0';}
        throw new LogicException('Unexpected check AGI command');
    },function() use (&$dbCalls,$factory){$dbCalls++;return $factory();});
    return ['result'=>$result,'vars'=>$vars,'commands'=>$commands,'db_calls'=>$dbCalls];
}
$stale=['QUEUE_CALLBACK_ENABLED'=>'1','QUEUE_CALLBACK_KEY'=>'9'];
$never=function(){throw new LogicException('Invalid input requested database');};
foreach(['','bad','3007;id',"3007\n",str_repeat('1',51)] as $queue){
    $run=run_check(array_replace($stale,['CALLBACK_QUEUE'=>$queue]),$never,['agi_dnid'=>'3007']);
    expect_check($run['db_calls']===0 && $run['vars']['QUEUE_CALLBACK_ENABLED']==='0'
        && $run['vars']['QUEUE_CALLBACK_KEY']==='' && in_array($run['result']['error'],['INVALID_QUEUE','AGI_ERROR'],true),'Invalid queue did not fail closed');
}
$run=run_check(array_replace($stale,['CALLBACK_QUEUE'=>'3007']),$never,[],'GET VARIABLE CALLBACK_QUEUE');
expect_check($run['db_calls']===0 && $run['vars']['QUEUE_CALLBACK_ENABLED']==='0' && !$run['result']['enabled'],'AGI GET failure allowed check');
$log=tempnam(sys_get_temp_dir(),'qcb-check-log-');$oldLog=ini_get('error_log');ini_set('error_log',$log);
try {
    $run=run_check(['CALLBACK_QUEUE'=>'3007']+$stale,function(){throw new PDOException('mock-password-secret SQLSTATE user/host details');});
    expect_check(!$run['result']['enabled'] && $run['vars']['QCB_CHECK_ERROR']==='DB_ERROR','Bootstrap failure enabled callback');
    $logged=file_get_contents($log);
    expect_check(!str_contains($logged,'mock-password-secret') && !str_contains($logged,'user/host details'),'Raw DB error logged');
    expect_check(!str_contains(implode("\n",$run['commands']),'mock-password-secret'),'Raw DB error emitted through AGI');
} finally {ini_set('error_log',$oldLog);unlink($log);}
echo "PASS: malformed queue, stale flags, protocol/bootstrap errors fail closed without leaking DB errors\n";

// Exercise the packaged executable, rejecting before any DB bootstrap.
$pipes=[];$proc=proc_open([PHP_BINARY,dirname(__DIR__).'/agi-bin/queuecallback-check.agi'],
    [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
expect_check(is_resource($proc),'Cannot start check AGI');$wire=$stale;
try {
    fwrite($pipes[0],"agi_dnid: 3007\n\n");fflush($pipes[0]);stream_set_timeout($pipes[1],5);$count=0;
    while(($line=fgets($pipes[1]))!==false){
        expect_check(++$count<30,'Check AGI loop did not finish');$line=rtrim($line,"\r\n");
        if(preg_match('/^SET VARIABLE ([A-Z_]+) "(.*)"$/D',$line,$m)){$wire[$m[1]]=stripcslashes($m[2]);$reply='200 result=1';}
        elseif($line==='GET VARIABLE CALLBACK_QUEUE'){$reply='200 result=1 (invalid)';}
        else {throw new RuntimeException('Unexpected protocol output');}
        fwrite($pipes[0],$reply."\n");fflush($pipes[0]);
    }
    expect_check(!stream_get_meta_data($pipes[1])['timed_out'],'Check AGI timed out');
    fclose($pipes[0]);fclose($pipes[1]);$stderr=stream_get_contents($pipes[2]);fclose($pipes[2]);
    $status=proc_close($proc);$proc=null;
    expect_check($status===0 && $stderr==='' && $wire['QUEUE_CALLBACK_ENABLED']==='0'
        && $wire['QCB_CHECK_ERROR']==='INVALID_QUEUE','Packaged check AGI did not report invalid queue');
} finally {foreach($pipes as $pipe){if(is_resource($pipe)){fclose($pipe);}}if(is_resource($proc)){proc_terminate($proc);proc_close($proc);}}
echo "PASS: packaged check AGI stdin/stdout rejects invalid input before DB access\n";

require_once '/etc/freepbx.conf';
$db=FreePBX::Database();$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TEMPORARY TABLE queuecallback_config (queue_id VARCHAR(50),enabled INT,callback_key VARCHAR(50))');
$db->exec('CREATE TEMPORARY TABLE queues_config (extension VARCHAR(50))');
$db->exec("INSERT INTO queuecallback_config VALUES ('3007',1,'0'),('3008',0,'#'),('3009',1,'#'),('3010',1,'bad'),('0',1,'*')");
$db->exec("INSERT INTO queues_config VALUES ('3007'),('3008'),('3010'),('0')");
$factory=function() use($db){return $db;};
$run=run_check(['CALLBACK_QUEUE'=>'3007']+$stale,$factory);
expect_check($run['result']['enabled'] && $run['vars']['QUEUE_CALLBACK_KEY']==='0'
    && end($run['commands'])==='SET VARIABLE QUEUE_CALLBACK_ENABLED "1"','Valid config/key 0 rejected or enabled too early');
foreach(['3008','3009','9999'] as $queue){
    $run=run_check(['CALLBACK_QUEUE'=>$queue]+$stale,$factory);
    expect_check(!$run['result']['enabled'] && $run['vars']['QUEUE_CALLBACK_ENABLED']==='0'
        && $run['vars']['QUEUE_CALLBACK_KEY']==='' && $run['result']['error']==='QUEUE_UNAVAILABLE','Disabled/orphan/missing queue enabled');
}
$run=run_check(['CALLBACK_QUEUE'=>'3010']+$stale,$factory);
expect_check(!$run['result']['enabled'] && $run['result']['error']==='INVALID_CONFIG','Invalid callback key accepted');
$run=run_check($stale,$factory,['agi_dnid'=>'0']);
expect_check($run['result']['enabled'] && $run['vars']['QUEUE_CALLBACK_KEY']==='*','Unset queue fallback or queue zero rejected');
$run=run_check(['CALLBACK_QUEUE'=>'3007']+$stale,$factory,[],'SET VARIABLE QUEUE_CALLBACK_KEY "0"');
expect_check(!$run['result']['enabled'] && $run['vars']['QUEUE_CALLBACK_ENABLED']==='0','Result transport failure enabled callback');
echo "PASS: shared database check requires enabled existing queue, validates keys, preserves zero and publishes enabled last\n";
