<?php
// Phase 2 tests: purchasing (dated prices, purchase requests, purchase orders, approvals, suggestions, isolation).
//   php hostinger/api/tests/phase2_test.php http://127.0.0.1:8200/api  mysql-dsn user pass
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

$admin = login('admin@manzuma.com', 'password');
// Phase 14: the real "user" role now means self-service only. These older suites need a general worker who can create and edit, so they use a test-only role.
$pdo->exec("INSERT IGNORE INTO roles (company_id, role_key, name_en, name_ar, is_system) VALUES (NULL, 'test_worker', 'Test worker', 'عامل اختبار', 0)");
$wk = (int)$pdo->query("SELECT id FROM roles WHERE role_key = 'test_worker' AND company_id IS NULL")->fetchColumn();
foreach (['*.view', '*.create', '*.edit'] as $pm) $pdo->exec("INSERT IGNORE INTO role_permissions (role_id, permission) VALUES ($wk, '$pm')");
$mk = fn($email, $role) => http('POST', '/users', ['name' => $email, 'email' => $email, 'password' => 'Strong-Pass-123', 'role_id' => $role], $admin);
foreach ([['buyer@x.com', $wk], ['mgr1@x.com', 2], ['mgr2@x.com', 2], ['viewer@x.com', 4]] as [$e, $r]) $mk($e, $r);
$buyer = login('buyer@x.com', 'Strong-Pass-123'); $m1 = login('mgr1@x.com', 'Strong-Pass-123'); $m2 = login('mgr2@x.com', 'Strong-Pass-123'); $viewer = login('viewer@x.com', 'Strong-Pass-123');

echo "\n== setup\n";
$s1 = http('POST', '/suppliers', ['code' => 'S1', 'name' => 'Green Farm'], $admin)['json']['id'];
$s2 = http('POST', '/suppliers', ['code' => 'S2', 'name' => 'Valley Farm'], $admin)['json']['id'];
$tom = http('POST', '/items', ['code' => 'TOM', 'name' => 'Tomato', 'shelf_life_days' => 7, 'storage_temp_min' => 2, 'storage_temp_max' => 8, 'reorder_level' => 500], $admin)['json']['id'];
$cuc = http('POST', '/items', ['code' => 'CUC', 'name' => 'Cucumber', 'shelf_life_days' => 7, 'reorder_level' => 100], $admin)['json']['id'];
$pot = http('POST', '/items', ['code' => 'POT', 'name' => 'Potato', 'shelf_life_days' => 30], $admin)['json']['id'];
check('suppliers and items exist', $s1 && $s2 && $tom && $cuc && $pot);

echo "\n== dated supplier prices\n";
$today = date('Y-m-d'); $yest = date('Y-m-d', strtotime('-1 day')); $next = date('Y-m-d', strtotime('+3 days')); $old = date('Y-m-d', strtotime('-30 days'));
check('buyer adds a price', http('POST', '/supplier-prices', ['supplier_id' => $s1, 'item_id' => $tom, 'price' => 4.00, 'valid_from' => $old], $buyer)['status'] === 201);
check('a newer price replaces it from its date', http('POST', '/supplier-prices', ['supplier_id' => $s1, 'item_id' => $tom, 'price' => 5.00, 'valid_from' => $yest], $buyer)['status'] === 201);
check('a future price is stored but not used yet', http('POST', '/supplier-prices', ['supplier_id' => $s1, 'item_id' => $tom, 'price' => 9.00, 'valid_from' => $next], $buyer)['status'] === 201);
check('same supplier, item and date twice -> 409', http('POST', '/supplier-prices', ['supplier_id' => $s1, 'item_id' => $tom, 'price' => 1, 'valid_from' => $yest], $buyer)['status'] === 409);
check('price must be positive -> 422', http('POST', '/supplier-prices', ['supplier_id' => $s1, 'item_id' => $tom, 'price' => 0, 'valid_from' => $today], $buyer)['status'] === 422);
check('unknown supplier -> 422', http('POST', '/supplier-prices', ['supplier_id' => 99999, 'item_id' => $tom, 'price' => 3], $buyer)['status'] === 422);
check('current price today = 5.00 (latest not in the future)', http('GET', "/supplier-prices/current?supplier_id=$s1&item_id=$tom", null, $buyer)['json']['price'] == 5.0);
check('price on an older date = 4.00', http('GET', "/supplier-prices/current?supplier_id=$s1&item_id=$tom&date=" . date('Y-m-d', strtotime('-10 days')), null, $buyer)['json']['price'] == 4.0);
check('price after the future date = 9.00', http('GET', "/supplier-prices/current?supplier_id=$s1&item_id=$tom&date=" . date('Y-m-d', strtotime('+4 days')), null, $buyer)['json']['price'] == 9.0);
check('no price for another supplier -> null', http('GET', "/supplier-prices/current?supplier_id=$s2&item_id=$tom", null, $buyer)['json']['price'] === null);
check('viewer cannot add prices (403)', http('POST', '/supplier-prices', ['supplier_id' => $s1, 'item_id' => $cuc, 'price' => 2], $viewer)['status'] === 403);
http('POST', '/supplier-prices', ['supplier_id' => $s1, 'item_id' => $cuc, 'price' => 2.50, 'valid_from' => $yest], $buyer);
check('price history list', count(http('GET', "/supplier-prices?supplier_id=$s1&item_id=$tom", null, $buyer)['json']) === 3);

echo "\n== purchase request: create, edit, validate\n";
$pr = http('POST', '/purchase-requests', ['needed_by' => $next, 'notes' => 'weekly', 'lines' => [['item_id' => $tom, 'qty' => 1000], ['item_id' => $cuc, 'qty' => 200]]], $buyer);
check('buyer creates a PR with lines', $pr['status'] === 201 && $pr['json']['number'] === 'PR-0001' && count($pr['json']['lines']) === 2 && $pr['json']['status'] === 'Draft', json_encode($pr['json']));
$pid = $pr['json']['id'];
check('PR without lines -> 422', http('POST', '/purchase-requests', ['lines' => []], $buyer)['status'] === 422);
check('zero quantity -> 422', http('POST', '/purchase-requests', ['lines' => [['item_id' => $tom, 'qty' => 0]]], $buyer)['status'] === 422);
check('duplicate item in one PR -> 422', http('POST', '/purchase-requests', ['lines' => [['item_id' => $tom, 'qty' => 1], ['item_id' => $tom, 'qty' => 2]]], $buyer)['status'] === 422);
check('unknown item -> 422', http('POST', '/purchase-requests', ['lines' => [['item_id' => 99999, 'qty' => 1]]], $buyer)['status'] === 422);
check('viewer cannot create a PR (403)', http('POST', '/purchase-requests', ['lines' => [['item_id' => $tom, 'qty' => 1]]], $viewer)['status'] === 403);
check('requester edits a draft', http('PUT', "/purchase-requests/$pid", ['notes' => 'weekly + extra', 'lines' => [['item_id' => $tom, 'qty' => 1200], ['item_id' => $cuc, 'qty' => 200]]], $buyer)['json']['lines'][0]['qty'] == 1200);
check('another user cannot edit it (403)', http('PUT', "/purchase-requests/$pid", ['notes' => 'x'], $m1)['status'] === 403);
check('cannot convert a draft to a PO (409)', http('POST', "/purchase-requests/$pid/to-po", ['supplier_id' => $s1], $buyer)['status'] === 409);

echo "\n== purchase request approval\n";
check('only the requester can submit (403)', http('POST', "/purchase-requests/$pid/submit", [], $m1)['status'] === 403);
$sub = http('POST', "/purchase-requests/$pid/submit", [], $buyer);
check('submit -> Pending (supervisor approval)', $sub['status'] === 200 && $sub['json']['status'] === 'Pending' && $sub['json']['workflow_instance_id'] > 0, json_encode($sub['json']));
check('a pending PR cannot be edited (409)', http('PUT', "/purchase-requests/$pid", ['notes' => 'x'], $buyer)['status'] === 409);
check('the submitter cannot approve own PR (403)', http('POST', "/purchase-requests/$pid/decide", ['action' => 'approve'], $buyer)['status'] === 403);
check('a viewer cannot decide (403)', http('POST', "/purchase-requests/$pid/decide", ['action' => 'approve'], $viewer)['status'] === 403);
check('reject needs a reason (422)', http('POST', "/purchase-requests/$pid/decide", ['action' => 'reject'], $m1)['status'] === 422);
$rj = http('POST', "/purchase-requests/$pid/decide", ['action' => 'reject', 'reason_code' => 'wrong_item', 'note' => 'use onions instead'], $m1);
check('manager rejects with a reason -> Rejected', $rj['status'] === 200 && $rj['json']['status'] === 'Rejected' && strpos($rj['json']['reject_reason'], 'wrong_item') !== false, json_encode($rj['json']));
check('requester fixes and resubmits a rejected PR', http('PUT', "/purchase-requests/$pid", ['lines' => [['item_id' => $tom, 'qty' => 1000], ['item_id' => $cuc, 'qty' => 200]]], $buyer)['json']['status'] === 'Draft' && http('POST', "/purchase-requests/$pid/submit", [], $buyer)['json']['status'] === 'Pending');
$ap = http('POST', "/purchase-requests/$pid/decide", ['action' => 'approve'], $m2);
check('another manager approves -> Approved', $ap['status'] === 200 && $ap['json']['status'] === 'Approved', json_encode($ap['json']));
check('an approved PR cannot be decided again (409)', http('POST', "/purchase-requests/$pid/decide", ['action' => 'approve'], $m1)['status'] === 409);

echo "\n== PR -> purchase order (price rules)\n";
check('buyer cannot convert with a supplier that has no price (422 names the items)', ($x = http('POST', "/purchase-requests/$pid/to-po", ['supplier_id' => $s2], $buyer)) && $x['status'] === 422 && strpos($x['json']['error'], 'Tomato') !== false, json_encode($x['json']));
check('viewer cannot convert (403)', http('POST', "/purchase-requests/$pid/to-po", ['supplier_id' => $s1], $viewer)['status'] === 403);
$po = http('POST', "/purchase-requests/$pid/to-po", ['supplier_id' => $s1], $buyer);
$expSub = 1000 * 5.00 + 200 * 2.50;                     // 5500
check('PO created from today\'s prices (5.00 and 2.50), VAT 15%', $po['status'] === 201 && abs($po['json']['subtotal'] - $expSub) < 0.01 && abs($po['json']['total'] - round($expSub * 1.15, 2)) < 0.01 && $po['json']['status'] === 'Draft' && $po['json']['number'] === 'PO-0001', json_encode($po['json']));
$poid = $po['json']['id'];
check('PR becomes Converted and cannot convert twice (409)', http('GET', "/purchase-requests/$pid", null, $buyer)['json']['status'] === 'Converted' && http('POST', "/purchase-requests/$pid/to-po", ['supplier_id' => $s1], $buyer)['status'] === 409);
check('the PO links back to the PR', (int)$po['json']['purchase_request_id'] === (int)$pid);

echo "\n== purchase order: guarded status, finance limit\n";
check('client cannot set a PO to Approved via PUT', ($r = http('PUT', "/purchase-orders/$poid", ['status' => 'Approved', 'notes' => 'trick'], $buyer)) && $r['status'] === 200 && $r['json']['status'] === 'Draft');
check('client cannot create an already-approved PO', http('POST', '/purchase-orders', ['supplier_id' => $s1, 'supplier_name' => 'x', 'date' => $today, 'status' => 'Approved', 'approved_by' => 1], $buyer)['json']['status'] === 'Draft');
http('PUT', '/settings', ['finance.po_approval_limit' => 10000], $admin);
$big = http('POST', '/purchase-requests', ['lines' => [['item_id' => $tom, 'qty' => 5000]]], $buyer)['json']['id'];
http('POST', "/purchase-requests/$big/submit", [], $buyer); http('POST', "/purchase-requests/$big/decide", ['action' => 'approve'], $m1);
$bigPo = http('POST', "/purchase-requests/$big/to-po", ['supplier_id' => $s1], $buyer)['json'];     // 5000 x 5 = 25 000 + VAT
check('large PO total computed (28 750)', abs($bigPo['total'] - 28750) < 0.01);
// the buyer tampers with the draft total before submitting: the server recomputes it from the lines
http('PUT', "/purchase-orders/{$bigPo['id']}", ['total' => 1, 'subtotal' => 1], $buyer);
$bs = http('POST', "/purchase-orders/{$bigPo['id']}/submit", [], $buyer);
check('tampered total is ignored: still above the limit -> Submitted for finance', $bs['status'] === 200 && $bs['json']['status'] === 'Submitted' && abs($bs['json']['total'] - 28750) < 0.01, json_encode($bs['json']));
check('submitter cannot approve own PO (403)', http('POST', "/purchase-orders/{$bigPo['id']}/decide", ['action' => 'approve'], $buyer)['status'] === 403);
check('a Submitted PO cannot be edited (409)', http('PUT', "/purchase-orders/{$bigPo['id']}", ['notes' => 'x'], $buyer)['status'] === 409);
check('a Submitted PO cannot be deleted even by an admin (409)', http('DELETE', "/purchase-orders/{$bigPo['id']}", null, $admin)['status'] === 409);
$rj = http('POST', "/purchase-orders/{$bigPo['id']}/decide", ['action' => 'reject', 'reason_code' => 'price_mismatch', 'note' => 'price too high'], $m1);
check('finance rejects -> back to Draft with the reason noted', $rj['status'] === 200 && $rj['json']['status'] === 'Draft' && strpos($rj['json']['notes'], 'price_mismatch') !== false, json_encode($rj['json']));
http('POST', "/purchase-orders/{$bigPo['id']}/submit", [], $buyer);
$ok = http('POST', "/purchase-orders/{$bigPo['id']}/decide", ['action' => 'approve'], $m2);
check('manager approves -> Approved with approver and time', $ok['status'] === 200 && $ok['json']['status'] === 'Approved' && (int)$ok['json']['approved_by'] > 0 && $ok['json']['approved_at'] !== null, json_encode($ok['json']));
$small = http('POST', "/purchase-orders/$poid/submit", [], $buyer);
check('PO below the limit (6 325) is approved automatically', $small['status'] === 200 && $small['json']['status'] === 'Approved' && $small['json']['approved_by'] === null, json_encode($small['json']));
check('an approved PO cannot be submitted again (409)', http('POST', "/purchase-orders/$poid/submit", [], $buyer)['status'] === 409);
http('PUT', '/settings', ['finance.po_approval_limit' => 0], $admin);
$pr3 = http('POST', '/purchase-requests', ['lines' => [['item_id' => $cuc, 'qty' => 10]]], $buyer)['json']['id'];
http('POST', "/purchase-requests/$pr3/submit", [], $buyer); http('POST', "/purchase-requests/$pr3/decide", ['action' => 'approve'], $m1);
$po3 = http('POST', "/purchase-requests/$pr3/to-po", ['supplier_id' => $s1], $buyer)['json']['id'];
check('limit 0 means every PO needs approval', http('POST', "/purchase-orders/$po3/submit", [], $buyer)['json']['status'] === 'Submitted');

echo "\n== approval switch and suggestions\n";
http('PUT', '/settings', ['purchasing.pr_approval_required' => 0], $admin);
$pr4 = http('POST', '/purchase-requests', ['lines' => [['item_id' => $pot, 'qty' => 5]]], $buyer)['json']['id'];
check('approval switched off: submit approves directly', http('POST', "/purchase-requests/$pr4/submit", [], $buyer)['json']['status'] === 'Approved');
http('PUT', '/settings', ['purchasing.pr_approval_required' => 1], $admin);
$sg = http('GET', '/purchase-requests/suggestions', null, $buyer)['json'];
$byItem = array_column($sg, null, 'item_id');
check('suggestions list items below their reorder level (tomato 500, cucumber 100; potato has none)', isset($byItem[$tom], $byItem[$cuc]) && !isset($byItem[$pot]) && abs($byItem[$tom]['shortfall'] - 500) < 0.001, json_encode($sg));
// stock arrives for tomatoes: 800 kg -> no longer short
$wh = http('POST', '/warehouses', ['code' => 'W', 'name' => 'W', 'type' => 'cold'], $admin)['json']['id'];
$room = http('POST', '/cold-rooms', ['warehouse_id' => $wh, 'code' => 'R', 'name' => 'R', 'temp_min' => 2, 'temp_max' => 6], $admin)['json']['id'];
http('POST', '/stock/receive', ['item_id' => $tom, 'qty' => 800, 'cold_room_id' => $room, 'expiry_date' => date('Y-m-d', strtotime('+5 days'))], $admin);
$sg = array_column(http('GET', '/purchase-requests/suggestions', null, $buyer)['json'], null, 'item_id');
check('after receiving 800 kg tomatoes they drop off the suggestions', !isset($sg[$tom]) && isset($sg[$cuc]));

echo "\n== lists, search, delete\n";
$list = http('GET', '/purchase-requests?status=Converted', null, $buyer)['json'];
check('list filters by status', count($list) >= 2 && $list[0]['status'] === 'Converted' && isset($list[0]['line_count']));
check('search by number', count(http('GET', '/purchase-requests?search=PR-0001', null, $buyer)['json']) === 1);
$dr = http('POST', '/purchase-requests', ['lines' => [['item_id' => $pot, 'qty' => 1]]], $buyer)['json']['id'];
check('requester deletes a draft', http('DELETE', "/purchase-requests/$dr", null, $buyer)['status'] === 200);
check('an approved PR cannot be deleted (409)', http('DELETE', "/purchase-requests/$pr4", null, $buyer)['status'] === 409);
check('PO search works', count(http('GET', '/purchase-orders?search=PO-0001', null, $buyer)['json']) === 1);

echo "\n== company isolation\n";
$co = http('POST', '/companies', ['name_en' => 'Other factory'], $admin)['json']['id'];
$adminId = (int)$pdo->query("SELECT id FROM users WHERE email = 'admin@manzuma.com'")->fetchColumn();
$pdo->exec("INSERT IGNORE INTO user_companies (user_id, company_id) VALUES ($adminId, $co)");
check('company 2 sees no PRs, POs or prices', count(http('GET', '/purchase-requests', null, $admin, $co)['json']) === 0 && count(http('GET', '/purchase-orders', null, $admin, $co)['json']) === 0 && count(http('GET', '/supplier-prices', null, $admin, $co)['json']) === 0);
check('company 2 cannot read a company 1 PR (404)', http('GET', "/purchase-requests/$pid", null, $admin, $co)['status'] === 404);
check('company 2 cannot decide a company 1 PR (404)', http('POST', "/purchase-requests/$pr3/decide", ['action' => 'approve'], $admin, $co)['status'] === 404);
check('company 2 cannot convert a company 1 PR (404)', http('POST', "/purchase-requests/$pr4/to-po", ['supplier_id' => $s1], $admin, $co)['status'] === 404);
check('company 2 cannot approve a company 1 PO (404)', http('POST', "/purchase-orders/$po3/decide", ['action' => 'approve'], $admin, $co)['status'] === 404);
check('company 2 has no price for company 1 suppliers', http('GET', "/supplier-prices/current?supplier_id=$s1&item_id=$tom", null, $admin, $co)['json']['price'] === null);
check('company 2 numbering starts again at PR-0001', http('POST', '/purchase-requests', ['lines' => [['item_id' => http('POST', '/items', ['code' => 'X', 'name' => 'x'], $admin, $co)['json']['id'], 'qty' => 1]]], $admin, $co)['json']['number'] === 'PR-0001');
echo "\n== module switch\n";
http('PUT', '/modules/purchase', ['is_enabled' => false], $admin);
check('closing Purchase blocks requests, prices and orders (403)', http('GET', '/purchase-requests', null, $admin)['status'] === 403 && http('GET', '/supplier-prices', null, $admin)['status'] === 403 && http('GET', '/purchase-orders', null, $admin)['status'] === 403);
http('PUT', '/modules/purchase', ['is_enabled' => true], $admin);
check('re-opened: data is back', count(http('GET', '/purchase-requests', null, $admin)['json']) >= 4);

echo "\n------------------------------------\n$pass passed, $fail failed\n";
if ($fail) { echo "Failed: " . implode('; ', $fails) . "\n"; exit(1); }
