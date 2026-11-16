<?php
// ─── Alanad ERP — company-scoped CRUD engine ─────────────────
// One implementation for all "list / get / create / update / delete" resources.
// Guarantees (tested for every resource in tests/isolation_test.php):
//   • every query is limited to the caller's company (company_id comes from the session, never from the client)
//   • only real columns of the table are accepted (no SQL through field names, no mass assignment of company_id/id)
//   • child rows (invoice lines, BOM items …) are only reachable through a parent of the same company
//
// $c = [
//   'table'   => 'quotations',
//   'search'  => ['number', 'customer_name'],          // columns for ?search=
//   'filters' => ['status'],                            // ?status=... -> column = value
//   'order'   => 'created_at DESC',
//   'dates'   => true,                                  // ?from= / ?to= on column `date`
//   'prefix'  => 'QUO',                                 // auto number when none is sent
//   'child'   => ['key' => 'lines', 'table' => 'quotation_lines', 'fk' => 'quotation_id'],
//   'related' => ['invoices' => ['sql' => '...WHERE customer_id = ? AND company_id = ?', 'params' => ['id','company']]],
// ]

function crud_columns(string $table): array {
    static $cache = [];
    if (!isset($cache[$table])) {
        $st = db()->prepare('SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $st->execute([$table]);
        $cache[$table] = $st->fetchAll(PDO::FETCH_COLUMN);
    }
    return $cache[$table];
}

/** Keep only real, writable columns; unknown names are a client error. */
function crud_clean(string $table, array $data, array $forbid = []): array {
    $cols = crud_columns($table);
    $out = [];
    foreach ($data as $k => $v) {
        if (in_array($k, $forbid, true)) continue;
        if (!in_array($k, $cols, true)) error_response("Unknown field '$k' for $table", 422);
        if (is_array($v)) error_response("Field '$k' must be a plain value", 422);
        $out[$k] = $v;
    }
    return $out;
}

function crud_insert(string $table, array $row): int {
    $cols = implode(',', array_map(fn($k) => "`$k`", array_keys($row)));
    $marks = implode(',', array_fill(0, count($row), '?'));
    db()->prepare("INSERT INTO `$table` ($cols) VALUES ($marks)")->execute(array_values($row));
    return (int)db()->lastInsertId();
}

function crud_next_number(string $prefix, string $table, int $companyId): string {
    $st = db()->prepare("SELECT number FROM `$table` WHERE company_id = ? AND number LIKE ? ORDER BY id DESC LIMIT 1");
    $st->execute([$companyId, $prefix . '-%']);
    $last = $st->fetchColumn();
    $n = $last ? (int)substr($last, strlen($prefix) + 1) + 1 : 1;
    return $prefix . '-' . str_pad((string)$n, 4, '0', STR_PAD_LEFT);
}

function crud_fetch(array $c, int $id, int $companyId): ?array {
    $st = db()->prepare("SELECT * FROM `{$c['table']}` WHERE id = ? AND company_id = ? LIMIT 1");
    $st->execute([$id, $companyId]);
    $row = $st->fetch();
    if (!$row) return null;
    if (!empty($c['child'])) {
        $ch = db()->prepare("SELECT * FROM `{$c['child']['table']}` WHERE `{$c['child']['fk']}` = ?");
        $ch->execute([$id]);
        $row[$c['child']['key']] = $ch->fetchAll();
    }
    foreach ($c['related'] ?? [] as $name => $rel) {
        $q = db()->prepare(is_array($rel) ? $rel['sql'] : $rel);
        $q->execute([$id, $companyId]);
        $row[$name] = $q->fetchAll();
    }
    return $row;
}

function crud_save_children(array $c, int $parentId, array $rows): void {
    $ch = $c['child'];
    db()->prepare("DELETE FROM `{$ch['table']}` WHERE `{$ch['fk']}` = ?")->execute([$parentId]);
    foreach ($rows as $r) {
        if (!is_array($r)) error_response("{$ch['key']} must be a list of objects", 422);
        $r = crud_clean($ch['table'], $r, ['id', $ch['fk']]);
        $r[$ch['fk']] = $parentId;
        crud_insert($ch['table'], $r);
    }
}

/** Translate database errors into clean client errors (never leak SQL). */
function crud_db_error(PDOException $e): void {
    $code = (int)($e->errorInfo[1] ?? 0);
    match (true) {
        $code === 1062 => error_response('Duplicate value (a unique code or number already exists)', 409),
        $code === 1452 => error_response('A referenced record does not exist', 422),
        $code === 1451 => error_response('This record is in use by other records and cannot be deleted', 409),
        in_array($code, [1406, 1264, 1366, 1292, 1265, 1048, 1364], true) => error_response('Invalid, missing or too long value', 422),
        default => throw $e,
    };
}

require_once __DIR__ . '/branches.php';

function crud_handle(array $c): void {
    $db = db();
    $companyId = (int)$GLOBALS['company_id'];
    $id = $GLOBALS['id'] !== null ? (int)$GLOBALS['id'] : null;
    $method = $GLOBALS['method'];
    $body = $GLOBALS['body'];
    $table = $c['table'];
    $cols = crud_columns($table);
    $now = date('Y-m-d H:i:s');
    $childKey = $c['child']['key'] ?? null;

    $bcol = $c['branch_col'] ?? null;
    if ($bcol && $id && in_array($method, ['GET', 'PUT', 'DELETE'], true)) {      // a record of a branch the user is not working in does not exist for him
        $br = $db->prepare("SELECT `$bcol` FROM `$table` WHERE id = ? AND company_id = ?"); $br->execute([$id, $companyId]);
        $bv = $br->fetchColumn();
        if ($bv !== false && !branch_in_scope($bv !== null ? (int)$bv : null)) error_response('Not found', 404);
    }

    switch ($method) {
    case 'GET':
        if ($id) {
            $row = crud_fetch($c, $id, $companyId);
            if (!$row) error_response('Not found', 404);
            json_response($row);
        }
        $w = 'company_id = ?'; $p = [$companyId];
        if ($bcol) $w .= branch_scope_sql("`$bcol`");
        if (!empty($_GET['search']) && !empty($c['search'])) {
            $s = '%' . $_GET['search'] . '%';
            $w .= ' AND (' . implode(' OR ', array_map(fn($col) => "`$col` LIKE ?", $c['search'])) . ')';
            foreach ($c['search'] as $_) $p[] = $s;
        }
        foreach ($c['filters'] ?? [] as $f) {
            if (isset($_GET[$f]) && $_GET[$f] !== '' && in_array($f, $cols, true)) { $w .= " AND `$f` = ?"; $p[] = $_GET[$f]; }
        }
        if (!empty($c['dates']) && in_array('date', $cols, true)) {
            if (!empty($_GET['from'])) { $w .= ' AND `date` >= ?'; $p[] = $_GET['from']; }
            if (!empty($_GET['to']))   { $w .= ' AND `date` <= ?'; $p[] = $_GET['to']; }
        }
        $order = preg_match('/^[a-z_]+( (ASC|DESC))?$/i', $c['order'] ?? 'id DESC') ? ($c['order'] ?? 'id DESC') : 'id DESC';
        $st = $db->prepare("SELECT * FROM `$table` WHERE $w ORDER BY $order");
        $st->execute($p);
        json_response($st->fetchAll());

    case 'POST':
        $children = null;
        if ($childKey) { $children = $body[$childKey] ?? []; unset($body[$childKey]); }
        if (!$body && !$children) error_response('No data provided', 422);
        $row = crud_clean($table, $body, ['id', 'company_id', 'created_at', 'updated_at']);
        $row['company_id'] = $companyId;
        if ($bcol) $row[$bcol] = !empty($body[$bcol]) ? branch_validate($companyId, (int)$body[$bcol]) : branch_default($companyId);
        if (in_array('created_at', $cols, true)) $row['created_at'] = $now;
        if (in_array('updated_at', $cols, true)) $row['updated_at'] = $now;
        if (!empty($c['prefix']) && empty($row['number'])) $row['number'] = crud_next_number($c['prefix'], $table, $companyId);
        $db->beginTransaction();
        try {
            $newId = crud_insert($table, $row);
            if ($childKey && $children) crud_save_children($c, $newId, $children);
            $db->commit();
        } catch (PDOException $e) {
            if ($db->inTransaction()) $db->rollBack();
            crud_db_error($e);
        }
        json_response(crud_fetch($c, $newId, $companyId), 201);

    case 'PUT':
        if (!$id) error_response('ID required', 422);
        if (!crud_fetch(['table' => $table], $id, $companyId)) error_response('Not found', 404);
        $children = null;
        if ($childKey && array_key_exists($childKey, $body)) { $children = $body[$childKey]; unset($body[$childKey]); }
        $forbid = ['id', 'company_id', 'created_at'];
        if ($bcol && !empty($body[$bcol])) branch_validate($companyId, (int)$body[$bcol]);
        if (!empty($c['prefix'])) $forbid[] = 'number';          // document numbers never change
        $row = crud_clean($table, $body, $forbid);
        if (in_array('updated_at', $cols, true)) $row['updated_at'] = $now;
        $db->beginTransaction();
        try {
            if ($row) {
                $set = implode(',', array_map(fn($k) => "`$k` = ?", array_keys($row)));
                $db->prepare("UPDATE `$table` SET $set WHERE id = ? AND company_id = ?")->execute([...array_values($row), $id, $companyId]);
            }
            if ($children !== null) crud_save_children($c, $id, is_array($children) ? $children : []);
            $db->commit();
        } catch (PDOException $e) {
            if ($db->inTransaction()) $db->rollBack();
            crud_db_error($e);
        }
        json_response(crud_fetch($c, $id, $companyId));

    case 'DELETE':
        if (!$id) error_response('ID required', 422);
        if (!crud_fetch(['table' => $table], $id, $companyId)) error_response('Not found', 404);
        try {
            $db->beginTransaction();
            if (!empty($c['child'])) $db->prepare("DELETE FROM `{$c['child']['table']}` WHERE `{$c['child']['fk']}` = ?")->execute([$id]);
            $db->prepare("DELETE FROM `$table` WHERE id = ? AND company_id = ?")->execute([$id, $companyId]);
            $db->commit();
        } catch (PDOException $e) {
            if ($db->inTransaction()) $db->rollBack();
            crud_db_error($e);
        }
        json_response(['message' => 'Deleted']);

    default:
        error_response('Method not allowed', 405);
    }
}
