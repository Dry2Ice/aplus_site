<?php
require_once __DIR__ . '/config/php-bootstrap.php';
aplus_init_error_logging('csrf-token');

$security_config = require __DIR__ . '/config/security.php';

function csrf_allowed_hosts() {
    global $security_config;
    $hosts = $security_config['allowed_hosts'] ?? [];
    if (!is_array($hosts) || empty($hosts)) {
        return ['aplus-charisma.ru', 'www.aplus-charisma.ru'];
    }
    $normalized = [];
    foreach ($hosts as $host) {
        $value = strtolower(trim((string)$host));
        if ($value !== '') {
            $normalized[] = $value;
        }
    }
    return array_values(array_unique($normalized));
}

function csrf_is_https_request() {
    if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    $forwardedProto = strtolower(trim((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')));
    return $forwardedProto === 'https';
}

function csrf_is_allowed_origin($origin) {
    if ($origin === '') {
        return true;
    }
    $parts = parse_url($origin);
    if (!is_array($parts) || empty($parts['host'])) {
        return false;
    }
    $host = strtolower((string)$parts['host']);
    return in_array($host, csrf_allowed_hosts(), true);
}

function csrf_is_allowed_referer($referer) {
    if ($referer === '') {
        return true;
    }
    $parts = parse_url($referer);
    if (!is_array($parts) || empty($parts['host'])) {
        return false;
    }
    $host = strtolower((string)$parts['host']);
    return in_array($host, csrf_allowed_hosts(), true);
}

function csrf_reject($status, $message) {
    http_response_code((int)$status);
    echo json_encode(['success' => false, 'message' => (string)$message], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit();
}

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('X-Frame-Options: DENY');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');

if (!in_array($_SERVER['REQUEST_METHOD'] ?? '', ['GET', 'HEAD'], true)) {
    csrf_reject(405, 'Method not allowed');
}

$origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
$referer = (string)($_SERVER['HTTP_REFERER'] ?? '');
if (!csrf_is_allowed_origin($origin) || !csrf_is_allowed_referer($referer)) {
    csrf_reject(403, 'Origin or referer not allowed');
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => csrf_is_https_request(),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'HEAD') {
    exit();
}

echo json_encode([
    'csrf_token' => $_SESSION['csrf_token'],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
