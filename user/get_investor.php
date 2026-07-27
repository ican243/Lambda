<?php
// 개인/외국인/기관 순매수 추이. 비로그인 공개.
require_once __DIR__ . '/../config/kis_api.php';
header('Content-Type: application/json');

$code = trim($_GET['code'] ?? '');
if ($code === '') { echo json_encode(['error' => 'no code']); exit; }

try {
    echo json_encode(getInvestorTrend($code));
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
