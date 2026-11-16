<?php
// ─── Fleet, gates and the entry permit (إذن دخول) ───
// Request (buyer / gate) → the store pre-approves that there is space → the gate officer issues the permit on a chosen gate
// → the truck enters: the arrival (delivery) is registered from the permit and the normal receiving flow continues.
require_once __DIR__ . '/receiving.php';

function fl_need(array $u, string $act): void {
    if (!permission_allows($u['permissions'], 'entry-permits', $act)) error_response("Forbidden: you do not have permission to $act entry permits", 403);
}
function fl_text(mixed $v, int $max = 500): ?string {
    $v = trim((string)($v ?? ''));
    return $v === '' ? null : mb_substr($v, 0, $max);
}
function fl_row(int $cid, int $id): array {
    $q = db()->prepare('SELECT * FROM entry_permits WHERE id = ? AND company_id = ?');
    $q->execute([$id, $cid]);
    $p = $q->fetch();
    if (!$p || !branch_in_scope($p['branch_id'] !== null ? (int)$p['branch_id'] : null)) error_response('Not found', 404);
    return $p;
}

/** Free cold-room space of a branch: capacity of the active rooms in order minus what they hold, minus loads already promised to other permits. */
function fl_space(int $cid, ?int $branch, int $exceptPermit = 0): array {
    $w = 'r.company_id = ? AND r.is_active = 1 AND r.status = \'ok\'' . ($branch !== null ? ' AND r.branch_id = ' . (int)$branch : '');
    $q = db()->prepare("SELECT r.id, r.capacity_kg, COALESCE((SELECT SUM(l.qty) FROM stock_ledger l WHERE l.cold_room_id = r.id), 0) AS held FROM cold_rooms r WHERE $w");
    $q->execute([$cid]);
    $free = 0.0; $known = 0; $unknown = 0;
    foreach ($q->fetchAll() as $r) {
        if ($r['capacity_kg'] === null) { $unknown++; continue; }
        $known++;
        $free += max(0.0, (float)$r['capacity_kg'] - (float)$r['held']);
    }
    $c = db()->prepare("SELECT COALESCE(SUM(expected_kg),0) FROM entry_permits WHERE company_id = ? AND id <> ? AND status IN ('Store_Approved','Issued')" . ($branch !== null ? ' AND branch_id = ' . (int)$branch : ''));
    $c->execute([$cid, $exceptPermit]);
    $committed = (float)$c->fetchColumn();
    return ['free_kg' => round(max(0.0, $free - $committed), 3), 'rooms_with_capacity' => $known, 'rooms_without_capacity' => $unknown, 'committed_kg' => round($committed, 3)];
}

function fl_detail(int $cid, int $id): array {
    $p = fl_row($cid, $id);
    foreach (['expected_kg', 'store_free_kg'] as $f) if ($p[$f] !== null) $p[$f] = (float)$p[$f];
    $po = db()->prepare('SELECT number, supplier_name FROM purchase_orders WHERE id = ?'); $po->execute([$p['po_id']]);
    $p['po'] = $po->fetch() ?: null;
    if ($p['gate_id']) { $g = db()->prepare('SELECT code, name, name_ar FROM gates WHERE id = ?'); $g->execute([$p['gate_id']]); $p['gate'] = $g->fetch() ?: null; } else $p['gate'] = null;
    if (in_array($p['status'], ['Requested', 'Store_Approved'], true)) $p['space'] = fl_space($cid, $p['branch_id'] !== null ? (int)$p['branch_id'] : null, $id);
    return $p;
}

function fl_create(array $u, array $b): array {
    fl_need($u, 'create');
    $cid = (int)$u['company_id'];
    $po = db()->prepare('SELECT id, status, branch_id FROM purchase_orders WHERE id = ? AND company_id = ?'); $po->execute([(int)($b['po_id'] ?? 0), $cid]);
    $po = $po->fetch();
    if (!$po) error_response('Purchase order not found', 404);
    if ($po['status'] !== 'Approved') error_response('Only an approved purchase order can have a truck coming (this one is ' . $po['status'] . ')', 409);
    if (!branch_in_scope($po['branch_id'] !== null ? (int)$po['branch_id'] : null)) error_response('Not found', 404);
    $vid = null; $plate = fl_text($b['vehicle_plate'] ?? null, 30); $driver = fl_text($b['driver_name'] ?? null, 120); $did = fl_text($b['driver_id_no'] ?? null, 40);
    if (!empty($b['vehicle_id'])) {
        $v = db()->prepare('SELECT * FROM vehicles WHERE id = ? AND company_id = ? AND is_active = 1'); $v->execute([(int)$b['vehicle_id'], $cid]);
        $v = $v->fetch();
        if (!$v) error_response('Vehicle not found', 404);
        $vid = (int)$v['id']; $plate = $v['plate']; $driver = $driver ?: $v['default_driver']; $did = $did ?: $v['default_driver_id_no'];
        if (inv_setting($cid, 'fleet.allow_double_booking', '0') !== '1') {
            $busy = db()->prepare("SELECT number FROM entry_permits WHERE company_id = ? AND vehicle_id = ? AND status IN ('Requested','Store_Approved','Issued') LIMIT 1");
            $busy->execute([$cid, $vid]);
            if ($n = $busy->fetchColumn()) error_response("This truck is already on the open entry permit $n", 409);
        }
    }
    if (!$plate) error_response('The truck plate (or a vehicle) is required', 422);
    if (!$driver) error_response('The driver name is required', 422);
    if (isset($b['expected_kg']) && $b['expected_kg'] !== '') {
        if (!is_numeric($b['expected_kg']) || (float)$b['expected_kg'] <= 0) error_response('expected_kg must be a positive number', 422);
        $kg = round((float)$b['expected_kg'], 3);
    } else {
        $s = db()->prepare('SELECT COALESCE(SUM(qty),0) FROM purchase_order_lines WHERE purchase_order_id = ?'); $s->execute([$po['id']]);
        $kg = round((float)$s->fetchColumn(), 3);
        if ($kg <= 0) error_response('expected_kg is required', 422);
    }
    $number = crud_next_number('EP', 'entry_permits', $cid);
    db()->prepare('INSERT INTO entry_permits (company_id, branch_id, number, po_id, vehicle_id, vehicle_plate, driver_name, driver_id_no, expected_kg, created_by) VALUES (?,?,?,?,?,?,?,?,?,?)')
        ->execute([$cid, $po['branch_id'], $number, $po['id'], $vid, $plate, $driver, $did, $kg, $u['user_id']]);
    return fl_detail($cid, (int)db()->lastInsertId());
}

/** The store person confirms there is space for the expected load (or refuses). */
function fl_store_decide(array $u, int $id, array $b, bool $approve): array {
    fl_need($u, 'store');
    $cid = (int)$u['company_id'];
    $p = fl_row($cid, $id);
    if ($p['status'] !== 'Requested') error_response('This permit is ' . $p['status'] . ': the store decides only on a request', 409);
    if ((int)$p['created_by'] === (int)$u['user_id'] && wf_setting($cid, 'workflow.maker_not_checker', '1') === '1') error_response('You requested this truck: another person must decide on the space', 403);
    $note = fl_text($b['note'] ?? $b['reason'] ?? null);
    if (!$approve) {
        if (!$note) error_response('A reason is required', 422);
        db()->prepare("UPDATE entry_permits SET status = 'Rejected', store_by = ?, store_at = NOW(), store_note = ? WHERE id = ?")->execute([$u['user_id'], $note, $id]);
        return fl_detail($cid, $id);
    }
    $space = fl_space($cid, $p['branch_id'] !== null ? (int)$p['branch_id'] : null, $id);
    if ((float)$p['expected_kg'] > $space['free_kg'] + 0.0005) {
        if (inv_setting($cid, 'fleet.space_check_strict', '0') === '1') error_response('Not enough free space: ' . $space['free_kg'] . ' kg free, ' . (float)$p['expected_kg'] . ' kg expected', 409);
        if (!$note) error_response('The expected load (' . (float)$p['expected_kg'] . ' kg) is more than the free space (' . $space['free_kg'] . ' kg): write why you approve anyway', 422);
    }
    db()->prepare("UPDATE entry_permits SET status = 'Store_Approved', store_by = ?, store_at = NOW(), store_free_kg = ?, store_note = ? WHERE id = ?")->execute([$u['user_id'], $space['free_kg'], $note, $id]);
    return fl_detail($cid, $id);
}

function fl_issue(array $u, int $id, array $b): array {
    fl_need($u, 'gate');
    $cid = (int)$u['company_id'];
    $p = fl_row($cid, $id);
    $needStore = inv_setting($cid, 'fleet.require_store_approval', '1') === '1';
    if (!($p['status'] === 'Store_Approved' || (!$needStore && $p['status'] === 'Requested'))) error_response('The store has not approved the space yet (this permit is ' . $p['status'] . ')', 409);
    $g = db()->prepare('SELECT id, branch_id FROM gates WHERE id = ? AND company_id = ? AND is_active = 1'); $g->execute([(int)($b['gate_id'] ?? 0), $cid]);
    $g = $g->fetch();
    if (!$g) error_response('Choose a gate', 422);
    if ($p['branch_id'] !== null && $g['branch_id'] !== null && (int)$g['branch_id'] !== (int)$p['branch_id']) error_response('This gate belongs to another branch', 422);
    db()->prepare("UPDATE entry_permits SET status = 'Issued', gate_id = ?, issued_by = ?, issued_at = NOW() WHERE id = ?")->execute([$g['id'], $u['user_id'], $id]);
    return fl_detail($cid, $id);
}

/** The truck passes the gate: the arrival is registered from the permit. */
function fl_enter(array $u, int $id, array $b): array {
    fl_need($u, 'gate');
    $cid = (int)$u['company_id'];
    $p = fl_row($cid, $id);
    if ($p['status'] !== 'Issued') error_response('Only an issued permit lets a truck in (this one is ' . $p['status'] . ')', 409);
    $gross = isset($b['gross_kg']) && $b['gross_kg'] !== '' ? $b['gross_kg'] : null;
    $d = rcv_create($u, ['po_id' => $p['po_id'], 'driver_name' => $p['driver_name'], 'driver_id_no' => $p['driver_id_no'], 'vehicle_plate' => $p['vehicle_plate'], 'gross_kg' => $gross, 'entry_permit_id' => $id]);
    return fl_detail($cid, $id) + ['delivery' => ['id' => $d['id'] ?? null, 'number' => $d['number'] ?? null]];
}

function fl_cancel(array $u, int $id, array $b): array {
    $cid = (int)$u['company_id'];
    $p = fl_row($cid, $id);
    if (!in_array($p['status'], ['Requested', 'Store_Approved', 'Issued'], true)) error_response('This permit can no longer be cancelled (it is ' . $p['status'] . ')', 409);
    if ((int)$p['created_by'] !== (int)$u['user_id']) { if (!permission_allows($u['permissions'], 'entry-permits', 'gate') && !permission_allows($u['permissions'], 'entry-permits', 'store')) error_response('Only who requested it, the store or the gate can cancel', 403); }
    $reason = fl_text($b['reason'] ?? null);
    if (!$reason) error_response('A reason is required', 422);
    db()->prepare("UPDATE entry_permits SET status = 'Cancelled', cancel_reason = ? WHERE id = ?")->execute([$reason, $id]);
    return fl_detail($cid, $id);
}

/** Trucks with a flag saying whether each is on an open permit or a delivery still in progress. */
function fl_vehicles(int $cid): array {
    $q = db()->prepare("SELECT v.*,
        (SELECT ep.number FROM entry_permits ep WHERE ep.company_id = v.company_id AND ep.vehicle_id = v.id AND ep.status IN ('Requested','Store_Approved','Issued') LIMIT 1) AS open_permit,
        (SELECT d.number FROM deliveries d WHERE d.company_id = v.company_id AND d.vehicle_plate = v.plate AND d.status NOT IN ('Stored','Rejected') LIMIT 1) AS open_delivery
        FROM vehicles v WHERE v.company_id = ? ORDER BY v.kind, v.plate");
    $q->execute([$cid]);
    return array_map(function ($v) {
        $v['busy'] = $v['open_permit'] !== null || $v['open_delivery'] !== null;
        if ($v['capacity_kg'] !== null) $v['capacity_kg'] = (float)$v['capacity_kg'];
        return $v;
    }, $q->fetchAll());
}
