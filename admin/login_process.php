<?php
require_once 'func.php';
/** @var mysqli $conn */   // config/db.php 에서 넘어옴 (에디터 자동완성·오탐 방지용)
startAdminSession();
requireCsrf('login.php', 'redirect', 'csrf');

$adminId = trim($_POST['admin_id'] ?? '');
$password = $_POST['password'] ?? '';

// 너무 많이 틀렸으면 잠깐 막는다 (5번 틀리면 10분)
$lockSec = loginLockSeconds($conn, 'admin', $adminId);
if ($lockSec > 0) {
    header('Location: login.php?error=locked&wait=' . ceil($lockSec / 60));
    exit;
}

$admin = getAdminByAdminId($conn, $adminId);

if (!$admin || !password_verify($password, $admin['password'])) {
    recordLoginFail($conn, 'admin', $adminId);
    header('Location: login.php?error=1');
    exit;
}

clearLoginFails($conn, 'admin', $adminId);
session_regenerate_id(true);   // 로그인 성공 → 세션 번호 새로 발급 (세션 고정 공격 방지)
$_SESSION['admin_id'] = $admin['id'];
$_SESSION['admin_name'] = $admin['name'];
$_SESSION['admin_role'] = $admin['role'] ?? 'super';   // 컬럼 도입 전 로그인이면 super(기존 관리자)

header('Location: index.php');
exit;
