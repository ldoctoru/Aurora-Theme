<?php
// Disk temperatures for the Main page and the Dashboard: Unraid's own limits per disk (or the
// Display Settings defaults) and the hottest reading of the last 24 hours, kept by
// scripts/collect.php. {"disks": {"disk1": {"temp": 38, "hot": 45, "max": 55, "peak": 41}, ...}}
header('Content-Type: application/json');
header('Cache-Control: no-store');

$disks = (array) @parse_ini_file('/var/local/emhttp/disks.ini', true);
$dynamix = array_replace_recursive(
    (array) @parse_ini_file('/usr/local/emhttp/plugins/dynamix/default.cfg', true),
    (array) @parse_ini_file('/boot/config/plugins/dynamix/dynamix.cfg', true)
);
$display = (array) ($dynamix['display'] ?? []);
$peaks = json_decode((string) @file_get_contents('/var/lib/aurora-theme/disktemp.json'), true);
$peaks = is_array($peaks) ? $peaks : [];
$since = (int) floor(time() / 3600) - 24;

$out = [];
foreach ($disks as $key => $disk) {
    if (!is_array($disk) || ($disk['device'] ?? '') === '') continue;
    $name = strtolower((string) ($disk['name'] ?? $key));
    $ssd = ($disk['rotational'] ?? '1') === '0';
    $limit = function (string $field, string $setting, int $fallback) use ($disk, $display, $ssd): int {
        if (isset($disk[$field]) && $disk[$field] !== '' && (int) $disk[$field] >= 0) return (int) $disk[$field];
        if ($ssd && (int) ($display[$setting . 'ssd'] ?? -1) >= 0) return (int) $display[$setting . 'ssd'];
        return (int) ($display[$setting] ?? $fallback);
    };
    $peak = null;
    foreach ((array) ($peaks[$name] ?? []) as $hour => $value) {
        if ((int) $hour > $since) $peak = max((int) $peak, (int) $value);
    }
    $out[$name] = [
        'temp' => is_numeric($disk['temp'] ?? '') ? (int) $disk['temp'] : null,
        'hot' => $limit('hotTemp', 'hot', 45),
        'max' => $limit('maxTemp', 'max', 55),
        'peak' => $peak,
    ];
}
echo json_encode(['disks' => $out]);
