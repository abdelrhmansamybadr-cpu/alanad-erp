<?php
// Company-scoped CRUD (see core/crud.php): how many kg one crate / carton / pallet of an item weighs.
require_once __DIR__ . '/../core/crud.php';
crud_handle(['table' => 'unit_conversions', 'search' => ['unit'], 'filters' => ['item_id'], 'order' => 'id DESC']);
