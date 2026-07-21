<?php


require_once 'func.php';
startUserSession();

if (!isLoggedIn()) {
    http_response_code(401);
    exit;
}

$stockCode = trim($_POST['stock_code'] ?? '');
if ($stockCode !== '') {
    addWatchlist($conn, $_SESSION['user_id'], $stockCode);
}

header('Content-Type: application/json');
echo json_encode(['success' => true]);
