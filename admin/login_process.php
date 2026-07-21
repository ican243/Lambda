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

header('Location: index.php');
exit;
