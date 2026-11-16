<?php
// Phase 4 (suite 10): fleet (own / rented), gates, entry permit, store pre-approval of space.
//   php hostinger/api/tests/phase10_test.php http://127.0.0.1:8200/api  mysql-dsn user pass
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
$role = fn(string $k) => (int)$pdo->query("SELECT id FROM roles WHERE role_key = '$k' AND company_id IS NULL")->fetchColumn();
foreach ([["fb_$u@x.com", $wk], ["fs_$u@x.com", $role('storekeeper')], ["fg_$u@x.com", $role('gate_officer')], ["fv_$u@x.com", 4]] as [$e, $r]) http('POST', '/users', ['name' => $e, 'email' => $e, 'password' => 'Strong-Pass-123', 'role_id' => $r], $admin);
$buyer = login("fb_$u@x.com", 'Strong-Pass-123'); $store = login("fs_$u@x.com", 'Strong-Pass-123'); $gate = login("fg_$u@x.com", 'Strong-Pass-123'); $viewer = login("fv_$u@x.com", 'Strong-Pass-123');
$set = fn(array $s) => http('PUT', '/settings', $s, $admin);
$set(['fleet.require_entry_permit' => false, 'fleet.require_store_approval' => true, 'fleet.space_check_strict' => false, 'fleet.allow_double_booking' => false]);
$main = (int)http('GET', '/me', null, $admin)['json']['branches'][0]['id'];
$B1 = (int)http('POST', '/company-branches', ['code' => "FJ$u", 'name_en' => "Fleet Jeddah $u"], $admin)['json']['id'];
$sup = http('POST', '/suppliers', ['code' => "FS$u", 'name' => "Farm $u"], $admin)['json']['id'];
$tom = http('POST', '/items', ['code' => "FT$u", 'name' => "FL Tomato $u", 'shelf_life_days' => 7], $admin)['json']['id'];
$B2 = (int)http('POST', '/company-branches', ['code' => "FK$u", 'name_en' => "Fleet Dammam $u"], $admin)['json']['id'];
$wh = http('POST', '/warehouses', ['code' => "FW$u", 'name' => "Fleet WH $u", 'type' => 'cold', 'branch_id' => $B1], $admin)['json']['id'];
$room = http('POST', '/cold-rooms', ['warehouse_id' => $wh, 'code' => "FR$u", 'name' => "Fleet room $u", 'temp_min' => 2, 'temp_max' => 8, 'capacity_kg' => 2000], $admin)['json']['id'];
http('POST', '/stock/receive', ['item_id' => $tom, 'qty' => 400, 'cold_room_id' => $room, 'expiry_date' => date('Y-m-d', strtotime('+5 days')), 'batch_no' => "F$u"], $admin, null, $B1);
$po = function (float $kg, string $status = 'Approved', ?int $branch = null) use ($admin, $sup, $tom, $pdo, $B1) {
    $branch = $branch ?? $B1;
    $p = http('POST', '/purchase-orders', ['date' => date('Y-m-d'), 'supplier_id' => $sup, 'supplier_name' => 'x', 'lines' => [['item_id' => $tom, 'item_name' => 'T', 'qty' => $kg, 'rate' => 5, 'amount' => $kg * 5]]], $admin, null, $branch)['json'];
    $pdo->exec("UPDATE purchase_orders SET status = '$status' WHERE id = {$p['id']}");
    return (int)$p['id'];
};

echo "\n== trucks (own and rented) and gates\n";
check('a read-only user cannot add a truck (403)', http('POST', '/vehicles', ['plate' => "V$u", 'kind' => 'own'], $viewer)['status'] === 403);
check('the plate is required (422)', http('POST', '/vehicles', ['kind' => 'own'], $admin)['status'] === 422);
check('the kind must be own or rented (422)', http('POST', '/vehicles', ['plate' => "X$u", 'kind' => 'borrowed'], $admin)['status'] === 422);
check('a rented truck needs the rental company (422)', http('POST', '/vehicles', ['plate' => "R0$u", 'kind' => 'rented'], $admin)['status'] === 422);
$own = http('POST', '/vehicles', ['plate' => "own $u", 'kind' => 'own', 'capacity_kg' => 8000, 'refrigerated' => true, 'default_driver' => 'Ali Hassan', 'default_driver_id_no' => '1010101010'], $admin);
$OWN = (int)($own['json']['id'] ?? 0);
check('an own truck is added; the plate is stored in capitals', $own['status'] === 201 && $own['json']['plate'] === "OWN $u", json_encode($own['json']));
$ren = http('POST', '/vehicles', ['plate' => "REN $u", 'kind' => 'rented', 'rental_company' => 'Fast Cold Trucks', 'capacity_kg' => 5000], $admin);
$REN = (int)($ren['json']['id'] ?? 0);
check('a rented truck with its rental company is added', $ren['status'] === 201 && $ren['json']['kind'] === 'rented');
check('the same plate twice is refused', in_array(http('POST', '/vehicles', ['plate' => "own $u", 'kind' => 'own'], $admin)['status'], [409, 422], true));
check('a bad capacity (422)', http('POST', '/vehicles', ['plate' => "C$u", 'kind' => 'own', 'capacity_kg' => -5], $admin)['status'] === 422);
$g1 = http('POST', '/gates', ['code' => "G1$u", 'name' => "Gate 1 $u", 'name_ar' => 'البوابة ١', 'branch_id' => $B1], $admin);
$G1 = (int)($g1['json']['id'] ?? 0);
check('a gate is created in its branch', $g1['status'] === 201 && (int)$g1['json']['branch_id'] === $B1);
check('a gate without a branch goes to the main branch', (int)http('POST', '/gates', ['code' => "GD$u", 'name' => "Default gate $u"], $admin)['json']['branch_id'] === $main);
$gj = http('POST', '/gates', ['code' => "GJ$u", 'name' => "Main gate $u", 'branch_id' => $main], $admin)['json'];
check('a gate name and code are required (422)', http('POST', '/gates', ['code' => 'x'], $admin)['status'] === 422);

echo "\n== requesting an entry permit\n";
$P1po = $po(1000);
check('a read-only user cannot request (403)', http('POST', '/entry-permits', ['po_id' => $P1po, 'vehicle_id' => $OWN], $viewer)['status'] === 403);
check('an order that is not approved (409)', http('POST', '/entry-permits', ['po_id' => $po(100, 'Draft'), 'vehicle_id' => $OWN], $buyer)['status'] === 409);
check('an unknown order (404)', http('POST', '/entry-permits', ['po_id' => 999999, 'vehicle_id' => $OWN], $buyer)['status'] === 404);
check('a plate and a driver are required without a vehicle (422)', http('POST', '/entry-permits', ['po_id' => $P1po], $buyer)['status'] === 422 && http('POST', '/entry-permits', ['po_id' => $P1po, 'vehicle_plate' => 'ABC 123'], $buyer)['status'] === 422);
$e1 = http('POST', '/entry-permits', ['po_id' => $P1po, 'vehicle_id' => $OWN], $buyer);
$E1 = (int)($e1['json']['id'] ?? 0);
check('the permit is Requested; the plate and driver come from the truck; the expected load is the order\'s 1,000 kg', $e1['status'] === 201 && $e1['json']['status'] === 'Requested' && $e1['json']['vehicle_plate'] === "OWN $u" && $e1['json']['driver_name'] === 'Ali Hassan' && $e1['json']['expected_kg'] == 1000.0 && strpos($e1['json']['number'], 'EP-') === 0, json_encode($e1['json']['error'] ?? $e1['json']['expected_kg'] ?? null));
check('the same truck cannot be on two open permits (409)', http('POST', '/entry-permits', ['po_id' => $P1po, 'vehicle_id' => $OWN], $buyer)['status'] === 409);
$vl = http('GET', '/vehicles', null, $admin)['json']; $byId = []; foreach ($vl as $v) $byId[$v['id']] = $v;
check('the truck list shows the own truck busy and the rented one free', $byId[$OWN]['busy'] === true && $byId[$REN]['busy'] === false && $byId[$OWN]['open_permit'] === $e1['json']['number']);
check('the permit detail shows the space check', ($e1['json']['space']['free_kg'] ?? null) == 1600.0, json_encode($e1['json']['space'] ?? null));

echo "\n== the store pre-approves that there is space\n";
check('the requester cannot decide on the space (403: no store right)', http('POST', "/entry-permits/$E1/store-approve", [], $buyer)['status'] === 403);
check('rejecting needs a reason (422)', http('POST', "/entry-permits/$E1/store-reject", [], $store)['status'] === 422);
check('the gate cannot issue before the store approves (409)', http('POST', "/entry-permits/$E1/issue", ['gate_id' => $G1], $gate)['status'] === 409);
$ap = http('POST', "/entry-permits/$E1/store-approve", [], $store);
check('the store approves: 1,600 kg were free (2,000 capacity - 400 held)', $ap['status'] === 200 && $ap['json']['status'] === 'Store_Approved' && $ap['json']['store_free_kg'] == 1600.0 && !empty($ap['json']['store_by']), json_encode($ap['json']['error'] ?? null));
check('a decision is taken once (409)', http('POST', "/entry-permits/$E1/store-approve", [], $store)['status'] === 409);
check('the free space now counts the promised load: 600 kg', http('GET', "/entry-permits/space?branch_id=$B1", null, $admin)['json']['free_kg'] == 600.0, json_encode(http('GET', "/entry-permits/space?branch_id=$B1", null, $admin)['json']));
$e2r = http('POST', '/entry-permits', ['po_id' => $po(5000), 'vehicle_id' => $REN, 'driver_name' => 'Rental driver'], $buyer);
$E2 = (int)($e2r['json']['id'] ?? 0);
check('(setup) a 5,000 kg permit on the rented truck is requested', $E2 > 0, json_encode($e2r['json']));
$bigNo = http('POST', "/entry-permits/$E2/store-approve", [], $store);
check('a load bigger than the free space needs a written reason (422)', $bigNo['status'] === 422 && stripos($bigNo['json']['error'], 'free space') !== false, json_encode($bigNo['json']));
$set(['fleet.space_check_strict' => true]);
check('with the strict setting it is refused outright (409)', http('POST', "/entry-permits/$E2/store-approve", ['note' => 'we will make room'], $store)['status'] === 409);
$set(['fleet.space_check_strict' => false]);
check('with a reason the store may still approve', http('POST', "/entry-permits/$E2/store-approve", ['note' => 'we will empty room 3 tonight'], $store)['json']['status'] === 'Store_Approved');
$E3 = (int)http('POST', '/entry-permits', ['po_id' => $po(300), 'vehicle_plate' => "HIRE $u", 'driver_name' => 'Said'], $buyer)['json']['id'];
$rj = http('POST', "/entry-permits/$E3/store-reject", ['reason' => 'room under maintenance'], $store)['json'];
check('the store can refuse with a reason', $rj['status'] === 'Rejected' && $rj['store_note'] === 'room under maintenance');

echo "\n== the gate issues the permit and the truck enters\n";
check('the store keeper cannot issue (403)', http('POST', "/entry-permits/$E1/issue", ['gate_id' => $G1], $store)['status'] === 403);
check('a gate is required (422)', http('POST', "/entry-permits/$E1/issue", [], $gate)['status'] === 422);
check('a gate of another branch is refused (422)', http('POST', "/entry-permits/$E1/issue", ['gate_id' => $gj['id']], $gate)['status'] === 422);
$is = http('POST', "/entry-permits/$E1/issue", ['gate_id' => $G1], $gate);
check('the gate officer issues the permit on gate 1', $is['status'] === 200 && $is['json']['status'] === 'Issued' && (int)$is['json']['gate_id'] === $G1 && $is['json']['gate']['code'] === "G1$u", json_encode($is['json']['error'] ?? null));
check('the truck cannot enter before the permit is issued (409)', http('POST', "/entry-permits/$E2/enter", [], $gate)['status'] === 409);
$en = http('POST', "/entry-permits/$E1/enter", ['gross_kg' => 9500], $gate);
check('the truck enters: the permit is Entered and a delivery was registered', $en['status'] === 200 && $en['json']['status'] === 'Entered' && !empty($en['json']['delivery_id']) && !empty($en['json']['delivery']['number']), json_encode($en['json']['error'] ?? null));
$dl = http('GET', '/deliveries/' . (int)$en['json']['delivery_id'], null, $admin)['json'];
check('the delivery carries the permit\'s plate and driver and starts at Arrived with the gross weight', $dl['status'] === 'Arrived' && $dl['vehicle_plate'] === "OWN $u" && $dl['driver_name'] === 'Ali Hassan' && $dl['gross_kg'] == 9500.0 && (int)$dl['purchase_order_id'] === $P1po, json_encode([$dl['status'], $dl['vehicle_plate'] ?? null]));
check('a permit lets a truck in once (409)', http('POST', "/entry-permits/$E1/enter", [], $gate)['status'] === 409);
check('once it entered, the delivery is the truck\'s busy mark, then free after storing', (function () use ($admin, $OWN) { foreach (http('GET', '/vehicles', null, $admin)['json'] as $v) if ($v['id'] == $OWN) return $v['busy'] === true && $v['open_delivery'] !== null; return false; })());

echo "\n== the company can require a permit at the gate (setting)\n";
$PX = $po(200);
$set(['fleet.require_entry_permit' => true]);
$noPermit = http('POST', '/deliveries', ['po_id' => $PX, 'driver_name' => 'Walk-in', 'vehicle_plate' => 'NOPE 1'], $admin);
check('a truck without a permit cannot be registered (409)', $noPermit['status'] === 409 && stripos($noPermit['json']['error'], 'entry permit') !== false, json_encode($noPermit['json']));
$E4 = (int)http('POST', '/entry-permits', ['po_id' => $po(200), 'vehicle_plate' => "HIRE2 $u", 'driver_name' => 'Omar'], $buyer)['json']['id'];
http("POST", "/entry-permits/$E4/store-approve", ["note" => "space arranged"], $store); http('POST', "/entry-permits/$E4/issue", ['gate_id' => $G1], $gate);
check('a permit for ANOTHER order does not let it in (409)', http('POST', '/deliveries', ['po_id' => $PX, 'driver_name' => 'x', 'entry_permit_id' => $E4], $admin)['status'] === 409);
$ok = http('POST', '/deliveries', ['po_id' => (int)$pdo->query("SELECT po_id FROM entry_permits WHERE id = $E4")->fetchColumn(), 'driver_name' => 'ignored', 'vehicle_plate' => 'IGNORED', 'entry_permit_id' => $E4], $admin);
check('with its own permit the arrival works, and the permit\'s plate/driver win', $ok['status'] === 201 && $ok['json']['vehicle_plate'] === "HIRE2 $u" && $ok['json']['driver_name'] === 'Omar' && $pdo->query("SELECT status FROM entry_permits WHERE id = $E4")->fetchColumn() === 'Entered', json_encode([$ok['status'], $ok['json']['error'] ?? $ok['json']['vehicle_plate'] ?? null, $ok['json']['driver_name'] ?? null]));
check('a cancelled/entered permit cannot be reused (409)', http('POST', '/deliveries', ['po_id' => (int)$pdo->query("SELECT po_id FROM entry_permits WHERE id = $E4")->fetchColumn(), 'driver_name' => 'x', 'entry_permit_id' => $E4], $admin)['status'] === 409);
$set(['fleet.require_entry_permit' => false]);
check('with the rule off, the old direct arrival still works', http('POST', '/deliveries', ['po_id' => $PX, 'driver_name' => 'Walk-in', 'vehicle_plate' => 'NOPE 1'], $admin)['status'] === 201);
$set(['fleet.require_store_approval' => false]);
$E5 = (int)http('POST', '/entry-permits', ['po_id' => $po(120), 'vehicle_plate' => "FAST $u", 'driver_name' => 'Nasser'], $buyer)['json']['id'];
check('with the store step switched off the gate can issue a requested permit', http('POST', "/entry-permits/$E5/issue", ['gate_id' => $G1], $gate)['json']['status'] === 'Issued');
$set(['fleet.require_store_approval' => true]);

echo "\n== cancelling, double booking, branches, companies\n";
check('cancelling needs a reason (422)', http('POST', "/entry-permits/$E5/cancel", [], $gate)['status'] === 422);
check('a read-only user cannot cancel (403)', http('POST', "/entry-permits/$E5/cancel", ['reason' => 'x'], $viewer)['status'] === 403);
check('the gate cancels an issued permit', http('POST', "/entry-permits/$E5/cancel", ['reason' => 'truck broke down'], $gate)['json']['status'] === 'Cancelled');
check('an entered permit cannot be cancelled (409)', http('POST', "/entry-permits/$E1/cancel", ['reason' => 'x'], $admin)['status'] === 409);
$set(['fleet.allow_double_booking' => true]);
$Ea = http('POST', '/entry-permits', ['po_id' => $po(50), 'vehicle_id' => $REN, 'driver_name' => 'Rental driver'], $buyer); $Eb = http('POST', '/entry-permits', ['po_id' => $po(50), 'vehicle_id' => $REN, 'driver_name' => 'Rental driver'], $buyer);
check('with double booking allowed, one truck may be on two permits', $Eb['status'] === 201 || $Ea['status'] === 409);
$set(['fleet.allow_double_booking' => false]);
$PJ = $po(100, 'Approved', $B2);
$EJ = (int)http('POST', '/entry-permits', ['po_id' => $PJ, 'vehicle_plate' => "DAM $u", 'driver_name' => 'Fahd'], $admin, null, $B2)['json']['id'];
check('a permit for a Dammam order belongs to Dammam and is hidden from other branches (404)', http('GET', "/entry-permits/$EJ", null, $admin, null, $B2)['status'] === 200 && http('GET', "/entry-permits/$EJ", null, $admin, null, $B1)['status'] === 404);
$EJs = http('GET', "/entry-permits/$EJ", null, $admin, null, $B2)['json'];
check('its space check counts only Dammam rooms (none yet: 0 kg free)', ($EJs['space']['free_kg'] ?? -1) == 0.0);
$co = http('POST', '/companies', ['name_en' => "FL other $u"], $admin)['json']['id'];
$adminId = (int)$pdo->query("SELECT id FROM users WHERE email = 'admin@manzuma.com'")->fetchColumn();
$pdo->exec("INSERT IGNORE INTO user_companies (user_id, company_id) VALUES ($adminId, $co)");
check('company 2 cannot see, decide on or issue company 1\'s permits (404), and has no trucks or gates of company 1', http('GET', "/entry-permits/$E2", null, $admin, $co)['status'] === 404 && http('POST', "/entry-permits/$E2/issue", ['gate_id' => $G1], $admin, $co)['status'] === 404 && count(http('GET', '/entry-permits', null, $admin, $co)['json']) === 0 && count(http('GET', '/vehicles', null, $admin, $co)['json']) === 0 && count(http('GET', '/gates', null, $admin, $co)['json']) === 0);
check('company 2 cannot use company 1\'s truck or order (404)', http('POST', '/entry-permits', ['po_id' => $P1po, 'vehicle_id' => $OWN], $admin, $co)['status'] === 404);
check('company 2 gets the fleet settings', (int)$pdo->query("SELECT COUNT(*) FROM settings WHERE company_id = $co AND setting_key LIKE 'fleet.%'")->fetchColumn() === 4);

echo "\n------------------------------------\n$pass passed, $fail failed\n";
if ($fail) { echo "Failed: " . implode('; ', $fails) . "\n"; exit(1); }
