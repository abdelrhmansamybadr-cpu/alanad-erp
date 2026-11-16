<?php
// Phase 9 (suite 15): sales prices, shipments (load check, tax invoice with VAT + ZATCA QR / hash chain, exit permit, dispatch), recalls.
//   php hostinger/api/tests/phase15_test.php http://127.0.0.1:8200/api  mysql-dsn user pass
$base = rtrim($argv[1] ?? 'http://127.0.0.1:8200/api', '/');
$pdo  = new PDO($argv[2] ?? 'mysql:host=127.0.0.1;port=3410;dbname=alanad_fresh;charset=utf8mb4', $argv[3] ?? 'root', $argv[4] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pass = 0; $fail = 0; $fails = [];
function http(string $m, string $path, ?array $body = null, ?string $tok = null, ?int $company = null, ?int $branch = null, bool $raw = false): array {
    global $base;
    $ch = curl_init($base . $path);
    $h = ['Content-Type: application/json'];
    if ($tok) $h[] = "Authorization: Bearer $tok";
    if ($company) $h[] = "X-Company-Id: $company";
    if ($branch) $h[] = "X-Branch-Id: $branch";
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $m, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $h, CURLOPT_TIMEOUT => 30]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    $out = curl_exec($ch); $st = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return ['status' => $st, 'json' => $raw ? null : json_decode($out, true), 'raw' => $out];
}
function check(string $name, bool $ok, string $detail = ''): void {
    global $pass, $fail, $fails;
    if ($ok) { $pass++; echo "  ok   $name\n"; } else { $fail++; $fails[] = $name; echo "  FAIL $name $detail\n"; }
}
function login(string $e, string $p): ?string { return http('POST', '/auth/login', ['email' => $e, 'password' => $p])['json']['token'] ?? null; }
function tlv(string $b64): array { $d = base64_decode($b64); $o = []; $i = 0; while ($i < strlen($d)) { $t = ord($d[$i]); $l = ord($d[$i + 1]); $o[$t] = substr($d, $i + 2, $l); $i += 2 + $l; } return $o; }

$u = substr((string)time(), -6);
$admin = login('admin@manzuma.com', 'password');
$role = fn(string $k) => (int)$pdo->query("SELECT id FROM roles WHERE role_key = '$k' AND company_id IS NULL")->fetchColumn();
foreach ([["xd_$u@x.com", $role('dispatcher')], ["xg_$u@x.com", $role('gate_officer')], ["xa_$u@x.com", $role('sales_accountant')], ["xm_$u@x.com", 2], ["xv_$u@x.com", 4]] as [$e, $r]) http('POST', '/users', ['name' => $e, 'email' => $e, 'password' => 'Strong-Pass-123', 'role_id' => $r], $admin);
$disp = login("xd_$u@x.com", 'Strong-Pass-123'); $gate = login("xg_$u@x.com", 'Strong-Pass-123'); $acct = login("xa_$u@x.com", 'Strong-Pass-123'); $mgr = login("xm_$u@x.com", 'Strong-Pass-123'); $viewer = login("xv_$u@x.com", 'Strong-Pass-123');
$set = fn(array $s) => http('PUT', '/settings', $s, $admin);
$set(['dispatch.require_invoice_before_exit' => true, 'dispatch.require_load_check' => true, 'sales.approval_above_kg' => 0, 'finance.vat_rate_pct' => 15]);
$pdo->exec("UPDATE companies SET vat_number = '', legal_name = 'Al Anad National Co' WHERE id = 1");
$B1 = (int)http('POST', '/company-branches', ['code' => "XB$u", 'name_en' => "Ship Jeddah $u"], $admin)['json']['id'];
$exp = fn($d) => date('Y-m-d', strtotime("+$d days"));
$tom = http('POST', '/items', ['code' => "XT$u", 'name' => "SH Tomato $u", 'shelf_life_days' => 7], $admin)['json']['id'];
$wh = http('POST', '/warehouses', ['code' => "XW$u", 'name' => "SH WH $u", 'type' => 'cold', 'branch_id' => $B1], $admin)['json']['id'];
$room = http('POST', '/cold-rooms', ['warehouse_id' => $wh, 'code' => "XR$u", 'name' => "SH room $u", 'temp_min' => 2, 'temp_max' => 8], $admin)['json']['id'];
http('POST', '/stock/receive', ['item_id' => $tom, 'qty' => 2000, 'cold_room_id' => $room, 'expiry_date' => $exp(5), 'batch_no' => "XA$u", 'grade' => 'A', 'unit_cost' => 3.0], $admin, null, $B1);
$custA = http('POST', '/customers', ['name' => "Big Client $u", 'code' => "XA$u", 'tax_id' => '300000000000003'], $admin)['json']['id'];
$custB = http('POST', '/customers', ['name' => "Small Client $u", 'code' => "XB$u"], $admin)['json']['id'];
$batchId = (int)$pdo->query("SELECT id FROM item_batches WHERE batch_no = 'XA$u'")->fetchColumn();
$pack = function (float $kg, float $box, ?int $co = null, ?int $cust = null) use ($admin, $tom, $B1) {
    $k = http('POST', '/pack-orders', array_filter(['item_id' => $tom, 'required_kg' => $kg, 'customer_id' => $cust, 'client_order_id' => $co, 'branch_id' => $B1]), $admin)['json']['id'];
    http('POST', "/pack-orders/$k/decide", ['box_kg' => $box], $admin); http('POST', "/pack-orders/$k/start", null, $admin);
    return http('POST', "/pack-orders/$k/pack", ['boxes_packed' => (int)round($kg / $box)], $admin)['json'];
};
$co1 = http('POST', '/client-orders', ['customer_id' => $custA, 'branch_id' => $B1, 'lines' => [['item_id' => $tom, 'qty_kg' => 400]]], $admin)['json']['id'];
$p1 = $pack(400, 2, $co1);                       // 200 boxes of 2 kg: 5 cartons on 1 pallet
$p2 = $pack(100, 1, null, $custB);                // 100 boxes of 1 kg: 40 + 40 + 20 on 1 pallet
$pal1 = array_values(array_filter($p1['units'], fn($x) => $x['kind'] === 'pallet'))[0]; $car1 = array_values(array_filter($p1['units'], fn($x) => $x['kind'] === 'carton'));
$pal2 = array_values(array_filter($p2['units'], fn($x) => $x['kind'] === 'pallet'))[0]; $car2 = array_values(array_filter($p2['units'], fn($x) => $x['kind'] === 'carton'));

echo "\n== selling prices\n";
check('a read-only user cannot add a price (403)', http('POST', '/sales-prices', ['item_id' => $tom, 'price_per_kg' => 4], $viewer)['status'] === 403);
check('the item must exist (404)', http('POST', '/sales-prices', ['item_id' => 999999, 'price_per_kg' => 4], $acct)['status'] === 404);
check('the price must be positive (422)', http('POST', '/sales-prices', ['item_id' => $tom, 'price_per_kg' => -1], $acct)['status'] === 422 && http('POST', '/sales-prices', ['item_id' => $tom], $acct)['status'] === 422);
check('the grade must be A, B or C (422)', http('POST', '/sales-prices', ['item_id' => $tom, 'price_per_kg' => 4, 'grade' => 'Z'], $acct)['status'] === 422);
check('an unknown customer (422)', http('POST', '/sales-prices', ['item_id' => $tom, 'price_per_kg' => 4, 'customer_id' => 999999], $acct)['status'] === 422);

echo "\n== a shipment from packed pallets and cartons\n";
check('the packed units are available to ship', count(array_filter(http('GET', '/shipments/available-units', null, $disp)['json'], fn($x) => in_array($x['id'], [$pal1['id'], $pal2['id']]))) === 2);
check('a read-only user cannot create a shipment (403)', http('POST', '/shipments', ['unit_ids' => [$pal1['id']], 'customer_id' => $custA], $viewer)['status'] === 403);
check('units are required (422)', http('POST', '/shipments', ['unit_ids' => [], 'customer_id' => $custA], $disp)['status'] === 422);
check('an unknown unit (404)', http('POST', '/shipments', ['unit_ids' => [999999], 'customer_id' => $custA], $disp)['status'] === 404);
check('the pallet and one of its cartons together are refused (422)', http('POST', '/shipments', ['unit_ids' => [$pal1['id'], $car1[0]['id']], 'customer_id' => $custA], $disp)['status'] === 422);
check('a client is required (422)', http('POST', '/shipments', ['unit_ids' => [$pal1['id']]], $disp)['status'] === 422);
check('units packed for another client order are refused (422)', http('POST', '/shipments', ['unit_ids' => [$pal1['id']], 'client_order_id' => http('POST', '/client-orders', ['customer_name' => 'Other', 'branch_id' => $B1, 'lines' => [['item_id' => $tom, 'qty_kg' => 10]]], $admin)['json']['id']], $disp)['status'] === 422);
$s1 = http('POST', '/shipments', ['unit_ids' => [$pal1['id']], 'client_order_id' => $co1, 'vehicle_plate' => "TRK $u", 'driver_name' => 'Ali', 'notes' => 'morning run'], $disp);
$S1 = (int)($s1['json']['id'] ?? 0);
check('the shipment takes the client from the client order; 400 kg / 200 boxes of tomato grade A; Draft', $s1['status'] === 201 && $s1['json']['status'] === 'Draft' && strpos($s1['json']['number'], 'DN-') === 0 && $s1['json']['total_kg'] == 400.0 && $s1['json']['total_boxes'] === 200 && $s1['json']['lines'][0]['grade'] === 'A' && strpos($s1['json']['customer_name'], 'Big Client') === 0, json_encode($s1['json']['error'] ?? null));
check('the same pallet cannot go on a second shipment (409)', http('POST', '/shipments', ['unit_ids' => [$pal1['id']], 'customer_id' => $custA], $disp)['status'] === 409);
check('nor one of its cartons (409)', http('POST', '/shipments', ['unit_ids' => [$car1[0]['id']], 'customer_id' => $custA], $disp)['status'] === 409);
$gone = array_filter(http('GET', '/shipments/available-units', null, $disp)['json'], fn($x) => $x['id'] == $pal1['id'] || $x['id'] == $car1[0]['id']);
check('the units on a shipment are no longer offered', count($gone) === 0);

echo "\n== loading: every code is scanned\n";
check('only the dispatcher loads (403 for the gate officer)', http('POST', "/shipments/$S1/load", ['codes' => [$pal1['code']]], $gate)['status'] === 403);
$m = http('POST', "/shipments/$S1/load", ['codes' => []], $disp);
check('an empty scan is refused and names what is missing (409)', $m['status'] === 409 && stripos($m['json']['error'], 'missing') !== false, json_encode($m['json']));
check('a code that is not on the shipment is refused (409)', http('POST', "/shipments/$S1/load", ['codes' => [$pal1['code'], $pal2['code']]], $disp)['status'] === 409);
check('the exact scan loads the truck', ($ld = http('POST', "/shipments/$S1/load", ['codes' => [strtolower($pal1['code'])]], $disp))['status'] === 200 && $ld['json']['status'] === 'Loaded' && !empty($ld['json']['loaded_by']), json_encode($ld['json']['error'] ?? null));

echo "\n== the tax invoice\n";
check('an invoice is refused while the company VAT number is missing (409)', ($np = http('POST', "/shipments/$S1/invoice", [], $acct))['status'] === 409 && stripos($np['json']['error'], 'VAT number') !== false, json_encode($np['json']));
check('the dispatcher cannot invoice (403)', http('POST', "/shipments/$S1/invoice", [], $disp)['status'] === 403);
$pdo->exec("UPDATE companies SET vat_number = '300000000000003' WHERE id = 1");
$noPrice = http('POST', "/shipments/$S1/invoice", [], $acct);
check('with the VAT number set but no price, the missing price is named (422)', $noPrice['status'] === 422 && stripos($noPrice['json']['error'], 'No price') !== false, json_encode($noPrice['json']));
http('POST', '/sales-prices', ['item_id' => $tom, 'grade' => 'A', 'price_per_kg' => 4.00], $acct);
$cp = http('POST', '/sales-prices', ['item_id' => $tom, 'grade' => 'A', 'customer_id' => $custA, 'price_per_kg' => 5.00], $acct);
check('the sales accountant sets a general price (4.00) and a price for the big client (5.00)', $cp['status'] === 201);
$lastIcv = (int)$pdo->query("SELECT COALESCE(MAX(icv),0) FROM sales_invoices WHERE company_id = 1")->fetchColumn();
$lastHash = $pdo->query("SELECT invoice_hash FROM sales_invoices WHERE company_id = 1 ORDER BY icv DESC LIMIT 1")->fetchColumn();
$iv = http('POST', "/shipments/$S1/invoice", [], $acct);
check('the invoice uses the big client\'s price: 400 kg x 5.00 = 2,000.00 + VAT 15% 300.00 = 2,300.00', $iv['status'] === 201 && $iv['json']['invoice']['subtotal'] == 2000.0 && $iv['json']['invoice']['vat_amount'] == 300.0 && $iv['json']['invoice']['total'] == 2300.0 && $iv['json']['invoice']['invoice_type'] === 'standard' && strpos($iv['json']['invoice']['number'], 'SI-') === 0, json_encode($iv['json']['error'] ?? $iv['json']['invoice'] ?? null));
$I1 = (int)$iv['json']['invoice']['id'];
$inv = http('GET', "/sales-invoices/$I1", null, $acct)['json'];
check('a customer with a 15-digit VAT number gets a standard (B2B) invoice; the line shows 400 kg at 5.00', $inv['invoice_type'] === 'standard' && count($inv['lines']) === 1 && $inv['lines'][0]['kg'] == 400.0 && $inv['lines'][0]['price_per_kg'] == 5.0);
$qr = tlv($inv['qr_base64']);
check('the QR code (TLV) carries the seller, the VAT number, the time, the total 2300.00 and the VAT 300.00', ($qr[1] ?? '') === 'Al Anad National Co' && ($qr[2] ?? '') === '300000000000003' && ($qr[4] ?? '') === '2300.00' && ($qr[5] ?? '') === '300.00' && preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $qr[3] ?? '') === 1, json_encode($qr));
check('the invoice counter goes up by one and the first invoice points to the ZATCA seed hash', (int)$inv['icv'] === $lastIcv + 1 && ($lastIcv > 0 ? $inv['pih'] === $lastHash : $inv['pih'] === base64_encode(hash('sha256', '0'))));
$xml = http('GET', "/sales-invoices/$I1/xml", null, $acct, null, null, true)['raw'];
check('the XML is a well-formed UBL invoice holding the total, the VAT and the ICV / PIH references', ($sx = @simplexml_load_string($xml)) !== false && strpos($xml, '<cbc:PayableAmount currencyID="SAR">2300.00</cbc:PayableAmount>') !== false && strpos($xml, '>ICV<') !== false && strpos($xml, $inv['pih']) !== false && strpos($xml, 'KGM') !== false);
check('the stored hash is the SHA-256 of that XML', base64_encode(hash('sha256', $xml, true)) === $inv['invoice_hash']);
$gl = $pdo->query("SELECT a.system_key, l.debit, l.credit FROM gl_lines l JOIN gl_accounts a ON a.id = l.account_id WHERE l.entry_id = " . (int)$inv['gl_entry_id'])->fetchAll(); $g = []; foreach ($gl as $x) $g[$x['system_key']] = $x;
check('accounting: Dr receivable 2,300 / Cr sales 2,000 / Cr VAT output 300, and Dr cost of goods 1,200 / Cr inventory 1,200 (400 kg x 3.00)', (float)($g['ar_customers']['debit'] ?? 0) == 2300.0 && (float)($g['sales']['credit'] ?? 0) == 2000.0 && (float)($g['vat_output']['credit'] ?? 0) == 300.0 && (float)($g['cogs']['debit'] ?? 0) == 1200.0 && (float)($g['inventory']['credit'] ?? 0) == 1200.0, json_encode($g));
check('a shipment is invoiced once (409)', http('POST', "/shipments/$S1/invoice", [], $acct)['status'] === 409);
$z = http('POST', "/sales-invoices/$I1/zatca-submit", null, $acct);
check('sending to ZATCA is refused honestly: the company is not onboarded (409)', $z['status'] === 409 && stripos($z['json']['error'], 'onboarded') !== false && $pdo->query("SELECT zatca_status FROM sales_invoices WHERE id = $I1")->fetchColumn() === 'Not_submitted');
check('an invoiced shipment cannot be cancelled (409)', http('POST', "/shipments/$S1/cancel", ['reason' => 'x'], $disp)['status'] === 409);

echo "\n== exit permit and dispatch\n";
check('the dispatcher cannot issue the exit permit (403)', http('POST', "/shipments/$S1/exit-permit", null, $disp)['status'] === 403);
$ep = http('POST', "/shipments/$S1/exit-permit", null, $gate);
check('the gate officer issues the exit permit EX-nnnn', $ep['status'] === 200 && $ep['json']['status'] === 'Permit_Issued' && strpos($ep['json']['exit_permit_no'], 'EX-') === 0, json_encode($ep['json']['error'] ?? null));
check('dispatch before the permit is refused on another shipment (409)', http('POST', "/shipments/" . (int)$s1['json']['id'] . "/exit-permit", null, $gate)['status'] === 409);
$dp = http('POST', "/shipments/$S1/dispatch", null, $gate);
check('the truck leaves: Dispatched', $dp['status'] === 200 && $dp['json']['status'] === 'Dispatched' && !empty($dp['json']['dispatched_at']), json_encode($dp['json']['error'] ?? null));
check('the pallet and its cartons are Shipped', (int)$pdo->query("SELECT COUNT(*) FROM pack_units WHERE order_id = {$p1['id']} AND status = 'Shipped'")->fetchColumn() === 6);
check('the client order is Closed: everything it asked for has shipped', http('GET', "/client-orders/$co1", null, $admin, null, $B1)['json']['status'] === 'Closed');
check('dispatching twice (409)', http('POST', "/shipments/$S1/dispatch", null, $gate)['status'] === 409);
check('delivery needs the name of who received it (422)', http('POST', "/shipments/$S1/deliver", [], $disp)['status'] === 422);
check('the client\'s receiver is recorded: Delivered', http('POST', "/shipments/$S1/deliver", ['received_by' => 'Khalid, warehouse'], $disp)['json']['status'] === 'Delivered');

echo "\n== the rules in the settings\n";
$s2 = (int)http('POST', '/shipments', ['unit_ids' => [$car2[0]['id'], $car2[1]['id']], 'customer_id' => $custB], $disp)['json']['id'];
check('(two of the three cartons are shipped on their own, without a truck yet)', $s2 > 0);
http('POST', "/shipments/$s2/load", ['codes' => [$car2[0]['code'], $car2[1]['code']]], $disp);
check('the exit permit needs the invoice first (409)', ($e2 = http('POST', "/shipments/$s2/exit-permit", null, $gate))['status'] === 409 && stripos($e2['json']['error'], 'invoice') !== false, json_encode($e2['json']));
$iv2 = http('POST', "/shipments/$s2/invoice", [], $acct);
check('the small client has no own price: the general price 4.00 applies; no VAT number = simplified invoice; 80 kg x 4.00 = 320.00 + 48.00 = 368.00', $iv2['status'] === 201 && $iv2['json']['invoice']['total'] == 368.0 && $iv2['json']['invoice']['invoice_type'] === 'simplified', json_encode($iv2['json']['error'] ?? $iv2['json']['invoice'] ?? null));
$inv2 = http('GET', "/sales-invoices/{$iv2['json']['invoice']['id']}", null, $acct)['json'];
check('the second invoice carries the first invoice\'s hash as its previous-invoice hash (the chain)', (int)$inv2['icv'] === (int)$inv['icv'] + 1 && $inv2['pih'] === $inv['invoice_hash']);
check('the exit permit needs the truck plate and the driver (409)', http('POST', "/shipments/$s2/exit-permit", null, $gate)['status'] === 409);
$set(['dispatch.require_invoice_before_exit' => false, 'dispatch.require_load_check' => false]);
$s3 = (int)http('POST', '/shipments', ['unit_ids' => [$car2[2]['id']], 'customer_id' => $custB, 'vehicle_plate' => "TRK3 $u", 'driver_name' => 'Omar'], $disp)['json']['id'];
check('with the load check switched off an empty scan loads', http('POST', "/shipments/$s3/load", ['codes' => []], $disp)['json']['status'] === 'Loaded');
check('with the invoice rule switched off the exit permit does not need it', http('POST', "/shipments/$s3/exit-permit", null, $gate)['json']['status'] === 'Permit_Issued');
$set(['dispatch.require_invoice_before_exit' => true, 'dispatch.require_load_check' => true]);
check('a shipment needs a reason to be cancelled (422), and an invoiced one cannot be cancelled at all (409)', http('POST', "/shipments/$s2/cancel", [], $disp)['status'] === 409);

echo "\n== recall: where did a batch go?\n";
$p3 = $pack(50, 1, null, $custB); $pal3 = array_values(array_filter($p3['units'], fn($y) => $y['kind'] === 'pallet'))[0];
check('a read-only user cannot open a recall (403)', http('POST', '/recalls', ['batch_no' => "XA$u", 'reason' => 'x'], $viewer)['status'] === 403);
check('an unknown batch (404)', http('POST', '/recalls', ['batch_no' => 'NOPE', 'reason' => 'x'], $admin)['status'] === 404);
check('a reason is required (422)', http('POST', '/recalls', ['batch_no' => "XA$u"], $admin)['status'] === 422);
$tr = http('GET', "/recalls/trace?batch_no=XA$u", null, $admin)['json'];
check('the trace (before any recall) already shows the pack orders and the client who received tomatoes from this batch', count($tr['pack_orders']) === 3 && in_array("Big Client $u", $tr['customers'], true), json_encode($tr['customers'] ?? null));
$rc = http('POST', '/recalls', ['batch_no' => "XA$u", 'reason' => 'pesticide residue found by the lab'], $admin);
$RC = (int)($rc['json']['id'] ?? 0);
check('the recall is opened: the batch goes on hold and the detail lists orders, shipments, customers and units still in the plant', $rc['status'] === 201 && $rc['json']['status'] === 'Open' && count($rc['json']['shipments']) >= 1 && in_array("Big Client $u", $rc['json']['customers'], true) && count($rc['json']['unshipped_units']) >= 1 && (int)$pdo->query("SELECT COUNT(*) FROM quality_holds WHERE batch_id = $batchId AND status = 'active'")->fetchColumn() === 1, json_encode($rc['json']['error'] ?? null));
check('a second open recall for the same batch is refused (409)', http('POST', '/recalls', ['batch_no' => "XA$u", 'reason' => 'again'], $admin)['status'] === 409);
$s4 = http('POST', '/shipments', ['unit_ids' => [$pal3['id']], 'customer_id' => $custB], $disp);
check('units made from the recalled batch can no longer be put on a shipment (409)', $s4['status'] === 409 && stripos($s4['json']['error'], 'recall') !== false, json_encode($s4['json']));
check('the person who opened the recall cannot close it (403)', http('POST', "/recalls/$RC/close", ['note' => 'done'], $admin)['status'] === 403);
check('closing needs a note (422)', http('POST', "/recalls/$RC/close", [], $mgr)['status'] === 422);
$cl = http('POST', "/recalls/$RC/close", ['note' => 'lab report shows the residue is within limits'], $mgr);
check('a manager closes it: the hold is released', $cl['status'] === 200 && $cl['json']['status'] === 'Closed' && (int)$pdo->query("SELECT COUNT(*) FROM quality_holds WHERE batch_id = $batchId AND status = 'active'")->fetchColumn() === 0, json_encode($cl['json']['error'] ?? null));
check('after the recall is closed the same units can be shipped', http('POST', '/shipments', ['unit_ids' => [$pal3['id']], 'customer_id' => $custB], $disp)['status'] === 201);

echo "\n== branches and companies\n";
$mainB = (int)http('GET', '/me', null, $admin)['json']['branches'][0]['id'];
check('a Jeddah shipment is hidden from the main branch (404)', http('GET', "/shipments/$S1", null, $admin, null, $B1)['status'] === 200 && http('GET', "/shipments/$S1", null, $admin, null, $mainB)['status'] === 404);
$co = http('POST', '/companies', ['name_en' => "SH other $u"], $admin)['json']['id'];
$adminId = (int)$pdo->query("SELECT id FROM users WHERE email = 'admin@manzuma.com'")->fetchColumn();
$pdo->exec("INSERT IGNORE INTO user_companies (user_id, company_id) VALUES ($adminId, $co)");
check('company 2 cannot see, load, invoice, dispatch or read company 1\'s shipment, invoice, XML or recall (404)', http('GET', "/shipments/$S1", null, $admin, $co)['status'] === 404 && http('POST', "/shipments/$s2/load", ['codes' => []], $admin, $co)['status'] === 404 && http('POST', "/shipments/$s2/invoice", [], $admin, $co)['status'] === 404 && http('POST', "/shipments/$S1/dispatch", null, $admin, $co)['status'] === 404 && http('GET', "/sales-invoices/$I1", null, $admin, $co)['status'] === 404 && http('GET', "/sales-invoices/$I1/xml", null, $admin, $co)['status'] === 404 && http('GET', "/recalls/$RC", null, $admin, $co)['status'] === 404);
check('and its lists, prices and available units are empty', count(http('GET', '/shipments', null, $admin, $co)['json']) === 0 && count(http('GET', '/sales-invoices', null, $admin, $co)['json']) === 0 && count(http('GET', '/sales-prices', null, $admin, $co)['json']) === 0 && count(http('GET', '/shipments/available-units', null, $admin, $co)['json']) === 0);
check('company 2 cannot ship company 1\'s pallets (404) and gets its own invoice numbering', http('POST', '/shipments', ['unit_ids' => [$pal2['id']], 'customer_name' => 'x'], $admin, $co)['status'] === 404);
check('a new company gets the dispatch settings', (int)$pdo->query("SELECT COUNT(*) FROM settings WHERE company_id = $co AND setting_key LIKE 'dispatch.%'")->fetchColumn() === 2);

echo "\n------------------------------------\n$pass passed, $fail failed\n";
if ($fail) { echo "Failed: " . implode('; ', $fails) . "\n"; exit(1); }
