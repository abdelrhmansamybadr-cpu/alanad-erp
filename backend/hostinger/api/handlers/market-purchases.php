<?php
// Market buying (rules in core/market.php).
//   GET  /market-purchases[?status=&mine=1]    GET /market-purchases/{id}     GET /market-purchases/custody  (open cash per buyer)
//   POST /market-purchases {market_name, supplier_id, client_order_id?, notes?, branch_id?, lines:[{item_id, qty_kg, offer_price}]}
//   PUT  /market-purchases/{id}                the buyer, while Draft / Returned
//   POST /market-purchases/{id}/inspect {line_id, result: Pass|Fail, grade?, note?}   the quality person (market-purchases.inspect), never the buyer
//   POST /market-purchases/{id}/submit         the buyer → Finance
//   POST /market-purchases/{id}/return {reason}     Finance sends it back
//   POST /market-purchases/{id}/approve        Finance (market-purchases.approve, never the buyer): creates the Approved purchase order
//   POST /market-purchases/{id}/issue-cash {amount?}   Finance gives the buyer cash (custody)
//   POST /market-purchases/{id}/settle {spent_amount, returned_amount, note}        the buyer, with receipts
//   POST /market-purchases/{id}/reject-settlement {reason}   |   POST /market-purchases/{id}/confirm-settlement
//   POST /market-purchases/{id}/cancel {reason}
require_once __DIR__ . '/../core/market.php';
$u = $GLOBALS['auth_user']; $cid = (int)$u['company_id']; $method = $GLOBALS['method']; $body = $GLOBALS['body'];
$seg = $GLOBALS['parts'][1] ?? '';
$action = $GLOBALS['parts'][2] ?? '';
$id = ctype_digit((string)$seg) ? (int)$seg : 0;

if ($method === 'GET') {
    if ($seg === 'custody') json_response(mk_custody_report($cid));
    if ($id) json_response(mk_detail($cid, $id));
    $w = 'm.company_id = ?' . branch_scope_sql('m.branch_id'); $p = [$cid];
    if (!empty($_GET['status'])) { $w .= ' AND m.status = ?'; $p[] = $_GET['status']; }
    if (!empty($_GET['mine'])) { $w .= ' AND m.buyer_id = ?'; $p[] = $u['user_id']; }
    $q = db()->prepare("SELECT m.id, m.number, m.market_name, m.status, m.buyer_id, bu.name AS buyer_name, m.supplier_id, s.name AS supplier_name, m.branch_id, m.total_amount, m.issued_amount, m.due_date, m.po_id,
                               (m.status = 'Cash_Issued' AND m.due_date < CURDATE()) AS is_overdue,
                               (SELECT COALESCE(SUM(qty_kg * offer_price),0) FROM market_purchase_lines WHERE purchase_id = m.id) AS offer_total,
                               (SELECT COUNT(*) FROM market_purchase_lines WHERE purchase_id = m.id AND inspect_result = 'Pending') AS pending_lines
                        FROM market_purchases m JOIN users bu ON bu.id = m.buyer_id JOIN suppliers s ON s.id = m.supplier_id WHERE $w ORDER BY m.id DESC LIMIT 300");
    $q->execute($p);
    json_response(array_map(function ($r) { foreach (['total_amount', 'issued_amount', 'offer_total'] as $f) if ($r[$f] !== null) $r[$f] = (float)$r[$f]; $r['is_overdue'] = (bool)$r['is_overdue']; return $r; }, $q->fetchAll()));
}
if ($method === 'POST' && !$id) json_response(mk_create($u, $body), 201);
if ($method === 'PUT' && $id) json_response(mk_update($u, $id, $body));
if ($method === 'POST' && $id) {
    switch ($action) {
        case 'inspect':             json_response(mk_inspect($u, $id, $body));
        case 'submit':              json_response(mk_submit($u, $id));
        case 'return':              json_response(mk_return($u, $id, $body));
        case 'approve':             json_response(mk_approve($u, $id));
        case 'issue-cash':          json_response(mk_issue_cash($u, $id, $body));
        case 'settle':              json_response(mk_settle($u, $id, $body));
        case 'reject-settlement':   json_response(mk_reject_settlement($u, $id, $body));
        case 'confirm-settlement':  json_response(mk_confirm_settlement($u, $id));
        case 'cancel':              json_response(mk_cancel($u, $id, $body));
    }
}
error_response('Not found', 404);
