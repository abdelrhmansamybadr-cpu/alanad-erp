<?php
// Phase 3 (suite 9): market buying — purchase offer, field inspection, Finance approval, cash custody, settlement, accounting.
//   php hostinger/api/tests/phase9_test.php http://127.0.0.1:8200/api  mysql-dsn user pass
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
$qcRole = (int)$pdo->query("SELECT id FROM roles WHERE role_key = 'quality_inspector' AND company_id IS NULL")->fetchColumn();
foreach ([["mb_$u@x.com", $wk], ["mq_$u@x.com", $qcRole], ["mf_$u@x.com", 2], ["mv_$u@x.com", 4]] as [$e, $r]) http('POST', '/users', ['name' => $e, 'email' => $e, 'password' => 'Strong-Pass-123', 'role_id' => $r], $admin);
$buyer = login("mb_$u@x.com", 'Strong-Pass-123'); $qc = login("mq_$u@x.com", 'Strong-Pass-123'); $fin = login("mf_$u@x.com", 'Strong-Pass-123'); $viewer = login("mv_$u@x.com", 'Strong-Pass-123');
$main = (int)http('GET', '/me', null, $admin)['json']['branches'][0]['id'];
$sup = http('POST', '/suppliers', ['code' => "MS$u", 'name' => "Market trader $u"], $admin)['json']['id'];
$tom = http('POST', '/items', ['code' => "MT$u", 'name' => "MK Tomato $u", 'shelf_life_days' => 7], $admin)['json']['id'];
$cuc = http('POST', '/items', ['code' => "MC$u", 'name' => "MK Cucumber $u", 'shelf_life_days' => 7], $admin)['json']['id'];
$set = fn(array $s) => http('PUT', '/settings', $s, $admin);
$set(['market.custody_limit_per_buyer' => 0, 'market.block_if_overdue' => true, 'market.require_field_inspection' => true, 'market.advance_pct' => 100]);
$gl = function (int $entryId) use ($pdo): array { $r = $pdo->query("SELECT a.system_key, l.debit, l.credit FROM gl_lines l JOIN gl_accounts a ON a.id = l.account_id WHERE l.entry_id = $entryId")->fetchAll(); $o = []; foreach ($r as $x) $o[$x['system_key']][] = [(float)$x['debit'], (float)$x['credit']]; return $o; };
$offer = fn(array $lines, ?string $tok = null, ?int $branch = null) => http('POST', '/market-purchases', ['market_name' => "Al Hisba $u", 'supplier_id' => $sup, 'lines' => $lines], $tok ?? $buyer, null, $branch);
$line = fn(int $item, float $q, float $p) => ['item_id' => $item, 'qty_kg' => $q, 'offer_price' => $p];
$inspectAll = function (int $id, bool $failLast = false) use ($qc) {
    $d = http('GET', "/market-purchases/$id", null, $GLOBALS['admin'])['json']; $n = count($d['lines']);
    foreach ($d['lines'] as $i => $l) { $f = $failLast && $i === $n - 1; http('POST', "/market-purchases/$id/inspect", ['line_id' => $l['id'], 'result' => $f ? 'Fail' : 'Pass', 'grade' => 'A', 'note' => $f ? 'bruised' : null], $qc); }
};
$approved = function (array $lines, bool $failLast = false) use ($offer, $inspectAll, $buyer, $fin) {
    $id = (int)$offer($lines)['json']['id']; $inspectAll($id, $failLast);
    http('POST', "/market-purchases/$id/submit", null, $buyer); http('POST', "/market-purchases/$id/approve", null, $fin);
    return $id;
};

echo "\n== the buyer prepares a purchase offer\n";
check('a read-only user cannot create (403)', http('POST', '/market-purchases', ['market_name' => 'x', 'supplier_id' => $sup, 'lines' => [$line($tom, 10, 4)]], $viewer)['status'] === 403);
check('the market name is required (422)', http('POST', '/market-purchases', ['supplier_id' => $sup, 'lines' => [$line($tom, 10, 4)]], $buyer)['status'] === 422);
check('the trader (supplier) is required (422)', http('POST', '/market-purchases', ['market_name' => 'x', 'lines' => [$line($tom, 10, 4)]], $buyer)['status'] === 422);
check('at least one line (422)', $offer([])['status'] === 422);
check('quantity and price must be positive (422)', $offer([$line($tom, 0, 4)])['status'] === 422 && $offer([$line($tom, 10, 0)])['status'] === 422);
check('the same item twice (422)', $offer([$line($tom, 10, 4), $line($tom, 5, 4)])['status'] === 422);
check('an unknown item (404)', $offer([$line(999999, 10, 4)])['status'] === 404);
$o1 = $offer([$line($tom, 1000, 4.00), $line($cuc, 200, 6.00)]);
$P1 = (int)($o1['json']['id'] ?? 0);
check('the offer is a Draft in the main branch, numbered MP-, total 5,200, nothing inspected yet', $o1['status'] === 201 && $o1['json']['status'] === 'Draft' && strpos($o1['json']['number'], 'MP-') === 0 && $o1['json']['offer_total'] == 5200.0 && (int)$o1['json']['branch_id'] === $main && $o1['json']['lines'][0]['inspect_result'] === 'Pending', json_encode($o1['json']['error'] ?? $o1['json']['offer_total'] ?? null));

echo "\n== the quality person inspects each line at the market, before buying\n";
check('it cannot go to Finance before inspection (409)', http('POST', "/market-purchases/$P1/submit", null, $buyer)['status'] === 409);
$L = array_column(http('GET', "/market-purchases/$P1", null, $buyer)['json']['lines'], 'id');
check('the buyer cannot inspect his own goods (403)', http('POST', "/market-purchases/$P1/inspect", ['line_id' => $L[0], 'result' => 'Pass'], $buyer)['status'] === 403);
check('result must be Pass or Fail (422)', http('POST', "/market-purchases/$P1/inspect", ['line_id' => $L[0], 'result' => 'ok'], $qc)['status'] === 422);
check('a failed line needs a reason (422)', http('POST', "/market-purchases/$P1/inspect", ['line_id' => $L[1], 'result' => 'Fail'], $qc)['status'] === 422);
check('an unknown line (404)', http('POST', "/market-purchases/$P1/inspect", ['line_id' => 999999, 'result' => 'Pass'], $qc)['status'] === 404);
http('POST', "/market-purchases/$P1/inspect", ['line_id' => $L[0], 'result' => 'Pass', 'grade' => 'A'], $qc);
$ins = http('POST', "/market-purchases/$P1/inspect", ['line_id' => $L[1], 'result' => 'Fail', 'note' => 'yellowing, soft'], $qc)['json'];
check('tomato passes, cucumber fails; the passing value is 4,000', $ins['lines'][0]['inspect_result'] === 'Pass' && $ins['lines'][1]['inspect_result'] === 'Fail' && $ins['pass_total'] == 4000.0 && !empty($ins['lines'][1]['inspected_by']), json_encode([$ins['pass_total']]));
check('only the buyer can send it to Finance (403)', http('POST', "/market-purchases/$P1/submit", null, $fin)['status'] === 403);
check('the buyer sends it to Finance', http('POST', "/market-purchases/$P1/submit", null, $buyer)['json']['status'] === 'Submitted');
check('a submitted offer cannot be edited or inspected again (409)', http('PUT', "/market-purchases/$P1", ['notes' => 'x'], $buyer)['status'] === 409 && http('POST', "/market-purchases/$P1/inspect", ['line_id' => $L[0], 'result' => 'Pass'], $qc)['status'] === 409);

echo "\n== Finance reviews\n";
check('the buyer and the quality person cannot approve (403)', http('POST', "/market-purchases/$P1/approve", null, $buyer)['status'] === 403 && http('POST', "/market-purchases/$P1/approve", null, $qc)['status'] === 403);
check('returning needs a reason (422)', http('POST', "/market-purchases/$P1/return", [], $fin)['status'] === 422);
$rt = http('POST', "/market-purchases/$P1/return", ['reason' => 'tomato price too high for today'], $fin)['json'];
check('Finance returns it with a reason', $rt['status'] === 'Returned' && $rt['return_reason'] === 'tomato price too high for today');
$up = http('PUT', "/market-purchases/$P1", ['lines' => [$line($tom, 1000, 4.20)]], $buyer)['json'];
check('the buyer corrects the price: the inspection starts again', $up['lines'][0]['offer_price'] == 4.2 && $up['lines'][0]['inspect_result'] === 'Pending' && count($up['lines']) === 1);
check('another person cannot edit his offer (403)', http('PUT', "/market-purchases/$P1", ['notes' => 'x'], $fin)['status'] === 403);
$inspectAll($P1);
http('POST', "/market-purchases/$P1/submit", null, $buyer);
$ap = http('POST', "/market-purchases/$P1/approve", null, $fin);
check('Finance approves: total 4,200 and a purchase order is created', $ap['status'] === 200 && $ap['json']['status'] === 'Approved' && $ap['json']['total_amount'] == 4200.0 && !empty($ap['json']['po_id']), json_encode($ap['json']['error'] ?? null));
$po = $pdo->query("SELECT status, total, supplier_id, branch_id FROM purchase_orders WHERE id = " . (int)$ap['json']['po_id'])->fetch();
$pol = $pdo->query("SELECT COUNT(*), SUM(qty), MAX(rate) FROM purchase_order_lines WHERE purchase_order_id = " . (int)$ap['json']['po_id'])->fetch(PDO::FETCH_NUM);
check('the order is Approved, for the trader, in the same branch, one line 1000 kg at 4.20', $po['status'] === 'Approved' && (float)$po['total'] == 4200.0 && (int)$po['supplier_id'] === $sup && (int)$po['branch_id'] === $main && (int)$pol[0] === 1 && (float)$pol[1] == 1000.0 && (float)$pol[2] == 4.2);

echo "\n== cash custody\n";
check('the buyer cannot issue cash to himself (403)', http('POST', "/market-purchases/$P1/issue-cash", null, $buyer)['status'] === 403);
check('more than the approved total is refused (422)', http('POST', "/market-purchases/$P1/issue-cash", ['amount' => 5000], $fin)['status'] === 422);
$ic = http('POST', "/market-purchases/$P1/issue-cash", null, $fin);
check('Finance gives 4,200 cash: status Cash_Issued with a due date', $ic['status'] === 200 && $ic['json']['status'] === 'Cash_Issued' && $ic['json']['issued_amount'] == 4200.0 && !empty($ic['json']['due_date']), json_encode($ic['json']['error'] ?? null));
$g = $gl((int)$ic['json']['gl_issue_entry_id']);
check('accounting: Dr buyers custody 4,200 / Cr cash 4,200', ($g['custody'][0][0] ?? 0) == 4200.0 && ($g['cash'][0][1] ?? 0) == 4200.0, json_encode($g));
check('cash cannot be given twice (409)', http('POST', "/market-purchases/$P1/issue-cash", null, $fin)['status'] === 409);
check('the custody report shows the buyer holding 4,200', (function () use ($admin) { foreach (http('GET', '/market-purchases/custody', null, $admin)['json'] as $r) if ($r['amount'] >= 4200) return true; return false; })());
check('an offer with cash out cannot be cancelled (409)', http('POST', "/market-purchases/$P1/cancel", ['reason' => 'x'], $buyer)['status'] === 409);

echo "\n== settlement with receipts\n";
check('spent + returned must equal the cash received (422)', http('POST', "/market-purchases/$P1/settle", ['spent_amount' => 4000, 'returned_amount' => 100, 'note' => 'r'], $buyer)['status'] === 422);
check('the receipts must be described (422)', http('POST', "/market-purchases/$P1/settle", ['spent_amount' => 4150, 'returned_amount' => 50], $buyer)['status'] === 422);
check('only the buyer settles (403)', http('POST', "/market-purchases/$P1/settle", ['spent_amount' => 4150, 'returned_amount' => 50, 'note' => 'r'], $fin)['status'] === 403);
$st = http('POST', "/market-purchases/$P1/settle", ['spent_amount' => 4150, 'returned_amount' => 50, 'note' => 'receipts 4471, 4472 from trader Abu Ali'], $buyer)['json'];
check('the buyer settles: spent 4,150, gave back 50', $st['status'] === 'Settlement_Submitted' && $st['spent_amount'] == 4150.0 && $st['returned_amount'] == 50.0);
check('Finance can send the settlement back with a reason', http('POST', "/market-purchases/$P1/reject-settlement", [], $fin)['status'] === 422 && http('POST', "/market-purchases/$P1/reject-settlement", ['reason' => 'receipt 4472 is unreadable'], $fin)['json']['status'] === 'Cash_Issued');
http('POST', "/market-purchases/$P1/settle", ['spent_amount' => 4150, 'returned_amount' => 50, 'note' => 'receipts 4471, 4472 (rescanned)'], $buyer);
check('the buyer cannot confirm his own settlement (403)', http('POST', "/market-purchases/$P1/confirm-settlement", null, $buyer)['status'] === 403);
$cf = http('POST', "/market-purchases/$P1/confirm-settlement", null, $fin);
check('Finance confirms: Settled', $cf['status'] === 200 && $cf['json']['status'] === 'Settled' && !empty($cf['json']['gl_settle_entry_id']), json_encode($cf['json']['error'] ?? null));
$g2 = $gl((int)$cf['json']['gl_settle_entry_id']);
$dr = 0; $cr = 0; foreach ($g2 as $rows) foreach ($rows as $r) { $dr += $r[0]; $cr += $r[1]; }
check('accounting: Dr goods-received 4,200 / Cr price variance 50 (cheaper than offered) / Dr cash 50 / Cr custody 4,200, balanced', ($g2['grni'][0][0] ?? 0) == 4200.0 && ($g2['price_variance'][0][1] ?? 0) == 50.0 && ($g2['cash'][0][0] ?? 0) == 50.0 && ($g2['custody'][0][1] ?? 0) == 4200.0 && abs($dr - $cr) < 0.005, json_encode($g2));
check('the custody report no longer lists it', (function () use ($admin, $P1) { return true; })() && count(array_filter(http('GET', '/market-purchases/custody', null, $admin)['json'], fn($r) => $r['amount'] == 4200.0 && $r['purchases'] === 1)) === 0);

echo "\n== custody limit and overdue custody (settings)\n";
$set(['market.custody_limit_per_buyer' => 5000]);
$P2 = $approved([$line($tom, 500, 6.00)]); $P3 = $approved([$line($tom, 400, 6.00)]);
check('first 3,000 within the limit', http('POST', "/market-purchases/$P2/issue-cash", null, $fin)['status'] === 200);
$lim = http('POST', "/market-purchases/$P3/issue-cash", null, $fin);
check('a second 2,400 would take him above 5,000: refused (409)', $lim['status'] === 409 && stripos($lim['json']['error'], 'limit') !== false, json_encode($lim['json']));
$set(['market.custody_limit_per_buyer' => 0]);
$pdo->exec("UPDATE market_purchases SET due_date = DATE_SUB(CURDATE(), INTERVAL 1 DAY) WHERE id = $P2");
check('the unsettled custody is overdue (detail and list)', http('GET', "/market-purchases/$P2", null, $admin)['json']['is_overdue'] === true && (function () use ($admin, $P2) { foreach (http('GET', '/market-purchases', null, $admin)['json'] as $r) if ($r['id'] == $P2) return $r['is_overdue'] === true; return false; })());
$ov = http('POST', "/market-purchases/$P3/issue-cash", null, $fin);
check('no new cash for a buyer with an overdue custody (409)', $ov['status'] === 409 && stripos($ov['json']['error'], 'overdue') !== false, json_encode($ov['json']));
$set(['market.block_if_overdue' => false]);
check('the rule can be switched off in settings', http('POST', "/market-purchases/$P3/issue-cash", null, $fin)['status'] === 200);
$set(['market.block_if_overdue' => true]);
http('POST', "/market-purchases/$P2/settle", ['spent_amount' => 3000, 'returned_amount' => 0, 'note' => 'receipts'], $buyer);
$c2 = http('POST', "/market-purchases/$P2/confirm-settlement", null, $fin)['json'];
check('settled exactly as offered: no price variance line', $c2['status'] === 'Settled' && !isset($gl((int)$c2['gl_settle_entry_id'])['price_variance']));
check('spending more than the cash received cannot be reported (422)', http('POST', "/market-purchases/$P3/settle", ['spent_amount' => 2500, 'returned_amount' => 0, 'note' => 'receipts'], $buyer)['status'] === 422);
http('POST', "/market-purchases/$P3/settle", ['spent_amount' => 2400, 'returned_amount' => 0, 'note' => 'receipts'], $buyer); http('POST', "/market-purchases/$P3/confirm-settlement", null, $fin);
$set(['market.advance_pct' => 110]);
$P6 = $approved([$line($tom, 100, 10.00)]);
check('with an advance of 110% the cash can exceed the offer (1,100)', http('POST', "/market-purchases/$P6/issue-cash", null, $fin)['json']['issued_amount'] == 1100.0);
http('POST', "/market-purchases/$P6/settle", ['spent_amount' => 1100, 'returned_amount' => 0, 'note' => 'receipts'], $buyer);
check('spending more than the offer shows as a price variance debit of 100', ($gl((int)http('POST', "/market-purchases/$P6/confirm-settlement", null, $fin)['json']['gl_settle_entry_id'])['price_variance'][0][0] ?? 0) == 100.0);
$set(['market.advance_pct' => 100]);

echo "\n== cancelling, inspection switch, separation of duties\n";
$P4 = $approved([$line($tom, 100, 5.00)]);
$cn = http('POST', "/market-purchases/$P4/cancel", ['reason' => 'trader sold out'], $buyer)['json'];
check('cancelling an approved offer also cancels its purchase order', $cn['status'] === 'Cancelled' && $pdo->query("SELECT status FROM purchase_orders WHERE id = " . (int)$cn['po_id'])->fetchColumn() === 'Cancelled');
check('cancel needs a reason (422)', http('POST', "/market-purchases/" . (int)$offer([$line($tom, 5, 5)])['json']['id'] . "/cancel", [], $buyer)['status'] === 422);
$set(['market.require_field_inspection' => false]);
$P5 = (int)$offer([$line($tom, 50, 5.00)])['json']['id'];
check('with the inspection rule off, the buyer can submit without it', http('POST', "/market-purchases/$P5/submit", null, $buyer)['status'] === 200 && http('POST', "/market-purchases/$P5/approve", null, $fin)['json']['total_amount'] == 250.0);
$set(['market.require_field_inspection' => true]);
$PA = (int)$offer([$line($tom, 10, 5.00)], $admin)['json']['id'];
$pdo->exec("UPDATE market_purchase_lines SET inspect_result = 'Pass' WHERE purchase_id = $PA");
http('POST', "/market-purchases/$PA/submit", null, $admin);
check('a buyer with full rights still cannot approve his own offer (403)', http('POST', "/market-purchases/$PA/approve", null, $admin)['status'] === 403);

echo "\n== branches and companies\n";
$B1 = (int)http('POST', '/company-branches', ['code' => "MB$u", 'name_en' => "MK Jeddah $u"], $admin)['json']['id'];
$PJ = (int)$offer([$line($tom, 10, 5.00)], null, $B1)['json']['id'];
check('an offer made while working in Jeddah belongs to Jeddah and is hidden from the main branch (404)', http('GET', "/market-purchases/$PJ", null, $admin, null, $B1)['status'] === 200 && http('GET', "/market-purchases/$PJ", null, $admin, null, $main)['status'] === 404);
$co = http('POST', '/companies', ['name_en' => "MK other $u"], $admin)['json']['id'];
$adminId = (int)$pdo->query("SELECT id FROM users WHERE email = 'admin@manzuma.com'")->fetchColumn();
$pdo->exec("INSERT IGNORE INTO user_companies (user_id, company_id) VALUES ($adminId, $co)");
check('company 2 cannot see, approve or cancel company 1\'s offers (404) and its list/custody are empty', http('GET', "/market-purchases/$P1", null, $admin, $co)['status'] === 404 && http('POST', "/market-purchases/$PA/approve", null, $admin, $co)['status'] === 404 && http('POST', "/market-purchases/$PA/cancel", ['reason' => 'x'], $admin, $co)['status'] === 404 && count(http('GET', '/market-purchases', null, $admin, $co)['json']) === 0 && count(http('GET', '/market-purchases/custody', null, $admin, $co)['json']) === 0);
check('a new company gets its own custody and cash accounts', (int)$pdo->query("SELECT COUNT(*) FROM gl_accounts WHERE company_id = $co AND system_key IN ('custody','cash')")->fetchColumn() === 2);

echo "\n------------------------------------\n$pass passed, $fail failed\n";
if ($fail) { echo "Failed: " . implode('; ', $fails) . "\n"; exit(1); }
