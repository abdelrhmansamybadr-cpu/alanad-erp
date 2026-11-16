<?php
auth_required();
// company-wide totals (revenue, customers, invoices...) are for people who may view everything, not for every signed-in employee
if (!permission_allows($GLOBALS['auth_user']['permissions'], 'dashboard', 'view')) error_response('Forbidden: you do not have permission to view dashboard', 403);
$db = db();
$cid = (int)$GLOBALS['company_id'];

$stats = [];
foreach ([
    'total_customers'  => "SELECT COUNT(*) FROM customers WHERE is_active=1 AND company_id=$cid",
    'total_suppliers'  => "SELECT COUNT(*) FROM suppliers WHERE is_active=1 AND company_id=$cid",
    'total_items'      => "SELECT COUNT(*) FROM items WHERE is_active=1 AND company_id=$cid",
    'total_invoices'   => "SELECT COUNT(*) FROM invoices WHERE company_id=$cid",
    'overdue_invoices' => "SELECT COUNT(*) FROM invoices WHERE status='Overdue' AND company_id=$cid",
    'pending_pos'      => "SELECT COUNT(*) FROM purchase_orders WHERE status='Submitted' AND company_id=$cid",
    'pending_sos'      => "SELECT COUNT(*) FROM sales_orders WHERE status='Confirmed' AND company_id=$cid",
    'total_employees'  => "SELECT COUNT(*) FROM employees WHERE status='Active' AND company_id=$cid",
] as $key => $sql) {
    $stats[$key] = (int) $db->query($sql)->fetchColumn();
}

$month_start = date('Y-m-01');
$stats['revenue_this_month'] = (float) $db->query(
    "SELECT COALESCE(SUM(total),0) FROM invoices WHERE status='Paid' AND date >= '$month_start' AND company_id=$cid"
)->fetchColumn();

$recent = $db->query(
    "SELECT number, date, party_name, amount, type, status FROM payments WHERE company_id=$cid ORDER BY created_at DESC LIMIT 5"
)->fetchAll();
$stats['recent_payments'] = $recent;

$top_customers = $db->query(
    "SELECT customer_name, SUM(total) as total FROM invoices WHERE status != 'Cancelled' AND company_id=$cid GROUP BY customer_name ORDER BY total DESC LIMIT 5"
)->fetchAll();
$stats['top_customers'] = $top_customers;

json_response($stats);
