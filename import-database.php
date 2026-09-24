<?php
declare(strict_types=1);

$database = 'bawaslu_sleman';
$dumpFile = __DIR__ . '/bawaslu_sleman.sql';

$host = getenv('BAWASLU_DB_HOST') ?: '127.0.0.1';
$port = (int) (getenv('BAWASLU_DB_PORT') ?: 3306);
$user = getenv('BAWASLU_DB_USER') ?: 'root';
$pass = getenv('BAWASLU_DB_PASS') ?: '';

$isCli = PHP_SAPI === 'cli';
$step = 0;
$lines = [];

function report(bool $isCli, array &$lines, string $text, string $level = 'info'): void
{
    if ($isCli) {
        $prefix = ['ok' => '[OK]  ', 'warn' => '[!!]  ', 'error' => '[XX]  '][$level] ?? '      ';
        echo $prefix . $text . PHP_EOL;
        return;
    }

    $lines[] = ['level' => $level, 'text' => $text];
}

report($isCli, $lines, 'Impor basis data ' . $database . ' dari ' . basename($dumpFile));

if (!is_readable($dumpFile)) {
    report($isCli, $lines, 'Berkas dump tidak ditemukan: ' . $dumpFile, 'error');
    if (!$isCli) {
        http_response_code(500);
    }
} elseif (!extension_loaded('pdo_mysql')) {
    report($isCli, $lines, 'Ekstensi pdo_mysql tidak aktif di PHP ini.', 'error');
    if (!$isCli) {
        http_response_code(500);
    }
} else {
    try {
        $dsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $host, $port);
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        report($isCli, $lines, 'Terhubung ke MySQL ' . $pdo->getAttribute(PDO::ATTR_SERVER_VERSION) . ' di ' . $host . ':' . $port);

        $existing = $pdo->query('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ' . $pdo->quote($database))->fetchColumn();
        $wasPresent = (int) $existing > 0;

        $sql = (string) file_get_contents($dumpFile);
        $pdo->exec($sql);
        $step = 1;

        report($isCli, $lines, $wasPresent
            ? 'Database lama ditimpa dengan isi berkas dump.'
            : 'Database baru dibuat dari berkas dump.');

        $tables = ['sources', 'articles', 'topics', 'article_topics', 'sentiment_snapshots', 'sync_logs', 'api_request_logs'];
        $total = 0;
        foreach ($tables as $table) {
            $count = (int) $pdo->query(sprintf('SELECT COUNT(*) FROM `%s`.`%s`', $database, $table))->fetchColumn();
            $total += $count;
            report($isCli, $lines, sprintf('Tabel %-20s %6d baris', $table, $count));
        }
        report($isCli, $lines, sprintf('Total %d baris pada %d tabel.', $total, count($tables)), 'ok');
        report($isCli, $lines, 'Selesai. Buka halaman #/basis-data untuk memeriksa hasilnya.', 'ok');
        $step = 2;
    } catch (PDOException $error) {
        report($isCli, $lines, 'Gagal: ' . $error->getMessage(), 'error');
        if (!$isCli) {
            http_response_code(500);
        }
    }
}

if ($isCli) {
    exit($step === 2 ? 0 : 1);
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Impor Basis Data — Bawaslu Sleman</title>
<style>
  body { margin: 0; padding: 40px 20px; font-family: system-ui, "Segoe UI", sans-serif; background: #f4f6f4; color: #16211d; }
  .wrap { max-width: 720px; margin: 0 auto; background: #fff; border: 1px solid #e4ded0; border-radius: 14px; overflow: hidden; box-shadow: 0 10px 30px rgba(22,33,29,.08); }
  header { padding: 22px 26px; background: linear-gradient(135deg, #16211d, #223029); color: #eef4f1; }
  header h1 { margin: 0; font-size: 19px; }
  header p { margin: 6px 0 0; font-size: 13px; opacity: .78; }
  ol { list-style: none; margin: 0; padding: 18px 26px; }
  li { display: flex; gap: 12px; padding: 7px 0; font-size: 14px; border-bottom: 1px dashed #eef1ee; }
  li:last-child { border-bottom: 0; }
  .badge { flex: 0 0 auto; font-size: 11px; font-weight: 700; letter-spacing: .04em; padding: 3px 8px; border-radius: 999px; height: 20px; }
  .ok .badge { background: #e3f2ea; color: #1c6b45; }
  .info .badge { background: #eef1f6; color: #4a5a63; }
  .warn .badge { background: #fdf3dd; color: #8a6416; }
  .error .badge { background: #fbe6e6; color: #9d1c27; }
  .mono { font-family: ui-monospace, Consolas, monospace; font-size: 12.5px; }
  footer { padding: 16px 26px 24px; font-size: 13px; color: #5a6b64; }
  footer a { color: #9d1c27; font-weight: 600; }
</style>
</head>
<body>
<div class="wrap">
  <header>
    <h1>Impor Basis Data</h1>
    <p>Dashboard Bawaslu Sleman — berkas bawaslu_sleman.sql</p>
  </header>
  <ol>
    <?php foreach ($lines as $item): ?>
      <li class="<?= htmlspecialchars($item['level'], ENT_QUOTES) ?>">
        <span class="badge"><?= htmlspecialchars(strtoupper($item['level']), ENT_QUOTES) ?></span>
        <span class="mono"><?= htmlspecialchars($item['text'], ENT_QUOTES) ?></span>
      </li>
    <?php endforeach; ?>
  </ol>
  <footer>
    <?php if ($step === 2): ?>
      Impor berhasil. <a href="index.html#/basis-data">Buka halaman Basis Data &raquo;</a>
    <?php else: ?>
      Impor belum berhasil. Pastikan MySQL aktif lalu muat ulang halaman ini.
    <?php endif; ?>
  </footer>
</div>
</body>
</html>
