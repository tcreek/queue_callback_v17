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

#!/usr/bin/php
<?php
require_once('/etc/freepbx.conf');
$qcallback = \FreePBX::create()->Qcallback ?? null;
if ($qcallback && method_exists($qcallback, 'processIntelligentCallbacks')) {
    $n = $qcallback->processIntelligentCallbacks();
    if ($n > 0) { error_log("Intelligent Queue Callback: processed $n"); }
}