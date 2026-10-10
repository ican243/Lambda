<?php
// 페이지 이동형 주문 (폼 POST → 처리 후 trade.php 로 이동).
// 종목 상세 화면은 이제 order_api.php(화면 이동 없음)를 쓰지만, 다른 곳에서 쓸 수 있게 남겨둔다.
// 검증·체결 로직은 func.php placeMockOrder() 한 곳에 있다.
require_once 'func.php';
/** @var mysqli $conn */   // config/db.php 에서 넘어옴 (에디터 자동완성·오탐 방지용)
startUserSession();

if (!isLoggedIn()) {
    header('Location: login.php');
    exit;
}

// CSRF 검증 — 외부 사이트가 대신 쏜 주문 차단
requireCsrf('trade.php');

$stockCode = trim($_POST['stock_code'] ?? '');
$quantity  = (int) ($_POST['quantity'] ?? 0);
$orderType = $_POST['order_type'] ?? '';

// 거래 시도 접속 로그(IP)
logAccess($conn, $_SESSION['user_id'], 'trade');

try {
    placeMockOrder($conn, $_SESSION['user_id'], $stockCode, $orderType, $quantity);
    header('Location: trade.php?success=' . urlencode('주문이 완료되었습니다.'));
    exit;
} catch (Exception $e) {
    // 주문 체결 실패 → 에러 로그 수집 (관리자 대시보드에서 확인)
    logError($conn, 'error', 'trade', "주문실패 uid={$_SESSION['user_id']} {$orderType} {$stockCode} x{$quantity}: " . $e->getMessage());
    header('Location: trade.php?error=' . urlencode($e->getMessage()));
    exit;
}
