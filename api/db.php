<?php
declare(strict_types=1);

function db_config(): array
{
    return [
        'host' => getenv('BAWASLU_DB_HOST') ?: '127.0.0.1',
        'port' => (int) (getenv('BAWASLU_DB_PORT') ?: 3306),
        'name' => getenv('BAWASLU_DB_NAME') ?: 'bawaslu_sleman',
        'user' => getenv('BAWASLU_DB_USER') ?: 'root',
        'pass' => getenv('BAWASLU_DB_PASS') ?: '',
        'charset' => 'utf8mb4',
    ];
}

function db(): ?PDO
{
    static $pdo = null;
    static $resolved = false;

    if ($resolved) {
        return $pdo;
    }

    $resolved = true;
    $config = db_config();
    $dsnBase = sprintf('mysql:host=%s;port=%d;charset=%s', $config['host'], $config['port'], $config['charset']);

    try {
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 3,
        ];

        $candidate = new PDO($dsnBase . ';dbname=' . $config['name'], $config['user'], $config['pass'], $options);
        $pdo = $candidate;
    } catch (Throwable $error) {
        $code = (int) $error->getCode();
        if ($code === 1049) {
            try {
                $server = new PDO($dsnBase, $config['user'], $config['pass'], [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_TIMEOUT => 3,
                ]);
                $server->exec(sprintf(
                    'CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
                    str_replace('`', '', $config['name'])
                ));
                $pdo = new PDO($dsnBase . ';dbname=' . $config['name'], $config['user'], $config['pass'], $options);
            } catch (Throwable $inner) {
                db_set_error($inner->getMessage());
                $pdo = null;
            }
        } else {
            db_set_error($error->getMessage());
            $pdo = null;
        }
    }

    if ($pdo instanceof PDO) {
        db_ensure_schema($pdo);
    }

    return $pdo;
}

function db_set_error(string $message): void
{
    $GLOBALS['__bawaslu_db_error'] = $message;
}

function db_last_error(): string
{
    return (string) ($GLOBALS['__bawaslu_db_error'] ?? '');
}

function db_available(): bool
{
    return db() instanceof PDO;
}

function db_schema_statements(): array
{
    return [
        'sources' => <<<SQL
            CREATE TABLE IF NOT EXISTS sources (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                name VARCHAR(191) NOT NULL,
                first_seen_at DATETIME NOT NULL,
                last_seen_at DATETIME NOT NULL,
                article_count INT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_sources_name (name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL,
        'articles' => <<<SQL
            CREATE TABLE IF NOT EXISTS articles (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                article_key CHAR(40) NOT NULL,
                title VARCHAR(512) NOT NULL,
                url VARCHAR(1024) NOT NULL DEFAULT '',
                source_id INT UNSIGNED NULL,
                district VARCHAR(120) NOT NULL DEFAULT '',
                sentiment_label VARCHAR(16) NOT NULL DEFAULT 'Netral',
                sentiment_confidence DECIMAL(5,2) NOT NULL DEFAULT 0,
                sentiment_reason VARCHAR(255) NOT NULL DEFAULT '',
                evidence VARCHAR(40) NOT NULL DEFAULT 'Artikel',
                published_at DATETIME NULL,
                published_time VARCHAR(5) NOT NULL DEFAULT '',
                first_seen_at DATETIME NOT NULL,
                last_seen_at DATETIME NOT NULL,
                seen_count INT UNSIGNED NOT NULL DEFAULT 1,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_articles_key (article_key),
                KEY idx_articles_published (published_at),
                KEY idx_articles_signal (sentiment_label),
                KEY idx_articles_district (district),
                KEY idx_articles_source (source_id),
                CONSTRAINT fk_articles_source FOREIGN KEY (source_id) REFERENCES sources (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL,
        'topics' => <<<SQL
            CREATE TABLE IF NOT EXISTS topics (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                name VARCHAR(120) NOT NULL,
                slug VARCHAR(120) NOT NULL,
                description VARCHAR(255) NOT NULL DEFAULT '',
                PRIMARY KEY (id),
                UNIQUE KEY uniq_topics_name (name),
                UNIQUE KEY uniq_topics_slug (slug)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL,
        'article_topics' => <<<SQL
            CREATE TABLE IF NOT EXISTS article_topics (
                article_id BIGINT UNSIGNED NOT NULL,
                topic_id INT UNSIGNED NOT NULL,
                matched_keyword VARCHAR(120) NOT NULL DEFAULT '',
                linked_at DATETIME NOT NULL,
                PRIMARY KEY (article_id, topic_id),
                KEY idx_article_topics_topic (topic_id),
                CONSTRAINT fk_at_article FOREIGN KEY (article_id) REFERENCES articles (id) ON DELETE CASCADE,
                CONSTRAINT fk_at_topic FOREIGN KEY (topic_id) REFERENCES topics (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL,
        'sentiment_snapshots' => <<<SQL
            CREATE TABLE IF NOT EXISTS sentiment_snapshots (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                captured_at DATETIME NOT NULL,
                total_articles INT UNSIGNED NOT NULL DEFAULT 0,
                positive_pct DECIMAL(5,2) NOT NULL DEFAULT 0,
                neutral_pct DECIMAL(5,2) NOT NULL DEFAULT 0,
                negative_pct DECIMAL(5,2) NOT NULL DEFAULT 0,
                PRIMARY KEY (id),
                KEY idx_snapshots_captured (captured_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL,
        'sync_logs' => <<<SQL
            CREATE TABLE IF NOT EXISTS sync_logs (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                started_at DATETIME NOT NULL,
                finished_at DATETIME NOT NULL,
                duration_ms INT UNSIGNED NOT NULL DEFAULT 0,
                mode VARCHAR(40) NOT NULL DEFAULT 'live',
                status VARCHAR(20) NOT NULL DEFAULT 'success',
                http_status SMALLINT UNSIGNED NOT NULL DEFAULT 200,
                fetched_count INT UNSIGNED NOT NULL DEFAULT 0,
                accepted_count INT UNSIGNED NOT NULL DEFAULT 0,
                new_count INT UNSIGNED NOT NULL DEFAULT 0,
                updated_count INT UNSIGNED NOT NULL DEFAULT 0,
                source_count INT UNSIGNED NOT NULL DEFAULT 0,
                payload_bytes INT UNSIGNED NOT NULL DEFAULT 0,
                message VARCHAR(255) NOT NULL DEFAULT '',
                PRIMARY KEY (id),
                KEY idx_sync_started (started_at),
                KEY idx_sync_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL,
        'api_request_logs' => <<<SQL
            CREATE TABLE IF NOT EXISTS api_request_logs (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                endpoint VARCHAR(120) NOT NULL,
                method VARCHAR(10) NOT NULL DEFAULT 'GET',
                http_status SMALLINT UNSIGNED NOT NULL DEFAULT 200,
                duration_ms INT UNSIGNED NOT NULL DEFAULT 0,
                payload_bytes INT UNSIGNED NOT NULL DEFAULT 0,
                client_ip VARCHAR(45) NOT NULL DEFAULT '',
                requested_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                KEY idx_req_endpoint (endpoint),
                KEY idx_req_requested (requested_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL,
    ];
}

function db_schema_catalog(): array
{
    return [
        'sources' => [
            'label' => 'Master penerbit',
            'description' => 'Daftar media/penerbit yang pernah memberitakan isu Sleman.',
            'columns' => ['id', 'name', 'first_seen_at', 'last_seen_at', 'article_count'],
        ],
        'articles' => [
            'label' => 'Artikel publik',
            'description' => 'Satu baris untuk tiap artikel RSS unik yang lolos filter relevansi.',
            'columns' => ['id', 'article_key', 'title', 'url', 'source_id', 'district', 'sentiment_label', 'sentiment_confidence', 'sentiment_reason', 'published_at', 'first_seen_at', 'last_seen_at', 'seen_count'],
        ],
        'topics' => [
            'label' => 'Master topik',
            'description' => 'Kamus topik prioritas pemantauan beserta slug-nya.',
            'columns' => ['id', 'name', 'slug', 'description'],
        ],
        'article_topics' => [
            'label' => 'Relasi artikel-topik',
            'description' => 'Tabel pivot banyak-ke-banyak antara artikel dan topik beserta kata kunci pemicunya.',
            'columns' => ['article_id', 'topic_id', 'matched_keyword', 'linked_at'],
        ],
        'sentiment_snapshots' => [
            'label' => 'Riwayat sentimen',
            'description' => 'Potret komposisi sentimen tiap kali sinkronisasi dijalankan.',
            'columns' => ['id', 'captured_at', 'total_articles', 'positive_pct', 'neutral_pct', 'negative_pct'],
        ],
        'sync_logs' => [
            'label' => 'Log sinkronisasi',
            'description' => 'Catatan setiap proses pengambilan data: durasi, jumlah, dan statusnya.',
            'columns' => ['id', 'started_at', 'finished_at', 'duration_ms', 'mode', 'status', 'fetched_count', 'accepted_count', 'new_count', 'updated_count', 'source_count'],
        ],
        'api_request_logs' => [
            'label' => 'Log permintaan API',
            'description' => 'Rekam jejak pemanggilan endpoint beserta waktu respons dan ukuran payload.',
            'columns' => ['id', 'endpoint', 'method', 'http_status', 'duration_ms', 'payload_bytes', 'client_ip', 'requested_at'],
        ],
    ];
}

function db_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    try {
        foreach (db_schema_statements() as $statement) {
            $pdo->exec($statement);
        }
        $done = true;
    } catch (Throwable $error) {
        db_set_error($error->getMessage());
    }
}

function db_seed_topics(PDO $pdo, array $topicRules): array
{
    $map = [];
    $statement = $pdo->prepare(
        'INSERT INTO topics (name, slug, description) VALUES (:name, :slug, :description)
         ON DUPLICATE KEY UPDATE description = VALUES(description)'
    );

    foreach ($topicRules as $name => $keywords) {
        $slug = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $name) ?? '', '-'));
        $statement->execute([
            ':name' => $name,
            ':slug' => $slug,
            ':description' => 'Kata kunci: ' . implode(', ', array_slice($keywords, 0, 4)),
        ]);
    }

    foreach ($pdo->query('SELECT id, name FROM topics') as $row) {
        $map[$row['name']] = (int) $row['id'];
    }

    return $map;
}

function db_upsert_source(PDO $pdo, string $name, string $seenAt): int
{
    $statement = $pdo->prepare(
        'INSERT INTO sources (name, first_seen_at, last_seen_at, article_count) VALUES (:name, :seen, :seen2, 0)
         ON DUPLICATE KEY UPDATE last_seen_at = VALUES(last_seen_at)'
    );
    $statement->execute([':name' => $name, ':seen' => $seenAt, ':seen2' => $seenAt]);

    $lookup = $pdo->prepare('SELECT id FROM sources WHERE name = :name LIMIT 1');
    $lookup->execute([':name' => $name]);
    $id = $lookup->fetchColumn();

    return $id !== false ? (int) $id : 0;
}

function db_persist_articles(PDO $pdo, array $feedback, array $topicRules): array
{
    $now = date('Y-m-d H:i:s');
    $topicIds = db_seed_topics($pdo, $topicRules);
    $sourceIds = [];
    $stats = ['new' => 0, 'updated' => 0, 'topic_links' => 0, 'sources' => 0];

    $insertArticle = $pdo->prepare(
        'INSERT INTO articles
            (article_key, title, url, source_id, district, sentiment_label, sentiment_confidence, sentiment_reason, evidence, published_at, published_time, first_seen_at, last_seen_at, seen_count)
         VALUES
            (:article_key, :title, :url, :source_id, :district, :sentiment_label, :confidence, :reason, :evidence, :published_at, :published_time, :now, :now2, 1)
         ON DUPLICATE KEY UPDATE
            title = VALUES(title),
            url = VALUES(url),
            source_id = VALUES(source_id),
            district = VALUES(district),
            sentiment_label = VALUES(sentiment_label),
            sentiment_confidence = VALUES(sentiment_confidence),
            sentiment_reason = VALUES(sentiment_reason),
            published_at = VALUES(published_at),
            published_time = VALUES(published_time),
            last_seen_at = VALUES(last_seen_at),
            seen_count = seen_count + 1'
    );

    $lookupArticle = $pdo->prepare('SELECT id FROM articles WHERE article_key = :key LIMIT 1');
    $clearTopics = $pdo->prepare('DELETE FROM article_topics WHERE article_id = :id');
    $linkTopic = $pdo->prepare(
        'INSERT INTO article_topics (article_id, topic_id, matched_keyword, linked_at) VALUES (:article, :topic, :keyword, :now)
         ON DUPLICATE KEY UPDATE matched_keyword = VALUES(matched_keyword)'
    );

    foreach ($feedback as $item) {
        if (!is_array($item)) {
            continue;
        }

        $title = (string) ($item['text'] ?? '');
        $url = (string) ($item['url'] ?? '');
        $sourceName = trim((string) ($item['sourceName'] ?? 'Sumber publik'));
        if ($sourceName === '') {
            $sourceName = 'Sumber publik';
        }

        if (!isset($sourceIds[$sourceName])) {
            $sourceIds[$sourceName] = db_upsert_source($pdo, $sourceName, $now);
            $stats['sources']++;
        }

        $publishedAt = trim((string) ($item['publishedAt'] ?? ''));
        $publishedTimestamp = $publishedAt !== '' ? strtotime($publishedAt) : false;
        $articleKey = sha1($title . '|' . $url);

        $insertArticle->execute([
            ':article_key' => $articleKey,
            ':title' => mb_substr($title, 0, 500),
            ':url' => mb_substr($url, 0, 1000),
            ':source_id' => $sourceIds[$sourceName] ?: null,
            ':district' => mb_substr((string) ($item['district'] ?? ''), 0, 118),
            ':sentiment_label' => mb_substr((string) ($item['signal'] ?? 'Netral'), 0, 15),
            ':confidence' => (float) ($item['sentimentConfidence'] ?? 0),
            ':reason' => mb_substr((string) ($item['sentimentReason'] ?? ''), 0, 250),
            ':evidence' => mb_substr((string) ($item['evidence'] ?? 'Artikel'), 0, 38),
            ':published_at' => $publishedTimestamp !== false ? date('Y-m-d H:i:s', $publishedTimestamp) : null,
            ':published_time' => mb_substr((string) ($item['time'] ?? ''), 0, 5),
            ':now' => $now,
            ':now2' => $now,
        ]);

        if ($insertArticle->rowCount() === 1) {
            $stats['new']++;
        } else {
            $stats['updated']++;
        }

        $lookupArticle->execute([':key' => $articleKey]);
        $articleId = (int) $lookupArticle->fetchColumn();
        if ($articleId <= 0) {
            continue;
        }

        $clearTopics->execute([':id' => $articleId]);

        $lower = mb_strtolower($title, 'UTF-8');
        foreach ($topicRules as $topicName => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($lower, $keyword)) {
                    $topicId = $topicIds[$topicName] ?? 0;
                    if ($topicId > 0) {
                        $linkTopic->execute([
                            ':article' => $articleId,
                            ':topic' => $topicId,
                            ':keyword' => mb_substr($keyword, 0, 118),
                            ':now' => $now,
                        ]);
                        $stats['topic_links']++;
                    }
                    break;
                }
            }
        }
    }

    $pdo->exec(
        'UPDATE sources s
         SET article_count = (SELECT COUNT(*) FROM articles a WHERE a.source_id = s.id)'
    );

    return $stats;
}

function db_insert_sentiment_snapshot(PDO $pdo, array $sentiment, int $total): void
{
    $statement = $pdo->prepare(
        'INSERT INTO sentiment_snapshots (captured_at, total_articles, positive_pct, neutral_pct, negative_pct)
         VALUES (:now, :total, :positive, :neutral, :negative)'
    );
    $statement->execute([
        ':now' => date('Y-m-d H:i:s'),
        ':total' => $total,
        ':positive' => (float) ($sentiment['Positif'] ?? 0),
        ':neutral' => (float) ($sentiment['Netral'] ?? 0),
        ':negative' => (float) ($sentiment['Negatif'] ?? 0),
    ]);
}

function db_insert_sync_log(PDO $pdo, array $log): void
{
    $statement = $pdo->prepare(
        'INSERT INTO sync_logs
            (started_at, finished_at, duration_ms, mode, status, http_status, fetched_count, accepted_count, new_count, updated_count, source_count, payload_bytes, message)
         VALUES
            (:started_at, :finished_at, :duration_ms, :mode, :status, :http_status, :fetched, :accepted, :new_count, :updated_count, :source_count, :payload_bytes, :message)'
    );
    $statement->execute([
        ':started_at' => $log['started_at'] ?? date('Y-m-d H:i:s'),
        ':finished_at' => $log['finished_at'] ?? date('Y-m-d H:i:s'),
        ':duration_ms' => (int) ($log['duration_ms'] ?? 0),
        ':mode' => mb_substr((string) ($log['mode'] ?? 'live'), 0, 38),
        ':status' => mb_substr((string) ($log['status'] ?? 'success'), 0, 18),
        ':http_status' => (int) ($log['http_status'] ?? 200),
        ':fetched' => (int) ($log['fetched_count'] ?? 0),
        ':accepted' => (int) ($log['accepted_count'] ?? 0),
        ':new_count' => (int) ($log['new_count'] ?? 0),
        ':updated_count' => (int) ($log['updated_count'] ?? 0),
        ':source_count' => (int) ($log['source_count'] ?? 0),
        ':payload_bytes' => (int) ($log['payload_bytes'] ?? 0),
        ':message' => mb_substr((string) ($log['message'] ?? ''), 0, 250),
    ]);
}

function db_log_request(string $endpoint, int $status, int $durationMs, int $bytes): void
{
    $pdo = db();
    if (!$pdo instanceof PDO) {
        return;
    }

    try {
        $statement = $pdo->prepare(
            'INSERT INTO api_request_logs (endpoint, method, http_status, duration_ms, payload_bytes, client_ip, requested_at)
             VALUES (:endpoint, :method, :status, :duration, :bytes, :ip, :now)'
        );
        $statement->execute([
            ':endpoint' => mb_substr($endpoint, 0, 118),
            ':method' => mb_substr((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'), 0, 8),
            ':status' => $status,
            ':duration' => $durationMs,
            ':bytes' => $bytes,
            ':ip' => mb_substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 44),
            ':now' => date('Y-m-d H:i:s'),
        ]);
    } catch (Throwable $error) {
        db_set_error($error->getMessage());
    }
}

function db_table_counts(PDO $pdo): array
{
    $counts = [];
    foreach (array_keys(db_schema_statements()) as $table) {
        $counts[$table] = (int) $pdo->query(sprintf('SELECT COUNT(*) FROM `%s`', $table))->fetchColumn();
    }

    return $counts;
}

function describe_database(bool $stored = true, array $stats = [], string $error = ''): array
{
    $pdo = db();
    $info = [
        'engine' => 'MySQL',
        'stored' => $stored && $pdo instanceof PDO,
        'connected' => $pdo instanceof PDO,
    ];

    if ($pdo instanceof PDO) {
        try {
            $info['engine'] = 'MySQL ' . $pdo->query('SELECT VERSION()')->fetchColumn();
            $info['database'] = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
        } catch (Throwable $inner) {
            $info['connected'] = false;
            $info['error'] = $inner->getMessage();
        }
    } elseif ($error !== '') {
        $info['error'] = $error;
    } else {
        $info['error'] = db_last_error();
    }

    if ($stats !== []) {
        $info['stats'] = $stats;
    }

    return $info;
}
