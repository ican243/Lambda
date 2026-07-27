<?php
require_once 'func.php';
startAdminSession();

if (!isAdminLoggedIn()) {
    header('Location: login.php');
    exit;
}

ensureAdminSchema($conn);   // users.status / admin_cash_logs 자동 보강

// 기간 필터 (화이트리스트)
$period = $_GET['period'] ?? 'today';
if (!in_array($period, ['today', 'week', 'month'], true)) $period = 'today';
$periodLabel = ['today' => '오늘', 'week' => '최근 7일', 'month' => '최근 30일'][$period];

// ── 데이터 로드 ──
$totalUsers = getTotalUserCount($conn);

// 기간별 가입자
$signupCond = periodCond($period, 'created_at');
$signups = (int) mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM users WHERE $signupCond"))['c'];

$ratio = getBuySellRatio($conn, $period);
$periodOrders = $ratio['buy']['cnt'] + $ratio['sell']['cnt'];
$periodAmount = $ratio['buy']['amt'] + $ratio['sell']['amt'];

$topTraded  = getTopTradedStocks($conn, $period);
$hourly     = getHourlyVolume($conn, $period);
$topProfit  = getLeaderboard($conn, 'top');
$topLoss    = getLeaderboard($conn, 'bottom');
$topStocks  = getTopWatchedStocks($conn);
$recentOrders = getRecentOrders($conn);

// 시스템 상태
$lastCollect = getLastCollectTime($conn);
$collectAge  = $lastCollect ? (time() - strtotime($lastCollect)) : null;   // 초
$wsAlive     = isWsAlive();
$tokenExp    = getKisTokenExpiry();                        // KIS 토큰 만료(unix) or null
$tokenLeft   = $tokenExp ? ($tokenExp - time()) : null;    // 남은 초
$todayErrors = getTodayErrorCount($conn);
$fdsIps      = getFdsMultiAccountIps($conn);
$fdsRapid    = getFdsRapidTraders($conn);
$errors      = getRecentErrors($conn);

// 상태 판정
function ageBadge($age) {
    if ($age === null) return ['secondary', '데이터 없음'];
    if ($age < 120)   return ['success', '실시간'];
    if ($age < 600)   return ['warning', '최근 수집'];
    return ['danger', '수집 지연'];
}
[$collectColor, $collectText] = ageBadge($collectAge);
function agoText($sec) {
    if ($sec === null) return '-';
    if ($sec < 60) return $sec . '초 전';
    if ($sec < 3600) return floor($sec / 60) . '분 전';
    return floor($sec / 3600) . '시간 전';
}

include 'includes/header.php';
?>

<style>
    .admin-nav { display:flex; gap:8px; align-items:center; margin-bottom:20px; }
    .admin-nav .sp { flex:1; }
    .stat-dot { display:inline-block; width:9px; height:9px; border-radius:50%; margin-right:6px; }
    .bar-track { height:14px; border-radius:7px; background:#eef1f4; overflow:hidden; display:flex; }
    .hour-chart { display:flex; align-items:flex-end; gap:4px; height:130px; padding-top:6px; }
    .hour-col { flex:1; display:flex; flex-direction:column; align-items:center; justify-content:flex-end; height:100%; }
    .hour-col .b { width:100%; max-width:26px; background:#3182f6; border-radius:4px 4px 0 0; min-height:2px; transition:height .2s; }
    .hour-col .lbl { font-size:11px; color:#8b95a1; margin-top:4px; }
    .lead-rate.up { color:#f04452; } .lead-rate.down { color:#3182f6; }
    .num { font-variant-numeric: tabular-nums; }
    .card h6 { color:#6b7684; }
</style>

<!-- 네비 -->
<div class="admin-nav">
    <a href="index.php" class="btn btn-dark btn-sm">대시보드</a>
    <a href="users.php" class="btn btn-outline-dark btn-sm">회원 관리</a>
    <?php if (adminCan('settings')): ?><a href="settings.php" class="btn btn-outline-dark btn-sm">설정</a><?php endif; ?>
    <?php if (adminCan('manage_admins')): ?><a href="admins.php" class="btn btn-outline-dark btn-sm">관리자</a><?php endif; ?>
    <span class="sp"></span>
    <span class="badge bg-<?= adminRole() === 'super' ? 'dark' : 'secondary' ?>"><?= roleLabel(adminRole()) ?></span>
    <span class="text-muted"><?= htmlspecialchars($_SESSION['admin_name']) ?>님</span>
    <a href="logout.php" class="btn btn-outline-secondary btn-sm">로그아웃</a>
</div>

<h2 class="mb-1">관리자 대시보드</h2>

<!-- 수집 지연/소켓 경고 배너 -->
<?php if ($collectAge === null || $collectAge >= 600 || !$wsAlive): ?>
    <div class="alert alert-danger mt-3 mb-0 py-2">
        ⚠️
        <?php if (!$wsAlive): ?> 실시간 WebSocket(stock-ws) 서버가 응답하지 않습니다. <?php endif; ?>
        <?php if ($collectAge === null): ?> 아직 수집된 시세가 없습니다. <?php elseif ($collectAge >= 600): ?> 시세 수집이 <?= agoText($collectAge) ?>로 지연되고 있습니다. <?php endif; ?>
        <span class="text-muted small">(cron/fetch_price.php · stock-ws 실행 상태를 확인하세요)</span>
    </div>
<?php endif; ?>

<!-- 시스템 상태 스트립 -->
<div class="row g-3 mt-2">
    <div class="col-md-4">
        <div class="card p-3">
            <h6>시세 수집 상태</h6>
            <div class="fs-5 fw-bold">
                <span class="stat-dot bg-<?= $collectColor ?>"></span><?= $collectText ?>
            </div>
            <div class="text-muted small">마지막 수집 <?= agoText($collectAge) ?> <?= $lastCollect ? '(' . htmlspecialchars($lastCollect) . ')' : '' ?></div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card p-3">
            <h6>실시간 WebSocket</h6>
            <div class="fs-5 fw-bold">
                <span class="stat-dot bg-<?= $wsAlive ? 'success' : 'danger' ?>"></span><?= $wsAlive ? '연결됨 (8080)' : '끊김' ?>
            </div>
            <div class="text-muted small">stock-ws 서버 포트 응답</div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card p-3">
            <h6>누적 시세 데이터</h6>
            <div class="fs-5 fw-bold num"><?= number_format(getTotalLogCount($conn)) ?>건</div>
            <div class="text-muted small">stock_logs 총 적재량</div>
        </div>
    </div>
</div>

<!-- 헬스: KIS 토큰 + 오늘 에러 -->
<div class="row g-3 mt-2">
    <div class="col-md-6">
        <div class="card p-3">
            <h6>KIS API 토큰</h6>
            <?php
            if ($tokenLeft === null) { $tc = 'secondary'; $tt = '정보 없음'; }
            elseif ($tokenLeft <= 0) { $tc = 'danger'; $tt = '만료됨'; }
            elseif ($tokenLeft < 3600) { $tc = 'warning'; $tt = '곧 만료 (' . floor($tokenLeft / 60) . '분 남음)'; }
            else { $tc = 'success'; $tt = number_format(floor($tokenLeft / 3600)) . '시간 남음'; }
            ?>
            <div class="fs-5 fw-bold"><span class="stat-dot bg-<?= $tc ?>"></span><?= $tt ?></div>
            <div class="text-muted small">만료 시 자동 재발급 · token_cache.json</div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card p-3">
            <h6>오늘 에러</h6>
            <div class="fs-5 fw-bold <?= $todayErrors > 0 ? 'text-danger' : '' ?>"><span class="stat-dot bg-<?= $todayErrors > 0 ? 'danger' : 'success' ?>"></span><?= number_format($todayErrors) ?>건</div>
            <div class="text-muted small">주문 실패·시스템 오류 (아래 로그 참고)</div>
        </div>
    </div>
</div>

<!-- FDS: 이상거래 감지 -->
<?php if ($fdsIps || $fdsRapid): ?>
<div class="card border-warning p-3 mt-3">
    <h6 class="text-warning-emphasis mb-3">🚨 이상 징후 감지 (FDS)</h6>
    <div class="row g-3">
        <?php if ($fdsIps): ?>
        <div class="col-md-6">
            <div class="fw-semibold small mb-1">다계정 의심 (한 IP · 최근 7일)</div>
            <table class="table table-sm mb-0">
                <thead><tr><th>IP</th><th class="text-end">계정 수</th><th class="text-end">접속</th></tr></thead>
                <tbody>
                <?php foreach ($fdsIps as $f): ?>
                    <tr><td class="num"><?= htmlspecialchars($f['ip']) ?></td><td class="text-end num text-danger fw-bold"><?= $f['accounts'] ?>개</td><td class="text-end num"><?= $f['hits'] ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
        <?php if ($fdsRapid): ?>
        <div class="col-md-6">
            <div class="fw-semibold small mb-1">비정상 거래 빈도 (10분 내 10건↑)</div>
            <table class="table table-sm mb-0">
                <thead><tr><th>유저</th><th class="text-end">거래 수</th><th>최근</th></tr></thead>
                <tbody>
                <?php foreach ($fdsRapid as $f): ?>
                    <tr><td><a href="user_detail.php?id=<?= (int) $f['user_id'] ?>" class="text-decoration-none"><?= htmlspecialchars($f['nickname']) ?></a></td><td class="text-end num text-danger fw-bold"><?= $f['cnt'] ?>건</td><td class="small"><?= htmlspecialchars(substr($f['last_at'], 11, 5)) ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
    <div class="text-muted small mt-2">※ 규칙 기반 탐지(학습용). 실무 FDS는 실시간 스트림 + 룰엔진/ML로 훨씬 정교합니다.</div>
</div>
<?php endif; ?>

<!-- 기간 필터 -->
<div class="d-flex align-items-center mt-4 mb-2">
    <h4 class="mb-0 me-3">거래 현황</h4>
    <div class="btn-group btn-group-sm" role="group">
        <?php foreach (['today' => '오늘', 'week' => '이번 주', 'month' => '이번 달'] as $p => $lbl): ?>
            <a href="?period=<?= $p ?>" class="btn btn-<?= $period === $p ? 'dark' : 'outline-dark' ?>"><?= $lbl ?></a>
        <?php endforeach; ?>
    </div>
    <span class="text-muted ms-3 small"><?= $periodLabel ?> 기준</span>
</div>

<!-- KPI 카드 -->
<div class="row g-3">
    <div class="col-md-3"><div class="card text-center p-3"><h6>전체 회원 수</h6><h3 class="num"><?= number_format($totalUsers) ?>명</h3></div></div>
    <div class="col-md-3"><div class="card text-center p-3"><h6><?= $periodLabel ?> 가입자</h6><h3 class="num"><?= number_format($signups) ?>명</h3></div></div>
    <div class="col-md-3"><div class="card text-center p-3"><h6><?= $periodLabel ?> 거래 건수</h6><h3 class="num"><?= number_format($periodOrders) ?>건</h3></div></div>
    <div class="col-md-3"><div class="card text-center p-3"><h6><?= $periodLabel ?> 거래대금</h6><h3 class="num"><?= number_format($periodAmount) ?>원</h3></div></div>
</div>

<!-- 거래 분석 -->
<div class="row g-3 mt-1">
    <!-- 최다 거래 종목 -->
    <div class="col-md-5">
        <div class="card p-3 h-100">
            <h6 class="mb-3">최다 거래 종목 TOP 5 <span class="text-muted small">(거래대금)</span></h6>
            <?php if (!$topTraded): ?>
                <p class="text-muted mb-0">해당 기간 거래가 없습니다.</p>
            <?php else: ?>
                <table class="table table-sm mb-0">
                    <thead><tr><th>#</th><th>종목</th><th class="text-end">거래대금</th><th class="text-end">체결수</th></tr></thead>
                    <tbody>
                    <?php foreach ($topTraded as $i => $s): ?>
                        <tr>
                            <td><?= $i + 1 ?></td>
                            <td><?= htmlspecialchars($s['stock_name']) ?> <span class="text-muted small"><?= htmlspecialchars($s['stock_code']) ?></span></td>
                            <td class="text-end num"><?= number_format($s['amt']) ?>원</td>
                            <td class="text-end num"><?= number_format($s['trades']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <!-- 매수 vs 매도 -->
    <div class="col-md-3">
        <div class="card p-3 h-100">
            <h6 class="mb-3">매수 vs 매도 <span class="text-muted small">(거래대금)</span></h6>
            <?php $tot = max(1, $ratio['buy']['amt'] + $ratio['sell']['amt']);
                  $bp = round($ratio['buy']['amt'] / $tot * 100); $sp = 100 - $bp; ?>
            <div class="bar-track mb-2">
                <div style="width:<?= $bp ?>%;background:#f04452;"></div>
                <div style="width:<?= $sp ?>%;background:#3182f6;"></div>
            </div>
            <div class="d-flex justify-content-between small">
                <span class="text-danger fw-bold">매수 <?= $bp ?>%</span>
                <span class="text-primary fw-bold">매도 <?= $sp ?>%</span>
            </div>
            <div class="d-flex justify-content-between small text-muted mt-2 num">
                <span><?= number_format($ratio['buy']['cnt']) ?>건</span>
                <span><?= number_format($ratio['sell']['cnt']) ?>건</span>
            </div>
        </div>
    </div>

    <!-- 시간대별 거래량 -->
    <div class="col-md-4">
        <div class="card p-3 h-100">
            <h6 class="mb-2">시간대별 거래 건수</h6>
            <?php
            $hoursRange = range(9, 15);
            $maxCnt = 1;
            foreach ($hoursRange as $h) $maxCnt = max($maxCnt, $hourly[$h]['cnt'] ?? 0);
            ?>
            <div class="hour-chart">
                <?php foreach ($hoursRange as $h): $c = $hourly[$h]['cnt'] ?? 0; ?>
                    <div class="hour-col" title="<?= $h ?>시 · <?= $c ?>건">
                        <div class="b" style="height:<?= round($c / $maxCnt * 100) ?>%;"></div>
                        <span class="lbl"><?= $h ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="text-muted small mt-1 text-center">장 시간(9~15시) 체결 분포</div>
        </div>
    </div>
</div>

<!-- 리더보드 -->
<div class="row g-3 mt-1">
    <?php
    $boards = [['🚀 수익률 TOP 5', $topProfit], ['📉 손실 TOP 5', $topLoss]];
    foreach ($boards as [$title, $rows]): ?>
        <div class="col-md-6">
            <div class="card p-3 h-100">
                <h6 class="mb-3"><?= $title ?> <span class="text-muted small">(초기 <?= number_format(INITIAL_CASH) ?>원 대비)</span></h6>
                <?php if (!$rows): ?>
                    <p class="text-muted mb-0">아직 유저 데이터가 없습니다.</p>
                <?php else: ?>
                    <table class="table table-sm mb-0">
                        <thead><tr><th>#</th><th>닉네임</th><th class="text-end">총자산</th><th class="text-end">수익률</th></tr></thead>
                        <tbody>
                        <?php foreach ($rows as $i => $u): $cls = $u['rate'] > 0 ? 'up' : ($u['rate'] < 0 ? 'down' : ''); ?>
                            <tr>
                                <td><?= $i + 1 ?></td>
                                <td><?= htmlspecialchars($u['nickname']) ?></td>
                                <td class="text-end num"><?= number_format($u['total_asset']) ?>원</td>
                                <td class="text-end num lead-rate <?= $cls ?>"><?= $u['rate'] > 0 ? '+' : '' ?><?= $u['rate'] ?>%</td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<!-- 인기 관심종목 + 최근 거래 -->
<div class="row g-3 mt-1">
    <div class="col-md-5">
        <div class="card p-3 h-100">
            <h6 class="mb-3">인기 관심종목 TOP 5</h6>
            <table class="table table-sm mb-0">
                <thead><tr><th>#</th><th>종목명</th><th class="text-end">등록 수</th></tr></thead>
                <tbody>
                <?php foreach ($topStocks as $i => $stock): ?>
                    <tr><td><?= $i + 1 ?></td><td><?= htmlspecialchars($stock['stock_name']) ?> <span class="text-muted small"><?= htmlspecialchars($stock['stock_code']) ?></span></td><td class="text-end num"><?= $stock['cnt'] ?>명</td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <div class="col-md-7">
        <div class="card p-3 h-100">
            <h6 class="mb-3">최근 거래 내역</h6>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead><tr><th>시각</th><th>유저</th><th>종목</th><th>구분</th><th class="text-end">수량</th><th class="text-end">총액</th></tr></thead>
                    <tbody>
                    <?php foreach ($recentOrders as $o): ?>
                        <tr>
                            <td class="small"><?= htmlspecialchars(substr($o['created_at'], 5, 11)) ?></td>
                            <td><?= htmlspecialchars($o['nickname']) ?></td>
                            <td><?= htmlspecialchars($o['stock_name']) ?></td>
                            <td><?= $o['order_type'] === 'buy' ? '<span class="text-danger">매수</span>' : '<span class="text-primary">매도</span>' ?></td>
                            <td class="text-end num"><?= number_format($o['quantity']) ?></td>
                            <td class="text-end num"><?= number_format($o['total_amount']) ?>원</td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- 에러 로그 수집기 -->
<div class="card p-3 mt-4">
    <h6 class="mb-3">최근 에러 로그 <span class="text-muted small">(주문 실패·시스템 오류)</span></h6>
    <?php if (!$errors): ?>
        <p class="text-muted mb-0">수집된 에러가 없습니다. 👍</p>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table table-sm mb-0">
            <thead class="table-light"><tr><th>일시</th><th>레벨</th><th>영역</th><th>메시지</th></tr></thead>
            <tbody>
            <?php foreach ($errors as $e): ?>
                <tr>
                    <td class="small text-nowrap"><?= htmlspecialchars(substr($e['created_at'], 5, 14)) ?></td>
                    <td><span class="badge bg-<?= $e['level'] === 'error' ? 'danger' : 'secondary' ?>"><?= htmlspecialchars($e['level']) ?></span></td>
                    <td class="small"><?= htmlspecialchars($e['context']) ?></td>
                    <td class="small"><?= htmlspecialchars($e['message']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<div class="mb-5"></div>
<?php include 'includes/footer.php'; ?>
