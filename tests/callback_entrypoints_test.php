<?php
/** Framework adapters tested without bootstrapping FreePBX or touching the PBX. */
define('FREEPBX_IS_AUTH',true);
class EntrypointModuleFixture {
    public $generations=[];public $events=0;public $processors=0;public $installs=0;public $saved=[];
    function generateCallbackDialplan($force,$reload) {$this->generations[]=[$force,$reload];}
    function refreshCallbackEvents() {$this->events++;}
    function processIntelligentCallbacks() {$this->processors++;}
    function install() {$this->installs++;}
    function getCallbackEnabledQueues() {return [['queue_id'=>'3007','callback_key'=>'0']];}
    function deleteQueueCallbackConfig($id) {$this->saved[]=['delete',$id];return true;}
    function setQueueCallbackConfig($id,$config) {$this->saved[]=[$id,$config];}
}
class FreePBX {
    public static $module;
    static function Qcallback(){return self::$module;}
    static function Database(){return new class {
        function prepare($sql){return new class {function execute($params){} function fetchColumn(){return '3007';}};}
    };}
}
function out($message) {}
function expect_entrypoint($ok,$message) {if(!$ok){throw new RuntimeException($message);}}
FreePBX::$module=new EntrypointModuleFixture();
$module=FreePBX::$module;
$files=($argv[1]??'functions-first')==='hooks-first'?['hooks.php','functions.inc.php']:['functions.inc.php','hooks.php'];
foreach($files as $file){require_once dirname(__DIR__).'/'.$file;}
$ext=new class {function add(){throw new LogicException('Legacy generator still modifies framework extensions');}};
queuecallback_create_queue_dialplan();queuecallback_write_custom_dialplan();
generateCallbackEnabledQueueContexts($ext);generateCallbackQueueDialplan($ext);
queuecallback_hook_core_reload();queuecallback_hook_core_configprocess();
expect_entrypoint(count($module->generations)===6,'Legacy generation entry point did not delegate');
foreach($module->generations as $args){expect_entrypoint($args===[true,false],'Hook performed its own reload');}
qcallback_get_config('other-engine');
$_REQUEST['action']='uninstall';queuecallback_create_queue_dialplan();queuecallback_hook_maintenance();
expect_entrypoint(count($module->generations)===6 && $module->processors===0,'Uninstall guard bypassed');
$_REQUEST=[];queuecallback_hook_maintenance();queuecallback_process_pending();
expect_entrypoint($module->processors===2,'Maintenance/explicit processing did not share worker');
queuecallback_recreate_events();expect_entrypoint($module->events===1,'Legacy heartbeat did not delegate');
$_SERVER['REQUEST_METHOD']='POST';
$_POST=['action'=>'edit','extdisplay'=>'3007'];queuecallback_hook_queues_configprocess();
expect_entrypoint(!$module->saved,'Queue edit without callback fields changed settings');
$_POST+=['callback_enabled'=>'1','callback_key'=>'0','callback_outbound_route_id'=>'0'];queuecallback_hook_queues_configprocess();
expect_entrypoint($module->saved===[['3007',['enabled'=>'1','outbound_route_id'=>'0','callback_key'=>'0']]],'Queue form lost zero values or overwrote unsubmitted fields');
expect_entrypoint(queuecallback_hook_queue_add('3007')===false,'Repeated queue-add hook overwrote existing configuration');
expect_entrypoint(queuecallback_get_enabled_queues()===[['queue_id'=>'3007','callback_key'=>'0']],'Legacy enabled-queue lookup diverged');
expect_entrypoint(queuecallback_delete_config('3007') && end($module->saved)===['delete','3007'],'Legacy config deletion did not delegate');
include dirname(__DIR__).'/install.php';
expect_entrypoint($module->installs===1,'Legacy installer did not delegate to BMO install');
echo 'PASS: '.($argv[1]??'functions-first')." loads safely; legacy adapters share generation, scheduling, config and installation\n";
