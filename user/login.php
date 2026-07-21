<?php
require_once 'func.php';
startUserSession();

if (isLoggedIn()) {
    header('Location: index.php');
    exit;
}

include 'includes/header.php';
?>

<h2 class="mb-4">로그인</h2>

<?php if (isset($_GET['joined'])): ?>
    <div class="alert alert-success">회원가입이 완료되었습니다. 로그인해주세요.</div>
<?php endif; ?>

<?php if (isset($_GET['error'])): ?>
    <div class="alert alert-danger">이메일 또는 비밀번호가 일치하지 않습니다.</div>
<?php endif; ?>

<form action="login_process.php" method="POST">
    <div class="mb-3">
        <label class="form-label">이메일</label>
        <input type="email" name="email" class="form-control" required>
    </div>
    <div class="mb-3">
        <label class="form-label">비밀번호</label>
        <input type="password" name="password" class="form-control" required>
    </div>
    <button type="submit" class="btn btn-primary">로그인</button>
</form>

<p class="mt-3"><a href="join.php">회원가입</a></p>

<?php include 'includes/footer.php'; ?>