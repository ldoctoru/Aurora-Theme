<?php
// Quick jump (Ctrl+K): everything worth jumping to, as {"items": [{"title", "group", "href"}, ...]}.
// Pages come from the webGUI's own .page files; apps, VMs and shares from their state. Read-only.
header('Content-Type: application/json');
header('Cache-Control: no-store');

$items = [];
$add = function (string $title, string $group, string $href) use (&$items) {
    $items[] = ['title' => $title, 'group' => $group, 'href' => $href];
};

// ---- Pages: a page's address is /<Tab>/<Name>, the tab being the ancestor listed under "Tasks" ----
$pages = [];
foreach (glob('/usr/local/emhttp/plugins/*/*.page') ?: [] as $file) {
    $head = (string) @file_get_contents($file, false, null, 0, 4096);
    $head = explode("\n---", $head, 2)[0];
    preg_match('/^Menu="([^"]*)"/m', $head, $menu);
    preg_match('/^Title="([^"]*)"/m', $head, $title);
    $pages[basename($file, '.page')] = [
        'parents' => array_values(array_filter(array_map(function ($part) { return strtok($part, ':'); }, preg_split('/\s+/', trim($menu[1] ?? ''))))),
        'title' => trim($title[1] ?? ''),
    ];
}
$tabOf = function (string $name) use ($pages): string {
    $seen = [];
    while (isset($pages[$name]) && !isset($seen[$name])) {
        $seen[$name] = true;
        $parents = $pages[$name]['parents'];
        if (!$parents) return '';
        if (in_array('Tasks', $parents, true)) return $name;
        $name = $parents[0];
    }
    return '';
};
foreach ($pages as $name => $page) {
    $title = $page['title'];
    if ($title === '' || strpos($title, '$') !== false || strpos($title, '<') !== false || mb_strlen($title) > 50) continue;
    if (!$page['parents'] || array_intersect($page['parents'], ['Buttons', 'xmenu'])) continue;
    $tab = $tabOf($name);
    if ($tab === '') continue;
    $add($title, $tab === $name ? 'Tab' : $tab, $tab === $name ? "/$name" : "/$tab/$name");
}

// ---- Apps, VMs, shares ----
if (is_executable('/usr/bin/docker')) {
    exec("timeout 3 /usr/bin/docker ps -a --format '{{.Names}}' 2>/dev/null", $names, $code);
    foreach ($code === 0 ? $names : [] as $name) $add($name, 'App', '/Docker');
}
if (is_executable('/usr/bin/virsh') && is_file('/var/run/libvirt/libvirtd.pid')) {
    exec('timeout 3 /usr/bin/virsh list --all --name 2>/dev/null', $vms, $code);
    foreach ($code === 0 ? array_filter(array_map('trim', $vms)) : [] as $vm) $add($vm, 'VM', '/VMs');
}
foreach (array_keys((array) @parse_ini_file('/var/local/emhttp/shares.ini', true)) as $share) {
    $add((string) $share, 'Share', '/Shares/Share?name=' . rawurlencode((string) $share));
}

echo json_encode(['items' => $items]);
