<?php
require_once '../config/kis_api.php';

try {
    $result = fetchAndSaveStockPrice($conn, '005930', '삼성전자');
    echo "저장 완료!<br>";
    print_r($result);
} catch (Exception $e) {
    echo "에러: " . $e->getMessage();
}
