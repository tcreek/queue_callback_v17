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
        'return_message_id'   => 'custom/callback_returned',
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
        $this->setupCallbackEvents();
        $this->installAgiScripts();
        $this->installCallbackDialplan();
    }

    public function uninstall() {
        $this->cleanupCallbackEvents();
        $this->uninstallAgiScripts();
        $this->uninstallCallbackDialplan();
        $this->uninstallModuleFiles();
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

    /**
     * Generate callback dialplan & queue configs.
     * Only runs in CLI unless $force=true.
     */
    public function generateCallbackDialplan(bool $force = false): void {
        if (!$this->isCli() && !$force) {
            return;
        }

        $configs = $this->getEnabledQueuesWithConfig();
        if (empty($configs)) {
            return;
        }

        $this->generateQueuesPostCustomWorking($configs);  // periodic announce
        $this->generateExtensionsCustomWorking($configs);  // handlers + hangup
        $this->generateExtensionsOverride($configs);       // override to strip H + add G()
        $this->ensureQueuesIncludeOrder();

        $this->reloadDialplan();
    }

    /* ------------------------------------------------------------------
     * Data access / config
     * ------------------------------------------------------------------*/
    private function getEnabledQueuesWithConfig(): array {
        try {
            $sql = "SELECT queue_id, announce_id, announce_frequency, callback_key, alt_number_key, confirm_number, confirm_message_id, alt_message_id, return_message_id, initiated_message_id, confirm_prompt_id
                    FROM queuecallback_config WHERE enabled = 1";
            $stmt = $this->db->prepare($sql);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $out = [];
            foreach ($rows as $r) {
                $out[] = [
                    'queue_id'             => $r['queue_id'],
                    'announce_id'          => $r['announce_id'] ?: self::DEFAULT_ANNOUNCEMENTS['announce_id'],
                    'announce_frequency'   => (int)($r['announce_frequency'] ?? 1),
                    'callback_key'         => $r['callback_key'] ?: '*',
                    'alt_number_key'       => $r['alt_number_key'] ?: '',
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
            return [];
        }
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
            'announce_frequency'  => 1,
            'callback_key'        => '*',
            'processing_interval' => 30,
            'max_attempts'        => 3,
            'retry_interval'      => 30,
            'return_message_id'   => null,
            'confirm_message_id'  => null,
            'confirm_number'      => 1,
            'alt_number_key'      => '2',
            'alt_message_id'      => null,
            'initiated_message_id'=> null,
            'confirm_prompt_id'   => null,
            'call_first'          => 'customer',
            'outbound_route_id'   => 1
        ];
    }

    public function setQueueCallbackConfig($queue_id, $config) {
        if (!$this->validateQueue($queue_id)) {
            throw new \Exception("Queue $queue_id does not exist");
        }

        $sql = "INSERT INTO queuecallback_config
                (queue_id, enabled, announce_id, announce_frequency, callback_key, processing_interval, max_attempts, retry_interval, return_message_id, confirm_message_id, confirm_number, alt_number_key, alt_message_id, initiated_message_id, confirm_prompt_id, call_first, outbound_route_id)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
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
                outbound_route_id=VALUES(outbound_route_id)";
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
            $config['confirm_number'] ?? 1,
            $config['alt_number_key'] ?? '2',
            $config['alt_message_id'] ?? null,
            $config['initiated_message_id'] ?? null,
            $config['confirm_prompt_id'] ?? null,
            $config['call_first'] ?? 'customer',
            $config['outbound_route_id'] ?? 1
        ]);

        $this->syncConfigToAsteriskDB($queue_id, $config);

        $this->setupCallbackEvents();
        $this->updateCronJob();
        $this->ensureCronJobExists();

        $this->generateCallbackDialplan(true);

        $this->flagNeedReload();

        $action = ($config['enabled'] ?? 0) ? 'enabled' : 'disabled';
        freepbx_log(FPBX_LOG_INFO, "Queue Callback: $action callback for queue $queue_id");
    }

    private function validateQueue($queue_id): bool {
        $stmt = $this->db->prepare("SELECT extension FROM queues_config WHERE extension = ?");
        $stmt->execute([$queue_id]);
        return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    }

    private function syncConfigToAsteriskDB($queue_id, $config): void {
        try {
            $ak = $config['callback_key'] ?? '*';
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

    private function generateQueuesPostCustomWorking(array $configs): void {
        $qpcPath = '/etc/asterisk/queues_post_custom.conf';
        $current = file_exists($qpcPath) ? file_get_contents($qpcPath) : '';
        $current = preg_replace('/; Auto: queue callback context.*$/s', '', $current);
        $current = preg_replace('/\n; BEGIN QCB_DB AUTO[\s\S]*?; END QCB_DB AUTO\n/s', '', $current);

        $buf = "\n; BEGIN QCB_DB AUTO (generated " . date('Y-m-d H:i:s') . ")\n";
        $freqSecMap = [];
        foreach ($configs as $c) {
            $qid = $c['queue_id'];
            $ann = $c['announce_file'];
            $freqMin = (int)($c['announce_frequency'] ?? 1);
            $freqSec = max(1, $freqMin) * 60;
            $freqSecMap[$qid] = $freqSec;

            $buf .= "[$qid](+)\n";
            $buf .= "context=queuecallback-$qid\n";
            if (!empty($ann)) {
                $buf .= "periodic-announce=$ann\n";
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

        foreach ($freqSecMap as $qid => $freqSec) {
            try {
                $this->db->prepare("REPLACE INTO queues_details (id, keyword, data, flags) VALUES (?, 'periodic-announce-frequency', ?, 0)")->execute([$qid, $freqSec]);
            } catch (\Exception $e) {
                freepbx_log(FPBX_LOG_WARNING, "Queue Callback: Could not update periodic-announce-frequency for $qid: " . $e->getMessage());
            }
        }

        freepbx_log(FPBX_LOG_INFO, "Queue Callback: Wrote " . count($configs) . " queue sections to $qpcPath");
    }

    /**
     * UPDATED: confirm prompt uses Read with n option so DTMF during audio is captured.
     * Also adds TIMEOUTs and t/i handlers for better UX.
     */
    private function generateExtensionsCustomWorking(array $configs): void {
        $ecPath = '/etc/asterisk/extensions_custom.conf';
        $content = file_exists($ecPath) ? file_get_contents($ecPath) : '';

        // Remove our managed handler contexts & hangup handler
        foreach ($configs as $c) {
            $qid = $c['queue_id'];
            $content = preg_replace('/\n\[queuecallback-' . preg_quote($qid, '/') . '\][\s\S]*?(?=\n\[|\z)/', '', $content);
            $content = preg_replace('/\n\[qcb-handler-' . preg_quote($qid, '/') . '\][\s\S]*?(?=\n\[|\z)/', '', $content);
        }
        $content = preg_replace('/\n\[qcb-hangup\][\s\S]*?(?=\n\[|\z)/', '', $content);
        $content = preg_replace('/\n\[queuecallback-agent-outbound\][\s\S]*?(?=\n\[|\z)/', '', $content);
        $content = preg_replace('/\n\[qcb-customer-confirm\][\s\S]*?(?=\n\[|\z)/', '', $content);
        $content = preg_replace('/\n\[qcb-complete\][\s\S]*?(?=\n\[|\z)/', '', $content);
        // Remove stale [ext-queues] override with QCALLBACK QUEUE ROUTES markers
        $content = preg_replace('/\n; BEGIN QCALLBACK QUEUE ROUTES.*?; END QCALLBACK QUEUE ROUTES\n/s', '', $content);
        // Remove stale contexts from earlier module versions
        $content = preg_replace('/\n\[queuecallback-handler-fixed\][\s\S]*?(?=\n\[|\z)/', '', $content);

        // Shared hangup handler
        $content .= "\n[qcb-hangup]\n";
        $content .= "exten => s,1,NoOp(QCB hangup handler: Q=\${ARG1} NUM=\${ARG2})\n";
        $content .= " same => n,Set(CALLBACK_QUEUE=\${ARG1})\n";
        $content .= " same => n,Set(CALLBACK_NUMBER=\${ARG2})\n";
        $content .= " same => n,Set(QCB_CONFIRMED=1)\n";
        $content .= " same => n,Set(QCB_CONFIRM_SOURCE=hangup)\n";
        $content .= " same => n,AGI(queuecallback-store.agi)\n";
        $content .= " same => n,Return()\n\n";

        // Build handler contexts
        foreach ($configs as $c) {
            $qid  = $c['queue_id'];
            $ckey = $this->isValidKey($c['callback_key']) ? $c['callback_key'] : '*';
            $alt  = (!empty($c['alt_number_key']) && $this->isValidKey($c['alt_number_key']) && $c['alt_number_key'] !== $ckey) ? $c['alt_number_key'] : '';
            $confirm = (int)$c['confirm_number'];

            $ctx = "queuecallback-$qid";
            $handler  = "[$ctx]\n";
            // Entry point for Queue() gosub: s,1; we can read ${CHANNEL(dtmf-digit)} on Asterisk ≥18
            $handler .= "exten => s,1,NoOp(QCB: DTMF in queue $qid from \${CALLERID(all)} digit=\${IF(\${ISNULL(\${CHANNEL(dtmf-digit)})}?unknown:\${CHANNEL(dtmf-digit)})})\n";
            $handler .= " same => n,Set(QUEUENAME=$qid)\n";
            $handler .= " same => n,Set(CALLBACK_NUMBER=\${CALLERID(num)})\n";
            $handler .= " same => n,GotoIf(\$[\"\${CHANNEL(dtmf-digit)}\" = \"$ckey\"]?$ctx,$ckey,1)\n";
            $handler .= " same => n,Return()\n";

            // Direct-key handlers (test6 structure preserved)
            $handler .= "exten => $ckey,1,NoOp(QCB: $qid caller pressed $ckey)\n";
            $handler .= " same => n,Set(CALLBACK_NUMBER=\${CALLERID(num)})\n";
            $handler .= " same => n,Set(CALLBACK_QUEUE=$qid)\n";
            $handler .= " same => n,AGI(queuecallback-check.agi)\n";
            $handler .= " same => n,GotoIf(\$[\"\${QUEUE_CALLBACK_ENABLED}\" = \"1\"]?confirm,1:unavailable)\n";
            $handler .= " same => n(unavailable),Playback(im-sorry)\n";
            $handler .= " same => n,Playback(goodbye)\n";
            $handler .= " same => n,Hangup()\n";

            $confirmFile = $c['confirm_message_file'] ?: 'beep';
            $confirmPrompt = $c['confirm_prompt_file'] ?: self::DEFAULT_ANNOUNCEMENTS['confirm_prompt_id'];

      // Confirm flow: play number + instruction, then read DTMF
            $handler .= "exten => confirm,1,NoOp(QCB confirm menu for $qid)\n";
            $handler .= " same => n,SayDigits(\${CALLBACK_NUMBER})\n";
            $handler .= " same => n,Read(CONFIRM1,$confirmFile,1,n,5)\n";
            $handler .= " same => n,GotoIf(\$[\"\${CONFIRM1}\" = \"\"]?cancel)\n";
            $handler .= " same => n,GotoIf(\$[\"\${CONFIRM1}\" = \"1\"]?confirm2)\n";
            if ($alt !== '') {
                $handler .= " same => n,GotoIf(\$[\"\${CONFIRM1}\" = \"2\"]?alt,1:invalid)\n";
            } else {
                $handler .= " same => n,Goto(invalid)\n";
            }
            $handler .= " same => n(invalid),Playback(vm-invalid)\n";
            $handler .= " same => n,Goto(confirm,1)\n";
            $handler .= " same => n(confirm2),Wait(1)\n";
            $handler .= " same => n,Read(CONFIRM2,$confirmPrompt,1,n,5)\n";
            $handler .= " same => n,GotoIf(\$[\"\${CONFIRM2}\" = \"\"]?cancel)\n";
            $handler .= " same => n,GotoIf(\$[\"\${CONFIRM2}\" != \"1\"]?confirm,1)\n";
            $handler .= " same => n,Goto(queuecallback-$qid,store,1)\n";
            $handler .= "exten => cancel,1,Playback(goodbye)\n";
            $handler .= " same => n,Set(QCB_CONFIRMED=0)\n";
            $handler .= " same => n,Hangup()\n";

            if ($alt !== '') {
                $handler .= "exten => alt,1,NoOp(QCB alt number entry for $qid)\n";
                $altFile = $c['alt_message_file'];
                if ($altFile !== '') {
                    $handler .= " same => n,Playback($altFile)\n";
                } else {
                    $handler .= " same => n,Playback(please-enter-your)\n";
                    $handler .= " same => n,Playback(at-following-number)\n";
                }
                $handler .= " same => n,Read(ALTNUM,beep,30,,,35)\n";
                $handler .= " same => n,GotoIf(\$[\"\${ALTNUM}\" = \"\"]?alt,1)\n";
                $handler .= " same => n,Set(CALLBACK_NUMBER=\${FILTER(0-9,\${ALTNUM})})\n";
                $handler .= " same => n,Goto(queuecallback-$qid,store,1)\n";
            }

            // Store + finish
            $handler .= "exten => store,1,Set(QCB_CONFIRMED=1)\n";
            $handler .= " same => n,Set(QCB_CONFIRM_SOURCE=dtmf)\n";
            $handler .= " same => n,AGI(queuecallback-store.agi)\n";
            $handler .= " same => n,Playback({$c['initiated_message_file']})\n";
            $handler .= " same => n,Playback(thank-you-for-calling)\n";
            $handler .= " same => n,Playback(goodbye)\n";
            $handler .= " same => n,Hangup()\n\n";

            $content .= $handler;
        }

        // Agent-first outbound callback context
        $content .= "\n[queuecallback-agent-outbound]\n";
        $content .= "exten => s,1,NoOp(QCB: Processing agent-first callback for queue \${CALLBACK_QUEUE_ID})\n";
        $content .= " same => n,Set(CALLERID(name)=Queue Callback)\n";
        $content .= " same => n,Answer()\n";
        $content .= " same => n,Wait(1)\n";
        $content .= " same => n,Playback(you-will-be-connected-to-a-customer)\n";
        $content .= " same => n,Set(__CALLBACK_RETURN_MSG=\${CALLBACK_RETURN_MSG})\n";
        $content .= " same => n,Set(__CALLBACK_CUSTOMER_NUM=\${CALLBACK_CUSTOMER_NUM})\n";
        $content .= " same => n,Dial(\${CALLBACK_CUSTOMER_CHANNEL},30,TtrU(qcb-customer-confirm))\n";
        $content .= " same => n,Hangup()\n\n";

        // Customer confirmation subroutine (agent-first flow)
        // Play Return Call Announcement then connect the call
        $content .= "[qcb-customer-confirm]\n";
        $content .= "exten => s,1,NoOp(QCB: Customer callback confirm for callback \${CALLBACK_ID})\n";
        $content .= " same => n,GotoIf(\$[\"\${CALLBACK_RETURN_MSG}\" != \"\"]?play_return)\n";
        $content .= " same => n,Playback(custom/callback_returned)\n";
        $content .= " same => n,Return()\n";
        $content .= " same => n(play_return),Playback(\${CALLBACK_RETURN_MSG})\n";
        $content .= " same => n,Return()\n\n";

        // Callback completion handler - marks callbacks complete when calls end
        $content .= "[qcb-complete]\n";
        $content .= "exten => s,1,NoOp(QCB completing callback ID \${ARG1})\n";
        $content .= " same => n,AGI(queuecallback-complete.agi,\${ARG1},completed)\n";
        $content .= " same => n,Return()\n\n";

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
        
        // Force reload of queue module to pick up changes
        $this->safeExec('asterisk -rx "module reload app_queue.so"');

        freepbx_log(FPBX_LOG_INFO, "Queue Callback: Ensured queues_post_custom.conf is last in $queuesConf and reloaded queues");
    }

    /* ------------------------------------------------------------------
     * Events / cron
     * ------------------------------------------------------------------*/
    private function setupCallbackEvents(): void {
        try {
            $stmt = $this->db->prepare("SELECT MIN(processing_interval) AS min_interval FROM queuecallback_config WHERE enabled = 1");
            $stmt->execute();
            $res = $stmt->fetch(PDO::FETCH_ASSOC);
            $interval = (int)($res['min_interval'] ?? 0);

            $this->db->exec("DROP EVENT IF EXISTS queuecallback_processor");
            $this->db->exec("DROP EVENT IF EXISTS queuecallback_trigger");

            if ($interval > 0) {
                $sql = "
                CREATE EVENT queuecallback_trigger
                ON SCHEDULE EVERY {$interval} MINUTE
                STARTS CURRENT_TIMESTAMP
                DO
                BEGIN
                    INSERT INTO queuecallback_trigger (last_run)
                    VALUES (UNIX_TIMESTAMP())
                    ON DUPLICATE KEY UPDATE last_run = UNIX_TIMESTAMP();

                    UPDATE queuecallback_requests
                    SET status='pending'
                    WHERE status='processing'
                      AND last_attempt < UNIX_TIMESTAMP() - 3600
                      AND attempts < max_attempts;

                    UPDATE queuecallback_requests
                    SET status='failed'
                    WHERE attempts >= max_attempts
                      AND status IN ('pending','processing');
                END";
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
        try {
            $stmt = $this->db->prepare("SELECT MIN(processing_interval) as min_interval FROM queuecallback_config WHERE enabled = 1");
            $stmt->execute();
            $res = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            $res = ['min_interval' => 1];
        }
        $min = (int)($res['min_interval'] ?? 1);

        $script = __DIR__ . '/process_callbacks.php';
        $line1 = "* * * * * /usr/bin/php $script >/dev/null 2>&1";
        $line2 = "* * * * * sleep 15; /usr/bin/php $script >/dev/null 2>&1";
        $line3 = "* * * * * sleep 30; /usr/bin/php $script >/dev/null 2>&1";
        $line4 = "* * * * * sleep 45; /usr/bin/php $script >/dev/null 2>&1";

        $current = @shell_exec('crontab -l 2>/dev/null') ?: '';
        $lines = array_filter(explode("\n", $current), function($l) use ($script) {
            return (strpos($l, basename($script)) === false);
        });
        $new = implode("\n", $lines) . "\n" . $line1 . "\n" . $line2 . "\n" . $line3 . "\n" . $line4 . "\n";
        file_put_contents('/tmp/new_crontab', $new);
        @shell_exec('crontab /tmp/new_crontab');
        @unlink('/tmp/new_crontab');

        freepbx_log(FPBX_LOG_INFO, "Queue Callback: Updated cron job to run every 15 seconds");
    }

    private function ensureCronJobExists(): void {
        $script = __DIR__ . '/process_callbacks.php';
        $current = @shell_exec('crontab -l 2>/dev/null') ?: '';
        if (strpos($current, 'process_callbacks.php') !== false) {
            return;
        }

        $stmt = $this->db->prepare("SELECT MIN(processing_interval) as min_interval FROM queuecallback_config WHERE enabled = 1");
        $stmt->execute();
        $res = $stmt->fetch(PDO::FETCH_ASSOC);
        $min = (int)($res['min_interval'] ?? 5);

        $expr = ($min <= 1) ? "* * * * *" :
                (($min <= 5) ? "*/5 * * * *" :
                (($min <= 10) ? "*/10 * * * *" :
                (($min <= 15) ? "*/15 * * * *" :
                (($min <= 30) ? "*/30 * * * *" : "0 * * * *"))));

        $line = "$expr /usr/bin/php $script >/dev/null 2>&1";
        $new = trim($current) . "\n" . $line . "\n";

        file_put_contents('/tmp/qcallback_cron', $new);
        @shell_exec('crontab /tmp/qcallback_cron');
        @unlink('/tmp/qcallback_cron');

        freepbx_log(FPBX_LOG_INFO, "Queue Callback: Added missing cron job");
    }

    /* ------------------------------------------------------------------
     * AGIs / Files
     * ------------------------------------------------------------------*/
    private function installAgiScripts(): void {
        $agi_scripts = ['queuecallback-store.agi', 'queuecallback-check.agi'];
        foreach ($agi_scripts as $s) {
            $src = __DIR__ . '/agi-bin/' . $s;
            $dst = '/var/lib/asterisk/agi-bin/' . $s;
            if (file_exists($src)) {
                copy($src, $dst);
                chmod($dst, 0755);
                @chown($dst, 'asterisk'); @chgrp($dst, 'asterisk');
                $data = file_get_contents($dst);
                $data = str_replace("\r\n", "\n", $data);
                file_put_contents($dst, $data);
            }
        }
    }

    private function uninstallAgiScripts(): void {
        foreach (['queuecallback-store.agi', 'queuecallback-check.agi'] as $s) {
            $dst = '/var/lib/asterisk/agi-bin/' . $s;
            if (file_exists($dst)) { @unlink($dst); }
        }
    }

    private function installCallbackDialplan(): void {
        $custom_file = '/etc/asterisk/extensions_custom.conf';
        $existing = file_exists($custom_file) ? file_get_contents($custom_file) : '';
        $existing = preg_replace('/; Queue Callback Custom Dialplan.*?\n\n/s', '', $existing);
        $existing = preg_replace('/\[queuecallback-handler-fixed\].*?\n\n/s', '', $existing);

        $dp  = "\n; Queue Callback Custom Dialplan - Auto-generated by module\n";
        $dp .= "[queuecallback-handler-fixed]\n";
        $dp .= "exten => s,1,NoOp(Callback key \${ARG1} pressed by \${CALLERID(number)} in queue \${QUEUENAME})\n";
        $dp .= "exten => s,n,Set(CALLBACK_NUMBER=\${CALLERID(number)})\n";
        $dp .= "exten => s,n,Set(CALLBACK_QUEUE=\${QUEUENAME})\n";
        $dp .= "exten => s,n,Set(CALLBACK_KEY=\${ARG1})\n";
        $dp .= "exten => s,n,AGI(queuecallback-store.agi)\n";
        $dp .= "exten => s,n,Playback(thank-you)\n";
        $dp .= "exten => s,n,Playback(your-call-will-be-returned)\n";
        $dp .= "exten => s,n,Hangup()\n\n";

        file_put_contents($custom_file, $existing . $dp, LOCK_EX);
        $this->reloadDialplan();
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
        if (!is_dir($dest_dir)) { mkdir($dest_dir, 0755, true); }

        $files = ['Qcallback.class.php','functions.inc.php','module.xml','page.qcallback.php','install.php','uninstall.php'];
        foreach ($files as $f) {
            $src = $module_dir . '/' . $f;
            $dst = $dest_dir . '/' . $f;
            if (file_exists($src)) {
                copy($src, $dst);
                chmod($dst, 0644);
                @chown($dst, 'asterisk'); @chgrp($dst, 'asterisk');
            }
        }
        foreach (['agi-bin','views'] as $d) {
            $this->copyDirectory($module_dir . '/' . $d, $dest_dir . '/' . $d);
        }
    }

    private function uninstallModuleFiles(): void {
        $dest_dir = '/var/www/html/admin/modules/qcallback';
        $this->removeDirectory($dest_dir);
    }

    private function copyDirectory($src, $dst): void {
        if (!is_dir($src)) { return; }
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
            $this->setupCallbackEvents();
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
