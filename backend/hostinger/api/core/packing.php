<?php
// ─── Packing (التعبئة): box (1 or 2 kg) → carton (40 boxes) → pallet (طبلية) ───
// The planner opens a pack order (for a client order or on its own); the head of preparation decides the box size; the packer issues the stock
// (grade A = class 1 from sorting, the batches held for that client order first) and enters how many boxes he packed; the cartons and pallets are created with codes.
require_once __DIR__ . '/inventory.php';
require_once __DIR__ . '/workflow.php';
require_once __DIR__ . '/crud.php';

function pk_need(array $u, string $act): void {
    if (!permission_allows($u['permissions'], 'pack-orders', $act)) error_response("Forbidden: you do not have permission to $act pack orders", 403);
}
function pk_text(mixed $v, int $max = 500): ?string {
    $v = trim((string)($v ?? ''));
    return $v === '' ? null : mb_substr($v, 0, $max);
}
function pk_row(int $cid, int $id): array {
    $q = db()->prepare('SELECT * FROM pack_orders WHERE id = ? AND company_id = ?');
    $q->execute([$id, $cid]);
    $r = $q->fetch();
    if (!$r || !branch_in_scope($r['branch_id'] !== null ? (int)$r['branch_id'] : null)) error_response('Not found', 404);
    return $r;
}
/** allowed box sizes from a text like "1,2" */
function pk_sizes(string $txt): array {
    $o = [];
    foreach (explode(',', $txt) as $x) { $x = trim($x); if ($x !== '' && is_numeric($x) && (float)$x > 0) $o[] = round((float)$x, 3); }
    $o = array_values(array_unique($o)); sort($o);
    return $o;
}

/** the pack specification that applies: the client's own for this item, else the item's general one, else the company defaults */
function pk_spec(int $cid, int $itemId, ?int $customerId): array {
    $q = db()->prepare('SELECT * FROM pack_specs WHERE company_id = ? AND item_id = ? AND is_active = 1 AND (customer_id <=> ? OR customer_id IS NULL) ORDER BY customer_id IS NULL, id DESC LIMIT 1');
    $q->execute([$cid, $itemId, $customerId]);
    $s = $q->fetch();
    if ($s) return ['id' => (int)$s['id'], 'sizes' => pk_sizes($s['box_kg_options']) ?: [1.0], 'default_box_kg' => (float)$s['default_box_kg'], 'boxes_per_carton' => (int)$s['boxes_per_carton'],
                    'cartons_per_pallet' => $s['cartons_per_pallet'] !== null ? (int)$s['cartons_per_pallet'] : (int)inv_setting($cid, 'packing.cartons_per_pallet', '40'), 'label_note' => $s['label_note']];
    $sizes = pk_sizes(inv_setting($cid, 'packing.box_sizes_kg', '1,2')) ?: [1.0];
    return ['id' => null, 'sizes' => $sizes, 'default_box_kg' => $sizes[0], 'boxes_per_carton' => (int)inv_setting($cid, 'packing.boxes_per_carton', '40'), 'cartons_per_pallet' => (int)inv_setting($cid, 'packing.cartons_per_pallet', '40'), 'label_note' => null];
}

function pk_detail(int $cid, int $id): array {
    $o = pk_row($cid, $id);
    foreach (['required_kg', 'box_kg', 'issued_kg', 'cost_total', 'loss_kg'] as $f) if ($o[$f] !== null) $o[$f] = (float)$o[$f];
    $i = db()->prepare('SELECT name, name_ar FROM items WHERE id = ?'); $i->execute([$o['item_id']]); $it = $i->fetch();
    $o['item_name'] = $it['name'] ?? null; $o['item_name_ar'] = $it['name_ar'] ?? null;
    $o['spec'] = pk_spec($cid, (int)$o['item_id'], $o['customer_id'] !== null ? (int)$o['customer_id'] : null);
    $b = db()->prepare('SELECT p.batch_id, b.batch_no, b.expiry_date, p.kg, p.unit_cost FROM pack_order_batches p JOIN item_batches b ON b.id = p.batch_id WHERE p.order_id = ? ORDER BY p.id'); $b->execute([$id]);
    $o['batches'] = array_map(function ($x) { $x['kg'] = (float)$x['kg']; return $x; }, $b->fetchAll());
    $u = db()->prepare('SELECT id, kind, code, parent_id, boxes, kg, is_partial, status FROM pack_units WHERE order_id = ? ORDER BY kind = \'carton\', id'); $u->execute([$id]);
    $o['units'] = array_map(function ($x) { $x['kg'] = (float)$x['kg']; $x['is_partial'] = (bool)$x['is_partial']; return $x; }, $u->fetchAll());
    return $o;
}

function pk_create(array $u, array $b): array {
    $cid = (int)$u['company_id'];
    $itemId = (int)($b['item_id'] ?? 0);
    $coId = null; $custId = null; $custName = pk_text($b['customer_name'] ?? null, 255); $due = $b['due_date'] ?? null; $branch = null; $req = $b['required_kg'] ?? null;
    if (!empty($b['client_order_id'])) {
        $c = db()->prepare('SELECT * FROM client_orders WHERE id = ? AND company_id = ?'); $c->execute([(int)$b['client_order_id'], $cid]);
        $co = $c->fetch();
        if (!$co || !branch_in_scope($co['branch_id'] !== null ? (int)$co['branch_id'] : null)) error_response('Client order not found', 404);
        if (in_array($co['status'], ['Cancelled', 'Closed'], true)) error_response('This client order is ' . $co['status'], 409);
        $l = db()->prepare('SELECT item_id, qty_kg FROM client_order_lines WHERE order_id = ? AND (item_id = ? OR ? = 0) ORDER BY id LIMIT 1'); $l->execute([$co['id'], $itemId, $itemId]);
        $line = $l->fetch();
        if (!$line) error_response('This item is not on the client order', 422);
        $itemId = (int)$line['item_id']; $coId = (int)$co['id']; $custId = $co['customer_id'] !== null ? (int)$co['customer_id'] : null; $custName = $custName ?: $co['customer_name']; $due = $due ?: $co['due_date']; $branch = $co['branch_id'] !== null ? (int)$co['branch_id'] : null;
        if ($req === null || $req === '') $req = $line['qty_kg'];
    }
    if ($coId === null && !empty($b['customer_id'])) {
        $c = db()->prepare('SELECT id, name FROM customers WHERE id = ? AND company_id = ?'); $c->execute([(int)$b['customer_id'], $cid]);
        $cr = $c->fetch();
        if (!$cr) error_response('Customer not found', 422);
        $custId = (int)$cr['id']; $custName = $custName ?: $cr['name'];
    }
    $item = inv_item($cid, $itemId);
    if (!is_numeric($req) || (float)$req <= 0) error_response('required_kg must be a positive number', 422);
    if ($due !== null && $due !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$due)) error_response('due_date must be YYYY-MM-DD', 422);
    $grade = strtoupper((string)($b['grade'] ?? inv_setting($cid, 'packing.default_grade', 'A')));
    if (!in_array($grade, ['A', 'B', 'C'], true)) error_response('grade must be A, B or C', 422);
    if (!empty($b['branch_id'])) $branch = branch_validate($cid, (int)$b['branch_id']); elseif ($branch === null) $branch = branch_default($cid);
    $spec = pk_spec($cid, (int)$item['id'], $custId);
    $number = crud_next_number('PK', 'pack_orders', $cid);
    db()->prepare('INSERT INTO pack_orders (company_id, branch_id, number, client_order_id, customer_id, customer_name, item_id, grade, required_kg, due_date, spec_id, boxes_per_carton, cartons_per_pallet, notes, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$cid, $branch, $number, $coId, $custId, $custName, $item['id'], $grade, round((float)$req, 3), $due ?: null, $spec['id'], $spec['boxes_per_carton'], $spec['cartons_per_pallet'], pk_text($b['notes'] ?? null, 1000), $u['user_id']]);
    return pk_detail($cid, (int)db()->lastInsertId());
}

/** the head of preparation decides the box size (from the sizes the specification allows) */
function pk_decide(array $u, int $id, array $b): array {
    pk_need($u, 'decide');
    $cid = (int)$u['company_id'];
    $o = pk_row($cid, $id);
    if (!in_array($o['status'], ['Draft', 'Ready'], true)) error_response('The box size is decided before packing starts (this order is ' . $o['status'] . ')', 409);
    $spec = pk_spec($cid, (int)$o['item_id'], $o['customer_id'] !== null ? (int)$o['customer_id'] : null);
    $kg = $b['box_kg'] ?? null;
    if (!is_numeric($kg) || !in_array(round((float)$kg, 3), $spec['sizes'], true)) error_response('Choose one of the allowed box sizes: ' . implode(', ', $spec['sizes']) . ' kg', 422);
    $kg = round((float)$kg, 3);
    db()->prepare("UPDATE pack_orders SET box_kg = ?, status = 'Ready', decided_by = ?, decided_at = NOW(), boxes_per_carton = ?, cartons_per_pallet = ?, spec_id = ?, planned_boxes = ? WHERE id = ?")
        ->execute([$kg, $u['user_id'], $spec['boxes_per_carton'], $spec['cartons_per_pallet'], $spec['id'], (int)ceil((float)$o['required_kg'] / $kg), $id]);
    return pk_detail($cid, $id);
}

/** the packer takes the stock out: grade A, oldest expiry first, the batches reserved for this client order first, other orders' reservations left alone */
function pk_start(array $u, int $id): array {
    pk_need($u, 'edit');
    $cid = (int)$u['company_id'];
    $o = pk_row($cid, $id);
    if ($o['status'] !== 'Ready') error_response('The head of preparation must decide the box size first (this order is ' . $o['status'] . ')', 409);
    $kg = round((int)$o['planned_boxes'] * (float)$o['box_kg'], 3);
    db()->beginTransaction();
    try {
        $res = inv_issue($u, ['item_id' => (int)$o['item_id'], 'qty' => $kg, 'grade' => $o['grade'], 'branch_id' => $o['branch_id'] !== null ? (int)$o['branch_id'] : null, 'honor_reservations' => true,
                              'reserve_order_id' => (int)($o['client_order_id'] ?? 0), 'reason' => 'Packing ' . $o['number'], 'ref_type' => 'pack_order', 'ref_id' => $id], 'issue');
        $info = db()->prepare('SELECT unit_cost FROM item_batches WHERE id = ?');
        $ins = db()->prepare('INSERT INTO pack_order_batches (order_id, batch_id, kg, unit_cost) VALUES (?,?,?,?)');
        $cost = 0.0;
        foreach ($res['allocations'] as $a) {
            $info->execute([$a['batch_id']]); $uc = $info->fetchColumn(); $uc = $uc !== false && $uc !== null ? (float)$uc : null;
            $ins->execute([$id, $a['batch_id'], $a['qty'], $uc]);
            $cost += $a['qty'] * ($uc ?? 0);
        }
        if (!empty($o['client_order_id'])) {   // what the client order had on hold for this item has now been taken out
            db()->prepare("UPDATE stock_reservations SET status = 'Released', released_at = NOW() WHERE order_id = ? AND item_id = ? AND status = 'Active'")->execute([$o['client_order_id'], $o['item_id']]);
            db()->prepare('UPDATE client_order_lines SET reserved_kg = 0 WHERE order_id = ? AND item_id = ?')->execute([$o['client_order_id'], $o['item_id']]);
        }
        db()->prepare("UPDATE pack_orders SET status = 'In_Progress', issued_kg = ?, cost_total = ?, started_by = ?, started_at = NOW() WHERE id = ?")->execute([$kg, round($cost, 2), $u['user_id'], $id]);
        db()->commit();
    } catch (Throwable $e) { if (db()->inTransaction()) db()->rollBack(); throw $e; }
    return pk_detail($cid, $id);
}

/** the packer reports how many boxes came out: cartons and pallets are created with their codes */
function pk_pack(array $u, int $id, array $b): array {
    pk_need($u, 'edit');
    $cid = (int)$u['company_id'];
    $o = pk_row($cid, $id);
    if ($o['status'] !== 'In_Progress') error_response('Start the packing first (this order is ' . $o['status'] . ')', 409);
    $boxes = $b['boxes_packed'] ?? null;
    if (!is_numeric($boxes) || (int)$boxes != $boxes || (int)$boxes <= 0) error_response('boxes_packed must be a whole number greater than zero', 422);
    $boxes = (int)$boxes;
    $loss = isset($b['loss_kg']) && $b['loss_kg'] !== '' ? (float)$b['loss_kg'] : 0.0;
    if ($loss < 0) error_response('loss_kg cannot be negative', 422);
    $issued = (float)$o['issued_kg']; $boxKg = (float)$o['box_kg'];
    $tol = (float)inv_setting($cid, 'packing.balance_tolerance_pct', '2');
    if (abs($issued - ($boxes * $boxKg + $loss)) > $issued * $tol / 100 + 0.0005) error_response("The boxes and the loss must add up to the $issued kg that was issued (within $tol %): $boxes x $boxKg + $loss = " . round($boxes * $boxKg + $loss, 3), 422);
    $perCtn = max(1, (int)$o['boxes_per_carton']); $perPal = max(1, (int)$o['cartons_per_pallet']);
    $full = intdiv($boxes, $perCtn); $rest = $boxes % $perCtn;
    $cartonsTotal = $full + ($rest > 0 ? 1 : 0);
    $pallets = (int)ceil($cartonsTotal / $perPal);
    db()->beginTransaction();
    try {
        $ins = db()->prepare('INSERT INTO pack_units (company_id, order_id, kind, code, parent_id, boxes, kg, is_partial) VALUES (?,?,?,?,?,?,?,?)');
        $palIds = [];
        for ($p = 1; $p <= $pallets; $p++) {
            $inThis = min($perPal, $cartonsTotal - ($p - 1) * $perPal);
            $pb = 0; for ($c = 0; $c < $inThis; $c++) { $idx = ($p - 1) * $perPal + $c + 1; $pb += ($idx <= $full) ? $perCtn : $rest; }
            $ins->execute([$cid, $id, 'pallet', 'PAL-' . $o['number'] . '-' . str_pad((string)$p, 2, '0', STR_PAD_LEFT), null, $pb, round($pb * $boxKg, 3), 0]);
            $palIds[$p] = (int)db()->lastInsertId();
        }
        for ($c = 1; $c <= $cartonsTotal; $c++) {
            $inBox = $c <= $full ? $perCtn : $rest;
            $ins->execute([$cid, $id, 'carton', 'CTN-' . $o['number'] . '-' . str_pad((string)$c, 3, '0', STR_PAD_LEFT), $palIds[(int)ceil($c / $perPal)], $inBox, round($inBox * $boxKg, 3), $inBox < $perCtn ? 1 : 0]);
        }
        db()->prepare("UPDATE pack_orders SET status = 'Packed', boxes_packed = ?, loss_kg = ?, cartons = ?, pallets = ?, packed_by = ?, packed_at = NOW() WHERE id = ?")->execute([$boxes, round($loss, 3), $cartonsTotal, $pallets, $u['user_id'], $id]);
        db()->commit();
    } catch (Throwable $e) { if (db()->inTransaction()) db()->rollBack(); throw $e; }
    return pk_detail($cid, $id);
}

function pk_cancel(array $u, int $id, array $b): array {
    $cid = (int)$u['company_id'];
    $o = pk_row($cid, $id);
    if (!in_array($o['status'], ['Draft', 'Ready'], true)) error_response('Packing already started: it cannot be cancelled here (this order is ' . $o['status'] . ')', 409);
    $reason = pk_text($b['reason'] ?? null);
    if (!$reason) error_response('A reason is required', 422);
    db()->prepare("UPDATE pack_orders SET status = 'Cancelled', cancel_reason = ? WHERE id = ?")->execute([$reason, $id]);
    return pk_detail($cid, $id);
}

/** trace a carton or pallet code back to the order, the client and the batches it came from */
function pk_trace(int $cid, string $code): array {
    $q = db()->prepare('SELECT u.id, u.kind, u.code, u.boxes, u.kg, u.status, u.order_id FROM pack_units u WHERE u.company_id = ? AND u.code = ?');
    $q->execute([$cid, $code]);
    $u = $q->fetch();
    if (!$u) error_response('Code not found', 404);
    $o = pk_detail($cid, (int)$u['order_id']);
    return ['unit' => $u, 'order' => ['id' => $o['id'], 'number' => $o['number'], 'customer_name' => $o['customer_name'], 'item_name' => $o['item_name'], 'box_kg' => $o['box_kg'], 'packed_at' => $o['packed_at']], 'batches' => $o['batches']];
}
