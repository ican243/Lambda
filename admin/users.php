<?php
require_once 'func.php';
/** @var mysqli $conn */   // config/db.php 에서 넘어옴 (에디터 자동완성·오탐 방지용)
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

$adminPage  = 'users';
$adminTitle = '회원 관리';

include 'includes/header.php';
?>

<div class="ad-head">
    <div>
        <h1>회원 관리</h1>
        <p><?= $keyword !== '' ? "'" . htmlspecialchars($keyword) . "' 검색 결과" : '최근 가입한 순서' ?> · <?= number_format(count($users)) ?>명</p>
    </div>
    <form method="GET" class="ad-form-row" style="min-width:min(100%,420px);">
        <input type="search" name="q" class="ad-input grow" placeholder="이메일 또는 닉네임" value="<?= htmlspecialchars($keyword) ?>">
        <button class="ad-btn dark">검색</button>
        <?php if ($keyword !== ''): ?><a href="users.php" class="ad-btn">전체</a><?php endif; ?>
    </form>
</div>

<?php if ($msg): ?><div class="ad-alert ok">✅ <span><?= htmlspecialchars($msg) ?></span></div><?php endif; ?>
<?php if ($err): ?><div class="ad-alert err">⚠️ <span><?= htmlspecialchars($err) ?></span></div><?php endif; ?>

<div class="ad-card">
    <?php if (!$users): ?>
        <div class="ad-empty"><b>🔍</b><?= $keyword !== '' ? '검색 결과가 없어요.' : '아직 회원이 없어요.' ?></div>
    <?php else: ?>
    <div class="ad-table-wrap"><table class="ad-table ad-users">
        <thead>
            <tr><th>회원</th><th class="r">예수금</th><th class="c">상태</th><th>가입일</th><th class="r">관리</th></tr>
        </thead>
        <tbody>
        <?php foreach ($users as $u): $suspended = ($u['status'] === 'suspended'); ?>
            <tr class="<?= $suspended ? 'off' : '' ?>">
                <td>
                    <a href="user_detail.php?id=<?= (int) $u['id'] ?>"><?= htmlspecialchars($u['nickname']) ?></a>
                    <div class="sm">#<?= (int) $u['id'] ?> · <?= htmlspecialchars($u['email']) ?></div>
                </td>
                <td class="r nw"><?= number_format($u['cash_balance']) ?>원</td>
                <td class="c"><span class="ad-badge <?= $suspended ? 'bad' : 'ok' ?>"><?= $suspended ? '정지' : '활성' ?></span></td>
                <td class="sm nw"><?= htmlspecialchars(substr($u['created_at'], 0, 10)) ?></td>
                <td class="r nw">
                    <!-- 보안 토큰(csrfField)은 반드시 form 안에 — 예전엔 confirm 문구 안에 잘못 들어가 있어서 버튼이 항상 실패했다 -->
                    <form method="POST" action="user_action.php" style="display:inline" data-confirm="<?= htmlspecialchars($u['nickname']) ?> 님 계정을 <?= $suspended ? '다시 활성화' : '정지' ?>할까요?">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="status">
                        <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
                        <input type="hidden" name="status" value="<?= $suspended ? 'active' : 'suspended' ?>">
                        <button class="ad-btn sm <?= $suspended ? 'ok-weak' : 'danger-weak' ?>"><?= $suspended ? '활성화' : '정지' ?></button>
                    </form>
                    <a href="user_detail.php?id=<?= (int) $u['id'] ?>" class="ad-btn sm">상세 ›</a>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php endif; ?>
</div>

<p class="ad-note">예수금 조정은 회원 <b>상세</b> 화면에서 할 수 있어요. 모든 조정은 감사 기록(admin_cash_logs)에 남아요.</p>

<script>
    // 위험한 작업은 한 번 더 묻는다 (문구는 data-confirm 에)
    document.querySelectorAll('form[data-confirm]').forEach(f => f.addEventListener('submit', e => {
        if (!confirm(f.dataset.confirm)) e.preventDefault();
    }));
</script>

<?php include 'includes/footer.php'; ?>
