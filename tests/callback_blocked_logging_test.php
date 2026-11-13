<?php
/** Terminal blocked audit records, report display/filter and failure-safe routing; temporary tables only. */
require_once dirname(__DIR__).'/callback_requests.php';
require_once dirname(__DIR__).'/callback_security.php';
require_once dirname(__DIR__).'/callback_install.php';
require_once '/etc/freepbx.conf';
function blocked_expect($ok,$message) {if (!$ok) {throw new RuntimeException($message);}}
$db=FreePBX::Database();
$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$installer=new class($db) {
    function __construct(private $db) {}
    function exec($sql) {return $this->db->exec(str_replace('CREATE TABLE IF NOT EXISTS','CREATE TEMPORARY TABLE IF NOT EXISTS',$sql));}
    function query($sql) {return $this->db->query($sql);}
    function prepare($sql) {return $this->db->prepare($sql);}
};
qcallback_install_schema($installer);
$db->exec("ALTER TABLE queuecallback_requests MODIFY status ENUM('pending','processing','completed','failed','cancelled') DEFAULT 'pending'");
$db->exec("INSERT INTO queuecallback_requests (queue_id,callback_number,status,time_requested) VALUES ('3007','5551234567','cancelled',1)");
qcallback_upgrade_blocked_status($db);
qcallback_upgrade_blocked_status($db);
blocked_expect($db->query('SELECT status FROM queuecallback_requests')->fetchColumn()==='cancelled','Migration changed historical status');
$db->exec('CREATE TEMPORARY TABLE queues_config (extension VARCHAR(50), descr VARCHAR(50)) DEFAULT CHARSET=utf8');
$db->exec("INSERT INTO queues_config VALUES ('3007','Test Queue')");
$db->exec("INSERT INTO queuecallback_config (queue_id,enabled,confirm_number,max_attempts) VALUES ('3007',1,0,3)");
$db->exec("INSERT INTO queuecallback_security (pattern,description,queue_id,enabled) VALUES ('_44.','Test rule','',1)");
function blocked_run($db,$source) {
    $vars=['QCB_CONFIRMED'=>'1','QCB_CONFIRM_SOURCE'=>$source,'CONFIRM2'=>'1','READSTATUS'=>'OK',
        'CALLBACK_QUEUE'=>'3007','CALLBACK_NUMBER'=>'+441234567890'];
    $result=qcallback_store_agi(['agi_callerid'=>'705','agi_uniqueid'=>'blocked-test'],function($command) use (&$vars) {
        if (preg_match('/^SET VARIABLE ([A-Z0-9_]+) "(.*)"$/D',$command,$m)) {
            $vars[$m[1]]=stripcslashes($m[2]);return '200 result=1';
        }
        if (preg_match('/^GET VARIABLE ([A-Z0-9_]+)$/D',$command,$m)) {
            return isset($vars[$m[1]])?'200 result=1 ('.$vars[$m[1]].')':'200 result=0';
        }
        throw new RuntimeException('Unexpected AGI command');
    },fn()=>$db);
    blocked_expect(!$result['success'] && $result['error']==='BLOCKED','Blocked request became successful');
    blocked_expect($vars['QCB_BLOCKED']==='1' && $vars['QCB_STORE_SUCCESS']==='0','Logging prevented blocking');
    return $result;
}
foreach (['dtmf','detected'] as $source) {
    $result=blocked_run($db,$source);
    blocked_expect(ctype_digit($result['id']??''),'Blocked audit ID missing');
    $row=$db->query('SELECT * FROM queuecallback_requests WHERE id='.(int)$result['id'])->fetch(PDO::FETCH_ASSOC);
    blocked_expect($row['status']==='blocked' && (int)$row['attempts']===0 && (int)$row['max_attempts']===0
        && (int)$row['time_processed']>0 && $row['callback_number']==='+441234567890'
        && $row['caller_id']==='705' && $row['queue_id']==='3007','Incorrect blocked audit record');
}
blocked_expect((int)$db->query("SELECT COUNT(*) FROM queuecallback_requests WHERE status IN ('pending','processing')")->fetchColumn()===0,'Blocked audit was dialable');
echo "PASS: schema upgrade preserves history; confirmed/detected blocked requests log once with no dialing attempts\n";
$fault=new class($db) {
    function __construct(private $db) {}
    function prepare($sql) {if (str_contains($sql,'INSERT INTO queuecallback_requests')) {throw new PDOException('Simulated logging failure');} return $this->db->prepare($sql);}
    function query($sql) {return $this->db->query($sql);}
};
$result=blocked_run($fault,'dtmf');
blocked_expect($result['id']===null,'Failed log returned an ID');
echo "PASS: logging failure still silently blocks the caller\n";
$early=[];
$check=qcallback_check_agi(['agi_callerid'=>'+441234567890','agi_uniqueid'=>'early-block'],function($command) use (&$early) {
    if (preg_match('/^SET VARIABLE ([A-Z0-9_]+) "(.*)"$/D',$command,$m)) {$early[$m[1]]=stripcslashes($m[2]);return '200 result=1';}
    if ($command==='GET VARIABLE CALLBACK_QUEUE') {return '200 result=1 (3007)';}
    throw new RuntimeException('Unexpected check protocol');
},fn()=>$db);
blocked_expect(!$check['enabled'] && $check['error']==='BLOCKED' && $early['QCB_BLOCKED']==='1','Detected blocked caller was not stopped before prompts');
echo "PASS: detected blocked numbers are logged and stopped before callback confirmation prompts\n";
$_REQUEST=['status_filter'=>'blocked'];
ob_start();include dirname(__DIR__).'/views/callback_reports.php';$html=ob_get_clean();
blocked_expect(str_contains($html,'value="blocked" selected') && substr_count($html,'+441234567890')===4
    && !str_contains($html,'5551234567') && str_contains($html,'Blocked'),'Report does not display/filter blocked records');
require_once dirname(__DIR__).'/Qcallback.class.php';
$module=new FreePBX\modules\Qcallback((object)['Database'=>$db]);
blocked_expect((int)$module->getCallbackStats('3007')['blocked']===3,'Blocked summary missing');
echo "PASS: report filter, Blocked labels and queue statistics include the audit records\n";
