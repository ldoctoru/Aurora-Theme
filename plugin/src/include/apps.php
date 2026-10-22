<?php
// Containers for the Dashboard's app tiles.
//   GET  returns {"apps": [{"name", "running", "status", "update", "webui"}, ...]}
// "status" is Docker's own text ("Up 3 days", "Exited (0) 2 days ago"). "webui" is the
// container's WebUI address from its Unraid template label, with [PORT:n] resolved to the host
// port Docker publishes; [IP] is left for the page to fill with the address it was opened on.
// Running containers first, then by name. Read-only.
header('Content-Type: application/json');
header('Cache-Control: no-store');

if (!is_executable('/usr/bin/docker')) {
    echo json_encode(['apps' => []]);
    exit;
}

$format = '{{.Names}}\t{{.Image}}\t{{.State}}\t{{.Status}}\t{{.Ports}}\t{{.Label "net.unraid.docker.webui"}}';
exec('/usr/bin/docker ps -a --format ' . escapeshellarg($format) . ' 2>/dev/null', $lines, $code);
if ($code !== 0) {
    http_response_code(502);
    echo json_encode(['error' => 'docker is not running']);
    exit;
}

$updates = json_decode((string) @file_get_contents('/var/lib/docker/unraid-update-status.json'), true);
$updates = is_array($updates) ? $updates : [];

$apps = [];
foreach ($lines as $line) {
    [$name, $image, $state, $status, $ports, $webui] = array_pad(explode("\t", $line, 6), 6, '');
    if ($name === '') continue;

    $tagged = (strpos($image, ':') === false || strrpos($image, ':') < (int) strrpos($image, '/')) ? "$image:latest" : $image;
    $entry = $updates[$tagged] ?? null;
    $update = is_array($entry) && ($entry['status'] ?? '') !== 'undef'
        && !empty($entry['local']) && !empty($entry['remote']) && $entry['local'] !== $entry['remote'];

    // "0.0.0.0:8097->8096/tcp, [::]:8097->8096/tcp" -> container port 8096 is host port 8097.
    $published = [];
    if (preg_match_all('/:(\d+)->(\d+)\/tcp/', $ports, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) $published[$match[2]] = $match[1];
    }
    $url = '';
    if ($webui !== '') {
        $url = preg_replace_callback('/\[PORT:(\d+)\]/i', function ($match) use ($published) {
            return $published[$match[1]] ?? $match[1];
        }, $webui);
        if (!preg_match('#^https?://[^\s"<>]+$#i', $url)) $url = '';
    }

    $apps[] = [
        'name' => $name,
        'running' => $state === 'running',
        'status' => $status,
        'update' => $update,
        'webui' => $url,
    ];
}

usort($apps, function ($a, $b) {
    return [$b['running'], strtolower($a['name'])] <=> [$a['running'], strtolower($b['name'])];
});
echo json_encode(['apps' => $apps], JSON_UNESCAPED_SLASHES);
