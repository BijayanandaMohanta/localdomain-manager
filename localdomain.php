<?php

$HOST_FILE = 'C:\Windows\System32\drivers\etc\hosts';
$VHOST_FILE = 'C:\xampp\apache\conf\extra\httpd-vhosts.conf';
$HTTPD_CONF = 'C:\xampp\apache\conf\httpd.conf';


/* =================================
   HELPERS
================================= */

function normalize($p)
{
    return str_replace('\\', '/', trim($p));
}

function escape($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function redirectWithFlash($status, $message, $extra = [])
{
    $params = array_merge([
        'status' => $status,
        'message' => $message,
    ], $extra);

    header('Location: ' . $_SERVER['PHP_SELF'] . '?' . http_build_query($params));
    exit;
}

function jsonResponse($payload, $statusCode = 200)
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($payload);
    exit;
}

function openFolderInExplorer($path)
{
    $normalizedPath = str_replace('/', '\\', normalize($path));

    if ($normalizedPath === '' || !is_dir($normalizedPath)) {
        return false;
    }

    $command = 'explorer ' . escapeshellarg($normalizedPath);

    @pclose(@popen('start /B "" ' . $command, 'r'));

    return true;
}

function formatBytes($bytes)
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $size = max(0, (float) $bytes);
    $unitIndex = 0;

    while ($size >= 1024 && $unitIndex < count($units) - 1) {
        $size /= 1024;
        $unitIndex++;
    }

    $precision = $unitIndex === 0 ? 0 : 1;

    return number_format($size, $precision) . ' ' . $units[$unitIndex];
}

function collectFolderMeta($path)
{
    $normalizedPath = normalize($path);
    $directoryPath = str_replace('/', DIRECTORY_SEPARATOR, $normalizedPath);

    $meta = [
        'exists' => is_dir($directoryPath),
        'is_git' => false,
        'size_label' => 'Unavailable',
        'last_modified' => 'Unavailable',
        'item_count' => 0,
        'checked_at' => date('d M Y, h:i A'),
    ];

    if (!$meta['exists']) {
        return $meta;
    }

    $meta['is_git'] = is_dir($directoryPath . DIRECTORY_SEPARATOR . '.git');

    $lastModified = @filemtime($directoryPath);
    if ($lastModified) {
        $meta['last_modified'] = date('d M Y, h:i A', $lastModified);
    }

    $totalSize = 0;
    $itemCount = 0;

    try {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directoryPath, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $itemCount++;

            if ($item->isFile()) {
                $totalSize += $item->getSize();
            }
        }

        $meta['size_label'] = formatBytes($totalSize);
        $meta['item_count'] = $itemCount;
    } catch (Throwable $error) {
        $meta['size_label'] = 'Restricted';
    }

    return $meta;
}


/* ---------- LISTEN PORT ---------- */

function addListenPort($file, $port)
{
    $conf = file_get_contents($file);

    if (!preg_match("/Listen\s+$port\b/", $conf)) {
        file_put_contents($file, "\nListen $port", FILE_APPEND);
    }
}

function removeListenPortIfUnused($vhostFile, $httpdFile, $port)
{

    $conf = file_get_contents($vhostFile);

    if (preg_match("/<VirtualHost \*:$port>/", $conf)) {
        return;
    }

    $lines = file($httpdFile);

    $lines = array_filter($lines, function ($l) use ($port) {
        return !preg_match("/Listen\s+$port\b/", $l);
    });

    file_put_contents($httpdFile, implode('', $lines));
}


/* ---------- HOST ---------- */

function addHost($file, $domain)
{
    $hosts = file_get_contents($file);
    if (!str_contains($hosts, $domain)) {
        file_put_contents($file, "\n127.0.0.1\t$domain", FILE_APPEND);
    }
}

function removeHost($file, $domain)
{
    $lines = file($file);
    $lines = array_filter($lines, fn($l) => !str_contains($l, $domain));
    file_put_contents($file, implode('', $lines));
}


/* ---------- VHOST ---------- */

function addVhost($file, $domain, $path, $port)
{

    $block = <<<CONF

<VirtualHost *:$port>
    DocumentRoot "$path"
    ServerName $domain
    <Directory "$path">
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>

CONF;

    file_put_contents($file, $block, FILE_APPEND);
}

function removeVhostAndReturnPort($file, $domain)
{

    $conf = file_get_contents($file);

    preg_match_all('/<VirtualHost \*:(\d+)>.*?<\/VirtualHost>/s', $conf, $blocks, PREG_SET_ORDER);

    $new = $conf;
    $port = null;

    foreach ($blocks as $block) {

        $p = $block[1];
        $b = $block[0];

        if (preg_match('/ServerName\s+([^\s]+)/', $b, $m)) {
            if ($m[1] === $domain) {
                $port = $p;
                $new = str_replace($b, '', $new);
            }
        }
    }

    file_put_contents($file, $new);

    return $port;
}


/* ---------- READ ALL ---------- */

function readAllDomains($file)
{

    $conf = file_get_contents($file);

    preg_match_all('/<VirtualHost \*:(\d+)>.*?<\/VirtualHost>/s', $conf, $blocks, PREG_SET_ORDER);

    $list = [];

    foreach ($blocks as $block) {

        $port = $block[1];
        $b = $block[0];

        preg_match('/ServerName\s+([^\s]+)/', $b, $d);
        preg_match('/DocumentRoot\s+"([^"]+)"/', $b, $p);

        if (!$d || !$p)
            continue;

        $domain = $d[1];

        if ($domain === 'localhost')
            continue;
        if (!preg_match('/\.(local|test)$/', $domain))
            continue;

        $list[] = [
            'domain' => $domain,
            'path' => $p[1],
            'port' => $port,
        ];
    }

    return $list;
}


/* =================================
   ACTIONS
================================= */

$status = $_GET['status'] ?? '';
$msg = $_GET['message'] ?? '';
$form = [
    'domain' => $_GET['domain'] ?? '',
    'path' => $_GET['path'] ?? '',
    'port' => $_GET['port'] ?? '80',
];

if (($_GET['action'] ?? '') === 'folder_meta') {
    $path = normalize($_GET['path'] ?? '');

    if ($path === '') {
        jsonResponse([
            'ok' => false,
            'message' => 'Missing folder path.',
        ], 400);
    }

    jsonResponse([
        'ok' => true,
        'meta' => collectFolderMeta($path),
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = $_POST['action'] ?? '';

    if ($action === 'add') {

        $domain = strtolower(trim($_POST['domain'] ?? ''));
        $path = normalize($_POST['path'] ?? '');
        $port = $_POST['port'] ?: 80;

        if (!preg_match('/^[a-z0-9\-]+\.(local|test)$/', $domain)) {
            redirectWithFlash('error', 'Invalid domain name. Use something like project.local or app.test.', [
                'domain' => $domain,
                'path' => $path,
                'port' => $port,
            ]);
        }

        if (!is_dir($path)) {
            redirectWithFlash('error', 'Folder not found. Check the project path and try again.', [
                'domain' => $domain,
                'path' => $path,
                'port' => $port,
            ]);
        }

        addHost($HOST_FILE, $domain);
        addListenPort($HTTPD_CONF, $port);
        addVhost($VHOST_FILE, $domain, $path, $port);

        redirectWithFlash('success', 'Created successfully. Restart Apache to apply the new domain.');
    }

    if ($action === 'delete') {

        $domain = $_POST['domain'] ?? '';

        removeHost($HOST_FILE, $domain);

        $port = removeVhostAndReturnPort($VHOST_FILE, $domain);

        if ($port) {
            removeListenPortIfUnused($VHOST_FILE, $HTTPD_CONF, $port);
        }

        redirectWithFlash('success', 'Deleted safely. Restart Apache if the site was active.');
    }

    if ($action === 'open_folder') {

        $path = normalize($_POST['path'] ?? '');

        if (!is_dir($path)) {
            redirectWithFlash('error', 'Folder not found. The directory may have been moved or deleted.');
        }

        if (!openFolderInExplorer($path)) {
            redirectWithFlash('error', 'Could not open the folder in File Explorer.');
        }

        redirectWithFlash('success', 'Folder opened in File Explorer.');
    }
}

$domains = readAllDomains($VHOST_FILE);
$domainCount = count($domains);
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">

<script>
window.tailwind = window.tailwind || {};
window.tailwind.config = { darkMode: 'class' };

(function () {
    const root = document.documentElement;
    let storedTheme = null;

    try {
        storedTheme = localStorage.getItem('localDomainTheme');
    } catch (error) {
        storedTheme = null;
    }

    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
    const theme = storedTheme || (prefersDark ? 'dark' : 'light');

    window.applyLocalDomainTheme = function (nextTheme) {
        const resolvedTheme = nextTheme === 'dark' ? 'dark' : 'light';

        root.classList.toggle('dark', resolvedTheme === 'dark');
        root.setAttribute('data-theme', resolvedTheme);
        root.style.colorScheme = resolvedTheme;

        try {
            localStorage.setItem('localDomainTheme', resolvedTheme);
        } catch (error) {
        }
    };

    window.getLocalDomainTheme = function () {
        return root.classList.contains('dark') ? 'dark' : 'light';
    };

    window.applyLocalDomainTheme(theme);
})();
</script>
<script src="https://cdn.tailwindcss.com"></script>
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Google+Sans:ital,opsz,wght@0,17..18,400..700;1,17..18,400..700&family=Open+Sans:ital,wght@0,300..800;1,300..800&family=Righteous&display=swap" rel="stylesheet">
<style>
html {
    color-scheme: light;
}

* {
    font-family: "Google Sans", sans-serif;
}

body {
    min-height: 100vh;
    background:
        radial-gradient(circle at top left, rgba(59, 130, 246, 0.15), transparent 28%),
        radial-gradient(circle at top right, rgba(16, 185, 129, 0.12), transparent 26%);
}

html.dark body {
    background-color: #020617;
    color: #f3f4f6;
}

html.dark [class*="dark:bg-slate-950"] {
    background-color: #020617;
}

html.dark [class*="dark:bg-slate-900/85"] {
    background-color: rgba(15, 23, 42, 0.85);
}

html.dark [class*="dark:bg-slate-900/80"] {
    background-color: rgba(15, 23, 42, 0.8);
}

html.dark [class*="dark:bg-slate-950/70"] {
    background-color: rgba(2, 6, 23, 0.7);
}

html.dark [class*="dark:bg-slate-950/60"] {
    background-color: rgba(2, 6, 23, 0.6);
}

html.dark [class*="dark:bg-slate-800"] {
    background-color: #1e293b;
}

html.dark [class*="dark:bg-blue-500/10"] {
    background-color: rgba(59, 130, 246, 0.1);
}

html.dark [class*="dark:bg-emerald-500/10"] {
    background-color: rgba(16, 185, 129, 0.1);
}

html.dark [class*="dark:bg-red-500/10"] {
    background-color: rgba(239, 68, 68, 0.1);
}

html.dark [class*="dark:bg-amber-500/10"] {
    background-color: rgba(245, 158, 11, 0.1);
}

html.dark [class*="dark:border-slate-800"] {
    border-color: #1e293b;
}

html.dark [class*="dark:border-slate-700"] {
    border-color: #334155;
}

html.dark [class*="dark:border-blue-500/30"] {
    border-color: rgba(59, 130, 246, 0.3);
}

html.dark [class*="dark:border-emerald-500/20"] {
    border-color: rgba(16, 185, 129, 0.2);
}

html.dark [class*="dark:border-emerald-500/30"] {
    border-color: rgba(16, 185, 129, 0.3);
}

html.dark [class*="dark:border-red-500/20"] {
    border-color: rgba(239, 68, 68, 0.2);
}

html.dark [class*="dark:border-red-500/30"] {
    border-color: rgba(239, 68, 68, 0.3);
}

html.dark [class*="dark:border-amber-500/30"] {
    border-color: rgba(245, 158, 11, 0.3);
}

html.dark [class*="dark:text-white"] {
    color: #ffffff;
}

html.dark [class*="dark:text-gray-100"] {
    color: #f3f4f6;
}

html.dark [class*="dark:text-slate-100"] {
    color: #f1f5f9;
}

html.dark [class*="dark:text-slate-200"] {
    color: #e2e8f0;
}

html.dark [class*="dark:text-slate-300"] {
    color: #cbd5e1;
}

html.dark [class*="dark:text-slate-400"] {
    color: #94a3b8;
}

html.dark [class*="dark:text-blue-300"] {
    color: #93c5fd;
}

html.dark [class*="dark:text-blue-400"] {
    color: #60a5fa;
}

html.dark [class*="dark:text-emerald-100"] {
    color: #d1fae5;
}

html.dark [class*="dark:text-emerald-200"] {
    color: #a7f3d0;
}

html.dark [class*="dark:text-emerald-300"] {
    color: #6ee7b7;
}

html.dark [class*="dark:text-red-200"] {
    color: #fecaca;
}

html.dark [class*="dark:text-red-300"] {
    color: #fca5a5;
}

html.dark [class*="dark:text-amber-200"] {
    color: #fde68a;
}

html.dark [class*="dark:hover:bg-slate-950/70"]:hover {
    background-color: rgba(2, 6, 23, 0.7);
}

html.dark [class*="dark:hover:bg-slate-800"]:hover {
    background-color: #1e293b;
}

html.dark [class*="dark:hover:bg-red-500/10"]:hover {
    background-color: rgba(239, 68, 68, 0.1);
}

html.dark [class*="dark:hover:text-blue-300"]:hover {
    color: #93c5fd;
}

html.dark [class*="dark:focus:bg-slate-900"]:focus {
    background-color: #0f172a;
}
</style>

<title>Local Domain Manager</title>
</head>


<body class="bg-slate-50 text-gray-800 dark:bg-slate-950 dark:text-gray-100">

<div class="max-w-7xl mx-auto px-4 py-8 sm:px-6 lg:px-8 lg:py-12">

    <div class="rounded-xl border border-white/60 bg-white/85 p-6 shadow-[0_20px_70px_rgba(15,23,42,0.08)] backdrop-blur dark:border-slate-800 dark:bg-slate-900/85 sm:p-8">
        <div class="flex flex-col gap-8 lg:flex-row lg:items-start lg:justify-between">
            <div class="max-w-3xl">
                <div class="inline-flex items-center gap-2 rounded-md border border-blue-200 bg-blue-50 px-3 py-1 text-xs font-semibold uppercase tracking-[0.2em] text-blue-700 dark:border-blue-500/30 dark:bg-blue-500/10 dark:text-blue-300">
                    <i class="bi bi-hdd-network"></i>
                    Apache + Hosts Helper
                </div>
                <h1 class="mt-4 text-3xl font-semibold tracking-tight text-slate-900 dark:text-white sm:text-4xl">
                    Local Domain Manager
                </h1>
                <p class="mt-3 max-w-2xl text-sm leading-6 text-slate-600 dark:text-slate-300 sm:text-base">
                    Create clean local domains for your projects without manually editing the hosts file or Apache vhosts.
                    Add a domain, point it to a folder, choose a port, and restart Apache.
                </p>
            </div>

            <div class="grid gap-3 sm:grid-cols-3 lg:min-w-[360px] lg:max-w-md">
                <div class="rounded-lg border border-slate-200 bg-slate-50 p-4 dark:border-slate-800 dark:bg-slate-950/60">
                    <div class="text-xs uppercase tracking-[0.18em] text-slate-500 dark:text-slate-400">Configured</div>
                    <div class="mt-2 text-2xl font-semibold text-slate-900 dark:text-white"><?= $domainCount ?></div>
                    <div class="mt-1 text-xs text-slate-500 dark:text-slate-400">Local domains listed</div>
                </div>
                <div class="rounded-lg border border-slate-200 bg-slate-50 p-4 dark:border-slate-800 dark:bg-slate-950/60">
                    <div class="text-xs uppercase tracking-[0.18em] text-slate-500 dark:text-slate-400">Default Port</div>
                    <div class="mt-2 text-2xl font-semibold text-slate-900 dark:text-white">80</div>
                    <div class="mt-1 text-xs text-slate-500 dark:text-slate-400">Use 8080+ if needed</div>
                </div>
                <div class="rounded-lg border border-emerald-200 bg-emerald-50 p-4 dark:border-emerald-500/20 dark:bg-emerald-500/10">
                    <div class="text-xs uppercase tracking-[0.18em] text-emerald-700 dark:text-emerald-300">Reminder</div>
                    <div class="mt-2 text-sm font-semibold text-emerald-900 dark:text-emerald-100">Run as Admin</div>
                    <div class="mt-1 text-xs text-emerald-700/80 dark:text-emerald-200/80">Hosts + Apache files need access</div>
                </div>
            </div>
        </div>

        <?php if ($msg): ?>
            <div class="mt-8 flex items-start gap-3 rounded-lg border px-4 py-4 text-sm <?= $status === 'error'
                ? 'border-red-200 bg-red-50 text-red-700 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-200'
                : 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-200' ?>">
                <i class="bi <?= $status === 'error' ? 'bi-exclamation-octagon' : 'bi-check-circle' ?> mt-0.5 text-base"></i>
                <div><?= escape($msg) ?></div>
            </div>
        <?php endif; ?>

        <div class="mt-8 grid gap-6 xl:grid-cols-[1.3fr_0.7fr]">
            <div class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900/80 sm:p-7">
                <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h2 class="text-xl font-semibold text-slate-900 dark:text-white">Add New Domain</h2>
                        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Use a .local or .test domain and point it to your project root or public folder.</p>
                    </div>
                    <div class="inline-flex items-center gap-2 rounded-md bg-slate-100 px-3 py-1 text-xs font-medium text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                        <i class="bi bi-arrow-repeat"></i>
                        Refresh-safe submission
                    </div>
                </div>

                <form method="post" class="mt-6 space-y-5">
                    <input type="hidden" name="action" value="add">

                    <div class="grid gap-5 lg:grid-cols-12">
                        <label class="block lg:col-span-4">
                            <span class="mb-2 block text-sm font-medium text-slate-700 dark:text-slate-200">Domain</span>
                            <input name="domain" placeholder="example.local" required value="<?= escape($form['domain']) ?>"
                                class="h-12 w-full rounded-lg border border-slate-200 bg-slate-50 px-4 text-sm text-slate-900 outline-none transition focus:border-blue-500 focus:bg-white focus:ring-4 focus:ring-blue-500/10 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100 dark:focus:bg-slate-900">
                            <span class="mt-2 block text-xs text-slate-500 dark:text-slate-400">Allowed: letters, numbers and hyphen, ending with .local or .test</span>
                        </label>

                        <label class="block lg:col-span-8">
                            <span class="mb-2 block text-sm font-medium text-slate-700 dark:text-slate-200">Project Path</span>
                            <input name="path" placeholder="C:/xampp/htdocs/project/public" required value="<?= escape($form['path']) ?>"
                                class="h-12 w-full rounded-lg border border-slate-200 bg-slate-50 px-4 text-sm text-slate-900 outline-none transition focus:border-blue-500 focus:bg-white focus:ring-4 focus:ring-blue-500/10 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100 dark:focus:bg-slate-900">
                            <span class="mt-2 block text-xs text-slate-500 dark:text-slate-400">Use the full folder path that Apache should serve</span>
                        </label>
                    </div>

                    <div class="grid gap-5 sm:grid-cols-2 lg:max-w-[30rem] lg:grid-cols-[13rem_minmax(0,1fr)] lg:items-start">
                        <label class="block">
                            <span class="mb-2 block text-sm font-medium text-slate-700 dark:text-slate-200">Port</span>
                            <select name="port" class="selectize" data-selected-port="<?= escape($form['port']) ?>">
                                <option value="80" <?= $form['port'] === '80' ? 'selected' : '' ?>>80 (default)</option>
                                <option value="8080" <?= $form['port'] === '8080' ? 'selected' : '' ?>>8080</option>
                                <option value="8081" <?= $form['port'] === '8081' ? 'selected' : '' ?>>8081</option>
                                <option value="8082" <?= $form['port'] === '8082' ? 'selected' : '' ?>>8082</option>
                                <option value="8085" <?= $form['port'] === '8085' ? 'selected' : '' ?>>8085</option>
                                <option value="8090" <?= $form['port'] === '8090' ? 'selected' : '' ?>>8090</option>
                                <option value="3000" <?= $form['port'] === '3000' ? 'selected' : '' ?>>3000</option>
                                <option value="3001" <?= $form['port'] === '3001' ? 'selected' : '' ?>>3001</option>
                                <option value="3002" <?= $form['port'] === '3002' ? 'selected' : '' ?>>3002</option>
                                <option value="4000" <?= $form['port'] === '4000' ? 'selected' : '' ?>>4000</option>
                                <option value="4001" <?= $form['port'] === '4001' ? 'selected' : '' ?>>4001</option>
                                <option value="5000" <?= $form['port'] === '5000' ? 'selected' : '' ?>>5000</option>
                                <option value="5001" <?= $form['port'] === '5001' ? 'selected' : '' ?>>5001</option>
                                <option value="6000" <?= $form['port'] === '6000' ? 'selected' : '' ?>>6000</option>
                                <option value="6001" <?= $form['port'] === '6001' ? 'selected' : '' ?>>6001</option>
                                <option value="7000" <?= $form['port'] === '7000' ? 'selected' : '' ?>>7000</option>
                                <option value="7001" <?= $form['port'] === '7001' ? 'selected' : '' ?>>7001</option>
                                <option value="9000" <?= $form['port'] === '9000' ? 'selected' : '' ?>>9000</option>
                                <option value="9001" <?= $form['port'] === '9001' ? 'selected' : '' ?>>9001</option>
                                <option value="10000" <?= $form['port'] === '10000' ? 'selected' : '' ?>>10000</option>
                                <option value="11000" <?= $form['port'] === '11000' ? 'selected' : '' ?>>11000</option>
                            </select>
                            <span class="mt-2 block text-xs text-slate-500 dark:text-slate-400">Keep port 80 unless another service already uses it</span>
                        </label>

                        <div class="flex flex-col">
                            <span class="mb-2 block text-sm font-medium text-transparent select-none">Action</span>
                            <button class="h-12 w-full rounded-lg bg-blue-600 px-5 text-sm font-semibold text-white transition hover:bg-blue-700 focus:outline-none focus:ring-4 focus:ring-blue-500/20">
                                <span class="inline-flex items-center justify-center gap-2">
                                    <i class="bi bi-plus-circle"></i>
                                    Add Domain
                                </span>
                            </button>
                        </div>
                    </div>
                </form>
            </div>

            <div class="rounded-xl border border-slate-200 bg-slate-50 p-6 shadow-sm dark:border-slate-800 dark:bg-slate-950/70 sm:p-7">
                <h2 class="text-xl font-semibold text-slate-900 dark:text-white">Quick Guide</h2>
                <div class="mt-5 space-y-4 text-sm leading-6 text-slate-600 dark:text-slate-300">
                    <div class="flex gap-3">
                        <div class="mt-0.5 flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-blue-600 text-xs font-semibold text-white">1</div>
                        <p>Enter a domain like <span class="font-semibold text-slate-900 dark:text-white">myproject.local</span>.</p>
                    </div>
                    <div class="flex gap-3">
                        <div class="mt-0.5 flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-blue-600 text-xs font-semibold text-white">2</div>
                        <p>Choose the folder Apache should open, usually the project root or public directory.</p>
                    </div>
                    <div class="flex gap-3">
                        <div class="mt-0.5 flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-blue-600 text-xs font-semibold text-white">3</div>
                        <p>Select a free port, submit, then restart Apache from XAMPP.</p>
                    </div>
                </div>

                <div class="mt-6 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200">
                    <div class="flex items-start gap-3">
                        <i class="bi bi-shield-lock mt-0.5"></i>
                        <p>This page updates the Windows hosts file and Apache config. Open XAMPP with administrator rights before making changes.</p>
                    </div>
                </div>
            </div>
        </div>

        <div class="mt-8 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900/80">
            <div class="flex flex-col gap-3 border-b border-slate-200 px-6 py-5 dark:border-slate-800 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h2 class="text-xl font-semibold text-slate-900 dark:text-white">Existing Domains</h2>
                    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Open, review, or remove domains already written to your Apache vhosts file.</p>
                </div>
                <div class="inline-flex items-center gap-2 rounded-md bg-slate-100 px-3 py-1 text-xs font-medium text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                    <i class="bi bi-collection"></i>
                    <span id="domainCountLabel"><?= $domainCount ?> item<?= $domainCount === 1 ? '' : 's' ?></span>
                </div>
            </div>

            <?php if ($domainCount === 0): ?>
                <div class="px-6 py-14 text-center">
                    <div class="mx-auto flex h-16 w-16 items-center justify-center rounded-lg bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-300">
                        <i class="bi bi-window-stack text-2xl"></i>
                    </div>
                    <h3 class="mt-4 text-lg font-semibold text-slate-900 dark:text-white">No domains added yet</h3>
                    <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">Create your first local domain using the form above.</p>
                </div>
            <?php else: ?>
                <div class="border-b border-slate-200 px-6 py-4 dark:border-slate-800">
                    <div class="relative max-w-md">
                        <i class="bi bi-search pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 text-slate-400"></i>
                        <input id="domainSearch" type="search" placeholder="Search by domain, port, or path"
                            class="h-11 w-full rounded-lg border border-slate-200 bg-slate-50 pl-11 pr-4 text-sm text-slate-900 outline-none transition focus:border-blue-500 focus:bg-white focus:ring-4 focus:ring-blue-500/10 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100 dark:focus:bg-slate-900">
                    </div>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-[0.16em] text-slate-500 dark:bg-slate-950 dark:text-slate-400">
                            <tr>
                                <th class="px-6 py-4">Domain</th>
                                <th class="px-6 py-4">Port</th>
                                <th class="px-6 py-4">Path</th>
                                <th class="px-6 py-4 text-center">Action</th>
                            </tr>
                        </thead>
                        <tbody id="domainsTableBody">
                            <?php foreach ($domains as $d): ?>
                                <tr class="domain-row border-t border-slate-200 transition hover:bg-slate-50 dark:border-slate-800 dark:hover:bg-slate-950/70"
                                    data-search="<?= escape(strtolower($d['domain'] . ' ' . $d['port'] . ' ' . $d['path'])) ?>">
                                    <td class="px-6 py-4">
                                        <a href="http://<?= escape($d['domain'] . ':' . $d['port']) ?>" target="_blank"
                                            class="inline-flex items-center gap-2 font-medium text-blue-600 hover:text-blue-700 hover:underline dark:text-blue-400 dark:hover:text-blue-300">
                                            <span class="rounded-md bg-blue-50 px-3 py-1 text-xs font-semibold uppercase tracking-[0.14em] text-blue-700 dark:bg-blue-500/10 dark:text-blue-300">Site</span>
                                            <?= escape($d['domain']) ?>
                                            <i class="bi bi-box-arrow-up-right text-xs"></i>
                                        </a>
                                    </td>
                                    <td class="px-6 py-4">
                                        <span class="inline-flex rounded-md bg-slate-100 px-3 py-1 font-semibold text-slate-700 dark:bg-slate-800 dark:text-slate-200"><?= escape($d['port']) ?></span>
                                    </td>
                                    <td class="px-6 py-4 text-slate-500 dark:text-slate-400">
                                        <div class="flex flex-wrap items-center gap-3">
                                            <button type="submit" form="open-folder-<?= md5($d['domain'] . $d['path']) ?>"
                                                class="inline-flex items-center gap-2 rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-left text-sm font-medium text-slate-700 transition hover:border-blue-300 hover:bg-blue-50 hover:text-blue-700 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800 dark:hover:text-blue-300"
                                                title="Open folder in File Explorer">
                                                <i class="bi bi-folder2-open"></i>
                                                <span class="max-w-xl break-all"><?= escape($d['path']) ?></span>
                                            </button>
                                            <button type="button"
                                                class="copy-trigger inline-flex items-center gap-2 rounded-md border border-slate-200 px-3 py-2 text-xs font-medium text-slate-600 transition hover:bg-slate-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800"
                                                data-copy-value="<?= escape($d['path']) ?>"
                                                data-copy-label="Path">
                                                <i class="bi bi-clipboard"></i>
                                                Copy Path
                                            </button>
                                            <button type="button"
                                                class="meta-trigger inline-flex items-center gap-2 rounded-md border border-slate-200 px-3 py-2 text-xs font-medium text-slate-600 transition hover:bg-slate-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800"
                                                data-domain="<?= escape($d['domain']) ?>"
                                                data-path="<?= escape($d['path']) ?>">
                                                <i class="bi bi-info-circle"></i>
                                                Info
                                            </button>
                                        </div>
                                        <form id="open-folder-<?= md5($d['domain'] . $d['path']) ?>" method="post" class="hidden">
                                            <input type="hidden" name="action" value="open_folder">
                                            <input type="hidden" name="path" value="<?= escape($d['path']) ?>">
                                        </form>
                                    </td>
                                    <td class="px-6 py-4 text-center">
                                        <div class="flex flex-wrap items-center justify-center gap-2">
                                            <button type="button"
                                                class="copy-trigger inline-flex items-center gap-2 rounded-md border border-slate-200 px-3 py-2 text-xs font-medium text-slate-600 transition hover:bg-slate-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800"
                                                data-copy-value="<?= escape('http://' . $d['domain'] . ':' . $d['port']) ?>"
                                                data-copy-label="URL">
                                                <i class="bi bi-link-45deg"></i>
                                                Copy URL
                                            </button>
                                            <form method="post" class="delete-form inline-flex" data-domain="<?= escape($d['domain']) ?>">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="domain" value="<?= escape($d['domain']) ?>">
                                                <button type="button" class="delete-trigger inline-flex items-center gap-2 rounded-lg border border-red-200 px-4 py-2 font-medium text-red-600 transition hover:border-red-300 hover:bg-red-50 hover:text-red-700 dark:border-red-500/20 dark:text-red-300 dark:hover:bg-red-500/10">
                                                    <i class="bi bi-trash"></i>
                                                    Delete
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div id="domainSearchEmpty" class="hidden px-6 py-10 text-center text-sm text-slate-500 dark:text-slate-400">
                    No domains matched your search.
                </div>
            <?php endif; ?>
        </div>

        <p class="mt-6 text-xs text-slate-500 dark:text-slate-400">
            Changes are written immediately to your hosts and Apache config files. Restart Apache after add or delete actions.
        </p>
    </div>
</div>

<button id="themeToggle"
    class="fixed bottom-6 right-6 z-40 inline-flex h-12 w-12 items-center justify-center rounded-lg border border-slate-200 bg-white text-slate-700 shadow-lg transition hover:bg-slate-50 focus:outline-none focus:ring-4 focus:ring-blue-500/20 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100 dark:hover:bg-slate-800"
    type="button" aria-label="Toggle theme" title="Toggle theme">
    <i id="themeToggleIcon" class="bi bi-moon-stars text-lg"></i>
</button>

<div id="metaModal" class="fixed inset-0 z-50 hidden items-center justify-center p-4">
    <div id="metaModalBackdrop" class="absolute inset-0 bg-slate-950/55 backdrop-blur-sm"></div>
    <div class="relative w-full max-w-lg rounded-xl border border-slate-200 bg-white p-6 shadow-2xl dark:border-slate-700 dark:bg-slate-900">
        <div class="flex items-start gap-4">
            <div class="flex h-11 w-11 items-center justify-center rounded-lg bg-blue-50 text-blue-600 dark:bg-blue-500/10 dark:text-blue-300">
                <i class="bi bi-folder2-open"></i>
            </div>
            <div class="flex-1">
                <h3 class="text-lg font-semibold text-slate-900 dark:text-white">Folder Details</h3>
                <p id="metaModalPath" class="mt-2 break-all text-sm leading-6 text-slate-500 dark:text-slate-400"></p>
                <div id="metaModalStatus" class="mt-3 text-xs font-medium text-slate-500 dark:text-slate-400"></div>
            </div>
        </div>

        <div id="metaModalLoading" class="mt-6 flex items-center gap-3 rounded-lg border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-600 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300">
            <i class="bi bi-arrow-repeat animate-spin"></i>
            Loading folder information...
        </div>

        <div id="metaModalGrid" class="mt-6 hidden grid gap-3 sm:grid-cols-2">
            <div class="rounded-lg border border-slate-200 bg-slate-50 p-4 dark:border-slate-700 dark:bg-slate-800">
                <div class="text-xs uppercase tracking-[0.16em] text-slate-500 dark:text-slate-400">Folder Size</div>
                <div id="metaSize" class="mt-2 text-lg font-semibold text-slate-900 dark:text-white">-</div>
            </div>
            <div class="rounded-lg border border-slate-200 bg-slate-50 p-4 dark:border-slate-700 dark:bg-slate-800">
                <div class="text-xs uppercase tracking-[0.16em] text-slate-500 dark:text-slate-400">Items</div>
                <div id="metaItems" class="mt-2 text-lg font-semibold text-slate-900 dark:text-white">-</div>
            </div>
            <div class="rounded-lg border border-slate-200 bg-slate-50 p-4 dark:border-slate-700 dark:bg-slate-800">
                <div class="text-xs uppercase tracking-[0.16em] text-slate-500 dark:text-slate-400">Git Status</div>
                <div id="metaGit" class="mt-2 text-lg font-semibold text-slate-900 dark:text-white">-</div>
            </div>
            <div class="rounded-lg border border-slate-200 bg-slate-50 p-4 dark:border-slate-700 dark:bg-slate-800">
                <div class="text-xs uppercase tracking-[0.16em] text-slate-500 dark:text-slate-400">Folder Status</div>
                <div id="metaExists" class="mt-2 text-lg font-semibold text-slate-900 dark:text-white">-</div>
            </div>
        </div>

        <div id="metaLastUpdated" class="mt-4 hidden text-xs text-slate-500 dark:text-slate-400"></div>

        <div class="mt-6 flex justify-end">
            <button id="closeMetaButton" type="button"
                class="rounded-lg border border-slate-200 px-4 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-50 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800">
                Close
            </button>
        </div>
    </div>
</div>

<div id="deleteModal" class="fixed inset-0 z-50 hidden items-center justify-center p-4">
    <div id="deleteModalBackdrop" class="absolute inset-0 bg-slate-950/55 backdrop-blur-sm"></div>
    <div class="relative w-full max-w-md rounded-xl border border-slate-200 bg-white p-6 shadow-2xl dark:border-slate-700 dark:bg-slate-900">
        <div class="flex items-start gap-4">
            <div class="flex h-11 w-11 items-center justify-center rounded-lg bg-red-50 text-red-600 dark:bg-red-500/10 dark:text-red-300">
                <i class="bi bi-trash3"></i>
            </div>
            <div class="flex-1">
                <h3 class="text-lg font-semibold text-slate-900 dark:text-white">Delete domain?</h3>
                <p class="mt-2 text-sm leading-6 text-slate-500 dark:text-slate-400">
                    Are you sure you want to delete <span id="deleteDomainName" class="font-semibold text-slate-900 dark:text-white"></span>?
                    This will remove it from your hosts file and Apache vhost config.
                </p>
            </div>
        </div>

        <div class="mt-6 flex justify-end gap-3">
            <button id="cancelDeleteButton" type="button"
                class="rounded-lg border border-slate-200 px-4 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-50 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800">
                No
            </button>
            <button id="confirmDeleteButton" type="button"
                class="rounded-lg bg-red-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-red-700 focus:outline-none focus:ring-4 focus:ring-red-500/20">
                Yes, Delete
            </button>
        </div>
    </div>
</div>

    <script src='https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js'></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/selectize.js/0.15.2/css/selectize.bootstrap5.min.css" rel="stylesheet">
<script src="https://cdnjs.cloudflare.com/ajax/libs/selectize.js/0.15.2/js/selectize.min.js"></script>
<script>
    $(".selectize").each(function () {
        const selectedPort = $(this).data("selected-port");

        $(this).selectize({
            create: false,
            sortField: "text",
            plugins: ["clear_button"],
            items: selectedPort ? [String(selectedPort)] : undefined,
        });
    });

    const domainSearch = document.getElementById("domainSearch");

    if (domainSearch) {
        const rows = Array.from(document.querySelectorAll(".domain-row"));
        const emptyState = document.getElementById("domainSearchEmpty");
        const countLabel = document.getElementById("domainCountLabel");
        const totalCount = rows.length;

        const updateSearch = function () {
            const query = domainSearch.value.trim().toLowerCase();
            let visibleCount = 0;

            rows.forEach(function (row) {
                const haystack = row.dataset.search || "";
                const matches = query === "" || haystack.indexOf(query) !== -1;

                row.style.display = matches ? "" : "none";

                if (matches) {
                    visibleCount += 1;
                }
            });

            if (emptyState) {
                emptyState.classList.toggle("hidden", visibleCount !== 0);
            }

            if (countLabel) {
                const labelCount = query === "" ? totalCount : visibleCount;
                countLabel.textContent = labelCount + " item" + (labelCount === 1 ? "" : "s");
            }
        };

        domainSearch.addEventListener("input", updateSearch);
        updateSearch();
    }

    const root = document.documentElement;
    const themeToggle = document.getElementById("themeToggle");
    const themeToggleIcon = document.getElementById("themeToggleIcon");

    const applyTheme = function (theme) {
        if (typeof window.applyLocalDomainTheme === "function") {
            window.applyLocalDomainTheme(theme);
        } else {
            root.classList.toggle("dark", theme === "dark");
            root.setAttribute("data-theme", theme === "dark" ? "dark" : "light");
            root.style.colorScheme = theme === "dark" ? "dark" : "light";
        }

        if (themeToggleIcon) {
            themeToggleIcon.className = theme === "dark" ? "bi bi-sun text-lg" : "bi bi-moon-stars text-lg";
        }

        if (themeToggle) {
            themeToggle.setAttribute("title", theme === "dark" ? "Switch to light mode" : "Switch to dark mode");
            themeToggle.setAttribute("aria-label", theme === "dark" ? "Switch to light mode" : "Switch to dark mode");
        }
    };

    let currentTheme = window.matchMedia("(prefers-color-scheme: dark)").matches ? "dark" : "light";

    try {
        currentTheme = localStorage.getItem("localDomainTheme") || currentTheme;
    } catch (error) {
    }

    applyTheme(currentTheme);

    if (themeToggle) {
        themeToggle.addEventListener("click", function () {
            const nextTheme = (typeof window.getLocalDomainTheme === "function" ? window.getLocalDomainTheme() : (root.classList.contains("dark") ? "dark" : "light")) === "dark" ? "light" : "dark";
            applyTheme(nextTheme);
        });
    }

    const deleteModal = document.getElementById("deleteModal");
    const deleteModalBackdrop = document.getElementById("deleteModalBackdrop");
    const deleteDomainName = document.getElementById("deleteDomainName");
    const confirmDeleteButton = document.getElementById("confirmDeleteButton");
    const cancelDeleteButton = document.getElementById("cancelDeleteButton");
    const deleteTriggers = document.querySelectorAll(".delete-trigger");
    const copyTriggers = document.querySelectorAll(".copy-trigger");
    const metaTriggers = document.querySelectorAll(".meta-trigger");
    const metaModal = document.getElementById("metaModal");
    const metaModalBackdrop = document.getElementById("metaModalBackdrop");
    const closeMetaButton = document.getElementById("closeMetaButton");
    const metaModalPath = document.getElementById("metaModalPath");
    const metaModalStatus = document.getElementById("metaModalStatus");
    const metaModalLoading = document.getElementById("metaModalLoading");
    const metaModalGrid = document.getElementById("metaModalGrid");
    const metaLastUpdated = document.getElementById("metaLastUpdated");
    const metaSize = document.getElementById("metaSize");
    const metaItems = document.getElementById("metaItems");
    const metaGit = document.getElementById("metaGit");
    const metaExists = document.getElementById("metaExists");
    let activeDeleteForm = null;
    let activeMetaKey = null;

    const closeDeleteModal = function () {
        if (!deleteModal) {
            return;
        }

        deleteModal.classList.add("hidden");
        deleteModal.classList.remove("flex");
        activeDeleteForm = null;
    };

    const openDeleteModal = function (form) {
        if (!deleteModal || !deleteDomainName) {
            return;
        }

        activeDeleteForm = form;
        deleteDomainName.textContent = form.dataset.domain || "this domain";
        deleteModal.classList.remove("hidden");
        deleteModal.classList.add("flex");
    };

    deleteTriggers.forEach(function (button) {
        button.addEventListener("click", function () {
            const form = button.closest(".delete-form");
            if (form) {
                openDeleteModal(form);
            }
        });
    });

    if (cancelDeleteButton) {
        cancelDeleteButton.addEventListener("click", closeDeleteModal);
    }

    if (confirmDeleteButton) {
        confirmDeleteButton.addEventListener("click", function () {
            if (activeDeleteForm) {
                activeDeleteForm.submit();
            }
        });
    }

    if (deleteModalBackdrop) {
        deleteModalBackdrop.addEventListener("click", closeDeleteModal);
    }

    const getMetaCookieName = function (domain, path) {
        return "ldm_meta_" + btoa(domain + "|" + path).replace(/[^a-zA-Z0-9]/g, "").slice(0, 32);
    };

    const readCookie = function (name) {
        const escapedName = name.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
        const match = document.cookie.match(new RegExp("(?:^|; )" + escapedName + "=([^;]*)"));
        return match ? decodeURIComponent(match[1]) : null;
    };

    const writeCookie = function (name, value) {
        const expires = new Date(Date.now() + (7 * 24 * 60 * 60 * 1000)).toUTCString();
        document.cookie = name + "=" + encodeURIComponent(value) + "; expires=" + expires + "; path=/; SameSite=Lax";
    };

    const setMetaLoading = function (isLoading, message) {
        if (!metaModalLoading) {
            return;
        }

        metaModalLoading.classList.toggle("hidden", !isLoading);
        metaModalLoading.innerHTML = isLoading
            ? '<i class="bi bi-arrow-repeat animate-spin"></i> ' + (message || 'Loading folder information...')
            : '';
    };

    const renderMeta = function (meta, sourceLabel) {
        if (!metaModalGrid || !metaSize || !metaItems || !metaGit || !metaExists || !metaLastUpdated || !metaModalStatus) {
            return;
        }

        metaSize.textContent = meta.size_label || "Unavailable";
        metaItems.textContent = (meta.item_count ?? 0) + " items";
        metaGit.textContent = meta.is_git ? "Git repo" : "No Git";
        metaExists.textContent = meta.exists ? "Folder exists" : "Missing folder";
        metaLastUpdated.textContent = "Last updated: " + (meta.last_modified || "Unavailable") + " • Checked: " + (meta.checked_at || "Just now");
        metaModalStatus.textContent = sourceLabel || "";

        metaModalGrid.classList.remove("hidden");
        metaLastUpdated.classList.remove("hidden");
    };

    const openMetaModal = function (domain, path) {
        if (!metaModal || !metaModalPath) {
            return;
        }

        activeMetaKey = getMetaCookieName(domain, path);
        metaModalPath.textContent = path;
        metaModal.classList.remove("hidden");
        metaModal.classList.add("flex");
        metaModalGrid.classList.add("hidden");
        metaLastUpdated.classList.add("hidden");
        setMetaLoading(true, "Loading folder information...");

        const cachedValue = readCookie(activeMetaKey);

        if (cachedValue) {
            try {
                const cachedMeta = JSON.parse(cachedValue);
                renderMeta(cachedMeta, "Showing last cached check while refreshing...");
            } catch (error) {
            }
        }

        fetch("?action=folder_meta&path=" + encodeURIComponent(path), {
            headers: {
                "X-Requested-With": "XMLHttpRequest"
            }
        })
            .then(function (response) {
                if (!response.ok) {
                    throw new Error("Request failed");
                }

                return response.json();
            })
            .then(function (payload) {
                if (!payload.ok || !payload.meta) {
                    throw new Error(payload.message || "Unable to fetch folder details.");
                }

                if (activeMetaKey) {
                    writeCookie(activeMetaKey, JSON.stringify(payload.meta));
                }

                renderMeta(payload.meta, "Live folder check completed.");
                setMetaLoading(false);
            })
            .catch(function () {
                metaModalStatus.textContent = cachedValue
                    ? "Live refresh failed. Showing cached details."
                    : "Could not load folder details.";
                setMetaLoading(false);
            });
    };

    const closeMetaModal = function () {
        if (!metaModal) {
            return;
        }

        metaModal.classList.add("hidden");
        metaModal.classList.remove("flex");
        activeMetaKey = null;
    };

    metaTriggers.forEach(function (button) {
        button.addEventListener("click", function () {
            openMetaModal(button.dataset.domain || "", button.dataset.path || "");
        });
    });

    if (metaModalBackdrop) {
        metaModalBackdrop.addEventListener("click", closeMetaModal);
    }

    if (closeMetaButton) {
        closeMetaButton.addEventListener("click", closeMetaModal);
    }

    copyTriggers.forEach(function (button) {
        button.addEventListener("click", async function () {
            const value = button.dataset.copyValue || "";
            const label = button.dataset.copyLabel || "Value";
            const previousHtml = button.innerHTML;

            try {
                await navigator.clipboard.writeText(value);
                button.innerHTML = '<i class="bi bi-check2"></i> Copied';
                button.classList.add("border-emerald-300", "text-emerald-600", "dark:text-emerald-300");
            } catch (error) {
                button.innerHTML = '<i class="bi bi-x-lg"></i> Copy failed';
                button.classList.add("border-red-300", "text-red-600", "dark:text-red-300");
                console.error(label + " copy failed", error);
            }

            window.setTimeout(function () {
                button.innerHTML = previousHtml;
                button.classList.remove("border-emerald-300", "text-emerald-600", "dark:text-emerald-300", "border-red-300", "text-red-600", "dark:text-red-300");
            }, 1400);
        });
    });

    document.addEventListener("keydown", function (event) {
        if (event.key === "Escape") {
            closeDeleteModal();
            closeMetaModal();
        }
    });
</script>
<style>

/* ===============================
   COMMON (light + dark shared)
=============================== */

.selectize-input {
    padding: .72rem .95rem;
    border-radius: 10px;
    min-height: 48px;
    font-size: 14px;
    line-height: 1.4;
}

.selectize-input.focus {
    box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.10) !important;
}

.selectize-dropdown {
    border-radius: 10px;
    overflow: hidden;
    margin-top: 8px;
    box-shadow: 0 20px 50px rgba(15, 23, 42, 0.14);
}

.selectize-dropdown-content {
    max-height: 240px;
    padding: 6px;
}

.selectize-control.plugin-clear_button .clear {
    font-weight: 100 !important;
    right: 34px;
    color: inherit;
}

.selectize-dropdown .option {
    border-radius: 8px;
    margin-bottom: 2px;
    padding: 10px 12px;
}

.selectize-input {
    background: #ffffff;
    border: 1px solid #e5e7eb;
    color: #111827;
    box-shadow: inset 0 1px 2px rgba(15, 23, 42, 0.03);
}

.selectize-input input {
    color: #111827;
}

.selectize-dropdown {
    background: #ffffff;
    border: 1px solid #e5e7eb;
}

.selectize-dropdown .option {
    color: #111827;
}

.selectize-dropdown .option:hover,
.selectize-dropdown .active {
    background: #2563eb;
    color: #fff;
}

html.dark .selectize-input,
html.dark .selectize-control.single .selectize-input.input-active,
html.dark .selectize-input.full {
    background: #0f172a;
    border: 1px solid #334155;
    color: #e2e8f0;
}

html.dark .selectize-input input {
    color: #e2e8f0;
    background: transparent;
}

html.dark .selectize-dropdown {
    background: #0f172a;
    border: 1px solid #334155;
}

html.dark .selectize-dropdown-content {
    background: #0f172a;
}

html.dark .selectize-dropdown .option {
    color: #e2e8f0;
}

html.dark .selectize-dropdown .option:hover {
    background: #1e293b;
    color: #fff;
}

html.dark .selectize-dropdown .active {
    background: #2563eb;
    color: white;
}

html.dark .selectize-control.single .selectize-input:after {
    border-color: #cbd5e1 transparent transparent transparent;
}

</style>

</body>

</html>