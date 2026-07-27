<?php
// 최근 체결 내역 + 체결강도. 비로그인 공개.
require_once __DIR__ . '/../config/kis_api.php';
header('Content-Type: application/json');

$code = trim($_GET['code'] ?? '');
if ($code === '') { echo json_encode(['error' => 'no code']); exit; }

try {
    echo json_encode(getRecentTrades($code));
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
