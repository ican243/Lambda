<?php
// user/func.php - 유저 전용 공용 함수 모음

require_once __DIR__ . '/../config/db.php';   // DB 연결($conn) 가져오기

// -----------------------------
// 1. 유저 세션 시작
// -----------------------------
function startUserSession()
{
    session_name('USER_SESSION');
    session_start();
}

// -----------------------------
// 2. 로그인 여부 체크
// -----------------------------
function isLoggedIn()
{
    return isset($_SESSION['user_id']);
}

// -----------------------------
// 3. 이메일 중복 체크
// -----------------------------
function emailExists($conn, $email)
{
    $stmt = mysqli_prepare($conn, "SELECT id FROM users WHERE email = ?");
    mysqli_stmt_bind_param($stmt, "s", $email);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    return mysqli_num_rows($result) > 0;
}

// -----------------------------
// 4. 회원 생성
// -----------------------------
function createUser($conn, $email, $hashedPassword, $nickname)
{
    $stmt = mysqli_prepare($conn, "INSERT INTO users (email, password, nickname) VALUES (?, ?, ?)");
    mysqli_stmt_bind_param($stmt, "sss", $email, $hashedPassword, $nickname);
    return mysqli_stmt_execute($stmt);
}

// -----------------------------
// 5. 이메일로 유저 정보 가져오기 (로그인 검증용)
// -----------------------------
function getUserByEmail($conn, $email)
{
    $stmt = mysqli_prepare($conn, "SELECT * FROM users WHERE email = ?");
    mysqli_stmt_bind_param($stmt, "s", $email);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    return mysqli_fetch_assoc($result);
}

// -----------------------------
// 6. 종목 이름으로 검색 (LIKE 검색)
// -----------------------------
function searchStocks($conn, $keyword)
{
    $stmt = mysqli_prepare($conn, "
        SELECT stock_code, stock_name, market 
        FROM stock_master 
        WHERE stock_name LIKE ? 
        LIMIT 20
    ");
    $likeKeyword = "%{$keyword}%";
    mysqli_stmt_bind_param($stmt, "s", $likeKeyword);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    $stocks = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $stocks[] = $row;
    }
    return $stocks;
}

// -----------------------------
// 7. 관심종목 추가
// -----------------------------
function addWatchlist($conn, $userId, $stockCode)
{
    $stmt = mysqli_prepare($conn, "
        INSERT IGNORE INTO watchlist (user_id, stock_code) VALUES (?, ?)
    ");
    mysqli_stmt_bind_param($stmt, "is", $userId, $stockCode);
    return mysqli_stmt_execute($stmt);
}

// -----------------------------
// 8. 관심종목 삭제
// -----------------------------
function removeWatchlist($conn, $userId, $stockCode)
{
    $stmt = mysqli_prepare($conn, "
        DELETE FROM watchlist WHERE user_id = ? AND stock_code = ?
    ");
    mysqli_stmt_bind_param($stmt, "is", $userId, $stockCode);
    return mysqli_stmt_execute($stmt);
}

// -----------------------------
// 9. 내 관심종목의 최신 시세
// -----------------------------
function getMyWatchlistPrices($conn, $userId)
{
    $stmt = mysqli_prepare($conn, "
        SELECT w.stock_code, sm.stock_name, sl.price, sl.change_price, sl.change_rate, sl.created_at
        FROM watchlist w
        INNER JOIN stock_master sm ON w.stock_code = sm.stock_code
        LEFT JOIN stock_logs sl ON sl.stock_code = w.stock_code
        WHERE w.user_id = ?
        AND (sl.id IS NULL OR sl.id = (
            SELECT MAX(id) FROM stock_logs WHERE stock_code = w.stock_code
        ))
        ORDER BY w.created_at DESC
    ");
    mysqli_stmt_bind_param($stmt, "i", $userId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    $prices = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $prices[] = $row;
    }
    return $prices;
}

// -----------------------------
// 10. 특정 종목의 전체 시세 기록 (CSV용)
// -----------------------------
function getStockLogsForCsv($conn, $stockCode)
{
    $stmt = mysqli_prepare($conn, "
        SELECT created_at, price, change_price, change_rate, volume
        FROM stock_logs
        WHERE stock_code = ?
        ORDER BY created_at ASC
    ");
    mysqli_stmt_bind_param($stmt, "s", $stockCode);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    $logs = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $logs[] = $row;
    }
    return $logs;
}

//신규 계좌 생성

function createAccount($conn, $userId)
{
    $stmt = mysqli_prepare($conn, "
        INSERT INTO accounts (user_id, cash_balance) VALUES (?, 10000000)
    ");
    mysqli_stmt_bind_param($stmt, "i", $userId);
    return mysqli_stmt_execute($stmt);
}

// -----------------------------
// 매수 처리 (DB 트랜잭션)
// -----------------------------
function processBuy($conn, $userId, $stockCode, $quantity, $price)
{
    $totalAmount = $quantity * $price;

    mysqli_begin_transaction($conn);
    try {
        // 1. 잔고 확인
        $stmt = mysqli_prepare($conn, "SELECT cash_balance FROM accounts WHERE user_id = ? FOR UPDATE");
        mysqli_stmt_bind_param($stmt, "i", $userId);
        mysqli_stmt_execute($stmt);
        $account = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

        if (!$account || $account['cash_balance'] < $totalAmount) {
            throw new Exception("잔액이 부족합니다.");
        }

        // 2. 잔고 차감
        $stmt = mysqli_prepare($conn, "UPDATE accounts SET cash_balance = cash_balance - ? WHERE user_id = ?");
        mysqli_stmt_bind_param($stmt, "ii", $totalAmount, $userId);
        mysqli_stmt_execute($stmt);

        // 3. 보유종목 반영 (있으면 평균단가 재계산, 없으면 신규)
        $stmt = mysqli_prepare($conn, "SELECT quantity, avg_price FROM holdings WHERE user_id = ? AND stock_code = ? FOR UPDATE");
        mysqli_stmt_bind_param($stmt, "is", $userId, $stockCode);
        mysqli_stmt_execute($stmt);
        $holding = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

        if ($holding) {
            $newQty = $holding['quantity'] + $quantity;
            $newAvgPrice = (($holding['quantity'] * $holding['avg_price']) + ($quantity * $price)) / $newQty;

            $stmt = mysqli_prepare($conn, "UPDATE holdings SET quantity = ?, avg_price = ? WHERE user_id = ? AND stock_code = ?");
            mysqli_stmt_bind_param($stmt, "idis", $newQty, $newAvgPrice, $userId, $stockCode);
            mysqli_stmt_execute($stmt);
        } else {
            $stmt = mysqli_prepare($conn, "INSERT INTO holdings (user_id, stock_code, quantity, avg_price) VALUES (?, ?, ?, ?)");
            mysqli_stmt_bind_param($stmt, "isid", $userId, $stockCode, $quantity, $price);
            mysqli_stmt_execute($stmt);
        }

        // 4. 주문 기록 남기기
        $stmt = mysqli_prepare($conn, "
            INSERT INTO orders (user_id, stock_code, order_type, quantity, price, total_amount)
            VALUES (?, ?, 'buy', ?, ?, ?)
        ");
        mysqli_stmt_bind_param($stmt, "isidi", $userId, $stockCode, $quantity, $price, $totalAmount);
        mysqli_stmt_execute($stmt);

        mysqli_commit($conn);
        return true;
    } catch (Exception $e) {
        mysqli_rollback($conn);
        throw $e;
    }
}

// -----------------------------
// 매도 처리 (DB 트랜잭션)
// -----------------------------
function processSell($conn, $userId, $stockCode, $quantity, $price)
{
    $totalAmount = $quantity * $price;

    mysqli_begin_transaction($conn);
    try {
        // 1. 보유수량 확인
        $stmt = mysqli_prepare($conn, "SELECT quantity, avg_price FROM holdings WHERE user_id = ? AND stock_code = ? FOR UPDATE");
        mysqli_stmt_bind_param($stmt, "is", $userId, $stockCode);
        mysqli_stmt_execute($stmt);
        $holding = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

        if (!$holding || $holding['quantity'] < $quantity) {
            throw new Exception("보유 수량이 부족합니다.");
        }

        // 2. 보유수량 차감 (0이 되면 행 자체를 삭제)
        $remainingQty = $holding['quantity'] - $quantity;
        if ($remainingQty === 0) {
            $stmt = mysqli_prepare($conn, "DELETE FROM holdings WHERE user_id = ? AND stock_code = ?");
            mysqli_stmt_bind_param($stmt, "is", $userId, $stockCode);
        } else {
            $stmt = mysqli_prepare($conn, "UPDATE holdings SET quantity = ? WHERE user_id = ? AND stock_code = ?");
            mysqli_stmt_bind_param($stmt, "iis", $remainingQty, $userId, $stockCode);
        }
        mysqli_stmt_execute($stmt);

        // 3. 잔고 증가
        $stmt = mysqli_prepare($conn, "UPDATE accounts SET cash_balance = cash_balance + ? WHERE user_id = ?");
        mysqli_stmt_bind_param($stmt, "ii", $totalAmount, $userId);
        mysqli_stmt_execute($stmt);

        // 4. 주문 기록
        $stmt = mysqli_prepare($conn, "
            INSERT INTO orders (user_id, stock_code, order_type, quantity, price, total_amount)
            VALUES (?, ?, 'sell', ?, ?, ?)
        ");
        mysqli_stmt_bind_param($stmt, "isidi", $userId, $stockCode, $quantity, $price, $totalAmount);
        mysqli_stmt_execute($stmt);

        mysqli_commit($conn);
        return true;
    } catch (Exception $e) {
        mysqli_rollback($conn);
        throw $e;
    }
}

// -----------------------------
// 내 보유종목 + 현재가 조회
// -----------------------------
function getMyHoldings($conn, $userId)
{
    $stmt = mysqli_prepare($conn, "
        SELECT h.stock_code, sm.stock_name, h.quantity, h.avg_price, sl.price as current_price
        FROM holdings h
        INNER JOIN stock_master sm ON h.stock_code = sm.stock_code
        LEFT JOIN stock_logs sl ON sl.stock_code = h.stock_code
        WHERE h.user_id = ?
        AND (sl.id IS NULL OR sl.id = (SELECT MAX(id) FROM stock_logs WHERE stock_code = h.stock_code))
    ");
    mysqli_stmt_bind_param($stmt, "i", $userId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    $holdings = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $holdings[] = $row;
    }
    return $holdings;
}

// -----------------------------
// 내 계좌 잔고 조회
// -----------------------------
function getMyAccount($conn, $userId)
{
    $stmt = mysqli_prepare($conn, "SELECT cash_balance FROM accounts WHERE user_id = ?");
    mysqli_stmt_bind_param($stmt, "i", $userId);
    mysqli_stmt_execute($stmt);
    $account = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

    // 계좌가 없으면 자동으로 하나 만들어줌 (예외 상황 방어)
    if (!$account) {
        createAccount($conn, $userId);
        return ['cash_balance' => 10000000];
    }

    return $account;
}

// -----------------------------
// 내 주문 내역 조회
// -----------------------------
function getMyOrders($conn, $userId)
{
    $stmt = mysqli_prepare($conn, "
        SELECT o.stock_code, sm.stock_name, o.order_type, o.quantity, o.price, o.total_amount, o.created_at
        FROM orders o
        INNER JOIN stock_master sm ON o.stock_code = sm.stock_code
        WHERE o.user_id = ?
        ORDER BY o.created_at DESC
    ");
    mysqli_stmt_bind_param($stmt, "i", $userId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    $orders = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $orders[] = $row;
    }
    return $orders;
}
