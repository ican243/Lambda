<?php
require_once 'func.php';
require_once '../config/kis_api.php';
/** @var mysqli $conn */   // config/db.php 에서 넘어옴 (에디터 자동완성·오탐 방지용)
startUserSession();

if (!isLoggedIn()) {
    header('Location: login.php');
    exit;
}

// CSRF 검증 — 외부 사이트가 대신 쏜 주문 차단
requireCsrf('trade.php');

// 점검 모드면 거래 차단 (운영 kill switch)
if (isMaintenance($conn)) {
    header('Location: trade.php?error=' . urlencode(maintenanceMsg($conn)));
    exit;
}

$stockCode = trim($_POST['stock_code'] ?? '');
$quantity = (int) ($_POST['quantity'] ?? 0);
$orderType = $_POST['order_type'] ?? '';

// 거래 시도 접속 로그(IP)
logAccess($conn, $_SESSION['user_id'], 'trade');

try {
    // 0. 서버측 입력 검증 (필수)
    //    trade.php 의 min="1" 은 브라우저 검증일 뿐 → 개발자도구·Postman 으로 우회 가능.
    //    특히 음수 수량은 totalAmount 가 음수가 되어 '잔고가 늘어나는' 치명적 버그를 만든다.
    if (!in_array($orderType, ['buy', 'sell'], true)) {
        throw new Exception("잘못된 주문 유형입니다.");
    }
    if (!preg_match('/^[0-9A-Z]{6}$/', $stockCode)) {
        throw new Exception("잘못된 종목코드입니다.");
    }
    if ($quantity < 1) {
        throw new Exception("수량은 1주 이상이어야 합니다.");
    }
    if ($quantity > 1000000) {
        throw new Exception("한 번에 주문 가능한 수량을 초과했습니다.");
    }

    // 1. DB에 쌓인 최신 가격 사용. 없으면(아직 수집 전 종목) API로 즉시 조회해서 보강
    $latest = getSingleStockPrice($conn, $stockCode);
    if (!$latest || !$latest['price']) {
        // stock_latest 에 없는 종목 → 한투 REST 로 실시간 조회 후 DB에 저장
        $fetched = fetchAndSaveStockPrice($conn, $stockCode);
        $currentPrice = $fetched['price'];
    } else {
        $currentPrice = $latest['price'];
    }
    if (!$currentPrice) {
        throw new Exception("현재 시세 정보를 가져올 수 없습니다. 잠시 후 다시 시도해주세요.");
    }

    // 2. (제거) 한투 실제 모의주문 전송
    //    우리는 자체 모의투자 시스템(accounts/holdings/orders)을 쓰므로 KIS 실주문은 보내지 않는다.
    //    KIS는 '시세 조회' 용도로만 사용. 실제 KIS 모의주문을 보내려면 KIS 모의투자 계좌의
    //    예수금 설정이 필요하고, 그게 안 되어 있으면 "모의투자 잔고내역이 없습니다" 로 실패한다.
    //    placeStockOrder($stockCode, $quantity, $orderType);

    // 3. 우리 DB 트랜잭션 처리 (잔고 차감/증가, 보유종목 반영, 주문기록)
    if ($orderType === 'buy') {
        processBuy($conn, $_SESSION['user_id'], $stockCode, $quantity, $currentPrice);
    } else {
        processSell($conn, $_SESSION['user_id'], $stockCode, $quantity, $currentPrice);
    }

    header('Location: trade.php?success=' . urlencode('주문이 완료되었습니다.'));
    exit;
} catch (Exception $e) {
    // 주문 체결 실패 → 에러 로그 수집 (관리자 대시보드에서 확인)
    logError($conn, 'error', 'trade', "주문실패 uid={$_SESSION['user_id']} {$orderType} {$stockCode} x{$quantity}: " . $e->getMessage());
    header('Location: trade.php?error=' . urlencode($e->getMessage()));
    exit;
}
