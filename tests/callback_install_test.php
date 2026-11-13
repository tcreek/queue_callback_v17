<?php
/** Shared installer operations in /tmp and temporary tables only. */
require_once dirname(__DIR__).'/callback_install.php';
require_once dirname(__DIR__).'/callback_dialplan.php';
function expect_install($ok,$message){if(!$ok){throw new RuntimeException($message);}}
$root=sys_get_temp_dir().'/qcb-install-test-'.bin2hex(random_bytes(8));mkdir($root,0700);
function remove_install_fixture($path){foreach(scandir($path) as $name){if($name==='.'||$name==='..'){continue;}$child=$path.'/'.$name;if(is_dir($child)){remove_install_fixture($child);}else{unlink($child);}}rmdir($path);}
try {
    $source=dirname(__DIR__).'/agi-bin';$destination=$root.'/agi';$modulePath="/tmp/test's qcallback";
    qcallback_install_agis($source,$destination,$modulePath,null);
    qcallback_install_agis($source,$destination,$modulePath,null);
    foreach(['queuecallback-store.agi','queuecallback-check.agi','queuecallback-complete.agi','queuecallback-result.agi','queuecallback-fifo.agi'] as $name){
        $expected=str_replace("\r\n","\n",file_get_contents($source.'/'.$name));
        expect_install(file_get_contents($destination.'/'.$name)===$expected,'Installed AGI differs from packaged source: '.$name);
        expect_install((fileperms($destination.'/'.$name)&0777)===0755,'Wrong installed AGI permissions');
    }
    mkdir($root.'/sounds');file_put_contents($root.'/sounds/callback-return-confirm.wav','wav fixture');file_put_contents($root.'/sounds/callback-return-confirm.ulaw','ulaw fixture');
    qcallback_install_announcements($root.'/sounds',$root.'/installed-sounds',['custom/callback-return-confirm'],null);
    expect_install(file_get_contents($root.'/installed-sounds/callback-return-confirm.wav')==='wav fixture'
        && file_get_contents($root.'/installed-sounds/callback-return-confirm.ulaw')==='ulaw fixture','Default sound formats not copied');
    $script='/tmp/my project/qcallback/process_callbacks.php';
    $current="MAILTO=test@example.invalid\n* * * * * php /other/process_callbacks.php\n"
        ."* * * * * php /old/qcallback/intelligent_callback_processor.php\n# Queue Callback processor (every 15s)\n";
    $cron=qcallback_processor_crontab($current,$script);
    expect_install(substr_count($cron,escapeshellarg($script))===4 && !str_contains($cron,'intelligent_callback_processor.php'),'Cron did not replace legacy jobs');
    expect_install(str_contains($cron,'/other/process_callbacks.php') && str_contains($cron,'MAILTO='),'Unrelated cron entries removed');
    expect_install(qcallback_processor_crontab($cron,$script)===$cron,'Repeated cron installation duplicates entries');
    echo "PASS: both installs use packaged AGIs and sounds; cron migration is repeatable and preserves unrelated jobs\n";

    $config=['queue_id'=>'3007','callback_key'=>'0','confirm_number'=>0,'alt_number_key'=>'2','breakout_context'=>'ivr-4','initiated_message_file'=>'custom/callback_initiated'];
    $existing="[admin-custom]\nexten => 123,1,Hangup()\n\n[queuecallback-3008]\nexten => 2,1,Hangup()\n\n[qcb-route-9]\ninclude => old-route\n";
    $plan=qcallback_render_extensions($existing,[$config],"\n[qcb-route-7]\ninclude => outrt-7\n");
    expect_install(str_contains($plan,'[admin-custom]') && !str_contains($plan,'[queuecallback-3008]') && !str_contains($plan,'[qcb-route-9]'),'Renderer lost unrelated context or retained stale queue/route');
    expect_install(str_contains($plan,'include => ivr-4') && str_contains($plan,'[qcb-handler-3007]'),'Renderer lost breakout IVR or compatibility context');
    expect_install(substr_count($plan,'[queuecallback-outbound]')===1 && substr_count($plan,'[queuecallback-3007]')===1,'Duplicate generated contexts');
    $again=qcallback_render_extensions($plan,[$config],"\n[qcb-route-7]\ninclude => outrt-7\n");
    expect_install($again===$plan,'Repeated dialplan rendering is not stable');
    $disabled=qcallback_render_extensions($plan,[],'');
    expect_install(!str_contains($disabled,'[queuecallback-3007]') && !str_contains($disabled,'[qcb-handler-3007]')
        && !str_contains($disabled,'[qcb-route-7]') && str_contains($disabled,'[admin-custom]'),'Disabling last queue leaves active custom contexts');
    echo "PASS: shared renderer preserves user contexts and IVR, avoids duplicates, and removes disabled queues/routes\n";

    require_once '/etc/freepbx.conf';
    $pdo=FreePBX::Database();$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
    // Rewrite CREATE only to connection-local temporary tables. All other
    // statements operate on those shadows, never on production tables.
    $db=new class($pdo){
        private $pdo;
        function __construct($pdo){$this->pdo=$pdo;}
        function exec($sql){return $this->pdo->exec(str_replace('CREATE TABLE IF NOT EXISTS','CREATE TEMPORARY TABLE IF NOT EXISTS',$sql));}
        function query($sql){return $this->pdo->query($sql);}
        function prepare($sql){return $this->pdo->prepare($sql);}
    };
    qcallback_install_schema($db);
    $pdo->exec("INSERT INTO queuecallback_requests (queue_id,callback_number,time_requested) VALUES ('3007','5551234567',123)");
    qcallback_seed_security_entries($db,'');qcallback_seed_security_entries($db,'');
    expect_install((int)$pdo->query('SELECT COUNT(*) FROM queuecallback_security')->fetchColumn()===32,'Repeated default seeding duplicated entries');
    qcallback_install_schema($db);
    expect_install((int)$pdo->query('SELECT time_requested FROM queuecallback_requests')->fetchColumn()===123,'Repeated installation destroyed existing requests');
    $pdo->exec('DROP TEMPORARY TABLE queuecallback_config');
    qcallback_install_schema($db);
    expect_install((int)$pdo->query('SELECT COUNT(*) FROM queuecallback_requests')->fetchColumn()===1,'Missing config table caused request loss');
    $pdo->exec('ALTER TABLE queuecallback_config DROP COLUMN announce_frequency, DROP COLUMN outbound_route_id, DROP COLUMN call_first, DROP COLUMN return_confirm_timeout, DROP COLUMN return_confirm_repeats');
    $pdo->exec('ALTER TABLE queuecallback_security DROP COLUMN queue_id');
    qcallback_install_schema($db);
    $columns=$pdo->query('SHOW COLUMNS FROM queuecallback_config')->fetchAll(PDO::FETCH_COLUMN);
    expect_install(in_array('announce_frequency',$columns,true)&&in_array('outbound_route_id',$columns,true)&&in_array('call_first',$columns,true),'Upgrade omitted legacy config columns');
    expect_install(in_array('return_confirm_timeout',$columns,true)&&in_array('return_confirm_repeats',$columns,true),'Upgrade omitted return confirmation settings');
    $pdo->exec("INSERT INTO queuecallback_config (queue_id,return_confirm_timeout,return_confirm_repeats) VALUES ('3007',23,0)");
    qcallback_install_schema($db);
    $saved=$pdo->query("SELECT return_confirm_timeout,return_confirm_repeats FROM queuecallback_config WHERE queue_id='3007'")->fetch(PDO::FETCH_ASSOC);
    expect_install((int)$saved['return_confirm_timeout']===23&&(int)$saved['return_confirm_repeats']===0,'Repeated upgrade changed saved confirmation settings');
    $columns=$pdo->query('SHOW COLUMNS FROM queuecallback_requests')->fetchAll(PDO::FETCH_COLUMN);
    expect_install(in_array('queue_joined_at',$columns,true)&&in_array('reserved_agent',$columns,true),'Shared install omitted dispatch migration');
    $failed=false;
    try {qcallback_install_schema(new class {function exec($sql){throw new RuntimeException('Simulated DDL failure');}});}
    catch(RuntimeException $e){$failed=true;}
    expect_install($failed,'Schema failure was swallowed');
    require_once dirname(__DIR__).'/Qcallback.class.php';
    $broken=new FreePBX\modules\Qcallback((object)['Database'=>new class {function prepare($sql){throw new PDOException('Simulated read failure');}}]);
    $method=new ReflectionMethod($broken,'getEnabledQueuesWithConfig');$method->setAccessible(true);
    $failed=false;try{$method->invoke($broken);}catch(PDOException $e){$failed=true;}
    expect_install($failed,'Configuration read failure treated as all queues disabled');
    echo "PASS: fresh/partial installs and repeat upgrades preserve requests, migrate legacy columns and seed defaults once\n";
} finally {remove_install_fixture($root);}
