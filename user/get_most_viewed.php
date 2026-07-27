<?php
// 홈 대시보드 우측 "지금 많이 봐요"(조회수 상위) JSON.
// 로그인 없이 누구나 조회 가능.
require_once 'func.php';

header('Content-Type: application/json');
echo json_encode(getMostViewedStocks($conn, 15), JSON_UNESCAPED_UNICODE);
