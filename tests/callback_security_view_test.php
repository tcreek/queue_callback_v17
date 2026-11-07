<?php
/** Render the real queue view with a fake service; no FreePBX or DB connection. */
class SecurityViewFixture {
    public $calls = [];
    function getQueueCallbackConfig($queue) { return ['enabled'=>1,'processing_interval'=>30]; }
    function getSecurityEntries($queue) { return [['id'=>1,'description'=>'Test rule','pattern'=>'_268NXXXXXX','area_code'=>'268','queue_id'=>'3007','enabled'=>1]]; }
    function addSecurityEntry($data) {$this->calls[]=['add',$data];}
    function updateSecurityEntry($id,$data) {$this->calls[]=['update',$id,$data];}
    function deleteSecurityEntry($id) {$this->calls[]=['delete',$id];}
    function toggleSecurityEntry($id,$enabled) {$this->calls[]=['toggle',$id,$enabled];}
}
class FreePBX {
    public static $module;
    static function Qcallback() {return self::$module;}
    static function Database() {throw new RuntimeException('No database in view test');}
}
function expect_security_view($ok,$message) {if (!$ok) {throw new RuntimeException($message);}}
function render_security_view(array $post) {
    $_POST=$post; $_GET=[]; $queue_id='3007';
    FreePBX::$module=new SecurityViewFixture();
    ob_start();include dirname(__DIR__).'/views/callback_config_standalone.php';return ob_get_clean();
}
foreach (['add_entry'=>'add','update_entry'=>'update','delete_entry'=>'delete','toggle_entry'=>'toggle'] as $action=>$expected) {
    render_security_view(['sec_action'=>$action,'id'=>'1','pattern'=>'_268NXXXXXX','description'=>'Test','area_code'=>'268','enabled'=>'on']);
    expect_security_view(count(FreePBX::$module->calls)===1 && FreePBX::$module->calls[0][0]===$expected,'Security action went to wrong handler: '.$action);
    if ($action==='update_entry') { expect_security_view(!array_key_exists('queue_id',FreePBX::$module->calls[0][2]),'Partial queue-view edit changed scope'); }
}
render_security_view(['sec_action'=>'toggle_entry','id'=>'1']);
expect_security_view(FreePBX::$module->calls[0]===['toggle',1,false],'Unchecked toggle did not disable');
$html=render_security_view([]);
$level=0;
preg_match_all('~</?form\b[^>]*>~i',$html,$tags);
foreach ($tags[0] as $tag) {
    if (str_starts_with(strtolower($tag),'</')) {$level--;}
    else {expect_security_view($level===0,'Nested form remains in queue configuration');$level++;}
    expect_security_view($level>=0,'Unbalanced form closing tag');
}
expect_security_view($level===0,'Unclosed form');
$dom=new DOMDocument();libxml_use_internal_errors(true);$dom->loadHTML($html);libxml_clear_errors();
$xpath=new DOMXPath($dom);
foreach (['qcb-security-add','qcb-security-toggle-1','qcb-security-delete-1'] as $id) {
    expect_security_view($xpath->query('//form[@id="'.$id.'"]')->length===1,'Detached security form missing');
    expect_security_view($xpath->query('//input[@form="'.$id.'" and @name="sec_action"]')->length===1,'Action not associated with security form');
}
expect_security_view($xpath->query('//input[@form="qcb-security-toggle-1" and @name="enabled"]')->length===1,'Toggle enabled field missing');
expect_security_view($xpath->query('//input[@form="qcb-security-add" and @name="pattern"]')->length===1,'Add pattern belongs to wrong form');
echo "PASS: queue security actions route correctly; toggles and detached forms submit independent actions\n";
