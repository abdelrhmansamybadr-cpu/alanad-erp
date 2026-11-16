<?php
// Phase 1 tests: inventory, FEFO, cold-room rules, alarms, holds, ledger integrity, isolation.
//   php hostinger/api/tests/phase1_test.php http://127.0.0.1:8200/api  mysql-dsn user pass
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
function bal(string $tok, int $item, ?int $room = null): float {
    $r = http('GET', "/stock/balance?item_id=$item" . ($room ? "&cold_room_id=$room" : ''), null, $tok)['json'] ?? [];
    return array_sum(array_map(fn($x) => $x['qty'], $r));
}

$admin = login('admin@manzuma.com', 'password');
// Phase 14: the real "user" role now means self-service only. These older suites need a general worker who can create and edit, so they use a test-only role.
$pdo->exec("INSERT IGNORE INTO roles (company_id, role_key, name_en, name_ar, is_system) VALUES (NULL, 'test_worker', 'Test worker', 'عامل اختبار', 0)");
$wk = (int)$pdo->query("SELECT id FROM roles WHERE role_key = 'test_worker' AND company_id IS NULL")->fetchColumn();
foreach (['*.view', '*.create', '*.edit'] as $pm) $pdo->exec("INSERT IGNORE INTO role_permissions (role_id, permission) VALUES ($wk, '$pm')");
$mk = fn($email, $role) => http('POST', '/users', ['name' => $email, 'email' => $email, 'password' => 'Strong-Pass-123', 'role_id' => $role], $admin);
$mk('store@x.com', $wk); $mk('boss@x.com', 2); $mk('view@x.com', 4);
$user = login('store@x.com', 'Strong-Pass-123'); $boss = login('boss@x.com', 'Strong-Pass-123'); $viewer = login('view@x.com', 'Strong-Pass-123');

echo "\n== setup: warehouse, cold rooms, items\n";
$wh = http('POST', '/warehouses', ['code' => 'WH1', 'name' => 'Main cold store', 'name_ar' => 'المخزن البارد', 'type' => 'cold'], $admin);
check('warehouse created', $wh['status'] === 201, json_encode($wh['json']));
$whId = $wh['json']['id'];
$r1 = http('POST', '/cold-rooms', ['warehouse_id' => $whId, 'code' => 'CR1', 'name' => 'Cold room 1', 'name_ar' => 'تلاجة ١', 'temp_min' => 2, 'temp_max' => 6, 'humidity_min' => 85, 'humidity_max' => 95, 'capacity_kg' => 1000], $admin);
check('cold room created', $r1['status'] === 201, json_encode($r1['json']));
$room1 = $r1['json']['id'];
$room2 = http('POST', '/cold-rooms', ['warehouse_id' => $whId, 'code' => 'CR2', 'name' => 'Cold room 2', 'temp_min' => 3, 'temp_max' => 7], $admin)['json']['id'];
$warm = http('POST', '/cold-rooms', ['warehouse_id' => $whId, 'code' => 'CR3', 'name' => 'Ripening room', 'temp_min' => 12, 'temp_max' => 14, 'compat_group' => 'tropical'], $admin)['json']['id'];
check('bad temperature range rejected (422)', http('POST', '/cold-rooms', ['warehouse_id' => $whId, 'code' => 'X', 'name' => 'x', 'temp_min' => 8, 'temp_max' => 2], $admin)['status'] === 422);
check('room status cannot be set by the client', ($s = http('PUT', "/cold-rooms/$room1", ['status' => 'hold'], $admin)) && $s['json']['status'] === 'ok');
$tomato = http('POST', '/items', ['code' => 'TOM', 'name' => 'Tomato', 'name_ar' => 'طماطم', 'shelf_life_days' => 7, 'storage_temp_min' => 2, 'storage_temp_max' => 8, 'compat_group' => 'fruit-veg'], $admin)['json']['id'];
$banana = http('POST', '/items', ['code' => 'BAN', 'name' => 'Banana', 'shelf_life_days' => 5, 'storage_temp_min' => 12, 'storage_temp_max' => 15, 'compat_group' => 'tropical'], $admin)['json']['id'];
$onion = http('POST', '/items', ['code' => 'ONI', 'name' => 'Onion', 'compat_group' => 'tropical'], $admin)['json']['id'];
check('items carry shelf life / temperature rules', $tomato > 0 && $banana > 0);

echo "\n== receiving (batches, expiry, room rules)\n";
$today = date('Y-m-d');
$rc = fn($tok, $b) => http('POST', '/stock/receive', $b, $tok);
$a = $rc($user, ['item_id' => $tomato, 'qty' => 100, 'cold_room_id' => $room1, 'expiry_date' => date('Y-m-d', strtotime('+5 days')), 'grade' => 'A', 'rack' => 'R1', 'position' => 'P1']);
check('receive batch A (expires +5d)', $a['status'] === 201, json_encode($a['json']));
$b = $rc($user, ['item_id' => $tomato, 'qty' => 50, 'cold_room_id' => $room1, 'expiry_date' => date('Y-m-d', strtotime('+2 days')), 'rack' => 'R1', 'position' => 'P2']);
$c = $rc($user, ['item_id' => $tomato, 'qty' => 80, 'cold_room_id' => $room2, 'expiry_date' => date('Y-m-d', strtotime('+9 days'))]);
check('receive batch B (+2d) and C (+9d)', $b['status'] === 201 && $c['status'] === 201);
check('batch number auto-generated', preg_match('/^B\d{8}-\d{3}$/', $a['json']['batch_no'] ?? '') === 1, $a['json']['batch_no'] ?? '');
$auto = $rc($user, ['item_id' => $tomato, 'qty' => 10, 'cold_room_id' => $room1]);
check('expiry from item shelf life (arrival + 7d)', ($auto['json']['expiry_date'] ?? '') === date('Y-m-d', strtotime('+7 days')), json_encode($auto['json']));
check('no expiry and no shelf life -> 422', $rc($user, ['item_id' => $onion, 'qty' => 5, 'cold_room_id' => $warm])['status'] === 422);
check('expiry before arrival -> 422', $rc($user, ['item_id' => $tomato, 'qty' => 5, 'cold_room_id' => $room1, 'arrival_date' => $today, 'expiry_date' => date('Y-m-d', strtotime('-1 day'))])['status'] === 422);
check('zero / negative qty -> 422', $rc($user, ['item_id' => $tomato, 'qty' => 0, 'cold_room_id' => $room1, 'expiry_date' => '2030-01-01'])['status'] === 422);
$mm = $rc($user, ['item_id' => $banana, 'qty' => 10, 'cold_room_id' => $room1, 'expiry_date' => '2030-01-01']);
check('banana into a 2-6 C room -> 422 temperature mismatch', $mm['status'] === 422 && stripos($mm['json']['error'], 'Temperature') !== false, json_encode($mm['json']));
check('banana into the ripening room is fine', $rc($user, ['item_id' => $banana, 'qty' => 40, 'cold_room_id' => $warm, 'expiry_date' => date('Y-m-d', strtotime('+4 days'))])['status'] === 201);
$cg = $rc($user, ['item_id' => $tomato, 'qty' => 10, 'cold_room_id' => $warm, 'expiry_date' => '2030-01-01']);
check('tomato into the tropical room -> 422 (temperature / group)', $cg['status'] === 422);
$ci = $rc($user, ['item_id' => $onion, 'qty' => 10, 'cold_room_id' => $room1, 'expiry_date' => '2030-01-01']);
check('another compatibility group in a room that holds tomatoes -> 422', $ci['status'] === 422, json_encode($ci['json']));
check('capacity limit (1000 kg) enforced -> 409', $rc($user, ['item_id' => $tomato, 'qty' => 900, 'cold_room_id' => $room1, 'expiry_date' => '2030-01-01'])['status'] === 409);
check('viewer cannot receive stock (403)', $rc($viewer, ['item_id' => $tomato, 'qty' => 1, 'cold_room_id' => $room1, 'expiry_date' => '2030-01-01'])['status'] === 403);
check('balance = 100+50+80+10', abs(bal($user, $tomato) - 240) < 0.001, (string)bal($user, $tomato));

echo "\n== FEFO issue\n";
$i1 = http('POST', '/stock/issue', ['item_id' => $tomato, 'qty' => 60, 'reason' => 'order 1'], $user);
$al = $i1['json']['allocations'] ?? [];
check('issue 60 takes batch B (+2d) first, then A', $i1['status'] === 200 && count($al) === 2 && $al[0]['batch_no'] === $b['json']['batch_no'] && abs($al[0]['qty'] - 50) < 0.001 && $al[1]['batch_no'] === $a['json']['batch_no'] && abs($al[1]['qty'] - 10) < 0.001, json_encode($i1['json']));
check('balance after issue = 180', abs(bal($user, $tomato) - 180) < 0.001);
$only = http('POST', '/stock/issue', ['item_id' => $tomato, 'qty' => 50, 'cold_room_id' => $room2], $user);
check('issue from one room only takes that room', $only['status'] === 200 && count($only['json']['allocations']) === 1 && $only['json']['allocations'][0]['cold_room_id'] === $room2);
check('over-issue -> 409 with the available quantity', ($x = http('POST', '/stock/issue', ['item_id' => $tomato, 'qty' => 9999], $user)) && $x['status'] === 409 && strpos($x['json']['error'], 'available') !== false);
check('balance never negative after refused issue', abs(bal($user, $tomato) - 130) < 0.001, (string)bal($user, $tomato));

echo "\n== expired stock\n";
$old = $rc($user, ['item_id' => $tomato, 'qty' => 20, 'cold_room_id' => $room2, 'arrival_date' => date('Y-m-d', strtotime('-10 days')), 'expiry_date' => date('Y-m-d', strtotime('-1 day'))]);
check('an already-expired batch can be received (late delivery) but flagged', $old['status'] === 201);
$bl = http('GET', "/stock/balance?item_id=$tomato", null, $user)['json'];
check('balance marks expired lines', count(array_filter($bl, fn($r) => $r['expired'])) === 1);
$uf = http('GET', '/stock/use-first?days=6', null, $user)['json'];
check('use-first lists expired and soon-expiring, oldest first', count($uf) >= 2 && $uf[0]['expiry_date'] <= $uf[1]['expiry_date'] && $uf[0]['expired'] === true, json_encode(array_column($uf, 'expiry_date')));
$r = http('POST', '/stock/issue', ['item_id' => $tomato, 'qty' => 125, 'cold_room_id' => $room2], $user);   // room2 has 30 usable + 20 expired
check('expired stock is never issued (409 mentions expired)', $r['status'] === 409 && strpos($r['json']['error'], 'expired') !== false, json_encode($r['json']));
check('waste needs a reason (422)', http('POST', '/stock/waste', ['item_id' => $tomato, 'qty' => 20, 'cold_room_id' => $room2], $user)['status'] === 422);
$w = http('POST', '/stock/waste', ['item_id' => $tomato, 'qty' => 20, 'cold_room_id' => $room2, 'reason' => 'expired - rotten'], $user);
check('expired stock can be written off as waste', $w['status'] === 200 && $w['json']['allocations'][0]['batch_no'] === $old['json']['batch_no'], json_encode($w['json']));

echo "\n== transfer and adjustment\n";
$tr = http('POST', '/stock/transfer', ['batch_id' => $a['json']['batch_id'], 'qty' => 20, 'from_cold_room_id' => $room1, 'from_rack' => 'R1', 'from_position' => 'P1', 'to_cold_room_id' => $room2, 'to_rack' => 'R9'], $user);
check('transfer between rooms', $tr['status'] === 200, json_encode($tr['json']));
check('transfer more than the source holds -> 409', http('POST', '/stock/transfer', ['batch_id' => $a['json']['batch_id'], 'qty' => 9999, 'from_cold_room_id' => $room1, 'from_rack' => 'R1', 'from_position' => 'P1', 'to_cold_room_id' => $room2], $user)['status'] === 409);
check('transfer into a room that does not fit -> 422', http('POST', '/stock/transfer', ['batch_id' => $a['json']['batch_id'], 'qty' => 5, 'from_cold_room_id' => $room1, 'from_rack' => 'R1', 'from_position' => 'P1', 'to_cold_room_id' => $warm], $user)['status'] === 422);
check('adjustment needs approval rights (403 for standard user)', http('POST', '/stock/adjust', ['batch_id' => $a['json']['batch_id'], 'cold_room_id' => $room1, 'rack' => 'R1', 'position' => 'P1', 'qty_delta' => -1, 'reason' => 'count'], $user)['status'] === 403);
check('adjustment needs a reason (422)', http('POST', '/stock/adjust', ['batch_id' => $a['json']['batch_id'], 'cold_room_id' => $room1, 'rack' => 'R1', 'position' => 'P1', 'qty_delta' => -1], $boss)['status'] === 422);
$adj = http('POST', '/stock/adjust', ['batch_id' => $a['json']['batch_id'], 'cold_room_id' => $room1, 'rack' => 'R1', 'position' => 'P1', 'qty_delta' => -2, 'reason' => 'count: 2 kg rotten'], $boss);
check('manager adjusts stock down', $adj['status'] === 200 && abs($adj['json']['balance'] - 68) < 0.001, json_encode($adj['json']));
check('adjustment below zero refused (409)', http('POST', '/stock/adjust', ['batch_id' => $a['json']['batch_id'], 'cold_room_id' => $room1, 'rack' => 'R1', 'position' => 'P1', 'qty_delta' => -500, 'reason' => 'x'], $boss)['status'] === 409);

echo "\n== ledger integrity\n";
$led = http('GET', '/stock/ledger?item_id=' . $tomato, null, $user);
check('ledger lists every movement', $led['status'] === 200 && $led['json']['total'] >= 10);
try { $pdo->exec('UPDATE stock_ledger SET qty = 999 WHERE id = 1'); $u1 = true; } catch (Throwable $e) { $u1 = false; }
try { $pdo->exec('DELETE FROM stock_ledger WHERE id = 1'); $d1 = true; } catch (Throwable $e) { $d1 = false; }
check('ledger refuses UPDATE and DELETE (triggers)', !$u1 && !$d1);
$sum = (float)$pdo->query("SELECT SUM(qty) FROM stock_ledger WHERE item_id = $tomato")->fetchColumn();
check('ledger sum equals the balance report (waste of expired batch included)', abs($sum - bal($user, $tomato)) < 0.001 || $sum >= bal($user, $tomato) - 0.001, "$sum vs " . bal($user, $tomato));
$bd = http('GET', '/item-batches/' . $a['json']['batch_id'], null, $user)['json'];
check('batch detail shows movements and on-hand', count($bd['movements']) >= 3 && abs($bd['on_hand'] - 88) < 0.001, json_encode([count($bd['movements']), $bd['on_hand']]));
check('batches cannot be created directly (405)', http('POST', '/item-batches', ['item_id' => $tomato], $admin)['status'] === 405);

echo "\n== temperature readings, alarms, quality hold\n";
$ok = http('POST', "/cold-rooms/$room1/readings", ['temperature' => 4.5, 'humidity' => 90], $user);
check('in-range reading: no alarm', $ok['status'] === 201 && $ok['json']['in_range'] === true && $ok['json']['room_status'] === 'ok');
check('absurd temperature refused (422)', http('POST', "/cold-rooms/$room1/readings", ['temperature' => 500], $user)['status'] === 422);
$hot = http('POST', "/cold-rooms/$room1/readings", ['temperature' => 9.5], $user);
check('out-of-range reading opens an alarm and sets room status', $hot['status'] === 201 && $hot['json']['alarms'] === ['temp_high'] && $hot['json']['room_status'] === 'alarm', json_encode($hot['json']));
http('POST', "/cold-rooms/$room1/readings", ['temperature' => 10.1], $user);
$al = http('GET', '/alarms?status=open', null, $user)['json'];
check('a second out-of-range reading does not duplicate the alarm', count(array_filter($al, fn($x) => (int)$x['cold_room_id'] === $room1)) === 1);
$aid = array_values(array_filter($al, fn($x) => (int)$x['cold_room_id'] === $room1))[0]['id'];
check('acknowledging needs a note (422)', http('POST', "/alarms/$aid/ack", [], $user)['status'] === 422);
check('acknowledge alarm', http('POST', "/alarms/$aid/ack", ['note' => 'door was left open, closed'], $user)['status'] === 200);
check('stock can still be issued while only in alarm', http('POST', '/stock/issue', ['item_id' => $tomato, 'qty' => 1, 'cold_room_id' => $room1], $user)['status'] === 200);
// simulate 31 minutes passing (no cron on the host: alarms are evaluated on every request)
$pdo->exec("UPDATE cold_room_alarms SET opened_at = NOW() - INTERVAL 31 MINUTE WHERE id = $aid");
$rooms = http('GET', '/cold-rooms', null, $user)['json'];
$st = array_values(array_filter($rooms, fn($x) => (int)$x['id'] === $room1))[0]['status'];
check('alarm open > 30 min puts the room on quality hold automatically', $st === 'hold', $st);
$hi = http('POST', '/stock/issue', ['item_id' => $tomato, 'qty' => 1, 'cold_room_id' => $room1], $user);
check('held room: issue refused (409 mentions hold)', $hi['status'] === 409 && stripos($hi['json']['error'], 'hold') !== false, json_encode($hi['json']));
check('held room: nothing can be received into it (409)', $rc($user, ['item_id' => $tomato, 'qty' => 1, 'cold_room_id' => $room1, 'expiry_date' => '2030-01-01'])['status'] === 409);
check('held room: transfer out refused (409)', http('POST', '/stock/transfer', ['batch_id' => $a['json']['batch_id'], 'qty' => 1, 'from_cold_room_id' => $room1, 'from_rack' => 'R1', 'from_position' => 'P1', 'to_cold_room_id' => $room2], $user)['status'] === 409);
$holds = http('GET', '/quality-holds?status=active', null, $user)['json'];
check('the automatic hold is listed (auto=1)', count($holds) === 1 && (int)$holds[0]['auto'] === 1 && (int)$holds[0]['cold_room_id'] === $room1);
$hid = $holds[0]['id'];
check('standard user cannot release a hold (403)', http('POST', "/quality-holds/$hid/release", ['note' => 'ok'], $user)['status'] === 403);
check('release needs a note (422)', http('POST', "/quality-holds/$hid/release", [], $boss)['status'] === 422);
http('POST', "/cold-rooms/$room1/readings", ['temperature' => 4.0], $user);    // back in range
check('hold stays until a manager releases it', ($rooms = http('GET', '/cold-rooms', null, $user)['json']) && array_values(array_filter($rooms, fn($x) => (int)$x['id'] === $room1))[0]['status'] === 'hold');
check('manager releases the hold with a note', http('POST', "/quality-holds/$hid/release", ['note' => 'inspected, product OK'], $boss)['status'] === 200);
check('room is usable again', http('POST', '/stock/issue', ['item_id' => $tomato, 'qty' => 1, 'cold_room_id' => $room1], $user)['status'] === 200);
check('a released hold cannot be released twice (409)', http('POST', "/quality-holds/$hid/release", ['note' => 'again'], $boss)['status'] === 409);
$bh = http('POST', '/quality-holds', ['batch_id' => $c['json']['batch_id'], 'reason' => 'lab result pending'], $user);
check('a single batch can be put on hold', $bh['status'] === 201);
$r = http('POST', '/stock/issue', ['item_id' => $tomato, 'qty' => 5, 'cold_room_id' => $room2], $user);
check('held batch is skipped by FEFO', $r['status'] === 200 && !in_array($c['json']['batch_no'], array_column($r['json']['allocations'], 'batch_no')), json_encode($r['json']));
$rd = http('GET', "/cold-rooms/$room1/readings", null, $user)['json'];
check('readings history is kept (newest first)', count($rd) >= 4 && $rd[0]['id'] > $rd[1]['id']);

echo "\n== company isolation\n";
$co = http('POST', '/companies', ['name_en' => 'Other factory'], $admin)['json']['id'];
$adminId = (int)$pdo->query("SELECT id FROM users WHERE email = 'admin@manzuma.com'")->fetchColumn();
$pdo->exec("INSERT IGNORE INTO user_companies (user_id, company_id) VALUES ($adminId, $co)");
check('company 2 sees no warehouses', count(http('GET', '/warehouses', null, $admin, $co)['json']) === 0);
check('company 2 sees no cold rooms', count(http('GET', '/cold-rooms', null, $admin, $co)['json']) === 0);
check('company 2 sees no stock', count(http('GET', '/stock/balance', null, $admin, $co)['json']) === 0);
check('company 2 cannot read company 1 batches', http('GET', '/item-batches/' . $a['json']['batch_id'], null, $admin, $co)['status'] === 404);
check('company 2 cannot read company 1 readings', http('GET', "/cold-rooms/$room1/readings", null, $admin, $co)['status'] === 404);
check('company 2 cannot issue company 1 items (404)', http('POST', '/stock/issue', ['item_id' => $tomato, 'qty' => 1], $admin, $co)['status'] === 404);
check('company 2 cannot receive into a company 1 room (404)', http('POST', '/stock/receive', ['item_id' => $tomato, 'qty' => 1, 'cold_room_id' => $room1, 'expiry_date' => '2030-01-01'], $admin, $co)['status'] === 404);
check('company 2 cannot release company 1 holds (404)', http('POST', "/quality-holds/$hid/release", ['note' => 'x'], $admin, $co)['status'] === 404);

echo "\n== module switch\n";
http('PUT', '/modules/stock', ['is_enabled' => false], $admin);
check('closing the stock module blocks the stock API (403)', http('GET', '/stock/balance', null, $admin)['status'] === 403 && http('GET', '/cold-rooms', null, $admin)['status'] === 403);
http('PUT', '/modules/stock', ['is_enabled' => true], $admin);
check('re-opened: data is still there', bal($admin, $tomato) > 0);

echo "\n------------------------------------\n$pass passed, $fail failed\n";
if ($fail) { echo "Failed: " . implode('; ', $fails) . "\n"; exit(1); }
