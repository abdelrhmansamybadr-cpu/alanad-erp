<?php
// Trucks (own and rented). GET /vehicles returns a "busy" flag (on an open entry permit or a delivery in progress) so the gate can pick a free truck or rent one.
require_once __DIR__ . '/../core/crud.php';
require_once __DIR__ . '/../core/fleet.php';
$cid = (int)$GLOBALS['company_id'];
if ($GLOBALS['method'] === 'GET' && !$GLOBALS['id']) json_response(fl_vehicles($cid));
if (in_array($GLOBALS['method'], ['POST', 'PUT'], true)) {
    $b = $GLOBALS['body'];
    if (isset($b['plate'])) { $b['plate'] = strtoupper(trim((string)$b['plate'])); if ($b['plate'] === '') error_response('plate is required', 422); }
    if ($GLOBALS['method'] === 'POST' && empty($b['plate'])) error_response('plate is required', 422);
    if (isset($b['kind']) && !in_array($b['kind'], ['own', 'rented'], true)) error_response("kind must be 'own' or 'rented'", 422);
    if (($b['kind'] ?? '') === 'rented' && empty($b['rental_company'])) error_response('A rented truck needs the rental company', 422);
    if (isset($b['capacity_kg']) && $b['capacity_kg'] !== null && (!is_numeric($b['capacity_kg']) || (float)$b['capacity_kg'] <= 0)) error_response('capacity_kg must be a positive number', 422);
    $GLOBALS['body'] = $b;
}
crud_handle(['table' => 'vehicles', 'search' => ['plate', 'rental_company', 'default_driver'], 'filters' => ['kind', 'is_active'], 'order' => 'plate ASC']);
