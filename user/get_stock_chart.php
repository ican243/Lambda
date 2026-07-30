<?php
// 종목 차트 데이터 — 비로그인도 차트를 볼 수 있게 공개.
require_once 'func.php';
/** @var mysqli $conn */   // config/db.php 에서 넘어옴 (에디터 자동완성·오탐 방지용)

$stockCode = trim($_GET['stock_code'] ?? '');
header('Content-Type: application/json');
echo json_encode(getCandleData($conn, $stockCode));
