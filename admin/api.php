<?php
// === Session and HTTP security headers =======================================
$secureCookie = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
session_set_cookie_params([
  'lifetime' => 0,
  'path' => '/',
  'domain' => '',
  'secure' => $secureCookie,
  'httponly' => true,
  'samesite' => 'Strict',
]);
session_start();
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

// === Global constants =========================================================
const SESSION_KEY = 'aplus_admin_auth';
const SESSION_TTL = 43200;
const MAX_FAILED_ATTEMPTS = 7;
const LOCKOUT_SECONDS = 300;
const MAX_UPLOAD_SIZE_BYTES = 8 * 1024 * 1024;


// === Environment/bootstrap guards ============================================
function appEnvironment(): string {
  return strtolower(trim((string)(getenv('APP_ENV') ?: '')));
}

function isProductionEnvironment(): bool {
  return in_array(appEnvironment(), ['prod', 'production'], true);
}

function isLocalEnvironment(): bool {
  return in_array(appEnvironment(), ['dev', 'development', 'local'], true);
}

function getBootstrapState(SQLite3 $db): array {
  $hasPasswordHashEnv = trim((string)(getenv('APLUS_ADMIN_PASSWORD_HASH') ?: '')) !== '';
  $hasAccessCodesEnv = trim((string)(getenv('APLUS_ADMIN_CODES') ?: '')) !== '';
  return [
    'appEnv' => appEnvironment(),
    'isProduction' => isProductionEnvironment(),
    'isLocal' => isLocalEnvironment(),
    'hasPasswordHashEnv' => $hasPasswordHashEnv,
    'hasAccessCodesEnv' => $hasAccessCodesEnv,
    'hasPasswordHashInDb' => (int)$db->querySingle("SELECT COUNT(*) FROM admin_settings WHERE setting_key = 'password_hash'") > 0,
    'activeAccessCodesInDb' => activeAccessCodeCount($db),
  ];
}

function assertProductionBootstrap(SQLite3 $db): void {
  $state = getBootstrapState($db);
  if (!$state['isProduction']) {
    return;
  }

  if (!$state['hasPasswordHashEnv'] || !$state['hasAccessCodesEnv']) {
    jsonResponse([
      'ok' => false,
      'error' => 'Production bootstrap is not configured. Set APLUS_ADMIN_PASSWORD_HASH and APLUS_ADMIN_CODES.',
      'bootstrap' => $state,
    ], 500);
  }
}

function ensureBootstrapSecret(string $envName, string $legacyDevValue): string {
  $value = trim((string)(getenv($envName) ?: ''));
  if ($value !== '') {
    return $value;
  }

  if (isLocalEnvironment()) {
    return $legacyDevValue;
  }

  jsonResponse([
    'ok' => false,
    'error' => 'Server security bootstrap is not configured. Set ' . $envName . ' environment variable.',
  ], 500);
}

function ensureCsrfToken(): string {
  if (empty($_SESSION['aplus_admin_csrf'])) {
    $_SESSION['aplus_admin_csrf'] = bin2hex(random_bytes(24));
  }
  return (string)$_SESSION['aplus_admin_csrf'];
}

function validateCsrfToken(): void {
  $sessionToken = (string)($_SESSION['aplus_admin_csrf'] ?? '');
  $headerToken = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
  if ($sessionToken === '' || $headerToken === '' || !hash_equals($sessionToken, $headerToken)) {
    jsonResponse(['ok' => false, 'error' => 'CSRF token mismatch'], 419);
  }
}

function csrfRequired(string $action, string $method): bool {
  if ($method !== 'POST') {
    return false;
  }
  return in_array($action, ['logout', 'save', 'upload-image', 'change-password', 'add-access-code', 'deactivate-access-code', 'translate-text', 'save-home-content', 'save-design-settings', 'save-home-layout'], true);
}

$root = dirname(__DIR__);
$dataDir = $root . '/data';
$uploadDir = $root . '/uploads';
$dbPath = $dataDir . '/catalog.sqlite';

if (!is_dir($dataDir)) {
  mkdir($dataDir, 0755, true);
}
if (!is_dir($uploadDir)) {
  mkdir($uploadDir, 0755, true);
}

// === Request/session helpers =================================================
function jsonResponse($payload, int $status = 200): void {
  http_response_code($status);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

function requestJson(): array {
  $raw = file_get_contents('php://input');
  if (!$raw) {
    return [];
  }
  $decoded = json_decode($raw, true);
  return is_array($decoded) ? $decoded : [];
}

function nowIso(): string {
  return gmdate('c');
}

// === Catalog normalization and file I/O ======================================
function allowedCategories(): array {
  return ['batteries', 'copters', 'water'];
}

function ensureCategory(string $category): string {
  if (!in_array($category, allowedCategories(), true)) {
    jsonResponse(['ok' => false, 'error' => 'Unknown category'], 422);
  }
  return $category;
}

function normalizeProductKey(string $raw, int $index): string {
  $key = preg_replace('/[^a-zA-Z0-9_-]+/u', '-', mb_strtolower(trim($raw)));
  if (!$key || $key === '-') {
    $key = 'item-' . ($index + 1);
  }
  return $key . '-' . $index;
}

function normalizeSlug(string $raw): string {
  $slug = preg_replace('/[^a-z0-9_-]+/u', '-', mb_strtolower(trim($raw)));
  $slug = trim((string)$slug, '-');
  return $slug !== '' ? $slug : 'item';
}

function ensureItemSlug(array $item, int $index): array {
  $source = (string)($item['slug'] ?? $item['id'] ?? $item['title'] ?? 'item-' . ($index + 1));
  $item['slug'] = normalizeSlug($source);
  return $item;
}

function readJsonFile(string $path): array {
  if (!file_exists($path)) {
    return [];
  }
  $content = file_get_contents($path);
  $decoded = json_decode($content, true);
  return is_array($decoded) ? $decoded : [];
}

function writeJsonFile(string $path, array $data): bool {
  $fp = fopen($path, 'c+');
  if (!$fp) {
    return false;
  }
  if (!flock($fp, LOCK_EX)) {
    fclose($fp);
    return false;
  }
  ftruncate($fp, 0);
  rewind($fp);
  $ok = fwrite($fp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) !== false;
  fflush($fp);
  flock($fp, LOCK_UN);
  fclose($fp);
  return $ok;
}

// === Database bootstrap =======================================================
function sqlite(): SQLite3 {
  global $dbPath;
  $db = new SQLite3($dbPath);
  $db->enableExceptions(true);
  $db->exec('PRAGMA foreign_keys = ON');
  return $db;
}

function initDb(SQLite3 $db): void {
  $db->exec('CREATE TABLE IF NOT EXISTS products (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    category TEXT NOT NULL,
    product_key TEXT NOT NULL,
    sort_order INTEGER NOT NULL DEFAULT 0,
    data_json TEXT NOT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    UNIQUE(category, product_key)
  )');

  $db->exec('CREATE TABLE IF NOT EXISTS access_codes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    code_hash TEXT NOT NULL,
    label TEXT,
    is_active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL
  )');

  $db->exec('CREATE TABLE IF NOT EXISTS admin_settings (
    setting_key TEXT PRIMARY KEY,
    setting_value TEXT NOT NULL,
    updated_at TEXT NOT NULL
  )');

  $db->exec('CREATE TABLE IF NOT EXISTS audit_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    action TEXT NOT NULL,
    category TEXT,
    item_count INTEGER,
    actor_ip TEXT,
    metadata_json TEXT,
    created_at TEXT NOT NULL
  )');
}

function appendAuditLog(SQLite3 $db, string $action, ?string $category = null, ?int $itemCount = null, array $meta = []): void {
  $stmt = $db->prepare('INSERT INTO audit_logs (action, category, item_count, actor_ip, metadata_json, created_at) VALUES (:a, :c, :i, :ip, :m, :t)');
  $stmt->bindValue(':a', $action, SQLITE3_TEXT);
  $stmt->bindValue(':c', $category, SQLITE3_TEXT);
  $stmt->bindValue(':i', $itemCount, SQLITE3_INTEGER);
  $stmt->bindValue(':ip', $_SERVER['REMOTE_ADDR'] ?? null, SQLITE3_TEXT);
  $stmt->bindValue(':m', json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), SQLITE3_TEXT);
  $stmt->bindValue(':t', nowIso(), SQLITE3_TEXT);
  $stmt->execute();
}

function ensureSecurityDefaults(SQLite3 $db): void {
  $settingsCount = (int)$db->querySingle("SELECT COUNT(*) FROM admin_settings WHERE setting_key = 'password_hash'");
  if ($settingsCount === 0) {
    $hashFromEnv = trim((string)(getenv('APLUS_ADMIN_PASSWORD_HASH') ?: ''));
    $passwordHash = $hashFromEnv !== ''
      ? $hashFromEnv
      : password_hash(ensureBootstrapSecret('APLUS_ADMIN_PASSWORD', 'aplus-admin-2026'), PASSWORD_DEFAULT);
    $stmt = $db->prepare('INSERT INTO admin_settings (setting_key, setting_value, updated_at) VALUES (:k, :v, :u)');
    $stmt->bindValue(':k', 'password_hash', SQLITE3_TEXT);
    $stmt->bindValue(':v', $passwordHash, SQLITE3_TEXT);
    $stmt->bindValue(':u', nowIso(), SQLITE3_TEXT);
    $stmt->execute();
  }

  $codesCount = (int)$db->querySingle('SELECT COUNT(*) FROM access_codes WHERE is_active = 1');
  if ($codesCount === 0) {
    $rawCodes = ensureBootstrapSecret('APLUS_ADMIN_CODES', 'APLUS-EDIT-2026');
    $codes = array_filter(array_map('trim', explode(',', $rawCodes)));
    if (!$codes) {
      $codes = ['APLUS-EDIT-2026'];
    }
    $stmt = $db->prepare('INSERT INTO access_codes (code_hash, label, is_active, created_at) VALUES (:h, :l, 1, :c)');
    foreach ($codes as $code) {
      $stmt->bindValue(':h', password_hash($code, PASSWORD_DEFAULT), SQLITE3_TEXT);
      $stmt->bindValue(':l', 'default', SQLITE3_TEXT);
      $stmt->bindValue(':c', nowIso(), SQLITE3_TEXT);
      $stmt->execute();
    }
  }
}

function migrateJsonToDb(SQLite3 $db): void {
  global $dataDir;
  $existing = (int)$db->querySingle('SELECT COUNT(*) FROM products');
  if ($existing > 0) {
    return;
  }

  $insert = $db->prepare('INSERT INTO products (category, product_key, sort_order, data_json, created_at, updated_at) VALUES (:c, :k, :s, :d, :n, :u)');

  foreach (allowedCategories() as $category) {
    $items = readJsonFile($dataDir . '/' . $category . '.json');
    foreach (array_values($items) as $idx => $item) {
      if (!is_array($item)) {
        continue;
      }
      $item = ensureItemSlug($item, $idx);
      $primary = (string)($item['id'] ?? $item['title'] ?? 'item-' . ($idx + 1));
      $insert->bindValue(':c', $category, SQLITE3_TEXT);
      $insert->bindValue(':k', normalizeProductKey($primary, $idx), SQLITE3_TEXT);
      $insert->bindValue(':s', $idx, SQLITE3_INTEGER);
      $insert->bindValue(':d', json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), SQLITE3_TEXT);
      $insert->bindValue(':n', nowIso(), SQLITE3_TEXT);
      $insert->bindValue(':u', nowIso(), SQLITE3_TEXT);
      $insert->execute();
    }
  }
}

function fetchCategoryItems(SQLite3 $db, string $category): array {
  $stmt = $db->prepare('SELECT data_json FROM products WHERE category = :c ORDER BY sort_order ASC, id ASC');
  $stmt->bindValue(':c', $category, SQLITE3_TEXT);
  $result = $stmt->execute();
  $items = [];
  while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $decoded = json_decode((string)$row['data_json'], true);
    if (is_array($decoded)) {
      $items[] = $decoded;
    }
  }
  return $items;
}

function syncJsonExports(SQLite3 $db): void {
  global $dataDir;
  foreach (allowedCategories() as $category) {
    writeJsonFile($dataDir . '/' . $category . '.json', fetchCategoryItems($db, $category));
  }
}

function regenerateSitemapFromJson(): bool {
  global $root;

  $baseUrl = 'https://aplus-charisma.ru';
  $catalogFiles = [
    'batteries' => $root . '/data/batteries.json',
    'copters' => $root . '/data/copters.json',
    'water' => $root . '/data/water.json',
  ];

  $urls = [
    ['path' => '/', 'priority' => '1.0'],
    ['path' => '/batteries/', 'priority' => '0.9'],
    ['path' => '/copters/', 'priority' => '0.9'],
    ['path' => '/water/', 'priority' => '0.9'],
  ];
  $seen = [
    '/' => true,
    '/batteries/' => true,
    '/copters/' => true,
    '/water/' => true,
  ];

  foreach ($catalogFiles as $category => $catalogPath) {
    $items = readJsonFile($catalogPath);
    foreach ($items as $item) {
      if (!is_array($item)) {
        continue;
      }

      $slugRaw = (string)($item['slug'] ?? $item['id'] ?? '');
      $slug = strtolower(trim($slugRaw, '/ '));
      if ($slug === '') {
        continue;
      }

      $path = '/' . $category . '/' . $slug . '/';
      if (isset($seen[$path])) {
        continue;
      }

      $seen[$path] = true;
      $urls[] = ['path' => $path, 'priority' => '0.7'];
    }
  }

  $xml = [
    '<?xml version="1.0" encoding="UTF-8"?>',
    '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">',
  ];

  foreach ($urls as $urlItem) {
    $xml[] = '  <url>';
    $xml[] = '    <loc>' . $baseUrl . $urlItem['path'] . '</loc>';
    $xml[] = '    <changefreq>weekly</changefreq>';
    $xml[] = '    <priority>' . $urlItem['priority'] . '</priority>';
    $xml[] = '  </url>';
  }

  $xml[] = '</urlset>';

  return file_put_contents($root . '/sitemap.xml', implode(PHP_EOL, $xml) . PHP_EOL) !== false;
}

function isAuthed(): bool {
  if (empty($_SESSION[SESSION_KEY])) {
    return false;
  }
  return (time() - (int)$_SESSION[SESSION_KEY]) <= SESSION_TTL;
}

function requireAuth(): void {
  if (!isAuthed()) {
    jsonResponse(['ok' => false, 'error' => 'Unauthorized'], 401);
  }
  $_SESSION[SESSION_KEY] = time();
}

function isLockedOut(): bool {
  $until = (int)($_SESSION['aplus_admin_lock_until'] ?? 0);
  return $until > time();
}

function registerFailedAttempt(): void {
  $attempts = (int)($_SESSION['aplus_admin_failed_attempts'] ?? 0) + 1;
  $_SESSION['aplus_admin_failed_attempts'] = $attempts;
  if ($attempts >= MAX_FAILED_ATTEMPTS) {
    $_SESSION['aplus_admin_lock_until'] = time() + LOCKOUT_SECONDS;
  }
}

function clearAttempts(): void {
  unset($_SESSION['aplus_admin_failed_attempts'], $_SESSION['aplus_admin_lock_until']);
}

function verifyAccessCode(SQLite3 $db, string $code): bool {
  if ($code === '') {
    return false;
  }
  $result = $db->query('SELECT code_hash FROM access_codes WHERE is_active = 1');
  while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    if (password_verify($code, (string)$row['code_hash'])) {
      return true;
    }
  }
  return false;
}

function upsertPasswordHash(SQLite3 $db, string $newPassword): void {
  $hash = password_hash($newPassword, PASSWORD_DEFAULT);
  $stmt = $db->prepare('INSERT INTO admin_settings (setting_key, setting_value, updated_at) VALUES (:k, :v, :u)
    ON CONFLICT(setting_key) DO UPDATE SET setting_value = excluded.setting_value, updated_at = excluded.updated_at');
  $stmt->bindValue(':k', 'password_hash', SQLITE3_TEXT);
  $stmt->bindValue(':v', $hash, SQLITE3_TEXT);
  $stmt->bindValue(':u', nowIso(), SQLITE3_TEXT);
  $stmt->execute();
}

function activeAccessCodeCount(SQLite3 $db): int {
  return (int)$db->querySingle('SELECT COUNT(*) FROM access_codes WHERE is_active = 1');
}



function translateTextViaGoogle(string $text, string $source, string $target): string {
  $trimmed = trim($text);
  if ($trimmed === '') {
    return '';
  }

  $url = 'https://translate.googleapis.com/translate_a/single?client=gtx&sl='
    . rawurlencode($source)
    . '&tl=' . rawurlencode($target)
    . '&dt=t&q=' . rawurlencode($trimmed);

  $context = stream_context_create([
    'http' => [
      'method' => 'GET',
      'timeout' => 10,
      'header' => "User-Agent: Mozilla/5.0\r\n",
    ],
  ]);

  $response = @file_get_contents($url, false, $context);
  if ($response === false) {
    throw new RuntimeException('Google Translate request failed');
  }

  $decoded = json_decode($response, true);
  if (!is_array($decoded) || !isset($decoded[0]) || !is_array($decoded[0])) {
    throw new RuntimeException('Google Translate returned invalid payload');
  }

  $chunks = [];
  foreach ($decoded[0] as $part) {
    if (is_array($part) && isset($part[0])) {
      $chunks[] = (string)$part[0];
    }
  }

  return trim(implode('', $chunks));
}


function defaultHomeContent(): array {
  return [
    'ru' => [
      'navItems' => [
        ['label' => 'БПЛА', 'view' => 'uav', 'tag' => 'uav', 'href' => '/copters/'],
        ['label' => 'Аккумуляторы', 'view' => 'energy', 'tag' => 'energy', 'href' => '/batteries/'],
        ['label' => 'Очистка воды', 'view' => 'water', 'tag' => 'water', 'href' => '/water/'],
        ['label' => 'Принципы', 'view' => 'home', 'href' => '/#principles'],
      ],
      'contact' => 'Связаться',
      'hero' => [
        'slogan' => [
          ['text' => '- что вы продаете?', 'type' => 'question'],
          ['text' => '- ХАРИЗМУ', 'type' => 'answer'],
        ],
        'subtext' => 'Мы создаем решения для тех, кто строит будущее.',
        'subtextSecondary' => 'Индивидуальная сборка под ваше тз.',
      ],
      'stats' => [
        ['value' => '2018', 'label' => 'Основание'],
        ['value' => '100k+', 'label' => 'Сборок / месяц'],
        ['value' => '100%', 'label' => 'Сделано в Москве'],
        ['value' => 'ГОСТ', 'label' => 'Сертификация'],
      ],
      'about' => [
        'title' => "Мы — сообщество,
создающее связи.",
        'desc1' => 'Компания «А Плюс» — российский разработчик и производитель высококачественной продукции. С 2018 года мы внедряем инновации в беспилотные системы и энергетику.',
        'desc2' => 'Мы формируем среду, где технологии решают реальные задачи — от агропромышленности до спасательных операций.',
        'trustedTitle' => 'Нам доверяют компании из ключевых отраслей:',
        'trustedSectors' => [
          'Аграрный и агропромышленный сектор',
          'Логистические компании',
          'Охранные компании и службы безопасности',
          'IT-компании и цифровые сервисы',
          'Рекламные и маркетинговые агентства',
          'Производственные и торговые компании',
        ],
      ],
      'workflowTitle' => 'Как мы работаем',
      'workflow' => [
        ['title' => 'Анализ задачи', 'desc' => 'Детально изучаем ваши требования и специфику проекта.', 'fullDesc' => 'На первом этапе мы проводим глубокий брифинг с клиентом, изучаем отраслевую специфику и операционные условия.', 'img' => 'https://images.unsplash.com/photo-1552664730-d307ca884978?w=800&q=80'],
        ['title' => 'Проектирование', 'desc' => 'Создаем техническое задание и подбираем лучшие компоненты.', 'fullDesc' => 'Наши инженеры разрабатывают детальную техническую документацию, проводят компонентный анализ и 3D-моделирование.', 'img' => 'https://images.unsplash.com/photo-1581091226825-a6a2a5aee158?w=800&q=80'],
        ['title' => 'Производство', 'desc' => 'Осуществляем сборку и настройку на нашей базе в Москве.', 'fullDesc' => 'Весь цикл производства сосредоточен на нашем предприятии в Москве.', 'img' => 'https://images.unsplash.com/photo-1565688534245-05d6b5be184a?w=800&q=80'],
        ['title' => 'Тестирование', 'desc' => 'Проводим финальные испытания и передаем готовый продукт.', 'fullDesc' => 'Каждое изделие проходит многоэтапный стресс-тест.', 'img' => 'https://images.unsplash.com/photo-1518770660439-4636190af475?w=800&q=80'],
      ],
      'infoTitle' => 'Справочная информация',
      'faqTitle' => 'Частые вопросы',
      'faq' => [
        ['q' => 'Как осуществляется доставка?', 'a' => 'Мы осуществляем доставку по всей России надежными транспортными компаниями.'],
        ['q' => 'Есть ли гарантия на сборки аккумуляторов?', 'a' => 'Да, на все наши аккумуляторные сборки предоставляется официальная гарантия 12 месяцев.'],
        ['q' => 'Возможна ли разработка кастомного аккумулятора под наши задачи?', 'a' => 'Конечно. Мы специализируемся на индивидуальных решениях.'],
      ],
      'productsTitle' => 'Продуктовая экосистема',
      'productsSubtitle' => 'Примеры наших флагманских решений.',
      'principlesTitle' => 'Наши принципы',
      'principles' => [
        ['title' => 'Собственное производство', 'text' => 'Полный цикл разработки и сборки в России позволяет нам отвечать за качество продукции.'],
        ['title' => 'Решение задач', 'text' => 'Мы автоматизируем процессы, всегда работая на результат.'],
        ['title' => 'Инженерная честность', 'text' => 'Благодаря нашим технологиям наша продукция работает в любых условиях.'],
      ],
      'cta' => [
        'title' => 'Готовы обсудить задачи?',
        'text' => 'Мы открыты к диалогу и готовы разработать индивидуальное решение под ваши нужды.',
        'button' => 'Начать проект',
      ],
      'sectionBadges' => [
        'about' => 'О компании',
        'products' => 'Продукция',
        'principles' => 'Фундамент',
        'cta' => 'Сотрудничество',
        'catalog' => 'Каталог',
      ],
      'footer' => [
        'copyright' => '© 2025 ООО «А ПЛЮС».',
        'details' => 'ИНН 7704462149 / ОГРН 1187746859190',
        'address' => 'г. Москва, Очаковское шоссе, 28, 5 этаж',
        'addressLink' => 'https://www.google.com/maps/place/%D0%9E%D1%87%D0%B0%D0%BA%D0%BE%D0%B2%D1%81%D0%BA%D0%BE%D0%B5+%D1%88.,+28,+%D0%9C%D0%BE%D1%81%D0%BA%D0%B2%D0%B0',
        'policy' => 'Политика конфиденциальности',
      ],
      'location' => [
        'title' => 'Как нас найти',
        'address' => 'Очаковское, д. 28, стр. 2, помещ. 1Н/5',
        'phone' => '+7 926 845 02 41',
        'mapImage' => '/map.webp',
        'imageAlt' => 'Карта с расположением компании А Плюс на Очаковском шоссе',
      ],
      'ui' => [
        'filterAll' => 'Все решения', 'more' => 'Подробнее', 'order' => 'Заказать / Опт', 'back' => 'Назад в каталог',
        'specs' => 'Характеристики', 'consultation' => 'Консультация', 'form_title' => 'Свяжитесь с нами', 'form_name' => 'Ваше имя',
        'form_phone' => 'Телефон', 'form_email' => 'E-mail', 'form_industry' => 'Отрасль', 'form_volume' => 'Ожидаемый объем',
        'form_timeline' => 'Срок запуска', 'form_budget' => 'Диапазон бюджета', 'form_submit' => 'Отправить',
        'form_sending' => 'Отправка...', 'form_success' => 'Спасибо! Мы скоро свяжемся с вами.',
        'form_error' => 'Ошибка отправки. Свяжитесь с нами по телефону.', 'custom_battery_btn' => 'Кастомный аккумулятор',
        'custom_order_title' => 'Кастомная аккумуляторная сборка', 'chemistry_type' => 'Тип химии', 'cell_selection' => 'Ячейка',
        'nominal_voltage' => 'Напряжение (В)', 'nominal_current' => 'Номинальный ток (А)', 'connector_type' => 'Тип силового разъема',
        'bms_toggle' => 'Наличие BMS', 'bms_on' => 'Включено', 'bms_off' => 'Выключено', 'no_stock' => 'Нет в наличии на этом маркетплейсе',
        'sp_config' => 'S×P конфигурация', 'other_connector' => 'Другое', 'custom_connector_placeholder' => 'Укажите тип разъема',
        'estimated_dims' => 'Расчетные габариты', 'estimated_weight' => 'Расчетный вес', 'contact_info_title' => 'Ваши контактные данные',
      ],
      'cookies' => ['text' => 'Мы используем cookie. Это помогает нам понимать, как улучшить сайт.', 'button' => 'Хорошо'],
      'pages' => [
        'copters' => [
          'catalogTitle' => 'Беспилотные Системы',
          'catalogSubtitle' => 'Российское производство полного цикла',
          'ui' => ['features_title' => 'Преимущества', 'empty' => 'В данной категории пока нет товаров.', 'catalog_badge' => 'Каталог'],
        ],
        'batteries' => [
          'catalogTitle' => 'Аккумуляторы',
          'ui' => [
            'catalog_badge' => 'Каталог',
            'filters' => 'Фильтры', 'reset' => 'Сбросить', 'found' => 'Найдено', 'items' => 'позиций',
            'type' => 'Тип химии', 'voltage' => 'Напряжение (В)', 'connector' => 'Разъём', 'bms_label' => 'BMS',
            'bms_yes' => 'С BMS', 'bms_no' => 'Без BMS', 'bms_all' => 'Все', 'cells_sp' => 'Конфигурация S×P',
            'capacity' => 'Ёмкость (мАч)', 'no_results' => 'Ничего не найдено. Попробуйте изменить фильтры.',
            'search' => 'Поиск по артикулу...', 'active_filters' => 'Активные фильтры:',
            'custom_order' => 'Создать на заказ', 'filters_restored' => 'Фильтры восстановлены',
            'sort' => 'Сортировка', 'sort_default' => 'По умолчанию', 'sort_capacity_asc' => 'Ёмкость (возрастание)',
            'sort_capacity_desc' => 'Ёмкость (убывание)', 'sort_s_asc' => 'S коэффициент (возрастание)',
            'sort_s_desc' => 'S коэффициент (убывание)', 'sort_p_asc' => 'P коэффициент (возрастание)',
            'sort_p_desc' => 'P коэффициент (убывание)', 'sort_weight_asc' => 'Вес (возрастание)',
            'sort_weight_desc' => 'Вес (убывание)',
          ],
        ],
        'water' => [
          'catalogTitle' => "Системы
Очистки Воды",
          'catalogSubtitle' => 'Автономные комплексы для любых условий',
          'ui' => ['empty' => 'В данной категории пока нет товаров.', 'catalog_badge' => 'Каталог'],
        ],
      ],
      'requisites' => [
        'title' => 'Реквизиты компании',
        'badge' => 'Реквизиты',
        'sections' => [
          ['title' => 'Банковские реквизиты', 'icon' => 'bank', 'rows' => [
            ['label' => 'Банк', 'value' => 'АО «АЛЬФА-БАНК»'], ['label' => 'Расчетный счет', 'value' => '40702810802330003006'], ['label' => 'Корр. счет', 'value' => '30101810200000000593'], ['label' => 'БИК', 'value' => '044525593'],
          ]],
          ['title' => 'Юридические данные', 'icon' => 'legal', 'rows' => [
            ['label' => 'Полное наименование', 'value' => 'ООО «А ПЛЮС»'], ['label' => 'Юр. адрес', 'value' => '119530, г. Москва, Очаковское шоссе, д. 28, стр. 2, пом. 1Н/5.'], ['label' => 'ОКТМО', 'value' => '45323000'], ['label' => 'ОКПО', 'value' => '33410262'],
          ]],
          ['title' => 'Налоговые данные', 'icon' => 'tax', 'rows' => [
            ['label' => 'ИНН', 'value' => '7704462149'], ['label' => 'КПП', 'value' => '772901001'], ['label' => 'ОГРН', 'value' => '1187746859190'], ['label' => 'Система налогообложения', 'value' => 'УСН'],
          ]],
          ['title' => 'Ответственные лица', 'icon' => 'people', 'rows' => [
            ['label' => 'Ген. директор', 'value' => 'Прозоровский А.А.'], ['label' => 'Гл. бухгалтер', 'value' => '—'], ['label' => 'Дата регистрации', 'value' => '09.10.2018'], ['label' => 'Лицензия', 'value' => '—'],
          ]],
        ],
      ],
    ],
    'en' => [
      'navItems' => [
        ['label' => 'UAV', 'view' => 'uav', 'tag' => 'uav', 'href' => '/copters/'],
        ['label' => 'Batteries', 'view' => 'energy', 'tag' => 'energy', 'href' => '/batteries/'],
        ['label' => 'Water', 'view' => 'water', 'tag' => 'water', 'href' => '/water/'],
        ['label' => 'Principles', 'view' => 'home', 'href' => '/#principles'],
      ],
      'contact' => 'Contact Us',
      'hero' => ['slogan' => [['text' => '- what do you sell?', 'type' => 'question'], ['text' => '- CHARISMA', 'type' => 'answer']], 'subtext' => 'We create solutions for those building the future.', 'subtextSecondary' => 'Individual assembly tailored to your technical brief.'],
      'stats' => [['value' => '2018', 'label' => 'Founded'], ['value' => '100k+', 'label' => 'Units / Month'], ['value' => '100%', 'label' => 'Made in Moscow'], ['value' => 'GOST', 'label' => 'Certified']],
      'about' => ['title' => "We are a community
creating connections.", 'desc1' => 'A-Plus is a developer and manufacturer of high-quality products. Since 2018, we have been innovating in unmanned systems and energy.', 'desc2' => 'We shape an environment where technology solves real problems — from agro-industry to rescue operations.', 'trustedTitle' => 'Trusted by companies from key industries:', 'trustedSectors' => ['Agrarian and agro-industrial sector', 'Logistics companies', 'Security companies and services', 'IT companies and digital services', 'Advertising and marketing agencies', 'Manufacturing and trading companies']],
      'workflowTitle' => 'How We Work',
      'workflow' => [
        ['title' => 'Task Analysis', 'desc' => 'We study your requirements and project specifics in detail.', 'fullDesc' => 'At the first stage, we conduct an in-depth briefing with the client.', 'img' => 'https://images.unsplash.com/photo-1552664730-d307ca884978?w=800&q=80'],
        ['title' => 'Engineering', 'desc' => 'We create terms of reference and select the best components.', 'fullDesc' => 'Our engineers develop detailed technical documentation, conduct component analysis, and 3D modeling.', 'img' => 'https://images.unsplash.com/photo-1581091226825-a6a2a5aee158?w=800&q=80'],
        ['title' => 'Production', 'desc' => 'Assembly and configuration at our facility in Moscow.', 'fullDesc' => 'The entire production cycle is concentrated at our Moscow facility.', 'img' => 'https://images.unsplash.com/photo-1565688534245-05d6b5be184a?w=800&q=80'],
        ['title' => 'Testing', 'desc' => 'Final trials and handover of the finished product.', 'fullDesc' => 'Each product undergoes a multi-stage stress test.', 'img' => 'https://images.unsplash.com/photo-1518770660439-4636190af475?w=800&q=80'],
      ],
      'infoTitle' => 'Reference information',
      'faqTitle' => 'FAQ',
      'faq' => [
        ['q' => 'How is delivery carried out?', 'a' => 'We deliver throughout Russia using reliable transport companies and courier services.'],
        ['q' => 'Is there a warranty for battery packs?', 'a' => 'Yes, all our battery assemblies come with a 12-month official warranty.'],
        ['q' => 'Is it possible to develop a custom battery for our tasks?', 'a' => 'Absolutely. We specialize in individual solutions.'],
      ],
      'productsTitle' => 'Product Ecosystem',
      'productsSubtitle' => 'Examples of our flagship solutions.',
      'principlesTitle' => 'Our Principles',
      'principles' => [
        ['title' => 'In-house Production', 'text' => 'Full development and assembly cycle in Russia allows us to ensure product quality.'],
        ['title' => 'Solving Problems', 'text' => 'We automate processes, always working towards results.'],
        ['title' => 'Engineering Honesty', 'text' => 'Thanks to our technologies, our products operate in any conditions.'],
      ],
      'location' => [
        'title' => 'How to find us',
        'address' => 'Ochakovskoe, 28 bld. 2, premises 1N/5',
        'phone' => '+7 926 845 02 41',
        'mapImage' => '/map_eng.webp',
        'imageAlt' => 'Map showing the A Plus company location on Ochakovskoye Shosse',
      ],
      'cta' => ['title' => 'Ready to discuss tasks?', 'text' => 'We are open to dialogue and ready to develop an individual solution for your needs.', 'button' => 'Start Project'],
      'sectionBadges' => [
        'about' => 'About',
        'products' => 'Products',
        'principles' => 'Foundation',
        'cta' => 'Cooperation',
        'catalog' => 'Catalog',
      ],
      'footer' => ['copyright' => '© 2025 A PLUS LLC.', 'details' => 'INN 7704462149 / OGRN 1187746859190', 'address' => 'Moscow, Ochakovskoye Shosse, 28, 5th floor', 'addressLink' => 'https://www.google.com/maps/place/%D0%9E%D1%87%D0%B0%D0%BA%D0%BE%D0%B2%D1%81%D0%BA%D0%BE%D0%B5+%D1%88.,+28,+%D0%9C%D0%BE%D1%81%D0%BA%D0%B2%D0%B0', 'policy' => 'Privacy Policy'],
      'ui' => ['filterAll' => 'All Solutions', 'more' => 'Learn More', 'order' => 'Order / Wholesale', 'back' => 'Back to Catalog', 'specs' => 'Specifications', 'consultation' => 'Consultation', 'form_title' => 'Contact Us', 'form_name' => 'Your Name', 'form_phone' => 'Phone', 'form_email' => 'Email', 'form_industry' => 'Industry', 'form_volume' => 'Expected volume', 'form_timeline' => 'Launch timeline', 'form_budget' => 'Budget range', 'form_submit' => 'Send', 'form_sending' => 'Sending...', 'form_success' => 'Thank you! We will contact you soon.', 'form_error' => 'Sending error. Please contact us via phone.', 'custom_battery_btn' => 'Custom Battery', 'custom_order_title' => 'Custom Battery Pack', 'chemistry_type' => 'Chemistry Type', 'cell_selection' => 'Cell', 'nominal_voltage' => 'Voltage (В)', 'nominal_current' => 'Nominal Current (А)', 'connector_type' => 'Power Connector Type', 'bms_toggle' => 'BMS Included', 'bms_on' => 'Enabled', 'bms_off' => 'Disabled', 'no_stock' => 'Not available on this marketplace', 'sp_config' => 'S×P Config', 'other_connector' => 'Other', 'custom_connector_placeholder' => 'Specify connector type', 'estimated_dims' => 'Estimated Dimensions', 'estimated_weight' => 'Estimated Weight', 'contact_info_title' => 'Your Contact Details'],
      'cookies' => ['text' => 'We use cookies to improve your experience on our website.', 'button' => 'Okay'],
      'pages' => [
        'copters' => [
          'catalogTitle' => 'Unmanned Systems',
          'catalogSubtitle' => 'Full-cycle Russian production',
          'ui' => ['features_title' => 'Advantages', 'empty' => 'No products in this category yet.', 'catalog_badge' => 'Catalog'],
        ],
        'batteries' => [
          'catalogTitle' => 'Batteries',
          'ui' => [
            'catalog_badge' => 'Catalog',
            'filters' => 'Filters', 'reset' => 'Reset', 'found' => 'Found', 'items' => 'items',
            'type' => 'Chemistry Type', 'voltage' => 'Voltage (V)', 'connector' => 'Connector', 'bms_label' => 'BMS',
            'bms_yes' => 'With BMS', 'bms_no' => 'Without BMS', 'bms_all' => 'All', 'cells_sp' => 'S×P Config',
            'capacity' => 'Capacity (mAh)', 'no_results' => 'No results found. Try changing filters.',
            'search' => 'Search by model...', 'active_filters' => 'Active filters:',
            'custom_order' => 'Custom Build', 'filters_restored' => 'Filters restored',
            'sort' => 'Sorting', 'sort_default' => 'Default', 'sort_capacity_asc' => 'Capacity (ascending)',
            'sort_capacity_desc' => 'Capacity (descending)', 'sort_s_asc' => 'S ratio (ascending)',
            'sort_s_desc' => 'S ratio (descending)', 'sort_p_asc' => 'P ratio (ascending)',
            'sort_p_desc' => 'P ratio (descending)', 'sort_weight_asc' => 'Weight (ascending)',
            'sort_weight_desc' => 'Weight (descending)',
          ],
        ],
        'water' => [
          'catalogTitle' => "Water
Purification",
          'catalogSubtitle' => 'Autonomous complexes for any conditions',
          'ui' => ['empty' => 'No products in this category yet.', 'catalog_badge' => 'Catalog'],
        ],
      ],
      'requisites' => [
        'title' => 'Company Details', 'badge' => 'Requisites',
        'sections' => [
          ['title' => 'Banking Details', 'icon' => 'bank', 'rows' => [['label' => 'Bank', 'value' => 'AO ALFA-BANK'], ['label' => 'Account No.', 'value' => '40702810802330003006'], ['label' => 'Corr. Account', 'value' => '30101810200000000593'], ['label' => 'BIC', 'value' => '044525593']]],
          ['title' => 'Legal Details', 'icon' => 'legal', 'rows' => [['label' => 'Full Name', 'value' => 'A PLUS LLC'], ['label' => 'Legal Address', 'value' => '119530, Moscow, Ochakovskoe highway, house 28, building 2, premises 1N/5.'], ['label' => 'OKTMO', 'value' => '45323000'], ['label' => 'OKPO', 'value' => '33410262']]],
          ['title' => 'Tax Details', 'icon' => 'tax', 'rows' => [['label' => 'INN (TIN)', 'value' => '7704462149'], ['label' => 'KPP', 'value' => '772901001'], ['label' => 'OGRN', 'value' => '1187746859190'], ['label' => 'Tax System', 'value' => 'Simplified Tax System']]],
          ['title' => 'Responsible Persons', 'icon' => 'people', 'rows' => [['label' => 'CEO', 'value' => 'Prozorovsky A.A.'], ['label' => 'Chief Accountant', 'value' => '—'], ['label' => 'Registration Date', 'value' => 'October 9, 2018'], ['label' => 'License', 'value' => '—']]],
        ],
      ],
    ],
  ];
}

function deepMergeArrays(array $base, array $override): array {
  $result = $base;
  foreach ($override as $key => $value) {
    if (is_array($value) && isset($result[$key]) && is_array($result[$key])) {
      $result[$key] = deepMergeArrays($result[$key], $value);
    } else {
      $result[$key] = $value;
    }
  }
  return $result;
}

function sanitizeContentNode($value) {
  if (is_array($value)) {
    $out = [];
    foreach ($value as $k => $v) {
      $out[$k] = sanitizeContentNode($v);
    }
    return $out;
  }
  if (is_bool($value) || is_int($value) || is_float($value)) {
    return (string)$value;
  }
  if ($value === null) {
    return '';
  }
  return (string)$value;
}

function readHomeContent(SQLite3 $db): array {
  $defaults = defaultHomeContent();
  $raw = (string)$db->querySingle("SELECT setting_value FROM admin_settings WHERE setting_key = 'homepage_content'");
  if ($raw === '') {
    return $defaults;
  }

  $decoded = json_decode($raw, true);
  if (!is_array($decoded)) {
    return $defaults;
  }

  $normalized = ['ru' => [], 'en' => []];
  foreach (['ru', 'en'] as $lang) {
    $normalized[$lang] = isset($decoded[$lang]) && is_array($decoded[$lang])
      ? sanitizeContentNode($decoded[$lang])
      : [];
    $normalized[$lang] = deepMergeArrays($defaults[$lang], $normalized[$lang]);
  }

  return $normalized;
}

function saveHomeContent(SQLite3 $db, array $content): void {
  $payload = ['ru' => [], 'en' => []];
  foreach (['ru', 'en'] as $lang) {
    $payload[$lang] = isset($content[$lang]) && is_array($content[$lang])
      ? sanitizeContentNode($content[$lang])
      : [];
  }

  $stmt = $db->prepare('INSERT INTO admin_settings (setting_key, setting_value, updated_at) VALUES (:k, :v, :u)
    ON CONFLICT(setting_key) DO UPDATE SET setting_value = excluded.setting_value, updated_at = excluded.updated_at');
  $stmt->bindValue(':k', 'homepage_content', SQLITE3_TEXT);
  $stmt->bindValue(':v', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), SQLITE3_TEXT);
  $stmt->bindValue(':u', nowIso(), SQLITE3_TEXT);
  $stmt->execute();
}

function defaultDesignSettings(): array {
  $lightColors = [
    'primary' => '#4c019c',
    'secondary' => '#6523b1',
    'tertiary' => '#bd9cf7',
    'background' => '#f7f2ff',
    'text' => '#201136',
    'surface' => '#ffffff',
  ];
  $darkColors = [
    'primary' => '#bd9cf7',
    'secondary' => '#6523b1',
    'tertiary' => '#4c019c',
    'background' => '#11071e',
    'text' => '#f7f3ff',
    'surface' => '#1d1233',
  ];
  return [
    'fontBody' => 'Onest',
    'fontDisplay' => 'Unbounded',
    'fontScale' => 100,
    'tokenMode' => 'auto',
    'colors' => $lightColors,
    'colorsDark' => $darkColors,
    'tokens' => deriveDesignTokens($lightColors, false),
    'tokensDark' => deriveDesignTokens($darkColors, true),
    'gradients' => [
      'main' => 'linear-gradient(135deg, #4c019c 0%, #6523b1 72%, #bd9cf7 100%)',
    ],
    'accessibility' => [
      'highContrast' => false,
      'reduceMotion' => false,
    ],
    'layout' => [
      'homeSections' => ['hero', 'stats', 'divider', 'about', 'workflow', 'products', 'principles', 'faq', 'cta'],
    ],
  ];
}

function sanitizeHexColor(string $value, string $fallback): string {
  $normalized = strtolower(trim($value));
  return preg_match('/^#[0-9a-f]{6}$/', $normalized) ? $normalized : $fallback;
}

function hexToRgbParts(string $hex): array {
  $safe = ltrim(sanitizeHexColor($hex, '#000000'), '#');
  return [
    'r' => hexdec(substr($safe, 0, 2)),
    'g' => hexdec(substr($safe, 2, 2)),
    'b' => hexdec(substr($safe, 4, 2)),
  ];
}

function rgbPartsToHex(array $rgb): string {
  $parts = [];
  foreach (['r', 'g', 'b'] as $channel) {
    $parts[] = str_pad(dechex(max(0, min(255, (int)($rgb[$channel] ?? 0)))), 2, '0', STR_PAD_LEFT);
  }
  return '#' . implode('', $parts);
}

function mixHexColors(string $base, string $target, float $ratio): string {
  $from = hexToRgbParts($base);
  $to = hexToRgbParts($target);
  $mix = static fn(int $start, int $end): int => (int)round($start + (($end - $start) * $ratio));
  return rgbPartsToHex([
    'r' => $mix($from['r'], $to['r']),
    'g' => $mix($from['g'], $to['g']),
    'b' => $mix($from['b'], $to['b']),
  ]);
}

function cssRgbWithAlpha(string $hex, float $alpha): string {
  $rgb = hexToRgbParts($hex);
  $safeAlpha = number_format(max(0, min(1, $alpha)), 2, '.', '');
  return sprintf('rgb(%d %d %d / %s)', $rgb['r'], $rgb['g'], $rgb['b'], rtrim(rtrim($safeAlpha, '0'), '.'));
}

// IMPORTANT: This must stay in sync with deriveSemanticTokens() in admin/index.html and /assets/common.js
function deriveDesignTokens(array $colors, bool $isDark): array {
  $background = sanitizeHexColor((string)($colors['background'] ?? ''), $isDark ? '#11071e' : '#f7f2ff');
  $surface = sanitizeHexColor((string)($colors['surface'] ?? ''), $isDark ? '#1d1233' : '#ffffff');
  $text = sanitizeHexColor((string)($colors['text'] ?? ''), $isDark ? '#f7f3ff' : '#201136');
  $primary = sanitizeHexColor((string)($colors['primary'] ?? ''), $isDark ? '#bd9cf7' : '#4c019c');
  $secondary = sanitizeHexColor((string)($colors['secondary'] ?? ''), '#6523b1');

  return [
    'backgroundElevated' => $isDark
      ? mixHexColors($background, $secondary, 0.22)
      : mixHexColors($background, $secondary, 0.08),
    'textMuted' => $isDark
      ? mixHexColors($text, $background, 0.22)
      : mixHexColors($text, $background, 0.32),
    'border' => cssRgbWithAlpha($primary, $isDark ? 0.18 : 0.12),
    'glow' => cssRgbWithAlpha($primary, $isDark ? 0.28 : 0.24),
    'overlay' => cssRgbWithAlpha($background, 0.90),
    'meshIntensity' => $isDark ? '0.16' : '0.08',
  ];
}

function sanitizeHomeSectionsOrder($rawOrder, array $fallback): array {
  $allowed = ['hero', 'stats', 'divider', 'about', 'workflow', 'products', 'principles', 'faq', 'cta'];
  $incoming = is_array($rawOrder) ? $rawOrder : [];
  $seen = [];
  $result = [];
  foreach ($incoming as $item) {
    $key = (string)$item;
    if (!in_array($key, $allowed, true) || isset($seen[$key])) {
      continue;
    }
    $seen[$key] = true;
    $result[] = $key;
  }
  foreach ($fallback as $item) {
    $key = (string)$item;
    if (!isset($seen[$key])) {
      $seen[$key] = true;
      $result[] = $key;
    }
  }
  return $result;
}


function sanitizeGradient(string $value, string $fallback): string {
  $normalized = trim($value);
  if ($normalized === '' || strlen($normalized) > 180) {
    return $fallback;
  }
  if (!preg_match('/^(linear|radial)-gradient\(/i', $normalized)) {
    return $fallback;
  }
  return $normalized;
}

function readDesignSettings(SQLite3 $db): array {
  $defaults = defaultDesignSettings();
  $raw = (string)$db->querySingle("SELECT setting_value FROM admin_settings WHERE setting_key = 'design_settings'");
  if ($raw === '') {
    return $defaults;
  }
  $decoded = json_decode($raw, true);
  if (!is_array($decoded)) {
    return $defaults;
  }

  $allowedFonts = ['Onest', 'Inter', 'Roboto', 'Arial', 'Georgia', 'Unbounded', 'Montserrat', 'Poppins', 'Oswald'];
  $fontBody = in_array((string)($decoded['fontBody'] ?? ''), $allowedFonts, true) ? (string)$decoded['fontBody'] : $defaults['fontBody'];
  $fontDisplay = in_array((string)($decoded['fontDisplay'] ?? ''), $allowedFonts, true) ? (string)$decoded['fontDisplay'] : $defaults['fontDisplay'];
  $fontScale = (int)($decoded['fontScale'] ?? $defaults['fontScale']);
  $fontScale = max(90, min(130, $fontScale));

  $colors = is_array($decoded['colors'] ?? null) ? $decoded['colors'] : [];
  $colorsDark = is_array($decoded['colorsDark'] ?? null) ? $decoded['colorsDark'] : [];
  $accessibility = is_array($decoded['accessibility'] ?? null) ? $decoded['accessibility'] : [];
  $layout = is_array($decoded['layout'] ?? null) ? $decoded['layout'] : [];
  $gradients = is_array($decoded['gradients'] ?? null) ? $decoded['gradients'] : [];

  return [
    'fontBody' => $fontBody,
    'fontDisplay' => $fontDisplay,
    'fontScale' => $fontScale,
    'tokenMode' => 'auto',
    'colors' => [
      'primary' => sanitizeHexColor((string)($colors['primary'] ?? ''), $defaults['colors']['primary']),
      'secondary' => sanitizeHexColor((string)($colors['secondary'] ?? ''), $defaults['colors']['secondary']),
      'tertiary' => sanitizeHexColor((string)($colors['tertiary'] ?? ''), $defaults['colors']['tertiary']),
      'background' => sanitizeHexColor((string)($colors['background'] ?? ''), $defaults['colors']['background']),
      'text' => sanitizeHexColor((string)($colors['text'] ?? ''), $defaults['colors']['text']),
      'surface' => sanitizeHexColor((string)($colors['surface'] ?? ''), $defaults['colors']['surface']),
    ],
    'colorsDark' => [
      'primary' => sanitizeHexColor((string)($colorsDark['primary'] ?? ''), $defaults['colorsDark']['primary']),
      'secondary' => sanitizeHexColor((string)($colorsDark['secondary'] ?? ''), $defaults['colorsDark']['secondary']),
      'tertiary' => sanitizeHexColor((string)($colorsDark['tertiary'] ?? ''), $defaults['colorsDark']['tertiary']),
      'background' => sanitizeHexColor((string)($colorsDark['background'] ?? ''), $defaults['colorsDark']['background']),
      'text' => sanitizeHexColor((string)($colorsDark['text'] ?? ''), $defaults['colorsDark']['text']),
      'surface' => sanitizeHexColor((string)($colorsDark['surface'] ?? ''), $defaults['colorsDark']['surface']),
    ],
    'accessibility' => [
      'highContrast' => (bool)($accessibility['highContrast'] ?? false),
      'reduceMotion' => (bool)($accessibility['reduceMotion'] ?? false),
    ],
    'layout' => [
      'homeSections' => sanitizeHomeSectionsOrder($layout['homeSections'] ?? null, $defaults['layout']['homeSections']),
    ],
    'gradients' => [
      'main' => sanitizeGradient((string)($gradients['main'] ?? ''), $defaults['gradients']['main']),
    ],
    'tokens' => deriveDesignTokens([
      'primary' => sanitizeHexColor((string)($colors['primary'] ?? ''), $defaults['colors']['primary']),
      'secondary' => sanitizeHexColor((string)($colors['secondary'] ?? ''), $defaults['colors']['secondary']),
      'tertiary' => sanitizeHexColor((string)($colors['tertiary'] ?? ''), $defaults['colors']['tertiary']),
      'background' => sanitizeHexColor((string)($colors['background'] ?? ''), $defaults['colors']['background']),
      'text' => sanitizeHexColor((string)($colors['text'] ?? ''), $defaults['colors']['text']),
      'surface' => sanitizeHexColor((string)($colors['surface'] ?? ''), $defaults['colors']['surface']),
    ], false),
    'tokensDark' => deriveDesignTokens([
      'primary' => sanitizeHexColor((string)($colorsDark['primary'] ?? ''), $defaults['colorsDark']['primary']),
      'secondary' => sanitizeHexColor((string)($colorsDark['secondary'] ?? ''), $defaults['colorsDark']['secondary']),
      'tertiary' => sanitizeHexColor((string)($colorsDark['tertiary'] ?? ''), $defaults['colorsDark']['tertiary']),
      'background' => sanitizeHexColor((string)($colorsDark['background'] ?? ''), $defaults['colorsDark']['background']),
      'text' => sanitizeHexColor((string)($colorsDark['text'] ?? ''), $defaults['colorsDark']['text']),
      'surface' => sanitizeHexColor((string)($colorsDark['surface'] ?? ''), $defaults['colorsDark']['surface']),
    ], true),
  ];
}

function saveDesignSettings(SQLite3 $db, array $settings): void {
  $payload = readDesignSettingsFromInput($settings);
  $stmt = $db->prepare('INSERT INTO admin_settings (setting_key, setting_value, updated_at) VALUES (:k, :v, :u)
    ON CONFLICT(setting_key) DO UPDATE SET setting_value = excluded.setting_value, updated_at = excluded.updated_at');
  $stmt->bindValue(':k', 'design_settings', SQLITE3_TEXT);
  $stmt->bindValue(':v', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), SQLITE3_TEXT);
  $stmt->bindValue(':u', nowIso(), SQLITE3_TEXT);
  $stmt->execute();
}

function saveHomeLayout(SQLite3 $db, array $layout): void {
  $current = readDesignSettings($db);
  $current['layout'] = [
    'homeSections' => sanitizeHomeSectionsOrder($layout['homeSections'] ?? null, defaultDesignSettings()['layout']['homeSections']),
  ];

  $stmt = $db->prepare('INSERT INTO admin_settings (setting_key, setting_value, updated_at) VALUES (:k, :v, :u)
    ON CONFLICT(setting_key) DO UPDATE SET setting_value = excluded.setting_value, updated_at = excluded.updated_at');
  $stmt->bindValue(':k', 'design_settings', SQLITE3_TEXT);
  $stmt->bindValue(':v', json_encode($current, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), SQLITE3_TEXT);
  $stmt->bindValue(':u', nowIso(), SQLITE3_TEXT);
  $stmt->execute();
}

function readDesignSettingsFromInput(array $settings): array {
  $defaults = defaultDesignSettings();
  $allowedFonts = ['Onest', 'Inter', 'Roboto', 'Arial', 'Georgia', 'Unbounded', 'Montserrat', 'Poppins', 'Oswald'];
  $fontBody = in_array((string)($settings['fontBody'] ?? ''), $allowedFonts, true) ? (string)$settings['fontBody'] : $defaults['fontBody'];
  $fontDisplay = in_array((string)($settings['fontDisplay'] ?? ''), $allowedFonts, true) ? (string)$settings['fontDisplay'] : $defaults['fontDisplay'];
  $fontScale = max(90, min(130, (int)($settings['fontScale'] ?? $defaults['fontScale'])));
  $colors = is_array($settings['colors'] ?? null) ? $settings['colors'] : [];
  $colorsDark = is_array($settings['colorsDark'] ?? null) ? $settings['colorsDark'] : [];
  $accessibility = is_array($settings['accessibility'] ?? null) ? $settings['accessibility'] : [];
  $layout = is_array($settings['layout'] ?? null) ? $settings['layout'] : [];
  $gradients = is_array($settings['gradients'] ?? null) ? $settings['gradients'] : [];

  $normalizedColors = [
    'primary' => sanitizeHexColor((string)($colors['primary'] ?? ''), $defaults['colors']['primary']),
    'secondary' => sanitizeHexColor((string)($colors['secondary'] ?? ''), $defaults['colors']['secondary']),
    'tertiary' => sanitizeHexColor((string)($colors['tertiary'] ?? ''), $defaults['colors']['tertiary']),
    'background' => sanitizeHexColor((string)($colors['background'] ?? ''), $defaults['colors']['background']),
    'text' => sanitizeHexColor((string)($colors['text'] ?? ''), $defaults['colors']['text']),
    'surface' => sanitizeHexColor((string)($colors['surface'] ?? ''), $defaults['colors']['surface']),
  ];
  $normalizedDarkColors = [
    'primary' => sanitizeHexColor((string)($colorsDark['primary'] ?? ''), $defaults['colorsDark']['primary']),
    'secondary' => sanitizeHexColor((string)($colorsDark['secondary'] ?? ''), $defaults['colorsDark']['secondary']),
    'tertiary' => sanitizeHexColor((string)($colorsDark['tertiary'] ?? ''), $defaults['colorsDark']['tertiary']),
    'background' => sanitizeHexColor((string)($colorsDark['background'] ?? ''), $defaults['colorsDark']['background']),
    'text' => sanitizeHexColor((string)($colorsDark['text'] ?? ''), $defaults['colorsDark']['text']),
    'surface' => sanitizeHexColor((string)($colorsDark['surface'] ?? ''), $defaults['colorsDark']['surface']),
  ];

  return [
    'fontBody' => $fontBody,
    'fontDisplay' => $fontDisplay,
    'fontScale' => $fontScale,
    'tokenMode' => 'auto',
    'colors' => $normalizedColors,
    'colorsDark' => $normalizedDarkColors,
    'accessibility' => [
      'highContrast' => (bool)($accessibility['highContrast'] ?? false),
      'reduceMotion' => (bool)($accessibility['reduceMotion'] ?? false),
    ],
    'layout' => [
      'homeSections' => sanitizeHomeSectionsOrder($layout['homeSections'] ?? null, $defaults['layout']['homeSections']),
    ],
    'gradients' => [
      'main' => sanitizeGradient((string)($gradients['main'] ?? ''), $defaults['gradients']['main']),
    ],
    'tokens' => deriveDesignTokens($normalizedColors, false),
    'tokensDark' => deriveDesignTokens($normalizedDarkColors, true),
  ];
}

function healthStatus(SQLite3 $db): array {
  global $dbPath, $root;

  $sitemapPath = $root . '/sitemap.xml';
  $mailLogPath = $root . '/data/mail-events.log';

  $mailFailed24h = 0;
  $mailSent24h = 0;
  $rateLimited24h = 0;
  $mailFailureAlertThreshold = (int)(getenv('APLUS_MAIL_FAILURE_ALERT_THRESHOLD') ?: 0);
  $windowStart = time() - 86400;

  if (is_file($mailLogPath)) {
    $lines = @file($mailLogPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    foreach ($lines as $line) {
      $decoded = json_decode((string)$line, true);
      if (!is_array($decoded)) {
        continue;
      }
      $at = isset($decoded['at']) ? strtotime((string)$decoded['at']) : false;
      if ($at === false || $at < $windowStart) {
        continue;
      }
      $event = (string)($decoded['event'] ?? '');
      if ($event === 'mail_failed') {
        $mailFailed24h++;
      } elseif ($event === 'mail_sent') {
        $mailSent24h++;
      } elseif ($event === 'rate_limit') {
        $rateLimited24h++;
      }
    }
  }

  return [
    'ok' => true,
    'time' => gmdate('c'),
    'appEnv' => appEnvironment(),
    'dbExists' => is_file($dbPath),
    'dbWritable' => is_writable(dirname($dbPath)),
    'sitemapExists' => is_file($sitemapPath),
    'sitemapUpdatedAt' => is_file($sitemapPath) ? gmdate('c', (int)filemtime($sitemapPath)) : null,
    'mailLogExists' => is_file($mailLogPath),
    'mailLogUpdatedAt' => is_file($mailLogPath) ? gmdate('c', (int)filemtime($mailLogPath)) : null,
    'mailSent24h' => $mailSent24h,
    'mailFailed24h' => $mailFailed24h,
    'rateLimited24h' => $rateLimited24h,
    'mailFailureAlertThreshold' => $mailFailureAlertThreshold,
    'mailAlertsOk' => $mailFailed24h <= $mailFailureAlertThreshold,
  ];
}

$db = sqlite();
initDb($db);
assertProductionBootstrap($db);
ensureSecurityDefaults($db);
migrateJsonToDb($db);
syncJsonExports($db);

$action = $_GET['action'] ?? 'status';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if (csrfRequired($action, $method)) {
  validateCsrfToken();
}

if ($action === 'status') {
  jsonResponse([
    'ok' => true,
    'authenticated' => isAuthed(),
    'ttl' => SESSION_TTL,
    'lockedUntil' => (int)($_SESSION['aplus_admin_lock_until'] ?? 0),
    'usesAccessCodes' => true,
    'csrfToken' => ensureCsrfToken(),
    'bootstrap' => getBootstrapState($db),
  ]);
}

if ($action === 'bootstrap-health') {
  jsonResponse([
    'ok' => true,
    'bootstrap' => getBootstrapState($db),
  ]);
}

if ($action === 'health') {
  jsonResponse(healthStatus($db));
}

if ($action === 'login' && $method === 'POST') {
  if (isLockedOut()) {
    jsonResponse(['ok' => false, 'error' => 'Слишком много попыток входа. Попробуйте позже.'], 429);
  }

  $input = requestJson();
  $password = (string)($input['password'] ?? '');
  $accessCode = (string)($input['accessCode'] ?? '');

  $storedHash = (string)$db->querySingle("SELECT setting_value FROM admin_settings WHERE setting_key = 'password_hash'");
  $passwordOk = $storedHash !== '' && password_verify($password, $storedHash);
  $codeOk = verifyAccessCode($db, $accessCode);

  if (!$passwordOk || !$codeOk) {
    registerFailedAttempt();
    jsonResponse(['ok' => false, 'error' => 'Неверный пароль или код доступа.'], 403);
  }

  clearAttempts();
  $_SESSION[SESSION_KEY] = time();
  appendAuditLog($db, 'login', null, null, ['success' => true]);
  jsonResponse(['ok' => true, 'csrfToken' => ensureCsrfToken()]);
}


if ($action === 'public-home-content') {
  jsonResponse(['ok' => true, 'content' => readHomeContent($db)]);
}

if ($action === 'public-design-settings') {
  jsonResponse(['ok' => true, 'settings' => readDesignSettings($db)]);
}

if ($action === 'public-catalog') {
  $category = ensureCategory((string)($_GET['category'] ?? ''));
  $stmt = $db->prepare('SELECT MAX(updated_at) AS updated_at FROM products WHERE category = :c');
  $stmt->bindValue(':c', $category, SQLITE3_TEXT);
  $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);

  jsonResponse([
    'ok' => true,
    'category' => $category,
    'updatedAt' => (string)($row['updated_at'] ?? ''),
    'items' => fetchCategoryItems($db, $category),
  ]);
}

if ($action === 'logout' && $method === 'POST') {
  appendAuditLog($db, 'logout');
  unset($_SESSION['aplus_admin_csrf']);
  session_destroy();
  jsonResponse(['ok' => true]);
}

requireAuth();

if ($action === 'catalog-summary') {
  $summary = [];
  foreach (allowedCategories() as $category) {
    $stmt = $db->prepare('SELECT COUNT(*) AS cnt FROM products WHERE category = :c');
    $stmt->bindValue(':c', $category, SQLITE3_TEXT);
    $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    $summary[$category] = (int)($row['cnt'] ?? 0);
  }
  jsonResponse(['ok' => true, 'summary' => $summary]);
}

if ($action === 'list') {
  $category = ensureCategory((string)($_GET['category'] ?? ''));
  jsonResponse(['ok' => true, 'items' => fetchCategoryItems($db, $category)]);
}

if ($action === 'save' && $method === 'POST') {
  $input = requestJson();
  $category = ensureCategory((string)($input['category'] ?? ''));
  $items = $input['items'] ?? null;
  if (!is_array($items)) {
    jsonResponse(['ok' => false, 'error' => 'Invalid payload'], 422);
  }

  $db->exec('BEGIN');
  try {
    $del = $db->prepare('DELETE FROM products WHERE category = :c');
    $del->bindValue(':c', $category, SQLITE3_TEXT);
    $del->execute();

    $ins = $db->prepare('INSERT INTO products (category, product_key, sort_order, data_json, created_at, updated_at) VALUES (:c, :k, :s, :d, :n, :u)');

    foreach (array_values($items) as $index => $item) {
      if (!is_array($item)) {
        continue;
      }
      $item = ensureItemSlug($item, $index);
      $primary = trim((string)($item['id'] ?? $item['title'] ?? 'item-' . ($index + 1)));
      $ins->bindValue(':c', $category, SQLITE3_TEXT);
      $ins->bindValue(':k', normalizeProductKey($primary, $index), SQLITE3_TEXT);
      $ins->bindValue(':s', $index, SQLITE3_INTEGER);
      $ins->bindValue(':d', json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), SQLITE3_TEXT);
      $ins->bindValue(':n', nowIso(), SQLITE3_TEXT);
      $ins->bindValue(':u', nowIso(), SQLITE3_TEXT);
      $ins->execute();
    }

    $db->exec('COMMIT');
  } catch (Throwable $e) {
    $db->exec('ROLLBACK');
    jsonResponse(['ok' => false, 'error' => 'Database write failed'], 500);
  }

  syncJsonExports($db);
  $sitemapOk = regenerateSitemapFromJson();
  appendAuditLog($db, 'save_category', $category, count($items), ['sitemap_regenerated' => $sitemapOk]);
  jsonResponse(['ok' => true, 'sitemapRegenerated' => $sitemapOk]);
}

if ($action === 'upload-image' && $method === 'POST') {
  if (empty($_FILES['image'])) {
    jsonResponse(['ok' => false, 'error' => 'No file'], 422);
  }
  $file = $_FILES['image'];
  if ($file['error'] !== UPLOAD_ERR_OK) {
    jsonResponse(['ok' => false, 'error' => 'Upload error'], 422);
  }
  if ((int)$file['size'] > MAX_UPLOAD_SIZE_BYTES) {
    jsonResponse(['ok' => false, 'error' => 'Файл больше 8MB'], 422);
  }

  $tmp = $file['tmp_name'];
  $mime = mime_content_type($tmp);
  $extMap = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
  if (!isset($extMap[$mime])) {
    jsonResponse(['ok' => false, 'error' => 'Поддерживаются только JPG/PNG/WEBP'], 422);
  }

  $name = uniqid('img_', true) . '.' . $extMap[$mime];
  $target = $uploadDir . '/' . $name;
  if (!move_uploaded_file($tmp, $target)) {
    jsonResponse(['ok' => false, 'error' => 'Move failed'], 500);
  }

  appendAuditLog($db, 'upload_image', null, null, ['file' => $name]);
  jsonResponse(['ok' => true, 'url' => '/uploads/' . $name]);
}


if ($action === 'translate-text' && $method === 'POST') {
  $input = requestJson();
  $source = strtolower(trim((string)($input['source'] ?? 'ru')));
  $target = strtolower(trim((string)($input['target'] ?? 'en')));
  $texts = $input['texts'] ?? [];

  if (!in_array($source, ['ru', 'en'], true) || !in_array($target, ['ru', 'en'], true)) {
    jsonResponse(['ok' => false, 'error' => 'Поддерживаются только языки ru/en'], 422);
  }
  if (!is_array($texts) || count($texts) === 0) {
    jsonResponse(['ok' => false, 'error' => 'Нет текста для перевода'], 422);
  }
  if (count($texts) > 200) {
    jsonResponse(['ok' => false, 'error' => 'Слишком много полей для перевода за один запрос'], 422);
  }

  try {
    $translated = [];
    foreach ($texts as $text) {
      $translated[] = translateTextViaGoogle((string)$text, $source, $target);
    }
    appendAuditLog($db, 'translate_text', null, count($translated), ['source' => $source, 'target' => $target]);
    jsonResponse(['ok' => true, 'texts' => $translated]);
  } catch (Throwable $e) {
    jsonResponse(['ok' => false, 'error' => 'Ошибка перевода: ' . $e->getMessage()], 502);
  }
}


if ($action === 'home-content') {
  jsonResponse(['ok' => true, 'content' => readHomeContent($db)]);
}

if ($action === 'save-home-content' && $method === 'POST') {
  $input = requestJson();
  $content = $input['content'] ?? null;
  if (!is_array($content)) {
    jsonResponse(['ok' => false, 'error' => 'Некорректный формат данных главной страницы'], 422);
  }
  saveHomeContent($db, $content);
  appendAuditLog($db, 'save_home_content');
  jsonResponse(['ok' => true]);
}

if ($action === 'design-settings') {
  jsonResponse(['ok' => true, 'settings' => readDesignSettings($db)]);
}

if ($action === 'save-design-settings' && $method === 'POST') {
  $input = requestJson();
  $settings = $input['settings'] ?? null;
  if (!is_array($settings)) {
    jsonResponse(['ok' => false, 'error' => 'Некорректный формат настроек дизайна'], 422);
  }
  saveDesignSettings($db, $settings);
  appendAuditLog($db, 'save_design_settings');
  jsonResponse(['ok' => true]);
}

if ($action === 'save-home-layout' && $method === 'POST') {
  $input = requestJson();
  $layout = $input['layout'] ?? null;
  if (!is_array($layout)) {
    jsonResponse(['ok' => false, 'error' => 'Некорректный формат порядка блоков'], 422);
  }
  saveHomeLayout($db, $layout);
  appendAuditLog($db, 'save_home_layout');
  jsonResponse(['ok' => true]);
}

if ($action === 'export') {
  $bundle = [];
  foreach (allowedCategories() as $category) {
    $bundle[$category] = fetchCategoryItems($db, $category);
  }
  jsonResponse(['ok' => true, 'data' => $bundle]);
}

if ($action === 'logs') {
  $result = $db->query('SELECT action, category, item_count, actor_ip, metadata_json, created_at FROM audit_logs ORDER BY id DESC LIMIT 200');
  $items = [];
  while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $row['metadata'] = json_decode((string)($row['metadata_json'] ?? '{}'), true);
    unset($row['metadata_json']);
    $items[] = $row;
  }
  jsonResponse(['ok' => true, 'items' => $items]);
}

if ($action === 'security-settings') {
  jsonResponse([
    'ok' => true,
    'activeAccessCodes' => activeAccessCodeCount($db),
  ]);
}

if ($action === 'change-password' && $method === 'POST') {
  $input = requestJson();
  $currentPassword = (string)($input['currentPassword'] ?? '');
  $newPassword = (string)($input['newPassword'] ?? '');

  if (mb_strlen($newPassword) < 8) {
    jsonResponse(['ok' => false, 'error' => 'Новый пароль должен быть не короче 8 символов'], 422);
  }

  $storedHash = (string)$db->querySingle("SELECT setting_value FROM admin_settings WHERE setting_key = 'password_hash'");
  if ($storedHash === '' || !password_verify($currentPassword, $storedHash)) {
    jsonResponse(['ok' => false, 'error' => 'Текущий пароль указан неверно'], 403);
  }

  upsertPasswordHash($db, $newPassword);
  appendAuditLog($db, 'change_password');
  jsonResponse(['ok' => true]);
}

if ($action === 'list-access-codes') {
  $result = $db->query('SELECT id, label, is_active, created_at FROM access_codes ORDER BY id DESC');
  $items = [];
  while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $items[] = [
      'id' => (int)$row['id'],
      'label' => (string)($row['label'] ?? ''),
      'isActive' => (int)$row['is_active'] === 1,
      'createdAt' => (string)($row['created_at'] ?? ''),
    ];
  }
  jsonResponse(['ok' => true, 'items' => $items]);
}

if ($action === 'add-access-code' && $method === 'POST') {
  $input = requestJson();
  $code = trim((string)($input['code'] ?? ''));
  $label = trim((string)($input['label'] ?? 'manual'));
  if ($code === '' || mb_strlen($code) < 6) {
    jsonResponse(['ok' => false, 'error' => 'Код доступа должен быть не короче 6 символов'], 422);
  }

  $stmt = $db->prepare('INSERT INTO access_codes (code_hash, label, is_active, created_at) VALUES (:h, :l, 1, :c)');
  $stmt->bindValue(':h', password_hash($code, PASSWORD_DEFAULT), SQLITE3_TEXT);
  $stmt->bindValue(':l', $label, SQLITE3_TEXT);
  $stmt->bindValue(':c', nowIso(), SQLITE3_TEXT);
  $stmt->execute();

  appendAuditLog($db, 'add_access_code', null, null, ['label' => $label]);
  jsonResponse(['ok' => true]);
}

if ($action === 'deactivate-access-code' && $method === 'POST') {
  $input = requestJson();
  $id = (int)($input['id'] ?? 0);
  if ($id <= 0) {
    jsonResponse(['ok' => false, 'error' => 'Неверный id кода'], 422);
  }

  $activeCount = activeAccessCodeCount($db);
  if ($activeCount <= 1) {
    jsonResponse(['ok' => false, 'error' => 'Нельзя отключить последний активный код'], 422);
  }

  $stmt = $db->prepare('UPDATE access_codes SET is_active = 0 WHERE id = :id');
  $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
  $stmt->execute();

  appendAuditLog($db, 'deactivate_access_code', null, null, ['id' => $id]);
  jsonResponse(['ok' => true]);
}

jsonResponse(['ok' => false, 'error' => 'Not found'], 404);
