<?php
require_once 'func.php';
startUserSession();

$email = trim($_POST['email']);
$password = $_POST['password'];

// 1. 이메일로 유저 조회
$user = getUserByEmail($conn, $email);

// 2. 유저가 없거나, 비밀번호가 틀리면 실패
if (!$user || !password_verify($password, $user['password'])) {
    header('Location: login.php?error=1');
    exit;
}

// 2-1. 관리자가 정지한 계정은 로그인 차단
if (($user['status'] ?? 'active') === 'suspended') {
    header('Location: login.php?error=suspended');
    exit;
}

// 3. 로그인 성공 → 세션에 유저 정보 저장
$_SESSION['user_id'] = $user['id'];
$_SESSION['nickname'] = $user['nickname'];

// 접속 로그(IP) — FDS/CS 기반 데이터
logAccess($conn, $user['id'], 'login');

header('Location: index.php');
exit;
