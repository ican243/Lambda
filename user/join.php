<?php
require_once 'func.php';
startUserSession();

if (isLoggedIn()) {
    header('Location: index.php');
    exit;
}

include 'includes/header.php';
?>

<h2 class="mb-4">회원가입</h2>

<?php if (isset($_GET['error'])): ?>
    <div class="alert alert-danger">
        <?php
        if ($_GET['error'] == 'dup_email') echo "이미 사용 중인 이메일입니다.";
        elseif ($_GET['error'] == 'pw_mismatch') echo "비밀번호가 일치하지 않습니다.";
        else echo "회원가입에 실패했습니다.";
        ?>
    </div>
<?php endif; ?>

<form action="join_process.php" method="POST">
    <div class="mb-3">
        <label class="form-label">이메일</label>
        <input type="email" name="email" class="form-control" required>
    </div>
    <div class="mb-3">
        <label class="form-label">비밀번호</label>
        <input type="password" name="password" class="form-control" required>
    </div>
    <div class="mb-3">
        <label class="form-label">비밀번호 확인</label>
        <input type="password" name="password_confirm" class="form-control" required>
    </div>
    <div class="mb-3">
        <label class="form-label">닉네임</label>
        <input type="text" name="nickname" class="form-control" required>
    </div>
    <button type="submit" class="btn btn-primary">가입하기</button>
</form>

<?php include 'includes/footer.php'; ?>