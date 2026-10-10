<?php
// 커뮤니티 글 작성. 로그인 필요.
require_once 'func.php';
/** @var mysqli $conn */   // config/db.php 에서 넘어옴 (에디터 자동완성·오탐 방지용)
startUserSession();
header('Content-Type: application/json');

if (!isLoggedIn()) { echo json_encode(['error' => 'login']); exit; }
requireCsrf('', 'json');

// 체험 계정은 여럿이 같이 쓰는 계정이라 커뮤니티 글쓰기를 막는다 (낙서·도배 방지)
if (isDemoUser($conn)) { echo json_encode(['error' => 'demo']); exit; }

$code    = trim($_POST['stock_code'] ?? '');
$content = trim($_POST['content'] ?? '');
if ($code === '' || $content === '') { echo json_encode(['error' => 'empty']); exit; }

$ok = addStockPost($conn, $code, $_SESSION['user_id'], $_SESSION['nickname'] ?? '익명', $content);
echo json_encode(['ok' => (bool) $ok]);
