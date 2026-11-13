<?php
/** Real subprocess coverage of copied/installed AGIs. All database writes use connection-local temporary tables. */
function expect_runtime($ok,$message){if(!$ok){throw new RuntimeException($message);}}
function run_runtime_agi(string $path,array $vars,array $options=[]): array {
    $pipes=[];$proc=proc_open(array_merge([PHP_BINARY],$options,[$path]),[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    expect_runtime(is_resource($proc),'Cannot launch AGI subprocess');$commands=[];
    try {
        fwrite($pipes[0],"agi_callerid: 705\nagi_dnid: 3007\nagi_uniqueid: runtime-test\n\n");fflush($pipes[0]);stream_set_timeout($pipes[1],10);
        while(($line=fgets($pipes[1]))!==false){
            expect_runtime(count($commands)<50,'AGI did not finish');$command=rtrim($line,"\r\n");
            // PHP auto_prepend_file can expose the main file's interpreter line.
            if($options && !$commands && $command==='#!/usr/bin/php'){continue;}
            $commands[]=$command;
            if(preg_match('/^SET VARIABLE ([A-Z0-9_]+) "(.*)"$/D',$command,$m)){$vars[$m[1]]=stripcslashes($m[2]);$reply='200 result=1';}
            elseif(preg_match('/^GET VARIABLE ([A-Z0-9_]+)$/D',$command,$m)){$reply=array_key_exists($m[1],$vars)?'200 result=1 ('.$vars[$m[1]].')':'200 result=0';}
            else{
                $diagnostic=$command;
                foreach(($GLOBALS['amp_conf']??[]) as $value){if(is_string($value)&&strlen($value)>=4){$diagnostic=str_replace($value,'[redacted]',$diagnostic);}}
                throw new RuntimeException('Non-protocol AGI output: '.substr($diagnostic,0,240));
            }
            fwrite($pipes[0],$reply."\n");fflush($pipes[0]);
        }
        expect_runtime(!stream_get_meta_data($pipes[1])['timed_out'],'AGI timed out');
        fclose($pipes[0]);fclose($pipes[1]);$stderr=stream_get_contents($pipes[2]);fclose($pipes[2]);
        $status=proc_close($proc);$proc=null;
        expect_runtime($status===0&&$stderr==='','AGI failed to load or bootstrap; no secret diagnostics are printed');
        return ['vars'=>$vars,'commands'=>$commands];
    }finally{foreach($pipes as $pipe){if(is_resource($pipe)){fclose($pipe);}}if(is_resource($proc)){proc_terminate($proc);proc_close($proc);}}
}
$installed=in_array('--installed',$argv,true);
$referenceQueue=null;
foreach($argv as $arg){if(preg_match('/^--queue=([0-9]+)$/D',$arg,$m)){$referenceQueue=$m[1];}}
$root=null;
if($installed){$dir='/var/lib/asterisk/agi-bin';}
else {
    $root=sys_get_temp_dir().'/qcb-agi-runtime-'.bin2hex(random_bytes(8));mkdir($root,0700);mkdir($root.'/agi-bin',0700);$dir=$root.'/agi-bin';
    foreach(['queuecallback-check.agi','queuecallback-store.agi','queuecallback-complete.agi'] as $name){copy(dirname(__DIR__).'/agi-bin/'.$name,$dir.'/'.$name);}
}
try {
    $result=run_runtime_agi($dir.'/queuecallback-check.agi',['CALLBACK_QUEUE'=>'invalid','QUEUE_CALLBACK_ENABLED'=>'1']);
    expect_runtime(($result['vars']['QCB_CHECK_ERROR']??'')==='INVALID_QUEUE'&&$result['vars']['QUEUE_CALLBACK_ENABLED']==='0','Copied/installed check AGI could not load helper');
    $result=run_runtime_agi($dir.'/queuecallback-store.agi',['CALLBACK_QUEUE'=>'3007','CALLBACK_NUMBER'=>'invalid','QCB_CONFIRMED'=>'1','QCB_CONFIRM_SOURCE'=>'dtmf','CONFIRM2'=>'1','READSTATUS'=>'OK','QCB_STORE_SUCCESS'=>'1']);
    expect_runtime(($result['vars']['QCB_STORE_ERROR']??'')==='INVALID_NUMBER'&&$result['vars']['QCB_STORE_SUCCESS']==='0','Copied/installed store AGI could not load helper');
    echo "PASS: copied/installed AGIs find shared helpers outside the module tree, without path substitution\n";
    require_once dirname(__DIR__).'/callback_requests.php';
    $db=qcallback_agi_database();
    expect_runtime((int)$db->query('SELECT 1')->fetchColumn()===1,'Function-scoped FreePBX bootstrap failed');
    echo "PASS: shared AGI factory bootstraps FreePBX from function scope\n";
    if($referenceQueue!==null){
        $stmt=$db->prepare('SELECT enabled, callback_key FROM queuecallback_config WHERE queue_id = ?');$stmt->execute([$referenceQueue]);$config=$stmt->fetch(PDO::FETCH_ASSOC);
        expect_runtime($config&&(int)$config['enabled']===1,'Reference queue must be enabled for read-only runtime probe');
        $result=run_runtime_agi($dir.'/queuecallback-check.agi',['CALLBACK_QUEUE'=>$referenceQueue,'QUEUE_CALLBACK_ENABLED'=>'0']);
        expect_runtime(($result['vars']['QUEUE_CALLBACK_ENABLED']??'')==='1'
            &&$result['vars']['QUEUE_CALLBACK_KEY']===$config['callback_key']
            &&($result['vars']['QCB_CHECK_ERROR']??'x')==='','Actual AGI bootstrap/config lookup did not enable reference queue');
        echo "PASS: fresh installed/copied check AGI enables the configured reference queue\n";
    }
    // Run the real store executable against connection-local tables created by
    // auto_prepend_file. Request shape comes from the migrated production table.
    $fixture=tempnam(sys_get_temp_dir(),'qcb-store-prepend-');
    $fixtureCode=<<<'FIXTURE'
<?php
require_once '/var/www/html/admin/modules/qcallback/callback_requests.php';
$db=qcallback_agi_database();
if(qcallback_agi_database()!==$db){throw new RuntimeException('AGI factory must reuse the temporary-table connection');}
$db->exec('CREATE TEMPORARY TABLE qcb_runtime_request_shape LIKE queuecallback_requests');
$db->exec('CREATE TEMPORARY TABLE queuecallback_requests LIKE qcb_runtime_request_shape');
$db->exec('DROP TEMPORARY TABLE qcb_runtime_request_shape');
$db->exec('CREATE TEMPORARY TABLE queuecallback_config (queue_id VARCHAR(50),enabled INT,max_attempts INT)');
$db->exec('CREATE TEMPORARY TABLE queues_config (extension VARCHAR(50))');
$db->exec("INSERT INTO queuecallback_config VALUES ('3007',1,3)");
$db->exec("INSERT INTO queues_config VALUES ('3007')");
register_shutdown_function(function() use($db){
    if((int)$db->query('SELECT COUNT(*) FROM queuecallback_requests')->fetchColumn()!==1){
        error_log('Temporary AGI request was not stored');exit(1);
    }
});
FIXTURE;
    file_put_contents($fixture,$fixtureCode);chmod($fixture,0600);
    try {
        $result=run_runtime_agi($dir.'/queuecallback-store.agi',['CALLBACK_QUEUE'=>'3007','CALLBACK_NUMBER'=>'705','QCB_CONFIRMED'=>'1','QCB_CONFIRM_SOURCE'=>'dtmf','CONFIRM2'=>'1','READSTATUS'=>'OK',
            'QUEUEJOINTIME'=>(string)(time()-120),'QUEUE_PRIO'=>'0','QUEUEPOSITION'=>'1'],['-d','auto_prepend_file='.$fixture]);
        expect_runtime(($result['vars']['QCB_STORE_SUCCESS']??'')==='1'&&ctype_digit($result['vars']['QCB_REQUEST_ID']??''),
            'Real store executable could not save against the live table shape');
        echo "PASS: real store AGI reports success using temporary tables with the migrated production schema; no live request created\n";
    }finally{unlink($fixture);}
    // Verify the actual result AGI reads the customer-confirmation flag. An
    // answered voicemail with positive timing must still return to pending.
    foreach (['1'=>'completed', '0'=>'pending', ''=>'pending'] as $confirmation=>$expected) {
        $fixture=tempnam(sys_get_temp_dir(),'qcb-result-prepend-');
        $log=tempnam(sys_get_temp_dir(),'qcb-result-log-');
        $code=<<<'RESULT_FIXTURE'
<?php
require_once '/var/www/html/admin/modules/qcallback/callback_requests.php';
$db=qcallback_agi_database();
$db->exec('CREATE TEMPORARY TABLE queuecallback_requests (id INT PRIMARY KEY,queue_id VARCHAR(50),status VARCHAR(20),attempts INT,max_attempts INT,last_attempt INT,time_processed INT NULL,fifo_connected_at INT DEFAULT NULL)');
$db->exec('CREATE TEMPORARY TABLE queuecallback_config (queue_id VARCHAR(50),enabled INT,max_attempts INT)');
$db->exec("INSERT INTO queuecallback_config VALUES ('3007',1,3)");
$db->exec("INSERT INTO queuecallback_requests VALUES (7001,'3007','processing',1,3,1,NULL,NULL)");
RESULT_FIXTURE;
        $code.="\nini_set('error_log',".var_export($log,true).");\n";
        $code.='register_shutdown_function(function() use($db){if($db->query("SELECT status FROM queuecallback_requests WHERE id=7001")->fetchColumn()!=='.var_export($expected,true).'){exit(1);}});';
        file_put_contents($fixture,$code);chmod($fixture,0600);
        try {
            run_runtime_agi($dir.'/queuecallback-complete.agi',[
                'CALLBACK_ID'=>'7001','CALLBACK_ATTEMPT'=>'1','QCB_MODE'=>'agent',
                'QCB_CUSTOMER_CONFIRMED'=>(string)$confirmation,'DIALSTATUS'=>'ANSWER','ANSWEREDTIME_MS'=>'2500'],
                ['-d','auto_prepend_file='.$fixture]);
        } finally {unlink($fixture);unlink($log);}
    }
    echo "PASS: real result AGI completes confirmed bridges and retries unconfirmed/voicemail answers; no live records changed\n";
}finally{if($root){foreach(glob($dir.'/*') as $path){unlink($path);}rmdir($dir);rmdir($root);}}
