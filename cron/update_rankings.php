<?php
// -----------------------------------------------------------
// 거래대금 상위 종목 자동 갱신 (KIS volume-rank / FHPST01710000)
//   1) 상위 종목의 시세를 stock_latest에 즉시 반영 → 홈 거래대금 순위가 실제와 일치
//   2) config/ranked_stocks.php(기계관리 파일) 재생성 → fetch_price가 계속 수집
//   ※ 형이 수기로 고른 config/default_stocks.php 는 건드리지 않음.
// 실행: php.exe cron/update_rankings.php  (장중 주기 실행 권장)
// -----------------------------------------------------------
require_once __DIR__ . '/../config/kis_api.php';

echo date('Y-m-d H:i:s') . " - 거래대금 순위 수집 시작\n";

try {
    $ranks = getVolumeRank(30);
} catch (Exception $e) {
    echo "순위 조회 실패: " . $e->getMessage() . "\n";
    exit(1);
}
if (!$ranks) {
    echo "순위 데이터 없음 (장 시작 전이거나 응답 비어있음)\n";
    exit;
}

// 1) 상위 종목 시세 즉시 반영
foreach ($ranks as $r) {
    try {
        upsertStockMaster($conn, $r['stock_code'], $r['stock_name']);
        upsertStockLatest($conn, [
            'stock_code'   => $r['stock_code'],
            'stock_name'   => $r['stock_name'],
            'price'        => $r['price'],
            'change_price' => $r['change_price'],
            'change_rate'  => $r['change_rate'],
            'volume'       => $r['volume'],
            'trade_value'  => $r['trade_value'],
        ]);
        echo sprintf("  %2d위 %s(%s) 거래대금 %s원\n", $r['rank'], $r['stock_name'], $r['stock_code'], number_format($r['trade_value']));
    } catch (Exception $e) {
        echo "  {$r['stock_code']} 반영 실패: " . $e->getMessage() . "\n";
    }
}

// 2) config/ranked_stocks.php 재생성 (code => name)
$file = __DIR__ . '/../config/ranked_stocks.php';
$php  = "<?php\n";
$php .= "// config/ranked_stocks.php — 자동 생성 파일 (직접 수정 금지)\n";
$php .= "// cron/update_rankings.php 가 거래대금 상위종목으로 매번 재생성함.\n";
$php .= "// 갱신: " . date('Y-m-d H:i:s') . "\n";
$php .= "return [\n";
foreach ($ranks as $r) {
    $php .= "    '" . addslashes($r['stock_code']) . "' => '" . addslashes($r['stock_name']) . "',\n";
}
$php .= "];\n";
file_put_contents($file, $php);

echo "완료: 상위 " . count($ranks) . "종목 시세 반영 + ranked_stocks.php 재생성\n";
