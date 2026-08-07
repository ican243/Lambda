<?php
// =============================================================
// stock_logs(원시 기록) → stock_candles_1m(1분봉) 보정 집계
// =============================================================
// 역할 분담이 중요하다:
//   · 주 경로 = 수집기(stock-ws/candles.js). 모든 체결을 메모리에서 누적해 정밀한 분봉을 쓴다.
//   · 이 파일 = 보수 경로. 수집기가 꺼져 있던 구간을 stock_logs 로 뒤늦게 메운다.
//
// 그래서 이 스크립트는 **이미 존재하는 분은 절대 건드리지 않는다**(INSERT IGNORE).
// 수집기가 만든 정밀한 분봉을, 10초 간격으로 듬성한 stock_logs 집계로 덮어쓰면 손해다.
//
// 사용법:
//   php cron/aggregate_candles.php              최근 30분 (작업 스케줄러용 기본값)
//   php cron/aggregate_candles.php 180          최근 180분
//   php cron/aggregate_candles.php 2026-08-05   그날 하루 전체
//   php cron/aggregate_candles.php --all        보유한 전 구간 (최초 1회 백필용, 오래 걸림)
// -------------------------------------------------------------
require_once __DIR__ . '/../config/db.php';
/** @var mysqli $conn */

$arg = $argv[1] ?? '30';
$t0  = microtime(true);

// ⚠️ 시각 기준은 반드시 DB 시계(NOW())를 쓴다.
//    PHP는 UTC, DB 세션은 KST(+09:00)라 PHP date()로 구간을 잡으면 9시간 어긋난다.
//    또 '진행 중인 분'은 아직 체결이 더 들어올 수 있으므로 항상 제외한다(< 현재 분).
$now = mysqli_fetch_assoc(mysqli_query($conn, "SELECT NOW() AS n, DATE_FORMAT(NOW(), '%Y-%m-%d %H:%i:00') AS cur"))
    ?: exit("DB 시각을 못 읽었습니다.\n");
$curMinute = $now['cur'];

if ($arg === '--all') {
    $r = mysqli_fetch_assoc(mysqli_query($conn, "SELECT MIN(created_at) AS m FROM stock_logs"));
    $from = $r['m'] ?? null;
    if (!$from) exit("stock_logs 가 비어 있습니다.\n");
    $to = $curMinute;
} elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $arg)) {
    $from = $arg . ' 00:00:00';
    $to   = $arg . ' 23:59:59';
    if ($to > $curMinute) $to = $curMinute;
} else {
    $mins = max(1, (int) $arg);
    $r = mysqli_fetch_assoc(mysqli_query(
        $conn,
        "SELECT DATE_FORMAT(NOW() - INTERVAL {$mins} MINUTE, '%Y-%m-%d %H:%i:00') AS f"
    ));
    $from = $r['f'];
    $to   = $curMinute;
}

echo "집계 구간: {$from} ~ {$to} (진행 중인 분 {$curMinute} 은 제외)\n";

// -------------------------------------------------------------
// 집계 쿼리
// -------------------------------------------------------------
// SUBSTRING_INDEX(GROUP_CONCAT(... ORDER BY id ASC), ',', 1) 는
// "그룹 안에서 첫 번째 값" 을 뽑는 관용구다. MySQL에는 FIRST_VALUE를 집계로 쓰는 문법이 없어서
// 이 방식이 표준처럼 쓰인다. (id 순 = 저장된 순 = 시간 순)
//
// vol_delta(그 분에 실제 체결된 수량)는 MAX(volume) - MIN(volume) 로 근사한다.
//   · 원시 로그에는 '누적거래량'만 있고 건별 체결량이 없어서 이렇게 뺄 수밖에 없다.
//   · 수집기(candles.js)는 건별 체결량을 그대로 더하므로 훨씬 정확하다.
//     → 이 값이 근사치라는 점도 '이미 있는 분은 안 건드린다' 규칙의 이유다.
//
// ⚠️ group_concat_max_len 기본 1024바이트. 한 분에 값이 많으면 잘려서 시가/종가가 틀어진다.
mysqli_query($conn, "SET SESSION group_concat_max_len = 1048576");

$sql = "
    INSERT IGNORE INTO stock_candles_1m
        (stock_code, ts, open_p, high_p, low_p, close_p, volume, vol_delta, trade_value, ticks)
    SELECT
        stock_code,
        DATE_FORMAT(created_at, '%Y-%m-%d %H:%i:00') AS ts,
        CAST(SUBSTRING_INDEX(GROUP_CONCAT(price ORDER BY id ASC),  ',', 1) AS UNSIGNED) AS open_p,
        MAX(price) AS high_p,
        MIN(price) AS low_p,
        CAST(SUBSTRING_INDEX(GROUP_CONCAT(price ORDER BY id DESC), ',', 1) AS UNSIGNED) AS close_p,
        MAX(volume) AS volume,
        GREATEST(MAX(volume) - MIN(volume), 0) AS vol_delta,
        MAX(trade_value) AS trade_value,
        COUNT(*) AS ticks
    FROM stock_logs
    WHERE created_at >= ? AND created_at < ?
    GROUP BY stock_code, ts
";

$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, "ss", $from, $to);
mysqli_stmt_execute($stmt);
$inserted = mysqli_stmt_affected_rows($stmt);

$sec = round(microtime(true) - $t0, 2);
echo "새로 만든 분봉: {$inserted}개 ({$sec}초)\n";
echo "(이미 있던 분은 수집기가 만든 정밀 분봉이라 건드리지 않았습니다)\n";
