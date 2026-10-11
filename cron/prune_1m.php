<?php
// 터미널(서버 시계 cron)에서만 실행 — 인터넷 주소로 열면 "없는 페이지"로 끝낸다
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
// =============================================================
// stock_candles_1m 롤링 삭제
// =============================================================
// 왜 필요한가:
//   1분봉은 종목당 하루 약 390행(장중 6.5시간)씩 쌓여서 일봉과 달리
//   장기 보관하면 용량이 기하급수적으로 커진다. 장기 분석은 stock_candles_1d로
//   충분하므로, 1분봉은 최근 N일만 남기고 오래된 행은 지워서 용량을 관리한다.
//
// 사용법:
//   php cron/prune_1m.php               기본 30일 보관 (그보다 오래된 행 삭제)
//
// 기준 날짜 = "가장 최근 1분봉" (오늘 날짜가 아님!)
//   시세 수집이 멈춘 동안(예: KIS 연결 끊김) 오늘 기준으로 지우면, 새 데이터는 안 들어오는데
//   남은 데이터만 매일 줄어들다 결국 0개가 되어 모든 차트의 1일·1주 화면이 비어 버린다.
//   그래서 "가장 최근 1분봉에서 30일 전"보다 오래된 것만 지운다 → 수집이 멈추면 지우기도 자동으로 멈춘다.
//   php cron/prune_1m.php --days=14      14일 보관
// -------------------------------------------------------------
require_once __DIR__ . '/../config/db.php';
/** @var mysqli $conn */

$opt = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--(\w+)=(.+)$/', $a, $m)) $opt[$m[1]] = $m[2];
}
$days = isset($opt['days']) ? max(1, (int) $opt['days']) : 30;

$before = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM stock_candles_1m"))['c'];

$latest = mysqli_fetch_assoc(mysqli_query($conn, "SELECT MAX(ts) t FROM stock_candles_1m"))['t'];
if (!$latest) { echo date('Y-m-d H:i:s') . " - 1분봉이 없어서 지울 것도 없음\n"; exit; }

$stmt = mysqli_prepare($conn, "DELETE FROM stock_candles_1m WHERE ts < DATE_SUB(?, INTERVAL {$days} DAY)");
mysqli_stmt_bind_param($stmt, "s", $latest);
mysqli_stmt_execute($stmt) or die("삭제 실패: " . mysqli_error($conn) . "\n");
$deleted = mysqli_stmt_affected_rows($stmt);

$after = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM stock_candles_1m"))['c'];

echo date('Y-m-d H:i:s') . " - 가장 최근 1분봉({$latest}) 기준 {$days}일 이전 데이터 삭제: {$deleted}행 (남은 행: {$before} → {$after})\n";
