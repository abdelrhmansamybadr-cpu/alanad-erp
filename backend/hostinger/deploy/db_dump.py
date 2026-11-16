"""Backs up the live database before a deploy: a one-time PHP script on the server writes a SQL dump, this script downloads it and deletes both.
   FTP_PW='...' python db_dump.py          -> deploy/backups/db-<time>.sql"""
import io, os, secrets, sys, time
from pathlib import Path
sys.path.insert(0, str(Path(__file__).resolve().parent))
import deploy

PHP = r"""<?php
if (($_GET['k'] ?? '') !== '__K__') { http_response_code(404); exit; }
header('Content-Type: text/plain; charset=utf-8'); set_time_limit(0);
require __DIR__ . '/api/config.php';
$pdo = new PDO('mysql:host=127.0.0.1;dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$out = fopen(__DIR__ . '/_dump___K__.sql', 'w');
fwrite($out, "SET FOREIGN_KEY_CHECKS=0;\n");
$tables = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_NUM);
$rows = 0;
foreach ($tables as [$t]) {
    $c = $pdo->query("SHOW CREATE TABLE `$t`")->fetch(PDO::FETCH_NUM);
    fwrite($out, "DROP TABLE IF EXISTS `$t`;\n" . $c[1] . ";\n");
    $st = $pdo->query("SELECT * FROM `$t`", PDO::FETCH_NUM);
    $batch = [];
    while ($r = $st->fetch(PDO::FETCH_NUM)) {
        $batch[] = '(' . implode(',', array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote((string)$v), $r)) . ')';
        $rows++;
        if (count($batch) >= 200) { fwrite($out, "INSERT INTO `$t` VALUES " . implode(',', $batch) . ";\n"); $batch = []; }
    }
    if ($batch) fwrite($out, "INSERT INTO `$t` VALUES " . implode(',', $batch) . ";\n");
}
fwrite($out, "SET FOREIGN_KEY_CHECKS=1;\n"); fclose($out);
echo "tables: ", count($tables), " rows: $rows\n";
"""

key = secrets.token_hex(10)
f = deploy.ftp()
f.storbinary(f"STOR {deploy.ROOT}/_dump_{key}.php", io.BytesIO(PHP.replace("__K__", key).encode()))
try:
    print(deploy.call(f"{deploy.SITE}/_dump_{key}.php?k={key}").strip())
    chunks = []
    f.retrbinary(f"RETR {deploy.ROOT}/_dump_{key}.sql", chunks.append)
    data = b"".join(chunks)
    out = Path(__file__).resolve().parent / "backups" / ("db-" + time.strftime("%Y%m%d-%H%M%S") + ".sql")
    out.write_bytes(data)
    print("saved", out, len(data), "bytes")
finally:
    for n in (f"_dump_{key}.php", f"_dump_{key}.sql"):
        try: f.delete(f"{deploy.ROOT}/{n}")
        except Exception as e: print("could not delete", n, e)
    print("leftovers:", [n for n in f.nlst(deploy.ROOT) if key in n])
