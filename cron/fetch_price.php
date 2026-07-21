<?php
require_once __DIR__ . '/../config/kis_api.php';

$targets = [
    '005930' => '삼성전자',
    '000660' => 'SK하이닉스',
    '035420' => 'NAVER',
];

foreach ($targets as $code => $name) {
    try {
        $result = fetchAndSaveStockPrice($conn, $code, $name);
        echo date('Y-m-d H:i:s') . " - {$name}({$code}) 저장 완료: {$result['price']}원\n";
    } catch (Exception $e) {
        echo date('Y-m-d H:i:s') . " - {$name}({$code}) 실패: " . $e->getMessage() . "\n";
    }
}
