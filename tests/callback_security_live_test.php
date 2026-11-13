<?php
/** Audit every enabled live rule through the installed AGI; temporary requests only, no calls. */
require_once dirname(__DIR__) . '/callback_requests.php';
require_once dirname(__DIR__) . '/callback_security.php';
function security_live_expect($ok, $message) { if (!$ok) { throw new RuntimeException($message); } }
$db = qcallback_agi_database();

$entries = $db->query('SELECT id, pattern, queue_id FROM queuecallback_security WHERE enabled=1 ORDER BY sort_order,id')->fetchAll(PDO::FETCH_ASSOC);
$queues = $db->query('SELECT queue_id FROM queuecallback_config WHERE enabled=1')->fetchAll(PDO::FETCH_COLUMN);
security_live_expect(count($entries)>0, 'No enabled blocklist entries');
security_live_expect(count($queues)>0, 'No enabled callback queues');
$fixture = tempnam(sys_get_temp_dir(), 'qcb-security-live-');
file_put_contents($fixture, <<<'FIXTURE'
<?php
require_once '/var/www/html/admin/modules/qcallback/callback_requests.php';
$db = qcallback_agi_database();
$db->exec('CREATE TEMPORARY TABLE qcb_security_request_shape LIKE queuecallback_requests');
$db->exec('CREATE TEMPORARY TABLE queuecallback_requests LIKE qcb_security_request_shape');
$db->exec('DROP TEMPORARY TABLE qcb_security_request_shape');
register_shutdown_function(function() use ($db) {
    if ((int)$db->query("SELECT COUNT(*) FROM queuecallback_requests WHERE status='blocked' AND attempts=0 AND time_processed IS NOT NULL")->fetchColumn() !== 1
        || (int)$db->query('SELECT COUNT(*) FROM queuecallback_requests')->fetchColumn() !== 1) {
        error_log('Blocked AGI request audit record is missing or nonterminal'); exit(1);
    }
});
FIXTURE
);
chmod($fixture,0600);
$checks=0;
try {
    foreach ($entries as $entry) {
        $pattern=qcallback_compile_security_pattern($entry['pattern']);
        $body=ltrim($pattern['dialplan'],'_');
        $sample=preg_replace_callback('/\[[^]]+\]|[XZN.!]/i', function($m) {
            if ($m[0][0]==='[') {return $m[0][1];}
            return ['X'=>'0','Z'=>'1','N'=>'2','.'=>'0','!'=>''][strtoupper($m[0])];
        }, $body);
        security_live_expect(preg_match($pattern['regex'],$sample)===1, 'Cannot synthesize rule '.$entry['id']);
        $effectiveQueues=$entry['queue_id']==='' ? $queues : [$entry['queue_id']];
        foreach ($effectiveQueues as $queue) {
            foreach ([$sample,'+'.$sample] as $number) {
                security_live_expect(qcallback_security_blocks_number($db,$number,$queue), 'Dispatch does not block rule '.$entry['id']);
                $vars=['CALLBACK_QUEUE'=>(string)$queue,'CALLBACK_NUMBER'=>$number,'QCB_CONFIRMED'=>'1',
                    'QCB_CONFIRM_SOURCE'=>'dtmf','CONFIRM2'=>'1','READSTATUS'=>'OK'];
                $pipes=[];
                $proc=proc_open([PHP_BINARY,'-d','auto_prepend_file='.$fixture,'/var/lib/asterisk/agi-bin/queuecallback-store.agi'],
                    [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
                security_live_expect(is_resource($proc),'Cannot run installed AGI');
                fwrite($pipes[0],"agi_callerid: 705\nagi_uniqueid: security-audit\n\n");fflush($pipes[0]);
                stream_set_timeout($pipes[1],10);
                $commands=0;
                while (($line=fgets($pipes[1]))!==false) {
                    $command=rtrim($line,"\r\n");
                    if ($command==='#!/usr/bin/php') {continue;}
                    security_live_expect(++$commands<50,'AGI protocol did not finish');
                    if (preg_match('/^SET VARIABLE ([A-Z0-9_]+) "(.*)"$/D',$command,$m)) {
                        $vars[$m[1]]=stripcslashes($m[2]);$reply='200 result=1';
                    } elseif (preg_match('/^GET VARIABLE ([A-Z0-9_]+)$/D',$command,$m)) {
                        $reply=isset($vars[$m[1]])?'200 result=1 ('.$vars[$m[1]].')':'200 result=0';
                    } else {throw new RuntimeException('Unexpected output from installed AGI');}
                    fwrite($pipes[0],$reply."\n");fflush($pipes[0]);
                }
                $timedOut=stream_get_meta_data($pipes[1])['timed_out'];
                fclose($pipes[0]);fclose($pipes[1]);$stderr=stream_get_contents($pipes[2]);fclose($pipes[2]);
                $status=proc_close($proc);
                security_live_expect(!$timedOut && $status===0 && $stderr==='', 'Installed AGI failed for rule '.$entry['id']);
                security_live_expect(($vars['QCB_STORE_ERROR']??'')==='BLOCKED'
                    && ($vars['QCB_STORE_SUCCESS']??'')==='0'
                    && ctype_digit($vars['QCB_REQUEST_ID']??'')
                    && ($vars['QCB_BLOCKED']??'')==='1', 'Installed AGI did not reject and route rule '.$entry['id']);
                $checks++;
            }
        }
    }
    echo 'PASS: ',count($entries),' enabled live rules; ',$checks,' installed-AGI checks across effective queues and both number formats',PHP_EOL;
    echo 'PASS: all matched requests logged as blocked with zero attempts; silent block flag set',PHP_EOL;
    echo 'Enabled callback queues: ',implode(', ',$queues),PHP_EOL;
} finally {unlink($fixture);}
