<?php
require_once 'func.php';
startAdminSession();

if (!isAdminLoggedIn()) {
    header('Location: login.php');
    exit;
}

ensureAdminSchema($conn);

$keyword = trim($_GET['q'] ?? '');
$users = searchUsers($conn, $keyword);

// 액션 결과 플래시
$msg = $_GET['msg'] ?? '';
$err = $_GET['err'] ?? '';

include 'includes/header.php';
?>

<style>
    .num { font-variant-numeric: tabular-nums; }
    .cash-form { display:flex; gap:4px; }
    .cash-form input.amt { width:120px; }
</style>

<div class="admin-nav d-flex align-items-center gap-2 mb-4">
    <a href="index.php" class="btn btn-outline-dark btn-sm">대시보드</a>
    <a href="users.php" class="btn btn-dark btn-sm">회원 관리</a>
    <?php if (adminCan('settings')): ?><a href="settings.php" class="btn btn-outline-dark btn-sm">설정</a><?php endif; ?>
    <?php if (adminCan('manage_admins')): ?><a href="admins.php" class="btn btn-outline-dark btn-sm">관리자</a><?php endif; ?>
    <span class="flex-grow-1"></span>
    <span class="badge bg-<?= adminRole() === 'super' ? 'dark' : 'secondary' ?>"><?= roleLabel(adminRole()) ?></span>
    <span class="text-muted"><?= htmlspecialchars($_SESSION['admin_name']) ?>님</span>
    <a href="logout.php" class="btn btn-outline-secondary btn-sm">로그아웃</a>
</div>

<h2 class="mb-3">회원 관리</h2>

<?php if ($msg): ?><div class="alert alert-success py-2"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-danger py-2"><?= htmlspecialchars($err) ?></div><?php endif; ?>

<form method="GET" class="row g-2 mb-3" style="max-width:520px;">
    <div class="col">
        <input type="text" name="q" class="form-control" placeholder="이메일 또는 닉네임 검색" value="<?= htmlspecialchars($keyword) ?>">
    </div>
    <div class="col-auto"><button class="btn btn-dark">검색</button></div>
    <?php if ($keyword !== ''): ?><div class="col-auto"><a href="users.php" class="btn btn-outline-secondary">전체</a></div><?php endif; ?>
</form>

<p class="text-muted small"><?= $keyword !== '' ? "'".htmlspecialchars($keyword)."' 검색 결과" : '최근 가입 순' ?> · <?= count($users) ?>명</p>

<div class="table-responsive">
<table class="table table-hover align-middle">
    <thead class="table-light">
        <tr>
            <th>ID</th><th>닉네임</th><th>이메일</th>
            <th class="text-end">예수금</th><th class="text-center">상태</th><th>가입일</th>
            <th>계정</th><th>예수금 조정</th>
        </tr>
    </thead>
    <tbody>
    <?php if (!$users): ?>
        <tr><td colspan="8" class="text-center text-muted py-4">회원이 없습니다.</td></tr>
    <?php else: foreach ($users as $u): $suspended = ($u['status'] === 'suspended'); ?>
        <tr class="<?= $suspended ? 'table-secondary' : '' ?>">
            <td class="num"><?= (int) $u['id'] ?></td>
            <td><a href="user_detail.php?id=<?= (int) $u['id'] ?>" class="fw-semibold text-decoration-none"><?= htmlspecialchars($u['nickname']) ?></a></td>
            <td class="small"><?= htmlspecialchars($u['email']) ?></td>
            <td class="text-end num"><?= number_format($u['cash_balance']) ?>원</td>
            <td class="text-center">
                <?php if ($suspended): ?><span class="badge bg-danger">정지</span><?php else: ?><span class="badge bg-success">활성</span><?php endif; ?>
            </td>
            <td class="small"><?= htmlspecialchars(substr($u['created_at'], 0, 10)) ?></td>
            <td>
                <form method="POST" action="user_action.php" onsubmit="return confirm('<?= $suspended ? '활성화' : '정지' ?> 처리할까요?');">
                    <input type="hidden" name="action" value="status">
                    <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
                    <input type="hidden" name="status" value="<?= $suspended ? 'active' : 'suspended' ?>">
                    <button class="btn btn-sm <?= $suspended ? 'btn-outline-success' : 'btn-outline-danger' ?>"><?= $suspended ? '활성화' : '정지' ?></button>
                </form>
            </td>
            <td>
                <?php if (adminCan('adjust_cash')): ?>
                <form method="POST" action="user_action.php" class="cash-form">
                    <input type="hidden" name="action" value="cash">
                    <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
                    <input type="number" name="delta" class="form-control form-control-sm amt" placeholder="+/- 금액" required>
                    <input type="text" name="reason" class="form-control form-control-sm" placeholder="사유(선택)">
                    <button class="btn btn-sm btn-outline-primary">적용</button>
                </form>
                <?php else: ?><span class="text-muted small">최고관리자 전용</span><?php endif; ?>
            </td>
        </tr>
    <?php endforeach; endif; ?>
    </tbody>
</table>
</div>

<p class="text-muted small mt-3">※ 예수금 조정은 감사로그(admin_cash_logs)에 기록됩니다. 금액에 음수(예: -50000)를 넣으면 차감됩니다.</p>

<div class="mb-5"></div>
<?php include 'includes/footer.php'; ?>
