<?php
require_once 'func.php';
/** @var mysqli $conn */   // config/db.php 에서 넘어옴 (에디터 자동완성·오탐 방지용)
startUserSession();

// 비로그인도 종목/차트/호가/체결을 볼 수 있는 공개 페이지. (거래·관심등록만 로그인 요구)
$loggedIn = isLoggedIn();

$stockCode = strtoupper(trim($_GET['code'] ?? ''));
if ($stockCode === '') {
    header('Location: index.php');
    exit;
}

// 종목코드는 영문대문자·숫자 6자리만 허용 → 이상한 값은 DB 조회도 하지 않는다
$stock = preg_match('/^[0-9A-Z]{6}$/', $stockCode) ? getSingleStockPrice($conn, $stockCode) : null;

$pageClass = 'app-wide';   // 메인과 같은 폭(1180px) — 화면 가장자리까지 퍼지지 않아 눈이 덜 바쁘다

// 없는 종목 → 하얀 글씨 한 줄(die) 대신 메인과 같은 모양의 안내 화면
if (!$stock) {
    http_response_code(404);
    $pageTitle = '종목을 찾을 수 없어요';
    include 'includes/header.php';
    ?>
    <div class="sd">
        <a href="index.php" class="sd-back">← 목록으로</a>
        <div class="sd-card">
            <div class="sd-state inline">
                <div class="ico">🔍</div>
                <div class="tt">종목을 찾을 수 없어요</div>
                <div class="ds">종목코드를 다시 확인하거나, 위 검색창에서 이름으로 찾아보세요.</div>
                <a href="index.php" class="sd-retry" style="display:inline-flex;align-items:center;text-decoration:none;">홈으로 가기</a>
            </div>
        </div>
    </div>
    <?php
    include 'includes/footer.php';
    exit;
}

// 조회수 +1 (홈 우측 "지금 많이 봐요" 랭킹 집계용)
recordStockView($conn, $stockCode);

$isFav   = $loggedIn ? isInWatchlist($conn, $_SESSION['user_id'], $stockCode) : false;
$account = $loggedIn ? getMyAccount($conn, $_SESSION['user_id']) : null;
$cash    = (int) ($account['cash_balance'] ?? 0);   // 계좌 행이 없는 회원이어도 경고 없이 0원
$holding = $loggedIn ? getMyHolding($conn, $_SESSION['user_id'], $stockCode) : null;

// 첫 화면에 바로 보일 가격(DB 최신값). 이후 KIS 시세·실시간이 오면 갱신된다.
$price  = (int) ($stock['price'] ?? 0);
$chg    = (int) ($stock['change_price'] ?? 0);
$rate   = (float) ($stock['change_rate'] ?? 0);
$dirCls = $chg > 0 ? 'up' : ($chg < 0 ? 'down' : 'flat');

// 이 가격이 언제 것인지 — 오늘이면 "오늘 14:30 기준", 아니면 "9월 17일 10:14 기준".
// stock_latest.updated_at 은 DB(MySQL) 시계 = UTC 로 저장된다 → 한국 시간(+9)으로 바꿔서 보여 준다.
// 실시간 가격이 들어오면 화면에서 '실시간'으로 바뀐다.
$asof = '';
if (!empty($stock['created_at'])) {
    $kst = new DateTimeZone('Asia/Seoul');
    $at  = (new DateTime($stock['created_at'], new DateTimeZone('UTC')))->setTimezone($kst);
    $asof = ($at->format('Y-m-d') === (new DateTime('now', $kst))->format('Y-m-d') ? '오늘 ' . $at->format('H:i') : $at->format('n월 j일 H:i')) . ' 기준';
}

// 로고 색 (종목코드 해시 → 파스텔). 실제 이미지 있으면 이미지로 대체.
$hue = crc32($stockCode) % 360;
$hasLogo = is_file(__DIR__ . '/assets/logos/' . $stockCode . '.png');   // 없는 그림은 요청하지 않는다

$pageTitle  = $stock['stock_name'];
$pageDesc   = $stock['stock_name'] . '(' . $stockCode . ') ' . ($price ? number_format($price) . '원' : '') . ' — 차트·호가·체결을 보고 모의투자로 사고팔아 보세요.';
$hideTabBar = true;   // 이 화면은 자기만의 하단 [판매][구매] 버튼이 있어서 공통 하단 메뉴는 숨긴다
include 'includes/header.php';
?>

<script src="assets/lightweight-charts.js"></script>

<div class="sd" id="sd" data-mtab="chart">
    <a href="index.php" class="sd-back">← 목록으로</a>

    <!-- ===== 상단: 종목명 · 현재가 · 등락 · 관심 별 ===== -->
    <header class="sd-head">
        <div>
            <div class="sd-id">
                <span class="sd-logo" style="background:hsl(<?= $hue ?>,62%,62%);">
                    <?php if ($hasLogo): ?><img src="assets/logos/<?= htmlspecialchars($stockCode) ?>.png" alt="" onerror="this.remove()"><?php endif; ?>
                    <?= htmlspecialchars(mb_substr($stock['stock_name'], 0, 1)) ?>
                </span>
                <span class="sd-name"><?= htmlspecialchars($stock['stock_name']) ?></span>
                <span class="sd-code"><?= htmlspecialchars($stockCode) ?></span>
            </div>
            <div class="sd-price" id="sd-price"><?= $price ? number_format($price) . '원' : '—' ?></div>
            <div class="sd-change">
                <span class="lbl">어제보다</span>
                <span id="sd-change" class="<?= $dirCls ?>"><?= $price ? ($chg > 0 ? '+' : '') . number_format($chg) . '원 (' . ($rate > 0 ? '+' : '') . number_format($rate, 2) . '%)' : '—' ?></span>
            </div>
            <?php if ($asof !== ''): ?>
                <div class="sd-live sd-asof" id="sd-asof"><i></i><span><?= $asof ?></span></div>
            <?php endif; ?>
        </div>
        <button type="button" id="sd-star" class="sd-star<?= $isFav ? ' on' : '' ?>"
                aria-pressed="<?= $isFav ? 'true' : 'false' ?>" title="<?= $isFav ? '관심종목에서 빼기' : '관심종목에 추가' ?>">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M12 2.8l2.82 5.72 6.31.92-4.57 4.45 1.08 6.29L12 17.21l-5.64 2.97 1.08-6.29L2.87 9.44l6.31-.92L12 2.8z"/></svg>
        </button>
    </header>

    <!-- 휴대폰에서만 보이는 차트/호가 전환 탭 -->
    <div class="sd-mtabs" role="tablist">
        <button type="button" class="on" data-mtab="chart">차트</button>
        <button type="button" data-mtab="book">호가</button>
    </div>

    <div class="sd-layout">
        <!-- ===================== 왼쪽 ===================== -->
        <div class="sd-main">
            <!-- 차트 -->
            <section class="sd-card sd-chart-card">
                <div class="sd-chart-bar">
                    <div class="sd-ranges" id="sd-ranges">
                        <button type="button" class="on" data-range="1D">1일</button>
                        <button type="button" data-range="1W">1주</button>
                        <button type="button" data-range="1M">1달</button>
                        <button type="button" data-range="3M">3달</button>
                        <button type="button" data-range="1Y">1년</button>
                        <button type="button" data-range="2Y">2년</button>
                    </div>
                    <div class="sd-seg" id="sd-type">
                        <button type="button" data-type="line">선</button>
                        <button type="button" data-type="candle">캔들</button>
                    </div>
                </div>
                <div class="sd-chart-wrap" id="sd-chart-wrap">
                    <div class="sd-chart" id="sd-chart"></div>
                    <div class="sd-skel sd-skel-chart" id="sd-chart-skel"></div>
                    <div class="sd-state" id="sd-chart-state" hidden></div>
                </div>
                <div class="sd-chart-note" id="sd-chart-note"></div>
            </section>

            <!-- 요약 카드 5개 -->
            <section class="sd-stats" id="sd-stats">
                <div class="sd-stat"><div class="k">시가</div><div class="v" id="st-open"><span class="sd-skel"></span></div></div>
                <div class="sd-stat"><div class="k">고가</div><div class="v" id="st-high"><span class="sd-skel"></span></div></div>
                <div class="sd-stat"><div class="k">저가</div><div class="v" id="st-low"><span class="sd-skel"></span></div></div>
                <div class="sd-stat"><div class="k">거래량</div><div class="v" id="st-vol"><span class="sd-skel"></span></div></div>
                <div class="sd-stat"><div class="k">거래대금</div><div class="v" id="st-tv"><span class="sd-skel"></span></div></div>
            </section>

            <!-- 상세 탭: 체결 · 투자자 · 종목정보 · 커뮤니티 -->
            <section class="sd-card sd-more">
                <div class="sd-tabs" id="sd-tabs" role="tablist">
                    <button type="button" class="on" data-pane="ticks">실시간 체결</button>
                    <button type="button" data-pane="invest">투자자별 매매</button>
                    <button type="button" data-pane="info">종목 정보</button>
                    <button type="button" data-pane="community">커뮤니티</button>
                </div>

                <div data-pane-box="ticks">
                    <div class="sd-pane-sub">
                        <span class="sd-live" id="tk-live"><i></i>실시간</span>
                        <span>체결강도 <b id="sd-strength">—</b></span>
                    </div>
                    <div class="sd-scroll" id="sd-ticks"></div>
                </div>
                <div data-pane-box="invest" hidden>
                    <div class="sd-pane-sub"><span>최근 10일 · 순매수(주)</span><span>+ 산 게 더 많음 / − 판 게 더 많음</span></div>
                    <div class="sd-scroll" id="sd-invest"></div>
                </div>
                <div data-pane-box="info" hidden>
                    <div id="sd-info"></div>
                </div>
                <div data-pane-box="community" hidden>
                    <div class="sd-posts" id="sd-posts"></div>
                    <form class="sd-post-form" id="sd-post-form">
                        <textarea id="sd-post-input" maxlength="500" placeholder="<?= $loggedIn ? '이 종목 어때요? 의견을 남겨보세요' : '로그인하고 의견을 남겨보세요' ?>"></textarea>
                        <button type="submit" class="sd-btn primary">등록</button>
                    </form>
                </div>
            </section>
        </div>

        <!-- ===================== 오른쪽 ===================== -->
        <aside class="sd-side">
            <!-- 호가 5단계 -->
            <section class="sd-card sd-book">
                <div class="sd-card-h">
                    <span class="sd-card-t">호가</span>
                    <span class="sd-live" id="ob-live"><i></i>실시간</span>
                </div>
                <div id="sd-book"></div>
            </section>

            <!-- 주문 — 시장가로 바로 체결. 화면 이동 없이 order_api.php 로 주문하고 결과만 받아온다 -->
            <section class="sd-card sd-order" id="order" data-side="buy">
                <div class="sd-card-h">
                    <div class="sd-side-tabs" role="tablist">
                        <button type="button" class="on" data-side="buy">구매</button>
                        <button type="button" data-side="sell">판매</button>
                    </div>
                    <button type="button" class="sd-sheet-close" id="sd-sheet-close" aria-label="닫기">✕</button>
                </div>
                <?php if ($loggedIn): ?>
                    <div class="sd-ord-row">
                        <span class="k">주문 가격</span>
                        <span class="v"><span class="sd-chip">시장가</span> <b id="ord-price"><?= $price ? number_format($price) . '원' : '—' ?></b></span>
                    </div>

                    <label class="sd-ord-label" for="sd-qty">수량</label>
                    <div class="sd-stepper">
                        <button type="button" data-step="-1" aria-label="1주 빼기">−</button>
                        <input type="number" id="sd-qty" min="1" step="1" inputmode="numeric" placeholder="수량 입력" autocomplete="off">
                        <span class="unit">주</span>
                        <button type="button" data-step="1" aria-label="1주 더하기">+</button>
                    </div>
                    <div class="sd-quick">
                        <button type="button" data-pct="10">10%</button>
                        <button type="button" data-pct="25">25%</button>
                        <button type="button" data-pct="50">50%</button>
                        <button type="button" data-pct="100">최대</button>
                    </div>
                    <div class="sd-ord-max" id="ord-max"></div>

                    <div class="sd-ord-box">
                        <div class="sd-ord-row" data-only="buy"><span class="k">구매 가능 금액</span><span class="v" id="ord-cash"><?= number_format($cash) ?>원</span></div>
                        <div class="sd-ord-row" data-only="sell"><span class="k">보유 수량</span><span class="v" id="ord-hold">—</span></div>
                        <div class="sd-ord-row" data-only="sell"><span class="k">평균 단가</span><span class="v" id="ord-avg">—</span></div>
                        <div class="sd-ord-row total"><span class="k">예상 금액</span><span class="v" id="ord-total">0원</span></div>
                    </div>

                    <p class="sd-ord-msg" id="ord-msg" role="alert"></p>
                    <button type="button" class="sd-btn buy sd-ord-submit" id="ord-submit" disabled>구매하기</button>

                    <p class="sd-ord-note">지금 가격(시장가)으로 바로 체결돼요. 모의투자라 실제 돈은 오가지 않아요.</p>
                <?php else: ?>
                    <p class="hint">로그인하면 1,000만원 모의자금으로 사고팔 수 있어요.</p>
                    <a href="login.php?next=<?= urlencode('stock_detail.php?code=' . $stockCode) ?>" class="sd-btn primary">로그인하고 거래하기</a>
                <?php endif; ?>
            </section>
        </aside>
    </div>

    <!-- 휴대폰: 하단 고정 버튼 + 주문 시트 뒤 어두운 배경 -->
    <div class="sd-bottombar">
        <button type="button" class="sd-btn sell" data-open-order="sell">판매</button>
        <button type="button" class="sd-btn buy" data-open-order="buy">구매</button>
    </div>
    <div class="sd-dim" id="sd-dim"></div>

    <!-- 주문 최종 확인 창 -->
    <div class="sd-modal" id="sd-confirm" role="dialog" aria-modal="true" aria-labelledby="cf-title" hidden>
        <div class="sd-modal-box">
            <div class="sd-modal-t" id="cf-title"></div>
            <div class="sd-ord-row"><span class="k">주문 가격</span><span class="v" id="cf-price"></span></div>
            <div class="sd-ord-row"><span class="k">수량</span><span class="v" id="cf-qty"></span></div>
            <div class="sd-ord-row total"><span class="k">예상 금액</span><span class="v" id="cf-total"></span></div>
            <p class="sd-ord-note">시장가 주문은 체결되는 순간의 가격이라 조금 달라질 수 있어요.</p>
            <div class="sd-modal-btns">
                <button type="button" class="sd-btn ghost" id="cf-cancel">취소</button>
                <button type="button" class="sd-btn buy" id="cf-ok">구매하기</button>
            </div>
        </div>
    </div>
    <div class="sd-conn" id="sd-toast" hidden></div>
</div>

<script>
(function () {
    'use strict';

    // ================= 기본값 =================
    const CODE     = <?= json_encode($stockCode) ?>;
    const LOGGED   = <?= $loggedIn ? 'true' : 'false' ?>;
    const CSRF     = <?= json_encode(csrfToken()) ?>;
    let price      = <?= $price ?>;                 // 현재가
    let prevClose  = <?= $price - $chg ?>;          // 전일 종가 (등락 색의 기준)
    let quote      = null;                          // KIS 종합 시세
    let book       = null;                          // 마지막 호가
    let ticks      = [];                            // 체결 내역

    const $ = id => document.getElementById(id);
    // 차트는 그림판(canvas)이라 CSS 변수를 직접 못 쓴다 → 지금 테마의 색을 읽어 와서 넘겨준다 (테마 바뀌면 다시 읽음)
    let COLOR = {};
    function readColors() {
        const css = getComputedStyle(document.documentElement);
        const v = (name, fb) => css.getPropertyValue(name).trim() || fb;
        COLOR = {
            up: v('--up', '#f04452'), down: v('--down', '#3182f6'), flat: v('--flat', '#8b95a1'),
            line: v('--line', '#e5e8eb'), sub: v('--text-sub', '#8b95a1'), weak: v('--line-weak', '#f2f4f6'),
            cross: v('--icon-off', '#c9d2dc'), label: v('--text-2', '#4e5968'), dash: v('--placeholder', '#b0b8c1'),
        };
    }
    readColors();

    // ================= 숫자 표시 =================
    const num  = n => Math.round(Number(n) || 0).toLocaleString('ko-KR');
    const won  = n => num(n) + '원';
    const sign = n => (n > 0 ? '+' : '');
    const dir  = (p, base = prevClose) => !base || p === base ? 'flat' : (p > base ? 'up' : 'down');
    const pct  = (p, base = prevClose) => base ? (p - base) / base * 100 : 0;
    function volFmt(n) {                          // 12,345,678 → 1,234만 주
        n = Number(n) || 0;
        return n >= 1e4 ? num(Math.floor(n / 1e4)) + '만 주' : num(n) + '주';
    }
    function moneyFmt(v) {                        // 원 → 조/억/만원
        v = Number(v) || 0;
        const jo = Math.floor(v / 1e12), eok = Math.floor((v % 1e12) / 1e8);
        if (jo > 0) return jo + '조 ' + num(eok) + '억';
        if (eok > 0) return num(eok) + '억';
        return num(Math.floor(v / 1e4)) + '만원';
    }
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const hms = t => { const s = String(t).padStart(6, '0'); return s.slice(0, 2) + ':' + s.slice(2, 4) + ':' + s.slice(4, 6); };

    // ================= 상태 화면 (빈 화면 · 오류) =================
    // retry 에 이름을 주면 [다시 시도] 버튼이 생기고, 누르면 RETRY[이름]() 이 실행된다.
    function stateInner(kind, title, desc, retry, label = '다시 시도') {
        return `<div class="ico">${kind === 'error' ? '⚠️' : '📭'}</div><div class="tt">${title}</div>`
             + (desc ? `<div class="ds">${desc}</div>` : '')
             + (retry ? `<button type="button" class="sd-retry" data-retry="${retry}">${label}</button>` : '');
    }
    const stateHTML = (kind, title, desc, retry) => `<div class="sd-state inline">${stateInner(kind, title, desc, retry)}</div>`;
    const RETRY = {};
    document.addEventListener('click', e => {
        const b = e.target.closest('[data-retry]');
        if (b && RETRY[b.dataset.retry]) RETRY[b.dataset.retry]();
    });

    // fetch + JSON. 서버 오류·{error:...} 응답을 모두 '실패'로 통일
    async function getJSON(url) {
        const res = await fetch(url, { headers: { 'Accept': 'application/json' } });
        if (!res.ok) throw new Error('HTTP ' + res.status);
        const data = await res.json();
        if (data && !Array.isArray(data) && data.error) throw new Error(data.error);
        return data;
    }

    // 잠깐 떴다 사라지는 안내
    let toastT = null;
    function toast(msg, ms = 2200) {
        const el = $('sd-toast');
        el.textContent = msg; el.hidden = false;
        clearTimeout(toastT);
        if (ms) toastT = setTimeout(() => { el.hidden = true; }, ms);
    }
    function flash(el, d) {
        if (!el || d === 'flat') return;
        el.classList.remove('sd-flash-up', 'sd-flash-down');
        void el.offsetWidth;                         // 애니메이션을 처음부터 다시 재생시키는 요령
        el.classList.add(d === 'up' ? 'sd-flash-up' : 'sd-flash-down');
    }
    function markLive(id) {
        const el = $(id); if (!el) return;
        el.classList.add('on'); clearTimeout(el._t);
        el._t = setTimeout(() => el.classList.remove('on'), 4000);
    }

    // ================= 현재가 갱신 (모든 곳이 이 함수 하나로) =================
    function setPrice(p, opts = {}) {
        p = Number(p) || 0;
        if (!p) return;
        const old = price;
        price = p;
        $('sd-price').textContent = won(p);
        const ch = p - prevClose, d = dir(p);
        const el = $('sd-change');
        el.textContent = `${sign(ch)}${num(ch)}원 (${sign(ch)}${pct(p).toFixed(2)}%)`;
        el.className = d;
        if (opts.flash && old && old !== p) flash($('sd-price'), p > old ? 'up' : 'down');

        // 고가·저가가 장중에 바뀌면 요약 카드도 따라간다
        if (quote) {
            if (p > quote.high) quote.high = p;
            if (quote.low && p < quote.low) quote.low = p;
            renderStats();
        }
        updateEstimate();
        if (book) renderBook(book);                  // '지금 가격' 줄 강조 위치 갱신
    }

    // ================= 요약 카드 =================
    function renderStats() {
        const q = quote;
        const put = (id, html, cls = '') => { const el = $(id); el.innerHTML = html; el.className = 'v ' + cls; };
        if (!q) return;
        put('st-open', num(q.open), dir(q.open));
        put('st-high', num(q.high), dir(q.high));
        put('st-low',  num(q.low),  dir(q.low));
        put('st-vol',  volFmt(q.volume));
        put('st-tv',   moneyFmt(q.trade_value));
    }
    function statsError() {
        ['st-open', 'st-high', 'st-low', 'st-vol', 'st-tv'].forEach(id => { $(id).textContent = '—'; $(id).className = 'v flat'; });
        let el = $('sd-stats-err');
        if (!el) {
            el = document.createElement('div');
            el.id = 'sd-stats-err'; el.className = 'sd-stats-err';
            $('sd-stats').appendChild(el);
        }
        el.innerHTML = '요약 정보를 불러오지 못했어요. <button type="button" class="sd-retry" data-retry="quote">다시 시도</button>';
    }

    async function loadQuote() {
        const err = $('sd-stats-err'); if (err) err.remove();
        try {
            const q = await getJSON('get_quote.php?code=' + CODE);
            quote = q;
            if (q.prev_close) prevClose = q.prev_close;
            renderStats();
            setPrice(q.price || price);
            renderInfo();
        } catch (e) {
            console.warn('시세 로드 실패', e);
            statsError();
            if (activePane === 'info') renderInfo(true);
        }
    }
    RETRY.quote = loadQuote;

    // ================= 호가 5단계 =================
    const LEVELS = 5;
    function bookSkeleton() {
        $('sd-book').innerHTML = Array.from({ length: LEVELS * 2 }, () => '<span class="sd-skel sd-ob-skel"></span>').join('');
    }
    function renderBook(d) {
        book = d;
        const asks = (d.asks || []).slice(0, LEVELS);    // [0] = 가장 싼 매도호가
        const bids = (d.bids || []).slice(0, LEVELS);    // [0] = 가장 비싼 매수호가
        const rows = asks.concat(bids);
        if (!rows.length || rows.every(r => !r.price)) {
            $('sd-book').innerHTML = stateHTML('empty', '호가가 아직 없어요', '장 시작 전이거나 거래가 멈춘 종목이에요.');
            return;
        }
        const maxQty = Math.max(1, ...rows.map(r => r.qty || 0));
        const row = (r, side) => {
            if (!r || !r.price) return `<div class="sd-ob-row sd-ob-${side}"><div class="sd-ob-qty"></div><div class="sd-ob-px"><b class="flat">—</b></div><div class="sd-ob-qty"></div></div>`;
            const w = ((r.qty || 0) / maxQty * 100).toFixed(1);
            const qty = `<div class="sd-ob-qty"><span class="bar" style="width:${w}%"></span><span class="n">${num(r.qty)}</span></div>`;
            const d2 = dir(r.price), p = pct(r.price);
            const px = `<div class="sd-ob-px ${d2}"><b>${num(r.price)}</b><small>${sign(p)}${p.toFixed(2)}%</small></div>`;
            const now = r.price === price ? ' is-now' : '';
            return side === 'ask'
                ? `<div class="sd-ob-row sd-ob-ask${now}">${qty}${px}<div class="sd-ob-qty"></div></div>`
                : `<div class="sd-ob-row sd-ob-bid${now}"><div class="sd-ob-qty"></div>${px}${qty}</div>`;
        };
        let html = '<div class="sd-ob-head"><span>판매 대기</span><span>가격</span><span>구매 대기</span></div>';
        for (let i = LEVELS - 1; i >= 0; i--) html += row(asks[i], 'ask');   // 위: 비싼 매도 → 싼 매도
        for (let i = 0; i < LEVELS; i++)      html += row(bids[i], 'bid');   // 아래: 비싼 매수 → 싼 매수
        const sum = a => a.reduce((s, r) => s + (r.qty || 0), 0);
        html += `<div class="sd-ob-sum"><span>${num(sum(asks))}</span><span>5호가 잔량</span><span>${num(sum(bids))}</span></div>`;
        $('sd-book').innerHTML = html;
    }
    async function loadBook() {
        if (!book) bookSkeleton();
        try {
            const d = await getJSON('get_orderbook.php?code=' + CODE);
            if (d.prev_close) prevClose = d.prev_close;
            renderBook(d);
        } catch (e) {
            console.warn('호가 로드 실패', e);
            if (!book) $('sd-book').innerHTML = stateHTML('error', '호가를 불러오지 못했어요', '잠시 후 다시 시도해 주세요.', 'book');
        }
    }
    RETRY.book = loadBook;

    // ================= 차트 =================
    const RANGE_RES  = { '1D': '1분봉', '1W': '5분봉', '1M': '일봉', '3M': '일봉', '1Y': '일봉', '2Y': '일봉' };
    const DAILY      = ['1M', '3M', '1Y', '2Y'];      // 서버가 time 을 'YYYY-MM-DD' 로 주는 기간 (func.php chartRangeSpec 과 맞출 것)
    const BUCKET_SEC = { '1D': 60, '1W': 300 };        // 실시간으로 마지막 봉을 갱신하는 기간과 봉 폭(초)
    let range = '1D', chartType = null, userPickedType = false;
    let candles = [], series = null, prevLine = null, chart = null, loadSeq = 0;
    let firstLoad = true;   // 처음 열 때만: '1일'이 비어 있으면(주말·장 시작 전·시세 중단) 자동으로 '3달'을 보여준다

    function chartState(kind, title, desc, retry, label) {
        const el = $('sd-chart-state');
        if (!kind) { el.hidden = true; el.innerHTML = ''; return; }
        el.innerHTML = stateInner(kind, title, desc, retry, label);
        el.hidden = false;
    }

    function initChart() {
        if (!window.LightweightCharts) return false;
        chart = LightweightCharts.createChart($('sd-chart'), {
            autoSize: true,
            layout: { background: { color: 'transparent' }, textColor: COLOR.sub, fontFamily: 'Pretendard', fontSize: 12 },
            grid: { vertLines: { visible: false }, horzLines: { color: COLOR.weak } },
            rightPriceScale: { borderVisible: false, scaleMargins: { top: 0.12, bottom: 0.08 } },
            // fixLeftEdge/fixRightEdge 는 짝으로 켠다 → 최대로 줄여도 데이터 밖 빈 여백이 생기지 않는다
            timeScale: { borderVisible: false, fixLeftEdge: true, fixRightEdge: true, rightOffset: 0, lockVisibleTimeRangeOnResize: true },
            crosshair: { mode: 1, vertLine: { color: COLOR.cross, labelBackgroundColor: COLOR.label }, horzLine: { color: COLOR.cross, labelBackgroundColor: COLOR.label } },
            localization: {
                locale: 'ko-KR',
                priceFormatter: p => num(p),
                // 분봉 time 은 'KST 시각을 UTC로 읽은 값'이라 getUTC* 로 읽어야 한국 시각이 나온다
                timeFormatter: t => {
                    if (typeof t === 'object') return `${t.year}.${String(t.month).padStart(2, '0')}.${String(t.day).padStart(2, '0')}`;
                    if (typeof t === 'string') return t.replace(/-/g, '.');
                    const d = new Date(t * 1000);
                    return `${d.getUTCMonth() + 1}/${d.getUTCDate()} ${String(d.getUTCHours()).padStart(2, '0')}:${String(d.getUTCMinutes()).padStart(2, '0')}`;
                },
            },
        });
        return true;
    }

    // 선 차트 색: 기준(1일=어제 종가, 나머지=기간 첫 값)보다 오르면 빨강, 내리면 파랑
    function lineColor() {
        if (!candles.length) return COLOR.flat;
        const base = range === '1D' && prevClose ? prevClose : candles[0].close;
        const d = dir(candles[candles.length - 1].close, base);
        return COLOR[d];
    }
    function hexToRgba(hex, a) {
        const m = hex.replace('#', '').match(/.{2}/g);
        return m ? `rgba(${parseInt(m[0], 16)},${parseInt(m[1], 16)},${parseInt(m[2], 16)},${a})` : hex;
    }

    function drawSeries() {
        if (!chart) return;
        if (series) { chart.removeSeries(series); series = null; prevLine = null; }
        if (chartType === 'candle') {
            series = chart.addCandlestickSeries({
                upColor: COLOR.up, downColor: COLOR.down, borderVisible: false,
                wickUpColor: COLOR.up, wickDownColor: COLOR.down, priceLineVisible: false,
            });
            series.setData(candles);
        } else {
            const c = lineColor();
            series = chart.addAreaSeries({
                lineColor: c, lineWidth: 2, topColor: hexToRgba(c, 0.16), bottomColor: hexToRgba(c, 0),
                priceLineVisible: false, crosshairMarkerRadius: 4,
            });
            series.setData(candles.map(k => ({ time: k.time, value: k.close })));
        }
        // 1일 차트에는 '어제 종가' 점선 → 지금 오른 건지 내린 건지 한눈에
        if (range === '1D' && prevClose) {
            prevLine = series.createPriceLine({ price: prevClose, color: COLOR.dash, lineWidth: 1, lineStyle: 2, axisLabelVisible: false, title: '어제' });
        }
        chart.timeScale().fitContent();
    }

    function setTypeButtons() {
        document.querySelectorAll('#sd-type button').forEach(b => b.classList.toggle('on', b.dataset.type === chartType));
    }

    async function loadChart(r = range, note = '') {
        const seq = ++loadSeq;
        const wrap = $('sd-chart-wrap');
        if (candles.length) wrap.classList.add('is-busy'); else $('sd-chart-skel').hidden = false;
        chartState(null);
        try {
            const data = await getJSON('get_stock_chart.php?stock_code=' + CODE + '&range=' + r);
            if (seq !== loadSeq) return;           // 그 사이 다른 기간을 눌렀다 → 늦게 온 응답은 버린다
            range = r;
            candles = Array.isArray(data) ? data : [];
            // 사용자가 직접 고른 적이 없을 때만 자동 선택: 1일·1주는 선(체결이 드문드문이라 캔들이 납작함), 나머지는 캔들
            if (!userPickedType) chartType = (r === '1D' || r === '1W') ? 'line' : 'candle';
            setTypeButtons();
            chart.applyOptions({ timeScale: { timeVisible: !DAILY.includes(r), secondsVisible: false } });
            drawSeries();
            if (!candles.length && r === '1D' && firstLoad) {
                // 방문자가 처음 보는 화면이 빈 차트면 아무것도 없는 서비스처럼 보인다 → 3달 차트로 바로 바꿔서 보여준다
                // (1달이 아니라 3달인 이유: 시세 수집이 멈춘 기간이 길면 1달 안에는 봉이 몇 개 없어서 여전히 휑해 보임)
                firstLoad = false;
                document.querySelectorAll('#sd-ranges button').forEach(x => x.classList.toggle('on', x.dataset.range === '3M'));
                loadChart('3M', '오늘은 거래가 없어서 최근 3달을 보여드려요');
                return;
            }
            firstLoad = false;
            if (!candles.length && r === '1D') {
                // '1일' = 오늘 하루. 주말·공휴일·장 시작 전엔 비어 있는 게 정상 → 1주 차트로 바로 넘어갈 수 있게
                chartState('empty', '오늘은 아직 거래가 없어요', '주말·공휴일이거나 장 시작(오전 9시) 전이에요.', 'week', '1주 차트 보기');
                $('sd-chart-note').textContent = '';
            } else if (!candles.length) {
                chartState('empty', '이 기간엔 거래 기록이 없어요', '다른 기간을 눌러 보세요.');
                $('sd-chart-note').textContent = '';
            } else {
                $('sd-chart-note').textContent = `${note ? note + ' · ' : ''}${RANGE_RES[r]} · ${candles.length.toLocaleString()}개`;
            }
        } catch (e) {
            if (seq !== loadSeq) return;
            console.warn('차트 로드 실패', e);
            chartState('error', '차트를 불러오지 못했어요', '네트워크가 불안정하거나 서버가 잠시 바빠요.', 'chart');
        } finally {
            if (seq === loadSeq) { wrap.classList.remove('is-busy'); $('sd-chart-skel').hidden = true; }
        }
    }
    RETRY.chart = () => loadChart(range);

    // 라이트↔다크가 바뀌면 차트 글자·격자·선 색을 새 테마로 다시 칠한다 (theme.js 가 'themechange' 를 쏜다)
    window.addEventListener('themechange', () => {
        readColors();
        if (!chart) return;
        chart.applyOptions({
            layout: { textColor: COLOR.sub },
            grid: { horzLines: { color: COLOR.weak } },
            crosshair: { vertLine: { color: COLOR.cross, labelBackgroundColor: COLOR.label }, horzLine: { color: COLOR.cross, labelBackgroundColor: COLOR.label } },
        });
        drawSeries();   // 캔들 빨강/파랑, 선 색, '어제' 점선
    });
    RETRY.week  = () => document.querySelector('#sd-ranges [data-range="1W"]').click();

    $('sd-ranges').addEventListener('click', e => {
        const b = e.target.closest('button[data-range]');
        if (!b || b.classList.contains('on')) return;
        document.querySelectorAll('#sd-ranges button').forEach(x => x.classList.toggle('on', x === b));
        loadChart(b.dataset.range);
    });
    $('sd-type').addEventListener('click', e => {
        const b = e.target.closest('button[data-type]');
        if (!b || b.dataset.type === chartType) return;
        chartType = b.dataset.type;
        userPickedType = true;                     // 이제부터 기간을 바꿔도 이 선택을 유지
        setTypeButtons();
        drawSeries();
    });

    // 실시간 체결 → 마지막 봉 갱신 (1일·1주만. 일봉 기간은 과거 차트라 건드리지 않음)
    // 체결시각(HHMMSS, 한국시각)을 서버 캔들과 같은 'KST를 UTC로 읽은' 축으로 바꾼다.
    function updateLastCandle(p, hhmmss) {
        const bucket = BUCKET_SEC[range];
        if (!bucket || !series || !hhmmss) return;
        const s = String(hhmmss).padStart(6, '0');
        const sec = (+s.slice(0, 2)) * 3600 + (+s.slice(2, 4)) * 60 + (+s.slice(4, 6));
        const todayKst = Math.floor((Date.now() / 1000 + 9 * 3600) / 86400) * 86400;
        const slot = Math.floor((todayKst + sec) / bucket) * bucket;

        const last = candles[candles.length - 1];
        if (last && last.time === slot) {
            last.high = Math.max(last.high, p); last.low = Math.min(last.low, p); last.close = p;
        } else if (!last || slot > last.time) {
            candles.push({ time: slot, open: p, high: p, low: p, close: p });
            if (candles.length === 1) chartState(null);
        } else return;                              // 이미 지나간 칸의 늦은 체결 → 무시
        const k = candles[candles.length - 1];
        series.update(chartType === 'candle' ? k : { time: k.time, value: k.close });
    }

    // ================= 아래 상세 탭 =================
    let activePane = 'ticks';
    const loaded = {};
    $('sd-tabs').addEventListener('click', e => {
        const b = e.target.closest('button[data-pane]');
        if (b) openPane(b.dataset.pane);
    });
    function openPane(name) {
        activePane = name;
        document.querySelectorAll('#sd-tabs button').forEach(b => b.classList.toggle('on', b.dataset.pane === name));
        document.querySelectorAll('[data-pane-box]').forEach(p => { p.hidden = p.dataset.paneBox !== name; });
        if (!loaded[name]) { loaded[name] = true; PANE_LOAD[name](); }   // 처음 열 때만 불러온다 (API 호출 절약)
    }
    const skelRows = n => Array.from({ length: n }, () => '<span class="sd-skel" style="height:20px;margin:12px 0;"></span>').join('');

    // --- 체결 ---
    function renderTicks() {
        const box = $('sd-ticks');
        if (!ticks.length) { box.innerHTML = stateHTML('empty', '아직 체결이 없어요', '거래가 생기면 여기에 바로 보여요.'); return; }
        box.innerHTML = '<table class="sd-tbl"><thead><tr><th>시각</th><th>체결가</th><th>등락률</th><th>체결량</th></tr></thead><tbody>'
            + ticks.slice(0, 30).map(t => {
                const d = dir(t.price), p = pct(t.price);
                return `<tr><td>${hms(t.time)}</td><td class="${d}">${num(t.price)}</td><td class="${d}">${sign(p)}${p.toFixed(2)}%</td><td>${num(t.volume)}</td></tr>`;
            }).join('') + '</tbody></table>';
    }
    async function loadTicks() {
        $('sd-ticks').innerHTML = skelRows(6);
        try {
            const d = await getJSON('get_ccnl.php?code=' + CODE);
            ticks = ticks.filter(t => t.live).concat(d.trades || []);   // 그새 실시간으로 받은 최신 체결이 맨 위
            setStrength(d.strength);
            renderTicks();
        } catch (e) {
            console.warn('체결 로드 실패', e);
            $('sd-ticks').innerHTML = stateHTML('error', '체결 내역을 불러오지 못했어요', '', 'ticks');
        }
    }
    function setStrength(v) { if (v) $('sd-strength').textContent = Number(v).toFixed(1) + '%'; }

    // --- 투자자 ---
    async function loadInvest() {
        const box = $('sd-invest');
        box.innerHTML = skelRows(6);
        try {
            const rows = await getJSON('get_investor.php?code=' + CODE);
            if (!Array.isArray(rows) || !rows.length) { box.innerHTML = stateHTML('empty', '투자자 정보가 없어요', ''); return; }
            const cell = v => `<td class="${v > 0 ? 'up' : (v < 0 ? 'down' : 'flat')}">${sign(v)}${num(v)}</td>`;
            box.innerHTML = '<table class="sd-tbl"><thead><tr><th>날짜</th><th>개인</th><th>외국인</th><th>기관</th></tr></thead><tbody>'
                + rows.slice(0, 10).map(r => {
                    const s = String(r.date);
                    return `<tr><td>${s.slice(4, 6)}.${s.slice(6, 8)}</td>${cell(r.individual)}${cell(r.foreign)}${cell(r.institution)}</tr>`;
                }).join('') + '</tbody></table>';
        } catch (e) {
            console.warn('투자자 로드 실패', e);
            box.innerHTML = stateHTML('error', '투자자 정보를 불러오지 못했어요', '', 'invest');
        }
    }

    // --- 종목 정보 ---
    function renderInfo(failed) {
        const box = $('sd-info');
        if (!quote) {
            box.innerHTML = failed ? stateHTML('error', '종목 정보를 불러오지 못했어요', '', 'quote') : skelRows(6);
            return;
        }
        const q = quote;
        const rows = [
            ['시장', esc(q.market || '—')], ['업종', esc(q.sector || '—')],
            ['어제 종가', won(q.prev_close)], ['시가총액', num(q.market_cap) + '억원'],
            ['52주 최고', won(q.w52_high)], ['52주 최저', won(q.w52_low)],
            ['상한가', won(q.upper_limit)], ['하한가', won(q.lower_limit)],
            ['PER', q.per ? q.per + '배' : '—'], ['PBR', q.pbr ? q.pbr + '배' : '—'],
            ['EPS', won(q.eps)], ['BPS', won(q.bps)],
            ['상장주식수', num(q.shares) + '주'], ['외국인 소진율', q.frgn_ehrt + '%'],
        ];
        box.innerHTML = '<div class="sd-info">' + rows.map(([k, v]) =>
            `<div class="sd-info-row"><span class="k">${k}</span><span class="v">${v}</span></div>`).join('') + '</div>';
    }

    // --- 커뮤니티 ---
    async function loadPosts() {
        const box = $('sd-posts');
        if (!box.innerHTML) box.innerHTML = skelRows(4);
        try {
            const posts = await getJSON('get_posts.php?code=' + CODE);
            if (!Array.isArray(posts) || !posts.length) { box.innerHTML = stateHTML('empty', '아직 글이 없어요', '첫 의견을 남겨 보세요 ✍️'); return; }
            box.innerHTML = posts.map(p =>
                `<div class="sd-post"><div class="m"><b>${esc(p.nickname)}</b><span>${esc((p.created_at || '').slice(5, 16))}</span></div>`
                + `<div class="b">${esc(p.content)}</div></div>`).join('');
        } catch (e) {
            console.warn('커뮤니티 로드 실패', e);
            box.innerHTML = stateHTML('error', '글을 불러오지 못했어요', '', 'community');
        }
    }
    $('sd-post-form').addEventListener('submit', async e => {
        e.preventDefault();
        if (!LOGGED) { location.href = 'login.php?next=' + encodeURIComponent('stock_detail.php?code=' + CODE); return; }
        const ta = $('sd-post-input');
        const content = ta.value.trim();
        if (!content) return;
        const btn = e.target.querySelector('button'); btn.disabled = true;
        try {
            const r = await fetch('post_add.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': CSRF },
                body: 'stock_code=' + encodeURIComponent(CODE) + '&content=' + encodeURIComponent(content) + '&csrf_token=' + encodeURIComponent(CSRF),
            }).then(res => res.json());
            if (r.error === 'login') { location.href = 'login.php?next=' + encodeURIComponent('stock_detail.php?code=' + CODE); return; }
            if (r.error === 'csrf') { toast('보안 토큰이 만료됐어요. 새로고침 후 다시 해 주세요.'); return; }
            if (r.error === 'demo') { toast('체험 계정은 글을 쓸 수 없어요. 가입하면 쓸 수 있어요.'); return; }
            if (r.error) { toast('글을 올리지 못했어요.'); return; }
            ta.value = '';
            loadPosts();
        } catch (err) {
            toast('글을 올리지 못했어요. 잠시 후 다시 해 주세요.');
        } finally { btn.disabled = false; }
    });

    const PANE_LOAD = { ticks: loadTicks, invest: loadInvest, info: () => renderInfo(), community: loadPosts };
    RETRY.ticks = loadTicks; RETRY.invest = loadInvest; RETRY.community = loadPosts;

    // ================= 관심종목 별 =================
    $('sd-star').addEventListener('click', async function () {
        if (!LOGGED) { location.href = 'login.php?next=' + encodeURIComponent('stock_detail.php?code=' + CODE); return; }
        const on = this.classList.contains('on');
        this.disabled = true;
        try {
            const res = await fetch(on ? 'watchlist_remove.php' : 'watchlist_add.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': CSRF },
                body: 'stock_code=' + encodeURIComponent(CODE) + '&csrf_token=' + encodeURIComponent(CSRF),
            });
            if (res.status === 401) { location.href = 'login.php?next=' + encodeURIComponent('stock_detail.php?code=' + CODE); return; }
            if (!res.ok) throw new Error('HTTP ' + res.status);
            // 서버가 '성공'이라고 답한 뒤에만 별을 바꾼다
            this.classList.toggle('on', !on);
            this.setAttribute('aria-pressed', String(!on));
            this.title = !on ? '관심종목에서 빼기' : '관심종목에 추가';
            toast(!on ? '관심종목에 추가했어요' : '관심종목에서 뺐어요');
        } catch (e) {
            toast('잠시 후 다시 시도해 주세요.');
        } finally { this.disabled = false; }
    });

    // ================= 주문 =================
    // 시장가 주문: 수량만 고르면 서버가 '서버가 가진 최신가'로 체결한다. 화면 이동 없이 order_api.php 와 JSON 으로 주고받음.
    let side    = 'buy';
    let cash    = <?= $cash ?>;
    let holding = <?= json_encode($holding) ?>;          // {quantity, avg_price} 또는 null
    let ordering = false;
    const qtyEl = $('sd-qty');

    const maxQty = () => side === 'buy' ? (price > 0 ? Math.floor(cash / price) : 0) : (holding ? holding.quantity : 0);
    const getQty = () => Math.max(0, parseInt(qtyEl?.value, 10) || 0);

    function setSide(s2) {
        side = s2;
        $('order').dataset.side = s2;
        document.querySelectorAll('.sd-side-tabs button').forEach(b => b.classList.toggle('on', b.dataset.side === s2));
        if (qtyEl) { qtyEl.value = ''; qtyEl.placeholder = '수량 입력'; }
        updateEstimate();
    }

    // 금액·최대수량·버튼 상태를 한 번에 갱신 (가격이 실시간으로 바뀔 때도 이 함수가 불린다)
    function updateEstimate() {
        if (!qtyEl) return;
        const q = getQty(), max = maxQty();
        $('ord-price').textContent = price ? won(price) : '—';
        $('ord-total').textContent = won(q * price);
        $('ord-cash').textContent = won(cash);
        $('ord-hold').textContent = holding ? num(holding.quantity) + '주' : '0주';
        if (holding) {
            const r = pct(price, holding.avg_price);
            $('ord-avg').innerHTML = `${won(holding.avg_price)} <small class="${dir(price, holding.avg_price)}">(${sign(r)}${r.toFixed(2)}%)</small>`;
        } else $('ord-avg').textContent = '—';

        $('ord-max').textContent = side === 'buy' ? `최대 ${num(max)}주 살 수 있어요` : `최대 ${num(max)}주 팔 수 있어요`;

        // 왜 못 누르는지 버튼 위에 한 줄로 알려준다
        let msg = '';
        if (!price) msg = '가격 정보가 없어서 지금은 주문할 수 없어요.';
        else if (side === 'sell' && !max) msg = '보유한 주식이 없어요.';
        else if (side === 'buy' && !max) msg = '구매 가능 금액이 부족해요.';
        else if (q > max) msg = side === 'buy' ? `구매 가능 금액이 부족해요. (최대 ${num(max)}주)` : `보유 수량보다 많아요. (최대 ${num(max)}주)`;
        $('ord-msg').textContent = msg;

        const btn = $('ord-submit');
        btn.textContent = side === 'buy' ? '구매하기' : '판매하기';
        btn.className = 'sd-btn sd-ord-submit ' + side;
        btn.disabled = ordering || !q || !!msg;
    }

    if (qtyEl) {
        qtyEl.addEventListener('input', updateEstimate);
        document.querySelectorAll('.sd-side-tabs button').forEach(b => b.addEventListener('click', () => setSide(b.dataset.side)));
        document.querySelectorAll('[data-step]').forEach(b => b.addEventListener('click', () => {
            qtyEl.value = Math.max(0, getQty() + Number(b.dataset.step)) || '';
            updateEstimate();
        }));
        document.querySelectorAll('[data-pct]').forEach(b => b.addEventListener('click', () => {
            const n = Math.floor(maxQty() * Number(b.dataset.pct) / 100);
            qtyEl.value = n || '';
            if (!n) toast(side === 'buy' ? '구매 가능 금액이 부족해요.' : '보유한 주식이 없어요.');
            updateEstimate();
        }));
        qtyEl.addEventListener('keydown', e => { if (e.key === 'Enter' && !$('ord-submit').disabled) openConfirm(); });
        $('ord-submit').addEventListener('click', openConfirm);
    } else {
        // 비로그인: 탭은 바꿔 보여주기만
        document.querySelectorAll('.sd-side-tabs button').forEach(b => b.addEventListener('click', () => {
            $('order').dataset.side = b.dataset.side;
            document.querySelectorAll('.sd-side-tabs button').forEach(x => x.classList.toggle('on', x === b));
        }));
    }

    // --- 최종 확인 창 ---
    const STOCK_NAME = <?= json_encode($stock['stock_name']) ?>;
    function openConfirm() {
        const q = getQty();
        if (!q) return;
        const verb = side === 'buy' ? '구매' : '판매';
        $('cf-title').textContent = `${STOCK_NAME} ${num(q)}주 ${verb}할까요?`;
        $('cf-price').textContent = '시장가 (약 ' + won(price) + ')';
        $('cf-qty').textContent = num(q) + '주';
        $('cf-total').textContent = '약 ' + won(q * price);
        $('cf-ok').textContent = verb + '하기';
        $('cf-ok').className = 'sd-btn ' + side;
        $('sd-confirm').hidden = false;
        $('cf-ok').focus();
    }
    const closeConfirm = () => { $('sd-confirm').hidden = true; };
    $('cf-cancel').addEventListener('click', closeConfirm);
    $('sd-confirm').addEventListener('click', e => { if (e.target.id === 'sd-confirm') closeConfirm(); });
    $('cf-ok').addEventListener('click', submitOrder);

    async function submitOrder() {
        if (ordering) return;                       // 두 번 눌러도 주문은 한 번만
        ordering = true;
        const q = getQty(), s2 = side;
        $('cf-ok').disabled = true; $('cf-ok').textContent = '주문하는 중…';
        updateEstimate();
        try {
            const res = await fetch('order_api.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': CSRF },
                body: new URLSearchParams({ stock_code: CODE, order_type: s2, quantity: q, csrf_token: CSRF }),
            });
            let r = {};
            try { r = await res.json(); } catch (e) { /* 서버가 JSON 이 아닌 걸 줬다 → 아래에서 실패 처리 */ }
            if (r.error === 'login') { location.href = 'login.php?next=' + encodeURIComponent('stock_detail.php?code=' + CODE); return; }
            if (r.error === 'csrf')  throw new Error('보안 토큰이 만료됐어요. 새로고침 후 다시 해 주세요.');
            if (!res.ok || !r.success) throw new Error(r.error || '주문에 실패했어요. 잠시 후 다시 해 주세요.');

            cash = r.cash_balance;
            holding = r.holding;
            closeConfirm();
            closeSheet();
            qtyEl.value = '';
            toast(`✅ ${num(r.quantity)}주 ${s2 === 'buy' ? '구매' : '판매'} 완료 · ${won(r.price)}에 체결 · 총 ${won(r.total)}`, 3500);
        } catch (e) {
            closeConfirm();
            $('ord-msg').textContent = e.message;
            toast(e.message, 3500);
        } finally {
            ordering = false;
            $('cf-ok').disabled = false;
            updateEstimate();
        }
    }

    // 휴대폰: 하단 [판매][구매] → 그 방향으로 주문 시트 열기
    const isMobile = () => window.matchMedia('(max-width: 900px)').matches;
    function openSheet() {
        $('order').classList.add('open'); $('sd-dim').classList.add('open');
        setTimeout(() => qtyEl?.focus(), 250);
    }
    function closeSheet() { $('order').classList.remove('open'); $('sd-dim').classList.remove('open'); }
    document.querySelectorAll('[data-open-order]').forEach(b => b.addEventListener('click', () => {
        if (qtyEl) setSide(b.dataset.openOrder);
        else { $('order').dataset.side = b.dataset.openOrder; document.querySelectorAll('.sd-side-tabs button').forEach(x => x.classList.toggle('on', x.dataset.side === b.dataset.openOrder)); }
        openSheet();
    }));
    $('sd-dim').addEventListener('click', closeSheet);
    $('sd-sheet-close').addEventListener('click', closeSheet);
    document.addEventListener('keydown', e => { if (e.key === 'Escape') { if (!$('sd-confirm').hidden) closeConfirm(); else closeSheet(); } });
    updateEstimate();

    // 자동매매 화면의 '일반매매'(…#order)로 들어오면 주문 패널을 바로 보여준다
    if (location.hash === '#order') {
        requestAnimationFrame(() => {
            if (isMobile()) { openSheet(); return; }
            const p = $('order');
            p.scrollIntoView({ behavior: 'smooth', block: 'center' });
            p.classList.add('w-highlight');
            setTimeout(() => p.classList.remove('w-highlight'), 1600);
        });
    }

    // 휴대폰: 차트 ↔ 호가 탭
    document.querySelector('.sd-mtabs').addEventListener('click', e => {
        const b = e.target.closest('button[data-mtab]');
        if (!b) return;
        $('sd').dataset.mtab = b.dataset.mtab;
        document.querySelectorAll('.sd-mtabs button').forEach(x => x.classList.toggle('on', x === b));
    });

    // ================= 실시간 (WebSocket, 끊기면 자동 재연결) =================
    let ws = null, wsTries = 0, wsTimer = null, everOpened = false;
    function connect() {
        try {
            ws = new WebSocket((location.protocol === 'https:' ? 'wss://' : 'ws://') + location.host + '/ws/');
        } catch (e) { return scheduleReconnect(); }
        ws.onopen = () => {
            ws.send(JSON.stringify({ action: 'watch', code: CODE }));
            if (wsTries > 0) {                      // 다시 붙었으면 끊긴 동안 놓친 값을 한 번 맞춘다
                toast('실시간 시세에 다시 연결됐어요');
                loadBook(); loadQuote();
            }
            wsTries = 0; everOpened = true;
        };
        ws.onmessage = ev => {
            let d; try { d = JSON.parse(ev.data); } catch (e) { return; }
            if (d.stock_code !== CODE) return;      // 서버는 모든 종목을 보내므로 내 종목만 골라 쓴다
            if (d.type === 'orderbook') { renderBook(d); markLive('ob-live'); return; }
            if (d.type === 'trade') {
                const t = { time: d.time, price: d.price, volume: d.volume, sign: d.sign, live: true };
                ticks.unshift(t); if (ticks.length > 60) ticks.pop();
                if (loaded.ticks) renderTicks();
                setStrength(d.strength); markLive('tk-live');
                updateLastCandle(d.price, d.time);
                return;
            }
            // type === 'price' → 현재가·누적거래량
            setPrice(d.price, { flash: true });
            const asof = $('sd-asof');   // 'O월 O일 기준' → '실시간' (빨간 점이 깜빡임)
            if (asof && !asof.classList.contains('on')) { asof.classList.add('on'); asof.querySelector('span').textContent = '실시간'; }
            if (quote && d.volume) { quote.volume = d.volume; renderStats(); }
        };
        ws.onerror = () => { /* onclose 에서 재연결 처리 */ };
        ws.onclose = scheduleReconnect;
    }
    function scheduleReconnect() {
        clearTimeout(wsTimer);
        wsTries++;
        if (everOpened && wsTries === 2) toast('실시간 연결이 끊겼어요. 다시 연결하는 중…', 0);
        const wait = Math.min(15000, 1000 * 2 ** Math.min(wsTries - 1, 4));   // 1, 2, 4, 8, 15초…
        wsTimer = setTimeout(connect, wait);
    }

    // ================= 시작 =================
    renderInfo();
    if (initChart()) loadChart('1D');
    else { $('sd-chart-skel').hidden = true; chartState('error', '차트를 표시할 수 없어요', '새로고침해 보세요.', null); }
    loadQuote();
    loadBook();
    openPane('ticks');
    connect();
})();
</script>

<?php include 'includes/footer.php'; ?>
