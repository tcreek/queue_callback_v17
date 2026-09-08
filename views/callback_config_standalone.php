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
// Standalone callback configuration page for a specific queue

$queue_id = $queue_id ?? '';
if (empty($queue_id)) {
    echo '<div class="alert alert-danger">Queue ID required</div>';
    return;
}

// Get current configuration
$callback_config = FreePBX::Qcallback()->getQueueCallbackConfig($queue_id);

// Get available system recordings for announcements
$recordings = array();
try {
    if (function_exists('recordings_list')) {
        $recordings = recordings_list();
    }
} catch (Exception $e) {
    $recordings = array();
}

// Get available outbound routes
$outbound_routes = array();
try {
    $db = FreePBX::Database();
    $stmt = $db->prepare("SELECT route_id, name FROM outbound_routes ORDER BY name");
    $stmt->execute();
    $outbound_routes = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (Exception $e) {
    $outbound_routes = array();
}

// Default announcement filenames
$default_announcements = array(
    'announce_id'         => 'Callback Announcement',
    'confirm_message_id'  => 'Number Confirmation',
    'alt_message_id'      => 'Alternate Number Instruction',
    'confirm_prompt_id'   => 'Callback Confirm Prompt',
    'return_message_id'   => 'Return Call Announcement',
    'initiated_message_id'=> 'Callback Initiated Announcement'
);

// Handle success/error messages from redirect
if (isset($_GET['saved']) && $_GET['saved'] == '1') {
    echo '<div class="alert alert-success">Configuration saved successfully!</div>';
}
if (isset($_GET['error'])) {
    echo '<div class="alert alert-danger">Error saving configuration: ' . htmlentities($_GET['error']) . '</div>';
}
?>

<div class="row">
    <div class="col-md-8">
        <div class="panel panel-default">
            <div class="panel-heading">
                <h3 class="panel-title">
                    <i class="fa fa-cog"></i> <?php echo _("Callback Configuration for Queue") ?> <?php echo htmlentities($queue_id) ?>
                </h3>
            </div>
            <div class="panel-body">
                <!-- Tab Navigation -->
                <ul class="nav nav-tabs" role="tablist">
                    <li role="presentation" class="active">
                        <a href="#general-tab" aria-controls="general-tab" role="tab" data-toggle="tab">
                            <i class="fa fa-cog"></i> <?php echo _("General") ?>
                        </a>
                    </li>
                    <li role="presentation">
                        <a href="#announcements-tab" aria-controls="announcements-tab" role="tab" data-toggle="tab">
                            <i class="fa fa-volume-up"></i> <?php echo _("Announcements") ?>
                        </a>
                    </li>
                </ul>

                <!-- Tab Content -->
                <form method="post" class="form-horizontal">
                    <input type="hidden" name="action" value="save_config">
                    
                    <div class="tab-content" style="margin-top: 20px;">
                        <!-- General Tab -->
                        <div role="tabpanel" class="tab-pane active" id="general-tab">
                    
                    <div class="form-group">
                        <label class="col-sm-3 control-label">
                            <?php echo _("Enable Callback") ?>
                            <i class="fa fa-question-circle" data-toggle="tooltip" data-placement="right" 
                               title="<?php echo _("Enable callback functionality for this queue. When enabled, callers can press the callback key to request a callback instead of waiting in the queue. When disabled, the callback feature will not be available to callers, but your configuration settings are preserved.") ?>"></i>
                        </label>
                        <div class="col-sm-9">
                            <select class="form-control" name="callback_enabled">
                                <option value="0" <?php echo !$callback_config['enabled'] ? 'selected' : '' ?>><?php echo _("No") ?></option>
                                <option value="1" <?php echo $callback_config['enabled'] ? 'selected' : '' ?>><?php echo _("Yes") ?></option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="col-sm-3 control-label">
                            <?php echo _("Outbound Route") ?>
                            <i class="fa fa-question-circle" data-toggle="tooltip" data-placement="right" 
                               title="<?php echo _("Select the outbound route to use for callback calls. This determines which trunk/route is used to call the customer or agent.") ?>"></i>
                        </label>
                        <div class="col-sm-9">
                            <select class="form-control" name="callback_outbound_route_id" style="width: 300px;">
                                <?php
                                $selected_route_id = $callback_config['outbound_route_id'] ?? 1;
                                if (!empty($outbound_routes) && !isset($outbound_routes[$selected_route_id])) {
                                    $selected_route_id = (int) array_keys($outbound_routes)[0];
                                }
                                ?>
                                <?php foreach ($outbound_routes as $route_id => $route_name): ?>
                                    <option value="<?php echo $route_id; ?>" <?php echo $selected_route_id == $route_id ? 'selected' : '' ?>><?php echo $route_name; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="col-sm-3 control-label">
                            <?php echo _("Callback Key") ?>
                            <i class="fa fa-question-circle" data-toggle="tooltip" data-placement="right" 
                               title="<?php echo _("Key callers press to request callback while in the queue. Common choices are * (asterisk) or # (pound). Default is *.") ?>"></i>
                        </label>
                        <div class="col-sm-9">
                            <input type="text" class="form-control" name="callback_key" 
                                   value="<?php echo htmlentities($callback_config['callback_key']) ?>" 
                                   maxlength="1" style="width: 60px;">
                        </div>
                    </div>
                    

                    
                    <div class="form-group">
                        <label class="col-sm-3 control-label">
                            <?php echo _("Processing Interval") ?>
                            <i class="fa fa-question-circle" data-toggle="tooltip" data-placement="right" 
                               title="<?php echo _("How often (in seconds) the system should check for and process pending callback requests. Lower values mean faster callbacks but more system load. Recommended: 30 seconds.") ?>"></i>
                        </label>
                        <div class="col-sm-9">
                            <div class="input-group" style="width: 120px;">
                                <input type="number" class="form-control" name="callback_processing_interval" 
                                       value="<?php echo $callback_config['processing_interval'] ?>" 
                                       min="1" max="120">
                                <span class="input-group-addon"><?php echo _("sec") ?></span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="col-sm-3 control-label">
                            <?php echo _("Maximum Attempts") ?>
                            <i class="fa fa-question-circle" data-toggle="tooltip" data-placement="right" 
                               title="<?php echo _("Maximum number of times the system will try to call back a customer before giving up. Recommended: 3 attempts.") ?>"></i>
                        </label>
                        <div class="col-sm-9">
                            <div class="input-group" style="width: 120px;">
                                <input type="number" class="form-control" name="callback_max_attempts" 
                                       value="<?php echo $callback_config['max_attempts'] ?? 3 ?>" 
                                       min="1" max="10">
                                <span class="input-group-addon"><?php echo _("tries") ?></span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="col-sm-3 control-label">
                            <?php echo _("Retry Interval") ?>
                            <i class="fa fa-question-circle" data-toggle="tooltip" data-placement="right" 
                               title="<?php echo _("Time to wait (in minutes) between callback attempts if the first attempt fails. Recommended: 5-15 minutes.") ?>"></i>
                        </label>
                        <div class="col-sm-9">
                            <div class="input-group" style="width: 120px;">
                                <input type="number" class="form-control" name="callback_retry_interval" 
                                       value="<?php echo $callback_config['retry_interval'] ?? 5 ?>" 
                                       min="1" max="120">
                                <span class="input-group-addon"><?php echo _("min") ?></span>
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="col-sm-3 control-label">
                            <?php echo _("Who to Call First") ?>
                            <i class="fa fa-question-circle" data-toggle="tooltip" data-placement="right" 
                               title="<?php echo _("Determines the order of the callback. 'Customer' calls the customer first and then connects them to the queue. 'Agent' connects an agent first and then calls the customer.") ?>"></i>
                        </label>
                        <div class="col-sm-9">
                            <select class="form-control" name="callback_call_first" style="width: 300px;">
                                <option value="customer" <?php echo ($callback_config['call_first'] ?? 'customer') == 'customer' ? 'selected' : '' ?>><?php echo _("Customer") ?></option>
                                <option value="agent" <?php echo ($callback_config['call_first'] ?? 'customer') == 'agent' ? 'selected' : '' ?>><?php echo _("Agent") ?></option>
                            </select>
                        </div>
                    </div>
                    

                    
                    <div class="form-group">
                        <label class="col-sm-3 control-label">
                            <?php echo _("Confirm Callback Number") ?>
                            <i class="fa fa-question-circle" data-toggle="tooltip" data-placement="right" 
                               title="<?php echo _("When enabled, the system reads back the detected caller ID. Callers hang up to confirm the number is correct, or press a key to enter a different number. When disabled, the system uses the detected caller ID directly.") ?>"></i>
                        </label>
                        <div class="col-sm-9">
                            <select class="form-control" name="callback_confirm_number" id="callback_confirm_number" style="width: 300px;">
                                <option value="1" <?php echo ($callback_config['confirm_number'] ?? 1) == 1 ? 'selected' : '' ?>><?php echo _("Yes - Ask caller to confirm number") ?></option>
                                <option value="0" <?php echo ($callback_config['confirm_number'] ?? 1) == 0 ? 'selected' : '' ?>><?php echo _("No - Use detected caller ID directly") ?></option>
                            </select>
                        </div>
                    </div>
                    

                    
                    <div class="form-group confirm-keys-group" style="<?php echo ($callback_config['confirm_number'] ?? 1) == 1 ? '' : 'display:none;' ?>">
                        <label class="col-sm-3 control-label">
                            <?php echo _("Different Number Key") ?>
                            <i class="fa fa-question-circle" data-toggle="tooltip" data-placement="right" 
                               title="<?php echo _("Key callers press if the detected number is incorrect or they want to be called back at a different number.") ?>"></i>
                        </label>
                        <div class="col-sm-9">
                            <select class="form-control" name="callback_alt_number_key" style="width: 80px;">
                                <?php for ($i = 0; $i <= 9; $i++): ?>
                                    <option value="<?php echo $i ?>" <?php echo ($callback_config['alt_number_key'] ?? '2') == $i ? 'selected' : '' ?>><?php echo $i ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                    </div>
                    
                        </div> <!-- End General Tab -->
                        
                        <!-- Announcements Tab -->
                        <div role="tabpanel" class="tab-pane" id="announcements-tab">
                            
                            <div class="form-group">
                                <label class="col-sm-3 control-label" style="white-space:nowrap;">
                                    <?php echo _("Queue Callback Instruction") ?>
                                    <i class="fa fa-question-circle" data-toggle="tooltip" data-placement="right" 
                                       title="<?php echo _("Announcement played AFTER the queue's initial announcement to instruct callers how to request a callback. Example: 'If you would like us to call you back instead of waiting, press star now.'") ?>"></i>
                                </label>
                                <div class="col-sm-9">
                                    <select class="form-control" name="callback_announce_id">
                                        <option value=""><?php echo _('Default') ?> - <?php echo htmlentities($default_announcements['announce_id']) ?></option>
                                        <?php foreach ($recordings as $recording): ?>
                                            <option value="<?php echo htmlentities($recording['id']) ?>" 
                                                    <?php echo ($callback_config['announce_id'] == $recording['id']) ? 'selected' : '' ?>>
                                                <?php echo htmlentities($recording['displayname']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label class="col-sm-3 control-label">
                                    <?php echo _("Instruction Frequency") ?>
                                    <i class="fa fa-question-circle" data-toggle="tooltip" data-placement="right" 
                                       title="<?php echo _("How often (in minutes) to play the callback instruction announcement to callers waiting in the queue. Default: 1 minute.") ?>"></i>
                                </label>
                                <div class="col-sm-9">
                                    <div class="input-group" style="width: 120px;">
                                        <input type="number" class="form-control" name="callback_announce_frequency" 
                                               value="<?php echo $callback_config['announce_frequency'] ?? 1 ?>" 
                                               min="1" max="15">
                                        <span class="input-group-addon"><?php echo _("min") ?></span>
                                    </div>
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="col-sm-3 control-label">
                                    <?php echo _("Alternate Number Instruction") ?>
                                    <i class="fa fa-question-circle" data-toggle="tooltip" data-placement="right" 
                                       title="<?php echo _("Announcement played when a caller presses the alternate number key. Example: 'Please enter the phone number you wish to be called at, followed by the pound sign.'") ?>"></i>
                                </label>
                                <div class="col-sm-9">
                                    <select class="form-control" name="callback_alt_message_id">
                                        <option value=""><?php echo _('Default') ?> - <?php echo htmlentities($default_announcements['alt_message_id']) ?></option>
                                        <?php foreach ($recordings as $recording): ?>
                                            <option value="<?php echo htmlentities($recording['id']) ?>" 
                                                    <?php echo ($callback_config['alt_message_id'] ?? '') == $recording['id'] ? 'selected' : '' ?>>
                                                <?php echo htmlentities($recording['displayname']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="col-sm-3 control-label">
                                    <?php echo _("Number Confirmation") ?>
                                    <i class="fa fa-question-circle" data-toggle="tooltip" data-placement="right" 
                                       title="<?php echo _("Announcement played to confirm the callback number with the caller. Example: 'We will call you back at [number]. If correct, press pound. Otherwise press 2.'") ?>"></i>
                                </label>
                                <div class="col-sm-9">
                                    <select class="form-control" name="callback_confirm_message_id">
                                        <option value=""><?php echo _('Default') ?> - <?php echo htmlentities($default_announcements['confirm_message_id']) ?></option>
                                        <?php foreach ($recordings as $recording): ?>
                                            <option value="<?php echo htmlentities($recording['id']) ?>" 
                                                    <?php echo ($callback_config['confirm_message_id'] ?? '') == $recording['id'] ? 'selected' : '' ?>>
                                                <?php echo htmlentities($recording['displayname']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="col-sm-3 control-label">
                                    <?php echo _("Callback Confirm Prompt") ?>
                                    <i class="fa fa-question-circle" data-toggle="tooltip" data-placement="right" 
                                       title="<?php echo _("Announcement played after the callback number confirmation to instruct the caller to press 1. Example: 'Press 1 to confirm.'") ?>"></i>
                                </label>
                                <div class="col-sm-9">
                                    <select class="form-control" name="callback_confirm_prompt_id">
                                        <option value=""><?php echo _('Default') ?> - <?php echo htmlentities($default_announcements['confirm_prompt_id']) ?></option>
                                        <?php foreach ($recordings as $recording): ?>
                                            <option value="<?php echo htmlentities($recording['id']) ?>" 
                                                    <?php echo ($callback_config['confirm_prompt_id'] ?? '') == $recording['id'] ? 'selected' : '' ?>>
                                                <?php echo htmlentities($recording['displayname']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="col-sm-3 control-label">
                                    <?php echo _("Callback Initiated Announcement") ?>
                                    <i class="fa fa-question-circle" data-toggle="tooltip" data-placement="right" 
                                       title="<?php echo _("Announcement played after the callback has been successfully scheduled. Example: 'Your callback request has been received. Someone will call you back shortly.'") ?>"></i>
                                </label>
                                <div class="col-sm-9">
                                    <select class="form-control" name="callback_initiated_message_id">
                                        <option value=""><?php echo _('Default') ?> - <?php echo htmlentities($default_announcements['initiated_message_id']) ?></option>
                                        <?php foreach ($recordings as $recording): ?>
                                            <option value="<?php echo htmlentities($recording['id']) ?>" 
                                                    <?php echo ($callback_config['initiated_message_id'] ?? '') == $recording['id'] ? 'selected' : '' ?>>
                                                <?php echo htmlentities($recording['displayname']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="col-sm-3 control-label">
                                    <?php echo _("Return Call Announcement") ?>
                                    <i class="fa fa-question-circle" data-toggle="tooltip" data-placement="right" 
                                       title="<?php echo _("Announcement played when the system calls the customer back. This should identify your business and explain that this is a return call. Example: 'This is Sales returning your call, please hold while we connect you.'") ?>"></i>
                                </label>
                                <div class="col-sm-9">
                                    <select class="form-control" name="callback_return_message_id">
                                        <option value=""><?php echo _('Default') ?> - <?php echo htmlentities($default_announcements['return_message_id']) ?></option>
                                        <?php foreach ($recordings as $recording): ?>
                                            <option value="<?php echo htmlentities($recording['id']) ?>" 
                                                    <?php echo ($callback_config['return_message_id'] == $recording['id']) ? 'selected' : '' ?>>
                                                <?php echo htmlentities($recording['displayname']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            
                        </div> <!-- End Announcements Tab -->
                        
                    </div> <!-- End tab-content -->
                    
                    <!-- Save Button (outside tabs) -->
                    <div class="form-group" style="margin-top: 30px;">
                        <div class="col-sm-offset-3 col-sm-9">
                            <button type="submit" class="btn btn-primary">
                                <i class="fa fa-save"></i> <?php echo _("Save Configuration") ?>
                            </button>
                            <a href="?display=qcallback&view=queue&queue_id=<?php echo urlencode($queue_id) ?>" class="btn btn-default">
                                <i class="fa fa-arrow-left"></i> <?php echo _("Back to Callbacks") ?>
                            </a>
                            <a href="?display=qcallback" class="btn btn-default">
                                <i class="fa fa-arrow-up"></i> <?php echo _("Back to Overview") ?>
                            </a>
                        </div>
                    </div>
                    
                </form>
            </div>
        </div>
    </div>
    
    <div class="col-md-4">
        <div class="panel panel-info">
            <div class="panel-heading">
                <h3 class="panel-title"><?php echo _("How to Create Return Announcements") ?></h3>
            </div>
            <div class="panel-body">
                <ol>
                    <li><?php echo _("Go to Admin → System Recordings") ?></li>
                    <li><?php echo _("Click 'Add Recording'") ?></li>
                    <li><?php echo _("Record your announcement, e.g.:") ?>
                        <ul>
                            <li><em>"This is Sales returning your call"</em></li>
                            <li><em>"Support is calling you back"</em></li>
                            <li><em>"Thank you for requesting a callback"</em></li>
                        </ul>
                    </li>
                    <li><?php echo _("Save the recording") ?></li>
                    <li><?php echo _("Return here and select it from the dropdown") ?></li>
                </ol>
            </div>
        </div>
        
        <div class="panel panel-warning">
            <div class="panel-heading">
                <h3 class="panel-title"><?php echo _("Important Notes") ?></h3>
            </div>
            <div class="panel-body">
                <ul>
                    <li><?php echo _("Changes require a dialplan reload") ?></li>
                    <li><?php echo _("Test your recordings before using them") ?></li>
                    <li><?php echo _("Keep announcements short and professional") ?></li>
                    <li><?php echo _("Different queues can have different announcements") ?></li>
                </ul>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    // Initialize Bootstrap tooltips
    $('[data-toggle="tooltip"]').tooltip();
    
    // Function to toggle confirm key options visibility
    function toggleConfirmKeyOptions() {
        if ($('#callback_confirm_number').val() == '1') {
            $('.confirm-keys-group').show();
        } else {
            $('.confirm-keys-group').hide();
        }
    }
    
    // Initial state
    toggleConfirmKeyOptions();
    
    // Add event listener
    $('#callback_confirm_number').change(function() {
        toggleConfirmKeyOptions();
    });
});
</script>