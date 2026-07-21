<?php
require_once 'func.php';
startUserSession();

if (!isLoggedIn()) {
    header('Location: login.php');
    exit;
}

$stockCode = trim($_GET['stock_code'] ?? '');
if ($stockCode === '') {
    die('종목코드가 필요합니다.');
}

$logs = getStockLogsForCsv($conn, $stockCode);

// -----------------------------
// CSV 다운로드 응답 헤더 설정
// -----------------------------
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $stockCode . '_price_log.csv"');

// 엑셀에서 한글 안 깨지게 BOM 추가
echo "\xEF\xBB\xBF";

$output = fopen('php://output', 'w');

// 헤더 행
fputcsv($output, ['시각', '가격', '전일대비', '등락률', '거래량']);

// 데이터 행
foreach ($logs as $log) {
    fputcsv($output, [
        $log['created_at'],
        $log['price'],
        $log['change_price'],
        $log['change_rate'],
        $log['volume'],
    ]);
}

fclose($output);
exit;
