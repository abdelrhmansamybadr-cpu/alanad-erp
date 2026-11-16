<?php
// Phase 8 (suite 14): packing — box (1 / 2 kg) -> carton (40) -> pallet, pack specs per client, stock held for client orders, traceability.
//   php hostinger/api/tests/phase14_test.php http://127.0.0.1:8200/api  mysql-dsn user pass
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
foreach ([["pp_$u@x.com", $role('packer')], ["ph_$u@x.com", $role('head_of_preparation')], ["pv_$u@x.com", 4]] as [$e, $r]) http('POST', '/users', ['name' => $e, 'email' => $e, 'password' => 'Strong-Pass-123', 'role_id' => $r], $admin);
$packer = login("pp_$u@x.com", 'Strong-Pass-123'); $head = login("ph_$u@x.com", 'Strong-Pass-123'); $viewer = login("pv_$u@x.com", 'Strong-Pass-123');
$set = fn(array $s) => http('PUT', '/settings', $s, $admin);
$set(['packing.box_sizes_kg' => '1,2', 'packing.boxes_per_carton' => 40, 'packing.cartons_per_pallet' => 40, 'packing.balance_tolerance_pct' => 2, 'packing.default_grade' => 'A', 'sales.approval_above_kg' => 0]);
$main = (int)http('GET', '/me', null, $admin)['json']['branches'][0]['id'];
$B1 = (int)http('POST', '/company-branches', ['code' => "PB$u", 'name_en' => "Pack Jeddah $u"], $admin)['json']['id'];
$exp = fn($d) => date('Y-m-d', strtotime("+$d days"));
$tom = http('POST', '/items', ['code' => "PT$u", 'name' => "PK Tomato $u", 'shelf_life_days' => 7], $admin)['json']['id'];
$wh = http('POST', '/warehouses', ['code' => "PW$u", 'name' => "PK WH $u", 'type' => 'cold', 'branch_id' => $B1], $admin)['json']['id'];
$room = http('POST', '/cold-rooms', ['warehouse_id' => $wh, 'code' => "PR$u", 'name' => "PK room $u", 'temp_min' => 2, 'temp_max' => 8], $admin)['json']['id'];
$recv = fn(float $kg, int $days, string $grade, string $no) => http('POST', '/stock/receive', ['item_id' => $tom, 'qty' => $kg, 'cold_room_id' => $room, 'expiry_date' => $exp($days), 'batch_no' => "$no$u", 'grade' => $grade], $GLOBALS['admin'], null, $GLOBALS['B1']);
$recv(1000, 3, 'A', 'A1'); $recv(500, 6, 'A', 'A2'); $recv(300, 4, 'B', 'B1');
$cust = http('POST', '/customers', ['name' => "Pack Client $u", 'code' => "PC$u"], $admin)['json']['id'];
$cust2 = http('POST', '/customers', ['name' => "Pallet Client $u", 'code' => "PD$u"], $admin)['json']['id'];
$batch = fn(string $no) => (int)$pdo->query("SELECT id FROM item_batches WHERE batch_no = '$no$u'")->fetchColumn();
$bal = function (?string $grade = null) use ($admin, $tom, $B1) { $s = 0; foreach (http('GET', "/stock/balance?item_id=$tom", null, $admin, null, $B1)['json'] as $b) if ($grade === null || $b['grade'] === $grade) $s += $b['qty']; return round($s, 3); };

echo "\n== pack specifications\n";
check('a read-only user cannot add a specification (403)', http('POST', '/pack-specs', ['item_id' => $tom, 'customer_id' => $cust], $viewer)['status'] === 403);
check('the item must exist (404)', http('POST', '/pack-specs', ['item_id' => 999999], $head)['status'] === 404);
check('box sizes need at least one number (422)', http('POST', '/pack-specs', ['item_id' => $tom, 'box_kg_options' => 'big'], $head)['status'] === 422);
check('boxes per carton must be a whole number above zero (422)', http('POST', '/pack-specs', ['item_id' => $tom, 'boxes_per_carton' => 0], $head)['status'] === 422);
$sp1 = http('POST', '/pack-specs', ['item_id' => $tom, 'customer_id' => $cust, 'box_kg_options' => '2, 1', 'default_box_kg' => 1, 'boxes_per_carton' => 40, 'cartons_per_pallet' => 24, 'label_note' => 'client logo on the carton'], $head);
check('the head of preparation sets a specification for a client (sizes are cleaned up: "1,2")', $sp1['status'] === 201 && (int)$sp1['json']['boxes_per_carton'] === 40 && (int)$sp1['json']['cartons_per_pallet'] === 24, json_encode($sp1['json']['error'] ?? null));
$sp2 = http('POST', '/pack-specs', ['item_id' => $tom, 'customer_id' => $cust2, 'box_kg_options' => '1', 'boxes_per_carton' => 10, 'cartons_per_pallet' => 3], $head);
check('another client gets small pallets: 10 boxes per carton, 3 cartons per pallet, only 1 kg boxes', $sp2['status'] === 201);

echo "\n== a pack order for a client order\n";
$co1 = http('POST', '/client-orders', ['customer_id' => $cust, 'branch_id' => $B1, 'due_date' => $exp(2), 'lines' => [['item_id' => $tom, 'qty_kg' => 400]]], $admin)['json']['id'];
$co2 = http('POST', '/client-orders', ['customer_name' => "Other client $u", 'branch_id' => $B1, 'lines' => [['item_id' => $tom, 'qty_kg' => 800]]], $admin)['json']['id'];
$r1 = http('POST', "/client-orders/$co1/reserve", null, $admin)['json']; $r2 = http('POST', "/client-orders/$co2/reserve", null, $admin)['json'];
check('(setup) order 1 holds 400 kg of the earliest batch; order 2 holds the rest of it and some of the next', $r1['status'] === 'Reserved' && $r2['status'] === 'Reserved');
check('a read-only user cannot create a pack order (403)', http('POST', '/pack-orders', ['client_order_id' => $co1], $viewer)['status'] === 403);
check('an unknown client order (404)', http('POST', '/pack-orders', ['client_order_id' => 999999], $packer)['status'] === 404);
check('required kg must be positive (422)', http('POST', '/pack-orders', ['item_id' => $tom, 'required_kg' => 0], $packer)['status'] === 422);
check('a bad grade (422)', http('POST', '/pack-orders', ['item_id' => $tom, 'required_kg' => 10, 'grade' => 'Z'], $packer)['status'] === 422);
$k1 = http('POST', '/pack-orders', ['client_order_id' => $co1], $packer);
$K1 = (int)($k1['json']['id'] ?? 0);
check('from the client order the kg (400), the client, the date and the client\'s specification are filled in', $k1['status'] === 201 && $k1['json']['required_kg'] == 400.0 && strpos($k1['json']['customer_name'], 'Pack Client') === 0 && (int)$k1['json']['cartons_per_pallet'] === 24 && $k1['json']['status'] === 'Draft' && $k1['json']['grade'] === 'A' && !empty($k1['json']['due_date']) && $k1['json']['spec']['sizes'] == [1.0, 2.0], json_encode($k1['json']['error'] ?? $k1['json']['spec'] ?? null));
check('the packer cannot decide the box size (403)', http('POST', "/pack-orders/$K1/decide", ['box_kg' => 2], $packer)['status'] === 403);
check('packing cannot start before the box size is decided (409)', http('POST', "/pack-orders/$K1/start", null, $packer)['status'] === 409);
check('a size outside the specification is refused (422)', http('POST', "/pack-orders/$K1/decide", ['box_kg' => 5], $head)['status'] === 422);
$d1 = http('POST', "/pack-orders/$K1/decide", ['box_kg' => 2], $head);
check('the head of preparation chooses 2 kg boxes: 200 boxes planned, status Ready', $d1['status'] === 200 && $d1['json']['status'] === 'Ready' && $d1['json']['box_kg'] == 2.0 && (int)$d1['json']['planned_boxes'] === 200 && !empty($d1['json']['decided_by']), json_encode($d1['json']['error'] ?? null));
check('the head cannot start the packing himself (403: no packer right)', http('POST', "/pack-orders/$K1/start", null, $head)['status'] === 403);

echo "\n== the packer takes the stock out\n";
$before = $bal('A');
$s1 = http('POST', "/pack-orders/$K1/start", null, $packer);
check('start: 400 kg of grade A are issued and the order is In_Progress', $s1['status'] === 200 && $s1['json']['status'] === 'In_Progress' && $s1['json']['issued_kg'] == 400.0 && $bal('A') == $before - 400.0, json_encode($s1['json']['error'] ?? null));
check('they came from the batch that was held for this client order (A1)', count($s1['json']['batches']) === 1 && (int)$s1['json']['batches'][0]['batch_id'] === $batch('A1') && $s1['json']['batches'][0]['kg'] == 400.0, json_encode($s1['json']['batches'] ?? null));
check('the client order\'s hold is released (it has been taken out)', http('GET', "/client-orders/$co1", null, $admin, null, $B1)['json']['reserved_kg'] == 0.0);
check('the other order still holds its 800 kg', http('GET', "/client-orders/$co2", null, $admin, null, $B1)['json']['reserved_kg'] == 800.0);
check('the batch cost is carried on the pack order', array_key_exists('cost_total', $s1['json']));
check('starting twice (409)', http('POST', "/pack-orders/$K1/start", null, $packer)['status'] === 409);
check('a pack order that is under way cannot be cancelled (409)', http('POST', "/pack-orders/$K1/cancel", ['reason' => 'x'], $packer)['status'] === 409);

echo "\n== boxes, cartons and pallets\n";
check('the head cannot pack (403)', http('POST', "/pack-orders/$K1/pack", ['boxes_packed' => 200], $head)['status'] === 403);
check('boxes must be a whole number (422)', http('POST', "/pack-orders/$K1/pack", ['boxes_packed' => 12.5], $packer)['status'] === 422);
$bad = http('POST', "/pack-orders/$K1/pack", ['boxes_packed' => 150], $packer);
check('150 boxes x 2 kg does not match the 400 kg issued (422)', $bad['status'] === 422 && stripos($bad['json']['error'], 'add up') !== false, json_encode($bad['json']));
$pk = http('POST', "/pack-orders/$K1/pack", ['boxes_packed' => 200, 'loss_kg' => 0], $packer);
check('200 boxes: 5 full cartons of 40 on 1 pallet; status Packed', $pk['status'] === 200 && $pk['json']['status'] === 'Packed' && (int)$pk['json']['cartons'] === 5 && (int)$pk['json']['pallets'] === 1 && (int)$pk['json']['boxes_packed'] === 200, json_encode($pk['json']['error'] ?? null));
$cartons = array_values(array_filter($pk['json']['units'], fn($x) => $x['kind'] === 'carton')); $pallets = array_values(array_filter($pk['json']['units'], fn($x) => $x['kind'] === 'pallet'));
check('every carton holds 40 boxes (80 kg) and has a code; the pallet holds 200 boxes (400 kg)', count($cartons) === 5 && $cartons[0]['boxes'] === 40 && $cartons[0]['kg'] == 80.0 && strpos($cartons[0]['code'], 'CTN-PK-') === 0 && $pallets[0]['boxes'] === 200 && $pallets[0]['kg'] == 400.0 && strpos($pallets[0]['code'], 'PAL-PK-') === 0 && $cartons[0]['parent_id'] === $pallets[0]['id'], json_encode([$cartons[0] ?? null]));
check('a packed order cannot be packed again (409)', http('POST', "/pack-orders/$K1/pack", ['boxes_packed' => 200], $packer)['status'] === 409);

echo "\n== partial carton, several pallets, other grades\n";
$k2 = (int)http('POST', '/pack-orders', ['item_id' => $tom, 'required_kg' => 250, 'customer_name' => 'Walk-in', 'branch_id' => $B1, 'notes' => 'no client order'], $packer)['json']['id'];
http('POST', "/pack-orders/$k2/decide", ['box_kg' => 1], $head);
$s2 = http('POST', "/pack-orders/$k2/start", null, $packer);
check('another order needs 250 kg of grade A; A1 is fully held for order 2, so it is taken from A2', $s2['status'] === 200 && count($s2['json']['batches']) === 1 && (int)$s2['json']['batches'][0]['batch_id'] === $batch('A2'), json_encode($s2['json']['error'] ?? $s2['json']['batches'] ?? null));
$p2 = http('POST', "/pack-orders/$k2/pack", ['boxes_packed' => 250], $packer)['json'];
$c2 = array_values(array_filter($p2['units'], fn($x) => $x['kind'] === 'carton'));
check('250 boxes of 1 kg: 6 full cartons + 1 partial carton of 10 boxes (flagged) on 1 pallet', (int)$p2['cartons'] === 7 && (int)$p2['pallets'] === 1 && $c2[6]['boxes'] === 10 && $c2[6]['is_partial'] === true && $c2[0]['is_partial'] === false);
$k3 = (int)http('POST', '/pack-orders', ['item_id' => $tom, 'required_kg' => 300, 'customer_name' => 'Greedy', 'branch_id' => $B1], $packer)['json']['id'];
http('POST', "/pack-orders/$k3/decide", ['box_kg' => 1], $head);
$s3 = http('POST', "/pack-orders/$k3/start", null, $packer);
check('only 250 kg of grade A are free now (the rest is held for the other client order): 300 kg are refused (409)', $s3['status'] === 409 && stripos($s3['json']['error'], 'Not enough') !== false, json_encode($s3['json']));
http('POST', "/pack-orders/$k3/cancel", ['reason' => 'not enough stock'], $packer);
$recv(300, 5, 'A', 'A3');
$kc = (int)http('POST', '/pack-orders', ['item_id' => $tom, 'required_kg' => 60, 'customer_id' => $cust2, 'branch_id' => $B1], $packer)['json']['id'];
check('the second client\'s specification allows only 1 kg boxes (422 for 2)', http('POST', "/pack-orders/$kc/decide", ['box_kg' => 2], $head)['status'] === 422);
http('POST', "/pack-orders/$kc/decide", ['box_kg' => 1], $head);
http('POST', "/pack-orders/$kc/cancel", ['reason' => 'test only'], $packer);
$kb = (int)http('POST', '/pack-orders', ['item_id' => $tom, 'required_kg' => 25, 'customer_id' => $cust2, 'grade' => 'B', 'branch_id' => $B1], $packer)['json']['id'];
http('POST', "/pack-orders/$kb/decide", ['box_kg' => 1], $head);
$sb = http('POST', "/pack-orders/$kb/start", null, $packer);
check('a grade B pack order takes only grade B stock (batch B1)', $sb['status'] === 200 && (int)$sb['json']['batches'][0]['batch_id'] === $batch('B1'));
$pb = http('POST', "/pack-orders/$kb/pack", ['boxes_packed' => 25], $packer)['json'];
$cb = array_values(array_filter($pb['units'], fn($x) => $x['kind'] === 'carton')); $pbs = array_values(array_filter($pb['units'], fn($x) => $x['kind'] === 'pallet'));
check('with 10 boxes per carton and 3 cartons per pallet: 25 boxes = 2 full cartons + 1 of 5 = 3 cartons on 1 pallet', (int)$pb['cartons'] === 3 && (int)$pb['pallets'] === 1 && $cb[2]['boxes'] === 5 && count($pbs) === 1);
// many pallets: 90 kg grade B is not there, so use the remaining grade A ... build it from a fresh batch
$km = (int)http('POST', '/pack-orders', ['item_id' => $tom, 'required_kg' => 250, 'customer_id' => $cust2, 'branch_id' => $B1], $packer)['json']['id'];
http('POST', "/pack-orders/$km/decide", ['box_kg' => 1], $head); http('POST', "/pack-orders/$km/start", null, $packer);
$pm = http('POST', "/pack-orders/$km/pack", ['boxes_packed' => 250], $packer)['json'];
$pls = array_values(array_filter($pm['units'], fn($x) => $x['kind'] === 'pallet'));
check('250 boxes with 10 per carton and 3 cartons per pallet = 25 cartons on 9 pallets; the last pallet carries 1 carton (10 boxes)', (int)$pm['cartons'] === 25 && (int)$pm['pallets'] === 9 && count($pls) === 9 && $pls[8]['boxes'] === 10 && $pls[0]['boxes'] === 30, json_encode([$pm['cartons'], $pm['pallets'], $pls[8]['boxes'] ?? null]));
check('the pallets together hold all 250 boxes / 250 kg', array_sum(array_column($pls, 'boxes')) === 250 && abs(array_sum(array_column($pls, 'kg')) - 250.0) < 0.001);

echo "\n== loss, trace, cancel\n";
$kl = (int)http('POST', '/pack-orders', ['item_id' => $tom, 'required_kg' => 10, 'customer_id' => $cust2, 'branch_id' => $B1], $packer)['json']['id'];
http('POST', "/pack-orders/$kl/decide", ['box_kg' => 1], $head); http('POST', "/pack-orders/$kl/start", null, $packer);
$pl = http('POST', "/pack-orders/$kl/pack", ['boxes_packed' => 9, 'loss_kg' => 1], $packer)['json'];
check('9 boxes + 1 kg lost (trimming) balances the 10 kg issued', $pl['status'] === 'Packed' && $pl['loss_kg'] == 1.0);
$tr = http('GET', '/pack-orders/trace?code=' . urlencode($cartons[0]['code']), null, $admin);
check('a carton code leads back to its order, the client and the source batch', $tr['status'] === 200 && $tr['json']['order']['number'] === $pk['json']['number'] && strpos($tr['json']['order']['customer_name'], 'Pack Client') === 0 && (int)$tr['json']['batches'][0]['batch_id'] === $batch('A1'), json_encode($tr['json']['error'] ?? null));
check('an unknown code (404)', http('GET', '/pack-orders/trace?code=CTN-NOPE-001', null, $admin)['status'] === 404);
$kd = (int)http('POST', '/pack-orders', ['item_id' => $tom, 'required_kg' => 5, 'branch_id' => $B1], $packer)['json']['id'];
check('cancelling needs a reason (422)', http('POST', "/pack-orders/$kd/cancel", [], $packer)['status'] === 422);
check('a draft can be cancelled', http('POST', "/pack-orders/$kd/cancel", ['reason' => 'client changed'], $packer)['json']['status'] === 'Cancelled');
check('the list shows the orders with their status', count(array_filter(http('GET', '/pack-orders?status=Packed', null, $admin)['json'], fn($x) => in_array((int)$x['id'], [$K1, $k2, $kb, $km, $kl], true))) === 5);

echo "\n== branches and companies\n";
check('a Jeddah pack order is hidden from the main branch (404)', http('GET', "/pack-orders/$K1", null, $admin, null, $B1)['status'] === 200 && http('GET', "/pack-orders/$K1", null, $admin, null, $main)['status'] === 404);
$co = http('POST', '/companies', ['name_en' => "PK other $u"], $admin)['json']['id'];
$adminId = (int)$pdo->query("SELECT id FROM users WHERE email = 'admin@manzuma.com'")->fetchColumn();
$pdo->exec("INSERT IGNORE INTO user_companies (user_id, company_id) VALUES ($adminId, $co)");
check('company 2 cannot see, decide, start, pack or trace company 1\'s packing (404)', http('GET', "/pack-orders/$K1", null, $admin, $co)['status'] === 404 && http('POST', "/pack-orders/$K1/decide", ['box_kg' => 1], $admin, $co)['status'] === 404 && http('POST', "/pack-orders/$K1/start", null, $admin, $co)['status'] === 404 && http('GET', '/pack-orders/trace?code=' . urlencode($cartons[0]['code']), null, $admin, $co)['status'] === 404 && count(http('GET', '/pack-orders', null, $admin, $co)['json']) === 0 && count(http('GET', '/pack-specs', null, $admin, $co)['json']) === 0);
check('company 2 cannot pack company 1\'s client order or use its item (404)', http('POST', '/pack-orders', ['client_order_id' => $co1], $admin, $co)['status'] === 404 && http('POST', '/pack-orders', ['item_id' => $tom, 'required_kg' => 5], $admin, $co)['status'] === 404);
check('a new company gets the packing settings', (int)$pdo->query("SELECT COUNT(*) FROM settings WHERE company_id = $co AND setting_key LIKE 'packing.%'")->fetchColumn() === 5);

echo "\n------------------------------------\n$pass passed, $fail failed\n";
if ($fail) { echo "Failed: " . implode('; ', $fails) . "\n"; exit(1); }
