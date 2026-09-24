<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Jakarta');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

require_once __DIR__ . '/db.php';

$startedAt = microtime(true);

function health_cache_dir(): string
{
    return __DIR__ . '/cache';
}

function health_cache_files(): array
{
    $dir = health_cache_dir();
    $files = [];

    foreach (glob($dir . '/*.json') ?: [] as $path) {
        $files[] = [
            'name' => basename($path),
            'bytes' => (int) filesize($path),
            'modifiedAt' => date(DATE_ATOM, (int) filemtime($path)),
            'ageSeconds' => max(0, time() - (int) filemtime($path)),
            'readable' => is_readable($path),
        ];
    }

    usort($files, static fn (array $a, array $b): int => $b['bytes'] <=> $a['bytes']);

    return $files;
}

function health_endpoint_catalog(): array
{
    return [
        [
            'endpoint' => 'api/scrape.php',
            'method' => 'GET',
            'role' => 'Mengambil RSS berita, memfilter artikel Sleman, lalu menyimpan ke MySQL dan cache JSON.',
            'returns' => 'metrik, sentimen, topik, artikel, arsip',
        ],
        [
            'endpoint' => 'api/db-explorer.php',
            'method' => 'GET',
            'role' => 'Membaca struktur dan isi tabel MySQL untuk halaman Basis Data.',
            'returns' => 'daftar tabel, jumlah baris, contoh data, katalog query',
        ],
        [
            'endpoint' => 'api/health.php',
            'method' => 'GET',
            'role' => 'Pemeriksaan kesehatan sistem: PHP, MySQL, cache, dan log sinkronisasi.',
            'returns' => 'status komponen, waktu respons, log permintaan',
        ],
    ];
}

$checks = [];
$checks[] = [
    'name' => 'PHP runtime',
    'status' => version_compare(PHP_VERSION, '8.0.0', '>=') ? 'ok' : 'warn',
    'detail' => 'PHP ' . PHP_VERSION . ' (' . PHP_SAPI . ')',
];

$checks[] = [
    'name' => 'Ekstensi PDO MySQL',
    'status' => extension_loaded('pdo_mysql') ? 'ok' : 'error',
    'detail' => extension_loaded('pdo_mysql') ? 'pdo_mysql aktif' : 'pdo_mysql tidak aktif',
];

$cacheFiles = health_cache_files();
$checks[] = [
    'name' => 'Direktori cache',
    'status' => is_writable(health_cache_dir()) ? 'ok' : 'warn',
    'detail' => count($cacheFiles) . ' berkas JSON, ' . number_format(array_sum(array_column($cacheFiles, 'bytes')) / 1024, 1) . ' KB',
];

$response = [
    'ok' => true,
    'generatedAt' => date(DATE_ATOM),
    'runtime' => [
        'phpVersion' => PHP_VERSION,
        'sapi' => PHP_SAPI,
        'osFamily' => PHP_OS_FAMILY,
        'memoryLimit' => (string) ini_get('memory_limit'),
        'maxExecutionTime' => (string) ini_get('max_execution_time'),
        'timezone' => date_default_timezone_get(),
        'serverSoftware' => (string) ($_SERVER['SERVER_SOFTWARE'] ?? 'unknown'),
        'serverName' => (string) ($_SERVER['SERVER_NAME'] ?? 'localhost'),
    ],
    'storage' => [
        'primary' => 'MySQL',
        'fallback' => 'JSON cache',
        'mysql' => [
            'connected' => false,
        ],
        'cache' => [
            'directory' => 'api/cache',
            'files' => $cacheFiles,
            'fileCount' => count($cacheFiles),
            'totalBytes' => array_sum(array_column($cacheFiles, 'bytes')),
        ],
    ],
    'endpoints' => health_endpoint_catalog(),
    'checks' => $checks,
    'requests' => [],
    'sync' => [],
    'queryTimings' => [],
];

$queryTimings = [];

$pdo = db();
$queryStart = microtime(true);
$checks[] = [
    'name' => 'Koneksi MySQL',
    'status' => $pdo instanceof PDO ? 'ok' : 'error',
    'detail' => $pdo instanceof PDO ? 'Terhubung ke server MySQL' : (db_last_error() !== '' ? db_last_error() : 'Tidak dapat terhubung'),
];
$queryTimings[] = ['label' => 'Koneksi PDO', 'ms' => round((microtime(true) - $queryStart) * 1000, 2)];

if ($pdo instanceof PDO) {
    try {
        $response['storage']['mysql'] = [
            'connected' => true,
            'engine' => 'MySQL ' . $pdo->query('SELECT VERSION()')->fetchColumn(),
            'database' => (string) $pdo->query('SELECT DATABASE()')->fetchColumn(),
            'collation' => (string) $pdo->query('SELECT @@collation_database')->fetchColumn(),
            'host' => db_config()['host'] . ':' . db_config()['port'],
        ];

        $queryStart = microtime(true);
        $counts = db_table_counts($pdo);
        $queryTimings[] = ['label' => 'Hitung baris 7 tabel', 'ms' => round((microtime(true) - $queryStart) * 1000, 2)];

        $queryStart = microtime(true);
        $sizeRow = $pdo->query(
            'SELECT COALESCE(SUM(DATA_LENGTH + INDEX_LENGTH), 0) AS bytes
             FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()'
        )->fetch();
        $queryTimings[] = ['label' => 'Ukuran basis data', 'ms' => round((microtime(true) - $queryStart) * 1000, 2)];

        $response['storage']['mysql']['tableCount'] = count($counts);
        $response['storage']['mysql']['totalRows'] = array_sum($counts);
        $response['storage']['mysql']['sizeBytes'] = (int) ($sizeRow['bytes'] ?? 0);
        $response['storage']['mysql']['tables'] = $counts;

        $queryStart = microtime(true);
        $latestSync = $pdo->query(
            'SELECT id, started_at, finished_at, duration_ms, mode, status, fetched_count, accepted_count, new_count, updated_count, source_count, payload_bytes, message
             FROM sync_logs ORDER BY id DESC LIMIT 1'
        )->fetch();
        $queryTimings[] = ['label' => 'Sinkronisasi terakhir', 'ms' => round((microtime(true) - $queryStart) * 1000, 2)];

        $queryStart = microtime(true);
        $syncToday = (int) $pdo->query('SELECT COUNT(*) FROM sync_logs WHERE DATE(started_at) = CURDATE()')->fetchColumn();
        $syncTotal = (int) $pdo->query('SELECT COUNT(*) FROM sync_logs')->fetchColumn();
        $avgDuration = (float) $pdo->query('SELECT COALESCE(AVG(duration_ms), 0) FROM sync_logs')->fetchColumn();
        $queryTimings[] = ['label' => 'Agregat log sinkronisasi', 'ms' => round((microtime(true) - $queryStart) * 1000, 2)];

        $queryStart = microtime(true);
        $requestTotal = (int) $pdo->query('SELECT COUNT(*) FROM api_request_logs')->fetchColumn();
        $requestToday = (int) $pdo->query('SELECT COUNT(*) FROM api_request_logs WHERE DATE(requested_at) = CURDATE()')->fetchColumn();
        $requestAvg = (float) $pdo->query('SELECT COALESCE(AVG(duration_ms), 0) FROM api_request_logs')->fetchColumn();
        $requestErrorCount = (int) $pdo->query('SELECT COUNT(*) FROM api_request_logs WHERE http_status >= 400')->fetchColumn();

        $perEndpoint = $pdo->query(
            'SELECT endpoint,
                    COUNT(*) AS hits,
                    ROUND(AVG(duration_ms), 1) AS avg_ms,
                    MAX(duration_ms) AS max_ms,
                    ROUND(AVG(payload_bytes), 0) AS avg_bytes
             FROM api_request_logs
             GROUP BY endpoint
             ORDER BY hits DESC'
        )->fetchAll();

        $recentRequests = $pdo->query(
            'SELECT endpoint, method, http_status, duration_ms, payload_bytes, client_ip, requested_at
             FROM api_request_logs ORDER BY id DESC LIMIT 12'
        )->fetchAll();
        $queryTimings[] = ['label' => 'Agregat log permintaan', 'ms' => round((microtime(true) - $queryStart) * 1000, 2)];

        $response['sync'] = [
            'latest' => $latestSync ?: null,
            'today' => $syncToday,
            'total' => $syncTotal,
            'avgDurationMs' => round($avgDuration, 1),
        ];
        $response['requests'] = [
            'total' => $requestTotal,
            'today' => $requestToday,
            'avgDurationMs' => round($requestAvg, 1),
            'errorCount' => $requestErrorCount,
            'perEndpoint' => $perEndpoint,
            'recent' => $recentRequests,
        ];

        $checks[] = [
            'name' => 'Tabel basis data',
            'status' => count($counts) === count(db_schema_statements()) ? 'ok' : 'warn',
            'detail' => count($counts) . ' tabel terdeteksi, ' . number_format(array_sum($counts)) . ' baris total',
        ];

        $checks[] = [
            'name' => 'Log sinkronisasi',
            'status' => $latestSync && ($latestSync['status'] ?? '') === 'success' ? 'ok' : 'warn',
            'detail' => $latestSync
                ? 'Terakhir ' . $latestSync['started_at'] . ' (' . $latestSync['status'] . ')'
                : 'Belum ada catatan sinkronisasi',
        ];
    } catch (Throwable $error) {
        $response['ok'] = false;
        $response['error'] = $error->getMessage();
        $checks[] = ['name' => 'Query basis data', 'status' => 'error', 'detail' => $error->getMessage()];
    }
} else {
    $response['ok'] = false;
    $response['storage']['mysql']['error'] = db_last_error();
    $checks[] = [
        'name' => 'Log sinkronisasi',
        'status' => 'warn',
        'detail' => 'Tidak tersedia saat MySQL terputus',
    ];
}

$queryTimings[] = ['label' => 'Total penyusunan respons', 'ms' => round((microtime(true) - $startedAt) * 1000, 2)];
$response['queryTimings'] = $queryTimings;
$response['checks'] = $checks;
$response['responseMs'] = round((microtime(true) - $startedAt) * 1000, 2);

$json = json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
http_response_code(200);
echo $json;

db_log_request('api/health.php', 200, (int) round((microtime(true) - $startedAt) * 1000), strlen((string) $json));
