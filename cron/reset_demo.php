<?php
// =============================================================
// 체험 계정 매일 초기화
// =============================================================
// 포트폴리오 방문자들이 같이 쓰는 체험 계정(demo@moa.local)을 처음 상태로 되돌린다.
// 누가 다 팔아버리거나 이상하게 만들어 놔도 다음 날이면 다시 보기 좋은 계좌가 된다.
//
// 사용법:  php cron/reset_demo.php
// 크론:    매일 04:00 KST (= 19:00 UTC)
// -------------------------------------------------------------
require_once __DIR__ . '/../user/func.php';
/** @var mysqli $conn */

try {
    $r = resetDemoAccount($conn);
    echo date('Y-m-d H:i:s') . " - 체험 계정 초기화 완료 (uid={$r['user_id']}, 현금 " . number_format($r['cash']) . "원, 보유 {$r['holdings']}종목, 주문 {$r['orders']}건)\n";
} catch (Exception $e) {
    echo date('Y-m-d H:i:s') . " - 실패: " . $e->getMessage() . "\n";
    exit(1);
}
