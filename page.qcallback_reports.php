<?php
if (!defined('FREEPBX_IS_AUTH')) { die('No direct script access allowed'); }
$_REQUEST['view'] = 'reports';
include __DIR__.'/page.qcallback.php';
