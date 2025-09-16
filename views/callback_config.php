<?php
// This file is included in the queues configuration form to add callback options

$callback_enabled = $callback_config['enabled'] ?? 0;
$callback_announce_id = $callback_config['announce_id'] ?? '';
$callback_key = $callback_config['callback_key'] ?? '*';
$callback_processing_interval = $callback_config['processing_interval'] ?? 5;

// Get available recordings for announcements
$recordings = array();
try {
    if (function_exists('recordings_list')) {
        $recordings = recordings_list();
        if (!is_array($recordings)) {
            $recordings = array();
        }
    }
} catch (Exception $e) {
    // If recordings module has issues, continue without recordings
    $recordings = array();
}
?>

<!-- Queue Callback Configuration Section -->
<div class="element-container">
    <div class="row">
        <div class="col-md-12">
            <div class="row">
                <div class="form-group">
                    <div class="col-md-3">
                        <label class="control-label"><?php echo _("Queue Callback") ?></label>
                        <i class="fa fa-question-circle fpbx-help-icon" data-for="callback_enabled"></i>
                    </div>
                    <div class="col-md-9 radioset">
                        <input type="radio" name="callback_enabled" id="callback_enabled-yes" value="1" <?php echo ($callback_enabled == '1') ? 'checked' : '' ?>>
                        <label for="callback_enabled-yes"><?php echo _("Yes") ?></label>
                        <input type="radio" name="callback_enabled" id="callback_enabled-no" value="0" <?php echo ($callback_enabled == '1') ? '' : 'checked' ?>>
                        <label for="callback_enabled-no"><?php echo _("No") ?></label>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="row">
        <div class="col-md-12">
            <span id="callback_enabled-help" class="help-block fpbx-help-block"><?php echo _("Enable callback functionality for this queue. When enabled, callers can press a key to request a callback instead of waiting in the queue.") ?></span>
        </div>
    </div>
</div>

<!-- Callback Announcement -->
<div class="element-container callback-options" style="<?php echo ($callback_enabled == '1') ? '' : 'display:none;' ?>">
    <div class="row">
        <div class="col-md-12">
            <div class="row">
                <div class="form-group">
                    <div class="col-md-3">
                        <label class="control-label" for="callback_announce_id"><?php echo _("Callback Announcement") ?></label>
                        <i class="fa fa-question-circle fpbx-help-icon" data-for="callback_announce_id"></i>
                    </div>
                    <div class="col-md-9">
                        <select class="form-control" id="callback_announce_id" name="callback_announce_id">
                            <option value=""><?php echo _("None") ?></option>
                            <?php if (empty($recordings)): ?>
                                <option value="" disabled><?php echo _("No recordings available - Create in System Recordings") ?></option>
                            <?php else: ?>
                                <?php foreach ($recordings as $recording): ?>
                                    <option value="<?php echo $recording['id'] ?>" <?php echo ($recording['id'] == $callback_announce_id) ? 'selected' : '' ?>>
                                        <?php echo htmlentities($recording['displayname']) ?>
                                    </option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="row">
        <div class="col-md-12">
            <span id="callback_announce_id-help" class="help-block fpbx-help-block"><?php echo _("Optional announcement explaining the callback option to callers. Example: 'To request a callback instead of waiting, press star.' Leave blank if no announcement needed. Create recordings in Admin → System Recordings.") ?></span>
        </div>
    </div>
</div>

<!-- Announcement Frequency -->
<div class="element-container callback-options" style="<?php echo ($callback_enabled == '1') ? '' : 'display:none;' ?>">
    <div class="row">
        <div class="col-md-12">
            <div class="row">
                <div class="form-group">
                    <div class="col-md-3">
                        <label class="control-label" for="callback_announce_frequency"><?php echo _("Announcement Frequency") ?></label>
                        <i class="fa fa-question-circle fpbx-help-icon" data-for="callback_announce_frequency"></i>
                    </div>
                    <div class="col-md-9">
                        <div class="input-group">
                            <input type="number" class="form-control" id="callback_announce_frequency" name="callback_announce_frequency" value="<?php echo $callback_config['announce_frequency'] ?? 1 ?>" min="1" max="15">
                            <span class="input-group-addon"><?php echo _("minutes") ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="row">
        <div class="col-md-12">
            <span id="callback_announce_frequency-help" class="help-block fpbx-help-block"><?php echo _("How often to play the callback announcement to callers waiting in the queue. Default: 1 minute.") ?></span>
        </div>
    </div>
</div>

<!-- Callback Key -->
<div class="element-container callback-options" style="<?php echo ($callback_enabled == '1') ? '' : 'display:none;' ?>">
    <div class="row">
        <div class="col-md-12">
            <div class="row">
                <div class="form-group">
                    <div class="col-md-3">
                        <label class="control-label" for="callback_key"><?php echo _("Callback Key") ?></label>
                        <i class="fa fa-question-circle fpbx-help-icon" data-for="callback_key"></i>
                    </div>
                    <div class="col-md-9">
                        <select class="form-control" id="callback_key" name="callback_key">
                            <?php
                            $keys = array('*' => '*', '#' => '#', '0' => '0', '1' => '1', '2' => '2', '3' => '3', '4' => '4', '5' => '5', '6' => '6', '7' => '7', '8' => '8', '9' => '9');
                            foreach ($keys as $key => $display) {
                                $selected = ($key == $callback_key) ? 'selected' : '';
                                echo "<option value=\"$key\" $selected>$display</option>";
                            }
                            ?>
                        </select>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="row">
        <div class="col-md-12">
            <span id="callback_key-help" class="help-block fpbx-help-block"><?php echo _("The DTMF key (0-9, *, #) that callers must press to request a callback. This key will be monitored while callers are waiting in the queue. Default is * (star). Make sure this key matches what you tell callers in your announcement.") ?></span>
        </div>
    </div>
</div>

<!-- Callback Processing Interval -->
<div class="element-container callback-options" style="<?php echo ($callback_enabled == '1') ? '' : 'display:none;' ?>">
    <div class="row">
        <div class="col-md-12">
            <div class="row">
                <div class="form-group">
                    <div class="col-md-3">
                        <label class="control-label" for="callback_processing_interval"><?php echo _("Processing Interval") ?></label>
                        <i class="fa fa-question-circle fpbx-help-icon" data-for="callback_processing_interval"></i>
                    </div>
                    <div class="col-md-9">
                        <div class="input-group">
                            <input type="number" class="form-control" id="callback_processing_interval" name="callback_processing_interval" value="<?php echo $callback_processing_interval ?>" min="1" max="60">
                            <span class="input-group-addon"><?php echo _("minutes") ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="row">
        <div class="col-md-12">
            <span id="callback_processing_interval-help" class="help-block fpbx-help-block"><?php echo _("How often the system should process pending callbacks. Lower values provide faster callbacks but increase system load. Default: 5 minutes.") ?></span>
        </div>
    </div>
</div>

<!-- Who to Call First -->
<div class="element-container callback-options" style="<?php echo ($callback_enabled == '1') ? '' : 'display:none;' ?>">
    <div class="row">
        <div class="col-md-12">
            <div class="row">
                <div class="form-group">
                    <div class="col-md-3">
                        <label class="control-label" for="callback_call_first"><?php echo _("Who to Call First") ?></label>
                        <i class="fa fa-question-circle fpbx-help-icon" data-for="callback_call_first"></i>
                    </div>
                    <div class="col-md-9">
                        <select class="form-control" id="callback_call_first" name="callback_call_first">
                            <option value="customer" <?php echo ($callback_config['call_first'] ?? 'customer') == 'customer' ? 'selected' : '' ?>><?php echo _("Customer") ?></option>
                            <option value="agent" <?php echo ($callback_config['call_first'] ?? 'customer') == 'agent' ? 'selected' : '' ?>><?php echo _("Agent") ?></option>
                        </select>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="row">
        <div class="col-md-12">
            <span id="callback_call_first-help" class="help-block fpbx-help-block"><?php echo _("Determines the order of the callback. 'Customer' calls the customer first and then connects them to the queue. 'Agent' connects an agent first and then calls the customer.") ?></span>
        </div>
    </div>
</div>

<!-- Note: Confirm Callback Number is now always enabled (hardcoded) -->

<!-- Note: Different Number Key is hardcoded as key 2 -->

<script>
$(document).ready(function() {
    // Function to toggle callback options visibility
    function toggleCallbackOptions() {
        if ($('#callback_enabled-yes').is(':checked')) {
            $('.callback-options').show();
        } else {
            $('.callback-options').hide();
        }
    }
    
    // Note: Confirm keys are now hardcoded (1=confirm, 2=different number)
    
    // Initial state
    toggleCallbackOptions();
    
    // Add event listeners
    $('#callback_enabled-yes, #callback_enabled-no').change(function() {
        toggleCallbackOptions();
    });
    
    // Confirm keys are hardcoded, no dynamic toggling needed
});
</script>