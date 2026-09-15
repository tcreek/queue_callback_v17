<?php
if (!defined('FREEPBX_IS_AUTH')) { die('No direct script access allowed'); }

$action = $_REQUEST['action'] ?? '';
$success = '';
$error = '';

$qcallback = FreePBX::Qcallback();

switch ($action) {
    case 'add_entry':
        $pattern = trim($_POST['pattern'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $area_code = trim($_POST['area_code'] ?? '');
        $enabled = isset($_POST['enabled']) ? 1 : 0;
        $sort_order = (int)($_POST['sort_order'] ?? 0);
        if (empty($pattern) || empty($description)) {
            $error = _('Pattern and Description are required');
        } else {
            try {
                $qcallback->addSecurityEntry([
                    'pattern' => $pattern,
                    'description' => $description,
                    'area_code' => $area_code,
                    'enabled' => $enabled,
                    'sort_order' => $sort_order,
                ]);
                $success = _('Entry added successfully');
            } catch (\Throwable $e) {
                $error = 'Error adding entry: ' . $e->getMessage();
            }
        }
        break;

    case 'update_entry':
        $id = (int)($_POST['id'] ?? 0);
        $pattern = trim($_POST['pattern'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $area_code = trim($_POST['area_code'] ?? '');
        $enabled = isset($_POST['enabled']) ? 1 : 0;
        $sort_order = (int)($_POST['sort_order'] ?? 0);
        if (empty($pattern) || empty($description)) {
            $error = _('Pattern and Description are required');
        } else {
            try {
                $qcallback->updateSecurityEntry($id, [
                    'pattern' => $pattern,
                    'description' => $description,
                    'area_code' => $area_code,
                    'enabled' => $enabled,
                    'sort_order' => $sort_order,
                ]);
                $success = _('Entry updated successfully');
            } catch (\Throwable $e) {
                $error = 'Error updating entry: ' . $e->getMessage();
            }
        }
        break;

    case 'delete_entry':
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            try {
                $qcallback->deleteSecurityEntry($id);
                $success = _('Entry deleted successfully');
            } catch (\Throwable $e) {
                $error = 'Error deleting entry: ' . $e->getMessage();
            }
        }
        break;

    case 'toggle_entry':
        $id = (int)($_POST['id'] ?? 0);
        $enabled = isset($_POST['enabled']) ? 1 : 0;
        if ($id > 0) {
            try {
                $qcallback->toggleSecurityEntry($id, (bool)$enabled);
                $success = _('Entry updated successfully');
            } catch (\Throwable $e) {
                $error = 'Error updating entry: ' . $e->getMessage();
            }
        }
        break;
}

$entries = $qcallback->getSecurityEntries();

$heading = _('Security - Toll Fraud Prevention');
?>

<div class="container-fluid">
    <h1><?php echo $heading ?></h1>

    <?php if ($success): ?>
        <div class="alert alert-success"><?php echo htmlentities($success) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-danger"><?php echo htmlentities($error) ?></div>
    <?php endif; ?>

    <div class="row">
        <div class="col-md-8">
            <div class="panel panel-default">
                <div class="panel-heading">
                    <h3 class="panel-title">
                        <i class="fa fa-shield-alt"></i> <?php echo _('Blocklist Entries') ?>
                        <span class="badge"><?php echo count($entries) ?></span>
                    </h3>
                </div>
                <div class="panel-body">
                    <?php if (empty($entries)): ?>
                        <div class="alert alert-info"><?php echo _('No blocklist entries configured') ?></div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-striped table-hover">
                                <thead>
                                    <tr>
                                        <th><?php echo _('ID') ?></th>
                                        <th><?php echo _('Description') ?></th>
                                        <th><?php echo _('Pattern') ?></th>
                                        <th><?php echo _('Area Code') ?></th>
                                        <th><?php echo _('Enabled') ?></th>
                                        <th><?php echo _('Actions') ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($entries as $entry): ?>
                                        <tr id="entry-<?php echo $entry['id'] ?>">
                                            <td><?php echo (int)$entry['id'] ?></td>
                                            <td><?php echo htmlentities($entry['description']) ?></td>
                                            <td><code><?php echo htmlentities($entry['pattern']) ?></code></td>
                                            <td><?php echo htmlentities($entry['area_code']) ?></td>
                                            <td>
                                                <form method="post" style="display:inline">
                                                    <input type="hidden" name="action" value="toggle_entry">
                                                    <input type="hidden" name="id" value="<?php echo (int)$entry['id'] ?>">
                                                    <label class="switch">
                                                        <input type="checkbox" name="enabled" onchange="this.form.submit()" <?php echo $entry['enabled'] ? 'checked' : '' ?>>
                                                        <span class="slider"></span>
                                                    </label>
                                                </form>
                                            </td>
                                            <td>
                                                <button class="btn btn-xs btn-warning btn-edit-entry" data-id="<?php echo (int)$entry['id'] ?>" data-pattern="<?php echo htmlentities($entry['pattern'], ENT_QUOTES) ?>" data-description="<?php echo htmlentities($entry['description'], ENT_QUOTES) ?>" data-area="<?php echo htmlentities($entry['area_code'], ENT_QUOTES) ?>" data-enabled="<?php echo (int)$entry['enabled'] ?>" data-sort="<?php echo (int)$entry['sort_order'] ?>" title="<?php echo _('Edit') ?>">
                                                    <i class="fa fa-edit"></i>
                                                </button>
                                                <form method="post" style="display:inline" onsubmit="return confirm('<?php echo _("Delete") ?> <?php echo htmlentities($entry['description'], ENT_QUOTES) ?>?');">
                                                    <input type="hidden" name="action" value="delete_entry">
                                                    <input type="hidden" name="id" value="<?php echo (int)$entry['id'] ?>">
                                                    <button type="submit" class="btn btn-xs btn-danger" title="<?php echo _('Delete') ?>">
                                                        <i class="fa fa-trash"></i>
                                                    </button>
                                                </form>
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

        <div class="col-md-4">
            <div class="panel panel-info">
                <div class="panel-heading">
                    <h3 class="panel-title">
                        <i class="fa fa-plus-circle"></i> <?php echo _('Add Entry') ?>
                    </h3>
                </div>
                <div class="panel-body">
                    <form method="post" id="security-add-form">
                        <input type="hidden" name="action" value="add_entry">
                        <div class="form-group">
                            <label for="pattern"><?php echo _('Asterisk Pattern') ?></label>
                            <input type="text" class="form-control" name="pattern" id="pattern" placeholder="_268NXXXXXX" required>
                            <p class="help-block"><?php echo _('Example: _268NXXXXXX matches any 10-digit number starting with 268. N=2-9, X=0-9.') ?></p>
                        </div>
                        <div class="form-group">
                            <label for="description"><?php echo _('Description') ?></label>
                            <input type="text" class="form-control" name="description" id="description" placeholder="Antigua and Barbuda (268)" required>
                        </div>
                        <div class="form-group">
                            <label for="area_code"><?php echo _('Area/Country Code') ?></label>
                            <input type="text" class="form-control" name="area_code" id="area_code" placeholder="268" maxlength="10">
                        </div>
                        <div class="form-group">
                            <label>
                                <input type="checkbox" name="enabled" id="enabled" checked>
                                <?php echo _('Block calls to this pattern') ?>
                            </label>
                        </div>
                        <div class="form-group">
                            <label for="sort_order"><?php echo _('Sort Order') ?></label>
                            <input type="number" class="form-control" name="sort_order" id="sort_order" value="0" min="0" max="999">
                        </div>
                        <button type="submit" class="btn btn-primary btn-block">
                            <i class="fa fa-plus"></i> <?php echo _('Add Entry') ?>
                        </button>
                    </form>
                </div>
            </div>

            <div class="panel panel-warning">
                <div class="panel-heading">
                    <h3 class="panel-title"><?php echo _('Pattern Guide') ?></h3>
                </div>
                <div class="panel-body">
                    <ul class="list-unstyled">
                        <li><strong>_</strong> <?php echo _('= pattern start wildcard') ?></li>
                        <li><strong>N</strong> <?php echo _('= digit 2-9') ?></li>
                        <li><strong>X</strong> <?php echo _('= digit 0-9') ?></li>
                        <li><strong>[1-9]</strong> <?php echo _('= digit range') ?></li>
                    </ul>
                    <p class="text-muted"><?php echo _('Examples:') ?></p>
                    <ul class="list-unstyled">
                        <li><code>_268NXXXXXX</code> = Antigua 10-digit</li>
                        <li><code>_1268NXXXXXX</code> = Antigua +1 prefix</li>
                        <li><code>_NXXNXXXXXX</code> = any NANP 10-digit</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="edit-modal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal">&times;</button>
                <h4 class="modal-title"><?php echo _('Edit Entry') ?></h4>
            </div>
            <div class="modal-body">
                <form method="post" id="edit-form">
                    <input type="hidden" name="action" value="update_entry">
                    <input type="hidden" name="id" id="edit_id" value="">
                    <div class="form-group">
                        <label><?php echo _('Pattern') ?></label>
                        <input type="text" class="form-control" name="pattern" id="edit_pattern" required>
                    </div>
                    <div class="form-group">
                        <label><?php echo _('Description') ?></label>
                        <input type="text" class="form-control" name="description" id="edit_description" required>
                    </div>
                    <div class="form-group">
                        <label><?php echo _('Area Code') ?></label>
                        <input type="text" class="form-control" name="area_code" id="edit_area_code">
                    </div>
                    <div class="form-group">
                        <label>
                            <input type="checkbox" name="enabled" id="edit_enabled">
                            <?php echo _('Enabled') ?>
                        </label>
                    </div>
                    <div class="form-group">
                        <label><?php echo _('Sort Order') ?></label>
                        <input type="number" class="form-control" name="sort_order" id="edit_sort_order" min="0" max="999">
                    </div>
                    <button type="submit" class="btn btn-primary"><?php echo _('Save') ?></button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    $('.btn-edit-entry').on('click', function() {
        $('#edit_id').val($(this).data('id'));
        $('#edit_pattern').val($(this).data('pattern'));
        $('#edit_description').val($(this).data('description'));
        $('#edit_area_code').val($(this).data('area'));
        $('#edit_enabled').prop('checked', $(this).data('enabled') === 1);
        $('#edit_sort_order').val($(this).data('sort'));
        $('#edit-modal').modal('show');
    });
});
</script>
