<?php
// ─── Alanad ERP — production and cold chain engine (Phase 5, tests/phase6_test.php) ───
// A preparation order turns stored raw material into finished product (wash, sort, trim, pack):
//   Draft -> Approved (a manager, never the planner) -> In_Progress (material issued oldest-expiry first at its real cost; the
//   outside-cold timer starts) -> Awaiting_Inspection (outputs by grade A/B/C and waste by reason weighed, timer stopped, yield checked)
//   -> Completed (a named inspector, never the person who entered the results, signs off; finished goods go into a cold room)
// Cost: material cost per input kg follows the weight: finished goods, rework and by-product take their share, the waste that is
// scrapped or destroyed waits for Finance, unaccounted loss is booked as shrinkage. Work in progress is empty when all is decided.
require_once __DIR__ . '/inventory.php';
require_once __DIR__ . '/finance.php';
require_once __DIR__ . '/purchasing.php';

const PROD_DECISIONS = ['rework', 'by_product', 'scrap_sale', 'destroy'];

function prod_need(array $u, string $resource, string $action): void {
    if (!permission_allows($u['permissions'], $resource, $action)) error_response("Forbidden: you do not have permission to $action $resource", 403);
}
function prod_num(mixed $v, string $name, bool $allowZero = false): float {
    if (!is_numeric($v) || (float)$v < 0 || (!$allowZero && (float)$v <= 0)) error_response("$name must be a " . ($allowZero ? 'non-negative' : 'positive') . ' number', 422);
    return (float)$v;
}
function prod_text(mixed $v, int $max = 255): ?string {
    $v = trim((string)$v);
    return $v === '' ? null : substr($v, 0, $max);
}

// ─── specifications ──────────────────────────────────────────

function prod_spec_row(int $cid, int $id): array {
    $st = db()->prepare('SELECT * FROM prep_specs WHERE id = ? AND company_id = ?');
    $st->execute([$id, $cid]);
    $r = $st->fetch();
    if (!$r) error_response('Specification not found', 404);
    return $r;
}
function prod_spec_list(int $cid): array {
    $q = db()->prepare('SELECT s.*, i.name AS input_item_name, i.name_ar AS input_item_name_ar, o.name AS output_item_name, o.name_ar AS output_item_name_ar, b.name AS byproduct_item_name
                        FROM prep_specs s JOIN items i ON i.id = s.input_item_id JOIN items o ON o.id = s.output_item_id LEFT JOIN items b ON b.id = s.byproduct_item_id WHERE s.company_id = ? ORDER BY s.code');
    $q->execute([$cid]);
    return $q->fetchAll();
}
function prod_spec_save(array $u, ?int $id, array $b): array {
    $cid = (int)$u['company_id'];
    $cur = $id ? prod_spec_row($cid, $id) : [];
    $get = fn($k, $d = null) => array_key_exists($k, $b) ? $b[$k] : ($cur[$k] ?? $d);
    $code = trim((string)$get('code', ''));
    if (!preg_match('/^[A-Za-z0-9_-]{2,30}$/', $code)) error_response('code must be 2 to 30 letters, digits, - or _', 422);
    if (trim((string)$get('name_en', '')) === '') error_response('name_en is required', 422);
    foreach (['input_item_id', 'output_item_id'] as $f) {
        $c = db()->prepare('SELECT 1 FROM items WHERE id = ? AND company_id = ?'); $c->execute([(int)$get($f, 0), $cid]);
        if (!$c->fetchColumn()) error_response("$f: item not found", 422);
    }
    $by = $get('byproduct_item_id');
    if ($by) { $c = db()->prepare('SELECT 1 FROM items WHERE id = ? AND company_id = ?'); $c->execute([(int)$by, $cid]); if (!$c->fetchColumn()) error_response('byproduct_item_id: item not found', 422); }
    $py = prod_num($get('planned_yield_pct', 85), 'planned_yield_pct'); $yt = prod_num($get('yield_tolerance_pct', 3), 'yield_tolerance_pct', true);
    $wh = prod_num($get('waste_high_pct', 20), 'waste_high_pct'); $mm = (int)prod_num($get('max_minutes_outside', 45), 'max_minutes_outside');
    if ($py > 100 || $wh > 100 || $yt > 100) error_response('Percentages cannot be above 100', 422);
    $vals = [$code, substr((string)$get('name_en'), 0, 160), prod_text($get('name_ar'), 160), (int)$get('input_item_id'), (int)$get('output_item_id'), $by ? (int)$by : null, $py, $yt, $wh, $mm, (int)(bool)$get('requires_wash', 1), (int)(bool)$get('is_active', 1)];
    try {
        if ($id) {
            db()->prepare('UPDATE prep_specs SET code=?, name_en=?, name_ar=?, input_item_id=?, output_item_id=?, byproduct_item_id=?, planned_yield_pct=?, yield_tolerance_pct=?, waste_high_pct=?, max_minutes_outside=?, requires_wash=?, is_active=? WHERE id = ? AND company_id = ?')->execute(array_merge($vals, [$id, $cid]));
        } else {
            db()->prepare('INSERT INTO prep_specs (code, name_en, name_ar, input_item_id, output_item_id, byproduct_item_id, planned_yield_pct, yield_tolerance_pct, waste_high_pct, max_minutes_outside, requires_wash, is_active, company_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute(array_merge($vals, [$cid]));
            $id = (int)db()->lastInsertId();
        }
    } catch (PDOException $e) {
        if ((int)($e->errorInfo[1] ?? 0) === 1062) error_response('This specification code already exists', 409);
        throw $e;
    }
    return prod_spec_row($cid, $id);
}

// ─── orders: reading ─────────────────────────────────────────

function prod_row(int $cid, int $id): array {
    $st = db()->prepare('SELECT * FROM prep_orders WHERE id = ? AND company_id = ?');
    $st->execute([$id, $cid]);
    $r = $st->fetch();
    if (!$r || !branch_in_scope($r['branch_id'] !== null ? (int)$r['branch_id'] : null)) error_response('Not found', 404);
    return $r;
}
function prod_available(int $cid, int $itemId, ?int $branchId = null): float {
    return round(array_sum(array_map(fn($r) => (float)$r['bal'], inv_candidates($cid, $itemId, null, false, $branchId !== null ? [$branchId] : null)['usable'])), 3);
}
function prod_detail(int $cid, int $id): array {
    $o = prod_row($cid, $id);
    foreach (['planned_input_kg', 'input_kg', 'issued_cost', 'output_kg', 'waste_kg', 'yield_pct', 'waste_pct'] as $f) if ($o[$f] !== null) $o[$f] = (float)$o[$f];
    $spec = prod_spec_row($cid, (int)$o['spec_id']);
    foreach (['planned_yield_pct', 'yield_tolerance_pct', 'waste_high_pct'] as $f) $spec[$f] = (float)$spec[$f];
    foreach (['input' => $spec['input_item_id'], 'output' => $spec['output_item_id']] as $k => $iid) {
        $x = db()->prepare('SELECT name, name_ar FROM items WHERE id = ?'); $x->execute([$iid]); $r = $x->fetch();
        $spec[$k . '_item_name'] = $r['name']; $spec[$k . '_item_name_ar'] = $r['name_ar'];
    }
    $o['spec'] = $spec;
    $q = db()->prepare('SELECT i.*, b.batch_no, b.expiry_date, r.name AS cold_room_name, s.name AS supplier_name FROM prep_inputs i JOIN item_batches b ON b.id = i.batch_id LEFT JOIN cold_rooms r ON r.id = i.cold_room_id LEFT JOIN suppliers s ON s.id = i.supplier_id WHERE i.order_id = ? ORDER BY i.id');
    $q->execute([$id]); $o['inputs'] = $q->fetchAll();
    $q = db()->prepare('SELECT o.*, b.batch_no, r.name AS cold_room_name FROM prep_outputs o LEFT JOIN item_batches b ON b.id = o.batch_id LEFT JOIN cold_rooms r ON r.id = o.cold_room_id WHERE o.order_id = ? ORDER BY o.grade');
    $q->execute([$id]); $o['outputs'] = $q->fetchAll();
    $q = db()->prepare('SELECT w.*, r.name_en AS reason_name_en, r.name_ar AS reason_name_ar FROM prep_waste w LEFT JOIN prep_waste_reasons r ON r.company_id = ? AND r.code = w.reason_code WHERE w.order_id = ? ORDER BY w.id');
    $q->execute([$cid, $id]); $o['waste'] = $q->fetchAll();
    $q = db()->prepare('SELECT * FROM haccp_logs WHERE prep_order_id = ? ORDER BY id');
    $q->execute([$id]); $o['logs'] = $q->fetchAll();
    $o['available_kg'] = prod_available($cid, (int)$spec['input_item_id'], $o['branch_id'] !== null ? (int)$o['branch_id'] : null);
    $o['shortage_kg'] = in_array($o['status'], ['Draft', 'Approved'], true) ? round(max(0, $o['planned_input_kg'] - $o['available_kg']), 3) : 0.0;
    $mw = db()->prepare('SELECT TIMESTAMPDIFF(MINUTE, started_at, NOW()) FROM prep_orders WHERE id = ?'); $mw->execute([$id]);
    $o['minutes_running'] = $o['status'] === 'In_Progress' ? max(0, (int)$mw->fetchColumn()) : null;
    $o['over_time_now'] = $o['minutes_running'] !== null && $o['minutes_running'] > (int)$spec['max_minutes_outside'];
    $o['unit_cost'] = ($o['input_kg'] ?? 0) > 0 ? round($o['issued_cost'] / $o['input_kg'], 4) : null;
    return $o;
}

// ─── orders: the steps ───────────────────────────────────────

function prod_create(array $u, array $b): array {
    $cid = (int)$u['company_id'];
    $spec = prod_spec_row($cid, (int)($b['spec_id'] ?? 0));
    if (!(int)$spec['is_active']) error_response('This specification is not active', 409);
    $kg = prod_num($b['planned_input_kg'] ?? null, 'planned_input_kg');
    $number = crud_next_number('PRP', 'prep_orders', $cid);
    db()->prepare('INSERT INTO prep_orders (company_id, number, spec_id, planned_input_kg, notes, created_by, branch_id) VALUES (?,?,?,?,?,?,?)')->execute([$cid, $number, $spec['id'], round($kg, 3), prod_text($b['notes'] ?? null, 1000), $u['user_id'], !empty($b['branch_id']) ? branch_validate($cid, (int)$b['branch_id']) : branch_default($cid)]);
    return prod_detail($cid, (int)db()->lastInsertId());
}

function prod_approve(array $u, int $id): array {
    prod_need($u, 'prep-orders', 'approve');
    $cid = (int)$u['company_id'];
    wf_require($cid, 'prep_order_approval', $u);
    $o = prod_row($cid, $id);
    if ($o['status'] !== 'Draft') error_response('Only a draft order can be approved (this one is ' . $o['status'] . ')', 409);
    if ((int)$o['created_by'] === (int)$u['user_id'] && wf_setting($cid, 'workflow.maker_not_checker', '1') === '1') error_response('You planned this order: another person must approve it', 403);
    db()->prepare("UPDATE prep_orders SET status = 'Approved', approved_by = ?, approved_at = NOW() WHERE id = ?")->execute([$u['user_id'], $id]);
    return prod_detail($cid, $id);
}

function prod_cancel(array $u, int $id, array $b): array {
    $cid = (int)$u['company_id'];
    $o = prod_row($cid, $id);
    if (!in_array($o['status'], ['Draft', 'Approved'], true)) error_response('Only an order that has not started can be cancelled', 409);
    $reason = prod_text($b['reason'] ?? null);
    if (!$reason) error_response('A reason is required', 422);
    db()->prepare("UPDATE prep_orders SET status = 'Cancelled', cancel_reason = ? WHERE id = ?")->execute([$reason, $id]);
    return prod_detail($cid, $id);
}

/** "Materials in stock? no -> automatic purchase request": a draft request for the shortage */
function prod_request_materials(array $u, int $id): array {
    if (!permission_allows($u['permissions'], 'purchase-requests', 'create')) error_response('Forbidden: you cannot create purchase requests', 403);
    $cid = (int)$u['company_id'];
    $d = prod_detail($cid, $id);
    if (!in_array($d['status'], ['Draft', 'Approved'], true)) error_response('Materials are requested before the order starts', 409);
    if ($d['shortage_kg'] <= 0.0005) error_response('There is enough usable stock: nothing to request', 409);
    $pr = pur_pr_create($u, ['notes' => 'Auto request for preparation order ' . $d['number'], 'lines' => [['item_id' => (int)$d['spec']['input_item_id'], 'qty' => ceil($d['shortage_kg']), 'note' => $d['number']]]]);
    return ['purchase_request_id' => (int)$pr['id'], 'number' => $pr['number'], 'shortage_kg' => $d['shortage_kg']];
}

function prod_start(array $u, int $id, array $b): array {
    $cid = (int)$u['company_id'];
    $o = prod_row($cid, $id);
    if ($o['status'] !== 'Approved') error_response('Only an approved order can start (this one is ' . $o['status'] . ')', 409);
    $spec = prod_spec_row($cid, (int)$o['spec_id']);
    $kg = isset($b['kg']) && $b['kg'] !== '' ? prod_num($b['kg'], 'kg') : (float)$o['planned_input_kg'];
    if ($kg > (float)$o['planned_input_kg'] * 1.1 + 0.0005) error_response('You can issue at most 10 % more than the planned quantity', 422);
    $res = inv_issue($u, ['item_id' => (int)$spec['input_item_id'], 'qty' => $kg, 'reason' => 'Preparation ' . $o['number'], 'branch_id' => $o['branch_id'] !== null ? (int)$o['branch_id'] : null, 'ref_type' => 'prep_order', 'ref_id' => $id], 'issue');   // oldest expiry first; 409 when short
    $fallback = db()->prepare('SELECT COALESCE(standard_buying_rate, 0) FROM items WHERE id = ?'); $fallback->execute([$spec['input_item_id']]);
    $fallback = (float)$fallback->fetchColumn();
    $info = db()->prepare('SELECT unit_cost, supplier_id FROM item_batches WHERE id = ?');
    $total = 0.0;
    db()->beginTransaction();
    try {
        foreach ($res['allocations'] as $a) {
            $info->execute([$a['batch_id']]); $bi = $info->fetch();
            $uc = $bi && $bi['unit_cost'] !== null ? (float)$bi['unit_cost'] : $fallback;
            $cost = round($a['qty'] * $uc, 2); $total += $cost;
            db()->prepare('INSERT INTO prep_inputs (order_id, batch_id, item_id, cold_room_id, supplier_id, kg, unit_cost, cost) VALUES (?,?,?,?,?,?,?,?)')
                ->execute([$id, $a['batch_id'], $spec['input_item_id'], $a['cold_room_id'], $bi['supplier_id'] ?? null, $a['qty'], $uc, $cost]);
        }
        $total = round($total, 2);
        db()->prepare("UPDATE prep_orders SET status = 'In_Progress', input_kg = ?, issued_cost = ?, started_at = NOW() WHERE id = ?")->execute([round($kg, 3), $total, $id]);
        if ($total > 0 && ($wip = fin_acct($cid, 'wip')) && ($inv = fin_acct($cid, 'inventory'))) {
            fin_post($cid, date('Y-m-d'), 'Material issued to ' . $o['number'], 'prep_issue', $id, [[$wip, $total, 0, 'Work in progress'], [$inv, 0, $total, 'Inventory']], (int)$u['user_id']);
        }
        db()->commit();
    } catch (Throwable $e) { if (db()->inTransaction()) db()->rollBack(); throw $e; }
    return prod_detail($cid, $id);
}

function prod_results(array $u, int $id, array $b): array {
    $cid = (int)$u['company_id'];
    $o = prod_row($cid, $id);
    if (!in_array($o['status'], ['In_Progress', 'Awaiting_Inspection'], true)) error_response('Results are entered while the order is running (this one is ' . $o['status'] . ')', 409);
    $spec = prod_spec_row($cid, (int)$o['spec_id']);
    $input = (float)$o['input_kg'];
    if ($spec['requires_wash']) {
        $k = db()->prepare("SELECT kind, COUNT(*) FROM haccp_logs WHERE prep_order_id = ? AND kind IN ('wash_water','sanitiser') GROUP BY kind"); $k->execute([$id]);
        $have = $k->fetchAll(PDO::FETCH_KEY_PAIR);
        if (empty($have['wash_water']) || empty($have['sanitiser'])) error_response('Record the wash-water temperature and the sanitiser level before the results (food-safety rule)', 409);
    }
    $outs = []; $outKg = 0.0;
    foreach (($b['outputs'] ?? []) as $x) {
        if (!is_array($x)) error_response('Invalid output line', 422);
        $g = $x['grade'] ?? '';
        if (!in_array($g, ['A', 'B', 'C'], true)) error_response('grade must be A, B or C', 422);
        $kg = round(prod_num($x['kg'] ?? null, 'output kg'), 3);
        $outs[$g] = ($outs[$g] ?? 0) + $kg; $outKg += $kg;
    }
    $reasons = db()->prepare('SELECT waste_class FROM prep_waste_reasons WHERE company_id = ? AND code = ? AND is_active = 1');
    $wastes = []; $wasteKg = 0.0;
    foreach (($b['waste'] ?? []) as $x) {
        if (!is_array($x)) error_response('Invalid waste line', 422);
        $reasons->execute([$cid, (string)($x['reason_code'] ?? '')]);
        $cls = $reasons->fetchColumn();
        if (!$cls) error_response('Choose a waste reason from the list', 422);
        $kg = round(prod_num($x['kg'] ?? null, 'waste kg'), 3);
        $dec = $x['decision'] ?? '';
        if (!in_array($dec, PROD_DECISIONS, true)) error_response('Every waste line needs a decision: rework, by_product, scrap_sale or destroy', 422);
        if ($dec === 'by_product' && !$spec['byproduct_item_id']) error_response('This specification has no by-product item: choose another decision', 422);
        $inc = null;
        if ($dec === 'scrap_sale') { if (!isset($x['scrap_income']) || !is_numeric($x['scrap_income']) || (float)$x['scrap_income'] < 0) error_response('A scrap sale needs the income (0 or more)', 422); $inc = round((float)$x['scrap_income'], 2); }
        $wastes[] = [(string)$x['reason_code'], $cls, $kg, $dec, $inc, prod_text($x['note'] ?? null)];
        $wasteKg += $kg;
    }
    if (!$outs) error_response('Weigh the finished product by grade', 422);
    $tol = max(0.0, (float)inv_setting($cid, 'production.balance_tolerance_pct', '3'));
    if ($outKg + $wasteKg > $input * (1 + $tol / 100) + 0.0005) error_response('Output + waste (' . round($outKg + $wasteKg, 3) . ' kg) is more than what was issued (' . round($input, 3) . ' kg)', 422);
    $unacc = $input - $outKg - $wasteKg;
    if ($unacc > $input * $tol / 100 + 0.0005) error_response('Weigh the waste by reason: ' . round($unacc, 3) . ' kg are unaccounted for (allowed ' . $tol . ' %)', 422);
    $yield = round($outKg / $input * 100, 2); $wastePct = round($wasteKg / $input * 100, 2);
    $yieldReason = prod_text($b['yield_reason'] ?? null);
    if ($yield < (float)$spec['planned_yield_pct'] - (float)$spec['yield_tolerance_pct'] && !$yieldReason) error_response('The yield (' . $yield . ' %) is below the plan (' . $spec['planned_yield_pct'] . ' %): a reason is required', 422);
    $ms = db()->prepare('SELECT TIMESTAMPDIFF(MINUTE, COALESCE(finished_at, NOW()), NOW()) AS since_stop, TIMESTAMPDIFF(MINUTE, started_at, COALESCE(finished_at, NOW())) AS mins FROM prep_orders WHERE id = ?'); $ms->execute([$id]);
    $mins = max(0, (int)$ms->fetch()['mins']);
    $over = $mins > (int)$spec['max_minutes_outside'];
    $overNote = prod_text($b['overtime_note'] ?? null);
    if ($over && !$overNote) error_response('The product was outside the cold room for ' . $mins . ' minutes (limit ' . $spec['max_minutes_outside'] . '): a note is required', 422);
    $result = $wastePct > (float)$spec['waste_high_pct'] ? 'waste_high' : 'high_quality';
    db()->beginTransaction();
    try {
        db()->prepare('DELETE FROM prep_outputs WHERE order_id = ?')->execute([$id]);
        db()->prepare('DELETE FROM prep_waste WHERE order_id = ?')->execute([$id]);
        foreach ($outs as $g => $kg) db()->prepare('INSERT INTO prep_outputs (order_id, grade, kg) VALUES (?,?,?)')->execute([$id, $g, $kg]);
        foreach ($wastes as $w) db()->prepare('INSERT INTO prep_waste (order_id, reason_code, waste_class, kg, decision, scrap_income, decision_note) VALUES (?,?,?,?,?,?,?)')->execute([$id, $w[0], $w[1], $w[2], $w[3], $w[4], $w[5]]);
        db()->prepare("UPDATE prep_orders SET status = 'Awaiting_Inspection', finished_at = COALESCE(finished_at, NOW()), minutes_outside = ?, over_time = ?, overtime_note = ?, yield_reason = ?, output_kg = ?, waste_kg = ?, yield_pct = ?, waste_pct = ?, result = ?, results_by = ?, inspected_by = NULL WHERE id = ?")
            ->execute([$mins, $over ? 1 : 0, $overNote, $yieldReason, round($outKg, 3), round($wasteKg, 3), $yield, $wastePct, $result, $u['user_id'], $id]);
        db()->commit();
    } catch (Throwable $e) { if (db()->inTransaction()) db()->rollBack(); throw $e; }
    return prod_detail($cid, $id);
}

/** books stock + the matching Dr Inventory line for a waste line that returns to the cold room (rework / by-product) */
function prod_waste_to_stock(array $u, array $o, array $spec, array $w, int $roomId, float $unitCost): array {
    $item = $w['decision'] === 'by_product' ? (int)$spec['byproduct_item_id'] : (int)$spec['input_item_id'];
    // the returned material keeps the supplier it came from (the one that delivered most of this order's input): loss reports and recall follow it
    $sp = db()->prepare('SELECT supplier_id FROM prep_inputs WHERE order_id = ? AND supplier_id IS NOT NULL GROUP BY supplier_id ORDER BY SUM(kg) DESC LIMIT 1'); $sp->execute([(int)$o['id']]);
    $supplier = $sp->fetchColumn();
    $res = inv_receive($u, ['item_id' => $item, 'qty' => (float)$w['kg'], 'cold_room_id' => $roomId, 'grade' => 'C', 'branch_id' => $o['branch_id'] !== null ? (int)$o['branch_id'] : null, 'unit_cost' => $unitCost, 'supplier_id' => $supplier !== false ? (int)$supplier : null, 'ref_type' => 'prep_order', 'ref_id' => (int)$o['id'], 'reason' => ($w['decision'] === 'by_product' ? 'By-product of ' : 'Rework from ') . $o['number']]);
    return [(int)$res['batch_id'], round((float)$w['kg'] * $unitCost, 2)];
}

function prod_sign_off(array $u, int $id, array $b): array {
    prod_need($u, 'prep-orders', 'approve');
    if (!permission_allows($u['permissions'], 'stock', 'create')) error_response('Forbidden: you do not have permission to create stock', 403);
    $cid = (int)$u['company_id'];
    $o = prod_row($cid, $id);
    if ($o['status'] !== 'Awaiting_Inspection') error_response('There is nothing to sign off (this order is ' . $o['status'] . ')', 409);
    if ((int)$o['results_by'] === (int)$u['user_id'] && wf_setting($cid, 'workflow.maker_not_checker', '1') === '1') error_response('You entered the results: another person must do the final inspection', 403);
    $spec = prod_spec_row($cid, (int)$o['spec_id']);
    $outs = db()->prepare('SELECT * FROM prep_outputs WHERE order_id = ?'); $outs->execute([$id]); $outs = $outs->fetchAll();
    $waste = db()->prepare('SELECT * FROM prep_waste WHERE order_id = ?'); $waste->execute([$id]); $waste = $waste->fetchAll();
    $store = []; foreach (($b['store'] ?? []) as $s) if (is_array($s)) $store[$s['grade'] ?? ''] = $s;
    foreach ($outs as $x) if (empty($store[$x['grade']]['cold_room_id'])) error_response('Choose a cold room for grade ' . $x['grade'], 422);
    $needRoom = false; foreach ($waste as $w) if (in_array($w['decision'], ['rework', 'by_product'], true)) $needRoom = true;
    $wasteRoom = !empty($b['waste_room_id']) ? (int)$b['waste_room_id'] : null;
    if ($needRoom && !$wasteRoom) error_response('Choose the cold room for the waste that goes back to stock (rework / by-product)', 422);

    $input = (float)$o['input_kg']; $cost = (float)$o['issued_cost'];
    $c = $input > 0 ? $cost / $input : 0.0;
    $wip = $cost > 0 ? fin_acct_or_fail($cid, 'wip') : null;
    $invAcct = $cost > 0 ? fin_acct_or_fail($cid, 'inventory') : null;
    $own = !db()->inTransaction();
    if ($own) db()->beginTransaction();
    try {
        $debits = [];   // [account, amount, memo]
        $sum = 0.0;
        $first = true; $outValues = [];
        foreach ($outs as $x) {
            $s = $store[$x['grade']];
            $v = round((float)$x['kg'] * $c, 2); $outValues[$x['id']] = $v; $sum += $v;
        }
        $shrinkKg = max(0.0, round($input - (float)$o['output_kg'] - (float)$o['waste_kg'], 3));
        $shrink = round($shrinkKg * $c, 2); $sum += $shrink;
        $pendingCost = 0.0; $wasteInfo = [];
        foreach ($waste as $w) {
            $wc = round((float)$w['kg'] * $c, 2); $sum += $wc;
            $wasteInfo[$w['id']] = $wc;
            if (in_array($w['decision'], ['destroy', 'scrap_sale'], true)) $pendingCost += $wc;
        }
        $residual = $cost > 0 ? round($cost - $sum, 2) : 0.0;     // cents lost by rounding go to the first finished line, so work in progress closes exactly
        $stockDebit = 0.0;
        foreach ($outs as $x) {
            $s = $store[$x['grade']];
            $v = $outValues[$x['id']] + ($first ? $residual : 0.0); $first = false;
            $uc = (float)$x['kg'] > 0 ? $v / (float)$x['kg'] : 0.0;
            $res = inv_receive($u, ['item_id' => (int)$spec['output_item_id'], 'qty' => (float)$x['kg'], 'cold_room_id' => (int)$s['cold_room_id'], 'grade' => $x['grade'], 'rack' => $s['rack'] ?? null, 'position' => $s['position'] ?? null,
                                     'expiry_date' => $s['expiry_date'] ?? null, 'branch_id' => $o['branch_id'] !== null ? (int)$o['branch_id'] : null, 'unit_cost' => round($uc, 4), 'ref_type' => 'prep_order', 'ref_id' => $id, 'reason' => 'Finished goods of ' . $o['number']]);
            db()->prepare('UPDATE prep_outputs SET batch_id = ?, cold_room_id = ?, value = ? WHERE id = ?')->execute([$res['batch_id'], $res['cold_room_id'], $v, $x['id']]);
            $stockDebit += $v;
        }
        foreach ($waste as $w) {
            $wc = $wasteInfo[$w['id']];
            if (in_array($w['decision'], ['rework', 'by_product'], true)) {
                [$bid] = prod_waste_to_stock($u, $o, $spec, $w, $wasteRoom, $c);
                db()->prepare("UPDATE prep_waste SET status = 'Done', cost = ?, batch_id = ?, decided_by = ?, decided_at = NOW() WHERE id = ?")->execute([$wc, $bid, $u['user_id'], $w['id']]);
                $stockDebit += $wc;
            } else {
                db()->prepare("UPDATE prep_waste SET status = 'Pending', cost = ? WHERE id = ?")->execute([$wc, $w['id']]);
            }
        }
        if ($cost > 0) {
            $lines = [[$invAcct, round($stockDebit, 2), 0, 'Finished goods and returned waste']];
            if ($shrink > 0) $lines[] = [fin_acct_or_fail($cid, 'waste'), $shrink, 0, 'Unaccounted loss (shrinkage) ' . $shrinkKg . ' kg'];
            $credit = round($stockDebit + $shrink, 2);
            if ($credit > 0) {
                $lines[] = [$wip, 0, $credit, 'Work in progress ' . $o['number']];
                fin_post($cid, date('Y-m-d'), 'Production completed ' . $o['number'], 'prep_complete', $id, $lines, (int)$u['user_id']);
            }
        }
        db()->prepare("UPDATE prep_orders SET status = 'Completed', inspected_by = ?, inspected_at = NOW(), inspection_note = ? WHERE id = ?")->execute([$u['user_id'], prod_text($b['note'] ?? null), $id]);
        if ($own) db()->commit();
    } catch (Throwable $e) { if ($own && db()->inTransaction()) db()->rollBack(); throw $e; }
    return prod_detail($cid, $id);
}

/** Finance approves (or returns) the write-off / scrap sale of one waste line */
function prod_waste_decide(array $u, int $orderId, int $wid, array $b): array {
    if (!permission_allows($u['permissions'], 'gl-entries', 'approve')) error_response('Forbidden: write-offs are approved by Finance', 403);
    $cid = (int)$u['company_id'];
    wf_require($cid, 'waste_decision', $u);
    $o = prod_row($cid, $orderId);
    $st = db()->prepare('SELECT * FROM prep_waste WHERE id = ? AND order_id = ?'); $st->execute([$wid, $orderId]);
    $w = $st->fetch();
    if (!$w) error_response('Waste line not found', 404);
    if ($w['status'] !== 'Pending') error_response('This waste line is not waiting for approval (it is ' . $w['status'] . ')', 409);
    if ((int)$o['inspected_by'] === (int)$u['user_id'] && wf_setting($cid, 'workflow.maker_not_checker', '1') === '1') error_response('You signed off this order: another person must approve the write-off', 403);
    $action = $b['action'] ?? '';
    $note = prod_text($b['note'] ?? null);
    if ($action === 'reject') {
        if (!$note) error_response('A note is required to return a waste decision', 422);
        db()->prepare("UPDATE prep_waste SET status = 'Returned', decision_note = ?, decided_by = ?, decided_at = NOW() WHERE id = ?")->execute([$note, $u['user_id'], $wid]);
        return prod_detail($cid, $orderId);
    }
    if ($action !== 'approve') error_response('action must be approve or reject', 422);
    $cost = (float)$w['cost'];
    $wip = fin_acct_or_fail($cid, 'wip'); $loss = fin_acct_or_fail($cid, 'waste');
    $lines = []; $income = (float)($w['scrap_income'] ?? 0);
    if ($w['decision'] === 'scrap_sale') {
        $lines[] = [fin_acct_or_fail($cid, 'cash'), $income, 0, 'Scrap sale income'];
        if ($cost - $income > 0.004) $lines[] = [$loss, round($cost - $income, 2), 0, 'Waste loss'];
        $lines[] = [$wip, 0, $cost, 'Work in progress ' . $o['number']];
        if ($income - $cost > 0.004) $lines[] = [fin_acct_or_fail($cid, 'scrap_income'), 0, round($income - $cost, 2), 'Scrap sales gain'];
    } else {
        $lines = [[$loss, $cost, 0, 'Waste written off'], [$wip, 0, $cost, 'Work in progress ' . $o['number']]];
    }
    $lines = array_values(array_filter($lines, fn($l) => $l[1] > 0 || $l[2] > 0));
    db()->beginTransaction();
    try {
        $entry = $cost > 0 || $income > 0 ? fin_post($cid, date('Y-m-d'), 'Waste ' . $w['decision'] . ' ' . $o['number'], 'prep_waste', $wid, $lines, (int)$u['user_id']) : null;
        db()->prepare("UPDATE prep_waste SET status = 'Done', decision_note = ?, decided_by = ?, decided_at = NOW(), entry_id = ? WHERE id = ?")->execute([$note, $u['user_id'], $entry, $wid]);
        db()->commit();
    } catch (Throwable $e) { if (db()->inTransaction()) db()->rollBack(); throw $e; }
    return prod_detail($cid, $orderId);
}

/** after Finance returned a decision: choose again (rework / by-product return to stock now; destroy / scrap sale wait for Finance again) */
function prod_waste_redecide(array $u, int $orderId, int $wid, array $b): array {
    prod_need($u, 'prep-orders', 'approve');
    $cid = (int)$u['company_id'];
    $o = prod_row($cid, $orderId);
    $st = db()->prepare('SELECT * FROM prep_waste WHERE id = ? AND order_id = ?'); $st->execute([$wid, $orderId]);
    $w = $st->fetch();
    if (!$w) error_response('Waste line not found', 404);
    if (!in_array($w['status'], ['Returned', 'Pending'], true)) error_response('This waste line is already ' . $w['status'], 409);
    $dec = $b['decision'] ?? '';
    if (!in_array($dec, PROD_DECISIONS, true)) error_response('decision must be rework, by_product, scrap_sale or destroy', 422);
    $spec = prod_spec_row($cid, (int)$o['spec_id']);
    if ($dec === 'by_product' && !$spec['byproduct_item_id']) error_response('This specification has no by-product item', 422);
    $inc = null;
    if ($dec === 'scrap_sale') { if (!isset($b['scrap_income']) || !is_numeric($b['scrap_income']) || (float)$b['scrap_income'] < 0) error_response('A scrap sale needs the income (0 or more)', 422); $inc = round((float)$b['scrap_income'], 2); }
    $w['decision'] = $dec;
    if (in_array($dec, ['rework', 'by_product'], true)) {
        $room = (int)($b['cold_room_id'] ?? 0);
        if (!$room) error_response('Choose the cold room for the waste that goes back to stock', 422);
        $cost = (float)$w['cost']; $unit = (float)$w['kg'] > 0 ? $cost / (float)$w['kg'] : 0.0;
        $wip = $cost > 0 ? fin_acct_or_fail($cid, 'wip') : null; $inv = $cost > 0 ? fin_acct_or_fail($cid, 'inventory') : null;
        db()->beginTransaction();
        try {
            [$bid] = prod_waste_to_stock($u, $o, $spec, $w, $room, $unit);
            $entry = $cost > 0 ? fin_post($cid, date('Y-m-d'), 'Waste ' . $dec . ' ' . $o['number'], 'prep_waste', $wid, [[$inv, $cost, 0, 'Back to stock'], [$wip, 0, $cost, 'Work in progress ' . $o['number']]], (int)$u['user_id']) : null;
            db()->prepare("UPDATE prep_waste SET decision = ?, scrap_income = NULL, status = 'Done', batch_id = ?, decided_by = ?, decided_at = NOW(), decision_note = ?, entry_id = ? WHERE id = ?")->execute([$dec, $bid, $u['user_id'], prod_text($b['note'] ?? null), $entry, $wid]);
            db()->commit();
        } catch (Throwable $e) { if (db()->inTransaction()) db()->rollBack(); throw $e; }
    } else {
        db()->prepare("UPDATE prep_waste SET decision = ?, scrap_income = ?, status = 'Pending', decision_note = ? WHERE id = ?")->execute([$dec, $inc, prod_text($b['note'] ?? null), $wid]);
    }
    return prod_detail($cid, $orderId);
}

// ─── food-safety logs ────────────────────────────────────────

function haccp_create(array $u, array $b): array {
    $cid = (int)$u['company_id'];
    $kind = $b['kind'] ?? '';
    if (!in_array($kind, ['wash_water', 'sanitiser', 'hygiene'], true)) error_response('kind must be wash_water, sanitiser or hygiene', 422);
    $order = !empty($b['prep_order_id']) ? (int)$b['prep_order_id'] : null;
    if ($order) { $o = prod_row($cid, $order); if (!in_array($o['status'], ['Approved', 'In_Progress', 'Awaiting_Inspection'], true)) error_response('This order is ' . $o['status'] . ': logs are recorded while it runs', 409); }
    $value = null; $person = null;
    if ($kind === 'wash_water') {
        if (!isset($b['value']) || !is_numeric($b['value'])) error_response('value (the water temperature in °C) is required', 422);
        $value = round((float)$b['value'], 2);
        $ok = $value <= (float)inv_setting($cid, 'production.wash_water_max_c', '10') + 0.0001;
    } elseif ($kind === 'sanitiser') {
        if (!isset($b['value']) || !is_numeric($b['value']) || (float)$b['value'] < 0) error_response('value (the sanitiser level in ppm) is required', 422);
        $value = round((float)$b['value'], 2);
        $ok = $value >= (float)inv_setting($cid, 'production.sanitiser_min_ppm', '100') - 0.0001 && $value <= (float)inv_setting($cid, 'production.sanitiser_max_ppm', '200') + 0.0001;
    } else {
        $person = prod_text($b['person'] ?? null, 120);
        if (!$person) error_response('The name of the person checked is required', 422);
        if (!array_key_exists('passed', $b)) error_response('passed (true / false) is required', 422);
        $ok = (bool)$b['passed'];
    }
    $action = prod_text($b['corrective_action'] ?? null);
    if (!$ok && !$action) error_response('This reading is out of the accepted range: record the corrective action', 422);
    db()->prepare('INSERT INTO haccp_logs (company_id, kind, prep_order_id, value, person, ok, note, corrective_action, created_by) VALUES (?,?,?,?,?,?,?,?,?)')
        ->execute([$cid, $kind, $order, $value, $person, $ok ? 1 : 0, prod_text($b['note'] ?? null), $action, $u['user_id']]);
    $q = db()->prepare('SELECT * FROM haccp_logs WHERE id = ?'); $q->execute([db()->lastInsertId()]);
    return $q->fetch();
}

// ─── reports ─────────────────────────────────────────────────

function prod_summary(int $cid): array {
    $one = function (string $sql, array $p = []) { $q = db()->prepare($sql); $q->execute($p); return $q->fetchColumn(); };
    $m = date('Y-m-01');
    $agg = db()->prepare("SELECT COALESCE(SUM(input_kg), 0) AS i, COALESCE(SUM(output_kg), 0) AS o, COALESCE(SUM(waste_kg), 0) AS w FROM prep_orders WHERE company_id = ? AND status = 'Completed' AND inspected_at >= ?");
    $agg->execute([$cid, $m]); $a = $agg->fetch();
    return [
        'draft' => (int)$one("SELECT COUNT(*) FROM prep_orders WHERE company_id = ? AND status = 'Draft'", [$cid]),
        'approved' => (int)$one("SELECT COUNT(*) FROM prep_orders WHERE company_id = ? AND status = 'Approved'", [$cid]),
        'in_progress' => (int)$one("SELECT COUNT(*) FROM prep_orders WHERE company_id = ? AND status = 'In_Progress'", [$cid]),
        'awaiting_inspection' => (int)$one("SELECT COUNT(*) FROM prep_orders WHERE company_id = ? AND status = 'Awaiting_Inspection'", [$cid]),
        'waste_pending' => (int)$one("SELECT COUNT(*) FROM prep_waste w JOIN prep_orders o ON o.id = w.order_id WHERE o.company_id = ? AND w.status IN ('Pending','Returned')", [$cid]),
        'over_time_running' => (int)$one("SELECT COUNT(*) FROM prep_orders o JOIN prep_specs s ON s.id = o.spec_id WHERE o.company_id = ? AND o.status = 'In_Progress' AND TIMESTAMPDIFF(MINUTE, o.started_at, NOW()) > s.max_minutes_outside", [$cid]),
        'unsafe_logs_week' => (int)$one("SELECT COUNT(*) FROM haccp_logs WHERE company_id = ? AND ok = 0 AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)", [$cid]),
        'month_input_kg' => round((float)$a['i'], 3), 'month_output_kg' => round((float)$a['o'], 3), 'month_waste_kg' => round((float)$a['w'], 3),
        'month_yield_pct' => (float)$a['i'] > 0 ? round((float)$a['o'] / (float)$a['i'] * 100, 1) : null,
    ];
}

function prod_yield_report(int $cid, string $from, string $to): array {
    $base = "o.company_id = ? AND o.status = 'Completed' AND DATE(o.inspected_at) BETWEEN ? AND ?";
    $p = [$cid, $from, $to];
    $rows = function (string $sql) use ($p) { $q = db()->prepare($sql); $q->execute($p); return $q->fetchAll(); };
    $pct = fn($a, $b) => (float)$b > 0 ? round((float)$a / (float)$b * 100, 1) : null;
    $spec = array_map(fn($r) => ['spec_id' => (int)$r['id'], 'code' => $r['code'], 'name_en' => $r['name_en'], 'name_ar' => $r['name_ar'], 'orders' => (int)$r['n'], 'input_kg' => round((float)$r['i'], 3), 'output_kg' => round((float)$r['o'], 3), 'waste_kg' => round((float)$r['w'], 3),
                    'yield_pct' => $pct($r['o'], $r['i']), 'planned_yield_pct' => (float)$r['py'], 'over_time_orders' => (int)$r['ot']],
        $rows("SELECT s.id, s.code, s.name_en, s.name_ar, s.planned_yield_pct AS py, COUNT(o.id) AS n, SUM(o.input_kg) AS i, SUM(o.output_kg) AS o, SUM(o.waste_kg) AS w, SUM(o.over_time) AS ot FROM prep_orders o JOIN prep_specs s ON s.id = o.spec_id WHERE $base GROUP BY s.id, s.code, s.name_en, s.name_ar, s.planned_yield_pct ORDER BY s.code"));
    // loss follows the material: each input batch carries its share of the order's waste
    $supplier = array_map(fn($r) => ['id' => $r['id'] !== null ? (int)$r['id'] : null, 'name' => $r['name'] ?? '—', 'input_kg' => round((float)$r['i'], 3), 'waste_kg' => round((float)$r['w'], 3), 'loss_pct' => $pct($r['w'], $r['i'])],
        $rows("SELECT pi.supplier_id AS id, s.name AS name, SUM(pi.kg) AS i, SUM(pi.kg * o.waste_kg / o.input_kg) AS w FROM prep_inputs pi JOIN prep_orders o ON o.id = pi.order_id LEFT JOIN suppliers s ON s.id = pi.supplier_id WHERE o.input_kg > 0 AND $base GROUP BY pi.supplier_id, s.name ORDER BY w DESC"));
    $room = array_map(fn($r) => ['id' => $r['id'] !== null ? (int)$r['id'] : null, 'name' => $r['name'] ?? '—', 'input_kg' => round((float)$r['i'], 3), 'waste_kg' => round((float)$r['w'], 3), 'loss_pct' => $pct($r['w'], $r['i'])],
        $rows("SELECT pi.cold_room_id AS id, r.name AS name, SUM(pi.kg) AS i, SUM(pi.kg * o.waste_kg / o.input_kg) AS w FROM prep_inputs pi JOIN prep_orders o ON o.id = pi.order_id LEFT JOIN cold_rooms r ON r.id = pi.cold_room_id WHERE o.input_kg > 0 AND $base GROUP BY pi.cold_room_id, r.name ORDER BY w DESC"));
    $reason = array_map(fn($r) => ['reason_code' => $r['code'], 'name_en' => $r['name_en'], 'name_ar' => $r['name_ar'], 'waste_class' => $r['waste_class'], 'kg' => round((float)$r['kg'], 3)],
        $rows("SELECT w.reason_code AS code, MAX(r.name_en) AS name_en, MAX(r.name_ar) AS name_ar, w.waste_class, SUM(w.kg) AS kg FROM prep_waste w JOIN prep_orders o ON o.id = w.order_id LEFT JOIN prep_waste_reasons r ON r.company_id = o.company_id AND r.code = w.reason_code WHERE $base GROUP BY w.reason_code, w.waste_class ORDER BY kg DESC"));
    return ['from' => $from, 'to' => $to, 'by_spec' => $spec, 'by_supplier' => $supplier, 'by_cold_room' => $room, 'by_waste_reason' => $reason];
}
