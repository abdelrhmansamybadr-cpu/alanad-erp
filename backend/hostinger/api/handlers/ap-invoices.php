<?php
// Supplier invoices with a 3-way match (rules in core/finance.php).
//   GET  /ap-invoices[?status=&supplier_id=&po_id=&search=]     GET /ap-invoices/{id}  (lines with the match result)
//   POST /ap-invoices {po_id, supplier_invoice_no, invoice_date?, due_date?, vat_rate_pct?, notes?, lines:[{item_id, qty_kg, price}]}   Draft
//   PUT  /ap-invoices/{id}                  edit a Draft / Rejected invoice (the person who entered it)
//   POST /ap-invoices/{id}/submit           runs the 3-way match: Pending (matched) or On_Hold (differences found)
//   POST /ap-invoices/{id}/decide {action: approve|reject, reason_code?, note?}   ap-invoices.approve, never the person who entered it; On_Hold needs a note to approve
//   GET  /ap-invoices/receivable?po_id=     per item of an order: ordered, received (net, stored), already invoiced, still billable, order price
//   DELETE /ap-invoices/{id}                Draft / Rejected only
require_once __DIR__ . '/../core/finance.php';
require_once __DIR__ . '/../core/inventory.php';
$u = $GLOBALS['auth_user']; $cid = (int)$u['company_id']; $id = $GLOBALS['id']; $method = $GLOBALS['method']; $body = $GLOBALS['body'];
$action = $GLOBALS['parts'][2] ?? '';

if ($method === 'GET') {
    if ($id === 'receivable') {
        $po = inv_po_status($cid, (int)($_GET['po_id'] ?? 0));
        $q = db()->prepare("SELECT l.item_id, SUM(l.qty_kg) AS kg FROM ap_invoice_lines l JOIN ap_invoices i ON i.id = l.invoice_id WHERE i.company_id = ? AND i.purchase_order_id = ? AND i.status IN ('Pending','On_Hold','Approved') GROUP BY l.item_id");
        $q->execute([$cid, $po['id']]);
        $billed = []; foreach ($q->fetchAll() as $r) $billed[(int)$r['item_id']] = (float)$r['kg'];
        $rate = db()->prepare('SELECT SUM(qty * rate) / NULLIF(SUM(qty), 0) FROM purchase_order_lines WHERE purchase_order_id = ? AND item_id = ?');
        $lines = [];
        foreach ($po['lines'] as $l) {
            $rate->execute([$po['id'], $l['item_id']]);
            $lines[] = ['item_id' => $l['item_id'], 'item_name' => $l['item_name'], 'item_name_ar' => $l['item_name_ar'], 'ordered' => $l['ordered'], 'received' => $l['received'],
                        'invoiced' => round($billed[$l['item_id']] ?? 0, 3), 'billable' => round(max(0, $l['received'] - ($billed[$l['item_id']] ?? 0)), 3), 'po_price' => round((float)$rate->fetchColumn(), 4)];
        }
        json_response(['po_id' => $po['id'], 'number' => $po['number'], 'supplier_id' => $po['supplier_id'], 'supplier_name' => $po['supplier_name'], 'lines' => $lines]);
    }
    if ($id) json_response(ap_detail($cid, (int)$id));
    $w = 'i.company_id = ?'; $p = [$cid];
    foreach (['status', 'supplier_id'] as $f) if (!empty($_GET[$f])) { $w .= " AND i.$f = ?"; $p[] = $_GET[$f]; }
    if (!empty($_GET['po_id'])) { $w .= ' AND i.purchase_order_id = ?'; $p[] = (int)$_GET['po_id']; }
    if (!empty($_GET['search'])) { $w .= ' AND (i.number LIKE ? OR i.supplier_invoice_no LIKE ?)'; $p[] = '%' . $_GET['search'] . '%'; $p[] = '%' . $_GET['search'] . '%'; }
    $q = db()->prepare("SELECT i.id, i.number, i.created_by, i.supplier_invoice_no, i.invoice_date, i.due_date, i.status, i.match_status, i.subtotal, i.vat_amount, i.total, i.paid_amount, i.supplier_id, s.name AS supplier_name, i.purchase_order_id, po.number AS po_number,
                               (i.total - i.paid_amount) AS open_amount, (i.status = 'Approved' AND i.total - i.paid_amount > 0.004 AND i.due_date < CURDATE()) AS overdue
                        FROM ap_invoices i JOIN suppliers s ON s.id = i.supplier_id JOIN purchase_orders po ON po.id = i.purchase_order_id WHERE $w ORDER BY i.id DESC LIMIT 300");
    $q->execute($p);
    json_response(array_map(function ($r) { foreach (['subtotal', 'vat_amount', 'total', 'paid_amount', 'open_amount'] as $f) $r[$f] = (float)$r[$f]; $r['overdue'] = (bool)$r['overdue']; return $r; }, $q->fetchAll()));
}
if ($method === 'POST' && !$id) json_response(ap_create($u, $body), 201);
if ($method === 'POST' && $id && $action === 'submit') json_response(ap_submit($u, (int)$id));
if ($method === 'POST' && $id && $action === 'decide') json_response(ap_decide($u, (int)$id, $body));
if ($method === 'PUT' && $id) json_response(ap_update($u, (int)$id, $body));
if ($method === 'DELETE' && $id) {
    $inv = ap_row($cid, (int)$id);
    if (!in_array($inv['status'], ['Draft', 'Rejected'], true)) error_response('Only a draft or rejected invoice can be deleted', 409);
    if ((int)$inv['created_by'] !== (int)$u['user_id'] && $u['role'] !== 'admin') error_response('Only the person who entered the invoice can delete it', 403);
    db()->prepare('DELETE FROM ap_invoices WHERE id = ? AND company_id = ?')->execute([$id, $cid]);
    json_response(['message' => 'Deleted']);
}
error_response('Not found', 404);
