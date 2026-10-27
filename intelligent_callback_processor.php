#!/usr/bin/php
<?php
require_once('/etc/freepbx.conf');
// Delegate to the working process_callbacks.php
$script = __DIR__ . '/process_callbacks.php';
if (file_exists($script)) {
    passthru('/usr/bin/php ' . escapeshellarg($script) . ' 2>&1');
}