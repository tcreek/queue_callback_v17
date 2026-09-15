<?php
/**
 * Queue Callback Module for FreePBX
 *
 * Copyright (C) 2026 Trent Creekmore
 * trent@netservisity.com
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the FreePBX Foundation, either version 3 of the License, or
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
// Scheduled Queue Callbacks Report

$db = FreePBX::Database();

$status_filter = $_REQUEST['status_filter'] ?? '';
$queue_filter = $_REQUEST['queue_filter'] ?? '';

// Get queues for filter dropdown
$queues = array();
try {
    $stmt = $db->query("SELECT queue_id FROM queuecallback_config ORDER BY queue_id");
    $queues = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
} catch (\Throwable $e) {}

// Build query
$sql = "SELECT r.id, r.queue_id, r.caller_id, r.callback_number, r.status, r.attempts, r.time_requested, r.time_processed, q.descr as queue_name
        FROM queuecallback_requests r
        LEFT JOIN queues_config q ON r.queue_id COLLATE utf8_general_ci = q.extension COLLATE utf8_general_ci
        WHERE 1=1";
$params = array();

if (!empty($status_filter)) {
    $sql .= " AND r.status = ?";
    $params[] = $status_filter;
}
if (!empty($queue_filter)) {
    $sql .= " AND r.queue_id = ?";
    $params[] = $queue_filter;
}

$sql .= " ORDER BY r.time_requested DESC LIMIT 500";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$callbacks = $stmt->fetchAll(PDO::FETCH_ASSOC);

$status_counts = array();
try {
    $stmt = $db->query("SELECT status, COUNT(*) as cnt FROM queuecallback_requests GROUP BY status");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $status_counts[$row['status']] = $row['cnt'];
    }
} catch (\Throwable $e) {}
?>

<div class="row">
    <div class="col-md-12">
        <div class="panel panel-primary">
            <div class="panel-heading">
                <h3 class="panel-title">
                    <i class="fa fa-calendar"></i> <?php echo _("Scheduled Queue Callbacks") ?>
                    <span class="badge"><?php echo count($callbacks) ?></span>
                </h3>
            </div>
            <div class="panel-body">
                <form method="get" class="form-inline" style="margin-bottom: 20px;">
                    <input type="hidden" name="display" value="qcallback">
                    <input type="hidden" name="view" value="reports">

                    <div class="form-group" style="margin-right: 15px;">
                        <label for="queue_filter" style="margin-right: 5px;"><?php echo _("Queue") ?></label>
                        <select name="queue_filter" id="queue_filter" class="form-control" style="width: 200px;">
                            <option value=""><?php echo _("All Queues") ?></option>
                            <?php foreach ($queues as $q): ?>
                                <option value="<?php echo htmlentities($q) ?>" <?php echo $queue_filter == $q ? 'selected' : '' ?>>
                                    <?php echo htmlentities($q) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group" style="margin-right: 15px;">
                        <label for="status_filter" style="margin-right: 5px;"><?php echo _("Status") ?></label>
                        <select name="status_filter" id="status_filter" class="form-control" style="width: 150px;">
                            <option value=""><?php echo _("All Status") ?></option>
                            <option value="pending" <?php echo $status_filter == 'pending' ? 'selected' : '' ?>><?php echo _("Pending") ?></option>
                            <option value="processing" <?php echo $status_filter == 'processing' ? 'selected' : '' ?>><?php echo _("Processing") ?></option>
                            <option value="completed" <?php echo $status_filter == 'completed' ? 'selected' : '' ?>><?php echo _("Completed") ?></option>
                            <option value="failed" <?php echo $status_filter == 'failed' ? 'selected' : '' ?>><?php echo _("Failed") ?></option>
                            <option value="cancelled" <?php echo $status_filter == 'cancelled' ? 'selected' : '' ?>><?php echo _("Cancelled") ?></option>
                        </select>
                    </div>

                    <button type="submit" class="btn btn-primary"><?php echo _("Filter") ?></button>
                    <a href="?display=qcallback&view=reports" class="btn btn-default"><?php echo _("Clear") ?></a>
                </form>

                <div class="table-responsive">
                    <table class="table table-striped table-hover">
                        <thead>
                            <tr>
                                <th><?php echo _("Date & Time of Request") ?></th>
                                <th><?php echo _("Queue Callback") ?></th>
                                <th><?php echo _("Requestor Name (CID)") ?></th>
                                <th><?php echo _("Requestor Number") ?></th>
                                <th><?php echo _("Tries") ?></th>
                                <th><?php echo _("Status") ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($callbacks)): ?>
                                <tr>
                                    <td colspan="6" class="text-center">
                                        <div class="alert alert-info"><?php echo _("No callback requests found") ?></div>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($callbacks as $cb): ?>
                                    <tr>
                                        <td><?php echo date('Y-m-d H:i:s', $cb['time_requested']) ?></td>
                                        <td>
                                            <strong><?php echo htmlentities($cb['queue_id']) ?></strong>
                                            <?php if (!empty($cb['queue_name'])): ?>
                                                <br><span class="text-muted"><?php echo htmlentities($cb['queue_name']) ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo htmlentities($cb['caller_id'] ?? '') ?></td>
                                        <td><?php echo htmlentities($cb['callback_number']) ?></td>
                                        <td><?php echo (int)$cb['attempts'] ?></td>
                                        <td>
                                            <?php
                                            $status_labels = array(
                                                'pending' => array('label' => 'warning', 'icon' => 'clock-o', 'text' => 'Pending'),
                                                'processing' => array('label' => 'info', 'icon' => 'spinner', 'text' => 'Processing'),
                                                'completed' => array('label' => 'success', 'icon' => 'check', 'text' => 'Completed'),
                                                'failed' => array('label' => 'danger', 'icon' => 'times', 'text' => 'Failed'),
                                                'cancelled' => array('label' => 'default', 'icon' => 'ban', 'text' => 'Cancelled'),
                                            );
                                            $st = $cb['status'];
                                            if (isset($status_labels[$st])):
                                                $sl = $status_labels[$st];
                                            ?>
                                                <span class="label label-<?php echo $sl['label'] ?>">
                                                    <i class="fa fa-<?php echo $sl['icon'] ?>"></i> <?php echo $sl['text'] ?>
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row" style="margin-top: 20px;">
    <div class="col-md-12">
        <div class="panel panel-info">
            <div class="panel-heading">
                <h3 class="panel-title"><?php echo _("Summary") ?></h3>
            </div>
            <div class="panel-body">
                <dl class="dl-horizontal">
                    <?php
                    $status_texts = array(
                        'pending' => _('Pending'),
                        'processing' => _('Processing'),
                        'completed' => _('Completed'),
                        'failed' => _('Failed'),
                        'cancelled' => _('Cancelled'),
                    );
                    foreach ($status_counts as $status => $count):
                        if (!empty($status) && isset($status_texts[$status])):
                    ?>
                            <dt><?php echo $status_texts[$status] ?></dt>
                            <dd><?php echo $count ?></dd>
                    <?php
                        endif;
                    endforeach;
                    ?>
                    <dt><?php echo _("Total") ?></dt>
                    <dd><?php echo array_sum($status_counts) ?></dd>
                </dl>
            </div>
        </div>
    </div>
</div>