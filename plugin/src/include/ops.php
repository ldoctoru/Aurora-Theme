<?php
// Running long operations, for the top bar: {"ops": [{"title", "percent", "left", "href"}, ...]}.
// percent and left (seconds) are null when not known. Read-only.
header('Content-Type: application/json');
header('Cache-Control: no-store');

$ops = [];
$var = (array) @parse_ini_file('/var/local/emhttp/var.ini');
if (($var['mdState'] ?? '') === 'STARTED' && (int) ($var['mdResync'] ?? 0) > 0) {
    $size = (float) ($var['mdResyncSize'] ?? 0);
    $pos = (float) ($var['mdResyncPos'] ?? 0);
    $dt = (float) ($var['mdResyncDt'] ?? 0);
    $db = (float) ($var['mdResyncDb'] ?? 0);
    $action = (string) ($var['mdResyncAction'] ?? '');
    $title = strpos($action, 'recon') === 0 ? 'Rebuild' : (strpos($action, 'clear') === 0 ? 'Clearing' : 'Parity check');
    $speed = $dt > 0 ? $db / $dt : 0; // KiB per second
    $paused = $dt == 0 && $db == 0;
    $ops[] = [
        'title' => $title . ($paused ? ' paused' : ''),
        'percent' => $size > 0 ? round($pos / $size * 100, 1) : null,
        'left' => !$paused && $speed > 0 && $size > $pos ? (int) (($size - $pos) / $speed) : null,
        'href' => '/Main',
    ];
}
$pid = (int) @file_get_contents('/var/run/mover.pid');
if ($pid > 0 && file_exists("/proc/$pid")) {
    $ops[] = ['title' => 'Mover running', 'percent' => null, 'left' => null, 'href' => '/Main'];
}
echo json_encode(['ops' => $ops]);
