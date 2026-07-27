<?php
// 종목 검색 — 비로그인 방문자도 종목을 찾아볼 수 있게 공개.
// (관심종목 추가는 watchlist_add.php 에서 로그인 검사)
require_once 'func.php';

$keyword = trim($_GET['keyword'] ?? '');
header('Content-Type: application/json');

if ($keyword === '') {
    echo json_encode([]);
    exit;
}

echo json_encode(searchStocks($conn, $keyword));
