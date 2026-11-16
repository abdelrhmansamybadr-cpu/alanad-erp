<?php
// Company-scoped CRUD (see core/crud.php). Generated from the legacy handler; edit the config below.
require_once __DIR__ . '/../core/crud.php';
crud_handle([
    'table' => 'customers',
    'search' => ['name', 'email', 'code'],
    'filters' => ['is_active'],
    'order' => 'name',
    'related' => [
        'invoices' => 'SELECT id, number, date, total, status FROM invoices WHERE customer_id = ? AND company_id = ? ORDER BY date DESC LIMIT 20',
    ],
]);
