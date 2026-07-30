<?php
require_once 'func.php';
startUserSession();

if (isLoggedIn()) {
    header('Location: index.php');
    exit;
}

include 'includes/header.php';
?>

<div style="margin: 40px 4px 24px;">
    <h2 style="font-size:24px;font-weight:700;margin:0;">회원가입</h2>
    <p class="muted" style="font-size:15px;margin:8px 0 0;">가입하면 1,000만원 모의자금을 드려요</p>
</div>

<?php if (isset($_GET['error'])): ?>
    <div class="alert-t alert-err">
        <?php
        if ($_GET['error'] == 'dup_email') echo "이미 사용 중인 이메일입니다.";
        elseif ($_GET['error'] == 'pw_mismatch') echo "비밀번호가 일치하지 않습니다.";
        elseif ($_GET['error'] == 'csrf') echo "보안 토큰이 만료되었습니다. 다시 시도해주세요.";
        else echo "회원가입에 실패했습니다.";
        ?>
    </div>
<?php endif; ?>

<form action="join_process.php" method="POST">
    <?= csrfField() ?>
    <label class="label-t">이메일</label>
    <input type="email" name="email" class="input-t" required style="margin-bottom:14px;">

    <label class="label-t">비밀번호</label>
    <input type="password" name="password" class="input-t" required style="margin-bottom:14px;">

    <label class="label-t">비밀번호 확인</label>
    <input type="password" name="password_confirm" class="input-t" required style="margin-bottom:14px;">

    <label class="label-t">닉네임</label>
    <input type="text" name="nickname" class="input-t" required style="margin-bottom:24px;">

    <button type="submit" class="btn-t btn-primary-t btn-block">가입하기</button>
</form>

<p style="text-align:center;margin-top:20px;">
    <a href="login.php" class="muted" style="font-size:14px;font-weight:600;text-decoration:none;">이미 회원이신가요? <span style="color:var(--primary);">로그인</span></a>
</p>

<?php include 'includes/footer.php'; ?>