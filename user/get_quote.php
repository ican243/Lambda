<?php
// 종목 전체 시세(지표 스트립용). 비로그인 공개.
require_once __DIR__ . '/../config/kis_api.php';
header('Content-Type: application/json');

$code = trim($_GET['code'] ?? '');
if ($code === '') { echo json_encode(['error' => 'no code']); exit; }

try {
    echo json_encode(getFullQuote($code) ?? ['error' => 'no data']);
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
