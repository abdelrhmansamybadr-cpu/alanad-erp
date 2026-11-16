<?php
// ─── Alanad ERP — purchasing engine (Phase 2) ────────────────
// need -> purchase request (PR) -> supervisor approval -> PO from dated supplier prices -> finance approval above a limit.
// Rules (tested in tests/phase2_test.php):
//   • a PO line always has a price from the supplier's dated price list; no price = no PO
//   • the person who submits a document can never approve it (workflow engine, maker != checker)
//   • a PO can only change status through submit / decide; clients cannot set Approved themselves
//   • POs at or below the finance limit (setting) are approved automatically; the rest need a manager

require_once __DIR__ . '/workflow.php';
require_once __DIR__ . '/crud.php';

function pur_ensure_workflows(int $cid): void {
    if ($cid === 1) return;
    foreach (['purchase_request', 'purchase_order'] as $key) {
        $st = db()->prepare('SELECT id FROM workflows WHERE company_id = ? AND wf_key = ?');
        $st->execute([$cid, $key]);
        if ($st->fetchColumn()) continue;
        db()->prepare('INSERT INTO workflows (company_id, wf_key, name_en, name_ar, doc_type) SELECT ?, wf_key, name_en, name_ar, doc_type FROM workflows WHERE company_id = 1 AND wf_key = ?')->execute([$cid, $key]);
        $new = (int)db()->lastInsertId();
        db()->prepare('INSERT INTO workflow_steps (workflow_id, position, name_en, name_ar, role_key, rule) SELECT ?, s.position, s.name_en, s.name_ar, s.role_key, s.rule FROM workflow_steps s JOIN workflows w ON w.id = s.workflow_id WHERE w.company_id = 1 AND w.wf_key = ?')->execute([$new, $key]);
    }
}

/** price per kg valid on $date (latest valid_from not in the future), or null */
function pur_price(int $cid, int $supplierId, int $itemId, ?string $date = null): ?float {
    $st = db()->prepare('SELECT price FROM supplier_prices WHERE company_id = ? AND supplier_id = ? AND item_id = ? AND valid_from <= ? ORDER BY valid_from DESC LIMIT 1');
    $st->execute([$cid, $supplierId, $itemId, $date ?? date('Y-m-d')]);
    $p = $st->fetchColumn();
    return $p === false ? null : (float)$p;
}

function pur_pr(int $cid, int $id): array {
    $st = db()->prepare('SELECT p.*, u.name AS requested_by_name FROM purchase_requests p LEFT JOIN users u ON u.id = p.requested_by WHERE p.id = ? AND p.company_id = ?');
    $st->execute([$id, $cid]);
    $pr = $st->fetch();
    if (!$pr) error_response('Not found', 404);
    $l = db()->prepare('SELECT l.id, l.item_id, i.name AS item_name, i.name_ar AS item_name_ar, i.code AS item_code, l.qty, l.note FROM purchase_request_lines l JOIN items i ON i.id = l.item_id WHERE l.purchase_request_id = ? ORDER BY l.id');
    $l->execute([$id]);
    $pr['lines'] = array_map(function ($r) { $r['qty'] = (float)$r['qty']; return $r; }, $l->fetchAll());
    return $pr;
}

function pur_save_lines(int $cid, int $prId, mixed $lines): void {
    if (!is_array($lines) || !$lines) error_response('A purchase request needs at least one line', 422);
    db()->prepare('DELETE FROM purchase_request_lines WHERE purchase_request_id = ?')->execute([$prId]);
    $seen = [];
    foreach ($lines as $ln) {
        if (!is_array($ln)) error_response('Invalid line', 422);
        $itemId = (int)($ln['item_id'] ?? 0);
        $qty = $ln['qty'] ?? null;
        if (!is_numeric($qty) || (float)$qty <= 0) error_response('Every line needs a quantity greater than zero', 422);
        if (isset($seen[$itemId])) error_response('The same item appears twice: merge the lines', 422);
        $seen[$itemId] = true;
        $ok = db()->prepare('SELECT 1 FROM items WHERE id = ? AND company_id = ?'); $ok->execute([$itemId, $cid]);
        if (!$ok->fetchColumn()) error_response("Item $itemId not found", 422);
        db()->prepare('INSERT INTO purchase_request_lines (purchase_request_id, item_id, qty, note) VALUES (?,?,?,?)')
            ->execute([$prId, $itemId, round((float)$qty, 3), isset($ln['note']) ? substr((string)$ln['note'], 0, 255) : null]);
    }
}

function pur_pr_create(array $u, array $b): array {
    $cid = (int)$u['company_id'];
    db()->beginTransaction();
    try {
        $number = crud_next_number('PR', 'purchase_requests', $cid);
        db()->prepare('INSERT INTO purchase_requests (company_id, number, date, needed_by, requested_by, notes) VALUES (?,?,?,?,?,?)')
            ->execute([$cid, $number, date('Y-m-d'), $b['needed_by'] ?? null, $u['user_id'], $b['notes'] ?? null]);
        $id = (int)db()->lastInsertId();
        pur_save_lines($cid, $id, $b['lines'] ?? []);
        db()->commit();
    } catch (Throwable $e) { if (db()->inTransaction()) db()->rollBack(); throw $e; }
    return pur_pr($cid, $id);
}

function pur_pr_update(array $u, int $id, array $b): array {
    $cid = (int)$u['company_id'];
    $pr = pur_pr($cid, $id);
    if (!in_array($pr['status'], ['Draft', 'Rejected'], true)) error_response('Only draft or rejected requests can be edited', 409);
    if ((int)$pr['requested_by'] !== (int)$u['user_id'] && $u['role'] !== 'admin') error_response('Only the requester can edit this request', 403);
    db()->beginTransaction();
    try {
        db()->prepare("UPDATE purchase_requests SET needed_by = ?, notes = ?, status = 'Draft', reject_reason = NULL WHERE id = ?")->execute([$b['needed_by'] ?? $pr['needed_by'], $b['notes'] ?? $pr['notes'], $id]);
        if (array_key_exists('lines', $b)) pur_save_lines($cid, $id, $b['lines']);
        db()->commit();
    } catch (Throwable $e) { if (db()->inTransaction()) db()->rollBack(); throw $e; }
    return pur_pr($cid, $id);
}

function pur_pr_submit(array $u, int $id): array {
    $cid = (int)$u['company_id'];
    $pr = pur_pr($cid, $id);
    if ($pr['status'] !== 'Draft') error_response('Only a draft request can be submitted', 409);
    if (!$pr['lines']) error_response('A purchase request needs at least one line', 422);
    if (inv_setting_pur($cid, 'purchasing.pr_approval_required', '1') !== '1') {
        db()->prepare("UPDATE purchase_requests SET status = 'Approved' WHERE id = ?")->execute([$id]);
        return pur_pr($cid, $id);
    }
    pur_ensure_workflows($cid);
    $inst = wf_start($cid, 'purchase_request', 'purchase_request', $id, (int)$u['user_id']);
    db()->prepare("UPDATE purchase_requests SET status = 'Pending', workflow_instance_id = ? WHERE id = ?")->execute([$inst['id'], $id]);
    return pur_pr($cid, $id);
}

function inv_setting_pur(int $cid, string $key, string $default): string { return wf_setting($cid, $key, $default); }

/** approve or reject a pending request / order; $table: purchase_requests | purchase_orders */
function pur_decide(array $u, string $table, int $id, array $b): array {
    $cid = (int)$u['company_id'];
    $isPr = $table === 'purchase_requests';
    $st = db()->prepare("SELECT * FROM `$table` WHERE id = ? AND company_id = ?");
    $st->execute([$id, $cid]);
    $doc = $st->fetch();
    if (!$doc) error_response('Not found', 404);
    $pending = $isPr ? 'Pending' : 'Submitted';
    if ($doc['status'] !== $pending || !$doc['workflow_instance_id']) error_response('This document is not waiting for a decision', 409);
    $action = $b['action'] ?? '';
    if (!in_array($action, ['approve', 'reject'], true)) error_response('action must be approve or reject', 422);

    $inst = wf_act((int)$doc['workflow_instance_id'], $u, $action, $b['reason_code'] ?? null, $b['note'] ?? null);   // enforces role, maker != checker, reason
    if ($action === 'approve' && $inst['status'] === 'approved') {
        if ($isPr) db()->prepare("UPDATE purchase_requests SET status = 'Approved' WHERE id = ?")->execute([$id]);
        else db()->prepare("UPDATE purchase_orders SET status = 'Approved', approved_by = ?, approved_at = NOW() WHERE id = ?")->execute([$u['user_id'], $id]);
    } elseif ($action === 'reject') {
        db()->prepare("UPDATE workflow_instances SET status = 'cancelled', needs_supervisor = 0 WHERE id = ?")->execute([$inst['id']]);
        $why = substr(trim(($b['reason_code'] ?? '') . ': ' . ($b['note'] ?? ''), ': '), 0, 255);
        if ($isPr) db()->prepare("UPDATE purchase_requests SET status = 'Rejected', reject_reason = ? WHERE id = ?")->execute([$why, $id]);
        else db()->prepare("UPDATE purchase_orders SET status = 'Draft', workflow_instance_id = NULL, notes = CONCAT(COALESCE(notes, ''), ?) WHERE id = ?")->execute([" [Rejected: $why]", $id]);
    }
    return $isPr ? pur_pr($cid, $id) : pur_po($cid, $id);
}

function pur_po(int $cid, int $id): array {
    $st = db()->prepare('SELECT * FROM purchase_orders WHERE id = ? AND company_id = ?');
    $st->execute([$id, $cid]);
    $po = $st->fetch();
    if (!$po) error_response('Not found', 404);
    $l = db()->prepare('SELECT * FROM purchase_order_lines WHERE purchase_order_id = ? ORDER BY id');
    $l->execute([$id]);
    $po['lines'] = $l->fetchAll();
    return $po;
}

function pur_pr_to_po(array $u, int $prId, int $supplierId): array {
    $cid = (int)$u['company_id'];
    $pr = pur_pr($cid, $prId);
    if ($pr['status'] !== 'Approved') error_response('Only an approved request can become a purchase order', 409);
    $sup = db()->prepare('SELECT id, name, currency FROM suppliers WHERE id = ? AND company_id = ? AND is_active = 1');
    $sup->execute([$supplierId, $cid]);
    $supplier = $sup->fetch();
    if (!$supplier) error_response('Supplier not found', 404);

    $lines = []; $missing = []; $subtotal = 0.0;
    foreach ($pr['lines'] as $l) {
        $price = pur_price($cid, $supplierId, (int)$l['item_id']);
        if ($price === null) { $missing[] = $l['item_name']; continue; }
        $amount = round($l['qty'] * $price, 2);
        $subtotal += $amount;
        $lines[] = ['item_id' => $l['item_id'], 'item_name' => $l['item_name'], 'qty' => $l['qty'], 'rate' => $price, 'amount' => $amount];
    }
    if ($missing) error_response('No price for this supplier today: ' . implode(', ', $missing), 422);

    $vat = (float)wf_setting($cid, 'finance.vat_rate_pct', '15');
    $tax = round($subtotal * $vat / 100, 2);
    db()->beginTransaction();
    try {
        $number = crud_next_number('PO', 'purchase_orders', $cid);
        db()->prepare("INSERT INTO purchase_orders (company_id, number, date, expected_date, supplier_id, supplier_name, currency, subtotal, tax_amount, total, status, purchase_request_id, created_by)
                       VALUES (?,?,?,?,?,?,?,?,?,?,'Draft',?,?)")
            ->execute([$cid, $number, date('Y-m-d'), $pr['needed_by'], $supplierId, $supplier['name'], $supplier['currency'] ?: 'SAR', round($subtotal, 2), $tax, round($subtotal + $tax, 2), $prId, $u['user_id']]);
        $poId = (int)db()->lastInsertId();
        foreach ($lines as $ln) {
            db()->prepare('INSERT INTO purchase_order_lines (purchase_order_id, item_id, item_name, qty, uom, rate, amount) VALUES (?,?,?,?,?,?,?)')
                ->execute([$poId, $ln['item_id'], $ln['item_name'], $ln['qty'], 'kg', $ln['rate'], $ln['amount']]);
        }
        db()->prepare("UPDATE purchase_requests SET status = 'Converted' WHERE id = ?")->execute([$prId]);
        db()->commit();
    } catch (Throwable $e) { if (db()->inTransaction()) db()->rollBack(); throw $e; }
    return pur_po($cid, $poId);
}

function pur_po_submit(array $u, int $id): array {
    $cid = (int)$u['company_id'];
    $po = pur_po($cid, $id);
    if ($po['status'] !== 'Draft') error_response('Only a draft order can be submitted', 409);
    if (!$po['lines']) error_response('The order has no lines', 422);
    foreach ($po['lines'] as $l) if (!is_numeric($l['rate']) || (float)$l['rate'] <= 0) error_response('Every line needs a price before the order can be sent', 422);
    // totals are always recomputed on the server from the lines: a client-supplied total can never dodge the approval limit
    $subtotal = 0.0;
    foreach ($po['lines'] as $l) {
        $amount = round((float)$l['qty'] * (float)$l['rate'], 2);
        db()->prepare('UPDATE purchase_order_lines SET amount = ? WHERE id = ?')->execute([$amount, $l['id']]);
        $subtotal += $amount;
    }
    $vat = (float)wf_setting($cid, 'finance.vat_rate_pct', '15');
    $tax = round($subtotal * $vat / 100, 2);
    $po['total'] = round($subtotal + $tax, 2);
    db()->prepare('UPDATE purchase_orders SET subtotal = ?, tax_amount = ?, total = ? WHERE id = ?')->execute([round($subtotal, 2), $tax, $po['total'], $id]);
    $limit = (float)wf_setting($cid, 'finance.po_approval_limit', '0');
    $needs = $limit <= 0 || (float)$po['total'] > $limit;
    if (!$needs) {
        db()->prepare("UPDATE purchase_orders SET status = 'Approved', approved_at = NOW(), approved_by = NULL WHERE id = ?")->execute([$id]);
        return pur_po($cid, $id);
    }
    pur_ensure_workflows($cid);
    $inst = wf_start($cid, 'purchase_order', 'purchase_order', $id, (int)$u['user_id']);
    db()->prepare("UPDATE purchase_orders SET status = 'Submitted', workflow_instance_id = ? WHERE id = ?")->execute([$inst['id'], $id]);
    return pur_po($cid, $id);
}

/** items whose on-hand quantity (kg, all rooms, usable or not) is below their reorder level */
function pur_suggestions(int $cid): array {
    $q = db()->prepare("SELECT * FROM (
                          SELECT i.id AS item_id, i.code, i.name, i.name_ar, i.reorder_level,
                                 COALESCE((SELECT SUM(l.qty) FROM stock_ledger l WHERE l.company_id = i.company_id AND l.item_id = i.id), 0) AS on_hand
                          FROM items i WHERE i.company_id = ? AND i.is_active = 1 AND i.reorder_level IS NOT NULL
                        ) t WHERE t.on_hand < t.reorder_level ORDER BY (t.reorder_level - t.on_hand) DESC");
    $q->execute([$cid]);
    return array_map(function ($r) { $r['on_hand'] = round((float)$r['on_hand'], 3); $r['reorder_level'] = (float)$r['reorder_level']; $r['shortfall'] = round($r['reorder_level'] - $r['on_hand'], 3); return $r; }, $q->fetchAll());
}
