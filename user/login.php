<?php
require_once 'func.php';
startUserSession();

// 로그인 후 돌아갈 곳(예: 자동매매). 외부주소는 safeNext가 걸러낸다.
$next = safeNext($_GET['next'] ?? '', '');

if (isLoggedIn()) {
    header('Location: ' . ($next !== '' ? $next : 'index.php'));
    exit;
}

// 가입 직후·로그인 실패 후에는 이메일을 미리 채워 준다 (한 번 쓰고 지움)
$prefill = $_SESSION['login_email'] ?? '';
unset($_SESSION['login_email']);

$error = $_GET['error'] ?? '';
$nextQs = $next !== '' ? '?next=' . urlencode($next) : '';

$pageTitle = '로그인';
include 'includes/header.php';
?>

<div class="lg">
    <div class="lg-card">
        <div class="lg-mark">모</div>
        <h1 class="lg-title">다시 만나서 반가워요</h1>
        <p class="lg-sub">이메일로 로그인하고 모의투자를 이어서 해 보세요.</p>

        <?php if (isset($_GET['joined'])): ?>
            <div class="lg-alert ok">🎉 <span>가입이 끝났어요! 비밀번호를 입력하면 바로 시작할 수 있어요.</span></div>
        <?php elseif ($error === 'suspended'): ?>
            <div class="lg-alert err">⛔ <span>이용이 정지된 계정이에요. 관리자에게 문의해 주세요.</span></div>
        <?php elseif ($error === 'demo'): ?>
            <div class="lg-alert err">⚠️ <span>체험 계정을 준비하지 못했어요. 잠시 후 다시 시도해 주세요.</span></div>
        <?php elseif ($error === 'locked'): ?>
            <div class="lg-alert err">⏳ <span>비밀번호를 여러 번 틀렸어요. <b><?= max(1, (int) ($_GET['wait'] ?? 10)) ?>분</b> 뒤에 다시 시도해 주세요.</span></div>
        <?php elseif ($error === 'csrf'): ?>
            <div class="lg-alert err">⚠️ <span>보안 확인 시간이 지났어요. 다시 한 번 로그인해 주세요.</span></div>
        <?php elseif ($error !== ''): ?>
            <div class="lg-alert err">⚠️ <span>이메일 또는 비밀번호가 맞지 않아요.</span></div>
        <?php elseif ($next !== ''): ?>
            <div class="lg-alert info">🔒 <span>로그인이 필요한 화면이에요. 로그인하면 바로 이어서 볼 수 있어요.</span></div>
        <?php endif; ?>

        <form action="login_process.php" method="POST" id="lg-form" novalidate>
            <?= csrfField() ?>
            <input type="hidden" name="next" value="<?= htmlspecialchars($next) ?>">

            <div class="lg-field">
                <label for="lg-email">이메일</label>
                <div class="lg-input">
                    <input type="email" id="lg-email" name="email" autocomplete="email" inputmode="email" placeholder="example@email.com"
                           value="<?= htmlspecialchars($prefill) ?>" required>
                </div>
            </div>

            <div class="lg-field">
                <label for="lg-pw">비밀번호</label>
                <div class="lg-input has-eye">
                    <input type="password" id="lg-pw" name="password" autocomplete="current-password" placeholder="비밀번호" required>
                    <button type="button" class="lg-eye" data-eye="lg-pw" aria-label="비밀번호 보기">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>
                    </button>
                </div>
            </div>

            <button type="submit" class="lg-btn" id="lg-submit">로그인</button>
        </form>

        <!-- 가입 없이 바로 둘러보기 (모두가 같이 쓰는 체험 계정, 매일 새벽 초기화) -->
        <div class="lg-or">또는</div>
        <form action="demo_login.php" method="POST" style="margin:0;">
            <?= csrfField() ?>
            <input type="hidden" name="next" value="<?= htmlspecialchars($next !== '' ? $next : 'index.php') ?>">
            <button type="submit" class="lg-btn ghost">로그인 없이 둘러보기</button>
        </form>

        <p class="lg-foot">아직 회원이 아니신가요?<a href="join.php<?= $nextQs ?>">회원가입</a></p>
    </div>
</div>

<script>
(function () {
    'use strict';
    const form = document.getElementById('lg-form');
    const email = document.getElementById('lg-email'), pw = document.getElementById('lg-pw'), btn = document.getElementById('lg-submit');

    // ⚠️ 버튼을 '두 칸이 다 차야 켜지게' 만들지 않는다.
    //    크롬이 저장된 비밀번호를 자동으로 채우면, 사용자가 화면을 한 번 누르기 전까지 페이지가 그 값을 못 읽어서
    //    버튼이 꺼진 채로 남는다(= 로그인을 못 함). 그래서 버튼은 항상 켜 두고, 누를 때 빈칸만 검사한다.
    function mark(input, bad) { input.closest('.lg-field').classList.toggle('bad', bad); }
    [email, pw].forEach(el => el.addEventListener('input', () => mark(el, false)));

    // 이메일이 미리 채워져 있으면 비밀번호 칸부터
    (email.value ? pw : email).focus();

    // 비밀번호 보기/숨기기
    document.querySelectorAll('[data-eye]').forEach(b => b.addEventListener('click', () => {
        const input = document.getElementById(b.dataset.eye);
        const show = input.type === 'password';
        input.type = show ? 'text' : 'password';
        b.setAttribute('aria-label', show ? '비밀번호 숨기기' : '비밀번호 보기');
        b.style.color = show ? 'var(--primary)' : '';
    }));

    // 두 번 눌러도 한 번만 보낸다
    form.addEventListener('submit', e => {
        if (btn.disabled) { e.preventDefault(); return; }
        const emptyEmail = !email.value.trim(), emptyPw = !pw.value;
        if (emptyEmail || emptyPw) {
            e.preventDefault();
            mark(email, emptyEmail); mark(pw, emptyPw);
            (emptyEmail ? email : pw).focus();
            return;
        }
        btn.disabled = true; btn.textContent = '로그인하는 중…';
    });
})();
</script>

<?php include 'includes/footer.php'; ?>
