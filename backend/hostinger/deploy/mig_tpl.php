<?php
if (($_GET['k'] ?? '') !== '__K__') { http_response_code(404); exit; }
header('Content-Type: text/plain; charset=utf-8');
require __DIR__ . '/api/config.php';
require __DIR__ . '/api/core/migrate.php';
$pdo = new PDO('mysql:host=127.0.0.1;dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
try {
    $r = run_migrations($pdo, __DIR__ . '/_mig___K__');
    echo json_encode($r, JSON_PRETTY_PRINT), "\n";
} catch (Throwable $e) { echo "ERROR: ", $e->getMessage(), "\n"; }
echo "tables: ", count($pdo->query('SHOW TABLES')->fetchAll()), "\n";
echo "audit triggers: ", count($pdo->query("SHOW TRIGGERS LIKE 'audit_log'")->fetchAll()), "\n";
