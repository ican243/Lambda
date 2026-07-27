<?php
// 종목 커뮤니티 글 목록. 비로그인도 열람 공개.
require_once 'func.php';
header('Content-Type: application/json');

$code = trim($_GET['code'] ?? '');
if ($code === '') { echo json_encode([]); exit; }

echo json_encode(getStockPosts($conn, $code));
