<?php
require_once 'func.php';
startUserSession();

// 로그아웃은 버튼(POST + 보안 토큰)으로만 — 주소만 열어서는 안 된다
// (다른 사이트가 <img src="…/logout.php"> 같은 걸로 몰래 로그아웃시키는 장난 방지)
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: index.php'); exit; }
requireCsrf('index.php');
$to = $_POST['to'] ?? '';

session_unset();     // 세션에 저장된 값 전부 제거
session_destroy();   // 세션 자체를 파괴

// ?to=join → 체험 계정 배너의 '내 계정 만들기'에서 온 경우, 로그아웃 후 바로 회원가입 화면으로
header('Location: ' . ($to === 'join' ? 'join.php' : 'login.php'));
exit;
