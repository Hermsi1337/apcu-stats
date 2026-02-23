<?php
declare(strict_types=1);

if (PHP_SAPI === 'cli') {
    fwrite(STDERR, "This script is meant to run through a web server.\n");
    exit(1);
}

header('Content-Type: text/html; charset=UTF-8');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function esc(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function formatBytes(int|float $bytes): string
{
    $bytes = max(0, (float) $bytes);
    $units = ['B', 'KiB', 'MiB', 'GiB', 'TiB'];
    $power = 0;

    while ($bytes >= 1024 && $power < count($units) - 1) {
        $bytes /= 1024;
        $power++;
    }

    return number_format($bytes, $power === 0 ? 0 : 2, '.', ',') . ' ' . $units[$power];
}

function formatDuration(int $seconds): string
{
    if ($seconds <= 0) {
        return '0s';
    }

    $units = [
        'd' => 86400,
        'h' => 3600,
        'm' => 60,
        's' => 1,
    ];

    $parts = [];
    foreach ($units as $name => $size) {
        if ($seconds < $size) {
            continue;
        }
        $value = intdiv($seconds, $size);
        $seconds -= $value * $size;
        $parts[] = $value . $name;
        if (count($parts) === 2) {
            break;
        }
    }

    return implode(' ', $parts);
}

function csrfToken(): string
{
    if (!isset($_SESSION['apcu_stats_csrf']) || !is_string($_SESSION['apcu_stats_csrf'])) {
        $_SESSION['apcu_stats_csrf'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['apcu_stats_csrf'];
}

function postString(string $key): string
{
    $value = $_POST[$key] ?? '';
    return is_string($value) ? $value : '';
}

function getString(string $key, string $default = ''): string
{
    $value = $_GET[$key] ?? $default;
    return is_string($value) ? $value : $default;
}

function getInt(string $key, int $default, int $min, int $max): int
{
    $value = $_GET[$key] ?? null;
    if ($value === null || $value === '') {
        return $default;
    }

    $parsed = filter_var($value, FILTER_VALIDATE_INT);
    if ($parsed === false) {
        return $default;
    }

    return max($min, min($max, $parsed));
}

function isApcuAvailable(): bool
{
    if (!extension_loaded('apcu')) {
        return false;
    }

    if (!function_exists('apcu_cache_info') || !function_exists('apcu_sma_info')) {
        return false;
    }

    if (function_exists('apcu_enabled') && !apcu_enabled()) {
        return false;
    }

    return true;
}

function mustAuthFromEnv(): bool
{
    return getenv('APCU_STATS_USER') !== false || getenv('APCU_STATS_PASS') !== false;
}

function requireBasicAuth(): void
{
    if (!mustAuthFromEnv()) {
        return;
    }

    $expectedUser = (string) getenv('APCU_STATS_USER');
    $expectedPass = (string) getenv('APCU_STATS_PASS');
    $actualUser = (string) ($_SERVER['PHP_AUTH_USER'] ?? '');
    $actualPass = (string) ($_SERVER['PHP_AUTH_PW'] ?? '');

    if (hash_equals($expectedUser, $actualUser) && hash_equals($expectedPass, $actualPass)) {
        return;
    }

    header('WWW-Authenticate: Basic realm="APCu Stats"');
    http_response_code(401);
    echo "Authentication required.\n";
    exit(0);
}

requireBasicAuth();

$messages = [];
$now = time();
$apcuAvailable = isApcuAvailable();

if ($apcuAvailable && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $submittedToken = postString('csrf_token');
    if (!hash_equals(csrfToken(), $submittedToken)) {
        $messages[] = ['type' => 'error', 'text' => 'Invalid CSRF token.'];
    } else {
        $action = postString('action');
        if ($action === 'clear') {
            $ok = apcu_clear_cache();
            $messages[] = [
                'type' => $ok ? 'success' : 'error',
                'text' => $ok ? 'APCu cache cleared.' : 'Unable to clear APCu cache.',
            ];
        } elseif ($action === 'delete') {
            $key = postString('key');
            if ($key === '') {
                $messages[] = ['type' => 'error', 'text' => 'Missing key.'];
            } else {
                $ok = apcu_delete($key);
                $messages[] = [
                    'type' => $ok ? 'success' : 'error',
                    'text' => $ok ? sprintf('Deleted key "%s".', $key) : sprintf('Failed to delete key "%s".', $key),
                ];
            }
        }
    }
}

$search = trim(getString('q', ''));
$sort = getString('sort', 'hits');
$dir = strtolower(getString('dir', 'desc')) === 'asc' ? 'asc' : 'desc';
$limit = getInt('limit', 200, 10, 1000);

$cacheInfo = null;
$smaInfo = null;
$entries = [];
$entryTotal = 0;

$hits = 0;
$misses = 0;
$inserts = 0;
$expunges = 0;
$startTime = 0;
$numEntries = 0;
$requests = 0;
$hitRate = 0.0;
$uptime = 0;
$requestRate = 0.0;
$hitRatePerSecond = 0.0;
$missRatePerSecond = 0.0;
$insertRatePerSecond = 0.0;
$segmentSize = 0;
$segmentCount = 0;
$totalMemory = 0;
$availableMemory = 0;
$usedMemory = 0;
$usagePercent = 0.0;
$fragmentation = 0.0;
$phpVersion = PHP_VERSION;
$apcuVersion = phpversion('apcu') ?: 'unknown';

if ($apcuAvailable) {
    $cacheInfo = apcu_cache_info(false);
    $smaInfo = apcu_sma_info(false);

    if (!is_array($cacheInfo)) {
        $cacheInfo = [];
        $messages[] = ['type' => 'error', 'text' => 'apcu_cache_info() did not return data.'];
    }

    if (!is_array($smaInfo)) {
        $smaInfo = [];
        $messages[] = ['type' => 'error', 'text' => 'apcu_sma_info() did not return data.'];
    }

    $hits = (int) ($cacheInfo['num_hits'] ?? 0);
    $misses = (int) ($cacheInfo['num_misses'] ?? 0);
    $inserts = (int) ($cacheInfo['num_inserts'] ?? 0);
    $expunges = (int) ($cacheInfo['expunges'] ?? 0);
    $startTime = (int) ($cacheInfo['start_time'] ?? 0);
    $numEntries = (int) ($cacheInfo['num_entries'] ?? 0);
    $requests = $hits + $misses;
    $hitRate = $requests > 0 ? ($hits / $requests) * 100 : 0;
    $uptime = $startTime > 0 ? max(0, $now - $startTime) : 0;
    $requestRate = $uptime > 0 ? $requests / $uptime : 0;
    $hitRatePerSecond = $uptime > 0 ? $hits / $uptime : 0;
    $missRatePerSecond = $uptime > 0 ? $misses / $uptime : 0;
    $insertRatePerSecond = $uptime > 0 ? $inserts / $uptime : 0;

    $segmentSize = (int) ($smaInfo['seg_size'] ?? 0);
    $segmentCount = (int) ($smaInfo['num_seg'] ?? 0);
    $totalMemory = $segmentSize * max(1, $segmentCount);
    $availableMemory = (int) ($smaInfo['avail_mem'] ?? 0);
    $usedMemory = (int) ($cacheInfo['mem_size'] ?? max(0, $totalMemory - $availableMemory));
    if ($totalMemory <= 0) {
        $totalMemory = $usedMemory + $availableMemory;
    }
    $usagePercent = $totalMemory > 0 ? ($usedMemory / $totalMemory) * 100 : 0;

    $largestBlock = 0;
    $totalFree = 0;
    $blockLists = $smaInfo['block_lists'] ?? [];
    if (is_array($blockLists)) {
        foreach ($blockLists as $list) {
            if (!is_array($list)) {
                continue;
            }
            foreach ($list as $block) {
                if (!is_array($block)) {
                    continue;
                }
                $size = (int) ($block['size'] ?? 0);
                if ($size <= 0) {
                    continue;
                }
                $totalFree += $size;
                $largestBlock = max($largestBlock, $size);
            }
        }
    }
    $fragmentation = $totalFree > 0 ? (1 - ($largestBlock / $totalFree)) * 100 : 0;

    $cacheList = $cacheInfo['cache_list'] ?? [];
    if (is_array($cacheList)) {
        foreach ($cacheList as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $key = (string) ($entry['info'] ?? $entry['key'] ?? '');
            if ($key === '') {
                continue;
            }

            if ($search !== '' && stripos($key, $search) === false) {
                continue;
            }

            $creation = (int) ($entry['creation_time'] ?? $entry['ctime'] ?? 0);
            $access = (int) ($entry['access_time'] ?? $entry['atime'] ?? 0);
            $mtime = (int) ($entry['mtime'] ?? $access);
            $ttl = (int) ($entry['ttl'] ?? 0);
            $expiresAt = ($ttl > 0 && $creation > 0) ? ($creation + $ttl) : 0;
            $ttlLeft = $expiresAt > 0 ? max(0, $expiresAt - $now) : null;

            $entries[] = [
                'key' => $key,
                'hits' => (int) ($entry['num_hits'] ?? $entry['nhits'] ?? 0),
                'size' => (int) ($entry['mem_size'] ?? $entry['size'] ?? 0),
                'ttl' => $ttl,
                'ttl_left' => $ttlLeft,
                'creation' => $creation,
                'mtime' => $mtime,
                'access' => $access,
            ];
        }
    }

    $sorters = [
        'key' => static fn(array $a, array $b): int => strcmp($a['key'], $b['key']),
        'hits' => static fn(array $a, array $b): int => $a['hits'] <=> $b['hits'],
        'size' => static fn(array $a, array $b): int => $a['size'] <=> $b['size'],
        'ttl' => static fn(array $a, array $b): int => ($a['ttl_left'] ?? PHP_INT_MAX) <=> ($b['ttl_left'] ?? PHP_INT_MAX),
        'created' => static fn(array $a, array $b): int => $a['creation'] <=> $b['creation'],
        'access' => static fn(array $a, array $b): int => $a['access'] <=> $b['access'],
    ];

    $sorter = $sorters[$sort] ?? $sorters['hits'];
    usort(
        $entries,
        static function (array $a, array $b) use ($sorter, $dir): int {
            $result = $sorter($a, $b);
            return $dir === 'asc' ? $result : -$result;
        }
    );

    $entryTotal = count($entries);
    if ($numEntries === 0) {
        $numEntries = $entryTotal;
    }
    $entries = array_slice($entries, 0, $limit);
}

$csrf = csrfToken();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>APCu Stats</title>
    <style>
        :root {
            --bg: #f5f7fa;
            --card: #ffffff;
            --text: #11213a;
            --muted: #5b667a;
            --accent: #0f766e;
            --accent-soft: #d4f4ef;
            --warning: #d97706;
            --danger: #b91c1c;
            --border: #dbe2ea;
            --success-bg: #d1fae5;
            --success-text: #065f46;
            --error-bg: #fee2e2;
            --error-text: #991b1b;
        }

        * { box-sizing: border-box; }
        body {
            margin: 0;
            font: 14px/1.5 "IBM Plex Sans", "Segoe UI", "Helvetica Neue", Arial, sans-serif;
            background: radial-gradient(circle at top, #e8f8f6 0%, var(--bg) 55%);
            color: var(--text);
        }
        .wrap { max-width: 1200px; margin: 24px auto; padding: 0 16px 24px; }
        h1 { margin: 0 0 8px; font-size: 30px; }
        .subtitle { color: var(--muted); margin-bottom: 16px; }
        .panel {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 14px;
            margin-bottom: 14px;
        }
        .grid {
            display: grid;
            gap: 10px;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
        }
        .metric { background: #f8fafc; border: 1px solid #e5edf5; border-radius: 8px; padding: 10px; }
        .metric .label { color: var(--muted); font-size: 12px; text-transform: uppercase; letter-spacing: .04em; }
        .metric .value { font-size: 20px; font-weight: 700; margin-top: 4px; }
        .progress {
            height: 9px; border-radius: 999px; background: #ecf2f9; overflow: hidden; margin-top: 8px;
        }
        .progress > span {
            display: block; height: 100%; background: linear-gradient(90deg, #10b981, #0f766e);
        }
        .controls {
            display: grid; gap: 10px; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            align-items: end;
        }
        label { display: block; font-weight: 600; font-size: 12px; color: var(--muted); margin-bottom: 4px; }
        input[type="text"], input[type="number"], select {
            width: 100%;
            padding: 8px 10px;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: #fff;
            color: var(--text);
        }
        button {
            border: 0;
            border-radius: 8px;
            padding: 9px 11px;
            cursor: pointer;
            font-weight: 700;
            color: #fff;
            background: var(--accent);
        }
        button.secondary { background: #475569; }
        button.danger { background: var(--danger); }
        .toolbar { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; margin-top: 10px; }
        .alert {
            border-radius: 8px;
            padding: 9px 12px;
            margin-bottom: 10px;
            border: 1px solid transparent;
        }
        .alert.success { background: var(--success-bg); color: var(--success-text); border-color: #6ee7b7; }
        .alert.error { background: var(--error-bg); color: var(--error-text); border-color: #fca5a5; }
        table { width: 100%; border-collapse: collapse; }
        th, td {
            border-bottom: 1px solid var(--border);
            padding: 8px;
            text-align: left;
            vertical-align: top;
            white-space: nowrap;
        }
        th { font-size: 12px; text-transform: uppercase; color: var(--muted); letter-spacing: .04em; }
        td.key { max-width: 380px; overflow: hidden; text-overflow: ellipsis; }
        .empty { color: var(--muted); text-align: center; padding: 20px 0; }
        .footnote { color: var(--muted); margin-top: 10px; font-size: 12px; }

        @media (max-width: 768px) {
            th:nth-child(5), td:nth-child(5),
            th:nth-child(6), td:nth-child(6) { display: none; }
        }
    </style>
</head>
<body>
<div class="wrap">
    <h1>APCu Stats</h1>
    <div class="subtitle">
        Live view of APCu cache usage and entries.
        <?php if ($startTime > 0): ?>
            Uptime: <?= esc(formatDuration($uptime)) ?>.
        <?php endif; ?>
    </div>

    <?php foreach ($messages as $message): ?>
        <div class="alert <?= esc($message['type']) ?>"><?= esc($message['text']) ?></div>
    <?php endforeach; ?>

    <?php if (!$apcuAvailable): ?>
        <div class="panel">
            <strong>APCu is not available.</strong>
            <div class="footnote">
                Check extension load state and ini settings (`apc.enabled=1`, and for CLI tests optionally `apc.enable_cli=1`).
            </div>
        </div>
    <?php else: ?>
        <div class="panel">
            <div class="grid">
                <div class="metric">
                    <div class="label">Entries</div>
                    <div class="value"><?= esc(number_format($numEntries)) ?></div>
                </div>
                <div class="metric">
                    <div class="label">Requests</div>
                    <div class="value"><?= esc(number_format($requests)) ?></div>
                </div>
                <div class="metric">
                    <div class="label">Hits</div>
                    <div class="value"><?= esc(number_format($hits)) ?></div>
                </div>
                <div class="metric">
                    <div class="label">Misses</div>
                    <div class="value"><?= esc(number_format($misses)) ?></div>
                </div>
                <div class="metric">
                    <div class="label">Hit Rate</div>
                    <div class="value"><?= esc(number_format($hitRate, 2)) ?>%</div>
                </div>
                <div class="metric">
                    <div class="label">Req/s</div>
                    <div class="value"><?= esc(number_format($requestRate, 2)) ?></div>
                </div>
                <div class="metric">
                    <div class="label">Hits/s</div>
                    <div class="value"><?= esc(number_format($hitRatePerSecond, 2)) ?></div>
                </div>
                <div class="metric">
                    <div class="label">Misses/s</div>
                    <div class="value"><?= esc(number_format($missRatePerSecond, 2)) ?></div>
                </div>
                <div class="metric">
                    <div class="label">Inserts/s</div>
                    <div class="value"><?= esc(number_format($insertRatePerSecond, 2)) ?></div>
                </div>
                <div class="metric">
                    <div class="label">Used Memory</div>
                    <div class="value"><?= esc(formatBytes($usedMemory)) ?></div>
                    <div class="progress"><span style="width: <?= esc(number_format(max(0, min(100, $usagePercent)), 2)) ?>%"></span></div>
                </div>
                <div class="metric">
                    <div class="label">Available Memory</div>
                    <div class="value"><?= esc(formatBytes($availableMemory)) ?></div>
                </div>
                <div class="metric">
                    <div class="label">Total Memory</div>
                    <div class="value"><?= esc(formatBytes($totalMemory)) ?></div>
                </div>
                <div class="metric">
                    <div class="label">Fragmentation</div>
                    <div class="value"><?= esc(number_format($fragmentation, 2)) ?>%</div>
                </div>
                <div class="metric">
                    <div class="label">Inserts / Expunges</div>
                    <div class="value"><?= esc(number_format($inserts)) ?> / <?= esc(number_format($expunges)) ?></div>
                </div>
                <div class="metric">
                    <div class="label">PHP / APCu</div>
                    <div class="value"><?= esc($phpVersion) ?> / <?= esc($apcuVersion) ?></div>
                </div>
            </div>
        </div>

        <div class="panel">
            <form method="get" class="controls">
                <div>
                    <label for="q">Search key</label>
                    <input id="q" type="text" name="q" value="<?= esc($search) ?>" placeholder="prefix:user:123">
                </div>
                <div>
                    <label for="sort">Sort</label>
                    <select id="sort" name="sort">
                        <?php
                        $sortLabels = [
                            'hits' => 'Hits',
                            'size' => 'Size',
                            'ttl' => 'TTL left',
                            'created' => 'Created',
                            'access' => 'Last access',
                            'key' => 'Key',
                        ];
                        foreach ($sortLabels as $value => $label):
                        ?>
                            <option value="<?= esc($value) ?>" <?= $sort === $value ? 'selected' : '' ?>><?= esc($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="dir">Direction</label>
                    <select id="dir" name="dir">
                        <option value="desc" <?= $dir === 'desc' ? 'selected' : '' ?>>Desc</option>
                        <option value="asc" <?= $dir === 'asc' ? 'selected' : '' ?>>Asc</option>
                    </select>
                </div>
                <div>
                    <label for="limit">Limit</label>
                    <input id="limit" type="number" name="limit" min="10" max="1000" value="<?= esc($limit) ?>">
                </div>
                <div>
                    <button type="submit">Apply</button>
                </div>
            </form>

            <div class="toolbar">
                <form method="post" onsubmit="return confirm('Clear entire APCu cache?');">
                    <input type="hidden" name="csrf_token" value="<?= esc($csrf) ?>">
                    <input type="hidden" name="action" value="clear">
                    <button type="submit" class="danger">Clear cache</button>
                </form>
                <button type="button" class="secondary" onclick="location.reload()">Refresh</button>
                <span class="footnote">Showing <?= esc(count($entries)) ?> of <?= esc($entryTotal) ?> matching entries.</span>
            </div>
        </div>

        <div class="panel">
            <table>
                <thead>
                <tr>
                    <th>Key</th>
                    <th>Size</th>
                    <th>Hits</th>
                    <th>TTL left</th>
                    <th>Created</th>
                    <th>Last access</th>
                    <th>Action</th>
                </tr>
                </thead>
                <tbody>
                <?php if ($entries === []): ?>
                    <tr><td class="empty" colspan="7">No entries found.</td></tr>
                <?php else: ?>
                    <?php foreach ($entries as $entry): ?>
                        <tr>
                            <td class="key" title="<?= esc($entry['key']) ?>"><?= esc($entry['key']) ?></td>
                            <td><?= esc(formatBytes($entry['size'])) ?></td>
                            <td><?= esc(number_format($entry['hits'])) ?></td>
                            <td>
                                <?php
                                if ($entry['ttl_left'] === null) {
                                    echo 'never';
                                } elseif ($entry['ttl_left'] === 0) {
                                    echo '<span style="color:' . esc('#d97706') . '">expired</span>';
                                } else {
                                    echo esc(formatDuration((int) $entry['ttl_left']));
                                }
                                ?>
                            </td>
                            <td><?= $entry['creation'] > 0 ? esc(date('Y-m-d H:i:s', (int) $entry['creation'])) : '-' ?></td>
                            <td><?= $entry['access'] > 0 ? esc(date('Y-m-d H:i:s', (int) $entry['access'])) : '-' ?></td>
                            <td>
                                <form method="post" onsubmit="return confirm('Delete this key?');">
                                    <input type="hidden" name="csrf_token" value="<?= esc($csrf) ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="key" value="<?= esc($entry['key']) ?>">
                                    <button type="submit">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
            <div class="footnote">
                Optional basic auth: set `APCU_STATS_USER` and `APCU_STATS_PASS` in your web server environment.
            </div>
        </div>
    <?php endif; ?>
</div>
</body>
</html>
