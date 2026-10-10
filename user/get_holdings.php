<?php
// 내 계좌 화면 30초 갱신용 — 현금 + 보유종목(현재가 포함). 로그인 필요.
// 실시간 서버는 홈 화면 종목만 상시 구독하므로, 그 밖의 보유 종목 가격은 이걸로 따라간다.
require_once 'func.php';
/** @var mysqli $conn */   // config/db.php 에서 넘어옴 (에디터 자동완성·오탐 방지용)
startUserSession();
header('Content-Type: application/json');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'login']);
    exit;
}

$uid = $_SESSION['user_id'];
echo json_encode([
    'cash'     => (int) getMyAccount($conn, $uid)['cash_balance'],
    'holdings' => array_map(fn($h) => [
        'code'     => $h['stock_code'],
        'name'     => $h['stock_name'],
        'qty'      => (int) $h['quantity'],
        'avg'      => (float) $h['avg_price'],
        'price'    => $h['current_price'] ? (int) $h['current_price'] : null,
        'price_at' => $h['price_at'],
    ], getMyHoldings($conn, $uid)),
], JSON_UNESCAPED_UNICODE);
