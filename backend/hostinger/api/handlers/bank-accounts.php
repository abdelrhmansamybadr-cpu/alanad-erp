<?php
// Company-scoped CRUD (see core/crud.php). Generated from the legacy handler; edit the config below.
require_once __DIR__ . '/../core/crud.php';
crud_handle([
    'table' => 'fin_bank_accounts',
    'search' => ['account_name', 'bank_name'],
    'filters' => [],
    'order' => 'id DESC',
]);
