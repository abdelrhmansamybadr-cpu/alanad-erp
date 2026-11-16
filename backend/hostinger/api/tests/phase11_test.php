<?php
// Phase 5 (suite 11): three people at the truck (quality + counter, then Finance), routing to sorting or store.
//   php hostinger/api/tests/phase11_test.php http://127.0.0.1:8200/api  mysql-dsn user pass
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
foreach ([["tc_$u@x.com", $role('counter')], ["tf_$u@x.com", $role('receiving_finance')], ["tm_$u@x.com", 2], ["tv_$u@x.com", 4]] as [$e, $r]) http('POST', '/users', ['name' => $e, 'email' => $e, 'password' => 'Strong-Pass-123', 'role_id' => $r], $admin);
$counter = login("tc_$u@x.com", 'Strong-Pass-123'); $fin = login("tf_$u@x.com", 'Strong-Pass-123'); $mgr = login("tm_$u@x.com", 'Strong-Pass-123'); $viewer = login("tv_$u@x.com", 'Strong-Pass-123');
$set = fn(array $s) => http('PUT', '/settings', $s, $admin);
$set(['receiving.three_person_rule' => true, 'receiving.count_tolerance_pct' => 1, 'fleet.require_entry_permit' => false]);
$main = (int)http('GET', '/me', null, $admin)['json']['branches'][0]['id'];
$B1 = (int)http('POST', '/company-branches', ['code' => "T3$u", 'name_en' => "Three Jeddah $u"], $admin)['json']['id'];
$sup = http('POST', '/suppliers', ['code' => "T3S$u", 'name' => "Farm $u"], $admin)['json']['id'];
$tom = http('POST', '/items', ['code' => "T3T$u", 'name' => "T3 Tomato $u", 'shelf_life_days' => 7, 'needs_sorting' => 1], $admin)['json']['id'];
$cuc = http('POST', '/items', ['code' => "T3C$u", 'name' => "T3 Cucumber $u", 'shelf_life_days' => 7], $admin)['json']['id'];
$wh = http('POST', '/warehouses', ['code' => "T3W$u", 'name' => "T3 WH $u", 'type' => 'cold'], $admin)['json']['id'];
$room = http('POST', '/cold-rooms', ['warehouse_id' => $wh, 'code' => "T3R$u", 'name' => "T3 room $u", 'temp_min' => 2, 'temp_max' => 8], $admin)['json']['id'];
check('the item flag needs_sorting is stored', (int)http('GET', "/items/$tom", null, $admin)['json']['needs_sorting'] === 1);

/** a delivery that has passed quality (Ready): arrival by the admin, quality by the admin, sign-off by a manager */
$ready = function (int $item, float $kg, ?int $branch = null) use ($admin, $mgr, $sup, $pdo) {
    $p = http('POST', '/purchase-orders', ['date' => date('Y-m-d'), 'supplier_id' => $sup, 'supplier_name' => 'x', 'lines' => [['item_id' => $item, 'item_name' => 'T', 'qty' => $kg, 'rate' => 5, 'amount' => $kg * 5]]], $admin, null, $branch)['json'];
    $pdo->exec("UPDATE purchase_orders SET status = 'Approved' WHERE id = {$p['id']}");
    $d = http('POST', '/deliveries', ['po_id' => $p['id'], 'driver_name' => 'Ali', 'vehicle_plate' => 'T3 ' . $p['id']], $admin)['json'];
    $id = (int)$d['id'];
    http('POST', "/deliveries/$id/weigh-in", ['gross_kg' => $kg + 1000], $admin);
    http('POST', "/deliveries/$id/temperature", ['truck_air_temp' => 4, 'product_temp' => 4], $admin);
    http('POST', "/deliveries/$id/quality", ['lines' => [['item_id' => $item, 'grade' => 'A', 'received_kg' => $kg]]], $admin);
    http('POST', "/deliveries/$id/weigh-out", ['tare_kg' => 1000], $admin);
    $a = http('POST', "/deliveries/$id/accept", null, $mgr);
    return [$id, $a];
};

echo "\n== the counter counts, after quality\n";
[$D1, $acc] = $ready($tom, 500);
check('(setup) the truck passed quality and is Ready', $acc['status'] === 200 && $acc['json']['status'] === 'Ready', json_encode($acc['json']['error'] ?? null));
check('the detail shows who did what and the suggested route (tomato needs sorting)', $acc['json']['three']['rule_on'] === true && $acc['json']['three']['suggested_route'] === 'sorting' && !empty($acc['json']['three']['people']['quality_by']), json_encode($acc['json']['three'] ?? null));
check('a read-only user cannot count (403)', http('POST', "/deliveries/$D1/count", ['units' => 50], $viewer)['status'] === 403);
check('Finance cannot count (403: not the counter)', http('POST', "/deliveries/$D1/count", ['units' => 50], $fin)['status'] === 403);
check('units must be a whole number above zero (422)', http('POST', "/deliveries/$D1/count", ['units' => 0], $counter)['status'] === 422 && http('POST', "/deliveries/$D1/count", ['units' => 'many'], $counter)['status'] === 422 && http('POST', "/deliveries/$D1/count", ['units' => 5.5], $counter)['status'] === 422);
check('the person who did the quality check cannot count the same truck (403)', ($qc = http('POST', "/deliveries/$D1/count", ['units' => 50], $admin))['status'] === 403 && stripos($qc['json']['error'], 'quality') !== false, json_encode($qc['json']));
check('the Finance person cannot confirm before the count (409)', http('POST', "/deliveries/$D1/finance-check", ['papers_ok' => true, 'goods_ok' => true, 'delivery_note_no' => 'N-1'], $fin)['status'] === 409);
$cn = http('POST', "/deliveries/$D1/count", ['units' => 50, 'unit' => 'crate'], $counter);
check('the counter counts 50 crates', $cn['status'] === 200 && (int)$cn['json']['counted_units'] === 50 && $cn['json']['count_unit'] === 'crate' && !empty($cn['json']['counted_by']), json_encode($cn['json']['error'] ?? null));
check('he can recount before Finance confirms', (int)http('POST', "/deliveries/$D1/count", ['units' => 52], $counter)['json']['counted_units'] === 52);

echo "\n== the Finance person confirms papers and goods\n";
check('the counter cannot do the Finance step (403)', http('POST', "/deliveries/$D1/finance-check", ['papers_ok' => true, 'goods_ok' => true, 'delivery_note_no' => 'N-1'], $counter)['status'] === 403);
check('the quality person cannot do the Finance step (403: also no Finance right)', http('POST', "/deliveries/$D1/finance-check", ['papers_ok' => true, 'goods_ok' => true, 'delivery_note_no' => 'N-1'], $admin)['status'] === 403);
check('papers_ok and goods_ok are required (422)', http('POST', "/deliveries/$D1/finance-check", ['papers_ok' => true], $fin)['status'] === 422);
check('good papers need the delivery note number (422)', http('POST', "/deliveries/$D1/finance-check", ['papers_ok' => true, 'goods_ok' => true], $fin)['status'] === 422);
$big = http('POST', "/deliveries/$D1/finance-check", ['papers_ok' => true, 'goods_ok' => true, 'delivery_note_no' => 'N-100', 'note_units' => 60], $fin);
check('52 counted vs 60 on the note is 13 % off: Finance must write why (422)', $big['status'] === 422 && stripos($big['json']['error'], 'differ') !== false, json_encode($big['json']));
check('route before Finance passed is refused (409)', http('POST', "/deliveries/$D1/route", ['to' => 'store'], $fin)['status'] === 409);
check('put-away before Finance passed is refused (409)', http('POST', "/deliveries/$D1/put-away", ['lines' => [['line_id' => http('GET', "/deliveries/$D1", null, $admin)['json']['lines'][0]['id'], 'cold_room_id' => $room]]], $admin)['status'] === 409);
$ok = http('POST', "/deliveries/$D1/finance-check", ['papers_ok' => true, 'goods_ok' => true, 'delivery_note_no' => 'N-100', 'note_units' => 60, 'note' => 'supplier confirmed 8 crates were left at the farm'], $fin);
check('with a reason Finance passes it; the count difference is shown', $ok['status'] === 200 && $ok['json']['finance_status'] === 'Passed' && $ok['json']['delivery_note_no'] === 'N-100' && $ok['json']['three']['count_diff_pct'] == -13.33, json_encode($ok['json']['error'] ?? $ok['json']['three']['count_diff_pct'] ?? null));
check('after Finance passed, nobody can recount (409)', http('POST', "/deliveries/$D1/count", ['units' => 60], $counter)['status'] === 409);

echo "\n== routing: sorting or store\n";
check('to must be store or sorting (422)', http('POST', "/deliveries/$D1/route", ['to' => 'moon'], $fin)['status'] === 422);
check('the counter cannot route (403)', http('POST', "/deliveries/$D1/route", ['to' => 'store'], $counter)['status'] === 403);
$rs = http('POST', "/deliveries/$D1/route", ['to' => 'sorting'], $fin);
check('routed to sorting: status To_Sorting', $rs['status'] === 200 && $rs['json']['status'] === 'To_Sorting' && $rs['json']['route'] === 'sorting' && !empty($rs['json']['routed_by']));
check('a truck waiting for sorting cannot be put away (409)', http('POST', "/deliveries/$D1/put-away", ['lines' => [['line_id' => $rs['json']['lines'][0]['id'], 'cold_room_id' => $room]]], $admin)['status'] === 409);
check('it appears in the list under To_Sorting', in_array($D1, array_column(http('GET', '/deliveries?status=To_Sorting', null, $admin)['json'], 'id')));
$rb = http('POST', "/deliveries/$D1/route", ['to' => 'store'], $fin);
check('Finance can send it to the store instead: back to Ready on the store route', $rb['json']['status'] === 'Ready' && $rb['json']['route'] === 'store');
$pa = http('POST', "/deliveries/$D1/put-away", ['lines' => [['line_id' => $rb['json']['lines'][0]['id'], 'cold_room_id' => $room]]], $admin);
check('on the store route the goods are put away', $pa['status'] === 200 && $pa['json']['status'] === 'Stored', json_encode($pa['json']['error'] ?? null));

echo "\n== when Finance finds a problem\n";
[$D2] = $ready($cuc, 300);
check('(a cucumber truck suggests the store)', http('GET', "/deliveries/$D2", null, $admin)['json']['three']['suggested_route'] === 'store');
http('POST', "/deliveries/$D2/count", ['units' => 30], $counter);
check('a problem needs an explanation (422)', http('POST', "/deliveries/$D2/finance-check", ['papers_ok' => false, 'goods_ok' => true], $fin)['status'] === 422);
$ds = http('POST', "/deliveries/$D2/finance-check", ['papers_ok' => false, 'goods_ok' => true, 'note' => 'delivery note belongs to another supplier'], $fin)['json'];
check('Finance marks it Disputed', $ds['finance_status'] === 'Disputed' && (int)$ds['papers_ok'] === 0 && $ds['finance_note'] === 'delivery note belongs to another supplier');
check('a disputed truck cannot be routed or stored (409)', http('POST', "/deliveries/$D2/route", ['to' => 'store'], $fin)['status'] === 409 && http('POST', "/deliveries/$D2/put-away", ['lines' => [['line_id' => $ds['lines'][0]['id'], 'cold_room_id' => $room]]], $admin)['status'] === 409);
check('a recount clears the dispute', http('POST', "/deliveries/$D2/count", ['units' => 31], $counter)['json']['finance_status'] === null);
check('then Finance checks again and passes', http('POST', "/deliveries/$D2/finance-check", ['papers_ok' => true, 'goods_ok' => true, 'delivery_note_no' => 'N-200', 'note_units' => 31], $fin)['json']['finance_status'] === 'Passed');
check('a manager can still reject a truck waiting at To_Sorting or Ready', http('POST', "/deliveries/$D2/reject", ['reason_code' => 'other', 'note' => 'changed our mind'], $mgr)['status'] === 200);

echo "\n== the rule is a setting\n";
$set(['receiving.three_person_rule' => false]);
[$D3] = $ready($cuc, 200);
$old = http('POST', "/deliveries/$D3/put-away", ['lines' => [['line_id' => http('GET', "/deliveries/$D3", null, $admin)['json']['lines'][0]['id'], 'cold_room_id' => $room]]], $admin);
check('with the rule off, the old flow still works (no counter, no Finance step)', $old['status'] === 200 && $old['json']['status'] === 'Stored', json_encode($old['json']['error'] ?? null));
$set(['receiving.three_person_rule' => true]);

echo "\n== branches and companies\n";
[$DJ] = $ready($cuc, 100, $B1);
check('a Jeddah truck is hidden from the main branch (404) when counting', http('POST', "/deliveries/$DJ/count", ['units' => 10], $counter, null, $main)['status'] === 404 && http('POST', "/deliveries/$DJ/count", ['units' => 10], $counter, null, $B1)['status'] === 200);
$co = http('POST', '/companies', ['name_en' => "T3 other $u"], $admin)['json']['id'];
$adminId = (int)$pdo->query("SELECT id FROM users WHERE email = 'admin@manzuma.com'")->fetchColumn();
$pdo->exec("INSERT IGNORE INTO user_companies (user_id, company_id) VALUES ($adminId, $co)");
check('company 2 cannot count, confirm or route company 1\'s truck (404)', http('POST', "/deliveries/$DJ/count", ['units' => 1], $admin, $co)['status'] === 404 && http('POST', "/deliveries/$DJ/finance-check", ['papers_ok' => true, 'goods_ok' => true, 'delivery_note_no' => 'x'], $admin, $co)['status'] === 404 && http('POST', "/deliveries/$DJ/route", ['to' => 'store'], $admin, $co)['status'] === 404);
check('a new company gets the receiving rule settings', (int)$pdo->query("SELECT COUNT(*) FROM settings WHERE company_id = $co AND setting_key IN ('receiving.three_person_rule','receiving.count_tolerance_pct')")->fetchColumn() === 2);
$set(['receiving.three_person_rule' => false]);

echo "\n------------------------------------\n$pass passed, $fail failed\n";
if ($fail) { echo "Failed: " . implode('; ', $fails) . "\n"; exit(1); }
