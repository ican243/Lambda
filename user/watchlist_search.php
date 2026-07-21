<?php
require_once 'func.php';
startUserSession();

if (!isLoggedIn()) {
    http_response_code(401);
    exit;
}

$keyword = trim($_GET['keyword'] ?? '');
header('Content-Type: application/json');

if ($keyword === '') {
    echo json_encode([]);
    exit;
}

echo json_encode(searchStocks($conn, $keyword));
