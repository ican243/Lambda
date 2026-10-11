<?php
// 종목 전체 시세(지표 스트립용). 비로그인 공개.
// KIS에 닿지 않으면 DB에 저장된 마지막 시세(getQuoteFromDb)로 대신 답한다 → 화면에 오류 대신 마지막 값.
require_once __DIR__ . '/../config/kis_api.php';
require_once __DIR__ . '/func.php';
header('Content-Type: application/json');

$code = trim($_GET['code'] ?? '');
if ($code === '') {
    echo json_encode(['error' => 'no code']);
    exit;
}

try {
    $q = getFullQuote($code);
} catch (Exception $e) {
    $q = null;
}
echo json_encode($q ?? getQuoteFromDb($conn, $code) ?? ['error' => 'no data']);
