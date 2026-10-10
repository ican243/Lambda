<?php
require_once 'func.php';
/** @var mysqli $conn */   // config/db.php 에서 넘어옴 (에디터 자동완성·오탐 방지용)
startUserSession();

if (!isLoggedIn()) {
    header('Location: login.php?next=trade.php');
    exit;
}

$uid      = $_SESSION['user_id'];
$account  = getMyAccount($conn, $uid);
$holdings = getMyHoldings($conn, $uid);
$orders   = getMyOrders($conn, $uid);
$initial  = getInitialCashAmount($conn);   // 시작 금액 (관리자 설정, 기본 1,000만원)

// 화면에 넘길 데이터 — 계산과 그리기는 아래 JS 가 한 곳에서 한다 (30초 갱신·실시간 시세도 같은 함수로)
$holdJs = array_map(fn($h) => [
    'code'     => $h['stock_code'],
    'name'     => $h['stock_name'],
    'qty'      => (int) $h['quantity'],
    'avg'      => (float) $h['avg_price'],
    'price'    => $h['current_price'] ? (int) $h['current_price'] : null,
    'price_at' => $h['price_at'],
], $holdings);
$orderJs = array_map(fn($o) => [
    'code'  => $o['stock_code'],
    'name'  => $o['stock_name'],
    'type'  => $o['order_type'],
    'qty'   => (int) $o['quantity'],
    'price' => (float) $o['price'],
    'total' => (int) $o['total_amount'],
    'at'    => $o['created_at'],
    'auto'  => (int) ($o['is_auto'] ?? 0),
], $orders);

$pageWide  = true;
$pageTitle = '내 계좌';
include 'includes/header.php';
?>

<div class="acct" id="acct" data-mtab="hold">
    <div class="acct-title">
        <h1>내 계좌</h1>
        <span><?= htmlspecialchars($_SESSION['nickname'] ?? '') ?>님</span>
    </div>

    <?php if (isset($_GET['error'])): ?>
        <div class="acct-alert err">⚠️ <?= htmlspecialchars($_GET['error']) ?></div>
    <?php endif; ?>
    <?php if (isset($_GET['success'])): ?>
        <div class="acct-alert ok">✅ <?= htmlspecialchars($_GET['success']) ?></div>
    <?php endif; ?>

    <!-- ===== 총 자산 ===== -->
    <section class="acct-hero">
        <div class="acct-hero-k">총 자산 <span class="sd-live" id="acct-live"><i></i>실시간</span></div>
        <div class="acct-total" id="acct-total">—</div>
        <div class="acct-total-sub"><span class="lbl">시작 금액 <?= number_format($initial / 10000) ?>만원보다</span><span id="acct-vs-start">—</span></div>

        <div class="acct-mix">
            <div class="acct-mix-bar"><span class="stock" id="acct-mix-stock" style="width:0%"></span><span class="cash"></span></div>
            <div class="acct-mix-legend">
                <span><i style="background:var(--primary)"></i>주식 <b id="acct-mix-sp">0%</b></span>
                <span><i style="background:#c9e2ff"></i>현금 <b id="acct-mix-cp">100%</b></span>
            </div>
        </div>

        <div class="acct-kpis">
            <div class="acct-kpi"><div class="k">주문 가능 현금</div><div class="v" id="kpi-cash">—</div></div>
            <div class="acct-kpi"><div class="k">주식 평가금액</div><div class="v" id="kpi-stock">—</div><div class="s flat" id="kpi-cost">—</div></div>
            <div class="acct-kpi"><div class="k">투자 손익</div><div class="v" id="kpi-pl">—</div><div class="s" id="kpi-plr">—</div></div>
        </div>
    </section>

    <!-- 휴대폰: 보유 종목 ↔ 주문 내역 -->
    <div class="acct-mtabs" role="tablist">
        <button type="button" class="on" data-mtab="hold">보유 종목</button>
        <button type="button" data-mtab="orders">주문 내역</button>
    </div>

    <div class="acct-layout">
        <!-- ===== 보유 종목 ===== -->
        <section class="acct-card acct-holds">
            <div class="acct-card-h">
                <span class="acct-card-t">보유 종목 <small id="hold-count"></small></span>
                <div class="acct-chips" id="hold-sort">
                    <button type="button" class="on" data-sort="value">평가금액순</button>
                    <button type="button" data-sort="rate">수익률순</button>
                    <button type="button" data-sort="name">이름순</button>
                </div>
            </div>
            <div id="hold-list"></div>
        </section>

        <!-- ===== 주문 내역 ===== -->
        <section class="acct-card acct-orders">
            <div class="acct-card-h">
                <span class="acct-card-t">주문 내역</span>
                <div class="acct-chips" id="ord-filter">
                    <button type="button" class="on" data-f="all">전체</button>
                    <button type="button" data-f="buy">구매</button>
                    <button type="button" data-f="sell">판매</button>
                </div>
            </div>
            <div class="acct-scroll" id="ord-list"></div>
        </section>
    </div>
</div>

<script>
(function () {
    'use strict';
    const INITIAL = <?= (int) $initial ?>;
    let cash      = <?= (int) $account['cash_balance'] ?>;
    let holds     = <?= json_encode($holdJs, JSON_UNESCAPED_UNICODE) ?>;
    const orders  = <?= json_encode($orderJs, JSON_UNESCAPED_UNICODE) ?>;

    const $ = id => document.getElementById(id);
    const num  = n => Math.round(Number(n) || 0).toLocaleString('ko-KR');
    const won  = n => num(n) + '원';
    const sign = n => (n > 0 ? '+' : '');
    const cls  = n => n > 0 ? 'up' : (n < 0 ? 'down' : 'flat');
    const esc  = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const plTxt = (pl, rate) => `${sign(pl)}${won(pl)} (${sign(rate)}${rate.toFixed(2)}%)`;
    function flash(el, up) {
        if (!el) return;
        el.classList.remove('sd-flash-up', 'sd-flash-down');
        void el.offsetWidth;                         // 애니메이션을 처음부터 다시 재생시키는 요령
        el.classList.add(up ? 'sd-flash-up' : 'sd-flash-down');
    }

    // 종목 하나의 숫자 (가격이 없으면 평균단가로 계산 → 손익 0 으로 보이게)
    function calc(h) {
        const p = h.price || h.avg;
        const value = p * h.qty, cost = h.avg * h.qty, pl = value - cost;
        return { value, cost, pl, rate: cost ? pl / cost * 100 : 0 };
    }

    // ================= 총 자산 =================
    function renderSummary() {
        let stock = 0, cost = 0;
        holds.forEach(h => { const c = calc(h); stock += c.value; cost += c.cost; });
        const total = cash + stock, pl = stock - cost, plr = cost ? pl / cost * 100 : 0;
        const vs = total - INITIAL, vsr = INITIAL ? vs / INITIAL * 100 : 0;

        const t = $('acct-total'), before = t.dataset.v ? Number(t.dataset.v) : null;
        t.textContent = won(total); t.dataset.v = Math.round(total);
        if (before !== null && Math.round(total) !== before) flash(t, total > before);

        $('acct-vs-start').textContent = plTxt(vs, vsr); $('acct-vs-start').className = cls(Math.round(vs));
        const sp = total ? Math.round(stock / total * 100) : 0;
        $('acct-mix-stock').style.width = sp + '%';
        $('acct-mix-sp').textContent = sp + '%'; $('acct-mix-cp').textContent = (100 - sp) + '%';

        $('kpi-cash').textContent = won(cash);
        $('kpi-stock').textContent = won(stock);
        $('kpi-cost').innerHTML = `투자 원금 <span class="nw">${won(cost)}</span>`;
        $('kpi-pl').textContent = sign(pl) + won(pl); $('kpi-pl').className = 'v ' + cls(Math.round(pl));
        $('kpi-plr').textContent = sign(plr) + plr.toFixed(2) + '%'; $('kpi-plr').className = 's ' + cls(Math.round(pl));
    }

    // ================= 보유 종목 =================
    let sortBy = 'value';
    function logo(code, name) {
        let hash = 0; for (const ch of code) hash = (hash * 31 + ch.charCodeAt(0)) >>> 0;
        const img = window.HAS_LOGO && HAS_LOGO.has(code) ? `<img src="assets/logos/${esc(code)}.png" alt="" onerror="this.remove()">` : '';   // 있는 로고만 요청
        return `<span class="acct-logo" style="background:hsl(${hash % 360},62%,62%)">${img}${esc(name.slice(0, 1))}</span>`;
    }
    function renderHolds() {
        $('hold-count').textContent = holds.length || '';
        const box = $('hold-list');
        if (!holds.length) {
            box.innerHTML = `<div class="acct-empty"><div class="ico">🌱</div><div class="tt">아직 산 주식이 없어요</div>`
                + `<div class="ds">관심 있는 종목을 골라 첫 주식을 사 보세요.</div><a href="index.php">주식 둘러보기</a></div>`;
            return;
        }
        const rows = holds.map(h => ({ h, c: calc(h) }));
        rows.sort((a, b) => sortBy === 'rate' ? b.c.rate - a.c.rate
                          : sortBy === 'name' ? a.h.name.localeCompare(b.h.name, 'ko')
                          : b.c.value - a.c.value);
        let cost = 0, value = 0;
        box.innerHTML = rows.map(({ h, c }) => {
            cost += c.cost; value += c.value;
            return `<a class="acct-row" href="stock_detail.php?code=${esc(h.code)}" data-code="${esc(h.code)}">`
                + logo(h.code, h.name)
                + `<div class="acct-name"><div class="nm">${esc(h.name)}</div><div class="sub">${num(h.qty)}주 · 평균 <span class="nw">${won(h.avg)}</span></div></div>`
                + `<div class="acct-val"><div class="v">${won(c.value)}</div><div class="pl ${cls(Math.round(c.pl))}">${plTxt(c.pl, c.rate)}</div></div></a>`;
        }).join('')
        + `<div class="acct-foot"><span>현재가 기준 평가금액</span><b>${won(value)}</b></div>`;
    }
    $('hold-sort').addEventListener('click', e => {
        const b = e.target.closest('button[data-sort]'); if (!b) return;
        sortBy = b.dataset.sort;
        document.querySelectorAll('#hold-sort button').forEach(x => x.classList.toggle('on', x === b));
        renderHolds();
    });

    // ================= 주문 내역 (날짜별 묶음 · 필터 · 더 보기) =================
    const PAGE = 20;
    let filter = 'all', shown = PAGE;
    const WEEK = ['일', '월', '화', '수', '목', '금', '토'];
    function dayLabel(d) {                         // '2026-10-10' → '10월 10일 (토)', 오늘·어제는 말로
        const today = new Date(Date.now() + 9 * 3600e3).toISOString().slice(0, 10);
        const yest  = new Date(Date.now() + 9 * 3600e3 - 86400e3).toISOString().slice(0, 10);
        if (d === today) return '오늘';
        if (d === yest) return '어제';
        const dt = new Date(d + 'T00:00:00Z');
        return `${dt.getUTCMonth() + 1}월 ${dt.getUTCDate()}일 (${WEEK[dt.getUTCDay()]})`;
    }
    function renderOrders() {
        const box = $('ord-list');
        const list = orders.filter(o => filter === 'all' || o.type === filter);
        if (!list.length) {
            box.innerHTML = `<div class="acct-empty"><div class="ico">🧾</div><div class="tt">${filter === 'all' ? '아직 주문 내역이 없어요' : (filter === 'buy' ? '구매' : '판매') + ' 내역이 없어요'}</div>`
                + `<div class="ds">주식을 사고팔면 여기에 기록돼요.</div></div>`;
            return;
        }
        let html = '', lastDay = '';
        list.slice(0, shown).forEach(o => {
            const day = (o.at || '').slice(0, 10);
            if (day !== lastDay) { html += `<div class="acct-day">${dayLabel(day)}</div>`; lastDay = day; }
            const buy = o.type === 'buy';
            html += `<a class="acct-ord" href="stock_detail.php?code=${esc(o.code)}">`
                + `<span class="acct-tag ${o.type}">${buy ? '구매' : '판매'}</span>`
                + `<div style="min-width:0"><div class="nm">${esc(o.name)}${o.auto ? '<span class="acct-auto">자동</span>' : ''}</div>`
                + `<div class="sub">${(o.at || '').slice(11, 16)} · ${num(o.qty)}주 · ${won(o.price)}</div></div>`
                + `<div class="amt">${won(o.total)}</div></a>`;
        });
        if (list.length > shown) html += `<button type="button" class="acct-more" id="ord-more">더 보기 (${num(list.length - shown)}건 남음)</button>`;
        box.innerHTML = html;
        $('ord-more')?.addEventListener('click', () => { shown += PAGE; renderOrders(); });
    }
    $('ord-filter').addEventListener('click', e => {
        const b = e.target.closest('button[data-f]'); if (!b) return;
        filter = b.dataset.f; shown = PAGE;
        document.querySelectorAll('#ord-filter button').forEach(x => x.classList.toggle('on', x === b));
        renderOrders();
    });

    // 휴대폰 탭
    document.querySelector('.acct-mtabs').addEventListener('click', e => {
        const b = e.target.closest('button[data-mtab]'); if (!b) return;
        $('acct').dataset.mtab = b.dataset.mtab;
        document.querySelectorAll('.acct-mtabs button').forEach(x => x.classList.toggle('on', x === b));
    });

    // ================= 가격 갱신 =================
    // 1) 실시간: 홈 화면 종목은 실시간 서버가 계속 보내 준다 → 내 보유 종목이면 바로 반영
    // 2) 30초마다: 나머지 보유 종목 가격 + 현금을 다시 받아온다 (메인 화면과 같은 방식)
    function applyPrice(code, price) {
        const h = holds.find(x => x.code === code);
        if (!h || !price || h.price === price) return;
        const up = price > (h.price || 0);
        h.price = price;
        h.price_at = new Date(Date.now() + 9 * 3600e3).toISOString().slice(0, 19).replace('T', ' ');
        renderHolds(); renderSummary();
        flash(document.querySelector(`.acct-row[data-code="${code}"] .v`), up);
        const live = $('acct-live'); live.classList.add('on'); clearTimeout(live._t);
        live._t = setTimeout(() => live.classList.remove('on'), 4000);
    }
    async function poll() {
        try {
            const res = await fetch('get_holdings.php');
            if (res.status === 401) { location.href = 'login.php?next=trade.php'; return; }
            if (!res.ok) return;
            const d = await res.json();
            cash = d.cash;
            const old = Object.fromEntries(holds.map(h => [h.code, h.price]));
            holds = d.holdings;
            renderHolds(); renderSummary();
            holds.forEach(h => {
                if (old[h.code] && h.price && h.price !== old[h.code]) flash(document.querySelector(`.acct-row[data-code="${h.code}"] .v`), h.price > old[h.code]);
            });
        } catch (e) { /* 네트워크 일시 오류는 다음 주기에 다시 */ }
    }
    setInterval(() => { if (!document.hidden) poll(); }, 30000);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) poll(); });   // 다른 탭 갔다 오면 바로 최신화

    let tries = 0;
    (function connect() {
        let ws;
        try { ws = new WebSocket((location.protocol === 'https:' ? 'wss://' : 'ws://') + location.host + '/ws/'); }
        catch (e) { return; }
        ws.onopen = () => { tries = 0; };
        ws.onmessage = ev => {
            let d; try { d = JSON.parse(ev.data); } catch (e) { return; }
            if (d.type && d.type !== 'price') return;          // 호가·체결 메시지는 이 화면에서 안 씀
            applyPrice(d.stock_code, d.price);
        };
        ws.onclose = () => { tries++; setTimeout(connect, Math.min(15000, 1000 * 2 ** Math.min(tries - 1, 4))); };   // 끊기면 자동 재연결
    })();

    renderSummary();
    renderHolds();
    renderOrders();
})();
</script>

<?php include 'includes/footer.php'; ?>
