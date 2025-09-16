<?php
if (!defined('FREEPBX_IS_AUTH')) { die('No direct script access allowed'); }

out("Queue Callback module installation started");

/* -------------------------------------------------------------------
 * 1) DATABASE SETUP
 * -------------------------------------------------------------------*/
try {
    // If exists, upgrade; else create
    $exists = sql("SHOW TABLES LIKE 'queuecallback_config'", "getAll");
    if (!empty($exists)) {
        out("Existing installation found. Checking for schema upgrades...");

        $cols = function($name) {
            return sql("SHOW COLUMNS FROM `queuecallback_config` LIKE " . q($name), "getAll");
        };

        if (empty($cols('announce_frequency'))) {
            sql("ALTER TABLE `queuecallback_config` ADD COLUMN `announce_frequency` INT DEFAULT 1 AFTER `announce_id`");
            out("Added column: announce_frequency");
        }
        if (empty($cols('call_first'))) {
            sql("ALTER TABLE `queuecallback_config` ADD COLUMN `call_first` VARCHAR(10) DEFAULT 'customer' AFTER `alt_number_key`");
            out("Added column: call_first");
        }
    } else {
        out("New installation. Creating tables...");

        sql("DROP TABLE IF EXISTS queuecallback_requests");
        sql("DROP TABLE IF EXISTS queuecallback_config");
        sql("DROP TABLE IF EXISTS queuecallback_trigger");

        sql("CREATE TABLE queuecallback_requests (
            id INT AUTO_INCREMENT PRIMARY KEY,
            queue_id VARCHAR(50) NOT NULL,
            caller_id VARCHAR(50) DEFAULT NULL,
            callback_number VARCHAR(50) NOT NULL,
            time_requested INT NOT NULL,
            time_processed INT DEFAULT NULL,
            status ENUM('pending','processing','completed','failed','cancelled') DEFAULT 'pending',
            attempts INT DEFAULT 0,
            max_attempts INT DEFAULT 3,
            last_attempt INT DEFAULT NULL,
            uniqueid VARCHAR(50) DEFAULT NULL,
            position INT DEFAULT NULL,
            INDEX idx_queue_status (queue_id, status),
            INDEX idx_time_requested (time_requested)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8");

        sql("CREATE TABLE queuecallback_config (
            queue_id VARCHAR(50) PRIMARY KEY,
            enabled TINYINT(1) DEFAULT 0,
            announce_id VARCHAR(100) DEFAULT NULL,
            announce_frequency INT DEFAULT 1,
            callback_key VARCHAR(10) DEFAULT '*',
            processing_interval INT DEFAULT 5,
            max_attempts INT DEFAULT 3,
            retry_interval INT DEFAULT 5,
            return_message_id VARCHAR(100) DEFAULT NULL,
            confirm_message_id VARCHAR(100) DEFAULT NULL,
            callback_started_message_id VARCHAR(100) DEFAULT NULL,
            confirm_number TINYINT(1) DEFAULT 1,
            alt_number_key VARCHAR(10) DEFAULT '2',
            call_first VARCHAR(10) DEFAULT 'customer'
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8");

        sql("CREATE TABLE queuecallback_trigger (
            id INT PRIMARY KEY DEFAULT 1,
            last_run INT NOT NULL,
            UNIQUE KEY unique_id (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8");

        out("Database tables created.");
    }
} catch (\Throwable $e) {
    out("Database setup error: " . $e->getMessage());
}

/* -------------------------------------------------------------------
 * 2) AGI FILES
 * -------------------------------------------------------------------*/
try {
    $agiDir = '/var/lib/asterisk/agi-bin';
    if (!is_dir($agiDir)) { @mkdir($agiDir, 0755, true); }

    // queuecallback-store.agi (module-provided)
    $storeSrc = __DIR__ . '/agi-bin/queuecallback-store.agi';
    $storeDst = "$agiDir/queuecallback-store.agi";
    if (file_exists($storeSrc)) {
        @copy($storeSrc, $storeDst);
        @chmod($storeDst, 0755);
        @chown($storeDst, 'asterisk'); @chgrp($storeDst, 'asterisk');
        out("Installed queuecallback-store.agi");
    } else {
        out("Warning: queuecallback-store.agi not found in module");
    }

    // queuecallback-check.agi (module-provided preferred; if missing, write the inline one)
    $checkSrc = __DIR__ . '/agi-bin/queuecallback-check.agi';
    $checkDst = "$agiDir/queuecallback-check.agi";
    if (file_exists($checkSrc)) {
        @copy($checkSrc, $checkDst);
        @chmod($checkDst, 0755);
        @chown($checkDst, 'asterisk'); @chgrp($checkDst, 'asterisk');
        out("Installed queuecallback-check.agi");
    } else {
        $checkContent = <<<'AGI'
#!/usr/bin/php
<?php
$stdin=fopen('php://stdin','r'); $stdout=fopen('php://stdout','w');
function agi($c){global $stdin,$stdout; fputs($stdout,$c."\n"); fflush($stdout); return fgets($stdin); }
$env=[];
while(($l=fgets($stdin))){ $l=trim($l); if($l==='') break; if(strpos($l,':')!==false){[$k,$v]=explode(':',$l,2); $env[trim($k)]=trim($v);} }
try{
  $res=agi("GET VARIABLE CALLBACK_QUEUE");
  preg_match('/result=1 \(([^)]*)\)/',$res,$m); $queue_id=$m[1]??'';
  if(!$queue_id){ $queue_id=$env['agi_dnid']??''; }
  if($queue_id){
    $pdo=new PDO('mysql:host=localhost;dbname=asterisk','root',''); $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
    $st=$pdo->prepare("SELECT enabled,callback_key FROM queuecallback_config WHERE queue_id=?"); $st->execute([$queue_id]);
    $cfg=$st->fetch(PDO::FETCH_ASSOC);
    if($cfg && (int)$cfg['enabled']===1){
      agi("SET VARIABLE QUEUE_CALLBACK_ENABLED 1");
      agi("SET VARIABLE QUEUE_CALLBACK_KEY ".(($cfg['callback_key']??'*')?:'*'));
    }else{
      agi("SET VARIABLE QUEUE_CALLBACK_ENABLED 0");
    }
  }else{
    agi("SET VARIABLE QUEUE_CALLBACK_ENABLED 0");
  }
}catch(Throwable $e){ agi('VERBOSE "QCB check error: '.$e->getMessage().'"'); agi('SET VARIABLE QUEUE_CALLBACK_ENABLED 0'); }
AGI;
        file_put_contents($checkDst, $checkContent);
        @chmod($checkDst, 0755);
        @chown($checkDst, 'asterisk'); @chgrp($checkDst, 'asterisk');
        out("Installed inline queuecallback-check.agi");
    }

    // optional complete.agi
    $completeSrc = __DIR__ . '/agi-bin/queuecallback-complete.agi';
    $completeDst = "$agiDir/queuecallback-complete.agi";
    if (file_exists($completeSrc)) {
        @copy($completeSrc, $completeDst);
        @chmod($completeDst, 0755);
        @chown($completeDst, 'asterisk'); @chgrp($completeDst, 'asterisk');
        out("Installed queuecallback-complete.agi");
    }
} catch (\Throwable $e) {
    out("AGI installation error: " . $e->getMessage());
}

/* -------------------------------------------------------------------
 * 3) SOUND FILES
 * -------------------------------------------------------------------*/
try {
    $soundsDir = '/var/lib/asterisk/sounds/en/custom';
    if (!is_dir($soundsDir)) { 
        @mkdir($soundsDir, 0755, true); 
        @chown($soundsDir, 'asterisk'); 
        @chgrp($soundsDir, 'asterisk');
    }

    // Install confirm_number.wav (hardcoded confirmation prompt)
    $confirmSrc = __DIR__ . '/sounds/en/custom/confirm_number.wav';
    $confirmDst = "$soundsDir/confirm_number.wav";
    if (file_exists($confirmSrc)) {
        @copy($confirmSrc, $confirmDst);
        @chmod($confirmDst, 0644);
        @chown($confirmDst, 'asterisk'); 
        @chgrp($confirmDst, 'asterisk');
        out("Installed custom/confirm_number.wav");
    } else {
        out("Warning: confirm_number.wav not found in module sounds directory");
    }
} catch (\Throwable $e) {
    out("Sound file installation error: " . $e->getMessage());
}

/* -------------------------------------------------------------------
 * 3) STATIC DIALPLAN CONTEXTS (NO cron here)
 * -------------------------------------------------------------------*/
try {
    $dialplan = <<<'DP'

; Queue Callback Outbound Context - Auto-generated
[queuecallback-outbound]
exten => _X.,1,NoOp(Processing callback for ${EXTEN})
 same => n,Set(CALLERID(name)=Queue Callback)
 same => n,Answer()
 same => n,Wait(1)
 same => n,GotoIf($["${CALLBACK_RETURN_MSG}" != ""]?custom_msg)
 same => n,Playback(queue-thankyou)
 same => n,Playback(pls-wait-connect-call)
 same => n,Goto(connect_queue)
 same => n(custom_msg),Playback(${CALLBACK_RETURN_MSG})
 same => n(connect_queue),Goto(ext-queues,${CALLBACK_QUEUE_ID},1)

; Agent-first leg (optional)
[queuecallback-agent-outbound]
exten => s,1,NoOp(Processing agent-first callback for queue ${CALLBACK_QUEUE_ID})
 same => n,Answer()
 same => n,Wait(1)
 same => n,Playback(you-will-be-connected-to-a-customer)
 same => n,Dial(Local/${CALLBACK_CUSTOMER_NUM}@from-internal,,Ttr)
 same => n,Hangup()

DP;

    $custom = '/etc/asterisk/extensions_custom.conf';
    $existing = file_exists($custom) ? file_get_contents($custom) : '';
    $needs_outbound = (strpos($existing, '[queuecallback-outbound]') === false);
    $needs_agent    = (strpos($existing, '[queuecallback-agent-outbound]') === false);

    if ($needs_outbound || $needs_agent) {
        file_put_contents($custom, $existing . $dialplan, LOCK_EX);
        out("Added outbound callback dialplan contexts");
    } else {
        out("Dialplan contexts already present — skipping");
    }
} catch (\Throwable $e) {
    out("Dialplan write error: " . $e->getMessage());
}

/* -------------------------------------------------------------------
 * 4) INTELLIGENT PROCESSOR SCRIPT (NO cron here)
 * -------------------------------------------------------------------*/
try {
    $proc = '/var/www/html/admin/modules/qcallback/intelligent_callback_processor.php';
    if (!file_exists($proc)) {
        // If you already have your working processor, copy it in instead of writing anything here.
        // Here we just ensure path exists; you can deploy your known-good file.
        @file_put_contents($proc, "#!/usr/bin/php\n<?php\nrequire_once('/etc/freepbx.conf');\n// your processor code here\n");
        @chmod($proc, 0755);
        @chown($proc, 'asterisk'); @chgrp($proc, 'asterisk');
        out("Wrote placeholder intelligent_callback_processor.php (no cron registered)");
    } else {
        out("Processor script already present (no cron registered)");
    }
} catch (\Throwable $e) {
    out("Processor setup error: " . $e->getMessage());
}

/* -------------------------------------------------------------------
 * 5) Reload (flag only)
 * -------------------------------------------------------------------*/
try {
    if (function_exists('needreload')) { needreload(); }
    out("Install complete (no cron entries added until a queue is enabled)");
} catch (\Throwable $e) {
    out("Reload flag error: " . $e->getMessage());
}
