<?php
require_once 'func.php';
startUserSession();

// 1. 값 받아오기
$email = trim($_POST['email']);
$password = $_POST['password'];
$passwordConfirm = $_POST['password_confirm'];
$nickname = trim($_POST['nickname']);

// 2. 비밀번호 확인 일치 체크
if ($password !== $passwordConfirm) {
    header('Location: join.php?error=pw_mismatch');
    exit;
}

// 3. 이메일 중복 체크
if (emailExists($conn, $email)) {
    header('Location: join.php?error=dup_email');
    exit;
}

// 4. 비밀번호 암호화
$hashedPassword = password_hash($password, PASSWORD_DEFAULT);

// 5. DB에 저장
$success = createUser($conn, $email, $hashedPassword, $nickname);

if ($success) {
    $newUserId = mysqli_insert_id($conn);   // 방금 만든 유저 가져오기
    createAccount($conn, $newUserId); //계좌도 같이 생성

    header('Location: login.php?joined=1');
    exit;
} else {
    header('Location: join.php?error=fail');
    exit;
}
