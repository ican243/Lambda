<?php
require_once 'func.php';
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

include 'includes/header.php';
?>

<div class="admin-nav d-flex align-items-center gap-2 mb-4">
    <a href="index.php" class="btn btn-outline-dark btn-sm">대시보드</a>
    <a href="users.php" class="btn btn-outline-dark btn-sm">회원 관리</a>
    <?php if (adminCan('settings')): ?><a href="settings.php" class="btn btn-outline-dark btn-sm">설정</a><?php endif; ?>
    <?php if (adminCan('manage_admins')): ?><a href="admins.php" class="btn btn-dark btn-sm">관리자</a><?php endif; ?>
    <span class="flex-grow-1"></span>
    <span class="badge bg-<?= adminRole() === 'super' ? 'dark' : 'secondary' ?>"><?= roleLabel(adminRole()) ?></span>
    <a href="logout.php" class="btn btn-outline-secondary btn-sm">로그아웃</a>
</div>

<h2 class="mb-3">관리자 · 권한 관리</h2>

<?php if (!adminCan('manage_admins')): ?>
    <div class="alert alert-warning">이 페이지는 <b>최고관리자 전용</b>입니다. (현재: <?= roleLabel(adminRole()) ?>)</div>
    <?php include 'includes/footer.php'; exit; ?>
<?php endif; ?>

<?php if ($msg): ?><div class="alert alert-success py-2"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-danger py-2"><?= htmlspecialchars($err) ?></div><?php endif; ?>

<div class="alert alert-light border small">
    <b>역할 안내</b> — <span class="badge bg-dark">최고관리자</span> 전권(예수금·설정·관리자 관리) ·
    <span class="badge bg-secondary">CS 담당자</span> 조회 + 계정 정지만 (돈·설정 불가)
</div>

<table class="table align-middle">
    <thead class="table-light"><tr><th>ID</th><th>아이디</th><th>이름</th><th>역할</th><th class="text-end">관리</th></tr></thead>
    <tbody>
    <?php foreach ($admins as $a): $isSuper = ($a['role'] === 'super'); $isMe = ((int) $a['id'] === $myId); ?>
        <tr>
            <td><?= (int) $a['id'] ?></td>
            <td><?= htmlspecialchars($a['admin_id']) ?> <?php if ($isMe): ?><span class="badge bg-info text-dark">나</span><?php endif; ?></td>
            <td><?= htmlspecialchars($a['name']) ?></td>
            <td><span class="badge bg-<?= $isSuper ? 'dark' : 'secondary' ?>"><?= roleLabel($a['role']) ?></span></td>
            <td class="text-end">
                <form method="POST" class="d-inline">
                    <input type="hidden" name="action" value="role">
                    <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                    <input type="hidden" name="role" value="<?= $isSuper ? 'staff' : 'super' ?>">
                    <button class="btn btn-sm btn-outline-dark"><?= $isSuper ? 'CS로 강등' : '최고관리자로' ?></button>
                </form>
                <?php if (!$isMe): ?>
                <form method="POST" class="d-inline" onsubmit="return confirm('삭제할까요?');">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                    <button class="btn btn-sm btn-outline-danger">삭제</button>
                </form>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<div class="card p-4 mt-3" style="max-width:640px;">
    <h5>관리자 추가</h5>
    <form method="POST" class="row g-2">
        <input type="hidden" name="action" value="add">
        <div class="col-md-6"><input type="text" name="admin_id" class="form-control" placeholder="로그인 아이디" required></div>
        <div class="col-md-6"><input type="text" name="name" class="form-control" placeholder="이름" required></div>
        <div class="col-md-6"><input type="password" name="password" class="form-control" placeholder="비밀번호" required></div>
        <div class="col-md-4">
            <select name="role" class="form-select">
                <option value="staff">CS 담당자</option>
                <option value="super">최고관리자</option>
            </select>
        </div>
        <div class="col-md-2"><button class="btn btn-dark w-100">추가</button></div>
    </form>
</div>

<div class="mb-5"></div>
<?php include 'includes/footer.php'; ?>
