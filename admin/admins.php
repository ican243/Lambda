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

// 최고관리자 수 (마지막 super 강등/삭제 방지용)
function countSupers($conn) {
    $r = mysqli_query($conn, "SELECT COUNT(*) c FROM admins WHERE COALESCE(role,'super')='super'");
    return (int) mysqli_fetch_assoc($r)['c'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf('admins.php', 'redirect', null, 'err');   // CSRF 검증
    requireAdminCan('manage_admins', 'admins.php');   // 서버측 강제 (super 전용)
    $act = $_POST['action'] ?? '';
    $myId = (int) ($_SESSION['admin_id'] ?? 0);
    try {
        if ($act === 'add') {
            createAdmin($conn, $_POST['admin_id'] ?? '', $_POST['password'] ?? '', $_POST['name'] ?? '', $_POST['role'] ?? 'staff');
            $msg = '관리자를 추가했습니다.';
        } elseif ($act === 'role') {
            $id = (int) $_POST['id'];
            $role = $_POST['role'] ?? 'staff';
            if ($role !== 'super' && countSupers($conn) <= 1) {
                // 강등 대상이 마지막 super인지 확인
                $r = mysqli_query($conn, "SELECT COALESCE(role,'super') role FROM admins WHERE id=" . $id);
                $cur = mysqli_fetch_assoc($r);
                if ($cur && $cur['role'] === 'super') throw new Exception('최소 1명의 최고관리자는 있어야 합니다.');
            }
            setAdminRole($conn, $id, $role);
            $msg = '역할을 변경했습니다.';
        } elseif ($act === 'delete') {
            $id = (int) $_POST['id'];
            if ($id === $myId) throw new Exception('본인 계정은 삭제할 수 없습니다.');
            $r = mysqli_query($conn, "SELECT COALESCE(role,'super') role FROM admins WHERE id=" . $id);
            $cur = mysqli_fetch_assoc($r);
            if ($cur && $cur['role'] === 'super' && countSupers($conn) <= 1) throw new Exception('마지막 최고관리자는 삭제할 수 없습니다.');
            deleteAdmin($conn, $id);
            $msg = '관리자를 삭제했습니다.';
        }
    } catch (Exception $e) {
        $err = $e->getMessage();
    }
    if ($msg || $err) {
        $q = $msg ? 'msg=' . urlencode($msg) : 'err=' . urlencode($err);
        header('Location: admins.php?' . $q);
        exit;
    }
}

$admins = getAllAdmins($conn);
$myId = (int) ($_SESSION['admin_id'] ?? 0);

$adminPage  = 'admins';
$adminTitle = '관리자 계정';

include 'includes/header.php';
?>

<div class="ad-head">
    <div>
        <h1>관리자 계정</h1>
        <p>누가 관리자 화면에 들어올 수 있고, 무엇을 할 수 있는지 정해요.</p>
    </div>
</div>

<?php if (!adminCan('manage_admins')): ?>
    <div class="ad-alert warn">🔒 <span>이 화면은 <b>최고관리자</b>만 쓸 수 있어요. (지금: <?= roleLabel(adminRole()) ?>)</span></div>
    <?php include 'includes/footer.php'; exit; ?>
<?php endif; ?>

<?php if ($msg): ?><div class="ad-alert ok">✅ <span><?= htmlspecialchars($msg) ?></span></div><?php endif; ?>
<?php if ($err): ?><div class="ad-alert err">⚠️ <span><?= htmlspecialchars($err) ?></span></div><?php endif; ?>

<div class="ad-grid c2">
    <div class="ad-card">
        <div class="ad-card-t" style="margin-bottom:var(--sp-2);"><span class="ad-badge dark">최고관리자</span></div>
        <div class="ad-card-d" style="margin:0;">모든 권한 — 예수금 조정·일괄 지급, 운영 설정, 관리자 계정 관리</div>
    </div>
    <div class="ad-card">
        <div class="ad-card-t" style="margin-bottom:var(--sp-2);"><span class="ad-badge">CS 담당자</span></div>
        <div class="ad-card-d" style="margin:0;">조회 + 회원 계정 정지만 — 돈과 설정은 건드릴 수 없어요</div>
    </div>
</div>

<div class="ad-card" style="margin-top:var(--sp-4);">
    <div class="ad-card-h"><span class="ad-card-t">관리자 목록 <small><?= count($admins) ?>명</small></span></div>
    <div class="ad-table-wrap"><table class="ad-table">
        <thead><tr><th>관리자</th><th class="c">역할</th><th class="r">관리</th></tr></thead>
        <tbody>
        <?php foreach ($admins as $a): $isSuper = ($a['role'] === 'super'); $isMe = ((int) $a['id'] === $myId); ?>
            <tr>
                <td><b><?= htmlspecialchars($a['name']) ?></b> <?php if ($isMe): ?><span class="ad-badge blue">나</span><?php endif; ?>
                    <div class="sm"><?= htmlspecialchars($a['admin_id']) ?> · #<?= (int) $a['id'] ?></div></td>
                <td class="c"><span class="ad-badge <?= $isSuper ? 'dark' : '' ?>"><?= roleLabel($a['role']) ?></span></td>
                <td class="r nw">
                    <form method="POST" style="display:inline" data-confirm="<?= htmlspecialchars($a['name']) ?> 님을 <?= $isSuper ? 'CS 담당자로 바꿀까요?' : '최고관리자로 바꿀까요?' ?>"><?= csrfField() ?>
                        <input type="hidden" name="action" value="role">
                        <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                        <input type="hidden" name="role" value="<?= $isSuper ? 'staff' : 'super' ?>">
                        <button class="ad-btn sm"><?= $isSuper ? 'CS로 바꾸기' : '최고관리자로' ?></button>
                    </form>
                    <?php if (!$isMe): ?>
                    <form method="POST" style="display:inline" data-confirm="<?= htmlspecialchars($a['name']) ?> 님의 관리자 계정을 삭제할까요? 되돌릴 수 없어요."><?= csrfField() ?>
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                        <button class="ad-btn sm danger-weak">삭제</button>
                    </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div>

<div class="ad-card" style="max-width:720px;">
    <div class="ad-card-h"><span class="ad-card-t">관리자 추가</span></div>
    <form method="POST"><?= csrfField() ?>
        <input type="hidden" name="action" value="add">
        <div class="ad-grid c2" style="gap:var(--sp-3);">
            <div><label class="ad-label">로그인 아이디</label><input type="text" name="admin_id" class="ad-input" style="width:100%;" autocomplete="off" required></div>
            <div><label class="ad-label">이름</label><input type="text" name="name" class="ad-input" style="width:100%;" required></div>
            <div><label class="ad-label">비밀번호</label><input type="password" name="password" class="ad-input" style="width:100%;" autocomplete="new-password" required></div>
            <div><label class="ad-label">역할</label>
                <select name="role" class="ad-select" style="width:100%;">
                    <option value="staff">CS 담당자</option>
                    <option value="super">최고관리자</option>
                </select>
            </div>
        </div>
        <button class="ad-btn dark" style="margin-top:var(--sp-4);">관리자 추가</button>
    </form>
</div>

<script>
    document.querySelectorAll('form[data-confirm]').forEach(f => f.addEventListener('submit', e => {
        if (!confirm(f.dataset.confirm)) e.preventDefault();
    }));
</script>

<?php include 'includes/footer.php'; ?>
