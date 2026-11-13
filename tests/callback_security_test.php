<?php
/** Pattern and real service integration checks; no live configuration changes. */
require_once dirname(__DIR__).'/callback_security.php';
function expect_security($ok,$message) {if (!$ok) {throw new RuntimeException($message);}}
function security_matches($pattern,$number) {
    return preg_match(qcallback_compile_security_pattern($pattern)['regex'],ltrim($number,'+'))===1;
}
foreach ([
    ['_268NXXXXXX','2682345678',true],['_268NXXXXXX','2681345678',false],
    ['_268NXXXXXX','268234567',false],['_268NXXXXXX','26823456789',false],
    ['_268NXXXXXX','+2682345678',true],['_1268NXXXXXX','+12682345678',true],
    ['_268NXXXXXX','12682345678',false],['_NXXNXXXXXX','2121234567',false],
    ['_NXXNXXXXXX','2122345678',true],['_ZXX','100',true],['_ZXX','000',false],
    ['_nxx','200',true],['_1[237-9]X','180',true],['_1[237-9]X','140',false],
    ['_44.','44',false],['_44.','441',true],['_44!','44',true],['_44!','44123',true],
    ['2682345678','2682345678',true],['2682345678','26823456789',false],
    ['+12682345678','12682345678',true],['_+1268NXXXXXX','+12682345678',true],['0','0',true],
] as [$pattern,$number,$expected]) {
    expect_security(security_matches($pattern,$number)===$expected,'Wrong match: '.$pattern.' / '.$number);
}
foreach (['','_','_+','NXX','_X.X','_X!X','_X..','_[9-2]','_[X]','_[]','_[1-]','_[1-2-3]',
    '_[12','_12]','_X|X','_X$','_X\\d','_X/123','_X,1,Hangup()','_X;comment',"_X\n",'_X X',str_repeat('1',21)] as $bad) {
    $rejected=false;try {qcallback_compile_security_pattern($bad);}catch(InvalidArgumentException $e){$rejected=true;}
    expect_security($rejected,'Invalid/injectable pattern accepted: '.json_encode($bad));
}
echo "PASS: exact, full-length wildcard/range matching, + equivalence and invalid-pattern rejection\n";

require_once '/etc/freepbx.conf';
require_once dirname(__DIR__).'/Qcallback.class.php';
require_once dirname(__DIR__).'/callback_dispatch.php';
$pdo=FreePBX::Database();$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->exec("CREATE TEMPORARY TABLE queuecallback_security (id INT AUTO_INCREMENT PRIMARY KEY,pattern VARCHAR(20),description VARCHAR(100),
    area_code VARCHAR(10),queue_id VARCHAR(20),enabled INT,sort_order INT,created_at INT,updated_at INT)");
$pdo->exec("CREATE TEMPORARY TABLE queuecallback_security_settings (id INT PRIMARY KEY, destination VARCHAR(255))");
// Mutators call needreload(). Intercept only its legacy DB adapter so the test
// never changes the running PBX's Apply Config flag.
$originalDb=$GLOBALS['db'];
$GLOBALS['db']=new class {
    public $reloads=0;
    function query($sql) {if ($sql!=="UPDATE admin SET value = 'true' WHERE variable = 'need_reload'") {throw new LogicException('Unexpected global DB query');}$this->reloads++;return true;}
    function IsError($result) {return false;}
};
$module=new FreePBX\modules\Qcallback((object)['Database'=>$pdo]);
try {
    $add=function($data) use ($module) {return $module->addSecurityEntry(array_replace(['pattern'=>'_268NXXXXXX','description'=>'Test','area_code'=>'','queue_id'=>'','enabled'=>1,'sort_order'=>0],$data));};
    $global=$add(['pattern'=>'_44.','area_code'=>'999']);
    $queue=$add(['queue_id'=>'3007','area_code'=>'999']);
    $other=$add(['pattern'=>'_345NXXXXXX','queue_id'=>'3008']);
    $disabled=$add(['pattern'=>'_876NXXXXXX','enabled'=>0]);
    expect_security($module->isNumberBlocked('2682345678','3007'),'Pattern with wrong area label did not block');
    expect_security(!$module->isNumberBlocked('9992345678','3007'),'Informational area label used as prefix rule');
    expect_security(!$module->isNumberBlocked('2681345678','3007'),'N wildcard ignored');
    expect_security(!$module->isNumberBlocked('268234567','3007'),'Short prefix match incorrectly blocked');
    expect_security($module->isNumberBlocked('+441234','3007') && $module->isNumberBlocked('441234','3008'),'Global rule missing in scoped checks');
    expect_security(!$module->isNumberBlocked('2682345678','3008') && !$module->isNumberBlocked('3452345678','3007'),'Queue rule leaked across scopes');
    expect_security(!$module->isNumberBlocked('2682345678'),'Null scope unexpectedly includes queue-specific rules');
    expect_security(!$module->isNumberBlocked('8762345678','3007'),'Disabled rule blocked');
    $effective=$module->getSecurityEntries('3007');
    expect_security(count($effective)===3,'Queue view did not combine global and queue entries');
    $module->updateSecurityEntry($queue,['pattern'=>'_268NXXXXXX','description'=>'Edited','area_code'=>'','enabled'=>1]);
    expect_security($module->getSecurityEntry($queue)['queue_id']==='3007','Partial edit made queue rule global');
    $module->toggleSecurityEntry($queue,false);
    expect_security(!$module->isNumberBlocked('2682345678','3007'),'Disabling rule had no effect');
    $module->toggleSecurityEntry($queue,true);
    expect_security($module->isNumberBlocked('2682345678','3007'),'Re-enabling rule had no effect');
    $before=(int)$pdo->query('SELECT COUNT(*) FROM queuecallback_security')->fetchColumn();
    foreach (['add','update'] as $operation) {
        $rejected=false;try {
            if ($operation==='add') {$add(['pattern'=>"_X\n"]);}
            else {$module->updateSecurityEntry($queue,['pattern'=>'_[9-2]','description'=>'Bad']);}
        } catch (InvalidArgumentException $e) {$rejected=true;}
        expect_security($rejected,'Service accepted malformed pattern on '.$operation);
    }
    expect_security((int)$pdo->query('SELECT COUNT(*) FROM queuecallback_security')->fetchColumn()===$before,'Invalid add changed table');
    $plan=$module->generateSecurityDialplan();
    $parts=preg_split('/^\[([^]]+)\]\R/m',$plan,-1,PREG_SPLIT_DELIM_CAPTURE);
    $contexts=[];for($i=1;$i<count($parts);$i+=2){$contexts[$parts[$i]]=$parts[$i+1];}
    expect_security(str_contains($contexts['qcallback-security-block'],'exten => _44.,')
        && !str_contains($contexts['qcallback-security-block'],'_268'),'Generated global context includes queue rules');
    expect_security(str_contains($contexts['qcallback-security-block-3007'],'exten => _268NXXXXXX,')
        && str_contains($contexts['qcallback-security-block-3007'],'exten => _+268NXXXXXX,')
        && !str_contains($contexts['qcallback-security-block-3007'],'_345'),'Generated scoped context is incorrect');
    expect_security(str_contains($contexts['qcallback-security-block-3007'],'include => qcallback-security-block'),'Scoped dialplan lacks global rules');
    $remove=new ReflectionMethod($module,'removeSecurityDialplanSections');$remove->setAccessible(true);
    expect_security(!str_contains($remove->invoke($module,$plan."[unrelated]\nexten => 1,1,Hangup()\n"),'qcallback-security-block')
        && str_contains($remove->invoke($module,$plan."[unrelated]\n"),'[unrelated]'),'Security context cleanup lost unrelated context or left scoped rules');
    echo "PASS: real service honors patterns, global/queue scope, disabled rules, partial edits and scoped dialplan\n";

    // Legacy invalid enabled rules defer dispatch; no calls or attempts occur.
    $pdo->exec("INSERT INTO queuecallback_security (pattern,description,queue_id,enabled,sort_order) VALUES ('_[9-2]','Legacy bad','3007',1,0)");
    $badId=(int)$pdo->lastInsertId();$rejected=false;
    try {$module->isNumberBlocked('2682345678','3007');}catch(InvalidArgumentException $e){$rejected=true;}
    expect_security($rejected,'Invalid enabled legacy rule silently ignored');
    $pdo->exec("CREATE TEMPORARY TABLE queuecallback_requests (id INT PRIMARY KEY,queue_id VARCHAR(50),callback_number VARCHAR(50),
        status VARCHAR(20),attempts INT,last_attempt INT,max_attempts INT,time_requested INT,time_processed INT,
        uniqueid VARCHAR(50),queue_joined_at INT,queue_priority INT DEFAULT 0,reserved_agent VARCHAR(64))");
    $pdo->exec("CREATE TEMPORARY TABLE queuecallback_config (queue_id VARCHAR(50),enabled INT,processing_interval INT,retry_interval INT,
        max_attempts INT,call_first VARCHAR(20),outbound_route_id INT,return_message_id INT, return_confirm_timeout INT DEFAULT 10, return_confirm_repeats INT DEFAULT 1)");
    $pdo->exec("INSERT INTO queuecallback_config VALUES ('3007',1,0,30,3,'customer',0,NULL,10,1)");
    $pdo->exec("INSERT INTO queuecallback_requests (id,queue_id,callback_number,status,attempts,max_attempts,time_requested)
        VALUES (1,'3007','2682345678','pending',0,3,".(time()-60).")");
    $stateCalls=0;$provider=function() use (&$stateCalls){$stateCalls++;return null;};
    $result=qcallback_dispatch_request($pdo,1,$module,$provider);
    expect_security($result['reason']==='DISPATCH_ERROR' && $stateCalls===0,'Invalid rule did not stop dispatch before AMI');
    expect_security($pdo->query('SELECT status FROM queuecallback_requests WHERE id=1')->fetchColumn()==='pending','Invalid rule cancelled request');
    $module->toggleSecurityEntry($badId,false); // Must remain possible to disable a broken rule.
    $rejected=false;try {$module->toggleSecurityEntry($badId,true);}catch(InvalidArgumentException $e){$rejected=true;}
    expect_security($rejected,'Invalid legacy rule could be enabled');
    $result=qcallback_dispatch_request($pdo,1,$module,$provider);
    expect_security($result['reason']==='BLOCKED' && $stateCalls===0,'Matching rule not checked before readiness');
    $row=$pdo->query('SELECT * FROM queuecallback_requests WHERE id=1')->fetch(PDO::FETCH_ASSOC);
    expect_security($row['status']==='blocked' && (int)$row['attempts']===0 && (int)$row['time_processed']>0,'Blocked request consumed attempt or lacks timestamp');
    $pdo->exec("UPDATE queuecallback_requests SET status='pending',callback_number='5551234567'");
    $pdo->exec('DROP TEMPORARY TABLE queuecallback_security');
    // Shadow the real table with a deliberately broken schema to prove that
    // database errors never become permission to dial.
    $pdo->exec('CREATE TEMPORARY TABLE queuecallback_security (id INT)');
    $result=qcallback_dispatch_request($pdo,1,$module,$provider);
    expect_security($result['reason']==='DISPATCH_ERROR' && $stateCalls===0,'Blocklist read failure allowed dispatch');
    echo "PASS: invalid legacy rules/read failures defer; blocked requests are marked blocked before AMI without an attempt\n";
} finally {$GLOBALS['db']=$originalDb;}
