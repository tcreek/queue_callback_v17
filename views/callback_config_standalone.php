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
    'return_message_id'   => 'callback-return-confirm.wav',
    'initiated_message_id'=> 'Callback Initiated Announcement'
);

// Handle success/error messages from redirect
if (isset($_GET['saved']) && $_GET['saved'] == '1') {
    echo '<div class="alert alert-success">Configuration saved successfully!</div>';
}
if (isset($_GET['error'])) {
    echo '<div class="alert alert-danger">Error saving configuration: ' . htmlentities($_GET['error']) . '</div>';
}

// Security actions
$sec_msg = '';
$sec_err = '';
$qcb = FreePBX::Qcallback();
if (($_POST['sec_action'] ?? '') === 'add_entry') {
    $pattern = trim($_POST['pattern'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $area_code = trim($_POST['area_code'] ?? '');
    $queue_id = trim($_POST['queue_id'] ?? $queue_id);
    $enabled = isset($_POST['enabled']) ? 1 : 0;
    $sort_order = (int)($_POST['sort_order'] ?? 0);
    if ($pattern === '' || $description === '') {
        $sec_err = _('Pattern and Description are required');
    } else {
        try {
            $qcb->addSecurityEntry(['pattern'=>$pattern,'description'=>$description,'area_code'=>$area_code,'queue_id'=>$queue_id,'enabled'=>$enabled,'sort_order'=>$sort_order]);
            $sec_msg = _('Entry added');
        } catch (\Throwable $e) { $sec_err = $e->getMessage(); }
    }
} elseif (($_POST['sec_action'] ?? '') === 'update_entry') {
    $id = (int)($_POST['id'] ?? 0);
    $pattern = trim($_POST['pattern'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $area_code = trim($_POST['area_code'] ?? '');
    $enabled = isset($_POST['enabled']) ? 1 : 0;
    $sort_order = (int)($_POST['sort_order'] ?? 0);
    if ($pattern === '' || $description === '') {
        $sec_err = _('Pattern and Description are required');
    } else {
        try {
            $entry_data = ['pattern'=>$pattern,'description'=>$description,'area_code'=>$area_code,'enabled'=>$enabled,'sort_order'=>$sort_order];
            if (array_key_exists('queue_id', $_POST)) { $entry_data['queue_id'] = trim($_POST['queue_id']); }
            $qcb->updateSecurityEntry($id, $entry_data);
            $sec_msg = _('Entry updated');
        } catch (\Throwable $e) { $sec_err = $e->getMessage(); }
    }
} elseif (($_POST['sec_action'] ?? '') === 'delete_entry') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
        try {
            $qcb->deleteSecurityEntry($id);
            $sec_msg = _('Entry deleted');
        } catch (\Throwable $e) { $sec_err = $e->getMessage(); }
    }
} elseif (($_POST['sec_action'] ?? '') === 'toggle_entry') {
    $id = (int)($_POST['id'] ?? 0);
    $enabled = isset($_POST['enabled']) ? 1 : 0;
    if ($id > 0) {
        try {
            $qcb->toggleSecurityEntry($id, (bool)$enabled);
            $sec_msg = _('Entry updated');
        } catch (\Throwable $e) { $sec_err = $e->getMessage(); }
    }
}
if ($sec_msg) { echo '<div class="alert alert-success">' . htmlentities($sec_msg) . '</div>'; }
if ($sec_err) { echo '<div class="alert alert-danger">' . htmlentities($sec_err) . '</div>'; }
?>

<?php require_once __DIR__ . '/callback_form_style.php'; ?>

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
                    <li role="presentation">
                        <a href="#security-tab" aria-controls="security-tab" role="tab" data-toggle="tab">
                            <i class="fa fa-shield-alt"></i> <?php echo _("Security") ?>
                        </a>
                    </li>
                </ul>

                <!-- Tab Content -->
                <form method="post" class="form-horizontal">
                    <input type="hidden" name="action" value="save_config">
                    
                    <div class="tab-content" style="margin-top: 20px;">
                        <!-- General Tab -->
                        <div role="tabpanel" class="tab-pane active" id="general-tab">
                    
                    <div class="form-group qcb-field-row">
                        <label class="col-sm-3 control-label qcb-field-label">
                            <?php echo _("Enable Callback") ?>
                            <i class="fa fa-question-circle" data-toggle="tooltip" data-placement="right" 
                               title="<?php echo _("Enable callback functionality for this queue. When enabled, callers can press the callback key to request a callback instead of waiting in the queue. When disabled, the callback feature will not be available to callers, but your configuration settings are preserved.") ?>"></i>
                        </label>
                        <div class="col-sm-9 qcb-field-control">
                            <select class="form-control" name="callback_enabled">
                                <option value="0" <?php echo !$callback_config['enabled'] ? 'selected' : '' ?>><?php echo _("No") ?></option>
                                <option value="1" <?php echo $callback_config['enabled'] ? 'selected' : '' ?>><?php echo _("Yes") ?></option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-group qcb-field-row">
                        <label class="col-sm-3 control-label qcb-field-label">
                            <?php echo _("Outbound Route") ?>
                            <i class="fa fa-question-circle" data-toggle="tooltip" data-placement="right" 
                               title="<?php echo _("Route for external customer callback numbers in either call-first mode. A selected route must match the number and its time conditions; other routes are not tried. Automatic uses FreePBX route order. Internal extensions and queue agents use internal routing.") ?>"></i>
                        </label>
                        <div class="col-sm-9 qcb-field-control">
                            <select class="form-control" name="callback_outbound_route_id" style="width: 300px;">
                                <?php $selected_route_id = $callback_config['outbound_route_id'] ?? 0; ?>
                                <option value="0" <?php echo (int)$selected_route_id === 0 ? 'selected' : '' ?>><?php echo _("Automatic - FreePBX route order") ?></option>
                                <?php if ((int)$selected_route_id > 0 && !isset($outbound_routes[$selected_route_id])): ?>
                                    <option value="<?php echo (int)$selected_route_id ?>" selected><?php echo _("Unavailable route") ?> (<?php echo (int)$selected_route_id ?>)</option>
                                <?php endif; ?>
                                <?php foreach ($outbound_routes as $route_id => $route_name): ?>
                                    <option value="<?php echo (int)$route_id; ?>" <?php echo $selected_route_id == $route_id ? 'selected' : '' ?>><?php echo htmlentities($route_name, ENT_QUOTES); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

<div class="alert alert-warning" role="alert">
    <strong><?php echo _("Breakout IVR and callback keys") ?></strong>
    <?php echo _("The Breakout IVR selected in the Queues module remains available while Queue Callback is enabled. The callback key takes priority if the IVR uses the same key. Choose a callback key that is not used by the Breakout IVR menu. The Queue Callback Instruction selected here is the periodic announcement played while waiting.") ?>
</div>

                    <div class="form-group qcb-field-row">
                        <label class="col-sm-3 control-label qcb-field-label">
                            <?php echo _("Callback Key") ?>
                            <i class="fa fa-question-circle" data-toggle="tooltip" data-placement="right" 
                               title="<?php echo _("Key callers press to request callback while in the queue. Default is # (pound). You can also choose a digit or * (star).") ?>"></i>
                        </label>
                        <div class="col-sm-9 qcb-field-control">
                            <input type="text" class="form-control" name="callback_key" 
                                   value="<?php echo htmlentities(($callback_config['callback_key'] ?? '#')) ?>" 
                                   maxlength="1" style="width: 60px;">
                        </div>
                    </div>
                    

                    
                    <div class="form-group qcb-field-row">
                        <label class="col-sm-3 control-label qcb-field-label">
                            <?php echo _("Processing Interval") ?>
                            <i class="fa fa-question-circle" data-toggle="tooltip" data-placement="right" 
                               title="<?php echo _("Minimum delay in seconds before the first callback attempt. The processor checks about every 15 seconds. Default: 30 seconds.") ?>"></i>
                        </label>
                        <div class="col-sm-9 qcb-field-control">
                            <div class="input-group" style="width: 180px;">
                                <input type="number" class="form-control" name="callback_processing_interval" 
                                       value="<?php echo $callback_config['processing_interval'] ?>" 
                                       min="1" max="120">
                                <span class="input-group-addon"><?php echo _("sec") ?></span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-group qcb-field-row">
                        <label class="col-sm-3 control-label qcb-field-label">
                            <?php echo _("Maximum Attempts") ?>
                            <i class="fa fa-question-circle" data-toggle="tooltip" data-placement="right" 
                               title="<?php echo _("Maximum total callback attempts, including the first attempt. Default: 3 attempts.") ?>"></i>
                        </label>
                        <div class="col-sm-9 qcb-field-control">
                            <div class="input-group" style="width: 180px;">
                                <input type="number" class="form-control" name="callback_max_attempts" 
                                       value="<?php echo $callback_config['max_attempts'] ?? 3 ?>" 
                                       min="1" max="10">
                                <span class="input-group-addon"><?php echo _("tries") ?></span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-group qcb-field-row">
                        <label class="col-sm-3 control-label qcb-field-label">
                            <?php echo _("Retry Interval") ?>
                            <i class="fa fa-question-circle" data-toggle="tooltip" data-placement="right" 
                               title="<?php echo _("Minimum time to wait after an unsuccessful attempt ends before retrying. Starts at 1 minute. The processor checks about every 15 seconds.") ?>"></i>
                        </label>
                        <div class="col-sm-9 qcb-field-control">
                            <div class="input-group" style="width: 180px;">
                                <input type="number" class="form-control" name="callback_retry_minutes"
                                       value="<?php echo max(1, (int)ceil(($callback_config['retry_interval'] ?? 60) / 60)) ?>"
                                       min="1" max="120" step="1">
                                <span class="input-group-addon"><?php echo _("minutes") ?></span>
                            </div>
                        </div>
                    </div>

                    <div class="form-group qcb-field-row">
                        <label class="col-sm-3 control-label qcb-field-label">
                            <?php echo _("Who to Call First") ?>
                            <i class="fa fa-question-circle" data-toggle="tooltip" data-placement="right" 
                               title="<?php echo _("Determines the order of the callback. 'Customer' calls the customer first and then connects them to the queue. 'Agent' connects an agent first and then calls the customer.") ?>"></i>
                        </label>
                        <div class="col-sm-9 qcb-field-control">
                            <select class="form-control" name="callback_call_first" style="width: 300px;">
                                <option value="customer" <?php echo ($callback_config['call_first'] ?? 'customer') == 'customer' ? 'selected' : '' ?>><?php echo _("Customer") ?></option>
                                <option value="agent" <?php echo ($callback_config['call_first'] ?? 'customer') == 'agent' ? 'selected' : '' ?>><?php echo _("Agent") ?></option>
                            </select>
                        </div>
                    </div>
                    

                    
                    <div class="form-group qcb-field-row">
                        <label class="col-sm-3 control-label qcb-field-label">
                            <?php echo _("Confirm Callback Number") ?>
                            <i class="fa fa-question-circle" data-toggle="tooltip" data-placement="right" 
                               title="<?php echo _("When enabled, read back the number for confirmation. Accepting an entered alternate number with 1 also saves the callback request. For the detected caller ID, the final callback-confirmation prompt still requires a fresh press 1; digits during that prompt are ignored. Timeout or hangup cancels. When disabled, use the detected caller ID without these request prompts. Return-call confirmation is separate.") ?>"></i>
                        </label>
                        <div class="col-sm-9 qcb-field-control">
                            <select class="form-control" name="callback_confirm_number" id="callback_confirm_number" style="width: 300px;">
                                <option value="1" <?php echo ($callback_config['confirm_number'] ?? 1) == 1 ? 'selected' : '' ?>><?php echo _("Yes - Ask caller to confirm number") ?></option>
                                <option value="0" <?php echo ($callback_config['confirm_number'] ?? 1) == 0 ? 'selected' : '' ?>><?php echo _("No - Use detected caller ID directly") ?></option>
                            </select>
                        </div>
                    </div>
                    

                    
                    <div class="form-group confirm-keys-group qcb-field-row" style="<?php echo ($callback_config['confirm_number'] ?? 1) == 1 ? '' : 'display:none;' ?>">
                        <label class="col-sm-3 control-label qcb-field-label">
                            <?php echo _("Different Number Key") ?>
                            <i class="fa fa-question-circle" data-toggle="tooltip" data-placement="right" 
                               title="<?php echo _("Key callers press in the confirmation menu to enter a different number. 1 is reserved for confirmation. Match the instruction recording to the selected key. None disables alternate-number entry.") ?>"></i>
                        </label>
                        <div class="col-sm-9 qcb-field-control">
                            <select class="form-control" name="callback_alt_number_key" style="width: 80px;">
                                <option value="" <?php echo (string)($callback_config['alt_number_key'] ?? '2') === '' ? 'selected' : '' ?>><?php echo _("None") ?></option>
                                <?php foreach (['0','1','2','3','4','5','6','7','8','9','*','#'] as $alt_key): ?>
                                    <option value="<?php echo $alt_key ?>" <?php echo (string)($callback_config['alt_number_key'] ?? '2') === $alt_key ? 'selected' : '' ?> <?php echo $alt_key === '1' && (string)($callback_config['alt_number_key'] ?? '2') !== '1' ? 'disabled' : '' ?>><?php echo $alt_key === '1' ? _('1 (reserved for confirmation)') : $alt_key ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                        </div> <!-- End General Tab -->
                        
                        <!-- Announcements Tab -->
                        <div role="tabpanel" class="tab-pane" id="announcements-tab">
                            
                            <div class="form-group qcb-field-row">
                                <label class="col-sm-3 control-label qcb-field-label" style="white-space:nowrap;">
                                    <?php echo _("Queue Callback Instruction") ?>
                                    <i class="fa fa-question-circle" data-toggle="tooltip" data-placement="right" 
                                       title="<?php echo _("Announcement played AFTER the queue's initial announcement to instruct callers how to request a callback. Example: 'If you would like us to call you back instead of waiting, press pound (#) now.'") ?>"></i>
                                </label>
                                <div class="col-sm-9 qcb-field-control">
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
                            
                            <div class="form-group qcb-field-row">
                                <label class="col-sm-3 control-label qcb-field-label">
                                    <?php echo _("Instruction Frequency") ?>
                                    <i class="fa fa-question-circle" data-toggle="tooltip" data-placement="right" 
                                       title="<?php echo _("How often to play the Queue Callback Instruction while waiting in the queue. Default: 30 seconds. This interval controls the first announcement and subsequent repeats.") ?>"></i>
                                </label>
                                <div class="col-sm-9 qcb-field-control">
                                    <div class="input-group" style="width: 180px;">
                                        <select class="form-control" id="callback_announce_frequency" name="callback_announce_frequency">
                                            <?php foreach (range(0, 15) as $frequency): ?>
                                                <option value="<?php echo $frequency ?>" <?php echo (int)($callback_config['announce_frequency'] ?? 0) === $frequency ? 'selected' : '' ?>><?php echo $frequency === 0 ? 30 : $frequency * 60 ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <span class="input-group-addon"><?php echo _("seconds") ?></span>
                                    </div>
                                </div>
                            </div>

                            <div class="form-group qcb-field-row">
                                <label class="col-sm-3 control-label qcb-field-label">
                                    <?php echo _("Alternate Number Instruction") ?>
                                    <i class="fa fa-question-circle" data-toggle="tooltip" data-placement="right" 
                                       title="<?php echo _("Announcement played when a caller presses the alternate number key. Example: 'Please enter the phone number you wish to be called at, followed by the pound sign.'") ?>"></i>
                                </label>
                                <div class="col-sm-9 qcb-field-control">
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

                            <div class="form-group qcb-field-row">
                                <label class="col-sm-3 control-label qcb-field-label">
                                    <?php echo _("Number Confirmation") ?>
                                    <i class="fa fa-question-circle" data-toggle="tooltip" data-placement="right" 
                                       title="<?php echo _("Announcement played after reading back the number. Instruct the caller to press 1 if correct, or the configured Different Number Key to enter another number. Match the recording to that setting.") ?>"></i>
                                </label>
                                <div class="col-sm-9 qcb-field-control">
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

                            <div class="form-group qcb-field-row">
                                <label class="col-sm-3 control-label qcb-field-label">
                                    <?php echo _("Callback Confirm Prompt") ?>
                                    <i class="fa fa-question-circle" data-toggle="tooltip" data-placement="right" 
                                       title="<?php echo _("When Confirm Callback Number is enabled, this prompt plays completely before accepting a fresh press 1 to request the callback. Digits during playback are ignored. Timeout, another key or hangup cancels. This is separate from return-call confirmation.") ?>"></i>
                                </label>
                                <div class="col-sm-9 qcb-field-control">
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

                            <div class="form-group qcb-field-row">
                                <label class="col-sm-3 control-label qcb-field-label">
                                    <?php echo _("Callback Initiated Announcement") ?>
                                    <i class="fa fa-question-circle" data-toggle="tooltip" data-placement="right" 
                                       title="<?php echo _("Announcement played after the callback has been successfully scheduled. Example: 'Your callback request has been received. Someone will call you back shortly.'") ?>"></i>
                                </label>
                                <div class="col-sm-9 qcb-field-control">
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

                            <div class="form-group qcb-field-row">
                                <label class="col-sm-3 control-label qcb-field-label">
                                    <?php echo _("Return Call Announcement - Confirmation") ?>
                                    <i class="fa fa-question-circle" data-toggle="tooltip" data-placement="right" 
                                       title="<?php echo _("Announces the return call and asks the caller to press 1 to connect. This prevents a voicemail answer from counting as a completed callback. If the caller does not confirm or accidentally hangs up before connecting, the attempt is unsuccessful and follows the configured retry interval and maximum attempts. Custom recordings must also ask the caller to press 1.") ?>"></i>
                                </label>
                                <div class="col-sm-9 qcb-field-control">
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
                            

                            <div class="form-group qcb-field-row">
                                <label class="col-sm-3 control-label qcb-field-label" for="callback_return_confirm_timeout"><?php echo _("Return Confirmation Timeout") ?>
                                    <i class="fa fa-question-circle" data-toggle="tooltip" title="<?php echo _("Time to wait for the customer to press 1 after each announcement finishes. Default: 10 seconds. Applies in both callback modes.") ?>"></i>
                                </label>
                                <div class="col-sm-9 qcb-field-control"><div class="input-group" style="width: 180px;">
                                    <input type="number" class="form-control" id="callback_return_confirm_timeout" name="callback_return_confirm_timeout" value="<?php echo (int)($callback_config['return_confirm_timeout'] ?? 10) ?>" min="1" max="60" step="1">
                                    <span class="input-group-addon"><?php echo _("seconds") ?></span>
                                </div></div>
                            </div>

                            <div class="form-group qcb-field-row">
                                <label class="col-sm-3 control-label qcb-field-label" for="callback_return_confirm_repeats"><?php echo _("Return Confirmation Repeats") ?>
                                    <i class="fa fa-question-circle" data-toggle="tooltip" title="<?php echo _("Additional plays after no response or an invalid key. Default: 1 repeat (2 plays total). Set 0 to play once. Repeats stay within the same callback attempt; only a failed call uses the callback retry interval.") ?>"></i>
                                </label>
                                <div class="col-sm-9 qcb-field-control"><div class="input-group" style="width: 180px;">
                                    <input type="number" class="form-control" id="callback_return_confirm_repeats" name="callback_return_confirm_repeats" value="<?php echo (int)($callback_config['return_confirm_repeats'] ?? 1) ?>" min="0" max="5" step="1">
                                    <span class="input-group-addon"><?php echo _("repeats") ?></span>
                                </div></div>
                            </div>
                        </div> <!-- End Announcements Tab -->

                        <!-- Security Tab -->
                        <div role="tabpanel" class="tab-pane" id="security-tab">
                            <h3><i class="fa fa-shield-alt"></i> <?php echo _("Toll Fraud Prevention - Blocklist") ?></h3>
                            <p class="text-muted"><?php echo _("Enabled global rules and rules for this queue both apply. Patterns determine which callback destinations are blocked; area code is a label only. A leading + is optional, but country codes are not added or removed.") ?></p>
                            <?php $sec_entries = $qcb->getSecurityEntries($queue_id); ?>

                            <div class="table-responsive" style="margin-bottom: 20px;">
                                <table class="table table-striped table-hover">
                                    <thead>
                                        <tr>
                                            <th><?php echo _("Description") ?></th>
                                            <th><?php echo _("Asterisk Pattern") ?></th>
                                            <th><?php echo _("Area Code") ?></th>
                                            <th><?php echo _("Scope") ?></th>
                                            <th><?php echo _("Enabled") ?></th>
                                            <th><?php echo _("Actions") ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($sec_entries)): ?>
                                            <tr><td colspan="6" class="text-center"><?php echo _("No entries configured") ?></td></tr>
                                        <?php else: ?>
                                            <?php foreach ($sec_entries as $se):
                                                $scope = ($se['queue_id'] === '')
                                                    ? '<span class="label label-info">' . _("Global") . '</span>'
                                                    : '<span class="label label-info">Queue ' . htmlentities($se['queue_id']) . '</span>';
                                            ?>
                                                <tr>
                                                    <td><strong><?php echo htmlentities($se['description']) ?></strong></td>
                                                    <td><code><?php echo htmlentities($se['pattern']) ?></code></td>
                                                    <td><?php echo htmlentities($se['area_code']) ?></td>
                                                    <td><?php echo $scope ?></td>
                                                     <td>
                                                        <div style="display:inline">
                                                            <input form="qcb-security-toggle-<?php echo (int)$se['id'] ?>" type="hidden" name="sec_action" value="toggle_entry">
                                                            <input form="qcb-security-toggle-<?php echo (int)$se['id'] ?>" type="hidden" name="id" value="<?php echo (int)$se['id'] ?>">
                                                            <label class="switch">
                                                                <input form="qcb-security-toggle-<?php echo (int)$se['id'] ?>" type="checkbox" name="enabled" onchange="this.form.requestSubmit()" <?php echo $se['enabled'] ? 'checked' : '' ?>>
                                                                <span class="slider"></span>
                                                            </label>
                                                        </div>
                                                    </td>
                                                    <td>
                                                        <div style="display:inline">
                                                            <input form="qcb-security-delete-<?php echo (int)$se['id'] ?>" type="hidden" name="sec_action" value="delete_entry">
                                                            <input form="qcb-security-delete-<?php echo (int)$se['id'] ?>" type="hidden" name="id" value="<?php echo (int)$se['id'] ?>">
                                                            <button form="qcb-security-delete-<?php echo (int)$se['id'] ?>" onclick="return confirm('Delete this blocklist entry?');" type="submit" class="btn btn-xs btn-danger">
                                                                <i class="fa fa-trash"></i>
                                                            </button>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>

                            <h4><?php echo _("Add New Entry") ?></h4>
                            <div class="form-horizontal">
                                <input form="qcb-security-add" type="hidden" name="sec_action" value="add_entry">
                                <input form="qcb-security-add" type="hidden" name="queue_id" value="<?php echo htmlentities($queue_id) ?>">
                                <div class="form-group">
                                    <label class="col-sm-3 control-label"><?php echo _("Human Description") ?></label>
                                    <div class="col-sm-9">
                                        <input form="qcb-security-add" type="text" class="form-control" name="description" placeholder="Antigua and Barbuda" required>
                                        <p class="help-block"><?php echo _("Human readable name. Example: Antigua and Barbuda, Jamaica, UK, etc.") ?></p>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label class="col-sm-3 control-label"><?php echo _("Asterisk Pattern") ?></label>
                                    <div class="col-sm-9">
                                        <input form="qcb-security-add" type="text" class="form-control" name="pattern" maxlength="20" placeholder="_268NXXXXXX" required>
                                        <p class="help-block"><?php echo _("Asterisk dial plan pattern. _NXXNXXXXXX = any 10-digit NANP. N=2-9, X=0-9. Example: _876NXXXXXX = Jamaica.") ?></p>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label class="col-sm-3 control-label"><?php echo _("Area Code") ?></label>
                                    <div class="col-sm-9">
                                        <input form="qcb-security-add" type="text" class="form-control" name="area_code" placeholder="876" maxlength="10">
                                    </div>
                                </div>
                                <div class="form-group">
                                    <div class="col-sm-offset-3 col-sm-9">
                                        <label class="checkbox-inline">
                                            <input form="qcb-security-add" type="checkbox" name="enabled" checked title="<?php echo _("Enabled rules block matching destinations. Disabling a rule does not override other matching rules.") ?>"> <?php echo _("Enabled") ?>
                                            <i class="fa fa-question-circle" data-toggle="tooltip" data-placement="top" title="<?php echo _("Enabled rules block matching destinations. Disabling a rule does not override other matching rules.") ?>" style="font-size: 14px; padding-left: 4px; cursor: help;"></i>
                                        </label>
                                        <label class="checkbox-inline" style="margin-left: 20px;">
                                            <input form="qcb-security-add" type="number" class="form-control" name="sort_order" value="0" min="0" max="999" style="width: 80px; display: inline; margin-right: 5px;"> <?php echo _("Sort Order") ?>
                                        </label>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <div class="col-sm-offset-3 col-sm-9">
                                        <button form="qcb-security-add" type="submit" class="btn btn-danger">
                                            <i class="fa fa-plus"></i> <?php echo _("Add Blocklist Entry") ?>
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <div class="alert alert-warning">
                                <strong><?php echo _("Note:") ?></strong> <?php echo _("Blocked numbers will be rejected when callbacks are processed. The system checks the blocklist before placing any callback call.") ?>
                            </div>

                            <div class="panel panel-info" style="margin-top: 20px;">
                                <div class="panel-heading">
                                    <h4 class="panel-title"><i class="fa fa-globe"></i> <?php echo _("Adding International Numbers") ?></h4>
                                </div>
                                <div class="panel-body" style="font-size: 15px; line-height: 1.6;">
                                    <p class="text-muted"><?php echo _("To block other countries, add entries using Asterisk patterns:") ?></p>
                                    <div class="row">
                                        <div class="col-md-6">
                                            <ul class="list-unstyled">
                                                <li><strong>Country code + area code</strong> - e.g., <code>_52NNXXXXXXXX</code> for Mexico (52) — 12 digits</li>
                                                <li><strong>Full international</strong> - e.g., <code>_44NXXXXXXXXXX</code> for UK (44) — 12 digits</li>
                                            </ul>
                                        </div>
                                        <div class="col-md-6">
                                            <p class="text-muted"><?php echo _("Pattern guide: use an exact number, or start with _ and use digits, X (0-9), Z (1-9), N (2-9), numeric sets/ranges, and a final . (one or more digits) or ! (zero or more digits). Maximum 20 characters. Global and queue rules both apply. Area code is a label only.") ?></p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <!-- End Security Tab -->

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
                <form id="qcb-security-add" method="post"></form>
                <?php foreach ($sec_entries as $se): ?>
                    <form id="qcb-security-toggle-<?php echo (int)$se['id'] ?>" method="post"></form>
                    <form id="qcb-security-delete-<?php echo (int)$se['id'] ?>" method="post"></form>
                <?php endforeach; ?>

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
                    <li><?php echo _("Go to Admin") ?> → <?php echo _("System Recordings") ?></li>
                    <li><?php echo _("Click") ?> <?php echo _("Add Recording") ?></li>
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
