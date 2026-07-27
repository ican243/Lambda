<?php
require_once __DIR__ . '/../config/kis_api.php';

// -----------------------------------------------------------
// 수집 대상 = 기본종목 ∪ 관심종목(watchlist)
// WS(실시간)가 꺼져있을 때의 백업 수집 + 야간/장외 보정용.
// -----------------------------------------------------------
$defaults = require __DIR__ . '/../config/default_stocks.php';

// 거래대금 상위 자동종목 (cron/update_rankings.php가 생성) — 있으면 함께 수집
$rankedFile = __DIR__ . '/../config/ranked_stocks.php';
$ranked = file_exists($rankedFile) ? (require $rankedFile) : [];

// 관심종목에 등록된 종목도 함께 수집
$watched = [];
$res = mysqli_query($conn, "SELECT DISTINCT stock_code FROM watchlist");
while ($row = mysqli_fetch_assoc($res)) {
    $watched[$row['stock_code']] = null;   // 이름은 API가 채워줌
}

// 기본종목 ∪ 자동순위종목 ∪ 관심종목
$targets = $defaults + $ranked + $watched;

foreach ($targets as $code => $name) {
    try {
        $result = fetchAndSaveStockPrice($conn, $code, $name);
        echo date('Y-m-d H:i:s') . " - {$code} 저장 완료: {$result['price']}원\n";
        usleep(500000);   // 초당 거래건수 제한 회피용 (0.5초 간격, 종목 많으니 여유있게)
    } catch (Exception $e) {
        echo date('Y-m-d H:i:s') . " - {$code} 실패: " . $e->getMessage() . "\n";
    }
}
