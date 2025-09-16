<?php
// View for managing callbacks for a specific queue

// Helper function to get pending callbacks
if (!function_exists('queuecallback_get_pending_requests')) {
    function queuecallback_get_pending_requests($queue_id = '') {
        try {
            return FreePBX::Qcallback()->getPendingCallbackRequests($queue_id);
        } catch (Exception $e) {
            error_log("queuecallback_get_pending_requests error: " . $e->getMessage());
            return [];
        }
    }
}

$queue_id = $queue_id ?? '';
$callbacks = queuecallback_get_pending_requests($queue_id);

// Get queue info
$queue_info = null;
if (function_exists('queues_get')) {
    $queue_info = queues_get($queue_id);
}

$callback_config = FreePBX::Qcallback()->getQueueCallbackConfig($queue_id);
?>

<div class="row">
    <div class="col-md-12">
        <div class="panel panel-default">
            <div class="panel-heading">
                <h3 class="panel-title">
                    <?php echo _("Queue Information") ?>
                    <div class="pull-right">
                        <a href="?display=queues&view=form&extdisplay=<?php echo urlencode($queue_id) ?>" class="btn btn-xs btn-default">
                            <i class="fa fa-cog"></i> <?php echo _("Configure Queue") ?>
                        </a>
                    </div>
                </h3>
            </div>
            <div class="panel-body">
                <dl class="dl-horizontal">
                    <dt><?php echo _("Queue ID") ?>:</dt>
                    <dd><?php echo htmlentities($queue_id) ?></dd>
                    
                    <?php if ($queue_info): ?>
                        <dt><?php echo _("Description") ?>:</dt>
                        <dd><?php echo htmlentities($queue_info['name'] ?? '') ?></dd>
                    <?php endif; ?>
                    
                    <dt><?php echo _("Callback Status") ?>:</dt>
                    <dd>
                        <?php if ($callback_config['enabled']): ?>
                            <span class="label label-success"><?php echo _("Enabled") ?></span>
                        <?php else: ?>
                            <span class="label label-default"><?php echo _("Disabled") ?></span>
                        <?php endif; ?>
                    </dd>
                    
                    <?php if ($callback_config['enabled']): ?>
                        <dt><?php echo _("Callback Key") ?>:</dt>
                        <dd><?php echo htmlentities($callback_config['callback_key']) ?></dd>
                        
                        <dt><?php echo _("Processing Interval") ?>:</dt>
                        <dd><?php echo $callback_config['processing_interval'] ?> <?php echo _("minutes") ?></dd>
                    <?php endif; ?>
                </dl>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-12">
        <div class="panel panel-default">
            <div class="panel-heading">
                <h3 class="panel-title">
                    <?php echo _("Pending Callbacks") ?>
                    <span class="badge"><?php echo count($callbacks) ?></span>
                    <div class="pull-right">
                        <button class="btn btn-xs btn-success" onclick="exportCallbacks()">
                            <i class="fa fa-download"></i> <?php echo _("Export CSV") ?>
                        </button>
                        <button class="btn btn-xs btn-primary" onclick="refreshCallbacks()">
                            <i class="fa fa-refresh"></i> <?php echo _("Refresh") ?>
                        </button>
                    </div>
                </h3>
            </div>
            <div class="panel-body">
                <?php if (empty($callbacks)): ?>
                    <div class="alert alert-info">
                        <?php echo _("No pending callbacks for this queue.") ?>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-striped" id="callbacks-table">
                            <thead>
                                <tr>
                                    <th><?php echo _("ID") ?></th>
                                    <th><?php echo _("Caller") ?></th>
                                    <th><?php echo _("Callback Number") ?></th>
                                    <th><?php echo _("Status") ?></th>
                                    <th><?php echo _("Requested") ?></th>
                                    <th><?php echo _("Attempts") ?></th>
                                    <th><?php echo _("Actions") ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($callbacks as $callback): ?>
                                    <tr id="callback-<?php echo $callback['id'] ?>">
                                        <td><?php echo $callback['id'] ?></td>
                                        <td><?php echo htmlentities($callback['caller_id']) ?></td>
                                        <td><?php echo htmlentities($callback['callback_number']) ?></td>
                                        <td>
                                            <?php
                                            $status_class = '';
                                            switch ($callback['status']) {
                                                case 'pending':
                                                    $status_class = 'label-warning';
                                                    break;
                                                case 'processing':
                                                    $status_class = 'label-info';
                                                    break;
                                                case 'completed':
                                                    $status_class = 'label-success';
                                                    break;
                                                case 'failed':
                                                    $status_class = 'label-danger';
                                                    break;
                                                case 'cancelled':
                                                    $status_class = 'label-default';
                                                    break;
                                            }
                                            ?>
                                            <span class="label <?php echo $status_class ?>"><?php echo ucfirst($callback['status']) ?></span>
                                        </td>
                                        <td><?php echo date('Y-m-d H:i:s', $callback['time_requested']) ?></td>
                                        <td><?php echo $callback['attempts'] ?> / <?php echo $callback['max_attempts'] ?></td>
                                        <td>
                                            <?php if ($callback['status'] == 'pending'): ?>
                                                <button class="btn btn-xs btn-success" onclick="processCallback(<?php echo $callback['id'] ?>)">
                                                    <i class="fa fa-play"></i> <?php echo _("Process Now") ?>
                                                </button>
                                            <?php endif; ?>
                                            
                                            <?php if (in_array($callback['status'], ['pending', 'processing'])): ?>
                                                <button class="btn btn-xs btn-danger" onclick="cancelCallback(<?php echo $callback['id'] ?>)">
                                                    <i class="fa fa-times"></i> <?php echo _("Cancel") ?>
                                                </button>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
function refreshCallbacks() {
    location.reload();
}

function processCallback(callbackId) {
    if (confirm('<?php echo _("Process this callback now?") ?>')) {
        $.post('?display=qcallback', {
            action: 'process_callback',
            callback_id: callbackId
        }, function(response) {
            if (response.status === 'success') {
                alert('<?php echo _("Callback processing initiated") ?>');
                refreshCallbacks();
            } else {
                alert('<?php echo _("Failed to process callback") ?>');
            }
        }, 'json');
    }
}

function cancelCallback(callbackId) {
    if (confirm('<?php echo _("Cancel this callback request?") ?>')) {
        $.post('?display=qcallback', {
            action: 'cancel_callback',
            callback_id: callbackId
        }, function(response) {
            if (response.status === 'success') {
                alert('<?php echo _("Callback cancelled") ?>');
                refreshCallbacks();
            } else {
                alert('<?php echo _("Failed to cancel callback") ?>');
            }
        }, 'json');
    }
}

function exportCallbacks() {
    window.location.href = '?display=qcallback&action=export_csv&queue_id=<?php echo urlencode($queue_id) ?>';
}
</script>