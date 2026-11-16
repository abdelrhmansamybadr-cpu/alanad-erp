<?php
// Stock operations. Every change goes through the append-only ledger (core/inventory.php).
//   GET  /stock/balance[?item_id=&cold_room_id=]      balances per item / batch / room / rack / position (positive only)
//   GET  /stock/use-first[?days=3]                    batches expiring soon (oldest expiry first) + already expired
//   GET  /stock/ledger[?item_id=&batch_id=&cold_room_id=&movement=&page=&per_page=]
//   POST /stock/receive  {item_id, qty, cold_room_id, expiry_date|(shelf life), arrival_date?, batch_no?, grade?, rack?, position?, supplier_id?}
//   POST /stock/issue    {item_id, qty, cold_room_id?, reason?}      FEFO allocation, returns the batches used
//   POST /stock/waste    {item_id, qty, reason}                      same as issue, reason mandatory, may use expired stock
//   POST /stock/transfer {batch_id, qty, from_cold_room_id, to_cold_room_id, from_rack?, from_position?, to_rack?, to_position?}
//   GET  /stock/po/{id}                          a purchase order with ordered / received / remaining per item
//   POST /stock/receive-po {po_id, close?, lines:[{item_id, qty, cold_room_id, expiry_date?, grade?, rack?, position?, batch_no?}]}   goods receipt against an approved order
//   POST /stock/adjust   {batch_id, cold_room_id, qty_delta, reason, rack?, position?}   needs stock.approve
require_once __DIR__ . '/../core/inventory.php';
$u = $GLOBALS['auth_user']; $cid = (int)$u['company_id']; $method = $GLOBALS['method']; $body = $GLOBALS['body'];
$action = $GLOBALS['parts'][1] ?? '';

if ($method === 'GET') {
    if ($action === 'balance') {
        $w = 'l.company_id = ?' . branch_wh_sql('l.warehouse_id'); $p = [$cid];
        if (!empty($_GET['item_id'])) { $w .= ' AND l.item_id = ?'; $p[] = (int)$_GET['item_id']; }
        if (!empty($_GET['cold_room_id'])) { $w .= ' AND l.cold_room_id = ?'; $p[] = (int)$_GET['cold_room_id']; }
        $q = db()->prepare("SELECT l.item_id, i.name AS item_name, i.name_ar AS item_name_ar, l.batch_id, b.batch_no, b.expiry_date, b.arrival_date, b.grade,
                                   l.warehouse_id, l.cold_room_id, r.name AS cold_room_name, r.status AS cold_room_status, l.rack, l.position, ROUND(SUM(l.qty), 3) AS qty,
                                   (b.expiry_date < CURDATE()) AS expired, DATEDIFF(b.expiry_date, CURDATE()) AS days_left
                            FROM stock_ledger l JOIN items i ON i.id = l.item_id JOIN item_batches b ON b.id = l.batch_id LEFT JOIN cold_rooms r ON r.id = l.cold_room_id
                            WHERE $w GROUP BY l.item_id, i.name, i.name_ar, l.batch_id, b.batch_no, b.expiry_date, b.arrival_date, b.grade, l.warehouse_id, l.cold_room_id, r.name, r.status, l.rack, l.position
                            HAVING SUM(l.qty) > 0.0005 ORDER BY b.expiry_date ASC, l.batch_id ASC");
        $q->execute($p);
        $rows = array_map(function ($r) { $r['expired'] = (bool)$r['expired']; $r['days_left'] = (int)$r['days_left']; $r['qty'] = (float)$r['qty']; return $r; }, $q->fetchAll());
        json_response($rows);
    }
    if ($action === 'use-first') {
        $days = isset($_GET['days']) ? max(0, (int)$_GET['days']) : (int)inv_setting($cid, 'stock.use_first_days', '3');
        $q = db()->prepare("SELECT l.item_id, i.name AS item_name, i.name_ar AS item_name_ar, l.batch_id, b.batch_no, b.expiry_date, ROUND(SUM(l.qty), 3) AS qty, l.cold_room_id, r.name AS cold_room_name,
                                   DATEDIFF(b.expiry_date, CURDATE()) AS days_left
                            FROM stock_ledger l JOIN items i ON i.id = l.item_id JOIN item_batches b ON b.id = l.batch_id LEFT JOIN cold_rooms r ON r.id = l.cold_room_id
                            WHERE l.company_id = ? AND b.expiry_date <= (CURDATE() + INTERVAL ? DAY)" . branch_wh_sql('l.warehouse_id') . "
                            GROUP BY l.item_id, i.name, i.name_ar, l.batch_id, b.batch_no, b.expiry_date, l.cold_room_id, r.name
                            HAVING SUM(l.qty) > 0.0005 ORDER BY b.expiry_date ASC");
        $q->execute([$cid, $days]);
        $rows = array_map(function ($r) { $r['days_left'] = (int)$r['days_left']; $r['qty'] = (float)$r['qty']; $r['expired'] = $r['days_left'] < 0; return $r; }, $q->fetchAll());
        json_response($rows);
    }
    if ($action === 'po') json_response(inv_po_status($cid, (int)($GLOBALS['parts'][2] ?? 0)));
    if ($action === 'ledger') {
        $w = 'company_id = ?' . branch_wh_sql('warehouse_id'); $p = [$cid];
        foreach (['item_id', 'batch_id', 'cold_room_id', 'movement'] as $f) if (!empty($_GET[$f])) { $w .= " AND $f = ?"; $p[] = $_GET[$f]; }
        $page = max(1, (int)($_GET['page'] ?? 1)); $per = min(200, max(1, (int)($_GET['per_page'] ?? 50)));
        json_response(paginate("SELECT * FROM stock_ledger WHERE $w ORDER BY id DESC", $p, $page, $per));
    }
    error_response('Not found', 404);
}

if ($method === 'POST') {
    switch ($action) {
        case 'receive':  unset($body['ref_type'], $body['ref_id']); json_response(inv_receive($u, $body), 201);   // links to orders only through receive-po
        case 'receive-po':
            // the normal way in is the gate: arrival, temperature, quality, put-away (deliveries). Direct receipt is a setting for small sites.
            if (inv_setting($cid, 'receiving.allow_direct_po_receipt', '0') !== '1') error_response('Goods are received through a delivery (gate, temperature and quality checks first)', 409);
            json_response(inv_receive_po($u, $body), 201);
        case 'issue':    json_response(inv_issue($u, $body, 'issue'));
        case 'waste':    json_response(inv_issue($u, $body, 'waste'));
        case 'transfer': json_response(inv_transfer($u, $body));
        case 'adjust':
            if (!permission_allows($u['permissions'], 'stock', 'approve')) error_response('Stock adjustments need approval rights', 403);
            json_response(inv_adjust($u, $body));
    }
}
error_response('Not found', 404);
