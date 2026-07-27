<?php
// 커뮤니티 글 작성. 로그인 필요.
require_once 'func.php';
startUserSession();
header('Content-Type: application/json');

if (!isLoggedIn()) { echo json_encode(['error' => 'login']); exit; }

$code    = trim($_POST['stock_code'] ?? '');
$content = trim($_POST['content'] ?? '');
if ($code === '' || $content === '') { echo json_encode(['error' => 'empty']); exit; }

$ok = addStockPost($conn, $code, $_SESSION['user_id'], $_SESSION['nickname'] ?? '익명', $content);
echo json_encode(['ok' => (bool) $ok]);
