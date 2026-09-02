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

out("Queue Callback module uninstallation started");

/* -------------------------------------------------------------------
 * 1) DATABASE: drop ONLY our tables
 * -------------------------------------------------------------------*/
try {
    sql("DROP TABLE IF EXISTS queuecallback_requests");
    sql("DROP TABLE IF EXISTS queuecallback_config");
    sql("DROP TABLE IF EXISTS queuecallback_trigger");
    out("Dropped Queue Callback database tables");
} catch (\Throwable $e) {
    out("Database cleanup error: " . $e->getMessage());
}

/* -------------------------------------------------------------------
 * 2) CRON: remove our intelligent processor entries only
 * -------------------------------------------------------------------*/
try {
    $current = shell_exec('crontab -l 2>/dev/null') ?: '';
    if ($current !== '') {
        $lines = explode("\n", $current);
        $filtered = array_filter($lines, function($line) {
            // Remove only our entries
            return strpos($line, 'intelligent_callback_processor.php') === false
                && strpos($line, 'process_callbacks.php') === false
                && strpos($line, 'callback_processor.php') === false;
        });
        $new = implode("\n", $filtered) . "\n";
        file_put_contents('/tmp/new_crontab', $new);
        shell_exec('crontab /tmp/new_crontab');
        @unlink('/tmp/new_crontab');
        out("Removed callback-related cron jobs");
    }
} catch (\Throwable $e) {
    out("Cron cleanup error: " . $e->getMessage());
}

/* -------------------------------------------------------------------
 * 3) DIALPLAN: remove ONLY what install.php wrote
 *    - [queuecallback-outbound]
 *    - [queuecallback-agent-outbound]
 *    - Any old handler contexts we might have written (defensive)
 * -------------------------------------------------------------------*/
try {
    $customFile = '/etc/asterisk/extensions_custom.conf';
    if (file_exists($customFile)) {
        $content = file_get_contents($customFile);

        // Remove our specific sections
        $patterns = [
            // exact contexts install.php adds
            '/\n\[queuecallback-outbound\][\s\S]*?(?=\n\[|\z)/i',
            '/\n\[queuecallback-agent-outbound\][\s\S]*?(?=\n\[|\z)/i',
            // defensive: older handler contexts
            '/\n\[qcb-hangup\][\s\S]*?(?=\n\[|\z)/i',
            '/\n\[queuecallback-[^\]]+\][\s\S]*?(?=\n\[|\z)/i',
        ];
        foreach ($patterns as $p) {
            $content = preg_replace($p, '', $content);
        }

        file_put_contents($customFile, $content, LOCK_EX);
        out("Cleaned Queue Callback contexts from extensions_custom.conf");
    }

    // If any auto blocks in queues_post_custom.conf exist from older versions, prune them
    $queuesPost = '/etc/asterisk/queues_post_custom.conf';
    if (file_exists($queuesPost)) {
        $qp = file_get_contents($queuesPost);
        $qp = preg_replace('/\n; BEGIN QCB_DB AUTO[\s\S]*?; END QCB_DB AUTO\n/s', "\n", $qp);
        file_put_contents($queuesPost, $qp, LOCK_EX);
        out("Removed QCB auto sections from queues_post_custom.conf (if any)");
    }

    // DO NOT touch extensions_override_freepbx.conf unless a clear marker exists (we never write it here)
} catch (\Throwable $e) {
    out("Dialplan cleanup error: " . $e->getMessage());
}

/* -------------------------------------------------------------------
 * 4) AGIs: remove only our AGI scripts
 * -------------------------------------------------------------------*/
try {
    $agi = [
        '/var/lib/asterisk/agi-bin/queuecallback-store.agi',
        '/var/lib/asterisk/agi-bin/queuecallback-check.agi',
        '/var/lib/asterisk/agi-bin/queuecallback-complete.agi'
    ];
    foreach ($agi as $f) {
        if (file_exists($f)) {
            @unlink($f);
            out("Removed AGI script: " . basename($f));
        }
    }
} catch (\Throwable $e) {
    out("AGI cleanup error: " . $e->getMessage());
}

/* -------------------------------------------------------------------
 * 5) ASTERISK DB: clear our family
 * -------------------------------------------------------------------*/
try {
    @shell_exec('asterisk -rx "database deltree QCALLBACK" 2>/dev/null');
    out("Cleared Asterisk database entries (family QCALLBACK)");
} catch (\Throwable $e) {
    out("Asterisk DB cleanup error: " . $e->getMessage());
}

/* -------------------------------------------------------------------
 * 6) Reload dialplan/framework
 * -------------------------------------------------------------------*/
try {
    // Flag a safe reload through framework
    if (function_exists('needreload')) { needreload(); }
    @shell_exec('fwconsole reload 2>/dev/null');
    out("Requested framework reload");
} catch (\Throwable $e) {
    out("Reload error: " . $e->getMessage());
}

out("Queue Callback module uninstallation completed");

