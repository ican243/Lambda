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
    <h2 style="font-size:24px;font-weight:700;margin:0;">로그인</h2>
    <p class="muted" style="font-size:15px;margin:8px 0 0;">모의투자를 시작해보세요</p>
</div>

<?php if (isset($_GET['joined'])): ?>
    <div class="alert-t alert-ok">회원가입이 완료되었습니다. 로그인해주세요.</div>
<?php endif; ?>
<?php if (($_GET['error'] ?? '') === 'suspended'): ?>
    <div class="alert-t alert-err">정지된 계정입니다. 관리자에게 문의해주세요.</div>
<?php elseif (isset($_GET['error'])): ?>
    <div class="alert-t alert-err">이메일 또는 비밀번호가 일치하지 않습니다.</div>
<?php endif; ?>

<form action="login_process.php" method="POST">
    <label class="label-t">이메일</label>
    <input type="email" name="email" class="input-t" required style="margin-bottom:14px;">

    <label class="label-t">비밀번호</label>
    <input type="password" name="password" class="input-t" required style="margin-bottom:24px;">

    <button type="submit" class="btn-t btn-primary-t btn-block">로그인</button>
</form>

<p style="text-align:center;margin-top:20px;">
    <a href="join.php" class="muted" style="font-size:14px;font-weight:600;text-decoration:none;">아직 회원이 아니신가요? <span style="color:var(--primary);">회원가입</span></a>
</p>

<?php include 'includes/footer.php'; ?>
