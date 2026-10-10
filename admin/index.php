<?php
require_once 'func.php';
/** @var mysqli $conn */   // config/db.php 에서 넘어옴 (에디터 자동완성·오탐 방지용)
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

// 상태 판정 → [점 색(ok|warn|bad|''), 문구]
function ageBadge($age) {
    if ($age === null) return ['', '데이터 없음'];
    if ($age < 120)   return ['ok', '실시간'];
    if ($age < 600)   return ['warn', '최근 수집'];
    return ['bad', '수집 지연'];
}
[$collectColor, $collectText] = ageBadge($collectAge);
function agoText($sec) {
    if ($sec === null) return '-';
    if ($sec < 60) return $sec . '초 전';
    if ($sec < 3600) return floor($sec / 60) . '분 전';
    if ($sec < 86400 * 2) return floor($sec / 3600) . '시간 전';
    return floor($sec / 86400) . '일 전';
}
// KIS 토큰 남은 시간 → [점 색, 문구]
if ($tokenLeft === null)     [$tokenColor, $tokenText] = ['', '정보 없음'];
elseif ($tokenLeft <= 0)     [$tokenColor, $tokenText] = ['bad', '만료됨'];
elseif ($tokenLeft < 3600)   [$tokenColor, $tokenText] = ['warn', '곧 만료 (' . floor($tokenLeft / 60) . '분 남음)'];
else                         [$tokenColor, $tokenText] = ['ok', number_format(floor($tokenLeft / 3600)) . '시간 남음'];
$initialCash = getInitialCash($conn);   // 수익률 순위의 기준 (관리자가 설정한 값)
$moneyFmt = function ($v) {             // 큰 금액은 억/만 단위로 (카드에서 한눈에)
    $v = (int) $v;
    if ($v >= 100000000) return number_format($v / 100000000, 1) . '억원';
    if ($v >= 10000)     return number_format(floor($v / 10000)) . '만원';
    return number_format($v) . '원';
};

$adminPage  = 'dashboard';
$adminTitle = '대시보드';

include 'includes/header.php';
?>

<div class="ad-head">
    <div>
        <h1>대시보드</h1>
        <p>서비스가 잘 돌아가는지, 사람들이 어떻게 거래하는지 한눈에 봐요.</p>
    </div>
</div>

<!-- 수집 지연/소켓 경고 -->
<?php if ($collectAge === null || $collectAge >= 600 || !$wsAlive): ?>
    <div class="ad-alert err">⚠️
        <span>
            <?php if (!$wsAlive): ?>실시간 시세 서버(stock-ws)가 응답하지 않아요. <?php endif; ?>
            <?php if ($collectAge === null): ?>아직 수집된 시세가 없어요.<?php elseif ($collectAge >= 600): ?>시세 수집이 <b><?= agoText($collectAge) ?></b>에서 멈춰 있어요.<?php endif; ?>
            <small>cron/fetch_price.php · stock-ws 실행 상태와 한국투자증권 API 연결을 확인해 주세요.</small>
        </span>
    </div>
<?php endif; ?>

<!-- ===== 운영 상태 ===== -->
<div class="ad-grid c4">
    <div class="ad-card ad-kpi">
        <div class="k"><span class="ad-dot <?= $collectColor ?>"></span>시세 수집</div>
        <div class="v"><?= $collectText ?></div>
        <div class="s">마지막 <?= agoText($collectAge) ?><?= $lastCollect ? '<br>' . htmlspecialchars(substr($lastCollect, 0, 16)) : '' ?></div>
    </div>
    <div class="ad-card ad-kpi">
        <div class="k"><span class="ad-dot <?= $wsAlive ? 'ok' : 'bad' ?>"></span>실시간 서버</div>
        <div class="v"><?= $wsAlive ? '연결됨' : '끊김' ?></div>
        <div class="s">stock-ws · 포트 8080</div>
    </div>
    <div class="ad-card ad-kpi">
        <div class="k"><span class="ad-dot <?= $tokenColor ?>"></span>KIS API 토큰</div>
        <div class="v"><?= $tokenText ?></div>
        <div class="s">만료되면 자동 재발급</div>
    </div>
    <div class="ad-card ad-kpi">
        <div class="k"><span class="ad-dot <?= $todayErrors > 0 ? 'bad' : 'ok' ?>"></span>오늘 에러</div>
        <div class="v <?= $todayErrors > 0 ? 'up' : '' ?>"><?= number_format($todayErrors) ?>건</div>
        <div class="s">시세 데이터 누적 <?= number_format(getTotalLogCount($conn)) ?>건</div>
    </div>
</div>

<!-- ===== 이상 거래 감지 (FDS) ===== -->
<?php if ($fdsIps || $fdsRapid): ?>
<div class="ad-card warn" style="margin-top:var(--sp-4);">
    <div class="ad-card-h"><span class="ad-card-t">🚨 이상 징후 감지 <small>FDS · 규칙 기반</small></span></div>
    <div class="ad-grid c2">
        <?php if ($fdsIps): ?>
        <div>
            <div class="ad-label">한 IP에서 여러 계정 (최근 7일)</div>
            <div class="ad-table-wrap"><table class="ad-table">
                <thead><tr><th>IP</th><th class="r">계정 수</th><th class="r">접속</th></tr></thead>
                <tbody>
                <?php foreach ($fdsIps as $f): ?>
                    <tr><td><?= htmlspecialchars($f['ip']) ?></td><td class="r up"><b><?= (int) $f['accounts'] ?>개</b></td><td class="r"><?= number_format($f['hits']) ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        </div>
        <?php endif; ?>
        <?php if ($fdsRapid): ?>
        <div>
            <div class="ad-label">짧은 시간 잦은 거래 (10분 내 10건 이상)</div>
            <div class="ad-table-wrap"><table class="ad-table">
                <thead><tr><th>회원</th><th class="r">거래 수</th><th class="r">최근</th></tr></thead>
                <tbody>
                <?php foreach ($fdsRapid as $f): ?>
                    <tr><td><a href="user_detail.php?id=<?= (int) $f['user_id'] ?>"><?= htmlspecialchars($f['nickname']) ?></a></td><td class="r up"><b><?= (int) $f['cnt'] ?>건</b></td><td class="r sm"><?= htmlspecialchars(substr($f['last_at'], 11, 5)) ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        </div>
        <?php endif; ?>
    </div>
    <p class="ad-note">※ 학습용 규칙 기반 탐지예요. 실제 증권사는 실시간 데이터 흐름 + 규칙 엔진/머신러닝으로 훨씬 정교하게 잡아요.</p>
</div>
<?php endif; ?>

<!-- ===== 거래 현황 ===== -->
<div class="ad-section-t">
    거래 현황
    <span class="ad-seg">
        <?php foreach (['today' => '오늘', 'week' => '최근 7일', 'month' => '최근 30일'] as $p => $lbl): ?>
            <a href="?period=<?= $p ?>" class="<?= $period === $p ? 'on' : '' ?>"><?= $lbl ?></a>
        <?php endforeach; ?>
    </span>
</div>

<div class="ad-grid c4">
    <div class="ad-card ad-kpi"><div class="k">전체 회원</div><div class="v"><?= number_format($totalUsers) ?>명</div></div>
    <div class="ad-card ad-kpi"><div class="k"><?= $periodLabel ?> 가입</div><div class="v"><?= number_format($signups) ?>명</div></div>
    <div class="ad-card ad-kpi"><div class="k"><?= $periodLabel ?> 거래</div><div class="v"><?= number_format($periodOrders) ?>건</div></div>
    <div class="ad-card ad-kpi"><div class="k"><?= $periodLabel ?> 거래대금</div><div class="v"><?= $moneyFmt($periodAmount) ?></div><div class="s"><?= number_format($periodAmount) ?>원</div></div>
</div>

<div class="ad-grid c3">
    <!-- 최다 거래 종목 -->
    <div class="ad-card">
        <div class="ad-card-h"><span class="ad-card-t">많이 거래된 종목 <small>거래대금 TOP 5</small></span></div>
        <?php if (!$topTraded): ?>
            <div class="ad-empty"><b>📭</b>이 기간엔 거래가 없어요.</div>
        <?php else: ?>
            <div class="ad-table-wrap"><table class="ad-table">
                <tbody>
                <?php foreach ($topTraded as $i => $s): ?>
                    <tr>
                        <td class="ad-rank"><?= $i + 1 ?></td>
                        <td><?= htmlspecialchars($s['stock_name']) ?><div class="sm"><?= htmlspecialchars($s['stock_code']) ?> · <?= number_format($s['trades']) ?>건</div></td>
                        <td class="r nw"><b><?= $moneyFmt($s['amt']) ?></b></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php endif; ?>
    </div>

    <!-- 구매 vs 판매 -->
    <div class="ad-card">
        <div class="ad-card-h"><span class="ad-card-t">구매 vs 판매 <small>거래대금 비율</small></span></div>
        <?php $tot = $ratio['buy']['amt'] + $ratio['sell']['amt'];
              $bp = $tot > 0 ? round($ratio['buy']['amt'] / $tot * 100) : 50; $sp = 100 - $bp; ?>
        <?php if ($tot <= 0): ?>
            <div class="ad-empty"><b>📭</b>이 기간엔 거래가 없어요.</div>
        <?php else: ?>
            <div style="display:flex;justify-content:space-between;font-size:var(--fs-xl);font-weight:700;margin-bottom:var(--sp-3);">
                <span class="up"><?= $bp ?>%</span><span class="down"><?= $sp ?>%</span>
            </div>
            <div class="ad-ratio"><span style="width:<?= $bp ?>%;background:var(--up);"></span><span style="width:<?= $sp ?>%;background:var(--down);"></span></div>
            <div style="display:flex;justify-content:space-between;margin-top:var(--sp-3);font-size:var(--fs-sm);">
                <span><b class="up">구매</b> <span class="muted"><?= number_format($ratio['buy']['cnt']) ?>건 · <?= $moneyFmt($ratio['buy']['amt']) ?></span></span>
                <span><span class="muted"><?= $moneyFmt($ratio['sell']['amt']) ?> · <?= number_format($ratio['sell']['cnt']) ?>건</span> <b class="down">판매</b></span>
            </div>
        <?php endif; ?>
    </div>

    <!-- 시간대별 거래 -->
    <div class="ad-card">
        <div class="ad-card-h"><span class="ad-card-t">시간대별 거래 <small>장 시간 9~15시</small></span></div>
        <?php
        $hoursRange = range(9, 15);
        $maxCnt = 1;
        foreach ($hoursRange as $h) $maxCnt = max($maxCnt, $hourly[$h]['cnt'] ?? 0);
        ?>
        <div class="ad-bars">
            <?php foreach ($hoursRange as $h): $c = $hourly[$h]['cnt'] ?? 0; ?>
                <div class="col" title="<?= $h ?>시 · <?= $c ?>건">
                    <span class="n"><?= $c ?: '' ?></span>
                    <div class="b" style="height:<?= round($c / $maxCnt * 100) ?>%;<?= $c ? '' : 'background:var(--line);' ?>"></div>
                    <span class="l"><?= $h ?>시</span>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- ===== 순위 ===== -->
<div class="ad-grid c3">
    <?php
    $boards = [['🚀 수익률 TOP 5', $topProfit], ['📉 손실 TOP 5', $topLoss]];
    foreach ($boards as [$title, $rows]): ?>
        <div class="ad-card">
            <div class="ad-card-h"><span class="ad-card-t"><?= $title ?> <small>시작 금액 <?= $moneyFmt($initialCash) ?> 대비</small></span></div>
            <?php if (!$rows): ?>
                <div class="ad-empty"><b>📭</b>아직 회원 데이터가 없어요.</div>
            <?php else: ?>
                <div class="ad-table-wrap"><table class="ad-table">
                    <tbody>
                    <?php foreach ($rows as $i => $u): $cls = $u['rate'] > 0 ? 'up' : ($u['rate'] < 0 ? 'down' : 'flat'); ?>
                        <tr>
                            <td class="ad-rank"><?= $i + 1 ?></td>
                            <td><a href="user_detail.php?id=<?= (int) $u['id'] ?>"><?= htmlspecialchars($u['nickname']) ?></a><div class="sm"><?= number_format($u['total_asset']) ?>원</div></td>
                            <td class="r nw <?= $cls ?>"><b><?= $u['rate'] > 0 ? '+' : '' ?><?= $u['rate'] ?>%</b></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>

    <div class="ad-card">
        <div class="ad-card-h"><span class="ad-card-t">⭐ 인기 관심종목 <small>TOP 5</small></span></div>
        <?php if (!$topStocks): ?>
            <div class="ad-empty"><b>📭</b>아직 관심종목이 없어요.</div>
        <?php else: ?>
            <div class="ad-table-wrap"><table class="ad-table">
                <tbody>
                <?php foreach ($topStocks as $i => $stock): ?>
                    <tr><td class="ad-rank"><?= $i + 1 ?></td><td><?= htmlspecialchars($stock['stock_name']) ?><div class="sm"><?= htmlspecialchars($stock['stock_code']) ?></div></td><td class="r nw"><b><?= number_format($stock['cnt']) ?>명</b></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php endif; ?>
    </div>
</div>

<!-- ===== 최근 거래 · 에러 로그 ===== -->
<div class="ad-grid c2">
    <div class="ad-card">
        <div class="ad-card-h"><span class="ad-card-t">최근 거래</span></div>
        <?php if (!$recentOrders): ?>
            <div class="ad-empty"><b>🧾</b>아직 거래가 없어요.</div>
        <?php else: ?>
            <div class="ad-table-wrap scroll"><table class="ad-table">
                <thead><tr><th>시각</th><th>회원</th><th>종목</th><th class="c">구분</th><th class="r">금액</th></tr></thead>
                <tbody>
                <?php foreach ($recentOrders as $o): $buy = $o['order_type'] === 'buy'; ?>
                    <tr>
                        <td class="sm nw"><?= htmlspecialchars(substr($o['created_at'], 5, 11)) ?></td>
                        <td class="nw"><?= htmlspecialchars($o['nickname']) ?></td>
                        <td><?= htmlspecialchars($o['stock_name']) ?><div class="sm"><?= number_format($o['quantity']) ?>주</div></td>
                        <td class="c"><span class="ad-badge <?= $buy ? 'red' : 'blue' ?>"><?= $buy ? '구매' : '판매' ?></span></td>
                        <td class="r nw"><?= number_format($o['total_amount']) ?>원</td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php endif; ?>
    </div>

    <div class="ad-card">
        <div class="ad-card-h"><span class="ad-card-t">최근 에러 로그 <small>주문 실패 · 시스템 오류</small></span></div>
        <?php if (!$errors): ?>
            <div class="ad-empty"><b>👍</b>수집된 에러가 없어요.</div>
        <?php else: ?>
            <div class="ad-table-wrap scroll"><table class="ad-table">
                <thead><tr><th>일시</th><th>영역</th><th>내용</th></tr></thead>
                <tbody>
                <?php foreach ($errors as $e): ?>
                    <tr>
                        <td class="sm nw"><?= htmlspecialchars(substr($e['created_at'], 5, 11)) ?></td>
                        <td class="nw"><span class="ad-badge <?= $e['level'] === 'error' ? 'bad' : '' ?>"><?= htmlspecialchars($e['context']) ?></span></td>
                        <td class="sm" style="word-break:break-all;"><?= htmlspecialchars($e['message']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php endif; ?>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
