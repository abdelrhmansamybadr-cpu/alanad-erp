<?php
// Company-isolation test: for EVERY resource served by the CRUD engine, a record created in company 1
// must be invisible and untouchable from company 2, and field names / company_id must not be abusable.
//   php hostinger/api/tests/isolation_test.php http://127.0.0.1:8200/api  mysql-dsn user pass
// Run after phase0_test.php's database setup (schema + migrations 001, 002). Uses the seed admin.

$base = rtrim($argv[1] ?? 'http://127.0.0.1:8200/api', '/');
$dsn  = $argv[2] ?? 'mysql:host=127.0.0.1;port=3410;dbname=alanad_erp_dev;charset=utf8mb4';
$pdo  = new PDO($dsn, $argv[3] ?? 'root', $argv[4] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pass = 0; $fail = 0; $fails = [];

function http(string $m, string $path, ?array $body = null, ?string $tok = null, ?int $company = null): array {
    global $base;
    $ch = curl_init($base . $path);
    $h = ['Content-Type: application/json'];
    if ($tok) $h[] = "Authorization: Bearer $tok";
    if ($company) $h[] = "X-Company-Id: $company";
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $m, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $h, CURLOPT_TIMEOUT => 20]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    $raw = curl_exec($ch); $st = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return ['status' => $st, 'json' => json_decode($raw, true)];
}
function check(string $name, bool $ok, string $detail = ''): void {
    global $pass, $fail, $fails;
    if ($ok) { $pass++; } else { $fail++; $fails[] = $name; echo "  FAIL $name $detail\n"; }
}

$login = http('POST', '/auth/login', ['email' => 'admin@manzuma.com', 'password' => 'password']);
$tok = $login['json']['token'] ?? null;
if (!$tok) { echo "cannot log in as seed admin\n"; exit(1); }
$adminId = (int)$pdo->query("SELECT id FROM users WHERE email = 'admin@manzuma.com'")->fetchColumn();

// company 2 + the admin may enter it
$c2 = http('POST', '/companies', ['name_en' => 'Isolation Co 2', 'name_ar' => 'شركة العزل'], $tok)['json']['id'] ?? 0;
$pdo->exec("INSERT IGNORE INTO user_companies (user_id, company_id) VALUES ($adminId, $c2)");
check('second company exists', $c2 > 1);

// resource -> table, read from the handler configs
$resources = [];
foreach (glob(__DIR__ . '/../handlers/*.php') as $f) {
    $src = file_get_contents($f);
    if (strpos($src, 'crud_handle(') === false) continue;
    if (in_array(basename($f, '.php'), ['accounts', 'journal-entries'], true)) continue;   // read-only since the finance engine (phase 5 suite covers the ledger)
    preg_match("/'table' => '([a-z_]+)'/", $src, $m);
    $child = preg_match("/'child' => \[\s*'key' => '([a-z_]+)',\s*'table' => '([a-z_]+)',\s*'fk' => '([a-z_]+)'/s", $src, $cm) ? $cm : null;
    $resources[basename($f, '.php')] = ['table' => $m[1], 'child' => $child];
}
echo count($resources) . " CRUD resources found\n";
check('at least 60 CRUD resources', count($resources) >= 60, (string)count($resources));

/** minimal valid body for a table, derived from the schema */
function sample(PDO $pdo, string $table, string $tag): array {
    $st = $pdo->prepare('SELECT COLUMN_NAME, DATA_TYPE, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA, CHARACTER_MAXIMUM_LENGTH FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $st->execute([$table]);
    $row = [];
    $all = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($all as $c) {
        if (in_array($c['COLUMN_NAME'], ['id', 'company_id', 'created_at', 'updated_at'], true)) continue;
        if (strpos($c['EXTRA'], 'auto_increment') !== false) continue;
        if ($c['IS_NULLABLE'] === 'YES' || $c['COLUMN_DEFAULT'] !== null || strpos($c['EXTRA'], 'GENERATED') !== false) continue;
        $row[$c['COLUMN_NAME']] = match (true) {
            in_array($c['DATA_TYPE'], ['int', 'bigint', 'smallint', 'tinyint', 'decimal', 'float', 'double']) => 1,
            in_array($c['DATA_TYPE'], ['date']) => '2026-01-15',
            in_array($c['DATA_TYPE'], ['datetime', 'timestamp']) => '2026-01-15 10:00:00',
            $c['DATA_TYPE'] === 'enum' => preg_match("/'([^']+)'/", $c['COLUMN_TYPE'], $e) ? $e[1] : 'x',
            $c['DATA_TYPE'] === 'json' => '{}',
            default => substr("{$tag}_{$c['COLUMN_NAME']}", 0, min(40, (int)($c['CHARACTER_MAXIMUM_LENGTH'] ?: 40))),
        };
    }
    if (!$row) foreach ($all as $c) if (in_array($c['DATA_TYPE'], ['varchar', 'text']) && $c['COLUMN_NAME'] !== 'id') { $row[$c['COLUMN_NAME']] = substr("{$tag}_x", 0, 30); break; }
    return $row;
}

foreach ($resources as $res => $info) {
    $table = $info['table'];
    $body = sample($pdo, $table, $res);
    if ($res === 'cold-rooms') { $body['temp_min'] = 2; $body['temp_max'] = 6; $body['warehouse_id'] = (int)(http('POST', '/warehouses', ['code' => 'ISO' . mt_rand(1000, 9999), 'name' => 'iso wh'], $tok, 1)['json']['id'] ?? 0); }
    if ($res === 'asset-movements') $body['asset_id'] = (int)(http('POST', '/assets', sample($pdo, 'assets', 'seedasset'), $tok, 1)['json']['id'] ?? 0);
    $r = http('POST', "/$res", $body, $tok, 1);
    if ($r['status'] !== 201) { check("$res: create in company 1", false, "status {$r['status']} " . json_encode($r['json']) . ' body ' . json_encode($body)); continue; }
    $id = $r['json']['id'];
    check("$res: row carries company 1", (int)$r['json']['company_id'] === 1);

    check("$res: ?search= works (every search column exists)", http('GET', "/$res?search=zz", null, $tok, 1)['status'] === 200);
    $l2 = http('GET', "/$res", null, $tok, $c2);
    $ids2 = array_map(fn($x) => $x['id'], is_array($l2['json']) ? $l2['json'] : []);
    check("$res: list in company 2 hides it", $l2['status'] === 200 && !in_array($id, $ids2, true));
    check("$res: GET by id from company 2 -> 404", http('GET', "/$res/$id", null, $tok, $c2)['status'] === 404);
    check("$res: PUT from company 2 -> 404", http('PUT', "/$res/$id", $body, $tok, $c2)['status'] === 404);
    check("$res: DELETE from company 2 -> 404", http('DELETE', "/$res/$id", null, $tok, $c2)['status'] === 404);
    check("$res: still exists in company 1 after the attacks", http('GET', "/$res/$id", null, $tok, 1)['status'] === 200);

    // field abuse
    check("$res: unknown field -> 422", http('POST', "/$res", $body + ['no_such_column' => 1], $tok, 1)['status'] === 422);
    $ct = http('PUT', "/$res/$id", ['company_id' => $c2], $tok, 1);
    $still = (int)$pdo->query("SELECT company_id FROM `$table` WHERE id = $id")->fetchColumn();
    check("$res: company_id cannot be moved by the client", $still === 1, "now $still");

    check("$res: DELETE in company 1", http('DELETE', "/$res/$id", null, $tok, 1)['status'] === 200);
    $mass = http('POST', "/$res", $body + ['company_id' => $c2], $tok, 1);
    check("$res: client company_id on create is ignored", $mass['status'] === 201 && (int)$mass['json']['company_id'] === 1);
    if ($mass['status'] === 201) http('DELETE', "/$res/" . $mass['json']['id'], null, $tok, 1);
}

echo "\n== parent / child rows\n";
$inv = http('POST', '/invoices', ['customer_name' => 'ACME', 'date' => '2026-02-01', 'total' => 115,
    'lines' => [['description' => 'Tomatoes', 'qty' => 10, 'rate' => 10, 'amount' => 100]]], $tok, 1);
check('invoice with lines created', $inv['status'] === 201 && count($inv['json']['lines'] ?? []) === 1, json_encode($inv['json']));
$iid = $inv['json']['id'] ?? 0;
check('invoice number auto-generated per company (INV-0001)', ($inv['json']['number'] ?? '') === 'INV-0001');
check('lines unreachable from company 2', http('GET', "/invoices/$iid", null, $tok, $c2)['status'] === 404);
$other = http('POST', '/invoices', ['customer_name' => 'Other', 'date' => '2026-02-01', 'total' => 1], $tok, $c2);
check('company 2 numbering starts again at INV-0001', ($other['json']['number'] ?? '') === 'INV-0001', json_encode($other['json']));
$upd = http('PUT', "/invoices/$iid", ['lines' => [['description' => 'Cucumbers', 'qty' => 2, 'rate' => 5, 'amount' => 10]], 'number' => 'HACK'], $tok, 1);
check('lines replaced, number is immutable', $upd['status'] === 200 && $upd['json']['lines'][0]['description'] === 'Cucumbers' && $upd['json']['number'] === 'INV-0001');
check('child with a foreign column -> 422', http('PUT', "/invoices/$iid", ['lines' => [['evil' => 1]]], $tok, 1)['status'] === 422);

echo "\n== unique codes are per company\n";
$uc = '9' . substr((string)time(), -5);
$a = http('POST', '/gl-accounts', ['code' => $uc, 'name_en' => 'Cash', 'type' => 'asset'], $tok, 1);
$b = http('POST', '/gl-accounts', ['code' => $uc, 'name_en' => 'Cash', 'type' => 'asset'], $tok, $c2);
$d = http('POST', '/gl-accounts', ['code' => $uc, 'name_en' => 'Cash again', 'type' => 'asset'], $tok, 1);
check('same account code allowed in two companies', $a['status'] === 201 && $b['status'] === 201, json_encode([$a['json'], $b['json']]));
check('same code twice in ONE company -> 409', $d['status'] === 409, (string)$d['status']);

echo "\n== related lists and dashboard\n";
$cu = http('POST', '/customers', ['name' => 'Rel Customer', 'code' => 'REL-1'], $tok, 1);
$cd = http('GET', '/customers/' . $cu['json']['id'], null, $tok, 1);
check('customer detail carries its invoices list', isset($cd['json']['invoices']) && is_array($cd['json']['invoices']));
$dash1 = http('GET', '/dashboard', null, $tok, 1)['json']; $dash2 = http('GET', '/dashboard', null, $tok, $c2)['json'];
check('dashboard counts are per company', ($dash1['total_invoices'] ?? 0) >= 1 && ($dash2['total_invoices'] ?? 0) === 1 && ($dash2['total_customers'] ?? -1) === 0, json_encode([$dash1['total_invoices'] ?? null, $dash2]));

echo "\n------------------------------------\n$pass passed, $fail failed\n";
if ($fail) { echo "Failed: " . implode('; ', array_slice($fails, 0, 40)) . "\n"; exit(1); }
