<?php
// 홈 대시보드 우측 "지금 많이 봐요"(조회수 상위) JSON.
// 로그인 없이 누구나 조회 가능.
require_once 'func.php';
/** @var mysqli $conn */   // config/db.php 에서 넘어옴 (에디터 자동완성·오탐 방지용)

header('Content-Type: application/json');
echo json_encode(getMostViewedStocks($conn, 15), JSON_UNESCAPED_UNICODE);
