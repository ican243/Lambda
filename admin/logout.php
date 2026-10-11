<?php
require_once 'func.php';
startAdminSession();

// 로그아웃은 버튼(POST + 보안 토큰)으로만 — 주소만 열어서는 안 된다
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: index.php'); exit; }
requireCsrf('index.php', 'redirect', null, 'err');

session_unset();
session_destroy();

header('Location: login.php');
exit;
