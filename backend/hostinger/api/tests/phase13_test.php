<?php
// Phase 7 (suite 13): sorting into three sellable classes, the 80 % rule, incident reports, stock and accounting.
//   php hostinger/api/tests/phase13_test.php http://127.0.0.1:8200/api  mysql-dsn user pass
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
foreach ([["ss_$u@x.com", $role('sorter')], ["sq_$u@x.com", $role('quality_inspector')], ["sm_$u@x.com", 2], ["sv_$u@x.com", 4]] as [$e, $r]) http('POST', '/users', ['name' => $e, 'email' => $e, 'password' => 'Strong-Pass-123', 'role_id' => $r], $admin);
$sorter = login("ss_$u@x.com", 'Strong-Pass-123'); $qc = login("sq_$u@x.com", 'Strong-Pass-123'); $mgr = login("sm_$u@x.com", 'Strong-Pass-123'); $viewer = login("sv_$u@x.com", 'Strong-Pass-123');
$set = fn(array $s) => http('PUT', '/settings', $s, $admin);
$set(['receiving.three_person_rule' => false, 'fleet.require_entry_permit' => false, 'sorting.min_class1_pct' => 80, 'sorting.balance_tolerance_pct' => 2, 'sorting.require_incident_below_min' => true]);
$main = (int)http('GET', '/me', null, $admin)['json']['branches'][0]['id'];
$B1 = (int)http('POST', '/company-branches', ['code' => "SB$u", 'name_en' => "Sort Jeddah $u"], $admin)['json']['id'];
$sup = http('POST', '/suppliers', ['code' => "SS$u", 'name' => "Farm $u"], $admin)['json']['id'];
$tom = http('POST', '/items', ['code' => "ST$u", 'name' => "SO Tomato $u", 'shelf_life_days' => 7, 'needs_sorting' => 1], $admin)['json']['id'];
$cuc = http('POST', '/items', ['code' => "SC$u", 'name' => "SO Cucumber $u", 'shelf_life_days' => 7, 'needs_sorting' => 1], $admin)['json']['id'];
$wh = http('POST', '/warehouses', ['code' => "SW$u", 'name' => "SO WH $u", 'type' => 'cold'], $admin)['json']['id'];
$mkroom = fn(string $c) => http('POST', '/cold-rooms', ['warehouse_id' => $wh, 'code' => "$c$u", 'name' => "SO room $c $u", 'temp_min' => 2, 'temp_max' => 8], $GLOBALS['admin'])['json']['id'];
$r1 = $mkroom('R1'); $r2 = $mkroom('R2'); $r3 = $mkroom('R3');
$rooms = ['1' => $r1, '2' => $r2, '3' => $r3];

/** a truck routed to sorting: arrival, quality (admin), sign-off (manager), route to sorting */
$toSorting = function (int $item, float $kg, ?int $branch = null) use ($admin, $mgr, $sup, $pdo) {
    $p = http('POST', '/purchase-orders', ['date' => date('Y-m-d'), 'supplier_id' => $sup, 'supplier_name' => 'x', 'lines' => [['item_id' => $item, 'item_name' => 'T', 'qty' => $kg, 'rate' => 5, 'amount' => $kg * 5]]], $admin, null, $branch)['json'];
    $pdo->exec("UPDATE purchase_orders SET status = 'Approved' WHERE id = {$p['id']}");
    $id = (int)http('POST', '/deliveries', ['po_id' => $p['id'], 'driver_name' => 'Ali', 'vehicle_plate' => 'SO ' . $p['id']], $admin)['json']['id'];
    http('POST', "/deliveries/$id/weigh-in", ['gross_kg' => $kg + 1000], $admin);
    http('POST', "/deliveries/$id/temperature", ['truck_air_temp' => 4, 'product_temp' => 4], $admin);
    http('POST', "/deliveries/$id/quality", ['lines' => [['item_id' => $item, 'grade' => 'A', 'received_kg' => $kg]]], $admin);
    http('POST', "/deliveries/$id/weigh-out", ['tare_kg' => 1000], $admin);
    http('POST', "/deliveries/$id/accept", null, $mgr);
    http('POST', "/deliveries/$id/route", ['to' => 'sorting'], $admin);
    return [$id, (int)$p['id']];
};
$lineOf = fn(array $run, int $i = 0) => (int)$run['lines'][$i]['id'];
$res = fn(int $line, float $c1, float $c2, float $c3, float $sh = 0) => ['lines' => [['line_id' => $line, 'class1_kg' => $c1, 'class2_kg' => $c2, 'class3_kg' => $c3, 'shrink_kg' => $sh]]];

echo "\n== starting a sorting run\n";
[$D1, $PO1] = $toSorting($tom, 500);
check('the truck waits in the sorting queue', in_array($D1, array_column(http('GET', '/sorting-runs/queue', null, $admin)['json'], 'id')));
check('a read-only user cannot start sorting (403)', http('POST', '/sorting-runs', ['delivery_id' => $D1], $viewer)['status'] === 403);
check('an unknown delivery (404)', http('POST', '/sorting-runs', ['delivery_id' => 999999], $sorter)['status'] === 404);
$run = http('POST', '/sorting-runs', ['delivery_id' => $D1], $sorter);
$S1 = (int)($run['json']['id'] ?? 0);
check('the sorter starts a run: lines come from what quality accepted (500 kg)', $run['status'] === 201 && strpos($run['json']['number'], 'SR-') === 0 && count($run['json']['lines']) === 1 && $run['json']['lines'][0]['input_kg'] == 500.0 && $run['json']['status'] === 'In_Progress', json_encode($run['json']['error'] ?? null));
check('the same truck cannot be sorted twice at once (409)', http('POST', '/sorting-runs', ['delivery_id' => $D1], $sorter)['status'] === 409);

echo "\n== the sorter enters the three classes\n";
$L1 = $lineOf($run['json']);
check('every line must be entered (422)', http('POST', "/sorting-runs/$S1/results", ['lines' => []], $sorter)['status'] === 422);
check('an unknown line (422)', http('POST', "/sorting-runs/$S1/results", ['lines' => [['line_id' => 999999, 'class1_kg' => 500]]], $sorter)['status'] === 422);
check('a negative quantity (422)', http('POST', "/sorting-runs/$S1/results", $res($L1, -5, 300, 205), $sorter)['status'] === 422);
$bad = http('POST', "/sorting-runs/$S1/results", $res($L1, 400, 60, 10), $sorter);
check('classes that do not add up to the 500 kg that came in are refused (422)', $bad['status'] === 422 && stripos($bad['json']['error'], 'add up') !== false, json_encode($bad['json']));
$ok = http('POST', "/sorting-runs/$S1/results", $res($L1, 400, 60, 30, 10), $sorter);
check('400 / 60 / 30 + 10 shrink is accepted: class 1 is exactly 80 %, not below the limit', $ok['status'] === 200 && $ok['json']['status'] === 'Awaiting_Signoff' && $ok['json']['lines'][0]['class1_pct'] == 80.0 && $ok['json']['lines'][0]['below_min'] === false && $ok['json']['below_min'] === false, json_encode($ok['json']['error'] ?? $ok['json']['lines'][0]['class1_pct'] ?? null));
check('no incident report is needed or accepted at 80 % (409)', http('POST', "/sorting-runs/$S1/incident", ['reason_code' => 'poor_harvest'], $qc)['status'] === 409);

echo "\n== quality sign-off puts the three classes in stock\n";
check('the sorter cannot sign off (403: no sign-off right)', http('POST', "/sorting-runs/$S1/sign-off", ['rooms' => $rooms], $sorter)['status'] === 403);
[$D0] = $toSorting($tom, 100);
$run0 = http('POST', '/sorting-runs', ['delivery_id' => $D0], $admin)['json']; $S0 = (int)$run0['id'];
http('POST', "/sorting-runs/$S0/results", $res($lineOf($run0), 90, 5, 5, 0), $admin);
check('whoever entered the results cannot also sign them off (403)', http('POST', "/sorting-runs/$S0/sign-off", ['rooms' => $rooms], $admin)['status'] === 403);
http('POST', "/sorting-runs/$S0/cancel", ['reason' => 'rule test'], $admin);
check('a cold room for every class that has kilos (422)', http('POST', "/sorting-runs/$S1/sign-off", ['rooms' => ['1' => $r1]], $qc)['status'] === 422);
check('an unknown cold room is refused (404)', http('POST', "/sorting-runs/$S1/sign-off", ['rooms' => ['1' => $r1, '2' => 999999, '3' => $r3]], $qc)['status'] === 404);
check('nothing was stored by the refused attempts', (int)$pdo->query("SELECT COUNT(*) FROM sorting_outputs WHERE run_id = $S1")->fetchColumn() === 0);
$sg = http('POST', "/sorting-runs/$S1/sign-off", ['rooms' => $rooms], $qc);
check('the quality person signs off: Completed with three output batches', $sg['status'] === 200 && $sg['json']['status'] === 'Completed' && count($sg['json']['outputs']) === 3, json_encode($sg['json']['error'] ?? null));
$o = []; foreach ($sg['json']['outputs'] as $x) $o[$x['class_no']] = $x;
check('class 1 = grade A 400 kg, class 2 = grade B 60 kg, class 3 = grade C 30 kg', ($o[1]['grade'] ?? '') === 'A' && $o[1]['kg'] == 400.0 && ($o[2]['grade'] ?? '') === 'B' && $o[2]['kg'] == 60.0 && ($o[3]['grade'] ?? '') === 'C' && $o[3]['kg'] == 30.0);
check('each class lies in the room chosen for it', (int)$o[1]['cold_room_id'] === $r1 && (int)$o[2]['cold_room_id'] === $r2 && (int)$o[3]['cold_room_id'] === $r3);
check('the shrink is absorbed: each sellable kg costs 500 x 5.00 / 490 = 5.1020', abs((float)$o[1]['unit_cost'] - 5.102) < 0.001, json_encode($o[1]['unit_cost']));
$bal = http('GET', "/stock/balance?item_id=$tom", null, $admin)['json']; $kgA = 0; foreach ($bal as $b) if ($b['grade'] === 'A' && in_array((int)$b['cold_room_id'], [$r1], true)) $kgA += $b['qty'];
check('the stock balance shows the grade A batch', $kgA == 400.0, json_encode($kgA));
$gl = $pdo->query("SELECT a.system_key, l.debit, l.credit FROM gl_lines l JOIN gl_accounts a ON a.id = l.account_id WHERE l.entry_id = " . (int)$sg['json']['gl_entry_id'])->fetchAll();
$g = []; foreach ($gl as $x) $g[$x['system_key']] = $x;
check('accounting: Dr inventory 2,500 / Cr goods received not invoiced 2,500 (what the truck brought)', (float)($g['inventory']['debit'] ?? 0) == 2500.0 && (float)($g['grni']['credit'] ?? 0) == 2500.0, json_encode($g));
check('the delivery is Stored and the purchase order Received', http('GET', "/deliveries/$D1", null, $admin)['json']['status'] === 'Stored' && $pdo->query("SELECT status FROM purchase_orders WHERE id = $PO1")->fetchColumn() === 'Received');
check('a completed run cannot be changed or signed twice (409)', http('POST', "/sorting-runs/$S1/results", $res($L1, 500, 0, 0), $sorter)['status'] === 409 && http('POST', "/sorting-runs/$S1/sign-off", ['rooms' => $rooms], $qc)['status'] === 409);

echo "\n== a load below 80 % needs an incident report\n";
[$D2] = $toSorting($cuc, 500);
$run2 = http('POST', '/sorting-runs', ['delivery_id' => $D2], $sorter)['json']; $S2 = (int)$run2['id'];
$r = http('POST', "/sorting-runs/$S2/results", $res($lineOf($run2), 350, 100, 40, 10), $sorter)['json'];
check('class 1 is 70 %: the line is flagged below the minimum', $r['lines'][0]['class1_pct'] == 70.0 && $r['lines'][0]['below_min'] === true && $r['below_min'] === true && $r['lines'][0]['min_pct'] == 80.0);
$blocked = http('POST', "/sorting-runs/$S2/sign-off", ['rooms' => $rooms], $qc);
check('sign-off is refused until an incident report is filed (409)', $blocked['status'] === 409 && stripos($blocked['json']['error'], 'incident') !== false, json_encode($blocked['json']));
check('the sorter cannot file the report (403)', http('POST', "/sorting-runs/$S2/incident", ['reason_code' => 'poor_harvest'], $sorter)['status'] === 403);
check('the reason must come from the list (422)', http('POST', "/sorting-runs/$S2/incident", ['reason_code' => 'because'], $qc)['status'] === 422);
check('"other" needs a note (422)', http('POST', "/sorting-runs/$S2/incident", ['reason_code' => 'other'], $qc)['status'] === 422);
$inc = http('POST', "/sorting-runs/$S2/incident", ['reason_code' => 'weather_damage', 'note' => 'rain two days before harvest'], $qc);
check('the quality person files the incident report', $inc['status'] === 200 && $inc['json']['incident']['reason_code'] === 'weather_damage' && $inc['json']['incident']['status'] === 'Open' && (int)$inc['json']['incident']['supplier_id'] === $sup, json_encode($inc['json']['error'] ?? null));
check('now the sign-off works', http('POST', "/sorting-runs/$S2/sign-off", ['rooms' => $rooms], $qc)['json']['status'] === 'Completed');

echo "\n== the manager reviews the incident\n";
$IID = (int)$inc['json']['incident']['id'];
check('the incident list shows it', in_array($IID, array_column(http('GET', '/sorting-runs/incidents', null, $admin)['json'], 'id')));
check('the person who filed it cannot review it (403)', http('POST', "/sorting-runs/incidents/$IID/review", ['note' => 'ok'], $qc)['status'] === 403);
check('a review needs a note (422)', http('POST', "/sorting-runs/incidents/$IID/review", [], $mgr)['status'] === 422);
$rv = http('POST', "/sorting-runs/incidents/$IID/review", ['note' => 'supplier warned', 'claim_supplier' => true], $mgr);
check('the manager reviews it and marks it as a claim against the supplier', $rv['status'] === 200 && $rv['json']['status'] === 'Reviewed' && (int)$rv['json']['claim_supplier'] === 1, json_encode($rv['json']['error'] ?? null));
check('an incident is reviewed once (409)', http('POST', "/sorting-runs/incidents/$IID/review", ['note' => 'again'], $mgr)['status'] === 409);

echo "\n== the limit is a setting (company and per item)\n";
$itemLimit = http('POST', '/items', ['code' => "SL$u", 'name' => "SO Pepper $u", 'shelf_life_days' => 7, 'min_class1_pct' => 60], $admin)['json']['id'];
[$D3] = $toSorting($itemLimit, 400);
$run3 = http('POST', '/sorting-runs', ['delivery_id' => $D3], $sorter)['json']; $S3 = (int)$run3['id'];
$r3x = http('POST', "/sorting-runs/$S3/results", $res($lineOf($run3), 260, 100, 40, 0), $sorter)['json'];
check('an item with its own minimum of 60 %: 65 % is fine, no report needed', $r3x['lines'][0]['min_pct'] == 60.0 && $r3x['below_min'] === false && http('POST', "/sorting-runs/$S3/sign-off", ['rooms' => $rooms], $qc)['json']['status'] === 'Completed');
$set(['sorting.min_class1_pct' => 90]);
[$D4] = $toSorting($tom, 200);
$run4 = http('POST', '/sorting-runs', ['delivery_id' => $D4], $sorter)['json']; $S4 = (int)$run4['id'];
check('with the company limit raised to 90 %, an 85 % load is below it', http('POST', "/sorting-runs/$S4/results", $res($lineOf($run4), 170, 20, 10, 0), $sorter)['json']['below_min'] === true);
$set(['sorting.require_incident_below_min' => false]);
check('and the report requirement itself can be switched off', http('POST', "/sorting-runs/$S4/sign-off", ['rooms' => $rooms], $qc)['json']['status'] === 'Completed');
$set(['sorting.min_class1_pct' => 80, 'sorting.require_incident_below_min' => true]);

echo "\n== cancelling, the report, branches, companies\n";
[$D5] = $toSorting($tom, 100);
$run5 = http('POST', '/sorting-runs', ['delivery_id' => $D5], $sorter)['json']; $S5 = (int)$run5['id'];
check('cancel needs a reason (422)', http('POST', "/sorting-runs/$S5/cancel", [], $sorter)['status'] === 422);
check('the sorter cancels; the truck is still waiting for sorting', http('POST', "/sorting-runs/$S5/cancel", ['reason' => 'wrong truck'], $sorter)['json']['status'] === 'Cancelled' && http('GET', "/deliveries/$D5", null, $admin)['json']['status'] === 'To_Sorting');
check('and a new run can be started for it', http('POST', '/sorting-runs', ['delivery_id' => $D5], $sorter)['status'] === 201);
$rep = http('GET', '/sorting-runs/report', null, $admin)['json']; $mine = array_values(array_filter($rep, fn($x) => (int)$x['supplier_id'] === $sup))[0] ?? null;
check('the supplier report adds up the completed runs: class shares and incidents', $mine && $mine['runs'] >= 3 && $mine['incidents'] >= 1 && $mine['class1_pct'] > 0 && $mine['input_kg'] >= 1400, json_encode($mine));
[$DJ] = $toSorting($tom, 100, $B1);
$runJ = http('POST', '/sorting-runs', ['delivery_id' => $DJ], $sorter, null, $B1)['json'];
check('a Jeddah run belongs to Jeddah and is hidden from the main branch (404)', (int)$runJ['branch_id'] === $B1 && http('GET', "/sorting-runs/{$runJ['id']}", null, $admin, null, $main)['status'] === 404 && http('GET', "/sorting-runs/{$runJ['id']}", null, $admin, null, $B1)['status'] === 200);
$co = http('POST', '/companies', ['name_en' => "SO other $u"], $admin)['json']['id'];
$adminId = (int)$pdo->query("SELECT id FROM users WHERE email = 'admin@manzuma.com'")->fetchColumn();
$pdo->exec("INSERT IGNORE INTO user_companies (user_id, company_id) VALUES ($adminId, $co)");
check('company 2 cannot see, change or sign off company 1\'s sorting (404) and has an empty queue', http('GET', "/sorting-runs/$S5", null, $admin, $co)['status'] === 404 && http('POST', "/sorting-runs/$S5/cancel", ['reason' => 'x'], $admin, $co)['status'] === 404 && http('POST', '/sorting-runs', ['delivery_id' => $D5], $admin, $co)['status'] === 404 && count(http('GET', '/sorting-runs/queue', null, $admin, $co)['json']) === 0 && count(http('GET', '/sorting-runs/incidents', null, $admin, $co)['json']) === 0);
check('a new company gets the sorting settings', (int)$pdo->query("SELECT COUNT(*) FROM settings WHERE company_id = $co AND setting_key LIKE 'sorting.%'")->fetchColumn() === 3);

echo "\n------------------------------------\n$pass passed, $fail failed\n";
if ($fail) { echo "Failed: " . implode('; ', $fails) . "\n"; exit(1); }
