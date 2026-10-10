<?php
require_once 'func.php';
/** @var mysqli $conn */   // config/db.php 에서 넘어옴 (에디터 자동완성·오탐 방지용)
startUserSession();
requireCsrf('join.php', 'redirect', 'csrf');

// 1. 값 받아오기
$email = trim($_POST['email'] ?? '');
$password = $_POST['password'] ?? '';
$passwordConfirm = $_POST['password_confirm'] ?? '';
$nickname = trim($_POST['nickname'] ?? '');
$next = safeNext($_POST['next'] ?? '', '');   // 가입 후 로그인 → 원래 보던 화면으로

// 실패하면 입력값(비밀번호 제외)을 들고 가입 화면으로 되돌린다 → 처음부터 다시 칠 필요 없게
function backToJoin($code, $email, $nickname, $next)
{
    $_SESSION['join_old'] = ['email' => $email, 'nickname' => $nickname];
    header('Location: join.php?error=' . $code . ($next !== '' ? '&next=' . urlencode($next) : ''));
    exit;
}

// 2. 서버측 입력 검증 — 화면의 검사는 개발자도구로 우회할 수 있으므로 여기서 반드시 다시 막는다
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 100) backToJoin('email', $email, $nickname, $next);
if (strlen($password) < 8 || strlen($password) > 72)                        backToJoin('pw_len', $email, $nickname, $next);   // 72바이트 초과분은 bcrypt가 무시함
$nickLen = mb_strlen($nickname);
if ($nickLen < 2 || $nickLen > 12)                                           backToJoin('nick', $email, $nickname, $next);

// 3. 비밀번호 확인 일치 체크
if ($password !== $passwordConfirm) backToJoin('pw_mismatch', $email, $nickname, $next);

// 4. 이메일 중복 체크
if (emailExists($conn, $email)) backToJoin('dup_email', $email, $nickname, $next);

// 5. 비밀번호 암호화
$hashedPassword = password_hash($password, PASSWORD_DEFAULT);

// 6. DB에 저장
$success = createUser($conn, $email, $hashedPassword, $nickname);

if ($success) {
    $newUserId = mysqli_insert_id($conn);   // 방금 만든 유저 가져오기
    createAccount($conn, $newUserId); //계좌도 같이 생성

    $_SESSION['login_email'] = $email;      // 로그인 화면에 이메일을 미리 채워 준다
    header('Location: login.php?joined=1' . ($next !== '' ? '&next=' . urlencode($next) : ''));
    exit;
} else {
    backToJoin('fail', $email, $nickname, $next);
}
