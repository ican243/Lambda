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
// 2-1. 로그인이 필요한 페이지 게이트(서버측 강제)
//   비로그인이면 로그인 화면으로 보내고, 로그인 끝나면 원래 가려던 곳으로 되돌려준다.
// -----------------------------
function requireUserLogin($next = null)
{
    if (isLoggedIn()) return;
    // 기본값 = 지금 열려던 페이지 파일명 + 쿼리스트링
    if ($next === null) {
        $next = basename($_SERVER['SCRIPT_NAME'] ?? '');
        if (!empty($_SERVER['QUERY_STRING'])) $next .= '?' . $_SERVER['QUERY_STRING'];
    }
    header('Location: login.php?next=' . urlencode($next));
    exit;
}

// -----------------------------
// 2-2. 로그인 후 돌아갈 주소 검증 (오픈 리다이렉트 방지)
//   같은 폴더의 .php 파일명만 허용 → //evil.com, http://... 같은 외부주소는 전부 차단
// -----------------------------
function safeNext($next, $fallback = 'index.php')
{
    $next = trim((string) $next);
    if ($next === '') return $fallback;
    if (!preg_match('/^[A-Za-z0-9_]+\.php(\?[A-Za-z0-9_=&%.\-]*)?$/', $next)) return $fallback;
    return $next;
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
    // 방어적 검증(2차) — 호출부에서 걸러도 데이터 계층에서 한 번 더 막는다
    $quantity = (int) $quantity;
    if ($quantity < 1) throw new Exception("수량은 1주 이상이어야 합니다.");
    if ($price <= 0)   throw new Exception("잘못된 가격입니다.");

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
    // 방어적 검증(2차) — 음수 매도는 보유수량을 늘리고 현금을 깎는 역버그가 된다
    $quantity = (int) $quantity;
    if ($quantity < 1) throw new Exception("수량은 1주 이상이어야 합니다.");
    if ($price <= 0)   throw new Exception("잘못된 가격입니다.");

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

// =============================================================
// [차트] 기간별 캔들 데이터
// =============================================================
// 예전 코드는 `WHERE DATE(created_at) = CURDATE()` 로 '오늘'만 조회했다.
// 그래서 매일 아침 9시가 되면 차트가 처음부터 다시 그려졌다(형이 본 증상).
// 이제 기간(range)을 받고, 그 기간에 어울리는 '해상도'까지 함께 고른다.
//
//   range  기간          해상도   캔들 개수(대략)   읽는 테이블
//   -----  ------------  -------  ---------------  ------------------
//   1D     오늘          1분봉    ~390             stock_candles_1m
//   1W     최근 7일      5분봉    ~390             stock_candles_1m
//   1M     최근 30일     30분봉   ~260             stock_candles_1m
//   3M     최근 3개월    일봉     ~65              stock_candles_1d
//   1Y     최근 1년      일봉     ~250             stock_candles_1d
//   5Y     최근 5년      일봉     ~1,230           stock_candles_1d
//
// 🔑 왜 해상도를 나누나 (이 설계가 이 기능의 핵심):
//    1분봉을 1년치 그대로 보내면 캔들이 9만 개, JSON이 수 MB가 되어 브라우저가 렉 걸린다.
//    기간이 길어질수록 봉을 굵게 만들면 어느 탭을 눌러도 캔들 수가 항상 수백 개로 유지된다.
//    즉 '1년 보기'가 '오늘 보기'보다 무거워지지 않는다.
// -----------------------------

// 기간 코드 → 해상도/조회범위 규격표. 모르는 값이 들어오면 1D로 떨어뜨린다.
function chartRangeSpec($range)
{
    $map = [
        '1D' => ['src' => 'minute', 'bucket' => 60,      'days' => 1],
        '1W' => ['src' => 'minute', 'bucket' => 300,     'days' => 7],
        // ⚠️ 1M은 원래 30분봉(minute)이었으나 2026-08-07에 일봉으로 바꿨다.
        //    이유: 분봉의 원천인 stock_logs/stock_candles_1m 은 수집을 시작한 2026-07-20 부터만
        //    존재해서, '1개월'을 눌러도 실제로는 18일치밖에 안 그려졌다(형이 발견한 증상).
        //    일봉 테이블은 2024년부터 있으므로 일봉으로 읽어야 한 달이 진짜 한 달로 나온다.
        //    분 단위 디테일이 필요한 구간은 1D·1W가 담당한다.
        '1M' => ['src' => 'daily',  'bucket' => 86400,   'days' => 31],
        '3M' => ['src' => 'daily',  'bucket' => 86400,   'days' => 92],
        '1Y' => ['src' => 'daily',  'bucket' => 86400,   'days' => 366],
        '2Y' => ['src' => 'daily',  'bucket' => 86400,   'days' => 732],
        // 5Y는 버튼에서 뺐다(백필이 2년치라 5년을 눌러도 2년만 나와 형이 혼란스러웠음).
        // 옛 링크·북마크가 죽지 않도록 규격표에는 남겨둔다. 나중에 5년치를 백필하면 버튼만 되살리면 된다.
        '5Y' => ['src' => 'daily',  'bucket' => 86400,   'days' => 1830],
    ];
    $key = strtoupper(trim((string) $range));
    return $map[$key] ?? $map['1D'];
}

// 일봉 테이블은 3단계에서 새로 쓰는 것이라, 없으면 자동 생성한다(형이 SQL 안 돌려도 되게).
// ⚠️ 컬레이션은 stock_candles_1m·stock_master 와 같은 utf8mb3_unicode_ci 로 맞춘다.
//    DB에 컬레이션이 3종 섞여 있어서, 안 맞추면 stock_master JOIN이 "illegal mix of collations"로 깨진다.
function ensureCandle1dTable($conn)
{
    static $done = false;              // 요청당 한 번만 (차트 호출마다 CREATE 쿼리 날리지 않도록)
    if ($done) return;
    mysqli_query($conn, "
        CREATE TABLE IF NOT EXISTS stock_candles_1d (
            stock_code  VARCHAR(10) NOT NULL,
            d           DATE        NOT NULL COMMENT '거래일',
            open_p      INT NOT NULL,
            high_p      INT NOT NULL,
            low_p       INT NOT NULL,
            close_p     INT NOT NULL,
            volume      BIGINT DEFAULT 0 COMMENT '그날 거래량',
            trade_value BIGINT DEFAULT 0 COMMENT '그날 거래대금(원)',
            PRIMARY KEY (stock_code, d),
            KEY idx_d (d)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci
    ");
    $done = true;
}

// 'YYYY-MM-DD HH:MM:SS' → 유닉스 초.
// ⚠️ 반드시 ' UTC'를 붙인다. 우리 DB 시각은 전부 KST '벽시계 값'이고,
//    lightweight-charts는 타임스탬프를 UTC로 해석해 라벨을 찍는다.
//    UTC로 읽어야 화면에 09:00이 09:00으로 나온다. (PHP 기본 타임존에 좌우되지 않게 고정)
function chartTs($datetimeStr)
{
    return strtotime(substr($datetimeStr, 0, 19) . ' UTC');
}

function getCandleData($conn, $stockCode, $range = '1D')
{
    $spec = chartRangeSpec($range);
    return $spec['src'] === 'daily'
        ? getDailyCandles($conn, $stockCode, $spec['days'])
        : getMinuteCandles($conn, $stockCode, $spec['days'], $spec['bucket']);
}

// -----------------------------
// 분/시간 단위 캔들
// -----------------------------
// 두 곳에서 읽어 이어 붙인다.
//   ① stock_candles_1m — 수집기가 실시간으로 쌓는 1분봉(영구보존). 빠르다.
//   ② stock_logs       — 아직 1분봉으로 안 굳은 '최근 구간'을 즉석 집계해서 보강.
// 이렇게 하면 수집기가 잠깐 죽었거나 분봉 모듈을 아직 재시작 안 했어도
// 차트에 구멍이 나지 않는다(자가 치유). 경계는 `>` 로 잡아 중복 캔들이 안 생긴다.
function getMinuteCandles($conn, $stockCode, $days, $bucketSec)
{
    $from = getRangeStart($conn, $days);
    if ($from === null) return [];

    // ① 1분봉 테이블에서 읽어 bucketSec 단위로 묶는다.
    $rows = groupCandleRows(
        $conn,
        "SELECT
            FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(ts) / ?) * ?) AS bts,
            SUBSTRING_INDEX(GROUP_CONCAT(open_p  ORDER BY ts ASC),  ',', 1) AS o,
            MAX(high_p) AS h,
            MIN(low_p)  AS l,
            SUBSTRING_INDEX(GROUP_CONCAT(close_p ORDER BY ts DESC), ',', 1) AS c,
            SUM(vol_delta) AS v
         FROM stock_candles_1m
         WHERE stock_code = ? AND ts >= ?
         GROUP BY bts ORDER BY bts ASC",
        "iiss",
        [$bucketSec, $bucketSec, $stockCode, $from]
    );

    // ② 1분봉이 어디까지 있는지 확인 → 그 이후 구간만 원시 로그로 메운다.
    $tail = $from;
    if ($rows) {
        $lastTs = mysqli_fetch_assoc(dbQueryOne(
            $conn,
            "SELECT MAX(ts) AS m FROM stock_candles_1m WHERE stock_code = ? AND ts >= ?",
            "ss",
            [$stockCode, $from]
        ));
        if (!empty($lastTs['m'])) $tail = $lastTs['m'];
    }

    $rawRows = groupCandleRows(
        $conn,
        "SELECT
            FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(created_at) / ?) * ?) AS bts,
            SUBSTRING_INDEX(GROUP_CONCAT(price ORDER BY id ASC),  ',', 1) AS o,
            MAX(price) AS h,
            MIN(price) AS l,
            SUBSTRING_INDEX(GROUP_CONCAT(price ORDER BY id DESC), ',', 1) AS c,
            (MAX(volume) - MIN(volume)) AS v
         FROM stock_logs
         WHERE stock_code = ? AND created_at > ?
         GROUP BY bts ORDER BY bts ASC",
        "iiss",
        [$bucketSec, $bucketSec, $stockCode, $tail]
    );

    // ①의 마지막 버킷과 ②의 첫 버킷이 같은 칸일 수 있다(예: 10:00:30까지만 분봉이 있는 경우).
    // 그때는 뒤에서 온 값(원시 로그 = 더 최신)이 이기게 덮어쓴다.
    $merged = [];
    foreach (array_merge($rows, $rawRows) as $r) $merged[$r['time']] = $r;
    ksort($merged);
    return array_values($merged);
}

// -----------------------------
// 일봉
// -----------------------------
// 일봉은 KIS 일봉 API로 백필해 둔 stock_candles_1d 를 그대로 읽는다(가공 없음).
// 시간축은 유닉스 초가 아니라 'YYYY-MM-DD' 문자열을 준다 — lightweight-charts가
// 이걸 'business day'로 알아듣고 주말·휴장일을 알아서 건너뛰어 그린다.
function getDailyCandles($conn, $stockCode, $days)
{
    ensureCandle1dTable($conn);
    $res = dbQueryOne(
        $conn,
        "SELECT d, open_p, high_p, low_p, close_p, volume
         FROM stock_candles_1d
         WHERE stock_code = ? AND d >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
         ORDER BY d ASC",
        "si",
        [$stockCode, $days]
    );

    $out = [];
    while ($row = mysqli_fetch_assoc($res)) {
        $out[] = [
            'time'   => $row['d'],                 // 'YYYY-MM-DD'
            'open'   => (float) $row['open_p'],
            'high'   => (float) $row['high_p'],
            'low'    => (float) $row['low_p'],
            'close'  => (float) $row['close_p'],
            'volume' => (float) $row['volume'],
        ];
    }
    return $out;
}

// 기간 시작 시각을 'DB 시계' 기준으로 만든다.
// ⚠️ PHP의 date()로 만들면 안 된다 — PHP는 UTC, DB 세션은 KST(+09:00)라 9시간 어긋나
//    '오늘'을 통째로 놓치거나 엉뚱한 구간을 보게 된다. 항상 DB에게 물어본다.
function getRangeStart($conn, $days)
{
    $res = dbQueryOne(
        $conn,
        $days <= 1
            ? "SELECT CAST(CURDATE() AS DATETIME) AS s"     // 1D는 '오늘 00:00부터'
            : "SELECT DATE_SUB(NOW(), INTERVAL ? DAY) AS s",
        $days <= 1 ? "" : "i",
        $days <= 1 ? [] : [$days]
    );
    $row = mysqli_fetch_assoc($res);
    return $row['s'] ?? null;
}

// prepared statement 실행 후 결과셋을 돌려주는 짧은 헬퍼 (같은 6줄을 계속 안 쓰려고)
function dbQueryOne($conn, $sql, $types, $params)
{
    $stmt = mysqli_prepare($conn, $sql);
    if ($types !== "") mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    return mysqli_stmt_get_result($stmt);
}

// GROUP_CONCAT 기반 버킷 쿼리를 실행해 캔들 배열로 변환.
// ⚠️ group_concat_max_len 기본값은 1024바이트다. 30분 버킷이면 한 칸에 값이 180개까지
//    들어가 1024를 넘고, 넘으면 MySQL이 조용히 잘라서 '시가/종가가 틀린' 캔들이 나온다.
//    그래서 세션 한도를 넉넉히 올려두고 쓴다.
function groupCandleRows($conn, $sql, $types, $params)
{
    static $raised = false;
    if (!$raised) { mysqli_query($conn, "SET SESSION group_concat_max_len = 1048576"); $raised = true; }

    $res = dbQueryOne($conn, $sql, $types, $params);
    $out = [];
    while ($row = mysqli_fetch_assoc($res)) {
        $out[] = [
            'time'   => chartTs($row['bts']),
            'open'   => (float) $row['o'],
            'high'   => (float) $row['h'],
            'low'    => (float) $row['l'],
            'close'  => (float) $row['c'],
            'volume' => (float) max(0, (int) $row['v']),   // 음수 방어(누적거래량이 리셋되는 날짜 경계)
        ];
    }
    return $out;
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

// =====================================================================
// [자동매매]
//   지금은 "화면만" — 실제 매매 로직은 다음 단계.
//   대신 나중에 로직이 붙을 때 프론트를 다시 안 만들도록,
//   ①자동/수동 주문 구분 컬럼 ②일시정지 상태를 미리 DB에 만들어 둔다.
// =====================================================================

// 스키마 자동 보강 (형이 SQL 직접 안 돌려도 됨)
//   orders.is_auto      : 0=사람이 낸 주문, 1=자동매매가 낸 주문
//   auto_trade_settings : 유저별 자동매매 일시정지 상태
// ⚠ MySQL 8.4에는 'ADD COLUMN IF NOT EXISTS' 문법이 없어서(그건 MariaDB 전용)
//   information_schema 로 먼저 있는지 확인한 뒤 ALTER 한다.
function ensureAutoTradeSchema($conn)
{
    static $done = false;
    if ($done) return;                 // 한 요청에서 여러 번 호출돼도 확인은 한 번만
    $done = true;

    $res = @mysqli_query($conn, "
        SELECT COUNT(*) AS c FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'is_auto'
    ");
    $row = $res ? mysqli_fetch_assoc($res) : null;
    if ($row && (int) $row['c'] === 0) {
        @mysqli_query($conn, "ALTER TABLE orders
            ADD COLUMN is_auto TINYINT NOT NULL DEFAULT 0,
            ADD INDEX idx_user_auto (user_id, is_auto, created_at)");
    }

    @mysqli_query($conn, "CREATE TABLE IF NOT EXISTS auto_trade_settings (
        user_id INT PRIMARY KEY,
        is_paused TINYINT NOT NULL DEFAULT 0,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        CONSTRAINT fk_ats_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

// -----------------------------
// 보유종목 상위 N개 (도넛차트 + 우측 리스트용)
//   조각 크기 기준 = 평가금액(현재가 × 수량). 매입금액이 아님.
//   6위 이하는 아예 표시하지 않는다('기타'로 묶지 않음).
// -----------------------------
function getAutoPortfolio($conn, $userId, $limit = 5)
{
    $items = [];
    $totalEval = 0.0;

    foreach (getMyHoldings($conn, $userId) as $r) {
        $qty = (int) $r['quantity'];
        if ($qty <= 0) continue;

        $avg = (float) $r['avg_price'];
        $cur = (float) ($r['current_price'] ?? 0);
        if ($cur <= 0) $cur = $avg;          // 시세 미수집 종목은 평단으로 대체(0원 조각 방지)

        $eval = $cur * $qty;
        $totalEval += $eval;

        $items[] = [
            'stock_code'   => $r['stock_code'],
            'stock_name'   => $r['stock_name'],
            'quantity'     => $qty,
            'avg_price'    => $avg,
            'price'        => $cur,
            'eval_amount'  => $eval,
            'profit_amount' => ($cur - $avg) * $qty,
            'profit_rate'  => $avg > 0 ? round(($cur - $avg) / $avg * 100, 2) : 0,
        ];
    }

    usort($items, function ($a, $b) { return $b['eval_amount'] <=> $a['eval_amount']; });
    $top = array_slice($items, 0, max(1, (int) $limit));

    // 비중은 '전체 보유 평가금액 대비'(진짜 포트폴리오 비중).
    // 도넛은 상위 N개만 그리므로, 상위 합계가 100%가 아닐 수 있다 → 화면에 따로 안내.
    $shownEval = 0.0;
    foreach ($top as &$t) {
        $t['weight'] = $totalEval > 0 ? round($t['eval_amount'] / $totalEval * 100, 1) : 0;
        $shownEval += $t['eval_amount'];
    }
    unset($t);

    return [
        'items'       => $top,
        'total_eval'  => $totalEval,
        'total_count' => count($items),      // 전체 보유 종목 수 (5개 초과 안내용)
        'shown_eval'  => $shownEval,
        'shown_ratio' => $totalEval > 0 ? round($shownEval / $totalEval * 100, 1) : 0,
    ];
}

// -----------------------------
// 거래내역 (최신 N건) + 수익률
//   · 매도 행 = 실현수익률 (매도가 - 그 시점 평단) / 평단
//   · 매수 행 = "매수가 대비 현재가" 등락률 (매수 시점엔 손익이 확정되지 않으므로)
//
//   그 시점 평단은 DB에 저장돼 있지 않다(holdings.avg_price는 '지금' 값이고,
//   전량 매도하면 행이 사라짐). 그래서 주문을 시간순으로 재생(replay)해서
//   processBuy 와 같은 이동평균 방식으로 매 시점 평단을 복원한다.
// -----------------------------
function getAutoTradeHistory($conn, $userId, $limit = 15, $autoOnly = false)
{
    ensureAutoTradeSchema($conn);

    $where = $autoOnly ? "AND o.is_auto = 1" : "";
    $stmt = mysqli_prepare($conn, "
        SELECT o.id, o.stock_code, sm.stock_name, o.order_type, o.quantity, o.price,
               o.total_amount, o.created_at, o.is_auto, sl.price AS current_price
        FROM orders o
        INNER JOIN stock_master sm ON sm.stock_code = o.stock_code
        LEFT JOIN stock_latest sl ON sl.stock_code = o.stock_code
        WHERE o.user_id = ? $where
        ORDER BY o.created_at ASC, o.id ASC
    ");
    if (!$stmt) return [];
    mysqli_stmt_bind_param($stmt, "i", $userId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    $rows = [];
    while ($row = mysqli_fetch_assoc($result)) $rows[] = $row;

    $book = [];   // stock_code => ['qty' => 보유수량, 'cost' => 매입원가 합계]
    foreach ($rows as &$r) {
        $code = $r['stock_code'];
        $qty  = (int) $r['quantity'];
        $px   = (float) $r['price'];
        $cur  = (float) ($r['current_price'] ?? 0);
        if (!isset($book[$code])) $book[$code] = ['qty' => 0, 'cost' => 0.0];

        if ($r['order_type'] === 'buy') {
            $book[$code]['qty']  += $qty;
            $book[$code]['cost'] += $px * $qty;
            $r['rate']       = ($px > 0 && $cur > 0) ? round(($cur - $px) / $px * 100, 2) : null;
            $r['rate_basis'] = 'current';       // 매수가 대비 현재가
        } else {
            $avg = $book[$code]['qty'] > 0 ? $book[$code]['cost'] / $book[$code]['qty'] : 0.0;
            $r['rate']       = $avg > 0 ? round(($px - $avg) / $avg * 100, 2) : null;
            $r['rate_basis'] = 'realized';      // 실현수익률

            $sellQty = min($qty, $book[$code]['qty']);
            $book[$code]['qty']  -= $sellQty;
            $book[$code]['cost'] -= $avg * $sellQty;
            if ($book[$code]['qty'] <= 0) $book[$code] = ['qty' => 0, 'cost' => 0.0];
        }

        $r['id']       = (int) $r['id'];
        $r['is_auto']  = (int) ($r['is_auto'] ?? 0);
        $r['quantity'] = $qty;
        $r['price']    = $px;
    }
    unset($r);

    $rows = array_reverse($rows);                       // 최신이 맨 위
    return array_slice($rows, 0, max(1, (int) $limit)); // 최신 N건만
}

// -----------------------------
// 자동매매 일시정지 상태 (나중에 매매 로직이 이 값을 읽고 멈춘다)
// -----------------------------
function isAutoTradePaused($conn, $userId)
{
    ensureAutoTradeSchema($conn);
    $stmt = @mysqli_prepare($conn, "SELECT is_paused FROM auto_trade_settings WHERE user_id = ?");
    if (!$stmt) return false;
    mysqli_stmt_bind_param($stmt, "i", $userId);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    return $row ? ((int) $row['is_paused'] === 1) : false;
}

function setAutoTradePaused($conn, $userId, $paused)
{
    ensureAutoTradeSchema($conn);
    $flag = $paused ? 1 : 0;
    $stmt = mysqli_prepare($conn, "INSERT INTO auto_trade_settings (user_id, is_paused) VALUES (?, ?)
        ON DUPLICATE KEY UPDATE is_paused = VALUES(is_paused)");
    if (!$stmt) return false;
    mysqli_stmt_bind_param($stmt, "ii", $userId, $flag);
    return mysqli_stmt_execute($stmt);
}
