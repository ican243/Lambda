<?php
require_once 'func.php';
startAdminSession();

$adminId = trim($_POST['admin_id']);
$password = $_POST['password'];

$admin = getAdminByAdminId($conn, $adminId);

if (!$admin || !password_verify($password, $admin['password'])) {
    header('Location: login.php?error=1');
    exit;
}

$_SESSION['admin_id'] = $admin['id'];
$_SESSION['admin_name'] = $admin['name'];
$_SESSION['admin_role'] = $admin['role'] ?? 'super';   // 컬럼 도입 전 로그인이면 super(기존 관리자)

header('Location: index.php');
exit;
