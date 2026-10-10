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
$msg = $_GET['msg'] ?? '';   // user_action.php 처리 결과
$err = $_GET['err'] ?? '';

$adminPage  = 'users';
$adminTitle = $u['nickname'] . ' · 회원 상세';

include 'includes/header.php';
?>

<a href="users.php" class="ad-back">← 회원 목록</a>
<div class="ad-head">
    <div>
        <h1><?= htmlspecialchars($u['nickname']) ?> <span class="ad-badge <?= $suspended ? 'bad' : 'ok' ?>" style="vertical-align:4px;"><?= $suspended ? '정지' : '활성' ?></span></h1>
        <p><?= htmlspecialchars($u['email']) ?> · #<?= (int) $u['id'] ?> · <?= htmlspecialchars(substr($u['created_at'], 0, 10)) ?> 가입</p>
    </div>
    <form method="POST" action="user_action.php" data-confirm="<?= htmlspecialchars($u['nickname']) ?> 님 계정을 <?= $suspended ? '다시 활성화' : '정지' ?>할까요?">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="status">
        <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
        <input type="hidden" name="status" value="<?= $suspended ? 'active' : 'suspended' ?>">
        <input type="hidden" name="back" value="<?= htmlspecialchars($back) ?>">
        <button class="ad-btn <?= $suspended ? 'ok-weak' : 'danger-weak' ?>"><?= $suspended ? '계정 다시 활성화' : '계정 정지' ?></button>
    </form>
</div>

<?php if ($msg): ?><div class="ad-alert ok">✅ <span><?= htmlspecialchars($msg) ?></span></div><?php endif; ?>
<?php if ($err): ?><div class="ad-alert err">⚠️ <span><?= htmlspecialchars($err) ?></span></div><?php endif; ?>

<!-- 요약 -->
<div class="ad-grid c4">
    <div class="ad-card ad-kpi"><div class="k">총자산</div><div class="v"><?= number_format($totalAsset) ?>원</div></div>
    <div class="ad-card ad-kpi"><div class="k">수익률 <span class="muted" style="font-weight:500;">(시작 <?= number_format($initial) ?>원 대비)</span></div><div class="v <?= $rate > 0 ? 'up' : ($rate < 0 ? 'down' : '') ?>"><?= $rate > 0 ? '+' : '' ?><?= $rate ?>%</div></div>
    <div class="ad-card ad-kpi"><div class="k">예수금</div><div class="v"><?= number_format($u['cash_balance']) ?>원</div></div>
    <div class="ad-card ad-kpi"><div class="k">주식 평가금액</div><div class="v"><?= number_format($evalSum) ?>원</div><div class="s"><?= count($holdings) ?>종목 보유</div></div>
</div>

<!-- 예수금 조정 -->
<div class="ad-card" style="margin-top:var(--sp-4);">
    <div class="ad-card-h"><span class="ad-card-t">예수금 조정</span></div>
    <?php if (adminCan('adjust_cash')): ?>
        <form method="POST" action="user_action.php" class="ad-form-row" id="cash-form" data-confirm-cash>
            <?= csrfField() ?>
            <input type="hidden" name="action" value="cash">
            <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
            <input type="hidden" name="back" value="<?= htmlspecialchars($back) ?>">
            <div class="ad-unit" style="width:200px;"><input type="number" name="delta" class="ad-input" placeholder="+ 지급 / − 차감" step="1" required><span>원</span></div>
            <input type="text" name="reason" class="ad-input grow" placeholder="사유 (예: 이벤트 보상, 오류 정정)" maxlength="100">
            <button class="ad-btn primary">적용</button>
        </form>
        <p class="ad-note">음수(예: -50000)를 넣으면 차감돼요. 모든 조정은 아래 이력과 감사 기록에 남아요.</p>
    <?php else: ?>
        <div class="ad-alert info" style="margin:0;">🔒 <span>예수금 조정은 <b>최고관리자</b>만 할 수 있어요.</span></div>
    <?php endif; ?>
</div>

<div class="ad-grid c2">
    <!-- 보유 종목 -->
    <div class="ad-card">
        <div class="ad-card-h"><span class="ad-card-t">보유 종목 <small><?= count($holdings) ?>개</small></span></div>
        <?php if (!$holdings): ?><div class="ad-empty"><b>🌱</b>보유 종목이 없어요.</div><?php else: ?>
        <div class="ad-table-wrap scroll"><table class="ad-table">
            <thead><tr><th>종목</th><th class="r">평균 단가</th><th class="r">현재가</th><th class="r">평가 손익</th></tr></thead>
            <tbody>
            <?php foreach ($holdings as $h): $pl = ($h['price'] - $h['avg_price']) * $h['quantity']; ?>
                <tr>
                    <td><?= htmlspecialchars($h['stock_name']) ?><div class="sm"><?= number_format($h['quantity']) ?>주</div></td>
                    <td class="r nw"><?= number_format($h['avg_price']) ?></td>
                    <td class="r nw"><?= number_format($h['price']) ?></td>
                    <td class="r nw <?= $pl > 0 ? 'up' : ($pl < 0 ? 'down' : 'flat') ?>"><b><?= $pl > 0 ? '+' : '' ?><?= number_format($pl) ?></b></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php endif; ?>
    </div>

    <!-- 접속 기록 -->
    <div class="ad-card">
        <div class="ad-card-h"><span class="ad-card-t">접속 기록 <small>IP</small></span></div>
        <?php if (!$accessLogs): ?><div class="ad-empty"><b>📭</b>기록이 없어요.<br><span class="sm">로그인·거래할 때 쌓여요.</span></div><?php else: ?>
        <div class="ad-table-wrap scroll"><table class="ad-table">
            <thead><tr><th>일시</th><th>IP</th><th class="c">구분</th></tr></thead>
            <tbody>
            <?php foreach ($accessLogs as $a): ?>
                <tr><td class="sm nw"><?= htmlspecialchars(substr($a['created_at'], 5, 11)) ?></td><td class="nw"><?= htmlspecialchars($a['ip']) ?></td>
                    <td class="c"><span class="ad-badge <?= $a['action'] === 'trade' ? 'warn' : '' ?>"><?= $a['action'] === 'trade' ? '거래' : '로그인' ?></span></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php endif; ?>
    </div>
</div>

<div class="ad-grid c2">
    <!-- 거래 히스토리 -->
    <div class="ad-card">
        <div class="ad-card-h"><span class="ad-card-t">거래 기록 <small>최근 <?= count($orders) ?>건</small></span></div>
        <?php if (!$orders): ?><div class="ad-empty"><b>🧾</b>거래가 없어요.</div><?php else: ?>
        <div class="ad-table-wrap scroll"><table class="ad-table">
            <thead><tr><th>일시</th><th>종목</th><th class="c">구분</th><th class="r">금액</th></tr></thead>
            <tbody>
            <?php foreach ($orders as $o): $buy = $o['order_type'] === 'buy'; ?>
                <tr>
                    <td class="sm nw"><?= htmlspecialchars(substr($o['created_at'], 5, 11)) ?></td>
                    <td><?= htmlspecialchars($o['stock_name']) ?><div class="sm"><?= number_format($o['quantity']) ?>주 · <?= number_format($o['price']) ?>원</div></td>
                    <td class="c"><span class="ad-badge <?= $buy ? 'red' : 'blue' ?>"><?= $buy ? '구매' : '판매' ?></span></td>
                    <td class="r nw"><?= number_format($o['total_amount']) ?>원</td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php endif; ?>
    </div>

    <!-- 예수금 조정 이력 -->
    <div class="ad-card">
        <div class="ad-card-h"><span class="ad-card-t">예수금 조정 이력</span></div>
        <?php if (!$cashLogs): ?><div class="ad-empty"><b>📭</b>조정 이력이 없어요.</div><?php else: ?>
        <div class="ad-table-wrap scroll"><table class="ad-table">
            <thead><tr><th>일시</th><th class="r">변동</th><th class="r">조정 후</th><th>사유</th></tr></thead>
            <tbody>
            <?php foreach ($cashLogs as $c): ?>
                <tr>
                    <td class="sm nw"><?= htmlspecialchars(substr($c['created_at'], 5, 11)) ?></td>
                    <td class="r nw <?= $c['delta'] > 0 ? 'up' : 'down' ?>"><b><?= $c['delta'] > 0 ? '+' : '' ?><?= number_format($c['delta']) ?></b></td>
                    <td class="r nw"><?= number_format($c['balance_after']) ?></td>
                    <td class="sm"><?= htmlspecialchars($c['reason']) ?: '—' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php endif; ?>
    </div>
</div>

<script>
    // 위험한 작업은 한 번 더 묻는다
    document.querySelectorAll('form[data-confirm]').forEach(f => f.addEventListener('submit', e => {
        if (!confirm(f.dataset.confirm)) e.preventDefault();
    }));
    // 예수금 조정: 금액을 읽기 쉽게 보여 주고 확인
    document.querySelector('form[data-confirm-cash]')?.addEventListener('submit', e => {
        const d = parseInt(e.target.delta.value, 10) || 0;
        if (!d) { e.preventDefault(); alert('조정 금액을 입력해 주세요.'); return; }
        const txt = (d > 0 ? '+' : '') + d.toLocaleString('ko-KR') + '원';
        if (!confirm(`예수금을 ${txt} ${d > 0 ? '지급' : '차감'}할까요?`)) e.preventDefault();
    });
</script>

<?php include 'includes/footer.php'; ?>
