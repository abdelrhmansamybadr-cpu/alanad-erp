<?php
// Phase 11 (suite 17): factory reports — real numbers from receiving, sorting, packing, sales, stock; period and branch filters; isolation.
//   php hostinger/api/tests/phase17_test.php http://127.0.0.1:8200/api  mysql-dsn user pass
$base = rtrim($argv[1] ?? 'http://127.0.0.1:8200/api', '/');
$pdo  = new PDO($argv[2] ?? 'mysql:host=127.0.0.1;port=3410;dbname=alanad_fresh;charset=utf8mb4', $argv[3] ?? 'root', $argv[4] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pass = 0; $fail = 0; $fails = [];
function http(string $m, string $path, ?array $body = null, ?string $tok = null, ?int $company = null, ?int $branch = null): array {
    global $base;
    $ch = curl_init($base . $path);
    $h = ['Content-Type: application/json'];
    if ($tok) $h[] = "Authorization: Bearer $tok";
    if ($company) $h[] = "X-Company-Id: $company";
    if ($branch) $h[] = "X-Branch-Id: $branch";
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $m, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $h, CURLOPT_TIMEOUT => 30]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    $raw = curl_exec($ch); $st = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return ['status' => $st, 'json' => json_decode($raw, true)];
}
function check(string $name, bool $ok, string $detail = ''): void {
    global $pass, $fail, $fails;
    if ($ok) { $pass++; echo "  ok   $name\n"; } else { $fail++; $fails[] = $name; echo "  FAIL $name $detail\n"; }
}
function login(string $e, string $p): ?string { return http('POST', '/auth/login', ['email' => $e, 'password' => $p])['json']['token'] ?? null; }

$u = substr((string)time(), -6);
$admin = login('admin@manzuma.com', 'password');
$role = fn(string $k) => (int)$pdo->query("SELECT id FROM roles WHERE role_key = '$k' AND company_id IS NULL")->fetchColumn();
foreach ([["rq_$u@x.com", $role('quality_inspector')], ["rv_$u@x.com", 4]] as [$e, $r]) http('POST', '/users', ['name' => $e, 'email' => $e, 'password' => 'Strong-Pass-123', 'role_id' => $r], $admin);
$qc = login("rq_$u@x.com", 'Strong-Pass-123'); $viewer = login("rv_$u@x.com", 'Strong-Pass-123');
$today = date('Y-m-d');
$pdo->exec("UPDATE companies SET vat_number = '300000000000003' WHERE id = 1");
http('PUT', '/settings', ['receiving.three_person_rule' => false, 'fleet.require_entry_permit' => false, 'sorting.min_class1_pct' => 80, 'dispatch.require_invoice_before_exit' => true, 'dispatch.require_load_check' => false, 'sales.approval_above_kg' => 0, 'finance.vat_rate_pct' => 15], $admin);
$B1 = (int)http('POST', '/company-branches', ['code' => "RB$u", 'name_en' => "Report Jeddah $u"], $admin)['json']['id'];
$rep = fn(string $q = '', ?string $tok = null, ?int $co = null, ?int $br = null) => http('GET', '/factory-reports' . $q, null, $tok ?? $admin, $co, $br);

echo "\n== the request\n";
check('no token (401)', http('GET', '/factory-reports')['status'] === 401);
check('a read-only user can see the reports', $rep('', $viewer)['status'] === 200);
check('a bad date, or from after to, or a period over two years is refused (422)', $rep('?from=12/01/2026')['status'] === 422 && $rep('?from=2026-11-10&to=2026-11-01')['status'] === 422 && $rep('?from=2020-01-01&to=2026-01-01')['status'] === 422);
$d = $rep()['json'];
check('the default period is the last 30 days and every section is there', $d['period']['to'] === $today && $d['period']['from'] === date('Y-m-d', strtotime('-29 days')) && isset($d['receiving'], $d['sorting'], $d['production'], $d['packing'], $d['sales'], $d['stock'], $d['market'], $d['hr']));

echo "\n== a branch with nothing in it shows zeros\n";
$z = $rep("?from=$today&to=$today", null, null, $B1)['json'];
check('a new branch: no trucks, no sorting, no packing, no invoices, no stock', $z['receiving']['trucks'] === 0 && $z['sorting']['runs'] === 0 && $z['packing']['orders'] === 0 && $z['sales']['invoices'] === 0 && $z['stock']['total_kg'] == 0 && $z['sorting']['class1_pct'] == 0);

echo "\n== one load through the whole chain (in the new branch)\n";
$sup = http('POST', '/suppliers', ['code' => "RS$u", 'name' => "Report Farm $u"], $admin)['json']['id'];
$tom = http('POST', '/items', ['code' => "RT$u", 'name' => "RP Tomato $u", 'shelf_life_days' => 7, 'needs_sorting' => 1], $admin)['json']['id'];
$wh = http('POST', '/warehouses', ['code' => "RW$u", 'name' => "RP WH $u", 'type' => 'cold', 'branch_id' => $B1], $admin)['json']['id'];
$mkroom = fn($c) => http('POST', '/cold-rooms', ['warehouse_id' => $GLOBALS['wh'], 'code' => "$c$u", 'name' => "RP room $c $u", 'temp_min' => 2, 'temp_max' => 8], $GLOBALS['admin'])['json']['id'];
$rooms = ['1' => $mkroom('R1'), '2' => $mkroom('R2'), '3' => $mkroom('R3')];
$cust = http('POST', '/customers', ['name' => "RP Client $u", 'code' => "RC$u", 'tax_id' => '300000000000003'], $admin)['json']['id'];
$po = http('POST', '/purchase-orders', ['date' => $today, 'supplier_id' => $sup, 'supplier_name' => 'x', 'lines' => [['item_id' => $tom, 'item_name' => 'T', 'qty' => 500, 'rate' => 5, 'amount' => 2500]]], $admin, null, $B1)['json'];
$pdo->exec("UPDATE purchase_orders SET status = 'Approved' WHERE id = {$po['id']}");
$mgr = login('admin@manzuma.com', 'password');
$dl = (int)http('POST', '/deliveries', ['po_id' => $po['id'], 'driver_name' => 'Ali', 'vehicle_plate' => "RP $u"], $admin)['json']['id'];
http('POST', "/deliveries/$dl/weigh-in", ['gross_kg' => 1500], $admin); http('POST', "/deliveries/$dl/temperature", ['truck_air_temp' => 4, 'product_temp' => 4], $admin);
http('POST', "/deliveries/$dl/quality", ['lines' => [['item_id' => $tom, 'grade' => 'A', 'received_kg' => 500]]], $admin); http('POST', "/deliveries/$dl/weigh-out", ['tare_kg' => 1000], $admin);
$mid = http('POST', '/users', ['name' => "rm_$u", 'email' => "rm_$u@x.com", 'password' => 'Strong-Pass-123', 'role_id' => 2], $admin); $mgrTok = login("rm_$u@x.com", 'Strong-Pass-123');
http('POST', "/deliveries/$dl/accept", null, $mgrTok); http('POST', "/deliveries/$dl/route", ['to' => 'sorting'], $admin);
$run = http('POST', '/sorting-runs', ['delivery_id' => $dl], $admin)['json'];
http('POST', "/sorting-runs/{$run['id']}/results", ['lines' => [['line_id' => $run['lines'][0]['id'], 'class1_kg' => 400, 'class2_kg' => 60, 'class3_kg' => 30, 'shrink_kg' => 10]]], $admin);
$sg = http('POST', "/sorting-runs/{$run['id']}/sign-off", ['rooms' => $rooms], $qc);
check('(setup) the load was sorted: 400 / 60 / 30 + 10 shrink', $sg['status'] === 200 && $sg['json']['status'] === 'Completed', json_encode($sg['json']['error'] ?? null));
$k = http('POST', '/pack-orders', ['item_id' => $tom, 'required_kg' => 100, 'customer_id' => $cust, 'branch_id' => $B1], $admin)['json']['id'];
http('POST', "/pack-orders/$k/decide", ['box_kg' => 2], $admin); http('POST', "/pack-orders/$k/start", null, $admin);
$pk = http('POST', "/pack-orders/$k/pack", ['boxes_packed' => 50], $admin)['json'];
$pal = array_values(array_filter($pk['units'], fn($x) => $x['kind'] === 'pallet'))[0];
http('POST', '/sales-prices', ['item_id' => $tom, 'grade' => 'A', 'price_per_kg' => 5], $admin);
$sh = (int)http('POST', '/shipments', ['unit_ids' => [$pal['id']], 'customer_id' => $cust, 'vehicle_plate' => "TRK $u", 'driver_name' => 'Omar'], $admin)['json']['id'];
http('POST', "/shipments/$sh/load", ['codes' => []], $admin);
$iv = http('POST', "/shipments/$sh/invoice", [], $admin);
http('POST', "/shipments/$sh/exit-permit", null, $admin); http('POST', "/shipments/$sh/dispatch", null, $admin);
check('(setup) the pallet was invoiced and dispatched', $iv['status'] === 201 && $iv['json']['invoice']['subtotal'] == 500.0, json_encode($iv['json']['error'] ?? null));

$r = $rep("?from=$today&to=$today", null, null, $B1)['json'];
check('receiving: 1 truck, 500 kg received, nothing rejected, the time to accept is a number of minutes', $r['receiving']['trucks'] === 1 && $r['receiving']['received_kg'] == 500.0 && $r['receiving']['rejected_kg'] == 0.0 && $r['receiving']['reject_pct'] == 0.0 && is_int($r['receiving']['avg_minutes_to_accept']), json_encode($r['receiving']));
check('sorting: 1 run, 500 kg in, class 1 = 80 %, class 2 = 12 %, class 3 = 6 %, shrink 2 %', $r['sorting']['runs'] === 1 && $r['sorting']['input_kg'] == 500.0 && $r['sorting']['class1_pct'] == 80.0 && $r['sorting']['class2_pct'] == 12.0 && $r['sorting']['class3_pct'] == 6.0 && $r['sorting']['shrink_pct'] == 2.0 && $r['sorting']['incidents'] === 0, json_encode($r['sorting']));
check('sorting by supplier: this farm gave 80 / 12 / 6 %', ($r['sorting']['by_supplier'][0]['supplier'] ?? '') === "Report Farm $u" && $r['sorting']['by_supplier'][0]['class1_pct'] == 80.0 && $r['sorting']['by_supplier'][0]['class3_pct'] == 6.0);
check('packing: 1 order, 50 boxes, 2 cartons (40 + 10), 1 pallet, 100 kg, no loss', $r['packing']['orders'] === 1 && $r['packing']['boxes'] === 50 && $r['packing']['cartons'] === 2 && $r['packing']['pallets'] === 1 && $r['packing']['kg'] == 100.0 && $r['packing']['loss_kg'] == 0.0, json_encode($r['packing']));
$cogs = round(100 * (500 * 5 / 490), 2);
check('sales: 1 invoice, 500.00 + VAT 75.00 = 575.00, 1 shipment dispatched; the cost of goods is 100 kg at the real batch cost', $r['sales']['invoices'] === 1 && $r['sales']['shipments'] === 1 && $r['sales']['subtotal'] == 500.0 && $r['sales']['vat'] == 75.0 && $r['sales']['total'] == 575.0 && abs($r['sales']['cogs'] - $cogs) < 0.02, json_encode($r['sales']));
check('sales: the gross margin is the invoice minus that cost; kg and average price by grade; the client is the top customer', abs($r['sales']['gross_margin'] - (500 - $cogs)) < 0.02 && $r['sales']['by_grade'][0]['grade'] === 'A' && $r['sales']['by_grade'][0]['kg'] == 100.0 && $r['sales']['by_grade'][0]['avg_price'] == 5.0 && $r['sales']['top_customers'][0]['customer'] === "RP Client $u" && $r['sales']['top_customers'][0]['subtotal'] == 500.0);
$sb = []; foreach ($r['stock']['by_grade'] as $x) $sb[$x['grade']] = $x['kg'];
check('stock now: grade A 300 kg (400 sorted - 100 packed), B 60, C 30 = 390 kg', ($sb['A'] ?? 0) == 300.0 && ($sb['B'] ?? 0) == 60.0 && ($sb['C'] ?? 0) == 30.0 && $r['stock']['total_kg'] == 390.0, json_encode($r['stock']));
check('market buying and HR sections are present and numeric', isset($r['market']['open_custody'], $r['hr']['active_employees']) && is_numeric($r['market']['spent']));

echo "\n== period, branch, company\n";
$old = $rep('?from=' . date('Y-m-d', strtotime('-60 days')) . '&to=' . date('Y-m-d', strtotime('-40 days')), null, null, $B1)['json'];
check('a period in the past shows no activity (stock is "now" and stays)', $old['receiving']['trucks'] === 0 && $old['sorting']['runs'] === 0 && $old['sales']['invoices'] === 0 && $old['packing']['orders'] === 0 && $old['stock']['total_kg'] == 390.0);
$main = (int)http('GET', '/me', null, $admin)['json']['branches'][0]['id'];
$m = $rep("?from=$today&to=$today", null, null, $main)['json'];
$all = $rep("?from=$today&to=$today")['json'];
check('each branch has its own numbers: the main branch plus the new branch add up to no more than the whole company', $all['receiving']['trucks'] >= $m['receiving']['trucks'] + 1 && $all['sorting']['input_kg'] >= $m['sorting']['input_kg'] + 500 - 0.001 && $all['stock']['total_kg'] >= $m['stock']['total_kg'] + 390 - 0.01);
$co = http('POST', '/companies', ['name_en' => "RP other $u"], $admin)['json']['id'];
$adminId = (int)$pdo->query("SELECT id FROM users WHERE email = 'admin@manzuma.com'")->fetchColumn();
$pdo->exec("INSERT IGNORE INTO user_companies (user_id, company_id) VALUES ($adminId, $co)");
$c2 = $rep("?from=$today&to=$today", null, $co)['json'];
check('company 2 sees none of it: no trucks, runs, packing, invoices or stock', $c2['receiving']['trucks'] === 0 && $c2['sorting']['runs'] === 0 && $c2['packing']['orders'] === 0 && $c2['sales']['invoices'] === 0 && $c2['stock']['total_kg'] == 0 && $c2['hr']['active_employees'] === 0);

echo "\n------------------------------------\n$pass passed, $fail failed\n";
if ($fail) { echo "Failed: " . implode('; ', $fails) . "\n"; exit(1); }
