<?php
// When each disk or pool will be full at its recent rate, from the daily usage that
// scripts/collect.php keeps: {"disks": {"disk1": {"days": 120, "perDay": 1048576}, ...}}
// (perDay in KiB; days is null while the disk is not filling or there are under 3 days).
header('Content-Type: application/json');
header('Cache-Control: no-store');

$history = json_decode((string) @file_get_contents('/boot/config/plugins/Aurora-Theme/diskusage.json'), true);
$history = is_array($history) ? $history : [];
$disks = (array) @parse_ini_file('/var/local/emhttp/disks.ini', true);
$out = [];
foreach ($disks as $key => $disk) {
    if (!is_array($disk)) continue;
    $name = strtolower((string) ($disk['name'] ?? $key));
    $free = (float) ($disk['fsFree'] ?? 0);
    $days = $history[$name] ?? [];
    if (count($days) < 3 || $free <= 0) continue;
    ksort($days);
    // Least-squares slope of used space per day, over the kept days.
    $n = 0; $sx = $sy = $sxx = $sxy = 0.0;
    $first = null;
    foreach ($days as $day => $used) {
        $t = strtotime((string) $day);
        if ($t === false) continue;
        $first = $first ?? $t;
        $x = ($t - $first) / 86400;
        $n++; $sx += $x; $sy += $used; $sxx += $x * $x; $sxy += $x * $used;
    }
    $den = $n * $sxx - $sx * $sx;
    if ($n < 3 || $den == 0) continue;
    $slope = ($n * $sxy - $sx * $sy) / $den;
    $out[$name] = ['perDay' => (int) round($slope), 'days' => $slope > 0 ? (int) floor($free / $slope) : null];
}
echo json_encode(['disks' => (object) $out]);
