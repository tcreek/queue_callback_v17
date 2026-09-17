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

        // alt_message_id
        $c = sql("SHOW COLUMNS FROM `queuecallback_config` LIKE 'alt_message_id'", "getAll");
        if (empty($c)) {
            sql("ALTER TABLE `queuecallback_config` ADD COLUMN `alt_message_id` VARCHAR(100) DEFAULT NULL AFTER `confirm_number`");
            out("Added column: alt_message_id");
        }

        // initiated_message_id
        $c = sql("SHOW COLUMNS FROM `queuecallback_config` LIKE 'initiated_message_id'", "getAll");
        if (empty($c)) {
            sql("ALTER TABLE `queuecallback_config` ADD COLUMN `initiated_message_id` VARCHAR(100) DEFAULT NULL AFTER `alt_message_id`");
            out("Added column: initiated_message_id");
        }

        // confirm_prompt_id
        $c = sql("SHOW COLUMNS FROM `queuecallback_config` LIKE 'confirm_prompt_id'", "getAll");
        if (empty($c)) {
            sql("ALTER TABLE `queuecallback_config` ADD COLUMN `confirm_prompt_id` VARCHAR(100) DEFAULT NULL AFTER `initiated_message_id`");
            out("Added column: confirm_prompt_id");
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
            alt_message_id VARCHAR(100) DEFAULT NULL,
            initiated_message_id VARCHAR(100) DEFAULT NULL,
            confirm_prompt_id VARCHAR(100) DEFAULT NULL,
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

    // Ensure queuecallback_security table exists (upgrade safety)
    try {
        $securityTable = sql("SHOW TABLES LIKE 'queuecallback_security'", "getAll");
        if (empty($securityTable)) {
            sql("CREATE TABLE queuecallback_security (
                id INT AUTO_INCREMENT PRIMARY KEY,
                pattern VARCHAR(20) NOT NULL,
                description VARCHAR(100) NOT NULL,
                area_code VARCHAR(10) DEFAULT '',
                queue_id VARCHAR(20) DEFAULT '',
                enabled TINYINT(1) DEFAULT 1,
                sort_order INT DEFAULT 0,
                created_at INT DEFAULT NULL,
                updated_at INT DEFAULT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
            freepbx_log(FPBX_LOG_INFO, "Queue Callback: Created queuecallback_security table");
        } else {
            $qc = sql("SHOW COLUMNS FROM `queuecallback_security` LIKE 'queue_id'", "getAll");
            if (empty($qc)) {
                sql("ALTER TABLE `queuecallback_security` ADD COLUMN `queue_id` VARCHAR(20) DEFAULT '' AFTER `area_code`");
                freepbx_log(FPBX_LOG_INFO, "Queue Callback: Added queue_id column to queuecallback_security");
            }
        }
    } catch (\Throwable $e) {
        freepbx_log(FPBX_LOG_WARNING, "Queue Callback: Could not create queuecallback_security table: " . $e->getMessage());
    }

    // Populate global Caribbean security entries (queue_id = '' for global scope)
    // Check specifically for global entries - per-queue entries may exist but global ones may not
    try {
        $check = sql("SELECT COUNT(*) as cnt FROM queuecallback_security WHERE queue_id = ''", "getAll");
        if (!empty($check) && (int)$check[0]['cnt'] === 0) {
            $caribbeanEntries = [
                ['pattern' => '_268NXXXXXX', 'description' => 'Antigua and Barbuda', 'area_code' => '268', 'queue_id' => '', 'enabled' => 1, 'sort_order' => 10],
                ['pattern' => '_284NXXXXXX', 'description' => 'British Virgin Islands', 'area_code' => '284', 'queue_id' => '', 'enabled' => 1, 'sort_order' => 20],
                ['pattern' => '_345NXXXXXX', 'description' => 'Cayman Islands', 'area_code' => '345', 'queue_id' => '', 'enabled' => 1, 'sort_order' => 30],
                ['pattern' => '_473NXXXXXX', 'description' => 'Grenada', 'area_code' => '473', 'queue_id' => '', 'enabled' => 1, 'sort_order' => 40],
                ['pattern' => '_649NXXXXXX', 'description' => 'Turks and Caicos Islands', 'area_code' => '649', 'queue_id' => '', 'enabled' => 1, 'sort_order' => 50],
                ['pattern' => '_664NXXXXXX', 'description' => 'Montserrat', 'area_code' => '664', 'queue_id' => '', 'enabled' => 1, 'sort_order' => 60],
                ['pattern' => '_721NXXXXXX', 'description' => 'Sint Maarten', 'area_code' => '721', 'queue_id' => '', 'enabled' => 1, 'sort_order' => 70],
                ['pattern' => '_758NXXXXXX', 'description' => 'Saint Lucia', 'area_code' => '758', 'queue_id' => '', 'enabled' => 1, 'sort_order' => 80],
                ['pattern' => '_767NXXXXXX', 'description' => 'Dominica', 'area_code' => '767', 'queue_id' => '', 'enabled' => 1, 'sort_order' => 90],
                ['pattern' => '_784NXXXXXX', 'description' => 'Saint Vincent and the Grenadines', 'area_code' => '784', 'queue_id' => '', 'enabled' => 1, 'sort_order' => 100],
                ['pattern' => '_809NXXXXXX', 'description' => 'Dominican Republic', 'area_code' => '809', 'queue_id' => '', 'enabled' => 1, 'sort_order' => 110],
                ['pattern' => '_829NXXXXXX', 'description' => 'Dominican Republic', 'area_code' => '829', 'queue_id' => '', 'enabled' => 1, 'sort_order' => 120],
                ['pattern' => '_849NXXXXXX', 'description' => 'Dominican Republic', 'area_code' => '849', 'queue_id' => '', 'enabled' => 1, 'sort_order' => 130],
                ['pattern' => '_868NXXXXXX', 'description' => 'Trinidad and Tobago', 'area_code' => '868', 'queue_id' => '', 'enabled' => 1, 'sort_order' => 140],
                ['pattern' => '_869NXXXXXX', 'description' => 'Saint Kitts and Nevis', 'area_code' => '869', 'queue_id' => '', 'enabled' => 1, 'sort_order' => 150],
                ['pattern' => '_876NXXXXXX', 'description' => 'Jamaica', 'area_code' => '876', 'queue_id' => '', 'enabled' => 1, 'sort_order' => 160],
                ['pattern' => '_1268NXXXXXX', 'description' => 'Antigua and Barbuda (+1)', 'area_code' => '1268', 'queue_id' => '', 'enabled' => 1, 'sort_order' => 170],
                ['pattern' => '_1284NXXXXXX', 'description' => 'British Virgin Islands (+1)', 'area_code' => '1284', 'queue_id' => '', 'enabled' => 1, 'sort_order' => 180],
                ['pattern' => '_1345NXXXXXX', 'description' => 'Cayman Islands (+1)', 'area_code' => '1345', 'queue_id' => '', 'enabled' => 1, 'sort_order' => 190],
                ['pattern' => '_1473NXXXXXX', 'description' => 'Grenada (+1)', 'area_code' => '1473', 'queue_id' => '', 'enabled' => 1, 'sort_order' => 200],
                ['pattern' => '_1649NXXXXXX', 'description' => 'Turks and Caicos Islands (+1)', 'area_code' => '1649', 'queue_id' => '', 'enabled' => 1, 'sort_order' => 210],
                ['pattern' => '_1664NXXXXXX', 'description' => 'Montserrat (+1)', 'area_code' => '1664', 'queue_id' => '', 'enabled' => 1, 'sort_order' => 220],
                ['pattern' => '_1721NXXXXXX', 'description' => 'Sint Maarten (+1)', 'area_code' => '1721', 'queue_id' => '', 'enabled' => 1, 'sort_order' => 230],
                ['pattern' => '_1758NXXXXXX', 'description' => 'Saint Lucia (+1)', 'area_code' => '1758', 'queue_id' => '', 'enabled' => 1, 'sort_order' => 240],
                ['pattern' => '_1767NXXXXXX', 'description' => 'Dominica (+1)', 'area_code' => '1767', 'queue_id' => '', 'enabled' => 1, 'sort_order' => 250],
                ['pattern' => '_1784NXXXXXX', 'description' => 'Saint Vincent and the Grenadines (+1)', 'area_code' => '1784', 'queue_id' => '', 'enabled' => 1, 'sort_order' => 260],
                ['pattern' => '_1809NXXXXXX', 'description' => 'Dominican Republic (+1)', 'area_code' => '1809', 'queue_id' => '', 'enabled' => 1, 'sort_order' => 270],
                ['pattern' => '_1829NXXXXXX', 'description' => 'Dominican Republic (+1)', 'area_code' => '1829', 'queue_id' => '', 'enabled' => 1, 'sort_order' => 280],
                ['pattern' => '_1849NXXXXXX', 'description' => 'Dominican Republic (+1)', 'area_code' => '1849', 'queue_id' => '', 'enabled' => 1, 'sort_order' => 290],
                ['pattern' => '_1868NXXXXXX', 'description' => 'Trinidad and Tobago (+1)', 'area_code' => '1868', 'queue_id' => '', 'enabled' => 1, 'sort_order' => 300],
                ['pattern' => '_1869NXXXXXX', 'description' => 'Saint Kitts and Nevis (+1)', 'area_code' => '1869', 'queue_id' => '', 'enabled' => 1, 'sort_order' => 310],
                ['pattern' => '_1876NXXXXXX', 'description' => 'Jamaica (+1)', 'area_code' => '1876', 'queue_id' => '', 'enabled' => 1, 'sort_order' => 320],
            ];
            foreach ($caribbeanEntries as $entry) {
                sql(
                    "INSERT INTO queuecallback_security (pattern, description, area_code, queue_id, enabled, sort_order, created_at) VALUES (?,?,?,?,?,?,?)",
                    "getAll",
                    $entry['pattern'],
                    $entry['description'],
                    $entry['area_code'],
                    $entry['queue_id'],
                    $entry['enabled'],
                    $entry['sort_order'],
                    time()
                );
            }
            freepbx_log(FPBX_LOG_INFO, "Queue Callback: Auto-populated " . count($caribbeanEntries) . " global default security entries");
        }
    } catch (\Throwable $e) {
        freepbx_log(FPBX_LOG_WARNING, "Queue Callback: Could not populate default security entries: " . $e->getMessage());
    }
} catch (\Throwable $e) {
    out("Database setup error: " . $e->getMessage());
}

/* -------------------------------------------------------------------
 * 1b) DEFAULT ANNOUNCEMENT FILES
 * -------------------------------------------------------------------*/
try {
    $srcDir = __DIR__ . '/announcements';
    $dstDir = '/var/lib/asterisk/sounds/en/custom';

    $defaultAnnouncements = [
        'callback-announcement.wav',
        'alternate_num_instruct.wav',
        'confirm_number.wav',
        'callback_returned.wav',
        'callback_initiated.wav',
        'callback_confirm.wav',
    ];

    if (!is_dir($dstDir)) {
        @mkdir($dstDir, 0755, true);
        @chown($dstDir, 'asterisk');
        @chgrp($dstDir, 'asterisk');
    }

    $defaultAnnouncementFormats = ['wav', 'ulaw', 'alaw'];

    if (is_dir($srcDir)) {
        foreach ($defaultAnnouncements as $baseName) {
            $base = basename($baseName, '.wav');
            foreach ($defaultAnnouncementFormats as $fmt) {
                $fileName = $base . '.' . $fmt;
                $src = $srcDir . '/' . $fileName;
                $dst = $dstDir . '/' . $fileName;
                if (file_exists($src)) {
                    $changed = !file_exists($dst) || md5_file($src) !== md5_file($dst);
                    if ($changed) {
                        @copy($src, $dst);
                        @chown($dst, 'asterisk');
                        @chgrp($dst, 'asterisk');
                        @chmod($dst, 0664);
                        out("Installed default announcement: $fileName");
                    }
                } else {
                    out("Warning: default announcement missing: $src");
                }
            }
        }
    } else {
        out("Warning: announcements directory not found at $srcDir");
    }
} catch (\Throwable $e) {
    out("Default announcement install error: " . $e->getMessage());
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
exten => s,1,NoOp(QCB: Processing customer-first callback for queue ${CALLBACK_QUEUE_ID})
same => n,Set(CALLERID(name)=Queue Callback)
same => n,Answer()
same => n,Wait(1)
same => n,Dial(Local/${CALLBACK_CUSTOMER_NUM}@from-internal,30,Ttr)
same => n,GotoIf($["${CALLBACK_RETURN_MSG}" != ""]?custom_msg)
same => n,Playback(custom/callback_returned)
same => n,Goto(connect_queue)
same => n(custom_msg),Playback(${CALLBACK_RETURN_MSG})
same => n(connect_queue),Set(__CALLBACK_RETURN=1)
same => n,Goto(ext-queues,${CALLBACK_QUEUE_ID},1)

; Queue Callback Agent Outbound Context - Auto-generated
[queuecallback-agent-outbound]
exten => s,1,NoOp(QCB: Processing agent-first callback for queue ${CALLBACK_QUEUE_ID})
same => n,Set(CALLERID(name)=Queue Callback)
same => n,Answer()
same => n,Wait(1)
same => n,Playback(you-will-be-connected-to-a-customer)
same => n,Set(__CALLBACK_RETURN_MSG=${CALLBACK_RETURN_MSG})
same => n,Set(__CALLBACK_CUSTOMER_NUM=${CALLBACK_CUSTOMER_NUM})
same => n,Set(__CALLBACK_QUEUE_ID=${CALLBACK_QUEUE_ID})
same => n,Dial(${CALLBACK_CUSTOMER_CHANNEL},30,TtrU(qcb-customer-confirm))
same => n,Hangup()

[qcb-customer-confirm]
exten => s,1,NoOp(QCB: Customer callback confirm for callback ${CALLBACK_ID})
 same => n,GotoIf($["${CALLBACK_RETURN_MSG}" != ""]?play_return)
 same => n,Playback(custom/callback_returned)
 same => n,Return()
 same => n(play_return),Playback(${CALLBACK_RETURN_MSG})
 same => n,Return()

DP;

    $custom = '/etc/asterisk/extensions_custom.conf';
    $existing = file_exists($custom) ? file_get_contents($custom) : '';

    // Idempotent: only append contexts that don't exist yet
    $needs_outbound = (strpos($existing, '[queuecallback-outbound]') === false);
    $needs_agent = (strpos($existing, '[queuecallback-agent-outbound]') === false);
    $needs_cust = (strpos($existing, '[qcb-customer-confirm]') === false);

    if ($needs_outbound || $needs_agent || $needs_cust) {
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
// Delegate to the working process_callbacks.php
$script = __DIR__ . '/process_callbacks.php';
if (file_exists($script)) {
    passthru('/usr/bin/php ' . escapeshellarg($script) . ' 2>&1');
}
PHP;
    file_put_contents($proc, $procContent);
    @chmod($proc, 0755);
    @chown($proc, 'asterisk'); @chgrp($proc, 'asterisk');
    out("Wrote intelligent callback processor script");

    // Install 4 staggered cron lines per minute (every ~15 seconds)
    // Use process_callbacks.php directly (consistent with Qcallback.class.php updateCronJob)
    $script = '/var/www/html/admin/modules/qcallback/process_callbacks.php';
    $current = shell_exec('crontab -l 2>/dev/null') ?: '';
    $lines = explode("\n", $current);
    $filtered = array_filter($lines, function($l) {
        return strpos($l, 'intelligent_callback_processor.php') === false
            && strpos($l, 'process_callbacks.php') === false
            && strpos($l, 'callback_processor.php') === false;
    });
    $filtered[] = '';
    $filtered[] = '# Queue Callback processor (every 15s)';
    $filtered[] = "* * * * * $script >/dev/null 2>&1";
    $filtered[] = "* * * * * sleep 15; $script >/dev/null 2>&1";
    $filtered[] = "* * * * * sleep 30; $script >/dev/null 2>&1";
    $filtered[] = "* * * * * sleep 45; $script >/dev/null 2>&1";

    $new = implode("\n", $filtered) . "\n";
    file_put_contents('/tmp/new_crontab', $new);
    shell_exec('crontab /tmp/new_crontab 2>/dev/null');
    @unlink('/tmp/new_crontab');

    out("Installed cron for callback processor");
} catch (\Throwable $e) {
    out("Processor setup error: " . $e->getMessage());
}

// Ensure menu configuration and FreePBX reload after installation
try {
    $menuSrc = __DIR__ . '/freepbx_menu.conf';
    $menuDst = '/etc/asterisk/freepbx_menu.conf';
    if (file_exists($menuSrc) && is_readable($menuSrc)) {
        if (!file_exists($menuDst) || strpos(file_get_contents($menuDst), '[qcallback_reports]') === false) {
            @copy($menuSrc, $menuDst);
            @chown($menuDst, 'asterisk'); @chgrp($menuDst, 'asterisk'); @chmod($menuDst, 0664);
        }
    }
    if (function_exists('needreload')) {
        needreload();
    }
} catch (\Throwable $e) {
    out("Post-install setup error: " . $e->getMessage());
}

out("Queue Callback module installation completed");

