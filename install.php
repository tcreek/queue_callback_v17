<?php
/**
 * Queue Callback Module for FreePBX
 *
 * Copyright (C) 2026 Trent Creekmore 
 * trent@netservisity.com
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */
if (!defined('FREEPBX_IS_AUTH')) { die('No direct script access allowed'); }

out("Queue Callback module installation started");

/* -------------------------------------------------------------------
 * 1) DATABASE SETUP (sql() only)
 * -------------------------------------------------------------------*/
try {
    // Check if table exists
    $exists = sql("SHOW TABLES LIKE 'queuecallback_config'", "getAll");
    $table_exists = !empty($exists);

    if ($table_exists) {
        out("Existing installation found. Checking for schema upgrades...");

        // announce_frequency
        $c = sql("SHOW COLUMNS FROM `queuecallback_config` LIKE 'announce_frequency'", "getAll");
        if (empty($c)) {
            sql("ALTER TABLE `queuecallback_config` ADD COLUMN `announce_frequency` INT DEFAULT 1 AFTER `announce_id`");
            out("Added column: announce_frequency");
        }

        // call_first
        $c = sql("SHOW COLUMNS FROM `queuecallback_config` LIKE 'call_first'", "getAll");
        if (empty($c)) {
            sql("ALTER TABLE `queuecallback_config` ADD COLUMN `call_first` VARCHAR(10) DEFAULT 'customer' AFTER `alt_number_key`");
            out("Added column: call_first");
        }

        // outbound_route_id
        $c = sql("SHOW COLUMNS FROM `queuecallback_config` LIKE 'outbound_route_id'", "getAll");
        if (empty($c)) {
            sql("ALTER TABLE `queuecallback_config` ADD COLUMN `outbound_route_id` INT DEFAULT 1 AFTER `call_first`");
            out("Added column: outbound_route_id");
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
            processing_interval INT DEFAULT 30,
            max_attempts INT DEFAULT 3,
            retry_interval INT DEFAULT 30,
            return_message_id VARCHAR(100) DEFAULT NULL,
            confirm_message_id VARCHAR(100) DEFAULT NULL,
            confirm_number TINYINT(1) DEFAULT 1,
            alt_number_key VARCHAR(10) DEFAULT '2',
            call_first VARCHAR(10) DEFAULT 'customer',
            outbound_route_id INT DEFAULT 1
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

    // store.agi
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

    // check.agi (inline; DB creds are local MySQL asterisk DB)
    $checkDst = "$agiDir/queuecallback-check.agi";
    $checkContent = <<<'AGI'
#!/usr/bin/php -q
<?php
ob_implicit_flush(true);
set_time_limit(30);
$stdin = fopen("php://stdin", "r");
$stdout = fopen("php://stdout", "w");
function agi_cmd($cmd) { global $stdin,$stdout; fputs($stdout, "$cmd\n"); fflush($stdout); return fgets($stdin); }
$queue_id = $argv[1] ?? getenv("QUEUENAME");
if ($queue_id) {
    try {
        $pdo = new PDO("mysql:host=localhost;dbname=asterisk", "freepbxuser", "55f88989cd6491e23706671e66750db4");
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $sql = "SELECT enabled, callback_key FROM queuecallback_config WHERE queue_id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$queue_id]);
        $config = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($config && $config["enabled"]) {
            agi_cmd("SET VARIABLE QUEUE_CALLBACK_ENABLED 1");
            agi_cmd("SET VARIABLE QUEUE_CALLBACK_KEY " . ($config["callback_key"] ?: "*"));
        } else {
            agi_cmd("SET VARIABLE QUEUE_CALLBACK_ENABLED 0");
        }
    } catch (Exception $e) {
        agi_cmd("VERBOSE \"Error checking callback config: " . $e->getMessage() . "\"");
        agi_cmd("SET VARIABLE QUEUE_CALLBACK_ENABLED 0");
    }
}
?>
AGI;
    file_put_contents($checkDst, $checkContent);
    @chmod($checkDst, 0755);
    @chown($checkDst, 'asterisk'); @chgrp($checkDst, 'asterisk');
    out("Installed queuecallback-check.agi");

    // complete.agi (optional)
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
 * 3) DIALPLAN CONTEXTS (only contexts uninstall.php removes)
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

; Queue Callback Agent Outbound Context - Auto-generated
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

    // Idempotent: only append once
    $needs_outbound = (strpos($existing, '[queuecallback-outbound]') === false);
    $needs_agent = (strpos($existing, '[queuecallback-agent-outbound]') === false);

    if ($needs_outbound || $needs_agent) {
        // If one exists but not the other, just append the full block (safe)
        file_put_contents($custom, $existing . $dialplan, LOCK_EX);
        out("Added outbound callback dialplan contexts");
    } else {
        out("Dialplan contexts already present — skipping");
    }
} catch (\Throwable $e) {
    out("Dialplan write error: " . $e->getMessage());
}

/* -------------------------------------------------------------------
 * 4) OPTIONAL — Intelligent processor script + cron (removed by uninstall.php)
 * -------------------------------------------------------------------*/
try {
    $proc = '/var/www/html/admin/modules/qcallback/intelligent_callback_processor.php';
    $procContent = <<<'PHP'
#!/usr/bin/php
<?php
require_once('/etc/freepbx.conf');
$qcallback = \FreePBX::create()->Qcallback ?? null;
if ($qcallback && method_exists($qcallback, 'processIntelligentCallbacks')) {
    $n = $qcallback->processIntelligentCallbacks();
    if ($n > 0) { error_log("Intelligent Queue Callback: processed $n"); }
}
PHP;
    file_put_contents($proc, $procContent);
    @chmod($proc, 0755);
    @chown($proc, 'asterisk'); @chgrp($proc, 'asterisk');
    out("Wrote intelligent callback processor script");

    // Install 4 staggered cron lines per minute (every ~15 seconds)
    $current = shell_exec('crontab -l 2>/dev/null') ?: '';
    $lines = explode("\n", $current);
    $filtered = array_filter($lines, function($l) {
        return strpos($l, 'intelligent_callback_processor.php') === false
            && strpos($l, 'process_callbacks.php') === false
            && strpos($l, 'callback_processor.php') === false;
    });
    $filtered[] = '';
    $filtered[] = '# Queue Callback intelligent processor (every 15s)';
    $filtered[] = "* * * * * $proc >/dev/null 2>&1";
    $filtered[] = "* * * * * sleep 15; $proc >/dev/null 2>&1";
    $filtered[] = "* * * * * sleep 30; $proc >/dev/null 2>&1";
    $filtered[] = "* * * * * sleep 45; $proc >/dev/null 2>&1";

    $new = implode("\n", $filtered) . "\n";
    file_put_contents('/tmp/new_crontab', $new);
    shell_exec('crontab /tmp/new_crontab');
    @unlink('/tmp/new_crontab');

    out("Installed cron for intelligent processor");
} catch (\Throwable $e) {
    out("Processor setup error: " . $e->getMessage());
}

out("Queue Callback module installation completed");

