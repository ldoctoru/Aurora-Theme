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

// Daily used space per disk and the server's starts, on the flash drive so they survive a
// reboot; each is written at most once a day (usage) or once an hour (starts).
(function () {
    $dir = '/boot/config/plugins/Aurora-Theme';
    if (!is_dir($dir)) return;

    $file = "$dir/diskusage.json";
    $today = date('Y-m-d');
    $usage = json_decode((string) @file_get_contents($file), true);
    $usage = is_array($usage) ? $usage : [];
    $changed = false;
    foreach ((array) @parse_ini_file('/var/local/emhttp/disks.ini', true) as $key => $disk) {
        if (!is_array($disk) || !is_numeric($disk['fsSize'] ?? '') || (float) $disk['fsSize'] <= 0 || !is_numeric($disk['fsFree'] ?? '')) continue;
        $name = strtolower((string) ($disk['name'] ?? $key));
        if (isset($usage[$name][$today])) continue;
        $usage[$name][$today] = (float) $disk['fsSize'] - (float) $disk['fsFree'];
        $usage[$name] = array_slice($usage[$name], -30, null, true);
        $changed = true;
    }
    if ($changed) @file_put_contents($file, json_encode($usage), LOCK_EX);

    $file = "$dir/boots.json";
    if (!preg_match('/^btime\s+(\d+)/m', (string) @file_get_contents('/proc/stat'), $m)) return;
    $boot = (int) $m[1];
    $boots = json_decode((string) @file_get_contents($file), true);
    $boots = is_array($boots) ? $boots : [];
    $last = end($boots);
    // btime can move by a second between reads; within a minute it is the same start.
    if (!$last || abs((int) $last['boot'] - $boot) > 60) {
        $boots[] = ['boot' => $boot, 'seen' => time()];
        @file_put_contents($file, json_encode(array_slice($boots, -10)), LOCK_EX);
    } elseif (time() - (int) $last['seen'] >= 3600) {
        $boots[count($boots) - 1]['seen'] = time();
        @file_put_contents($file, json_encode($boots), LOCK_EX);
    }
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
