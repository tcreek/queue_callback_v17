<?php
namespace FreePBX\modules;

use BMO;
use FreePBX_Helpers;
use PDO;

/**
 * Queue Callback FreePBX Module Class
 * - Generates queue handler dialplan
 * - Installs/maintains AGIs
 * - Manages asterisk-user cron block (no root/system edits)
 * - Uses VQ_GOSUB/QGOSUB so Queue() passes our handler
 */
class Qcallback extends FreePBX_Helpers implements BMO {

    /** @var \FreePBX */
    protected $FreePBX;

    /** @var \PDO */
    protected $db;

    public function __construct($freepbx = null) {
        $this->FreePBX = $freepbx;
        $this->db = $freepbx->Database;
    }

    /* -------------------------
     * Helpers
     * -------------------------*/
    private function isCli(): bool {
        return (PHP_SAPI === 'cli');
    }

    private function flagNeedReload(): void {
        if (function_exists('needreload')) { needreload(); }
        if ($this->FreePBX && method_exists($this->FreePBX, 'needreload')) {
            $this->FreePBX->needreload();
        }
    }

    private function safeExec(string $cmd): void {
        // Only execute from CLI to avoid permission/shell issues from web
        if ($this->isCli()) { @exec($cmd); }
    }

    private function reloadDialplan(): void {
        if ($this->isCli()) {
            $this->safeExec('asterisk -rx "dialplan reload"');
        } else {
            $this->flagNeedReload();
        }
    }

    /* -------------------------
     * BMO required
     * -------------------------*/
    public function install() {
        $this->installAgiScripts(); // ensure our PDO AGIs are in place
        // Do not touch cron or dialplan here; generation occurs on enable/save
    }

    public function uninstall() {
        $this->uninstallAgiScripts();
        // remove our cron if nothing enabled (or force)
        $this->removeCronIfNoQueues(true);
        // FreePBX will rebuild stock dialplans
    }

    public function backup() {
        $out = [
            'queuecallback_config'   => [],
            'queuecallback_requests' => []
        ];
        try {
            $st = $this->db->query('SELECT * FROM queuecallback_config');
            $out['queuecallback_config'] = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {}
        try {
            $st = $this->db->query('SELECT * FROM queuecallback_requests WHERE status != "completed"');
            $out['queuecallback_requests'] = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {}
        return $out;
    }

    public function restore($backup) {
        if (!empty($backup['queuecallback_config'])) {
            foreach ($backup['queuecallback_config'] as $config) {
                $this->setQueueCallbackConfig($config['queue_id'], $config);
            }
        }
        if (!empty($backup['queuecallback_requests'])) {
            foreach ($backup['queuecallback_requests'] as $request) {
                unset($request['id']);
                $cols = array_keys($request);
                $ph   = implode(',', array_fill(0, count($cols), '?'));
                $sql  = 'INSERT INTO queuecallback_requests (' . implode(',', $cols) . ") VALUES ($ph)";
                $stmt = $this->db->prepare($sql);
                $stmt->execute(array_values($request));
            }
        }
    }

    public function doConfigPageInit($page) { return; }

    public function showPage() {
        $request = $_REQUEST;
        if (($request['display'] ?? '') === 'queues' && !empty($request['extdisplay'])) {
            $queue_id = $request['extdisplay'];
            $callback_config = $this->getQueueCallbackConfig($queue_id);
            include(__DIR__ . '/views/callback_config.php');
        }
    }

    /* -------------------------
     * Public API
     * -------------------------*/
    /**
     * Generate all dialplan artifacts, include orders, and cron decisions.
     * Always re-install AGIs so our PDO versions stay active.
     */
    public function generateCallbackDialplan(bool $force = false): void {
        if (!$this->isCli() && !$force) { return; }

        // keep AGIs correct on every regen
        $this->installAgiScripts();

        $configs = $this->getEnabledQueuesWithConfig();

        // Always write contexts (and remove stale ones)
        $this->generateQueuesPostCustom($configs);
        $this->generateExtensionsHandlers($configs);
        $this->generateExtensionsOverride($configs);
        $this->ensureQueuesIncludeOrder();

        $this->reloadDialplan();

        // Cron only if any queue enabled; remove if none
        if (!empty($configs)) {
            $this->ensureCronIfQueues();
        } else {
            $this->removeCronIfNoQueues();
        }
    }

    /* -------------------------
     * Config access
     * -------------------------*/
    private function getEnabledQueuesWithConfig(): array {
        try {
            $sql = "SELECT queue_id, announce_id, announce_frequency, callback_key, confirm_message_id, callback_started_message_id
                    FROM queuecallback_config WHERE enabled = 1";
            $stmt = $this->db->prepare($sql);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $out = [];
            foreach ($rows as $r) {
                $out[] = [
                    'queue_id'           => $r['queue_id'],
                    'announce_file'      => $this->resolveAnnouncementFile($r['announce_id']),
                    'announce_frequency' => (int)($r['announce_frequency'] ?? 1),
                    'callback_key'       => (!empty($r['callback_key']) && $this->isValidKey($r['callback_key'])) ? $r['callback_key'] : '*',
                    'confirm_prompt'     => 'custom/confirm_number', // Always use hardcoded confirmation prompt
                    'callback_started_file' => $this->resolveAnnouncementFile($r['callback_started_message_id'] ?? null) ?: 'thank-you-for-calling',
                ];
            }
            return $out;
        } catch (\Throwable $e) { return []; }
    }

    private function resolveAnnouncementFile($id): string {
        if (empty($id)) { return ''; }
        try {
            // recordings_details (preferred language-specific)
            $tables = $this->db->query("SHOW TABLES LIKE 'recordings_details'")->fetchAll(PDO::FETCH_ASSOC);
            if (!empty($tables)) {
                $stmt = $this->db->prepare("SELECT filename FROM recordings_details WHERE id = ? AND language = 'en' LIMIT 1");
                $stmt->execute([$id]);
                $res = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$res) {
                    $stmt = $this->db->prepare("SELECT filename FROM recordings_details WHERE id = ? LIMIT 1");
                    $stmt->execute([$id]);
                    $res = $stmt->fetch(PDO::FETCH_ASSOC);
                }
                if (!empty($res['filename'])) {
                    return $this->normalizeSoundFile($res['filename']);
                }
            }
            // recordings
            $stmt = $this->db->prepare("SELECT filename FROM recordings WHERE id = ? LIMIT 1");
            $stmt->execute([$id]);
            $res = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!empty($res['filename'])) {
                return $this->normalizeSoundFile($res['filename']);
            }
            // soundlang
            $stmt = $this->db->prepare("SELECT filename FROM soundlang WHERE id = ? AND language = 'en' LIMIT 1");
            $stmt->execute([$id]);
            $res = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$res) {
                $stmt = $this->db->prepare("SELECT filename FROM soundlang WHERE id = ? LIMIT 1");
                $stmt->execute([$id]);
                $res = $stmt->fetch(PDO::FETCH_ASSOC);
            }
            if (!empty($res['filename'])) {
                return $this->normalizeSoundFile($res['filename']);
            }
        } catch (\Throwable $e) {}
        return '';
    }

    private function normalizeSoundFile(string $filename): string {
        $f = trim($filename);
        $f = ltrim($f, '/');
        $f = preg_replace('#^var/lib/asterisk/sounds/#i', '', $f);
        $f = preg_replace('#^asterisk/sounds/#i', '', $f);
        // strip language prefix
        $f = preg_replace('#^[a-z]{2}(?:_[A-Z]{2})?/#', '', $f);
        // strip extension
        $f = preg_replace('/\.(wav|ulaw|alaw|gsm|sln|sln16|g722|mp3)$/i', '', $f);
        return $f;
    }

    public function getQueueCallbackConfig($queue_id) {
        try {
            $stmt = $this->db->prepare("SELECT * FROM queuecallback_config WHERE queue_id = ?");
            $stmt->execute([$queue_id]);
            $res = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($res) { return $res; }
        } catch (\Throwable $e) {}
        // defaults
        return [
            'queue_id'           => $queue_id,
            'enabled'            => 0,
            'announce_id'        => null,
            'announce_frequency' => 1,
            'callback_key'       => '*',
            'processing_interval'=> 5,
            'max_attempts'       => 3,
            'retry_interval'     => 5,
            'return_message_id'  => null,
            'confirm_message_id' => null,
            'callback_started_message_id' => null,
            'confirm_number'     => 1,
            'alt_number_key'     => '2',
            'call_first'         => 'customer',
        ];
    }

    public function setQueueCallbackConfig($queue_id, $config) {
        if (!$this->validateQueue($queue_id)) {
            throw new \Exception("Queue $queue_id does not exist");
        }

        $sql = "INSERT INTO queuecallback_config
                (queue_id, enabled, announce_id, announce_frequency, callback_key, processing_interval, max_attempts, retry_interval, return_message_id, confirm_message_id, callback_started_message_id, confirm_number, alt_number_key, call_first)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                ON DUPLICATE KEY UPDATE
                enabled=VALUES(enabled),
                announce_id=VALUES(announce_id),
                announce_frequency=VALUES(announce_frequency),
                callback_key=VALUES(callback_key),
                processing_interval=VALUES(processing_interval),
                max_attempts=VALUES(max_attempts),
                retry_interval=VALUES(retry_interval),
                return_message_id=VALUES(return_message_id),
                confirm_message_id=VALUES(confirm_message_id),
                callback_started_message_id=VALUES(callback_started_message_id),
                confirm_number=VALUES(confirm_number),
                alt_number_key=VALUES(alt_number_key),
                call_first=VALUES(call_first)";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            $queue_id,
            $config['enabled'] ?? 0,
            $config['announce_id'] ?? null,
            $config['announce_frequency'] ?? 1,
            $config['callback_key'] ?? '*',
            $config['processing_interval'] ?? 5,
            $config['max_attempts'] ?? 3,
            $config['retry_interval'] ?? 5,
            $config['return_message_id'] ?? null,
            $config['confirm_message_id'] ?? null,
            $config['callback_started_message_id'] ?? null,
            $config['confirm_number'] ?? 1,
            $config['alt_number_key'] ?? '2',
            $config['call_first'] ?? 'customer'
        ]);

        $this->syncSmallBitsToAstDB($queue_id, $config);

        // Generate dialplan & cron decisions
        $this->generateCallbackDialplan(true);
        $this->flagNeedReload();

        $action = ($config['enabled'] ?? 0) ? 'enabled' : 'disabled';
        freepbx_log(FPBX_LOG_INFO, "Queue Callback: $action for queue $queue_id");
    }

    private function validateQueue($queue_id): bool {
        $st = $this->db->prepare("SELECT extension FROM queues_config WHERE extension = ?");
        $st->execute([$queue_id]);
        return (bool)$st->fetch(PDO::FETCH_ASSOC);
    }

    private function syncSmallBitsToAstDB($queue_id, $config): void {
        try {
            $ckey = $config['callback_key'] ?? '*';
            $this->safeExec(sprintf('asterisk -rx %s', escapeshellarg("database put QCALLBACK/$queue_id callback_key $ckey")));
            if (!empty($config['announce_id'])) {
                $this->safeExec(sprintf('asterisk -rx %s', escapeshellarg("database put QCALLBACK/$queue_id announce_id {$config['announce_id']}")));
            } else {
                $this->safeExec(sprintf('asterisk -rx %s', escapeshellarg("database del QCALLBACK/$queue_id announce_id")));
            }
            if (!empty($config['return_message_id'])) {
                $this->safeExec(sprintf('asterisk -rx %s', escapeshellarg("database put QCALLBACK/$queue_id return_message_id {$config['return_message_id']}")));
            } else {
                $this->safeExec(sprintf('asterisk -rx %s', escapeshellarg("database del QCALLBACK/$queue_id return_message_id")));
            }
        } catch (\Throwable $e) {}
    }

    private function isValidKey($key): bool {
        return (is_string($key) && strlen($key) === 1 && preg_match('/^[0-9*#]$/', $key));
    }

    /* -------------------------
     * File generation
     * -------------------------*/
    private function generateQueuesPostCustom(array $configs): void {
        $path = '/etc/asterisk/queues_post_custom.conf';
        $existing = file_exists($path) ? file_get_contents($path) : '';
        // Remove our previous block
        $existing = preg_replace('/\n; BEGIN QCB AUTO[\s\S]*?; END QCB AUTO\n/s', "\n", $existing);

        $buf = "\n; BEGIN QCB AUTO (generated ".date('Y-m-d H:i:s').")\n";
        foreach ($configs as $c) {
            $qid = $c['queue_id'];
            $ann = $c['announce_file'] ?: '';
            $freqSec = max(1, (int)($c['announce_frequency'] ?? 1)) * 60;

            $buf .= "[$qid](+)\n";
            $buf .= "context=queuecallback-$qid\n";
            if ($ann !== '') {
                $buf .= "periodic-announce=$ann\n";
                $buf .= "periodic-announce-frequency=$freqSec\n";
            }
            $buf .= "\n";
        }
        $buf .= "; END QCB AUTO\n";

        file_put_contents($path, rtrim($existing) . $buf . "\n");
        @chown($path, 'asterisk'); @chgrp($path, 'asterisk'); @chmod($path, 0664);

        freepbx_log(FPBX_LOG_INFO, "Queue Callback: wrote ".count($configs)." queue sections to queues_post_custom.conf");
    }

    private function generateExtensionsHandlers(array $configs): void {
        $path = '/etc/asterisk/extensions_custom.conf';
        $content = file_exists($path) ? file_get_contents($path) : '';

        // Clear our per-queue contexts
        foreach ($configs as $c) {
            $qid = $c['queue_id'];
            $content = preg_replace('/\n\[queuecallback-'.preg_quote($qid,'/').'\][\s\S]*?(?=\n\[|\z)/', '', $content);
        }
        // Clear our global contexts so we don't duplicate
        $content = preg_replace('/\n\[queuecallback-outbound\][\s\S]*?(?=\n\[|\z)/', '', $content);
        $content = preg_replace('/\n\[queuecallback-agent-outbound\][\s\S]*?(?=\n\[|\z)/', '', $content);

        // Per-queue handler contexts
        foreach ($configs as $c) {
            $qid  = $c['queue_id'];
            $ckey = $c['callback_key']; // already validated/defaulted
            $confirmPrompt = 'custom/confirm_number'; // hardcoded

            $ctx = "queuecallback-$qid";
            $h  = "[$ctx]\n";
            // When gosub is entered without a digit (s), just return to queue
            $h .= "exten => s,1,Return()\n";

            // Initiation key
            $h .= "exten => $ckey,1,NoOp(QCB: queue $qid start key \"$ckey\" pressed by \${CALLERID(all)})\n";
            $h .= " same => n,Set(CALLBACK_NUMBER=\${CALLERID(num)})\n";
            $h .= " same => n,Set(CALLBACK_QUEUE=$qid)\n";
            $h .= " same => n,AGI(queuecallback-check.agi,$qid)\n";
            $h .= " same => n,GotoIf(\$[\"\${QUEUE_CALLBACK_ENABLED}\" = \"1\"]?confirm:unavail)\n";
            $h .= " same => n(unavail),Playback(im-sorry)\n";
            $h .= " same => n,Playback(goodbye)\n";
            $h .= " same => n,Hangup()\n";

            // Confirmation (keys 1/2)
            $h .= " same => n(confirm),NoOp(QCB: confirm menu queue $qid)\n";
            $h .= " same => n,SayDigits(\${CALLBACK_NUMBER})\n";
            $h .= " same => n,Background($confirmPrompt)\n";
            $h .= " same => n,Set(TIMEOUT(digit)=5)\n";
            $h .= " same => n,Set(TIMEOUT(response)=10)\n";
            $h .= " same => n,WaitExten(10)\n";

            $h .= "exten => 1,1,NoOp(QCB: confirmed via 1)\n";
            $h .= " same => n,Goto(queuecallback-$qid,store,1)\n";

            $h .= "exten => 2,1,NoOp(QCB: confirmed via 2)\n";
            $h .= " same => n,Goto(queuecallback-$qid,store,1)\n";

            $h .= "exten => t,1,Playback(goodbye)\n";
            $h .= " same => n,Hangup()\n";

            $h .= "exten => i,1,Playback(vm-invalid)\n";
            $h .= " same => n,WaitExten(5)\n";

            // Store and finish
            $h .= "exten => store,1,Set(QCB_CONFIRMED=1)\n";
            $h .= " same => n,Set(QCB_CONFIRM_SOURCE=dtmf)\n";
            $h .= " same => n,AGI(queuecallback-store.agi)\n";
            $h .= " same => n,Set(CHANNEL(language)=en)\n";
            $callbackStartedMsg = $c['callback_started_file'] ?: 'thank-you-for-calling';
            $h .= " same => n,Playback($callbackStartedMsg)\n";
            $h .= " same => n,Hangup()\n\n";

            $content .= "\n".$h;
        }

        // Customer-first outbound leg: use __ vars + flags before Queue()
        $out  = "[queuecallback-outbound]\n";
        $out .= "exten => _X.,1,NoOp(QCB outbound: queue \${EXTEN} num \${CALLERID(num)})\n";
        $out .= " same => n,Set(CHANNEL(language)=en)\n";
        $out .= " same => n,Set(__CALLBACK_QUEUE_ID=\${EXTEN})\n";
        $out .= " same => n,NoOp(QCB using queue: \${EXTEN})\n";
        // CRITICAL flags so FreePBX 'from-queue' agent legs don't auto-hangup
        $out .= " same => n,Set(__FROMQ=true)\n";
        $out .= " same => n,Set(__NODEST=\${EXTEN})\n";
        // optional return message
        $out .= " same => n,ExecIf(\$[\"\${__CALLBACK_RETURN_MSG}\" != \"\"]?Playback(\${__CALLBACK_RETURN_MSG}))\n";
        // enter the queue
        $out .= " same => n,Queue(\${EXTEN},t,,,,,,)\n";
        // mark completion only if truly answered
        $out .= " same => n,NoOp(QCB Queue result: \${QUEUESTATUS})\n";
        $out .= " same => n,GotoIf(\$[\"\${QUEUESTATUS}\" = \"COMPLETECALLER\" | \"\${QUEUESTATUS}\" = \"COMPLETEAGENT\"]?markdone:hang)\n";
        $out .= " same => n(markdone),AGI(queuecallback-complete.agi)\n";
        $out .= " same => n,Hangup()\n";
        $out .= " same => n(hang),Hangup()\n\n";

        // Agent-first helper (inherits the same flags)
        $out .= "[queuecallback-agent-outbound]\n";
        $out .= "exten => s,1,NoOp(QCB agent-first: dialing customer \${__CALLBACK_CUSTOMER_NUM})\n";
        $out .= " same => n,Set(CHANNEL(language)=en)\n";
        $out .= " same => n,Set(__FROMQ=true)\n";
        $out .= " same => n,Set(__NODEST=\${__CALLBACK_QUEUE_ID})\n";
        $out .= " same => n,Dial(Local/\${__CALLBACK_CUSTOMER_NUM}@from-internal,30,t)\n";
        $out .= " same => n,Hangup()\n\n";

        $content = rtrim($content)."\n\n".$out;

        file_put_contents($path, $content);
        @chown($path, 'asterisk'); @chgrp($path, 'asterisk'); @chmod($path, 0664);

        freepbx_log(FPBX_LOG_INFO, "Queue Callback: generated ".count($configs)." handler contexts + outbound helpers in extensions_custom.conf");
    }

    /**
     * Critical: use VQ_GOSUB/QGOSUB so FreePBX passes our handler to Queue().
     * We set both to cover framework variations, then jump back to -additional.
     */
    private function generateExtensionsOverride(array $configs): void {
        $path = '/etc/asterisk/extensions_override_freepbx.conf';
        $ov  = "; Auto-generated by Queue Callback module\n";
        $ov .= "; Attach in-queue DTMF handler via VQ_GOSUB/QGOSUB so FreePBX passes it to Queue()\n";
        $ov .= "; Generated: " . date('Y-m-d H:i:s') . "\n\n";
        $ov .= "[from-internal]\n\n";

        foreach ($configs as $c) {
            $qid = $c['queue_id'];
            $gosub = 'queuecallback-' . $qid . ',s,1';

            $ov .= "; --- Queue $qid Callback Override ---\n";
            $ov .= "exten => $qid,1,Set(VQ_GOSUB=$gosub)\n";
            $ov .= " same => n,Set(QGOSUB=$gosub)\n";
            $ov .= " same => n,Goto(from-internal-additional,$qid,1)\n\n";
        }

        file_put_contents($path, $ov);
        @chown($path, 'asterisk'); @chgrp($path, 'asterisk'); @chmod($path, 0664);

        freepbx_log(FPBX_LOG_INFO, "Queue Callback: wrote VQ_GOSUB/QGOSUB override for ".count($configs)." queues");
    }

    private function ensureQueuesIncludeOrder(): void {
        $queuesConf = '/etc/asterisk/queues.conf';
        if (!file_exists($queuesConf)) {
            freepbx_log(FPBX_LOG_WARNING, "Queue Callback: $queuesConf not found");
            return;
        }
        $content = file_get_contents($queuesConf);
        // Remove duplicates
        $content = preg_replace('/^\s*#include\s+queues_post_custom\.conf\s*$/mi', '', $content);
        $content = rtrim($content)."\n#include queues_post_custom.conf\n";

        $tmp = $queuesConf . '.tmp.' . getmypid();
        file_put_contents($tmp, $content);
        rename($tmp, $queuesConf);

        $this->safeExec('asterisk -rx "module reload app_queue.so"');
    }

    /* -------------------------
     * Cron management (asterisk user only)
     * -------------------------*/
    private function ensureCronIfQueues(): void {
        try {
            $enabled = $this->countEnabledQueues();
            if ($enabled < 1) { $this->removeCronIfNoQueues(); return; }

            $current = @shell_exec('crontab -u asterisk -l 2>/dev/null') ?: '';
            $lines = array_filter(explode("\n", $current), function($l) {
                return (strpos($l, 'intelligent_callback_processor.php') === false)
                    && (strpos($l, '# BEGIN QCALLBACK') === false)
                    && (strpos($l, '# END QCALLBACK') === false);
            });

            $proc = '/var/www/html/admin/modules/qcallback/intelligent_callback_processor.php';
            $block = [];
            $block[] = '# BEGIN QCALLBACK';
            $block[] = '# Queue Callback intelligent processor (every 15s, via PHP CLI)';
            $block[] = "* * * * * /usr/bin/php -q $proc >> /var/log/asterisk/qcallback.log 2>&1";
            $block[] = "* * * * * sleep 15; /usr/bin/php -q $proc >> /var/log/asterisk/qcallback.log 2>&1";
            $block[] = "* * * * * sleep 30; /usr/bin/php -q $proc >> /var/log/asterisk/qcallback.log 2>&1";
            $block[] = "* * * * * sleep 45; /usr/bin/php -q $proc >> /var/log/asterisk/qcallback.log 2>&1";
            $block[] = '# END QCALLBACK';

            $new = rtrim(implode("\n", $lines)."\n\n".implode("\n", $block))."\n";
            file_put_contents('/tmp/new_crontab_qcb', $new);
            @shell_exec('crontab -u asterisk /tmp/new_crontab_qcb');
            @unlink('/tmp/new_crontab_qcb');

            // Detect (but do not edit) system-wide duplicates and warn
            $dups = @shell_exec("grep -R --line-number 'intelligent_callback_processor.php' /etc/crontab /etc/cron.d 2>/dev/null");
            if ($dups) {
                freepbx_log(FPBX_LOG_WARNING, "Queue Callback: processor also referenced in system cron:\n".$dups);
            }

            freepbx_log(FPBX_LOG_INFO, "Queue Callback: ensured asterisk-user cron (enabled queues: $enabled)");
        } catch (\Throwable $e) {
            freepbx_log(FPBX_LOG_ERROR, "Queue Callback: cron ensure failed: ".$e->getMessage());
        }
    }

    private function removeCronIfNoQueues(bool $force = false): void {
        try {
            if (!$force) {
                $enabled = $this->countEnabledQueues();
                if ($enabled > 0) { return; }
            }
            $current = @shell_exec('crontab -u asterisk -l 2>/dev/null') ?: '';
            if ($current === '') { return; }
            $lines = explode("\n", $current);
            $filtered = array_filter($lines, function($l) {
                return (strpos($l, 'intelligent_callback_processor.php') === false)
                    && (strpos($l, '# BEGIN QCALLBACK') === false)
                    && (strpos($l, '# END QCALLBACK') === false);
            });
            $new = rtrim(implode("\n", $filtered))."\n";
            file_put_contents('/tmp/new_crontab_qcb', $new);
            @shell_exec('crontab -u asterisk /tmp/new_crontab_qcb');
            @unlink('/tmp/new_crontab_qcb');

            freepbx_log(FPBX_LOG_INFO, "Queue Callback: removed asterisk-user callback cron (no enabled queues)");
        } catch (\Throwable $e) {
            freepbx_log(FPBX_LOG_ERROR, "Queue Callback: cron remove failed: ".$e->getMessage());
        }
    }

    private function countEnabledQueues(): int {
        try {
            $st = $this->db->query("SELECT COUNT(*) c FROM queuecallback_config WHERE enabled = 1");
            $c = $st->fetch(PDO::FETCH_ASSOC);
            return (int)($c['c'] ?? 0);
        } catch (\Throwable $e) { return 0; }
    }

    /* -------------------------
     * AGIs
     * -------------------------*/
    private function installAgiScripts(): void {
        $agi_scripts = ['queuecallback-store.agi', 'queuecallback-check.agi', 'queuecallback-complete.agi'];
        foreach ($agi_scripts as $s) {
            $src = __DIR__ . '/agi-bin/' . $s;
            $dst = '/var/lib/asterisk/agi-bin/' . $s;
            if (file_exists($src)) {
                @copy($src, $dst);
                @chmod($dst, 0755);
                @chown($dst, 'asterisk'); @chgrp($dst, 'asterisk');
                // Normalize line endings
                $data = file_get_contents($dst);
                if ($data !== false) {
                    $data = str_replace("\r\n", "\n", $data);
                    file_put_contents($dst, $data);
                }
            }
        }
    }

    private function uninstallAgiScripts(): void {
        foreach (['queuecallback-store.agi','queuecallback-check.agi','queuecallback-complete.agi'] as $s) {
            $p = '/var/lib/asterisk/agi-bin/' . $s;
            if (file_exists($p)) { @unlink($p); }
        }
    }

    /* -------------------------
     * Misc UI helpers
     * -------------------------*/
    public function getCallbackEnabledQueues(): array {
        try {
            $st = $this->db->prepare("SELECT qc.*, q.descr as queue_name
                                      FROM queuecallback_config qc
                                      LEFT JOIN queues_config q ON qc.queue_id COLLATE utf8_general_ci = q.extension COLLATE utf8_general_ci
                                      WHERE qc.enabled = 1");
            $st->execute();
            return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            try {
                $st = $this->db->prepare("SELECT * FROM queuecallback_config WHERE enabled = 1");
                $st->execute();
                $res = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
                foreach ($res as &$r) { $r['queue_name'] = 'Queue '.$r['queue_id']; }
                return $res;
            } catch (\Throwable $e2) { return []; }
        }
    }

    public function isCallbackEnabled($queue_id): bool {
        $c = $this->getQueueCallbackConfig($queue_id);
        return !empty($c['enabled']);
    }

    public function getCallbackStats($queue_id): array {
        $sql = "SELECT
                    COUNT(*) total_requests,
                    SUM(CASE WHEN status='pending' THEN 1 ELSE 0 END) pending,
                    SUM(CASE WHEN status='completed' THEN 1 ELSE 0 END) completed,
                    SUM(CASE WHEN status='failed' THEN 1 ELSE 0 END) failed,
                    SUM(CASE WHEN status='cancelled' THEN 1 ELSE 0 END) cancelled
                FROM queuecallback_requests
                WHERE queue_id = ?";
        $st = $this->db->prepare($sql);
        $st->execute([$queue_id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: [
            'total_requests'=>0,'pending'=>0,'completed'=>0,'failed'=>0,'cancelled'=>0
        ];
    }

    /**
     * Get pending callback requests for a specific queue
     * @param string $queue_id The queue ID to get callbacks for (empty for all queues)
     * @return array Array of callback records
     */
    public function getPendingCallbackRequests($queue_id = ''): array {
        try {
            if (empty($queue_id)) {
                // Get all pending callbacks if no queue specified
                $stmt = $this->db->prepare("SELECT * FROM queuecallback_requests WHERE status IN ('pending', 'processing') ORDER BY time_requested ASC");
                $stmt->execute();
            } else {
                // Get callbacks for specific queue
                $stmt = $this->db->prepare("SELECT * FROM queuecallback_requests WHERE queue_id = ? AND status IN ('pending', 'processing') ORDER BY time_requested ASC");
                $stmt->execute([$queue_id]);
            }
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (\Exception $e) {
            error_log("getPendingCallbackRequests error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get all callback requests for a specific queue (including completed/failed)
     * @param string $queue_id The queue ID to get callbacks for
     * @param int $limit Maximum number of records to return
     * @return array Array of callback records
     */
    public function getAllCallbackRequests($queue_id, $limit = 100): array {
        try {
            $stmt = $this->db->prepare("SELECT * FROM queuecallback_requests WHERE queue_id = ? ORDER BY time_requested DESC LIMIT ?");
            $stmt->execute([$queue_id, $limit]);
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (\Exception $e) {
            error_log("getAllCallbackRequests error: " . $e->getMessage());
            return [];
        }
    }



    public function deleteQueueCallbackConfig($queue_id): bool {
        try {
            $this->db->exec("START TRANSACTION");
            $st = $this->db->prepare("DELETE FROM queuecallback_config WHERE queue_id = ?");
            $st->execute([$queue_id]);

            $st = $this->db->prepare("UPDATE queuecallback_requests
                                      SET status='cancelled', time_processed=?
                                      WHERE queue_id=? AND status IN ('pending','processing')");
            $st->execute([time(), $queue_id]);

            $this->db->exec("COMMIT");
            // Regenerate dialplan / cron decisions
            $this->generateCallbackDialplan(true);
            $this->flagNeedReload();
            return true;
        } catch (\Throwable $e) {
            $this->db->exec("ROLLBACK");
            throw $e;
        }
    }

    public function getAllCallbackQueues(): array {
        try {
            $sql = "SELECT qc.*, q.descr as queue_name, q.strategy
                    FROM queuecallback_config qc
                    LEFT JOIN queues_config q ON qc.queue_id COLLATE utf8_general_ci = q.extension COLLATE utf8_general_ci
                    ORDER BY qc.queue_id";
            $st = $this->db->prepare($sql);
            $st->execute();
            return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            try {
                $st = $this->db->prepare("SELECT * FROM queuecallback_config ORDER BY queue_id");
                $st->execute();
                $res = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
                foreach ($res as &$r) {
                    $r['queue_name'] = 'Queue '.$r['queue_id'];
                    $r['strategy']   = 'ringall';
                }
                return $res;
            } catch (\Throwable $e2) { return []; }
        }
    }
}
