#!/usr/bin/php
<?php
// Run by cron once a minute: stores one history point for the Stats page.
require __DIR__ . '/../include/StatsLib.php';

// Hottest temperature per disk and hour, kept for a day, for include/disktemps.php. Recorded
// even when the Stats history is off.
(function () {
    $file = '/var/lib/aurora-theme/disktemp.json';
    if (!is_dir(dirname($file))) @mkdir(dirname($file), 0755, true);
    $peaks = json_decode((string) @file_get_contents($file), true);
    $peaks = is_array($peaks) ? $peaks : [];
    $hour = (int) floor(time() / 3600);
    foreach ((array) @parse_ini_file('/var/local/emhttp/disks.ini', true) as $key => $disk) {
        if (!is_array($disk) || !is_numeric($disk['temp'] ?? '')) continue;
        $name = strtolower((string) ($disk['name'] ?? $key));
        $peaks[$name][$hour] = max((int) ($peaks[$name][$hour] ?? 0), (int) $disk['temp']);
    }
    foreach ($peaks as $name => $hours) {
        $peaks[$name] = array_filter((array) $hours, function ($h) use ($hour) { return (int) $h > $hour - 25; }, ARRAY_FILTER_USE_KEY);
        if (!$peaks[$name]) unset($peaks[$name]);
    }
    @file_put_contents($file, json_encode($peaks), LOCK_EX);
})();

if (!tft_stats_enabled()) {
    exit(0);
}
if (!is_dir(TFT_STATS_DIR)) {
    mkdir(TFT_STATS_DIR, 0755, true);
}

$stateFile = TFT_STATS_DIR . '/last.json';
$now = tft_stats_sample();
$previous = json_decode((string) @file_get_contents($stateFile), true);
file_put_contents($stateFile, json_encode($now), LOCK_EX);

// A gap of more than five minutes (first run, or the server was off) gives no meaningful rate.
if (is_array($previous) && isset($previous['t']) && $now['t'] - $previous['t'] < 300) {
    $point = tft_stats_rates($previous, $now);
    if ($point) {
        file_put_contents(TFT_STATS_DIR . '/' . gmdate('Ymd', $point[0]) . '.csv', implode(',', $point) . "\n", FILE_APPEND | LOCK_EX);
    }
}

$oldest = gmdate('Ymd', time() - TFT_STATS_KEEP_DAYS * 86400);
foreach (glob(TFT_STATS_DIR . '/*.csv') ?: [] as $file) {
    if (basename($file, '.csv') < $oldest) {
        unlink($file);
    }
}
