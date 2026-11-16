<?php
// ─── Sorting (الفرز): a truck routed to sorting is split into three SELLABLE classes ───
//   class 1 → grade A (good, for the client; at least min % of the load, else an incident report)   class 2 → grade B   class 3 → grade C
// The sorter enters the results; the quality person (never the sorter) signs them off, and the three classes enter stock as batches.
require_once __DIR__ . '/receiving.php';
require_once __DIR__ . '/finance.php';

const SO_GRADE = [1 => 'A', 2 => 'B', 3 => 'C'];

function so_need(array $u, string $act): void {
    if (!permission_allows($u['permissions'], 'sorting-runs', $act)) error_response("Forbidden: you do not have permission to $act sorting runs", 403);
}
function so_kg(mixed $v, string $name): float {
    if ($v === null || $v === '') return 0.0;
    if (!is_numeric($v) || (float)$v < 0) error_response("$name must be zero or a positive number", 422);
    return round((float)$v, 3);
}
function so_text(mixed $v, int $max = 500): ?string {
    $v = trim((string)($v ?? ''));
    return $v === '' ? null : mb_substr($v, 0, $max);
}
function so_row(int $cid, int $id): array {
    $q = db()->prepare('SELECT * FROM sorting_runs WHERE id = ? AND company_id = ?');
    $q->execute([$id, $cid]);
    $r = $q->fetch();
    if (!$r || !branch_in_scope($r['branch_id'] !== null ? (int)$r['branch_id'] : null)) error_response('Not found', 404);
    return $r;
}
/** the class-1 minimum for an item: its own override, else the company setting */
function so_min_pct(int $cid, int $itemId): float {
    $q = db()->prepare('SELECT min_class1_pct FROM items WHERE id = ? AND company_id = ?'); $q->execute([$itemId, $cid]);
    $v = $q->fetchColumn();
    return $v !== false && $v !== null ? (float)$v : (float)inv_setting($cid, 'sorting.min_class1_pct', '80');
}

function so_detail(int $cid, int $id): array {
    $r = so_row($cid, $id);
    $d = db()->prepare('SELECT d.number, d.supplier_id, d.vehicle_plate, d.purchase_order_id, d.arrived_at, s.name AS supplier_name FROM deliveries d LEFT JOIN suppliers s ON s.id = d.supplier_id WHERE d.id = ?');
    $d->execute([$r['delivery_id']]);
    $r['delivery'] = $d->fetch() ?: null;
    $q = db()->prepare('SELECT l.*, i.name AS item_name, i.name_ar AS item_name_ar FROM sorting_lines l JOIN items i ON i.id = l.item_id WHERE l.run_id = ? ORDER BY l.id');
    $q->execute([$id]);
    $r['lines'] = array_map(function ($l) use ($cid) {
        foreach (['input_kg', 'class1_kg', 'class2_kg', 'class3_kg', 'shrink_kg', 'class1_pct', 'min_pct'] as $f) if ($l[$f] !== null) $l[$f] = (float)$l[$f];
        $min = so_min_pct($cid, (int)$l['item_id']);
        $l['min_pct'] = $min;
        $l['below_min'] = $l['class1_pct'] !== null && $l['class1_pct'] + 0.0001 < $min;
        return $l;
    }, $q->fetchAll());
    $r['below_min'] = (bool)array_filter($r['lines'], fn($l) => $l['below_min']);
    $i = db()->prepare('SELECT i.*, u.name AS filed_by_name FROM sorting_incidents i JOIN users u ON u.id = i.filed_by WHERE i.run_id = ?'); $i->execute([$id]);
    $r['incident'] = $i->fetch() ?: null;
    $o = db()->prepare('SELECT o.*, b.batch_no, b.grade FROM sorting_outputs o JOIN item_batches b ON b.id = o.batch_id WHERE o.run_id = ? ORDER BY o.id'); $o->execute([$id]);
    $r['outputs'] = array_map(function ($x) { $x['kg'] = (float)$x['kg']; return $x; }, $o->fetchAll());
    $r['limits'] = ['min_class1_pct' => (float)inv_setting($cid, 'sorting.min_class1_pct', '80'), 'balance_tolerance_pct' => (float)inv_setting($cid, 'sorting.balance_tolerance_pct', '2')];
    return $r;
}

function so_create(array $u, array $b): array {
    $cid = (int)$u['company_id'];
    $d = rcv_row($cid, (int)($b['delivery_id'] ?? 0));
    if ($d['status'] !== 'To_Sorting') error_response('Only a truck routed to sorting can be sorted (this one is ' . $d['status'] . ')', 409);
    $open = db()->prepare("SELECT number FROM sorting_runs WHERE delivery_id = ? AND status IN ('In_Progress','Awaiting_Signoff')"); $open->execute([$d['id']]);
    if ($n = $open->fetchColumn()) error_response("This truck is already being sorted ($n)", 409);
    $lines = array_filter(rcv_lines((int)$d['id']), fn($l) => $l['accepted_kg'] > 0.0005 && $l['stored_at'] === null);
    if (!$lines) error_response('There is nothing to sort on this delivery', 409);
    db()->beginTransaction();
    try {
        $number = crud_next_number('SR', 'sorting_runs', $cid);
        db()->prepare('INSERT INTO sorting_runs (company_id, branch_id, number, delivery_id, created_by) VALUES (?,?,?,?,?)')->execute([$cid, $d['branch_id'], $number, $d['id'], $u['user_id']]);
        $id = (int)db()->lastInsertId();
        $ins = db()->prepare('INSERT INTO sorting_lines (run_id, delivery_line_id, item_id, input_kg) VALUES (?,?,?,?)');
        foreach ($lines as $l) $ins->execute([$id, $l['id'], $l['item_id'], $l['accepted_kg']]);
        db()->commit();
    } catch (Throwable $e) { if (db()->inTransaction()) db()->rollBack(); throw $e; }
    return so_detail($cid, $id);
}

/** the sorter enters, per item, how many kg ended in each class and how many were lost (dirt, moisture) */
function so_results(array $u, int $id, array $b): array {
    $cid = (int)$u['company_id'];
    $r = so_row($cid, $id);
    if (!in_array($r['status'], ['In_Progress', 'Awaiting_Signoff'], true)) error_response('This sorting run is ' . $r['status'], 409);
    $req = $b['lines'] ?? null;
    if (!is_array($req) || !$req) error_response('Enter the result of every line', 422);
    $lines = []; $q = db()->prepare('SELECT * FROM sorting_lines WHERE run_id = ?'); $q->execute([$id]);
    foreach ($q->fetchAll() as $l) $lines[(int)$l['id']] = $l;
    $tol = (float)inv_setting($cid, 'sorting.balance_tolerance_pct', '2');
    $seen = [];
    $upd = db()->prepare('UPDATE sorting_lines SET class1_kg = ?, class2_kg = ?, class3_kg = ?, shrink_kg = ?, class1_pct = ?, min_pct = ? WHERE id = ?');
    db()->beginTransaction();
    try {
        foreach ($req as $x) {
            $lid = (int)($x['line_id'] ?? 0);
            if (!isset($lines[$lid])) error_response("Line $lid is not part of this sorting run", 422);
            $l = $lines[$lid]; $seen[$lid] = true;
            $c1 = so_kg($x['class1_kg'] ?? 0, 'class1_kg'); $c2 = so_kg($x['class2_kg'] ?? 0, 'class2_kg'); $c3 = so_kg($x['class3_kg'] ?? 0, 'class3_kg'); $sh = so_kg($x['shrink_kg'] ?? 0, 'shrink_kg');
            $in = (float)$l['input_kg'];
            $gap = abs($in - ($c1 + $c2 + $c3 + $sh));
            if ($gap > $in * $tol / 100 + 0.0005) error_response('The three classes + shrink must add up to ' . $in . ' kg (within ' . $tol . ' %): you entered ' . round($c1 + $c2 + $c3 + $sh, 3), 422);
            $upd->execute([$c1, $c2, $c3, $sh, round($c1 / $in * 100, 2), so_min_pct($cid, (int)$l['item_id']), $lid]);
        }
        if (count($seen) !== count($lines)) error_response('Enter the result of every line (' . count($lines) . ' lines)', 422);
        db()->prepare("UPDATE sorting_runs SET status = 'Awaiting_Signoff', results_by = ?, results_at = NOW(), signed_by = NULL, signed_at = NULL WHERE id = ?")->execute([$u['user_id'], $id]);
        db()->commit();
    } catch (Throwable $e) { if (db()->inTransaction()) db()->rollBack(); throw $e; }
    return so_detail($cid, $id);
}

/** the quality person files the incident report that a load below the class-1 minimum requires */
function so_incident(array $u, int $id, array $b): array {
    so_need($u, 'signoff');
    $cid = (int)$u['company_id'];
    $r = so_row($cid, $id);
    if ($r['status'] !== 'Awaiting_Signoff') error_response('Enter the sorting results first (this run is ' . $r['status'] . ')', 409);
    $det = so_detail($cid, $id);
    if (!$det['below_min']) error_response('No line is below the class-1 minimum: no incident report is needed', 409);
    $code = (string)($b['reason_code'] ?? '');
    $ok = db()->prepare("SELECT 1 FROM reject_reasons WHERE company_id = ? AND code = ? AND gate = 'sorting' AND is_active = 1"); $ok->execute([$cid, $code]);
    if (!$ok->fetchColumn()) error_response('Choose a reason from the list', 422);
    $note = so_text($b['note'] ?? null, 1000);
    if ($code === 'other' && !$note) error_response('A note is required when the reason is "other"', 422);
    db()->prepare("INSERT INTO sorting_incidents (company_id, run_id, supplier_id, reason_code, note, filed_by, filed_at) VALUES (?,?,?,?,?,?,NOW())
                   ON DUPLICATE KEY UPDATE reason_code = VALUES(reason_code), note = VALUES(note), filed_by = VALUES(filed_by), filed_at = NOW(), status = 'Open', reviewed_by = NULL, reviewed_at = NULL")
        ->execute([$cid, $id, $det['delivery']['supplier_id'] ?? null, $code, $note, $u['user_id']]);
    return so_detail($cid, $id);
}

/** quality sign-off: the three classes enter stock as grade A / B / C batches and the goods are booked into the ledger */
function so_signoff(array $u, int $id, array $b): array {
    so_need($u, 'signoff');
    $cid = (int)$u['company_id'];
    $r = so_row($cid, $id);
    if ($r['status'] !== 'Awaiting_Signoff') error_response('There are no results to sign off (this run is ' . $r['status'] . ')', 409);
    if ((int)$r['results_by'] === (int)$u['user_id'] && wf_setting($cid, 'workflow.maker_not_checker', '1') === '1') error_response('You entered these results: the quality person must be someone else', 403);
    $det = so_detail($cid, $id);
    if ($det['below_min'] && inv_setting($cid, 'sorting.require_incident_below_min', '1') === '1' && !$det['incident']) error_response('A line is below the class-1 minimum: file the incident report first', 409);
    $rooms = is_array($b['rooms'] ?? null) ? $b['rooms'] : [];
    $need = [];
    foreach ($det['lines'] as $l) foreach ([1, 2, 3] as $c) if ($l["class{$c}_kg"] > 0.0005) $need[$c] = true;
    foreach (array_keys($need) as $c) {
        if (empty($rooms[(string)$c]) && empty($rooms[$c])) error_response("Choose a cold room for class $c", 422);
    }
    $d = rcv_row($cid, (int)$r['delivery_id']);
    if ($d['status'] !== 'To_Sorting') error_response('The truck is no longer waiting for sorting (it is ' . $d['status'] . ')', 409);
    $poId = (int)$d['purchase_order_id'];
    $total = 0.0;
    db()->beginTransaction();
    try {
        $outIns = db()->prepare('INSERT INTO sorting_outputs (run_id, line_id, class_no, kg, batch_id, cold_room_id, unit_cost) VALUES (?,?,?,?,?,?,?)');
        foreach ($det['lines'] as $l) {
            $sellable = $l['class1_kg'] + $l['class2_kg'] + $l['class3_kg'];
            $rate = (float)fin_po_rate($poId, (int)$l['item_id']);
            $total += $l['input_kg'] * $rate;
            if ($sellable <= 0.0005) continue;
            $unit = $rate > 0 ? round($l['input_kg'] * $rate / $sellable, 4) : null;   // the shrink is absorbed: the sellable kilos carry the whole cost
            foreach ([1, 2, 3] as $c) {
                $kg = $l["class{$c}_kg"];
                if ($kg <= 0.0005) continue;
                $res = inv_receive($u, [
                    'item_id' => (int)$l['item_id'], 'qty' => $kg, 'cold_room_id' => (int)($rooms[(string)$c] ?? $rooms[$c]), 'grade' => SO_GRADE[$c], 'unit_cost' => $unit,
                    'arrival_date' => substr($d['arrived_at'], 0, 10), 'supplier_id' => $d['supplier_id'], 'branch_id' => $d['branch_id'] !== null ? (int)$d['branch_id'] : null,
                    'ref_type' => 'sorting_run', 'ref_id' => $id, 'reason' => 'Sorting ' . $r['number'] . ' class ' . $c,
                ]);
                $outIns->execute([$id, $l['id'], $c, $kg, $res['batch_id'], $res['cold_room_id'], $unit]);
            }
        }
        // Dr Inventory / Cr Goods received not invoiced for what the truck brought (the receipt was held back until sorting)
        $inv = fin_acct($cid, 'inventory'); $grni = fin_acct($cid, 'grni'); $value = round($total, 2);
        $je = null;
        if ($inv && $grni && $value > 0) {
            $date = substr($d['arrived_at'], 0, 10);
            $closed = trim(wf_setting($cid, 'finance.closed_until', ''));
            if ($closed !== '' && $date <= $closed) $date = date('Y-m-d');
            $je = fin_post($cid, $date, 'Goods sorted ' . $r['number'], 'sorting', $id, [[$inv, $value, 0, 'Inventory'], [$grni, 0, $value, 'Goods received not invoiced']], (int)$u['user_id']);
        }
        foreach ($det['lines'] as $l) db()->prepare('UPDATE delivery_lines SET stored_at = NOW() WHERE id = ?')->execute([$l['delivery_line_id']]);
        db()->prepare("UPDATE deliveries SET status = 'Stored' WHERE id = ?")->execute([$d['id']]);
        db()->prepare("UPDATE sorting_runs SET status = 'Completed', signed_by = ?, signed_at = NOW(), gl_entry_id = ? WHERE id = ?")->execute([$u['user_id'], $je, $id]);
        $po = inv_po_status($cid, $poId); $complete = true;
        foreach ($po['lines'] as $pl) if ($pl['remaining'] > 0.0005) $complete = false;
        if ($complete) db()->prepare("UPDATE purchase_orders SET status = 'Received' WHERE id = ? AND company_id = ?")->execute([$poId, $cid]);
        db()->commit();
    } catch (Throwable $e) { if (db()->inTransaction()) db()->rollBack(); throw $e; }
    return so_detail($cid, $id);
}

function so_cancel(array $u, int $id, array $b): array {
    $cid = (int)$u['company_id'];
    $r = so_row($cid, $id);
    if (!in_array($r['status'], ['In_Progress', 'Awaiting_Signoff'], true)) error_response('This sorting run is ' . $r['status'], 409);
    if ((int)$r['created_by'] !== (int)$u['user_id'] && !permission_allows($u['permissions'], 'sorting-runs', 'signoff') && !permission_allows($u['permissions'], 'sorting-runs', 'approve')) error_response('Only who started it, the quality person or a manager can cancel', 403);
    $reason = so_text($b['reason'] ?? null);
    if (!$reason) error_response('A reason is required', 422);
    db()->prepare("UPDATE sorting_runs SET status = 'Cancelled', cancel_reason = ? WHERE id = ?")->execute([$reason, $id]);
    return so_detail($cid, $id);
}

/** a manager reviews an incident; it may count as a claim against the supplier */
function so_review(array $u, int $incidentId, array $b): array {
    if (!permission_allows($u['permissions'], 'sorting-runs', 'approve')) error_response('Forbidden: incidents are reviewed by a manager', 403);
    $cid = (int)$u['company_id'];
    $q = db()->prepare('SELECT * FROM sorting_incidents WHERE id = ? AND company_id = ?'); $q->execute([$incidentId, $cid]);
    $i = $q->fetch();
    if (!$i) error_response('Not found', 404);
    if ($i['status'] !== 'Open') error_response('This incident was already reviewed', 409);
    if ((int)$i['filed_by'] === (int)$u['user_id'] && wf_setting($cid, 'workflow.maker_not_checker', '1') === '1') error_response('You filed this report: another person must review it', 403);
    $note = so_text($b['note'] ?? null, 1000);
    if (!$note) error_response('A review note is required', 422);
    db()->prepare("UPDATE sorting_incidents SET status = 'Reviewed', claim_supplier = ?, reviewed_by = ?, reviewed_at = NOW(), review_note = ? WHERE id = ?")->execute([!empty($b['claim_supplier']) ? 1 : 0, $u['user_id'], $note, $incidentId]);
    $q->execute([$incidentId, $cid]);
    return $q->fetch();
}

function so_incidents(int $cid): array {
    $q = db()->prepare("SELECT i.*, r.number AS run_number, s.name AS supplier_name, u.name AS filed_by_name FROM sorting_incidents i JOIN sorting_runs r ON r.id = i.run_id LEFT JOIN suppliers s ON s.id = i.supplier_id JOIN users u ON u.id = i.filed_by
                        WHERE i.company_id = ?" . branch_scope_sql('r.branch_id') . ' ORDER BY i.id DESC LIMIT 200');
    $q->execute([$cid]);
    return $q->fetchAll();
}

/** what the sorting found, per supplier: the share of each class over the completed runs */
function so_report(int $cid): array {
    $q = db()->prepare("SELECT s.id AS supplier_id, s.name AS supplier_name, COUNT(DISTINCT r.id) AS runs, COALESCE(SUM(l.input_kg),0) AS input_kg, COALESCE(SUM(l.class1_kg),0) AS class1_kg,
                               COALESCE(SUM(l.class2_kg),0) AS class2_kg, COALESCE(SUM(l.class3_kg),0) AS class3_kg, COALESCE(SUM(l.shrink_kg),0) AS shrink_kg,
                               (SELECT COUNT(*) FROM sorting_incidents i WHERE i.supplier_id = s.id AND i.company_id = r.company_id) AS incidents
                        FROM sorting_runs r JOIN deliveries d ON d.id = r.delivery_id JOIN suppliers s ON s.id = d.supplier_id JOIN sorting_lines l ON l.run_id = r.id
                        WHERE r.company_id = ? AND r.status = 'Completed'" . branch_scope_sql('r.branch_id') . ' GROUP BY s.id, s.name, r.company_id ORDER BY s.name');
    $q->execute([$cid]);
    return array_map(function ($x) {
        $in = (float)$x['input_kg'];
        foreach (['input_kg', 'class1_kg', 'class2_kg', 'class3_kg', 'shrink_kg'] as $f) $x[$f] = round((float)$x[$f], 3);
        $x['runs'] = (int)$x['runs']; $x['incidents'] = (int)$x['incidents'];
        foreach ([1, 2, 3] as $c) $x["class{$c}_pct"] = $in > 0 ? round($x["class{$c}_kg"] / $in * 100, 1) : 0.0;
        return $x;
    }, $q->fetchAll());
}
