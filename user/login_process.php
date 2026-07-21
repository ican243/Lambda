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

// 3. 로그인 성공 → 세션에 유저 정보 저장
$_SESSION['user_id'] = $user['id'];
$_SESSION['nickname'] = $user['nickname'];

header('Location: index.php');
exit;
