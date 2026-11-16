<?php
// Phase 1 of the owner's plan (suite 7): branches — a company has branches in different places; stock, rooms, orders, deliveries and
// production belong to a branch; a user may be limited to some branches.
//   php hostinger/api/tests/phase7_test.php http://127.0.0.1:8200/api  mysql-dsn user pass
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
// Phase 14: the real "user" role now means self-service only. These older suites need a general worker who can create and edit, so they use a test-only role.
$pdo->exec("INSERT IGNORE INTO roles (company_id, role_key, name_en, name_ar, is_system) VALUES (NULL, 'test_worker', 'Test worker', 'عامل اختبار', 0)");
$wk = (int)$pdo->query("SELECT id FROM roles WHERE role_key = 'test_worker' AND company_id IS NULL")->fetchColumn();
foreach (['*.view', '*.create', '*.edit'] as $pm) $pdo->exec("INSERT IGNORE INTO role_permissions (role_id, permission) VALUES ($wk, '$pm')");
foreach ([["bu_$u@x.com", $wk], ["bm_$u@x.com", 2]] as [$e, $r]) http('POST', '/users', ['name' => $e, 'email' => $e, 'password' => 'Strong-Pass-123', 'role_id' => $r], $admin);
$user = login("bu_$u@x.com", 'Strong-Pass-123'); $mgr = login("bm_$u@x.com", 'Strong-Pass-123');
$userId = (int)$pdo->query("SELECT id FROM users WHERE email = 'bu_$u@x.com'")->fetchColumn();

echo "\n== the main branch every company starts with\n";
$me = http('GET', '/me', null, $admin)['json'];
check('/me lists the branches the user may use, starting with the main one', count($me['branches']) >= 1 && (int)$me['branches'][0]['is_main'] === 1 && $me['branch_id'] === null, json_encode($me['branches'][0] ?? null));
$main = (int)$me['branches'][0]['id'];
$list = http('GET', '/company-branches', null, $admin)['json'];
check('the branch list shows how many warehouses, cold rooms and restricted users each has', isset($list[0]['warehouses']) && isset($list[0]['cold_rooms']) && isset($list[0]['restricted_users']));

echo "\n== administering branches\n";
check('a standard user cannot read or create branches (403)', http('GET', '/company-branches', null, $user)['status'] === 403 && http('POST', '/company-branches', ['code' => "X$u", 'name_en' => 'x'], $user)['status'] === 403);
check('a bad code is refused (422)', http('POST', '/company-branches', ['code' => 'a b', 'name_en' => 'x'], $admin)['status'] === 422);
check('a name is required (422)', http('POST', '/company-branches', ['code' => "N$u"], $admin)['status'] === 422);
check('a latitude above 90 is refused (422)', http('POST', '/company-branches', ['code' => "L$u", 'name_en' => 'x', 'latitude' => 120], $admin)['status'] === 422);
$b1 = http('POST', '/company-branches', ['code' => "JED$u", 'name_en' => "Jeddah $u", 'name_ar' => 'جدة', 'city' => 'Jeddah', 'address' => 'Industrial city', 'latitude' => 21.5433, 'longitude' => 39.1728, 'phone' => '0120000000'], $admin);
$B1 = (int)($b1['json']['id'] ?? 0);
check('a branch with its location is created', $b1['status'] === 201 && $B1 > 0 && (int)$b1['json']['is_main'] === 0 && $b1['json']['city'] === 'Jeddah');
check('the same branch code twice (409)', http('POST', '/company-branches', ['code' => "JED$u", 'name_en' => 'again'], $admin)['status'] === 409);
$b2 = http('POST', '/company-branches', ['code' => "DAM$u", 'name_en' => "Dammam $u", 'city' => 'Dammam'], $admin)['json'];
$B2 = (int)$b2['id'];
check('a branch can be edited', http('PUT', "/company-branches/$B2", ['city' => 'Al Khobar', 'phone' => '0130000000'], $admin)['json']['city'] === 'Al Khobar');
check('the main branch cannot be deactivated (409)', http('PUT', "/company-branches/$main", ['is_active' => false], $admin)['status'] === 409);
check('an empty branch can be deactivated and brought back', http('PUT', "/company-branches/$B2", ['is_active' => false], $admin)['json']['is_active'] == 0 && http('PUT', "/company-branches/$B2", ['is_active' => true], $admin)['json']['is_active'] == 1);

echo "\n== warehouses and cold rooms belong to a branch\n";
$w0 = http('POST', '/warehouses', ['code' => "WM$u", 'name' => "Main WH $u", 'type' => 'cold'], $admin)['json'];
check('a warehouse without a branch goes to the main branch', (int)$w0['branch_id'] === $main);
$w1 = http('POST', '/warehouses', ['code' => "WJ$u", 'name' => "Jeddah WH $u", 'type' => 'cold', 'branch_id' => $B1], $admin)['json'];
check('a warehouse can be created in a chosen branch', (int)$w1['branch_id'] === $B1);
check('another company\'s / unknown branch is refused (422)', http('POST', '/warehouses', ['code' => "WX$u", 'name' => 'x', 'type' => 'cold', 'branch_id' => 99999], $admin)['status'] === 422);
$r0 = http('POST', '/cold-rooms', ['warehouse_id' => $w0['id'], 'code' => "RM$u", 'name' => "Room main $u", 'temp_min' => 2, 'temp_max' => 8], $admin)['json'];
$r1 = http('POST', '/cold-rooms', ['warehouse_id' => $w1['id'], 'code' => "RJ$u", 'name' => "Room Jeddah $u", 'temp_min' => 2, 'temp_max' => 8, 'branch_id' => $main], $admin)['json'];
check('a cold room always takes the branch of its warehouse (a wrong branch in the request is ignored)', (int)$r0['branch_id'] === $main && (int)$r1['branch_id'] === $B1, json_encode([$r0['branch_id'], $r1['branch_id']]));
check('a warehouse with cold rooms cannot change branch (409)', http('PUT', "/warehouses/{$w1['id']}", ['branch_id' => $main], $admin)['status'] === 409);
$w2 = http('POST', '/warehouses', ['code' => "WE$u", 'name' => "Empty WH $u", 'type' => 'dry', 'branch_id' => $B1], $admin)['json'];
check('an empty warehouse can move to another branch', (int)http('PUT', "/warehouses/{$w2['id']}", ['branch_id' => $main], $admin)['json']['branch_id'] === $main);
check('a branch with an active warehouse cannot be deactivated (409)', http('PUT', "/company-branches/$B1", ['is_active' => false], $admin)['status'] === 409);

echo "\n== working in one branch: lists follow the branch\n";
$ids = fn($r) => array_column($r['json'], 'id');
$all = $ids(http('GET', '/warehouses', null, $admin)); $inB1 = $ids(http('GET', '/warehouses', null, $admin, null, $B1)); $inMain = $ids(http('GET', '/warehouses', null, $admin, null, $main));
check('without a branch the admin sees every warehouse; in a branch only that branch\'s', in_array($w0['id'], $all) && in_array($w1['id'], $all) && $inB1 === [$w1['id']] && in_array($w0['id'], $inMain) && !in_array($w1['id'], $inMain), json_encode([$inB1, $inMain]));
check('cold rooms follow the branch too', $ids(http('GET', '/cold-rooms', null, $admin, null, $B1)) === [$r1['id']] && !in_array($r1['id'], $ids(http('GET', '/cold-rooms', null, $admin, null, $main))));
check('a record of another branch is "not found" while working in this one (404)', http('GET', "/warehouses/{$w1['id']}", null, $admin, null, $main)['status'] === 404 && http('GET', "/warehouses/{$w1['id']}", null, $admin, null, $B1)['status'] === 200);
check('an unknown or foreign branch in the header is refused (403)', http('GET', '/warehouses', null, $admin, null, 99999)['status'] === 403);

echo "\n== stock follows the branch\n";
$tom = http('POST', '/items', ['code' => "T$u", 'name' => "Tomato $u", 'shelf_life_days' => 7, 'storage_temp_min' => 2, 'storage_temp_max' => 8], $admin)['json']['id'];
$exp = fn($d) => date('Y-m-d', strtotime("+$d days"));
http('POST', '/stock/receive', ['item_id' => $tom, 'qty' => 20, 'cold_room_id' => $r0['id'], 'expiry_date' => $exp(3), 'batch_no' => "M$u"], $admin, null, $main);
http('POST', '/stock/receive', ['item_id' => $tom, 'qty' => 10, 'cold_room_id' => $r1['id'], 'expiry_date' => $exp(6), 'batch_no' => "J$u"], $admin, null, $B1);
$sum = fn($rows) => round(array_sum(array_column($rows, 'qty')), 3);
check('balances: 30 kg in the whole company, 10 kg in Jeddah, 20 kg in the main branch', $sum(http('GET', "/stock/balance?item_id=$tom", null, $admin)['json']) == 30.0 && $sum(http('GET', "/stock/balance?item_id=$tom", null, $admin, null, $B1)['json']) == 10.0 && $sum(http('GET', "/stock/balance?item_id=$tom", null, $admin, null, $main)['json']) == 20.0);
check('the ledger follows the branch', count(http('GET', "/stock/ledger?item_id=$tom", null, $admin, null, $B1)['json']['data']) === 1 && count(http('GET', "/stock/ledger?item_id=$tom", null, $admin)['json']['data']) === 2);
check('"use first" follows the branch', count(array_filter(http('GET', '/stock/use-first?days=10', null, $admin, null, $B1)['json'], fn($r) => $r['item_id'] === $tom)) === 1);
check('stock cannot enter a cold room of a branch you are not working in (403)', http('POST', '/stock/receive', ['item_id' => $tom, 'qty' => 1, 'cold_room_id' => $r0['id'], 'expiry_date' => $exp(5)], $admin, null, $B1)['status'] === 403);
$fifo = http('POST', '/stock/issue', ['item_id' => $tom, 'qty' => 5], $admin, null, $B1);
check('an issue in Jeddah takes only Jeddah stock even though the main branch has an earlier expiry', $fifo['status'] === 200 && $fifo['json']['allocations'][0]['batch_no'] === "J$u", json_encode($fifo['json']));
check('an issue in Jeddah cannot use the main branch stock (409 not enough)', http('POST', '/stock/issue', ['item_id' => $tom, 'qty' => 12], $admin, null, $B1)['status'] === 409);
check('a transfer between branches is refused (422)', ($bt = http('POST', '/stock/transfer', ['batch_id' => (int)$pdo->query("SELECT id FROM item_batches WHERE batch_no = 'M$u'")->fetchColumn(), 'qty' => 1, 'from_cold_room_id' => $r0['id'], 'to_cold_room_id' => $r1['id']], $admin))['status'] === 422 && stripos($bt['json']['error'], 'between branches') !== false, json_encode($bt['json']));
http('POST', "/cold-rooms/{$r1['id']}/readings", ['temperature' => 15], $admin, null, $B1);
check('alarms follow the branch (the Jeddah room alarm is not in the main branch)', count(http('GET', '/alarms?status=open', null, $admin, null, $B1)['json']) >= 1 && count(array_filter(http('GET', '/alarms?status=open', null, $admin, null, $main)['json'], fn($a) => (int)$a['cold_room_id'] === (int)$r1['id'])) === 0);

echo "\n== orders and deliveries belong to a branch\n";
$sup = http('POST', '/suppliers', ['code' => "S$u", 'name' => "Farm $u"], $admin)['json']['id'];
$mkpo = function (?int $branch) use ($admin, $sup, $tom, $pdo, $main) {
    $po = http('POST', '/purchase-orders', ['date' => date('Y-m-d'), 'supplier_id' => $sup, 'supplier_name' => 'x', 'lines' => [['item_id' => $tom, 'item_name' => 'T', 'qty' => 50, 'rate' => 5, 'amount' => 250]]], $admin, null, $branch)['json'];
    $pdo->exec("UPDATE purchase_orders SET status = 'Approved' WHERE id = {$po['id']}");
    return $po;
};
$poJ = $mkpo($B1); $poM = $mkpo($main);
check('a purchase order made while working in a branch belongs to it', (int)$poJ['branch_id'] === $B1 && (int)$poM['branch_id'] === $main);
check('purchase orders are listed per branch', in_array($poJ['id'], $ids(http('GET', '/purchase-orders', null, $admin, null, $B1))) && !in_array($poJ['id'], $ids(http('GET', '/purchase-orders', null, $admin, null, $main))));
$dJ = http('POST', '/deliveries', ['po_id' => $poJ['id'], 'driver_name' => 'Ali'], $admin)['json'];
check('a delivery belongs to the branch of its purchase order', (int)$dJ['branch_id'] === $B1);
check('deliveries are listed per branch and hidden from other branches (404)', in_array($dJ['id'], $ids(http('GET', '/deliveries', null, $admin, null, $B1))) && !in_array($dJ['id'], $ids(http('GET', '/deliveries', null, $admin, null, $main))) && http('GET', "/deliveries/{$dJ['id']}", null, $admin, null, $main)['status'] === 404);
// walk the Jeddah delivery to "Ready" and try to store it in the main branch's room
http('POST', "/deliveries/{$dJ['id']}/weigh-in", ['gross_kg' => 1050], $admin);
http('POST', "/deliveries/{$dJ['id']}/temperature", ['truck_air_temp' => 4, 'product_temp' => 4], $admin);
http('POST', "/deliveries/{$dJ['id']}/quality", ['lines' => [['item_id' => $tom, 'grade' => 'A', 'received_kg' => 50]]], $admin);
http('POST', "/deliveries/{$dJ['id']}/weigh-out", ['tare_kg' => 1000], $admin);
$ready = http('POST', "/deliveries/{$dJ['id']}/accept", null, $mgr);
check('(setup) the Jeddah delivery is signed off', $ready['status'] === 200 && $ready['json']['status'] === 'Ready', json_encode($ready['json']['error'] ?? $ready['json']['status'] ?? null));
$line = $ready['json']['lines'][0]['id'];
$bad = http('POST', "/deliveries/{$dJ['id']}/put-away", ['lines' => [['line_id' => $line, 'cold_room_id' => $r0['id']]]], $admin);
check('putting a Jeddah delivery into a main-branch cold room is refused (422)', $bad['status'] === 422 && stripos($bad['json']['error'], 'another branch') !== false, json_encode($bad['json']));
check('putting it into a Jeddah cold room works', http('POST', "/deliveries/{$dJ['id']}/put-away", ['lines' => [['line_id' => $line, 'cold_room_id' => $r1['id']]]], $admin)['json']['status'] === 'Stored');

echo "\n== production uses its own branch's stock and rooms\n";
$fin = http('POST', '/items', ['code' => "F$u", 'name' => "Washed $u", 'shelf_life_days' => 5], $admin)['json']['id'];
$spec = http('POST', '/prep-specs', ['code' => "SP$u", 'name_en' => "Spec $u", 'input_item_id' => $tom, 'output_item_id' => $fin, 'planned_yield_pct' => 80, 'requires_wash' => false, 'max_minutes_outside' => 60], $admin)['json']['id'];
$oJ = http('POST', '/prep-orders', ['spec_id' => $spec, 'planned_input_kg' => 20], $admin, null, $B1)['json'];
check('a preparation order belongs to the branch it was planned in, and counts only that branch\'s raw material', (int)$oJ['branch_id'] === $B1 && $oJ['available_kg'] == 55.0 && http('GET', "/prep-orders/{$oJ['id']}", null, $admin, null, $main)['status'] === 404, json_encode([$oJ['branch_id'], $oJ['available_kg']]));
http('POST', "/prep-orders/{$oJ['id']}/approve", null, $mgr);
$st = http('POST', "/prep-orders/{$oJ['id']}/start", [], $admin);
check('it issues only from the branch\'s stock (the earlier-expiring main-branch batch is untouched)', $st['status'] === 200 && count($st['json']['inputs']) >= 1 && !in_array("M$u", array_column($st['json']['inputs'], 'batch_no'), true) && $sum(http('GET', "/stock/balance?item_id=$tom", null, $admin, null, $main)['json']) == 20.0, json_encode(array_column($st['json']['inputs'] ?? [], 'batch_no')));
http('POST', '/haccp-logs', ['kind' => 'wash_water', 'value' => 5, 'prep_order_id' => $oJ['id']], $admin);
$rs = http('POST', "/prep-orders/{$oJ['id']}/results", ['outputs' => [['grade' => 'A', 'kg' => 16]], 'waste' => [['reason_code' => 'trim', 'kg' => 4, 'decision' => 'destroy']]], $admin);
$badSo = http('POST', "/prep-orders/{$oJ['id']}/sign-off", ['store' => [['grade' => 'A', 'cold_room_id' => $r0['id']]]], $mgr);
check('the finished goods cannot go to another branch\'s cold room (422)', $badSo['status'] === 422 && stripos($badSo['json']['error'], 'another branch') !== false, json_encode($badSo['json']));
check('into a room of the order\'s own branch they can', http('POST', "/prep-orders/{$oJ['id']}/sign-off", ['store' => [['grade' => 'A', 'cold_room_id' => $r1['id']]]], $mgr)['json']['status'] === 'Completed');

echo "\n== limiting a user to some branches\n";
check('a standard user cannot change branch access (403)', http('PUT', "/branch-access/$userId", ['branch_ids' => [$B1]], $user)['status'] === 403);
check('branch ids must be active branches of the company (422)', http('PUT', "/branch-access/$userId", ['branch_ids' => [99999]], $admin)['status'] === 422);
check('branch_ids must be a list (422)', http('PUT', "/branch-access/$userId", ['branch_ids' => 'x'], $admin)['status'] === 422);
check('an unknown user (404)', http('PUT', '/branch-access/999999', ['branch_ids' => [$B1]], $admin)['status'] === 404);
$acc = http('PUT', "/branch-access/$userId", ['branch_ids' => [$B1]], $admin);
check('the user is now limited to Jeddah', $acc['status'] === 200 && $acc['json']['branch_ids'] === [$B1]);
$ul = array_values(array_filter(http('GET', '/branch-access', null, $admin)['json'], fn($x) => (int)$x['id'] === $userId))[0];
check('the access list shows who is limited to what', $ul['branch_ids'] === [$B1]);
$ume = http('GET', '/me', null, $user)['json'];
check('/me for the limited user lists only his branch and selects it', count($ume['branches']) === 1 && (int)$ume['branches'][0]['id'] === $B1);
check('without choosing, the limited user sees only his branch\'s warehouses', $ids(http('GET', '/warehouses', null, $user)) === [$w1['id']]);
check('he cannot enter the main branch (403)', http('GET', '/warehouses', null, $user, null, $main)['status'] === 403);
check('he sees only his branch\'s stock', $sum(http('GET', "/stock/balance?item_id=$tom", null, $user)['json']) == $sum(http('GET', "/stock/balance?item_id=$tom", null, $admin, null, $B1)['json']));
check('he cannot receive into a main-branch room (403)', http('POST', '/stock/receive', ['item_id' => $tom, 'qty' => 1, 'cold_room_id' => $r0['id'], 'expiry_date' => $exp(4)], $user)['status'] === 403);
check('the admin is not limited by it', count(http('GET', '/me', null, $admin)['json']['branches']) >= 3);
check('an empty list removes the restriction', http('PUT', "/branch-access/$userId", ['branch_ids' => []], $admin)['json']['branch_ids'] === [] && count(http('GET', '/me', null, $user)['json']['branches']) >= 3);

echo "\n== companies stay apart\n";
$co = http('POST', '/companies', ['name_en' => "Other $u"], $admin)['json']['id'];
$adminId = (int)$pdo->query("SELECT id FROM users WHERE email = 'admin@manzuma.com'")->fetchColumn();
$pdo->exec("INSERT IGNORE INTO user_companies (user_id, company_id) VALUES ($adminId, $co)");
$cb = http('GET', '/company-branches', null, $admin, $co)['json'];
check('a new company gets its own main branch', count($cb) === 1 && (int)$cb[0]['is_main'] === 1 && (int)$cb[0]['id'] !== $main);
check('company 2 does not see the branches of company 1, and cannot enter them (404 / 403)', !in_array($B1, array_column($cb, 'id')) && http('PUT', "/company-branches/$B1", ['city' => 'x'], $admin, $co)['status'] === 404 && http('GET', '/warehouses', null, $admin, $co, $B1)['status'] === 403);
check('company 2 cannot be given a branch of company 1 for a warehouse (422)', http('POST', '/warehouses', ['code' => "WZ$u", 'name' => 'x', 'type' => 'cold', 'branch_id' => $B1], $admin, $co)['status'] === 422);

echo "\n------------------------------------\n$pass passed, $fail failed\n";
if ($fail) { echo "Failed: " . implode('; ', $fails) . "\n"; exit(1); }
