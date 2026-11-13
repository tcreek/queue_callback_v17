<?php
/** Protect callback positions at Queue's outgoing-member boundary. GPL-3.0-or-later. */
require_once __DIR__ . '/callback_queue.php';
require_once __DIR__ . '/callback_security.php';

/** Preserve administrator pre-dial logic while putting FIFO enforcement first. */
function qcallback_fifo_options(string $options): array {
    if ($options === '') { $options = 't'; }
    $result = ''; $previous = '';
    for ($i = 0, $length = strlen($options); $i < $length;) {
        $start = $i; $option = $options[$i++];
        if ($option === 'n' && ($options[$i] ?? '') !== '(') {continue;}
        if (($options[$i] ?? '') === '(') {
            $depth = 1; $i++; $argument = $i;
            while ($i < $length && $depth) {
                if ($options[$i] === '(') { $depth++; }
                elseif ($options[$i] === ')') { $depth--; }
                $i++;
            }
            if ($depth) { throw new InvalidArgumentException('Unbalanced queue options'); }
            if ($option === 'b') {
                $previous = str_replace('^', ',', substr($options, $argument, $i - $argument - 1));
                continue;
            }
        }
        $result .= substr($options, $start, $i - $start);
    }
    return [$result . 'b(qcb-fifo-member^s^1)', $previous];
}

/** Retry eligibility never changes ownership of a place in line. */
function qcallback_fifo_allows($db, string $queue, int $joined, int $priority, int $self = 0, string $uniqueid = ''): bool {
    $stmt = $db->prepare("SELECT r.*, c.max_attempts AS configured_max
        FROM queuecallback_requests r JOIN queuecallback_config c ON c.queue_id = r.queue_id
        WHERE r.queue_id = ? AND c.enabled = 1 AND r.status IN ('pending','processing')
        AND (r.queue_priority > ? OR (r.queue_priority = ? AND COALESCE(r.queue_joined_at,r.time_requested) <= ?))
        ORDER BY r.queue_priority DESC, COALESCE(r.queue_joined_at,r.time_requested),r.time_requested,r.id");
    if (!$stmt->execute([$queue,$priority,$priority,$joined])) { throw new RuntimeException('Cannot read FIFO order'); }
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if ((int)$row['id'] === $self) { continue; }
        if (!qcallback_callback_before_live($row,['Priority'=>$priority,'joined_at'=>$joined,'Uniqueid'=>$uniqueid])) {continue;}
        if ($row['status'] === 'processing' && $row['fifo_connected_at'] !== null) { continue; }
        if ($row['status'] === 'pending' && (int)$row['attempts'] >= (int)$row['configured_max']) { continue; }
        if (qcallback_security_blocks_number($db,$row['callback_number'],$queue)) { continue; }
        return false;
    }
    return true;
}

/** All modes use injectable AGI transport and the shared FreePBX connection. */
function qcallback_fifo_agi(array $env, callable $exchange, callable $database, ?callable $snapshot = null): void {
    $set = function($name,$value) use($exchange) {
        if (!preg_match('/^200 result=1\s*$/D',$exchange('SET VARIABLE '.$name.' "'.addcslashes((string)$value,"\\\"").'"'))) {
            throw new RuntimeException('FIFO AGI transport failed');
        }
    };
    $get = function($name) use($exchange) {
        $reply=$exchange('GET VARIABLE '.$name);
        if (preg_match('/^200 result=1 \((.*)\)$/D',$reply,$m)) {return $m[1];}
        if ($reply==='200 result=0') {return '';}
        throw new RuntimeException('FIFO AGI transport failed');
    };
    $mode=$env['agi_arg_1'] ?? 'connected';
    try {
        if ($mode==='entry') {
            $set('QCB_FIFO_INITIALIZED','0');
            $queue=(string)($env['agi_arg_2']??'');
            if (!preg_match('/^[0-9]{1,50}$/D',$queue)) {throw new InvalidArgumentException('Invalid FIFO queue');}
            $db=$database();
            $joined=time();$priority=qcallback_queue_int($get('QUEUE_PRIO')?:'0',-2147483648);$self=0;
            if ($get('CALLBACK_RETURN')==='1') {
                $self=qcallback_queue_int($get('CALLBACK_ID'),1);
                $attempt=qcallback_queue_int($get('CALLBACK_ATTEMPT'),1);
                $stmt=$db->prepare("SELECT * FROM queuecallback_requests WHERE id=? AND queue_id=? AND status='processing' AND attempts=?");
                $stmt->execute([$self,$queue,$attempt]);$row=$stmt->fetch(PDO::FETCH_ASSOC);
                if (!$row) {throw new RuntimeException('Stale returning callback');}
                $joined=(int)($row['queue_joined_at']??$row['time_requested']);$priority=(int)$row['queue_priority'];
                // Place a returning customer behind older live callers, not at an unconditional position 1.
                $state=$snapshot ? $snapshot($queue) : qcallback_read_queue_state($queue);
                if (!$state || !isset($state['queues'][$queue])) {throw new RuntimeException('Queue state unavailable');}
                $position=1;
                foreach ($state['queues'][$queue]['entries'] as $entry) {
                    if (!qcallback_callback_before_live($row,$entry)) {$position++;}
                }
                $set('VQ_POSITION',$position);
                $set('QUEUE_PRIO',$priority);
                $previous=$get('VQ_AGI')?:$get('QAGI');
                $set('__QCB_FIFO_PREVIOUS_AGI',$previous==='queuecallback-fifo.agi'?'':$previous);
                $set('VQ_AGI','queuecallback-fifo.agi');
            }
            [$options,$previous]=qcallback_fifo_options($get('VQ_OPTIONS'));
            $set('VQ_OPTIONS',$options);
            // A refused offer must retry while the live caller stays on hold.
            $set('VQ_RETRY',' ');
            $set('__QCB_FIFO_PREVIOUS_GOSUB',$previous);
            $set('__QCB_FIFO_QUEUE',$queue);
            $set('__QCB_FIFO_JOINED_AT',$joined);
            $set('__QCB_FIFO_PRIORITY',$priority);
            $set('__QCB_FIFO_SELF',$self);
            $set('__QCB_FIFO_OWNER',(string)($env['agi_uniqueid']??''));
            $set('QCB_FIFO_INITIALIZED','1');
        } elseif ($mode==='check') {
            $set('QCB_FIFO_ALLOW','0');
            $queue=$get('QCB_FIFO_QUEUE');
            if (!preg_match('/^[0-9]{1,50}$/D',$queue)) {throw new InvalidArgumentException('Missing FIFO queue');}
            $joined=qcallback_queue_int($get('QCB_FIFO_JOINED_AT'),1);
            $priority=qcallback_queue_int($get('QCB_FIFO_PRIORITY')?:'0',-2147483648);
            $self=qcallback_queue_int($get('QCB_FIFO_SELF')?:'0');
            if (qcallback_fifo_allows($database(),$queue,$joined,$priority,$self,$get('QCB_FIFO_OWNER'))) {$set('QCB_FIFO_ALLOW','1');}
        } elseif ($mode==='connected') {
            $id=qcallback_queue_int($get('CALLBACK_ID'),1);
            $attempt=qcallback_queue_int($get('CALLBACK_ATTEMPT'),1);
            $db=$database();
            $stmt=$db->prepare("UPDATE queuecallback_requests SET fifo_connected_at=COALESCE(fifo_connected_at,?) WHERE id=? AND attempts=? AND status='processing'");
            if (!$stmt->execute([time(),$id,$attempt])) {throw new RuntimeException('Cannot release connected FIFO position');}
            $previous=$get('QCB_FIFO_PREVIOUS_AGI');
            if ($previous!=='') {
                if (preg_match('/[\x00-\x1f\x7f]/',$previous)) {throw new InvalidArgumentException('Invalid previous queue AGI');}
                $exchange('EXEC AGI "'.addcslashes($previous,"\\\"").'"');
            }
        } else {throw new InvalidArgumentException('Invalid FIFO mode');}
    } catch (Throwable $e) {
        error_log('Queue Callback: FIFO '.$mode.' failed type='.get_class($e));
        // Defaults deny member dialing/queue entry; connected failures retain the position until completion.
    }
}
