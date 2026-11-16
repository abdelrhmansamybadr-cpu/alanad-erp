<?php
// ─── Market buying: purchase offer → field quality inspection → Finance approval (creates the purchase order) → cash custody → settlement ───
// Separation of duties (setting workflow.maker_not_checker): the inspector, the approver, the cash issuer and the settlement approver are never the buyer.
require_once __DIR__ . '/finance.php';
require_once __DIR__ . '/crud.php';

function mk_need(array $u, string $act): void {
    if (!permission_allows($u['permissions'], 'market-purchases', $act)) error_response("Forbidden: you do not have permission to $act market purchases", 403);
}
function mk_sod(int $cid, array $p, array $u, string $what): void {
    if ((int)$p['buyer_id'] === (int)$u['user_id'] && wf_setting($cid, 'workflow.maker_not_checker', '1') === '1') error_response("You are the buyer: another person must $what", 403);
}
function mk_text(mixed $v, int $max = 500): ?string {
    $v = trim((string)($v ?? ''));
    return $v === '' ? null : mb_substr($v, 0, $max);
}
function mk_money(mixed $v, string $name, bool $zero = false): float {
    if (!is_numeric($v) || (float)$v < 0 || (!$zero && (float)$v <= 0)) error_response("$name must be a " . ($zero ? 'non-negative' : 'positive') . ' number', 422);
    return round((float)$v, 2);
}

function mk_row(int $cid, int $id): array {
    $q = db()->prepare('SELECT * FROM market_purchases WHERE id = ? AND company_id = ?');
    $q->execute([$id, $cid]);
    $p = $q->fetch();
    if (!$p || !branch_in_scope($p['branch_id'] !== null ? (int)$p['branch_id'] : null)) error_response('Not found', 404);
    return $p;
}

function mk_lines(int $id): array {
    $q = db()->prepare('SELECT l.id, l.item_id, i.name AS item_name, i.name_ar AS item_name_ar, l.qty_kg, l.offer_price, l.inspect_result, l.inspect_grade, l.inspect_note, l.inspected_by, l.inspected_at
                        FROM market_purchase_lines l JOIN items i ON i.id = l.item_id WHERE l.purchase_id = ? ORDER BY l.id');
    $q->execute([$id]);
    return array_map(function ($r) { $r['qty_kg'] = (float)$r['qty_kg']; $r['offer_price'] = (float)$r['offer_price']; $r['amount'] = round($r['qty_kg'] * $r['offer_price'], 2); return $r; }, $q->fetchAll());
}

function mk_detail(int $cid, int $id): array {
    $p = mk_row($cid, $id);
    $p['lines'] = mk_lines($id);
    $p['offer_total'] = round(array_sum(array_column($p['lines'], 'amount')), 2);
    $p['pass_total'] = round(array_sum(array_map(fn($l) => $l['inspect_result'] === 'Fail' ? 0 : $l['amount'], $p['lines'])), 2);
    $p['is_overdue'] = $p['status'] === 'Cash_Issued' && $p['due_date'] !== null && $p['due_date'] < date('Y-m-d');
    foreach (['total_amount', 'issued_amount', 'spent_amount', 'returned_amount'] as $f) if ($p[$f] !== null) $p[$f] = (float)$p[$f];
    return $p;
}

function mk_save_lines(int $cid, int $pid, mixed $lines): void {
    if (!is_array($lines) || !$lines) error_response('A purchase offer needs at least one line', 422);
    db()->prepare('DELETE FROM market_purchase_lines WHERE purchase_id = ?')->execute([$pid]);
    $ins = db()->prepare('INSERT INTO market_purchase_lines (purchase_id, item_id, qty_kg, offer_price) VALUES (?,?,?,?)');
    $seen = [];
    foreach ($lines as $l) {
        if (!is_array($l)) error_response('Invalid line', 422);
        $it = db()->prepare('SELECT id FROM items WHERE id = ? AND company_id = ?'); $it->execute([(int)($l['item_id'] ?? 0), $cid]);
        if (!$it->fetchColumn()) error_response('Item not found', 404);
        $item = (int)$l['item_id'];
        if (isset($seen[$item])) error_response('The same item appears twice: merge the lines', 422);
        $seen[$item] = true;
        $ins->execute([$pid, $item, round(mk_money($l['qty_kg'] ?? null, 'qty_kg'), 3), mk_money($l['offer_price'] ?? null, 'offer_price')]);
    }
}

function mk_create(array $u, array $b): array {
    $cid = (int)$u['company_id'];
    $market = mk_text($b['market_name'] ?? null, 255);
    if (!$market) error_response('The market name is required', 422);
    $s = db()->prepare('SELECT id FROM suppliers WHERE id = ? AND company_id = ? AND is_active = 1'); $s->execute([(int)($b['supplier_id'] ?? 0), $cid]);
    if (!$s->fetchColumn()) error_response('Supplier (the market trader) not found', 422);
    $co = null;
    if (!empty($b['client_order_id'])) {
        $c = db()->prepare('SELECT id FROM client_orders WHERE id = ? AND company_id = ?'); $c->execute([(int)$b['client_order_id'], $cid]);
        if (!$c->fetchColumn()) error_response('Client order not found', 422);
        $co = (int)$b['client_order_id'];
    }
    $branch = !empty($b['branch_id']) ? branch_validate($cid, (int)$b['branch_id']) : branch_default($cid);
    db()->beginTransaction();
    try {
        $number = crud_next_number('MP', 'market_purchases', $cid);
        db()->prepare('INSERT INTO market_purchases (company_id, branch_id, number, buyer_id, market_name, supplier_id, client_order_id, notes) VALUES (?,?,?,?,?,?,?,?)')
            ->execute([$cid, $branch, $number, $u['user_id'], $market, (int)$b['supplier_id'], $co, mk_text($b['notes'] ?? null, 2000)]);
        $id = (int)db()->lastInsertId();
        mk_save_lines($cid, $id, $b['lines'] ?? []);
        db()->commit();
    } catch (Throwable $e) { if (db()->inTransaction()) db()->rollBack(); throw $e; }
    return mk_detail($cid, $id);
}

function mk_update(array $u, int $id, array $b): array {
    $cid = (int)$u['company_id'];
    $p = mk_row($cid, $id);
    if (!in_array($p['status'], ['Draft', 'Returned'], true)) error_response('Only a draft or returned offer can be edited (this one is ' . $p['status'] . ')', 409);
    if ((int)$p['buyer_id'] !== (int)$u['user_id']) error_response('Only the buyer can edit his offer', 403);
    db()->beginTransaction();
    try {
        if (isset($b['market_name']) && mk_text($b['market_name'], 255)) db()->prepare('UPDATE market_purchases SET market_name = ? WHERE id = ?')->execute([mk_text($b['market_name'], 255), $id]);
        if (array_key_exists('notes', $b)) db()->prepare('UPDATE market_purchases SET notes = ? WHERE id = ?')->execute([mk_text($b['notes'], 2000), $id]);
        if (isset($b['lines'])) mk_save_lines($cid, $id, $b['lines']);   // new lines mean a fresh inspection
        db()->commit();
    } catch (Throwable $e) { if (db()->inTransaction()) db()->rollBack(); throw $e; }
    return mk_detail($cid, $id);
}

/** The quality person at the market passes or fails one line BEFORE the buyer buys. */
function mk_inspect(array $u, int $id, array $b): array {
    mk_need($u, 'inspect');
    $cid = (int)$u['company_id'];
    $p = mk_row($cid, $id);
    if (!in_array($p['status'], ['Draft', 'Returned'], true)) error_response('Inspection happens before the offer goes to Finance (this one is ' . $p['status'] . ')', 409);
    mk_sod($cid, $p, $u, 'inspect these goods');
    $res = $b['result'] ?? '';
    if (!in_array($res, ['Pass', 'Fail'], true)) error_response("result must be 'Pass' or 'Fail'", 422);
    $note = mk_text($b['note'] ?? null);
    if ($res === 'Fail' && !$note) error_response('A reason is required when a line fails inspection', 422);
    $grade = mk_text($b['grade'] ?? null, 10);
    $q = db()->prepare('UPDATE market_purchase_lines SET inspect_result = ?, inspect_grade = ?, inspect_note = ?, inspected_by = ?, inspected_at = NOW() WHERE id = ? AND purchase_id = ?');
    $q->execute([$res, $grade, $note, $u['user_id'], (int)($b['line_id'] ?? 0), $id]);
    if (!$q->rowCount()) { $ex = db()->prepare('SELECT 1 FROM market_purchase_lines WHERE id = ? AND purchase_id = ?'); $ex->execute([(int)($b['line_id'] ?? 0), $id]); if (!$ex->fetchColumn()) error_response('Line not found', 404); }
    return mk_detail($cid, $id);
}

function mk_submit(array $u, int $id): array {
    $cid = (int)$u['company_id'];
    $p = mk_row($cid, $id);
    if (!in_array($p['status'], ['Draft', 'Returned'], true)) error_response('This offer is ' . $p['status'], 409);
    if ((int)$p['buyer_id'] !== (int)$u['user_id']) error_response('Only the buyer can send his offer to Finance', 403);
    $lines = mk_lines($id);
    $must = wf_setting($cid, 'market.require_field_inspection', '1') === '1';
    if ($must) {
        foreach ($lines as $l) if ($l['inspect_result'] === 'Pending') error_response('Every line must be inspected by the quality person before the offer goes to Finance', 409);
        if (!array_filter($lines, fn($l) => $l['inspect_result'] === 'Pass')) error_response('No line passed inspection: there is nothing to buy', 409);
    }
    db()->prepare("UPDATE market_purchases SET status = 'Submitted', submitted_at = NOW(), return_reason = NULL WHERE id = ?")->execute([$id]);
    return mk_detail($cid, $id);
}

function mk_return(array $u, int $id, array $b): array {
    mk_need($u, 'approve');
    $cid = (int)$u['company_id'];
    $p = mk_row($cid, $id);
    if ($p['status'] !== 'Submitted') error_response('Only a submitted offer can be returned (this one is ' . $p['status'] . ')', 409);
    $reason = mk_text($b['reason'] ?? null);
    if (!$reason) error_response('A reason is required', 422);
    db()->prepare("UPDATE market_purchases SET status = 'Returned', return_reason = ? WHERE id = ?")->execute([$reason, $id]);
    return mk_detail($cid, $id);
}

/** Finance approves the offer: the purchase order is created (Approved) for the lines that passed, at the offered prices. */
function mk_approve(array $u, int $id): array {
    mk_need($u, 'approve');
    $cid = (int)$u['company_id'];
    wf_require($cid, 'market_purchase_approval', $u);
    $p = mk_row($cid, $id);
    if ($p['status'] !== 'Submitted') error_response('Only a submitted offer can be approved (this one is ' . $p['status'] . ')', 409);
    mk_sod($cid, $p, $u, 'approve this offer');
    $must = wf_setting($cid, 'market.require_field_inspection', '1') === '1';
    $lines = array_values(array_filter(mk_lines($id), fn($l) => $must ? $l['inspect_result'] === 'Pass' : $l['inspect_result'] !== 'Fail'));
    if (!$lines) error_response('Nothing to buy', 409);
    $total = round(array_sum(array_column($lines, 'amount')), 2);
    $sup = db()->prepare('SELECT name, currency FROM suppliers WHERE id = ?'); $sup->execute([$p['supplier_id']]); $s = $sup->fetch();
    db()->beginTransaction();
    try {
        $po = crud_next_number('PO', 'purchase_orders', $cid);
        // market cash purchases carry no recoverable VAT unless a tax invoice exists, so the order is net of tax
        db()->prepare("INSERT INTO purchase_orders (company_id, branch_id, number, date, supplier_id, supplier_name, currency, subtotal, tax_amount, total, status, notes, approved_by, approved_at, created_by)
                       VALUES (?,?,?,?,?,?,?,?,0,?,'Approved',?,?,NOW(),?)")
            ->execute([$cid, $p['branch_id'], $po, date('Y-m-d'), $p['supplier_id'], $s['name'], $s['currency'] ?: 'SAR', $total, $total, 'Market purchase ' . $p['number'] . ' (' . $p['market_name'] . ')', $u['user_id'], $p['buyer_id']]);
        $poId = (int)db()->lastInsertId();
        $ins = db()->prepare('INSERT INTO purchase_order_lines (purchase_order_id, item_id, item_name, qty, uom, rate, amount) VALUES (?,?,?,?,?,?,?)');
        foreach ($lines as $l) $ins->execute([$poId, $l['item_id'], $l['item_name'], $l['qty_kg'], 'kg', $l['offer_price'], $l['amount']]);
        db()->prepare("UPDATE market_purchases SET status = 'Approved', approved_by = ?, approved_at = NOW(), total_amount = ?, po_id = ? WHERE id = ?")->execute([$u['user_id'], $total, $poId, $id]);
        db()->commit();
    } catch (Throwable $e) { if (db()->inTransaction()) db()->rollBack(); throw $e; }
    return mk_detail($cid, $id);
}

function mk_open_custody(int $cid, int $buyer): array {
    $q = db()->prepare("SELECT COALESCE(SUM(issued_amount),0) AS amount, COALESCE(SUM(due_date < CURDATE()),0) AS overdue FROM market_purchases WHERE company_id = ? AND buyer_id = ? AND status IN ('Cash_Issued','Settlement_Submitted')");
    $q->execute([$cid, $buyer]);
    $r = $q->fetch();
    return ['amount' => (float)$r['amount'], 'overdue' => (int)$r['overdue']];
}

/** Finance hands the buyer cash: Dr buyers custody, Cr cash. Never more than the approved total (× advance %), within the buyer's limit. */
function mk_issue_cash(array $u, int $id, array $b): array {
    mk_need($u, 'approve');
    $cid = (int)$u['company_id'];
    $p = mk_row($cid, $id);
    if ($p['status'] !== 'Approved') error_response('Cash is given only for an approved offer (this one is ' . $p['status'] . ')', 409);
    mk_sod($cid, $p, $u, 'give the cash');
    $max = round((float)$p['total_amount'] * (float)wf_setting($cid, 'market.advance_pct', '100') / 100, 2);
    $amount = isset($b['amount']) && $b['amount'] !== '' ? mk_money($b['amount'], 'amount') : $max;
    if ($amount > $max + 0.004) error_response('The cash cannot be more than ' . number_format($max, 2, '.', '') . ' (the approved total × the advance %)', 422);
    $open = mk_open_custody($cid, (int)$p['buyer_id']);
    if (wf_setting($cid, 'market.block_if_overdue', '1') === '1' && $open['overdue'] > 0) error_response('This buyer has an overdue custody that is not settled: settle it first', 409);
    $limit = (float)wf_setting($cid, 'market.custody_limit_per_buyer', '0');
    if ($limit > 0 && $open['amount'] + $amount > $limit + 0.004) error_response('This would take the buyer above his custody limit of ' . number_format($limit, 2, '.', '') . ' (he holds ' . number_format($open['amount'], 2, '.', '') . ')', 409);
    $date = date('Y-m-d');
    fin_assert_open($cid, $date);
    db()->beginTransaction();
    try {
        $je = fin_post($cid, $date, 'Cash custody for ' . $p['number'], 'custody_issue', $id, [[fin_acct_or_fail($cid, 'custody'), $amount, 0], [fin_acct_or_fail($cid, 'cash'), 0, $amount]], (int)$u['user_id']);
        $days = max(1, (int)wf_setting($cid, 'market.settlement_days', '3'));
        db()->prepare("UPDATE market_purchases SET status = 'Cash_Issued', issued_amount = ?, cash_issued_by = ?, cash_issued_at = NOW(), due_date = DATE_ADD(CURDATE(), INTERVAL ? DAY), gl_issue_entry_id = ? WHERE id = ?")
            ->execute([$amount, $u['user_id'], $days, $je, $id]);
        db()->commit();
    } catch (Throwable $e) { if (db()->inTransaction()) db()->rollBack(); throw $e; }
    return mk_detail($cid, $id);
}

/** The buyer reports what he spent and what he gives back. spent + returned must equal the cash he received. */
function mk_settle(array $u, int $id, array $b): array {
    $cid = (int)$u['company_id'];
    $p = mk_row($cid, $id);
    if ($p['status'] !== 'Cash_Issued') error_response('Only a purchase with cash issued can be settled (this one is ' . $p['status'] . ')', 409);
    if ((int)$p['buyer_id'] !== (int)$u['user_id']) error_response('Only the buyer settles his custody', 403);
    $spent = mk_money($b['spent_amount'] ?? null, 'spent_amount', true);
    $ret = mk_money($b['returned_amount'] ?? null, 'returned_amount', true);
    if (abs($spent + $ret - (float)$p['issued_amount']) > 0.01) error_response('Spent + returned must equal the cash you received (' . number_format((float)$p['issued_amount'], 2, '.', '') . ')', 422);
    if ($spent > 0 && !mk_text($b['note'] ?? null)) error_response('Describe the receipts (market receipt numbers, traders) in the note', 422);
    db()->prepare("UPDATE market_purchases SET status = 'Settlement_Submitted', spent_amount = ?, returned_amount = ?, settlement_note = ?, settlement_submitted_at = NOW() WHERE id = ?")
        ->execute([$spent, $ret, mk_text($b['note'] ?? null), $id]);
    return mk_detail($cid, $id);
}

function mk_reject_settlement(array $u, int $id, array $b): array {
    mk_need($u, 'approve');
    $cid = (int)$u['company_id'];
    $p = mk_row($cid, $id);
    if ($p['status'] !== 'Settlement_Submitted') error_response('There is no settlement to return', 409);
    $reason = mk_text($b['reason'] ?? null);
    if (!$reason) error_response('A reason is required', 422);
    db()->prepare("UPDATE market_purchases SET status = 'Cash_Issued', settlement_note = CONCAT(COALESCE(settlement_note,''), ' | Returned by Finance: ', ?) WHERE id = ?")->execute([$reason, $id]);
    return mk_detail($cid, $id);
}

/** Finance confirms the settlement. Dr GRNI (the approved order value) ± price variance, Dr cash (change returned), Cr custody (cash issued). */
function mk_confirm_settlement(array $u, int $id): array {
    mk_need($u, 'approve');
    $cid = (int)$u['company_id'];
    $p = mk_row($cid, $id);
    if ($p['status'] !== 'Settlement_Submitted') error_response('There is no settlement to confirm (this one is ' . $p['status'] . ')', 409);
    mk_sod($cid, $p, $u, 'confirm this settlement');
    $T = (float)$p['total_amount']; $S = (float)$p['spent_amount']; $R = (float)$p['returned_amount']; $I = (float)$p['issued_amount'];
    $lines = [[fin_acct_or_fail($cid, 'custody'), 0, $I]];
    if ($T > 0) $lines[] = [fin_acct_or_fail($cid, 'grni'), $T, 0];
    if ($S - $T > 0.004) $lines[] = [fin_acct_or_fail($cid, 'price_variance'), round($S - $T, 2), 0];
    elseif ($T - $S > 0.004) $lines[] = [fin_acct_or_fail($cid, 'price_variance'), 0, round($T - $S, 2)];
    if ($R > 0) $lines[] = [fin_acct_or_fail($cid, 'cash'), $R, 0];
    $date = date('Y-m-d');
    fin_assert_open($cid, $date);
    db()->beginTransaction();
    try {
        $je = fin_post($cid, $date, 'Custody settlement for ' . $p['number'], 'custody_settle', $id, $lines, (int)$u['user_id']);
        db()->prepare("UPDATE market_purchases SET status = 'Settled', settled_by = ?, settled_at = NOW(), gl_settle_entry_id = ? WHERE id = ?")->execute([$u['user_id'], $je, $id]);
        db()->commit();
    } catch (Throwable $e) { if (db()->inTransaction()) db()->rollBack(); throw $e; }
    return mk_detail($cid, $id);
}

function mk_cancel(array $u, int $id, array $b): array {
    $cid = (int)$u['company_id'];
    $p = mk_row($cid, $id);
    if (!in_array($p['status'], ['Draft', 'Returned', 'Submitted', 'Approved'], true)) error_response('This purchase can no longer be cancelled (it is ' . $p['status'] . ')', 409);
    $isOwner = (int)$p['buyer_id'] === (int)$u['user_id'];
    if (!$isOwner && !permission_allows($u['permissions'], 'market-purchases', 'approve')) error_response('Only the buyer or Finance can cancel', 403);
    $reason = mk_text($b['reason'] ?? null);
    if (!$reason) error_response('A reason is required', 422);
    db()->beginTransaction();
    if ($p['po_id']) {
        $d = db()->prepare('SELECT COUNT(*) FROM deliveries WHERE purchase_order_id = ?'); $d->execute([$p['po_id']]);
        if ((int)$d->fetchColumn() > 0) { db()->rollBack(); error_response('A truck was already booked against its purchase order', 409); }
        db()->prepare("UPDATE purchase_orders SET status = 'Cancelled' WHERE id = ?")->execute([$p['po_id']]);
    }
    db()->prepare("UPDATE market_purchases SET status = 'Cancelled', notes = CONCAT(COALESCE(notes,''), ?) WHERE id = ?")->execute(["\nCancelled: " . $reason, $id]);
    db()->commit();
    return mk_detail($cid, $id);
}

/** Open custody per buyer (what Finance watches): amount held, how many are overdue. */
function mk_custody_report(int $cid): array {
    $q = db()->prepare("SELECT m.buyer_id, u.name AS buyer_name, COUNT(*) AS purchases, SUM(m.issued_amount) AS amount, SUM(m.due_date < CURDATE()) AS overdue, MIN(m.due_date) AS earliest_due
                        FROM market_purchases m JOIN users u ON u.id = m.buyer_id
                        WHERE m.company_id = ? AND m.status IN ('Cash_Issued','Settlement_Submitted')" . branch_scope_sql('m.branch_id') . ' GROUP BY m.buyer_id, u.name ORDER BY amount DESC');
    $q->execute([$cid]);
    return array_map(function ($r) { $r['amount'] = (float)$r['amount']; $r['overdue'] = (int)$r['overdue']; $r['purchases'] = (int)$r['purchases']; return $r; }, $q->fetchAll());
}
