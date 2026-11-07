<?php
/** Shared installation operations. GPL-3.0-or-later. */
require_once __DIR__ . '/callback_queue.php';

function qcallback_upgrade_return_confirmation($db): void {
    $columns = $db->query('SHOW COLUMNS FROM queuecallback_config')->fetchAll(PDO::FETCH_COLUMN);
    foreach (['return_confirm_timeout' => 10, 'return_confirm_repeats' => 1] as $name => $default) {
        if (!in_array($name, $columns, true)) {
            $db->exec("ALTER TABLE queuecallback_config ADD COLUMN $name INT NOT NULL DEFAULT $default");
        }
    }
}

function qcallback_install_schema($db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS queuecallback_requests (
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
    $db->exec("CREATE TABLE IF NOT EXISTS queuecallback_config (
                    queue_id VARCHAR(50) PRIMARY KEY,
                    enabled TINYINT(1) DEFAULT 0,
                    announce_id VARCHAR(100) DEFAULT NULL,
                    announce_frequency INT DEFAULT 0,
                    callback_key VARCHAR(10) DEFAULT '#',
                    processing_interval INT DEFAULT 30,
                    max_attempts INT DEFAULT 3,
                    retry_interval INT DEFAULT 60,
                    return_message_id VARCHAR(100) DEFAULT NULL,
                    confirm_message_id VARCHAR(100) DEFAULT NULL,
                    confirm_number TINYINT(1) DEFAULT 1,
                    alt_number_key VARCHAR(10) DEFAULT '2',
                    alt_message_id VARCHAR(100) DEFAULT NULL,
                    initiated_message_id VARCHAR(100) DEFAULT NULL,
                    confirm_prompt_id VARCHAR(100) DEFAULT NULL,
                    call_first VARCHAR(10) DEFAULT 'customer',
                    outbound_route_id INT DEFAULT 0
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
    $db->exec("CREATE TABLE IF NOT EXISTS queuecallback_trigger (
                    id INT PRIMARY KEY DEFAULT 1,
                    last_run INT NOT NULL,
                    UNIQUE KEY unique_id (id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
    $db->exec("CREATE TABLE IF NOT EXISTS queuecallback_security (
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
    $columns = $db->query('SHOW COLUMNS FROM queuecallback_config')->fetchAll(PDO::FETCH_COLUMN);
    foreach (['announce_frequency'=>'INT DEFAULT 0', 'call_first'=>"VARCHAR(10) DEFAULT 'customer'",
        'outbound_route_id'=>'INT DEFAULT 0', 'alt_message_id'=>'VARCHAR(100) DEFAULT NULL',
        'initiated_message_id'=>'VARCHAR(100) DEFAULT NULL', 'confirm_prompt_id'=>'VARCHAR(100) DEFAULT NULL'] as $name=>$definition) {
        if (!in_array($name, $columns, true)) { $db->exec("ALTER TABLE queuecallback_config ADD COLUMN $name $definition"); }
    }
    $columns = $db->query('SHOW COLUMNS FROM queuecallback_security')->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('queue_id', $columns, true)) { $db->exec("ALTER TABLE queuecallback_security ADD COLUMN queue_id VARCHAR(20) DEFAULT '' AFTER area_code"); }
    qcallback_upgrade_queue_schema($db);
    qcallback_upgrade_return_confirmation($db);
}

function qcallback_default_security_entries(): array {
    return [
        ['pattern' => '_268NXXXXXX', 'description' => 'Antigua and Barbuda', 'area_code' => '268', 'enabled' => 1, 'sort_order' => 10],
        ['pattern' => '_284NXXXXXX', 'description' => 'British Virgin Islands', 'area_code' => '284', 'enabled' => 1, 'sort_order' => 20],
        ['pattern' => '_345NXXXXXX', 'description' => 'Cayman Islands', 'area_code' => '345', 'enabled' => 1, 'sort_order' => 30],
        ['pattern' => '_473NXXXXXX', 'description' => 'Grenada', 'area_code' => '473', 'enabled' => 1, 'sort_order' => 40],
        ['pattern' => '_649NXXXXXX', 'description' => 'Turks and Caicos Islands', 'area_code' => '649', 'enabled' => 1, 'sort_order' => 50],
        ['pattern' => '_664NXXXXXX', 'description' => 'Montserrat', 'area_code' => '664', 'enabled' => 1, 'sort_order' => 60],
        ['pattern' => '_721NXXXXXX', 'description' => 'Sint Maarten', 'area_code' => '721', 'enabled' => 1, 'sort_order' => 70],
        ['pattern' => '_758NXXXXXX', 'description' => 'Saint Lucia', 'area_code' => '758', 'enabled' => 1, 'sort_order' => 80],
        ['pattern' => '_767NXXXXXX', 'description' => 'Dominica', 'area_code' => '767', 'enabled' => 1, 'sort_order' => 90],
        ['pattern' => '_784NXXXXXX', 'description' => 'Saint Vincent and the Grenadines', 'area_code' => '784', 'enabled' => 1, 'sort_order' => 100],
        ['pattern' => '_809NXXXXXX', 'description' => 'Dominican Republic', 'area_code' => '809', 'enabled' => 1, 'sort_order' => 110],
        ['pattern' => '_829NXXXXXX', 'description' => 'Dominican Republic', 'area_code' => '829', 'enabled' => 1, 'sort_order' => 120],
        ['pattern' => '_849NXXXXXX', 'description' => 'Dominican Republic', 'area_code' => '849', 'enabled' => 1, 'sort_order' => 130],
        ['pattern' => '_868NXXXXXX', 'description' => 'Trinidad and Tobago', 'area_code' => '868', 'enabled' => 1, 'sort_order' => 140],
        ['pattern' => '_869NXXXXXX', 'description' => 'Saint Kitts and Nevis', 'area_code' => '869', 'enabled' => 1, 'sort_order' => 150],
        ['pattern' => '_876NXXXXXX', 'description' => 'Jamaica', 'area_code' => '876', 'enabled' => 1, 'sort_order' => 160],
        ['pattern' => '_1268NXXXXXX', 'description' => 'Antigua and Barbuda (+1)', 'area_code' => '1268', 'enabled' => 1, 'sort_order' => 170],
        ['pattern' => '_1284NXXXXXX', 'description' => 'British Virgin Islands (+1)', 'area_code' => '1284', 'enabled' => 1, 'sort_order' => 180],
        ['pattern' => '_1345NXXXXXX', 'description' => 'Cayman Islands (+1)', 'area_code' => '1345', 'enabled' => 1, 'sort_order' => 190],
        ['pattern' => '_1473NXXXXXX', 'description' => 'Grenada (+1)', 'area_code' => '1473', 'enabled' => 1, 'sort_order' => 200],
        ['pattern' => '_1649NXXXXXX', 'description' => 'Turks and Caicos Islands (+1)', 'area_code' => '1649', 'enabled' => 1, 'sort_order' => 210],
        ['pattern' => '_1664NXXXXXX', 'description' => 'Montserrat (+1)', 'area_code' => '1664', 'enabled' => 1, 'sort_order' => 220],
        ['pattern' => '_1721NXXXXXX', 'description' => 'Sint Maarten (+1)', 'area_code' => '1721', 'enabled' => 1, 'sort_order' => 230],
        ['pattern' => '_1758NXXXXXX', 'description' => 'Saint Lucia (+1)', 'area_code' => '1758', 'enabled' => 1, 'sort_order' => 240],
        ['pattern' => '_1767NXXXXXX', 'description' => 'Dominica (+1)', 'area_code' => '1767', 'enabled' => 1, 'sort_order' => 250],
        ['pattern' => '_1784NXXXXXX', 'description' => 'Saint Vincent and the Grenadines (+1)', 'area_code' => '1784', 'enabled' => 1, 'sort_order' => 260],
        ['pattern' => '_1809NXXXXXX', 'description' => 'Dominican Republic (+1)', 'area_code' => '1809', 'enabled' => 1, 'sort_order' => 270],
        ['pattern' => '_1829NXXXXXX', 'description' => 'Dominican Republic (+1)', 'area_code' => '1829', 'enabled' => 1, 'sort_order' => 280],
        ['pattern' => '_1849NXXXXXX', 'description' => 'Dominican Republic (+1)', 'area_code' => '1849', 'enabled' => 1, 'sort_order' => 290],
        ['pattern' => '_1868NXXXXXX', 'description' => 'Trinidad and Tobago (+1)', 'area_code' => '1868', 'enabled' => 1, 'sort_order' => 300],
        ['pattern' => '_1869NXXXXXX', 'description' => 'Saint Kitts and Nevis (+1)', 'area_code' => '1869', 'enabled' => 1, 'sort_order' => 310],
        ['pattern' => '_1876NXXXXXX', 'description' => 'Jamaica (+1)', 'area_code' => '1876', 'enabled' => 1, 'sort_order' => 320],
    ];
}

function qcallback_seed_security_entries($db, string $queue): void {
    $check = $db->prepare('SELECT COUNT(*) FROM queuecallback_security WHERE queue_id = ?');
    $check->execute([$queue]);
    if ((int)$check->fetchColumn() > 0) { return; }
    $insert = $db->prepare('INSERT INTO queuecallback_security (pattern, description, area_code, queue_id, enabled, sort_order, created_at) VALUES (?,?,?,?,?,?,?)');
    foreach (qcallback_default_security_entries() as $entry) {
        $insert->execute([$entry['pattern'], $entry['description'], $entry['area_code'], $queue,
            $entry['enabled'], $entry['sort_order'], time()]);
    }
}

/** Same source AGIs for every installer; normalize only line endings and module path. */
function qcallback_install_agis(string $source, string $destination, string $moduleDir, ?array $owner = ['asterisk','asterisk']): void {
    if (!is_dir($destination) && !mkdir($destination, 0755, true)) { throw new RuntimeException('Cannot create AGI directory'); }
    foreach (['queuecallback-store.agi','queuecallback-check.agi','queuecallback-complete.agi','queuecallback-result.agi'] as $name) {
        $data = file_get_contents($source . '/' . $name);
        if ($data === false) { throw new RuntimeException('Missing callback AGI: ' . $name); }
        $data = str_replace("\r\n", "\n", $data);
        $target = $destination . '/' . $name;
        if (file_put_contents($target, $data, LOCK_EX) !== strlen($data) || !chmod($target, 0755)) {
            throw new RuntimeException('Cannot install callback AGI: ' . $name);
        }
        if ($owner) { chown($target, $owner[0]); chgrp($target, $owner[1]); }
    }
}

function qcallback_install_announcements(string $source, string $destination, array $sounds, ?array $owner = ['asterisk','asterisk']): void {
    if (!is_dir($destination) && !mkdir($destination, 0755, true)) { throw new RuntimeException('Cannot create sound directory'); }
    foreach ($sounds as $sound) {
        foreach (['wav','ulaw','alaw'] as $format) {
            $name = basename($sound) . '.' . $format;
            if (!is_file($source . '/' . $name)) { continue; }
            $target = $destination . '/' . $name;
            if (!copy($source . '/' . $name, $target) || !chmod($target, 0664)) { throw new RuntimeException('Cannot install sound: ' . $name); }
            if ($owner) { chown($target, $owner[0]); chgrp($target, $owner[1]); }
        }
    }
}

/** Replace only this module's processor entries and retain unrelated jobs. */
function qcallback_processor_crontab(string $current, string $script): string {
    $lines = [];
    foreach (explode("\n", $current) as $line) {
        if (preg_match('~/qcallback/(?:process_callbacks|intelligent_callback_processor|callback_processor)\.php(?:[\s\x22\x27]|$)~', $line)
            || trim($line) === '# Queue Callback processor (every 15s)') { continue; }
        $lines[] = $line;
    }
    $body = rtrim(implode("\n", $lines));
    $body .= "\n# Queue Callback processor (every 15s)\n";
    foreach ([0,15,30,45] as $delay) {
        $body .= '* * * * * ' . ($delay ? "sleep $delay; " : '')
            . '/usr/bin/php ' . escapeshellarg($script) . " >/dev/null 2>&1\n";
    }
    return ltrim($body, "\n");
}
