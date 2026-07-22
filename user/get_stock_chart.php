<?php
require_once 'func.php';
startUserSession();

if (!isLoggedIn()) {
    http_response_code(401);
    exit;
}

$stockCode = trim($_GET['stock_code'] ?? '');
header('Content-Type: application/json');
echo json_encode(getCandleData($conn, $stockCode));
