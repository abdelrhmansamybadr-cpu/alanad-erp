#!/usr/bin/env bash
# Local test rig for the Alanad ERP API: a throw-away MySQL on port 3410 (database alanad_fresh = a brand-new install:
# schema.sql + every migration) and the PHP API on http://127.0.0.1:8200/api.   Usage:  bash dev-up.sh [--reset]
# The data lives in %TEMP%\alanad-dev (not in the project). Tests: php hostinger/api/tests/phaseN_test.php
set -e
HERE="$(cd "$(dirname "$0")" && pwd)"; BE="$(cd "$HERE/../.." && pwd)"
MYSQL_DIR="$(ls -d C:/laragon/bin/mysql/*/ | head -1)"; PHP="$(ls -d C:/laragon/bin/php/*/ | head -1)php.exe"
DATA="${LOCALAPPDATA//\\//}/Temp/alanad-dev/mysql"
[ "$1" = "--reset" ] && rm -rf "$DATA"
if [ ! -d "$DATA/mysql" ]; then
  mkdir -p "$DATA"; "$MYSQL_DIR/bin/mysqld.exe" --initialize-insecure --datadir="$DATA" --console >/dev/null 2>&1
fi
if ! netstat -ano | grep -q ":3410 .*LISTENING"; then
  (nohup "$MYSQL_DIR/bin/mysqld.exe" --datadir="$DATA" --port=3410 --bind-address=127.0.0.1 --console >/tmp/alanad-mysql.log 2>&1 &)
  for i in $(seq 1 40); do netstat -ano | grep -q ":3410 .*LISTENING" && break; sleep 1; done
fi
"$PHP" -r '
$root = new PDO("mysql:host=127.0.0.1;port=3410;charset=utf8mb4", "root", "");
$exists = $root->query("SHOW DATABASES LIKE \"alanad_fresh\"")->fetchColumn();
if (!$exists) { $root->exec("CREATE DATABASE alanad_fresh CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"); $root->exec("USE alanad_fresh"); $root->exec(file_get_contents($argv[1] . "/database/schema.sql")); echo "base schema loaded\n"; }
' "$BE"
"$PHP" "$BE/hostinger/api/core/migrate.php" --dsn="mysql:host=127.0.0.1;port=3410;dbname=alanad_fresh;charset=utf8mb4" --user=root | grep -E "applied|=>|FAIL|Fatal" | head -20 || true
if ! netstat -ano | grep -q ":8200 .*LISTENING"; then
  (cd "$BE" && ALANAD_CONFIG="$HERE/test_config_fresh.php" nohup "$PHP" -S 127.0.0.1:8200 hostinger/api/router.php >/tmp/alanad-php.log 2>&1 &)
  sleep 2
fi
curl -s -o /dev/null -w "API http %{http_code} (401 is right without a token)\n" http://127.0.0.1:8200/api/me
