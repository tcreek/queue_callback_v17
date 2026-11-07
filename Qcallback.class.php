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
namespace FreePBX\modules;

use BMO;
use FreePBX_Helpers;
use PDO;

class Qcallback extends FreePBX_Helpers implements BMO { // NOTE: keep original class name/namespace

    /** @var \FreePBX */
    protected $FreePBX;

    /** @var \PDO */
    protected $db;

    public const DEFAULT_ANNOUNCEMENTS = [
        'announce_id'         => 'custom/callback-announcement',
        'alt_message_id'      => 'custom/alternate_num_instruct',
        'confirm_message_id'  => 'custom/confirm_number',
        'return_message_id'   => 'custom/callback-return-confirm',
        'initiated_message_id'=> 'custom/callback_initiated',
        'confirm_prompt_id'   => 'custom/callback_confirm',
    ];

    public function __construct($freepbx = null) {
        $this->FreePBX = $freepbx;
        $this->db = $freepbx->Database;
    }

    /* ------------------------------------------------------------------
     * Helpers
     * ------------------------------------------------------------------*/
    private function isCli(): bool {
        return (PHP_SAPI === 'cli');
    }

    private function isWebPost(): bool {
        return (PHP_SAPI !== 'cli') && (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST');
    }

    private function isModulePost(string $module = 'qcallback'): bool {
        return $this->isWebPost() && (($_POST['display'] ?? '') === $module);
    }

    private function flagNeedReload(): void {
        if (function_exists('needreload')) {
            needreload();
        }
        if (isset($this->FreePBX) && method_exists($this->FreePBX, 'needreload')) {
            $this->FreePBX->needreload();
        }
    }

    private function safeExec(string $cmd): void {
        if ($this->isCli()) {
            @exec($cmd);
        }
    }

    private function reloadDialplan(): void {
        if ($this->isCli()) {
            $this->safeExec('asterisk -rx "dialplan reload"');
        } else {
            $this->flagNeedReload();
        }
    }

    /* ------------------------------------------------------------------
     * BMO Required Methods
     * ------------------------------------------------------------------*/
    public function install() {
        $this->installModuleFiles();
        $this->installMenuConfiguration();
        $this->installDatabaseTables();
        $this->refreshCallbackEvents();
        $this->installAgiScripts();
        require_once __DIR__ . '/callback_install.php';
        \qcallback_install_announcements(__DIR__ . '/announcements', '/var/lib/asterisk/sounds/en/custom', array_merge(array_values(self::DEFAULT_ANNOUNCEMENTS), ['custom/failed-to-confirm']));
        $this->updateCronJob();
        $this->installCallbackDialplan();
        $this->flagNeedReload();
    }

    public function uninstall() {
        $this->cleanupCallbackEvents();
        $this->uninstallAgiScripts();
        $this->uninstallCallbackDialplan();
        $this->uninstallMenuConfiguration();
        // FreePBX owns module files; keep them available after Uninstall.
    }

    public function backup() {
        $out = [
            'queuecallback_config'   => [],
            'queuecallback_requests' => []
        ];
        try {
            $stmt = $this->db->query('SELECT * FROM queuecallback_config');
            $out['queuecallback_config'] = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {}

        try {
            $stmt = $this->db->query('SELECT * FROM queuecallback_requests WHERE status != "completed"');
            $out['queuecallback_requests'] = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
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

    /**
     * Called for *every* page load. DO NOT reload/generate here.
     */
    public function doConfigPageInit($page) {
        return;
    }

    /**
     * Optional: render extra UI on queue pages.
     */
    public function showPage() {
        $request = $_REQUEST;
        if (($request['display'] ?? '') === 'queues' && !empty($request['extdisplay'])) {
            $queue_id = $request['extdisplay'];
            $callback_config = $this->getQueueCallbackConfig($queue_id);
            include(__DIR__ . '/views/callback_config.php');
        }
    }

    /* ------------------------------------------------------------------
     * Public API used by your module UI / hooks
     * ------------------------------------------------------------------*/

    public function processIntelligentCallbacks(): void {
        $script = __DIR__ . '/process_callbacks.php';
        if (file_exists($script)) {
            if ($this->isCli()) {
                @exec('/usr/bin/php ' . escapeshellarg($script) . ' 2>&1');
            } else {
                @shell_exec('/usr/bin/php ' . escapeshellarg($script) . ' >/dev/null 2>&1 &');
            }
        }
    }

    /**
     * Generate callback dialplan & queue configs.
     * Only runs in CLI unless $force=true.
     * Set $reload=false when FreePBX is already generating configuration.
     */
    public function generateCallbackDialplan(bool $force = false, bool $reload = true): void {
        if (!$this->isCli() && !$force) {
            return;
        }

        $this->refreshCallbackEvents();
        $configs = $this->getEnabledQueuesWithConfig();
        $this->generateQueuesPostCustomWorking($configs);  // periodic announce
        $this->generateExtensionsCustomWorking($configs);  // handlers + hangup
        $this->generateExtensionsOverride($configs);       // override to strip H + add G()
        $this->ensureQueuesIncludeOrder();
        $this->generateSecurityDialplanFile();

        // FreePBX reloads Asterisk after all modules have generated their files.
        if ($reload) {
            $this->safeExec('asterisk -rx "config reload queues.conf"');
            $this->reloadDialplan();
        }
    }

    /* ------------------------------------------------------------------
     * Data access / config
     * ------------------------------------------------------------------*/
    private function getEnabledQueuesWithConfig(): array {
        try {
            $sql = "SELECT queue_id, announce_id, announce_frequency, callback_key, alt_number_key, confirm_number, confirm_message_id, alt_message_id, return_message_id, initiated_message_id, confirm_prompt_id, outbound_route_id
                    FROM queuecallback_config WHERE enabled = 1";
            $stmt = $this->db->prepare($sql);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $out = [];
            foreach ($rows as $r) {
                $out[] = [
                    'queue_id'             => $r['queue_id'],
                    'breakout_context'     => $this->getQueueBreakoutContext($r['queue_id']),
                    'announce_id'          => $r['announce_id'] ?: self::DEFAULT_ANNOUNCEMENTS['announce_id'],
                    'announce_frequency'   => (int)($r['announce_frequency'] ?? 0),
                    'callback_key'         => (string)($r['callback_key'] ?? '#'),
                    'alt_number_key'       => (string)($r['alt_number_key'] ?? '2'),
                    'outbound_route_id'    => (int)($r['outbound_route_id'] ?? 0),
                    'confirm_number'       => isset($r['confirm_number']) ? (int)$r['confirm_number'] : 1,
                    'announce_file'        => $this->resolveOrDefault($r['announce_id'], 'announce_id'),
                    'confirm_message_file' => $this->resolveOrDefault($r['confirm_message_id'] ?? null, 'confirm_message_id'),
                    'alt_message_file'     => $this->resolveOrDefault($r['alt_message_id'] ?? null, 'alt_message_id'),
                    'return_message_file'  => $this->resolveOrDefault($r['return_message_id'] ?? null, 'return_message_id'),
                    'initiated_message_file' => $this->resolveOrDefault($r['initiated_message_id'] ?? null, 'initiated_message_id'),
                    'confirm_prompt_file'    => $this->resolveOrDefault($r['confirm_prompt_id'] ?? null, 'confirm_prompt_id'),
                ];
            }
            return $out;
        } catch (\Throwable $e) {
            // A failed query must not be mistaken for all queues disabled.
            throw $e;
        }
    }

    private function getQueueBreakoutContext($queue_id): string {
        $stmt = $this->db->prepare("SELECT ivr_id FROM queues_config WHERE extension = ?");
        $stmt->execute([$queue_id]);
        $ivrId = (string)$stmt->fetchColumn();
        // FreePBX IVR identifiers are numeric. Never emit arbitrary dialplan text.
        return preg_match('/^[0-9]+$/D', $ivrId) ? 'ivr-' . $ivrId : '';
    }

    private function resolveOrDefault($announce_id, string $field): string {
        if (!empty($announce_id)) {
            $resolved = $this->resolveAnnouncementFile($announce_id);
            if ($resolved !== '' && $resolved !== 'please-hold') {
                return $resolved;
            }
        }
        return self::DEFAULT_ANNOUNCEMENTS[$field] ?? '';
    }

    private function resolveAnnouncementFile($announce_id): string {
        if (empty($announce_id)) {
            return '';
        }
        if (!ctype_digit((string)$announce_id)) {
            return $this->normalizeSoundFile((string)$announce_id);
        }
        try {
            $tables = $this->db->query("SHOW TABLES LIKE 'recordings_details'")->fetchAll();
            if (!empty($tables)) {
                $stmt = $this->db->prepare("SELECT filename FROM recordings_details WHERE id = ? AND language = 'en' LIMIT 1");
                $stmt->execute([$announce_id]);
                $res = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$res) {
                    $stmt = $this->db->prepare("SELECT filename FROM recordings_details WHERE id = ? LIMIT 1");
                    $stmt->execute([$announce_id]);
                    $res = $stmt->fetch(PDO::FETCH_ASSOC);
                }
                if (!empty($res['filename'])) {
                    return $this->normalizeSoundFile($res['filename']);
                }
            }

            $stmt = $this->db->prepare("SELECT filename, displayname FROM recordings WHERE id = ? LIMIT 1");
            $stmt->execute([$announce_id]);
            $res = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($res) {
                if (!empty($res['filename'])) {
                    return $this->normalizeSoundFile($res['filename']);
                }
                if (!empty($res['displayname'])) {
                    $map = [
                        'callback announcement'   => 'please-hold',
                        'queue announcement'      => 'please-hold',
                        'please hold'             => 'please-hold',
                        'thank you for calling'   => 'thank-you-for-calling',
                    ];
                    $dn = strtolower($res['displayname']);
                    return $map[$dn] ?? 'please-hold';
                }
            }

            $stmt = $this->db->prepare("SELECT filename FROM soundlang WHERE id = ? AND language = 'en' LIMIT 1");
            $stmt->execute([$announce_id]);
            $res = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$res) {
                $stmt = $this->db->prepare("SELECT filename FROM soundlang WHERE id = ? LIMIT 1");
                $stmt->execute([$announce_id]);
                $res = $stmt->fetch(PDO::FETCH_ASSOC);
            }
            if (!empty($res['filename'])) {
                return $this->normalizeSoundFile($res['filename']);
            }
        } catch (\Throwable $e) {}

        return 'please-hold';
    }

    public function resolveReturnMessage($value): string {
        return $this->resolveOrDefault($value, 'return_message_id');
    }

    private function normalizeSoundFile(string $filename): string {
        $f = trim($filename);
        $f = ltrim($f, '/');
        $f = preg_replace('#^var/lib/asterisk/sounds/#i', '', $f);
        $f = preg_replace('#^asterisk/sounds/#i', '', $f);
        if (preg_match('#^[a-z]{2}/#i', $f)) {
            $f = substr($f, 3);
        }
        $f = preg_replace('/\.(wav|ulaw|alaw|gsm|sln|sln16|g722|mp3)$/i', '', $f);
        return $f ?: 'please-hold';
    }

    public function getQueueCallbackConfig($queue_id) {
        try {
            $stmt = $this->db->prepare("SELECT * FROM queuecallback_config WHERE queue_id = ?");
            $stmt->execute([$queue_id]);
            $res = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($res) {
                return $res;
            }
        } catch (\Throwable $e) {}
        return [
            'queue_id'            => $queue_id,
            'enabled'             => 0,
            'announce_id'         => null,
            'announce_frequency'  => 0,
            'callback_key'        => '#',
            'processing_interval' => 30,
            'max_attempts'        => 3,
            'retry_interval'      => 60,
            'return_message_id'   => null,
            'return_confirm_timeout' => 10,
            'return_confirm_repeats' => 1,
            'confirm_message_id'  => null,
            'confirm_number'      => 1,
            'alt_number_key'      => '2',
            'alt_message_id'      => null,
            'initiated_message_id'=> null,
            'confirm_prompt_id'   => null,
            'call_first'          => 'customer',
            'outbound_route_id'   => 0
        ];
    }

    public function setQueueCallbackConfig($queue_id, $config) {
        if (!$this->validateQueue($queue_id)) {
            throw new \Exception("Queue $queue_id does not exist");
        }

        require_once(__DIR__ . '/callback_settings.php');
        // Legacy forms submit only a subset; preserve saved routing and menus.
        $config = \qcallback_validate_settings($this->db,
            array_replace($this->getQueueCallbackConfig($queue_id), $config));

        $sql = "INSERT INTO queuecallback_config
                (queue_id, enabled, announce_id, announce_frequency, callback_key, processing_interval, max_attempts, retry_interval, return_message_id, confirm_message_id, confirm_number, alt_number_key, alt_message_id, initiated_message_id, confirm_prompt_id, call_first, outbound_route_id, return_confirm_timeout, return_confirm_repeats)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
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
                confirm_number=VALUES(confirm_number),
                alt_number_key=VALUES(alt_number_key),
                alt_message_id=VALUES(alt_message_id),
                initiated_message_id=VALUES(initiated_message_id),
                confirm_prompt_id=VALUES(confirm_prompt_id),
                call_first=VALUES(call_first),
                outbound_route_id=VALUES(outbound_route_id),
                return_confirm_timeout=VALUES(return_confirm_timeout),
                return_confirm_repeats=VALUES(return_confirm_repeats)";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            $queue_id,
            $config['enabled'] ?? 0,
            $config['announce_id'] ?? null,
            max(0, min(15, (int)($config['announce_frequency'] ?? 0))),
            $config['callback_key'] ?? '#',
            $config['processing_interval'] ?? 30,
            $config['max_attempts'] ?? 3,
            $config['retry_interval'] ?? 60,
            $config['return_message_id'] ?? null,
            $config['confirm_message_id'] ?? null,
            $config['confirm_number'] ?? 1,
            $config['alt_number_key'] ?? '2',
            $config['alt_message_id'] ?? null,
            $config['initiated_message_id'] ?? null,
            $config['confirm_prompt_id'] ?? null,
            $config['call_first'] ?? 'customer',
            $config['outbound_route_id'] ?? 0,
            $config['return_confirm_timeout'],
            $config['return_confirm_repeats']
        ]);
        $isNewConfig = $stmt->rowCount() === 1;

        $this->syncConfigToAsteriskDB($queue_id, $config);

        if ($isNewConfig || ($config['enabled'] ?? 0)) {
            $this->populateDefaultSecurityEntries($queue_id);
        }

        $this->refreshCallbackEvents();
        $this->updateCronJob();
        $this->ensureCronJobExists();

        $this->generateCallbackDialplan(true);

        $this->flagNeedReload();

        $action = ($config['enabled'] ?? 0) ? 'enabled' : 'disabled';
        freepbx_log(FPBX_LOG_INFO, "Queue Callback: $action callback for queue $queue_id");
    }

    private function populateDefaultSecurityEntries(string $queue_id): void {
        require_once __DIR__ . '/callback_install.php';
        \qcallback_seed_security_entries($this->db, $queue_id);
    }

    private function validateQueue($queue_id): bool {
        $stmt = $this->db->prepare("SELECT extension FROM queues_config WHERE extension = ?");
        $stmt->execute([$queue_id]);
        return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    }

    private function syncConfigToAsteriskDB($queue_id, $config): void {
        try {
            $ak = $config['callback_key'] ?? '#';
            $this->safeExec(sprintf('asterisk -rx %s', escapeshellarg("database put QCALLBACK/$queue_id callback_key $ak")));

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
        } catch (\Throwable $e) {
            // Ignore failures on web requests
        }
    }

    /* ------------------------------------------------------------------
     * File generation (called by generateCallbackDialplan())
     * ------------------------------------------------------------------*/

    /** Legacy values are minutes; zero selects the new 30-second default. */
    public static function announcementFrequencySeconds($frequency): int {
        $minutes = max(0, min(15, (int)$frequency));
        return $minutes === 0 ? 30 : $minutes * 60;
    }

    private function generateQueuesPostCustomWorking(array $configs): void {
        $qpcPath = '/etc/asterisk/queues_post_custom.conf';
        $current = file_exists($qpcPath) ? file_get_contents($qpcPath) : '';
        $current = preg_replace('/; Auto: queue callback context.*$/s', '', $current);
        // Replace both current and legacy blocks without retaining stale queue settings.
        $current = preg_replace('/^; BEGIN QCB(?:_DB)? AUTO[^\r\n]*\R[\s\S]*?^; END QCB(?:_DB)? AUTO[^\r\n]*(?:\R|$)/m', '', $current);

        $buf = "\n; BEGIN QCB_DB AUTO (generated " . date('Y-m-d H:i:s') . ")\n";
        $freqSecMap = [];
        foreach ($configs as $c) {
            $qid = $c['queue_id'];
            $ann = $c['announce_file'];
            $freqSec = self::announcementFrequencySeconds($c['announce_frequency'] ?? 0);
            $freqSecMap[$qid] = $freqSec;

            $buf .= "[$qid](+)\n";
            $buf .= "context=queuecallback-exit-$qid\n";
            if (!empty($ann)) {
                // Asterisk defaults this to no, suppressing announcements for
                // callers at the head of the queue (including a lone caller).
                $buf .= "announce-to-first-user=yes\n";
                $buf .= "periodic-announce=$ann\n";
                $buf .= "periodic-announce-startdelay=$freqSec\n";
                $buf .= "periodic-announce-frequency=$freqSec\n";
            }
            $buf .= "\n";
        }
        $buf .= "; END QCB_DB AUTO\n";

        $tmp = '/tmp/queues_post_custom_' . getmypid() . '.tmp';
        file_put_contents($tmp, rtrim($current) . $buf);
        copy($tmp, $qpcPath);
        @unlink($tmp);
        @chown($qpcPath, 'asterisk'); @chgrp($qpcPath, 'asterisk'); @chmod($qpcPath, 0664);

        freepbx_log(FPBX_LOG_INFO, "Queue Callback: Wrote " . count($configs) . " queue sections to $qpcPath");
    }

    /**
     * UPDATED: confirm prompt uses Read with n option so DTMF during audio is captured.
     * Also adds TIMEOUTs and t/i handlers for better UX.
     */
    private function generateExtensionsCustomWorking(array $configs): void {
        $ecPath = '/etc/asterisk/extensions_custom.conf';
        $content = file_exists($ecPath) ? file_get_contents($ecPath) : '';

        require_once __DIR__ . '/callback_dialplan.php';
        require_once __DIR__ . '/callback_settings.php';
        $content = \qcallback_render_extensions($content, $configs,
            \qcallback_selected_route_contexts($this->db, $configs));

        $tmp = '/tmp/extensions_custom_' . getmypid() . '.tmp';
        file_put_contents($tmp, $content);
        copy($tmp, $ecPath);
        @unlink($tmp);
        @chown($ecPath, 'asterisk'); @chgrp($ecPath, 'asterisk'); @chmod($ecPath, 0664);

        freepbx_log(FPBX_LOG_INFO, "Queue Callback: Generated handler contexts in $ecPath (Read n-option confirm)");
    }

    private function isValidKey($key): bool {
        return (is_string($key) && strlen($key) === 1 && preg_match('/^[0-9*#]$/', $key));
    }

    /**
     * CRITICAL: Modify QOPTIONS before FreePBX calls Queue(): strip 'H' and
     * add G(<context>,s,1) so our handler context runs and DTMF flows to us.
     * (Use single-quoted PHP strings to avoid escaping issues.)
     */
    private function generateExtensionsOverride(array $configs): void {
        $overridePath = '/etc/asterisk/extensions_override_freepbx.conf';
        
        $ov  = "; Auto-generated by Queue Callback module\n";
        $ov .= "; This file modifies queue options to enable callback features.\n";
        $ov .= "; Generated: " . date('Y-m-d H:i:s') . "\n\n";
        $ov .= "[from-internal]\n\n";

        foreach ($configs as $c) {
            $qid = $c['queue_id'];
            $gosub_context = 'queuecallback-' . $qid;

            $ov .= "; --- Queue $qid Callback Override ---\n";
            // Set QGOSUB to our handler for pre-connection DTMF check
            $ov .= "exten => $qid,1,Set(__QGOSUB=$gosub_context,s,1)\n";
            // This reports intended configuration, not confirmation of Queue() playback.
            $announcement = str_replace(["\r", "\n", ',', '(', ')', '$', ';'], '_', (string) $c['announce_file']);
            $frequency = self::announcementFrequencySeconds($c['announce_frequency'] ?? 0);
            $ov .= " same => n,NoOp(QCB periodic announcement configured: queue=$qid file=$announcement interval=$frequency seconds language=\${CHANNEL(language)} channel=\${CHANNEL} uniqueid=\${UNIQUEID})\n";
            $ov .= " same => n,Goto(from-internal-additional,$qid,1)\n\n";
        }

        $tmp = '/tmp/extensions_override_' . getmypid() . '.tmp';
        file_put_contents($tmp, $ov);
        copy($tmp, $overridePath);
        @unlink($tmp);
        @chown($overridePath, 'asterisk'); @chgrp($overridePath, 'asterisk'); @chmod($overridePath, 0664);

        freepbx_log(FPBX_LOG_INFO, "Queue Callback: Wrote QOPTIONS overrides to extensions_override_freepbx.conf for " . count($configs) . " queues");
    }

    private function ensureQueuesIncludeOrder(): void {
        $queuesConf = '/etc/asterisk/queues.conf';
        if (!file_exists($queuesConf)) {
            freepbx_log(FPBX_LOG_WARNING, "Queue Callback: $queuesConf not found");
            return;
        }
        $content = file_get_contents($queuesConf);
        
        // Remove any existing queues_post_custom.conf includes
        $content = preg_replace('/^\s*#include\s+queues_post_custom\.conf\s*$/mi', '', $content);
        
        // Ensure it's the very last include
        $content = rtrim($content) . "\n#include queues_post_custom.conf\n";

        $tmp = $queuesConf . '.tmp.' . getmypid();
        file_put_contents($tmp, $content);
        rename($tmp, $queuesConf);
        
        freepbx_log(FPBX_LOG_INFO, "Queue Callback: Ensured queues_post_custom.conf is last in $queuesConf");
    }

    /* ------------------------------------------------------------------
     * ------------------------------------------------------------------*/
    private function installDatabaseTables(): void {
        require_once __DIR__ . '/callback_install.php';
        \qcallback_install_schema($this->db);
        if ((int)$this->db->query('SELECT COUNT(*) FROM queuecallback_security')->fetchColumn() === 0) {
            \qcallback_seed_security_entries($this->db, '');
        }
    }

    public function refreshCallbackEvents(): void {
        try {
            $stmt = $this->db->prepare("SELECT MIN(processing_interval) AS min_interval FROM queuecallback_config WHERE enabled = 1");
            $stmt->execute();
            $res = $stmt->fetch(PDO::FETCH_ASSOC);
            $interval = (int)($res['min_interval'] ?? 0);

            $this->db->exec("DROP EVENT IF EXISTS queuecallback_processor");
            $this->db->exec("DROP EVENT IF EXISTS queuecallback_trigger");

            if ($interval > 0) {
                require_once(__DIR__ . '/callback_lifecycle.php');
                $sql = \qcallback_trigger_event_sql($interval);
                $this->db->exec($sql);
            }
        } catch (\Throwable $e) {
            error_log("Queue Callback: Failed to create database events: " . $e->getMessage());
        }
    }

    private function cleanupCallbackEvents(): void {
        try {
            $this->db->exec("DROP EVENT IF EXISTS queuecallback_processor");
            $this->db->exec("DROP EVENT IF EXISTS queuecallback_trigger");
        } catch (\Throwable $e) {}
    }

    private function updateCronJob(): void {
        require_once __DIR__ . '/callback_install.php';
        $current = @shell_exec('crontab -l 2>/dev/null') ?: '';
        $new = \qcallback_processor_crontab($current, __DIR__ . '/process_callbacks.php');
        $tmp = tempnam(sys_get_temp_dir(), 'qcb-cron-');
        if ($tmp === false) { throw new \RuntimeException('Cannot stage callback crontab'); }
        try {
            if (file_put_contents($tmp, $new) !== strlen($new)) { throw new \RuntimeException('Cannot write callback crontab'); }
            exec('crontab ' . escapeshellarg($tmp) . ' 2>&1', $output, $status);
            if ($status !== 0) { throw new \RuntimeException('Cannot install callback crontab'); }
        } finally { unlink($tmp); }
    }

    private function ensureCronJobExists(): void {
        $script = __DIR__ . '/process_callbacks.php';
        $current = @shell_exec('crontab -l 2>/dev/null') ?: '';
        if (strpos($current, 'process_callbacks.php') !== false
            || strpos($current, 'intelligent_callback_processor.php') !== false) {
            return;
        }

        $this->updateCronJob();
    }

    /* ------------------------------------------------------------------
     * AGIs / Files
     * ------------------------------------------------------------------*/
    private function installAgiScripts(): void {
        require_once __DIR__ . '/callback_install.php';
        \qcallback_install_agis(__DIR__ . '/agi-bin', '/var/lib/asterisk/agi-bin', __DIR__);
    }

    private function uninstallAgiScripts(): void {
        foreach (['queuecallback-store.agi', 'queuecallback-check.agi', 'queuecallback-complete.agi', 'queuecallback-result.agi'] as $s) {
            $dst = '/var/lib/asterisk/agi-bin/' . $s;
            if (file_exists($dst)) { @unlink($dst); }
        }
    }

    private function installCallbackDialplan(): void {
        // Installation and Apply Config use exactly the same context renderer.
        $this->generateExtensionsCustomWorking($this->getEnabledQueuesWithConfig());
    }

    private function uninstallCallbackDialplan(): void {
        $custom_file = '/etc/asterisk/extensions_custom.conf';
        if (file_exists($custom_file)) {
            $c = file_get_contents($custom_file);
            $c = preg_replace('/; Queue Callback Custom Dialplan.*?\n\n/s', '', $c);
            $c = preg_replace('/\[queuecallback-handler-fixed\].*?\n\n/s', '', $c);
            $c = preg_replace('/\[from-internal-custom\].*?exten => \d+,.*?Hangup\(\)\n\n/s', '', $c);
            file_put_contents($custom_file, $c, LOCK_EX);
            $this->reloadDialplan();
        }
    }

    private function installModuleFiles(): void {
        $module_dir = __DIR__;
        $dest_dir = '/var/www/html/admin/modules/qcallback';
        // FreePBX normally invokes install() from the already installed module.
        // Leave those files and their framework-managed permissions untouched.
        if (realpath($module_dir) === realpath($dest_dir)) {
            return;
        }
        if (!is_dir($dest_dir)) { mkdir($dest_dir, 0755, true); }

        $files = [
            'Qcallback.class.php',
            'functions.inc.php',
            'freepbx_menu.conf',
            'hooks.php',
            'install.php',
            'intelligent_callback_processor.php',
            'module.xml',
            'README.md',
            'RELEASE_NOTES.md',
            'LICENSE',
            'page.qcallback.php',
            'page.qcallback_reports.php',
            'page.qcallback_security.php',
            'page.qcallback_tab.php',
            'process_callbacks.php',
            'callback_spool.php',
            'callback_lifecycle.php',
            'callback_dialplan.php',
            'callback_settings.php',
            'callback_requests.php',
            'callback_queue.php',
            'callback_dispatch.php',
            'callback_security.php',
            'callback_install.php',
            'uninstall.php',
        ];
        foreach ($files as $f) {
            $src = $module_dir . '/' . $f;
            $dst = $dest_dir . '/' . $f;
            if (file_exists($src)) {
                copy($src, $dst);
                chmod($dst, 0644);
                @chown($dst, 'asterisk'); @chgrp($dst, 'asterisk');
            }
        }
        foreach (['agi-bin','views','announcements'] as $d) {
            $this->copyDirectory($module_dir . '/' . $d, $dest_dir . '/' . $d);
        }
    }

    private function installMenuConfiguration(): void {
        $src = __DIR__ . '/freepbx_menu.conf';
        $dst = '/etc/asterisk/freepbx_menu.conf';
        if (!is_readable($src)) {
            return;
        }

        try {
            $moduleConfig = file_get_contents($src);
            $existing = is_readable($dst) ? file_get_contents($dst) : '';
            $existing = preg_replace(
                '/^\s*\[(?:qcallback|qcallback_reports|qcallback_security)\][^\[]*?(?=^\s*\[|\z)/mi',
                '',
                $existing
            ) ?? '';
            $content = trim($existing);
            if ($content !== '') {
                $content .= "\n\n";
            }
            $content .= trim($moduleConfig) . "\n";

            if (!is_dir(dirname($dst))) {
                mkdir(dirname($dst), 0755, true);
            }
            if (file_put_contents($dst, $content, LOCK_EX) === false) {
                freepbx_log(FPBX_LOG_WARNING, 'Queue Callback: Could not write freepbx_menu.conf');
                return;
            }
            @chown($dst, 'asterisk');
            @chgrp($dst, 'asterisk');
            @chmod($dst, 0664);
        } catch (\Throwable $e) {
            freepbx_log(FPBX_LOG_WARNING, 'Queue Callback: Could not install freepbx_menu.conf: ' . $e->getMessage());
        }
    }

    private function uninstallMenuConfiguration(): void {
        $dst = '/etc/asterisk/freepbx_menu.conf';
        if (!is_readable($dst)) {
            return;
        }

        try {
            $content = file_get_contents($dst);
            $content = preg_replace(
                '/^\s*\[(?:qcallback|qcallback_reports|qcallback_security)\][^\[]*?(?=^\s*\[|\z)/mi',
                '',
                $content
            ) ?? '';
            $content = trim(preg_replace('/\n{3,}/', "\n\n", $content));
            if ($content === '') {
                @unlink($dst);
                return;
            }
            file_put_contents($dst, $content . "\n", LOCK_EX);
            @chown($dst, 'asterisk');
            @chgrp($dst, 'asterisk');
            @chmod($dst, 0664);
        } catch (\Throwable $e) {
            freepbx_log(FPBX_LOG_WARNING, 'Queue Callback: Could not remove freepbx_menu.conf entries: ' . $e->getMessage());
        }
    }

    private function uninstallModuleFiles(): void {
        $dest_dir = '/var/www/html/admin/modules/qcallback';
        $this->removeDirectory($dest_dir);
    }

    private function copyDirectory($src, $dst): void {
        if (!is_dir($src)) { return; }
        // Includes symlink aliases of the same directory.
        if (realpath($src) === realpath($dst)) { return; }
        if (!is_dir($dst)) { mkdir($dst, 0755, true); }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($src, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($it as $item) {
            $path = $dst . DIRECTORY_SEPARATOR . $it->getSubPathName();
            if ($item->isDir()) {
                if (!is_dir($path)) { mkdir($path, 0755, true); }
            } else {
                copy($item, $path);
                chmod($path, 0644);
                @chown($path, 'asterisk'); @chgrp($path, 'asterisk');
            }
        }
    }

    private function removeDirectory($dir): void {
        if (!is_dir($dir)) { return; }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $item) {
            $item->isDir() ? @rmdir($item) : @unlink($item);
        }
        @rmdir($dir);
    }

    /* ------------------------------------------------------------------
     * Misc API
     * ------------------------------------------------------------------*/
    public function getCallbackEnabledQueues(): array {
        try {
            $sql = "SELECT qc.*, q.descr as queue_name
                    FROM queuecallback_config qc
                    LEFT JOIN queues_config q ON qc.queue_id COLLATE utf8_general_ci = q.extension COLLATE utf8_general_ci
                    WHERE qc.enabled = 1";
            $st = $this->db->prepare($sql);
            $st->execute();
            return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            try {
                $st = $this->db->prepare("SELECT * FROM queuecallback_config WHERE enabled = 1");
                $st->execute();
                $res = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
                foreach ($res as &$r) { $r['queue_name'] = "Queue " . $r['queue_id']; }
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
            $this->refreshCallbackEvents();
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
                    $r['queue_name'] = "Queue " . $r['queue_id'];
                    $r['strategy']   = 'ringall';
                }
                return $res;
            } catch (\Throwable $e2) { return []; }
        }
    }

    public function getSecurityEntries(?string $queue_id = null): array {
        try {
            if ($queue_id !== null) {
                $stmt = $this->db->prepare("SELECT * FROM queuecallback_security WHERE (queue_id = ? OR queue_id = '') ORDER BY sort_order ASC, id ASC");
                $stmt->execute([$queue_id]);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                if (!empty($rows)) {
                    return $rows;
                }
                return $this->getGlobalSecurityEntries();
            }
            $stmt = $this->db->query("SELECT * FROM queuecallback_security ORDER BY sort_order ASC, id ASC");
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function getGlobalSecurityEntries(): array {
        try {
            $stmt = $this->db->query("SELECT * FROM queuecallback_security WHERE queue_id = '' ORDER BY sort_order ASC, id ASC");
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    public function getSecurityEntry(int $id): ?array {
        try {
            $stmt = $this->db->prepare("SELECT * FROM queuecallback_security WHERE id = ?");
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function addSecurityEntry(array $data): int {
        require_once __DIR__ . '/callback_security.php';
        $data = \qcallback_validate_security_entry($data);
        $sql = "INSERT INTO queuecallback_security (pattern, description, area_code, queue_id, enabled, sort_order, created_at) VALUES (?,?,?,?,?,?,?)";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            $data['pattern'] ?? '',
            $data['description'] ?? '',
            $data['area_code'] ?? '',
            $data['queue_id'] ?? '',
            isset($data['enabled']) ? (int)$data['enabled'] : 1,
            (int)($data['sort_order'] ?? 0),
            time()
        ]);
        $id = (int)$this->db->lastInsertId();
        $this->flagNeedReload();
        return $id;
    }

    public function updateSecurityEntry(int $id, array $data): bool {
        require_once __DIR__ . '/callback_security.php';
        // Partial edits must retain their original queue/global scope.
        if (!array_key_exists('queue_id', $data)) {
            $existing = $this->getSecurityEntry($id);
            if (!$existing) { throw new \InvalidArgumentException('Blocklist entry not found'); }
            $data['queue_id'] = (string)$existing['queue_id'];
        }
        $data = \qcallback_validate_security_entry($data);
        $sql = "UPDATE queuecallback_security SET pattern=?, description=?, area_code=?, queue_id=?, enabled=?, sort_order=? WHERE id=?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            $data['pattern'] ?? '',
            $data['description'] ?? '',
            $data['area_code'] ?? '',
            $data['queue_id'] ?? '',
            isset($data['enabled']) ? (int)$data['enabled'] : 0,
            (int)($data['sort_order'] ?? 0),
            $id
        ]);
        $this->flagNeedReload();
        return true;
    }

    public function deleteSecurityEntry(int $id): bool {
        $stmt = $this->db->prepare("DELETE FROM queuecallback_security WHERE id = ?");
        $stmt->execute([$id]);
        $this->flagNeedReload();
        return true;
    }

    public function toggleSecurityEntry(int $id, bool $enabled): bool {
        if ($enabled) {
            require_once __DIR__ . '/callback_security.php';
            $entry = $this->getSecurityEntry($id);
            if (!$entry) { throw new \InvalidArgumentException('Blocklist entry not found'); }
            \qcallback_validate_security_entry($entry);
        }
        $stmt = $this->db->prepare("UPDATE queuecallback_security SET enabled = ?, updated_at = ? WHERE id = ?");
        $stmt->execute([(int)$enabled, time(), $id]);
        $this->flagNeedReload();
        return true;
    }

    public function getSecurityBlocklist(?string $queue_id = null): array {
        try {
            if ($queue_id !== null) {
                $stmt = $this->db->prepare("SELECT pattern, description FROM queuecallback_security WHERE enabled = 1 AND (queue_id = ? OR queue_id = '') ORDER BY sort_order ASC");
                $stmt->execute([$queue_id]);
            } else {
                $stmt = $this->db->query("SELECT pattern, description FROM queuecallback_security WHERE enabled = 1 AND queue_id = '' ORDER BY sort_order ASC");
            }
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    public function generateSecurityDialplan(): string {
        require_once __DIR__ . '/callback_security.php';
        $entries = $this->db->query("SELECT * FROM queuecallback_security ORDER BY sort_order, id");
        if (!$entries) { throw new \RuntimeException('Cannot read callback blocklist'); }
        return \qcallback_security_dialplan($entries->fetchAll(PDO::FETCH_ASSOC));
    }

    public function isNumberBlocked(string $number, ?string $queue_id = null): bool {
        require_once __DIR__ . '/callback_security.php';
        return \qcallback_security_blocks_number($this->db, $number, $queue_id);
    }

    /** Remove current and legacy security contexts, even if wrapper markers were lost. */
    private function removeSecurityDialplanSections(string $content): string {
        $content = preg_replace(
            '/^[ \t]*\[qcallback-security-block(?:-[0-9]+)?\][^\r\n]*(?:\R|$)[\s\S]*?(?=^[ \t]*\[|\z)/mi',
            '',
            $content
        );
        // Remove only our own markers, including orphaned markers from old reloads.
        return preg_replace(
            '/^[ \t]*;(?: BEGIN QCB_SECURITY BLOCKLIST| END QCB_SECURITY BLOCKLIST| Auto-generated by Queue Callback - Security Blocklist)[^\r\n]*(?:\R|$)/mi',
            '',
            $content
        );
    }

    public function generateSecurityDialplanFile(): void {
        $ecPath = '/etc/asterisk/extensions_custom.conf';
        $content = file_exists($ecPath) ? file_get_contents($ecPath) : '';

        $buf = $this->generateSecurityDialplan();
        $content = $this->removeSecurityDialplanSections($content);
        if (empty($buf)) {
            file_put_contents($ecPath, $content);
            return;
        }

        $section = "\n; BEGIN QCB_SECURITY BLOCKLIST\n";
        $section .= $buf;
        $section .= "; END QCB_SECURITY BLOCKLIST\n";

        $content = rtrim($content) . "\n" . $section;

        $tmp = '/tmp/extensions_custom_' . getmypid() . '.tmp';
        file_put_contents($tmp, $content);
        copy($tmp, $ecPath);
        @unlink($tmp);
        @chown($ecPath, 'asterisk'); @chgrp($ecPath, 'asterisk'); @chmod($ecPath, 0664);

        freepbx_log(FPBX_LOG_INFO, "Queue Callback: Generated security blocklist dialplan");
    }

    public function cleanupOrphanedConfigs(): bool {
        $st = $this->db->prepare("DELETE qc FROM queuecallback_config qc LEFT JOIN queues_config q ON qc.queue_id = q.extension WHERE q.extension IS NULL");
        $ok = $st->execute();

        $st = $this->db->prepare("UPDATE queuecallback_requests qr
                                  LEFT JOIN queues_config q ON qr.queue_id = q.extension
                                  SET qr.status='cancelled', qr.time_processed=?
                                  WHERE q.extension IS NULL AND qr.status IN ('pending','processing')");
        $st->execute([time()]);

        return (bool)$ok;
    }

    public function getActionBar($request) {
        $buttons = [];
        if (($request['display'] ?? '') === 'queues' && !empty($request['extdisplay'])) {
            $queue_id = $request['extdisplay'];
            $buttons[] = [
                'name'  => 'callback_manage',
                'id'    => 'callback_manage',
                'value' => _('Manage Callbacks'),
                'href'  => '?display=qcallback&view=queue&queue_id=' . urlencode($queue_id),
            ];
        }
        return $buttons;
    }

    /**
     * Disabled: override handles the Gosub/G option injection reliably.
     */
    public function getQueueDialplanHook($queue_id) {
        return [];
    }
}
