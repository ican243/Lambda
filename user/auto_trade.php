<?php
// user/auto_trade.php - 자동매매 (로그인 필수)
//
// 이번 단계는 "화면만". 실제 매매 로직은 다음 단계.
// 화면 구성 (형 손그림 last2.png 기준 → 토스형으로 다듬음):
//   위   : 가동 스위치(일시정지/재개) + 요약 카드(투자 중 금액 · 평가 손익 · 주문 가능 현금)
//   가운데: 보유주식 도넛차트 (상위 5개만, '기타'로 안 묶음, 조각 크기 = 평가금액) + 상위 5종목 목록
//   아래 : 거래내역 6가지 정보 (종목명·종목코드·시간·가격·매수/매도·수익률), 최신 15건 FIFO
require_once 'func.php';
/** @var mysqli $conn */   // config/db.php 에서 넘어옴 (에디터 자동완성·오탐 방지용)
startUserSession();

// 비로그인이면 로그인 화면으로 → 로그인 끝나면 여기로 자동 복귀
requireUserLogin();

$userId  = (int) $_SESSION['user_id'];
$account = getMyAccount($conn, $userId);

// 서버에서 초기 데이터를 미리 실어 보낸다(첫 화면이 fetch를 기다리지 않게 = 홈/상세와 같은 방식)
$initial = [
    'portfolio' => getAutoPortfolio($conn, $userId, 5),
    'trades'    => getAutoTradeHistory($conn, $userId, 15),
    'paused'    => isAutoTradePaused($conn, $userId),
    'cash'      => (int) ($account['cash_balance'] ?? 0),
];

$pageWide  = true;
$pageTitle = '자동매매';
include 'includes/header.php';
?>

<div class="au">
    <div class="au-head">
        <div>
            <h1>자동매매</h1>
            <p>조건을 정해 두면 알아서 사고팔아요.</p>
        </div>
        <button type="button" class="au-switch" id="au-switch" role="switch" aria-checked="false">
            <span class="lbl"><i></i><span id="au-switch-txt">가동 중</span></span>
            <span class="track"></span>
        </button>
    </div>

    <!-- 이 화면 사용법 한 줄 — '준비 중' 대신 지금 되는 것을 알려 준다 -->
    <div class="au-notice">
        <span>🤖</span>
        <span>자동매매로 사고판 종목에는 <b>자동</b> 표시가 붙어요. 오른쪽 위 스위치로 <b>언제든 멈추고 다시 켤 수</b> 있어요.</span>
    </div>

    <!-- 요약 -->
    <div class="au-kpis">
        <div class="au-kpi"><div class="k">투자 중인 금액</div><div class="v" id="kpi-eval">—</div><div class="s flat" id="kpi-count">—</div></div>
        <div class="au-kpi"><div class="k">평가 손익</div><div class="v" id="kpi-pl">—</div><div class="s" id="kpi-plr">—</div></div>
        <div class="au-kpi"><div class="k">주문 가능 현금</div><div class="v" id="kpi-cash">—</div></div>
    </div>

    <!-- 보유 비중: 도넛 + 상위 5종목 -->
    <section class="au-card">
        <div class="au-card-h">
            <span class="au-card-t">보유 비중 <small>평가금액 상위 5종목</small></span>
            <span class="sd-live" id="au-live"><i></i>실시간</span>
        </div>
        <div id="au-mix-box">
            <div class="au-mix">
                <div>
                    <div class="au-donut">
                        <canvas id="au-donut" aria-label="보유종목 비중 도넛차트" role="img"></canvas>
                        <div class="au-donut-c" id="au-donut-c"></div>
                    </div>
                    <div class="au-mix-note" id="au-mix-note" style="text-align:center;"></div>
                </div>
                <div id="au-list"></div>
            </div>
        </div>
        <div id="au-mix-empty" hidden>
            <div class="au-empty"><div class="ico">🌱</div><div class="tt">아직 보유한 종목이 없어요</div>
                <div class="ds">종목을 사면 비중과 수익률이 여기에 그려져요.</div><a href="index.php#ranking">주식 골라보기</a></div>
        </div>
    </section>

    <!-- 거래 내역 (최신 15건) -->
    <section class="au-card">
        <div class="au-card-h">
            <span class="au-card-t">거래 내역 <small>최신 15건</small></span>
        </div>
        <table class="au-table">
            <thead>
                <tr><th>종목명</th><th>종목코드</th><th>시간</th><th class="num">가격</th><th class="ctr">구분</th><th class="num">수익률</th></tr>
            </thead>
            <tbody id="au-tbody"></tbody>
        </table>
        <div class="au-foot">
            <b>판매</b> 줄의 수익률은 <b>실제로 번 비율</b>(판매가 − 그때 평균 단가), <b>구매</b> 줄은 <b>산 가격 대비 지금 가격</b> 등락률이에요.
        </div>
    </section>
    <div class="sd-conn" id="au-toast" hidden></div>
</div>

<script src="assets/chart.umd.min.js"></script>
<script>
(function () {
    'use strict';
    // =====================================================================
    // 자동매매 화면
    //   · 초기 데이터는 서버에서 인라인으로 받음(INITIAL)
    //   · 이후 5초마다 get_auto_trades.php 폴링 → 요약/도넛/목록/거래내역 갱신
    //   · 새 거래 행은 맨 위에 삽입 + 반짝, 15개 초과분은 맨 아래에서 제거(FIFO)
    //   · 실시간 시세: 실시간 서버가 상시 보내는 홈 종목 가격 중 내 보유 종목이면 즉시 반영
    // =====================================================================
    const INITIAL  = <?= json_encode($initial, JSON_UNESCAPED_UNICODE) ?>;
    const CSRF     = <?= json_encode(csrfToken()) ?>;   // AJAX POST에 함께 보낼 CSRF 토큰
    const MAX_ROWS = 15;
    // 도넛 조각 색 (토스 블루 계열 + 포인트) — 5개면 충분
    const SLICE_COLORS = ['#3182f6', '#4dabf7', '#845ef7', '#ff922b', '#20c997'];

    const $ = id => document.getElementById(id);
    const num  = n => Math.round(Number(n) || 0).toLocaleString('ko-KR');
    const won  = n => num(n) + '원';
    const sign = n => (n > 0 ? '+' : '');
    const cls  = n => (n > 0 ? 'up' : (n < 0 ? 'down' : 'flat'));
    const esc  = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const rateText = v => (v === null || v === undefined) ? '—' : `${sign(Number(v))}${Number(v).toFixed(2)}%`;
    function shortWon(n) {                         // 조 / 억 / 만 단위로 축약 (도넛 가운데 표기용)
        n = Number(n) || 0;
        if (n >= 1e12) return (n / 1e12).toFixed(2) + '조';
        if (n >= 1e8)  return Math.round(n / 1e8).toLocaleString() + '억';
        if (n >= 1e4)  return Math.round(n / 1e4).toLocaleString() + '만';
        return n.toLocaleString();
    }
    // '2026-10-10 14:23:05' → 오늘이면 '14:23:05', 아니면 '10/09 14:23'
    function timeText(dt) {
        const s = String(dt || '');
        if (s.length < 19) return s;
        const today = new Date(Date.now() + 9 * 3600e3).toISOString().slice(0, 10);
        return s.slice(0, 10) === today ? s.slice(11, 19) : `${+s.slice(5, 7)}/${s.slice(8, 10)} ${s.slice(11, 16)}`;
    }
    function logo(code, name) {
        let h = 0; for (const ch of String(code)) h = (h * 31 + ch.charCodeAt(0)) >>> 0;
        const img = window.HAS_LOGO && HAS_LOGO.has(code) ? `<img src="assets/logos/${esc(code)}.png" alt="" loading="lazy" onload="this.classList.add('ok')" onerror="this.remove()">` : '';   // 있는 로고만 요청
        return `<span class="au-logo" style="background:hsl(${h % 360},62%,62%)">${img}${esc(String(name || '?').trim().charAt(0))}</span>`;
    }
    let toastT = null;
    function toast(msg) {
        const el = $('au-toast'); el.textContent = msg; el.hidden = false;
        clearTimeout(toastT); toastT = setTimeout(() => { el.hidden = true; }, 2200);
    }

    // ================= 요약 카드 =================
    let cash = INITIAL.cash;
    function renderKpis(p) {
        $('kpi-eval').textContent = won(p.total_eval);
        $('kpi-count').textContent = p.total_count ? `보유 ${p.total_count}종목` : '보유 종목 없음';
        const pl = (p.total_eval || 0) - (p.total_cost || 0), plr = p.total_cost ? pl / p.total_cost * 100 : 0;
        $('kpi-pl').textContent = sign(pl) + won(pl); $('kpi-pl').className = 'v ' + cls(Math.round(pl));
        $('kpi-plr').textContent = rateText(plr); $('kpi-plr').className = 's ' + cls(Math.round(pl));
        $('kpi-cash').textContent = won(cash);
    }

    // ================= 도넛 =================
    let donut = null;
    // 조각 사이 테두리 = 카드 바탕색 (라이트=흰색, 다크=진회색) → 조각이 떨어져 보이게
    const cardColor = () => getComputedStyle(document.documentElement).getPropertyValue('--card').trim() || '#fff';
    window.addEventListener('themechange', () => {
        if (!donut) return;
        donut.data.datasets[0].borderColor = donut.data.datasets[0].hoverBorderColor = cardColor();
        donut.update('none');
    });
    function renderDonut(p) {
        const items = p.items || [];
        $('au-mix-box').hidden = !items.length;
        $('au-mix-empty').hidden = !!items.length;
        if (!items.length) { if (donut) { donut.destroy(); donut = null; } return; }

        $('au-donut-c').innerHTML = `<div class="k">총 평가금액</div><div class="v">${shortWon(p.total_eval)}원</div>`;
        // 상위 5개 합이 전체의 100%가 아니면(=6위 이하 존재) 솔직하게 알려준다
        $('au-mix-note').innerHTML = p.total_count > items.length
            ? `보유 ${p.total_count}종목 중 상위 ${items.length}개 · 전체의 <b>${p.shown_ratio}%</b>`
            : `보유 ${p.total_count}종목 전체`;
        if (!window.Chart) return;

        const labels = items.map(i => i.stock_name), data = items.map(i => i.eval_amount);
        // 데이터만 바뀐 경우엔 차트를 다시 만들지 않고 갱신(폴링마다 애니메이션 튀는 것 방지)
        if (donut && donut.data.labels.join('|') === labels.join('|')) {
            donut.data.datasets[0].data = data; donut._items = items; donut.update('none');
            return;
        }
        if (donut) donut.destroy();
        donut = new Chart($('au-donut'), {
            type: 'doughnut',
            data: { labels, datasets: [{ data, backgroundColor: SLICE_COLORS.slice(0, items.length), borderColor: cardColor(), borderWidth: 3, hoverOffset: 12, hoverBorderColor: cardColor() }] },
            options: {
                cutout: '64%', responsive: true, maintainAspectRatio: false,
                animation: { animateRotate: true, animateScale: true, duration: 800 },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: 'rgba(25,31,40,.92)', padding: 12, displayColors: false,
                        titleFont: { family: 'Pretendard', size: 13, weight: '700' }, bodyFont: { family: 'Pretendard', size: 13 },
                        callbacks: { label: ctx => { const it = (donut._items || [])[ctx.dataIndex]; return it ? [`평가금액 ${won(it.eval_amount)}`, `비중 ${it.weight}%`, `수익률 ${rateText(it.profit_rate)}`] : ''; } },
                    },
                },
                // 조각 클릭 → 종목 상세
                onClick: (evt, els) => { const it = els.length && (donut._items || [])[els[0].index]; if (it) location.href = 'stock_detail.php?code=' + encodeURIComponent(it.stock_code); },
                onHover: (evt, els) => { evt.native.target.style.cursor = els.length ? 'pointer' : 'default'; },
            },
        });
        donut._items = items;
    }

    // ================= 상위 5종목 목록 =================
    function renderList(p) {
        $('au-list').innerHTML = (p.items || []).map((it, i) =>
            `<div class="au-item" data-code="${esc(it.stock_code)}">`
            + `<span class="au-dot" style="background:${SLICE_COLORS[i % SLICE_COLORS.length]}"></span>`
            + `<a class="au-item-main" href="stock_detail.php?code=${esc(it.stock_code)}">${logo(it.stock_code, it.stock_name)}`
            + `<span style="min-width:0;flex:1"><div class="nm">${esc(it.stock_name)}</div><div class="sub">비중 ${it.weight}% · ${num(it.quantity)}주</div></span>`
            + `<span class="val"><div class="v">${won(it.eval_amount)}</div><div class="r ${cls(it.profit_rate)}">${rateText(it.profit_rate)}</div></span></a>`
            + `<a class="au-trade" href="stock_detail.php?code=${esc(it.stock_code)}#order">일반매매</a></div>`
        ).join('');
    }

    // ================= 거래 내역 (최신 15건 FIFO) =================
    const seenIds = new Set();
    function rowHTML(t) {
        const buy = t.order_type === 'buy';
        return `<td class="c-name"><a href="stock_detail.php?code=${esc(t.stock_code)}">${esc(t.stock_name)}</a>${Number(t.is_auto) === 1 ? '<span class="au-auto" title="자동매매가 낸 주문">자동</span>' : ''}</td>`
            + `<td class="c-code muted">${esc(t.stock_code)}</td>`
            + `<td class="c-time muted">${timeText(t.created_at)}</td>`
            + `<td class="c-price num">${won(t.price)}</td>`
            + `<td class="c-type ctr"><span class="au-tag ${buy ? 'buy' : 'sell'}">${buy ? '구매' : '판매'}</span></td>`
            + `<td class="c-rate num rate ${cls(t.rate)}">${rateText(t.rate)}</td>`;
    }
    function renderTrades(trades) {
        const tb = $('au-tbody');
        seenIds.clear();
        if (!trades.length) {
            tb.innerHTML = `<tr class="au-empty-row"><td colspan="6"><div class="au-empty"><div class="ico">🧾</div><div class="tt">아직 거래 내역이 없어요</div><div class="ds">자동매매나 직접 주문이 체결되면 여기에 쌓여요.</div></div></td></tr>`;
            return;
        }
        tb.innerHTML = trades.map(t => { seenIds.add(t.id); return `<tr data-id="${t.id}">${rowHTML(t)}</tr>`; }).join('');
    }
    // 폴링 결과 반영: 새 거래만 맨 위에 삽입 + 반짝, 15개 넘으면 맨 아래 제거
    function mergeTrades(trades) {
        const tb = $('au-tbody');
        if (!seenIds.size) { renderTrades(trades); return; }
        const fresh = trades.filter(t => !seenIds.has(t.id)).reverse();   // 오래된 것부터 넣어야 최신이 맨 위
        fresh.forEach(t => {
            tb.querySelector('.au-empty-row')?.remove();
            const tr = document.createElement('tr');
            tr.dataset.id = t.id; tr.className = 'is-new'; tr.innerHTML = rowHTML(t);
            tb.insertBefore(tr, tb.firstChild);
            seenIds.add(t.id);
        });
        while (tb.rows.length > MAX_ROWS) { const last = tb.rows[tb.rows.length - 1]; seenIds.delete(Number(last.dataset.id)); last.remove(); }
        // 기존 행의 수익률은 현재가에 따라 계속 움직인다(구매 행) → 값이 바뀐 것만 갱신
        trades.forEach(t => {
            const cell = tb.querySelector(`tr[data-id="${t.id}"] .rate`);
            if (cell && cell.textContent !== rateText(t.rate)) { cell.textContent = rateText(t.rate); cell.className = 'c-rate num rate ' + cls(t.rate); }
        });
    }

    // ================= 가동 스위치 (일시정지 / 재개) =================
    let paused = !!INITIAL.paused;
    function renderSwitch() {
        const sw = $('au-switch');
        sw.classList.toggle('on', !paused);
        sw.setAttribute('aria-checked', String(!paused));
        $('au-switch-txt').textContent = paused ? '일시정지됨' : '가동 중';
        sw.title = paused ? '누르면 자동매매를 다시 켜요' : '누르면 자동매매를 잠시 멈춰요';
    }
    $('au-switch').addEventListener('click', async e => {
        const sw = e.currentTarget;
        sw.disabled = true;
        try {
            const res = await fetch('auto_trade_toggle.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': CSRF },
                body: 'csrf_token=' + encodeURIComponent(CSRF) + '&paused=' + (paused ? '0' : '1'),
            });
            const j = await res.json();
            if (j.error === 'login') { location.href = 'login.php?next=auto_trade.php'; return; }
            if (j.error === 'csrf') { toast('보안 토큰이 만료됐어요. 새로고침 후 다시 해 주세요.'); return; }
            if (j.ok) { paused = !!j.paused; renderSwitch(); toast(paused ? '자동매매를 잠시 멈췄어요' : '자동매매를 다시 켰어요'); }
            else toast('바꾸지 못했어요. 잠시 후 다시 해 주세요.');
        } catch (err) {
            toast('바꾸지 못했어요. 잠시 후 다시 해 주세요.');   // 실패하면 상태를 바꾸지 않는다(화면과 DB가 어긋나지 않게)
        } finally { sw.disabled = false; }
    });

    // ================= 실시간 시세 반영 =================
    let PF = INITIAL.portfolio, TRADES = INITIAL.trades, hiddenEval = 0;
    const livePrice = new Map();                 // 종목코드 → 실시간 현재가
    function adoptPortfolio(p) {                 // 서버에서 새로 받을 때마다 기준값 재설정 + 받아둔 실시간가 반영
        PF = p;
        hiddenEval = Math.max(0, (p.total_eval || 0) - (p.shown_eval || 0));
        applyLive();
    }
    function applyLive() {                       // 실시간가로 평가금액·비중·수익률·총평가 재계산
        let shown = 0;
        PF.items.forEach(it => {
            const lp = livePrice.get(it.stock_code);
            if (lp > 0) it.price = lp;
            it.eval_amount = it.price * it.quantity;
            it.profit_amount = (it.price - it.avg_price) * it.quantity;
            it.profit_rate = it.avg_price > 0 ? Math.round((it.price - it.avg_price) / it.avg_price * 10000) / 100 : 0;
            shown += it.eval_amount;
        });
        const total = shown + hiddenEval;         // 비중 분모 = 상위5 실시간 평가금액 + 상위5 밖 종목(서버값)
        PF.items.forEach(it => { it.weight = total > 0 ? Math.round(it.eval_amount / total * 1000) / 10 : 0; });
        PF.shown_eval = shown; PF.total_eval = total;
        PF.shown_ratio = total > 0 ? Math.round(shown / total * 1000) / 10 : 0;
    }
    function updateBuyRates() {                   // 구매 행 수익률 = 산 가격 대비 지금 가격
        TRADES.forEach(t => {
            if (t.order_type !== 'buy') return;
            const lp = livePrice.get(t.stock_code);
            if (lp > 0 && t.price > 0) t.rate = Math.round((lp - t.price) / t.price * 10000) / 100;
        });
        mergeTrades(TRADES);
    }
    let queued = false;
    function scheduleRender() {                  // 실시간은 초당 여러 건 → 0.4초 단위로 묶어서 그린다
        if (queued) return; queued = true;
        setTimeout(() => {
            queued = false;
            applyLive(); renderKpis(PF); renderDonut(PF); renderList(PF); updateBuyRates();
            const live = $('au-live'); live.classList.add('on'); clearTimeout(live._t); live._t = setTimeout(() => live.classList.remove('on'), 4000);
        }, 400);
    }
    let tries = 0;
    (function connect() {
        let ws;
        try { ws = new WebSocket((location.protocol === 'https:' ? 'wss://' : 'ws://') + location.host + '/ws/'); } catch (e) { return; }
        ws.onopen = () => { tries = 0; };
        ws.onmessage = ev => {
            let d; try { d = JSON.parse(ev.data); } catch (e) { return; }
            if (d.type !== 'price' || !d.stock_code || !(d.price > 0)) return;
            if (!PF.items.some(i => i.stock_code === d.stock_code)) return;   // 내 상위 보유 종목만
            livePrice.set(d.stock_code, Number(d.price));
            scheduleRender();
        };
        ws.onclose = () => { tries++; setTimeout(connect, Math.min(15000, 1000 * 2 ** Math.min(tries - 1, 4))); };   // 끊기면 자동 재연결
    })();

    // ================= 5초 폴링 (새 거래 · 보유 변화 · 다른 탭에서 바꾼 스위치) =================
    async function poll() {
        try {
            const res = await fetch('get_auto_trades.php', { cache: 'no-store' });
            if (res.status === 401) { location.href = 'login.php?next=auto_trade.php'; return; }
            const d = await res.json();
            if (d.error) return;
            cash = d.cash;
            adoptPortfolio(d.portfolio);
            TRADES = d.trades;
            renderKpis(PF); renderDonut(PF); renderList(PF); updateBuyRates();
            if (paused !== !!d.paused) { paused = !!d.paused; renderSwitch(); }
        } catch (err) { /* 폴링 실패는 조용히 넘긴다 — 다음 주기에 다시 */ }
    }

    adoptPortfolio(INITIAL.portfolio);
    renderKpis(PF); renderDonut(PF); renderList(PF); renderTrades(TRADES); renderSwitch();
    setInterval(() => { if (!document.hidden) poll(); }, 5000);   // 다른 탭을 보고 있을 땐 쉰다
    document.addEventListener('visibilitychange', () => { if (!document.hidden) poll(); });
})();
</script>

<?php include 'includes/footer.php'; ?>
