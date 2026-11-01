<?php
// Recent starts of the server, newest first, kept by scripts/collect.php:
// {"boots": [{"boot": 1761900000, "seen": 1761990000}, ...], "now": 1761999999}
header('Content-Type: application/json');
header('Cache-Control: no-store');
$boots = json_decode((string) @file_get_contents('/boot/config/plugins/Aurora-Theme/boots.json'), true);
$boots = is_array($boots) ? array_values($boots) : [];
echo json_encode(['boots' => array_reverse(array_slice($boots, -6)), 'now' => time()]);
