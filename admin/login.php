<?php
require_once 'func.php';
startAdminSession();

if (isAdminLoggedIn()) {
    header('Location: index.php');
    exit;
}

include 'includes/header.php';
?>

<h2 class="mb-4">관리자 로그인</h2>

<?php if (isset($_GET['error'])): ?>
    <div class="alert alert-danger">아이디 또는 비밀번호가 일치하지 않습니다.</div>
<?php endif; ?>

<form action="login_process.php" method="POST">
    <div class="mb-3">
        <label class="form-label">관리자 아이디</label>
        <input type="text" name="admin_id" class="form-control" required>
    </div>
    <div class="mb-3">
        <label class="form-label">비밀번호</label>
        <input type="password" name="password" class="form-control" required>
    </div>
    <button type="submit" class="btn btn-dark">로그인</button>
</form>

<?php include 'includes/footer.php'; ?>