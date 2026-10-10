<?php
require_once 'func.php';
/** @var mysqli $conn */   // config/db.php 에서 넘어옴 (에디터 자동완성·오탐 방지용)
startAdminSession();

if (!isAdminLoggedIn()) {
    header('Location: login.php');
    exit;
}
ensureAdminSchema($conn);

$msg = $_GET['msg'] ?? '';
$err = $_GET['err'] ?? '';

// ── POST 처리 ── (RBAC: 설정/지급/점검/공지는 모두 최고관리자 전용)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf('settings.php', 'redirect', null, 'err');   // CSRF 검증
    requireAdminCan('settings', 'settings.php');   // 서버측 강제
    $act = $_POST['action'] ?? '';
    try {
        if ($act === 'set_initial') {
            $v = (int) ($_POST['initial_cash'] ?? 0);
            if ($v < 0) throw new Exception('0원 이상으로 입력하세요.');
            setSetting($conn, 'initial_cash', (string) $v);
            $msg = '신규 가입 초기 예수금을 ' . number_format($v) . '원으로 설정했습니다.';
        } elseif ($act === 'grant_all') {
            $amount = (int) ($_POST['amount'] ?? 0);
            $reason = trim($_POST['reason'] ?? '');
            $cnt = grantCashToAll($conn, $_SESSION['admin_id'] ?? 0, $amount, $reason);
            $msg = '전체 ' . number_format($cnt) . '명에게 ' . number_format($amount) . '원씩 일괄 지급했습니다.';
        } elseif ($act === 'toggle_maint') {
            $on = ($_POST['mode'] ?? '0') === '1';
            setSetting($conn, 'maintenance_mode', $on ? '1' : '0');
            if (isset($_POST['maintenance_msg'])) setSetting($conn, 'maintenance_msg', trim($_POST['maintenance_msg']));
            $msg = $on ? '점검 모드를 켰습니다. (유저 거래가 차단됩니다)' : '점검 모드를 껐습니다.';
        } elseif ($act === 'notice_create') {
            createNotice($conn, $_POST['title'] ?? '', $_POST['body'] ?? '');
            $msg = '공지를 등록했습니다.';
        } elseif ($act === 'notice_toggle') {
            setNoticeActive($conn, (int) $_POST['id'], ($_POST['active'] ?? '0') === '1');
            $msg = '공지 노출 상태를 변경했습니다.';
        } elseif ($act === 'notice_delete') {
            deleteNotice($conn, (int) $_POST['id']);
            $msg = '공지를 삭제했습니다.';
        }
    } catch (Exception $e) {
        $err = $e->getMessage();
    }
    // 처리 후 반드시 다른 주소로 넘긴다(PRG). 안 그러면 새로고침 한 번에 '전체 회원 일괄 지급'이 또 실행될 수 있다.
    $q = $msg ? 'msg=' . urlencode($msg) : 'err=' . urlencode($err ?: '알 수 없는 작업입니다.');
    header('Location: settings.php?' . $q);
    exit;
}

$initialCash = getInitialCash($conn);
$userCount   = getTotalUserCount($conn);
$maintOn     = isMaintenance($conn);
$maintMsg    = maintenanceMsg($conn);
$notices     = getAllNotices($conn);

// 최근 일괄지급 이력 (요약)
$grantLogs = [];
$res = mysqli_query($conn, "
    SELECT reason, delta, COUNT(*) AS users, MAX(created_at) AS at
    FROM admin_cash_logs
    WHERE reason LIKE '[일괄지급]%'
    GROUP BY reason, delta, DATE_FORMAT(created_at, '%Y-%m-%d %H:%i')
    ORDER BY at DESC LIMIT 10
");
if ($res) while ($row = mysqli_fetch_assoc($res)) $grantLogs[] = $row;

$adminPage  = 'settings';
$adminTitle = '운영 설정';

include 'includes/header.php';
?>

<div class="ad-head">
    <div>
        <h1>운영 설정</h1>
        <p>예수금, 점검 모드, 공지처럼 서비스 전체에 영향을 주는 설정이에요.</p>
    </div>
</div>

<?php if (!adminCan('settings')): ?>
    <div class="ad-alert warn">🔒 <span>이 화면은 <b>최고관리자</b>만 쓸 수 있어요. (지금: <?= roleLabel(adminRole()) ?>)</span></div>
    <?php include 'includes/footer.php'; exit; ?>
<?php endif; ?>

<?php if ($msg): ?><div class="ad-alert ok">✅ <span><?= htmlspecialchars($msg) ?></span></div><?php endif; ?>
<?php if ($err): ?><div class="ad-alert err">⚠️ <span><?= htmlspecialchars($err) ?></span></div><?php endif; ?>

<!-- 점검 모드 (가장 위험 → 맨 위) -->
<div class="ad-card <?= $maintOn ? 'danger' : '' ?>">
    <div class="ad-card-h">
        <span class="ad-card-t">서비스 점검 모드</span>
        <span class="ad-badge <?= $maintOn ? 'bad' : 'ok' ?>"><?= $maintOn ? '● 점검 중 — 거래 차단됨' : '● 정상 운영' ?></span>
    </div>
    <p class="ad-card-d">켜면 회원들의 <b>구매·판매가 즉시 막히고</b>, 모든 화면 위에 아래 안내 문구가 떠요. 시세 보기는 그대로 돼요.</p>
    <form method="POST" class="ad-form-row" data-confirm="<?= $maintOn ? '점검 모드를 끄고 거래를 다시 열까요?' : '점검 모드를 켤까요? 회원들의 거래가 즉시 막혀요.' ?>"><?= csrfField() ?>
        <input type="hidden" name="action" value="toggle_maint">
        <input type="hidden" name="mode" value="<?= $maintOn ? '0' : '1' ?>">
        <input type="text" name="maintenance_msg" class="ad-input grow" value="<?= htmlspecialchars($maintMsg) ?>" placeholder="점검 안내 문구" maxlength="200">
        <button class="ad-btn <?= $maintOn ? 'primary' : 'danger' ?>"><?= $maintOn ? '점검 끝내기' : '점검 시작하기' ?></button>
    </form>
</div>

<div class="ad-grid c2">
    <!-- 초기 예수금 -->
    <div class="ad-card">
        <div class="ad-card-h"><span class="ad-card-t">신규 가입 시작 금액</span></div>
        <p class="ad-card-d">새로 가입하는 회원에게 주는 모의자금이에요. 이미 가입한 회원의 잔액은 바뀌지 않아요.</p>
        <div class="ad-kpi" style="margin-bottom:var(--sp-4);"><div class="k">지금 설정</div><div class="v"><?= number_format($initialCash) ?>원</div></div>
        <form method="POST" class="ad-form-row"><?= csrfField() ?>
            <input type="hidden" name="action" value="set_initial">
            <div class="ad-unit grow"><input type="number" name="initial_cash" class="ad-input" value="<?= $initialCash ?>" min="0" step="1000000" required><span>원</span></div>
            <button class="ad-btn dark">저장</button>
        </form>
    </div>

    <!-- 일괄 지급 -->
    <div class="ad-card">
        <div class="ad-card-h"><span class="ad-card-t">전체 회원 일괄 지급 <small>이벤트</small></span></div>
        <p class="ad-card-d">회원 <b><?= number_format($userCount) ?>명</b> 모두의 예수금에 같은 금액을 더해요. <b>되돌릴 수 없어요.</b></p>
        <form method="POST" id="grant-form"><?= csrfField() ?>
            <input type="hidden" name="action" value="grant_all">
            <div class="ad-form-row">
                <div class="ad-unit grow"><input type="number" name="amount" class="ad-input" placeholder="1명당 지급 금액" min="1" step="100000" required><span>원</span></div>
            </div>
            <div class="ad-form-row" style="margin-top:var(--sp-2);">
                <input type="text" name="reason" class="ad-input grow" placeholder="지급 사유 (예: 오픈 기념 이벤트)" maxlength="100">
                <button class="ad-btn danger">지급하기</button>
            </div>
        </form>
    </div>
</div>

<!-- 공지 -->
<div class="ad-card" style="margin-top:var(--sp-4);">
    <div class="ad-card-h"><span class="ad-card-t">공지사항 <small>노출 중인 공지는 모든 사용자 화면 위에 떠요</small></span></div>
    <form method="POST" class="ad-form-row" style="margin-bottom:var(--sp-4);"><?= csrfField() ?>
        <input type="hidden" name="action" value="notice_create">
        <input type="text" name="title" class="ad-input" style="flex:1 1 200px;" placeholder="공지 제목" maxlength="100" required>
        <input type="text" name="body" class="ad-input" style="flex:2 1 260px;" placeholder="내용 (선택)" maxlength="300">
        <button class="ad-btn dark">등록</button>
    </form>
    <?php if (!$notices): ?>
        <div class="ad-empty"><b>📢</b>등록된 공지가 없어요.</div>
    <?php else: ?>
    <div class="ad-table-wrap"><table class="ad-table">
        <thead><tr><th>공지</th><th class="c">노출</th><th class="r">관리</th></tr></thead>
        <tbody>
        <?php foreach ($notices as $n): ?>
            <tr class="<?= $n['is_active'] ? '' : 'off' ?>">
                <td><b><?= htmlspecialchars($n['title']) ?></b><?php if (trim($n['body'] ?? '') !== ''): ?><div class="sm"><?= htmlspecialchars($n['body']) ?></div><?php endif; ?></td>
                <td class="c">
                    <form method="POST" style="display:inline"><?= csrfField() ?>
                        <input type="hidden" name="action" value="notice_toggle">
                        <input type="hidden" name="id" value="<?= (int) $n['id'] ?>">
                        <input type="hidden" name="active" value="<?= $n['is_active'] ? '0' : '1' ?>">
                        <button class="ad-btn sm <?= $n['is_active'] ? 'ok-weak' : '' ?>" title="누르면 <?= $n['is_active'] ? '숨겨요' : '보여요' ?>"><?= $n['is_active'] ? '● 노출 중' : '숨김' ?></button>
                    </form>
                </td>
                <td class="r">
                    <form method="POST" style="display:inline" data-confirm="'<?= htmlspecialchars($n['title']) ?>' 공지를 삭제할까요?"><?= csrfField() ?>
                        <input type="hidden" name="action" value="notice_delete">
                        <input type="hidden" name="id" value="<?= (int) $n['id'] ?>">
                        <button class="ad-btn sm danger-weak">삭제</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php endif; ?>
</div>

<!-- 일괄 지급 이력 -->
<div class="ad-card">
    <div class="ad-card-h"><span class="ad-card-t">최근 일괄 지급 이력</span></div>
    <?php if (!$grantLogs): ?>
        <div class="ad-empty"><b>📭</b>아직 일괄 지급 이력이 없어요.</div>
    <?php else: ?>
    <div class="ad-table-wrap"><table class="ad-table">
        <thead><tr><th>일시</th><th>사유</th><th class="r">1명당</th><th class="r">대상</th></tr></thead>
        <tbody>
        <?php foreach ($grantLogs as $g): $why = preg_replace('/^\[일괄지급\]\s*/', '', $g['reason']); ?>
            <tr>
                <td class="sm nw"><?= htmlspecialchars(substr($g['at'], 0, 16)) ?></td>
                <td><?= $why !== '' ? htmlspecialchars($why) : '<span class="muted">—</span>' ?></td>
                <td class="r nw up"><b>+<?= number_format($g['delta']) ?>원</b></td>
                <td class="r nw"><?= number_format($g['users']) ?>명</td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php endif; ?>
</div>

<script>
    document.querySelectorAll('form[data-confirm]').forEach(f => f.addEventListener('submit', e => {
        if (!confirm(f.dataset.confirm)) e.preventDefault();
    }));
    // 일괄 지급: 총액까지 계산해서 한 번 더 확인 + 두 번 눌러도 한 번만
    document.getElementById('grant-form').addEventListener('submit', e => {
        const amt = parseInt(e.target.amount.value, 10) || 0, users = <?= (int) $userCount ?>;
        if (amt <= 0) { e.preventDefault(); alert('지급 금액을 입력해 주세요.'); return; }
        const msg = `회원 ${users.toLocaleString('ko-KR')}명에게 ${amt.toLocaleString('ko-KR')}원씩, 총 ${(amt * users).toLocaleString('ko-KR')}원을 지급할까요?\n되돌릴 수 없어요.`;
        if (!confirm(msg)) { e.preventDefault(); return; }
        const b = e.target.querySelector('button'); b.disabled = true; b.textContent = '지급하는 중…';
    });
</script>

<?php include 'includes/footer.php'; ?>
