<?php
// ─── Alanad ERP — inventory & cold-room engine (Phase 1) ─────
// Rules enforced here (and tested in tests/phase1_test.php):
//   • stock only changes through the append-only ledger (stock_ledger); balances are SUM(qty)
//   • every batch has arrival and expiry dates; issuing follows FEFO (earliest expiry first)
//   • expired stock, stock on quality hold and stock in a held cold room cannot be issued
//   • an item may only enter a cold room whose temperature range fits the item and whose compatibility group matches
//   • a cold-room alarm that stays open longer than the limit puts the room on quality hold (released only with a note)

require_once __DIR__ . '/finance.php';

function inv_setting(int $companyId, string $key, string $default): string {
    $st = db()->prepare('SELECT setting_value FROM settings WHERE company_id = ? AND setting_key = ?');
    $st->execute([$companyId, $key]);
    $v = $st->fetchColumn();
    return ($v === false || $v === null) ? $default : (string)$v;
}

function inv_num(mixed $v, string $field): float {
    if (!is_numeric($v)) error_response("$field must be a number", 422);
    return (float)$v;
}

function inv_ledger(int $cid, int $item, int $batch, int $wh, ?int $room, ?string $rack, ?string $pos, string $movement, float $qty, ?string $reason, ?string $refType, ?int $refId, int $userId): int {
    db()->prepare('INSERT INTO stock_ledger (company_id, item_id, batch_id, warehouse_id, cold_room_id, rack, position, movement, qty, reason, ref_type, ref_id, user_id)
                   VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$cid, $item, $batch, $wh, $room, $rack, $pos, $movement, round($qty, 3), $reason, $refType, $refId, $userId]);
    return (int)db()->lastInsertId();
}

function inv_item(int $cid, int $id): array {
    $st = db()->prepare('SELECT * FROM items WHERE id = ? AND company_id = ?');
    $st->execute([$id, $cid]);
    $r = $st->fetch();
    if (!$r) error_response('Item not found', 404);
    return $r;
}

function inv_room(int $cid, int $id): array {
    $st = db()->prepare('SELECT * FROM cold_rooms WHERE id = ? AND company_id = ? AND is_active = 1');
    $st->execute([$id, $cid]);
    $r = $st->fetch();
    if (!$r) error_response('Cold room not found', 404);
    return $r;
}

function inv_balance(int $cid, int $item, int $batch, ?int $room, ?string $rack = null, ?string $pos = null): float {
    $sql = 'SELECT COALESCE(SUM(qty), 0) FROM stock_ledger WHERE company_id = ? AND item_id = ? AND batch_id = ?';
    $p = [$cid, $item, $batch];
    if ($room === null) { $sql .= ' AND cold_room_id IS NULL'; } else { $sql .= ' AND cold_room_id = ?'; $p[] = $room; }
    $sql .= ($rack === null ? ' AND rack IS NULL' : ' AND rack = ?') . ($pos === null ? ' AND position IS NULL' : ' AND position = ?');
    if ($rack !== null) $p[] = $rack;
    if ($pos !== null) $p[] = $pos;
    $st = db()->prepare($sql);
    $st->execute($p);
    return (float)$st->fetchColumn();
}

/** Temperature range of the item must contain the room's range; compatibility groups must match. */
function inv_check_room(int $cid, array $item, array $room): void {
    if (inv_setting($cid, 'stock.enforce_room_rules', '1') !== '1') return;
    if ($room['status'] === 'hold') error_response('This cold room is on quality hold', 409);
    if ($item['storage_temp_min'] !== null && $item['storage_temp_max'] !== null) {
        if ((float)$room['temp_min'] < (float)$item['storage_temp_min'] || (float)$room['temp_max'] > (float)$item['storage_temp_max']) {
            error_response("Temperature mismatch: item needs {$item['storage_temp_min']}..{$item['storage_temp_max']} °C, room runs {$room['temp_min']}..{$room['temp_max']} °C", 422);
        }
    }
    $g = $item['compat_group'];
    if ($g !== null && $g !== '') {
        if ($room['compat_group'] !== null && $room['compat_group'] !== '' && $room['compat_group'] !== $g) {
            error_response("Compatibility group mismatch: item is '$g', room is '{$room['compat_group']}'", 422);
        }
        $st = db()->prepare("SELECT 1 FROM stock_ledger l JOIN items i ON i.id = l.item_id
                             WHERE l.company_id = ? AND l.cold_room_id = ? AND i.compat_group IS NOT NULL AND i.compat_group <> '' AND i.compat_group <> ?
                             GROUP BY l.item_id, l.batch_id HAVING SUM(l.qty) > 0.0005 LIMIT 1");
        $st->execute([$cid, $room['id'], $g]);
        if ($st->fetchColumn()) error_response("This room already holds items of another compatibility group than '$g'", 422);
    }
    if ($room['capacity_kg'] !== null) {
        $st = db()->prepare('SELECT COALESCE(SUM(qty),0) FROM stock_ledger WHERE company_id = ? AND cold_room_id = ?');
        $st->execute([$cid, $room['id']]);
        $GLOBALS['_inv_room_used'] = (float)$st->fetchColumn();
    }
}

function inv_receive(array $u, array $b): array {
    $cid = (int)$u['company_id'];
    $item = inv_item($cid, (int)($b['item_id'] ?? 0));
    $qty = inv_num($b['qty'] ?? null, 'qty');
    if ($qty <= 0) error_response('qty must be greater than zero', 422);
    $room = inv_room($cid, (int)($b['cold_room_id'] ?? 0));
    $arrival = $b['arrival_date'] ?? date('Y-m-d');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $arrival)) error_response('arrival_date must be YYYY-MM-DD', 422);
    $expiry = $b['expiry_date'] ?? null;
    if (!$expiry) {
        if (!$item['shelf_life_days']) error_response('expiry_date is required (the item has no shelf life set)', 422);
        $expiry = date('Y-m-d', strtotime("$arrival +{$item['shelf_life_days']} days"));
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiry)) error_response('expiry_date must be YYYY-MM-DD', 422);
    if ($expiry < $arrival) error_response('expiry_date cannot be before arrival_date', 422);
    $grade = $b['grade'] ?? null;
    if ($grade !== null && !in_array($grade, ['A', 'B', 'C'], true)) error_response('grade must be A, B or C', 422);
    $rack = isset($b['rack']) && $b['rack'] !== '' ? substr((string)$b['rack'], 0, 20) : null;
    $pos = isset($b['position']) && $b['position'] !== '' ? substr((string)$b['position'], 0, 20) : null;

    branch_check_room($room, !empty($b['branch_id']) ? (int)$b['branch_id'] : null);   // stock enters a cold room of the right branch only
    inv_check_room($cid, $item, $room);
    if ($room['capacity_kg'] !== null && ($GLOBALS['_inv_room_used'] ?? 0) + $qty > (float)$room['capacity_kg'] + 0.0005) {
        error_response('Not enough free capacity in this cold room', 409);
    }

    $own = !db()->inTransaction();   // production wraps several stock moves and postings in one transaction
    if ($own) db()->beginTransaction();
    try {
        $batchNo = trim((string)($b['batch_no'] ?? ''));
        if ($batchNo === '') {
            $st = db()->prepare('SELECT COUNT(*) FROM item_batches WHERE company_id = ? AND arrival_date = ?');
            $st->execute([$cid, $arrival]);
            $batchNo = 'B' . str_replace('-', '', $arrival) . '-' . str_pad((string)((int)$st->fetchColumn() + 1), 3, '0', STR_PAD_LEFT);
        }
        $unitCost = isset($b['unit_cost']) && is_numeric($b['unit_cost']) ? round((float)$b['unit_cost'], 4)
            : ((($b['ref_type'] ?? null) === 'purchase_order' && !empty($b['ref_id'])) ? fin_po_rate((int)$b['ref_id'], (int)$item['id']) : null);
        db()->prepare('INSERT INTO item_batches (company_id, item_id, batch_no, supplier_id, arrival_date, harvest_date, expiry_date, grade, qty_received, unit_cost, prep_order_id) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$cid, $item['id'], $batchNo, $b['supplier_id'] ?? null, $arrival, $b['harvest_date'] ?? null, $expiry, $grade, round($qty, 3), $unitCost, ($b['ref_type'] ?? null) === 'prep_order' ? (int)$b['ref_id'] : null]);
        $batchId = (int)db()->lastInsertId();
        inv_ledger($cid, (int)$item['id'], $batchId, (int)$room['warehouse_id'], (int)$room['id'], $rack, $pos, 'receipt', $qty, $b['reason'] ?? null, $b['ref_type'] ?? null, isset($b['ref_id']) ? (int)$b['ref_id'] : null, (int)$u['user_id']);
        // stored goods of a purchase order are booked to the ledger in the same transaction (Dr Inventory / Cr Goods received not invoiced)
        if (($b['ref_type'] ?? null) === 'purchase_order' && !empty($b['ref_id'])) fin_post_receipt($cid, (int)$b['ref_id'], (int)$item['id'], $qty, $batchId, $arrival, (int)$u['user_id']);
        if ($own) db()->commit();
    } catch (PDOException $e) {
        if ($own && db()->inTransaction()) db()->rollBack();
        if ((int)($e->errorInfo[1] ?? 0) === 1062) error_response('Batch number already exists', 409);
        throw $e;
    }
    return ['batch_id' => $batchId, 'batch_no' => $batchNo, 'item_id' => (int)$item['id'], 'cold_room_id' => (int)$room['id'], 'qty' => $qty, 'arrival_date' => $arrival, 'expiry_date' => $expiry];
}

/** stock lines (batch x room x rack x position) with a positive balance, FEFO order. */
function inv_candidates(int $cid, int $itemId, ?int $roomId, bool $allowExpired, ?array $branches = null): array {
    $today = date('Y-m-d');
    // only the stock of the branch(es) in question: the order's branch, else the branch(es) the caller works in
    $bsql = $branches !== null ? ' AND l.warehouse_id IN (SELECT id FROM warehouses WHERE branch_id IN (' . ($branches ? implode(',', array_map('intval', $branches)) : '0') . '))' : branch_wh_sql('l.warehouse_id');
    $sql = "SELECT l.batch_id, l.warehouse_id, l.cold_room_id, l.rack, l.position, SUM(l.qty) AS bal, b.batch_no, b.expiry_date, b.arrival_date, b.grade,
                   r.status AS room_status,
                   (SELECT COUNT(*) FROM quality_holds h WHERE h.company_id = l.company_id AND h.status = 'active' AND h.batch_id = l.batch_id) AS batch_holds
            FROM stock_ledger l
            JOIN item_batches b ON b.id = l.batch_id
            LEFT JOIN cold_rooms r ON r.id = l.cold_room_id
            WHERE l.company_id = ? AND l.item_id = ?" . ($roomId ? ' AND l.cold_room_id = ?' : '') . $bsql . "
            GROUP BY l.company_id, l.batch_id, l.warehouse_id, l.cold_room_id, l.rack, l.position, b.batch_no, b.expiry_date, b.arrival_date, b.grade, r.status
            HAVING SUM(l.qty) > 0.0005
            ORDER BY b.expiry_date ASC, b.arrival_date ASC, l.batch_id ASC";
    $st = db()->prepare($sql);
    $st->execute($roomId ? [$cid, $itemId, $roomId] : [$cid, $itemId]);
    $out = ['usable' => [], 'blocked_hold' => 0.0, 'blocked_expired' => 0.0];
    foreach ($st->fetchAll() as $r) {
        if ($r['room_status'] === 'hold' || (int)$r['batch_holds'] > 0) { $out['blocked_hold'] += (float)$r['bal']; continue; }
        if (!$allowExpired && $r['expiry_date'] < $today) { $out['blocked_expired'] += (float)$r['bal']; continue; }
        $out['usable'][] = $r;
    }
    return $out;
}

/** Issue / waste with FEFO. $movement: issue | waste */
function inv_issue(array $u, array $b, string $movement = 'issue'): array {
    $cid = (int)$u['company_id'];
    $item = inv_item($cid, (int)($b['item_id'] ?? 0));
    $qty = inv_num($b['qty'] ?? null, 'qty');
    if ($qty <= 0) error_response('qty must be greater than zero', 422);
    if ($movement === 'waste' && trim((string)($b['reason'] ?? '')) === '') error_response('A reason is required for waste', 422);
    $roomId = !empty($b['cold_room_id']) ? (int)$b['cold_room_id'] : null;
    $allowExpired = inv_setting($cid, 'stock.allow_expired_issue', '0') === '1' || $movement === 'waste';   // expired stock may always be written off

    $own = !db()->inTransaction();
    if ($own) db()->beginTransaction();
    try {
        // serialise concurrent issues of the same item
        $lock = db()->prepare('SELECT id FROM items WHERE id = ? AND company_id = ? FOR UPDATE');
        $lock->execute([$item['id'], $cid]);
        $c = inv_candidates($cid, (int)$item['id'], $roomId, $allowExpired, !empty($b['branch_id']) ? [(int)$b['branch_id']] : null);
        if (!empty($b['grade'])) $c['usable'] = array_values(array_filter($c['usable'], fn($r) => $r['grade'] === $b['grade']));
        if (!empty($b['honor_reservations'])) {      // stock held for OTHER client orders is not free; the batches held for this order are used first
            $ordId = (int)($b['reserve_order_id'] ?? 0);
            $hq = db()->prepare("SELECT batch_id, SUM(CASE WHEN order_id = ? THEN qty ELSE 0 END) AS mine, SUM(CASE WHEN order_id <> ? THEN qty ELSE 0 END) AS others FROM stock_reservations WHERE company_id = ? AND status = 'Active' GROUP BY batch_id");
            $hq->execute([$ordId, $ordId, $cid]);
            $held = []; foreach ($hq->fetchAll() as $h) $held[(int)$h['batch_id']] = [(float)$h['mine'], (float)$h['others']];
            $mine = []; $rest = [];
            foreach ($c['usable'] as $r) {
                [$m, $o] = $held[(int)$r['batch_id']] ?? [0.0, 0.0];
                $r['bal'] = max(0.0, (float)$r['bal'] - $o);
                if ($r['bal'] <= 0.0005) continue;
                if ($m > 0) $mine[] = $r; else $rest[] = $r;
            }
            $c['usable'] = array_merge($mine, $rest);
        }
        $available = array_sum(array_map(fn($r) => (float)$r['bal'], $c['usable']));
        if ($available + 0.0005 < $qty) {
            if ($own) db()->rollBack();
            $msg = 'Not enough usable stock: available ' . round($available, 3) . ', requested ' . round($qty, 3);
            if ($c['blocked_hold'] > 0) $msg .= ' (' . round($c['blocked_hold'], 3) . ' is on quality hold)';
            if ($c['blocked_expired'] > 0) $msg .= ' (' . round($c['blocked_expired'], 3) . ' is expired)';
            error_response($msg, 409);
        }
        $left = $qty; $alloc = [];
        foreach ($c['usable'] as $r) {
            if ($left <= 0.0005) break;
            $take = min($left, (float)$r['bal']);
            inv_ledger($cid, (int)$item['id'], (int)$r['batch_id'], (int)$r['warehouse_id'], $r['cold_room_id'] !== null ? (int)$r['cold_room_id'] : null,
                       $r['rack'], $r['position'], $movement, -$take, $b['reason'] ?? null, $b['ref_type'] ?? null, isset($b['ref_id']) ? (int)$b['ref_id'] : null, (int)$u['user_id']);
            $alloc[] = ['batch_id' => (int)$r['batch_id'], 'batch_no' => $r['batch_no'], 'expiry_date' => $r['expiry_date'], 'cold_room_id' => $r['cold_room_id'] !== null ? (int)$r['cold_room_id'] : null, 'rack' => $r['rack'], 'position' => $r['position'], 'qty' => round($take, 3)];
            $left -= $take;
        }
        if ($own) db()->commit();
    } catch (Throwable $e) {
        if ($own && db()->inTransaction()) db()->rollBack();
        throw $e;
    }
    return ['item_id' => (int)$item['id'], 'qty' => round($qty, 3), 'allocations' => $alloc];
}

function inv_transfer(array $u, array $b): array {
    $cid = (int)$u['company_id'];
    $batchId = (int)($b['batch_id'] ?? 0);
    $qty = inv_num($b['qty'] ?? null, 'qty');
    if ($qty <= 0) error_response('qty must be greater than zero', 422);
    $st = db()->prepare('SELECT * FROM item_batches WHERE id = ? AND company_id = ?');
    $st->execute([$batchId, $cid]);
    $batch = $st->fetch();
    if (!$batch) error_response('Batch not found', 404);
    $item = inv_item($cid, (int)$batch['item_id']);
    $from = inv_room($cid, (int)($b['from_cold_room_id'] ?? 0));
    $to = inv_room($cid, (int)($b['to_cold_room_id'] ?? 0));
    branch_check_room($from); branch_check_room($to);
    if ((int)$from['branch_id'] !== (int)$to['branch_id']) error_response('Transfers between branches are not supported yet: both cold rooms must be in the same branch', 422);
    if ($from['id'] === $to['id'] && ($b['from_rack'] ?? null) === ($b['to_rack'] ?? null) && ($b['from_position'] ?? null) === ($b['to_position'] ?? null)) error_response('Source and destination are the same', 422);
    if ($from['status'] === 'hold') error_response('The source cold room is on quality hold', 409);
    $hold = db()->prepare("SELECT 1 FROM quality_holds WHERE company_id = ? AND status = 'active' AND batch_id = ? LIMIT 1");
    $hold->execute([$cid, $batchId]);
    if ($hold->fetchColumn()) error_response('This batch is on quality hold', 409);
    inv_check_room($cid, $item, $to);
    $fr = isset($b['from_rack']) && $b['from_rack'] !== '' ? (string)$b['from_rack'] : null;
    $fp = isset($b['from_position']) && $b['from_position'] !== '' ? (string)$b['from_position'] : null;
    $tr = isset($b['to_rack']) && $b['to_rack'] !== '' ? (string)$b['to_rack'] : null;
    $tp = isset($b['to_position']) && $b['to_position'] !== '' ? (string)$b['to_position'] : null;

    db()->beginTransaction();
    try {
        $lock = db()->prepare('SELECT id FROM item_batches WHERE id = ? FOR UPDATE'); $lock->execute([$batchId]);
        $bal = inv_balance($cid, (int)$item['id'], $batchId, (int)$from['id'], $fr, $fp);
        if ($bal + 0.0005 < $qty) { db()->rollBack(); error_response('Not enough stock at the source: ' . round($bal, 3) . ' available', 409); }
        inv_ledger($cid, (int)$item['id'], $batchId, (int)$from['warehouse_id'], (int)$from['id'], $fr, $fp, 'transfer_out', -$qty, $b['reason'] ?? null, null, null, (int)$u['user_id']);
        inv_ledger($cid, (int)$item['id'], $batchId, (int)$to['warehouse_id'], (int)$to['id'], $tr, $tp, 'transfer_in', $qty, $b['reason'] ?? null, null, null, (int)$u['user_id']);
        db()->commit();
    } catch (Throwable $e) { if (db()->inTransaction()) db()->rollBack(); throw $e; }
    return ['batch_id' => $batchId, 'qty' => round($qty, 3), 'from_cold_room_id' => (int)$from['id'], 'to_cold_room_id' => (int)$to['id']];
}

/** Count correction: needs the approve permission and a reason; never makes a balance negative. */
function inv_adjust(array $u, array $b): array {
    $cid = (int)$u['company_id'];
    if (trim((string)($b['reason'] ?? '')) === '') error_response('A reason is required for an adjustment', 422);
    $delta = inv_num($b['qty_delta'] ?? null, 'qty_delta');
    if (abs($delta) < 0.0005) error_response('qty_delta cannot be zero', 422);
    $st = db()->prepare('SELECT * FROM item_batches WHERE id = ? AND company_id = ?');
    $st->execute([(int)($b['batch_id'] ?? 0), $cid]);
    $batch = $st->fetch();
    if (!$batch) error_response('Batch not found', 404);
    $room = inv_room($cid, (int)($b['cold_room_id'] ?? 0));
    $rack = isset($b['rack']) && $b['rack'] !== '' ? (string)$b['rack'] : null;
    $pos = isset($b['position']) && $b['position'] !== '' ? (string)$b['position'] : null;
    db()->beginTransaction();
    try {
        $lock = db()->prepare('SELECT id FROM item_batches WHERE id = ? FOR UPDATE'); $lock->execute([$batch['id']]);
        $bal = inv_balance($cid, (int)$batch['item_id'], (int)$batch['id'], (int)$room['id'], $rack, $pos);
        if ($bal + $delta < -0.0005) { db()->rollBack(); error_response('The adjustment would make the balance negative (' . round($bal, 3) . ' on hand)', 409); }
        inv_ledger($cid, (int)$batch['item_id'], (int)$batch['id'], (int)$room['warehouse_id'], (int)$room['id'], $rack, $pos, 'adjust', $delta, (string)$b['reason'], 'count', null, (int)$u['user_id']);
        db()->commit();
    } catch (Throwable $e) { if (db()->inTransaction()) db()->rollBack(); throw $e; }
    return ['batch_id' => (int)$batch['id'], 'qty_delta' => round($delta, 3), 'balance' => round($bal + $delta, 3)];
}

// ── cold-room monitoring ─────────────────────────────────────

function inv_open_hold(int $cid, ?int $room, ?int $batch, string $reason, ?int $userId, bool $auto): int {
    db()->prepare('INSERT INTO quality_holds (company_id, cold_room_id, batch_id, reason, created_by, auto) VALUES (?,?,?,?,?,?)')
        ->execute([$cid, $room, $batch, $reason, $userId, $auto ? 1 : 0]);
    $id = (int)db()->lastInsertId();
    if ($room) db()->prepare("UPDATE cold_rooms SET status = 'hold' WHERE id = ? AND company_id = ?")->execute([$room, $cid]);
    return $id;
}

/** Alarms open longer than the limit put the room on hold. Called on every reading and every alarm/room list (no cron needed). */
function inv_evaluate_alarms(int $cid, ?int $roomId = null): void {
    $minutes = (int)inv_setting($cid, 'stock.alarm_hold_minutes', '30');
    $sql = "SELECT a.id, a.cold_room_id, a.kind FROM cold_room_alarms a
            WHERE a.company_id = ? AND a.status IN ('open','acknowledged') AND a.hold_id IS NULL
              AND a.opened_at <= (NOW() - INTERVAL ? MINUTE)" . ($roomId ? ' AND a.cold_room_id = ?' : '');
    $st = db()->prepare($sql);
    $st->execute($roomId ? [$cid, $minutes, $roomId] : [$cid, $minutes]);
    foreach ($st->fetchAll() as $a) {
        $hold = inv_open_hold($cid, (int)$a['cold_room_id'], null, "Automatic hold: {$a['kind']} alarm open for more than $minutes minutes", null, true);
        db()->prepare('UPDATE cold_room_alarms SET hold_id = ? WHERE id = ?')->execute([$hold, $a['id']]);
    }
}

function inv_reading(array $u, array $room, array $b): array {
    $cid = (int)$u['company_id'];
    $t = inv_num($b['temperature'] ?? null, 'temperature');
    if ($t < -60 || $t > 60) error_response('temperature looks wrong (-60..60 °C)', 422);
    $h = isset($b['humidity']) && $b['humidity'] !== '' ? inv_num($b['humidity'], 'humidity') : null;
    if ($h !== null && ($h < 0 || $h > 100)) error_response('humidity must be 0..100', 422);
    $source = ($b['source'] ?? 'manual') === 'sensor' ? 'sensor' : 'manual';
    db()->prepare('INSERT INTO temperature_readings (company_id, cold_room_id, temperature, humidity, source, user_id) VALUES (?,?,?,?,?,?)')
        ->execute([$cid, $room['id'], $t, $h, $source, $u['user_id']]);
    $readingId = (int)db()->lastInsertId();

    $out = [];
    if ($t > (float)$room['temp_max']) $out['temp_high'] = $t;
    if ($t < (float)$room['temp_min']) $out['temp_low'] = $t;
    if ($h !== null && (($room['humidity_max'] !== null && $h > (float)$room['humidity_max']) || ($room['humidity_min'] !== null && $h < (float)$room['humidity_min']))) $out['humidity'] = $h;

    foreach ($out as $kind => $val) {
        $st = db()->prepare("SELECT id FROM cold_room_alarms WHERE company_id = ? AND cold_room_id = ? AND kind = ? AND status IN ('open','acknowledged') LIMIT 1");
        $st->execute([$cid, $room['id'], $kind]);
        if (!$st->fetchColumn()) {
            db()->prepare('INSERT INTO cold_room_alarms (company_id, cold_room_id, kind, `first_value`) VALUES (?,?,?,?)')->execute([$cid, $room['id'], $kind, $val]);
        }
    }
    // alarms of kinds that are back in range recover
    $rec = db()->prepare("UPDATE cold_room_alarms SET status = 'recovered', recovered_at = NOW() WHERE company_id = ? AND cold_room_id = ? AND status IN ('open','acknowledged') AND kind NOT IN ('" . implode("','", array_merge(array_keys($out), ['__none__'])) . "')");
    $rec->execute([$cid, $room['id']]);

    $open = db()->prepare("SELECT COUNT(*) FROM cold_room_alarms WHERE company_id = ? AND cold_room_id = ? AND status IN ('open','acknowledged')");
    $open->execute([$cid, $room['id']]);
    $hold = db()->prepare("SELECT COUNT(*) FROM quality_holds WHERE company_id = ? AND cold_room_id = ? AND status = 'active'");
    $hold->execute([$cid, $room['id']]);
    $status = (int)$hold->fetchColumn() > 0 ? 'hold' : ((int)$open->fetchColumn() > 0 ? 'alarm' : 'ok');
    db()->prepare('UPDATE cold_rooms SET status = ? WHERE id = ? AND company_id = ?')->execute([$status, $room['id'], $cid]);

    inv_evaluate_alarms($cid, (int)$room['id']);
    $st = db()->prepare('SELECT status FROM cold_rooms WHERE id = ?'); $st->execute([$room['id']]);
    return ['reading_id' => $readingId, 'in_range' => !$out, 'alarms' => array_keys($out), 'room_status' => $st->fetchColumn()];
}

function inv_release_hold(array $u, int $holdId, ?string $note): array {
    $cid = (int)$u['company_id'];
    if ($note === null || trim($note) === '') error_response('A release note is required', 422);
    $st = db()->prepare('SELECT * FROM quality_holds WHERE id = ? AND company_id = ?');
    $st->execute([$holdId, $cid]);
    $h = $st->fetch();
    if (!$h) error_response('Not found', 404);
    if ($h['status'] !== 'active') error_response('This hold is already released', 409);
    db()->prepare("UPDATE quality_holds SET status = 'released', released_by = ?, released_at = NOW(), release_note = ? WHERE id = ?")->execute([$u['user_id'], $note, $holdId]);
    if ($h['cold_room_id']) {
        $act = db()->prepare("SELECT COUNT(*) FROM quality_holds WHERE company_id = ? AND cold_room_id = ? AND status = 'active'");
        $act->execute([$cid, $h['cold_room_id']]);
        $al = db()->prepare("SELECT COUNT(*) FROM cold_room_alarms WHERE company_id = ? AND cold_room_id = ? AND status IN ('open','acknowledged')");
        $al->execute([$cid, $h['cold_room_id']]);
        $status = (int)$act->fetchColumn() > 0 ? 'hold' : ((int)$al->fetchColumn() > 0 ? 'alarm' : 'ok');
        db()->prepare('UPDATE cold_rooms SET status = ? WHERE id = ? AND company_id = ?')->execute([$status, $h['cold_room_id'], $cid]);
    }
    return ['id' => $holdId, 'status' => 'released'];
}

// ─── Receiving against a purchase order ──────────────────────
/** The PO with, per item, what was ordered, what has arrived (from the ledger) and what is left. */
/** kg received per item on a purchase order: receipts stored straight away + the accepted kg of trucks that went through sorting */
function inv_po_received(int $cid, int $poId): array {
    $r = db()->prepare("SELECT item_id, SUM(qty) AS received FROM stock_ledger WHERE company_id = ? AND ref_type = 'purchase_order' AND ref_id = ? AND movement = 'receipt' GROUP BY item_id");
    $r->execute([$cid, $poId]);
    $got = [];
    foreach ($r->fetchAll() as $row) $got[(int)$row['item_id']] = (float)$row['received'];
    try {
        $s = db()->prepare("SELECT l.item_id, SUM(l.input_kg) AS kg FROM sorting_lines l JOIN sorting_runs r ON r.id = l.run_id JOIN deliveries d ON d.id = r.delivery_id
                            WHERE r.company_id = ? AND r.status = 'Completed' AND d.purchase_order_id = ? GROUP BY l.item_id");
        $s->execute([$cid, $poId]);
        foreach ($s->fetchAll() as $row) $got[(int)$row['item_id']] = ($got[(int)$row['item_id']] ?? 0) + (float)$row['kg'];
    } catch (PDOException $e) { /* sorting tables not installed yet */ }
    return $got;
}

function inv_po_status(int $cid, int $poId): array {
    $st = db()->prepare('SELECT * FROM purchase_orders WHERE id = ? AND company_id = ?');
    $st->execute([$poId, $cid]);
    $po = $st->fetch();
    if (!$po) error_response('Not found', 404);
    $l = db()->prepare('SELECT l.item_id, MAX(i.name) AS item_name, MAX(i.name_ar) AS item_name_ar, MAX(i.shelf_life_days) AS shelf_life_days, SUM(l.qty) AS ordered
                        FROM purchase_order_lines l JOIN items i ON i.id = l.item_id WHERE l.purchase_order_id = ? GROUP BY l.item_id ORDER BY MIN(l.id)');
    $l->execute([$poId]);
    $got = inv_po_received($cid, $poId);
    $lines = [];
    foreach ($l->fetchAll() as $row) {
        $ordered = round((float)$row['ordered'], 3); $received = round($got[(int)$row['item_id']] ?? 0, 3);
        $lines[] = ['item_id' => (int)$row['item_id'], 'item_name' => $row['item_name'], 'item_name_ar' => $row['item_name_ar'], 'shelf_life_days' => $row['shelf_life_days'] !== null ? (int)$row['shelf_life_days'] : null,
                    'ordered' => $ordered, 'received' => $received, 'remaining' => round(max(0, $ordered - $received), 3)];
    }
    return ['id' => (int)$po['id'], 'number' => $po['number'], 'status' => $po['status'], 'supplier_id' => $po['supplier_id'] !== null ? (int)$po['supplier_id'] : null, 'supplier_name' => $po['supplier_name'], 'lines' => $lines];
}

/**
 * Receive goods against an approved purchase order.
 * Body: {po_id, close?, lines:[{item_id, qty, cold_room_id, expiry_date?, grade?, rack?, position?, batch_no?}]}
 * Every line is checked first (nothing is booked if one is wrong); a line may not exceed the ordered quantity by more than
 * the setting purchase.receive_tolerance_pct (default 10 %, produce weight varies). The order becomes Received when every
 * line has arrived in full, or when the user closes it (the rest will not come).
 */
function inv_receive_po(array $u, array $b): array {
    $cid = (int)$u['company_id'];
    $st = inv_po_status($cid, (int)($b['po_id'] ?? 0));
    if ($st['status'] !== 'Approved') error_response('Only an approved order can be received (this one is ' . $st['status'] . ')', 409);
    $lines = $b['lines'] ?? null;
    if (!is_array($lines) || !$lines) error_response('Nothing to receive', 422);
    $tol = max(0.0, (float)inv_setting($cid, 'purchase.receive_tolerance_pct', '10'));
    $byItem = []; foreach ($st['lines'] as $l) $byItem[$l['item_id']] = $l;
    $adding = [];
    foreach ($lines as $ln) {
        if (!is_array($ln)) error_response('Invalid line', 422);
        $iid = (int)($ln['item_id'] ?? 0);
        if (!isset($byItem[$iid])) error_response("Item $iid is not on this order", 422);
        $qty = $ln['qty'] ?? null;
        if (!is_numeric($qty) || (float)$qty <= 0) error_response('Every line needs a quantity greater than zero', 422);
        $adding[$iid] = ($adding[$iid] ?? 0) + (float)$qty;
    }
    foreach ($adding as $iid => $qty) {
        $l = $byItem[$iid];
        if ($l['received'] + $qty > $l['ordered'] * (1 + $tol / 100) + 0.0005) {
            error_response("Too much for {$l['item_name']}: ordered {$l['ordered']} kg, already received {$l['received']} kg (tolerance $tol %)", 422);
        }
    }
    $done = [];
    foreach ($lines as $ln) {
        $done[] = inv_receive($u, [
            'item_id' => (int)$ln['item_id'], 'qty' => $ln['qty'], 'cold_room_id' => $ln['cold_room_id'] ?? 0, 'expiry_date' => $ln['expiry_date'] ?? null,
            'grade' => $ln['grade'] ?? null, 'rack' => $ln['rack'] ?? null, 'position' => $ln['position'] ?? null, 'batch_no' => $ln['batch_no'] ?? null,
            'arrival_date' => $ln['arrival_date'] ?? null, 'supplier_id' => $st['supplier_id'], 'ref_type' => 'purchase_order', 'ref_id' => $st['id'], 'reason' => 'PO ' . $st['number'],
        ]);
    }
    $after = inv_po_status($cid, $st['id']);
    $complete = true; foreach ($after['lines'] as $l) if ($l['remaining'] > 0.0005) $complete = false;
    if ($complete || !empty($b['close'])) {
        db()->prepare("UPDATE purchase_orders SET status = 'Received' WHERE id = ? AND company_id = ?")->execute([$st['id'], $cid]);
        $after['status'] = 'Received';
    }
    $after['received_batches'] = $done;
    return $after;
}
