<?php
require_once '../config/kis_api.php';
/** @var mysqli $conn */   // config/db.php 에서 넘어옴 (에디터 자동완성·오탐 방지용)

try {
    $result = fetchAndSaveStockPrice($conn, '005930', '삼성전자');
    echo "저장 완료!<br>";
    print_r($result);
} catch (Exception $e) {
    echo "에러: " . $e->getMessage();
}
