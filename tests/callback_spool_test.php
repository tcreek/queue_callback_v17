<?php
/**
 * Integration checks: php tests/callback_spool_test.php
 * Uses connection-local TEMPORARY tables that shadow the production tables,
 * and a spool under /tmp. No call files are sent to Asterisk.
 */
require_once('/etc/freepbx.conf');
require_once(dirname(__DIR__) . '/callback_spool.php');

function check($condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

class SpoolTestStatement extends PDOStatement {
    public static $afterClaim;
    protected function __construct() {}
    public function execute(?array $params = null): bool {
        $result = parent::execute($params);
        if (strpos($this->queryString, 'UPDATE queuecallback_requests r') === 0
            && $this->rowCount() === 1 && self::$afterClaim) {
            (self::$afterClaim)();
        }
        return $result;
    }
}

$db = FreePBX::Database();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_STATEMENT_CLASS, [SpoolTestStatement::class]);
$db->exec("CREATE TEMPORARY TABLE queuecallback_requests (
    id INT PRIMARY KEY, queue_id VARCHAR(50), callback_number VARCHAR(50),
    status VARCHAR(20), attempts INT, last_attempt INT NULL, max_attempts INT,
    time_requested INT DEFAULT 0)");
$db->exec("CREATE TEMPORARY TABLE queuecallback_config (
    queue_id VARCHAR(50) PRIMARY KEY, enabled INT, max_attempts INT,
    processing_interval INT DEFAULT 30, retry_interval INT DEFAULT 30)");

$root = sys_get_temp_dir() . '/qcb-spool-test-' . bin2hex(random_bytes(8));
mkdir($root, 0700);
$outgoing = $root . '/outgoing';
mkdir($outgoing, 0700);
$queue = 'test-' . bin2hex(random_bytes(8));
$callback = ['id' => 1, 'queue_id' => $queue, 'callback_number' => '5551234567',
    'attempts' => 0, 'last_attempt' => null];
$db->prepare('INSERT INTO queuecallback_config (queue_id, enabled, max_attempts) VALUES (?, 1, 3)')->execute([$queue]);
$content = "Channel: Local/5551234567@from-internal\nContext: test-only\n";
$destination = $outgoing . '/queuecallback_1_1.call';
$reset = function () use ($db, $queue): void {
    $db->exec('DELETE FROM queuecallback_requests');
    $db->prepare("INSERT INTO queuecallback_requests (id, queue_id, callback_number, status, attempts, last_attempt, max_attempts) VALUES (1, ?, '5551234567', 'pending', 0, NULL, 3)")->execute([$queue]);
};
$state = function () use ($db): array {
    return $db->query('SELECT * FROM queuecallback_requests WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
};

try {
    $reset();
    SpoolTestStatement::$afterClaim = function () use ($state, $outgoing): void {
        check($state()['status'] === 'processing', 'Must claim before publishing');
        check(glob($outgoing . '/*') === [], 'Outgoing must remain empty during staging');
    };
    check(qcallback_publish_call($db, $callback, $content, $outgoing), 'Initial publication failed');
    check(file_get_contents($destination) === $content . "MaxRetries: 0\nSetVar: __CALLBACK_ATTEMPT=1\n", 'Published file must be complete');
    check((fileperms($destination) & 0777) === 0640, 'Call file permissions must be 0640');
    check(fileowner($destination) === fileowner($outgoing), 'Call file must use spool owner');
    check(filegroup($destination) === filegroup($outgoing), 'Call file must use spool group');
    check((int)$state()['attempts'] === 1, 'Exactly one attempt must be recorded');
    check(glob($root . '/.qcallback-staging/*') === [], 'Staging file was not removed');
    check(!qcallback_publish_call($db, $callback, $content, $outgoing), 'Duplicate worker published');
    check((int)$state()['attempts'] === 1, 'Duplicate worker changed attempts');
    unlink($destination);
    echo "PASS: complete publication, permissions, claim ordering, duplicate request\n";

    $db->prepare("INSERT INTO queuecallback_requests (id, queue_id, callback_number, status, attempts, last_attempt, max_attempts) VALUES (2, ?, '5551234567', 'pending', 0, NULL, 3)")->execute([$queue]);
    $other = array_replace($callback, ['id' => 2]);
    check(!qcallback_publish_call($db, $other, $content, $outgoing), 'Duplicate number published');
    echo "PASS: different requests for an active queue/number are blocked\n";

    // A stale selection must not reclaim a request that has since been retried.
    $reset();
    SpoolTestStatement::$afterClaim = null;
    $db->exec('UPDATE queuecallback_requests SET attempts = 1, last_attempt = 123');
    check(!qcallback_publish_call($db, $callback, $content, $outgoing), 'Stale worker claimed request');
    check(glob($outgoing . '/*') === [], 'Stale worker published a file');
    check(glob($root . '/.qcallback-staging/*') === [], 'Rejected claim left staging files');
    echo "PASS: stale selection rejected and staged file cleaned\n";

    $reset();
    $db->prepare('UPDATE queuecallback_config SET enabled = 0 WHERE queue_id = ?')->execute([$queue]);
    check(!qcallback_publish_call($db, $callback, $content, $outgoing), 'Disabled queue published');
    $db->prepare('UPDATE queuecallback_config SET enabled = 1 WHERE queue_id = ?')->execute([$queue]);
    $db->exec('UPDATE queuecallback_requests SET attempts = 3');
    check(!qcallback_publish_call($db, array_replace($callback, ['attempts' => 3]), $content, $outgoing), 'Exhausted request published');
    echo "PASS: disabled queues and exhausted requests rejected\n";

    // Simulate a filesystem failure after the DB claim has succeeded.
    $reset();
    SpoolTestStatement::$afterClaim = function () use ($destination): void { mkdir($destination); };
    set_error_handler(function ($severity, $message) {
        if ($severity === E_WARNING && strpos($message, 'rename(') === 0) { return true; }
        return false;
    });
    try {
        check(!qcallback_publish_call($db, $callback, $content, $outgoing), 'Failed publication returned success');
    } finally {
        restore_error_handler();
        SpoolTestStatement::$afterClaim = null;
    }
    check($state()['status'] === 'pending' && (int)$state()['attempts'] === 0
        && $state()['last_attempt'] === null, 'Failed publication did not restore attempt state');
    check(glob($root . '/.qcallback-staging/*') === [], 'Failed publication left staging file');
    rmdir($destination);
    check(qcallback_publish_call($db, $callback, $content, $outgoing), 'Recovery left request or lock unusable');
    unlink($destination);
    echo "PASS: publish failure restores attempt state, cleans staging, releases lock\n";

    $reset();
    file_put_contents($destination, 'existing call');
    check(!qcallback_publish_call($db, $callback, $content, $outgoing), 'Existing call file was overwritten');
    check(file_get_contents($destination) === 'existing call', 'Existing file contents changed');
    check($state()['status'] === 'pending' && (int)$state()['attempts'] === 0, 'Existing file consumed an attempt');
    unlink($destination);
    echo "PASS: existing outgoing file is preserved\n";

    $reset();
    $db->exec("UPDATE queuecallback_requests SET status = 'cancelled'");
    check(!qcallback_publish_call($db, $callback, $content, $outgoing), 'Cancelled request published');
    check(glob($outgoing . '/*') === [], 'Cancelled request left an outgoing file');
    echo "PASS: cancellation before claim is respected\n";

    // Independent connection models a second worker holding the same lock.
    $host = $amp_conf['AMPDBHOST'];
    $peer = new PDO('mysql:host=' . $host . ';dbname=' . $amp_conf['AMPDBNAME'],
        $amp_conf['AMPDBUSER'], $amp_conf['AMPDBPASS'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $lockName = 'qcb:' . substr(hash('sha256', $queue . '|' . $callback['callback_number']), 0, 60);
    $lock = $peer->prepare('SELECT GET_LOCK(?, 0)');
    $lock->execute([$lockName]);
    check((int)$lock->fetchColumn() === 1, 'Could not acquire competing connection lock');
    try {
        $reset();
        check(!qcallback_publish_call($db, $callback, $content, $outgoing), 'Competing lock was ignored');
        check($state()['status'] === 'pending' && (int)$state()['attempts'] === 0, 'Lock loser changed request');
    } finally {
        $peer->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]);
    }
    check(qcallback_publish_call($db, $callback, $content, $outgoing), 'Request failed after competing lock released');
    unlink($destination);
    echo "PASS: concurrent database connection is excluded until lock release\n";
} finally {
    SpoolTestStatement::$afterClaim = null;
    foreach (glob($root . '/.qcallback-staging/*') ?: [] as $file) { unlink($file); }
    foreach (glob($outgoing . '/*') ?: [] as $file) {
        if (is_dir($file)) { rmdir($file); } else { unlink($file); }
    }
    if (is_dir($root . '/.qcallback-staging')) { rmdir($root . '/.qcallback-staging'); }
    rmdir($outgoing);
    rmdir($root);
    $db->exec('DROP TEMPORARY TABLE queuecallback_requests, queuecallback_config');
}
