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
?>

<?php
// Get all queues with callback configurations
$queues = array();
if (function_exists('queues_list')) {
    $all_queues = queues_list();
    foreach ($all_queues as $queue) {
        $queue_id = $queue[0];
        $queue_desc = $queue[1];
        $callback_config = FreePBX::Qcallback()->getQueueCallbackConfig($queue_id);
        
        $queues[] = array(
            'extension' => $queue_id,
            'description' => $queue_desc,
            'callback_enabled' => $callback_config['enabled'] ? _('Yes') : _('No'),
            'pending_callbacks' => count(queuecallback_get_pending_requests($queue_id))
        );
    }
}
?>

<div id="toolbar-callback-nav">
    <a href="?display=queues" class="btn btn-default">
        <i class="fa fa-list"></i>&nbsp; <?php echo _("Manage Queues") ?>
    </a>
    <a href="?display=recordings" class="btn btn-default">
        <i class="fa fa-microphone"></i>&nbsp; <?php echo _("System Recordings") ?>
    </a>
    <button class="btn btn-info" onclick="refreshCallbackData()">
        <i class="fa fa-refresh"></i>&nbsp; <?php echo _("Refresh") ?>
    </button>
</div>

<table data-toolbar="#toolbar-callback-nav" data-cache="false" data-toggle="table" data-search="true" data-sort-name="extension" data-sort-order="asc" class="table table-striped" id="callback-queues-table">
    <thead>
        <tr>
            <th data-sortable="true" data-field="extension"><?php echo _('Queue') ?></th>
            <th data-sortable="true" data-field="description"><?php echo _("Description") ?></th>
            <th data-sortable="true" data-field="callback_enabled"><?php echo _("Callback Enabled") ?></th>
            <th data-sortable="true" data-field="pending_callbacks"><?php echo _("Pending Callbacks") ?></th>
            <th data-field="actions"><?php echo _("Actions") ?></th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($queues as $queue): ?>
            <tr>
                <td><?php echo htmlentities($queue['extension']) ?></td>
                <td><?php echo htmlentities($queue['description']) ?></td>
                <td>
                    <?php if ($queue['callback_enabled'] == _('Yes')): ?>
                        <span class="label label-success"><?php echo $queue['callback_enabled'] ?></span>
                    <?php else: ?>
                        <span class="label label-default"><?php echo $queue['callback_enabled'] ?></span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($queue['pending_callbacks'] > 0): ?>
                        <span class="badge badge-warning"><?php echo $queue['pending_callbacks'] ?></span>
                    <?php else: ?>
                        <span class="text-muted">0</span>
                    <?php endif; ?>
                </td>
                <td>
                    <div class="btn-group btn-group-xs">
                        <a href="?display=qcallback&view=queue&queue_id=<?php echo urlencode($queue['extension']) ?>" class="btn btn-primary" title="<?php echo _("View Callbacks") ?>">
                            <i class="fa fa-list"></i>
                        </a>
                        <a href="?display=qcallback&view=config&queue_id=<?php echo urlencode($queue['extension']) ?>" class="btn btn-default" title="<?php echo _("Configure Callback") ?>">
                            <i class="fa fa-cog"></i>
                        </a>
                        <a href="?display=queues&view=form&extdisplay=<?php echo urlencode($queue['extension']) ?>" class="btn btn-info" title="<?php echo _("Edit Queue") ?>">
                            <i class="fa fa-edit"></i>
                        </a>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<script type="text/javascript">
function refreshCallbackData() {
    location.reload();
}

$(document).ready(function() {
    // Initialize Bootstrap table if not already done
    if (typeof $.fn.bootstrapTable !== 'undefined') {
        $('#callback-queues-table').bootstrapTable();
    }
});
</script>
