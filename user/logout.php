<?php
require_once 'func.php';
startUserSession();

session_unset();     // 세션에 저장된 값 전부 제거
session_destroy();   // 세션 자체를 파괴

// ?to=join → 체험 계정 배너의 '내 계정 만들기'에서 온 경우, 로그아웃 후 바로 회원가입 화면으로
header('Location: ' . (($_GET['to'] ?? '') === 'join' ? 'join.php' : 'login.php'));
exit;
