<?php
$action = $parts[1] ?? '';

if ($method === 'POST' && $resource === 'auth') {
    if ($action === 'login' || $id === 'login') {
        $email    = trim($body['email'] ?? '');
        $password = $body['password'] ?? '';
        if (!$email || !$password) error_response('Email and password are required');

        // brute-force protection: too many recent failures => 429
        $ip   = $_SERVER['REMOTE_ADDR'] ?? '';
        $maxF = (int)(db()->query("SELECT setting_value FROM settings WHERE company_id = 1 AND setting_key = 'auth.login_max_failures'")->fetchColumn() ?: 5);
        $lock = (int)(db()->query("SELECT setting_value FROM settings WHERE company_id = 1 AND setting_key = 'auth.login_lock_minutes'")->fetchColumn() ?: 15);
        // per account: $maxF failures; per IP: 10x that (a factory shares one public IP, so a low IP limit would lock everybody out)
        $cnt  = db()->prepare('SELECT COUNT(*) FROM login_attempts WHERE success = 0 AND created_at > (NOW() - INTERVAL ? MINUTE) AND email = ?');
        $cnt->execute([$lock, $email]);
        $cntIp = db()->prepare('SELECT COUNT(*) FROM login_attempts WHERE success = 0 AND created_at > (NOW() - INTERVAL ? MINUTE) AND ip = ?');
        $cntIp->execute([$lock, $ip]);
        if ((int)$cnt->fetchColumn() >= $maxF || (int)$cntIp->fetchColumn() >= $maxF * 10) error_response("Too many failed attempts. Try again in $lock minutes.", 429);

        $stmt = db()->prepare('SELECT * FROM users WHERE email = ? AND is_active = 1 LIMIT 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            db()->prepare('INSERT INTO login_attempts (email, ip, success) VALUES (?,?,0)')->execute([$email, $ip]);
            error_response('Invalid email or password', 401);
        }
        db()->prepare('INSERT INTO login_attempts (email, ip, success) VALUES (?,?,1)')->execute([$email, $ip]);

        $token = jwt_encode(['user_id' => $user['id'], 'email' => $user['email'], 'role' => $user['role']]);
        json_response([
            'token' => $token,
            'user'  => ['id' => $user['id'], 'name' => $user['name'], 'email' => $user['email'], 'role' => $user['role']],
        ]);
    }
    if ($action === 'logout' || $id === 'logout') {
        json_response(['message' => 'Logged out']);
    }
}

if ($method === 'POST' && ($action === 'change-password' || $id === 'change-password')) {
    $payload = auth_required();
    $cur = (string)($body['current_password'] ?? ''); $new = (string)($body['new_password'] ?? '');
    $st = db()->prepare('SELECT password_hash FROM users WHERE id = ?'); $st->execute([$payload['user_id']]);
    $hash = $st->fetchColumn();
    if (!$hash || !password_verify($cur, $hash)) error_response('Current password is incorrect', 403);
    if (strlen($new) < 10 || !preg_match('/[A-Za-z]/', $new) || !preg_match('/\d/', $new)) error_response('Password must be at least 10 characters with letters and numbers', 422);
    if ($new === $cur) error_response('Choose a different password', 422);
    db()->prepare('UPDATE users SET password_hash = ?, must_change_password = 0 WHERE id = ?')->execute([password_hash($new, PASSWORD_BCRYPT), $payload['user_id']]);
    json_response(['message' => 'Password changed']);
}

if ($method === 'GET' && ($action === 'me' || $id === 'me')) {
    $payload = auth_required();
    $stmt = db()->prepare('SELECT id, name, email, role FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$payload['user_id']]);
    $user = $stmt->fetch();
    if (!$user) error_response('User not found', 404);
    json_response($user);
}

error_response('Auth endpoint not found', 404);
