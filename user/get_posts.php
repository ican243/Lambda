<?php
// 종목 커뮤니티 글 목록. 비로그인도 열람 공개.
require_once 'func.php';
/** @var mysqli $conn */   // config/db.php 에서 넘어옴 (에디터 자동완성·오탐 방지용)
header('Content-Type: application/json');

$code = trim($_GET['code'] ?? '');
if ($code === '') { echo json_encode([]); exit; }

echo json_encode(getStockPosts($conn, $code));
