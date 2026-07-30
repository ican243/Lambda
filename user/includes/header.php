<?php
/** @var mysqli $conn */   // config/db.php 에서 넘어옴 (에디터 자동완성·오탐 방지용)
// 각 페이지에서 include 전에 $pageWide = true 로 설정하면 넓은(대시보드) 레이아웃,
// 아니면 좁은(폼/상세) 레이아웃을 쓴다.
// $pageClass 를 직접 지정하면(예: 'app-xwide') 그 클래스를 그대로 쓴다.
$pageWide  = $pageWide ?? false;
$pageClass = $pageClass ?? ($pageWide ? 'app-wide' : 'app');
$loggedIn  = function_exists('isLoggedIn') && isLoggedIn();
// CSS/JS 캐시 자동 무효화(파일 수정시각) → 새로고침만 해도 항상 최신 반영
$cssVer = @filemtime(__DIR__ . '/../assets/toss.css') ?: '1';
?>
<!DOCTYPE html>
<html lang="ko">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>모의투자</title>
    <!-- 로컬 자산 (CDN 왕복 제거 → 로딩 빠름) -->
    <link href="assets/bootstrap.min.css" rel="stylesheet">
    <link href="assets/toss.css?v=<?= $cssVer ?>" rel="stylesheet">
</head>

<body>
    <!-- 상단 네비게이션 -->
    <nav class="nav">
        <div class="nav-inner">
            <a href="index.php" class="brand">모아<b>증권</b></a>
            <div class="nav-menu">
                <a href="index.php" class="nav-link">홈</a>
                <a href="index.php#ranking" class="nav-link">주식 골라보기</a>
                <!-- 자동매매: 비로그인에게도 보이고, 클릭하면 auto_trade.php가 로그인 여부를 판단 -->
                <a href="auto_trade.php" class="nav-link nav-link-accent">자동매매</a>
                <?php if ($loggedIn): ?>
                    <a href="trade.php" class="nav-link">내 계좌</a>
                <?php endif; ?>
            </div>
            <form class="nav-search" onsubmit="return false;">
                <input type="text" id="nav-search-input" placeholder="종목명을 검색하세요">
                <div id="nav-search-results" class="nav-search-results"></div>
            </form>
            <?php if ($loggedIn): ?>
                <span class="nav-user"><?= htmlspecialchars($_SESSION['nickname'] ?? '') ?>님</span>
                <a href="logout.php" class="nav-logout">로그아웃</a>
            <?php else: ?>
                <a href="login.php" class="nav-login">로그인</a>
            <?php endif; ?>
        </div>
    </nav>

    <div class="<?= $pageClass ?>">

    <?php
    // 점검 모드 배너 + 활성 공지 배너 (관리자 운영 컨트롤)
    if (isset($conn)):
        if (isMaintenance($conn)): ?>
            <div style="background:#fdecee;color:#f04452;border-radius:12px;padding:12px 16px;margin:0 0 16px;font-size:14px;font-weight:600;">
                🛠️ <?= htmlspecialchars(maintenanceMsg($conn)) ?>
            </div>
        <?php endif;
        foreach (getActiveNotices($conn) as $notice): ?>
            <div style="background:#e8f3ff;color:#1b64da;border-radius:12px;padding:12px 16px;margin:0 0 12px;font-size:14px;">
                📢 <b><?= htmlspecialchars($notice['title']) ?></b>
                <?php if (trim($notice['body'] ?? '') !== ''): ?><span style="color:#3182f6;"> · <?= htmlspecialchars($notice['body']) ?></span><?php endif; ?>
            </div>
        <?php endforeach;
    endif; ?>

    <script>
    // 네비 검색 (전 페이지 공통) — 결과 클릭 시 상세로 이동
    (function () {
        const input = document.getElementById('nav-search-input');
        const box = document.getElementById('nav-search-results');
        if (!input) return;
        let t = null;
        input.addEventListener('input', () => {
            clearTimeout(t);
            const kw = input.value.trim();
            if (!kw) { box.innerHTML = ''; box.style.display = 'none'; return; }
            t = setTimeout(async () => {
                const res = await fetch('watchlist_search.php?keyword=' + encodeURIComponent(kw));
                const stocks = await res.json();
                if (!stocks.length) { box.innerHTML = ''; box.style.display = 'none'; return; }
                box.style.display = 'block';
                box.innerHTML = stocks.map(s => `
                    <a class="nav-search-item" href="stock_detail.php?code=${s.stock_code}">
                        <span>${s.stock_name}</span>
                        <span class="muted" style="font-size:12px;">${s.stock_code} · ${s.market ?? ''}</span>
                    </a>`).join('');
            }, 250);
        });
        document.addEventListener('click', (e) => {
            if (!input.contains(e.target) && !box.contains(e.target)) box.style.display = 'none';
        });
    })();
    </script>
