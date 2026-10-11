<?php
require_once 'func.php';
startUserSession();

if (isLoggedIn()) {
    header('Location: index.php');
    exit;
}

// 가입 후 로그인 → 원래 보던 화면으로 돌아가도록 계속 들고 다닌다
$next = safeNext($_GET['next'] ?? '', '');

// 서버 검사에 걸려 돌아왔을 때 입력값(비밀번호 제외)을 다시 채워 준다 (한 번 쓰고 지움)
$old = $_SESSION['join_old'] ?? ['email' => '', 'nickname' => ''];
unset($_SESSION['join_old']);

// 서버 오류 → 어느 칸 문제인지 + 안내 문장
$error = $_GET['error'] ?? '';
$fieldErr = [
    'email'       => ['email', '이메일 형식이 맞지 않아요.'],
    'dup_email'   => ['email', '이미 가입된 이메일이에요. 로그인해 보세요.'],
    'pw_len'      => ['pw', '비밀번호는 8자 이상이어야 해요.'],
    'pw_mismatch' => ['pw2', '비밀번호가 서로 달라요.'],
    'nick'        => ['nick', '닉네임은 2~12자로 정해 주세요.'],
][$error] ?? null;
$alertMsg = $error === 'csrf' ? '보안 확인 시간이 지났어요. 다시 한 번 시도해 주세요.'
          : (($error !== '' && !$fieldErr) ? '가입하지 못했어요. 잠시 후 다시 시도해 주세요.' : '');

$pageTitle = '회원가입';
include 'includes/header.php';
?>

<div class="lg">
    <div class="lg-card">
        <div class="lg-mark">모</div>
        <h1 class="lg-title">1,000만원으로<br>모의투자 시작하기</h1>
        <p class="lg-sub">진짜 시세로 사고팔아 보세요. <b>실제 돈은 하나도 들지 않아요.</b></p>
        <div class="lg-perks"><span>💰 모의자금 1,000만원</span><span>📈 실시간 시세</span><span>🤖 자동매매</span></div>

        <?php if ($alertMsg): ?>
            <div class="lg-alert err">⚠️ <span><?= $alertMsg ?></span></div>
        <?php endif; ?>

        <form action="join_process.php" method="POST" id="jn-form" novalidate>
            <?= csrfField() ?>
            <input type="hidden" name="next" value="<?= htmlspecialchars($next) ?>">

            <div class="lg-field" data-f="email">
                <label for="jn-email">이메일</label>
                <div class="lg-input"><input type="email" id="jn-email" name="email" autocomplete="email" inputmode="email" maxlength="100"
                       placeholder="example@email.com" value="<?= htmlspecialchars($old['email']) ?>" required></div>
                <p class="lg-hint">로그인할 때 쓰는 이메일이에요.</p>
            </div>

            <div class="lg-field" data-f="nick">
                <label for="jn-nick">닉네임</label>
                <div class="lg-input"><input type="text" id="jn-nick" name="nickname" autocomplete="nickname" maxlength="12"
                       placeholder="2~12자" value="<?= htmlspecialchars($old['nickname']) ?>" required></div>
                <p class="lg-hint">커뮤니티 글에 보이는 이름이에요.</p>
            </div>

            <div class="lg-field" data-f="pw">
                <label for="jn-pw">비밀번호</label>
                <div class="lg-input has-eye"><input type="password" id="jn-pw" name="password" autocomplete="new-password" maxlength="72" placeholder="8자 이상" required>
                    <button type="button" class="lg-eye" data-eye="jn-pw" aria-label="비밀번호 보기"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg></button>
                </div>
                <p class="lg-hint">8자 이상으로 정해 주세요.</p>
            </div>

            <div class="lg-field" data-f="pw2">
                <label for="jn-pw2">비밀번호 확인</label>
                <div class="lg-input has-eye"><input type="password" id="jn-pw2" name="password_confirm" autocomplete="new-password" maxlength="72" placeholder="한 번 더 입력" required>
                    <button type="button" class="lg-eye" data-eye="jn-pw2" aria-label="비밀번호 보기"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg></button>
                </div>
                <p class="lg-hint"></p>
            </div>

            <button type="submit" class="lg-btn" id="jn-submit">가입하기</button>
        </form>

        <p class="lg-foot">이미 회원이신가요?<a href="login.php<?= $next !== '' ? '?next=' . urlencode($next) : '' ?>">로그인</a></p>
    </div>
</div>

<script>
(function () {
    'use strict';
    const $ = id => document.getElementById(id);
    const el = { email: $('jn-email'), nick: $('jn-nick'), pw: $('jn-pw'), pw2: $('jn-pw2') };
    const defaultHint = {};
    document.querySelectorAll('.lg-field[data-f]').forEach(f => { defaultHint[f.dataset.f] = f.querySelector('.lg-hint').textContent; });

    // 칸마다 검사 규칙 — 서버(join_process.php)와 같은 기준. ok: 통과 / msg: 보여줄 문장
    const rules = {
        email: v => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v.trim()) ? { ok: true } : { ok: false, msg: '이메일 형식이 맞지 않아요.' },
        nick:  v => { const n = [...v.trim()].length; return n >= 2 && n <= 12 ? { ok: true, msg: `${n}/12자` } : { ok: false, msg: n < 2 ? '2자 이상 입력해 주세요.' : '12자까지 쓸 수 있어요.' }; },
        pw:    v => v.length >= 8 ? { ok: true, msg: '사용할 수 있는 비밀번호예요.' } : { ok: false, msg: `8자 이상으로 정해 주세요. (지금 ${v.length}자)` },
        pw2:   v => v && v === el.pw.value ? { ok: true, msg: '비밀번호가 같아요.' } : { ok: false, msg: v ? '비밀번호가 서로 달라요.' : '' },
    };
    const touched = {};

    function paint(key, forceShow) {
        const field = document.querySelector(`.lg-field[data-f="${key}"]`), hint = field.querySelector('.lg-hint');
        const r = rules[key](el[key].value);
        field.classList.remove('bad', 'good');
        // 아직 손대지 않은 칸은 빨갛게 하지 않는다 (처음부터 혼나는 느낌이 들지 않게)
        if (!touched[key] && !forceShow) { hint.textContent = defaultHint[key]; return r.ok; }
        if (r.ok) { field.classList.add('good'); hint.textContent = r.msg || ''; }
        else if (r.msg) { field.classList.add('bad'); hint.textContent = r.msg; }
        else hint.textContent = defaultHint[key];
        return r.ok;
    }
    Object.entries(el).forEach(([k, input]) => {
        input.addEventListener('input', () => {
            if (touched[k] || (k === 'pw' && input.value.length >= 8)) { touched[k] = true; paint(k); }
            if (k === 'pw' && touched.pw2) paint('pw2');   // 비밀번호를 고치면 확인 칸도 다시 비교
            if (k === 'pw2' && input.value.length >= el.pw.value.length) { touched.pw2 = true; paint('pw2'); }
        });
        input.addEventListener('blur', () => { if (input.value) { touched[k] = true; paint(k); } });
    });

    // 서버에서 돌려보낸 오류는 해당 칸에 바로 표시
    const serverErr = <?= json_encode($fieldErr) ?>;
    if (serverErr) {
        const field = document.querySelector(`.lg-field[data-f="${serverErr[0]}"]`);
        field.classList.add('bad'); field.querySelector('.lg-hint').textContent = serverErr[1];
        el[serverErr[0]].focus();
    } else (el.email.value ? el.pw : el.email).focus();

    // 비밀번호 보기/숨기기
    document.querySelectorAll('[data-eye]').forEach(b => b.addEventListener('click', () => {
        const input = $(b.dataset.eye), show = input.type === 'password';
        input.type = show ? 'text' : 'password';
        b.setAttribute('aria-label', show ? '비밀번호 숨기기' : '비밀번호 보기');
        b.style.color = show ? 'var(--primary)' : '';
    }));

    // 버튼은 항상 켜 둔다 — 크롬 자동 입력은 'input' 신호 없이 칸을 채워서, 꺼 두면 다 채워져도 안 눌리는 일이 생긴다.
    // 대신 누른 순간 모든 칸을 검사하고, 틀린 칸이 있으면 보내지 않고 그 칸으로 커서를 옮긴다.
    // 마지막 확인 + 두 번 눌러도 한 번만
    $('jn-form').addEventListener('submit', e => {
        if ($('jn-submit').disabled) { e.preventDefault(); return; }   // 이미 보내는 중
        const bad = Object.keys(rules).filter(k => { touched[k] = true; return !paint(k, true); });
        if (bad.length) { e.preventDefault(); el[bad[0]].focus(); return; }
        $('jn-submit').disabled = true; $('jn-submit').textContent = '가입하는 중…';
    });
})();
</script>

<?php include 'includes/footer.php'; ?>
