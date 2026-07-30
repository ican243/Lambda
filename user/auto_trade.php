<?php
// user/auto_trade.php - 자동매매 (로그인 필수)
//
// 이번 단계는 "화면만". 실제 매매 로직은 다음 단계.
// 화면 구성 (형 손그림 last2.png 기준):
//   좌 : 보유주식 도넛차트 (상위 5개만, '기타'로 안 묶음, 조각 크기 = 평가금액)
//   우 : 상위 5종목 리스트 (내림차순) + 우상단 일시정지 버튼
//   하 : 거래내역 표 6컬럼 (종목명·종목코드·시간·가격·매수/매도·수익률), 최신 15건 FIFO
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

$pageClass = 'app-xwide';
include 'includes/header.php';
?>

<div style="margin: 12px 0 8px;">
    <a href="index.php" class="muted" style="text-decoration:none;font-size:15px;font-weight:600;">← 홈으로</a>
</div>

<div class="at-head">
    <div>
        <h2 style="font-size:24px;font-weight:700;margin:0;">자동매매</h2>
        <p class="muted" style="font-size:15px;margin:8px 0 0;">
            조건을 정해두면 알아서 사고팝니다 ·
            주문 가능 현금 <b style="color:var(--text);" id="at-cash"><?= number_format($initial['cash']) ?>원</b>
        </p>
    </div>
    <div class="at-status" id="at-status"><!-- JS: 가동중 / 일시정지 배지 --></div>
</div>

<!-- 아직 매매 로직이 없다는 안내 (로직 붙으면 이 줄만 지우면 됨) -->
<div class="at-notice">
    🤖 지금은 <b>화면</b>만 준비된 단계예요. 아래 차트·리스트·거래내역은 <b>실제 내 보유·거래 데이터</b>로 그려집니다.
    자동매매 조건 설정은 다음 단계에 붙습니다.
</div>

<div class="at-top">
    <!-- ① 도넛차트: 보유 상위 5종목 -->
    <div class="widget">
        <div class="widget-h">
            <span class="wt">보유주식 <span class="muted" style="font-weight:500;font-size:13px;">· 상위 5종목</span></span>
        </div>
        <div class="at-donut-body">
            <div class="at-donut-wrap">
                <canvas id="at-donut" aria-label="보유종목 비중 도넛차트" role="img"></canvas>
                <!-- 도넛 가운데 총 평가금액 -->
                <div class="at-donut-center" id="at-donut-center"></div>
            </div>
            <div class="at-donut-note muted" id="at-donut-note"></div>
        </div>
    </div>

    <!-- ② 상위 5종목 리스트 + 일시정지 -->
    <div class="widget">
        <div class="widget-h">
            <span class="wt">거래중 종목</span>
            <span class="wh-right">
                <button type="button" class="at-pause" id="at-pause">일시정지</button>
            </span>
        </div>
        <div class="at-list" id="at-list"></div>
    </div>
</div>

<!-- ③ 거래내역 (최신 15건) -->
<div class="widget" style="margin-top:20px;">
    <div class="widget-h">
        <span class="wt">거래내역 <span class="muted" style="font-weight:500;font-size:13px;">· 최신 15건</span></span>
        <span class="wh-right">
            <span class="live" id="at-live"><span class="blip"></span>실시간</span>
        </span>
    </div>
    <div class="at-table-wrap">
        <table class="at-table">
            <thead>
                <tr>
                    <th>종목명</th>
                    <th>종목코드</th>
                    <th>시간</th>
                    <th class="num">가격</th>
                    <th class="ctr">구분</th>
                    <th class="num">수익률</th>
                </tr>
            </thead>
            <tbody id="at-tbody"></tbody>
        </table>
    </div>
    <div class="at-table-foot muted">
        매도 행은 <b>실현수익률</b>(매도가 − 그 시점 평단), 매수 행은 <b>매수가 대비 현재가</b> 등락률입니다.
    </div>
</div>

<script src="assets/chart.umd.min.js"></script>
<script>
// =====================================================================
// 자동매매 화면
//   · 초기 데이터는 서버에서 인라인으로 받음(INITIAL)
//   · 이후 5초마다 get_auto_trades.php 폴링 → 거래내역/도넛 갱신
//   · 새 거래 행은 맨 위에 삽입 + 플래시, 15개 초과분은 맨 아래에서 제거(FIFO)
// =====================================================================
const INITIAL = <?= json_encode($initial, JSON_UNESCAPED_UNICODE) ?>;
const CSRF    = <?= json_encode(csrfToken()) ?>;   // AJAX POST에 함께 보낼 CSRF 토큰
const MAX_ROWS = 15;

// ---------- 공통 헬퍼 (홈 index.php와 같은 규칙) ----------
const won = n => Number(n).toLocaleString() + '원';
const logoText = name => (name || '?').trim().charAt(0);
function cls(v) { return v > 0 ? 'up' : (v < 0 ? 'down' : 'muted'); }
function rateText(v) {
    if (v === null || v === undefined) return '-';
    const n = Number(v);
    return (n > 0 ? '+' : '') + n.toFixed(2) + '%';
}
// 종목코드 해시 → 종목별 고유 파스텔 컬러 (실제 로고 없을 때의 시각 포인트)
function logoColor(code) {
    const s = String(code || '');
    let h = 0;
    for (let i = 0; i < s.length; i++) h = (h * 31 + s.charCodeAt(i)) >>> 0;
    const hue = h % 360;
    return { bg: `hsl(${hue} 70% 93%)`, fg: `hsl(${hue} 45% 40%)` };
}
function logoHTML(code, name) {
    const { bg, fg } = logoColor(code);
    return `<div class="stock-logo" style="background:${bg};color:${fg}">
        <img src="assets/logos/${code}.png" alt="" loading="lazy" onerror="this.remove()">
        <span>${logoText(name)}</span>
    </div>`;
}
// 조 / 억 단위로 축약 (총 평가금액 표기용)
function shortWon(n) {
    n = Number(n) || 0;
    if (n >= 1e12) return (n / 1e12).toFixed(2) + '조';
    if (n >= 1e8)  return Math.round(n / 1e8).toLocaleString() + '억';
    if (n >= 1e4)  return Math.round(n / 1e4).toLocaleString() + '만';
    return n.toLocaleString();
}
// '2026-07-30 14:23:05' → '14:23:05'
function timeOnly(dt) {
    const s = String(dt || '');
    return s.length >= 19 ? s.slice(11, 19) : s;
}
const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

// 도넛 조각 색 (토스 블루 계열 + 포인트) — 5개면 충분
const SLICE_COLORS = ['#3182f6', '#4dabf7', '#845ef7', '#ff922b', '#20c997'];

// ---------- ① 도넛차트 ----------
let donut = null;

function renderDonut(p) {
    const items = p.items || [];
    const center = document.getElementById('at-donut-center');
    const note = document.getElementById('at-donut-note');
    const canvas = document.getElementById('at-donut');

    if (!items.length) {
        if (donut) { donut.destroy(); donut = null; }
        canvas.style.display = 'none';
        center.innerHTML = '';
        note.innerHTML = `보유한 종목이 없어요.<br><a href="index.php#ranking" style="color:var(--primary);font-weight:600;">주식 골라보기 →</a>`;
        return;
    }
    canvas.style.display = '';

    center.innerHTML = `<div class="at-dc-label">총 평가금액</div>
        <div class="at-dc-value">${shortWon(p.total_eval)}원</div>`;

    // 상위 5개 합이 전체의 100%가 아니면(=6위 이하 존재) 솔직하게 알려준다
    note.innerHTML = (p.total_count > items.length)
        ? `보유 ${p.total_count}종목 중 상위 ${items.length}개 · 전체 평가금액의 <b>${p.shown_ratio}%</b>`
        : `보유 ${p.total_count}종목 전체`;

    const labels = items.map(i => i.stock_name);
    const data = items.map(i => i.eval_amount);

    // 데이터만 바뀐 경우엔 차트를 다시 만들지 않고 갱신(폴링마다 애니메이션 튀는 것 방지)
    if (donut && donut.data.labels.join('|') === labels.join('|')) {
        donut.data.datasets[0].data = data;
        donut._items = items;
        donut.update('none');       // 실시간 갱신은 애니메이션 없이 (0.4초마다 오므로 튀는 것 방지)
        return;
    }
    if (donut) donut.destroy();

    donut = new Chart(document.getElementById('at-donut'), {
        type: 'doughnut',
        data: {
            labels,
            datasets: [{
                data,
                backgroundColor: SLICE_COLORS.slice(0, items.length),
                borderColor: '#fff',
                borderWidth: 3,
                hoverOffset: 14,        // hover 시 조각이 튀어나옴 (형 요청)
                hoverBorderColor: '#fff'
            }]
        },
        options: {
            cutout: '58%',
            responsive: true,
            maintainAspectRatio: false,
            animation: { animateRotate: true, animateScale: true, duration: 900 },
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: 'rgba(25,31,40,.92)',
                    padding: 12,
                    displayColors: false,
                    titleFont: { family: 'Pretendard', size: 13, weight: '700' },
                    bodyFont: { family: 'Pretendard', size: 13 },
                    callbacks: {
                        label: (ctx) => {
                            const it = (donut._items || [])[ctx.dataIndex];
                            if (!it) return '';
                            return [`평가금액 ${won(Math.round(it.eval_amount))}`,
                                    `비중 ${it.weight}%`,
                                    `수익률 ${rateText(it.profit_rate)}`];
                        }
                    }
                }
            },
            // 조각 클릭 → 기존 종목상세로 라우팅
            onClick: (evt, els) => {
                if (!els.length) return;
                const it = (donut._items || [])[els[0].index];
                if (it) location.href = 'stock_detail.php?code=' + encodeURIComponent(it.stock_code);
            },
            onHover: (evt, els) => { evt.native.target.style.cursor = els.length ? 'pointer' : 'default'; }
        }
    });
    donut._items = items;
}

// ---------- ② 상위 5종목 리스트 ----------
function renderList(p) {
    const box = document.getElementById('at-list');
    const items = p.items || [];
    if (!items.length) {
        box.innerHTML = `<div class="at-empty muted">거래중인 종목이 없어요.</div>`;
        return;
    }
    box.innerHTML = items.map((it, i) => {
        const c = cls(it.profit_rate);
        return `
        <div class="at-row" data-code="${it.stock_code}">
            <div class="at-slice-dot" style="background:${SLICE_COLORS[i % SLICE_COLORS.length]}"></div>
            <a class="at-row-main" href="stock_detail.php?code=${it.stock_code}">
                ${logoHTML(it.stock_code, it.stock_name)}
                <div class="at-row-name">
                    <div class="nm">${esc(it.stock_name)}</div>
                    <div class="cd">비중 ${it.weight}% · ${it.quantity.toLocaleString()}주</div>
                </div>
                <div class="at-row-price">
                    <div class="pr">${won(Math.round(it.price))}</div>
                    <div class="rt ${c}">${rateText(it.profit_rate)}</div>
                </div>
            </a>
            <a class="at-trade-btn" href="stock_detail.php?code=${it.stock_code}#order">일반매매</a>
        </div>`;
    }).join('');
}

// ---------- ③ 거래내역 표 (최신 15건 FIFO) ----------
const seenIds = new Set();

function tradeRowHTML(t) {
    const isBuy = t.order_type === 'buy';
    const c = cls(t.rate);
    const badge = isBuy ? `<span class="at-tag buy">매수</span>` : `<span class="at-tag sell">매도</span>`;
    const auto = Number(t.is_auto) === 1 ? `<span class="at-auto-tag" title="자동매매가 낸 주문">자동</span>` : '';
    return `
        <td class="at-td-name">
            <a href="stock_detail.php?code=${t.stock_code}">${esc(t.stock_name)}</a>${auto}
        </td>
        <td class="muted">${esc(t.stock_code)}</td>
        <td class="muted">${timeOnly(t.created_at)}</td>
        <td class="num">${won(Math.round(t.price))}</td>
        <td class="ctr">${badge}</td>
        <td class="num rate ${c}">${rateText(t.rate)}</td>`;
}

// 전체 새로 그리기 (첫 로드)
function renderTrades(trades) {
    const tbody = document.getElementById('at-tbody');
    seenIds.clear();
    if (!trades.length) {
        tbody.innerHTML = `<tr class="at-empty-row"><td colspan="6" class="muted">아직 거래내역이 없어요.</td></tr>`;
        return;
    }
    tbody.innerHTML = trades.map(t => {
        seenIds.add(t.id);
        return `<tr data-id="${t.id}">${tradeRowHTML(t)}</tr>`;
    }).join('');
}

// 폴링 결과 반영: 새 거래만 맨 위에 삽입 + 플래시, 15개 넘으면 맨 아래 제거
function mergeTrades(trades) {
    const tbody = document.getElementById('at-tbody');
    if (!seenIds.size) { renderTrades(trades); return; }

    // 오래된 것 → 새것 순으로 넣어야 최신이 결국 맨 위에 온다
    const fresh = trades.filter(t => !seenIds.has(t.id)).reverse();

    if (fresh.length) {
        const empty = tbody.querySelector('.at-empty-row');
        if (empty) empty.remove();

        fresh.forEach(t => {
            const tr = document.createElement('tr');
            tr.dataset.id = t.id;
            tr.innerHTML = tradeRowHTML(t);
            tbody.insertBefore(tr, tbody.firstChild);
            seenIds.add(t.id);
            // 수익률 셀에 플래시 (홈 시세 갱신과 같은 효과)
            const cell = tr.querySelector('.rate');
            if (cell) cell.classList.add(Number(t.rate) >= 0 ? 'flash-up' : 'flash-down');
        });

        while (tbody.rows.length > MAX_ROWS) {
            const last = tbody.rows[tbody.rows.length - 1];
            seenIds.delete(Number(last.dataset.id));
            last.remove();
        }
    }

    // 기존 행의 수익률은 현재가에 따라 계속 움직인다(매수 행) → 값이 바뀐 것만 갱신
    trades.forEach(t => {
        const tr = tbody.querySelector(`tr[data-id="${t.id}"]`);
        if (!tr) return;
        const cell = tr.querySelector('.rate');
        if (!cell) return;
        const next = rateText(t.rate);
        if (cell.textContent !== next) {
            cell.textContent = next;
            cell.className = 'num rate ' + cls(t.rate);
        }
    });
}

// ---------- 일시정지 버튼 ----------
let paused = !!INITIAL.paused;

function renderPause() {
    const btn = document.getElementById('at-pause');
    const status = document.getElementById('at-status');
    btn.textContent = paused ? '재개하기' : '일시정지';
    btn.classList.toggle('resumed', paused);
    status.innerHTML = paused
        ? `<span class="at-badge off">⏸ 일시정지</span>`
        : `<span class="at-badge on">▶ 가동중</span>`;
}

document.getElementById('at-pause').addEventListener('click', async (e) => {
    const btn = e.currentTarget;
    btn.disabled = true;
    try {
        const res = await fetch('auto_trade_toggle.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': CSRF },
            body: 'csrf_token=' + encodeURIComponent(CSRF) + '&paused=' + (paused ? '0' : '1')
        });
        const j = await res.json();
        if (j.error === 'login') { location.href = 'login.php?next=auto_trade.php'; return; }
        if (j.ok) { paused = !!j.paused; renderPause(); }
    } catch (err) {
        // 네트워크 실패 시엔 상태를 바꾸지 않는다(화면과 DB가 어긋나는 것 방지)
    } finally {
        btn.disabled = false;
    }
});

// =====================================================================
// 실시간 (내 계좌와 같은 방식: stock-ws 웹소켓)
//   · 보유 상위5 종목의 체결이 올 때마다 현재가 → 평가금액 → 비중 → 수익률을 다시 계산
//   · 도넛 조각 크기도 같이 움직인다
//   · 거래내역의 '매수' 행 수익률(=매수가 대비 현재가)도 실시간으로 갱신
//   · DB 폴링(5초)은 새 거래·보유 변화용, 시세는 웹소켓이 담당
// =====================================================================
let PF = INITIAL.portfolio;          // 화면에 그려진 포트폴리오 (실시간으로 값이 갱신됨)
let TRADES = INITIAL.trades;
let hiddenEval = 0;                  // 상위5에 안 들어간 종목들의 평가금액 (비중 분모에 필요)
const livePrice = new Map();         // 종목코드 → 실시간 현재가

// 서버에서 포트폴리오를 새로 받을 때마다 기준값을 다시 잡는다
function adoptPortfolio(p) {
    PF = p;
    hiddenEval = Math.max(0, (p.total_eval || 0) - (p.shown_eval || 0));
    // 이미 받아둔 실시간가가 있으면 즉시 반영 (폴링 결과가 시세를 되돌리지 않게)
    applyLive();
}

// 실시간가를 반영해 파생값(평가금액·비중·수익률·총평가) 재계산
function applyLive() {
    let shown = 0;
    PF.items.forEach(it => {
        const lp = livePrice.get(it.stock_code);
        if (lp > 0) it.price = lp;
        it.eval_amount = it.price * it.quantity;
        it.profit_amount = (it.price - it.avg_price) * it.quantity;
        it.profit_rate = it.avg_price > 0
            ? Math.round((it.price - it.avg_price) / it.avg_price * 10000) / 100
            : 0;
        shown += it.eval_amount;
    });
    // 비중 분모 = 상위5 실시간 평가금액 + 상위5 밖 종목(시세 미구독이라 서버값 고정)
    const total = shown + hiddenEval;
    PF.items.forEach(it => {
        it.weight = total > 0 ? Math.round(it.eval_amount / total * 1000) / 10 : 0;
    });
    PF.shown_eval = shown;
    PF.total_eval = total;
    PF.shown_ratio = total > 0 ? Math.round(shown / total * 1000) / 10 : 0;
}

// 실시간은 초당 수십 건씩 오므로 렌더는 묶어서 처리 (도넛 재계산이 매 틱마다 돌면 낭비)
let renderQueued = false;
function scheduleRender() {
    if (renderQueued) return;
    renderQueued = true;
    setTimeout(() => {
        renderQueued = false;
        applyLive();
        renderDonut(PF);
        renderList(PF);
        updateBuyRates();
        blip();
    }, 400);
}

// 거래내역의 '매수' 행 수익률 = 매수가 대비 현재가 → 실시간가로 갱신
function updateBuyRates() {
    const tbody = document.getElementById('at-tbody');
    TRADES.forEach(t => {
        if (t.order_type !== 'buy') return;                 // 매도는 실현수익률이라 고정
        const lp = livePrice.get(t.stock_code);
        if (!(lp > 0) || !(t.price > 0)) return;
        t.rate = Math.round((lp - t.price) / t.price * 10000) / 100;
        const tr = tbody.querySelector(`tr[data-id="${t.id}"]`);
        if (!tr) return;
        const cell = tr.querySelector('.rate');
        if (!cell) return;
        const next = rateText(t.rate);
        if (cell.textContent !== next) {
            cell.textContent = next;
            cell.className = 'num rate ' + cls(t.rate);
        }
    });
}

function blip() {
    const dot = document.getElementById('at-live');
    if (!dot) return;
    dot.classList.add('on');
}

// ---------- 웹소켓 ----------
let sock = null;
let watching = '';          // 마지막으로 구독 요청한 종목코드 목록 (중복 요청 방지)

function currentCodes() { return (PF.items || []).map(i => i.stock_code); }

function sendWatch() {
    if (!sock || sock.readyState !== WebSocket.OPEN) return;
    const codes = currentCodes();
    const key = codes.join(',');
    if (key === watching) return;
    watching = key;
    // 가격만 필요하므로 watchPrices (호가 구독 슬롯을 쓰지 않음)
    sock.send(JSON.stringify({ action: 'watchPrices', codes }));
}

function connectWs() {
    try { sock = new WebSocket('ws://localhost:8080'); } catch (e) { return; }

    sock.onopen = () => { watching = ''; sendWatch(); };

    sock.onmessage = (event) => {
        let d;
        try { d = JSON.parse(event.data); } catch { return; }
        if (d.type !== 'price') return;                     // 체결내역·호가 메시지는 무시
        if (!d.stock_code || !(d.price > 0)) return;
        // 내 보유종목이 아니면 무시 (홈 종목들도 같은 소켓으로 흘러온다)
        if (!PF.items.some(i => i.stock_code === d.stock_code)) return;
        livePrice.set(d.stock_code, Number(d.price));
        scheduleRender();
    };

    sock.onclose = () => {
        document.getElementById('at-live').classList.remove('on');
        setTimeout(connectWs, 3000);                        // 끊기면 3초 후 재접속
    };
    sock.onerror = () => { document.getElementById('at-live').classList.remove('on'); };
}

// ---------- 폴링 (새 거래·보유 변화 감지) ----------
async function poll() {
    try {
        const res = await fetch('get_auto_trades.php', { cache: 'no-store' });
        if (res.status === 401) { location.href = 'login.php?next=auto_trade.php'; return; }
        const d = await res.json();
        if (d.error) return;

        adoptPortfolio(d.portfolio);
        TRADES = d.trades;
        renderDonut(PF);
        renderList(PF);
        mergeTrades(TRADES);
        updateBuyRates();
        sendWatch();                                        // 보유종목이 바뀌었으면 구독도 갱신
        document.getElementById('at-cash').textContent = Number(d.cash).toLocaleString() + '원';
        if (paused !== !!d.paused) { paused = !!d.paused; renderPause(); }   // 다른 탭에서 바꿨을 때 동기화
    } catch (err) {
        // 폴링 실패는 조용히 넘긴다 (시세는 웹소켓이 담당하므로 화면이 멈추지 않음)
    }
}

// ---------- 첫 렌더 ----------
adoptPortfolio(INITIAL.portfolio);
renderDonut(PF);
renderList(PF);
renderTrades(TRADES);
renderPause();
connectWs();
setInterval(poll, 5000);
</script>

<?php include 'includes/footer.php'; ?>
