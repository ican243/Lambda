<?php
require_once 'func.php';
startUserSession();

// 로그인 안 해도 볼 수 있는 공개 홈. (매수/매도/관심등록만 로그인 요구)
$loggedIn = isLoggedIn();

// 초기 데이터를 서버가 미리 만들어 HTML 안에 심는다 → 첫 화면 왕복 0
$popular = getPopularStocks($conn, 100);
$mostViewed = getMostViewedStocks($conn, 15);   // 우측 "지금 많이 봐요"
$myWatch = $loggedIn ? getMyWatchlistPrices($conn, $_SESSION['user_id']) : [];

$pageWide = true;
include 'includes/header.php';
?>

<div class="page-title">실시간 인기 종목</div>
<p class="page-sub">거래대금이 많은 순서예요. 종목을 눌러 차트를 확인해보세요.</p>

<!-- 시장 요약 스트립 (인기 상위 6종목) -->
<div class="market-strip" id="market-strip"></div>

<?php if ($loggedIn && $myWatch): ?>
<!-- 로그인 사용자의 관심종목 -->
<div class="section-title" style="margin-top:0;">내 관심종목</div>
<div class="stock-list" id="watch-list" style="margin-bottom:8px;"></div>
<?php endif; ?>

<div class="dash" id="ranking">
    <!-- 메인: 인기/급등/급락 순위 -->
    <div>
        <div class="tabs">
            <button data-sort="value" class="on">거래대금</button>
            <button data-sort="volume">거래량</button>
            <button data-sort="up">급상승</button>
            <button data-sort="down">급하락</button>
        </div>
        <div class="stock-list" id="main-list"></div>
    </div>

    <!-- 사이드: 실시간 랭킹 -->
    <aside class="side">
        <div class="side-head">
            <span class="t">🔥 지금 많이 봐요</span>
            <span class="time" id="side-time"></span>
        </div>
        <div class="stock-list" id="side-list"></div>
        <p class="side-note" id="side-note"></p>
    </aside>
</div>

<script>
    const isLoggedIn = <?= $loggedIn ? 'true' : 'false' ?>;
    let stocks = <?= json_encode($popular, JSON_UNESCAPED_UNICODE) ?>;
    let mostViewed = <?= json_encode($mostViewed, JSON_UNESCAPED_UNICODE) ?>;
    let myWatch = <?= json_encode($myWatch, JSON_UNESCAPED_UNICODE) ?>;
    let currentSort = 'value';

    // ---------- 공통 헬퍼 ----------
    const won = n => Number(n).toLocaleString() + '원';
    const logoText = name => (name || '?').trim().charAt(0);
    function cls(v) { return v > 0 ? 'up' : (v < 0 ? 'down' : 'muted'); }
    // 등락률만: 큰 헤더 등에서 사용
    function rateText(cp, cr) { return (cp > 0 ? '+' : '') + (cr ?? 0) + '%'; }
    // 등락 "금액(원) + 비율(%)" 동시 표기 → 초보자용. 예) -12,000원 (-6.88%)
    function changeText(cp, cr) {
        cp = Number(cp) || 0;
        const sign = cp > 0 ? '+' : '';   // 음수/0은 toLocaleString·값 자체에 부호 포함
        return `${sign}${cp.toLocaleString()}원 (${sign}${cr ?? 0}%)`;
    }
    // 종목코드 해시 → 종목별 고유 파스텔 컬러 (실제 로고 없을 때의 시각 포인트)
    function logoColor(code) {
        const s = String(code || '');
        let h = 0;
        for (let i = 0; i < s.length; i++) h = (h * 31 + s.charCodeAt(i)) >>> 0;
        const hue = h % 360;
        return { bg: `hsl(${hue} 70% 93%)`, fg: `hsl(${hue} 45% 40%)` };
    }
    // 로고 셀: 실제 이미지(assets/logos/{코드}.png) 있으면 표시, 없으면 파스텔 글자로 fallback
    function logoHTML(s) {
        const { bg, fg } = logoColor(s.stock_code);
        return `<div class="stock-logo" style="background:${bg};color:${fg}">
            <img src="assets/logos/${s.stock_code}.png" alt="" loading="lazy" onerror="this.remove()">
            <span>${logoText(s.stock_name)}</span>
        </div>`;
    }

    // ---------- 정렬 ----------
    // 거래대금은 실제 trade_value(누적거래대금)로 정렬 → 토스/한투와 순위 일치.
    // trade_value가 아직 0이면 price*volume 근사로 보조.
    function tradeValueOf(s) {
        const tv = Number(s.trade_value) || 0;
        return tv > 0 ? tv : (Number(s.price) * Number(s.volume));
    }
    function sortStocks(list, mode) {
        const arr = [...list];
        if (mode === 'up')        arr.sort((a, b) => b.change_rate - a.change_rate);
        else if (mode === 'down') arr.sort((a, b) => a.change_rate - b.change_rate);
        else if (mode === 'volume') arr.sort((a, b) => Number(b.volume) - Number(a.volume));
        else arr.sort((a, b) => tradeValueOf(b) - tradeValueOf(a));   // value(거래대금)
        return arr;
    }

    // ---------- 렌더: 순위 행 ----------
    // opts.badge → 이름 아래 보조문구(예: "1,240명이 봤어요")
    function rowHTML(s, rank, opts = {}) {
        const c = cls(s.change_price);
        const sub = opts.badge
            ? `<div class="sub-info">${opts.badge}</div>`
            : `<div class="cd">${s.stock_code}${s.market ? ' · ' + s.market : ''}</div>`;
        return `
        <a class="stock-row" href="stock_detail.php?code=${s.stock_code}" data-code="${s.stock_code}">
            ${rank ? `<div class="rank ${rank <= 3 ? 'top' : ''}">${rank}</div>` : ''}
            ${logoHTML(s)}
            <div class="stock-name">
                <div class="nm">${s.stock_name}</div>
                ${sub}
            </div>
            <div class="stock-price">
                <div class="pr">${s.price ? won(s.price) : '-'}</div>
                <div class="rt ${c}">${changeText(s.change_price, s.change_rate)}</div>
            </div>
        </a>`;
    }

    // ---------- 렌더: 우측 "지금 많이 봐요" ----------
    function renderSide() {
        const note = document.getElementById('side-note');
        if (mostViewed && mostViewed.length) {
            // 실제 조회수 기반
            document.getElementById('side-list').innerHTML = mostViewed
                .slice(0, 10)
                .map((s, i) => rowHTML(s, i + 1, {
                    badge: `${Number(s.view_count).toLocaleString()}명이 봤어요`
                })).join('');
            if (note) note.textContent = '방문자들이 많이 열어본 종목이에요.';
        } else {
            // 조회 데이터가 아직 없으면 급상승 TOP으로 폴백 (좌측 거래대금과 겹치지 않게)
            const rising = sortStocks(stocks, 'up').slice(0, 10);
            document.getElementById('side-list').innerHTML =
                rising.map((s, i) => rowHTML(s, i + 1)).join('');
            if (note) note.textContent = '아직 조회 데이터가 모이는 중이라 급상승 종목을 보여드려요.';
        }
    }

    // ---------- 렌더: 전체 화면 ----------
    function renderAll() {
        // 시장 스트립 (상위 6)
        const top6 = sortStocks(stocks, 'value').slice(0, 6);
        document.getElementById('market-strip').innerHTML = top6.map(s => {
            const c = cls(s.change_price);
            return `<a class="market-card" href="stock_detail.php?code=${s.stock_code}">
                <div class="mc-nm">${s.stock_name}</div>
                <div class="mc-pr">${s.price ? won(s.price) : '-'}</div>
                <div class="mc-rt ${c}">${changeText(s.change_price, s.change_rate)}</div>
            </a>`;
        }).join('');

        // 메인 순위 (현재 탭 기준)
        const sorted = sortStocks(stocks, currentSort);
        document.getElementById('main-list').innerHTML = sorted.map((s, i) => rowHTML(s, i + 1)).join('');

        // 사이드 = "지금 많이 봐요" (조회수 상위). 아직 조회 데이터가 없으면 급상승으로 폴백.
        renderSide();

        // 관심종목
        const wl = document.getElementById('watch-list');
        if (wl) wl.innerHTML = myWatch.length
            ? myWatch.map(s => rowHTML(s, 0)).join('')
            : '<div class="muted" style="padding:20px;text-align:center;font-size:14px;">관심종목을 검색해서 추가해보세요</div>';

        const now = new Date();
        document.getElementById('side-time').textContent =
            `${String(now.getHours()).padStart(2,'0')}:${String(now.getMinutes()).padStart(2,'0')} 기준`;
    }

    // ---------- 탭 전환 ----------
    document.querySelectorAll('.tabs button').forEach(btn => {
        btn.addEventListener('click', () => {
            document.querySelectorAll('.tabs button').forEach(b => b.classList.remove('on'));
            btn.classList.add('on');
            currentSort = btn.dataset.sort;
            renderAll();
        });
    });

    renderAll();

    // ---------- 30초마다 순위 새로고침 (거래대금 변동 반영) ----------
    setInterval(async () => {
        try {
            const res = await fetch('get_market.php');
            stocks = await res.json();
            // 많이 본 종목(조회수)도 함께 갱신
            const mv = await fetch('get_most_viewed.php');
            if (mv.ok) mostViewed = await mv.json();
            if (isLoggedIn) {
                const w = await fetch('get_prices.php');
                if (w.ok) myWatch = await w.json();
            }
            renderAll();
        } catch (e) { /* 네트워크 일시 오류는 무시 */ }
    }, 30000);

    // ---------- 실시간 WS: 가격만 즉시 갱신 ----------
    const ws = new WebSocket('ws://localhost:8080');
    ws.onmessage = (event) => {
        const d = JSON.parse(event.data);
        if (d.type && d.type !== 'price') return;   // 상세용 호가/체결 메시지는 홈에서 무시
        // 메모리 데이터 업데이트
        const hit = stocks.find(s => s.stock_code === d.stock_code);
        if (hit) { hit.price = d.price; hit.change_price = d.change_price; hit.change_rate = d.change_rate; hit.volume = d.volume; if (d.trade_value != null) hit.trade_value = d.trade_value; }
        const w = myWatch.find(s => s.stock_code === d.stock_code);
        if (w) { w.price = d.price; w.change_price = d.change_price; w.change_rate = d.change_rate; }

        // 화면의 해당 행들 갱신 + 반짝임
        document.querySelectorAll(`.stock-row[data-code="${d.stock_code}"]`).forEach(row => {
            const c = cls(d.change_price);
            row.querySelector('.pr').textContent = won(d.price);
            const rt = row.querySelector('.rt');
            rt.textContent = changeText(d.change_price, d.change_rate);
            rt.className = 'rt ' + c;
            row.classList.remove('flash-up', 'flash-down');
            void row.offsetWidth;
            row.classList.add(d.change_price >= 0 ? 'flash-up' : 'flash-down');
        });
    };
</script>

<?php include 'includes/footer.php'; ?>
