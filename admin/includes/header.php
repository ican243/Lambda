<?php
// 관리자 공통 머리말 — 왼쪽 메뉴까지 여기서 그린다 (예전엔 페이지마다 메뉴를 복사해 두었음).
// 페이지에서 include 전에:
//   $adminPage  = 'dashboard' | 'users' | 'settings' | 'admins'   → 메뉴에서 '지금 보는 곳' 표시
//   $adminBare  = true                                             → 메뉴 없이 (로그인 화면)
//   $adminTitle = '회원 관리'                                       → 브라우저 탭 제목
$adminPage  = $adminPage ?? '';
$adminBare  = $adminBare ?? false;
$adminTitle = $adminTitle ?? '관리자';
// CSS 캐시 자동 무효화(파일 수정시각) → 새로고침만 해도 항상 최신 반영. 사용자 화면과 같은 디자인 파일을 쓴다.
$cssVer = @filemtime(__DIR__ . '/../../user/assets/toss.css') ?: '1';

$icons = [
    'dashboard' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/></svg>',
    'users'     => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="8" r="4"/><path d="M2 21c0-4 3-7 7-7s7 3 7 7"/><path d="M16 4a4 4 0 0 1 0 8M22 21c0-3-2-5.5-4.5-6.5"/></svg>',
    'settings'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 6h10M18 6h2M4 12h4M12 12h8M4 18h12M20 18h0"/><circle cx="16" cy="6" r="2"/><circle cx="10" cy="12" r="2"/><circle cx="18" cy="18" r="2"/></svg>',
    'admins'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6l8-3z"/><path d="M9 12l2 2 4-4"/></svg>',
];
$menu = [['dashboard', 'index.php', '대시보드'], ['users', 'users.php', '회원 관리']];
if (function_exists('adminCan') && adminCan('settings'))      $menu[] = ['settings', 'settings.php', '운영 설정'];
if (function_exists('adminCan') && adminCan('manage_admins')) $menu[] = ['admins', 'admins.php', '관리자 계정'];
?>
<!DOCTYPE html>
<html lang="ko">

<head>
    <meta charset="UTF-8">
    <script src="../user/assets/theme.js?v=<?= @filemtime(__DIR__ . '/../../user/assets/theme.js') ?: '1' ?>"></script>
    <meta name="theme-color" content="#ffffff">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?= htmlspecialchars($adminTitle) ?> · 모아증권 관리자</title>
    <link href="../user/assets/toss.css?v=<?= $cssVer ?>" rel="stylesheet">
</head>

<body class="ad-body">
<?php if ($adminBare): ?>
    <div class="ad-login">
<?php else: ?>
    <div class="ad-shell">
        <aside class="ad-side">
            <a class="ad-brand" href="index.php"><span class="m">모</span><span><b>모아증권</b><small>관리자</small></span></a>
            <nav class="ad-nav">
                <?php foreach ($menu as [$key, $href, $label]): ?>
                    <a href="<?= $href ?>" class="<?= $adminPage === $key ? 'on' : '' ?>"><?= $icons[$key] ?><?= $label ?></a>
                <?php endforeach; ?>
            </nav>
            <div class="ad-me">
                <div class="nm"><?= htmlspecialchars($_SESSION['admin_name'] ?? '') ?>님</div>
                <div class="row2">
                    <span class="ad-badge <?= adminRole() === 'super' ? 'dark' : '' ?>"><?= roleLabel(adminRole()) ?></span>
                    <span style="display:inline-flex;align-items:center;gap:var(--sp-2);">
                        <?php include __DIR__ . '/../../user/includes/theme_switch.php'; ?>
                        <a href="logout.php">로그아웃</a>
                    </span>
                </div>
                <a class="ad-site" href="../user/index.php" target="_blank" rel="noopener">사용자 화면 보기 ↗</a>
            </div>
        </aside>
        <main class="ad-main">
<?php endif; ?>
