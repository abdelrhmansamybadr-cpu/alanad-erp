<?php
// ─── Client orders: the order that arrives (e-mail / phone), stock check, reservation, shortage → purchase request ───
// Reservation is a soft hold on batches (oldest expiry first): it lowers what other orders see as "free", it does not move stock.
require_once __DIR__ . '/inventory.php';
require_once __DIR__ . '/purchasing.php';
require_once __DIR__ . '/crud.php';

function co_need(array $u, string $res, string $act): void {
    if (!permission_allows($u['permissions'], $res, $act)) error_response("Forbidden: you do not have permission to $act $res", 403);
}

function co_row(int $cid, int $id): array {
    $q = db()->prepare('SELECT * FROM client_orders WHERE id = ? AND company_id = ?');
    $q->execute([$id, $cid]);
    $o = $q->fetch();
    if (!$o || !branch_in_scope($o['branch_id'] !== null ? (int)$o['branch_id'] : null)) error_response('Not found', 404);
    return $o;
}

function co_lines(int $id): array {
    $q = db()->prepare('SELECT l.id, l.item_id, i.name AS item_name, i.name_ar AS item_name_ar, i.code AS item_code, l.qty_kg, l.reserved_kg, l.note
                        FROM client_order_lines l JOIN items i ON i.id = l.item_id WHERE l.order_id = ? ORDER BY l.id');
    $q->execute([$id]);
    return array_map(function ($r) {
        $r['qty_kg'] = (float)$r['qty_kg']; $r['reserved_kg'] = (float)$r['reserved_kg'];
        $r['shortage_kg'] = round(max(0, $r['qty_kg'] - $r['reserved_kg']), 3);
        return $r;
    }, $q->fetchAll());
}

/** Free kg per usable batch for an item in the order's branch: physical usable balance minus the active reservations of OTHER orders. */
function co_free_batches(int $cid, int $itemId, ?int $branch, int $exceptOrder = 0): array {
    $cand = inv_candidates($cid, $itemId, null, false, $branch !== null ? [$branch] : null);
    $r = db()->prepare("SELECT COALESCE(SUM(qty),0) FROM stock_reservations WHERE company_id = ? AND batch_id = ? AND status = 'Active' AND order_id <> ?");
    $out = [];
    foreach ($cand['usable'] as $b) {
        $r->execute([$cid, $b['batch_id'], $exceptOrder]);
        $free = round((float)$b['bal'] - (float)$r->fetchColumn(), 3);
        if ($free > 0.0005) $out[] = ['batch_id' => (int)$b['batch_id'], 'batch_no' => $b['batch_no'], 'expiry_date' => $b['expiry_date'], 'warehouse_id' => (int)$b['warehouse_id'], 'cold_room_id' => $b['cold_room_id'] !== null ? (int)$b['cold_room_id'] : null, 'free_kg' => $free];
    }
    // one batch can sit in several rooms: keep rows separate but never let reservations of the same batch be counted twice
    return $out;
}

function co_needs_approval(int $cid, array $o): bool {
    $lim = (float)wf_setting($cid, 'sales.approval_above_kg', '0');
    if ($lim <= 0 || $o['approved_by']) return false;
    $q = db()->prepare('SELECT COALESCE(SUM(qty_kg),0) FROM client_order_lines WHERE order_id = ?'); $q->execute([$o['id']]);
    return (float)$q->fetchColumn() > $lim;
}

function co_detail(int $cid, int $id): array {
    $o = co_row($cid, $id);
    $o['needs_approval'] = co_needs_approval($cid, $o);
    $o['lines'] = co_lines($id);
    $q = db()->prepare("SELECT r.id, r.line_id, r.batch_id, b.batch_no, b.expiry_date, r.cold_room_id, r.qty, r.status FROM stock_reservations r JOIN item_batches b ON b.id = r.batch_id WHERE r.order_id = ? AND r.status = 'Active' ORDER BY r.id");
    $q->execute([$id]);
    $o['reservations'] = array_map(fn($r) => $r + [], $q->fetchAll());
    foreach ($o['reservations'] as &$r) $r['qty'] = (float)$r['qty'];
    $o['total_kg'] = round(array_sum(array_column($o['lines'], 'qty_kg')), 3);
    $o['reserved_kg'] = round(array_sum(array_column($o['lines'], 'reserved_kg')), 3);
    return $o;
}

function co_save_lines(int $cid, int $orderId, mixed $lines): void {
    if (!is_array($lines) || !$lines) error_response('An order needs at least one line', 422);
    $seen = [];
    $ins = db()->prepare('INSERT INTO client_order_lines (order_id, item_id, qty_kg, note) VALUES (?,?,?,?)');
    foreach ($lines as $ln) {
        if (!is_array($ln)) error_response('Invalid line', 422);
        $item = inv_item($cid, (int)($ln['item_id'] ?? 0));
        $qty = $ln['qty_kg'] ?? $ln['qty'] ?? null;
        if (!is_numeric($qty) || (float)$qty <= 0) error_response('Every line needs a quantity (kg) greater than zero', 422);
        if (isset($seen[$item['id']])) error_response('The same item appears twice: merge the lines', 422);
        $seen[$item['id']] = true;
        $note = isset($ln['note']) && $ln['note'] !== '' ? mb_substr(trim((string)$ln['note']), 0, 255) : null;
        $ins->execute([$orderId, $item['id'], round((float)$qty, 3), $note]);
    }
}

function co_create(array $u, array $b): array {
    $cid = (int)$u['company_id'];
    $name = trim((string)($b['customer_name'] ?? ''));
    $custId = null;
    if (!empty($b['customer_id'])) {
        $c = db()->prepare('SELECT id, name FROM customers WHERE id = ? AND company_id = ?'); $c->execute([(int)$b['customer_id'], $cid]);
        $cr = $c->fetch();
        if (!$cr) error_response('Customer not found', 422);
        $custId = (int)$cr['id']; if ($name === '') $name = $cr['name'];
    }
    if ($name === '') error_response('The client name is required', 422);
    $due = $b['due_date'] ?? null;
    if ($due !== null && $due !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$due)) error_response('due_date must be YYYY-MM-DD', 422);
    $channel = in_array($b['channel'] ?? 'email', ['email', 'phone', 'whatsapp', 'visit', 'other'], true) ? ($b['channel'] ?? 'email') : error_response('Unknown channel', 422);
    $branch = !empty($b['branch_id']) ? branch_validate($cid, (int)$b['branch_id']) : branch_default($cid);
    db()->beginTransaction();
    try {
        $number = crud_next_number('CO', 'client_orders', $cid);
        db()->prepare('INSERT INTO client_orders (company_id, branch_id, number, customer_id, customer_name, channel, order_date, due_date, notes, created_by) VALUES (?,?,?,?,?,?,?,?,?,?)')
            ->execute([$cid, $branch, $number, $custId, mb_substr($name, 0, 255), $channel, date('Y-m-d'), $due ?: null, isset($b['notes']) ? mb_substr((string)$b['notes'], 0, 2000) : null, $u['user_id']]);
        $id = (int)db()->lastInsertId();
        co_save_lines($cid, $id, $b['lines'] ?? []);
        db()->commit();
    } catch (Throwable $e) { if (db()->inTransaction()) db()->rollBack(); throw $e; }
    return co_detail($cid, $id);
}

function co_update(array $u, int $id, array $b): array {
    $cid = (int)$u['company_id'];
    $o = co_row($cid, $id);
    if ($o['status'] !== 'Draft') error_response('Release the reservation first: only a draft order can be edited (this one is ' . $o['status'] . ')', 409);
    db()->beginTransaction();
    try {
        $set = []; $p = [];
        if (isset($b['customer_name']) && trim((string)$b['customer_name']) !== '') { $set[] = 'customer_name = ?'; $p[] = mb_substr(trim((string)$b['customer_name']), 0, 255); }
        if (array_key_exists('due_date', $b)) {
            if ($b['due_date'] !== null && $b['due_date'] !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$b['due_date'])) error_response('due_date must be YYYY-MM-DD', 422);
            $set[] = 'due_date = ?'; $p[] = $b['due_date'] ?: null;
        }
        if (array_key_exists('notes', $b)) { $set[] = 'notes = ?'; $p[] = $b['notes'] !== null ? mb_substr((string)$b['notes'], 0, 2000) : null; }
        if ($set) { $p[] = $id; db()->prepare('UPDATE client_orders SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($p); }
        if (isset($b['lines'])) { db()->prepare('DELETE FROM client_order_lines WHERE order_id = ?')->execute([$id]); co_save_lines($cid, $id, $b['lines']); db()->prepare('UPDATE client_orders SET approved_by = NULL, approved_at = NULL WHERE id = ?')->execute([$id]); }   // new quantities need a fresh approval
        db()->commit();
    } catch (Throwable $e) { if (db()->inTransaction()) db()->rollBack(); throw $e; }
    return co_detail($cid, $id);
}

/** Read-only: for every line, how much can be served from free stock right now and what is missing. */
function co_check(int $cid, int $id): array {
    $o = co_row($cid, $id);
    if (in_array($o['status'], ['Cancelled', 'Closed'], true)) error_response('This order is ' . $o['status'], 409);
    $branch = $o['branch_id'] !== null ? (int)$o['branch_id'] : null;
    $res = []; $all = true;
    foreach (co_lines($id) as $l) {
        $free = co_free_batches($cid, (int)$l['item_id'], $branch, $id);
        $avail = round(array_sum(array_column($free, 'free_kg')), 3);
        $short = round(max(0, $l['qty_kg'] - $avail), 3);
        if ($short > 0.0005) $all = false;
        $res[] = ['line_id' => (int)$l['id'], 'item_id' => (int)$l['item_id'], 'item_name' => $l['item_name'], 'needed_kg' => $l['qty_kg'], 'free_kg' => $avail, 'shortage_kg' => $short, 'batches' => $free];
    }
    return ['order_id' => $id, 'can_fulfil' => $all, 'lines' => $res];
}

function co_release_active(int $id): void {
    db()->prepare("UPDATE stock_reservations SET status = 'Released', released_at = NOW() WHERE order_id = ? AND status = 'Active'")->execute([$id]);
    db()->prepare('UPDATE client_order_lines SET reserved_kg = 0 WHERE order_id = ?')->execute([$id]);
}

/** Hold stock for the order, oldest expiry first. Re-running replaces the previous hold. Partial cover → status Short. */
function co_reserve(array $u, int $id): array {
    $cid = (int)$u['company_id'];
    $o = co_row($cid, $id);
    if (!in_array($o['status'], ['Draft', 'Reserved', 'Short'], true)) error_response('This order is ' . $o['status'], 409);
    if (co_needs_approval($cid, $o)) error_response('This order is above the approval limit: a second person must approve it before stock is reserved', 409);
    $branch = $o['branch_id'] !== null ? (int)$o['branch_id'] : null;
    $lock = 'co_resv_' . $cid;
    $got = db()->query("SELECT GET_LOCK(" . db()->quote($lock) . ", 10)")->fetchColumn();
    if (!$got) error_response('The system is busy reserving stock: try again', 409);
    db()->beginTransaction();
    try {
        co_release_active($id);
        $ins = db()->prepare('INSERT INTO stock_reservations (company_id, order_id, line_id, item_id, batch_id, warehouse_id, cold_room_id, qty) VALUES (?,?,?,?,?,?,?,?)');
        $upd = db()->prepare('UPDATE client_order_lines SET reserved_kg = ? WHERE id = ?');
        $full = true;
        foreach (co_lines($id) as $l) {
            $need = $l['qty_kg']; $got_kg = 0.0;
            foreach (co_free_batches($cid, (int)$l['item_id'], $branch, $id) as $b) {
                if ($need <= 0.0005) break;
                $take = round(min($need, $b['free_kg']), 3);
                $ins->execute([$cid, $id, $l['id'], $l['item_id'], $b['batch_id'], $b['warehouse_id'], $b['cold_room_id'], $take]);
                $need = round($need - $take, 3); $got_kg = round($got_kg + $take, 3);
            }
            $upd->execute([$got_kg, $l['id']]);
            if ($need > 0.0005) $full = false;
        }
        db()->prepare('UPDATE client_orders SET status = ? WHERE id = ?')->execute([$full ? 'Reserved' : 'Short', $id]);
        db()->commit();
    } catch (Throwable $e) { if (db()->inTransaction()) db()->rollBack(); db()->query('SELECT RELEASE_LOCK(' . db()->quote($lock) . ')'); throw $e; }
    db()->query('SELECT RELEASE_LOCK(' . db()->quote($lock) . ')');
    return co_detail($cid, $id);
}

function co_release(array $u, int $id): array {
    $cid = (int)$u['company_id'];
    $o = co_row($cid, $id);
    if (!in_array($o['status'], ['Reserved', 'Short'], true)) error_response('There is no reservation to release (this order is ' . $o['status'] . ')', 409);
    db()->beginTransaction();
    co_release_active($id);
    db()->prepare("UPDATE client_orders SET status = 'Draft' WHERE id = ?")->execute([$id]);
    db()->commit();
    return co_detail($cid, $id);
}

function co_cancel(array $u, int $id, array $b): array {
    $cid = (int)$u['company_id'];
    $o = co_row($cid, $id);
    if (in_array($o['status'], ['Cancelled', 'Closed'], true)) error_response('This order is already ' . $o['status'], 409);
    $reason = trim((string)($b['reason'] ?? ''));
    if ($reason === '') error_response('A reason is required', 422);
    db()->beginTransaction();
    co_release_active($id);
    db()->prepare("UPDATE client_orders SET status = 'Cancelled', notes = CONCAT(COALESCE(notes,''), ?) WHERE id = ?")->execute(["\nCancelled: " . mb_substr($reason, 0, 500), $id]);
    db()->commit();
    return co_detail($cid, $id);
}

/** Draft purchase request for whatever the reservation could not cover (the buyers then go to suppliers / the market). */
function co_request_purchase(array $u, int $id): array {
    co_need($u, 'purchase-requests', 'create');
    $cid = (int)$u['company_id'];
    $o = co_row($cid, $id);
    if ($o['status'] !== 'Short') error_response('Only an order with a shortage (status Short) needs a purchase request — reserve first', 409);
    if ($o['purchase_request_id']) error_response('A purchase request was already made for this order', 409);
    $lines = [];
    foreach (co_lines($id) as $l) if ($l['shortage_kg'] > 0.0005) $lines[] = ['item_id' => (int)$l['item_id'], 'qty' => ceil($l['shortage_kg']), 'note' => $o['number']];
    $pr = pur_pr_create($u, ['notes' => 'For client order ' . $o['number'] . ' (' . $o['customer_name'] . ')', 'needed_by' => $o['due_date'], 'lines' => $lines]);
    db()->prepare('UPDATE client_orders SET purchase_request_id = ? WHERE id = ?')->execute([$pr['id'], $id]);
    return ['purchase_request_id' => (int)$pr['id'], 'number' => $pr['number'], 'lines' => count($lines)];
}

/** A second person (client-orders.approve, never the one who entered it) approves a big order so it can be reserved. */
function co_approve(array $u, int $id): array {
    co_need($u, 'client-orders', 'approve');
    $cid = (int)$u['company_id'];
    wf_require($cid, 'client_order_approval', $u);
    $o = co_row($cid, $id);
    if ($o['status'] !== 'Draft') error_response('Only a draft order can be approved (this one is ' . $o['status'] . ')', 409);
    if (!co_needs_approval($cid, $o)) error_response('This order does not need approval', 409);
    if ((int)$o['created_by'] === (int)$u['user_id'] && wf_setting($cid, 'workflow.maker_not_checker', '1') === '1') error_response('You entered this order: another person must approve it', 403);
    db()->prepare('UPDATE client_orders SET approved_by = ?, approved_at = NOW() WHERE id = ?')->execute([$u['user_id'], $id]);
    return co_detail($cid, $id);
}
