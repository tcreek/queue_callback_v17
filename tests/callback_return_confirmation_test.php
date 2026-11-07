<?php
/** Execute the generated confirmation menu with scripted input; no calls. */
require_once dirname(__DIR__).'/callback_dialplan.php';
require_once dirname(__DIR__).'/callback_settings.php';
function confirm_expect($ok,$message){if(!$ok)throw new RuntimeException($message);}
function confirmation_flow(array $inputs,array $initial=[]): array {
    $plan=qcallback_outbound_dialplan();
    preg_match('/\[qcb-return-confirm\]\n(.*?)(?=\n\[)/s',$plan,$match);
    $commands=[];$labels=[];
    foreach(explode("\n",$match[1]) as $line){
        if(!preg_match('/^(?:exten => s,| same => )([^,]+),(.*)$/',$line,$m))continue;
        if(preg_match('/n\(([^)]+)\)/',$m[1],$label))$labels[$label[1]]=count($commands);
        $commands[]=$m[2];
    }
    $vars=$initial;$played=[];$reads=[];$index=0;
    $condition=function($expression) {
        $expression=preg_replace('/^\$\[|\]$/','',$expression);
        foreach(explode('|',$expression) as $or){
            $truth=true;
            foreach(explode('&',$or) as $part){
                confirm_expect(preg_match('/^\s*("[^"]*"|[0-9]+)\s*(=|<|>)\s*("[^"]*"|[0-9]+)\s*$/',$part,$m),'Unhandled expression '.$expression);
                $a=$m[1][0]==='"'?trim($m[1],'"'):(int)$m[1];$b=$m[3][0]==='"'?trim($m[3],'"'):(int)$m[3];
                $truth=$truth&&match($m[2]){'='=>$a===$b,'<'=>$a<$b,'>'=>$a>$b};
            }
            if($truth)return true;
        }
        return false;
    };
    $expand=function($text)use(&$vars){
        for($i=0;$i<8&&str_contains($text,'${');$i++)$text=preg_replace_callback('/\$\{([^{}]+)\}/',function($m)use(&$vars){
            if(preg_match('/^FILTER\(0-9,(.*)\)$/',$m[1],$f))return preg_replace('/[^0-9]/','',$f[1]);
            return (string)($vars[$m[1]]??'');
        },$text);
        return preg_replace_callback('/\$\[([0-9]+) \+ ([0-9]+)\]/',fn($m)=>(string)((int)$m[1]+(int)$m[2]),$text);
    };
    $execute=null;
    $execute=function($command)use(&$execute,&$vars,&$played,&$reads,&$inputs,&$index,$labels,$condition,$expand){
        confirm_expect(preg_match('/^(\w+)\((.*)\)$/',$command,$m),'Invalid application '.$command);
        $app=$m[1];$args=$expand($m[2]);
        if($app==='Set'){[$key,$value]=explode('=',$args,2);$vars[$key]=$value;}
        elseif($app==='ExecIf'){[$test,$action]=explode('?',$args,2);if($condition($test))$execute($action);}
        elseif($app==='GotoIf'){
            [$test,$branches]=explode('?',$args,2);$branches=explode(':',$branches,2);$target=$branches[$condition($test)?0:1]??'';
            if($target!==''){confirm_expect(isset($labels[$target]),'Unknown label '.$target);$index=$labels[$target];}
        }elseif($app==='Read'){
            $fields=explode(',',$args);confirm_expect($fields[2]==='1'&&$fields[3]==='nt()','Read must accept # as a literal single key');
            $reads[]=['prompt'=>$fields[1],'timeout'=>(int)$fields[5]];
            $input=array_shift($inputs)??['TIMEOUT',''];
            $vars['READSTATUS']=$input[0];$vars[$fields[0]]=$input[1];
            if($input[0]==='HANGUP')return false;
        }elseif($app==='Playback'){$played[]=$args;}
        elseif($app==='Return'){return false;}
        else throw new RuntimeException('Unhandled application '.$app);
        return true;
    };
    for($steps=0;$steps<250;$steps++){
        confirm_expect(isset($commands[$index]),'Confirmation fell out of menu');
        if(!$execute($commands[$index++]))break;
    }
    confirm_expect($steps<250,'Confirmation loop was unbounded');
    return ['vars'=>$vars,'reads'=>$reads,'played'=>$played];
}
$r=confirmation_flow([['OK','1']]);
confirm_expect($r['vars']['QCB_CUSTOMER_CONFIRMED']==='1'&&count($r['reads'])===1&&!$r['played'],'Immediate confirmation failed');
$r=confirmation_flow([]);
confirm_expect($r['vars']['QCB_CUSTOMER_CONFIRMED']==='0'&&count($r['reads'])===2&&$r['reads'][0]['timeout']===10
    &&$r['played']===['custom/failed-to-confirm'],'Defaults must permit exactly one repeat after timeout');
foreach(['0','2','9','*','#'] as $key){
    $r=confirmation_flow([['OK',$key],['OK','1']]);
    confirm_expect($r['vars']['QCB_CUSTOMER_CONFIRMED']==='1'&&count($r['reads'])===2
        &&$r['played']===['option-is-invalid'],'Wrong key must announce invalid option and accept later 1');
}
$r=confirmation_flow([['OK','2'],['OK','#']]);
confirm_expect($r['vars']['QCB_CUSTOMER_CONFIRMED']==='0'&&$r['played']===['option-is-invalid','option-is-invalid','custom/failed-to-confirm'],'Repeated invalid choices must fail after bounded plays');
$r=confirmation_flow([['TIMEOUT',''],['OK','1']],['CALLBACK_CONFIRM_TIMEOUT'=>'23','CALLBACK_CONFIRM_PLAYS'=>'3','CALLBACK_RETURN_MSG'=>'custom/tenant']);
confirm_expect($r['vars']['QCB_CUSTOMER_CONFIRMED']==='1'&&$r['reads'][1]===['prompt'=>'custom/tenant','timeout'=>23],'Configured timeout or custom recording ignored');
$r=confirmation_flow([],['CALLBACK_CONFIRM_TIMEOUT'=>'1','CALLBACK_CONFIRM_PLAYS'=>'1']);
confirm_expect(count($r['reads'])===1&&$r['reads'][0]['timeout']===1,'Zero repeats must play once');
$r=confirmation_flow([],['CALLBACK_CONFIRM_TIMEOUT'=>'60','CALLBACK_CONFIRM_PLAYS'=>'6']);
confirm_expect(count($r['reads'])===6,'Maximum repeat budget incorrect');
foreach(['HANGUP','ERROR'] as $status){$r=confirmation_flow([[$status,'']]);confirm_expect(count($r['reads'])===1&&$r['vars']['QCB_CUSTOMER_CONFIRMED']==='0','Hangup/read failure must not repeat or confirm');}
foreach([['return_confirm_timeout'=>0],['return_confirm_timeout'=>61],['return_confirm_timeout'=>'1.5'],['return_confirm_repeats'=>-1],['return_confirm_repeats'=>6],['return_confirm_repeats'=>'1x']] as $bad){
    $caught=false;try{qcallback_return_confirmation_settings($bad);}catch(InvalidArgumentException $e){$caught=true;}
    confirm_expect($caught,'Invalid setting accepted');
}
confirm_expect(qcallback_return_confirmation_settings(['return_confirm_repeats'=>'0'])['return_confirm_repeats']===0,'Zero repeat setting lost');
$plan=qcallback_outbound_dialplan();preg_match('/\[queuecallback-agent-outbound\](.*?)(?=\n\[)/s',$plan,$agent);
confirm_expect(str_contains($agent[1],'TtgrU(')&&strpos($agent[1],'Dial(')<strpos($agent[1],'Playback(custom/failed-to-confirm)')
    &&str_contains($agent[1],'${SHARED(QCB_CUSTOMER_CONFIRMED)}'),'Agent must remain after customer hangup and hear failure only if unconfirmed');
echo "PASS: generated confirmation flow handles default/configured timeout and repeats, invalid digits, press 1, hangup, error, custom recordings and agent failure feedback\n";
