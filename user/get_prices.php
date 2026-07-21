<?php
require_once 'func.php';
startUserSession();

if (!isLoggedIn()) {
    http_response_code(401);
    exit;
}

header('Content-Type: application/json');
$prices = getMyWatchlistPrices($conn, $_SESSION['user_id']);
echo json_encode($prices);
