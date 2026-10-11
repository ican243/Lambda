<?php
// 호가 10단계. 비로그인 공개.
require_once __DIR__ . '/../config/kis_api.php';
header('Content-Type: application/json');

$code = trim($_GET['code'] ?? '');
if ($code === '') { echo json_encode(['error' => 'no code']); exit; }

try {
    echo json_encode(getOrderbook($code) ?? ['error' => 'no data']);
} catch (Exception $e) {
    // KIS 시세 서버에 닿지 않음 → 'offline' 으로 통일. 화면은 ⚠️ 오류 대신 차분한 안내를 보여 준다.
    echo json_encode(['error' => 'offline']);
}
