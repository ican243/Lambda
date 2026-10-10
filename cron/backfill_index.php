<?php
// =============================================================
// KIS 지수(코스피/코스닥) 일봉 → index_candles_1d 백필
// =============================================================
// backfill_daily.php(개별종목용)와 같은 구조, 대상만 지수 2개(코스피/코스닥)로 고정.
// 한 번에 최대 100행(≈5개월) 제약도 동일해서 여러 번 나눠 호출한다.
//
// 사용법:
//   php cron/backfill_index.php                  최근 100영업일만 (매일 자동갱신용 기본값)
//   php cron/backfill_index.php --years=2        2년치 소급 (최초 1회)
// -------------------------------------------------------------
require_once __DIR__ . '/../config/kis_api.php';
require_once __DIR__ . '/../user/func.php';
/** @var mysqli $conn */

$opt = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--(\w+)=(.+)$/', $a, $m)) $opt[$m[1]] = $m[2];
}
$years = isset($opt['years']) ? max(0, (float) $opt['years']) : 0;

ensureIndexCandle1dTable($conn);

$targets = ['0001' => '코스피', '1001' => '코스닥'];

$today = mysqli_fetch_assoc(mysqli_query($conn, "SELECT DATE_FORMAT(CURDATE(), '%Y%m%d') AS d"))['d'];
$limitDate = $years > 0
    ? mysqli_fetch_assoc(mysqli_query(
        $conn,
        "SELECT DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL " . (int) round($years * 365) . " DAY), '%Y%m%d') AS d"
    ))['d']
    : null;

function saveIndexDaily($conn, $code, $name, $rows)
{
    if (!$rows) return 0;
    $vals = [];
    foreach ($rows as $r) {
        $vals[] = sprintf(
            "('%s','%s','%s',%.2f,%.2f,%.2f,%.2f,%d,%d)",
            mysqli_real_escape_string($conn, $code),
            mysqli_real_escape_string($conn, $name),
            mysqli_real_escape_string($conn, $r['date']),
            $r['open'], $r['high'], $r['low'], $r['close'], $r['volume'], $r['trade_value']
        );
    }
    $sql = "INSERT INTO index_candles_1d (index_code, index_name, d, open_p, high_p, low_p, close_p, volume, trade_value)
            VALUES " . implode(',', $vals) . "
            ON DUPLICATE KEY UPDATE
                open_p = VALUES(open_p), high_p = VALUES(high_p), low_p = VALUES(low_p),
                close_p = VALUES(close_p), volume = VALUES(volume), trade_value = VALUES(trade_value)";
    mysqli_query($conn, $sql) or print("  ⚠️ 저장 실패: " . mysqli_error($conn) . "\n");
    return count($rows);
}

$totalRows = 0;
foreach ($targets as $code => $name) {
    $to = $today;
    $codeRows = 0;
    $guard = 0;
    $prevOldest = null;

    while (true) {
        $from = date('Ymd', strtotime($to . ' -200 days'));
        $rows = getIndexDailyChart($code, $from, $to);
        usleep(300000);

        if (!$rows) break;
        $codeRows += saveIndexDaily($conn, $code, $name, $rows);

        if ($years <= 0) break;
        $oldest = $rows[0]['date'];
        if (str_replace('-', '', $oldest) <= $limitDate) break;
        // ⚠️ 지수는 개별종목과 달리 '상장일'이 없어 count($rows)<100으로 끝을 판단할 수 없다.
        //    실호출 확인 결과 지수 API는 한 번에 최대 50행만 준다(종목은 100행). $oldest가
        //    직전과 같으면(더 못 감) 무한루프 방지로 멈춘다.
        if (isset($prevOldest) && $prevOldest === $oldest) break;
        $prevOldest = $oldest;

        $to = date('Ymd', strtotime($oldest . ' -1 day'));
        if (++$guard >= 25) break;
    }

    printf("%-6s(%s)  %5d행\n", $code, $name, $codeRows);
    $totalRows += $codeRows;
}

echo "완료: {$totalRows}행\n";
