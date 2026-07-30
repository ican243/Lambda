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

<!-- 빠른 주문 -->
<div class="section-title">빠른 주문</div>
<div class="card-t">
    <form action="trade_process.php" method="POST" id="trade-form">
        <?= csrfField() ?>
        <!-- 종목명으로 검색 → 선택하면 코드가 자동으로 채워짐 (초보자 친화) -->
        <div class="ac-wrap" style="margin-bottom:10px;">
            <input type="text" id="q-search" class="input-t" autocomplete="off"
                placeholder="종목명으로 검색 (예: 삼성전자, 하이닉스)">
            <input type="hidden" name="stock_code" id="q-code">
            <div id="q-results" class="ac-results"></div>
        </div>
        <div id="q-picked" class="ac-picked" style="display:none;margin:0 2px 10px;"></div>

        <input type="number" name="quantity" id="q-qty" class="input-t" placeholder="수량" required min="1" style="margin-bottom:12px;">
        <!-- 어느 버튼을 눌렀는지 여기에 담김 (Enter 오작동 방지) -->
        <input type="hidden" name="order_type" id="q-type">
        <div style="display:flex;gap:10px;">
            <button type="button" onclick="submitQuick('sell')" class="btn-t btn-blue-t btn-block">매도</button>
            <button type="button" onclick="submitQuick('buy')" class="btn-t btn-red-t btn-block">매수</button>
        </div>
    </form>
</div>

<!-- 보유 종목 -->
<div class="section-title">보유 종목</div>
<?php if (!$holdings): ?>
    <div class="card-t muted" style="text-align:center;font-size:14px;">보유 중인 종목이 없습니다</div>
<?php else: ?>
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
<?php endif; ?>

<!-- 주문 내역 -->
<div class="section-title">주문 내역</div>
<?php if (!$orders): ?>
    <div class="card-t muted" style="text-align:center;font-size:14px;">아직 주문 내역이 없습니다</div>
<?php else: ?>
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
<?php endif; ?>

<script>
    const qSearch = document.getElementById('q-search');
    const qCode = document.getElementById('q-code');
    const qResults = document.getElementById('q-results');
    const qPicked = document.getElementById('q-picked');
    let qTimer = null;

    // 종목명 입력 → 검색 → 드롭다운
    qSearch.addEventListener('input', () => {
        qCode.value = '';                 // 다시 입력하면 이전 선택 해제
        qPicked.style.display = 'none';
        clearTimeout(qTimer);
        const kw = qSearch.value.trim();
        if (!kw) { qResults.style.display = 'none'; qResults.innerHTML = ''; return; }

        qTimer = setTimeout(async () => {
            const res = await fetch('watchlist_search.php?keyword=' + encodeURIComponent(kw));
            const stocks = await res.json();
            if (!stocks.length) { qResults.style.display = 'none'; return; }

            qResults.style.display = 'block';
            qResults.innerHTML = stocks.map(s => `
                <div class="ac-item" data-code="${s.stock_code}" data-name="${s.stock_name}">
                    <span class="anm">${s.stock_name}</span>
                    <span class="acd">${s.stock_code} · ${s.market ?? ''}</span>
                </div>`).join('');

            qResults.querySelectorAll('.ac-item').forEach(item => {
                item.addEventListener('click', () => {
                    qCode.value = item.dataset.code;              // 코드 자동 입력!
                    qSearch.value = item.dataset.name;
                    qPicked.textContent = `✓ ${item.dataset.name} (${item.dataset.code}) 선택됨`;
                    qPicked.style.display = 'block';
                    qResults.style.display = 'none';
                });
            });
        }, 250);
    });

    // 바깥 클릭 시 드롭다운 닫기
    document.addEventListener('click', (e) => {
        if (!qSearch.contains(e.target) && !qResults.contains(e.target)) qResults.style.display = 'none';
    });

    // 매수/매도 버튼 클릭 시에만 주문 (Enter로는 제출되지 않음)
    function submitQuick(type) {
        let code = qCode.value;
        // 목록에서 안 골랐어도, 6자리 숫자 코드를 직접 쳤으면 그대로 인정
        if (!code) {
            const v = qSearch.value.trim();
            if (/^\d{6}$/.test(v)) code = v;
        }
        if (!code) { alert('종목명을 검색해서 목록에서 선택해주세요.'); return; }
        const qty = parseInt(document.getElementById('q-qty').value);
        if (!qty || qty < 1) { alert('수량을 입력해주세요.'); return; }

        qCode.value = code;
        document.getElementById('q-type').value = type;   // buy / sell 확정
        const form = document.getElementById('trade-form');
        form.querySelectorAll('button').forEach(b => b.disabled = true);
        form.submit();
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

    const tradeSocket = new WebSocket('ws://localhost:8080');
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
