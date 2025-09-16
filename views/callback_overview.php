<?php
// Overview page showing queues with and without callback enabled

// Get all queues
$queues = array();
try {
    if (function_exists('queues_list')) {
        $queues = queues_list();
        if (!is_array($queues)) {
            $queues = array();
        }
    }
} catch (Exception $e) {
    // If queues module has issues, show empty list
    $queues = array();
}

// Get callback configurations and separate enabled/disabled
$enabled_queues = array();
$disabled_queues = array();

foreach ($queues as $queue) {
    $queue_id = $queue[0];
    $queue_desc = $queue[1];
    $callback_config = FreePBX::Qcallback()->getQueueCallbackConfig($queue_id);
    
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
    
    // Get pending callback count
    $pending_callbacks = queuecallback_get_pending_requests($queue_id);
    $pending_count = count($pending_callbacks);
    
    $queue_data = array(
        'id' => $queue_id,
        'description' => $queue_desc,
        'config' => $callback_config,
        'pending_count' => $pending_count
    );
    
    if ($callback_config['enabled']) {
        $enabled_queues[] = $queue_data;
    } else {
        $disabled_queues[] = $queue_data;
    }
}
?>

<div class="row">
    <div class="col-md-12">
        <div class="panel panel-success">
            <div class="panel-heading">
                <h3 class="panel-title">
                    <i class="fa fa-check-circle"></i> <?php echo _("Queues with Callback Enabled") ?>
                    <span class="badge"><?php echo count($enabled_queues) ?></span>
                </h3>
            </div>
            <div class="panel-body">
                <?php if (empty($enabled_queues)): ?>
                    <div class="alert alert-info">
                        <i class="fa fa-info-circle"></i> <?php echo _("No queues have callback enabled. Use the table below to add callback functionality to your queues.") ?>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-striped table-hover">
                            <thead>
                                <tr>
                                    <th><?php echo _("Queue") ?></th>
                                    <th><?php echo _("Description") ?></th>
                                    <th><?php echo _("Status") ?></th>
                                    <th><?php echo _("Callback Key") ?></th>
                                    <th><?php echo _("Processing Interval") ?></th>
                                    <th><?php echo _("Pending Callbacks") ?></th>
                                    <th><?php echo _("Actions") ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($enabled_queues as $queue): ?>
                                    <tr>
                                        <td><strong><?php echo htmlentities($queue['id']) ?></strong></td>
                                        <td><?php echo htmlentities($queue['description']) ?></td>
                                        <td>
                                            <span class="label label-success">
                                                <i class="fa fa-check"></i> <?php echo _("Enabled") ?>
                                            </span>
                                        </td>
                                        <td><code><?php echo htmlentities($queue['config']['callback_key']) ?></code></td>
                                        <td><?php echo $queue['config']['processing_interval'] ?> <?php echo _("min") ?></td>
                                        <td>
                                            <?php if ($queue['pending_count'] > 0): ?>
                                                <span class="badge badge-warning"><?php echo $queue['pending_count'] ?></span>
                                            <?php else: ?>
                                                <span class="text-muted">0</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="btn-group btn-group-sm">
                                                <a href="?display=qcallback&view=config&queue_id=<?php echo urlencode($queue['id']) ?>" 
                                                   class="btn btn-primary" title="<?php echo _("Configure Callback Settings") ?>">
                                                    <i class="fa fa-cog"></i> <?php echo _("Configure") ?>
                                                </a>
                                                <a href="?display=qcallback&view=queue&queue_id=<?php echo urlencode($queue['id']) ?>" 
                                                   class="btn btn-info" title="<?php echo _("View Pending Callbacks") ?>">
                                                    <i class="fa fa-list"></i> <?php echo _("View") ?>
                                                </a>
                                                <button class="btn btn-danger" 
                                                        onclick="removeCallback('<?php echo htmlentities($queue['id']) ?>')" 
                                                        title="<?php echo _("Remove Callback from Queue") ?>">
                                                    <i class="fa fa-minus"></i> <?php echo _("Remove") ?>
                                                </button>
                                            </div>
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

<div class="row">
    <div class="col-md-12">
        <div class="panel panel-default">
            <div class="panel-heading">
                <h3 class="panel-title">
                    <i class="fa fa-plus-circle"></i> <?php echo _("Available Queues") ?>
                    <span class="badge"><?php echo count($disabled_queues) ?></span>
                </h3>
            </div>
            <div class="panel-body">
                <?php if (empty($disabled_queues)): ?>
                    <div class="alert alert-success">
                        <i class="fa fa-check-circle"></i> <?php echo _("All queues have callback functionality enabled!") ?>
                    </div>
                <?php else: ?>
                    <p class="text-muted"><?php echo _("These queues do not have callback functionality enabled. Click 'Add Callback' to enable callback for a queue.") ?></p>
                    <div class="table-responsive">
                        <table class="table table-striped table-hover">
                            <thead>
                                <tr>
                                    <th><?php echo _("Queue") ?></th>
                                    <th><?php echo _("Description") ?></th>
                                    <th><?php echo _("Actions") ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($disabled_queues as $queue): ?>
                                    <tr>
                                        <td><strong><?php echo htmlentities($queue['id']) ?></strong></td>
                                        <td><?php echo htmlentities($queue['description']) ?></td>
                                        <td>
                                            <button class="btn btn-success btn-sm" 
                                                    onclick="addCallback('<?php echo htmlentities($queue['id']) ?>')" 
                                                    title="<?php echo _("Add Callback to Queue") ?>">
                                                <i class="fa fa-plus"></i> <?php echo _("Add Callback") ?>
                                            </button>
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

<div class="row">
    <div class="col-md-6">
        <div class="panel panel-info">
            <div class="panel-heading">
                <h3 class="panel-title"><?php echo _("Quick Stats") ?></h3>
            </div>
            <div class="panel-body">
                <?php
                $total_queues = count($queues);
                $enabled_count = count($enabled_queues);
                $total_pending = array_sum(array_column($enabled_queues, 'pending_count'));
                ?>
                <dl class="dl-horizontal">
                    <dt><?php echo _("Total Queues") ?>:</dt>
                    <dd><?php echo $total_queues ?></dd>
                    
                    <dt><?php echo _("Callback Enabled") ?>:</dt>
                    <dd><?php echo $enabled_count ?></dd>
                    
                    <dt><?php echo _("Total Pending") ?>:</dt>
                    <dd><?php echo $total_pending ?></dd>
                </dl>
            </div>
        </div>
    </div>
    
    <div class="col-md-6">
        <div class="panel panel-default">
            <div class="panel-heading">
                <h3 class="panel-title"><?php echo _("How to Use") ?></h3>
            </div>
            <div class="panel-body">
                <ol>
                    <li><?php echo _("Click 'Add Callback' to enable callback for a queue") ?></li>
                    <li><?php echo _("Click 'Configure' to set callback options") ?></li>
                    <li><?php echo _("Click 'View' to see pending callbacks") ?></li>
                    <li><?php echo _("Click 'Remove' to disable callback for a queue") ?></li>
                </ol>
            </div>
        </div>
    </div>
</div>

<script>
function addCallback(queueId) {
    if (confirm('<?php echo _("Enable callback functionality for queue") ?> ' + queueId + '?')) {
        $.post('?display=qcallback', {
            action: 'add_callback',
            queue_id: queueId
        }, function(response) {
            if (response.status === 'success') {
                location.reload();
            } else {
                alert('<?php echo _("Failed to add callback") ?>: ' + (response.message || '<?php echo _("Unknown error") ?>'));
            }
        }, 'json').fail(function() {
            alert('<?php echo _("Failed to add callback - please try again") ?>');
        });
    }
}

function removeCallback(queueId) {
    if (confirm('<?php echo _("Remove callback functionality from queue") ?> ' + queueId + '?\n\n<?php echo _("This will cancel any pending callbacks for this queue.") ?>')) {
        $.post('?display=qcallback', {
            action: 'remove_callback',
            queue_id: queueId
        }, function(response) {
            if (response.status === 'success') {
                location.reload();
            } else {
                alert('<?php echo _("Failed to remove callback") ?>: ' + (response.message || '<?php echo _("Unknown error") ?>'));
            }
        }, 'json').fail(function() {
            alert('<?php echo _("Failed to remove callback - please try again") ?>');
        });
    }
}
</script>