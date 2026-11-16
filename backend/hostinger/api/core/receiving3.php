<?php
// ─── Three people at the truck ───
//   1. quality (the quality step and its sign-off)   2. the counter (counts the units)   3. a Finance person (papers + physical goods)
// then the delivery is routed: straight to sorting (الفرز) or straight to the store (put-away).
// With setting receiving.three_person_rule = 1 the three must be three different people and the order is enforced.
require_once __DIR__ . '/receiving.php';

function rcv3_on(int $cid): bool { return inv_setting($cid, 'receiving.three_person_rule', '0') === '1'; }

function rcv3_need(array $u, string $act): void {
    if (!permission_allows($u['permissions'], 'deliveries', $act)) error_response("Forbidden: you do not have permission to $act deliveries", 403);
}

/** called by the quality steps: the counter and the Finance person may not also be the quality person */
function rcv3_guard_quality(array $u, array $d): void {
    if (!rcv3_on((int)$u['company_id'])) return;
    $me = (int)$u['user_id'];
    if ($d['counted_by'] !== null && (int)$d['counted_by'] === $me) error_response('You counted this truck: a different person must do the quality check', 403);
    if ($d['finance_by'] !== null && (int)$d['finance_by'] === $me) error_response('You are the Finance person on this truck: a different person must do the quality check', 403);
}

/** the route the system suggests: sorting if any item on the delivery needs it, else the store */
function rcv3_suggest(int $deliveryId): string {
    $q = db()->prepare('SELECT COUNT(*) FROM delivery_lines l JOIN items i ON i.id = l.item_id WHERE l.delivery_id = ? AND i.needs_sorting = 1 AND l.received_kg - l.rejected_kg > 0');
    $q->execute([$deliveryId]);
    return (int)$q->fetchColumn() > 0 ? 'sorting' : 'store';
}

function rcv3_info(int $cid, array $d): array {
    $names = [];
    foreach (['quality_by', 'accepted_by', 'counted_by', 'finance_by', 'routed_by'] as $f) {
        if ($d[$f] !== null) { $q = db()->prepare('SELECT name FROM users WHERE id = ?'); $q->execute([$d[$f]]); $names[$f] = $q->fetchColumn() ?: null; }
    }
    $diff = null;
    if ($d['counted_units'] !== null && $d['note_units'] !== null && (int)$d['note_units'] > 0) $diff = round(((int)$d['counted_units'] - (int)$d['note_units']) / (int)$d['note_units'] * 100, 2);
    return [
        'rule_on' => rcv3_on($cid), 'suggested_route' => rcv3_suggest((int)$d['id']), 'count_diff_pct' => $diff,
        'count_tolerance_pct' => (float)inv_setting($cid, 'receiving.count_tolerance_pct', '1'), 'people' => $names,
    ];
}

/** The counter counts the units (crates, boxes, bags) that came off the truck. */
function rcv3_count(array $u, int $id, array $b): array {
    rcv3_need($u, 'count');
    $cid = (int)$u['company_id'];
    $d = rcv_row($cid, $id);
    rcv_open($d, ['Arrived', 'Temp_Hold', 'Checking', 'Ready']);
    if ($d['finance_status'] === 'Passed') error_response('Finance has already confirmed this truck', 409);
    $units = $b['units'] ?? null;
    if (!is_numeric($units) || (int)$units != $units || (int)$units <= 0) error_response('units must be a whole number greater than zero', 422);
    if (rcv3_on($cid)) {
        $me = (int)$u['user_id'];
        foreach (['quality_by' => 'did the quality check', 'accepted_by' => 'signed off the quality', 'finance_by' => 'is the Finance person'] as $f => $why) {
            if ($d[$f] !== null && (int)$d[$f] === $me) error_response("You $why on this truck: a different person must count", 403);
        }
    }
    $unit = isset($b['unit']) && trim((string)$b['unit']) !== '' ? mb_substr(trim((string)$b['unit']), 0, 20) : 'crate';
    $note = isset($b['note']) && trim((string)$b['note']) !== '' ? mb_substr(trim((string)$b['note']), 0, 500) : null;
    db()->prepare("UPDATE deliveries SET counted_units = ?, count_unit = ?, counted_by = ?, counted_at = NOW(), count_note = ?, finance_status = IF(finance_status = 'Disputed', NULL, finance_status) WHERE id = ?")
        ->execute([(int)$units, $unit, $u['user_id'], $note, $id]);
    return rcv_detail($cid, $id);
}

/** The Finance person confirms the papers (delivery note matches the order) and the physical goods (the count matches the note). */
function rcv3_finance(array $u, int $id, array $b): array {
    rcv3_need($u, 'finance');
    $cid = (int)$u['company_id'];
    $d = rcv_row($cid, $id);
    rcv_open($d, ['Ready']);
    $on = rcv3_on($cid);
    if ($on) {
        if ($d['counted_units'] === null) error_response('The counter must count the units first', 409);
        $me = (int)$u['user_id'];
        foreach (['quality_by' => 'did the quality check', 'accepted_by' => 'signed off the quality', 'counted_by' => 'counted the units'] as $f => $why) {
            if ($d[$f] !== null && (int)$d[$f] === $me) error_response("You $why on this truck: a different person must confirm for Finance", 403);
        }
    }
    $papers = filter_var($b['papers_ok'] ?? null, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    $goods = filter_var($b['goods_ok'] ?? null, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    if ($papers === null || $goods === null) error_response('Say whether the papers and the goods are correct (papers_ok, goods_ok)', 422);
    $note = isset($b['note']) && trim((string)$b['note']) !== '' ? mb_substr(trim((string)$b['note']), 0, 500) : null;
    $dn = isset($b['delivery_note_no']) && trim((string)$b['delivery_note_no']) !== '' ? mb_substr(trim((string)$b['delivery_note_no']), 0, 60) : null;
    $nu = null;
    if (isset($b['note_units']) && $b['note_units'] !== '') {
        if (!is_numeric($b['note_units']) || (int)$b['note_units'] != $b['note_units'] || (int)$b['note_units'] <= 0) error_response('note_units must be a whole number greater than zero', 422);
        $nu = (int)$b['note_units'];
    }
    $ok = $papers && $goods;
    if ($papers && !$dn) error_response('Write the delivery note number the supplier gave', 422);
    if (!$ok && !$note) error_response('Explain what is wrong with the papers or the goods', 422);
    if ($ok && $d['counted_units'] !== null && $nu !== null) {
        $tol = (float)inv_setting($cid, 'receiving.count_tolerance_pct', '1');
        $diff = abs((int)$d['counted_units'] - $nu) / $nu * 100;
        if ($diff > $tol + 0.0001 && !$note) error_response('The counted units differ from the delivery note by ' . round($diff, 1) . ' % (allowed ' . $tol . ' %): write why Finance accepts it', 422);
    }
    db()->prepare('UPDATE deliveries SET finance_status = ?, finance_by = ?, finance_at = NOW(), delivery_note_no = ?, note_units = ?, papers_ok = ?, goods_ok = ?, finance_note = ?, route = NULL, routed_by = NULL, routed_at = NULL WHERE id = ?')
        ->execute([$ok ? 'Passed' : 'Disputed', $u['user_id'], $dn, $nu, $papers ? 1 : 0, $goods ? 1 : 0, $note, $id]);
    return rcv_detail($cid, $id);
}

/** After Finance: straight to sorting or straight to the store. */
function rcv3_route(array $u, int $id, array $b): array {
    rcv3_need($u, 'finance');
    $cid = (int)$u['company_id'];
    $d = rcv_row($cid, $id);
    rcv_open($d, ['Ready', 'To_Sorting']);
    if (rcv3_on($cid) && $d['finance_status'] !== 'Passed') error_response('Finance must confirm the papers and the goods before the truck is routed', 409);
    $to = $b['to'] ?? '';
    if (!in_array($to, ['store', 'sorting'], true)) error_response("to must be 'store' or 'sorting'", 422);
    $status = $to === 'sorting' ? 'To_Sorting' : 'Ready';
    db()->prepare('UPDATE deliveries SET route = ?, status = ?, routed_by = ?, routed_at = NOW() WHERE id = ?')->execute([$to, $status, $u['user_id'], $id]);
    return rcv_detail($cid, $id);
}

/** put-away is allowed only on the store route once Finance passed (when the three-person rule is on) */
function rcv3_guard_put_away(int $cid, array $d): void {
    if (!rcv3_on($cid)) return;
    if ($d['finance_status'] !== 'Passed') error_response('Finance must confirm the papers and the goods before the goods are stored', 409);
    if ($d['route'] !== 'store') error_response('Route this delivery to the store first (Finance → route)', 409);
}
