<?php
// Password hash SHA-256 dari "1qazxsw2"
define('ADMIN_PASSWORD_HASH', 'b73846dd535927acb39ffca85c45f41197d0380b1477e3448a041b9b94a222b1');

// Token file & secret key
$_KEY_FILE = __DIR__ . '/.secret_key';
if (file_exists($_KEY_FILE)) {
    $APP_SECRET = file_get_contents($_KEY_FILE);
} else {
    $APP_SECRET = bin2hex(random_bytes(32));
    file_put_contents($_KEY_FILE, $APP_SECRET);
}
define('APP_SECRET', $APP_SECRET);
define('ADMIN_TOKEN', hash('sha256', 'admin-token-' . APP_SECRET));

function require_admin(): void {
    $token = $_SERVER['HTTP_X_ADMIN_TOKEN'] ?? '';
    if ($token !== ADMIN_TOKEN) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'msg' => 'Unauthorized']);
        exit;
    }
}

function json_out(mixed $data, int $code = 200): never {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function get_json_body(): array {
    return json_decode(file_get_contents('php://input'), true) ?? [];
}
