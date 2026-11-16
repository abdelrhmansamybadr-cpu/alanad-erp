<?php
// Phase 5 tests (suite 6): production and cold chain — preparation orders, FEFO issue at real cost, timer, food-safety logs,
// yield, waste decisions with Finance approval, work-in-progress costing, traceability, reports.
//   php hostinger/api/tests/phase6_test.php http://127.0.0.1:8200/api  mysql-dsn user pass
$base = rtrim($argv[1] ?? 'http://127.0.0.1:8200/api', '/');
$pdo  = new PDO($argv[2] ?? 'mysql:host=127.0.0.1;port=3410;dbname=alanad_erp_dev;charset=utf8mb4', $argv[3] ?? 'root', $argv[4] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pass = 0; $fail = 0; $fails = [];

function http(string $m, string $path, ?array $body = null, ?string $tok = null, ?int $company = null): array {
    global $base;
    $ch = curl_init($base . $path);
    $h = ['Content-Type: application/json'];
    if ($tok) $h[] = "Authorization: Bearer $tok";
    if ($company) $h[] = "X-Company-Id: $company";
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
function near(float $a, float $b, float $eps = 0.011): bool { return abs($a - $b) <= $eps; }

$u = substr((string)time(), -6);
$admin = login('admin@manzuma.com', 'password');
foreach ([["pm1_$u@x.com", 2], ["pm2_$u@x.com", 2], ["pv_$u@x.com", 4]] as [$e, $r]) http('POST', '/users', ['name' => $e, 'email' => $e, 'password' => 'Strong-Pass-123', 'role_id' => $r], $admin);
$m1 = login("pm1_$u@x.com", 'Strong-Pass-123'); $m2 = login("pm2_$u@x.com", 'Strong-Pass-123'); $viewer = login("pv_$u@x.com", 'Strong-Pass-123');
http('PUT', '/settings', ['finance.closed_until' => ''], $admin);

$bal = function (string $code) use ($admin) {
    foreach (http('GET', '/finance-reports/trial-balance', null, $admin)['json']['rows'] as $r) if ($r['code'] === $code) return $r;
    return ['debit' => 0, 'credit' => 0, 'balance' => 0];
};
$kg = function (int $item, ?string $grade = null) use ($admin) {
    $s = 0.0; foreach (http('GET', "/stock/balance?item_id=$item", null, $admin)['json'] as $r) if ($grade === null || $r['grade'] === $grade) $s += $r['qty'];
    return round($s, 3);
};
$entryOf = function (string $type, int $src) use ($pdo, $admin) {
    $id = $pdo->query("SELECT id FROM gl_entries WHERE source_type = '$type' AND source_id = $src")->fetchColumn();
    return $id ? http('GET', "/gl-entries/$id", null, $admin)['json'] : null;
};
$amt = fn($e, $code, $side) => array_sum(array_map(fn($l) => $l[$side], array_filter($e['lines'] ?? [], fn($l) => $l['account_code'] === $code)));

echo "\n== setup: raw material in two batches with different cost\n";
$sup = http('POST', '/suppliers', ['code' => "S$u", 'name' => "Farm $u"], $admin)['json']['id'];
$raw = http('POST', '/items', ['code' => "RT$u", 'name' => "Raw tomato $u", 'shelf_life_days' => 7, 'storage_temp_min' => 2, 'storage_temp_max' => 8], $admin)['json']['id'];
$fin = http('POST', '/items', ['code' => "WT$u", 'name' => "Washed tomato $u", 'shelf_life_days' => 5, 'storage_temp_min' => 2, 'storage_temp_max' => 8], $admin)['json']['id'];
$byp = http('POST', '/items', ['code' => "TR$u", 'name' => "Tomato trim $u", 'shelf_life_days' => 3], $admin)['json']['id'];
$wh = http('POST', '/warehouses', ['code' => "W$u", 'name' => "WH $u", 'type' => 'cold'], $admin)['json']['id'];
$room = http('POST', '/cold-rooms', ['warehouse_id' => $wh, 'code' => "R$u", 'name' => "Room $u", 'temp_min' => 2, 'temp_max' => 8], $admin)['json']['id'];
$mkpo = function (int $qty, float $rate) use ($admin, $sup, $raw, $pdo) {
    $po = http('POST', '/purchase-orders', ['date' => date('Y-m-d'), 'supplier_id' => $sup, 'supplier_name' => 'x', 'lines' => [['item_id' => $raw, 'item_name' => 'Raw', 'qty' => $qty, 'rate' => $rate, 'amount' => $qty * $rate]]], $admin);
    $id = (int)($po['json']['id'] ?? 0);
    if ($id) $pdo->exec("UPDATE purchase_orders SET status = 'Approved' WHERE id = $id");
    return $id;
};
http('PUT', '/settings', ['receiving.allow_direct_po_receipt' => true], $admin);
$poA = $mkpo(40, 4.00); $poB = $mkpo(60, 5.00);
$exp = fn($d) => date('Y-m-d', strtotime("+$d days"));
http('POST', '/stock/receive-po', ['po_id' => $poA, 'lines' => [['item_id' => $raw, 'qty' => 40, 'cold_room_id' => $room, 'expiry_date' => $exp(3)]]], $admin);
http('POST', '/stock/receive-po', ['po_id' => $poB, 'lines' => [['item_id' => $raw, 'qty' => 60, 'cold_room_id' => $room, 'expiry_date' => $exp(6)]]], $admin);
http('PUT', '/settings', ['receiving.allow_direct_po_receipt' => false], $admin);
$costs = $pdo->query("SELECT unit_cost FROM item_batches WHERE item_id = $raw ORDER BY expiry_date")->fetchAll(PDO::FETCH_COLUMN);
check('setup: 100 kg raw tomatoes in two batches; each batch carries the order price (4.00 and 5.00)', $kg($raw) == 100.0 && count($costs) === 2 && near((float)$costs[0], 4.0) && near((float)$costs[1], 5.0), json_encode($costs));

echo "\n== specifications\n";
$spec = ['code' => "WASH$u", 'name_en' => "Wash & pack $u", 'name_ar' => 'غسيل وتعبئة', 'input_item_id' => $raw, 'output_item_id' => $fin, 'byproduct_item_id' => $byp, 'planned_yield_pct' => 85, 'yield_tolerance_pct' => 3, 'waste_high_pct' => 20, 'max_minutes_outside' => 45];
check('viewer cannot create a specification (403)', http('POST', '/prep-specs', $spec, $viewer)['status'] === 403);
check('a bad code is refused (422)', http('POST', '/prep-specs', array_merge($spec, ['code' => 'x y']), $admin)['status'] === 422);
check('an unknown item is refused (422)', http('POST', '/prep-specs', array_merge($spec, ['code' => "B$u", 'input_item_id' => 999999]), $admin)['status'] === 422);
check('a percentage above 100 is refused (422)', http('POST', '/prep-specs', array_merge($spec, ['code' => "C$u", 'planned_yield_pct' => 150]), $admin)['status'] === 422);
$sp = http('POST', '/prep-specs', $spec, $admin);
$specId = (int)($sp['json']['id'] ?? 0);
check('specification created', $sp['status'] === 201 && $specId > 0);
check('the same code twice (409)', http('POST', '/prep-specs', $spec, $admin)['status'] === 409);
check('it is listed with the item names', in_array($specId, array_column(http('GET', '/prep-specs', null, $admin)['json'], 'id'), true));
$wr = http('GET', '/production-reports/waste-reasons', null, $admin)['json'];
check('waste reasons are seeded: trim, rot, damage, size, cosmetic with a class and a default decision', count($wr) >= 5 && !array_diff(['trim', 'rot', 'damage', 'size', 'cosmetic'], array_column($wr, 'code')));

echo "\n== order: plan, approve, cancel, shortage\n";
check('viewer cannot plan an order (403)', http('POST', '/prep-orders', ['spec_id' => $specId, 'planned_input_kg' => 100], $viewer)['status'] === 403);
check('planned kg must be positive (422)', http('POST', '/prep-orders', ['spec_id' => $specId, 'planned_input_kg' => 0], $admin)['status'] === 422);
check('an unknown specification (404)', http('POST', '/prep-orders', ['spec_id' => 999999, 'planned_input_kg' => 10], $admin)['status'] === 404);
$o1 = http('POST', '/prep-orders', ['spec_id' => $specId, 'planned_input_kg' => 100], $admin);
$id1 = (int)($o1['json']['id'] ?? 0);
check('order planned as Draft, numbered PRP-, 100 kg available so no shortage', $o1['status'] === 201 && $o1['json']['status'] === 'Draft' && str_starts_with($o1['json']['number'], 'PRP-') && $o1['json']['available_kg'] == 100.0 && $o1['json']['shortage_kg'] == 0.0, json_encode($o1['json']['shortage_kg'] ?? $o1['json']));
check('an unapproved order cannot start (409)', http('POST', "/prep-orders/$id1/start", [], $admin)['status'] === 409);
check('the planner cannot approve his own order (403)', http('POST', "/prep-orders/$id1/approve", null, $admin)['status'] === 403);
check('viewer cannot approve (403)', http('POST', "/prep-orders/$id1/approve", null, $viewer)['status'] === 403);
$ap = http('POST', "/prep-orders/$id1/approve", null, $m1);
check('a manager approves: Approved', $ap['status'] === 200 && $ap['json']['status'] === 'Approved' && $ap['json']['approved_by'] !== null);
check('approving twice (409)', http('POST', "/prep-orders/$id1/approve", null, $m1)['status'] === 409);
$big = http('POST', '/prep-orders', ['spec_id' => $specId, 'planned_input_kg' => 500], $admin)['json'];
check('an order bigger than the stock shows the shortage (400 kg)', $big['shortage_kg'] == 400.0, json_encode($big['shortage_kg']));
$rm = http('POST', "/prep-orders/{$big['id']}/request-materials", null, $admin);
check('"materials in stock? no" -> an automatic draft purchase request for the shortage', $rm['status'] === 201 && str_starts_with($rm['json']['number'], 'PR-') && $rm['json']['shortage_kg'] == 400.0, json_encode($rm['json']));
check('requesting when there is enough stock (409)', http('POST', "/prep-orders/$id1/request-materials", null, $admin)['status'] === 409);
check('a cancel needs a reason (422)', http('POST', "/prep-orders/{$big['id']}/cancel", [], $admin)['status'] === 422);
check('cancelled with a reason', http('POST', "/prep-orders/{$big['id']}/cancel", ['reason' => 'Supplier cannot deliver'], $admin)['json']['status'] === 'Cancelled');
check('a cancelled order cannot be approved or started (409)', http('POST', "/prep-orders/{$big['id']}/approve", null, $m1)['status'] === 409 && http('POST', "/prep-orders/{$big['id']}/start", [], $admin)['status'] === 409);

echo "\n== issue the material (oldest expiry first, at its real cost)\n";
$wip0 = $bal('1320'); $inv0 = $bal('1310');
check('issuing more than 10 % above the plan (422)', http('POST', "/prep-orders/$id1/start", ['kg' => 120], $admin)['status'] === 422);
$st = http('POST', "/prep-orders/$id1/start", [], $admin);
check('started: In_Progress, 100 kg issued from the two batches (40 then 60)', $st['status'] === 200 && $st['json']['status'] === 'In_Progress' && $st['json']['input_kg'] == 100.0 && count($st['json']['inputs']) === 2 && $st['json']['inputs'][0]['kg'] == 40.0 && $st['json']['inputs'][1]['kg'] == 60.0, json_encode($st['json']['inputs'] ?? $st['json']));
check('the material cost is the real batch cost: 40 x 4.00 + 60 x 5.00 = 460.00', near($st['json']['issued_cost'], 460.0) && near($st['json']['unit_cost'], 4.6, 0.0001), json_encode([$st['json']['issued_cost'], $st['json']['unit_cost']]));
check('the raw stock is gone and the timer is running', $kg($raw) == 0.0 && $st['json']['minutes_running'] !== null);
check('the ledger: Dr Work in progress 460 / Cr Inventory 460', near($bal('1320')['debit'] - $wip0['debit'], 460) && near($bal('1310')['credit'] - $inv0['credit'], 460));
check('starting twice (409)', http('POST', "/prep-orders/$id1/start", [], $admin)['status'] === 409);

echo "\n== food-safety logs (HACCP)\n";
$rs = fn($body) => http('POST', "/prep-orders/$id1/results", $body, $admin);
$okRes = ['outputs' => [['grade' => 'A', 'kg' => 70], ['grade' => 'B', 'kg' => 8]], 'waste' => [['reason_code' => 'trim', 'kg' => 12, 'decision' => 'by_product'], ['reason_code' => 'rot', 'kg' => 4, 'decision' => 'destroy'], ['reason_code' => 'cosmetic', 'kg' => 5, 'decision' => 'scrap_sale', 'scrap_income' => 6]], 'yield_reason' => 'Soft tomatoes in the second batch'];
check('results before the wash-water and sanitiser logs are refused (409)', $rs($okRes)['status'] === 409);
check('a log kind must be known (422)', http('POST', '/haccp-logs', ['kind' => 'smell', 'prep_order_id' => $id1, 'value' => 1], $admin)['status'] === 422);
check('wash water 15 °C is above the 10 °C limit: needs a corrective action (422)', http('POST', '/haccp-logs', ['kind' => 'wash_water', 'prep_order_id' => $id1, 'value' => 15], $admin)['status'] === 422);
$bad = http('POST', '/haccp-logs', ['kind' => 'wash_water', 'prep_order_id' => $id1, 'value' => 15, 'corrective_action' => 'Added ice, re-measured'], $admin);
check('with a corrective action it is stored as NOT OK', $bad['status'] === 201 && (int)$bad['json']['ok'] === 0);
check('wash water 6 °C is OK', (int)http('POST', '/haccp-logs', ['kind' => 'wash_water', 'prep_order_id' => $id1, 'value' => 6], $admin)['json']['ok'] === 1);
check('sanitiser 50 ppm is below 100: needs a corrective action (422)', http('POST', '/haccp-logs', ['kind' => 'sanitiser', 'prep_order_id' => $id1, 'value' => 50], $admin)['status'] === 422);
check('sanitiser 150 ppm is OK', (int)http('POST', '/haccp-logs', ['kind' => 'sanitiser', 'prep_order_id' => $id1, 'value' => 150], $admin)['json']['ok'] === 1);
check('a hygiene check needs the name of the person (422)', http('POST', '/haccp-logs', ['kind' => 'hygiene', 'passed' => true], $admin)['status'] === 422);
check('a hygiene check is stored', (int)http('POST', '/haccp-logs', ['kind' => 'hygiene', 'person' => 'Ahmed', 'passed' => true], $admin)['json']['ok'] === 1);
check('viewer cannot write logs (403)', http('POST', '/haccp-logs', ['kind' => 'wash_water', 'value' => 5], $viewer)['status'] === 403);
$draftOrder = http('POST', '/prep-orders', ['spec_id' => $specId, 'planned_input_kg' => 5], $admin)['json']['id'];
check('a log on an order that has not been approved (409)', http('POST', '/haccp-logs', ['kind' => 'wash_water', 'prep_order_id' => $draftOrder, 'value' => 5], $admin)['status'] === 409);
check('logs are listed and can be filtered to the unsafe ones', count(http('GET', '/haccp-logs?ok=0', null, $admin)['json']) >= 1 && count(http('GET', "/haccp-logs?prep_order_id=$id1", null, $admin)['json']) === 3);

echo "\n== results: yield, waste, balance\n";
check('weighing nothing (422)', $rs(['outputs' => [], 'waste' => []])['status'] === 422);
check('a grade must be A, B or C (422)', $rs(['outputs' => [['grade' => 'X', 'kg' => 10]], 'waste' => []])['status'] === 422);
check('a waste reason must be from the list (422)', $rs(['outputs' => [['grade' => 'A', 'kg' => 80]], 'waste' => [['reason_code' => 'made_up', 'kg' => 20, 'decision' => 'destroy']]])['status'] === 422);
check('every waste line needs a decision (422)', $rs(['outputs' => [['grade' => 'A', 'kg' => 80]], 'waste' => [['reason_code' => 'rot', 'kg' => 20]]])['status'] === 422);
check('a scrap sale needs the income (422)', $rs(['outputs' => [['grade' => 'A', 'kg' => 80]], 'waste' => [['reason_code' => 'rot', 'kg' => 20, 'decision' => 'scrap_sale']]])['status'] === 422);
check('output + waste above what was issued (422)', $rs(['outputs' => [['grade' => 'A', 'kg' => 90]], 'waste' => [['reason_code' => 'rot', 'kg' => 20, 'decision' => 'destroy']]])['status'] === 422);
$un = $rs(['outputs' => [['grade' => 'A', 'kg' => 80]], 'waste' => [['reason_code' => 'rot', 'kg' => 5, 'decision' => 'destroy']], 'yield_reason' => 'x']);
check('15 kg unaccounted: "weigh the waste by reason" (422)', $un['status'] === 422 && stripos($un['json']['error'], 'unaccounted') !== false, json_encode($un['json']));
$ly = $rs(['outputs' => [['grade' => 'A', 'kg' => 70], ['grade' => 'B', 'kg' => 8]], 'waste' => $okRes['waste']]);
check('a yield below the plan needs a reason (422)', $ly['status'] === 422 && stripos($ly['json']['error'], 'below the plan') !== false, json_encode($ly['json']));
$r1 = $rs($okRes);
check('results saved: Awaiting_Inspection, yield 78.00 %, waste 21.00 %', $r1['status'] === 200 && $r1['json']['status'] === 'Awaiting_Inspection' && near($r1['json']['yield_pct'], 78.0, 0.01) && near($r1['json']['waste_pct'], 21.0, 0.01), json_encode($r1['json']['status'] ?? $r1['json']));
check('waste 21 % is above the 20 % limit: the result is "waste_high"', $r1['json']['result'] === 'waste_high');
check('the timer is stopped and the minutes recorded', $r1['json']['finished_at'] !== null && $r1['json']['minutes_outside'] !== null && (int)$r1['json']['over_time'] === 0);
check('three waste lines saved with their classes (trim low, rot low, cosmetic high)', count($r1['json']['waste']) === 3 && $r1['json']['waste'][2]['waste_class'] === 'high');

echo "\n== final inspection and put-away\n";
$store = ['store' => [['grade' => 'A', 'cold_room_id' => $room, 'rack' => 'F1'], ['grade' => 'B', 'cold_room_id' => $room]], 'waste_room_id' => $room];
check('the person who entered the results cannot inspect (403)', http('POST', "/prep-orders/$id1/sign-off", $store, $admin)['status'] === 403);
check('viewer cannot inspect (403)', http('POST', "/prep-orders/$id1/sign-off", $store, $viewer)['status'] === 403);
check('a cold room for every finished grade is required (422)', http('POST', "/prep-orders/$id1/sign-off", ['store' => [['grade' => 'A', 'cold_room_id' => $room]], 'waste_room_id' => $room], $m1)['status'] === 422);
check('a cold room for the by-product is required (422)', http('POST', "/prep-orders/$id1/sign-off", ['store' => $store['store']], $m1)['status'] === 422);
$fin0 = $bal('1310'); $wip1 = $bal('1320'); $wl0 = $bal('5300');
$so = http('POST', "/prep-orders/$id1/sign-off", $store, $m1);
check('a named inspector signs off: Completed', $so['status'] === 200 && $so['json']['status'] === 'Completed' && $so['json']['inspected_by'] !== null, json_encode($so['json']['status'] ?? $so['json']));
check('finished goods are in the cold room: 70 kg grade A, 8 kg grade B', $kg($fin, 'A') == 70.0 && $kg($fin, 'B') == 8.0 && $kg($fin) == 78.0);
check('the by-product (trim) is back in stock: 12 kg', $kg($byp) == 12.0);
$bat = $pdo->query("SELECT unit_cost, prep_order_id FROM item_batches WHERE item_id = $fin AND grade = 'A'")->fetch();
check('the finished batch costs 4.60/kg and knows the order that made it (traceability)', near((float)$bat['unit_cost'], 4.6, 0.001) && (int)$bat['prep_order_id'] === $id1, json_encode($bat));
$ec = $entryOf('prep_complete', $id1);
check('entry: Dr Inventory 414.00 (finished + by-product), Dr Waste/shrinkage 4.60 (1 kg unaccounted), Cr WIP 418.60', near($amt($ec, '1310', 'debit'), 414.0) && near($amt($ec, '5300', 'debit'), 4.6) && near($amt($ec, '1320', 'credit'), 418.6), json_encode($ec['lines'] ?? $ec));
$w = $so['json']['waste'];
check('rework / by-product lines are Done, destroy and scrap sale wait for Finance', $w[0]['status'] === 'Done' && $w[1]['status'] === 'Pending' && $w[2]['status'] === 'Pending' && near((float)$w[1]['cost'], 18.4) && near((float)$w[2]['cost'], 23.0));
check('work in progress of this order is 41.40 (the two waste lines still to be decided)', near($bal('1320')['debit'] - $bal('1320')['credit'] - ($wip0['debit'] - $wip0['credit']), 41.4), json_encode([$bal('1320'), $wip0]));
check('a signed-off order cannot be signed off again (409)', http('POST', "/prep-orders/$id1/sign-off", $store, $m1)['status'] === 409);

echo "\n== waste decisions: Finance approves the write-off\n";
$rot = $w[1]['id']; $cos = $w[2]['id'];
check('viewer cannot approve a write-off (403)', http('POST', "/prep-orders/$id1/waste/$rot/decide", ['action' => 'approve'], $viewer)['status'] === 403);
check('the inspector cannot approve the write-off of his own order (403)', http('POST', "/prep-orders/$id1/waste/$rot/decide", ['action' => 'approve'], $m1)['status'] === 403);
check('returning a decision needs a note (422)', http('POST', "/prep-orders/$id1/waste/$rot/decide", ['action' => 'reject'], $m2)['status'] === 422);
$rj = http('POST', "/prep-orders/$id1/waste/$rot/decide", ['action' => 'reject', 'note' => 'Some of it can still be reworked'], $m2);
check('Finance returns the rot decision: Returned', $rj['status'] === 200 && $rj['json']['waste'][1]['status'] === 'Returned');
check('re-deciding "rework" needs a cold room (422)', http('POST', "/prep-orders/$id1/waste/$rot/redecide", ['decision' => 'rework'], $m1)['status'] === 422);
$raw0 = $kg($raw);
$rd = http('POST', "/prep-orders/$id1/waste/$rot/redecide", ['decision' => 'rework', 'cold_room_id' => $room], $m1);
check('re-decided as rework: 4 kg back in raw stock, Done', $rd['status'] === 200 && $rd['json']['waste'][1]['status'] === 'Done' && $kg($raw) - $raw0 == 4.0);
$er = $entryOf('prep_waste', (int)$rot);
check('entry: Dr Inventory 18.40 / Cr Work in progress 18.40', near($amt($er, '1310', 'debit'), 18.4) && near($amt($er, '1320', 'credit'), 18.4));
$ap2 = http('POST', "/prep-orders/$id1/waste/$cos/decide", ['action' => 'approve'], $m2);
check('the scrap sale is approved: Done', $ap2['status'] === 200 && $ap2['json']['waste'][2]['status'] === 'Done', json_encode($ap2['json']['error'] ?? null));
$es = $entryOf('prep_waste', (int)$cos);
check('entry: Dr Cash 6.00 (income), Dr Waste loss 17.00, Cr Work in progress 23.00', near($amt($es, '1120', 'debit'), 6.0) && near($amt($es, '5300', 'debit'), 17.0) && near($amt($es, '1320', 'credit'), 23.0), json_encode($es['lines'] ?? $es));
check('a decided waste line cannot be decided again (409)', http('POST', "/prep-orders/$id1/waste/$cos/decide", ['action' => 'approve'], $m2)['status'] === 409);
$wipNet = fn($b) => $b['debit'] - $b['credit'];
check('work in progress of this order is now exactly zero: the whole 460.00 is accounted for', near($wipNet($bal('1320')) - $wipNet($wip0), 0.0, 0.015), json_encode([$bal('1320'), $wip0]));
$tb = http('GET', '/finance-reports/trial-balance', null, $admin)['json'];
check('the trial balance is still balanced', $tb['balanced'] === true);

echo "\n== the clock: time outside the cold room\n";
$poC = $mkpo(50, 5.0);
http('PUT', '/settings', ['receiving.allow_direct_po_receipt' => true], $admin);
http('POST', '/stock/receive-po', ['po_id' => $poC, 'lines' => [['item_id' => $raw, 'qty' => 50, 'cold_room_id' => $room]]], $admin);
http('PUT', '/settings', ['receiving.allow_direct_po_receipt' => false], $admin);
$id2 = (int)http('POST', '/prep-orders', ['spec_id' => $specId, 'planned_input_kg' => 20], $admin)['json']['id'];
http('POST', "/prep-orders/$id2/approve", null, $m1);
http('POST', "/prep-orders/$id2/start", [], $admin);
foreach ([['wash_water', 5], ['sanitiser', 150]] as [$k, $v]) http('POST', '/haccp-logs', ['kind' => $k, 'prep_order_id' => $id2, 'value' => $v], $admin);
$pdo->exec("UPDATE prep_orders SET started_at = DATE_SUB(NOW(), INTERVAL 100 MINUTE) WHERE id = $id2");
$dl = http('GET', "/prep-orders/$id2", null, $admin)['json'];
check('a running order past its 45 minutes is flagged', $dl['over_time_now'] === true && $dl['minutes_running'] >= 100);
check('the dashboard counts it as over time', http('GET', '/production-reports/summary', null, $admin)['json']['over_time_running'] >= 1);
$res2 = ['outputs' => [['grade' => 'A', 'kg' => 17]], 'waste' => [['reason_code' => 'trim', 'kg' => 3, 'decision' => 'by_product']]];
$o2 = http('POST', "/prep-orders/$id2/results", $res2, $admin);
check('100 minutes outside the cold room needs a note (422)', $o2['status'] === 422 && stripos($o2['json']['error'], 'minutes') !== false, json_encode($o2['json']));
$o2 = http('POST', "/prep-orders/$id2/results", array_merge($res2, ['overtime_note' => 'Line stopped for 40 minutes: power cut']), $admin);
check('with the note it is saved, over_time = 1 and the 100 minutes are recorded', $o2['status'] === 200 && (int)$o2['json']['over_time'] === 1 && $o2['json']['minutes_outside'] >= 100 && $o2['json']['result'] === 'high_quality', json_encode($o2['json']['status'] ?? $o2['json']));
check('results can be corrected while awaiting inspection', http('POST', "/prep-orders/$id2/results", array_merge($res2, ['overtime_note' => 'Power cut']), $admin)['status'] === 200);
$so2 = http('POST', "/prep-orders/$id2/sign-off", ['store' => [['grade' => 'A', 'cold_room_id' => $room]], 'waste_room_id' => $room], $m1);
check('the second order is signed off (17 kg more finished goods)', $so2['json']['status'] === 'Completed' && $kg($fin) == 95.0, json_encode($so2['json']['status'] ?? $so2['json']));

echo "\n== reports and traceability\n";
$sm = http('GET', '/production-reports/summary', null, $admin)['json'];
check('summary: orders completed this month and the month yield', $sm['month_input_kg'] >= 120 && $sm['month_yield_pct'] > 0 && $sm['unsafe_logs_week'] >= 1);
$yr = http('GET', '/production-reports/yield?from=' . date('Y-m-01') . '&to=' . date('Y-m-t'), null, $admin)['json'];
$mine = array_values(array_filter($yr['by_spec'], fn($s) => $s['spec_id'] === $specId))[0] ?? null;
check('yield by product: 2 orders, 120 kg in, 95 kg out, 1 over-time order', $mine && $mine['orders'] === 2 && near($mine['input_kg'], 120, 0.01) && near($mine['output_kg'], 95, 0.01) && $mine['over_time_orders'] === 1, json_encode($mine));
$sl = array_values(array_filter($yr['by_supplier'], fn($s) => $s['id'] === $sup))[0] ?? null;
check('loss by supplier follows the material: waste kg and loss % per supplier', $sl && $sl['input_kg'] >= 120 && $sl['waste_kg'] > 0 && $sl['loss_pct'] > 0, json_encode($sl));
check('loss by cold room is reported too', count($yr['by_cold_room']) >= 1 && $yr['by_cold_room'][0]['loss_pct'] > 0);
check('waste by reason lists trim, rot and cosmetic with their kg', count(array_filter($yr['by_waste_reason'], fn($r) => in_array($r['reason_code'], ['trim', 'rot', 'cosmetic'], true))) === 3);
check('every output batch points back to its order (recall starts here)', (int)$pdo->query("SELECT COUNT(*) FROM item_batches WHERE prep_order_id IN ($id1, $id2)")->fetchColumn() >= 4);
check('the list shows status, yield, over-time and pending waste', ($l = http('GET', '/prep-orders?status=Completed', null, $admin)['json']) && count($l) >= 2 && isset($l[0]['waste_pending']) && isset($l[0]['yield_pct']));

echo "\n== company isolation and module switch\n";
$co = http('POST', '/companies', ['name_en' => "Other $u"], $admin)['json']['id'];
$adminId = (int)$pdo->query("SELECT id FROM users WHERE email = 'admin@manzuma.com'")->fetchColumn();
$pdo->exec("INSERT IGNORE INTO user_companies (user_id, company_id) VALUES ($adminId, $co)");
check('a new company gets the waste reasons and its own chart (WIP account)', count(http('GET', '/production-reports/waste-reasons', null, $admin, $co)['json']) >= 5);
check('company 2 sees no specifications, orders or logs', count(http('GET', '/prep-specs', null, $admin, $co)['json']) === 0 && count(http('GET', '/prep-orders', null, $admin, $co)['json']) === 0 && count(http('GET', '/haccp-logs', null, $admin, $co)['json']) === 0);
check('company 2 cannot read, approve, start or sign off company 1 orders (404)', http('GET', "/prep-orders/$id1", null, $admin, $co)['status'] === 404 && http('POST', "/prep-orders/$id2/approve", null, $admin, $co)['status'] === 404 && http('POST', "/prep-orders/$id2/start", [], $admin, $co)['status'] === 404 && http('POST', "/prep-orders/$id1/sign-off", $store, $admin, $co)['status'] === 404);
check('company 2 cannot decide company 1 waste (404)', http('POST', "/prep-orders/$id1/waste/$cos/decide", ['action' => 'approve'], $admin, $co)['status'] === 404);
check('company 2 cannot plan an order on a company 1 specification (404)', http('POST', '/prep-orders', ['spec_id' => $specId, 'planned_input_kg' => 5], $admin, $co)['status'] === 404);
check('company 2 cannot log against a company 1 order (404)', http('POST', '/haccp-logs', ['kind' => 'wash_water', 'prep_order_id' => $id2, 'value' => 5], $admin, $co)['status'] === 404);
http('PUT', '/modules/manufacturing', ['is_enabled' => false], $admin);
check('closing the Production module blocks it (403)', http('GET', '/prep-orders', null, $admin)['status'] === 403 && http('GET', '/haccp-logs', null, $admin)['status'] === 403 && http('GET', '/production-reports/summary', null, $admin)['status'] === 403);
http('PUT', '/modules/manufacturing', ['is_enabled' => true], $admin);
check('re-opened: the data is back', http('GET', "/prep-orders/$id1", null, $admin)['status'] === 200);

echo "\n------------------------------------\n$pass passed, $fail failed\n";
if ($fail) { echo "Failed: " . implode('; ', $fails) . "\n"; exit(1); }
