<?php
// ─── Alanad ERP — receiving, weighing and quality engine (Phase 3) ───
// A delivery follows a truck from the gate to the cold room (tested in tests/phase4_test.php):
//   Arrived  -> gross weight, truck air + product temperature
//   Temp_Hold  temperature out of range: only a manager may accept (with a note) or reject
//   Checking -> sampling, grade A/B/C and rejected kg per item (a reject needs a reason), tare weight => net weight
//   Ready    -> quality sign-off (maker != checker); the lines add up to the net weight
//   Stored   -> put-away: every accepted line enters a cold room as a new batch through the stock ledger
//   Rejected -> the whole truck goes back, with a reason (counts against the supplier)
require_once __DIR__ . '/inventory.php';
require_once __DIR__ . '/workflow.php';
require_once __DIR__ . '/crud.php';

function rcv_need(array $u, string $action): void {
    if (!permission_allows($u['permissions'], 'deliveries', $action)) error_response("Forbidden: you do not have permission to $action deliveries", 403);
}
function rcv_row(int $cid, int $id): array {
    $st = db()->prepare('SELECT * FROM deliveries WHERE id = ? AND company_id = ?');
    $st->execute([$id, $cid]);
    $r = $st->fetch();
    if (!$r || !branch_in_scope($r['branch_id'] !== null ? (int)$r['branch_id'] : null)) error_response('Not found', 404);
    return $r;
}
function rcv_open(array $d, array $allowed): void {
    if (!in_array($d['status'], $allowed, true)) error_response('This delivery is ' . $d['status'] . ': that step is not possible now', 409);
}
function rcv_num(mixed $v, string $name, bool $allowZero = false): float {
    if (!is_numeric($v) || (float)$v < 0 || (!$allowZero && (float)$v <= 0)) error_response("$name must be a " . ($allowZero ? 'non-negative' : 'positive') . ' number', 422);
    return (float)$v;
}
function rcv_temp(mixed $v, string $name): float {
    if (!is_numeric($v) || (float)$v < -40 || (float)$v > 80) error_response("$name must be a temperature in °C", 422);
    return (float)$v;
}

/** the limits that apply to the truck of this delivery */
function rcv_limits(int $cid, int $poId): array {
    $q = db()->prepare('SELECT MIN(i.storage_temp_max) FROM purchase_order_lines l JOIN items i ON i.id = l.item_id WHERE l.purchase_order_id = ? AND i.storage_temp_max IS NOT NULL');
    $q->execute([$poId]);
    $itemMax = $q->fetchColumn();
    return [
        'truck_air_max' => (float)inv_setting($cid, 'receiving.truck_air_max', '8'),
        'product_temp_max' => $itemMax !== false && $itemMax !== null ? (float)$itemMax : (float)inv_setting($cid, 'receiving.product_temp_max', '8'),
        'product_temp_tolerance' => (float)inv_setting($cid, 'receiving.product_temp_tolerance', '2'),
        'net_tolerance_pct' => (float)inv_setting($cid, 'receiving.net_tolerance_pct', '2'),
        'max_wait_minutes' => (int)inv_setting($cid, 'receiving.max_wait_minutes', '60'),
    ];
}
function rcv_reason(int $cid, mixed $code, ?string $note): string {
    $code = (string)$code;
    $q = db()->prepare("SELECT 1 FROM reject_reasons WHERE company_id = ? AND code = ? AND gate IN ('receiving','general') AND is_active = 1 LIMIT 1");
    $q->execute([$cid, $code]);
    if (!$q->fetchColumn()) error_response('Choose a reject reason from the list', 422);
    if ($code === 'other' && trim((string)$note) === '') error_response('A note is required when the reason is "other"', 422);
    return $code;
}

function rcv_lines(int $id): array {
    $q = db()->prepare('SELECT l.*, i.name AS item_name, i.name_ar AS item_name_ar, i.shelf_life_days FROM delivery_lines l JOIN items i ON i.id = l.item_id WHERE l.delivery_id = ? ORDER BY l.id');
    $q->execute([$id]);
    return array_map(function ($r) {
        foreach (['received_kg', 'rejected_kg'] as $f) $r[$f] = (float)$r[$f];
        $r['sample_kg'] = $r['sample_kg'] !== null ? (float)$r['sample_kg'] : null;
        $r['accepted_kg'] = round($r['received_kg'] - $r['rejected_kg'], 3);
        return $r;
    }, $q->fetchAll());
}

function rcv_detail(int $cid, int $id): array {
    $d = rcv_row($cid, $id);
    foreach (['gross_kg', 'tare_kg', 'net_kg', 'truck_air_temp', 'product_temp'] as $f) if ($d[$f] !== null) $d[$f] = (float)$d[$f];
    $d['lines'] = rcv_lines($id);
    $d['po'] = inv_po_status($cid, (int)$d['purchase_order_id']);
    $d['limits'] = rcv_limits($cid, (int)$d['purchase_order_id']);
    if (function_exists('rcv3_info')) $d['three'] = rcv3_info($cid, $d);
    $open = !in_array($d['status'], ['Stored', 'Rejected'], true);
    $mw = db()->prepare('SELECT TIMESTAMPDIFF(MINUTE, arrived_at, NOW()) FROM deliveries WHERE id = ?');   // in SQL: the database clock stamped the arrival
    $mw->execute([$id]);
    $d['minutes_waiting'] = $open ? max(0, (int)$mw->fetchColumn()) : null;
    $d['waiting_too_long'] = $open && $d['minutes_waiting'] > $d['limits']['max_wait_minutes'];
    return $d;
}

function rcv_create(array $u, array $b): array {
    $cid = (int)$u['company_id'];
    $po = db()->prepare('SELECT id, status, supplier_id, branch_id FROM purchase_orders WHERE id = ? AND company_id = ?');
    $po->execute([(int)($b['po_id'] ?? 0), $cid]);
    $po = $po->fetch();
    if (!$po) error_response('Purchase order not found', 404);
    if ($po['status'] !== 'Approved') error_response('Only an approved purchase order can be delivered (this one is ' . $po['status'] . ')', 409);
    // entry permit: required when the company switches the rule on; a given permit must be issued for this very order
    $permit = null;
    if (!empty($b['entry_permit_id'])) {
        $pq = db()->prepare('SELECT * FROM entry_permits WHERE id = ? AND company_id = ?'); $pq->execute([(int)$b['entry_permit_id'], $cid]);
        $permit = $pq->fetch();
        if (!$permit) error_response('Entry permit not found', 404);
        if ($permit['status'] !== 'Issued') error_response('This entry permit is ' . $permit['status'] . ': only an issued permit lets a truck in', 409);
        if ((int)$permit['po_id'] !== (int)$po['id']) error_response('This entry permit is for another purchase order', 409);
        $b['driver_name'] = $permit['driver_name']; $b['driver_id_no'] = $permit['driver_id_no']; $b['vehicle_plate'] = $permit['vehicle_plate'];
    } elseif (inv_setting($cid, 'fleet.require_entry_permit', '0') === '1') {
        error_response('The truck needs an issued entry permit before it can be registered at the gate', 409);
    }
    $driver = trim((string)($b['driver_name'] ?? ''));
    if ($driver === '') error_response('The driver name is required', 422);
    $gross = isset($b['gross_kg']) && $b['gross_kg'] !== '' ? rcv_num($b['gross_kg'], 'gross_kg') : null;
    $number = crud_next_number('RC', 'deliveries', $cid);
    db()->prepare('INSERT INTO deliveries (company_id, number, purchase_order_id, supplier_id, driver_name, driver_id_no, vehicle_plate, status, arrived_at, gross_kg, notes, created_by, branch_id) VALUES (?,?,?,?,?,?,?,?,NOW(),?,?,?,?)')
        ->execute([$cid, $number, $po['id'], $po['supplier_id'], substr($driver, 0, 120), isset($b['driver_id_no']) ? substr((string)$b['driver_id_no'], 0, 40) : null,
                   isset($b['vehicle_plate']) ? substr((string)$b['vehicle_plate'], 0, 30) : null, 'Arrived', $gross, $b['notes'] ?? null, $u['user_id'], $po['branch_id'] ?? branch_default($cid)]);
    $newId = (int)db()->lastInsertId();
    if ($permit) {
        db()->prepare('UPDATE deliveries SET entry_permit_id = ? WHERE id = ?')->execute([$permit['id'], $newId]);
        db()->prepare("UPDATE entry_permits SET status = 'Entered', delivery_id = ?, entered_at = NOW() WHERE id = ?")->execute([$newId, $permit['id']]);
    }
    return rcv_detail($cid, $newId);
}

function rcv_weigh_in(array $u, int $id, array $b): array {
    $cid = (int)$u['company_id'];
    $d = rcv_row($cid, $id);
    rcv_open($d, ['Arrived', 'Temp_Hold', 'Checking']);
    db()->prepare('UPDATE deliveries SET gross_kg = ? WHERE id = ?')->execute([rcv_num($b['gross_kg'] ?? null, 'gross_kg'), $id]);
    return rcv_detail($cid, $id);
}

function rcv_temperature(array $u, int $id, array $b): array {
    $cid = (int)$u['company_id'];
    $d = rcv_row($cid, $id);
    rcv_open($d, ['Arrived', 'Temp_Hold', 'Checking']);
    $air = rcv_temp($b['truck_air_temp'] ?? null, 'truck_air_temp');
    $prod = rcv_temp($b['product_temp'] ?? null, 'product_temp');
    $lim = rcv_limits($cid, (int)$d['purchase_order_id']);
    $ok = $air <= $lim['truck_air_max'] + 0.0001 && $prod <= $lim['product_temp_max'] + $lim['product_temp_tolerance'] + 0.0001;
    db()->prepare('UPDATE deliveries SET truck_air_temp = ?, product_temp = ?, temp_ok = ?, temp_checked_by = ?, temp_decision_by = NULL, temp_decision_note = NULL, status = ? WHERE id = ?')
        ->execute([$air, $prod, $ok ? 1 : 0, $u['user_id'], $ok ? 'Checking' : 'Temp_Hold', $id]);
    return rcv_detail($cid, $id);
}

function rcv_temp_decision(array $u, int $id, array $b): array {
    rcv_need($u, 'approve');
    $cid = (int)$u['company_id'];
    $d = rcv_row($cid, $id);
    rcv_open($d, ['Temp_Hold']);
    $note = trim((string)($b['note'] ?? ''));
    if ($note === '') error_response('A note is required for the temperature decision', 422);
    if ((int)$d['temp_checked_by'] === (int)$u['user_id'] && wf_setting($cid, 'workflow.maker_not_checker', '1') === '1') {
        error_response('You recorded this temperature: another person must decide', 403);
    }
    $action = $b['decision'] ?? '';
    if ($action === 'accept') {
        db()->prepare("UPDATE deliveries SET status = 'Checking', temp_decision_by = ?, temp_decision_note = ? WHERE id = ?")->execute([$u['user_id'], substr($note, 0, 255), $id]);
    } elseif ($action === 'reject') {
        db()->prepare("UPDATE deliveries SET status = 'Rejected', reject_reason_code = 'temperature', reject_note = ?, rejected_by = ?, rejected_at = NOW(), temp_decision_by = ?, temp_decision_note = ? WHERE id = ?")
            ->execute([substr($note, 0, 255), $u['user_id'], $u['user_id'], substr($note, 0, 255), $id]);
    } else error_response('decision must be accept or reject', 422);
    return rcv_detail($cid, $id);
}

function rcv_quality(array $u, int $id, array $b): array {
    $cid = (int)$u['company_id'];
    $d = rcv_row($cid, $id);
    rcv_open($d, ['Checking']);
    if (function_exists('rcv3_guard_quality')) rcv3_guard_quality($u, $d);
    if ($d['temp_ok'] === null) error_response('Record the temperature first', 409);
    $lines = $b['lines'] ?? null;
    if (!is_array($lines) || !$lines) error_response('Add at least one weighed item', 422);
    $po = inv_po_status($cid, (int)$d['purchase_order_id']);
    $onOrder = []; foreach ($po['lines'] as $l) $onOrder[$l['item_id']] = true;
    $clean = [];
    foreach ($lines as $ln) {
        if (!is_array($ln)) error_response('Invalid line', 422);
        $item = (int)($ln['item_id'] ?? 0);
        if (!isset($onOrder[$item])) error_response("Item $item is not on the purchase order", 422);
        $grade = $ln['grade'] ?? '';
        if (!in_array($grade, ['A', 'B', 'C'], true)) error_response('grade must be A, B or C', 422);
        $recv = rcv_num($ln['received_kg'] ?? null, 'received_kg');
        $rej = rcv_num($ln['rejected_kg'] ?? 0, 'rejected_kg', true);
        if ($rej > $recv + 0.0005) error_response('Rejected kg cannot be more than the weighed kg', 422);
        $reason = null;
        if ($rej > 0) $reason = rcv_reason($cid, $ln['reject_reason_code'] ?? '', $ln['note'] ?? null);
        $sample = isset($ln['sample_kg']) && $ln['sample_kg'] !== '' ? rcv_num($ln['sample_kg'], 'sample_kg') : null;
        $clean[] = [$item, $grade, round($recv, 3), round($rej, 3), $reason, $sample, isset($ln['note']) ? substr((string)$ln['note'], 0, 255) : null];
    }
    db()->beginTransaction();
    try {
        db()->prepare('DELETE FROM delivery_lines WHERE delivery_id = ?')->execute([$id]);
        foreach ($clean as $c) {
            db()->prepare('INSERT INTO delivery_lines (delivery_id, item_id, grade, received_kg, rejected_kg, reject_reason_code, sample_kg, note) VALUES (?,?,?,?,?,?,?,?)')->execute(array_merge([$id], $c));
        }
        // the lines changed: the weigh-out has to be checked against them again
        db()->prepare('UPDATE deliveries SET quality_by = ?, quality_at = NOW(), tare_kg = NULL, net_kg = NULL WHERE id = ?')->execute([$u['user_id'], $id]);
        db()->commit();
    } catch (Throwable $e) { if (db()->inTransaction()) db()->rollBack(); throw $e; }
    return rcv_detail($cid, $id);
}

function rcv_weigh_out(array $u, int $id, array $b): array {
    $cid = (int)$u['company_id'];
    $d = rcv_row($cid, $id);
    rcv_open($d, ['Checking']);
    if ($d['gross_kg'] === null) error_response('Weigh the truck in first (gross weight)', 409);
    $lines = rcv_lines($id);
    if (!$lines) error_response('Record the quality lines before the weigh-out', 409);
    $tare = rcv_num($b['tare_kg'] ?? null, 'tare_kg', true);
    $net = round((float)$d['gross_kg'] - $tare, 3);
    if ($net <= 0) error_response('Tare weight must be lower than the gross weight', 422);
    $sum = array_sum(array_column($lines, 'received_kg'));
    $tol = rcv_limits($cid, (int)$d['purchase_order_id'])['net_tolerance_pct'];
    if (abs($net - $sum) > $net * $tol / 100 + 0.0005) {
        error_response("Net weight $net kg does not match the weighed items $sum kg (allowed gap $tol %)", 422);
    }
    db()->prepare('UPDATE deliveries SET tare_kg = ?, net_kg = ? WHERE id = ?')->execute([$tare, $net, $id]);
    return rcv_detail($cid, $id);
}

/** quantity that would exceed the order (accepted kg per item, on top of what is already stored) */
function rcv_check_order_limit(int $cid, array $d, array $lines): void {
    $po = inv_po_status($cid, (int)$d['purchase_order_id']);
    $tol = max(0.0, (float)inv_setting($cid, 'purchase.receive_tolerance_pct', '10'));
    $byItem = []; foreach ($po['lines'] as $l) $byItem[$l['item_id']] = $l;
    $add = [];
    foreach ($lines as $l) if (empty($l['stored_at'])) $add[$l['item_id']] = ($add[$l['item_id']] ?? 0) + $l['accepted_kg'];
    foreach ($add as $item => $kg) {
        $o = $byItem[$item];
        if ($o['received'] + $kg > $o['ordered'] * (1 + $tol / 100) + 0.0005) {
            error_response("More than ordered for {$o['item_name']}: ordered {$o['ordered']} kg, already stored {$o['received']} kg, this truck {$kg} kg (tolerance $tol %). Reject the excess or ask Purchasing to raise the order.", 422);
        }
    }
}

function rcv_accept(array $u, int $id): array {
    rcv_need($u, 'approve');
    $cid = (int)$u['company_id'];
    $d = rcv_row($cid, $id);
    rcv_open($d, ['Checking']);
    if (function_exists('rcv3_guard_quality')) rcv3_guard_quality($u, $d);
    if ($d['net_kg'] === null) error_response('Weigh the truck out first (tare weight)', 409);
    $lines = rcv_lines($id);
    if (!$lines) error_response('There are no quality lines', 409);
    if ((int)$d['created_by'] === (int)$u['user_id'] && wf_setting($cid, 'workflow.maker_not_checker', '1') === '1') {
        error_response('You registered this arrival: someone else must sign off the quality', 403);
    }
    if (array_sum(array_column($lines, 'accepted_kg')) <= 0.0005) error_response('Everything was rejected: reject the whole delivery instead', 422);
    rcv_check_order_limit($cid, $d, $lines);
    db()->prepare("UPDATE deliveries SET status = 'Ready', accepted_by = ?, accepted_at = NOW() WHERE id = ?")->execute([$u['user_id'], $id]);
    return rcv_detail($cid, $id);
}

function rcv_reject(array $u, int $id, array $b): array {
    rcv_need($u, 'approve');
    $cid = (int)$u['company_id'];
    $d = rcv_row($cid, $id);
    rcv_open($d, ['Arrived', 'Temp_Hold', 'Checking', 'Ready', 'To_Sorting']);
    $code = rcv_reason($cid, $b['reason_code'] ?? '', $b['note'] ?? null);
    db()->prepare("UPDATE deliveries SET status = 'Rejected', reject_reason_code = ?, reject_note = ?, rejected_by = ?, rejected_at = NOW() WHERE id = ?")
        ->execute([$code, isset($b['note']) ? substr((string)$b['note'], 0, 255) : null, $u['user_id'], $id]);
    return rcv_detail($cid, $id);
}

/** put-away: body {lines:[{line_id, cold_room_id, rack?, position?, expiry_date?}], close_order?} */
function rcv_put_away(array $u, int $id, array $b): array {
    if (!permission_allows($u['permissions'], 'stock', 'create')) error_response('Forbidden: you do not have permission to create stock', 403);
    $cid = (int)$u['company_id'];
    $d = rcv_row($cid, $id);
    rcv_open($d, ['Ready']);
    if (function_exists('rcv3_guard_put_away')) rcv3_guard_put_away($cid, $d);
    $req = $b['lines'] ?? null;
    if (!is_array($req) || !$req) error_response('Choose a cold room for at least one line', 422);
    $lines = []; foreach (rcv_lines($id) as $l) $lines[$l['id']] = $l;
    $todo = [];
    foreach ($req as $r) {
        $lid = (int)($r['line_id'] ?? 0);
        if (!isset($lines[$lid])) error_response("Line $lid is not part of this delivery", 422);
        if ($lines[$lid]['stored_at'] !== null) error_response('Line ' . $lid . ' is already stored', 409);
        $todo[$lid] = $r;
    }
    $mine = array_intersect_key($lines, $todo);
    rcv_check_order_limit($cid, $d, $mine);
    foreach ($todo as $lid => $r) {
        $l = $lines[$lid];
        if ($l['accepted_kg'] <= 0.0005) {   // everything on this line was rejected: nothing to store
            db()->prepare('UPDATE delivery_lines SET stored_at = NOW() WHERE id = ?')->execute([$lid]);
            continue;
        }
        $res = inv_receive($u, [
            'item_id' => (int)$l['item_id'], 'qty' => $l['accepted_kg'], 'cold_room_id' => $r['cold_room_id'] ?? 0, 'expiry_date' => $r['expiry_date'] ?? null,
            'grade' => $l['grade'], 'rack' => $r['rack'] ?? null, 'position' => $r['position'] ?? null, 'arrival_date' => substr($d['arrived_at'], 0, 10),
            'supplier_id' => $d['supplier_id'], 'branch_id' => $d['branch_id'] !== null ? (int)$d['branch_id'] : null, 'ref_type' => 'purchase_order', 'ref_id' => (int)$d['purchase_order_id'], 'reason' => 'Delivery ' . $d['number'],
        ]);
        db()->prepare('UPDATE delivery_lines SET batch_id = ?, cold_room_id = ?, stored_at = NOW() WHERE id = ?')->execute([$res['batch_id'], $res['cold_room_id'], $lid]);
    }
    $left = db()->prepare('SELECT COUNT(*) FROM delivery_lines WHERE delivery_id = ? AND stored_at IS NULL');
    $left->execute([$id]);
    if ((int)$left->fetchColumn() === 0) {
        db()->prepare("UPDATE deliveries SET status = 'Stored' WHERE id = ?")->execute([$id]);
        $po = inv_po_status($cid, (int)$d['purchase_order_id']);
        $complete = true; foreach ($po['lines'] as $l) if ($l['remaining'] > 0.0005) $complete = false;
        if ($complete || !empty($b['close_order'])) {
            db()->prepare("UPDATE purchase_orders SET status = 'Received' WHERE id = ? AND company_id = ?")->execute([(int)$d['purchase_order_id'], $cid]);
        }
    }
    return rcv_detail($cid, $id);
}

/** supplier rating from what the quality check found */
function rcv_supplier_rating(int $cid): array {
    $q = db()->prepare("SELECT s.id AS supplier_id, s.name AS supplier_name,
            COUNT(DISTINCT d.id) AS deliveries,
            COUNT(DISTINCT CASE WHEN d.status = 'Rejected' THEN d.id END) AS rejected_deliveries,
            COALESCE(SUM(l.received_kg), 0) AS received_kg, COALESCE(SUM(l.rejected_kg), 0) AS rejected_kg,
            COALESCE(SUM(CASE WHEN l.grade = 'A' THEN l.received_kg - l.rejected_kg ELSE 0 END), 0) AS grade_a_kg,
            COALESCE(SUM(l.received_kg - l.rejected_kg), 0) AS accepted_kg
          FROM deliveries d JOIN suppliers s ON s.id = d.supplier_id LEFT JOIN delivery_lines l ON l.delivery_id = d.id
          WHERE d.company_id = ? GROUP BY s.id, s.name ORDER BY s.name");
    $q->execute([$cid]);
    return array_map(function ($r) {
        $recv = (float)$r['received_kg']; $acc = (float)$r['accepted_kg'];
        return ['supplier_id' => (int)$r['supplier_id'], 'supplier_name' => $r['supplier_name'], 'deliveries' => (int)$r['deliveries'], 'rejected_deliveries' => (int)$r['rejected_deliveries'],
                'received_kg' => round($recv, 3), 'rejected_kg' => round((float)$r['rejected_kg'], 3), 'reject_pct' => $recv > 0 ? round((float)$r['rejected_kg'] / $recv * 100, 1) : 0.0,
                'grade_a_pct' => $acc > 0 ? round((float)$r['grade_a_kg'] / $acc * 100, 1) : 0.0];
    }, $q->fetchAll());
}
