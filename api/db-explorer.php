<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Jakarta');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

require_once __DIR__ . '/db.php';

$startedAt = microtime(true);

function explorer_query_catalog(): array
{
    return [
        [
            'name' => 'Ambil artikel terbaru + penerbit',
            'sql' => "SELECT a.id, a.title, s.name AS penerbit, a.sentiment_label, a.published_at\nFROM articles a\nLEFT JOIN sources s ON s.id = a.source_id\nORDER BY a.published_at DESC\nLIMIT 10;",
            'used_by' => 'Halaman Aduan Publik',
        ],
        [
            'name' => 'Rekap artikel per penerbit',
            'sql' => "SELECT s.name AS penerbit, COUNT(a.id) AS jumlah_artikel\nFROM sources s\nLEFT JOIN articles a ON a.source_id = s.id\nGROUP BY s.id\nORDER BY jumlah_artikel DESC;",
            'used_by' => 'Grafik Sumber Data',
        ],
        [
            'name' => 'Distribusi sentimen',
            'sql' => "SELECT sentiment_label, COUNT(*) AS jumlah,\n  ROUND(COUNT(*) * 100 / (SELECT COUNT(*) FROM articles), 1) AS persen\nFROM articles\nGROUP BY sentiment_label;",
            'used_by' => 'Metrik & donat sentimen',
        ],
        [
            'name' => 'Artikel per topik (relasi pivot)',
            'sql' => "SELECT t.name AS topik, COUNT(at.article_id) AS jumlah_artikel\nFROM topics t\nLEFT JOIN article_topics at ON at.topic_id = t.id\nGROUP BY t.id\nORDER BY jumlah_artikel DESC;",
            'used_by' => 'Peta isu prioritas',
        ],
        [
            'name' => 'Riwayat sinkronisasi terakhir',
            'sql' => "SELECT started_at, duration_ms, mode, status, new_count, updated_count\nFROM sync_logs\nORDER BY started_at DESC\nLIMIT 10;",
            'used_by' => 'Halaman Status Sistem',
        ],
    ];
}

function explorer_fetch_samples(PDO $pdo, string $table, int $limit = 8): array
{
    $allowed = array_keys(db_schema_statements());
    if (!in_array($table, $allowed, true)) {
        return [];
    }

    $sql = sprintf('SELECT * FROM `%s` ORDER BY 1 DESC LIMIT %d', $table, max(1, min($limit, 25)));
    $rows = $pdo->query($sql)->fetchAll();

    return array_map(static function (array $row): array {
        return array_map(static function ($value): string {
            if ($value === null) {
                return 'NULL';
            }
            $text = (string) $value;
            return mb_strlen($text) > 90 ? mb_substr($text, 0, 89) . '…' : $text;
        }, $row);
    }, $rows);
}

function explorer_column_meta(PDO $pdo): array
{
    $schema = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
    $statement = $pdo->prepare(
        'SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_KEY, COLUMN_DEFAULT, EXTRA
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = :schema
         ORDER BY TABLE_NAME, ORDINAL_POSITION'
    );
    $statement->execute([':schema' => $schema]);

    $map = [];
    foreach ($statement->fetchAll() as $row) {
        $map[$row['TABLE_NAME']][] = [
            'name' => (string) $row['COLUMN_NAME'],
            'type' => (string) $row['COLUMN_TYPE'],
            'null' => (string) $row['IS_NULLABLE'],
            'key' => (string) $row['COLUMN_KEY'],
            'default' => $row['COLUMN_DEFAULT'],
            'extra' => (string) $row['EXTRA'],
        ];
    }

    return $map;
}

function explorer_index_meta(PDO $pdo): array
{
    $schema = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
    $statement = $pdo->prepare(
        'SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS cols
         FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = :schema
         GROUP BY TABLE_NAME, INDEX_NAME, NON_UNIQUE
         ORDER BY TABLE_NAME, INDEX_NAME'
    );
    $statement->execute([':schema' => $schema]);

    $map = [];
    foreach ($statement->fetchAll() as $row) {
        $map[$row['TABLE_NAME']][] = [
            'name' => (string) $row['INDEX_NAME'],
            'unique' => (int) $row['NON_UNIQUE'] === 0,
            'columns' => (string) $row['cols'],
        ];
    }

    return $map;
}

function explorer_table_sizes(PDO $pdo): array
{
    $statement = $pdo->prepare(
        'SELECT TABLE_NAME, DATA_LENGTH, INDEX_LENGTH, TABLE_COLLATION
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = :schema'
    );
    $statement->execute([':schema' => (string) $pdo->query('SELECT DATABASE()')->fetchColumn()]);

    $sizes = [];
    foreach ($statement->fetchAll() as $row) {
        $sizes[$row['TABLE_NAME']] = [
            'data_bytes' => (int) $row['DATA_LENGTH'],
            'index_bytes' => (int) $row['INDEX_LENGTH'],
            'collation' => (string) $row['TABLE_COLLATION'],
        ];
    }

    return $sizes;
}

$response = [
    'ok' => true,
    'generatedAt' => date(DATE_ATOM),
    'available' => false,
    'tables' => [],
    'queries' => explorer_query_catalog(),
    'stats' => [],
    'recentActivity' => [],
];

$pdo = db();

if ($pdo instanceof PDO && isset($_GET['run'])) {
    $catalogQueries = explorer_query_catalog();
    $index = (int) $_GET['run'];
    $response['queryRun'] = ['requested' => $index, 'executed' => false];

    if (isset($catalogQueries[$index])) {
        $entry = $catalogQueries[$index];
        $queryStart = microtime(true);
        try {
            $statement = $pdo->query($entry['sql']);
            $rows = $statement->fetchAll();
            $response['queryRun'] = [
                'requested' => $index,
                'executed' => true,
                'name' => $entry['name'],
                'sql' => $entry['sql'],
                'used_by' => $entry['used_by'],
                'durationMs' => round((microtime(true) - $queryStart) * 1000, 2),
                'rowCount' => count($rows),
                'columns' => $rows === [] ? [] : array_keys($rows[0]),
                'rows' => array_slice($rows, 0, 25),
            ];
        } catch (Throwable $error) {
            $response['ok'] = false;
            $response['queryRun']['error'] = $error->getMessage();
        }
    } else {
        $response['ok'] = false;
        $response['queryRun']['error'] = 'Indeks query tidak dikenal.';
    }
}

if ($pdo instanceof PDO) {
    try {
        $catalog = db_schema_catalog();
        $counts = db_table_counts($pdo);
        $sizes = explorer_table_sizes($pdo);
        $columns = explorer_column_meta($pdo);
        $indexes = explorer_index_meta($pdo);
        $tableNames = array_keys(db_schema_statements());
        $selectedTable = isset($_GET['table']) && in_array($_GET['table'], $tableNames, true)
            ? (string) $_GET['table']
            : 'articles';

        $tables = [];
        foreach ($catalog as $name => $meta) {
            $size = $sizes[$name] ?? ['data_bytes' => 0, 'index_bytes' => 0, 'collation' => ''];
            $columnList = $columns[$name] ?? array_map(
                static fn (string $column): array => [
                    'name' => $column,
                    'type' => '',
                    'null' => '',
                    'key' => '',
                    'default' => null,
                    'extra' => '',
                ],
                $meta['columns']
            );

            $tables[] = [
                'name' => $name,
                'label' => $meta['label'],
                'description' => $meta['description'],
                'columns' => $columnList,
                'columnCount' => count($columnList),
                'indexes' => $indexes[$name] ?? [],
                'rows' => $counts[$name] ?? 0,
                'data_bytes' => $size['data_bytes'],
                'index_bytes' => $size['index_bytes'],
                'total_bytes' => $size['data_bytes'] + $size['index_bytes'],
                'collation' => $size['collation'],
                'samples' => explorer_fetch_samples($pdo, $name, 6),
            ];
        }

        $latestSync = $pdo->query(
            'SELECT started_at, finished_at, duration_ms, mode, status, fetched_count, accepted_count, new_count, updated_count, source_count, payload_bytes, message
             FROM sync_logs ORDER BY id DESC LIMIT 1'
        )->fetch();

        $signalRows = $pdo->query('SELECT sentiment_label, COUNT(*) AS jumlah FROM articles GROUP BY sentiment_label')->fetchAll();
        $signalMap = [];
        foreach ($signalRows as $row) {
            $signalMap[$row['sentiment_label']] = (int) $row['jumlah'];
        }

        $topicRows = $pdo->query(
            'SELECT t.name AS topik, COUNT(at.article_id) AS jumlah
             FROM topics t
             LEFT JOIN article_topics at ON at.topic_id = t.id
             GROUP BY t.id ORDER BY jumlah DESC'
        )->fetchAll();

        $requests = $pdo->query(
            'SELECT endpoint, method, http_status, duration_ms, payload_bytes, requested_at
             FROM api_request_logs ORDER BY id DESC LIMIT 10'
        )->fetchAll();

        $syncHistory = $pdo->query(
            'SELECT started_at, duration_ms, mode, status, new_count, updated_count, accepted_count, payload_bytes
             FROM sync_logs ORDER BY id DESC LIMIT 12'
        )->fetchAll();

        $response['available'] = true;
        $response['engine'] = 'MySQL ' . $pdo->query('SELECT VERSION()')->fetchColumn();
        $response['database'] = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
        $response['collation'] = (string) $pdo->query('SELECT @@collation_database')->fetchColumn();
        $response['selectedTable'] = $selectedTable;
        $response['tables'] = $tables;
        $response['stats'] = [
            'tableCount' => count($tables),
            'totalRows' => array_sum($counts),
            'totalBytes' => array_sum(array_map(static fn (array $t): int => $t['total_bytes'], $tables)),
            'sourceCount' => $counts['sources'] ?? 0,
            'articleCount' => $counts['articles'] ?? 0,
            'topicCount' => count($topicRows),
            'signalBreakdown' => $signalMap,
            'topicBreakdown' => array_map(static fn (array $row): array => [
                'name' => (string) $row['topik'],
                'count' => (int) $row['jumlah'],
            ], $topicRows),
        ];
        $response['latestSync'] = $latestSync ?: null;
        $response['recentActivity'] = $requests;
        $response['syncHistory'] = $syncHistory;
    } catch (Throwable $error) {
        $response['ok'] = false;
        $response['error'] = $error->getMessage();
    }
} else {
    $response['ok'] = false;
    $response['error'] = db_last_error() !== '' ? db_last_error() : 'MySQL tidak dapat dihubungi.';
}

$json = json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
http_response_code($response['ok'] ? 200 : 503);
echo $json;

db_log_request('api/db-explorer.php', $response['ok'] ? 200 : 503, (int) round((microtime(true) - $startedAt) * 1000), strlen((string) $json));
