<?php
// CPU and memory of each running container, for the Docker page's small graphs:
// {"load": {"name": {"cpu": 1.5, "mem": 3.2}, ...}} (percent; CPU can pass 100 on several cores).
header('Content-Type: application/json');
header('Cache-Control: no-store');
$load = [];
if (is_executable('/usr/bin/docker')) {
    exec("timeout 8 /usr/bin/docker stats --no-stream --format '{{.Name}}\t{{.CPUPerc}}\t{{.MemPerc}}' 2>/dev/null", $lines, $code);
    foreach ($code === 0 ? $lines : [] as $line) {
        [$name, $cpu, $mem] = array_pad(explode("\t", $line, 3), 3, '');
        if ($name === '') continue;
        $load[$name] = ['cpu' => (float) rtrim($cpu, '%'), 'mem' => (float) rtrim($mem, '%')];
    }
}
echo json_encode(['load' => (object) $load]);
