<?php
require_once 'func.php';
require_once '../config/kis_api.php';
startUserSession();

if (!isLoggedIn()) {
    header('Location: login.php');
    exit;
}

$stockCode = trim($_POST['stock_code']);
$quantity = (int) $_POST['quantity'];
$orderType = $_POST['order_type'];   // 'buy' 또는 'sell'

try {
    // 1. 현재가 조회 (실시간 가격으로 체결가 삼음)
    $priceData = getStockPrice($stockCode);
    $currentPrice = $priceData['price'];

    // 2. 한투 API로 실제 모의주문 전송
    placeStockOrder($stockCode, $quantity, $orderType);

    // 3. 우리 DB에도 반영 (잔고/보유종목/주문내역)
    if ($orderType === 'buy') {
        processBuy($conn, $_SESSION['user_id'], $stockCode, $quantity, $currentPrice);
    } else {
        processSell($conn, $_SESSION['user_id'], $stockCode, $quantity, $currentPrice);
    }

    header('Location: trade.php?success=' . urlencode('주문이 완료되었습니다.'));
    exit;
} catch (Exception $e) {
    header('Location: trade.php?error=' . urlencode($e->getMessage()));
    exit;
}
