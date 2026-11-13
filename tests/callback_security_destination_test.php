<?php
/** Blocked calls must stop silently before announcements and never use a destination. */
require_once dirname(__DIR__).'/callback_dialplan.php';
$c=['queue_id'=>'3007','confirm_number'=>1,'confirm_message_file'=>'custom/confirm',
    'alt_message_file'=>'custom/alt','confirm_prompt_file'=>'custom/prompt','initiated_message_file'=>'custom/success'];
$plan=qcallback_request_dialplan($c);
foreach (['queuecallback-check.agi','queuecallback-store.agi'] as $script) {
    $expected='AGI('.$script.')'."\n".' same => n,GotoIf($["${QCB_BLOCKED}" = "1"]?blocked,1)';
    if (!str_contains($plan,$expected)) {throw new RuntimeException('Blocked call can reach prompts after '.$script);}
}
if (!str_contains($plan,'exten => blocked,1,Hangup()')) {throw new RuntimeException('Silent hangup missing');}
if (str_contains($plan,'QCB_SECURITY_DESTINATION')) {throw new RuntimeException('Destination routing remains');}
echo "PASS: blocked detected/alternate numbers silently hang up before subsequent announcements\n";
