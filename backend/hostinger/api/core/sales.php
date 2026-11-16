<?php
// ─── Sales and dispatch: prices, shipments (delivery note + exit permit), the tax invoice, and recalls ───
// Packed pallets / cartons → a shipment (Draft) → loaded (every code scanned) → invoice (VAT, ZATCA QR + hash chain, accounting) → exit permit → dispatched (units Shipped; the client order closes when fully shipped).
require_once __DIR__ . '/inventory.php';
require_once __DIR__ . '/finance.php';
require_once __DIR__ . '/zatca.php';

function sh_need(array $u, string $act): void {
    if (!permission_allows($u['permissions'], 'shipments', $act)) error_response("Forbidden: you do not have permission to $act shipments", 403);
}
function sh_text(mixed $v, int $max = 500): ?string {
    $v = trim((string)($v ?? ''));
    return $v === '' ? null : mb_substr($v, 0, $max);
}
function sh_row(int $cid, int $id): array {
    $q = db()->prepare('SELECT * FROM shipments WHERE id = ? AND company_id = ?');
    $q->execute([$id, $cid]);
    $r = $q->fetch();
    if (!$r || !branch_in_scope($r['branch_id'] !== null ? (int)$r['branch_id'] : null)) error_response('Not found', 404);
    return $r;
}

/** price per kg for an item and grade, for a client: the client's own price first, the specific grade first, the latest date first */
function sl_price(int $cid, int $itemId, ?string $grade, ?int $customerId): ?float {
    $q = db()->prepare('SELECT price_per_kg FROM sales_prices WHERE company_id = ? AND item_id = ? AND is_active = 1 AND valid_from <= CURDATE() AND (grade <=> ? OR grade IS NULL) AND (customer_id <=> ? OR customer_id IS NULL)
                        ORDER BY customer_id IS NULL, grade IS NULL, valid_from DESC, id DESC LIMIT 1');
    $q->execute([$cid, $itemId, $grade, $customerId]);
    $v = $q->fetchColumn();
    return $v === false ? null : (float)$v;
}

function sh_units(int $id): array {
    $q = db()->prepare("SELECT u.id, u.kind, u.code, u.boxes, u.kg, u.status, u.parent_id, o.number AS pack_order, o.item_id, o.grade, i.name AS item_name
                        FROM shipment_units s JOIN pack_units u ON u.id = s.unit_id JOIN pack_orders o ON o.id = u.order_id JOIN items i ON i.id = o.item_id WHERE s.shipment_id = ? ORDER BY u.kind = 'carton', u.id");
    $q->execute([$id]);
    return array_map(function ($x) { $x['kg'] = (float)$x['kg']; return $x; }, $q->fetchAll());
}
/** what is on the shipment per item and grade (a pallet already contains its cartons) */
function sh_lines(int $id): array {
    $q = db()->prepare("SELECT o.item_id, i.name AS item_name, o.grade, SUM(u.kg) AS kg, SUM(u.boxes) AS boxes,
                               SUM(u.kg * CASE WHEN o.issued_kg > 0 THEN COALESCE(o.cost_total, 0) / o.issued_kg ELSE 0 END) AS cost
                        FROM shipment_units s JOIN pack_units u ON u.id = s.unit_id JOIN pack_orders o ON o.id = u.order_id JOIN items i ON i.id = o.item_id WHERE s.shipment_id = ? GROUP BY o.item_id, i.name, o.grade ORDER BY i.name, o.grade");
    $q->execute([$id]);
    return array_map(function ($x) { $x['kg'] = round((float)$x['kg'], 3); $x['boxes'] = (int)$x['boxes']; $x['cost'] = round((float)$x['cost'], 2); return $x; }, $q->fetchAll());
}
function sh_detail(int $cid, int $id): array {
    $s = sh_row($cid, $id);
    $s['units'] = sh_units($id);
    $s['lines'] = sh_lines($id);
    $s['total_kg'] = round(array_sum(array_column($s['lines'], 'kg')), 3);
    $s['total_boxes'] = array_sum(array_column($s['lines'], 'boxes'));
    $s['invoice'] = null;
    if ($s['invoice_id']) { $i = db()->prepare('SELECT id, number, total, subtotal, vat_amount, zatca_status, invoice_type FROM sales_invoices WHERE id = ?'); $i->execute([$s['invoice_id']]); $inv = $i->fetch(); if ($inv) { foreach (['total', 'subtotal', 'vat_amount'] as $f) $inv[$f] = (float)$inv[$f]; } $s['invoice'] = $inv ?: null; }
    return $s;
}

/** a unit whose pack order took stock from a batch under an active quality hold (a recall) cannot ship */
function sh_check_recall(int $cid, array $unitIds): void {
    if (!$unitIds) return;
    $in = implode(',', array_map('intval', $unitIds));
    $q = db()->query("SELECT DISTINCT u.code, b.batch_no FROM pack_units u JOIN pack_order_batches p ON p.order_id = u.order_id JOIN item_batches b ON b.id = p.batch_id
                      JOIN quality_holds h ON h.batch_id = p.batch_id AND h.status = 'active' AND h.company_id = " . (int)$cid . " WHERE u.id IN ($in) LIMIT 1");
    $r = $q->fetch();
    if ($r) error_response('The unit ' . $r['code'] . ' may contain batch ' . $r['batch_no'] . ' which is under a recall / quality hold: it cannot ship', 409);
}

function sh_create(array $u, array $b): array {
    $cid = (int)$u['company_id'];
    $ids = $b['unit_ids'] ?? null;
    if (!is_array($ids) || !$ids) error_response('Choose the pallets or cartons to ship (unit_ids)', 422);
    $ids = array_values(array_unique(array_map('intval', $ids)));
    $in = implode(',', $ids);
    $q = db()->query("SELECT u.*, o.branch_id, o.client_order_id, o.customer_id AS o_customer FROM pack_units u JOIN pack_orders o ON o.id = u.order_id WHERE u.company_id = " . (int)$cid . " AND u.id IN ($in)");
    $units = $q->fetchAll();
    if (count($units) !== count($ids)) error_response('A pallet or carton was not found', 404);
    $branches = array_unique(array_map(fn($x) => $x['branch_id'], $units));
    if (count($branches) > 1) error_response('The units come from different branches: ship them separately', 422);
    $branch = $branches[0] !== null ? (int)$branches[0] : null;
    if (!branch_in_scope($branch)) error_response('Not found', 404);
    $byId = []; foreach ($units as $x) $byId[(int)$x['id']] = $x;
    foreach ($units as $x) {
        if ($x['status'] !== 'Packed') error_response('The unit ' . $x['code'] . ' is already ' . $x['status'], 409);
        if ($x['kind'] === 'carton' && $x['parent_id'] !== null && isset($byId[(int)$x['parent_id']])) error_response('The carton ' . $x['code'] . ' is already on the pallet you chose: pick the pallet or its cartons, not both', 422);
    }
    $busy = db()->query("SELECT u.code FROM shipment_units s JOIN shipments sh ON sh.id = s.shipment_id AND sh.status <> 'Cancelled' JOIN pack_units u ON u.id = s.unit_id WHERE s.unit_id IN ($in) OR s.unit_id IN (SELECT parent_id FROM pack_units WHERE id IN ($in) AND parent_id IS NOT NULL) OR s.unit_id IN (SELECT id FROM pack_units WHERE parent_id IN ($in)) LIMIT 1")->fetchColumn();
    if ($busy) error_response("The unit $busy is already on another shipment", 409);
    sh_check_recall($cid, $ids);
    $coId = null; $custId = null; $custName = sh_text($b['customer_name'] ?? null, 255);
    if (!empty($b['client_order_id'])) {
        $c = db()->prepare('SELECT * FROM client_orders WHERE id = ? AND company_id = ?'); $c->execute([(int)$b['client_order_id'], $cid]);
        $co = $c->fetch();
        if (!$co || !branch_in_scope($co['branch_id'] !== null ? (int)$co['branch_id'] : null)) error_response('Client order not found', 404);
        if (in_array($co['status'], ['Cancelled', 'Closed'], true)) error_response('This client order is ' . $co['status'], 409);
        foreach ($units as $x) if ($x['client_order_id'] !== null && (int)$x['client_order_id'] !== (int)$co['id']) error_response('The unit ' . $x['code'] . ' was packed for another client order', 422);
        $coId = (int)$co['id']; $custId = $co['customer_id'] !== null ? (int)$co['customer_id'] : null; $custName = $custName ?: $co['customer_name'];
    }
    if ($coId === null && !empty($b['customer_id'])) {
        $c = db()->prepare('SELECT id, name FROM customers WHERE id = ? AND company_id = ?'); $c->execute([(int)$b['customer_id'], $cid]);
        $cr = $c->fetch();
        if (!$cr) error_response('Customer not found', 422);
        $custId = (int)$cr['id']; $custName = $custName ?: $cr['name'];
    }
    if (!$custName) error_response('The client is required (a client order, a customer or a name)', 422);
    $vid = null; $plate = sh_text($b['vehicle_plate'] ?? null, 30); $driver = sh_text($b['driver_name'] ?? null, 120); $did = sh_text($b['driver_id_no'] ?? null, 40);
    if (!empty($b['vehicle_id'])) {
        $v = db()->prepare('SELECT * FROM vehicles WHERE id = ? AND company_id = ? AND is_active = 1'); $v->execute([(int)$b['vehicle_id'], $cid]);
        $v = $v->fetch();
        if (!$v) error_response('Vehicle not found', 404);
        $vid = (int)$v['id']; $plate = $v['plate']; $driver = $driver ?: $v['default_driver']; $did = $did ?: $v['default_driver_id_no'];
    }
    db()->beginTransaction();
    try {
        $number = crud_next_number('DN', 'shipments', $cid);
        db()->prepare('INSERT INTO shipments (company_id, branch_id, number, client_order_id, customer_id, customer_name, vehicle_id, vehicle_plate, driver_name, driver_id_no, notes, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$cid, $branch, $number, $coId, $custId, $custName, $vid, $plate, $driver, $did, sh_text($b['notes'] ?? null, 1000), $u['user_id']]);
        $id = (int)db()->lastInsertId();
        $ins = db()->prepare('INSERT INTO shipment_units (shipment_id, unit_id) VALUES (?,?)');
        foreach ($ids as $uid) $ins->execute([$id, $uid]);
        db()->commit();
    } catch (Throwable $e) { if (db()->inTransaction()) db()->rollBack(); throw $e; }
    return sh_detail($cid, $id);
}

/** the dispatcher scans every pallet / carton code onto the truck: the scan must match the shipment exactly */
function sh_load(array $u, int $id, array $b): array {
    sh_need($u, 'load');
    $cid = (int)$u['company_id'];
    $s = sh_row($cid, $id);
    if ($s['status'] !== 'Draft') error_response('Only a draft shipment is loaded (this one is ' . $s['status'] . ')', 409);
    $codes = $b['codes'] ?? null;
    if (!is_array($codes)) error_response('Send the scanned codes', 422);
    $scanned = array_values(array_unique(array_map(fn($c) => strtoupper(trim((string)$c)), $codes)));
    $want = array_map(fn($x) => strtoupper($x['code']), sh_units($id));
    $missing = array_values(array_diff($want, $scanned)); $extra = array_values(array_diff($scanned, $want));
    if (inv_setting($cid, 'dispatch.require_load_check', '1') === '1' && ($missing || $extra)) {
        error_response('The scan does not match the shipment' . ($missing ? ': missing ' . implode(', ', array_slice($missing, 0, 5)) : '') . ($extra ? '; not on this shipment: ' . implode(', ', array_slice($extra, 0, 5)) : ''), 409);
    }
    db()->prepare("UPDATE shipments SET status = 'Loaded', loaded_by = ?, loaded_at = NOW() WHERE id = ?")->execute([$u['user_id'], $id]);
    return sh_detail($cid, $id);
}

/** the tax invoice: VAT, the ZATCA QR and hash chain, and the accounting (receivable, sales, VAT, cost of goods) */
function sh_invoice(array $u, int $id, array $b): array {
    sh_need($u, 'invoice');
    $cid = (int)$u['company_id'];
    $s = sh_row($cid, $id);
    if (!in_array($s['status'], ['Loaded', 'Permit_Issued', 'Dispatched', 'Delivered'], true)) error_response('Load the shipment first (this one is ' . $s['status'] . ')', 409);
    if ($s['invoice_id']) error_response('This shipment is already invoiced', 409);
    $co = db()->prepare('SELECT * FROM companies WHERE id = ?'); $co->execute([$cid]); $co = $co->fetch();
    $vat = trim((string)($co['vat_number'] ?? ''));
    if (!preg_match('/^\d{15}$/', $vat)) error_response('Enter the company VAT number (15 digits) in Admin → Companies before issuing tax invoices', 409);
    $cust = ['name' => $s['customer_name'], 'vat' => '', 'address' => '', 'cr' => ''];
    if ($s['customer_id']) { $c = db()->prepare('SELECT name, tax_id FROM customers WHERE id = ?'); $c->execute([$s['customer_id']]); $cr = $c->fetch(); if ($cr) { $cust['name'] = $cr['name']; $cust['vat'] = trim((string)$cr['tax_id']); } }
    $type = preg_match('/^\d{15}$/', $cust['vat']) ? 'standard' : 'simplified';
    $overrides = is_array($b['prices'] ?? null) ? $b['prices'] : [];
    $lines = []; $subtotal = 0.0; $cogs = 0.0; $missing = [];
    foreach (sh_lines($id) as $l) {
        $key = $l['item_id'] . ':' . $l['grade'];
        $price = isset($overrides[$key]) && is_numeric($overrides[$key]) && (float)$overrides[$key] > 0 ? (float)$overrides[$key] : sl_price($cid, (int)$l['item_id'], $l['grade'], $s['customer_id'] !== null ? (int)$s['customer_id'] : null);
        if ($price === null) { $missing[] = $l['item_name'] . ' (' . $l['grade'] . ') [' . $key . ']'; continue; }
        $amt = round($l['kg'] * $price, 2); $subtotal += $amt; $cogs += $l['cost'];
        $lines[] = ['item_id' => (int)$l['item_id'], 'item_name' => $l['item_name'], 'grade' => $l['grade'], 'kg' => $l['kg'], 'price_per_kg' => $price, 'amount' => $amt];
    }
    if ($missing) error_response('No price for: ' . implode('; ', $missing) . '. Add it under Sales → Prices or send it in prices {"itemId:grade": price}', 422);
    $rate = (float)wf_setting($cid, 'finance.vat_rate_pct', '15');
    $subtotal = round($subtotal, 2); $vatAmt = round($subtotal * $rate / 100, 2); $total = round($subtotal + $vatAmt, 2); $cogs = round($cogs, 2);
    $lock = 'si_' . $cid;
    $got = db()->query('SELECT GET_LOCK(' . db()->quote($lock) . ', 10)')->fetchColumn();
    if (!$got) error_response('The system is numbering invoices: try again', 409);
    db()->beginTransaction();
    try {
        $last = db()->prepare('SELECT icv, invoice_hash FROM sales_invoices WHERE company_id = ? ORDER BY icv DESC LIMIT 1'); $last->execute([$cid]);
        $l = $last->fetch();
        $icv = $l ? (int)$l['icv'] + 1 : 1; $pih = $l ? $l['invoice_hash'] : zatca_initial_pih();
        $number = crud_next_number('SI', 'sales_invoices', $cid);
        $now = date('Y-m-d H:i:s'); $uuid = zatca_uuid();
        $seller = ['name' => $co['legal_name'] ?: $co['name_en'], 'vat' => $vat, 'cr' => (string)$co['cr_number'], 'address' => inv_setting($cid, 'zatca.seller_address', '') ?: (string)$co['address']];
        $inv = ['number' => $number, 'uuid' => $uuid, 'issued_at' => $now, 'invoice_type' => $type, 'subtotal' => $subtotal, 'vat_rate' => $rate, 'vat_amount' => $vatAmt, 'total' => $total, 'icv' => $icv, 'pih' => $pih];
        $xml = zatca_xml($inv, $lines, $seller, $cust);
        $qr = zatca_qr($seller['name'], $vat, gmdate('Y-m-d\TH:i:s\Z', strtotime($now)), $total, $vatAmt);
        $je = null;
        $ar = fin_acct($cid, 'ar_customers'); $sales = fin_acct($cid, 'sales'); $vo = fin_acct($cid, 'vat_output'); $cg = fin_acct($cid, 'cogs'); $invAcc = fin_acct($cid, 'inventory');
        if ($ar && $sales && $vo) {
            $gl = [[$ar, $total, 0, 'Receivable ' . $number], [$sales, 0, $subtotal, 'Sales'], [$vo, 0, $vatAmt, 'VAT output']];
            if ($cogs > 0 && $cg && $invAcc) { $gl[] = [$cg, $cogs, 0, 'Cost of goods sold']; $gl[] = [$invAcc, 0, $cogs, 'Inventory']; }
            $date = date('Y-m-d'); fin_assert_open($cid, $date);
            $je = fin_post($cid, $date, 'Sales invoice ' . $number, 'sales_invoice', null, $gl, (int)$u['user_id']);
        }
        db()->prepare('INSERT INTO sales_invoices (company_id, branch_id, number, shipment_id, customer_id, customer_name, customer_vat, invoice_type, issued_at, subtotal, vat_rate, vat_amount, total, cogs, uuid, icv, pih, invoice_hash, qr_base64, xml, gl_entry_id, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$cid, $s['branch_id'], $number, $id, $s['customer_id'], $cust['name'], $cust['vat'] ?: null, $type, $now, $subtotal, $rate, $vatAmt, $total, $cogs, $uuid, $icv, $pih, zatca_hash($xml), $qr, $xml, $je, $u['user_id']]);
        $invId = (int)db()->lastInsertId();
        $ins = db()->prepare('INSERT INTO sales_invoice_lines (invoice_id, item_id, item_name, grade, kg, price_per_kg, amount) VALUES (?,?,?,?,?,?,?)');
        foreach ($lines as $ln) $ins->execute([$invId, $ln['item_id'], $ln['item_name'], $ln['grade'], $ln['kg'], $ln['price_per_kg'], $ln['amount']]);
        db()->prepare('UPDATE shipments SET invoice_id = ? WHERE id = ?')->execute([$invId, $id]);
        db()->commit();
    } catch (Throwable $e) { if (db()->inTransaction()) db()->rollBack(); db()->query('SELECT RELEASE_LOCK(' . db()->quote($lock) . ')'); throw $e; }
    db()->query('SELECT RELEASE_LOCK(' . db()->quote($lock) . ')');
    return sh_detail($cid, $id);
}

function sh_exit_permit(array $u, int $id): array {
    sh_need($u, 'gate');
    $cid = (int)$u['company_id'];
    $s = sh_row($cid, $id);
    if ($s['status'] !== 'Loaded') error_response('Only a loaded shipment gets an exit permit (this one is ' . $s['status'] . ')', 409);
    if (inv_setting($cid, 'dispatch.require_invoice_before_exit', '1') === '1' && !$s['invoice_id']) error_response('Issue the tax invoice before the exit permit', 409);
    if (!$s['vehicle_plate'] || !$s['driver_name']) error_response('The truck plate and the driver must be on the shipment', 409);
    sh_check_recall($cid, array_column(sh_units($id), 'id'));
    $no = 'EX-' . substr($s['number'], 3);
    db()->prepare("UPDATE shipments SET status = 'Permit_Issued', exit_permit_no = ?, permit_by = ?, permit_at = NOW() WHERE id = ?")->execute([$no, $u['user_id'], $id]);
    return sh_detail($cid, $id);
}

/** the truck leaves: the units become Shipped and the client order closes when everything it asked for has shipped */
function sh_dispatch(array $u, int $id): array {
    sh_need($u, 'gate');
    $cid = (int)$u['company_id'];
    $s = sh_row($cid, $id);
    if ($s['status'] !== 'Permit_Issued') error_response('The truck needs an exit permit first (this shipment is ' . $s['status'] . ')', 409);
    $unitIds = array_column(sh_units($id), 'id');
    sh_check_recall($cid, $unitIds);
    db()->beginTransaction();
    try {
        $in = implode(',', array_map('intval', $unitIds));
        db()->exec("UPDATE pack_units SET status = 'Shipped' WHERE id IN ($in) OR parent_id IN ($in)");
        db()->prepare("UPDATE shipments SET status = 'Dispatched', dispatched_by = ?, dispatched_at = NOW() WHERE id = ?")->execute([$u['user_id'], $id]);
        if ($s['client_order_id']) {
            $shipped = db()->prepare("SELECT o.item_id, SUM(u.kg) AS kg FROM shipments sh JOIN shipment_units su ON su.shipment_id = sh.id JOIN pack_units u ON u.id = su.unit_id JOIN pack_orders o ON o.id = u.order_id
                                      WHERE sh.client_order_id = ? AND sh.status IN ('Dispatched','Delivered') GROUP BY o.item_id");
            $shipped->execute([$s['client_order_id']]);
            $got = []; foreach ($shipped->fetchAll() as $r) $got[(int)$r['item_id']] = (float)$r['kg'];
            $l = db()->prepare('SELECT item_id, qty_kg FROM client_order_lines WHERE order_id = ?'); $l->execute([$s['client_order_id']]);
            $done = true; foreach ($l->fetchAll() as $r) if (($got[(int)$r['item_id']] ?? 0) + 0.0005 < (float)$r['qty_kg']) $done = false;
            if ($done) db()->prepare("UPDATE client_orders SET status = 'Closed' WHERE id = ?")->execute([$s['client_order_id']]);
        }
        db()->commit();
    } catch (Throwable $e) { if (db()->inTransaction()) db()->rollBack(); throw $e; }
    return sh_detail($cid, $id);
}

function sh_deliver(array $u, int $id, array $b): array {
    sh_need($u, 'edit');
    $cid = (int)$u['company_id'];
    $s = sh_row($cid, $id);
    if ($s['status'] !== 'Dispatched') error_response('Only a dispatched shipment can be marked delivered (this one is ' . $s['status'] . ')', 409);
    $who = sh_text($b['received_by'] ?? null, 120);
    if (!$who) error_response('Write who received the goods (name of the client\'s receiver)', 422);
    db()->prepare("UPDATE shipments SET status = 'Delivered', delivered_at = NOW(), received_by = ? WHERE id = ?")->execute([$who, $id]);
    return sh_detail($cid, $id);
}

function sh_cancel(array $u, int $id, array $b): array {
    sh_need($u, 'edit');
    $cid = (int)$u['company_id'];
    $s = sh_row($cid, $id);
    if (!in_array($s['status'], ['Draft', 'Loaded'], true) || $s['invoice_id']) error_response('This shipment can no longer be cancelled (it is ' . $s['status'] . ($s['invoice_id'] ? ', already invoiced' : '') . ')', 409);
    $reason = sh_text($b['reason'] ?? null);
    if (!$reason) error_response('A reason is required', 422);
    db()->prepare("UPDATE shipments SET status = 'Cancelled', cancel_reason = ? WHERE id = ?")->execute([$reason, $id]);
    return sh_detail($cid, $id);
}

// ─── recalls ───
function rc_row(int $cid, int $id): array {
    $q = db()->prepare('SELECT r.*, b.batch_no, b.item_id, i.name AS item_name, b.expiry_date FROM recalls r JOIN item_batches b ON b.id = r.batch_id JOIN items i ON i.id = b.item_id WHERE r.id = ? AND r.company_id = ?');
    $q->execute([$id, $cid]);
    $r = $q->fetch();
    if (!$r) error_response('Not found', 404);
    return $r;
}
/** where a batch went: what is still in stock, the pack orders that used it, and every shipment / client that may have received it */
function rc_detail(int $cid, int $id): array {
    $r = rc_row($cid, $id);
    $s = db()->prepare('SELECT COALESCE(SUM(qty),0) FROM stock_ledger WHERE company_id = ? AND batch_id = ?'); $s->execute([$cid, $r['batch_id']]);
    $r['in_stock_kg'] = round((float)$s->fetchColumn(), 3);
    $o = db()->prepare('SELECT o.id, o.number, o.customer_name, p.kg, o.status FROM pack_order_batches p JOIN pack_orders o ON o.id = p.order_id WHERE p.batch_id = ? AND o.company_id = ? ORDER BY o.id');
    $o->execute([$r['batch_id'], $cid]);
    $r['pack_orders'] = array_map(function ($x) { $x['kg'] = (float)$x['kg']; return $x; }, $o->fetchAll());
    $ids = array_column($r['pack_orders'], 'id');
    $r['unshipped_units'] = []; $r['shipments'] = [];
    if ($ids) {
        $in = implode(',', array_map('intval', $ids));
        $r['unshipped_units'] = db()->query("SELECT code, kind, boxes, kg FROM pack_units WHERE order_id IN ($in) AND status = 'Packed' ORDER BY id")->fetchAll();
        $sh = db()->query("SELECT sh.id, sh.number, sh.customer_name, sh.status, sh.dispatched_at, sh.vehicle_plate, COUNT(u.id) AS units, SUM(u.kg) AS kg FROM shipments sh JOIN shipment_units su ON su.shipment_id = sh.id JOIN pack_units u ON u.id = su.unit_id
                           WHERE u.order_id IN ($in) AND sh.status <> 'Cancelled' AND sh.company_id = " . (int)$cid . ' GROUP BY sh.id, sh.number, sh.customer_name, sh.status, sh.dispatched_at, sh.vehicle_plate ORDER BY sh.id');
        $r['shipments'] = array_map(function ($x) { $x['units'] = (int)$x['units']; $x['kg'] = (float)$x['kg']; return $x; }, $sh->fetchAll());
    }
    $r['customers'] = array_values(array_unique(array_column(array_filter($r['shipments'], fn($x) => in_array($x['status'], ['Dispatched', 'Delivered'], true)), 'customer_name')));
    return $r;
}
function rc_create(array $u, array $b): array {
    if (!permission_allows($u['permissions'], 'recalls', 'create')) error_response('Forbidden: you cannot open recalls', 403);
    $cid = (int)$u['company_id'];
    $q = db()->prepare('SELECT id FROM item_batches WHERE company_id = ? AND (id = ? OR batch_no = ?) LIMIT 1');
    $q->execute([$cid, (int)($b['batch_id'] ?? 0), (string)($b['batch_no'] ?? '')]);
    $batch = $q->fetchColumn();
    if (!$batch) error_response('Batch not found', 404);
    $reason = sh_text($b['reason'] ?? null);
    if (!$reason) error_response('A reason is required', 422);
    $open = db()->prepare("SELECT number FROM recalls WHERE company_id = ? AND batch_id = ? AND status = 'Open'"); $open->execute([$cid, $batch]);
    if ($n = $open->fetchColumn()) error_response("This batch already has an open recall ($n)", 409);
    db()->beginTransaction();
    try {
        $hold = inv_open_hold($cid, null, (int)$batch, 'Recall: ' . mb_substr($reason, 0, 200), (int)$u['user_id'], false);
        $number = crud_next_number('RC', 'recalls', $cid);
        db()->prepare('INSERT INTO recalls (company_id, number, batch_id, reason, hold_id, created_by) VALUES (?,?,?,?,?,?)')->execute([$cid, $number, $batch, $reason, $hold, $u['user_id']]);
        $id = (int)db()->lastInsertId();
        db()->commit();
    } catch (Throwable $e) { if (db()->inTransaction()) db()->rollBack(); throw $e; }
    return rc_detail($cid, $id);
}
function rc_close(array $u, int $id, array $b): array {
    if (!permission_allows($u['permissions'], 'stock', 'approve')) error_response('Closing a recall needs approval rights', 403);
    $cid = (int)$u['company_id'];
    $r = rc_row($cid, $id);
    if ($r['status'] !== 'Open') error_response('This recall is already closed', 409);
    if ((int)$r['created_by'] === (int)$u['user_id'] && wf_setting($cid, 'workflow.maker_not_checker', '1') === '1') error_response('You opened this recall: another person must close it', 403);
    $note = sh_text($b['note'] ?? null);
    if (!$note) error_response('A closing note is required', 422);
    db()->beginTransaction();
    try {
        if ($r['hold_id']) inv_release_hold($u, (int)$r['hold_id'], 'Recall ' . $r['number'] . ' closed: ' . $note);
        db()->prepare("UPDATE recalls SET status = 'Closed', closed_by = ?, closed_at = NOW(), close_note = ? WHERE id = ?")->execute([$u['user_id'], $note, $id]);
        db()->commit();
    } catch (Throwable $e) { if (db()->inTransaction()) db()->rollBack(); throw $e; }
    return rc_detail($cid, $id);
}
