<?php
require_once 'func.php';
startAdminSession();

if (!isAdminLoggedIn()) {
    header('Location: login.php');
    exit;
}
ensureAdminSchema($conn);

$msg = '';
$err = '';

// ── POST 처리 ── (RBAC: 설정/지급/점검/공지는 모두 최고관리자 전용)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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

include 'includes/header.php';
?>

<style>.num{font-variant-numeric:tabular-nums;}</style>

<div class="admin-nav d-flex align-items-center gap-2 mb-4">
    <a href="index.php" class="btn btn-outline-dark btn-sm">대시보드</a>
    <a href="users.php" class="btn btn-outline-dark btn-sm">회원 관리</a>
    <?php if (adminCan('settings')): ?><a href="settings.php" class="btn btn-dark btn-sm">설정</a><?php endif; ?>
    <?php if (adminCan('manage_admins')): ?><a href="admins.php" class="btn btn-outline-dark btn-sm">관리자</a><?php endif; ?>
    <span class="flex-grow-1"></span>
    <span class="badge bg-<?= adminRole() === 'super' ? 'dark' : 'secondary' ?>"><?= roleLabel(adminRole()) ?></span>
    <span class="text-muted"><?= htmlspecialchars($_SESSION['admin_name']) ?>님</span>
    <a href="logout.php" class="btn btn-outline-secondary btn-sm">로그아웃</a>
</div>

<?php if (!adminCan('settings')): ?>
    <div class="alert alert-warning">이 페이지는 <b>최고관리자 전용</b>입니다. (현재: <?= roleLabel(adminRole()) ?>)</div>
    <?php include 'includes/footer.php'; exit; ?>
<?php endif; ?>

<h2 class="mb-3">설정 · 예수금 관리</h2>

<?php if ($msg): ?><div class="alert alert-success py-2"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-danger py-2"><?= htmlspecialchars($err) ?></div><?php endif; ?>

<div class="row g-3">
    <!-- 초기 예수금 설정 -->
    <div class="col-md-6">
        <div class="card p-4 h-100">
            <h5>신규 가입 초기 예수금</h5>
            <p class="text-muted small">새로 가입하는 회원에게 지급되는 가상 예수금 기본값입니다.</p>
            <div class="mb-2">현재 설정: <b class="num"><?= number_format($initialCash) ?>원</b></div>
            <form method="POST" class="row g-2 align-items-center">
                <input type="hidden" name="action" value="set_initial">
                <div class="col-auto">
                    <div class="input-group">
                        <input type="number" name="initial_cash" class="form-control num" value="<?= $initialCash ?>" min="0" step="1000000" required>
                        <span class="input-group-text">원</span>
                    </div>
                </div>
                <div class="col-auto"><button class="btn btn-dark">저장</button></div>
            </form>
            <p class="text-muted small mt-2 mb-0">※ 이미 가입한 회원의 잔액에는 영향을 주지 않습니다.</p>
        </div>
    </div>

    <!-- 일괄 지급 -->
    <div class="col-md-6">
        <div class="card p-4 h-100">
            <h5>예수금 일괄 지급 (이벤트)</h5>
            <p class="text-muted small">현재 전체 회원 <b><?= number_format($userCount) ?>명</b>의 예수금에 지정 금액을 더해줍니다.</p>
            <form method="POST" onsubmit="return confirm('전체 회원에게 지급할까요? 되돌릴 수 없습니다.');">
                <input type="hidden" name="action" value="grant_all">
                <div class="input-group mb-2">
                    <input type="number" name="amount" class="form-control num" placeholder="지급 금액" min="1" step="100000" required>
                    <span class="input-group-text">원</span>
                </div>
                <input type="text" name="reason" class="form-control mb-2" placeholder="지급 사유 (예: 오픈 기념 이벤트)">
                <button class="btn btn-danger w-100">전체 회원에게 지급</button>
            </form>
        </div>
    </div>
</div>

<!-- 운영 컨트롤: 점검 모드 -->
<div class="card p-4 mt-3 <?= $maintOn ? 'border-danger' : '' ?>">
    <h5>서비스 점검 모드 (Kill Switch)</h5>
    <p class="text-muted small mb-2">켜면 유저의 <b>매수/매도가 즉시 차단</b>되고, 전 페이지 상단에 점검 배너가 표시됩니다. (열람은 가능)</p>
    <div class="mb-3">현재 상태:
        <?php if ($maintOn): ?><span class="badge bg-danger">점검 중</span><?php else: ?><span class="badge bg-success">정상 운영</span><?php endif; ?>
    </div>
    <form method="POST" class="row g-2 align-items-end">
        <input type="hidden" name="action" value="toggle_maint">
        <input type="hidden" name="mode" value="<?= $maintOn ? '0' : '1' ?>">
        <div class="col-md-8">
            <label class="form-label small text-muted">점검 안내 문구</label>
            <input type="text" name="maintenance_msg" class="form-control" value="<?= htmlspecialchars($maintMsg) ?>">
        </div>
        <div class="col-md-4">
            <button class="btn w-100 <?= $maintOn ? 'btn-success' : 'btn-danger' ?>"><?= $maintOn ? '점검 모드 끄기' : '점검 모드 켜기' ?></button>
        </div>
    </form>
</div>

<!-- 공지사항 관리 -->
<div class="card p-4 mt-3">
    <h5>공지사항 관리</h5>
    <p class="text-muted small">활성 공지는 유저 페이지 상단에 배너로 표시됩니다.</p>
    <form method="POST" class="row g-2 mb-3">
        <input type="hidden" name="action" value="notice_create">
        <div class="col-md-4"><input type="text" name="title" class="form-control" placeholder="공지 제목" required></div>
        <div class="col-md-6"><input type="text" name="body" class="form-control" placeholder="내용 (선택)"></div>
        <div class="col-md-2"><button class="btn btn-dark w-100">등록</button></div>
    </form>
    <table class="table table-sm align-middle mb-0">
        <thead class="table-light"><tr><th>제목</th><th>내용</th><th class="text-center">노출</th><th class="text-end">관리</th></tr></thead>
        <tbody>
        <?php if (!$notices): ?>
            <tr><td colspan="4" class="text-center text-muted py-3">등록된 공지가 없습니다.</td></tr>
        <?php else: foreach ($notices as $n): ?>
            <tr>
                <td><b><?= htmlspecialchars($n['title']) ?></b></td>
                <td class="small text-muted"><?= htmlspecialchars($n['body']) ?></td>
                <td class="text-center">
                    <form method="POST" class="d-inline">
                        <input type="hidden" name="action" value="notice_toggle">
                        <input type="hidden" name="id" value="<?= (int) $n['id'] ?>">
                        <input type="hidden" name="active" value="<?= $n['is_active'] ? '0' : '1' ?>">
                        <button class="btn btn-sm <?= $n['is_active'] ? 'btn-success' : 'btn-outline-secondary' ?>"><?= $n['is_active'] ? '노출 중' : '숨김' ?></button>
                    </form>
                </td>
                <td class="text-end">
                    <form method="POST" class="d-inline" onsubmit="return confirm('삭제할까요?');">
                        <input type="hidden" name="action" value="notice_delete">
                        <input type="hidden" name="id" value="<?= (int) $n['id'] ?>">
                        <button class="btn btn-sm btn-outline-danger">삭제</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>

<h5 class="mt-4">최근 일괄 지급 이력</h5>
<div class="table-responsive">
<table class="table table-sm">
    <thead class="table-light"><tr><th>일시</th><th>사유</th><th class="text-end">금액</th><th class="text-end">대상</th></tr></thead>
    <tbody>
    <?php if (!$grantLogs): ?>
        <tr><td colspan="4" class="text-center text-muted py-3">아직 일괄 지급 이력이 없습니다.</td></tr>
    <?php else: foreach ($grantLogs as $g): ?>
        <tr>
            <td class="small"><?= htmlspecialchars($g['at']) ?></td>
            <td><?= htmlspecialchars(preg_replace('/^\[일괄지급\]\s*/', '', $g['reason'])) ?: '<span class="text-muted">-</span>' ?></td>
            <td class="text-end num">+<?= number_format($g['delta']) ?>원</td>
            <td class="text-end num"><?= number_format($g['users']) ?>명</td>
        </tr>
    <?php endforeach; endif; ?>
    </tbody>
</table>
</div>

<div class="mb-5"></div>
<?php include 'includes/footer.php'; ?>
