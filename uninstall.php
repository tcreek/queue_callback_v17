<?php
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
 * 2) CRON: remove our entries from both 'asterisk' and current user
 *    - Removes block between markers
 *    - Removes any old stray header lines or processor lines
 * -------------------------------------------------------------------*/
function qcb_strip_cron_block($content) {
    if ($content === '' || $content === null) return '';

    // Remove our new block (marker-based)
    $content = preg_replace('/^[ \t]*# BEGIN QCALLBACK[\s\S]*?# END QCALLBACK[ \t]*\R?/mi', '', $content);

    // Remove any leftover old-style header lines and processor calls (defensive)
    $content = preg_replace('/^[ \t]*#\s*Queue Callback intelligent processor.*\R?/mi', '', $content);
    $content = preg_replace('/^[ \t]*\* \* \* \* \* (?:sleep \d+; )?\/var\/www\/html\/admin\/modules\/qcallback\/intelligent_callback_processor\.php.*\R?/mi', '', $content);
    $content = preg_replace('/^[ \t]*.*process_callbacks\.php.*\R?/mi', '', $content);
    $content = preg_replace('/^[ \t]*.*\/admin\/modules\/qcallback\/.*\R?/mi', '', $content);

    // Normalize blanks
    $content = preg_replace("/\R{3,}/", "\n\n", $content);
    $content = trim($content) . "\n";
    return $content;
}

function qcb_remove_cron_for_user($user) {
    $cur = shell_exec('crontab -u '.escapeshellarg($user).' -l 2>/dev/null') ?: '';
    if ($cur === '') return false;
    $new = qcb_strip_cron_block($cur);
    if ($new === $cur) return false;
    $tmp = '/tmp/qcb_uninstall_cron_'.getmypid().'_'.$user;
    file_put_contents($tmp, $new);
    shell_exec('crontab -u '.escapeshellarg($user).' '.escapeshellarg($tmp).' 2>/dev/null');
    @unlink($tmp);
    return true;
}

try {
    $didAsterisk = qcb_remove_cron_for_user('asterisk');
    $who = trim(shell_exec('whoami')) ?: 'root';
    $didCurrent  = ($who !== 'asterisk') ? qcb_remove_cron_for_user($who) : false;
    if ($didAsterisk || $didCurrent) {
        out("Removed callback-related cron jobs");
    } else {
        out("No callback-related cron jobs found");
    }
} catch (\Throwable $e) {
    out("Cron cleanup error: " . $e->getMessage());
}

/* -------------------------------------------------------------------
 * 3) DIALPLAN: remove ONLY what we added
 * -------------------------------------------------------------------*/
try {
    $customFile = '/etc/asterisk/extensions_custom.conf';
    if (file_exists($customFile)) {
        $content = file_get_contents($customFile);
        $patterns = [
            '/\n\[queuecallback-outbound\][\s\S]*?(?=\n\[|\z)/i',
            '/\n\[queuecallback-agent-outbound\][\s\S]*?(?=\n\[|\z)/i',
            '/\n\[queuecallback-[^\]]+\][\s\S]*?(?=\n\[|\z)/i',
            '/\n\[qcb-hangup\][\s\S]*?(?=\n\[|\z)/i',
        ];
        foreach ($patterns as $p) { $content = preg_replace($p, '', $content); }
        file_put_contents($customFile, $content, LOCK_EX);
        out("Cleaned Queue Callback contexts from extensions_custom.conf");
    }

    $queuesPost = '/etc/asterisk/queues_post_custom.conf';
    if (file_exists($queuesPost)) {
        $qp = file_get_contents($queuesPost);
        $qp = preg_replace('/\n; BEGIN QCB_DB AUTO[\s\S]*?; END QCB_DB AUTO\n/s', "\n", $qp);
        file_put_contents($queuesPost, $qp, LOCK_EX);
        out("Removed QCB auto sections from queues_post_custom.conf (if any)");
    }
} catch (\Throwable $e) {
    out("Dialplan cleanup error: " . $e->getMessage());
}

/* -------------------------------------------------------------------
 * 4) AGIs
 * -------------------------------------------------------------------*/
try {
    $agi = [
        '/var/lib/asterisk/agi-bin/queuecallback-store.agi',
        '/var/lib/asterisk/agi-bin/queuecallback-check.agi',
        '/var/lib/asterisk/agi-bin/queuecallback-complete.agi'
    ];
    foreach ($agi as $f) {
        if (file_exists($f)) { @unlink($f); out("Removed AGI script: " . basename($f)); }
    }
} catch (\Throwable $e) {
    out("AGI cleanup error: " . $e->getMessage());
}

/* -------------------------------------------------------------------
 * 5) SOUND FILES
 * -------------------------------------------------------------------*/
try {
    $soundFile = '/var/lib/asterisk/sounds/en/custom/confirm_number.wav';
    if (file_exists($soundFile)) {
        @unlink($soundFile);
        out("Removed custom sound file: confirm_number.wav");
    }
    
    // Only remove the custom directory if it's empty (don't remove other custom sounds)
    $customDir = '/var/lib/asterisk/sounds/en/custom';
    if (is_dir($customDir) && count(scandir($customDir)) == 2) { // only . and ..
        @rmdir($customDir);
        out("Removed empty custom sounds directory");
    }
} catch (\Throwable $e) {
    out("Sound file cleanup error: " . $e->getMessage());
}

/* -------------------------------------------------------------------
 * 6) ASTERISK DB
 * -------------------------------------------------------------------*/
try {
    @shell_exec('asterisk -rx "database deltree QCALLBACK" 2>/dev/null');
    out("Cleared Asterisk database entries (family QCALLBACK)");
} catch (\Throwable $e) {
    out("Asterisk DB cleanup error: " . $e->getMessage());
}

/* -------------------------------------------------------------------
 * 7) Flag reload
 * -------------------------------------------------------------------*/
try {
    if (function_exists('needreload')) { needreload(); }
    out("Requested framework reload");
} catch (\Throwable $e) {
    out("Reload error: " . $e->getMessage());
}

out("Queue Callback module uninstallation completed");
