<?php
// Server health for the Dashboard's health strip, read from Unraid's own state files.
//   GET  returns {"items": [{"tone": "danger|warn|info|ok", "title": "...", "detail": "...", "href": "/Main"}, ...]}
// Problems come first (danger, then warn, then info); "ok" items follow so the strip always
// shows the four most useful facts. Read-only: nothing is changed here.
header('Content-Type: application/json');
header('Cache-Control: no-store');

$var = (array) @parse_ini_file('/var/local/emhttp/var.ini');
$disks = (array) @parse_ini_file('/var/local/emhttp/disks.ini', true);
// Unraid's display settings (temperature limits) and notification folder: defaults, then the user's.
$dynamix = array_replace_recursive(
    (array) @parse_ini_file('/usr/local/emhttp/plugins/dynamix/default.cfg', true),
    (array) @parse_ini_file('/boot/config/plugins/dynamix/dynamix.cfg', true)
);
$display = (array) ($dynamix['display'] ?? []);

$items = [];
$add = function (string $tone, string $title, string $detail, string $href) use (&$items) {
    $items[] = ['tone' => $tone, 'title' => $title, 'detail' => $detail, 'href' => $href];
};
$plural = function (int $n, string $one, string $many): string {
    return $n . ' ' . ($n === 1 ? $one : $many);
};

// ---- Array state and running operation (parity check, rebuild, clear) ----
$started = ($var['mdState'] ?? '') === 'STARTED';
$disabled = (int) ($var['mdNumDisabled'] ?? 0);
$invalid = (int) ($var['mdNumInvalid'] ?? 0);
$missing = (int) ($var['mdNumMissing'] ?? 0);
if (!$started) {
    $add('warn', 'Array stopped', 'Shares, apps and VMs on the array are unavailable.', '/Main');
} elseif ($disabled + $missing > 0) {
    $add('danger', $plural($disabled + $missing, 'disk disabled', 'disks disabled'), 'Data is emulated from parity. Replace or rebuild the disk.', '/Main');
} elseif ($invalid > 0) {
    $add('warn', $plural($invalid, 'disk invalid', 'disks invalid'), 'Parity is not protecting all data.', '/Main');
}
$resync = (int) ($var['mdResync'] ?? 0);
if ($started && $resync > 0) {
    $size = (float) ($var['mdResyncSize'] ?? 0);
    $pos = (float) ($var['mdResyncPos'] ?? 0);
    $percent = $size > 0 ? floor($pos / $size * 100) : 0;
    $add('info', 'Array operation running', "$percent% done", '/Main');
}

// ---- Disks: failures, read errors, temperature against Unraid's own limits ----
$failed = $errors = $hot = $critical = [];
foreach ($disks as $name => $disk) {
    if (!is_array($disk) || ($disk['device'] ?? '') === '') continue;
    $label = ucfirst((string) ($disk['name'] ?? $name));
    $color = strtolower((string) strtok((string) ($disk['color'] ?? ''), '-'));
    if ($color === 'red') $failed[] = $label;
    if ((int) ($disk['numErrors'] ?? 0) > 0) $errors[] = $label;
    $temp = $disk['temp'] ?? '*';
    if (!is_numeric($temp)) continue;
    $ssd = ($disk['rotational'] ?? '1') === '0';
    $hotLimit = (isset($disk['hotTemp']) && (int) $disk['hotTemp'] >= 0 && $disk['hotTemp'] !== '') ? (int) $disk['hotTemp']
        : (int) ($ssd && (int) ($display['hotssd'] ?? -1) >= 0 ? $display['hotssd'] : ($display['hot'] ?? 45));
    $maxLimit = (isset($disk['maxTemp']) && (int) $disk['maxTemp'] >= 0 && $disk['maxTemp'] !== '') ? (int) $disk['maxTemp']
        : (int) ($ssd && (int) ($display['maxssd'] ?? -1) >= 0 ? $display['maxssd'] : ($display['max'] ?? 55));
    if ($maxLimit > 0 && $temp >= $maxLimit) $critical[] = "$label {$temp} °C";
    elseif ($hotLimit > 0 && $temp >= $hotLimit) $hot[] = "$label {$temp} °C";
}
if ($failed) $add('danger', $plural(count($failed), 'disk failing', 'disks failing'), implode(', ', $failed), '/Main');
if ($critical) $add('danger', 'Disk overheating', implode(', ', $critical), '/Main');
if ($errors) $add('warn', 'Disk read errors', implode(', ', $errors), '/Main');
if ($hot) $add('warn', count($hot) === 1 ? 'Disk hot' : count($hot) . ' disks hot', implode(', ', $hot), '/Main');

// ---- Parity: last check and its errors ----
$synced = (int) ($var['sbSynced'] ?? 0);
$syncErrors = (int) ($var['sbSyncErrs'] ?? 0);
if ($started && $resync === 0 && $synced > 0) {
    $days = (int) floor((time() - $synced) / 86400);
    $when = $days === 0 ? 'today' : ($days === 1 ? 'yesterday' : "$days days ago");
    if ($syncErrors > 0) $add('warn', 'Parity errors found', $plural($syncErrors, 'error', 'errors') . " in the last check ($when)", '/Main');
    elseif ($days > 45) $add('info', 'Parity check overdue', "Last check $when", '/Settings/Scheduler');
    else $add('ok', 'Parity valid', "Last check $when, no errors", '/Main');
}

// ---- Apps: containers whose image has a newer version ----
$status = json_decode((string) @file_get_contents('/var/lib/docker/unraid-update-status.json'), true);
if (is_array($status) && is_executable('/usr/bin/docker')) {
    exec("/usr/bin/docker ps -a --format '{{.Names}}\t{{.Image}}' 2>/dev/null", $lines, $code);
    $updates = [];
    foreach ($code === 0 ? $lines : [] as $line) {
        [$container, $image] = array_pad(explode("\t", $line, 2), 2, '');
        if (strpos($image, ':') === false || strrpos($image, ':') < strrpos($image, '/')) $image .= ':latest';
        $entry = $status[$image] ?? null;
        if (is_array($entry) && ($entry['status'] ?? '') !== 'undef' && !empty($entry['local']) && !empty($entry['remote']) && $entry['local'] !== $entry['remote']) {
            $updates[] = $container;
        }
    }
    if ($updates) {
        $shown = array_slice($updates, 0, 3);
        $add('info', $plural(count($updates), 'app update', 'app updates'), implode(', ', $shown) . (count($updates) > 3 ? ', …' : ''), '/Docker');
    } elseif ($code === 0 && $lines) {
        $add('ok', 'Apps up to date', $plural(count($lines), 'container', 'containers'), '/Docker');
    }
}

// ---- Unread notifications that need attention ----
$alerts = $warnings = 0;
$path = (string) ($dynamix['notify']['path'] ?? '') ?: '/tmp/notifications';
foreach (glob(rtrim($path, '/') . '/unread/*.notify') ?: [] as $file) {
    $note = (array) @parse_ini_file($file);
    $importance = (string) ($note['importance'] ?? 'normal');
    if ($importance === 'alert') $alerts++;
    elseif ($importance === 'warning') $warnings++;
}
if ($alerts) $add('danger', $plural($alerts, 'unread alert', 'unread alerts'), 'Open notifications to review them.', '/Tools/Notifications');
elseif ($warnings) $add('warn', $plural($warnings, 'unread warning', 'unread warnings'), 'Open notifications to review them.', '/Tools/Notifications');

// ---- Fill with good news when nothing is wrong ----
$tones = array_column($items, 'tone');
if ($started && !array_intersect($tones, ['danger']) && $disabled + $missing + $invalid === 0 && !$failed) {
    $count = count(array_filter($disks, function ($d) { return is_array($d) && ($d['device'] ?? '') !== '' && ($d['type'] ?? '') !== 'Flash'; }));
    $add('ok', 'Array healthy', $plural($count, 'disk', 'disks') . ' online', '/Main');
}
if (!$alerts && !$warnings) $add('ok', 'No unread alerts', 'Notifications are clear', '/Tools/Notifications');

$rank = ['danger' => 0, 'warn' => 1, 'info' => 2, 'ok' => 3];
usort($items, function ($a, $b) use ($rank) { return $rank[$a['tone']] <=> $rank[$b['tone']]; });
echo json_encode(['items' => array_slice($items, 0, 4)]);
