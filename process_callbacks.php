#!/usr/bin/php
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
require_once '/etc/freepbx.conf';
require_once __DIR__ . '/callback_dispatch.php';
require_once __DIR__ . '/callback_lifecycle.php';

$db = FreePBX::Database();
qcallback_reconcile_attempts($db);

// Include heads waiting for retry: younger requests must not bypass them.
$pending = $db->query("SELECT id, queue_id FROM queuecallback_requests WHERE status = 'pending'
    ORDER BY queue_priority DESC, COALESCE(queue_joined_at, time_requested), time_requested, id");
$seen = [];
foreach ($pending->fetchAll(PDO::FETCH_ASSOC) as $callback) {
    $queue = (string)$callback['queue_id'];
    if (isset($seen[$queue])) { continue; }
    $seen[$queue] = true;
    $result = qcallback_dispatch_request($db, (int)$callback['id']);
    if ($result['published']) { break; } // At most one new call per invocation.
}

$db->prepare("DELETE FROM queuecallback_requests WHERE status IN ('completed', 'failed')
    AND time_processed IS NOT NULL AND time_processed < ?")->execute([time() - 86400]);
