<?php
// === Entry point and high-level metadata =====================================
/**
 * send-mail.php - Обработчик форм обратной связи
 * Сайт: А Плюс (aplus-charisma.ru)
 * Хостинг: SprintHost
 */

// === Response headers and baseline HTTP hardening ============================
require_once __DIR__ . '/config/php-bootstrap.php';
aplus_init_error_logging('send-mail');

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');


// === Security configuration loading =========================================
$security_config = require __DIR__ . '/config/security.php';

// === Security and request-origin helpers ====================================
function security_allowed_hosts() {
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

function security_default_cors_origin() {
    global $security_config;
    $origin = trim((string)($security_config['default_cors_origin'] ?? ''));
    return $origin !== '' ? $origin : 'https://aplus-charisma.ru';
}

// === Mail event logging ======================================================
function mail_log_path() {
    return __DIR__ . '/data/mail-events.log';
}

function mail_log_event($event, $meta = []) {
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? 'unknown');
    $record = [
        'at' => gmdate('c'),
        'event' => (string)$event,
        'ip_hash' => hash('sha256', $ip),
        'ua_hash' => hash('sha256', $ua),
        'meta' => $meta,
    ];
    @file_put_contents(mail_log_path(), json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND | LOCK_EX);
}

function client_ip_address() {
    // Trust REMOTE_ADDR only (set by the web server from the actual TCP connection).
    // X-Forwarded-For and X-Real-IP can be spoofed by clients and must not be trusted
    // for rate limiting unless the server is behind a known reverse proxy that strips them.
    $remote = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    return $remote !== '' ? $remote : 'unknown';
}

// === Guard rails and rejection flow =========================================
function reject_with_log($status, $message, $meta = []) {
    mail_log_event('reject', array_merge(['status' => (int)$status, 'message' => (string)$message], $meta));
    http_response_code((int)$status);
    echo json_encode(['success' => false, 'message' => (string)$message]);
    exit();
}

function is_allowed_origin($origin) {
    if ($origin === '') {
        return false;
    }

    $parts = parse_url($origin);
    if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
        return false;
    }

    $scheme = strtolower((string)$parts['scheme']);
    $host = strtolower((string)$parts['host']);

    if (!in_array($scheme, ['http', 'https'], true)) {
        return false;
    }

    return in_array($host, security_allowed_hosts(), true);
}


function is_allowed_referer($referer) {
    if ($referer === '') {
        return true;
    }

    $parts = parse_url($referer);
    if (!is_array($parts) || empty($parts['host'])) {
        return false;
    }

    $host = strtolower((string)$parts['host']);
    return in_array($host, security_allowed_hosts(), true);
}

// === CORS response policy ===================================================
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (is_allowed_origin($origin)) {
    header('Access-Control-Allow-Origin: ' . $origin);
} else {
    header('Access-Control-Allow-Origin: ' . security_default_cors_origin());
}
header('Vary: Origin');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// === Preflight and method validation ========================================
// Обработка preflight запроса
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Проверка метода запроса
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    reject_with_log(405, 'Method not allowed. Use POST.');
}

if ($origin !== '' && !is_allowed_origin($origin)) {
    reject_with_log(403, 'Origin not allowed', ['origin' => $origin]);
}


$referer = $_SERVER['HTTP_REFERER'] ?? '';
if (!is_allowed_referer($referer)) {
    reject_with_log(403, 'Referer not allowed', ['referer' => $referer]);
}

// === Payload parsing and schema validation ==================================
// Получение данных из запроса
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!$data) {
    reject_with_log(400, 'Invalid JSON data');
}


function is_analytics_key($key) {
    return strpos($key, 'utm_') === 0 || in_array($key, ['gclid', 'fbclid', 'yclid'], true);
}

function assert_allowed_fields($data, $allowedFields) {
    if (!is_array($data)) {
        reject_with_log(400, 'Invalid payload');
    }

    $unknown = [];
    foreach (array_keys($data) as $key) {
        if (is_analytics_key((string)$key)) {
            continue;
        }
        if (!in_array((string)$key, $allowedFields, true)) {
            $unknown[] = (string)$key;
        }
    }

    if (!empty($unknown)) {
        mail_log_event('reject', ['status' => 422, 'message' => 'Unexpected fields in payload', 'fields' => $unknown]);
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Unexpected fields in payload', 'fields' => $unknown]);
        exit();
    }
}

function looks_like_spam($data) {
    $signals = [];
    $name = trim((string)($data['name'] ?? ''));
    $email = trim((string)($data['email'] ?? ''));

    if ($name !== '' && preg_match('/https?:\/\//i', $name)) {
        $signals[] = 'name_contains_url';
    }
    if ($email !== '' && preg_match('/https?:\/\//i', $email)) {
        $signals[] = 'email_contains_url';
    }

    return $signals;
}


// === Form-type routing and anti-spam checks =================================
$is_custom_order = isset($data['chemistry']) || isset($data['voltage']) || isset($data['current']);
$commonFields = ['name', 'phone', 'email', 'context', 'website', 'industry', 'volume', 'timeline', 'budget'];
$customFields = ['chemistry', 'cell', 'voltage', 'current', 'connector', 'customConnector', 'bms', 'spConfig', 'dimensions', 'weight'];
$allowedFields = $is_custom_order ? array_merge($commonFields, $customFields) : $commonFields;
assert_allowed_fields($data, $allowedFields);

if (isset($data['website']) && trim((string)$data['website']) !== '') {
    mail_log_event('honeypot_blocked');
    http_response_code(200);
    echo json_encode(['success' => true, 'message' => 'Request accepted']);
    exit();
}

$spamSignals = looks_like_spam($data);
if (!empty($spamSignals)) {
    reject_with_log(422, 'Spam suspected', ['signals' => $spamSignals]);
}

// === Rate limiting ===========================================================
function rate_limit_exceeded($ip, $limit = 8, $windowSeconds = 300) {
    $file = sys_get_temp_dir() . '/aplus_rate_limit_' . md5((string)$ip) . '.json';
    $now = time();
    $state = ['start' => $now, 'count' => 0];

    $fp = @fopen($file, 'c+');
    if (!$fp) {
        return false;
    }

    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        return false;
    }

    $raw = stream_get_contents($fp);
    if (is_string($raw) && $raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded) && isset($decoded['start'], $decoded['count'])) {
            $state = $decoded;
        }
    }

    if (($now - (int)$state['start']) > $windowSeconds) {
        $state = ['start' => $now, 'count' => 1];
    } else {
        $state['count'] = (int)$state['count'] + 1;
    }

    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($state));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    return (int)$state['count'] > $limit;
}

$client_ip = client_ip_address();
if (rate_limit_exceeded($client_ip)) {
    header('Retry-After: 300');
    mail_log_event('rate_limit', ['ip_hash' => hash('sha256', (string)$client_ip)]);
    reject_with_log(429, 'Too many requests. Please try again later.');
}

function sanitize_field($value, $maxLen = 500) {
    $value = trim((string)($value ?? ''));
    $value = str_replace(["\r", "\n"], ' ', $value);
    return mb_substr($value, 0, $maxLen);
}

function is_valid_phone($phone) {
    if ($phone === '') {
        return false;
    }
    return preg_match('/^[0-9+()\-\s]{6,20}$/', $phone) === 1;
}

function is_valid_email($email) {
    if ($email === '') {
        return true;
    }
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

// ============================================
// НАСТРОЙКИ ПОЧТЫ
// ============================================
$to_email = getenv('MAIL_TO') ?: 'noreply@aplus-charisma.ru';
$admin_email = getenv('MAIL_ADMIN') ?: 'info@aplus.org.ru';
$site_name = 'А Плюс';
$site_url = 'https://aplus-charisma.ru';

// ============================================
// ОПРЕДЕЛЕНИЕ ТИПА ФОРМЫ
// ============================================
$is_general_contact = isset($data['name']) && isset($data['phone']) && !$is_custom_order;

$name = sanitize_field($data['name'] ?? '', 120);
$phone = sanitize_field($data['phone'] ?? '', 60);
$email = sanitize_field($data['email'] ?? '', 120);
$context = sanitize_field($data['context'] ?? 'Заявка с сайта', 180);
$chemistry = sanitize_field($data['chemistry'] ?? '', 80);
$cell = sanitize_field($data['cell'] ?? '', 80);
$voltage = sanitize_field($data['voltage'] ?? '', 40);
$current = sanitize_field($data['current'] ?? '', 40);
$connector = sanitize_field($data['connector'] ?? '', 80);
$customConnector = sanitize_field($data['customConnector'] ?? '', 80);
$spConfig = sanitize_field($data['spConfig'] ?? '', 60);
$dimensions = sanitize_field($data['dimensions'] ?? '', 120);
$weight = sanitize_field($data['weight'] ?? '', 60);
$industry = sanitize_field($data['industry'] ?? '', 120);
$volume = sanitize_field($data['volume'] ?? '', 120);
$timeline = sanitize_field($data['timeline'] ?? '', 120);
$budget = sanitize_field($data['budget'] ?? '', 120);

if (!is_valid_phone($phone)) {
    reject_with_log(422, 'Invalid phone format');
}

if (!is_valid_email($email)) {
    reject_with_log(422, 'Invalid email format');
}

// ============================================
// ФОРМИРОВАНИЕ ПИСЬМА
// ============================================
if ($is_custom_order) {
    // ─── Форма индивидуального заказа аккумулятора ───
    $subject = '🔋 Заявка на индивидуальный аккумулятор';
    
    $message = "═══════════════════════════════════════════════════\n";
    $message .= "  НОВАЯ ЗАЯВКА НА ИНДИВИДУАЛЬНЫЙ АККУМУЛЯТОР\n";
    $message .= "═══════════════════════════════════════════════════\n\n";
    
    $message .= "📋 ПАРАМЕТРЫ АККУМУЛЯТОРА:\n";
    $message .= "───────────────────────────────────────────────────\n";
    $message .= "Тип химии:      " . ($chemistry ?: 'Не указано') . "\n";
    $message .= "Элемент:        " . ($cell ?: 'Не указано') . "\n";
    $message .= "Напряжение:     " . ($voltage ?: 'Не указано') . " V\n";
    $message .= "Ток:            " . ($current ?: 'Не указано') . " A\n";
    $message .= "Разъем:         " . ($connector ?: 'Не указано') . "\n";
    if (!empty($customConnector)) {
        $message .= "Свой разъем:    " . $customConnector . "\n";
    }
    $message .= "BMS:            " . (!empty($data['bms']) ? 'Да' : 'Нет') . "\n";
    $message .= "Конфигурация:   " . ($spConfig ?: 'Не указано') . "\n";
    $message .= "Размеры:        " . ($dimensions ?: 'Не указано') . "\n";
    $message .= "Вес:            " . ($weight ?: 'Не указано') . "\n";
    
    if ($industry !== '' || $volume !== '' || $timeline !== '' || $budget !== '') {
        $message .= "\n🎯 КВАЛИФИКАЦИЯ ЛИДА:\n";
        $message .= "───────────────────────────────────────────────────\n";
        $message .= "Сфера:          " . ($industry ?: 'Не указано') . "\n";
        $message .= "Объём:          " . ($volume ?: 'Не указано') . "\n";
        $message .= "Срок:           " . ($timeline ?: 'Не указано') . "\n";
        $message .= "Бюджет:         " . ($budget ?: 'Не указано') . "\n";
    }

    $message .= "\n👤 КОНТАКТНЫЕ ДАННЫЕ КЛИЕНТА:\n";
    $message .= "───────────────────────────────────────────────────\n";
    $message .= "Имя:            " . ($name ?: 'Не указано') . "\n";
    $message .= "Телефон:        " . ($phone ?: 'Не указано') . "\n";
    $message .= "Email:          " . ($email ?: 'Не указано') . "\n";
    
} elseif ($is_general_contact) {
    // ─── Общая контактная форма ───
    $context = $context ?: 'Заявка с сайта';
    $subject = '📩 ' . $context;
    
    $message = "═══════════════════════════════════════════════════\n";
    $message .= "  НОВАЯ ЗАЯВКА С САЙТА\n";
    $message .= "═══════════════════════════════════════════════════\n\n";
    
    $message .= "📋 КОНТЕКСТ ЗАЯВКИ:\n";
    $message .= "───────────────────────────────────────────────────\n";
    $message .= "Тип:            " . $context . "\n";
    
    if ($industry !== '' || $volume !== '' || $timeline !== '' || $budget !== '') {
        $message .= "\n🎯 КВАЛИФИКАЦИЯ ЛИДА:\n";
        $message .= "───────────────────────────────────────────────────\n";
        $message .= "Сфера:          " . ($industry ?: 'Не указано') . "\n";
        $message .= "Объём:          " . ($volume ?: 'Не указано') . "\n";
        $message .= "Срок:           " . ($timeline ?: 'Не указано') . "\n";
        $message .= "Бюджет:         " . ($budget ?: 'Не указано') . "\n";
    }

    $message .= "\n👤 КОНТАКТНЫЕ ДАННЫЕ КЛИЕНТА:\n";
    $message .= "───────────────────────────────────────────────────\n";
    $message .= "Имя:            " . ($name ?: 'Не указано') . "\n";
    $message .= "Телефон:        " . ($phone ?: 'Не указано') . "\n";
    $message .= "Email:          " . ($email ?: 'Не указано') . "\n";
    
} else {
    reject_with_log(400, 'Incomplete form data');
}

// ─── Общая информация ───
$message .= "\nℹ️ ТЕХНИЧЕСКАЯ ИНФОРМАЦИЯ:\n";
$message .= "───────────────────────────────────────────────────\n";
$message .= "Дата:           " . date('d.m.Y H:i:s') . "\n";
$message .= "IP-хеш:         " . hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? 'unknown')) . "\n";
$message .= "Сайт:           " . $site_url . "\n";
$message .= "═══════════════════════════════════════════════════\n";

// ============================================
// ЗАГОЛОВКИ ПИСЬМА
// ============================================
$headers = "From: " . $site_name . " <" . $to_email . ">\r\n";
$headers .= "Reply-To: " . ($email ?: $to_email) . "\r\n";
$headers .= "X-Mailer: PHP/" . phpversion() . "\r\n";
$headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
$headers .= "Content-Transfer-Encoding: 8bit\r\n";

// ============================================
// ОТПРАВКА ПИСЬМА
// ============================================
$mail_sent = mail($to_email, $subject, $message, $headers);

// Дополнительно: продублировать на основную почту менеджера
if ($mail_sent) {
    mail($admin_email, $subject, $message, $headers);
}

// ============================================
// ОТВЕТ КЛИЕНТУ
// ============================================
if ($mail_sent) {
    mail_log_event('mail_sent', ['context' => $context, 'form_type' => $is_custom_order ? 'custom_order' : 'contact']);
    echo json_encode([
        'success' => true,
        'message' => 'Письмо успешно отправлено',
        'timestamp' => date('Y-m-d H:i:s')
    ]);
} else {
    $errorMessage = error_get_last()['message'] ?? 'Unknown error';
    mail_log_event('mail_failed', ['context' => $context, 'error' => $errorMessage]);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Ошибка отправки письма. Попробуйте позже или свяжитесь с нами по телефону.'
    ]);
}
?>
