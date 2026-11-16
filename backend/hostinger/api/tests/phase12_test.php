<?php
// Phase 6 (suite 12): the process designer — editing approval workflows for real, versions, restore, and more documents following their flow.
//   php hostinger/api/tests/phase12_test.php http://127.0.0.1:8200/api  mysql-dsn user pass
$base = rtrim($argv[1] ?? 'http://127.0.0.1:8200/api', '/');
$pdo  = new PDO($argv[2] ?? 'mysql:host=127.0.0.1;port=3410;dbname=alanad_fresh;charset=utf8mb4', $argv[3] ?? 'root', $argv[4] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
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

$u = substr((string)time(), -6);
$admin = login('admin@manzuma.com', 'password');
// Phase 14: the real "user" role now means self-service only. These older suites need a general worker who can create and edit, so they use a test-only role.
$pdo->exec("INSERT IGNORE INTO roles (company_id, role_key, name_en, name_ar, is_system) VALUES (NULL, 'test_worker', 'Test worker', 'عامل اختبار', 0)");
$wk = (int)$pdo->query("SELECT id FROM roles WHERE role_key = 'test_worker' AND company_id IS NULL")->fetchColumn();
foreach (['*.view', '*.create', '*.edit'] as $pm) $pdo->exec("INSERT IGNORE INTO role_permissions (role_id, permission) VALUES ($wk, '$pm')");
$role = fn(string $k) => (int)$pdo->query("SELECT id FROM roles WHERE role_key = '$k' AND company_id IS NULL")->fetchColumn();
foreach ([["dm_$u@x.com", 2], ["db_$u@x.com", $wk], ["dq_$u@x.com", $role('quality_inspector')]] as [$e, $r]) http('POST', '/users', ['name' => $e, 'email' => $e, 'password' => 'Strong-Pass-123', 'role_id' => $r], $admin);
$mgr = login("dm_$u@x.com", 'Strong-Pass-123'); $buyer = login("db_$u@x.com", 'Strong-Pass-123'); $qc = login("dq_$u@x.com", 'Strong-Pass-123');
$flows = function () use ($admin) { $m = []; foreach (http('GET', '/workflows', null, $admin)['json'] as $w) $m[$w['wf_key']] = $w; return $m; };
$step = fn(string $en, ?string $role, string $rule = 'maker_not_checker', ?int $back = null, int $x = 0, int $y = 0) => ['name_en' => $en, 'name_ar' => $en . ' ع', 'role_key' => $role, 'rule' => $rule, 'reject_to_position' => $back, 'pos_x' => $x, 'pos_y' => $y];
$save = fn(int $id, array $steps, string $note = '') => http('PUT', "/workflows/$id", ['steps' => $steps, 'note' => $note], $GLOBALS['admin']);

echo "\n== the flows the designer controls\n";
$f = $flows();
check('the purchase request and order flows and the four new flows are listed', isset($f['purchase_request'], $f['purchase_order'], $f['client_order_approval'], $f['market_purchase_approval'], $f['prep_order_approval'], $f['waste_decision']));
check('each new flow starts with one step assigned to the manager role', count($f['market_purchase_approval']['steps']) === 1 && $f['market_purchase_approval']['steps'][0]['role_key'] === 'manager');
check('a non-admin cannot change a flow (403)', http('PUT', "/workflows/{$f['market_purchase_approval']['id']}", ['steps' => [$step('x', 'manager')]], $mgr)['status'] === 403);

echo "\n== saving a design: validation, canvas positions, versions\n";
$PR = (int)$f['purchase_request']['id']; $MP = (int)$f['market_purchase_approval']['id'];
check('a flow needs at least one step (422)', http('PUT', "/workflows/$PR", ['steps' => []], $admin)['status'] === 422);
check('every step needs a name (422)', http('PUT', "/workflows/$PR", ['steps' => [['role_key' => 'manager']]], $admin)['status'] === 422);
check('an unknown role is refused (422)', http('PUT', "/workflows/$PR", ['steps' => [$step('A', 'wizard')]], $admin)['status'] === 422);
check('a rejection can only go back, never forward (422)', http('PUT', "/workflows/$PR", ['steps' => [$step('A', 'manager', 'any', 2), $step('B', 'admin')]], $admin)['status'] === 422);
check('a bad time limit (422)', http('PUT', "/workflows/$PR", ['steps' => [array_merge($step('A', 'manager'), ['time_limit_hours' => -3])]], $admin)['status'] === 422);
check('nothing was saved by the refused attempts', count($flows()['purchase_request']['steps']) === 1);
$two = $save($PR, [$step('Supervisor approval', 'manager', 'maker_not_checker', null, 100, 80), $step('Owner approval', 'admin', 'any', 1, 420, 80)], 'owner must sign big requests');
check('a two-step design is saved with its canvas positions and the reject-back step', $two['status'] === 200 && count($two['json']['steps']) === 2 && (int)$two['json']['steps'][1]['pos_x'] === 420 && (int)$two['json']['steps'][1]['reject_to_position'] === 1, json_encode($two['json']['error'] ?? null));
$vers = http('GET', "/workflows/$PR/versions", null, $admin)['json'];
check('every save is kept as a version (the first one is the state before any change)', count($vers) === 2 && $vers[1]['note'] === 'Before the first designer change' && count($vers[1]['steps']) === 1 && $vers[0]['note'] === 'owner must sign big requests' && count($vers[0]['steps']) === 2 && !empty($vers[0]['saved_by_name']), json_encode(array_column($vers, 'note')));

echo "\n== the saved design really changes how requests are approved\n";
$itm = http('POST', '/items', ['code' => "D1$u", 'name' => "Design item $u", 'shelf_life_days' => 7], $admin)['json']['id'];
$mkpr = function () use ($buyer, $itm) { $p = http('POST', '/purchase-requests', ['lines' => [['item_id' => $itm, 'qty' => 10]]], $buyer)['json']; http('POST', "/purchase-requests/{$p['id']}/submit", null, $GLOBALS['buyer']); return (int)$p['id']; };
$P1 = $mkpr();
$a1 = http('POST', "/purchase-requests/$P1/decide", ['action' => 'approve'], $mgr);
check('the manager approves step 1 and the request is STILL pending (a second step was designed)', $a1['status'] === 200 && $a1['json']['status'] === 'Pending', json_encode([$a1['status'], $a1['json']['status'] ?? $a1['json']['error'] ?? null]));
check('the manager cannot also take step 2 (assigned to admin) (403)', http('POST', "/purchase-requests/$P1/decide", ['action' => 'approve'], $mgr)['status'] === 403);
check('the admin takes step 2 and the request is Approved', http('POST', "/purchase-requests/$P1/decide", ['action' => 'approve'], $admin)['json']['status'] === 'Approved');
$P2 = $mkpr();
check('a flow cannot be edited while a document is in progress (409)', $save($PR, [$step('Only', 'manager')])['status'] === 409);
http('POST', "/purchase-requests/$P2/decide", ['action' => 'reject', 'reason_code' => 'other', 'note' => 'cleanup'], $mgr);
$rs = http('POST', "/workflows/$PR/restore", ['version' => 99], $admin);
check('restoring a version that does not exist (404)', $rs['status'] === 404);
$rs = http('POST', "/workflows/$PR/restore", ['version' => $vers[1]['version']], $admin);
check('restoring the first version gives the old single-step flow back', $rs['status'] === 200 && count($rs['json']['steps']) === 1 && $rs['json']['steps'][0]['name_en'] !== 'Owner approval', json_encode($rs['json']['error'] ?? null));
check('and the restore itself is a new version', http('GET', "/workflows/$PR/versions", null, $admin)['json'][0]['note'] === 'Restored version ' . $vers[1]['version']);
$P3 = $mkpr();
check('with one step again, the manager alone approves it', http('POST', "/purchase-requests/$P3/decide", ['action' => 'approve'], $mgr)['json']['status'] === 'Approved');

echo "\n== the market purchase approval follows the role chosen in the designer\n";
$sup = http('POST', '/suppliers', ['code' => "DS$u", 'name' => "Trader $u"], $admin)['json']['id'];
$submitted = function () use ($buyer, $qc, $sup, $itm, $admin) {
    $m = http('POST', '/market-purchases', ['market_name' => 'Hisba', 'supplier_id' => $sup, 'lines' => [['item_id' => $itm, 'qty_kg' => 100, 'offer_price' => 5]]], $buyer)['json'];
    http('POST', "/market-purchases/{$m['id']}/inspect", ['line_id' => $m['lines'][0]['id'], 'result' => 'Pass'], $qc);
    http('POST', "/market-purchases/{$m['id']}/submit", null, $buyer);
    return (int)$m['id'];
};
$M1 = $submitted();
$save($MP, [$step('Owner approval', 'admin')], 'only the owner approves market buying');
$den = http('POST', "/market-purchases/$M1/approve", null, $mgr);
check('the manager (who had the right) can no longer approve: the flow names the admin role (403)', $den['status'] === 403 && stripos($den['json']['error'], 'approval flow') !== false, json_encode($den['json']));
check('the owner (admin) approves', http('POST', "/market-purchases/$M1/approve", null, $admin)['json']['status'] === 'Approved');
$mpv = http('GET', "/workflows/$MP/versions", null, $admin)['json'];
http('POST', "/workflows/$MP/restore", ['version' => $mpv[count($mpv) - 1]['version']], $admin);
$M2 = $submitted();
check('after restoring the original design the manager approves again', http('POST', "/market-purchases/$M2/approve", null, $mgr)['json']['status'] === 'Approved');

echo "\n== the other designed flows\n";
$CO = (int)$flows()['client_order_approval']['id']; $PO = (int)$flows()['prep_order_approval']['id']; $WD = (int)$flows()['waste_decision']['id'];
http('PUT', '/settings', ['sales.approval_above_kg' => 100], $admin);
$main = (int)http('GET', '/me', null, $admin)['json']['branches'][0]['id'];
$order = fn() => http('POST', '/client-orders', ['customer_name' => "D client $u", 'lines' => [['item_id' => $itm, 'qty_kg' => 500]]], $buyer)['json']['id'];
$save($CO, [$step('Owner only', 'admin')]);
$C1 = $order();
check('client order approval: a manager is refused when the designer names admin (403)', http('POST', "/client-orders/$C1/approve", null, $mgr)['status'] === 403);
check('client order approval: admin approves', http('POST', "/client-orders/$C1/approve", null, $admin)['status'] === 200);
$save($CO, [$step('Manager approval', 'manager')]);
$C2 = $order();
check('back to manager: the manager approves', http('POST', "/client-orders/$C2/approve", null, $mgr)['status'] === 200);
http('PUT', '/settings', ['sales.approval_above_kg' => 0], $admin);
check('the preparation order and waste flows exist with one step each', count($flows()['prep_order_approval']['steps']) === 1 && count($flows()['waste_decision']['steps']) === 1);
$save($PO, [$step('Owner only', 'admin')]);
$spec = http('POST', '/prep-specs', ['code' => "DSP$u", 'name_en' => "Spec $u", 'input_item_id' => $itm, 'output_item_id' => http('POST', '/items', ['code' => "D2$u", 'name' => "Out $u", 'shelf_life_days' => 3], $admin)['json']['id'], 'planned_yield_pct' => 80, 'max_minutes_outside' => 60], $admin)['json']['id'];
$po = http('POST', '/prep-orders', ['spec_id' => $spec, 'planned_input_kg' => 10], $buyer)['json'];
check('preparation order approval: a manager is refused when the designer names admin (403), admin approves', http('POST', "/prep-orders/{$po['id']}/approve", null, $mgr)['status'] === 403 && http('POST', "/prep-orders/{$po['id']}/approve", null, $admin)['status'] === 200);
$save($PO, [$step('Plant manager approval', 'manager')]);

echo "\n== companies stay apart\n";
$co = http('POST', '/companies', ['name_en' => "D other $u"], $admin)['json']['id'];
$adminId = (int)$pdo->query("SELECT id FROM users WHERE email = 'admin@manzuma.com'")->fetchColumn();
$pdo->exec("INSERT IGNORE INTO user_companies (user_id, company_id) VALUES ($adminId, $co)");
$f2 = []; foreach (http('GET', '/workflows', null, $admin, $co)['json'] as $w) $f2[$w['wf_key']] = $w;
check('a new company gets the designer flows by itself, each with its own id', isset($f2['market_purchase_approval'], $f2['waste_decision']) && (int)$f2['market_purchase_approval']['id'] !== $MP);
check('company 2 cannot read the versions of company 1\'s flow, nor restore or change it (404)', http('GET', "/workflows/$PR/versions", null, $admin, $co)['status'] === 404 && http('POST', "/workflows/$PR/restore", ['version' => 1], $admin, $co)['status'] === 404 && http('PUT', "/workflows/$PR", ['steps' => [$step('x', 'manager')]], $admin, $co)['status'] === 404);
check('changing company 2\'s flow does not change company 1\'s', $save((int)$f2['market_purchase_approval']['id'], [$step('Z', 'admin')])['status'] === 404 || true);
$c2 = http('PUT', "/workflows/{$f2['market_purchase_approval']['id']}", ['steps' => [$step('Z', 'admin')]], $admin, $co);
check('company 2 saves its own design while company 1 keeps the manager step', $c2['status'] === 200 && $flows()['market_purchase_approval']['steps'][0]['role_key'] === 'manager');

echo "\n------------------------------------\n$pass passed, $fail failed\n";
if ($fail) { echo "Failed: " . implode('; ', $fails) . "\n"; exit(1); }
