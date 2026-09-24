<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Jakarta');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

require_once __DIR__ . '/db.php';

$requestStartedAt = microtime(true);

$cacheFile = __DIR__ . '/cache/sleman-articles-v1.json';
$lastGoodCache = __DIR__ . '/cache/sleman-articles-last-good.json';
$archiveFile = __DIR__ . '/cache/sleman-articles-archive.json';
$cacheTtl = 180;

if (is_file($cacheFile) && time() - filemtime($cacheFile) < $cacheTtl) {
    $cachedJson = file_get_contents($cacheFile);
    $cachedPayload = is_string($cachedJson) ? json_decode($cachedJson, true) : null;
    if (is_array($cachedPayload)) {
        $cachedPayload = sanitizeArticlePayload($cachedPayload);
        $cachedPayload['archive'] = mergeArchive($cachedPayload['feedback'], $archiveFile);
        $cachedPayload['fromCache'] = true;
        $cachedPayload['cacheAgeSeconds'] = max(0, time() - filemtime($cacheFile));
        $cachedPayload['database'] = describe_database(false);
        $cachedJsonOut = json_encode($cachedPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        db_log_request('api/scrape.php', 200, (int) round((microtime(true) - $requestStartedAt) * 1000), strlen((string) $cachedJsonOut));
        echo $cachedJsonOut;
        exit;
    }

    readfile($cacheFile);
    exit;
}

$queries = [
    'Bawaslu Sleman',
    'pemilu Sleman',
    'pilkada Sleman',
    'politik uang Sleman',
    'pelanggaran kampanye Sleman',
    'daftar pemilih Sleman',
    'TPS rawan Sleman',
    'KPU Sleman Bawaslu',
];

$districts = getSlemanDistricts();
$relevanceKeywords = getArticleRelevanceKeywords();

$positiveWords = ['apresiasi', 'baik', 'cepat', 'transparan', 'klarifikasi', 'edukasi', 'sosialisasi', 'tertib', 'sinergi', 'perkuat'];
$negativeWords = ['dugaan', 'pelanggaran', 'politik uang', 'money politic', 'netralitas', 'masalah', 'aduan', 'protes', 'hoaks', 'intimidasi', 'kecurangan', 'rawan', 'rusak', 'konflik', 'lapor'];
$topicRules = getTopicRules();

$items = [];

foreach ($queries as $query) {
    $url = 'https://news.google.com/rss/search?q=' . rawurlencode($query . ' Kabupaten Sleman') . '&hl=id&gl=ID&ceid=ID:id';
    $rss = fetchUrl($url);

    if ($rss === null) {
        continue;
    }

    $xml = @simplexml_load_string($rss);
    if (!$xml || !isset($xml->channel->item)) {
        continue;
    }

    foreach ($xml->channel->item as $item) {
        $title = cleanText((string) $item->title);
        $description = cleanText(strip_tags((string) $item->description));
        $link = (string) $item->link;
        $published = strtotime((string) $item->pubDate) ?: time();
        $sourceName = isset($item->source) ? cleanText((string) $item->source) : 'Sumber publik';
        $combined = $title . ' ' . $description;

        if (!isRelevantArticle($combined, $districts, $relevanceKeywords)) {
            continue;
        }

        $id = sha1($title . $link);
        $sentiment = analyzeSentiment($combined, $positiveWords, $negativeWords);
        $items[$id] = [
            'time' => date('H:i', $published),
            'source' => 'Artikel Publik',
            'sourceName' => $sourceName,
            'text' => $title,
            'signal' => $sentiment['label'],
            'sentimentConfidence' => $sentiment['confidence'],
            'sentimentReason' => $sentiment['reason'],
            'evidence' => 'Artikel',
            'url' => $link,
            'publishedAt' => date(DATE_ATOM, $published),
            'district' => detectDistrict($combined, $districts),
        ];
    }
}

$feedback = array_values($items);
usort($feedback, static fn(array $a, array $b): int => strcmp($b['publishedAt'], $a['publishedAt']));
$feedback = array_slice($feedback, 0, 40);

if (count($feedback) === 0) {
    foreach ([$lastGoodCache, __DIR__ . '/cache/sleman-feedback.json'] as $fallbackFile) {
        if (!is_file($fallbackFile)) {
            continue;
        }

        $fallbackJson = file_get_contents($fallbackFile);
        $fallbackPayload = is_string($fallbackJson) ? json_decode($fallbackJson, true) : null;
        if (is_array($fallbackPayload) && !empty($fallbackPayload['feedback'])) {
            $fallbackPayload = sanitizeArticlePayload($fallbackPayload);
            $fallbackPayload['archive'] = loadArchive($archiveFile);
            $fallbackPayload['mode'] = 'last-good-article-cache';
            $fallbackPayload['scrapedAt'] = date(DATE_ATOM);
            $fallbackPayload['fromCache'] = true;
            $fallbackPayload['cacheAgeSeconds'] = max(0, time() - filemtime($fallbackFile));
            $fallbackPayload['database'] = describe_database(false);
            $fallbackJsonOut = json_encode($fallbackPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            db_log_request('api/scrape.php', 200, (int) round((microtime(true) - $requestStartedAt) * 1000), strlen((string) $fallbackJsonOut));
            echo $fallbackJsonOut;
            exit;
        }
    }
}

$archive = mergeArchive($feedback, $archiveFile);
$payload = buildPayload($feedback, $topicRules);
$payload['archive'] = $archive;

$responseStartedAt = $requestStartedAt ?? microtime(true);
$dbStats = [];
$dbError = '';

$database = db();
if ($database instanceof PDO) {
    try {
        $dbStats = db_persist_articles($database, $feedback, $topicRules);
        db_insert_sentiment_snapshot($database, $payload['sentiment'], (int) $payload['totalMentions']);
        $payload['database'] = describe_database(true, [
            'new_articles' => $dbStats['new'] ?? 0,
            'updated_articles' => $dbStats['updated'] ?? 0,
            'topic_links' => $dbStats['topic_links'] ?? 0,
            'sources_touched' => $dbStats['sources'] ?? 0,
        ]);
    } catch (Throwable $error) {
        $dbError = $error->getMessage();
        $payload['database'] = describe_database(false, [], $dbError);
    }
} else {
    $dbError = db_last_error();
    $payload['database'] = describe_database(false, [], $dbError);
}

$json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

if ($json === false) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Gagal membentuk JSON']);
    exit;
}

$durationMs = (int) round((microtime(true) - $responseStartedAt) * 1000);

if ($database instanceof PDO) {
    try {
        db_insert_sync_log($database, [
            'started_at' => date('Y-m-d H:i:s', (int) ($requestStartedAt ?? time())),
            'finished_at' => date('Y-m-d H:i:s'),
            'duration_ms' => $durationMs,
            'mode' => (string) ($payload['mode'] ?? 'live'),
            'status' => $dbError === '' ? 'success' : 'partial',
            'http_status' => 200,
            'fetched_count' => (int) ($payload['totalMentions'] ?? 0),
            'accepted_count' => count($feedback),
            'new_count' => (int) ($dbStats['new'] ?? 0),
            'updated_count' => (int) ($dbStats['updated'] ?? 0),
            'source_count' => (int) ($dbStats['sources'] ?? 0),
            'payload_bytes' => strlen($json),
            'message' => $dbError === '' ? 'Sinkronisasi tersimpan ke MySQL' : mb_substr($dbError, 0, 250),
        ]);
        db_log_request('api/scrape.php', 200, $durationMs, strlen($json));
    } catch (Throwable $error) {
        db_set_error($error->getMessage());
    }
}

$cacheDir = dirname($cacheFile);
if (!is_dir($cacheDir)) {
    @mkdir($cacheDir, 0775, true);
}

@file_put_contents($cacheFile, $json);
if (count($feedback) > 0) {
    @file_put_contents($lastGoodCache, $json);
}

echo $json;

function fetchUrl(string $url): ?string
{
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 10,
            'header' => "User-Agent: BawasluSlemanArticleDashboard/1.0\r\nAccept: application/rss+xml, application/xml, text/xml\r\n",
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);

    $content = @file_get_contents($url, false, $context);
    return is_string($content) && $content !== '' ? $content : null;
}

function cleanText(string $text): string
{
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/\s+/', ' ', $text) ?? $text;
    return trim($text);
}

function getSlemanDistricts(): array
{
    return [
        'Sleman', 'Gamping', 'Godean', 'Moyudan', 'Minggir', 'Seyegan', 'Mlati',
        'Depok', 'Berbah', 'Prambanan', 'Kalasan', 'Ngemplak', 'Ngaglik',
        'Pakem', 'Turi', 'Tempel', 'Cangkringan',
    ];
}

function getArticleRelevanceKeywords(): array
{
    return [
        'bawaslu', 'panwas', 'panwascam', 'pengawas pemilu', 'pengawasan pemilu',
        'pemilu', 'pilkada', 'pilbup', 'pilpres', 'pileg', 'pemilihan', 'coblosan',
        'kpu', 'dkpp', 'baleg', 'dprd', 'parpol', 'partai politik', 'partai',
        'paslon', 'calon bupati', 'bupati', 'wakil bupati', 'caleg', 'kandidat',
        'kampanye', 'alat peraga', 'apk', 'visi misi', 'politik', 'politik uang',
        'money politic', 'serangan fajar', 'suara sah', 'perolehan suara',
        'surat suara', 'daftar pemilih', 'dpt', 'pemilih', 'tps', 'kpps',
        'netralitas', 'pelanggaran pemilu', 'pelanggaran pilkada', 'sengketa pemilu',
        'hibah pemilu', 'dana hibah pemilu',
    ];
}

function getTopicRules(): array
{
    return [
        'Dugaan politik uang' => ['politik uang', 'money politic', 'sembako', 'serangan fajar'],
        'Data dan hak pilih' => ['daftar pemilih', 'dpt', 'pemilih', 'coklit', 'hak suara'],
        'Pelanggaran kampanye' => ['kampanye', 'apk', 'alat peraga', 'sosialisasi'],
        'TPS dan logistik' => ['tps', 'logistik', 'surat suara', 'coblosan', 'pemungutan'],
        'Netralitas penyelenggara' => ['netralitas', 'asn', 'penyelenggara', 'aparatur', 'panwas'],
    ];
}

function isSlemanRelated(string $text, array $districts): bool
{
    $lower = mb_strtolower($text, 'UTF-8');
    foreach ($districts as $district) {
        if (str_contains($lower, mb_strtolower($district, 'UTF-8'))) {
            return true;
        }
    }
    return false;
}

function hasPoliticalContext(string $text, array $keywords): bool
{
    $lower = mb_strtolower($text, 'UTF-8');
    foreach ($keywords as $keyword) {
        if (str_contains($lower, mb_strtolower($keyword, 'UTF-8'))) {
            return true;
        }
    }
    return false;
}

function isRelevantArticle(string $text, array $districts, array $keywords): bool
{
    return isSlemanRelated($text, $districts) && hasPoliticalContext($text, $keywords);
}

function detectDistrict(string $text, array $districts): string
{
    $lower = mb_strtolower($text, 'UTF-8');
    foreach ($districts as $district) {
        if (str_contains($lower, mb_strtolower($district, 'UTF-8'))) {
            return $district;
        }
    }
    return 'Kabupaten Sleman';
}

function analyzeSentiment(string $text, array $positiveWords, array $negativeWords): array
{
    $lower = mb_strtolower($text, 'UTF-8');
    $positiveMatches = [];
    $negativeMatches = [];

    foreach ($positiveWords as $word) {
        if (str_contains($lower, $word)) $positiveMatches[] = $word;
    }
    foreach ($negativeWords as $word) {
        if (str_contains($lower, $word)) $negativeMatches[] = $word;
    }

    $positive = count($positiveMatches);
    $negative = count($negativeMatches);
    $total = max(1, $positive + $negative);

    if ($negative > $positive) {
        return [
            'label' => 'Negatif',
            'confidence' => min(95, 55 + (int) round((($negative - $positive) / $total) * 40)),
            'reason' => 'Kata risiko: ' . implode(', ', array_slice($negativeMatches, 0, 3)),
        ];
    }

    if ($positive > $negative) {
        return [
            'label' => 'Positif',
            'confidence' => min(95, 55 + (int) round((($positive - $negative) / $total) * 40)),
            'reason' => 'Kata positif: ' . implode(', ', array_slice($positiveMatches, 0, 3)),
        ];
    }

    return [
        'label' => 'Netral',
        'confidence' => 50,
        'reason' => 'Tidak ada dominasi kata positif atau risiko',
    ];
}

function sanitizeArticlePayload(array $payload): array
{
    $feedback = isset($payload['feedback']) && is_array($payload['feedback']) ? $payload['feedback'] : [];
    $districts = getSlemanDistricts();
    $relevanceKeywords = getArticleRelevanceKeywords();
    $feedback = array_map(static function (array $item): array {
        $item['source'] = 'Artikel Publik';
        $item['sourceName'] = $item['sourceName'] ?? 'Sumber publik';
        $item['evidence'] = 'Artikel';
        return $item;
    }, array_filter($feedback, static function (array $item) use ($districts, $relevanceKeywords): bool {
        $combined = implode(' ', [
            (string) ($item['text'] ?? ''),
            (string) ($item['sourceName'] ?? ''),
            (string) ($item['district'] ?? ''),
        ]);
        return isRelevantArticle($combined, $districts, $relevanceKeywords);
    }));

    $archive = isset($payload['archive']) && is_array($payload['archive']) ? $payload['archive'] : $feedback;
    $archive = array_values(array_filter($archive, static function (array $item) use ($districts, $relevanceKeywords): bool {
        $combined = implode(' ', [
            (string) ($item['text'] ?? ''),
            (string) ($item['sourceName'] ?? ''),
            (string) ($item['district'] ?? ''),
        ]);
        return isRelevantArticle($combined, $districts, $relevanceKeywords);
    }));

    $payload['feedback'] = array_values($feedback);
    $payload['archive'] = $archive;
    $payload['sources'] = buildSourceCounts($feedback);
    $payload['sentiment'] = buildSentimentPercent($feedback);
    $payload['topics'] = buildTopicShares($feedback, getTopicRules());
    $payload = array_merge($payload, buildDerivedMetrics($feedback));
    $legacyMapsKey = 'google' . 'Maps';
    unset($payload[$legacyMapsKey]);
    return $payload;
}

function loadArchive(string $archiveFile): array
{
    if (!is_file($archiveFile)) {
        return [];
    }

    $json = file_get_contents($archiveFile);
    $payload = is_string($json) ? json_decode($json, true) : null;
    if (!is_array($payload)) {
        return [];
    }

    $items = isset($payload['items']) && is_array($payload['items']) ? $payload['items'] : $payload;
    return normalizeArchiveItems($items);
}

function mergeArchive(array $feedback, string $archiveFile): array
{
    $archive = loadArchive($archiveFile);
    $itemsByKey = [];

    foreach (array_merge($archive, $feedback) as $item) {
        if (!is_array($item)) {
            continue;
        }

        $key = getArticleArchiveKey($item);
        if ($key === '') {
            continue;
        }

        $item['source'] = 'Artikel Publik';
        $item['sourceName'] = $item['sourceName'] ?? 'Sumber publik';
        $item['evidence'] = 'Artikel';
        $item['archivedAt'] = $item['archivedAt'] ?? date(DATE_ATOM);
        $itemsByKey[$key] = array_merge($itemsByKey[$key] ?? [], $item);
    }

    $items = array_values($itemsByKey);
    usort($items, static function (array $a, array $b): int {
        return strcmp((string) ($b['publishedAt'] ?? ''), (string) ($a['publishedAt'] ?? ''));
    });
    $items = array_slice($items, 0, 300);

    $archiveJson = json_encode([
        'ok' => true,
        'updatedAt' => date(DATE_ATOM),
        'total' => count($items),
        'items' => $items,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    if ($archiveJson !== false) {
        $cacheDir = dirname($archiveFile);
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0775, true);
        }
        @file_put_contents($archiveFile, $archiveJson);
    }

    return $items;
}

function normalizeArchiveItems(array $items): array
{
    $normalized = [];
    $districts = getSlemanDistricts();
    $relevanceKeywords = getArticleRelevanceKeywords();
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $item['source'] = 'Artikel Publik';
        $item['sourceName'] = $item['sourceName'] ?? 'Sumber publik';
        $item['text'] = $item['text'] ?? 'Artikel tanpa judul';
        $item['signal'] = $item['signal'] ?? 'Netral';
        $item['evidence'] = 'Artikel';
        $item['url'] = $item['url'] ?? '';
        $item['district'] = $item['district'] ?? 'Kabupaten Sleman';
        $item['publishedAt'] = $item['publishedAt'] ?? '';
        $item['time'] = $item['time'] ?? '--:--';
        $combined = implode(' ', [$item['text'], $item['sourceName'], $item['district']]);
        if (!isRelevantArticle($combined, $districts, $relevanceKeywords)) {
            continue;
        }
        $normalized[] = $item;
    }
    return $normalized;
}

function getArticleArchiveKey(array $item): string
{
    $url = trim((string) ($item['url'] ?? ''));
    if ($url !== '') {
        return sha1($url);
    }

    $text = trim((string) ($item['text'] ?? ''));
    $publishedAt = trim((string) ($item['publishedAt'] ?? ''));
    if ($text === '' && $publishedAt === '') {
        return '';
    }

    return sha1($text . '|' . $publishedAt);
}

function buildPayload(array $feedback, array $topicRules): array
{
    return array_merge([
        'ok' => true,
        'mode' => 'real-article-rss',
        'scope' => 'Kabupaten Sleman',
        'scrapedAt' => date(DATE_ATOM),
        'sources' => buildSourceCounts($feedback),
        'sentiment' => buildSentimentPercent($feedback),
        'topics' => buildTopicShares($feedback, $topicRules),
        'feedback' => $feedback,
    ], buildDerivedMetrics($feedback));
}

function buildSentimentPercent(array $feedback): array
{
    $sentiment = ['Positif' => 0, 'Netral' => 0, 'Negatif' => 0];
    foreach ($feedback as $item) {
        $label = (string) ($item['signal'] ?? 'Netral');
        $sentiment[$label] = ($sentiment[$label] ?? 0) + 1;
    }

    $total = max(count($feedback), 1);
    $percent = [];
    foreach ($sentiment as $key => $value) {
        $percent[$key] = round(($value / $total) * 100, 1);
    }

    return $percent;
}

function buildTopicShares(array $feedback, array $topicRules): array
{
    $topicCounts = array_fill_keys(array_keys($topicRules), 0);

    foreach ($feedback as $item) {
        $lower = mb_strtolower((string) ($item['text'] ?? ''), 'UTF-8');
        foreach ($topicRules as $topic => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($lower, $keyword)) {
                    $topicCounts[$topic]++;
                    break;
                }
            }
        }
    }

    $total = max(count($feedback), 1);
    $topics = [];
    foreach ($topicCounts as $name => $count) {
        $topics[] = [
            'name' => $name,
            'value' => (int) round(($count / $total) * 100),
            'count' => $count,
        ];
    }

    return $topics;
}

function buildDerivedMetrics(array $feedback): array
{
    $total = count($feedback);
    $publishers = [];
    $freshCount = 0;
    $completeCount = 0;
    $cutoff = time() - 86400;

    foreach ($feedback as $item) {
        if (!is_array($item)) {
            continue;
        }

        $sourceName = trim((string) ($item['sourceName'] ?? ''));
        if ($sourceName !== '') {
            $publishers[$sourceName] = true;
        }

        $publishedAt = trim((string) ($item['publishedAt'] ?? ''));
        $publishedTs = $publishedAt !== '' ? strtotime($publishedAt) : false;
        if ($publishedTs !== false && $publishedTs >= $cutoff) {
            $freshCount++;
        }

        if ($sourceName !== '' && trim((string) ($item['url'] ?? '')) !== '' && $publishedTs !== false) {
            $completeCount++;
        }
    }

    return [
        'totalMentions' => $total,
        'mediaCount' => count($publishers),
        'freshCount' => $freshCount,
        'sla' => $total > 0 ? (int) round(($completeCount / $total) * 100) : 0,
    ];
}

function buildSourceCounts(array $feedback): array
{
    $counts = [];
    foreach ($feedback as $item) {
        $name = trim((string) ($item['sourceName'] ?? 'Sumber publik'));
        if ($name === '') {
            $name = 'Sumber publik';
        }
        $counts[$name] = ($counts[$name] ?? 0) + 1;
    }

    arsort($counts);
    return array_slice($counts, 0, 6, true);
}
