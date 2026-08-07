<?php
// =============================================================
// KIS 일봉 → stock_candles_1d 백필
// =============================================================
// 왜 필요한가:
//   우리가 직접 수집한 데이터는 2026-07-20부터뿐이다. 그래서 '3개월/1년' 탭은
//   우리 데이터만으로는 영원히 채워지지 않는다. KIS 일봉 API에서 과거를 받아와 채운다.
//
// ⚠️ KIS 일봉은 한 호출에 100행이 상한이고, 요청 구간이 더 길어도
//    '끝 날짜(FID_INPUT_DATE_2) 기준 최근 100행'만 돌려준다(실호출로 확인).
//    → 더 과거로 가려면 끝 날짜를 뒤로 밀어가며 여러 번 호출해야 한다. 그 반복이 이 파일의 핵심.
//
// 사용법:
//   php cron/backfill_daily.php                  최근 100영업일만 (종목당 1회 호출) ← 매일 1회 스케줄러용
//   php cron/backfill_daily.php --years=2        2년치 소급 (최초 1회, 종목당 약 5회 호출)
//   php cron/backfill_daily.php --years=5        5년치 소급 (오래 걸림)
//   php cron/backfill_daily.php --code=005930 --years=5   한 종목만
// -------------------------------------------------------------
require_once __DIR__ . '/../config/kis_api.php';
/** @var mysqli $conn */

$opt = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--(\w+)=(.+)$/', $a, $m)) $opt[$m[1]] = $m[2];
}
$years   = isset($opt['years']) ? max(0, (float) $opt['years']) : 0;   // 0 = 최근 100영업일만
$oneCode = $opt['code'] ?? null;

$SLEEP_US = 300000;   // 호출 간격 0.3초 — KIS 초당 호출 제한 회피 (초과분은 kisQuotationGet이 재시도)

// 일봉 테이블 자동 생성 (형이 SQL 안 돌려도 되게) — 정의는 user/func.php 와 한 곳에서 관리
require_once __DIR__ . '/../user/func.php';
ensureCandle1dTable($conn);

// -------------------------------------------------------------
// 대상 종목 = 기본종목 ∪ 자동순위종목 ∪ 관심종목  (fetch_price.php 와 같은 집합)
// -------------------------------------------------------------
if ($oneCode) {
    $targets = [$oneCode => null];
} else {
    $defaults   = require __DIR__ . '/../config/default_stocks.php';
    $rankedFile = __DIR__ . '/../config/ranked_stocks.php';
    $ranked     = file_exists($rankedFile) ? (require $rankedFile) : [];
    $watched    = [];
    $res = mysqli_query($conn, "SELECT DISTINCT stock_code FROM watchlist");
    while ($row = mysqli_fetch_assoc($res)) $watched[$row['stock_code']] = null;
    $targets = $defaults + $ranked + $watched;
}

// ⚠️ 기준 날짜는 DB 시계로 (PHP는 UTC, DB 세션은 KST → PHP date()면 하루가 어긋난다)
$today = mysqli_fetch_assoc(mysqli_query($conn, "SELECT DATE_FORMAT(CURDATE(), '%Y%m%d') AS d"))['d'];
$limitDate = $years > 0
    ? mysqli_fetch_assoc(mysqli_query(
        $conn,
        "SELECT DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL " . (int) round($years * 365) . " DAY), '%Y%m%d') AS d"
    ))['d']
    : null;

echo "대상 " . count($targets) . "종목 / " . ($years > 0 ? "{$years}년 소급 ({$limitDate}까지)" : "최근 100영업일만") . "\n\n";

// -------------------------------------------------------------
// 저장 — 배치 upsert
// -------------------------------------------------------------
// KIS 값이 항상 정답이므로 그대로 덮어쓴다(ON DUPLICATE KEY UPDATE).
// 수정주가(액면분할 등)는 시간이 지나면 과거 값 자체가 바뀌므로 갱신되는 게 맞다.
function saveDaily($conn, $code, $rows)
{
    if (!$rows) return 0;
    $vals = [];
    foreach ($rows as $r) {
        $vals[] = sprintf(
            "('%s','%s',%d,%d,%d,%d,%d,%d)",
            mysqli_real_escape_string($conn, $code),
            mysqli_real_escape_string($conn, $r['date']),
            $r['open'], $r['high'], $r['low'], $r['close'], $r['volume'], $r['trade_value']
        );
    }
    $sql = "INSERT INTO stock_candles_1d (stock_code, d, open_p, high_p, low_p, close_p, volume, trade_value)
            VALUES " . implode(',', $vals) . "
            ON DUPLICATE KEY UPDATE
                open_p = VALUES(open_p), high_p = VALUES(high_p), low_p = VALUES(low_p),
                close_p = VALUES(close_p), volume = VALUES(volume), trade_value = VALUES(trade_value)";
    mysqli_query($conn, $sql) or print("  ⚠️ 저장 실패: " . mysqli_error($conn) . "\n");
    return count($rows);
}

$t0 = microtime(true);
$totalRows = 0; $totalCalls = 0; $okStocks = 0; $failStocks = [];

foreach ($targets as $code => $_name) {
    $to = $today;
    $stockRows = 0;
    $guard = 0;                       // 무한루프 방지 — 종목당 최대 호출 수

    while (true) {
        // 한 번에 100행(≈140일)이 오므로 요청 구간은 넉넉히 200일로 잡는다.
        $from = date('Ymd', strtotime($to . ' -200 days'));
        $rows = getDailyChart($code, $from, $to);
        $totalCalls++;
        usleep($SLEEP_US);

        if (!$rows) break;                          // 더 이상 과거 데이터 없음(상장 전 등)
        $stockRows += saveDaily($conn, $code, $rows);

        if ($years <= 0) break;                     // 최근분만 원하면 1회로 끝
        $oldest = $rows[0]['date'];                 // getDailyChart가 오름차순 정렬해서 준다
        if (str_replace('-', '', $oldest) <= $limitDate) break;   // 목표 기간까지 도달
        if (count($rows) < 100) break;              // 100행 미만 = 그 종목의 상장 시작까지 왔다는 뜻

        $to = date('Ymd', strtotime($oldest . ' -1 day'));        // 그 앞으로 한 칸 더
        if (++$guard >= 25) break;                  // 25회 = 약 10년치. 그 이상은 비정상.
    }

    if ($stockRows > 0) { $okStocks++; $totalRows += $stockRows; }
    else $failStocks[] = $code;

    printf("%-8s %5d행\n", $code, $stockRows);
}

$sec = round(microtime(true) - $t0, 1);
echo "\n완료: {$okStocks}종목 / {$totalRows}행 / API 호출 {$totalCalls}회 / {$sec}초\n";
if ($failStocks) {
    echo "데이터 못 받은 종목 " . count($failStocks) . "개: " . implode(', ', array_slice($failStocks, 0, 20))
        . (count($failStocks) > 20 ? ' …' : '') . "\n";
}
