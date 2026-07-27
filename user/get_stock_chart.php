<?php
// 종목 차트 데이터 — 비로그인도 차트를 볼 수 있게 공개.
require_once 'func.php';

$stockCode = trim($_GET['stock_code'] ?? '');
header('Content-Type: application/json');
echo json_encode(getCandleData($conn, $stockCode));
