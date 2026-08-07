<?php
// 종목 차트 데이터 — 비로그인도 차트를 볼 수 있게 공개.
//
// range 파라미터로 기간을 고른다(1D/1W/1M/3M/1Y/2Y). 없거나 모르는 값이면 1D로 떨어진다.
// 해상도(1분봉·5분봉·일봉)는 서버가 기간에 맞춰 알아서 정한다 → func.php chartRangeSpec 주석 참고.
// (2026-08-07: 1M을 30분봉→일봉으로, 5Y→2Y로 변경. 5Y는 옛 링크 호환용으로 규격표에만 남아 있음)
require_once 'func.php';
/** @var mysqli $conn */   // config/db.php 에서 넘어옴 (에디터 자동완성·오탐 방지용)

$stockCode = trim($_GET['stock_code'] ?? '');
$range     = trim($_GET['range'] ?? '1D');

header('Content-Type: application/json');

// 종목코드 형식 검증 — 이상한 값이면 쿼리 자체를 안 날리고 빈 배열
if (!preg_match('/^[0-9A-Z]{6}$/', $stockCode)) {
    echo json_encode([]);
    exit;
}

echo json_encode(getCandleData($conn, $stockCode, $range));
