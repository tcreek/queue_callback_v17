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
                            <span class="input-group-addon"><?php echo _("seconds") ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="row">
        <div class="col-md-12">
            <span id="callback_processing_interval-help" class="help-block fpbx-help-block"><?php echo _("How often the system should process pending callbacks, in seconds. Lower values provide faster callbacks but increase system load. Default: 30 seconds.") ?></span>
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

<!-- Number Confirmation -->
<div class="element-container callback-options" style="<?php echo ($callback_enabled == '1') ? '' : 'display:none;' ?>">
    <div class="row">
        <div class="col-md-12">
            <div class="row">
                <div class="form-group">
                    <div class="col-md-3">
                        <label class="control-label" for="callback_confirm_number"><?php echo _("Confirm Callback Number") ?></label>
                        <i class="fa fa-question-circle fpbx-help-icon" data-for="callback_confirm_number"></i>
                    </div>
                    <div class="col-md-9">
                        <select class="form-control" id="callback_confirm_number" name="callback_confirm_number">
                            <option value="1" <?php echo ($callback_config['confirm_number'] ?? 1) == 1 ? 'selected' : '' ?>><?php echo _("Yes - Ask caller to confirm number") ?></option>
                            <option value="0" <?php echo ($callback_config['confirm_number'] ?? 1) == 0 ? 'selected' : '' ?>><?php echo _("No - Use detected caller ID directly") ?></option>
                        </select>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="row">
        <div class="col-md-12">
            <span id="callback_confirm_number-help" class="help-block fpbx-help-block"><?php echo _("When enabled, the system reads back the detected caller ID. Callers hang up to confirm the number is correct, or press a key to enter a different number. When disabled, the system uses the detected caller ID directly.") ?></span>
        </div>
    </div>
</div>

<!-- Different Number Key (only show when confirm_number is enabled) -->
<div class="element-container callback-options confirm-keys-options" style="<?php echo ($callback_enabled == '1' && ($callback_config['confirm_number'] ?? 1) == 1) ? '' : 'display:none;' ?>">
    <div class="row">
        <div class="col-md-12">
            <div class="row">
                <div class="form-group">
                    <div class="col-md-3">
                        <label class="control-label" for="callback_alt_number_key"><?php echo _("Different Number Key") ?></label>
                        <i class="fa fa-question-circle fpbx-help-icon" data-for="callback_alt_number_key"></i>
                    </div>
                    <div class="col-md-9">
                        <select class="form-control" id="callback_alt_number_key" name="callback_alt_number_key" style="width: 80px;">
                            <?php for ($i = 0; $i <= 9; $i++): ?>
                                <option value="<?php echo $i ?>" <?php echo ($callback_config['alt_number_key'] ?? '2') == $i ? 'selected' : '' ?>><?php echo $i ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="row">
        <div class="col-md-12">
            <span id="callback_alt_number_key-help" class="help-block fpbx-help-block"><?php echo _("Key callers press if the detected number is incorrect or they want to be called back at a different number.") ?></span>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    // Function to toggle callback options visibility
    function toggleCallbackOptions() {
        if ($('#callback_enabled-yes').is(':checked')) {
            $('.callback-options').show();
            toggleConfirmKeyOptions();
        } else {
            $('.callback-options').hide();
        }
    }
    
    // Function to toggle confirm key options visibility
    function toggleConfirmKeyOptions() {
        if ($('#callback_confirm_number').val() == '1') {
            $('.confirm-keys-options').show();
        } else {
            $('.confirm-keys-options').hide();
        }
    }
    
    // Initial state
    toggleCallbackOptions();
    
    // Add event listeners
    $('#callback_enabled-yes, #callback_enabled-no').change(function() {
        toggleCallbackOptions();
    });
    
    $('#callback_confirm_number').change(function() {
        toggleConfirmKeyOptions();
    });
});
</script>