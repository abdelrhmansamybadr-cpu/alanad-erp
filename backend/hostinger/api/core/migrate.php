<?php
// ─── Alanad ERP — migration runner ───────────────────────────
// Applies database/migrations/*.sql in order, once each, and records them
// in `schema_migrations`. Usable from the CLI (php core/migrate.php) or from
// code (run_migrations($pdo, $dir)).

function run_migrations(PDO $pdo, string $dir): array {
    $pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
        version VARCHAR(100) NOT NULL PRIMARY KEY,
        applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $done = $pdo->query('SELECT version FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
    $files = glob(rtrim($dir, '/\\') . '/*.sql');
    sort($files);
    $report = ['applied' => [], 'skipped' => [], 'warnings' => []];

    foreach ($files as $file) {
        $version = basename($file, '.sql');
        if (in_array($version, $done, true)) { $report['skipped'][] = $version; continue; }

        $sql = file_get_contents($file);
        // keep "-- @optional" markers, drop other comment lines
        $sql = preg_replace('/^\s*--(?!\s*@optional).*$/m', '', $sql);
        $optionalNext = false;
        $failed = false;
        foreach (preg_split('/;\s*[\r\n]+/', $sql) as $stmt) {
            $stmt = trim($stmt);
            if ($stmt === '') continue;
            $optional = false;
            if (preg_match('/^--\s*@optional\s*/', $stmt)) {
                $optional = true;
                $stmt = trim(preg_replace('/^--\s*@optional\s*/', '', $stmt));
            }
            try {
                $pdo->exec($stmt);
            } catch (Throwable $e) {
                if ($optional) {
                    $report['warnings'][] = "$version (optional): " . substr($e->getMessage(), 0, 120);
                } else {
                    $report['warnings'][] = "$version FAILED: " . substr($e->getMessage(), 0, 200);
                    $failed = true;
                    break;
                }
            }
        }
        if ($failed) { throw new RuntimeException("Migration $version failed: " . end($report['warnings'])); }
        $pdo->prepare('INSERT INTO schema_migrations (version) VALUES (?)')->execute([$version]);
        $report['applied'][] = $version;
    }
    return $report;
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) {
    // php core/migrate.php <dir> [--dsn=mysql:host=..;dbname=..] --user=.. --pass=..
    $opts = getopt('', ['dsn:', 'user:', 'pass:', 'dir:']);
    $pdo = new PDO($opts['dsn'], $opts['user'], $opts['pass'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    print_r(run_migrations($pdo, $opts['dir'] ?? __DIR__ . '/../../../database/migrations'));
}
