<?php
// =============================================================
// stock_logs 오래된 원시 기록 정리
// =============================================================
// 왜 필요한가:
//   stock_logs 는 '틱 원본'이라 계속 불어난다(현재 2,594,121행 / 344.7MB, 인덱스 포함).
//   그런데 차트가 실제로 필요로 하는 건 1분봉이고, 1분봉은 원본의 1/35 크기다.
//   → 최근 N일치 원본만 남기고, 그 이전은 1분봉으로 대체한다. 데이터를 '버리는' 게 아니라
//     '틱 → 분봉으로 압축 교환'하는 것이다.
//
// 🔒 안전장치 (이 스크립트에서 가장 중요한 부분):
//   그날의 1분봉이 제대로 만들어져 있지 않으면 그날은 절대 지우지 않는다.
//   분봉 없이 원본을 지우면 그 구간은 영영 복구 불가다.
//   판단 기준은 '분봉이 그날 로그를 남긴 종목의 90% 이상을 덮고 있는가'.
//
// 사용법:
//   php cron/purge_logs.php --dry      실제로 지우지 않고 무엇이 지워질지만 출력 (먼저 이걸로 확인!)
//   php cron/purge_logs.php            기본 7일 보존
//   php cron/purge_logs.php 14         14일 보존
//   php cron/purge_logs.php 7 --dry
// -------------------------------------------------------------
require_once __DIR__ . '/../config/db.php';
/** @var mysqli $conn */

$args     = array_slice($argv, 1);
$dry      = in_array('--dry', $args, true);
$keepDays = 7;
foreach ($args as $a) {
    if (ctype_digit($a)) { $keepDays = max(1, (int) $a); break; }
}

$CHUNK    = 50000;   // 한 번에 지우는 행 수. 통째로 DELETE 하면 락이 오래 걸려 수집기가 밀린다.
$COVERAGE = 0.90;    // 분봉이 이만큼 종목을 덮어야 그날을 지운다

// ⚠️ 기준일은 DB 시계로 계산한다 (PHP는 UTC, DB 세션은 KST → PHP date()로 잡으면 하루가 어긋난다)
$cut = mysqli_fetch_assoc(mysqli_query(
    $conn,
    "SELECT DATE_SUB(CURDATE(), INTERVAL {$keepDays} DAY) AS c"
))['c'];

echo "보존 기간: {$keepDays}일 → {$cut} 이전 데이터가 대상" . ($dry ? "  [드라이런]" : "") . "\n\n";

// 대상 날짜 목록 + 그날 로그를 남긴 종목 수
$res = mysqli_query($conn, "
    SELECT DATE(created_at) AS d, COUNT(*) AS rows_n, COUNT(DISTINCT stock_code) AS codes_n
    FROM stock_logs
    WHERE created_at < '{$cut}'
    GROUP BY d ORDER BY d ASC
");
$days = [];
while ($r = mysqli_fetch_assoc($res)) $days[] = $r;

if (!$days) exit("지울 대상이 없습니다.\n");

$totalDeleted = 0;
$skipped = 0;
$t0 = microtime(true);

foreach ($days as $day) {
    $d      = $day['d'];
    $rowsN  = (int) $day['rows_n'];
    $codesN = (int) $day['codes_n'];

    // 안전장치: 그날 분봉이 종목을 얼마나 덮고 있나?
    $c = mysqli_fetch_assoc(mysqli_query($conn, "
        SELECT COUNT(*) AS n, COUNT(DISTINCT stock_code) AS codes_n
        FROM stock_candles_1m WHERE DATE(ts) = '{$d}'
    "));
    $candleCodes = (int) $c['codes_n'];
    $coverage = $codesN > 0 ? $candleCodes / $codesN : 0;

    if ((int) $c['n'] === 0 || $coverage < $COVERAGE) {
        printf("⏭  %s  건너뜀 — 분봉 커버리지 %d/%d 종목(%.0f%%). 먼저 aggregate_candles.php %s 를 돌리세요.\n",
            $d, $candleCodes, $codesN, $coverage * 100, $d);
        $skipped++;
        continue;
    }

    if ($dry) {
        printf("🗑  %s  삭제 예정 %s행 (분봉 %s개로 대체됨, 커버리지 %.0f%%)\n",
            $d, number_format($rowsN), number_format((int) $c['n']), $coverage * 100);
        $totalDeleted += $rowsN;
        continue;
    }

    // 청크 삭제 — LIMIT 로 잘라 여러 번 돈다. 사이사이 수집기가 INSERT 할 틈이 생긴다.
    $deleted = 0;
    while (true) {
        mysqli_query($conn, "DELETE FROM stock_logs WHERE DATE(created_at) = '{$d}' LIMIT {$CHUNK}");
        $n = mysqli_affected_rows($conn);
        $deleted += $n;
        if ($n < $CHUNK) break;
        usleep(200000);   // 0.2초 숨 고르기
    }
    $totalDeleted += $deleted;
    printf("✅ %s  %s행 삭제\n", $d, number_format($deleted));
}

$sec = round(microtime(true) - $t0, 1);
echo "\n" . ($dry ? "삭제 예정 합계: " : "삭제 합계: ") . number_format($totalDeleted) . "행"
    . ($skipped ? " / 건너뛴 날 {$skipped}일" : "") . " ({$sec}초)\n";

if (!$dry && $totalDeleted > 0) {
    // InnoDB는 DELETE 만으로 파일 크기가 줄지 않는다(빈 공간을 재사용할 뿐).
    // 디스크를 실제로 반납하려면 OPTIMIZE TABLE 이 필요한데, 테이블을 통째로 다시 쓰는
    // 무거운 작업이라 장중에 돌리면 수집이 멈춘다. 그래서 자동 실행하지 않고 안내만 한다.
    echo "\n💡 디스크를 실제로 반납하려면 장 마감 후 아래를 한 번 실행하세요(수 분 소요):\n";
    echo "   mysql> OPTIMIZE TABLE stock_logs;\n";
}
