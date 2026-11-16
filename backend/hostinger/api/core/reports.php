<?php
// ─── Factory reports: one overview over a date range, from the real data of every phase ───
require_once __DIR__ . '/workflow.php';
require_once __DIR__ . '/hr.php';

function rp_one(string $sql, array $p): array {
    $q = db()->prepare($sql); $q->execute($p);
    return $q->fetch() ?: [];
}
function rp_all(string $sql, array $p): array {
    $q = db()->prepare($sql); $q->execute($p);
    return $q->fetchAll();
}
function rp_f(mixed $v, int $d = 3): float { return round((float)$v, $d); }
function rp_pct(float $a, float $b): float { return $b > 0 ? round($a / $b * 100, 1) : 0.0; }

function rp_overview(int $cid, string $from, string $to): array {
    $p = [$cid, $from, $to];

    // receiving
    $r = rp_one("SELECT COUNT(*) AS trucks, COALESCE(SUM(status = 'Rejected'),0) AS rejected_trucks, AVG(CASE WHEN accepted_at IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, arrived_at, accepted_at) END) AS avg_minutes
                 FROM deliveries d WHERE d.company_id = ? AND DATE(d.arrived_at) BETWEEN ? AND ?" . branch_scope_sql('d.branch_id'), $p);
    $l = rp_one("SELECT COALESCE(SUM(l.received_kg),0) AS received_kg, COALESCE(SUM(l.rejected_kg),0) AS rejected_kg FROM delivery_lines l JOIN deliveries d ON d.id = l.delivery_id
                 WHERE d.company_id = ? AND DATE(d.arrived_at) BETWEEN ? AND ?" . branch_scope_sql('d.branch_id'), $p);
    $receiving = ['trucks' => (int)$r['trucks'], 'rejected_trucks' => (int)$r['rejected_trucks'], 'received_kg' => rp_f($l['received_kg']), 'rejected_kg' => rp_f($l['rejected_kg']),
                  'reject_pct' => rp_pct((float)$l['rejected_kg'], (float)$l['received_kg']), 'avg_minutes_to_accept' => $r['avg_minutes'] !== null ? (int)round($r['avg_minutes']) : null];

    // sorting: how the loads split into the three classes
    $s = rp_one("SELECT COUNT(DISTINCT r.id) AS runs, COALESCE(SUM(l.input_kg),0) AS input_kg, COALESCE(SUM(l.class1_kg),0) AS c1, COALESCE(SUM(l.class2_kg),0) AS c2, COALESCE(SUM(l.class3_kg),0) AS c3, COALESCE(SUM(l.shrink_kg),0) AS shrink
                 FROM sorting_runs r JOIN sorting_lines l ON l.run_id = r.id WHERE r.company_id = ? AND r.status = 'Completed' AND DATE(r.signed_at) BETWEEN ? AND ?" . branch_scope_sql('r.branch_id'), $p);
    $inc = rp_one("SELECT COUNT(*) AS n FROM sorting_incidents i JOIN sorting_runs r ON r.id = i.run_id WHERE i.company_id = ? AND DATE(i.filed_at) BETWEEN ? AND ?" . branch_scope_sql('r.branch_id'), $p);
    $by = rp_all("SELECT sp.name AS supplier, COUNT(DISTINCT r.id) AS runs, SUM(l.input_kg) AS input_kg, SUM(l.class1_kg) AS c1, SUM(l.class2_kg) AS c2, SUM(l.class3_kg) AS c3
                  FROM sorting_runs r JOIN deliveries d ON d.id = r.delivery_id JOIN suppliers sp ON sp.id = d.supplier_id JOIN sorting_lines l ON l.run_id = r.id
                  WHERE r.company_id = ? AND r.status = 'Completed' AND DATE(r.signed_at) BETWEEN ? AND ?" . branch_scope_sql('r.branch_id') . ' GROUP BY sp.id, sp.name ORDER BY SUM(l.class1_kg) / NULLIF(SUM(l.input_kg),0) DESC', $p);
    $in = (float)$s['input_kg'];
    $sorting = ['runs' => (int)$s['runs'], 'input_kg' => rp_f($in), 'class1_kg' => rp_f($s['c1']), 'class2_kg' => rp_f($s['c2']), 'class3_kg' => rp_f($s['c3']), 'shrink_kg' => rp_f($s['shrink']),
                'class1_pct' => rp_pct((float)$s['c1'], $in), 'class2_pct' => rp_pct((float)$s['c2'], $in), 'class3_pct' => rp_pct((float)$s['c3'], $in), 'shrink_pct' => rp_pct((float)$s['shrink'], $in), 'incidents' => (int)$inc['n'],
                'by_supplier' => array_map(fn($x) => ['supplier' => $x['supplier'], 'runs' => (int)$x['runs'], 'input_kg' => rp_f($x['input_kg']), 'class1_pct' => rp_pct((float)$x['c1'], (float)$x['input_kg']), 'class2_pct' => rp_pct((float)$x['c2'], (float)$x['input_kg']), 'class3_pct' => rp_pct((float)$x['c3'], (float)$x['input_kg'])], $by)];

    // preparation yield and packing
    $y = rp_one("SELECT COUNT(*) AS orders, AVG(yield_pct) AS yield_pct, AVG(waste_pct) AS waste_pct, COALESCE(SUM(input_kg),0) AS input_kg, COALESCE(SUM(output_kg),0) AS output_kg
                 FROM prep_orders o WHERE o.company_id = ? AND o.status = 'Completed' AND DATE(o.created_at) BETWEEN ? AND ?" . branch_scope_sql('o.branch_id'), $p);
    $k = rp_one("SELECT COUNT(*) AS orders, COALESCE(SUM(boxes_packed),0) AS boxes, COALESCE(SUM(cartons),0) AS cartons, COALESCE(SUM(pallets),0) AS pallets, COALESCE(SUM(issued_kg),0) AS kg, COALESCE(SUM(loss_kg),0) AS loss_kg
                 FROM pack_orders o WHERE o.company_id = ? AND o.status = 'Packed' AND DATE(o.packed_at) BETWEEN ? AND ?" . branch_scope_sql('o.branch_id'), $p);
    $production = ['prep_orders' => (int)$y['orders'], 'avg_yield_pct' => $y['yield_pct'] !== null ? rp_f($y['yield_pct'], 1) : null, 'avg_waste_pct' => $y['waste_pct'] !== null ? rp_f($y['waste_pct'], 1) : null];
    $packing = ['orders' => (int)$k['orders'], 'boxes' => (int)$k['boxes'], 'cartons' => (int)$k['cartons'], 'pallets' => (int)$k['pallets'], 'kg' => rp_f($k['kg']), 'loss_kg' => rp_f($k['loss_kg']), 'loss_pct' => rp_pct((float)$k['loss_kg'], (float)$k['kg'])];

    // sales and margin
    $v = rp_one("SELECT COUNT(*) AS invoices, COALESCE(SUM(subtotal),0) AS subtotal, COALESCE(SUM(vat_amount),0) AS vat, COALESCE(SUM(total),0) AS total, COALESCE(SUM(cogs),0) AS cogs
                 FROM sales_invoices i WHERE i.company_id = ? AND DATE(i.issued_at) BETWEEN ? AND ?" . branch_scope_sql('i.branch_id'), $p);
    $g = rp_all("SELECT COALESCE(l.grade, '-') AS grade, SUM(l.kg) AS kg, SUM(l.amount) AS amount FROM sales_invoice_lines l JOIN sales_invoices i ON i.id = l.invoice_id
                 WHERE i.company_id = ? AND DATE(i.issued_at) BETWEEN ? AND ?" . branch_scope_sql('i.branch_id') . ' GROUP BY l.grade ORDER BY l.grade', $p);
    $c = rp_all("SELECT i.customer_name AS customer, SUM(i.subtotal) AS subtotal, COUNT(*) AS invoices FROM sales_invoices i WHERE i.company_id = ? AND DATE(i.issued_at) BETWEEN ? AND ?" . branch_scope_sql('i.branch_id') . ' GROUP BY i.customer_name ORDER BY SUM(i.subtotal) DESC LIMIT 5', $p);
    $sh = rp_one("SELECT COUNT(*) AS n FROM shipments s WHERE s.company_id = ? AND s.status IN ('Dispatched','Delivered') AND DATE(s.dispatched_at) BETWEEN ? AND ?" . branch_scope_sql('s.branch_id'), $p);
    $sub = (float)$v['subtotal'];
    $sales = ['invoices' => (int)$v['invoices'], 'shipments' => (int)$sh['n'], 'subtotal' => rp_f($sub, 2), 'vat' => rp_f($v['vat'], 2), 'total' => rp_f($v['total'], 2), 'cogs' => rp_f($v['cogs'], 2), 'gross_margin' => rp_f($sub - (float)$v['cogs'], 2), 'gross_margin_pct' => rp_pct($sub - (float)$v['cogs'], $sub),
              'by_grade' => array_map(fn($x) => ['grade' => $x['grade'], 'kg' => rp_f($x['kg']), 'amount' => rp_f($x['amount'], 2), 'avg_price' => (float)$x['kg'] > 0 ? rp_f((float)$x['amount'] / (float)$x['kg'], 2) : 0], $g),
              'top_customers' => array_map(fn($x) => ['customer' => $x['customer'], 'subtotal' => rp_f($x['subtotal'], 2), 'invoices' => (int)$x['invoices']], $c)];

    // stock now
    $bal = rp_all("SELECT COALESCE(b.grade, '-') AS grade, SUM(l.qty) AS kg FROM stock_ledger l JOIN item_batches b ON b.id = l.batch_id WHERE l.company_id = ?" . branch_wh_sql('l.warehouse_id') . ' GROUP BY b.grade HAVING SUM(l.qty) > 0.0005 ORDER BY b.grade', [$cid]);
    $days = max(1, (int)wf_setting($cid, 'stock.use_first_days', '3'));
    $exp = rp_one("SELECT COALESCE(SUM(t.kg),0) AS kg FROM (SELECT SUM(l.qty) AS kg FROM stock_ledger l JOIN item_batches b ON b.id = l.batch_id WHERE l.company_id = ?" . branch_wh_sql('l.warehouse_id') . " AND b.expiry_date <= DATE_ADD(CURDATE(), INTERVAL $days DAY) GROUP BY l.batch_id HAVING SUM(l.qty) > 0.0005) t", [$cid]);
    $al = rp_one("SELECT COUNT(*) AS n FROM cold_room_alarms WHERE company_id = ? AND status = 'open'", [$cid]);
    $ho = rp_one("SELECT COUNT(*) AS n FROM quality_holds WHERE company_id = ? AND status = 'active'", [$cid]);
    $stock = ['by_grade' => array_map(fn($x) => ['grade' => $x['grade'], 'kg' => rp_f($x['kg'])], $bal), 'total_kg' => rp_f(array_sum(array_column($bal, 'kg'))), 'expiring_kg' => rp_f($exp['kg']), 'expiring_days' => $days, 'open_alarms' => (int)$al['n'], 'active_holds' => (int)$ho['n']];

    // market buying and cash custody
    $m = rp_one("SELECT COUNT(*) AS n, COALESCE(SUM(spent_amount),0) AS spent FROM market_purchases m WHERE m.company_id = ? AND m.status = 'Settled' AND DATE(m.settled_at) BETWEEN ? AND ?" . branch_scope_sql('m.branch_id'), $p);
    $cu = rp_one("SELECT COALESCE(SUM(issued_amount),0) AS amount, COALESCE(SUM(due_date < CURDATE()),0) AS overdue FROM market_purchases m WHERE m.company_id = ? AND m.status IN ('Cash_Issued','Settlement_Submitted')" . branch_scope_sql('m.branch_id'), [$cid]);
    $market = ['settled_purchases' => (int)$m['n'], 'spent' => rp_f($m['spent'], 2), 'open_custody' => rp_f($cu['amount'], 2), 'overdue_custody' => (int)$cu['overdue']];

    $hrS = hr_summary($cid); unset($hrS['portal_url']);
    return ['period' => ['from' => $from, 'to' => $to], 'receiving' => $receiving, 'sorting' => $sorting, 'production' => $production, 'packing' => $packing, 'sales' => $sales, 'stock' => $stock, 'market' => $market, 'hr' => $hrS];
}
