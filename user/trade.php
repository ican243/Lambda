<?php
require_once 'func.php';
/** @var mysqli $conn */   // config/db.php 에서 넘어옴 (에디터 자동완성·오탐 방지용)
startUserSession();

if (!isLoggedIn()) {
    header('Location: login.php');
    exit;
}

$account = getMyAccount($conn, $_SESSION['user_id']);
$holdings = getMyHoldings($conn, $_SESSION['user_id']);
$orders = getMyOrders($conn, $_SESSION['user_id']);

// 총 평가금액 = 현금 + 보유종목 평가액
$stockValue = 0;
foreach ($holdings as $h) {
    if ($h['current_price']) $stockValue += $h['current_price'] * $h['quantity'];
}
$totalAsset = $account['cash_balance'] + $stockValue;

include 'includes/header.php';
?>

<!-- 보유종목/주문내역 위젯(이동): 로컬 라이브러리 (CDN 왕복 제거) -->
<link href="assets/gridstack.min.css" rel="stylesheet">
<script src="assets/gridstack-all.js"></script>

<div style="margin: 12px 0 8px;">
    <a href="index.php" class="muted" style="text-decoration:none;font-size:15px;font-weight:600;">← 목록</a>
</div>

<?php if (isset($_GET['error'])): ?>
    <div class="alert-t alert-err"><?= htmlspecialchars($_GET['error']) ?></div>
<?php endif; ?>
<?php if (isset($_GET['success'])): ?>
    <div class="alert-t alert-ok"><?= htmlspecialchars($_GET['success']) ?></div>
<?php endif; ?>

<!-- 총 자산 히어로 카드 (실시간 갱신) -->
<div class="card-t" style="background:linear-gradient(135deg,#3182f6,#1b64da);color:#fff;">
    <div style="font-size:14px;font-weight:600;opacity:.85;display:flex;align-items:center;gap:6px;">
        총 평가자산 <span id="live-dot" style="width:7px;height:7px;border-radius:50%;background:#9be7b4;display:inline-block;transition:opacity .3s;"></span>
    </div>
    <div id="total-asset" style="font-size:30px;font-weight:700;margin:6px 0 16px;"><?= number_format($totalAsset) ?>원</div>
    <div style="display:flex;justify-content:space-between;font-size:14px;opacity:.9;">
        <span>주문가능 현금</span><span style="font-weight:600;"><?= number_format($account['cash_balance']) ?>원</span>
    </div>
    <div style="display:flex;justify-content:space-between;font-size:14px;opacity:.9;margin-top:6px;">
        <span>보유주식 평가액</span><span style="font-weight:600;" id="stock-value"><?= number_format($stockValue) ?>원</span>
    </div>
</div>

<!-- 내 계좌 위젯(끌어서 순서 변경 · 세로 크기조절): stock_detail.php 대시보드와 같은 방식 -->
<div class="dv-bar" style="margin-top:4px;">
    <span class="section-title" style="margin:0;">내 계좌</span>
    <div class="dv-tools" id="dv-tools">
        <span class="dv-hint"><svg width="13" height="13" viewBox="0 0 16 16" fill="currentColor"><circle cx="5" cy="4" r="1.3"/><circle cx="11" cy="4" r="1.3"/><circle cx="5" cy="8" r="1.3"/><circle cx="11" cy="8" r="1.3"/><circle cx="5" cy="12" r="1.3"/><circle cx="11" cy="12" r="1.3"/></svg> 끌어서 순서 변경 · 아래로 늘려서 더보기</span>
        <button class="dv-reset" onclick="resetTradeLayout()">배치 초기화</button>
    </div>
</div>

<div id="trade-grid" class="grid-stack">
    <!-- 보유 종목 -->
    <div class="grid-stack-item" gs-id="holdings" gs-w="1" gs-h="6" gs-min-h="3">
        <div class="grid-stack-item-content widget" data-w="holdings">
            <div class="widget-h"><span class="wt">보유 종목</span><span class="drag-handle" title="끌어서 이동"><svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor"><circle cx="5" cy="4" r="1.4"/><circle cx="11" cy="4" r="1.4"/><circle cx="5" cy="8" r="1.4"/><circle cx="11" cy="8" r="1.4"/><circle cx="5" cy="12" r="1.4"/><circle cx="11" cy="12" r="1.4"/></svg></span></div>
            <?php if (!$holdings): ?>
                <div class="muted" style="text-align:center;font-size:14px;padding:30px 0;">보유 중인 종목이 없습니다</div>
            <?php else: ?>
                <div class="tick-scroll">
                    <div class="stock-list">
                        <?php foreach ($holdings as $h):
                            $profit = $h['current_price'] ? ($h['current_price'] - $h['avg_price']) * $h['quantity'] : null;
                            $rate = ($h['current_price'] && $h['avg_price']) ? ($h['current_price'] - $h['avg_price']) / $h['avg_price'] * 100 : null;
                            $cls = $profit > 0 ? 'up' : ($profit < 0 ? 'down' : 'muted');
                        ?>
                            <a class="stock-row" href="stock_detail.php?code=<?= $h['stock_code'] ?>"
                                data-code="<?= $h['stock_code'] ?>" data-qty="<?= (int) $h['quantity'] ?>" data-avg="<?= (float) $h['avg_price'] ?>">
                                <div class="stock-logo"><?= mb_substr($h['stock_name'], 0, 1) ?></div>
                                <div class="stock-name">
                                    <div class="nm"><?= htmlspecialchars($h['stock_name']) ?></div>
                                    <div class="cd"><?= number_format($h['quantity']) ?>주 · 평단 <?= number_format($h['avg_price']) ?>원</div>
                                </div>
                                <div class="stock-price">
                                    <div class="pr hold-cur"><?= $h['current_price'] ? number_format($h['current_price']) . '원' : '-' ?></div>
                                    <div class="rt hold-pl <?= $cls ?>">
                                        <?= $profit !== null ? ($profit > 0 ? '+' : '') . number_format($profit) . '원' : '-' ?>
                                        <?= $rate !== null ? ' (' . ($rate > 0 ? '+' : '') . number_format($rate, 2) . '%)' : '' ?>
                                    </div>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- 주문 내역 -->
    <div class="grid-stack-item" gs-id="orders" gs-w="1" gs-h="6" gs-min-h="3">
        <div class="grid-stack-item-content widget" data-w="orders">
            <div class="widget-h"><span class="wt">주문 내역</span><span class="drag-handle" title="끌어서 이동"><svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor"><circle cx="5" cy="4" r="1.4"/><circle cx="11" cy="4" r="1.4"/><circle cx="5" cy="8" r="1.4"/><circle cx="11" cy="8" r="1.4"/><circle cx="5" cy="12" r="1.4"/><circle cx="11" cy="12" r="1.4"/></svg></span></div>
            <?php if (!$orders): ?>
                <div class="muted" style="text-align:center;font-size:14px;padding:30px 0;">아직 주문 내역이 없습니다</div>
            <?php else: ?>
                <div class="tick-scroll">
                    <div class="stock-list">
                        <?php foreach ($orders as $o):
                            $buy = $o['order_type'] === 'buy';
                        ?>
                            <div class="stock-row" style="cursor:default;">
                                <div class="stock-logo" style="background:<?= $buy ? '#fdecee' : '#e8f3ff' ?>;color:<?= $buy ? 'var(--up)' : 'var(--down)' ?>;">
                                    <?= $buy ? '매수' : '매도' ?>
                                </div>
                                <div class="stock-name">
                                    <div class="nm"><?= htmlspecialchars($o['stock_name']) ?></div>
                                    <div class="cd"><?= $o['created_at'] ?></div>
                                </div>
                                <div class="stock-price">
                                    <div class="pr"><?= number_format($o['total_amount']) ?>원</div>
                                    <div class="rt muted"><?= number_format($o['quantity']) ?>주 · <?= number_format($o['price']) ?>원</div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
    // ---------- 내 계좌 위젯 (gridstack: 이동 + 세로 리사이즈) ----------
    // stock_detail.php의 위젯 대시보드와 같은 방식, 저장 키만 이 페이지 전용으로 분리.
    const TRADE_LAYOUT_KEY = 'moa_trade_layout_v1';
    const tradeGrid = GridStack.init({
        column: 1,
        cellHeight: 60,
        margin: 10,
        float: false,
        handle: '.drag-handle',
        resizable: { handles: 's' },   // 좁은 1열 레이아웃이라 세로로만 크기조절
    }, document.getElementById('trade-grid'));

    function saveTradeLayout() {
        try { localStorage.setItem(TRADE_LAYOUT_KEY, JSON.stringify(tradeGrid.save(false))); } catch (e) {}
    }
    (function restoreTradeLayout() {
        let saved;
        try { saved = JSON.parse(localStorage.getItem(TRADE_LAYOUT_KEY)); } catch { saved = null; }
        if (Array.isArray(saved) && saved.length) tradeGrid.load(saved);
    })();
    tradeGrid.on('change', saveTradeLayout);
    tradeGrid.on('resizestop', saveTradeLayout);

    function resetTradeLayout() {
        localStorage.removeItem(TRADE_LAYOUT_KEY);
        location.reload();
    }

    // ---------------------------------------------
    // 실시간 갱신 (보유종목 현재가·평가손익·총자산)
    // 토스처럼 새로고침 없이 시세가 오면 바로 반영된다.
    // ---------------------------------------------
    const holdData = <?= json_encode(array_map(function ($h) {
        return [
            'code'  => $h['stock_code'],
            'qty'   => (int) $h['quantity'],
            'avg'   => (float) $h['avg_price'],
            'price' => $h['current_price'] ? (int) $h['current_price'] : null,
        ];
    }, $holdings), JSON_UNESCAPED_UNICODE) ?>;
    const cashBalance = <?= (int) $account['cash_balance'] ?>;

    const num = n => Number(n).toLocaleString();

    // 총 평가자산 재계산 = 현금 + Σ(보유수량 × 현재가)
    function recalcTotal() {
        let stockVal = 0;
        holdData.forEach(h => { if (h.price) stockVal += h.price * h.qty; });
        const sv = document.getElementById('stock-value');
        const ta = document.getElementById('total-asset');
        if (sv) sv.textContent = num(stockVal) + '원';
        if (ta) ta.textContent = num(cashBalance + stockVal) + '원';
    }

    const tradeSocket = new WebSocket((location.protocol === 'https:' ? 'wss://' : 'ws://') + location.host + '/ws/');
    tradeSocket.onmessage = (event) => {
        const d = JSON.parse(event.data);
        const h = holdData.find(x => x.code === d.stock_code);
        if (!h) return;                    // 내 보유종목이 아니면 무시
        h.price = d.price;

        const row = document.querySelector(`.stock-row[data-code="${d.stock_code}"]`);
        if (row) {
            const profit = (d.price - h.avg) * h.qty;
            const rate = h.avg ? (d.price - h.avg) / h.avg * 100 : 0;
            const cls = profit > 0 ? 'up' : (profit < 0 ? 'down' : 'muted');
            row.querySelector('.hold-cur').textContent = num(d.price) + '원';
            const pl = row.querySelector('.hold-pl');
            pl.textContent = `${profit > 0 ? '+' : ''}${num(Math.round(profit))}원 (${rate > 0 ? '+' : ''}${rate.toFixed(2)}%)`;
            pl.className = 'rt hold-pl ' + cls;
            row.classList.remove('flash-up', 'flash-down');
            void row.offsetWidth;
            row.classList.add(d.change_price >= 0 ? 'flash-up' : 'flash-down');
        }
        recalcTotal();

        // 실시간 표시등 깜빡
        const dot = document.getElementById('live-dot');
        if (dot) { dot.style.opacity = '0.3'; setTimeout(() => dot.style.opacity = '1', 200); }
    };
    tradeSocket.onclose = () => {
        const dot = document.getElementById('live-dot');
        if (dot) dot.style.background = '#c9cdd2';   // 연결 끊기면 회색
    };
</script>

<?php include 'includes/footer.php'; ?>
