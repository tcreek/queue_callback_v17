<?php
/** Snapshot/restore integration; connection-local temporary tables, no live restore. */
require_once '/etc/freepbx.conf';
require_once dirname(__DIR__).'/callback_install.php';
require_once dirname(__DIR__).'/Qcallback.class.php';
function backup_expect($ok,$message) {if (!$ok) {throw new RuntimeException($message);}}
$pdo=FreePBX::Database();$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$installer=new class($pdo) {
    function __construct(private $pdo) {}
    function exec($sql) {return $this->pdo->exec(str_replace('CREATE TABLE IF NOT EXISTS','CREATE TEMPORARY TABLE IF NOT EXISTS',$sql));}
    function query($sql) {return $this->pdo->query($sql);}
    function prepare($sql) {return $this->pdo->prepare($sql);}
};
qcallback_install_schema($installer);
$original=$GLOBALS['db'];
$GLOBALS['db']=new class {
    function query($sql) {if ($sql!=="UPDATE admin SET value = 'true' WHERE variable = 'need_reload'") {throw new RuntimeException('Unexpected live mutation');}return true;}
    function IsError($result) {return false;}
};
$module=new FreePBX\modules\Qcallback((object)['Database'=>$pdo]);
try {
    $pdo->exec("INSERT INTO queuecallback_config (queue_id,enabled,confirm_number) VALUES ('3007',1,0)");
    $pdo->exec("INSERT INTO queuecallback_security (id,pattern,description,queue_id,enabled,sort_order) VALUES
        (11,'_44.','Global','',1,2),(12,'_268NXXXXXX','Scoped','3007',0,1)");
    $pdo->exec("INSERT INTO queuecallback_requests (id,queue_id,callback_number,status,time_requested,attempts) VALUES
        (21,'3007','441234','blocked',100,0),(22,'3007','705','completed',101,1)");
    $snapshot=$module->backup();
    backup_expect(count($snapshot['queuecallback_requests'])===2,'Backup omitted request history');
    $pdo->exec("DELETE FROM queuecallback_security");
    $module->restore($snapshot);
    backup_expect($module->backup()===$snapshot,'Snapshot did not roundtrip');
    $module->restore($snapshot);
    backup_expect($module->backup()===$snapshot,'Repeated restore duplicated rows');
    echo "PASS: native snapshot preserves rules, scope, enabled state, ordering and request history/IDs; repeated restore is stable\n";
    $bad=$snapshot;$bad['queuecallback_security'][0]['unknown`column']='bad';
    $rejected=false;try {$module->restore($bad);}catch(InvalidArgumentException $e) {$rejected=true;}
    backup_expect($rejected && $module->backup()===$snapshot,'Unknown backup column changed live snapshot');
    backup_expect($module->backup()===$snapshot,'Data gone before SQL-failure test');
    $bad=$snapshot;$bad['queuecallback_config'][0]['enabled']=0;
    $bad['queuecallback_security'][]=['id'=>999,'pattern'=>null,'description'=>'Invalid'];
    $rejected=false;try {$module->restore($bad);}catch(PDOException $e) {$rejected=true;}
    backup_expect($rejected && $module->backup()===$snapshot,'Insert failure left a partial restore');
    echo "PASS: malformed backups roll back the complete restore transaction\n";
    $module->restore(['queuecallback_config'=>[],'queuecallback_requests'=>[]]);
    $legacy=$module->backup();
    backup_expect($legacy['queuecallback_security']===$snapshot['queuecallback_security'],'Legacy backup cleared missing security sections');
    $module->restore(['queuecallback_security'=>[], 'queuecallback_security_settings'=>[['id'=>1,'destination'=>'ext-local,250,1']]]);
    backup_expect($module->getSecurityEntries()===[],'Empty sections were not restored');
    $module->restore($snapshot);
    echo "PASS: old backups preserve missing security sections; explicitly empty lists restore empty; obsolete destination sections are ignored\n";
    $fault=new FreePBX\modules\Qcallback((object)['Database'=>new class {
        function query($sql) {throw new PDOException('Simulated read failure');}
    }]);
    $rejected=false;try {$fault->backup();}catch(PDOException $e) {$rejected=true;}
    backup_expect($rejected,'Read failure silently produced incomplete backup');
    echo "PASS: unreadable data fails backup instead of producing a misleading empty snapshot\n";
    require_once dirname(__DIR__).'/Backup.php';
    require_once dirname(__DIR__).'/Restore.php';
    class BackupAdapterFixture extends FreePBX\modules\Qcallback\Backup {
        public $captured;
        function wire($module) {$this->FreePBX=(object)['Qcallback'=>$module];}
        function addConfigs($settings) {$this->captured=$settings;}
    }
    class RestoreAdapterFixture extends FreePBX\modules\Qcallback\Restore {
        public $snapshot;
        function wire($module,$snapshot) {$this->FreePBX=(object)['Qcallback'=>$module];$this->snapshot=$snapshot;}
        function getConfigs($options=[]) {return $this->snapshot;}
    }
    $backupAdapter=(new ReflectionClass(BackupAdapterFixture::class))->newInstanceWithoutConstructor();
    $backupAdapter->wire($module);$backupAdapter->runBackup('fixture','fixture');
    backup_expect($backupAdapter->captured===$snapshot,'Native backup adapter lost data');
    $restoreAdapter=(new ReflectionClass(RestoreAdapterFixture::class))->newInstanceWithoutConstructor();
    $restoreAdapter->wire($module,$snapshot);$restoreAdapter->runRestore();
    backup_expect($module->backup()===$snapshot,'Native restore adapter lost data');
    echo "PASS: FreePBX 17 BackupBase/RestoreBase adapters call the tested snapshot implementation\n";

} finally {$GLOBALS['db']=$original;}
