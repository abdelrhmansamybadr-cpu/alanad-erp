<?php
// ─── Manzuma ERP — API Helpers ───────────────────────────────

// ── Database ──────────────────────────────────────────────────
function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        // Shared hosting limits how many database connections one account may hold at once. A page opens ~13 calls together, so a "too many connections"
        // answer is retried a few times with a short, growing pause instead of failing the request.
        $opts = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        $dsn = 'mysql:host=' . DB_HOST . (defined('DB_PORT') ? ';port=' . DB_PORT : '') . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        for ($try = 1; ; $try++) {
            try {
                $pdo = new PDO($dsn, DB_USER, DB_PASS, $opts);
                break;
            } catch (PDOException $e) {
                if ($try >= 8) {
                    http_response_code(503);
                    header('Retry-After: 2');
                    echo json_encode(['error' => 'Database connection failed']);
                    exit;
                }
                usleep(random_int(80, 220) * 1000 * $try);   // 0.1 s, 0.3 s, 0.5 s ... (random so parallel calls do not retry together)
            }
        }
    }
    return $pdo;
}

// ── CORS ──────────────────────────────────────────────────────
function cors(): void {
    // Only our own web app(s) may call the API from a browser. Mobile apps send no Origin header.
    $origin  = $_SERVER['HTTP_ORIGIN'] ?? '';
    $allowed = defined('ALLOWED_ORIGINS') ? ALLOWED_ORIGINS : [];
    $host    = $_SERVER['HTTP_HOST'] ?? '';
    if ($origin !== '' && (in_array($origin, $allowed, true) || preg_replace('#^https?://#', '', $origin) === $host)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');
    }
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Company-Id');
    header('Content-Type: application/json; charset=utf-8');
}

// ── Responses ─────────────────────────────────────────────────
function json_response(mixed $data, int $status = 200): void {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function error_response(string $message, int $status = 400): void {
    json_response(['error' => $message], $status);
}

// ── JWT ───────────────────────────────────────────────────────
function jwt_encode(array $payload): string {
    $header  = base64url_encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
    $payload['exp'] = time() + JWT_EXPIRY;
    $payload = base64url_encode(json_encode($payload));
    $sig     = base64url_encode(hash_hmac('sha256', "$header.$payload", JWT_SECRET, true));
    return "$header.$payload.$sig";
}

function jwt_decode(string $token): array|false {
    $parts = explode('.', $token);
    if (count($parts) !== 3) return false;
    [$header, $payload, $sig] = $parts;
    $expected = base64url_encode(hash_hmac('sha256', "$header.$payload", JWT_SECRET, true));
    if (!hash_equals($expected, $sig)) return false;
    $data = json_decode(base64url_decode($payload), true);
    if (!$data || (isset($data['exp']) && $data['exp'] < time())) return false;
    return $data;
}

function base64url_encode(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function base64url_decode(string $data): string {
    return base64_decode(strtr($data, '-_', '+/'));
}

// ── Auth Guard ────────────────────────────────────────────────
function auth_required(): array {
    if (!empty($GLOBALS['auth_user'])) return $GLOBALS['auth_user'];   // already resolved by the pipeline
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!str_starts_with($header, 'Bearer ')) {
        error_response('Unauthorized', 401);
    }
    $token = substr($header, 7);
    $payload = jwt_decode($token);
    if (!$payload) {
        error_response('Invalid or expired token', 401);
    }
    return $payload;
}

// ── Number Generator ──────────────────────────────────────────
function next_number(string $prefix, string $table, string $col = 'number'): string {
    $stmt = db()->prepare("SELECT {$col} FROM `{$table}` WHERE `{$col}` LIKE ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$prefix . '%']);
    $last = $stmt->fetchColumn();
    $n = $last ? (int) substr($last, strlen($prefix) + 1) + 1 : 1;
    return $prefix . '-' . str_pad($n, 4, '0', STR_PAD_LEFT);
}

// ── Body Parser ───────────────────────────────────────────────
function body(): array {
    global $body;
    return $body ?? [];
}

// ── Pagination helper ─────────────────────────────────────────
function paginate(string $sql, array $params, int $page = 1, int $per_page = 100): array {
    $offset = ($page - 1) * $per_page;
    // a plain "SELECT * FROM" must only swap the first FROM (the WHERE may hold sub-selects of its own)
    $countSql = preg_match('/^\s*SELECT \* FROM/i', $sql) ? preg_replace('/^\s*SELECT \* FROM/i', 'SELECT COUNT(*) FROM', $sql) : preg_replace('/SELECT .+ FROM/i', 'SELECT COUNT(*) FROM', $sql);
    $countSql = preg_replace('/ORDER BY .+$/i', '', $countSql);
    $countStmt = db()->prepare($countSql);
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    $stmt = db()->prepare($sql . " LIMIT $per_page OFFSET $offset");
    $stmt->execute($params);
    $data = $stmt->fetchAll();

    return ['data' => $data, 'total' => $total, 'page' => $page, 'per_page' => $per_page];
}
