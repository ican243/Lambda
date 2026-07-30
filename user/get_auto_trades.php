<?php
// user/get_auto_trades.php
// 자동매매 화면 폴링용 JSON. 본인 데이터만 → 로그인 필수.
// 5초마다 호출해서 ①거래내역 최신 15건 ②보유 상위5(도넛) 를 갱신한다.
// 나중에 자동매매 로직이 orders 에 INSERT 만 해도 화면이 저절로 흐른다(프론트 재작업 없음).
require_once 'func.php';
/** @var mysqli $conn */   // config/db.php 에서 넘어옴 (에디터 자동완성·오탐 방지용)
startUserSession();
header('Content-Type: application/json');

// IDOR 방어: 종목/유저 id를 파라미터로 받지 않고 세션의 user_id만 사용
if (!isLoggedIn()) { http_response_code(401); echo json_encode(['error' => 'login']); exit; }
$userId = (int) $_SESSION['user_id'];

echo json_encode([
    'portfolio' => getAutoPortfolio($conn, $userId, 5),
    'trades'    => getAutoTradeHistory($conn, $userId, 15),
    'paused'    => isAutoTradePaused($conn, $userId),
    'cash'      => (int) (getMyAccount($conn, $userId)['cash_balance'] ?? 0),
], JSON_UNESCAPED_UNICODE);
