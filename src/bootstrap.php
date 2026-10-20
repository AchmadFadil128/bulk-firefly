<?php
declare(strict_types=1);

$timezone = getenv('APP_TIMEZONE') ?: 'Asia/Jakarta';
date_default_timezone_set($timezone);

function is_https_request(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    $forwardedProto = strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
    if ($forwardedProto === 'https') {
        return true;
    }
    return false;
}

$isLocalEnv = (getenv('APP_ENV') ?: 'production') === 'local';
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Strict');
ini_set('session.cookie_secure', is_https_request() ? '1' : '0');
ini_set('session.save_path', '/tmp');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('bulkfirefly_session');
    session_start();
}

function json_response(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, private');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function require_method(string $method): void
{
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== strtoupper($method)) {
        header('Allow: ' . strtoupper($method));
        json_response(['ok' => false, 'message' => 'Metode request tidak diizinkan.'], 405);
    }
}

function read_json_body(): array
{
    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?: '', true);
    if (!is_array($data)) {
        json_response(['ok' => false, 'message' => 'Body JSON tidak valid.'], 400);
    }
    return $data;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function require_csrf_and_same_origin(): void
{
    $provided = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $expected = $_SESSION['csrf_token'] ?? '';
    if (!is_string($provided) || !is_string($expected) || $expected === '' || !hash_equals($expected, $provided)) {
        json_response(['ok' => false, 'message' => 'Token CSRF tidak valid. Muat ulang halaman lalu coba lagi.'], 403);
    }

    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $forwardedHost = (string)($_SERVER['HTTP_X_FORWARDED_HOST'] ?? '');
    if ($forwardedHost !== '') {
        $hostList = explode(',', $forwardedHost);
        $requestHostHeader = strtolower(trim($hostList[0]));
    } else {
        $requestHostHeader = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    }

    $parts = is_string($origin) ? parse_url($origin) : false;
    $requestParts = $requestHostHeader !== '' ? parse_url('http://' . $requestHostHeader) : false;
    $originHost = is_array($parts) ? strtolower((string)($parts['host'] ?? '')) : '';
    $requestHost = is_array($requestParts) ? strtolower((string)($requestParts['host'] ?? '')) : '';
    $originScheme = is_array($parts) ? strtolower((string)($parts['scheme'] ?? '')) : '';

    $isLocalEnv = (getenv('APP_ENV') ?: 'production') === 'local';
    // Allow HTTP when accessed over HTTP (e.g. administrator troubleshooting via Tailscale / host port) or local env
    $allowedScheme = ($isLocalEnv || !is_https_request()) ? ['http', 'https'] : ['https'];

    $originPort = is_array($parts) ? (int)($parts['port'] ?? ($originScheme === 'https' ? 443 : 80)) : 0;
    $requestPort = is_array($requestParts) ? (int)($requestParts['port'] ?? ($originScheme === 'https' ? 443 : 80)) : 0;
    $hasExtraOriginParts = is_array($parts) && (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) || !in_array((string)($parts['path'] ?? ''), ['', '/'], true));

    if ($originHost === '' || $requestHost === '' || !hash_equals($requestHost, $originHost) || $originPort !== $requestPort || !in_array($originScheme, $allowedScheme, true) || $hasExtraOriginParts) {
        json_response(['ok' => false, 'message' => 'Origin request tidak diizinkan.'], 403);
    }
}
