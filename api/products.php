<?php
require_once __DIR__ . '/../config/php-bootstrap.php';
aplus_init_error_logging('api-products');

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Cache-Control: public, max-age=3600, stale-while-revalidate=300');

if (!in_array($_SERVER['REQUEST_METHOD'] ?? '', ['GET', 'HEAD'], true)) {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use GET.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit();
}

$allowedCategories = ['batteries', 'copters', 'water'];
$category = strtolower(trim((string)($_GET['category'] ?? '')));
if ($category === '' || !in_array($category, $allowedCategories, true)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid category. Use one of: batteries, copters, water.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit();
}

$dbPath = __DIR__ . '/../data/catalog.sqlite';
if (!is_file($dbPath)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Catalog database is not available.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit();
}

$pdo = new PDO('sqlite:' . $dbPath, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$stmt = $pdo->prepare('SELECT product_key, data_json, updated_at FROM products WHERE category = :category ORDER BY sort_order ASC, id ASC');
$stmt->execute([':category' => $category]);
$rows = $stmt->fetchAll();

$items = [];
$latestUpdatedAt = '';

foreach ($rows as $row) {
    $decoded = json_decode((string)($row['data_json'] ?? ''), true);
    if (!is_array($decoded)) {
        continue;
    }

    // Keep stable API contract: expose item slug even if legacy records miss explicit id.
    if (!isset($decoded['id']) || !is_string($decoded['id']) || trim($decoded['id']) === '') {
        $decoded['id'] = (string)($row['product_key'] ?? '');
    }

    $items[] = $decoded;

    $updatedAt = (string)($row['updated_at'] ?? '');
    if ($updatedAt !== '' && ($latestUpdatedAt === '' || strcmp($updatedAt, $latestUpdatedAt) > 0)) {
        $latestUpdatedAt = $updatedAt;
    }
}

$response = [
    'success' => true,
    'category' => $category,
    'count' => count($items),
    'updated_at' => $latestUpdatedAt,
    'items' => $items,
];

$etag = 'W/"' . hash('sha256', json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '"';
header('ETag: ' . $etag);

$ifNoneMatch = trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
if ($ifNoneMatch !== '' && $ifNoneMatch === $etag) {
    http_response_code(304);
    exit();
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'HEAD') {
    exit();
}

echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
