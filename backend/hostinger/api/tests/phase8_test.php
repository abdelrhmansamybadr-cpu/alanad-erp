<?php
// Phase 2 (suite 8): client orders, stock check, reservation, shortage -> purchase request.
//   php hostinger/api/tests/phase8_test.php http://127.0.0.1:8200/api  mysql-dsn user pass
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
http('POST', '/users', ['name' => "ns$u", 'email' => "co_$u@x.com", 'password' => 'Strong-Pass-123', 'role_id' => 4], $admin);
$user = login("co_$u@x.com", 'Strong-Pass-123');
$exp = fn($d) => date('Y-m-d', strtotime("+$d days"));
$main = (int)http('GET', '/me', null, $admin)['json']['branches'][0]['id'];
$B1 = (int)http('POST', '/company-branches', ['code' => "CJ$u", 'name_en' => "CO Jeddah $u"], $admin)['json']['id'];
$wm = http('POST', '/warehouses', ['code' => "CWM$u", 'name' => "CO main $u", 'type' => 'cold'], $admin)['json'];
$wj = http('POST', '/warehouses', ['code' => "CWJ$u", 'name' => "CO jed $u", 'type' => 'cold', 'branch_id' => $B1], $admin)['json'];
$rm = http('POST', '/cold-rooms', ['warehouse_id' => $wm['id'], 'code' => "CRM$u", 'name' => "CO room main $u", 'temp_min' => 2, 'temp_max' => 8], $admin)['json']['id'];
$rj = http('POST', '/cold-rooms', ['warehouse_id' => $wj['id'], 'code' => "CRJ$u", 'name' => "CO room jed $u", 'temp_min' => 2, 'temp_max' => 8], $admin)['json']['id'];
$tom = http('POST', '/items', ['code' => "CT$u", 'name' => "CO Tomato $u", 'shelf_life_days' => 7], $admin)['json']['id'];
$cuc = http('POST', '/items', ['code' => "CC$u", 'name' => "CO Cucumber $u", 'shelf_life_days' => 7], $admin)['json']['id'];
http('POST', '/stock/receive', ['item_id' => $tom, 'qty' => 600, 'cold_room_id' => $rm, 'expiry_date' => $exp(3), 'batch_no' => "A$u"], $admin, null, $main);
http('POST', '/stock/receive', ['item_id' => $tom, 'qty' => 500, 'cold_room_id' => $rm, 'expiry_date' => $exp(6), 'batch_no' => "B$u"], $admin, null, $main);
http('POST', '/stock/receive', ['item_id' => $tom, 'qty' => 300, 'cold_room_id' => $rj, 'expiry_date' => $exp(5), 'batch_no' => "J$u"], $admin, null, $B1);
$cust = http('POST', '/customers', ['name' => "Big Client $u", 'code' => "BC$u"], $admin)['json']['id'] ?? null;

echo "\n== creating the order that arrived by e-mail\n";
check('a read-only user cannot create (403)', http('POST', '/client-orders', ['customer_name' => 'x', 'lines' => [['item_id' => $tom, 'qty_kg' => 1]]], $user)['status'] === 403);
check('a client name is required (422)', http('POST', '/client-orders', ['lines' => [['item_id' => $tom, 'qty_kg' => 1]]], $admin)['status'] === 422);
check('at least one line (422)', http('POST', '/client-orders', ['customer_name' => 'x', 'lines' => []], $admin)['status'] === 422);
check('quantity must be positive (422)', http('POST', '/client-orders', ['customer_name' => 'x', 'lines' => [['item_id' => $tom, 'qty_kg' => 0]]], $admin)['status'] === 422);
check('an unknown item (404)', http('POST', '/client-orders', ['customer_name' => 'x', 'lines' => [['item_id' => 999999, 'qty_kg' => 5]]], $admin)['status'] === 404);
check('the same item twice (422)', http('POST', '/client-orders', ['customer_name' => 'x', 'lines' => [['item_id' => $tom, 'qty_kg' => 5], ['item_id' => $tom, 'qty_kg' => 5]]], $admin)['status'] === 422);
check('a bad date (422)', http('POST', '/client-orders', ['customer_name' => 'x', 'due_date' => '12/11/2026', 'lines' => [['item_id' => $tom, 'qty_kg' => 5]]], $admin)['status'] === 422);
check('an unknown customer (422)', http('POST', '/client-orders', ['customer_id' => 999999, 'lines' => [['item_id' => $tom, 'qty_kg' => 5]]], $admin)['status'] === 422);
$o1 = http('POST', '/client-orders', ['customer_id' => $cust, 'due_date' => $exp(2), 'channel' => 'email', 'notes' => '1000 kg tomato', 'branch_id' => $main, 'lines' => [['item_id' => $tom, 'qty_kg' => 1000, 'note' => 'box 1 kg']]], $admin);
$O1 = (int)($o1['json']['id'] ?? 0);
check('the order is created in the main branch as a Draft with the client\'s name and a number', $o1['status'] === 201 && $o1['json']['status'] === 'Draft' && (int)$o1['json']['branch_id'] === $main && $o1['json']['customer_name'] === "Big Client $u" && strpos($o1['json']['number'], 'CO-') === 0 && $o1['json']['total_kg'] == 1000.0, json_encode($o1['json']));

echo "\n== the stock check (read-only)\n";
$ck = http('GET', "/client-orders/$O1/check", null, $admin)['json'];
check('1100 kg are free in the main branch (the Jeddah 300 kg is not counted): the 1000 kg order can be served', $ck['can_fulfil'] === true && $ck['lines'][0]['free_kg'] == 1100.0 && $ck['lines'][0]['shortage_kg'] == 0.0, json_encode($ck['lines'][0] ?? null));
check('the check lists batches oldest expiry first', ($ck['lines'][0]['batches'][0]['batch_no'] ?? '') === "A$u");
check('checking holds nothing (the order is still a Draft with 0 kg reserved)', http('GET', "/client-orders/$O1", null, $admin)['json']['reserved_kg'] == 0.0);

echo "\n== reserving\n";
$r1 = http('POST', "/client-orders/$O1/reserve", null, $admin)['json'];
check('reserved in full: status Reserved, 1000 kg held', $r1['status'] === 'Reserved' && $r1['reserved_kg'] == 1000.0, json_encode([$r1['status'], $r1['reserved_kg']]));
$byBatch = []; foreach ($r1['reservations'] as $r) $byBatch[$r['batch_no']] = $r['qty'];
check('the hold takes the earliest-expiry batch first (600 from A, 400 from B)', ($byBatch["A$u"] ?? 0) == 600.0 && ($byBatch["B$u"] ?? 0) == 400.0, json_encode($byBatch));
check('reserving again replaces the hold (not 2000 kg)', http('POST', "/client-orders/$O1/reserve", null, $admin)['json']['reserved_kg'] == 1000.0);
check('a reserved order cannot be edited (409)', http('PUT', "/client-orders/$O1", ['notes' => 'x'], $admin)['status'] === 409);
check('the physical stock did not move', (function () use ($admin, $tom, $main) { $s = 0; foreach (http('GET', "/stock/balance?item_id=$tom", null, $admin, null, $main)['json'] as $b) $s += $b['qty']; return $s == 1100.0; })());

echo "\n== a second order sees only what is still free\n";
$o2 = http('POST', '/client-orders', ['customer_name' => "Small Client $u", 'branch_id' => $main, 'lines' => [['item_id' => $tom, 'qty_kg' => 250], ['item_id' => $cuc, 'qty_kg' => 40]]], $admin)['json'];
$O2 = (int)$o2['id'];
$ck2 = http('GET', "/client-orders/$O2/check", null, $admin)['json'];
check('only 100 kg of tomato are free; cucumbers have none: cannot fulfil', $ck2['can_fulfil'] === false && $ck2['lines'][0]['free_kg'] == 100.0 && $ck2['lines'][0]['shortage_kg'] == 150.0 && $ck2['lines'][1]['free_kg'] == 0.0 && $ck2['lines'][1]['shortage_kg'] == 40.0, json_encode($ck2['lines']));
$r2 = http('POST', "/client-orders/$O2/reserve", null, $admin)['json'];
check('a partial hold: status Short, 100 kg held, 150 + 40 missing', $r2['status'] === 'Short' && $r2['reserved_kg'] == 100.0 && $r2['lines'][0]['shortage_kg'] == 150.0 && $r2['lines'][1]['shortage_kg'] == 40.0, json_encode([$r2['status'], $r2['reserved_kg']]));
check('the list shows both orders with their status and held kg', (function () use ($admin, $O1, $O2) { $l = http('GET', '/client-orders', null, $admin, null, null)['json']; $m = []; foreach ($l as $x) $m[$x['id']] = $x; return $m[$O1]['status'] === 'Reserved' && $m[$O2]['status'] === 'Short' && $m[$O2]['reserved_kg'] == 100.0; })());

echo "\n== shortage becomes a purchase request\n";
check('an order that is not short cannot request purchase (409)', http('POST', "/client-orders/$O1/request-purchase", null, $admin)['status'] === 409);
$pr = http('POST', "/client-orders/$O2/request-purchase", null, $admin);
check('a draft purchase request is made for the missing kg (tomato 150, cucumber 40)', $pr['status'] === 201 && $pr['json']['lines'] === 2, json_encode($pr['json']));
$prl = $pdo->query("SELECT l.item_id, l.qty FROM purchase_request_lines l WHERE l.purchase_request_id = " . (int)$pr['json']['purchase_request_id'])->fetchAll(PDO::FETCH_KEY_PAIR);
check('the quantities are right', (float)($prl[$tom] ?? 0) == 150.0 && (float)($prl[$cuc] ?? 0) == 40.0, json_encode($prl));
check('only once per order (409)', http('POST', "/client-orders/$O2/request-purchase", null, $admin)['status'] === 409);

echo "\n== releasing and cancelling\n";
check('release gives the stock back to others: order 2 now sees 1100 - 0 free after order 1 is released', http('POST', "/client-orders/$O1/release", null, $admin)['json']['status'] === 'Draft' && http('GET', "/client-orders/$O2/check", null, $admin)['json']['lines'][0]['free_kg'] >= 1000.0);
check('releasing a draft (409)', http('POST', "/client-orders/$O1/release", null, $admin)['status'] === 409);
check('a draft can be edited again (lines replaced, 400 kg)', http('PUT', "/client-orders/$O1", ['lines' => [['item_id' => $tom, 'qty_kg' => 400]]], $admin)['json']['total_kg'] == 400.0);
check('cancel needs a reason (422)', http('POST', "/client-orders/$O2/cancel", [], $admin)['status'] === 422);
$c2 = http('POST', "/client-orders/$O2/cancel", ['reason' => 'client changed his mind'], $admin)['json'];
check('a cancelled order holds nothing', $c2['status'] === 'Cancelled' && $c2['reserved_kg'] == 0.0 && count($c2['reservations']) === 0);
check('a cancelled order cannot be reserved or checked (409)', http('POST', "/client-orders/$O2/reserve", null, $admin)['status'] === 409 && http('GET', "/client-orders/$O2/check", null, $admin)['status'] === 409);

echo "\n== branches\n";
$oJ = http('POST', '/client-orders', ['customer_name' => "Jeddah client $u", 'lines' => [['item_id' => $tom, 'qty_kg' => 350]]], $admin, null, $B1)['json'];
check('an order made while working in Jeddah belongs to Jeddah', (int)$oJ['branch_id'] === $B1);
$rJ = http('POST', "/client-orders/{$oJ['id']}/reserve", null, $admin)['json'];
check('it reserves only Jeddah stock (300 kg) and is Short by 50', $rJ['status'] === 'Short' && $rJ['reserved_kg'] == 300.0 && $rJ['lines'][0]['shortage_kg'] == 50.0, json_encode([$rJ['status'], $rJ['reserved_kg']]));
check('orders are listed per branch and hidden from other branches (404)', in_array($oJ['id'], array_column(http('GET', '/client-orders', null, $admin, null, $B1)['json'], 'id')) && !in_array($oJ['id'], array_column(http('GET', '/client-orders', null, $admin, null, $main)['json'], 'id')) && http('GET', "/client-orders/{$oJ['id']}", null, $admin, null, $main)['status'] === 404);

echo "\n== optional approval of big orders (setting sales.approval_above_kg)\n";
http('POST', '/users', ['name' => "cm$u", 'email' => "com_$u@x.com", 'password' => 'Strong-Pass-123', 'role_id' => 2], $admin);
$mgr = login("com_$u@x.com", 'Strong-Pass-123');
check('by default (0) no approval is needed', http('GET', "/client-orders/$O1", null, $admin)['json']['needs_approval'] === false);
http('PUT', '/settings', ['sales.approval_above_kg' => 500], $admin);
$oa = http('POST', '/client-orders', ['customer_name' => "Approval client $u", 'branch_id' => $main, 'lines' => [['item_id' => $tom, 'qty_kg' => 800]]], $admin)['json'];
check('an order above the limit is flagged as needing approval', $oa['needs_approval'] === true);
check('it cannot be reserved before approval (409)', http('POST', "/client-orders/{$oa['id']}/reserve", null, $admin)['status'] === 409);
check('the person who entered it cannot approve it (403)', http('POST', "/client-orders/{$oa['id']}/approve", null, $admin)['status'] === 403);
check('a read-only user cannot approve (403)', http('POST', "/client-orders/{$oa['id']}/approve", null, $user)['status'] === 403);
$ap = http('POST', "/client-orders/{$oa['id']}/approve", null, $mgr);
check('a manager approves it', $ap['status'] === 200 && $ap['json']['needs_approval'] === false && !empty($ap['json']['approved_by']), json_encode($ap['json']['error'] ?? null));
check('approving twice (409)', http('POST', "/client-orders/{$oa['id']}/approve", null, $mgr)['status'] === 409);
check('after approval it can be reserved', http('POST', "/client-orders/{$oa['id']}/reserve", null, $admin)['json']['status'] === 'Reserved');
http('POST', "/client-orders/{$oa['id']}/release", null, $admin);
check('changing the quantities asks for a new approval', http('PUT', "/client-orders/{$oa['id']}", ['lines' => [['item_id' => $tom, 'qty_kg' => 900]]], $admin)['json']['needs_approval'] === true);
check('a small order under the limit needs none', http('POST', '/client-orders', ['customer_name' => 'tiny', 'branch_id' => $main, 'lines' => [['item_id' => $tom, 'qty_kg' => 100]]], $admin)['json']['needs_approval'] === false);
http('POST', "/client-orders/{$oa['id']}/cancel", ['reason' => 'test over'], $admin);
http('PUT', '/settings', ['sales.approval_above_kg' => 0], $admin);

echo "\n== companies stay apart\n";
$co = http('POST', '/companies', ['name_en' => "CO other $u"], $admin)['json']['id'];
$adminId = (int)$pdo->query("SELECT id FROM users WHERE email = 'admin@manzuma.com'")->fetchColumn();
$pdo->exec("INSERT IGNORE INTO user_companies (user_id, company_id) VALUES ($adminId, $co)");
check('company 2 cannot read, check, reserve or cancel company 1\'s order (404)', http('GET', "/client-orders/$O1", null, $admin, $co)['status'] === 404 && http('GET', "/client-orders/$O1/check", null, $admin, $co)['status'] === 404 && http('POST', "/client-orders/$O1/reserve", null, $admin, $co)['status'] === 404 && http('POST', "/client-orders/$O1/cancel", ['reason' => 'x'], $admin, $co)['status'] === 404);
check('and its list is empty', count(http('GET', '/client-orders', null, $admin, $co)['json']) === 0);
check('company 2 cannot order company 1\'s items (404)', http('POST', '/client-orders', ['customer_name' => 'x', 'lines' => [['item_id' => $tom, 'qty_kg' => 5]]], $admin, $co)['status'] === 404);

echo "\n------------------------------------\n$pass passed, $fail failed\n";
if ($fail) { echo "Failed: " . implode('; ', $fails) . "\n"; exit(1); }
