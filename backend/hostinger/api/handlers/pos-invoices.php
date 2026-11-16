<?php
// Company-scoped CRUD (see core/crud.php). Generated from the legacy handler; edit the config below.
require_once __DIR__ . '/../core/crud.php';
crud_handle([
    'table' => 'pos_invoices',
    'search' => ['number', 'customer_name'],
    'filters' => ['status'],
    'order' => 'id DESC',
]);
