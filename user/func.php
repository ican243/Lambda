<?php
// user/func.php - 유저 전용 공용 함수 모음

require_once __DIR__ . '/../config/db.php';   // DB 연결($conn) 가져오기
require_once __DIR__ . '/../config/app.php';  // 공용 운영계층(로깅·점검·공지)

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
// 8-1. 특정 종목이 내 관심종목인지 확인
// -----------------------------
function isInWatchlist($conn, $userId, $stockCode)
{
    $stmt = mysqli_prepare($conn, "SELECT 1 FROM watchlist WHERE user_id = ? AND stock_code = ?");
    mysqli_stmt_bind_param($stmt, "is", $userId, $stockCode);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    return mysqli_num_rows($result) > 0;
}

// -----------------------------
// 9. 내 관심종목의 최신 시세
// -----------------------------
function getMyWatchlistPrices($conn, $userId)
{
    $stmt = mysqli_prepare($conn, "
        SELECT w.stock_code, sl.stock_name, sl.price, sl.change_price, sl.change_rate, sl.updated_at as created_at
        FROM watchlist w
        INNER JOIN stock_latest sl ON w.stock_code = sl.stock_code
        WHERE w.user_id = ?
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

// -----------------------------
// [홈 대시보드] 인기종목 = 거래대금(현재가 × 거래량) 상위
// 로그인 여부와 무관하게 누구나 조회 가능
// -----------------------------
function getPopularStocks($conn, $limit = 100)
{
    $limit = (int) $limit;
    // 실제 누적거래대금(trade_value) 기준 정렬 → 토스/한투와 순위 일치.
    // (아직 trade_value가 0인 종목은 price*volume 근사로 보조 정렬)
    $sql = "
        SELECT sl.stock_code,
               COALESCE(sm.stock_name, sl.stock_name, sl.stock_code) AS stock_name,
               sm.market, sl.price, sl.change_price, sl.change_rate, sl.volume,
               sl.trade_value
        FROM stock_latest sl
        LEFT JOIN stock_master sm ON sm.stock_code = sl.stock_code
        WHERE sl.price > 0
        ORDER BY (CASE WHEN sl.trade_value > 0 THEN sl.trade_value ELSE sl.price * sl.volume END) DESC
        LIMIT $limit
    ";
    $result = mysqli_query($conn, $sql);
    $rows = [];
    while ($row = mysqli_fetch_assoc($result)) $rows[] = $row;
    return $rows;
}

// -----------------------------
// [홈 대시보드] 급등/급락 종목
// $dir = 'up'  → 등락률 높은 순(급등)
// $dir = 'down'→ 등락률 낮은 순(급락)
// -----------------------------
function getTopMovers($conn, $dir = 'up', $limit = 10)
{
    $limit = (int) $limit;
    $order = ($dir === 'down') ? 'ASC' : 'DESC';
    $sql = "
        SELECT sl.stock_code,
               COALESCE(sm.stock_name, sl.stock_name, sl.stock_code) AS stock_name,
               sm.market, sl.price, sl.change_price, sl.change_rate, sl.volume
        FROM stock_latest sl
        LEFT JOIN stock_master sm ON sm.stock_code = sl.stock_code
        WHERE sl.price > 0
        ORDER BY sl.change_rate $order
        LIMIT $limit
    ";
    $result = mysqli_query($conn, $sql);
    $rows = [];
    while ($row = mysqli_fetch_assoc($result)) $rows[] = $row;
    return $rows;
}

// -----------------------------
// [조회수] 종목 상세를 열 때 1 증가 (많이 본 종목 집계용)
// -----------------------------
function recordStockView($conn, $stockCode)
{
    $stmt = mysqli_prepare($conn, "
        INSERT INTO stock_views (stock_code, view_count)
        VALUES (?, 1)
        ON DUPLICATE KEY UPDATE view_count = view_count + 1
    ");
    mysqli_stmt_bind_param($stmt, "s", $stockCode);
    return mysqli_stmt_execute($stmt);
}

// -----------------------------
// [홈 대시보드 우측] 많이 본 종목 = 조회수 상위
// 시세가 있는(stock_latest) 종목만 → 가격까지 함께 렌더 가능.
// 아직 조회 데이터가 없으면 빈 배열 반환(프론트에서 급상승으로 폴백).
// -----------------------------
function getMostViewedStocks($conn, $limit = 15)
{
    $limit = (int) $limit;
    $sql = "
        SELECT sl.stock_code,
               COALESCE(sm.stock_name, sl.stock_name, sl.stock_code) AS stock_name,
               sm.market, sl.price, sl.change_price, sl.change_rate, sl.volume,
               sv.view_count
        FROM stock_views sv
        JOIN stock_latest sl ON sl.stock_code = sv.stock_code
        LEFT JOIN stock_master sm ON sm.stock_code = sv.stock_code
        WHERE sl.price > 0 AND sv.view_count > 0
        ORDER BY sv.view_count DESC
        LIMIT $limit
    ";
    $result = mysqli_query($conn, $sql);
    $rows = [];
    while ($row = mysqli_fetch_assoc($result)) $rows[] = $row;
    return $rows;
}

//신규 계좌 생성

// 신규 가입 시 지급되는 초기 예수금 (관리자 설정 app_settings.initial_cash, 없으면 1,000만)
function getInitialCashAmount($conn)
{
    $initial = 10000000;
    $res = @mysqli_query($conn, "SELECT svalue FROM app_settings WHERE skey = 'initial_cash'");
    if ($res && ($row = mysqli_fetch_assoc($res))) $initial = (int) $row['svalue'];
    return $initial;
}

function createAccount($conn, $userId)
{
    $initial = getInitialCashAmount($conn);
    $stmt = mysqli_prepare($conn, "INSERT INTO accounts (user_id, cash_balance) VALUES (?, ?)");
    mysqli_stmt_bind_param($stmt, "ii", $userId, $initial);
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
        LEFT JOIN stock_latest sl ON sl.stock_code = h.stock_code
        WHERE h.user_id = ?
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
        return ['cash_balance' => getInitialCashAmount($conn)];
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



// 특정 종목의 오늘 시세 흐름 (차트용)

function getStockChartData($conn, $stockCode)
{
    $stmt = mysqli_prepare($conn, "
        SELECT created_at, price
        FROM stock_logs
        WHERE stock_code = ? AND DATE(created_at) = CURDATE()
        ORDER BY created_at ASC
    ");
    mysqli_stmt_bind_param($stmt, "s", $stockCode);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    $data = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $data[] = $row;
    }
    return $data;
}


// 특정 종목의 최신 시세 1건 (상세페이지 상단용)

function getSingleStockPrice($conn, $stockCode)
{
    $stmt = mysqli_prepare($conn, "
        SELECT sm.stock_name, sl.price, sl.change_price, sl.change_rate, sl.updated_at as created_at
        FROM stock_master sm
        LEFT JOIN stock_latest sl ON sl.stock_code = sm.stock_code
        WHERE sm.stock_code = ?
    ");
    mysqli_stmt_bind_param($stmt, "s", $stockCode);
    mysqli_stmt_execute($stmt);
    return mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
}

// -----------------------------
// 1분봉 캔들 데이터 생성 (오늘자 원시 데이터를 1분 단위로 묶음)
// -----------------------------
function getCandleData($conn, $stockCode)
{
    $stmt = mysqli_prepare($conn, "
        SELECT created_at, price
        FROM stock_logs
        WHERE stock_code = ? AND DATE(created_at) = CURDATE()
        ORDER BY created_at ASC
    ");
    mysqli_stmt_bind_param($stmt, "s", $stockCode);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    $buckets = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $minuteKey = substr($row['created_at'], 0, 16);
        $buckets[$minuteKey][] = (float) $row['price'];
    }

    $candles = [];
    foreach ($buckets as $minuteKey => $prices) {
        $candles[] = [
            'time'  => strtotime($minuteKey . ':00'),
            'open'  => $prices[0],
            'high'  => max($prices),
            'low'   => min($prices),
            'close' => end($prices),
        ];
    }

    return $candles;
}

// -----------------------------
// [커뮤니티] 종목별 게시글
//   테이블이 없으면 자동 생성 → 형이 별도 마이그레이션 안 돌려도 됨.
// -----------------------------
function ensurePostsTable($conn)
{
    mysqli_query($conn, "
        CREATE TABLE IF NOT EXISTS stock_posts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            stock_code VARCHAR(20) NOT NULL,
            user_id INT NOT NULL,
            nickname VARCHAR(50) NOT NULL,
            content VARCHAR(500) NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_code_time (stock_code, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

function getStockPosts($conn, $stockCode, $limit = 30)
{
    ensurePostsTable($conn);
    $limit = (int) $limit;
    $stmt = mysqli_prepare($conn, "
        SELECT nickname, content, created_at
        FROM stock_posts WHERE stock_code = ?
        ORDER BY created_at DESC LIMIT $limit
    ");
    mysqli_stmt_bind_param($stmt, "s", $stockCode);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $rows = [];
    while ($row = mysqli_fetch_assoc($result)) $rows[] = $row;
    return $rows;
}

function addStockPost($conn, $stockCode, $userId, $nickname, $content)
{
    ensurePostsTable($conn);
    $content = trim($content);
    if ($content === '') return false;
    $content = mb_substr($content, 0, 500);
    $nickname = $nickname ?: '익명';
    $stmt = mysqli_prepare($conn, "
        INSERT INTO stock_posts (stock_code, user_id, nickname, content)
        VALUES (?, ?, ?, ?)
    ");
    mysqli_stmt_bind_param($stmt, "siss", $stockCode, $userId, $nickname, $content);
    return mysqli_stmt_execute($stmt);
}
