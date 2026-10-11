<?php
require_once 'func.php';
/** @var mysqli $conn */   // config/db.php 에서 넘어옴 (에디터 자동완성·오탐 방지용)
startUserSession();
requireCsrf('login.php', 'redirect', 'csrf');

$email = trim($_POST['email'] ?? '');
$password = $_POST['password'] ?? '';

// 로그인 후 돌아갈 곳(자동매매 등). 실패해서 폼으로 되돌릴 때도 계속 들고 다닌다.
$next = safeNext($_POST['next'] ?? '', '');
$backQs = $next !== '' ? '&next=' . urlencode($next) : '';

// 0. 너무 많이 틀렸으면 잠깐 막는다 (비밀번호를 확인하기도 전에)
$lockSec = loginLockSeconds($conn, 'user', $email);
if ($lockSec > 0) {
    $_SESSION['login_email'] = $email;
    header('Location: login.php?error=locked&wait=' . ceil($lockSec / 60) . $backQs);
    exit;
}

// 1. 이메일로 유저 조회
$user = getUserByEmail($conn, $email);

// 2. 유저가 없거나, 비밀번호가 틀리면 실패
if (!$user || !password_verify($password, $user['password'])) {
    recordLoginFail($conn, 'user', $email);
    $_SESSION['login_email'] = $email;   // 다시 칠 필요 없게 이메일은 남겨 둔다 (비밀번호는 절대 안 남김)
    header('Location: login.php?error=1' . $backQs);
    exit;
}

// 2-1. 관리자가 정지한 계정은 로그인 차단
if (($user['status'] ?? 'active') === 'suspended') {
    header('Location: login.php?error=suspended' . $backQs);
    exit;
}

clearLoginFails($conn, 'user', $email);

// 3. 로그인 성공 → 세션 번호를 새로 발급(세션 고정 공격 방지)한 뒤 유저 정보 저장
session_regenerate_id(true);
$_SESSION['user_id'] = $user['id'];
$_SESSION['nickname'] = $user['nickname'];

// 접속 로그(IP) — FDS/CS 기반 데이터
logAccess($conn, $user['id'], 'login');

header('Location: ' . ($next !== '' ? $next : 'index.php'));
exit;
