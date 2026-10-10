<?php
// 화면 이동 없는 주문 (종목 상세 주문 패널 전용). JSON 으로 주고받는다.
//   POST stock_code, order_type(buy|sell), quantity, csrf_token
//   → 성공: {success, order_type, price, quantity, total, cash_balance, holding:{quantity, avg_price}|null}
//   → 실패: {error: 'login'|'csrf'|'사용자에게 보여줄 문장'}  (+ HTTP 상태코드)
// 검증·체결 로직은 func.php placeMockOrder() — trade_process.php 와 같은 규칙.
require_once 'func.php';
/** @var mysqli $conn */   // config/db.php 에서 넘어옴 (에디터 자동완성·오탐 방지용)
startUserSession();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST 요청만 받습니다.']);
    exit;
}
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'login']);
    exit;
}
requireCsrf('', 'json');   // 실패 시 403 {error:'csrf'} 응답 후 종료

$userId    = $_SESSION['user_id'];
$stockCode = trim($_POST['stock_code'] ?? '');
$quantity  = (int) ($_POST['quantity'] ?? 0);
$orderType = $_POST['order_type'] ?? '';

logAccess($conn, $userId, 'trade');

try {
    $r = placeMockOrder($conn, $userId, $stockCode, $orderType, $quantity);
    $account = getMyAccount($conn, $userId);
    echo json_encode([
        'success'      => true,
        'order_type'   => $orderType,
        'price'        => $r['price'],
        'quantity'     => $r['quantity'],
        'total'        => $r['total'],
        'cash_balance' => (int) $account['cash_balance'],
        'holding'      => getMyHolding($conn, $userId, $stockCode),
    ]);
} catch (Exception $e) {
    logError($conn, 'error', 'trade', "주문실패 uid={$userId} {$orderType} {$stockCode} x{$quantity}: " . $e->getMessage());
    http_response_code(422);
    echo json_encode(['error' => $e->getMessage()]);
}
