<?php
require_once 'func.php';
startAdminSession();

if (isAdminLoggedIn()) {
    header('Location: index.php');
    exit;
}

$adminBare  = true;
$adminTitle = '로그인';
include 'includes/header.php';
?>

<div class="lg-card">
    <div class="lg-mark" style="background:var(--inverse);">모</div>
    <h1 class="lg-title">관리자 로그인</h1>
    <p class="lg-sub">모아증권 운영 도구예요. 관리자 계정으로 로그인해 주세요.</p>

    <?php if (($_GET['error'] ?? '') === 'csrf'): ?>
        <div class="lg-alert err">⚠️ <span>보안 확인 시간이 지났어요. 다시 한 번 로그인해 주세요.</span></div>
    <?php elseif (isset($_GET['error'])): ?>
        <div class="lg-alert err">⚠️ <span>아이디 또는 비밀번호가 맞지 않아요.</span></div>
    <?php endif; ?>

    <form action="login_process.php" method="POST" id="ad-login-form">
        <?= csrfField() ?>
        <div class="lg-field">
            <label for="ad-id">관리자 아이디</label>
            <div class="lg-input"><input type="text" id="ad-id" name="admin_id" autocomplete="username" required autofocus></div>
        </div>
        <div class="lg-field">
            <label for="ad-pw">비밀번호</label>
            <div class="lg-input"><input type="password" id="ad-pw" name="password" autocomplete="current-password" required></div>
        </div>
        <button type="submit" class="lg-btn" style="background:var(--inverse);">로그인</button>
    </form>
</div>
<script>
    // 두 번 눌러도 한 번만 보낸다
    document.getElementById('ad-login-form').addEventListener('submit', function () {
        const b = this.querySelector('button'); b.disabled = true; b.textContent = '로그인하는 중…';
    });
</script>

<?php include 'includes/footer.php'; ?>
