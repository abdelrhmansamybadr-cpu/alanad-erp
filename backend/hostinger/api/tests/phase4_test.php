<?php
// Phase 3 tests (file name phase4 = the 4th suite): receiving, weighing and quality — a truck from the gate to the cold room.
//   php hostinger/api/tests/phase4_test.php http://127.0.0.1:8200/api  mysql-dsn user pass
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
foreach ([["mgr4_$u@x.com", 2], ["viewer4_$u@x.com", 4]] as [$e, $r]) http('POST', '/users', ['name' => $e, 'email' => $e, 'password' => 'Strong-Pass-123', 'role_id' => $r], $admin);
$mgr = login("mgr4_$u@x.com", 'Strong-Pass-123'); $viewer = login("viewer4_$u@x.com", 'Strong-Pass-123');

echo "\n== setup\n";
$sup = http('POST', '/suppliers', ['code' => "S$u", 'name' => "Farm $u"], $admin)['json']['id'];
$tom = http('POST', '/items', ['code' => "T$u", 'name' => "Tomato $u", 'shelf_life_days' => 7, 'storage_temp_min' => 2, 'storage_temp_max' => 8], $admin)['json']['id'];
$cuc = http('POST', '/items', ['code' => "C$u", 'name' => "Cucumber $u", 'shelf_life_days' => 7], $admin)['json']['id'];
$wh = http('POST', '/warehouses', ['code' => "W$u", 'name' => "WH $u", 'type' => 'cold'], $admin)['json']['id'];
$room = http('POST', '/cold-rooms', ['warehouse_id' => $wh, 'code' => "R$u", 'name' => "Room $u", 'temp_min' => 2, 'temp_max' => 8], $admin)['json']['id'];
$mkpo = function (array $lines) use ($admin, $sup, $pdo) {
    $po = http('POST', '/purchase-orders', ['date' => date('Y-m-d'), 'supplier_id' => $sup, 'supplier_name' => 'x', 'lines' => $lines], $admin);
    $id = (int)($po['json']['id'] ?? 0);
    if ($id) $pdo->exec("UPDATE purchase_orders SET status = 'Approved' WHERE id = $id");
    return $id;
};
$po1 = $mkpo([['item_id' => $tom, 'item_name' => 'Tomato', 'qty' => 100, 'rate' => 5, 'amount' => 500], ['item_id' => $cuc, 'item_name' => 'Cucumber', 'qty' => 50, 'rate' => 3, 'amount' => 150]]);
$draftPo = (int)http('POST', '/purchase-orders', ['date' => date('Y-m-d'), 'supplier_id' => $sup, 'supplier_name' => 'x', 'lines' => [['item_id' => $tom, 'item_name' => 'T', 'qty' => 1, 'rate' => 1, 'amount' => 1]]], $admin)['json']['id'];
check('setup: items, room and an approved two-item order exist', $sup && $tom && $cuc && $room && $po1 && $draftPo);

echo "\n== arrival\n";
check('viewer cannot register an arrival (403)', http('POST', '/deliveries', ['po_id' => $po1, 'driver_name' => 'Ali'], $viewer)['status'] === 403);
check('driver name is required (422)', http('POST', '/deliveries', ['po_id' => $po1], $admin)['status'] === 422);
check('a draft order cannot be delivered (409)', http('POST', '/deliveries', ['po_id' => $draftPo, 'driver_name' => 'Ali'], $admin)['status'] === 409);
check('unknown order -> 404', http('POST', '/deliveries', ['po_id' => 99999, 'driver_name' => 'Ali'], $admin)['status'] === 404);
$d = http('POST', '/deliveries', ['po_id' => $po1, 'driver_name' => 'Ali Hassan', 'driver_id_no' => '1012345678', 'vehicle_plate' => 'ABC 1234', 'gross_kg' => 1150], $admin);
$d1 = (int)($d['json']['id'] ?? 0);
check('arrival is registered: Arrived, numbered RC-, clock started', $d['status'] === 201 && $d['json']['status'] === 'Arrived' && str_starts_with($d['json']['number'], 'RC-') && $d['json']['minutes_waiting'] !== null && $d['json']['gross_kg'] == 1150, json_encode($d['json']));
check('detail carries the order with ordered / stored per item and the limits', count($d['json']['po']['lines']) === 2 && $d['json']['limits']['truck_air_max'] == 8.0 && $d['json']['limits']['product_temp_max'] == 8.0);

echo "\n== temperature\n";
check('temperature must be a number (422)', http('POST', "/deliveries/$d1/temperature", ['truck_air_temp' => 'x', 'product_temp' => 4], $admin)['status'] === 422);
$t = http('POST', "/deliveries/$d1/temperature", ['truck_air_temp' => 12.0, 'product_temp' => 6.0], $admin);
check('truck air above the limit -> Temp_Hold', $t['json']['status'] === 'Temp_Hold' && (int)$t['json']['temp_ok'] === 0, json_encode($t['json']));
check('quality cannot start while the truck is on temperature hold (409)', http('POST', "/deliveries/$d1/quality", ['lines' => [['item_id' => $tom, 'grade' => 'A', 'received_kg' => 10]]], $admin)['status'] === 409);
check('the person who measured cannot decide (403)', http('POST', "/deliveries/$d1/temperature-decision", ['decision' => 'accept', 'note' => 'ok'], $admin)['status'] === 403);
check('viewer cannot decide (403)', http('POST', "/deliveries/$d1/temperature-decision", ['decision' => 'accept', 'note' => 'ok'], $viewer)['status'] === 403);
check('a note is required for the decision (422)', http('POST', "/deliveries/$d1/temperature-decision", ['decision' => 'accept'], $mgr)['status'] === 422);
$a = http('POST', "/deliveries/$d1/temperature-decision", ['decision' => 'accept', 'note' => 'Short trip, product still cold'], $mgr);
check('manager accepts with a note -> Checking', $a['json']['status'] === 'Checking' && $a['json']['temp_decision_note'] !== null, json_encode($a['json']));
$t2 = http('POST', "/deliveries/$d1/temperature", ['truck_air_temp' => 5.0, 'product_temp' => 9.5], $admin);
check('product 9.5 is inside limit 8 + tolerance 2 -> OK, Checking', $t2['json']['status'] === 'Checking' && (int)$t2['json']['temp_ok'] === 1);
$t3 = http('POST', "/deliveries/$d1/temperature", ['truck_air_temp' => 5.0, 'product_temp' => 11.0], $admin);
check('product 11 is above the tolerance -> Temp_Hold again', $t3['json']['status'] === 'Temp_Hold');
http('POST', "/deliveries/$d1/temperature", ['truck_air_temp' => 4.0, 'product_temp' => 5.0], $admin);
check('a good re-measurement releases it', http('GET', "/deliveries/$d1", null, $admin)['json']['status'] === 'Checking');

echo "\n== quality lines\n";
$q = fn($lines) => http('POST', "/deliveries/$d1/quality", ['lines' => $lines], $admin);
check('grade must be A, B or C (422)', $q([['item_id' => $tom, 'grade' => 'D', 'received_kg' => 10]])['status'] === 422);
check('an item that is not on the order (422)', $q([['item_id' => 99999, 'grade' => 'A', 'received_kg' => 10]])['status'] === 422);
check('rejected kg above weighed kg (422)', $q([['item_id' => $tom, 'grade' => 'A', 'received_kg' => 10, 'rejected_kg' => 11, 'reject_reason_code' => 'rotten']])['status'] === 422);
check('a reject needs a reason (422)', $q([['item_id' => $tom, 'grade' => 'A', 'received_kg' => 10, 'rejected_kg' => 2]])['status'] === 422);
check('a reject reason must be from the list (422)', $q([['item_id' => $tom, 'grade' => 'A', 'received_kg' => 10, 'rejected_kg' => 2, 'reject_reason_code' => 'made_up']])['status'] === 422);
check('reason "other" needs a note (422)', $q([['item_id' => $tom, 'grade' => 'A', 'received_kg' => 10, 'rejected_kg' => 2, 'reject_reason_code' => 'other']])['status'] === 422);
$ql = $q([['item_id' => $tom, 'grade' => 'A', 'received_kg' => 80, 'sample_kg' => 5], ['item_id' => $tom, 'grade' => 'B', 'received_kg' => 20, 'rejected_kg' => 5, 'reject_reason_code' => 'rotten'], ['item_id' => $cuc, 'grade' => 'A', 'received_kg' => 50]]);
check('quality lines saved: 3 lines, accepted kg per line computed', $ql['status'] === 200 && count($ql['json']['lines']) === 3 && $ql['json']['lines'][1]['accepted_kg'] == 15.0, json_encode($ql['json']['lines'] ?? $ql['json']));
check('weigh-out before gross is impossible only without gross: tare >= gross (422)', http('POST', "/deliveries/$d1/weigh-out", ['tare_kg' => 1200], $admin)['status'] === 422);
check('net weight that does not match the lines is refused (422)', http('POST', "/deliveries/$d1/weigh-out", ['tare_kg' => 900], $admin)['status'] === 422);
check('accepting before the weigh-out (409)', http('POST', "/deliveries/$d1/accept", null, $mgr)['status'] === 409);
$wo = http('POST', "/deliveries/$d1/weigh-out", ['tare_kg' => 1000], $admin);
check('tare 1000 -> net 150 kg matches the lines', $wo['status'] === 200 && $wo['json']['net_kg'] == 150.0 && $wo['json']['tare_kg'] == 1000.0, json_encode($wo['json']));

echo "\n== sign-off\n";
check('the person who registered the arrival cannot sign off (403)', http('POST', "/deliveries/$d1/accept", null, $admin)['status'] === 403);
check('viewer cannot sign off (403)', http('POST', "/deliveries/$d1/accept", null, $viewer)['status'] === 403);
$ac = http('POST', "/deliveries/$d1/accept", null, $mgr);
check('manager signs off -> Ready', $ac['status'] === 200 && $ac['json']['status'] === 'Ready' && $ac['json']['accepted_by'] !== null, json_encode($ac['json']));
check('lines cannot be changed after the sign-off (409)', $q([['item_id' => $tom, 'grade' => 'A', 'received_kg' => 10]])['status'] === 409);

echo "\n== put-away\n";
$L = $ac['json']['lines'];   // [tom A 80, tom B 20 (15 accepted), cuc A 50]
check('viewer cannot put away (403)', http('POST', "/deliveries/$d1/put-away", ['lines' => [['line_id' => $L[0]['id'], 'cold_room_id' => $room]]], $viewer)['status'] === 403);
check('a line without a cold room is refused', in_array(http('POST', "/deliveries/$d1/put-away", ['lines' => [['line_id' => $L[0]['id']]]], $admin)['status'], [404, 422], true));
check('a line of another delivery (422)', http('POST', "/deliveries/$d1/put-away", ['lines' => [['line_id' => 999999, 'cold_room_id' => $room]]], $admin)['status'] === 422);
$p1 = http('POST', "/deliveries/$d1/put-away", ['lines' => [['line_id' => $L[0]['id'], 'cold_room_id' => $room, 'rack' => 'R1', 'position' => 'P1'], ['line_id' => $L[2]['id'], 'cold_room_id' => $room]]], $admin);
check('first put-away stores two lines, delivery stays Ready', $p1['status'] === 200 && $p1['json']['status'] === 'Ready' && $p1['json']['lines'][0]['batch_id'] !== null && $p1['json']['lines'][1]['batch_id'] === null, json_encode($p1['json']['status'] ?? $p1['json']));
check('a stored line cannot be stored twice (409)', http('POST', "/deliveries/$d1/put-away", ['lines' => [['line_id' => $L[0]['id'], 'cold_room_id' => $room]]], $admin)['status'] === 409);
$p2 = http('POST', "/deliveries/$d1/put-away", ['close_order' => true, 'lines' => [['line_id' => $L[1]['id'], 'cold_room_id' => $room]]], $admin);
check('second put-away completes the delivery -> Stored', $p2['status'] === 200 && $p2['json']['status'] === 'Stored', json_encode($p2['json']['status'] ?? $p2['json']));
$bal = http('GET', "/stock/balance?item_id=$tom", null, $admin)['json'];
$byGrade = []; foreach ($bal as $b) $byGrade[$b['grade']] = ($byGrade[$b['grade']] ?? 0) + $b['qty'];
check('stock has 80 kg grade A and 15 kg grade B tomatoes (the 5 kg reject stayed out)', ($byGrade['A'] ?? 0) == 80.0 && ($byGrade['B'] ?? 0) == 15.0, json_encode($byGrade));
check('cucumbers: 50 kg in stock', array_sum(array_column(http('GET', "/stock/balance?item_id=$cuc", null, $admin)['json'], 'qty')) == 50.0);
check('closing the order marked the purchase order Received', http('GET', "/purchase-orders/$po1", null, $admin)['json']['status'] === 'Received');
check('a stored delivery accepts no more steps (409)', http('POST', "/deliveries/$d1/reject", ['reason_code' => 'rotten'], $mgr)['status'] === 409);

echo "\n== whole-truck reject and temperature reject\n";
$po2 = $mkpo([['item_id' => $tom, 'item_name' => 'Tomato', 'qty' => 100, 'rate' => 5, 'amount' => 500]]);
$d2 = (int)http('POST', '/deliveries', ['po_id' => $po2, 'driver_name' => 'Omar'], $admin)['json']['id'];
check('reject needs a reason from the list (422)', http('POST', "/deliveries/$d2/reject", ['reason_code' => 'nope'], $mgr)['status'] === 422);
check('viewer cannot reject (403)', http('POST', "/deliveries/$d2/reject", ['reason_code' => 'rotten'], $viewer)['status'] === 403);
$rj = http('POST', "/deliveries/$d2/reject", ['reason_code' => 'rotten', 'note' => 'whole load spoiled'], $mgr);
check('manager rejects the whole truck -> Rejected with reason', $rj['status'] === 200 && $rj['json']['status'] === 'Rejected' && $rj['json']['reject_reason_code'] === 'rotten');
check('a rejected delivery accepts no more steps (409)', http('POST', "/deliveries/$d2/weigh-in", ['gross_kg' => 5], $admin)['status'] === 409);
check('the order stays open for another truck', http('GET', "/purchase-orders/$po2", null, $admin)['json']['status'] === 'Approved');
$d3 = (int)http('POST', '/deliveries', ['po_id' => $po2, 'driver_name' => 'Sami'], $admin)['json']['id'];
http('POST', "/deliveries/$d3/temperature", ['truck_air_temp' => 15, 'product_temp' => 14], $admin);
$tr = http('POST', "/deliveries/$d3/temperature-decision", ['decision' => 'reject', 'note' => 'Warm product'], $mgr);
check('temperature reject sends the truck back (Rejected, reason temperature)', $tr['json']['status'] === 'Rejected' && $tr['json']['reject_reason_code'] === 'temperature', json_encode($tr['json']));

echo "\n== never more than ordered\n";
$d4 = (int)http('POST', '/deliveries', ['po_id' => $po2, 'driver_name' => 'Khaled', 'gross_kg' => 1130], $admin)['json']['id'];
http('POST', "/deliveries/$d4/temperature", ['truck_air_temp' => 4, 'product_temp' => 4], $admin);
http('POST', "/deliveries/$d4/quality", ['lines' => [['item_id' => $tom, 'grade' => 'A', 'received_kg' => 130]]], $admin);
http('POST', "/deliveries/$d4/weigh-out", ['tare_kg' => 1000], $admin);
$ex = http('POST', "/deliveries/$d4/accept", null, $mgr);
check('130 kg on a 100 kg order (tolerance 10 %) is refused at sign-off (422)', $ex['status'] === 422 && stripos($ex['json']['error'], 'more than ordered') !== false, json_encode($ex['json']));
http('POST', "/deliveries/$d4/quality", ['lines' => [['item_id' => $tom, 'grade' => 'A', 'received_kg' => 130, 'rejected_kg' => 25, 'reject_reason_code' => 'wrong_size']]], $admin);
http('POST', "/deliveries/$d4/weigh-out", ['tare_kg' => 1000], $admin);
check('rejecting the excess (25 kg) makes it acceptable (105 kg)', http('POST', "/deliveries/$d4/accept", null, $mgr)['json']['status'] === 'Ready');

echo "\n== lists, rating, settings\n";
$list = http('GET', '/deliveries?status=Rejected', null, $admin)['json'];
check('list filters by status and shows the order and supplier', count($list) >= 2 && $list[0]['po_number'] !== null && $list[0]['supplier_name'] !== null);
$openL = http('GET', '/deliveries?status=Ready', null, $admin)['json'];
check('open deliveries show the waiting minutes, stored ones do not', count($openL) >= 1 && $openL[0]['minutes_waiting'] !== null && http('GET', '/deliveries?status=Stored', null, $admin)['json'][0]['minutes_waiting'] === null);
$pdo->exec("UPDATE deliveries SET arrived_at = DATE_SUB(NOW(), INTERVAL 3 HOUR) WHERE id = $d4");
check('a truck waiting 3 hours is flagged (limit 60 min)', http('GET', "/deliveries/$d4", null, $admin)['json']['waiting_too_long'] === true);
$rate = http('GET', '/deliveries/supplier-rating', null, $admin)['json'];
$mine = array_values(array_filter($rate, fn($r) => $r['supplier_id'] === $sup))[0] ?? null;
check('supplier rating counts deliveries and rejected deliveries', $mine && $mine['deliveries'] === 4 && $mine['rejected_deliveries'] === 2, json_encode($mine));
check('supplier rating reject % and grade A share are computed', $mine && $mine['reject_pct'] > 0 && $mine['grade_a_pct'] > 0, json_encode($mine));
check('direct receipt into stock is blocked by default (409)', http('POST', '/stock/receive-po', ['po_id' => $po2, 'lines' => [['item_id' => $tom, 'qty' => 1, 'cold_room_id' => $room]]], $admin)['status'] === 409);
$st = array_column(http('GET', '/settings?group=receiving', null, $admin)['json'], 'setting_key');
check('the thresholds are editable settings', in_array('receiving.truck_air_max', $st, true) && in_array('receiving.net_tolerance_pct', $st, true) && in_array('receiving.allow_direct_po_receipt', $st, true));

echo "\n== company isolation and module switch\n";
$co = http('POST', '/companies', ['name_en' => "Other $u"], $admin)['json']['id'];
$adminId = (int)$pdo->query("SELECT id FROM users WHERE email = 'admin@manzuma.com'")->fetchColumn();
$pdo->exec("INSERT IGNORE INTO user_companies (user_id, company_id) VALUES ($adminId, $co)");
check('company 2 sees no deliveries', count(http('GET', '/deliveries', null, $admin, $co)['json']) === 0);
check('company 2 cannot read, sign off or put away company 1 deliveries (404)', http('GET', "/deliveries/$d4", null, $admin, $co)['status'] === 404 && http('POST', "/deliveries/$d4/accept", null, $admin, $co)['status'] === 404 && http('POST', "/deliveries/$d1/put-away", ['lines' => [['line_id' => $L[0]['id'], 'cold_room_id' => $room]]], $admin, $co)['status'] === 404);
check('company 2 cannot register a delivery for a company 1 order (404)', http('POST', '/deliveries', ['po_id' => $po2, 'driver_name' => 'X'], $admin, $co)['status'] === 404);
check('company 2 gets the receiving settings and reject reasons too', count(http('GET', '/settings?group=receiving', null, $admin, $co)['json']) >= 6);
http('PUT', '/modules/quality', ['is_enabled' => false], $admin);
check('closing the Quality module blocks deliveries (403)', http('GET', '/deliveries', null, $admin)['status'] === 403);
http('PUT', '/modules/quality', ['is_enabled' => true], $admin);
check('re-opened: the data is back', http('GET', "/deliveries/$d1", null, $admin)['status'] === 200);

echo "\n------------------------------------\n$pass passed, $fail failed\n";
if ($fail) { echo "Failed: " . implode('; ', $fails) . "\n"; exit(1); }
