<?php
// Which VMs and containers are pinned to which CPU threads, for the Dashboard's core tiles:
// {"pins": {"0": ["VM win10"], "10": ["App plex"]}, "isolated": [2, 3]}. Read-only.
header('Content-Type: application/json');
header('Cache-Control: no-store');

$pins = [];
$cpus = function (string $set): array {
    $out = [];
    foreach (explode(',', $set) as $part) {
        $part = trim($part);
        if (preg_match('/^(\d+)-(\d+)$/', $part, $m)) $out = array_merge($out, range((int) $m[1], min((int) $m[2], (int) $m[1] + 512)));
        elseif (ctype_digit($part)) $out[] = (int) $part;
    }
    return array_unique($out);
};
$add = function (string $owner, array $list) use (&$pins) {
    foreach ($list as $cpu) {
        if (!in_array($owner, $pins[$cpu] ?? [], true)) $pins[$cpu][] = $owner;
    }
};

// VMs: libvirt's definitions (vcpupin, or the vcpu element's cpuset).
foreach (glob('/etc/libvirt/qemu/*.xml') ?: [] as $file) {
    $xml = @simplexml_load_file($file);
    if (!$xml) continue;
    $name = 'VM ' . (string) $xml->name;
    $list = [];
    foreach ($xml->xpath('//cputune/vcpupin') ?: [] as $pin) $list = array_merge($list, $cpus((string) $pin['cpuset']));
    if (!$list && isset($xml->vcpu['cpuset'])) $list = $cpus((string) $xml->vcpu['cpuset']);
    $add($name, array_unique($list));
}

// Containers: --cpuset-cpus of the running ones.
if (is_executable('/usr/bin/docker')) {
    exec("timeout 5 /usr/bin/docker ps -q 2>/dev/null", $ids, $code);
    if ($code === 0 && $ids) {
        exec('timeout 5 /usr/bin/docker inspect -f ' . escapeshellarg('{{.Name}}|{{.HostConfig.CpusetCpus}}') . ' ' . implode(' ', array_map('escapeshellarg', $ids)) . ' 2>/dev/null', $lines);
        foreach ($lines as $line) {
            [$name, $set] = array_pad(explode('|', $line, 2), 2, '');
            if (trim($set) !== '') $add('App ' . ltrim($name, '/'), $cpus($set));
        }
    }
}

// Isolated threads (Settings > CPU Pinning, "CPU Isolation"): isolcpus on the kernel line.
$isolated = [];
if (preg_match('/\bisolcpus=([\d,\-]+)/', (string) @file_get_contents('/proc/cmdline'), $m)) $isolated = array_values($cpus($m[1]));

echo json_encode(['pins' => (object) $pins, 'isolated' => $isolated]);
