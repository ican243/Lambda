<?php
// 홈 대시보드용 인기종목(거래대금 상위) JSON.
// 로그인 없이 누구나 조회 가능 — 비로그인 방문자도 시세를 볼 수 있어야 하므로.
require_once 'func.php';

header('Content-Type: application/json');
echo json_encode(getPopularStocks($conn, 100));
