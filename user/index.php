<?php
require_once 'func.php';
/** @var mysqli $conn */   // config/db.php 에서 넘어옴 (에디터 자동완성·오탐 방지용)
startUserSession();

// 로그인 안 해도 볼 수 있는 공개 홈. (매수/매도/관심등록만 로그인 요구)
$loggedIn = isLoggedIn();

// 초기 데이터를 서버가 미리 만들어 HTML 안에 심는다 → 첫 화면 왕복 0
$popular    = getPopularStocks($conn, 100);
$mostViewed = getMostViewedStocks($conn, 30);
$indices    = getIndexSummary($conn, 30);
$myWatch    = $loggedIn ? getMyWatchlistPrices($conn, $_SESSION['user_id']) : [];

// 로그인 사용자의 '내 투자' 요약 (총 자산 = 현금 + 보유주식 평가액)
$my = null;
if ($loggedIn) {
    $cash = (int) getMyAccount($conn, $_SESSION['user_id'])['cash_balance'];
    $stock = 0; $cost = 0;
    foreach (getMyHoldings($conn, $_SESSION['user_id']) as $h) {
        $p = $h['current_price'] ?: $h['avg_price'];
        $stock += $p * $h['quantity'];
        $cost  += $h['avg_price'] * $h['quantity'];
    }
    $my = ['cash' => $cash, 'stock' => (int) round($stock), 'cost' => (int) round($cost), 'initial' => getInitialCashAmount($conn)];
}

$pageWide = true;
include 'includes/header.php';
?>

<div class="hm">
    <div class="hm-head">
        <h1>오늘의 시장</h1>
        <span class="hm-status" id="hm-status"><i></i><span></span></span>
    </div>

    <!-- ===== 지수 카드 ===== -->
    <?php if ($indices): ?>
        <div class="hm-indices" id="hm-indices"></div>
    <?php endif; ?>

    <div class="hm-layout">
        <!-- ===================== 왼쪽: 실시간 순위 ===================== -->
        <div class="hm-main">
            <section class="hm-card hm-rankcard" id="ranking">
                <div class="hm-card-h">
                    <span class="hm-card-t">실시간 순위</span>
                    <span class="hm-time" id="hm-time"></span>
                </div>
                <div class="hm-tabs" id="hm-tabs" role="tablist">
                    <button type="button" class="on" data-sort="value">거래대금</button>
                    <button type="button" data-sort="volume">거래량</button>
                    <button type="button" data-sort="up">급상승</button>
                    <button type="button" data-sort="down">급하락</button>
                    <button type="button" data-sort="views">많이 본</button>
                </div>
                <div class="hm-th"><span>순위</span><span>종목</span><span>현재가</span><span>등락률</span><span id="hm-ex-h">거래대금</span></div>
                <div id="hm-list"></div>
            </section>
        </div>

        <!-- ===================== 오른쪽 ===================== -->
        <aside class="hm-side">
            <?php if ($my): ?>
                <a class="hm-card hm-my" href="trade.php" style="display:block;text-decoration:none;color:inherit;">
                    <div class="hm-card-h" style="margin-bottom:0;">
                        <span class="k">내 총 자산</span>
                        <span class="hm-link">내 계좌 ›</span>
                    </div>
                    <div class="total" id="my-total">—</div>
                    <div class="pl" id="my-pl">—</div>
                    <div class="hm-my-rows">
                        <div><span>주문 가능 현금</span><b id="my-cash">—</b></div>
                        <div><span>주식 평가금액</span><b id="my-stock">—</b></div>
                    </div>
                </a>
            <?php else: ?>
                <section class="hm-card hm-cta hm-my">
                    <div class="t">1,000만원으로<br>모의투자를 시작해 보세요</div>
                    <div class="d">진짜 시세로 사고팔아 보면서, 실제 돈은 하나도 잃지 않아요.</div>
                    <!-- 가입 없이 바로: 모두가 같이 쓰는 체험 계정으로 로그인 (demo_login.php) -->
                    <form action="demo_login.php" method="POST" style="margin:0;">
                        <?= csrfField() ?>
                        <button type="submit" class="hm-cta-btn">로그인 없이 둘러보기</button>
                    </form>
                    <a href="login.php" class="hm-cta-sub">로그인 · 회원가입</a>
                </section>
            <?php endif; ?>

            <section class="hm-card hm-watch">
                <div class="hm-card-h">
                    <span class="hm-card-t">관심 종목 <small id="watch-count"></small></span>
                </div>
                <div id="watch-list"></div>
            </section>
        </aside>
    </div>
</div>

<script>
(function () {
    'use strict';
    const LOGGED  = <?= $loggedIn ? 'true' : 'false' ?>;
    let stocks     = <?= json_encode($popular, JSON_UNESCAPED_UNICODE) ?>;
    let mostViewed = <?= json_encode($mostViewed, JSON_UNESCAPED_UNICODE) ?>;
    let myWatch    = <?= json_encode($myWatch, JSON_UNESCAPED_UNICODE) ?>;
    const indices  = <?= json_encode($indices, JSON_UNESCAPED_UNICODE) ?>;
    const MY       = <?= json_encode($my) ?>;

    const $ = id => document.getElementById(id);
    const num  = n => Math.round(Number(n) || 0).toLocaleString('ko-KR');
    const won  = n => num(n) + '원';
    const sign = n => (n > 0 ? '+' : '');
    const cls  = n => (n > 0 ? 'up' : (n < 0 ? 'down' : 'flat'));
    const esc  = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const rateTxt = r => `${sign(r)}${Number(r || 0).toFixed(2)}%`;
    const kstNow  = () => new Date(Date.now() + 9 * 3600e3);          // getUTC* 로 읽으면 한국 시각
    function moneyFmt(v) {                                              // 원 → 조/억/만
        v = Number(v) || 0;
        const jo = Math.floor(v / 1e12), eok = Math.floor((v % 1e12) / 1e8);
        if (jo > 0) return jo + '조 ' + num(eok) + '억';
        if (eok > 0) return num(eok) + '억';
        return num(Math.floor(v / 1e4)) + '만';
    }
    const volFmt = v => (v = Number(v) || 0) >= 1e4 ? num(Math.floor(v / 1e4)) + '만 주' : num(v) + '주';
    function flash(el, up) {
        if (!el) return;
        el.classList.remove('sd-flash-up', 'sd-flash-down');
        void el.offsetWidth;                         // 애니메이션을 처음부터 다시 재생시키는 요령
        el.classList.add(up ? 'sd-flash-up' : 'sd-flash-down');
    }
    function logo(code, name) {
        let h = 0; for (const ch of String(code)) h = (h * 31 + ch.charCodeAt(0)) >>> 0;
        // 로고 그림은 실제로 있는 종목만 요청한다 (없는 그림을 부르면 404 요청이 쌓임) — 목록은 header.php 의 HAS_LOGO
        const img = window.HAS_LOGO && HAS_LOGO.has(code) ? `<img src="assets/logos/${esc(code)}.png" alt="" loading="lazy" onload="this.classList.add('ok')" onerror="this.remove()">` : '';
        return `<span class="hm-logo" style="background:hsl(${h % 360},62%,62%)">${img}${esc(String(name || '?').trim().charAt(0))}</span>`;
    }

    // ================= 장 상태 =================
    function renderStatus() {
        const t = kstNow(), day = t.getUTCDay(), m = t.getUTCHours() * 60 + t.getUTCMinutes();
        let txt, open = false;
        if (day === 0 || day === 6) txt = '오늘은 휴장일';
        else if (m < 9 * 60) txt = '장 시작 전 · 9시 개장';
        else if (m < 15 * 60 + 30) { txt = '장 열림 · 15:30 마감'; open = true; }
        else txt = '장 마감';
        $('hm-status').className = 'hm-status' + (open ? ' open' : '');
        $('hm-status').querySelector('span').textContent = txt;

        // 'HH:MM 기준'은 시세가 오늘 것일 때만 보여준다 (오래된 시세에 지금 시각을 붙이면 거짓말이 되므로)
        const latest = stocks.map(s => s.updated_at || '').sort().pop();
        const fresh = latest && latest.slice(0, 10) >= t.toISOString().slice(0, 10);
        $('hm-time').textContent = fresh ? `${String(t.getUTCHours()).padStart(2, '0')}:${String(t.getUTCMinutes()).padStart(2, '0')} 기준` : '';
    }

    // ================= 지수 카드 (작은 선 차트 포함) =================
    function spark(series, color) {
        const w = 96, h = 44, min = Math.min(...series), max = Math.max(...series), span = max - min || 1;
        const pts = series.map((v, i) => `${(i / (series.length - 1) * w).toFixed(1)},${(h - 3 - (v - min) / span * (h - 6)).toFixed(1)}`).join(' ');
        return `<svg class="hm-spark" viewBox="0 0 ${w} ${h}" preserveAspectRatio="none" aria-hidden="true">`
             + `<polyline points="${pts}" fill="none" stroke="${color}" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke"/></svg>`;
    }
    function renderIndices() {
        const box = $('hm-indices'); if (!box) return;
        const css = getComputedStyle(document.documentElement);
        box.innerHTML = indices.map(ix => {
            const c = cls(ix.change), color = css.getPropertyValue(c === 'up' ? '--up' : c === 'down' ? '--down' : '--flat').trim();
            return `<div class="hm-index"><div class="info"><div class="nm">${esc(ix.name)}</div>`
                + `<div class="v">${Number(ix.close).toLocaleString('ko-KR', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</div>`
                + `<div class="c ${c}">${sign(ix.change)}${Number(ix.change).toFixed(2)} (${rateTxt(ix.rate)})</div></div>`
                + spark(ix.series, color) + `</div>`;
        }).join('');
    }

    // ================= 실시간 순위 =================
    let sortBy = 'value', shown = 20;
    const tv = s => (Number(s.trade_value) || 0) > 0 ? Number(s.trade_value) : Number(s.price) * Number(s.volume);
    function sorted() {
        if (sortBy === 'views') return [...mostViewed];
        const a = [...stocks];
        if (sortBy === 'up') a.sort((x, y) => y.change_rate - x.change_rate);
        else if (sortBy === 'down') a.sort((x, y) => x.change_rate - y.change_rate);
        else if (sortBy === 'volume') a.sort((x, y) => Number(y.volume) - Number(x.volume));
        else a.sort((x, y) => tv(y) - tv(x));
        return a;
    }
    function extra(s) {                                   // 마지막 칸: 탭에 따라 다른 정보
        if (sortBy === 'volume') return volFmt(s.volume);
        if (sortBy === 'views') return num(s.view_count) + '회';
        return moneyFmt(tv(s));
    }
    function renderList() {
        $('hm-ex-h').textContent = sortBy === 'volume' ? '거래량' : sortBy === 'views' ? '조회수' : '거래대금';
        const list = sorted();
        const box = $('hm-list');
        if (!list.length) {
            box.innerHTML = `<div class="hm-empty"><b>${sortBy === 'views' ? '아직 조회 기록이 없어요' : '시세 정보가 없어요'}</b>`
                + `${sortBy === 'views' ? '종목을 열어 본 사람이 생기면 여기에 모여요.' : '잠시 후 다시 확인해 주세요.'}</div>`;
            return;
        }
        box.innerHTML = list.slice(0, shown).map((s, i) => {
            const c = cls(Number(s.change_price));
            return `<a class="hm-tr" href="stock_detail.php?code=${esc(s.stock_code)}" data-code="${esc(s.stock_code)}">`
                + `<span class="hm-rank${i < 3 ? ' top' : ''}">${i + 1}</span>`
                + `<span class="hm-stock">${logo(s.stock_code, s.stock_name)}<span style="min-width:0"><div class="nm">${esc(s.stock_name)}</div>`
                + `<div class="sub">${esc(s.market || s.stock_code)}</div></span></span>`
                + `<span class="hm-px">${s.price ? won(s.price) : '—'}</span>`
                + `<span class="hm-rt ${c}">${rateTxt(s.change_rate)}</span>`
                + `<span class="hm-ex">${extra(s)}</span></a>`;
        }).join('')
        + (list.length > shown ? `<button type="button" class="hm-more" id="hm-more">더 보기</button>` : '');
        $('hm-more')?.addEventListener('click', () => { shown += 20; renderList(); });
    }
    $('hm-tabs').addEventListener('click', e => {
        const b = e.target.closest('button[data-sort]'); if (!b) return;
        sortBy = b.dataset.sort; shown = 20;
        document.querySelectorAll('#hm-tabs button').forEach(x => x.classList.toggle('on', x === b));
        renderList();
    });

    // ================= 오른쪽: 내 투자 · 관심종목 =================
    function renderMy() {
        if (!MY) return;
        const total = MY.cash + MY.stock, vs = total - MY.initial, vsr = MY.initial ? vs / MY.initial * 100 : 0;
        $('my-total').textContent = won(total);
        $('my-pl').textContent = `시작 금액보다 ${sign(vs)}${won(vs)} (${rateTxt(vsr)})`;
        $('my-pl').className = 'pl ' + cls(Math.round(vs));
        $('my-cash').textContent = won(MY.cash);
        $('my-stock').textContent = won(MY.stock);
    }
    function renderWatch() {
        const box = $('watch-list');
        $('watch-count').textContent = myWatch.length || '';
        if (!LOGGED) { box.innerHTML = `<div class="hm-empty"><b>로그인하면 보여요</b>관심 있는 종목을 모아 한눈에 볼 수 있어요.</div>`; return; }
        if (!myWatch.length) { box.innerHTML = `<div class="hm-empty"><b>관심 종목이 없어요</b>종목 화면에서 ☆를 누르면 여기에 모여요.</div>`; return; }
        box.innerHTML = myWatch.map(s => {
            const c = cls(Number(s.change_price));
            return `<a class="hm-wrow" href="stock_detail.php?code=${esc(s.stock_code)}" data-code="${esc(s.stock_code)}">${logo(s.stock_code, s.stock_name)}`
                + `<span class="nm">${esc(s.stock_name)}</span>`
                + `<span class="r"><div class="p">${s.price ? won(s.price) : '—'}</div><div class="c ${c}">${rateTxt(s.change_rate)}</div></span></a>`;
        }).join('');
    }

    function renderAll() { renderStatus(); renderList(); renderWatch(); }

    // ================= 갱신 =================
    // 30초마다 순위·조회수·관심종목을 다시 받아온다 (화면을 보고 있을 때만)
    async function poll() {
        try {
            const [m, v, w] = await Promise.all([
                fetch('get_market.php').then(r => r.ok ? r.json() : null),
                fetch('get_most_viewed.php').then(r => r.ok ? r.json() : null),
                LOGGED ? fetch('get_prices.php').then(r => r.ok ? r.json() : null) : null,
            ]);
            if (Array.isArray(m)) stocks = m;
            if (Array.isArray(v)) mostViewed = v;
            if (Array.isArray(w)) myWatch = w;
            renderAll();
        } catch (e) { /* 네트워크 일시 오류는 다음 주기에 다시 */ }
    }
    setInterval(() => { if (!document.hidden) poll(); }, 30000);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) poll(); });

    // 실시간: 가격 메시지가 오면 해당 종목 숫자만 바꾸고 반짝 (끊기면 자동 재연결)
    function applyPrice(d) {
        [stocks, mostViewed, myWatch].forEach(list => {
            const s = list.find(x => x.stock_code === d.stock_code);
            if (s) Object.assign(s, { price: d.price, change_price: d.change_price, change_rate: d.change_rate, volume: d.volume ?? s.volume });
        });
        const c = cls(Number(d.change_price));
        document.querySelectorAll(`.hm-tr[data-code="${d.stock_code}"]`).forEach(row => {
            const px = row.querySelector('.hm-px'), up = d.price > Number(px.textContent.replace(/[^0-9]/g, ''));
            px.textContent = won(d.price);
            const rt = row.querySelector('.hm-rt'); rt.textContent = rateTxt(d.change_rate); rt.className = 'hm-rt ' + c;
            flash(px, up);
        });
        document.querySelectorAll(`.hm-wrow[data-code="${d.stock_code}"]`).forEach(row => {
            const p = row.querySelector('.p'), up = d.price > Number(p.textContent.replace(/[^0-9]/g, ''));
            p.textContent = won(d.price);
            const cc = row.querySelector('.c'); cc.textContent = rateTxt(d.change_rate); cc.className = 'c ' + c;
            flash(p, up);
        });
    }
    let tries = 0;
    (function connect() {
        let ws;
        try { ws = new WebSocket((location.protocol === 'https:' ? 'wss://' : 'ws://') + location.host + '/ws/'); }
        catch (e) { return; }
        ws.onopen = () => { tries = 0; };
        ws.onmessage = ev => {
            let d; try { d = JSON.parse(ev.data); } catch (e) { return; }
            if (d.type && d.type !== 'price') return;          // 상세용 호가/체결 메시지는 홈에서 무시
            applyPrice(d);
        };
        ws.onclose = () => { tries++; setTimeout(connect, Math.min(15000, 1000 * 2 ** Math.min(tries - 1, 4))); };
    })();

    renderIndices();
    renderMy();
    renderAll();
    setInterval(renderStatus, 60000);   // 장 상태 문구는 1분마다
})();
</script>

<?php include 'includes/footer.php'; ?>
