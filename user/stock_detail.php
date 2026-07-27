<?php
require_once 'func.php';
startUserSession();

// 비로그인도 종목/차트/호가/체결을 볼 수 있는 공개 페이지. (거래·관심등록만 로그인 요구)
$loggedIn = isLoggedIn();

$stockCode = trim($_GET['code'] ?? '');
if ($stockCode === '') {
    header('Location: index.php');
    exit;
}

$stock = getSingleStockPrice($conn, $stockCode);
if (!$stock) {
    die('존재하지 않는 종목입니다.');
}

// 조회수 +1 (홈 우측 "지금 많이 봐요" 랭킹 집계용)
recordStockView($conn, $stockCode);

$isFav   = $loggedIn ? isInWatchlist($conn, $_SESSION['user_id'], $stockCode) : false;
$account = $loggedIn ? getMyAccount($conn, $_SESSION['user_id']) : null;

// 로고 색 (종목코드 해시 → 파스텔). 실제 이미지 있으면 이미지로 대체.
$hue = crc32($stockCode) % 360;

$pageClass = 'app-xwide';   // 상세는 토스처럼 화면 넓게
include 'includes/header.php';
?>

<!-- 상세 차트 + 위젯 대시보드(이동·리사이즈): 로컬 라이브러리 (CDN 왕복 제거) -->
<script src="assets/lightweight-charts.js"></script>
<link href="assets/gridstack.min.css" rel="stylesheet">
<script src="assets/gridstack-all.js"></script>

<a href="index.php" class="muted" style="text-decoration:none;font-size:15px;font-weight:600;display:inline-block;margin-bottom:14px;">← 목록으로</a>

<!-- ===== 헤더: 로고 + 종목명/가격 + 관심등록 ===== -->
<div class="dv-head">
    <div class="dv-title">
        <span class="dv-logo" style="background:hsl(<?= $hue ?>,70%,72%);">
            <img src="assets/logos/<?= htmlspecialchars($stockCode) ?>.png" alt="" onerror="this.remove()">
            <?= mb_substr($stock['stock_name'], 0, 1) ?>
        </span>
        <div class="price-hero">
            <div class="nm"><?= htmlspecialchars($stock['stock_name']) ?> <span class="muted" style="font-size:14px;">· <?= htmlspecialchars($stockCode) ?></span></div>
            <div id="detail-price" class="pr"><?= $stock['price'] ? number_format($stock['price']) . '원' : '-' ?></div>
            <div id="detail-change" class="rt <?= $stock['change_price'] > 0 ? 'up' : ($stock['change_price'] < 0 ? 'down' : 'muted') ?>">
                어제보다 <?= $stock['change_price'] > 0 ? '+' : '' ?><?= number_format($stock['change_price'] ?? 0) ?>원
                (<?= $stock['change_rate'] ?? 0 ?>%)
            </div>
        </div>
    </div>
    <button id="fav-btn" class="btn-t <?= $isFav ? 'btn-ghost-t' : 'btn-primary-t' ?>"
        style="padding:9px 16px;font-size:14px;white-space:nowrap;" data-fav="<?= $isFav ? '1' : '0' ?>">
        <?= $isFav ? '♥ 관심종목' : '♡ 관심등록' ?>
    </button>
</div>

<!-- ===== 지표 스트립 ===== -->
<div class="metric-strip">
    <div class="metric">
        <span class="mk">1일 범위</span>
        <div class="range-row"><span id="m-day-lo">-</span><span class="range-bar"><span class="dot" id="m-day-dot"></span></span><span id="m-day-hi">-</span></div>
    </div>
    <div class="metric">
        <span class="mk">52주 범위</span>
        <div class="range-row"><span id="m-52-lo">-</span><span class="range-bar"><span class="dot" id="m-52-dot"></span></span><span id="m-52-hi">-</span></div>
    </div>
    <div class="metric">
        <span class="mk">거래대금</span>
        <span class="mv" id="m-trval">-</span>
    </div>
    <div class="metric">
        <span class="mk">체결강도</span>
        <span class="mv" id="m-strength">-</span>
    </div>
</div>

<!-- ===== 탭 + 편집 툴바 ===== -->
<div class="dv-bar">
    <div class="dv-tabs">
        <button id="tab-chart" class="on" onclick="switchTab('chart')">차트·호가</button>
        <button id="tab-info" onclick="switchTab('info')">종목정보</button>
    </div>
    <div class="dv-tools" id="dv-tools">
        <span class="dv-hint"><svg width="13" height="13" viewBox="0 0 16 16" fill="currentColor"><circle cx="5" cy="4" r="1.3"/><circle cx="11" cy="4" r="1.3"/><circle cx="5" cy="8" r="1.3"/><circle cx="11" cy="8" r="1.3"/><circle cx="5" cy="12" r="1.3"/><circle cx="11" cy="12" r="1.3"/></svg> 끌어서 이동 · 모서리 잡고 크기조절</span>
        <button class="dv-reset" onclick="resetLayout()">배치 초기화</button>
    </div>
</div>

<!-- ===== 탭1: 차트·호가 (gridstack: 이동 + 칸단위 리사이즈) ===== -->
<div id="pane-chart" class="grid-stack">
    <!-- 차트 -->
    <div class="grid-stack-item" gs-id="chart" gs-w="8" gs-h="7" gs-min-w="4" gs-min-h="4">
        <div class="grid-stack-item-content widget" data-w="chart">
            <div class="widget-h">
                <span class="wt">차트</span>
                <span class="wh-right">
                    <div class="seg">
                        <button id="btn-candle" class="on" onclick="switchChartType('candle')">캔들</button>
                        <button id="btn-line" onclick="switchChartType('line')">라인</button>
                    </div>
                    <span class="drag-handle" title="끌어서 이동"><svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor"><circle cx="5" cy="4" r="1.4"/><circle cx="11" cy="4" r="1.4"/><circle cx="5" cy="8" r="1.4"/><circle cx="11" cy="8" r="1.4"/><circle cx="5" cy="12" r="1.4"/><circle cx="11" cy="12" r="1.4"/></svg></span>
                </span>
            </div>
            <div id="chart-container" style="width:100%;"></div>
        </div>
    </div>

    <!-- 주문 패널 -->
    <div class="grid-stack-item" gs-id="order" gs-w="4" gs-h="7" gs-min-w="3" gs-min-h="4">
        <div class="grid-stack-item-content widget" data-w="order">
            <div class="widget-h"><span class="wt">주문하기</span><span class="drag-handle" title="끌어서 이동"><svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor"><circle cx="5" cy="4" r="1.4"/><circle cx="11" cy="4" r="1.4"/><circle cx="5" cy="8" r="1.4"/><circle cx="11" cy="8" r="1.4"/><circle cx="5" cy="12" r="1.4"/><circle cx="11" cy="12" r="1.4"/></svg></span></div>
            <?php if ($loggedIn): ?>
                <div class="op-cash">
                    <span class="muted" style="font-size:14px;font-weight:600;">주문 가능 현금</span>
                    <span class="v"><?= number_format($account['cash_balance']) ?>원</span>
                </div>
                <form id="trade-form-detail" action="trade_process.php" method="POST">
                    <input type="hidden" name="stock_code" value="<?= htmlspecialchars($stockCode) ?>">
                    <input type="hidden" name="order_type" id="order-type-field">
                    <label class="label-t">수량</label>
                    <input type="number" id="qty" name="quantity" class="input-t" min="1" required placeholder="주문 수량" oninput="updateEstimate()">
                    <div class="order-est"><span>예상 주문금액</span><span class="v" id="estimate">0원</span></div>
                    <div style="display:flex;gap:10px;margin-top:14px;">
                        <button type="button" onclick="submitOrder('sell')" class="btn-t btn-blue-t btn-block">판매</button>
                        <button type="button" onclick="submitOrder('buy')" class="btn-t btn-red-t btn-block">구매</button>
                    </div>
                </form>
            <?php else: ?>
                <p class="muted" style="font-size:14px;margin:6px 0 16px;">로그인하면 1,000만원 모의자금으로 사고팔 수 있어요.</p>
                <a href="login.php" class="btn-t btn-primary-t btn-block" style="text-decoration:none;">로그인하고 거래하기</a>
            <?php endif; ?>
        </div>
    </div>

    <!-- 호가창 -->
    <div class="grid-stack-item" gs-id="book" gs-w="4" gs-h="7" gs-min-w="3" gs-min-h="4">
        <div class="grid-stack-item-content widget" data-w="book">
            <div class="widget-h"><span class="wt">호가 <span class="live" id="ob-live"><span class="blip"></span>실시간</span></span><span class="drag-handle" title="끌어서 이동"><svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor"><circle cx="5" cy="4" r="1.4"/><circle cx="11" cy="4" r="1.4"/><circle cx="5" cy="8" r="1.4"/><circle cx="11" cy="8" r="1.4"/><circle cx="5" cy="12" r="1.4"/><circle cx="11" cy="12" r="1.4"/></svg></span></div>
            <div id="orderbook"><div class="muted" style="font-size:13px;padding:20px 0;text-align:center;">불러오는 중…</div></div>
        </div>
    </div>

    <!-- 체결내역 (시세) -->
    <div class="grid-stack-item" gs-id="ticks" gs-w="4" gs-h="7" gs-min-w="3" gs-min-h="4">
        <div class="grid-stack-item-content widget" data-w="ticks">
            <div class="widget-h"><span class="wt">시세 · 체결 <span class="live" id="tk-live"><span class="blip"></span>실시간</span></span><span class="drag-handle" title="끌어서 이동"><svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor"><circle cx="5" cy="4" r="1.4"/><circle cx="11" cy="4" r="1.4"/><circle cx="5" cy="8" r="1.4"/><circle cx="11" cy="8" r="1.4"/><circle cx="5" cy="12" r="1.4"/><circle cx="11" cy="12" r="1.4"/></svg></span></div>
            <div class="tick-scroll">
                <table class="mini-tbl">
                    <thead><tr><th>체결시각</th><th>체결가</th><th>체결량</th></tr></thead>
                    <tbody id="tick-body"><tr><td colspan="3" class="muted" style="text-align:center;padding:16px;">불러오는 중…</td></tr></tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- 수급 (개인·외국인·기관) -->
    <div class="grid-stack-item" gs-id="invest" gs-w="4" gs-h="6" gs-min-w="3" gs-min-h="3">
        <div class="grid-stack-item-content widget" data-w="invest">
            <div class="widget-h"><span class="wt">개인 · 외국인 · 기관 <span class="muted" style="font-size:11px;font-weight:600;">순매수(주)</span></span><span class="drag-handle" title="끌어서 이동"><svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor"><circle cx="5" cy="4" r="1.4"/><circle cx="11" cy="4" r="1.4"/><circle cx="5" cy="8" r="1.4"/><circle cx="11" cy="8" r="1.4"/><circle cx="5" cy="12" r="1.4"/><circle cx="11" cy="12" r="1.4"/></svg></span></div>
            <div class="tick-scroll">
                <table class="mini-tbl">
                    <thead><tr><th>일자</th><th>개인</th><th>외국인</th><th>기관</th></tr></thead>
                    <tbody id="inv-body"><tr><td colspan="4" class="muted" style="text-align:center;padding:16px;">불러오는 중…</td></tr></tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- 커뮤니티 -->
    <div class="grid-stack-item" gs-id="community" gs-w="8" gs-h="5" gs-min-w="4" gs-min-h="4">
        <div class="grid-stack-item-content widget" data-w="community">
            <div class="widget-h"><span class="wt">커뮤니티</span><span class="drag-handle" title="끌어서 이동"><svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor"><circle cx="5" cy="4" r="1.4"/><circle cx="11" cy="4" r="1.4"/><circle cx="5" cy="8" r="1.4"/><circle cx="11" cy="8" r="1.4"/><circle cx="5" cy="12" r="1.4"/><circle cx="11" cy="12" r="1.4"/></svg></span></div>
            <div id="post-list" class="post-list"><div class="post-empty">불러오는 중…</div></div>
            <form id="post-form" class="post-form" onsubmit="submitPost(event)">
                <textarea id="post-input" maxlength="500" placeholder="<?= $loggedIn ? '이 종목 어때요? 의견을 남겨보세요' : '로그인하고 의견을 남겨보세요' ?>"></textarea>
                <button type="submit" class="btn-t btn-primary-t" style="padding:0 16px;font-size:14px;white-space:nowrap;">등록</button>
            </form>
        </div>
    </div>
</div>

<!-- ===== 탭2: 종목정보 ===== -->
<div id="pane-info" class="widget" style="display:none;">
    <div class="info-grid" id="info-grid">
        <div class="muted" style="padding:20px;">불러오는 중…</div>
    </div>
</div>

<script>
    const stockCode = '<?= $stockCode ?>';
    const loggedIn = <?= $loggedIn ? 'true' : 'false' ?>;
    let currentPrice = <?= (int) ($stock['price'] ?? 0) ?>;
    let prevClose = <?= (int) (($stock['price'] ?? 0) - ($stock['change_price'] ?? 0)) ?>;
    let quote = null;
    let candleData = [];
    let currentType = 'candle';
    let series;

    // ---------- 포맷 헬퍼 ----------
    const won = n => Number(n).toLocaleString() + '원';
    const qtyFmt = n => Number(n).toLocaleString();
    function signClass(sign) { return (sign == 1 || sign == 2) ? 'up' : ((sign == 4 || sign == 5) ? 'down' : 'muted'); }
    function priceClass(p) { return p > prevClose ? 'up' : (p < prevClose ? 'down' : 'muted'); }
    function tradeValueFmt(v) {
        v = Number(v);
        const jo = Math.floor(v / 1e12);
        const eok = Math.floor((v % 1e12) / 1e8);
        if (jo > 0) return jo + '조 ' + eok.toLocaleString() + '억';
        return eok.toLocaleString() + '억';
    }
    function timeFmt(hhmmss) {
        const s = String(hhmmss).padStart(6, '0');
        return s.slice(0,2) + ':' + s.slice(2,4) + ':' + s.slice(4,6);
    }
    function rangePct(v, lo, hi) { return hi > lo ? Math.max(0, Math.min(100, (v - lo) / (hi - lo) * 100)) : 50; }

    function updateEstimate() {
        const q = parseInt(document.getElementById('qty')?.value) || 0;
        const est = document.getElementById('estimate');
        if (est) est.textContent = (q * currentPrice).toLocaleString() + '원';
    }

    // ---------- 지표 스트립 ----------
    function applyQuote(q) {
        quote = q;
        prevClose = q.prev_close || prevClose;
        document.getElementById('m-day-lo').textContent = qtyFmt(q.low);
        document.getElementById('m-day-hi').textContent = qtyFmt(q.high);
        document.getElementById('m-day-dot').style.left = rangePct(currentPrice, q.low, q.high) + '%';
        document.getElementById('m-52-lo').textContent = qtyFmt(q.w52_low);
        document.getElementById('m-52-hi').textContent = qtyFmt(q.w52_high);
        document.getElementById('m-52-dot').style.left = rangePct(currentPrice, q.w52_low, q.w52_high) + '%';
        document.getElementById('m-trval').textContent = tradeValueFmt(q.trade_value);
        renderInfo(q);
    }
    function refreshDayDot() {
        if (!quote) return;
        document.getElementById('m-day-dot').style.left = rangePct(currentPrice, quote.low, quote.high) + '%';
    }
    function setStrength(v) {
        if (!v) return;
        document.getElementById('m-strength').textContent = Number(v).toFixed(1) + '%';
    }

    // ---------- 종목정보 탭 ----------
    function renderInfo(q) {
        const rows = [
            ['시장', q.market || '-'], ['업종', q.sector || '-'],
            ['시가', won(q.open)], ['전일종가', won(q.prev_close)],
            ['상한가', won(q.upper_limit)], ['하한가', won(q.lower_limit)],
            ['52주 최고', won(q.w52_high)], ['52주 최저', won(q.w52_low)],
            ['PER', q.per + '배'], ['PBR', q.pbr + '배'],
            ['EPS', qtyFmt(q.eps) + '원'], ['BPS', qtyFmt(q.bps) + '원'],
            ['시가총액', qtyFmt(q.market_cap) + '억'], ['상장주식수', qtyFmt(q.shares) + '주'],
            ['외국인 소진율', q.frgn_ehrt + '%'], ['거래량', qtyFmt(q.volume) + '주'],
        ];
        document.getElementById('info-grid').innerHTML = rows.map(([k, v]) =>
            `<div class="info-row"><span class="k">${k}</span><span class="v">${v}</span></div>`).join('');
    }

    // ---------- 호가창 ----------
    function renderOrderbook(d) {
        if (!d || d.error || !d.asks) return;
        const all = d.asks.concat(d.bids).map(x => x.qty);
        const maxQty = Math.max(1, ...all);
        let html = '';
        // 매도호가: askp10(높은가) → askp1(최우선). 위에서 아래로.
        for (let i = 9; i >= 0; i--) {
            const a = d.asks[i];
            const w = (a.qty / maxQty * 100).toFixed(0);
            html += `<div class="ob-row ob-ask"><div class="ob-price">${qtyFmt(a.price)}</div>`
                 +  `<div class="ob-qty"><span class="bar" style="width:${w}%"></span><span class="num">${qtyFmt(a.qty)}</span></div></div>`;
        }
        html += `<div class="ob-mid ${priceClass(currentPrice)}">${qtyFmt(currentPrice)}</div>`;
        // 매수호가: bidp1(최우선) → bidp10.
        for (let i = 0; i < 10; i++) {
            const b = d.bids[i];
            const w = (b.qty / maxQty * 100).toFixed(0);
            html += `<div class="ob-row ob-bid"><div class="ob-price">${qtyFmt(b.price)}</div>`
                 +  `<div class="ob-qty"><span class="bar" style="width:${w}%"></span><span class="num">${qtyFmt(b.qty)}</span></div></div>`;
        }
        html += `<div class="ob-total"><span>매도잔량 <b>${qtyFmt(d.total_ask)}</b></span><span>매수잔량 <b>${qtyFmt(d.total_bid)}</b></span></div>`;
        document.getElementById('orderbook').innerHTML = html;
    }

    // ---------- 체결내역 ----------
    let ticks = [];
    function renderTicks() {
        if (!ticks.length) return;
        document.getElementById('tick-body').innerHTML = ticks.slice(0, 30).map(t =>
            `<tr><td>${timeFmt(t.time)}</td><td class="${signClass(t.sign)}">${qtyFmt(t.price)}</td><td>${qtyFmt(t.volume)}</td></tr>`).join('');
    }
    function pushTick(t) { ticks.unshift(t); if (ticks.length > 60) ticks.pop(); renderTicks(); }

    // ---------- 수급 ----------
    function renderInvestor(rows) {
        if (!Array.isArray(rows) || !rows.length) {
            document.getElementById('inv-body').innerHTML = '<tr><td colspan="4" class="muted" style="text-align:center;padding:16px;">데이터 없음</td></tr>';
            return;
        }
        const sc = v => v > 0 ? 'up' : (v < 0 ? 'down' : 'muted');
        const sg = v => (v > 0 ? '+' : '') + qtyFmt(v);
        document.getElementById('inv-body').innerHTML = rows.slice(0, 10).map(r => {
            const d = String(r.date).slice(4, 6) + '.' + String(r.date).slice(6, 8);
            return `<tr><td>${d}</td>`
                 + `<td class="${sc(r.individual)}">${sg(r.individual)}</td>`
                 + `<td class="${sc(r.foreign)}">${sg(r.foreign)}</td>`
                 + `<td class="${sc(r.institution)}">${sg(r.institution)}</td></tr>`;
        }).join('');
    }

    // ---------- 탭 전환 ----------
    function switchTab(t) {
        const isChart = t === 'chart';
        document.getElementById('pane-chart').style.display = isChart ? '' : 'none';
        document.getElementById('pane-info').style.display = isChart ? 'none' : 'block';
        document.getElementById('dv-tools').style.display = isChart ? 'flex' : 'none';
        document.getElementById('tab-chart').className = isChart ? 'on' : '';
        document.getElementById('tab-info').className = isChart ? '' : 'on';
        if (isChart) resizeChart();
    }
    function resizeChart() {
        // autoSize가 폭/높이를 자동으로 맞추므로 캔들 범위만 다시 채워줌
        chart.timeScale().fitContent();
    }

    // ---------- 위젯 대시보드 (gridstack: 이동 + 칸단위 리사이즈) ----------
    const LAYOUT_KEY = 'moa_widget_layout_v1';
    const grid = GridStack.init({
        column: 12,
        cellHeight: 58,
        margin: 10,
        float: false,
        handle: '.drag-handle',                     // 이 핸들로만 이동
        resizable: { handles: 'e, se, s' },         // 오른쪽·하단·모서리로 크기조절
        columnOpts: { breakpointForWindow: true, breakpoints: [{ w: 768, c: 1 }] },  // 좁으면 1열
    }, document.getElementById('pane-chart'));

    function saveLayout() {
        try { localStorage.setItem(LAYOUT_KEY, JSON.stringify(grid.save(false))); } catch (e) {}
    }
    (function restoreLayout() {
        let saved;
        try { saved = JSON.parse(localStorage.getItem(LAYOUT_KEY)); } catch { saved = null; }
        if (Array.isArray(saved) && saved.length) grid.load(saved);   // 저장된 위치/크기 복원
    })();

    grid.on('change', saveLayout);
    grid.on('resizestop', () => { saveLayout(); resizeChart(); });

    function resetLayout() {          // 기본 배치로 초기화
        localStorage.removeItem(LAYOUT_KEY);
        location.reload();
    }

    // ---------- 차트 ----------
    const chart = LightweightCharts.createChart(document.getElementById('chart-container'), {
        layout: { background: { color: 'transparent' }, textColor: '#8b95a1', fontFamily: 'Pretendard' },
        grid: { vertLines: { color: '#f2f4f6' }, horzLines: { color: '#f2f4f6' } },
        rightPriceScale: { borderColor: '#e5e8eb' },
        timeScale: { borderColor: '#e5e8eb', timeVisible: true, secondsVisible: false },
        crosshair: { mode: 0 },
        autoSize: true,   // 컨테이너 크기를 자동 감지 → 창 리사이즈에 매끄럽게 대응
    });
    function createCandleSeries() {
        return chart.addCandlestickSeries({ upColor: '#f04452', downColor: '#3182f6', borderVisible: false, wickUpColor: '#f04452', wickDownColor: '#3182f6' });
    }
    function createLineSeries() { return chart.addLineSeries({ color: '#3182f6', lineWidth: 2 }); }
    series = createCandleSeries();

    async function loadChart() {
        const res = await fetch('get_stock_chart.php?stock_code=' + stockCode);
        candleData = await res.json();
        renderChart();
        chart.timeScale().fitContent();
    }
    function renderChart() {
        if (currentType === 'candle') series.setData(candleData);
        else series.setData(candleData.map(c => ({ time: c.time, value: c.close })));
    }
    function switchChartType(type) {
        if (type === currentType) return;
        currentType = type;
        chart.removeSeries(series);
        series = (type === 'candle') ? createCandleSeries() : createLineSeries();
        renderChart();
        document.getElementById('btn-candle').className = type === 'candle' ? 'on' : '';
        document.getElementById('btn-line').className = type === 'line' ? 'on' : '';
    }
    // (차트 폭은 autoSize가 자동 처리 → 별도 resize 핸들러 불필요)

    // ---------- 관심종목 토글 ----------
    document.getElementById('fav-btn').addEventListener('click', async function () {
        if (!loggedIn) { location.href = 'login.php'; return; }
        const isFav = this.dataset.fav === '1';
        const url = isFav ? 'watchlist_remove.php' : 'watchlist_add.php';
        await fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: 'stock_code=' + encodeURIComponent(stockCode) });
        this.dataset.fav = isFav ? '0' : '1';
        this.textContent = isFav ? '♡ 관심등록' : '♥ 관심종목';
        this.className = 'btn-t ' + (isFav ? 'btn-primary-t' : 'btn-ghost-t');
        this.style.cssText = 'padding:9px 16px;font-size:14px;white-space:nowrap;';
    });

    // ---------- 주문 ----------
    function submitOrder(type) {
        const form = document.getElementById('trade-form-detail');
        const qty = parseInt(document.getElementById('qty').value);
        if (!qty || qty < 1) { alert('수량을 입력해주세요.'); return; }
        document.getElementById('order-type-field').value = type;
        form.querySelectorAll('button').forEach(b => b.disabled = true);
        form.submit();
    }

    // ---------- 초기 REST 로드 (장 마감/실시간 끊겨도 항상 보이게) ----------
    async function loadInitial() {
        try {
            const [q, ob, cc, inv] = await Promise.all([
                fetch('get_quote.php?code=' + stockCode).then(r => r.json()),
                fetch('get_orderbook.php?code=' + stockCode).then(r => r.json()),
                fetch('get_ccnl.php?code=' + stockCode).then(r => r.json()),
                fetch('get_investor.php?code=' + stockCode).then(r => r.json()),
            ]);
            if (q && !q.error) {
                currentPrice = q.price || currentPrice;
                applyQuote(q);
                // 헤더 가격도 최신값으로
                document.getElementById('detail-price').textContent = won(currentPrice);
                const ch = document.getElementById('detail-change');
                ch.textContent = `어제보다 ${q.change_price > 0 ? '+' : ''}${qtyFmt(q.change_price)}원 (${q.change_rate}%)`;
                ch.className = 'rt ' + priceClass(currentPrice);
                updateEstimate();
            }
            renderOrderbook(ob);
            if (cc && cc.trades) { ticks = cc.trades; renderTicks(); setStrength(cc.strength); }
            renderInvestor(inv);
        } catch (e) { console.warn('초기 로드 실패', e); }
    }

    // ---------- 실시간 WS ----------
    function markLive(id) {
        const el = document.getElementById(id);
        if (el) { el.classList.add('on'); clearTimeout(el._t); el._t = setTimeout(() => el.classList.remove('on'), 4000); }
    }
    const detailSocket = new WebSocket('ws://localhost:8080');
    detailSocket.onopen = () => detailSocket.send(JSON.stringify({ action: 'watch', code: stockCode }));
    detailSocket.onmessage = (event) => {
        const d = JSON.parse(event.data);
        if (d.stock_code !== stockCode) return;

        if (d.type === 'orderbook') {
            renderOrderbook(d); markLive('ob-live'); return;
        }
        if (d.type === 'trade') {
            pushTick({ time: d.time, price: d.price, volume: d.volume, sign: d.sign });
            setStrength(d.strength); markLive('tk-live'); return;
        }
        // type === 'price' (또는 구버전 무태그) → 헤더/차트/지표 갱신
        currentPrice = d.price;
        updateEstimate();
        refreshDayDot();
        document.getElementById('detail-price').textContent = won(d.price);
        const ch = document.getElementById('detail-change');
        ch.textContent = `어제보다 ${d.change_price > 0 ? '+' : ''}${qtyFmt(d.change_price)}원 (${d.change_rate}%)`;
        ch.className = 'rt ' + (d.change_price > 0 ? 'up' : (d.change_price < 0 ? 'down' : 'muted'));
        updateLastCandle(d.price, Math.floor(new Date(d.created_at).getTime() / 1000));
    };

    function updateLastCandle(price, timestamp) {
        const currentMinute = Math.floor(timestamp / 60) * 60;
        if (candleData.length === 0) {
            candleData.push({ time: currentMinute, open: price, high: price, low: price, close: price });
        } else {
            const last = candleData[candleData.length - 1];
            if (last.time === currentMinute) {
                last.high = Math.max(last.high, price); last.low = Math.min(last.low, price); last.close = price;
            } else if (currentMinute > last.time) {
                candleData.push({ time: currentMinute, open: price, high: price, low: price, close: price });
            } else { return; }
        }
        const last = candleData[candleData.length - 1];
        if (currentType === 'candle') series.update(last);
        else series.update({ time: last.time, value: price });
    }

    // ---------- 커뮤니티 ----------
    function esc(s) { return String(s ?? '').replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c])); }
    async function loadPosts() {
        try {
            const posts = await fetch('get_posts.php?code=' + stockCode).then(r => r.json());
            const box = document.getElementById('post-list');
            if (!Array.isArray(posts) || !posts.length) {
                box.innerHTML = '<div class="post-empty">아직 글이 없어요.<br>첫 의견을 남겨보세요 ✍️</div>';
                return;
            }
            box.innerHTML = posts.map(p =>
                `<div class="post-item"><div class="pmeta"><b>${esc(p.nickname)}</b><span>${(p.created_at || '').slice(5, 16)}</span></div>`
                + `<div class="pbody">${esc(p.content)}</div></div>`).join('');
        } catch (e) { console.warn('커뮤니티 로드 실패', e); }
    }
    async function submitPost(e) {
        e.preventDefault();
        if (!loggedIn) { location.href = 'login.php'; return; }
        const ta = document.getElementById('post-input');
        const content = ta.value.trim();
        if (!content) return;
        const r = await fetch('post_add.php', {
            method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'stock_code=' + encodeURIComponent(stockCode) + '&content=' + encodeURIComponent(content),
        }).then(r => r.json());
        if (r.error === 'login') { location.href = 'login.php'; return; }
        ta.value = '';
        loadPosts();
    }

    // ---------- 부팅 ----------
    loadChart();
    loadInitial();
    loadPosts();
    window.addEventListener('load', resizeChart);   // 레이아웃 확정 후 차트 폭 재조정
</script>

<?php include 'includes/footer.php'; ?>
