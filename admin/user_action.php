<?php
require_once 'func.php';
/** @var mysqli $conn */   // config/db.php 에서 넘어옴 (에디터 자동완성·오탐 방지용)
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

// CSRF 검증 — 관리자 세션을 노린 위조 요청(예: 해커 계정에 예수금 지급) 차단
// $back 은 위에서 화이트리스트 검증을 마친 값이라 리다이렉트 대상으로 안전하다.
requireCsrf($back, 'redirect', null, 'err');

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
