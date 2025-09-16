<?php
// SPDX-License-Identifier: GPL-3.0-or-later
// FreePBX dialplan builder for Queue Callback (Asterisk 22 / FreePBX 16+)

if (!defined('FREEPBX_IS_AUTH')) {
    die('No direct script access allowed');
}

function qcallback_get_config($engine) {
    if ($engine !== 'asterisk') { return; }
    global $ext;

    // -------- Context: qcb-outbound --------
    $ctx = 'qcb-outbound';
    $s   = 's';

    // 1) Debug + language
    $ext->add($ctx, $s, '', new ext_noop('QCB outbound: queue ${__CALLBACK_QUEUE_ID} num ${CALLERID(num)}'));
    $ext->add($ctx, $s, '', new ext_set('CHANNEL(language)','en'));

    // 2) Choose the queue id (prefer inherited __CALLBACK_QUEUE_ID)
    $ext->add($ctx, $s, '', new ext_set('QTEMP','${IF($["${__CALLBACK_QUEUE_ID}" != ""]?${__CALLBACK_QUEUE_ID}:${CALLBACK_QUEUE_ID})}'));
    $ext->add($ctx, $s, '', new ext_noop('QCB debug: __CALLBACK_QUEUE_ID=${__CALLBACK_QUEUE_ID} CALLBACK_QUEUE_ID=${CALLBACK_QUEUE_ID} QTEMP=${QTEMP}'));
    $ext->add($ctx, $s, '', new ext_gotoif('$["${QTEMP}" = ""]','hang'));

    // 3) Set all the usual FreePBX queue markers so from-queue won’t dump to hangup
    $ext->add($ctx, $s, '', new ext_set('__CALLBACK_QUEUE_ID','${QTEMP}'));
    $ext->add($ctx, $s, '', new ext_set('CALLBACK_QUEUE_ID','${QTEMP}'));
    $ext->add($ctx, $s, '', new ext_set('MASTER_CHANNEL(NODEST)','${QTEMP}'));
    $ext->add($ctx, $s, '', new ext_set('__NODEST','${QTEMP}'));
    $ext->add($ctx, $s, '', new ext_set('NODEST','${QTEMP}'));
    $ext->add($ctx, $s, '', new ext_set('MASTER_CHANNEL(QUEUENUM)','${QTEMP}'));
    $ext->add($ctx, $s, '', new ext_set('__QUEUENUM','${QTEMP}'));
    $ext->add($ctx, $s, '', new ext_set('QUEUENUM','${QTEMP}'));
    $ext->add($ctx, $s, '', new ext_set('__FROMQ','true'));

    // 4) Optional return audio (Playback) if set by the processor
    // ext_execif(cond, app, args)
    $ext->add($ctx, $s, '', new ext_execif('$["${__CALLBACK_RETURN_MSG}" != ""]','TryExec','Playback(${__CALLBACK_RETURN_MSG})'));

    // 5) Queue the call (use proper ext_queue signature, 5 args min on FPBX 16+)
    // ext_queue($queue, $options='', $url='', $announce='', $timeout='')
    $ext->add($ctx, $s, '', new ext_queue('${QTEMP}','t','','',''));

    // 6) Post-queue result, mark complete only on successful bridge
    $ext->add($ctx, $s, '', new ext_noop('QCB Queue result: ${QUEUESTATUS}'));
    $ext->add($ctx, $s, '', new ext_gotoif('$["${QUEUESTATUS}" = "COMPLETECALLER" | "${QUEUESTATUS}" = "COMPLETEAGENT"]','markdone','hang'));

    $ext->add($ctx, 'markdone', '', new ext_agi('queuecallback-complete.agi'));
    $ext->add($ctx, 'markdone', '', new ext_hangup(''));
    $ext->add($ctx, 'hang',     '', new ext_hangup(''));

    // -------- Context: qcb-agent-outbound (optional agent-first) --------
    $ctx2 = 'qcb-agent-outbound';
    $ext->add($ctx2, 's', '', new ext_noop('QCB agent-first: dialing customer ${__CALLBACK_CUSTOMER_NUM}'));
    $ext->add($ctx2, 's', '', new ext_set('CHANNEL(language)','en'));
    $ext->add($ctx2, 's', '', new ext_set('__FROMQ','true'));
    $ext->add($ctx2, 's', '', new ext_dial('Local/${__CALLBACK_CUSTOMER_NUM}@from-internal/n','30','tT,tr'));
    $ext->add($ctx2, 's', '', new ext_hangup(''));

    // -------- Pass-through in from-queue for the enabled queues --------
    // We add ONE exact-match exten per enabled QCB queue, forwarding to from-internal,${QAGENT},1
    // Avoids the pattern '_.’ hangup path.
    try {
        require_once '/etc/freepbx.conf';
        $pdo = FreePBX::Database();
        $rows = $pdo->query('SELECT queue_id FROM queuecallback_config WHERE enabled=1');
        foreach ($rows as $r) {
            $qid = preg_replace('/[^0-9A-Za-z_\-]/','',(string)$r['queue_id']);
            if ($qid === '') { continue; }
            $ext->add('from-queue', $qid, '', new ext_noop("QCB pass-through for queue $qid"));
            $ext->add('from-queue', $qid, '', new ext_goto('from-internal,${QAGENT},1')); // single, correct Goto
        }
    } catch (Throwable $e) {
        // If DB is not available during dialplan build, skip pass-through safely.
    }
}

/**
 * Global function wrapper for getting pending callback requests
 * This function is called from view files and needs to be globally available
 * @param string $queue_id The queue ID to get callbacks for
 * @return array Array of callback records
 */
function queuecallback_get_pending_requests($queue_id = '') {
    try {
        // Use the FreePBX module system to get the callback requests
        return FreePBX::Qcallback()->getPendingCallbackRequests($queue_id);
    } catch (Exception $e) {
        error_log("queuecallback_get_pending_requests error: " . $e->getMessage());
        return [];
    }
}

/**
 * Global function wrapper for getting all callback requests
 * @param string $queue_id The queue ID to get callbacks for
 * @param int $limit Maximum number of records to return
 * @return array Array of callback records
 */
function queuecallback_get_all_requests($queue_id, $limit = 100) {
    try {
        return FreePBX::Qcallback()->getAllCallbackRequests($queue_id, $limit);
    } catch (Exception $e) {
        error_log("queuecallback_get_all_requests error: " . $e->getMessage());
        return [];
    }
}


