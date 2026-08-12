<?php
require_once __DIR__ . '/../../includes/auth.php';
require_login();

header('Content-Type: application/json');

$pdo = get_db_conn();
$uid = (int)($_SESSION['user']['id'] ?? 0);

$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($action === 'get' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $pdo->prepare('SELECT layout, quick_actions FROM user_dashboard_layout WHERE user_id = :uid');
    $stmt->execute([':uid' => $uid]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $layout = $row && $row['layout'] ? json_decode($row['layout'], true) : null;
    $quickActions = $row && $row['quick_actions'] ? json_decode($row['quick_actions'], true) : null;
    echo json_encode([
        'success' => true,
        'layout' => is_array($layout) ? $layout : null,
        'quick_actions' => is_array($quickActions) ? $quickActions : null,
    ]);
    exit;
}

if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        echo json_encode(['success' => false, 'error' => 'Invalid request body']);
        exit;
    }
    if (!csrf_verify($input['csrf'] ?? '')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
        exit;
    }

    $allowedIds = ['secActionCenter', 'secInventoryStatus', 'secChartsTrends', 'secNotifications'];
    $layout = $input['layout'] ?? null;
    if (!is_array($layout) || array_diff($layout, $allowedIds)) {
        echo json_encode(['success' => false, 'error' => 'Invalid layout']);
        exit;
    }

    $stmt = $pdo->prepare("
        INSERT INTO user_dashboard_layout (user_id, layout, updated_at)
        VALUES (:uid, :layout, NOW())
        ON CONFLICT (user_id) DO UPDATE
        SET layout = EXCLUDED.layout, updated_at = NOW()
    ");
    $stmt->execute([':uid' => $uid, ':layout' => json_encode(array_values($layout))]);

    echo json_encode(['success' => true]);
    exit;
}

if ($action === 'save_quick_actions' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        echo json_encode(['success' => false, 'error' => 'Invalid request body']);
        exit;
    }
    if (!csrf_verify($input['csrf'] ?? '')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
        exit;
    }

    $keys = $input['quick_actions'] ?? null;
    if (!is_array($keys)) {
        echo json_encode(['success' => false, 'error' => 'Invalid quick_actions']);
        exit;
    }
    // Catalog keys are internal identifiers (index.php), not free text — enforce
    // the same shape regardless of what the catalog contains today, and cap the
    // count so a malformed client can't stuff an unbounded array into storage.
    $keys = array_values(array_filter(array_map('strval', $keys), function ($k) {
        return preg_match('/^[a-z0-9_]{1,40}$/', $k) === 1;
    }));
    $keys = array_slice(array_values(array_unique($keys)), 0, 30);

    $stmt = $pdo->prepare("
        INSERT INTO user_dashboard_layout (user_id, quick_actions, updated_at)
        VALUES (:uid, :qa, NOW())
        ON CONFLICT (user_id) DO UPDATE
        SET quick_actions = EXCLUDED.quick_actions, updated_at = NOW()
    ");
    $stmt->execute([':uid' => $uid, ':qa' => json_encode($keys)]);

    echo json_encode(['success' => true]);
    exit;
}

echo json_encode(['success' => false, 'error' => 'Unknown action']);
