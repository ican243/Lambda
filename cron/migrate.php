<?php
// cron/migrate.php
// -----------------------------------------------------------
// DB 스키마 보강 — 거래대금(trade_value) 컬럼 추가.
// 브라우저에서 한 번만 열면 된다: localhost/stock-project/cron/migrate.php
// (MariaDB의 IF NOT EXISTS 로 여러 번 실행해도 안전)
// -----------------------------------------------------------
require_once __DIR__ . '/../config/db.php';
/** @var mysqli $conn */   // config/db.php 에서 넘어옴 (에디터 자동완성·오탐 방지용)

$statements = [
    "ALTER TABLE stock_latest ADD COLUMN IF NOT EXISTS trade_value BIGINT DEFAULT 0",
    "ALTER TABLE stock_logs   ADD COLUMN IF NOT EXISTS trade_value BIGINT DEFAULT 0",
    // 정렬 속도용 인덱스 (거래대금/거래량)
    "CREATE INDEX IF NOT EXISTS idx_trade_value ON stock_latest (trade_value)",
    "CREATE INDEX IF NOT EXISTS idx_volume ON stock_latest (volume)",
    // 조회수 집계(홈 우측 "지금 많이 봐요")용 테이블
    "CREATE TABLE IF NOT EXISTS stock_views (
        stock_code VARCHAR(10) PRIMARY KEY,
        view_count BIGINT NOT NULL DEFAULT 0,
        last_viewed DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_view_count (view_count)
    )",
];

header('Content-Type: text/plain; charset=utf-8');
foreach ($statements as $sql) {
    if (mysqli_query($conn, $sql)) {
        echo "✅ OK: $sql\n";
    } else {
        echo "⚠️  " . mysqli_error($conn) . " :: $sql\n";
    }
}
echo "\n마이그레이션 완료. 이제 cron/fetch_price.php 를 실행하면 거래대금이 채워집니다.\n";
