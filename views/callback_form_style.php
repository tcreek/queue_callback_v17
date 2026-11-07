<?php
/** Shared layout for the standalone, Queues and legacy callback forms. */
?>
<style>
.qcb-field-row {
    display: flex;
    flex-direction: column;
    align-items: stretch;
    row-gap: 8px;
}
.qcb-field-row::before,
.qcb-field-row::after {
    display: none;
}
.qcb-field-row > .qcb-field-label {
    flex: 0 0 auto;
    width: 100%;
    float: none;
    text-align: left;
}
.qcb-field-label.control-label,
.qcb-field-label .control-label {
    white-space: nowrap;
    text-align: left;
}
.qcb-field-row > .qcb-field-control {
    flex: 0 0 auto;
    width: 100%;
    min-width: 0;
    float: none;
}
.qcb-field-control .form-control,
.qcb-field-control .input-group {
    max-width: 100%;
}
</style>
