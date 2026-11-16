<?php
// Company-scoped CRUD (see core/crud.php). Generated from the legacy handler; edit the config below.
require_once __DIR__ . '/../core/crud.php';
crud_handle([
    'table' => 'tax_templates',
    'search' => ['name'],
    'filters' => ['type'],
    'order' => 'id DESC',
]);
