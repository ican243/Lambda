<?php
/** @var mysqli $conn */   // config/db.php 에서 넘어옴 (에디터 자동완성·오탐 방지용)
// 각 페이지에서 include 전에 $pageWide = true 로 설정하면 넓은(대시보드) 레이아웃,
// 아니면 좁은(폼/상세) 레이아웃을 쓴다.
// $pageClass 를 직접 지정하면(예: 'app-xwide') 그 클래스를 그대로 쓴다.
// $pageTitle  = '내 계좌'      → 브라우저 탭 제목 "내 계좌 | 모아증권" (없으면 홈 제목)
// $hideTabBar = true          → 휴대폰 하단 메뉴 숨김 (종목 상세처럼 자기만의 하단 버튼이 있는 화면)
$pageWide  = $pageWide ?? false;
$pageClass = $pageClass ?? ($pageWide ? 'app-wide' : 'app');
$loggedIn  = function_exists('isLoggedIn') && isLoggedIn();
$isDemo    = $loggedIn && isset($conn) && function_exists('isDemoUser') && isDemoUser($conn);
// CSS/JS 캐시 자동 무효화(파일 수정시각) → 새로고침만 해도 항상 최신 반영
$cssVer = @filemtime(__DIR__ . '/../assets/toss.css') ?: '1';

// 탭 제목 · 링크 미리보기(OG) — 카톡/노션/슬랙에 링크를 붙였을 때 보이는 제목·설명·이미지
$siteName  = '모아증권';
$fullTitle = isset($pageTitle) && $pageTitle !== '' ? $pageTitle . ' | ' . $siteName : $siteName . ' — 진짜 시세로 연습하는 모의투자';
$pageDesc  = $pageDesc ?? '모의자금 1,000만원으로 실제 시세를 보며 주식을 사고팔아 보는 토스 스타일 모의투자 서비스. 실시간 호가·차트·자동매매까지.';
$scheme    = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$baseUrl   = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'kir1.cafe24.com');
$pageUrl   = $baseUrl . strtok($_SERVER['REQUEST_URI'] ?? '/user/', '#');

// 로고 이미지가 실제로 있는 종목코드 목록 → 화면에서 없는 그림을 요청하지 않게 (404 요청 방지)
$logoCodes = array_map(fn($f) => basename($f, '.png'), glob(__DIR__ . '/../assets/logos/*.png') ?: []);
?>
<!DOCTYPE html>
<html lang="ko">

<head>
    <meta charset="UTF-8">
    <!-- 테마(라이트/다크)는 화면이 그려지기 전에 정해야 깜빡임이 없다 → 맨 먼저, 동기로 -->
    <script src="assets/theme.js?v=<?= @filemtime(__DIR__ . '/../assets/theme.js') ?: '1' ?>"></script>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($fullTitle) ?></title>
    <meta name="description" content="<?= htmlspecialchars($pageDesc) ?>">
    <meta name="theme-color" content="#ffffff">
    <link rel="icon" href="assets/favicon.svg" type="image/svg+xml">
    <link rel="apple-touch-icon" href="assets/apple-touch-icon.png">
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="<?= $siteName ?>">
    <meta property="og:title" content="<?= htmlspecialchars($fullTitle) ?>">
    <meta property="og:description" content="<?= htmlspecialchars($pageDesc) ?>">
    <meta property="og:image" content="<?= htmlspecialchars($baseUrl) ?>/user/assets/og.png">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta property="og:url" content="<?= htmlspecialchars($pageUrl) ?>">
    <meta name="twitter:card" content="summary_large_image">
    <!-- 로컬 자산 (CDN 왕복 제거 → 로딩 빠름) -->
    <link href="assets/toss.css?v=<?= $cssVer ?>" rel="stylesheet">
    <script>window.HAS_LOGO = new Set(<?= json_encode(array_values($logoCodes)) ?>);</script>
</head>

<body class="site<?= empty($hideTabBar) ? ' has-tabbar' : '' ?>">
    <?php if ($isDemo): ?>
        <!-- 체험 계정 안내 띠 -->
        <div class="demo-bar">
            <span class="long">🎟️ <b>체험 계정</b>으로 둘러보는 중이에요 · 매일 새벽 4시에 처음 상태로 돌아가요</span>
            <span class="short">🎟️ <b>체험 계정</b> · 매일 새벽 4시 초기화</span>
            <form action="logout.php" method="POST" class="logout-form"><?= csrfField() ?><input type="hidden" name="to" value="join"><button type="submit">내 계정 만들기 ›</button></form>
        </div>
    <?php endif; ?>

    <!-- 상단 네비게이션 -->
    <nav class="nav">
        <div class="nav-inner">
            <a href="index.php" class="brand"><img src="assets/favicon.svg" alt="" class="brand-mark">모아<b>증권</b></a>
            <div class="nav-menu">
                <a href="index.php" class="nav-link">홈</a>
                <a href="index.php#ranking" class="nav-link">주식 골라보기</a>
                <!-- 자동매매: 비로그인에게도 보이고, 클릭하면 auto_trade.php가 로그인 여부를 판단 -->
                <a href="auto_trade.php" class="nav-link nav-link-accent">자동매매</a>
                <?php if ($loggedIn): ?>
                    <a href="trade.php" class="nav-link">내 계좌</a>
                <?php endif; ?>
            </div>
            <!-- 휴대폰: 검색칸 대신 🔍 아이콘 → 누르면 검색칸이 펼쳐진다 -->
            <button type="button" class="nav-search-btn" id="nav-search-btn" aria-label="종목 검색" aria-expanded="false">
                <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg>
            </button>
            <form class="nav-search" onsubmit="return false;">
                <input type="text" id="nav-search-input" placeholder="종목명을 검색하세요">
                <div id="nav-search-results" class="nav-search-results"></div>
            </form>
            <?php include __DIR__ . '/theme_switch.php'; ?>
            <?php if ($loggedIn): ?>
                <span class="nav-user"><?= htmlspecialchars($_SESSION['nickname'] ?? '') ?>님</span>
                <form action="logout.php" method="POST" class="logout-form"><?= csrfField() ?><button type="submit" class="nav-logout">로그아웃</button></form>
            <?php elseif (!in_array(basename($_SERVER['SCRIPT_NAME'] ?? ''), ['login.php', 'join.php'], true)): ?>
                <!-- 로그인·회원가입 화면에서는 이미 그 화면이라 버튼을 숨긴다 -->
                <a href="login.php" class="nav-login">로그인</a>
            <?php endif; ?>
        </div>
    </nav>

    <div class="<?= $pageClass ?>">

    <?php
    // 점검 모드 배너 + 활성 공지 배너 (관리자 운영 컨트롤)
    if (isset($conn)):
        if (isMaintenance($conn)): ?>
            <div class="notice-bar err">🛠️ <?= htmlspecialchars(maintenanceMsg($conn)) ?></div>
        <?php endif;
        foreach (getActiveNotices($conn) as $notice): ?>
            <div class="notice-bar info">
                📢 <b><?= htmlspecialchars($notice['title']) ?></b>
                <?php if (trim($notice['body'] ?? '') !== ''): ?><span> · <?= htmlspecialchars($notice['body']) ?></span><?php endif; ?>
            </div>
        <?php endforeach;
    endif; ?>

    <script>
    // 네비 검색 (전 페이지 공통) — 결과 클릭 시 상세로 이동
    (function () {
        const input = document.getElementById('nav-search-input');
        const box = document.getElementById('nav-search-results');
        if (!input) return;
        const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
        let t = null;
        input.addEventListener('input', () => {
            clearTimeout(t);
            const kw = input.value.trim();
            if (!kw) { box.innerHTML = ''; box.style.display = 'none'; return; }
            t = setTimeout(async () => {
                const res = await fetch('watchlist_search.php?keyword=' + encodeURIComponent(kw));
                const stocks = await res.json();
                box.style.display = 'block';
                box.innerHTML = stocks.length ? stocks.map(s => `
                    <a class="nav-search-item" href="stock_detail.php?code=${encodeURIComponent(s.stock_code)}">
                        <span>${esc(s.stock_name)}</span>
                        <span class="muted" style="font-size:12px;">${esc(s.stock_code)} · ${esc(s.market ?? '')}</span>
                    </a>`).join('')
                    : `<div class="nav-search-empty">'${esc(kw)}'에 맞는 종목이 없어요</div>`;
            }, 250);
        });
        // 휴대폰: 🔍 누르면 펼치고, 바깥을 누르거나 Esc 면 접는다
        const nav = document.querySelector('.nav'), sbtn = document.getElementById('nav-search-btn');
        const openSearch = (on) => { nav.classList.toggle('search-open', on); sbtn.setAttribute('aria-expanded', String(on)); if (on) input.focus(); };
        sbtn.addEventListener('click', () => openSearch(true));
        document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && nav.classList.contains('search-open')) { openSearch(false); sbtn.focus(); } });
        document.addEventListener('click', (e) => {
            if (!input.contains(e.target) && !box.contains(e.target)) box.style.display = 'none';
            if (nav.classList.contains('search-open') && !e.target.closest('.nav-search') && !e.target.closest('#nav-search-btn')) openSearch(false);
        });
    })();
    </script>
