<?php
// Step 4 tests: goods receipt against a purchase order (ordered / received / remaining, tolerance, closing, isolation).
//   php hostinger/api/tests/phase3_test.php http://127.0.0.1:8200/api  mysql-dsn user pass
$base = rtrim($argv[1] ?? 'http://127.0.0.1:8200/api', '/');
$pdo  = new PDO($argv[2] ?? 'mysql:host=127.0.0.1;port=3410;dbname=alanad_erp_dev;charset=utf8mb4', $argv[3] ?? 'root', $argv[4] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
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
    if ($ok) { $pass++; echo "  ok   $name\n"; } else { $fail++; $fails[] = $name; echo "  FAIL $name $detail\n"; }
}
function login(string $e, string $p): ?string { return http('POST', '/auth/login', ['email' => $e, 'password' => $p])['json']['token'] ?? null; }

$u = substr((string)time(), -6);
$admin = login('admin@manzuma.com', 'password');
http('PUT', '/settings', ['receiving.allow_direct_po_receipt' => true], $admin);   // direct receipt is off by default since the gate flow (phase 4 suite); this suite tests the direct path
http('POST', '/users', ['name' => 'viewer3', 'email' => "viewer3_$u@x.com", 'password' => 'Strong-Pass-123', 'role_id' => 4], $admin);
$viewer = login("viewer3_$u@x.com", 'Strong-Pass-123');

echo "\n== setup\n";
$sup = http('POST', '/suppliers', ['code' => "S$u", 'name' => "Farm $u"], $admin)['json']['id'];
$tom = http('POST', '/items', ['code' => "T$u", 'name' => "Tomato $u", 'shelf_life_days' => 7, 'storage_temp_min' => 2, 'storage_temp_max' => 8], $admin)['json']['id'];
$cuc = http('POST', '/items', ['code' => "C$u", 'name' => "Cucumber $u", 'shelf_life_days' => 7], $admin)['json']['id'];
$wh = http('POST', '/warehouses', ['code' => "W$u", 'name' => "WH $u", 'type' => 'cold'], $admin)['json']['id'];
$room = http('POST', '/cold-rooms', ['warehouse_id' => $wh, 'code' => "R$u", 'name' => "Room $u", 'temp_min' => 2, 'temp_max' => 8], $admin)['json']['id'];
$mkpo = function (int $qty) use ($admin, $sup, $tom, $cuc) {
    $po = http('POST', '/purchase-orders', ['date' => date('Y-m-d'), 'supplier_id' => $sup, 'supplier_name' => 'x', 'lines' => [['item_id' => $tom, 'item_name' => 'Tomato', 'qty' => $qty, 'rate' => 5, 'amount' => $qty * 5]]], $admin);
    return (int)($po['json']['id'] ?? 0);
};
$po = $mkpo(100);
check('setup: supplier, items, room and a draft PO exist', $sup && $tom && $cuc && $room && $po);
$line = fn($item, $qty, $extra = []) => array_merge(['item_id' => $item, 'qty' => $qty, 'cold_room_id' => $room], $extra);

echo "\n== status\n";
$st = http('GET', "/stock/po/$po", null, $admin)['json'];
check('status shows ordered 100, received 0, remaining 100', $st['lines'][0]['ordered'] == 100 && $st['lines'][0]['received'] == 0 && $st['lines'][0]['remaining'] == 100, json_encode($st));
check('a draft order cannot be received (409)', http('POST', '/stock/receive-po', ['po_id' => $po, 'lines' => [$line($tom, 10)]], $admin)['status'] === 409);
$pdo->exec("UPDATE purchase_orders SET status = 'Approved' WHERE id = $po");

echo "\n== receiving\n";
$r = http('POST', '/stock/receive-po', ['po_id' => $po, 'lines' => [$line($tom, 40)]], $admin);
check('partial receipt books a batch and keeps the order open', $r['status'] === 201 && $r['json']['status'] === 'Approved' && $r['json']['lines'][0]['received'] == 40 && $r['json']['lines'][0]['remaining'] == 60 && count($r['json']['received_batches']) === 1, json_encode($r['json']));
check('expiry came from the shelf life (7 days)', $r['json']['received_batches'][0]['expiry_date'] === date('Y-m-d', strtotime('+7 days')));
$led = $pdo->query("SELECT COUNT(*) FROM stock_ledger WHERE ref_type = 'purchase_order' AND ref_id = $po AND movement = 'receipt'")->fetchColumn();
check('the ledger row is linked to the order', (int)$led === 1);
$over = http('POST', '/stock/receive-po', ['po_id' => $po, 'lines' => [$line($tom, 80)]], $admin);
check('more than ordered + 10 % tolerance -> 422', $over['status'] === 422, json_encode($over['json']));
check('nothing was booked by the refused receipt', http('GET', "/stock/po/$po", null, $admin)['json']['lines'][0]['received'] == 40);
check('an item that is not on the order -> 422', http('POST', '/stock/receive-po', ['po_id' => $po, 'lines' => [$line($cuc, 5)]], $admin)['status'] === 422);
check('zero quantity -> 422', http('POST', '/stock/receive-po', ['po_id' => $po, 'lines' => [$line($tom, 0)]], $admin)['status'] === 422);
check('empty receipt -> 422', http('POST', '/stock/receive-po', ['po_id' => $po, 'lines' => []], $admin)['status'] === 422);
check('unknown cold room is refused', in_array(http('POST', '/stock/receive-po', ['po_id' => $po, 'lines' => [$line($tom, 5, ['cold_room_id' => 99999])]], $admin)['status'], [404, 422], true));
check('viewer cannot receive (403)', http('POST', '/stock/receive-po', ['po_id' => $po, 'lines' => [$line($tom, 5)]], $viewer)['status'] === 403);
$r2 = http('POST', '/stock/receive-po', ['po_id' => $po, 'lines' => [$line($tom, 70)]], $admin);
check('receiving up to the tolerance completes the order (status Received)', $r2['status'] === 201 && $r2['json']['status'] === 'Received', json_encode($r2['json']));
check('a received order cannot be received again (409)', http('POST', '/stock/receive-po', ['po_id' => $po, 'lines' => [$line($tom, 1)]], $admin)['status'] === 409);
check('PO status in the purchase-orders list is Received', http('GET', "/purchase-orders/$po", null, $admin)['json']['status'] === 'Received');

echo "\n== closing early and fake links\n";
$po2 = $mkpo(100); $pdo->exec("UPDATE purchase_orders SET status = 'Approved' WHERE id = $po2");
$c = http('POST', '/stock/receive-po', ['po_id' => $po2, 'close' => true, 'lines' => [$line($tom, 30)]], $admin);
check('close = true marks the order Received although only 30 of 100 arrived', $c['status'] === 201 && $c['json']['status'] === 'Received');
$po3 = $mkpo(100); $pdo->exec("UPDATE purchase_orders SET status = 'Approved' WHERE id = $po3");
http('POST', '/stock/receive', ['item_id' => $tom, 'qty' => 10, 'cold_room_id' => $room, 'ref_type' => 'purchase_order', 'ref_id' => $po3], $admin);
check('plain /stock/receive cannot fake a link to an order', http('GET', "/stock/po/$po3", null, $admin)['json']['lines'][0]['received'] == 0);

echo "\n== company isolation\n";
$co = http('POST', '/companies', ['name_en' => "Other $u"], $admin)['json']['id'];
$adminId = (int)$pdo->query("SELECT id FROM users WHERE email = 'admin@manzuma.com'")->fetchColumn();
$pdo->exec("INSERT IGNORE INTO user_companies (user_id, company_id) VALUES ($adminId, $co)");
check('company 2 cannot read company 1 order status (404)', http('GET', "/stock/po/$po3", null, $admin, $co)['status'] === 404);
check('company 2 cannot receive against it (404)', http('POST', '/stock/receive-po', ['po_id' => $po3, 'lines' => [$line($tom, 1)]], $admin, $co)['status'] === 404);

http('PUT', '/settings', ['receiving.allow_direct_po_receipt' => false], $admin);   // back to the default
echo "\n------------------------------------\n$pass passed, $fail failed\n";
if ($fail) { echo "Failed: " . implode('; ', $fails) . "\n"; exit(1); }
