<?php
require_once 'func.php';
startAdminSession();

if (!isAdminLoggedIn()) {
    header('Location: login.php');
    exit;
}
ensureAdminSchema($conn);

$action = $_POST['action'] ?? '';
$userId = (int) ($_POST['user_id'] ?? 0);
// 복귀 경로 (오픈 리다이렉트 방지: 허용된 admin 페이지만)
$back = $_POST['back'] ?? 'users.php';
if (!preg_match('#^(users\.php|user_detail\.php\?id=\d+)$#', $back)) $back = 'users.php';
$sep = (strpos($back, '?') !== false) ? '&' : '?';   // back에 이미 ?id= 있으면 &로 이어붙임

if ($userId <= 0) {
    header("Location: {$back}{$sep}err=" . urlencode('잘못된 요청입니다.'));
    exit;
}

try {
    if ($action === 'status') {
        requireAdminCan('suspend_user', $back);   // RBAC: staff·super 가능
        $status = $_POST['status'] ?? 'active';
        setUserStatus($conn, $userId, $status);
        $label = ($status === 'suspended') ? '정지' : '활성화';
        header("Location: {$back}{$sep}msg=" . urlencode("#{$userId} 계정을 {$label} 처리했습니다."));
        exit;
    }

    if ($action === 'cash') {
        requireAdminCan('adjust_cash', $back);     // RBAC: super 전용
        $delta  = (int) ($_POST['delta'] ?? 0);
        $reason = trim($_POST['reason'] ?? '');
        if ($delta === 0) throw new Exception('조정 금액을 입력하세요.');
        $after = adjustUserCash($conn, $_SESSION['admin_id'] ?? 0, $userId, $delta, $reason);
        $sign = $delta > 0 ? '+' : '';
        header("Location: {$back}{$sep}msg=" . urlencode("#{$userId} 예수금 {$sign}" . number_format($delta) . "원 조정 완료 (현재 " . number_format($after) . "원)"));
        exit;
    }

    throw new Exception('알 수 없는 작업입니다.');
} catch (Exception $e) {
    header("Location: {$back}{$sep}err=" . urlencode($e->getMessage()));
    exit;
}
