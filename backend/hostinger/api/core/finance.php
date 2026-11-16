<?php
// ─── Alanad ERP — finance engine (Phase 4, tests/phase5_test.php) ───
//   • double-entry ledger: gl_entries + gl_lines, balanced, append-only (a mistake is corrected by a reversal)
//   • automatic postings:  goods stored      Dr Inventory           Cr Goods received not invoiced   (at the order price)
//                          invoice approved  Dr Goods received not invoiced, Dr/Cr Price variance, Dr VAT input   Cr Trade payables
//                          payment approved  Dr Trade payables      Cr Bank
//   • supplier invoice = 3-way match: order <-> net weight received (stored kg) <-> invoice; a mismatch puts it On_Hold
//   • maker != checker on invoice approval and payment approval; every reject needs a reason
require_once __DIR__ . '/workflow.php';
require_once __DIR__ . '/crud.php';

function fin_need(array $u, string $resource, string $action): void {
    if (!permission_allows($u['permissions'], $resource, $action)) error_response("Forbidden: you do not have permission to $action $resource", 403);
}
function fin_money(mixed $v, string $name, bool $allowZero = false): float {
    if (!is_numeric($v) || (float)$v < 0 || (!$allowZero && (float)$v <= 0)) error_response("$name must be a " . ($allowZero ? 'non-negative' : 'positive') . ' number', 422);
    return round((float)$v, 2);
}
function fin_date(mixed $v, string $name): string {
    if (!is_string($v) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) || !checkdate((int)substr($v, 5, 2), (int)substr($v, 8, 2), (int)substr($v, 0, 4))) error_response("$name must be a date (YYYY-MM-DD)", 422);
    return $v;
}
function fin_acct(int $cid, string $key): ?int {
    $q = db()->prepare('SELECT id FROM gl_accounts WHERE company_id = ? AND system_key = ? AND is_active = 1 LIMIT 1');
    $q->execute([$cid, $key]);
    $id = $q->fetchColumn();
    return $id === false ? null : (int)$id;
}
function fin_acct_or_fail(int $cid, string $key): int {
    $id = fin_acct($cid, $key);
    if (!$id) error_response("The chart of accounts has no active '$key' account", 409);
    return $id;
}
/** period close: nothing is posted on or before the date in finance.closed_until */
function fin_assert_open(int $cid, string $date): void {
    $closed = trim(wf_setting($cid, 'finance.closed_until', ''));
    if ($closed !== '' && $date <= $closed) error_response("The period is closed up to $closed: nothing can be posted on $date", 409);
}
function fin_reason(int $cid, mixed $code, ?string $note): string {
    $code = (string)$code;
    $q = db()->prepare("SELECT 1 FROM reject_reasons WHERE company_id = ? AND code = ? AND gate IN ('finance','general') AND is_active = 1 LIMIT 1");
    $q->execute([$cid, $code]);
    if (!$q->fetchColumn()) error_response('Choose a reject reason from the list', 422);
    if ($code === 'other' && trim((string)$note) === '') error_response('A note is required when the reason is "other"', 422);
    return $code;
}

/**
 * Post one balanced entry. $lines: [[account_id, debit, credit, memo?, supplier_id?], ...]
 * Runs inside the caller's transaction when there is one.
 */
function fin_post(int $cid, string $date, string $desc, string $srcType, ?int $srcId, array $lines, ?int $userId, ?int $reversalOf = null): int {
    $dr = 0.0; $cr = 0.0;
    if (count($lines) < 2) error_response('An entry needs at least two lines', 422);
    $check = db()->prepare('SELECT 1 FROM gl_accounts WHERE id = ? AND company_id = ? AND is_postable = 1 AND is_active = 1');
    foreach ($lines as $l) {
        $d = round((float)$l[1], 2); $c = round((float)$l[2], 2);
        if ($d < 0 || $c < 0 || ($d > 0) === ($c > 0)) error_response('Every line needs either a debit or a credit amount', 422);
        $check->execute([(int)$l[0], $cid]);
        if (!$check->fetchColumn()) error_response('Account ' . (int)$l[0] . ' does not exist, is inactive or cannot be posted to', 422);
        $dr += $d; $cr += $c;
    }
    if (abs($dr - $cr) > 0.004) error_response('The entry is not balanced: debit ' . number_format($dr, 2, '.', '') . ' vs credit ' . number_format($cr, 2, '.', ''), 422);
    $own = !db()->inTransaction();
    if ($own) db()->beginTransaction();
    try {
        $number = crud_next_number('JV', 'gl_entries', $cid);
        db()->prepare('INSERT INTO gl_entries (company_id, number, date, description, source_type, source_id, reversal_of, total, created_by) VALUES (?,?,?,?,?,?,?,?,?)')
            ->execute([$cid, $number, $date, substr($desc, 0, 255), $srcType, $srcId, $reversalOf, round($dr, 2), $userId]);
        $id = (int)db()->lastInsertId();
        $ins = db()->prepare('INSERT INTO gl_lines (entry_id, account_id, debit, credit, memo, supplier_id) VALUES (?,?,?,?,?,?)');
        foreach ($lines as $l) $ins->execute([$id, (int)$l[0], round((float)$l[1], 2), round((float)$l[2], 2), isset($l[3]) ? substr((string)$l[3], 0, 255) : null, $l[4] ?? null]);
        if ($own) db()->commit();
        return $id;
    } catch (Throwable $e) {
        if ($own && db()->inTransaction()) db()->rollBack();
        throw $e;
    }
}

/** weighted price per kg of an item on a purchase order (null when it is not on the order) */
function fin_po_rate(int $poId, int $itemId): ?float {
    $q = db()->prepare('SELECT SUM(qty * rate) / NULLIF(SUM(qty), 0) FROM purchase_order_lines WHERE purchase_order_id = ? AND item_id = ?');
    $q->execute([$poId, $itemId]);
    $r = $q->fetchColumn();
    return $r === false || $r === null ? null : round((float)$r, 4);
}

/** called from inv_receive: stored goods of a purchase order enter Inventory against "goods received not invoiced" at the order price */
function fin_post_receipt(int $cid, int $poId, int $itemId, float $qty, int $batchId, string $date, ?int $userId): void {
    $inv = fin_acct($cid, 'inventory'); $grni = fin_acct($cid, 'grni');
    if (!$inv || !$grni) return;                                   // no chart of accounts yet: nothing to post to
    $rate = (float)fin_po_rate($poId, $itemId);
    $value = round($qty * $rate, 2);
    if ($value <= 0) return;
    $closed = trim(wf_setting($cid, 'finance.closed_until', ''));
    if ($closed !== '' && $date <= $closed) $date = date('Y-m-d');   // goods never wait for the accountants: a closed period moves the posting to today
    fin_post($cid, $date, 'Goods received (batch #' . $batchId . ')', 'receipt', $batchId, [[$inv, $value, 0, 'Inventory'], [$grni, 0, $value, 'Goods received not invoiced']], $userId);
}

// ─── supplier invoices ───────────────────────────────────────

function ap_row(int $cid, int $id): array {
    $st = db()->prepare('SELECT * FROM ap_invoices WHERE id = ? AND company_id = ?');
    $st->execute([$id, $cid]);
    $r = $st->fetch();
    if (!$r) error_response('Not found', 404);
    return $r;
}
function ap_lines(int $id): array {
    $q = db()->prepare('SELECT l.*, i.name AS item_name, i.name_ar AS item_name_ar FROM ap_invoice_lines l JOIN items i ON i.id = l.item_id WHERE l.invoice_id = ? ORDER BY l.id');
    $q->execute([$id]);
    return array_map(function ($r) {
        foreach (['qty_kg', 'price', 'amount', 'po_price', 'received_kg', 'billable_kg'] as $f) if ($r[$f] !== null) $r[$f] = (float)$r[$f];
        return $r;
    }, $q->fetchAll());
}
function ap_detail(int $cid, int $id): array {
    $r = ap_row($cid, $id);
    foreach (['subtotal', 'vat_amount', 'total', 'paid_amount', 'vat_rate_pct'] as $f) $r[$f] = (float)$r[$f];
    $r['open_amount'] = round($r['total'] - $r['paid_amount'], 2);
    $r['lines'] = ap_lines($id);
    $x = db()->prepare('SELECT s.name AS supplier_name, po.number AS po_number FROM suppliers s, purchase_orders po WHERE s.id = ? AND po.id = ?');
    $x->execute([$r['supplier_id'], $r['purchase_order_id']]);
    $r = array_merge($r, $x->fetch() ?: ['supplier_name' => null, 'po_number' => null]);
    $e = db()->prepare('SELECT number FROM gl_entries WHERE id = ?'); $e->execute([$r['entry_id']]);
    $r['entry_number'] = $e->fetchColumn() ?: null;
    $r['overdue_days'] = ($r['status'] === 'Approved' && $r['open_amount'] > 0.004 && $r['due_date'] < date('Y-m-d')) ? (int)((time() - strtotime($r['due_date'])) / 86400) : 0;
    return $r;
}

function ap_clean_lines(int $cid, int $poId, mixed $lines): array {
    if (!is_array($lines) || !$lines) error_response('An invoice needs at least one line', 422);
    $onPo = db()->prepare('SELECT 1 FROM purchase_order_lines WHERE purchase_order_id = ? AND item_id = ? LIMIT 1');
    $out = [];
    foreach ($lines as $ln) {
        if (!is_array($ln)) error_response('Invalid line', 422);
        $item = (int)($ln['item_id'] ?? 0);
        $qty = $ln['qty_kg'] ?? null;
        if (!is_numeric($qty) || (float)$qty <= 0) error_response('Every line needs a quantity in kg greater than zero', 422);
        $price = $ln['price'] ?? null;
        if (!is_numeric($price) || (float)$price <= 0) error_response('Every line needs a price per kg greater than zero', 422);
        $chk = db()->prepare('SELECT 1 FROM items WHERE id = ? AND company_id = ?'); $chk->execute([$item, $cid]);
        if (!$chk->fetchColumn()) error_response("Item $item not found", 422);
        $out[] = [$item, round((float)$qty, 3), round((float)$price, 4), round((float)$qty * (float)$price, 2)];
    }
    return $out;
}
function ap_save_lines(int $id, array $clean): float {
    db()->prepare('DELETE FROM ap_invoice_lines WHERE invoice_id = ?')->execute([$id]);
    $sub = 0.0;
    foreach ($clean as $c) {
        db()->prepare('INSERT INTO ap_invoice_lines (invoice_id, item_id, qty_kg, price, amount) VALUES (?,?,?,?,?)')->execute([$id, $c[0], $c[1], $c[2], $c[3]]);
        $sub += $c[3];
    }
    return round($sub, 2);
}
function ap_vat(int $cid, mixed $given): float {
    $rate = $given !== null && $given !== '' ? $given : wf_setting($cid, 'finance.vat_rate_pct', '15');
    if (!is_numeric($rate) || (float)$rate < 0 || (float)$rate > 100) error_response('vat_rate_pct must be between 0 and 100', 422);
    return round((float)$rate, 2);
}

function ap_create(array $u, array $b): array {
    $cid = (int)$u['company_id'];
    $po = db()->prepare('SELECT id, supplier_id, status FROM purchase_orders WHERE id = ? AND company_id = ?');
    $po->execute([(int)($b['po_id'] ?? 0), $cid]);
    $po = $po->fetch();
    if (!$po) error_response('Purchase order not found', 404);
    if (!in_array($po['status'], ['Approved', 'Received'], true) || !$po['supplier_id']) error_response('Only an approved or received purchase order can be invoiced', 409);
    $no = trim((string)($b['supplier_invoice_no'] ?? ''));
    if ($no === '') error_response("The supplier's invoice number is required", 422);
    $date = fin_date($b['invoice_date'] ?? date('Y-m-d'), 'invoice_date');
    $days = (int)wf_setting($cid, 'finance.payment_terms_days', '30');
    $due = isset($b['due_date']) && $b['due_date'] !== '' ? fin_date($b['due_date'], 'due_date') : date('Y-m-d', strtotime("$date +$days days"));
    if ($due < $date) error_response('The due date cannot be before the invoice date', 422);
    $rate = ap_vat($cid, $b['vat_rate_pct'] ?? null);
    $clean = ap_clean_lines($cid, (int)$po['id'], $b['lines'] ?? []);
    db()->beginTransaction();
    try {
        $number = crud_next_number('APB', 'ap_invoices', $cid);
        db()->prepare('INSERT INTO ap_invoices (company_id, number, supplier_id, supplier_invoice_no, invoice_date, due_date, purchase_order_id, vat_rate_pct, notes, created_by) VALUES (?,?,?,?,?,?,?,?,?,?)')
            ->execute([$cid, $number, $po['supplier_id'], substr($no, 0, 60), $date, $due, $po['id'], $rate, $b['notes'] ?? null, $u['user_id']]);
        $id = (int)db()->lastInsertId();
        $sub = ap_save_lines($id, $clean);
        $vat = round($sub * $rate / 100, 2);
        db()->prepare('UPDATE ap_invoices SET subtotal = ?, vat_amount = ?, total = ? WHERE id = ?')->execute([$sub, $vat, round($sub + $vat, 2), $id]);
        db()->commit();
    } catch (PDOException $e) {
        if (db()->inTransaction()) db()->rollBack();
        if ((int)($e->errorInfo[1] ?? 0) === 1062) error_response('This supplier invoice number is already entered for this supplier', 409);
        throw $e;
    }
    return ap_detail($cid, $id);
}

function ap_update(array $u, int $id, array $b): array {
    $cid = (int)$u['company_id'];
    $inv = ap_row($cid, $id);
    if (!in_array($inv['status'], ['Draft', 'Rejected'], true)) error_response('Only a draft or rejected invoice can be edited', 409);
    if ((int)$inv['created_by'] !== (int)$u['user_id'] && $u['role'] !== 'admin') error_response('Only the person who entered the invoice can edit it', 403);
    $date = isset($b['invoice_date']) ? fin_date($b['invoice_date'], 'invoice_date') : $inv['invoice_date'];
    $due = isset($b['due_date']) && $b['due_date'] !== '' ? fin_date($b['due_date'], 'due_date') : $inv['due_date'];
    if ($due < $date) error_response('The due date cannot be before the invoice date', 422);
    $rate = ap_vat($cid, $b['vat_rate_pct'] ?? $inv['vat_rate_pct']);
    $no = isset($b['supplier_invoice_no']) ? trim((string)$b['supplier_invoice_no']) : $inv['supplier_invoice_no'];
    if ($no === '') error_response("The supplier's invoice number is required", 422);
    db()->beginTransaction();
    try {
        $sub = (float)$inv['subtotal'];
        if (array_key_exists('lines', $b)) $sub = ap_save_lines($id, ap_clean_lines($cid, (int)$inv['purchase_order_id'], $b['lines']));
        $vat = round($sub * $rate / 100, 2);
        db()->prepare("UPDATE ap_invoices SET supplier_invoice_no = ?, invoice_date = ?, due_date = ?, vat_rate_pct = ?, subtotal = ?, vat_amount = ?, total = ?, notes = ?, status = 'Draft', match_status = NULL, match_note = NULL, reject_reason_code = NULL, decision_note = NULL WHERE id = ?")
            ->execute([substr($no, 0, 60), $date, $due, $rate, $sub, $vat, round($sub + $vat, 2), $b['notes'] ?? $inv['notes'], $id]);
        db()->commit();
    } catch (PDOException $e) {
        if (db()->inTransaction()) db()->rollBack();
        if ((int)($e->errorInfo[1] ?? 0) === 1062) error_response('This supplier invoice number is already entered for this supplier', 409);
        throw $e;
    }
    return ap_detail($cid, $id);
}

/** the 3-way match: records the findings on the lines and returns the list of issues */
function ap_match(int $cid, array $inv): array {
    $poId = (int)$inv['purchase_order_id'];
    $tolQ = max(0.0, (float)wf_setting($cid, 'finance.match_qty_tolerance_pct', '2'));
    $tolP = max(0.0, (float)wf_setting($cid, 'finance.price_tolerance_pct', '2'));
    $q = db()->prepare('SELECT item_id, SUM(qty * rate) / NULLIF(SUM(qty), 0) AS rate FROM purchase_order_lines WHERE purchase_order_id = ? AND item_id IS NOT NULL GROUP BY item_id');
    $q->execute([$poId]);
    $rates = []; foreach ($q->fetchAll() as $r) $rates[(int)$r['item_id']] = (float)$r['rate'];
    $received = inv_po_received($cid, $poId);
    $q = db()->prepare("SELECT l.item_id, SUM(l.qty_kg) AS kg FROM ap_invoice_lines l JOIN ap_invoices i ON i.id = l.invoice_id
                        WHERE i.company_id = ? AND i.purchase_order_id = ? AND i.id <> ? AND i.status IN ('Pending','On_Hold','Approved') GROUP BY l.item_id");
    $q->execute([$cid, $poId, $inv['id']]);
    $billed = []; foreach ($q->fetchAll() as $r) $billed[(int)$r['item_id']] = (float)$r['kg'];

    $lines = ap_lines((int)$inv['id']);
    $sumQty = []; foreach ($lines as $l) $sumQty[$l['item_id']] = ($sumQty[$l['item_id']] ?? 0) + $l['qty_kg'];
    $issues = []; $seen = [];
    $upd = db()->prepare('UPDATE ap_invoice_lines SET po_price = ?, received_kg = ?, billable_kg = ?, issue = ? WHERE id = ?');
    foreach ($lines as $l) {
        $iid = $l['item_id']; $msgs = [];
        $rate = $rates[$iid] ?? null;
        $recv = round($received[$iid] ?? 0, 3);
        $bill = round(max(0, $recv - ($billed[$iid] ?? 0)), 3);
        if ($rate === null) $msgs[] = 'The item is not on the purchase order';
        else {
            if ($recv <= 0.0005) $msgs[] = 'Nothing has been received for this item yet';
            elseif (!isset($seen[$iid]) && $sumQty[$iid] > $bill * (1 + $tolQ / 100) + 0.0005) $msgs[] = 'Billed ' . round($sumQty[$iid], 3) . ' kg but only ' . $bill . ' kg received and not yet invoiced (tolerance ' . $tolQ . '%)';
            if ($rate > 0 && abs($l['price'] - $rate) > $rate * $tolP / 100 + 0.00005) $msgs[] = 'Price ' . $l['price'] . ' differs from the order price ' . round($rate, 4) . ' (tolerance ' . $tolP . '%)';
        }
        $seen[$iid] = true;
        $upd->execute([$rate, $recv, $bill, $msgs ? substr(implode('; ', $msgs), 0, 255) : null, $l['id']]);
        foreach ($msgs as $m) $issues[] = $l['item_name'] . ': ' . $m;
    }
    return $issues;
}

function ap_submit(array $u, int $id): array {
    $cid = (int)$u['company_id'];
    $inv = ap_row($cid, $id);
    if ($inv['status'] !== 'Draft') error_response('Only a draft invoice can be submitted', 409);
    if ((int)$inv['created_by'] !== (int)$u['user_id'] && $u['role'] !== 'admin') error_response('Only the person who entered the invoice can submit it', 403);
    if (!ap_lines($id)) error_response('The invoice has no lines', 422);
    $issues = ap_match($cid, $inv);
    db()->prepare('UPDATE ap_invoices SET status = ?, match_status = ?, match_note = ? WHERE id = ?')
        ->execute([$issues ? 'On_Hold' : 'Pending', $issues ? 'mismatch' : 'matched', $issues ? implode("\n", $issues) : null, $id]);
    return ap_detail($cid, $id);
}

function ap_decide(array $u, int $id, array $b): array {
    fin_need($u, 'ap-invoices', 'approve');
    $cid = (int)$u['company_id'];
    $inv = ap_row($cid, $id);
    if (!in_array($inv['status'], ['Pending', 'On_Hold'], true)) error_response('This invoice is not waiting for a decision (it is ' . $inv['status'] . ')', 409);
    if ((int)$inv['created_by'] === (int)$u['user_id'] && wf_setting($cid, 'workflow.maker_not_checker', '1') === '1') {
        error_response('You entered this invoice: another person must decide', 403);
    }
    $action = $b['action'] ?? '';
    $note = trim((string)($b['note'] ?? ''));
    if ($action === 'reject') {
        $code = fin_reason($cid, $b['reason_code'] ?? '', $note);
        db()->prepare("UPDATE ap_invoices SET status = 'Rejected', reject_reason_code = ?, decision_note = ?, decided_by = ?, decided_at = NOW() WHERE id = ?")->execute([$code, $note !== '' ? substr($note, 0, 255) : null, $u['user_id'], $id]);
        return ap_detail($cid, $id);
    }
    if ($action !== 'approve') error_response('action must be approve or reject', 422);
    if ($inv['status'] === 'On_Hold' && $note === '') error_response('The 3-way match found differences: a note is required to approve anyway', 422);
    fin_assert_open($cid, $inv['invoice_date']);
    $apAcct = fin_acct_or_fail($cid, 'ap'); $grni = fin_acct_or_fail($cid, 'grni');
    $lines = ap_lines($id);
    $grniDebit = 0.0;
    foreach ($lines as $l) $grniDebit += round($l['qty_kg'] * ($l['po_price'] ?? $l['price']), 2);
    $grniDebit = round($grniDebit, 2);
    $sub = (float)$inv['subtotal']; $vat = (float)$inv['vat_amount']; $total = (float)$inv['total'];
    $variance = round($sub - $grniDebit, 2);
    $je = [[$grni, $grniDebit, 0, 'Clears goods received not invoiced']];
    if ($variance > 0) $je[] = [fin_acct_or_fail($cid, 'price_variance'), $variance, 0, 'Purchase price variance'];
    if ($variance < 0) $je[] = [fin_acct_or_fail($cid, 'price_variance'), 0, -$variance, 'Purchase price variance'];
    if ($vat > 0) $je[] = [fin_acct_or_fail($cid, 'vat_input'), $vat, 0, 'VAT input ' . $inv['vat_rate_pct'] . '%'];
    $je[] = [$apAcct, 0, $total, 'Supplier invoice ' . $inv['supplier_invoice_no'], (int)$inv['supplier_id']];
    db()->beginTransaction();
    try {
        $entry = fin_post($cid, $inv['invoice_date'], 'Supplier invoice ' . $inv['number'] . ' (' . $inv['supplier_invoice_no'] . ')', 'ap_invoice', $id, $je, (int)$u['user_id']);
        db()->prepare("UPDATE ap_invoices SET status = 'Approved', decision_note = ?, decided_by = ?, decided_at = NOW(), entry_id = ? WHERE id = ?")->execute([$note !== '' ? substr($note, 0, 255) : null, $u['user_id'], $entry, $id]);
        db()->commit();
    } catch (Throwable $e) { if (db()->inTransaction()) db()->rollBack(); throw $e; }
    return ap_detail($cid, $id);
}

// ─── payments ────────────────────────────────────────────────

/** what is still open on an invoice, not counting payments that wait for approval except $exceptPayment's */
function ap_open_amount(array $inv, int $exceptPayment = 0): float {
    $q = db()->prepare("SELECT COALESCE(SUM(a.amount), 0) FROM ap_payment_allocations a JOIN ap_payments p ON p.id = a.payment_id WHERE a.invoice_id = ? AND p.status = 'Pending' AND p.id <> ?");
    $q->execute([$inv['id'], $exceptPayment]);
    return round((float)$inv['total'] - (float)$inv['paid_amount'] - (float)$q->fetchColumn(), 2);
}
function pay_row(int $cid, int $id): array {
    $st = db()->prepare('SELECT * FROM ap_payments WHERE id = ? AND company_id = ?');
    $st->execute([$id, $cid]);
    $r = $st->fetch();
    if (!$r) error_response('Not found', 404);
    return $r;
}
function pay_detail(int $cid, int $id): array {
    $r = pay_row($cid, $id);
    $r['amount'] = (float)$r['amount'];
    $s = db()->prepare('SELECT name FROM suppliers WHERE id = ?'); $s->execute([$r['supplier_id']]);
    $r['supplier_name'] = $s->fetchColumn() ?: null;
    $a = db()->prepare('SELECT a.invoice_id, a.amount, i.number, i.supplier_invoice_no FROM ap_payment_allocations a JOIN ap_invoices i ON i.id = a.invoice_id WHERE a.payment_id = ? ORDER BY a.id');
    $a->execute([$id]);
    $r['allocations'] = array_map(function ($x) { $x['amount'] = (float)$x['amount']; return $x; }, $a->fetchAll());
    $e = db()->prepare('SELECT number FROM gl_entries WHERE id = ?'); $e->execute([$r['entry_id']]);
    $r['entry_number'] = $e->fetchColumn() ?: null;
    return $r;
}

function pay_create(array $u, array $b): array {
    $cid = (int)$u['company_id'];
    $sup = db()->prepare('SELECT id FROM suppliers WHERE id = ? AND company_id = ?');
    $sup->execute([(int)($b['supplier_id'] ?? 0), $cid]);
    if (!$sup->fetchColumn()) error_response('Supplier not found', 404);
    $supplier = (int)$b['supplier_id'];
    $amount = fin_money($b['amount'] ?? null, 'amount');
    $date = fin_date($b['date'] ?? date('Y-m-d'), 'date');
    $method = $b['method'] ?? 'bank_transfer';
    if (!in_array($method, ['bank_transfer', 'cheque', 'cash'], true)) error_response('method must be bank_transfer, cheque or cash', 422);
    $bank = isset($b['bank_account_id']) && $b['bank_account_id'] !== '' ? (int)$b['bank_account_id'] : fin_acct_or_fail($cid, 'bank');
    $ba = db()->prepare("SELECT 1 FROM gl_accounts WHERE id = ? AND company_id = ? AND type = 'asset' AND is_postable = 1 AND is_active = 1");
    $ba->execute([$bank, $cid]);
    if (!$ba->fetchColumn()) error_response('Choose an active bank or cash account', 422);
    $alloc = $b['allocations'] ?? null;
    if (!is_array($alloc) || !$alloc) error_response('Choose the invoice(s) this payment settles', 422);
    $sum = 0.0; $seen = [];
    foreach ($alloc as $a) {
        $iid = (int)($a['invoice_id'] ?? 0);
        if (isset($seen[$iid])) error_response('The same invoice appears twice', 422);
        $seen[$iid] = true;
        $inv = ap_row($cid, $iid);
        if ((int)$inv['supplier_id'] !== $supplier) error_response('Invoice ' . $inv['number'] . ' belongs to another supplier', 422);
        if ($inv['status'] !== 'Approved') error_response('Invoice ' . $inv['number'] . ' is not approved yet', 409);
        $amt = fin_money($a['amount'] ?? null, 'allocation amount');
        if ($amt > ap_open_amount($inv) + 0.004) error_response('Invoice ' . $inv['number'] . ' has only ' . number_format(ap_open_amount($inv), 2, '.', '') . ' open', 422);
        $sum += $amt;
    }
    if (abs($sum - $amount) > 0.004) error_response('The allocations (' . number_format($sum, 2, '.', '') . ') must add up to the payment amount (' . number_format($amount, 2, '.', '') . ')', 422);
    db()->beginTransaction();
    try {
        $number = crud_next_number('PAY', 'ap_payments', $cid);
        db()->prepare('INSERT INTO ap_payments (company_id, number, supplier_id, date, amount, method, reference, bank_account_id, created_by) VALUES (?,?,?,?,?,?,?,?,?)')
            ->execute([$cid, $number, $supplier, $date, $amount, $method, isset($b['reference']) ? substr((string)$b['reference'], 0, 100) : null, $bank, $u['user_id']]);
        $id = (int)db()->lastInsertId();
        foreach ($alloc as $a) db()->prepare('INSERT INTO ap_payment_allocations (payment_id, invoice_id, amount) VALUES (?,?,?)')->execute([$id, (int)$a['invoice_id'], round((float)$a['amount'], 2)]);
        db()->commit();
    } catch (Throwable $e) { if (db()->inTransaction()) db()->rollBack(); throw $e; }
    return pay_detail($cid, $id);
}

function pay_decide(array $u, int $id, array $b): array {
    fin_need($u, 'ap-payments', 'approve');
    $cid = (int)$u['company_id'];
    $p = pay_row($cid, $id);
    if ($p['status'] !== 'Pending') error_response('This payment is not waiting for a decision (it is ' . $p['status'] . ')', 409);
    if ((int)$p['created_by'] === (int)$u['user_id'] && wf_setting($cid, 'workflow.maker_not_checker', '1') === '1') {
        error_response('You created this payment: another person must approve it', 403);
    }
    $action = $b['action'] ?? '';
    $note = trim((string)($b['note'] ?? ''));
    if ($action === 'reject') {
        $code = fin_reason($cid, $b['reason_code'] ?? '', $note);
        db()->prepare("UPDATE ap_payments SET status = 'Rejected', reject_reason_code = ?, decision_note = ?, decided_by = ?, decided_at = NOW() WHERE id = ?")->execute([$code, $note !== '' ? substr($note, 0, 255) : null, $u['user_id'], $id]);
        return pay_detail($cid, $id);
    }
    if ($action !== 'approve') error_response('action must be approve or reject', 422);
    fin_assert_open($cid, $p['date']);
    $apAcct = fin_acct_or_fail($cid, 'ap');
    $alloc = db()->prepare('SELECT invoice_id, amount FROM ap_payment_allocations WHERE payment_id = ?');
    $alloc->execute([$id]);
    $alloc = $alloc->fetchAll();
    foreach ($alloc as $a) {      // re-check: the invoices may have been paid by another payment meanwhile
        $inv = ap_row($cid, (int)$a['invoice_id']);
        if ($inv['status'] !== 'Approved' || (float)$a['amount'] > ap_open_amount($inv, $id) + 0.004) error_response('Invoice ' . $inv['number'] . ' no longer has this much open', 409);
    }
    db()->beginTransaction();
    try {
        $entry = fin_post($cid, $p['date'], 'Payment ' . $p['number'], 'ap_payment', $id,
            [[$apAcct, (float)$p['amount'], 0, 'Settles supplier invoices', (int)$p['supplier_id']], [(int)$p['bank_account_id'], 0, (float)$p['amount'], strtoupper($p['method']) . ($p['reference'] ? ' ' . $p['reference'] : '')]], (int)$u['user_id']);
        foreach ($alloc as $a) db()->prepare('UPDATE ap_invoices SET paid_amount = paid_amount + ? WHERE id = ?')->execute([$a['amount'], $a['invoice_id']]);
        db()->prepare("UPDATE ap_payments SET status = 'Posted', decision_note = ?, decided_by = ?, decided_at = NOW(), entry_id = ? WHERE id = ?")->execute([$note !== '' ? substr($note, 0, 255) : null, $u['user_id'], $entry, $id]);
        db()->commit();
    } catch (Throwable $e) { if (db()->inTransaction()) db()->rollBack(); throw $e; }
    return pay_detail($cid, $id);
}

// ─── manual entries and reversals ────────────────────────────

function gl_entry_detail(int $cid, int $id): array {
    $st = db()->prepare('SELECT * FROM gl_entries WHERE id = ? AND company_id = ?');
    $st->execute([$id, $cid]);
    $e = $st->fetch();
    if (!$e) error_response('Not found', 404);
    $l = db()->prepare('SELECT l.id, l.account_id, a.code AS account_code, a.name_en AS account_name, a.name_ar AS account_name_ar, l.debit, l.credit, l.memo, l.supplier_id FROM gl_lines l JOIN gl_accounts a ON a.id = l.account_id WHERE l.entry_id = ? ORDER BY l.id');
    $l->execute([$id]);
    $e['total'] = (float)$e['total'];
    $e['lines'] = array_map(function ($x) { $x['debit'] = (float)$x['debit']; $x['credit'] = (float)$x['credit']; return $x; }, $l->fetchAll());
    return $e;
}
function gl_manual(array $u, array $b): array {
    fin_need($u, 'gl-entries', 'approve');
    $cid = (int)$u['company_id'];
    $date = fin_date($b['date'] ?? date('Y-m-d'), 'date');
    fin_assert_open($cid, $date);
    $desc = trim((string)($b['description'] ?? ''));
    if ($desc === '') error_response('A description is required', 422);
    $lines = [];
    foreach (($b['lines'] ?? []) as $l) {
        if (!is_array($l)) error_response('Invalid line', 422);
        $lines[] = [(int)($l['account_id'] ?? 0), $l['debit'] ?? 0, $l['credit'] ?? 0, $l['memo'] ?? null];
    }
    return gl_entry_detail($cid, fin_post($cid, $date, $desc, 'manual', null, $lines, (int)$u['user_id']));
}
function gl_reverse(array $u, int $id, array $b): array {
    fin_need($u, 'gl-entries', 'approve');
    $cid = (int)$u['company_id'];
    $e = gl_entry_detail($cid, $id);
    if ($e['source_type'] !== 'manual') error_response('Only manual entries can be reversed here. Documents (receipts, invoices, payments) are corrected through their own screens.', 409);
    if ($e['status'] !== 'Posted') error_response('This entry is already reversed', 409);
    $reason = trim((string)($b['reason'] ?? ''));
    if ($reason === '') error_response('A reason is required', 422);
    $date = fin_date($b['date'] ?? date('Y-m-d'), 'date');
    fin_assert_open($cid, $date);
    $lines = array_map(fn($l) => [$l['account_id'], $l['credit'], $l['debit'], 'Reversal: ' . $reason], $e['lines']);
    db()->beginTransaction();
    try {
        $new = fin_post($cid, $date, 'Reversal of ' . $e['number'] . ': ' . $reason, 'reversal', $id, $lines, (int)$u['user_id'], $id);
        db()->prepare("UPDATE gl_entries SET status = 'Reversed' WHERE id = ?")->execute([$id]);
        db()->commit();
    } catch (Throwable $ex) { if (db()->inTransaction()) db()->rollBack(); throw $ex; }
    return gl_entry_detail($cid, $new);
}

// ─── reports ─────────────────────────────────────────────────

function fin_trial_balance(int $cid, ?string $to): array {
    $w = 'a.company_id = ?'; $p = [$cid];
    $join = 'LEFT JOIN gl_lines l ON l.account_id = a.id LEFT JOIN gl_entries e ON e.id = l.entry_id' . ($to ? ' AND e.date <= ?' : '');
    if ($to) $p = [$to, $cid];
    $q = db()->prepare("SELECT a.id, a.code, a.name_en, a.name_ar, a.type, a.is_postable, COALESCE(SUM(CASE WHEN e.id IS NOT NULL THEN l.debit END), 0) AS debit, COALESCE(SUM(CASE WHEN e.id IS NOT NULL THEN l.credit END), 0) AS credit
                        FROM gl_accounts a $join WHERE $w GROUP BY a.id, a.code, a.name_en, a.name_ar, a.type, a.is_postable ORDER BY a.code");
    $q->execute($p);
    $rows = []; $td = 0.0; $tc = 0.0;
    foreach ($q->fetchAll() as $r) {
        $d = round((float)$r['debit'], 2); $c = round((float)$r['credit'], 2);
        if ($d == 0 && $c == 0 && !(int)$r['is_postable']) continue;
        $bal = in_array($r['type'], ['asset', 'expense'], true) ? round($d - $c, 2) : round($c - $d, 2);
        $rows[] = ['account_id' => (int)$r['id'], 'code' => $r['code'], 'name_en' => $r['name_en'], 'name_ar' => $r['name_ar'], 'type' => $r['type'], 'debit' => $d, 'credit' => $c, 'balance' => $bal];
        $td += $d; $tc += $c;
    }
    return ['to' => $to, 'rows' => $rows, 'total_debit' => round($td, 2), 'total_credit' => round($tc, 2), 'balanced' => abs($td - $tc) < 0.005];
}

function fin_aging(int $cid): array {
    $q = db()->prepare("SELECT i.id, i.supplier_id, s.name AS supplier_name, i.due_date, (i.total - i.paid_amount) AS open_amount, DATEDIFF(CURDATE(), i.due_date) AS days
                        FROM ap_invoices i JOIN suppliers s ON s.id = i.supplier_id WHERE i.company_id = ? AND i.status = 'Approved' AND i.total - i.paid_amount > 0.004 ORDER BY s.name, i.due_date");
    $q->execute([$cid]);
    $by = []; $tot = ['not_due' => 0.0, 'd1_30' => 0.0, 'd31_60' => 0.0, 'd61_90' => 0.0, 'over_90' => 0.0, 'total' => 0.0];
    foreach ($q->fetchAll() as $r) {
        $d = (int)$r['days']; $amt = round((float)$r['open_amount'], 2);
        $k = $d <= 0 ? 'not_due' : ($d <= 30 ? 'd1_30' : ($d <= 60 ? 'd31_60' : ($d <= 90 ? 'd61_90' : 'over_90')));
        $s = (int)$r['supplier_id'];
        $by[$s] = $by[$s] ?? ['supplier_id' => $s, 'supplier_name' => $r['supplier_name'], 'not_due' => 0.0, 'd1_30' => 0.0, 'd31_60' => 0.0, 'd61_90' => 0.0, 'over_90' => 0.0, 'total' => 0.0];
        $by[$s][$k] += $amt; $by[$s]['total'] += $amt; $tot[$k] += $amt; $tot['total'] += $amt;
    }
    return ['suppliers' => array_values(array_map(fn($x) => array_map(fn($v) => is_float($v) ? round($v, 2) : $v, $x), $by)), 'totals' => array_map(fn($v) => round($v, 2), $tot)];
}

function fin_statement(int $cid, int $supplierId): array {
    $s = db()->prepare('SELECT id, name FROM suppliers WHERE id = ? AND company_id = ?');
    $s->execute([$supplierId, $cid]);
    $sup = $s->fetch();
    if (!$sup) error_response('Supplier not found', 404);
    $q = db()->prepare("SELECT invoice_date AS date, 'invoice' AS kind, number, supplier_invoice_no AS reference, total AS credit, 0 AS debit, id FROM ap_invoices WHERE company_id = ? AND supplier_id = ? AND status = 'Approved'
                        UNION ALL SELECT date, 'payment', number, reference, 0, amount, id FROM ap_payments WHERE company_id = ? AND supplier_id = ? AND status = 'Posted' ORDER BY date, kind, id");
    $q->execute([$cid, $supplierId, $cid, $supplierId]);
    $bal = 0.0; $rows = [];
    foreach ($q->fetchAll() as $r) {
        $bal += (float)$r['credit'] - (float)$r['debit'];
        $rows[] = ['date' => $r['date'], 'kind' => $r['kind'], 'number' => $r['number'], 'reference' => $r['reference'], 'invoiced' => (float)$r['credit'], 'paid' => (float)$r['debit'], 'balance' => round($bal, 2)];
    }
    return ['supplier' => $sup, 'rows' => $rows, 'balance' => round($bal, 2)];
}

function fin_vat_return(int $cid, string $from, string $to): array {
    $q = db()->prepare("SELECT COUNT(*) AS n, COALESCE(SUM(subtotal), 0) AS taxable, COALESCE(SUM(vat_amount), 0) AS vat FROM ap_invoices WHERE company_id = ? AND status = 'Approved' AND invoice_date BETWEEN ? AND ?");
    $q->execute([$cid, $from, $to]);
    $in = $q->fetch();
    $out = 0.0;
    if ($acct = fin_acct($cid, 'vat_output')) {
        $o = db()->prepare('SELECT COALESCE(SUM(l.credit - l.debit), 0) FROM gl_lines l JOIN gl_entries e ON e.id = l.entry_id WHERE e.company_id = ? AND l.account_id = ? AND e.date BETWEEN ? AND ?');
        $o->execute([$cid, $acct, $from, $to]);
        $out = round((float)$o->fetchColumn(), 2);
    }
    $inVat = round((float)$in['vat'], 2);
    return ['from' => $from, 'to' => $to, 'purchase_invoices' => (int)$in['n'], 'taxable_purchases' => round((float)$in['taxable'], 2), 'input_vat' => $inVat, 'output_vat' => $out, 'net_vat_payable' => round($out - $inVat, 2)];
}

function fin_summary(int $cid): array {
    $one = function (string $sql, array $p = []) { $q = db()->prepare($sql); $q->execute($p); return $q->fetchColumn(); };
    $month = date('Y-m-01');
    return [
        'payables_open' => round((float)$one("SELECT COALESCE(SUM(total - paid_amount), 0) FROM ap_invoices WHERE company_id = ? AND status = 'Approved'", [$cid]), 2),
        'overdue_amount' => round((float)$one("SELECT COALESCE(SUM(total - paid_amount), 0) FROM ap_invoices WHERE company_id = ? AND status = 'Approved' AND due_date < CURDATE() AND total - paid_amount > 0.004", [$cid]), 2),
        'invoices_pending' => (int)$one("SELECT COUNT(*) FROM ap_invoices WHERE company_id = ? AND status = 'Pending'", [$cid]),
        'invoices_on_hold' => (int)$one("SELECT COUNT(*) FROM ap_invoices WHERE company_id = ? AND status = 'On_Hold'", [$cid]),
        'invoices_draft' => (int)$one("SELECT COUNT(*) FROM ap_invoices WHERE company_id = ? AND status = 'Draft'", [$cid]),
        'payments_pending' => (int)$one("SELECT COUNT(*) FROM ap_payments WHERE company_id = ? AND status = 'Pending'", [$cid]),
        'goods_not_invoiced' => round((float)$one("SELECT COALESCE(SUM(l.credit - l.debit), 0) FROM gl_lines l JOIN gl_entries e ON e.id = l.entry_id JOIN gl_accounts a ON a.id = l.account_id WHERE e.company_id = ? AND a.system_key = 'grni'", [$cid]), 2),
        'vat_input_month' => round((float)$one("SELECT COALESCE(SUM(vat_amount), 0) FROM ap_invoices WHERE company_id = ? AND status = 'Approved' AND invoice_date >= ?", [$cid, $month]), 2),
        'closed_until' => trim(wf_setting($cid, 'finance.closed_until', '')) ?: null,
    ];
}
