<?php
// "로그인 없이 둘러보기" — 모두가 같이 쓰는 체험 계정으로 바로 로그인시켜 준다.
// POST + CSRF 로만 받는다 (링크 한 번 눌린 걸로 남의 브라우저를 체험 계정으로 바꿔치기하는 것 방지).
require_once 'func.php';
/** @var mysqli $conn */   // config/db.php 에서 넘어옴 (에디터 자동완성·오탐 방지용)
startUserSession();
requireCsrf('login.php', 'redirect', 'csrf');

$next = safeNext($_POST['next'] ?? '', 'index.php');

try {
    $uid = getDemoUserId($conn);
    if (!$uid) $uid = resetDemoAccount($conn)['user_id'];   // 처음 한 번은 여기서 만들어진다
} catch (Exception $e) {
    logError($conn, 'error', 'demo', '체험 계정 준비 실패: ' . $e->getMessage());
    header('Location: login.php?error=demo');
    exit;
}

// 다른 계정으로 로그인돼 있었다면 그 세션은 버리고 새로 시작
session_regenerate_id(true);
$_SESSION = [];
$_SESSION['user_id']  = $uid;
$_SESSION['nickname'] = DEMO_NICKNAME;
$_SESSION['is_demo']  = true;
logAccess($conn, $uid, 'login');

header('Location: ' . $next);
exit;
