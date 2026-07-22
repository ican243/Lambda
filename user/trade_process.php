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
$orderType = $_POST['order_type'];

try {
    // 1. API 재호출 대신, DB에 이미 쌓인 최신 가격 사용
    $latest = getSingleStockPrice($conn, $stockCode);
    if (!$latest || !$latest['price']) {
        throw new Exception("현재 시세 정보가 없습니다. 잠시 후 다시 시도해주세요.");
    }
    $currentPrice = $latest['price'];

    // 2. 한투 API로 실제 모의주문 전송 (이건 여전히 필요, 진짜 주문이니까)
    placeStockOrder($stockCode, $quantity, $orderType);

    // 3. 우리 DB 트랜잭션 처리
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
