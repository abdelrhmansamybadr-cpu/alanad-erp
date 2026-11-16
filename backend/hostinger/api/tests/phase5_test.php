<?php
// Phase 4 tests (suite 5): finance — ledger, automatic postings, supplier invoices with a 3-way match, payments, reports.
//   php hostinger/api/tests/phase5_test.php http://127.0.0.1:8200/api  mysql-dsn user pass
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

$u = substr((string)time(), -6);
$admin = login('admin@manzuma.com', 'password');
foreach ([["fmgr_$u@x.com", 2], ["fviewer_$u@x.com", 4]] as [$e, $r]) http('POST', '/users', ['name' => $e, 'email' => $e, 'password' => 'Strong-Pass-123', 'role_id' => $r], $admin);
$mgr = login("fmgr_$u@x.com", 'Strong-Pass-123'); $viewer = login("fviewer_$u@x.com", 'Strong-Pass-123');
http('PUT', '/settings', ['finance.closed_until' => ''], $admin);

/** account balance (natural side) from the trial balance */
$tb = function (?string $code = null) use ($admin) {
    $r = http('GET', '/finance-reports/trial-balance', null, $admin)['json'];
    if ($code === null) return $r;
    foreach ($r['rows'] as $row) if ($row['code'] === $code) return $row;
    return ['debit' => 0, 'credit' => 0, 'balance' => 0];
};
$entryFor = function (string $type, int $srcId) use ($pdo) {
    $id = $pdo->query("SELECT id FROM gl_entries WHERE source_type = '$type' AND source_id = $srcId")->fetchColumn();
    return $id ? http('GET', "/gl-entries/$id", null, $GLOBALS['admin'])['json'] : null;
};
$amt = fn($entry, $code, $side) => array_sum(array_map(fn($l) => $l[$side], array_filter($entry['lines'] ?? [], fn($l) => $l['account_code'] === $code)));

echo "\n== chart of accounts\n";
$accts = http('GET', '/gl-accounts', null, $admin)['json'];
$codes = array_column($accts, 'code');
check('the Saudi starter chart is there (inventory, GRNI, payables, VAT, bank)', !array_diff(['1110', '1310', '1410', '2110', '2120', '2210', '5200'], $codes));
check('viewer cannot add an account (403)', http('POST', '/gl-accounts', ['code' => '5510', 'name_en' => 'x', 'type' => 'expense'], $viewer)['status'] === 403);
check('bad account code (422)', http('POST', '/gl-accounts', ['code' => 'AB', 'name_en' => 'x', 'type' => 'expense'], $admin)['status'] === 422);
check('duplicate account code (409)', http('POST', '/gl-accounts', ['code' => '1310', 'name_en' => 'x', 'type' => 'asset'], $admin)['status'] === 409);
$p5 = null; foreach ($accts as $a) if ($a['code'] === '5000') $p5 = $a['id'];
check('a child must have the type of its parent (422)', http('POST', '/gl-accounts', ['code' => "55$u", 'name_en' => 'x', 'type' => 'asset', 'parent_id' => $p5], $admin)['status'] === 422);
$newAcc = http('POST', '/gl-accounts', ['code' => "55$u", 'name_en' => "Fuel $u", 'name_ar' => 'وقود', 'type' => 'expense', 'parent_id' => $p5], $admin);
check('a new expense account is created under Expenses', $newAcc['status'] === 201);
$sysAcc = array_values(array_filter($accts, fn($a) => $a['code'] === '2110'))[0]['id'];
check('an account used by automatic postings cannot be deactivated (409)', http('PUT', "/gl-accounts/$sysAcc", ['is_active' => false], $admin)['status'] === 409);

echo "\n== setup: order, goods stored\n";
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
http('PUT', '/settings', ['receiving.allow_direct_po_receipt' => true], $admin);   // this suite books goods in through the direct path (the gate flow is tested in the phase 4 suite)
$po1 = $mkpo([['item_id' => $tom, 'item_name' => 'Tomato', 'qty' => 100, 'rate' => 5, 'amount' => 500], ['item_id' => $cuc, 'item_name' => 'Cucumber', 'qty' => 50, 'rate' => 3, 'amount' => 150]]);
$inv0 = $tb('1310'); $grni0 = $tb('2120');
$r1 = http('POST', '/stock/receive-po', ['po_id' => $po1, 'lines' => [['item_id' => $tom, 'qty' => 100, 'cold_room_id' => $room], ['item_id' => $cuc, 'qty' => 50, 'cold_room_id' => $room]]], $admin);
check('setup: order exists and 100 kg tomatoes + 50 kg cucumbers are stored', $po1 && $r1['status'] === 201, json_encode($r1['json']));
$inv1 = $tb('1310'); $grni1 = $tb('2120');
check('storing the goods booked Dr Inventory +650.00', round($inv1['debit'] - $inv0['debit'], 2) == 650.00, json_encode([$inv0, $inv1]));
check('... and Cr Goods received not invoiced +650.00', round($grni1['credit'] - $grni0['credit'], 2) == 650.00);
$rec = http('GET', "/ap-invoices/receivable?po_id=$po1", null, $admin)['json'];
check('receivable shows received 100 / 50 kg at the order prices, nothing invoiced yet', $rec['lines'][0]['received'] == 100 && $rec['lines'][0]['billable'] == 100 && $rec['lines'][0]['po_price'] == 5 && $rec['lines'][1]['billable'] == 50);

echo "\n== supplier invoice: entry, match, approval\n";
$lines1 = [['item_id' => $tom, 'qty_kg' => 100, 'price' => 5], ['item_id' => $cuc, 'qty_kg' => 50, 'price' => 3]];
check('viewer cannot enter an invoice (403)', http('POST', '/ap-invoices', ['po_id' => $po1, 'supplier_invoice_no' => "F-$u-1", 'lines' => $lines1], $viewer)['status'] === 403);
check("the supplier's invoice number is required (422)", http('POST', '/ap-invoices', ['po_id' => $po1, 'lines' => $lines1], $admin)['status'] === 422);
check('an invoice needs lines (422)', http('POST', '/ap-invoices', ['po_id' => $po1, 'supplier_invoice_no' => "F-$u-x", 'lines' => []], $admin)['status'] === 422);
check('a price of zero (422)', http('POST', '/ap-invoices', ['po_id' => $po1, 'supplier_invoice_no' => "F-$u-x", 'lines' => [['item_id' => $tom, 'qty_kg' => 1, 'price' => 0]]], $admin)['status'] === 422);
$i1 = http('POST', '/ap-invoices', ['po_id' => $po1, 'supplier_invoice_no' => "F-$u-1", 'invoice_date' => date('Y-m-d'), 'lines' => $lines1], $admin);
$inv1id = (int)($i1['json']['id'] ?? 0);
check('invoice entered as Draft: 650 + 15 % VAT = 747.50, due in 30 days', $i1['status'] === 201 && $i1['json']['status'] === 'Draft' && $i1['json']['subtotal'] == 650 && $i1['json']['vat_amount'] == 97.5 && $i1['json']['total'] == 747.5 && $i1['json']['due_date'] === date('Y-m-d', strtotime('+30 days')), json_encode($i1['json']));
check('the same supplier invoice number twice (409)', http('POST', '/ap-invoices', ['po_id' => $po1, 'supplier_invoice_no' => "F-$u-1", 'lines' => $lines1], $admin)['status'] === 409);
check('a draft cannot be decided (409)', http('POST', "/ap-invoices/$inv1id/decide", ['action' => 'approve'], $mgr)['status'] === 409);
$sb = http('POST', "/ap-invoices/$inv1id/submit", null, $admin);
check('submit runs the 3-way match: matched -> Pending', $sb['json']['status'] === 'Pending' && $sb['json']['match_status'] === 'matched' && $sb['json']['lines'][0]['received_kg'] == 100 && $sb['json']['lines'][0]['issue'] === null, json_encode($sb['json']['status'] ?? $sb['json']));
check('the person who entered it cannot approve (403)', http('POST', "/ap-invoices/$inv1id/decide", ['action' => 'approve'], $admin)['status'] === 403);
check('viewer cannot approve (403)', http('POST', "/ap-invoices/$inv1id/decide", ['action' => 'approve'], $viewer)['status'] === 403);
check('a reject needs a reason from the list (422)', http('POST', "/ap-invoices/$inv1id/decide", ['action' => 'reject', 'reason_code' => 'nope'], $mgr)['status'] === 422);
$ap0 = $tb('2110'); $vat0 = $tb('1410');
$ok1 = http('POST', "/ap-invoices/$inv1id/decide", ['action' => 'approve'], $mgr);
check('another person approves: Approved with a journal entry', $ok1['status'] === 200 && $ok1['json']['status'] === 'Approved' && $ok1['json']['entry_number'] !== null, json_encode($ok1['json']['status'] ?? $ok1['json']));
$je = $entryFor('ap_invoice', $inv1id);
check('entry: Dr GRNI 650.00, Dr VAT input 97.50, Cr Payables 747.50', $amt($je, '2120', 'debit') == 650 && $amt($je, '1410', 'debit') == 97.5 && $amt($je, '2110', 'credit') == 747.5 && $je['total'] == 747.5, json_encode($je['lines'] ?? $je));
check('payables and VAT input moved in the trial balance', round($tb('2110')['credit'] - $ap0['credit'], 2) == 747.5 && round($tb('1410')['debit'] - $vat0['debit'], 2) == 97.5);
check('an approved invoice cannot be edited (409)', http('PUT', "/ap-invoices/$inv1id", ['notes' => 'x'], $admin)['status'] === 409);
check('billing the same goods again is on hold: nothing left to bill', ($i2 = http('POST', "/ap-invoices/" . http('POST', '/ap-invoices', ['po_id' => $po1, 'supplier_invoice_no' => "F-$u-2", 'lines' => [['item_id' => $tom, 'qty_kg' => 10, 'price' => 5]]], $admin)['json']['id'] . "/submit", null, $admin))['json']['status'] === 'On_Hold' && stripos($i2['json']['match_note'], 'not yet invoiced') !== false, json_encode($i2['json']['match_note'] ?? $i2['json']));
$inv2id = $i2['json']['id'];
check('an on-hold invoice needs a note to be approved anyway (422)', http('POST', "/ap-invoices/$inv2id/decide", ['action' => 'approve'], $mgr)['status'] === 422);
$rj = http('POST', "/ap-invoices/$inv2id/decide", ['action' => 'reject', 'reason_code' => 'quantity_mismatch', 'note' => 'Already billed on F-1'], $mgr);
check('rejected with a reason (back to purchasing / the accountant)', $rj['json']['status'] === 'Rejected' && $rj['json']['reject_reason_code'] === 'quantity_mismatch');
$ed = http('PUT', "/ap-invoices/$inv2id", ['lines' => [['item_id' => $cuc, 'qty_kg' => 5, 'price' => 9]]], $admin);
check('the accountant can correct a rejected invoice: back to Draft with new totals', $ed['json']['status'] === 'Draft' && $ed['json']['subtotal'] == 45 && $ed['json']['reject_reason_code'] === null, json_encode($ed['json']['status'] ?? $ed['json']));
check('a draft invoice can be deleted by its author', http('DELETE', "/ap-invoices/$inv2id", null, $admin)['status'] === 200);

echo "\n== price differences\n";
$po2 = $mkpo([['item_id' => $tom, 'item_name' => 'Tomato', 'qty' => 100, 'rate' => 5, 'amount' => 500]]);
http('POST', '/stock/receive-po', ['po_id' => $po2, 'lines' => [['item_id' => $tom, 'qty' => 100, 'cold_room_id' => $room]]], $admin);
$pv0 = $tb('5200');
$i3 = http('POST', '/ap-invoices', ['po_id' => $po2, 'supplier_invoice_no' => "F-$u-3", 'lines' => [['item_id' => $tom, 'qty_kg' => 100, 'price' => 5.04]]], $admin)['json']['id'];
$s3 = http('POST', "/ap-invoices/$i3/submit", null, $admin);
check('5.04 vs 5.00 is inside the 2 % price tolerance: matched', $s3['json']['status'] === 'Pending', json_encode($s3['json']['match_note'] ?? null));
http('POST', "/ap-invoices/$i3/decide", ['action' => 'approve'], $mgr);
$je3 = $entryFor('ap_invoice', $i3);
check('the 4.00 price difference goes to Purchase price variance (Dr 5200)', $amt($je3, '5200', 'debit') == 4.0 && $amt($je3, '2120', 'debit') == 500.0 && $amt($je3, '2110', 'credit') == 579.6, json_encode($je3['lines'] ?? $je3));
$po3 = $mkpo([['item_id' => $tom, 'item_name' => 'Tomato', 'qty' => 100, 'rate' => 5, 'amount' => 500]]);
http('POST', '/stock/receive-po', ['po_id' => $po3, 'lines' => [['item_id' => $tom, 'qty' => 100, 'cold_room_id' => $room]]], $admin);
$i4 = http('POST', '/ap-invoices', ['po_id' => $po3, 'supplier_invoice_no' => "F-$u-4", 'lines' => [['item_id' => $tom, 'qty_kg' => 100, 'price' => 5.5]]], $admin)['json']['id'];
$s4 = http('POST', "/ap-invoices/$i4/submit", null, $admin);
check('5.50 vs 5.00 is outside the tolerance: On_Hold, the note says which price', $s4['json']['status'] === 'On_Hold' && stripos($s4['json']['match_note'], 'differs from the order price') !== false && $s4['json']['lines'][0]['issue'] !== null, json_encode($s4['json']['match_note'] ?? null));
$ap4 = http('POST', "/ap-invoices/$i4/decide", ['action' => 'approve', 'note' => 'Market price rose, buyer agreed by phone'], $mgr);
check('approved anyway with a note: posted, the variance (50.00) booked', $ap4['json']['status'] === 'Approved' && $amt($entryFor('ap_invoice', $i4), '5200', 'debit') == 50.0, json_encode($ap4['json']['status'] ?? $ap4['json']));
$po4 = $mkpo([['item_id' => $tom, 'item_name' => 'Tomato', 'qty' => 100, 'rate' => 5, 'amount' => 500]]);
$i5 = http('POST', '/ap-invoices', ['po_id' => $po4, 'supplier_invoice_no' => "F-$u-5", 'lines' => [['item_id' => $tom, 'qty_kg' => 100, 'price' => 5]]], $admin)['json']['id'];
check('billing goods that were never received: On_Hold ("nothing has been received")', stripos(http('POST', "/ap-invoices/$i5/submit", null, $admin)['json']['match_note'], 'nothing has been received') !== false);
check('an item that is not on the order is a mismatch', stripos(http('POST', "/ap-invoices/" . http('POST', '/ap-invoices', ['po_id' => $po4, 'supplier_invoice_no' => "F-$u-6", 'lines' => [['item_id' => $cuc, 'qty_kg' => 1, 'price' => 3]]], $admin)['json']['id'] . "/submit", null, $admin)['json']['match_note'], 'not on the purchase order') !== false);

echo "\n== payments\n";
check('viewer cannot enter a payment (403)', http('POST', '/ap-payments', ['supplier_id' => $sup, 'amount' => 100, 'allocations' => [['invoice_id' => $inv1id, 'amount' => 100]]], $viewer)['status'] === 403);
check('a payment must name the invoices it settles (422)', http('POST', '/ap-payments', ['supplier_id' => $sup, 'amount' => 100], $admin)['status'] === 422);
check('allocations must add up to the amount (422)', http('POST', '/ap-payments', ['supplier_id' => $sup, 'amount' => 100, 'allocations' => [['invoice_id' => $inv1id, 'amount' => 90]]], $admin)['status'] === 422);
check('more than what is open on the invoice (422)', http('POST', '/ap-payments', ['supplier_id' => $sup, 'amount' => 800, 'allocations' => [['invoice_id' => $inv1id, 'amount' => 800]]], $admin)['status'] === 422);
check('an invoice that is not approved cannot be paid (409)', http('POST', '/ap-payments', ['supplier_id' => $sup, 'amount' => 10, 'allocations' => [['invoice_id' => $i5, 'amount' => 10]]], $admin)['status'] === 409);
$py1 = http('POST', '/ap-payments', ['supplier_id' => $sup, 'amount' => 400, 'method' => 'bank_transfer', 'reference' => 'TRX-1', 'allocations' => [['invoice_id' => $inv1id, 'amount' => 400]]], $admin);
$py1id = (int)($py1['json']['id'] ?? 0);
check('payment entered as Pending (nothing posted yet)', $py1['status'] === 201 && $py1['json']['status'] === 'Pending' && $py1['json']['entry_number'] === null && str_starts_with($py1['json']['number'], 'PAY-'), json_encode($py1['json']));
check('a second pending payment cannot reserve the same money (open 347.50)', http('POST', '/ap-payments', ['supplier_id' => $sup, 'amount' => 400, 'allocations' => [['invoice_id' => $inv1id, 'amount' => 400]]], $admin)['status'] === 422);
check('the person who entered the payment cannot approve it (403)', http('POST', "/ap-payments/$py1id/decide", ['action' => 'approve'], $admin)['status'] === 403);
check('viewer cannot approve a payment (403)', http('POST', "/ap-payments/$py1id/decide", ['action' => 'approve'], $viewer)['status'] === 403);
$bank0 = $tb('1110'); $ap1 = $tb('2110');
$pa = http('POST', "/ap-payments/$py1id/decide", ['action' => 'approve'], $mgr);
check('approved: Posted with a journal entry', $pa['json']['status'] === 'Posted' && $pa['json']['entry_number'] !== null, json_encode($pa['json']['status'] ?? $pa['json']));
check('Dr Payables 400 / Cr Bank 400', round($tb('2110')['debit'] - $ap1['debit'], 2) == 400 && round($tb('1110')['credit'] - $bank0['credit'], 2) == 400);
$d1 = http('GET', "/ap-invoices/$inv1id", null, $admin)['json'];
check('the invoice shows paid 400.00 and open 347.50', $d1['paid_amount'] == 400 && $d1['open_amount'] == 347.5);
$py2 = http('POST', '/ap-payments', ['supplier_id' => $sup, 'amount' => 347.5, 'allocations' => [['invoice_id' => $inv1id, 'amount' => 347.5]]], $admin)['json']['id'];
http('POST', "/ap-payments/$py2/decide", ['action' => 'approve'], $mgr);
check('the second payment settles it: open 0.00', http('GET', "/ap-invoices/$inv1id", null, $admin)['json']['open_amount'] == 0);
check('a settled invoice cannot be paid again (422)', http('POST', '/ap-payments', ['supplier_id' => $sup, 'amount' => 1, 'allocations' => [['invoice_id' => $inv1id, 'amount' => 1]]], $admin)['status'] === 422);
$py3 = http('POST', '/ap-payments', ['supplier_id' => $sup, 'amount' => 100, 'allocations' => [['invoice_id' => $i3, 'amount' => 100]]], $admin)['json']['id'];
$rp = http('POST', "/ap-payments/$py3/decide", ['action' => 'reject', 'reason_code' => 'missing_document', 'note' => 'No bank slip'], $mgr);
check('a payment can be rejected with a reason; nothing is posted', $rp['json']['status'] === 'Rejected' && $rp['json']['entry_number'] === null && http('GET', "/ap-invoices/$i3", null, $admin)['json']['paid_amount'] == 0);

echo "\n== manual entries and reversal\n";
$a1110 = array_values(array_filter($accts, fn($a) => $a['code'] === '1110'))[0]['id']; $a3100 = array_values(array_filter($accts, fn($a) => $a['code'] === '3100'))[0]['id']; $a1000 = array_values(array_filter($accts, fn($a) => $a['code'] === '1000'))[0]['id'];
$man = ['description' => 'Opening capital', 'lines' => [['account_id' => $a1110, 'debit' => 1000], ['account_id' => $a3100, 'credit' => 1000]]];
check('viewer cannot post a manual entry (403)', http('POST', '/gl-entries', $man, $viewer)['status'] === 403);
check('an unbalanced entry is refused (422)', http('POST', '/gl-entries', ['description' => 'x', 'lines' => [['account_id' => $a1110, 'debit' => 100], ['account_id' => $a3100, 'credit' => 90]]], $mgr)['status'] === 422);
check('a line with both debit and credit is refused (422)', http('POST', '/gl-entries', ['description' => 'x', 'lines' => [['account_id' => $a1110, 'debit' => 50, 'credit' => 50], ['account_id' => $a3100, 'credit' => 0.0]]], $mgr)['status'] === 422);
check('a heading account (not postable) is refused (422)', http('POST', '/gl-entries', ['description' => 'x', 'lines' => [['account_id' => $a1000, 'debit' => 10], ['account_id' => $a3100, 'credit' => 10]]], $mgr)['status'] === 422);
check('a description is required (422)', http('POST', '/gl-entries', ['lines' => $man['lines']], $mgr)['status'] === 422);
$bk = $tb('1110');
$me = http('POST', '/gl-entries', $man, $mgr);
check('a balanced manual entry is posted by the finance approver', $me['status'] === 201 && $me['json']['source_type'] === 'manual' && $me['json']['status'] === 'Posted' && $me['json']['total'] == 1000 && count($me['json']['lines']) === 2);
check('reversal needs a reason (422)', http('POST', "/gl-entries/{$me['json']['id']}/reverse", [], $mgr)['status'] === 422);
$rv = http('POST', "/gl-entries/{$me['json']['id']}/reverse", ['reason' => 'Wrong account'], $mgr);
check('reversed: a new opposite entry, the original shows Reversed', $rv['status'] === 201 && $rv['json']['source_type'] === 'reversal' && http('GET', "/gl-entries/{$me['json']['id']}", null, $admin)['json']['status'] === 'Reversed');
check('the bank balance is back to where it was', round($tb('1110')['debit'] - $tb('1110')['credit'], 2) == round($bk['debit'] - $bk['credit'], 2));
check('an entry cannot be reversed twice (409)', http('POST', "/gl-entries/{$me['json']['id']}/reverse", ['reason' => 'again'], $mgr)['status'] === 409);
check('an entry posted by a document cannot be reversed here (409)', http('POST', "/gl-entries/{$je['id']}/reverse", ['reason' => 'x'], $mgr)['status'] === 409);
check('the old generic accounting endpoints are read-only now (405)', http('POST', '/journal-entries', ['date' => date('Y-m-d')], $admin)['status'] === 405 && http('POST', '/accounts', ['code' => '9'], $admin)['status'] === 405);

echo "\n== period close\n";
http('PUT', '/settings', ['finance.closed_until' => date('Y-m-d')], $admin);
check('nothing can be posted on a closed date (409)', http('POST', '/gl-entries', $man, $mgr)['status'] === 409);
$i7 = http('POST', '/ap-invoices', ['po_id' => $po4, 'supplier_invoice_no' => "F-$u-7", 'lines' => [['item_id' => $tom, 'qty_kg' => 1, 'price' => 5]]], $admin)['json']['id'];
http('POST', "/ap-invoices/$i7/submit", null, $admin);
check('an invoice dated inside the closed period cannot be approved (409)', http('POST', "/ap-invoices/$i7/decide", ['action' => 'approve', 'note' => 'x'], $mgr)['status'] === 409);
$po5 = $mkpo([['item_id' => $tom, 'item_name' => 'Tomato', 'qty' => 10, 'rate' => 5, 'amount' => 50]]);
check('goods are still received during a closed period (posted to today)', http('POST', '/stock/receive-po', ['po_id' => $po5, 'lines' => [['item_id' => $tom, 'qty' => 10, 'cold_room_id' => $room]]], $admin)['status'] === 201);
http('PUT', '/settings', ['finance.closed_until' => ''], $admin);

echo "\n== reports\n";
$t = http('GET', '/finance-reports/trial-balance', null, $admin)['json'];
check('trial balance: total debit = total credit', $t['balanced'] === true && $t['total_debit'] == $t['total_credit'] && $t['total_debit'] > 0, json_encode([$t['total_debit'], $t['total_credit']]));
check('trial balance "to" a date in the past shows less', http('GET', '/finance-reports/trial-balance?to=2000-01-01', null, $admin)['json']['total_debit'] == 0);
$pdo->exec("UPDATE ap_invoices SET due_date = DATE_SUB(CURDATE(), INTERVAL 45 DAY) WHERE id = $i3");
$ag = http('GET', '/finance-reports/aging', null, $admin)['json'];
$mine = array_values(array_filter($ag['suppliers'], fn($s) => $s['supplier_id'] === $sup))[0] ?? null;
check('ageing: the invoice due 45 days ago sits in the 31-60 bucket', $mine && $mine['d31_60'] == 579.6, json_encode($mine));
check('ageing totals add up', abs($ag['totals']['total'] - ($ag['totals']['not_due'] + $ag['totals']['d1_30'] + $ag['totals']['d31_60'] + $ag['totals']['d61_90'] + $ag['totals']['over_90'])) < 0.01);
$st = http('GET', "/finance-reports/statement?supplier_id=$sup", null, $admin)['json'];
$expectBal = round(747.5 + 579.6 + 632.5 + 0 - 747.5, 2);   // invoices 1, 3, 4 (4 = 100 kg * 5.5 + 15 %), payments 400 + 347.5
check('supplier statement: running balance = invoiced - paid', abs($st['balance'] - ($st['rows'] ? end($st['rows'])['balance'] : 0)) < 0.01 && $st['balance'] == $expectBal, json_encode([$st['balance'], $expectBal]));
$vr = http('GET', '/finance-reports/vat-return?from=' . date('Y-m-01') . '&to=' . date('Y-m-t'), null, $admin)['json'];
check('VAT return: input VAT counts the approved purchase invoices of the period', $vr['purchase_invoices'] >= 3 && $vr['input_vat'] > 0 && $vr['net_vat_payable'] == round($vr['output_vat'] - $vr['input_vat'], 2), json_encode($vr));
$sm = http('GET', '/finance-reports/summary', null, $admin)['json'];
check('dashboard summary: payables open, goods not invoiced, invoices on hold', $sm['payables_open'] > 0 && isset($sm['goods_not_invoiced']) && isset($sm['invoices_on_hold']), json_encode($sm));
check('a viewer can read reports but not post (read 200, write 403)', http('GET', '/finance-reports/summary', null, $viewer)['status'] === 200 && http('POST', '/gl-entries', $man, $viewer)['status'] === 403);

echo "\n== company isolation and module switch\n";
$co = http('POST', '/companies', ['name_en' => "Other $u"], $admin)['json']['id'];
$adminId = (int)$pdo->query("SELECT id FROM users WHERE email = 'admin@manzuma.com'")->fetchColumn();
$pdo->exec("INSERT IGNORE INTO user_companies (user_id, company_id) VALUES ($adminId, $co)");
check('a new company gets its own chart of accounts', count(http('GET', '/gl-accounts', null, $admin, $co)['json']) >= 20);
check('company 2 sees no entries, invoices or payments', count(http('GET', '/gl-entries', null, $admin, $co)['json']) === 0 && count(http('GET', '/ap-invoices', null, $admin, $co)['json']) === 0 && count(http('GET', '/ap-payments', null, $admin, $co)['json']) === 0);
check('company 2 cannot read, decide or pay company 1 documents (404)', http('GET', "/ap-invoices/$inv1id", null, $admin, $co)['status'] === 404 && http('POST', "/ap-invoices/$i7/decide", ['action' => 'approve', 'note' => 'x'], $admin, $co)['status'] === 404 && http('POST', "/ap-payments/$py1id/decide", ['action' => 'approve'], $admin, $co)['status'] === 404 && http('GET', "/gl-entries/{$je['id']}", null, $admin, $co)['status'] === 404);
check('company 2 cannot invoice a company 1 order (404)', http('POST', '/ap-invoices', ['po_id' => $po1, 'supplier_invoice_no' => 'X', 'lines' => $lines1], $admin, $co)['status'] === 404);
check('company 2 trial balance is empty', http('GET', '/finance-reports/trial-balance', null, $admin, $co)['json']['total_debit'] == 0);
http('PUT', '/modules/finance', ['is_enabled' => false], $admin);
check('closing the Finance module blocks every finance endpoint (403)', http('GET', '/ap-invoices', null, $admin)['status'] === 403 && http('GET', '/gl-accounts', null, $admin)['status'] === 403 && http('GET', '/finance-reports/summary', null, $admin)['status'] === 403);
http('PUT', '/modules/finance', ['is_enabled' => true], $admin);
check('re-opened: the data is back', http('GET', "/ap-invoices/$inv1id", null, $admin)['status'] === 200);
http('PUT', '/settings', ['receiving.allow_direct_po_receipt' => false], $admin);

echo "\n------------------------------------\n$pass passed, $fail failed\n";
if ($fail) { echo "Failed: " . implode('; ', $fails) . "\n"; exit(1); }
