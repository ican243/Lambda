<?php

require_once 'func.php';
/** @var mysqli $conn */   // config/db.php 에서 넘어옴 (에디터 자동완성·오탐 방지용)
startUserSession();

if (!isLoggedIn()) {
    http_response_code(401);
    exit;
}
requireCsrf('', 'json');

$stockCode = trim($_POST['stock_code'] ?? '');
if ($stockCode !== '') {
    removeWatchlist($conn, $_SESSION['user_id'], $stockCode);
}

header('Content-Type: application/json');
echo json_encode(['success' => true]);
