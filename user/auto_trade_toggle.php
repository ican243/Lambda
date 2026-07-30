<?php
// user/auto_trade_toggle.php
// 자동매매 일시정지 / 재개 토글. 로그인 + CSRF 필수.
// 지금은 상태만 DB에 저장한다 → 나중에 붙는 매매 로직이 이 값을 읽고 주문을 멈추면 됨.
require_once 'func.php';
/** @var mysqli $conn */   // config/db.php 에서 넘어옴 (에디터 자동완성·오탐 방지용)
startUserSession();
header('Content-Type: application/json');

if (!isLoggedIn()) { http_response_code(401); echo json_encode(['error' => 'login']); exit; }
requireCsrf('', 'json');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error' => 'method']); exit; }

$userId = (int) $_SESSION['user_id'];

// paused 값을 클라이언트가 보내되, 화이트리스트('0'|'1')로만 받는다.
// 값이 없으면 현재 상태를 뒤집는다(토글).
$raw = $_POST['paused'] ?? null;
if ($raw === '0' || $raw === '1') {
    $paused = ($raw === '1');
} elseif ($raw === null) {
    $paused = !isAutoTradePaused($conn, $userId);
} else {
    http_response_code(400);
    echo json_encode(['error' => 'bad_value']);
    exit;
}

$ok = setAutoTradePaused($conn, $userId, $paused);
echo json_encode(['ok' => (bool) $ok, 'paused' => $paused]);
