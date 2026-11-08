<?php
/** Shared outbound contexts for installation and Apply Config. GPL-3.0-or-later. */

/** Per-queue request menu; keys and confirmation are separate from the IVR exit. */
function qcallback_request_dialplan(array $c): string {
    $qid = (string)$c['queue_id'];
    if (!preg_match('/^[0-9]+$/D', $qid)) { throw new InvalidArgumentException('Invalid queue number'); }
    $ckey = (string)($c['callback_key'] ?? '#');
    if (!preg_match('/^[0-9*#]$/D', $ckey)) { throw new InvalidArgumentException('Invalid callback key'); }
    $confirm = (int)($c['confirm_number'] ?? 1) === 1;
    $alt = (string)($c['alt_number_key'] ?? '2');
    if ($alt !== '' && !preg_match('/^[0-9*#]$/D', $alt)) { throw new InvalidArgumentException('Invalid alternate-number key'); }
    if ($confirm && $alt === '1') { throw new InvalidArgumentException('Different Number Key cannot be 1; 1 confirms the number'); }
    $ctx = "queuecallback-$qid";
    $handler = "\n[queuecallback-exit-$qid]\n";
    if (preg_match('/^ivr-[0-9]+$/D', $c['breakout_context'] ?? '')) {
        $handler .= "include => {$c['breakout_context']}\n";
    }
    $handler .= "exten => $ckey,1,Goto($ctx,$ckey,1)\n\n[$ctx]\n";
    $handler .= "exten => s,1,Set(QUEUENAME=$qid)\n";
    $handler .= " same => n,GotoIf(\$[\"\${CHANNEL(dtmf-digit)}\" = \"$ckey\"]?$ctx,$ckey,1)\n";
    $handler .= " same => n,Return()\n";
    $handler .= "exten => $ckey,1,Set(CALLBACK_NUMBER=\${CALLERID(num)})\n";
    $handler .= " same => n,Set(CALLBACK_QUEUE=$qid)\n";
    $handler .= " same => n,Set(QCB_ALTERNATE_NUMBER=0)\n";
    $handler .= " same => n,Set(QCB_CONFIRMED=0)\n same => n,Set(QCB_CONFIRM_SOURCE=)\n same => n,Set(CONFIRM2=)\n";
    $handler .= " same => n,Set(QUEUE_CALLBACK_ENABLED=0)\n";
    $handler .= " same => n,Set(QUEUE_CALLBACK_KEY=)\n";
    $handler .= " same => n,Set(QCB_CHECK_ERROR=AGI_ERROR)\n";
    $handler .= " same => n,AGI(queuecallback-check.agi)\n";
    $target = $confirm ? 'confirm' : 'store';
    $handler .= " same => n,GotoIf(\$[\"\${QUEUE_CALLBACK_ENABLED}\" = \"1\"]?$target,1:unavailable)\n";
    $handler .= " same => n(unavailable),Playback(im-sorry)\n same => n,Playback(goodbye)\n same => n,Hangup()\n";
    if ($confirm) {
        $message = $c['confirm_message_file'] ?: 'custom/confirm_number';
        $handler .= "exten => confirm,1,SayDigits(\${CALLBACK_NUMBER})\n";
        // Single-digit menus have no terminator so # is a usable alternate key.
        $handler .= " same => n,Read(CONFIRM1,$message,1,nt(),1,5)\n";
        $handler .= " same => n,GotoIf(\$[\"\${CONFIRM1}\" = \"\"]?cancel,1)\n";
        $handler .= " same => n,GotoIf(\$[\"\${CONFIRM1}\" = \"1\"]?authorize,1)\n";
        if ($alt !== '') {
            $handler .= " same => n,GotoIf(\$[\"\${CONFIRM1}\" = \"$alt\"]?alt,1:invalid)\n";
        } else {
            $handler .= " same => n,Goto(invalid)\n";
        }
        $handler .= " same => n(invalid),Playback(vm-invalid)\n same => n,Goto(confirm,1)\n";
        if ($alt !== '') {
            $message = $c['alt_message_file'] ?: 'custom/alternate_num_instruct';
            $handler .= "exten => alt,1,Read(ALTNUM,$message,30,,1,35)\n";
            $handler .= " same => n,GotoIf(\$[\"\${FILTER(0-9,\${ALTNUM})}\" != \"\${ALTNUM}\"]?alt,1)\n";
            $handler .= " same => n,Set(CALLBACK_NUMBER=\${ALTNUM})\n";
            $handler .= " same => n,GotoIf(\$[\"\${CALLBACK_NUMBER}\" = \"\"]?alt,1)\n";
            $handler .= " same => n,Set(QCB_ALTERNATE_NUMBER=1)\n";
            $handler .= " same => n,Goto(confirm,1)\n";
        }
    }
    // Accepting an entered alternate number also authorizes that request.
    // Detected-number requests still require a fresh digit after the final prompt.
    $prompt = ($c['confirm_prompt_file'] ?? '') ?: 'custom/callback_confirm';
    if ($confirm) {
        $handler .= "exten => authorize,1,Set(CONFIRM2=\${CONFIRM1})\n";
        $handler .= " same => n,GotoIf(\$[\"\${QCB_ALTERNATE_NUMBER}\" = \"1\"]?validate)\n";
        $handler .= " same => n,Set(CONFIRM2=)\n";
        $handler .= " same => n,Playback($prompt)\n";
        $handler .= " same => n,GotoIf(\$[\"\${PLAYBACKSTATUS}\" != \"SUCCESS\"]?cancel,1)\n";
        $handler .= " same => n,Set(CONFIRM2=)\n";
        $handler .= " same => n,Read(CONFIRM2,,1,nt(),1,5)\n";
        $handler .= " same => n(validate),GotoIf(\$[\"\${READSTATUS}\" != \"OK\"]?cancel,1)\n";
        $handler .= " same => n,GotoIf(\$[\"\${CONFIRM2}\" != \"1\"]?cancel,1)\n";
        $handler .= " same => n,Set(QCB_CONFIRMED=1)\n same => n,Set(QCB_CONFIRM_SOURCE=dtmf)\n same => n,Goto(store,1)\n";
    }
    $handler .= "exten => cancel,1,Set(QCB_CONFIRMED=0)\n same => n,Playback(goodbye)\n same => n,Hangup()\n";
    if (!$confirm) {
        $handler .= "exten => store,1,Set(QCB_CONFIRMED=1)\n same => n,Set(QCB_CONFIRM_SOURCE=detected)\n";
    } else {
        $handler .= "exten => store,1,GotoIf(\$[\"\${QCB_CONFIRMED}\" != \"1\"]?cancel,1)\n";
    }
    $handler .= " same => n,Set(QCB_STORE_SUCCESS=0)\n";
    $handler .= " same => n,Set(QCB_REQUEST_ID=)\n";
    $handler .= " same => n,Set(QCB_STORE_ERROR=AGI_ERROR)\n";
    $handler .= " same => n,AGI(queuecallback-store.agi)\n";
    $handler .= " same => n,GotoIf(\$[\"\${QCB_STORE_SUCCESS}\" != \"1\"]?store-failed,1)\n";
    $handler .= " same => n,Playback({$c['initiated_message_file']})\n";
    $handler .= " same => n,Playback(thank-you-for-calling)\n same => n,Playback(goodbye)\n same => n,Hangup()\n";
    $handler .= "exten => store-failed,1,Playback(im-sorry)\n";
    $handler .= " same => n,Playback(an-error-has-occurred)\n";
    $handler .= " same => n,Playback(please-try-call-later)\n same => n,Hangup()\n";
    return $handler;
}

function qcallback_outbound_dialplan(): string {
    return <<<'DIALPLAN'

[queuecallback-outbound]
exten => s,1,NoOp(QCB customer answered callback ${CALLBACK_ID} attempt ${CALLBACK_ATTEMPT})
 same => n,Set(QCB_MODE=customer)
 same => n,Set(QCB_CUSTOMER_CONFIRMED=0)
 same => n,Set(QCB_PHASE=announcement)
 same => n,Set(ANSWEREDTIME_MS=)
 same => n,Set(QUEUEWAIT_MS=)
 same => n,Set(QUEUESTATUS=)
 same => n,Set(ABANDONED=)
 same => n,Set(CHANNEL(hangup_handler_push)=qcb-complete,s,1(${CALLBACK_ID},${CALLBACK_ATTEMPT},customer))
 same => n,Wait(1)
 same => n,Gosub(qcb-return-confirm,s,1)
 same => n,GotoIf($["${QCB_CUSTOMER_CONFIRMED}" = "1"]?connect_queue)
 same => n,Hangup()
 same => n(connect_queue),Set(__CALLBACK_RETURN=1)
 same => n,Set(QUEUE_PRIO=${CALLBACK_QUEUE_PRIORITY})
 same => n,Set(VQ_POSITION=1)
 same => n,Set(QCB_PHASE=queue)
 same => n,Goto(ext-queues,${CALLBACK_QUEUE_ID},1)

[queuecallback-agent-outbound]
exten => s,1,NoOp(QCB agent answered callback ${CALLBACK_ID} attempt ${CALLBACK_ATTEMPT})
 same => n,Set(QCB_MODE=agent)
 same => n,Set(QCB_CUSTOMER_CONFIRMED=0)
 same => n,Set(SHARED(QCB_CUSTOMER_CONFIRMED)=0)
 same => n,Set(CHANNEL(hangup_handler_push)=qcb-complete,s,1(${CALLBACK_ID},${CALLBACK_ATTEMPT},agent))
 same => n,Set(DIALSTATUS=)
 same => n,Set(ANSWEREDTIME_MS=)
 same => n,Set(CALLERID(name)=Queue Callback)
 same => n,Wait(1)
 same => n,Playback(you-will-be-connected-to-a-customer)
 ; Agent-only, non-inherited sound consumed when the confirmed call bridges.
 same => n,Set(BRIDGE_PLAY_SOUND=beep)
 same => n,Dial(${CALLBACK_CUSTOMER_CHANNEL},30,TtgrU(qcb-customer-confirm^${CHANNEL}))
 same => n,Set(BRIDGE_PLAY_SOUND=)
 same => n,GotoIf($["${SHARED(QCB_CUSTOMER_CONFIRMED)}" = "1"]?finished)
 same => n,Playback(custom/failed-to-confirm)
 same => n(finished),Hangup()

[qcb-customer-confirm]
exten => s,1,NoOp(QCB confirm customer before bridging callback ${CALLBACK_ID})
 same => n,Gosub(qcb-return-confirm,s,1)
 same => n,GotoIf($["${QCB_CUSTOMER_CONFIRMED}" = "1"]?confirmed)
 same => n,Set(GOSUB_RESULT=CONTINUE)
 same => n,Return()
 same => n(confirmed),Set(SHARED(QCB_CUSTOMER_CONFIRMED,${ARG1})=1)
 same => n,Return()

[qcb-return-confirm]
exten => s,1,Set(QCB_CUSTOMER_CONFIRMED=0)
 same => n,Set(QCB_RETURN_TRIES=0)
 same => n,Set(QCB_RETURN_TIMEOUT=${FILTER(0-9,${CALLBACK_CONFIRM_TIMEOUT})})
 same => n,ExecIf($[0${QCB_RETURN_TIMEOUT} < 1 | 0${QCB_RETURN_TIMEOUT} > 60]?Set(QCB_RETURN_TIMEOUT=10))
 same => n,Set(QCB_RETURN_PLAYS=${FILTER(0-9,${CALLBACK_CONFIRM_PLAYS})})
 same => n,ExecIf($[0${QCB_RETURN_PLAYS} < 1 | 0${QCB_RETURN_PLAYS} > 6]?Set(QCB_RETURN_PLAYS=2))
 same => n,Set(QCB_RETURN_PROMPT=${CALLBACK_RETURN_MSG})
 same => n,ExecIf($["${QCB_RETURN_PROMPT}" = ""]?Set(QCB_RETURN_PROMPT=custom/callback-return-confirm))
 same => n(prompt),Set(QCB_RETURN_TRIES=$[${QCB_RETURN_TRIES} + 1])
 same => n,Set(QCB_RETURN_DIGIT=)
 same => n,Read(QCB_RETURN_DIGIT,${QCB_RETURN_PROMPT},1,nt(),1,${QCB_RETURN_TIMEOUT})
 same => n,GotoIf($["${READSTATUS}" = "OK" & "${QCB_RETURN_DIGIT}" = "1"]?confirmed)
 same => n,GotoIf($["${READSTATUS}" = "OK"]?invalid)
 same => n,GotoIf($["${READSTATUS}" = "TIMEOUT"]?retry:failed)
 same => n(invalid),Playback(option-is-invalid)
 same => n(retry),GotoIf($[${QCB_RETURN_TRIES} < ${QCB_RETURN_PLAYS}]?prompt)
 same => n(failed),Playback(custom/failed-to-confirm)
 same => n,Return()
 same => n(confirmed),Set(QCB_CUSTOMER_CONFIRMED=1)
 same => n,Return()

[qcb-complete]
exten => s,1,NoOp(QCB result for callback ${ARG1} attempt ${ARG2})
 same => n,ExecIf($["${ARG3}" = "agent"]?Set(QCB_CUSTOMER_CONFIRMED=${SHARED(QCB_CUSTOMER_CONFIRMED)}))
 same => n,AGI(queuecallback-complete.agi,${ARG1},${ARG2},${ARG3})
 same => n,Return()

DIALPLAN;
}

/** Legacy context names forward to the same configured request menu. */
function qcallback_legacy_handler_dialplan(array $configs): string {
    $plan = "\n[queuecallback-handler-fixed]\nexten => s,1,Goto(queuecallback-\${QUEUENAME},\${ARG1},1)\n"
        . "\n[queuecallback-handler]\nexten => s,1,Goto(queuecallback-\${QUEUENAME},\${ARG1},1)\n";
    foreach ($configs as $config) {
        $queue = (string)$config['queue_id']; $key = (string)$config['callback_key'];
        if (!preg_match('/^[0-9]+$/D', $queue) || !preg_match('/^[0-9*#]$/D', $key)) {
            throw new InvalidArgumentException('Invalid legacy callback target');
        }
        $plan .= "\n[qcb-handler-$queue]\nexten => s,1,Goto(queuecallback-$queue,s,1)\n"
            . "exten => $key,1,Goto(queuecallback-$queue,$key,1)\n";
    }
    return $plan;
}

/** Shared install/reload renderer; remove disabled queues as well as active ones. */
function qcallback_render_extensions(string $existing, array $configs, string $routes): string {
    // Only module-owned marker blocks and named contexts are replaced. Never
    // replace an administrator's entire from-internal-custom/ext-queues section.
    $existing = preg_replace('/^; (?:Queue Callback Module - Start|BEGIN QCALLBACK QUEUE ROUTES)[^\r\n]*\R[\s\S]*?^; (?:Queue Callback Module - End|END QCALLBACK QUEUE ROUTES)[^\r\n]*(?:\R|$)/m', '', $existing);
    $owned = 'queuecallback-(?:[0-9]+|exit-[0-9]+|outbound|agent-outbound|handler(?:-fixed)?)'
        . '|qcb-(?:handler-[0-9]+|hangup|complete|customer-confirm|return-confirm|route-[0-9]+)';
    $existing = preg_replace('/^[ \t]*\[(?:' . $owned . ')\][^\r\n]*(?:\R|$)[\s\S]*?(?=^[ \t]*\[|\z)/m', '', $existing);
    $plan = rtrim($existing) . "\n";
    foreach ($configs as $config) { $plan .= qcallback_request_dialplan($config); }
    return $plan . $routes . qcallback_outbound_dialplan() . qcallback_legacy_handler_dialplan($configs);
}
