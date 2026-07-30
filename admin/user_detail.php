<?php
require_once 'func.php';
/** @var mysqli $conn */   // config/db.php 에서 넘어옴 (에디터 자동완성·오탐 방지용)
startAdminSession();

if (!isAdminLoggedIn()) {
    header('Location: login.php');
    exit;
}
ensureAdminSchema($conn);

$userId = (int) ($_GET['id'] ?? 0);
$u = $userId ? getUserProfile($conn, $userId) : null;
if (!$u) {
    header('Location: users.php?err=' . urlencode('존재하지 않는 회원입니다.'));
    exit;
}

$holdings   = getUserHoldingsAdmin($conn, $userId);
$orders     = getUserOrdersAdmin($conn, $userId);
$cashLogs   = getUserCashLogs($conn, $userId);
$accessLogs = getUserAccessLogs($conn, $userId);

// 총자산 / 수익률
$evalSum = 0;
foreach ($holdings as $h) $evalSum += $h['quantity'] * $h['price'];
$totalAsset = $u['cash_balance'] + $evalSum;
$initial = getInitialCash($conn);
$rate = $initial > 0 ? round(($totalAsset - $initial) / $initial * 100, 2) : 0;
$suspended = ($u['status'] === 'suspended');
$back = 'user_detail.php?id=' . $userId;

include 'includes/header.php';
?>

<style>.num{font-variant-numeric:tabular-nums;} .up{color:#f04452;} .down{color:#3182f6;}</style>

<div class="admin-nav d-flex align-items-center gap-2 mb-4">
    <a href="index.php" class="btn btn-outline-dark btn-sm">대시보드</a>
    <a href="users.php" class="btn btn-outline-dark btn-sm">회원 관리</a>
    <?php if (adminCan('settings')): ?><a href="settings.php" class="btn btn-outline-dark btn-sm">설정</a><?php endif; ?>
    <?php if (adminCan('manage_admins')): ?><a href="admins.php" class="btn btn-outline-dark btn-sm">관리자</a><?php endif; ?>
    <span class="flex-grow-1"></span>
    <span class="badge bg-<?= adminRole() === 'super' ? 'dark' : 'secondary' ?>"><?= roleLabel(adminRole()) ?></span>
    <a href="logout.php" class="btn btn-outline-secondary btn-sm">로그아웃</a>
</div>

<a href="users.php" class="text-muted text-decoration-none small">← 회원 목록</a>

<div class="d-flex align-items-center gap-3 mt-2 mb-1">
    <h2 class="mb-0"><?= htmlspecialchars($u['nickname']) ?></h2>
    <?php if ($suspended): ?><span class="badge bg-danger fs-6">정지</span><?php else: ?><span class="badge bg-success fs-6">활성</span><?php endif; ?>
</div>
<p class="text-muted"><?= htmlspecialchars($u['email']) ?> · #<?= (int) $u['id'] ?> · 가입 <?= htmlspecialchars(substr($u['created_at'], 0, 10)) ?></p>

<!-- 요약 카드 -->
<div class="row g-3">
    <div class="col-md-3"><div class="card text-center p-3"><h6 class="text-muted">예수금</h6><h4 class="num"><?= number_format($u['cash_balance']) ?>원</h4></div></div>
    <div class="col-md-3"><div class="card text-center p-3"><h6 class="text-muted">주식 평가금</h6><h4 class="num"><?= number_format($evalSum) ?>원</h4></div></div>
    <div class="col-md-3"><div class="card text-center p-3"><h6 class="text-muted">총자산</h6><h4 class="num"><?= number_format($totalAsset) ?>원</h4></div></div>
    <div class="col-md-3"><div class="card text-center p-3"><h6 class="text-muted">수익률</h6><h4 class="num <?= $rate > 0 ? 'up' : ($rate < 0 ? 'down' : '') ?>"><?= $rate > 0 ? '+' : '' ?><?= $rate ?>%</h4></div></div>
</div>

<!-- 관리 액션 -->
<div class="card p-3 mt-3">
    <div class="row g-3 align-items-center">
        <div class="col-auto">
            <form method="POST" action="user_action.php" onsubmit="return confirm('<?= $suspended ? '활성화' : '정지' ?><?= csrfField() ?> 처리할까요?');">
                <input type="hidden" name="action" value="status">
                <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
                <input type="hidden" name="status" value="<?= $suspended ? 'active' : 'suspended' ?>">
                <input type="hidden" name="back" value="<?= htmlspecialchars($back) ?>">
                <button class="btn btn-sm <?= $suspended ? 'btn-success' : 'btn-danger' ?>"><?= $suspended ? '계정 활성화' : '계정 정지' ?></button>
            </form>
        </div>
        <div class="col">
            <?php if (adminCan('adjust_cash')): ?>
            <form method="POST" action="user_action.php" class="d-flex gap-2" style="max-width:460px;"><?= csrfField() ?>
                <input type="hidden" name="action" value="cash">
                <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
                <input type="hidden" name="back" value="<?= htmlspecialchars($back) ?>">
                <input type="number" name="delta" class="form-control form-control-sm" placeholder="예수금 +/- 조정" required>
                <input type="text" name="reason" class="form-control form-control-sm" placeholder="사유(선택)">
                <button class="btn btn-sm btn-outline-primary">적용</button>
            </form>
            <?php else: ?><span class="text-muted small">예수금 조정은 최고관리자 전용입니다.</span><?php endif; ?>
        </div>
    </div>
</div>

<div class="row g-3 mt-1">
    <!-- 보유 종목 -->
    <div class="col-md-6">
        <div class="card p-3 h-100">
            <h6 class="mb-3">보유 종목 (<?= count($holdings) ?>)</h6>
            <?php if (!$holdings): ?><p class="text-muted mb-0">보유 종목 없음</p><?php else: ?>
            <div class="table-responsive"><table class="table table-sm mb-0">
                <thead><tr><th>종목</th><th class="text-end">수량</th><th class="text-end">평단</th><th class="text-end">현재가</th><th class="text-end">평가손익</th></tr></thead>
                <tbody>
                <?php foreach ($holdings as $h): $pl = ($h['price'] - $h['avg_price']) * $h['quantity']; ?>
                    <tr>
                        <td><?= htmlspecialchars($h['stock_name']) ?></td>
                        <td class="text-end num"><?= number_format($h['quantity']) ?></td>
                        <td class="text-end num"><?= number_format($h['avg_price']) ?></td>
                        <td class="text-end num"><?= number_format($h['price']) ?></td>
                        <td class="text-end num <?= $pl > 0 ? 'up' : ($pl < 0 ? 'down' : '') ?>"><?= $pl > 0 ? '+' : '' ?><?= number_format($pl) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
            <?php endif; ?>
        </div>
    </div>

    <!-- 접속 기록 (IP) -->
    <div class="col-md-6">
        <div class="card p-3 h-100">
            <h6 class="mb-3">접속 기록 (IP)</h6>
            <?php if (!$accessLogs): ?><p class="text-muted mb-0">기록 없음 (로그인/거래 시 쌓입니다)</p><?php else: ?>
            <div class="table-responsive" style="max-height:240px;overflow:auto;"><table class="table table-sm mb-0">
                <thead><tr><th>일시</th><th>IP</th><th>구분</th></tr></thead>
                <tbody>
                <?php foreach ($accessLogs as $a): ?>
                    <tr><td class="small"><?= htmlspecialchars(substr($a['created_at'], 5, 14)) ?></td><td class="num"><?= htmlspecialchars($a['ip']) ?></td><td><span class="badge bg-<?= $a['action'] === 'trade' ? 'warning text-dark' : 'secondary' ?>"><?= $a['action'] === 'trade' ? '거래' : '로그인' ?></span></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="row g-3 mt-1">
    <!-- 거래 히스토리 -->
    <div class="col-md-7">
        <div class="card p-3 h-100">
            <h6 class="mb-3">거래 히스토리 (최근 <?= count($orders) ?>건)</h6>
            <?php if (!$orders): ?><p class="text-muted mb-0">거래 없음</p><?php else: ?>
            <div class="table-responsive" style="max-height:320px;overflow:auto;"><table class="table table-sm mb-0">
                <thead><tr><th>일시</th><th>종목</th><th>구분</th><th class="text-end">수량</th><th class="text-end">단가</th><th class="text-end">총액</th></tr></thead>
                <tbody>
                <?php foreach ($orders as $o): ?>
                    <tr>
                        <td class="small"><?= htmlspecialchars(substr($o['created_at'], 5, 14)) ?></td>
                        <td><?= htmlspecialchars($o['stock_name']) ?></td>
                        <td><?= $o['order_type'] === 'buy' ? '<span class="up">매수</span>' : '<span class="down">매도</span>' ?></td>
                        <td class="text-end num"><?= number_format($o['quantity']) ?></td>
                        <td class="text-end num"><?= number_format($o['price']) ?></td>
                        <td class="text-end num"><?= number_format($o['total_amount']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
            <?php endif; ?>
        </div>
    </div>

    <!-- 예수금 조정 이력 -->
    <div class="col-md-5">
        <div class="card p-3 h-100">
            <h6 class="mb-3">예수금 조정 이력</h6>
            <?php if (!$cashLogs): ?><p class="text-muted mb-0">조정 이력 없음</p><?php else: ?>
            <div class="table-responsive" style="max-height:320px;overflow:auto;"><table class="table table-sm mb-0">
                <thead><tr><th>일시</th><th class="text-end">변동</th><th class="text-end">잔액</th><th>사유</th></tr></thead>
                <tbody>
                <?php foreach ($cashLogs as $c): ?>
                    <tr>
                        <td class="small"><?= htmlspecialchars(substr($c['created_at'], 5, 11)) ?></td>
                        <td class="text-end num <?= $c['delta'] > 0 ? 'up' : 'down' ?>"><?= $c['delta'] > 0 ? '+' : '' ?><?= number_format($c['delta']) ?></td>
                        <td class="text-end num"><?= number_format($c['balance_after']) ?></td>
                        <td class="small"><?= htmlspecialchars($c['reason']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="mb-5"></div>
<?php include 'includes/footer.php'; ?>
