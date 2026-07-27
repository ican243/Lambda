<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';   // 공용 운영계층(설정·로깅·점검·공지)

function startAdminSession()
{
    session_name('ADMIN_SESSION');
    session_start();
}

function isAdminLoggedIn()
{
    return isset($_SESSION['admin_id']);
}

function getAdminByAdminId($conn, $adminId)
{
    $stmt = mysqli_prepare($conn, "SELECT * FROM admins WHERE admin_id = ?");
    mysqli_stmt_bind_param($stmt, "s", $adminId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    return mysqli_fetch_assoc($result);
}


// 종목 이름으로 검색 (LIKE 검색)

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


// 관심종목 추가

function addWatchlist($conn, $userId, $stockCode)
{
    $stmt = mysqli_prepare($conn, "
        INSERT IGNORE INTO watchlist (user_id, stock_code) VALUES (?, ?)
    ");
    mysqli_stmt_bind_param($stmt, "is", $userId, $stockCode);
    return mysqli_stmt_execute($stmt);
}

// 관심종목 삭제

function removeWatchlist($conn, $userId, $stockCode)
{
    $stmt = mysqli_prepare($conn, "
        DELETE FROM watchlist WHERE user_id = ? AND stock_code = ?
    ");
    mysqli_stmt_bind_param($stmt, "is", $userId, $stockCode);
    return mysqli_stmt_execute($stmt);
}


// 내 관심종목의 최신 시세

function getMyWatchlistPrices($conn, $userId)
{
    $stmt = mysqli_prepare($conn, "
        SELECT sl.stock_code, sm.stock_name, sl.price, sl.change_price, sl.change_rate, sl.created_at
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


// 전체 유저 수

function getTotalUserCount($conn)
{
    $result = mysqli_query($conn, "SELECT COUNT(*) as cnt FROM users");
    return mysqli_fetch_assoc($result)['cnt'];
}


// 오늘 가입한 유저 수

function getTodaySignupCount($conn)
{
    $result = mysqli_query($conn, "SELECT COUNT(*) as cnt FROM users WHERE DATE(created_at) = CURDATE()");
    return mysqli_fetch_assoc($result)['cnt'];
}


// 가장 인기 있는 관심종목 TOP 5

function getTopWatchedStocks($conn)
{
    $sql = "
        SELECT sm.stock_name, w.stock_code, COUNT(*) as cnt
        FROM watchlist w
        INNER JOIN stock_master sm ON w.stock_code = sm.stock_code
        GROUP BY w.stock_code
        ORDER BY cnt DESC
        LIMIT 5
    ";
    $result = mysqli_query($conn, $sql);
    $stocks = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $stocks[] = $row;
    }
    return $stocks;
}


// 전체 저장된 시세 로그 건수 (데이터 적재량 파악용)

function getTotalLogCount($conn)
{
    $result = mysqli_query($conn, "SELECT COUNT(*) as cnt FROM stock_logs");
    return mysqli_fetch_assoc($result)['cnt'];
}


// -----------------------------
// 오늘 거래 건수 (매수+매도)
// -----------------------------
function getTodayOrderCount($conn)
{
    $result = mysqli_query($conn, "SELECT COUNT(*) as cnt FROM orders WHERE DATE(created_at) = CURDATE()");
    return mysqli_fetch_assoc($result)['cnt'];
}

// -----------------------------
// 오늘 거래대금 총합
// -----------------------------
function getTodayTradeVolume($conn)
{
    $result = mysqli_query($conn, "SELECT SUM(total_amount) as total FROM orders WHERE DATE(created_at) = CURDATE()");
    return mysqli_fetch_assoc($result)['total'] ?? 0;
}

// -----------------------------
// 최근 거래 내역 TOP 10 (전체 유저 통틀어)
// -----------------------------
function getRecentOrders($conn)
{
    $sql = "
        SELECT o.created_at, u.nickname, sm.stock_name, o.order_type, o.quantity, o.price, o.total_amount
        FROM orders o
        INNER JOIN users u ON o.user_id = u.id
        INNER JOIN stock_master sm ON o.stock_code = sm.stock_code
        ORDER BY o.created_at DESC
        LIMIT 10
    ";
    $result = mysqli_query($conn, $sql);
    $orders = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $orders[] = $row;
    }
    return $orders;
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

    // 1. 1분 단위로 그룹 묶기
    $buckets = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $minuteKey = substr($row['created_at'], 0, 16);   // "2026-07-22 10:25" 까지만 잘라서 분 단위 키로 사용
        $buckets[$minuteKey][] = (float) $row['price'];
    }

    // 2. 각 그룹에서 시가/고가/저가/종가 계산
    $candles = [];
    foreach ($buckets as $minuteKey => $prices) {
        $candles[] = [
            'time'  => strtotime($minuteKey . ':00'),   // Lightweight Charts는 유닉스 타임스탬프(초) 필요
            'open'  => $prices[0],
            'high'  => max($prices),
            'low'   => min($prices),
            'close' => end($prices),
        ];
    }

    return $candles;
}

// =============================================================
// [관리자 대시보드 확장] 스키마 보강 · 거래분석 · 리더보드 · 시스템상태 · 회원관리
//   테이블/컬럼이 없으면 자동 보강 → 형이 별도 마이그레이션 안 돌려도 됨.
// =============================================================
function ensureAdminSchema($conn)
{
    // users.status 컬럼 (계정 정지/활성) — MySQL 8.x는 ADD COLUMN IF NOT EXISTS 미지원 → 존재 확인 후 추가
    $r = mysqli_query($conn, "
        SELECT COUNT(*) c FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'status'
    ");
    if ($r && (int) mysqli_fetch_assoc($r)['c'] === 0) {
        @mysqli_query($conn, "ALTER TABLE users ADD COLUMN status VARCHAR(10) NOT NULL DEFAULT 'active'");
    }
    // 예수금 조정 감사로그
    mysqli_query($conn, "
        CREATE TABLE IF NOT EXISTS admin_cash_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            admin_id INT, user_id INT NOT NULL,
            delta BIGINT NOT NULL, balance_after BIGINT NOT NULL,
            reason VARCHAR(200), created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    // admins.role 컬럼 (RBAC) — 없으면 추가하고 기존 관리자는 전원 super로(첫 도입 잠김 방지)
    $r = mysqli_query($conn, "
        SELECT COUNT(*) c FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'admins' AND column_name = 'role'
    ");
    if ($r && (int) mysqli_fetch_assoc($r)['c'] === 0) {
        @mysqli_query($conn, "ALTER TABLE admins ADD COLUMN role VARCHAR(20) NOT NULL DEFAULT 'staff'");
        @mysqli_query($conn, "UPDATE admins SET role = 'super'");   // 기존 관리자 = 최고관리자
    }
    // 공용 운영 스키마(app_settings·access_logs·notices·error_logs)
    ensureOpsSchema($conn);
}

// =============================================================
// [RBAC] 역할 기반 접근제어
//   실무 원칙: 정책을 한 곳에 정의하고, "서버측"에서 강제한다. (UI 숨김은 UX 보조일 뿐)
// =============================================================
$GLOBALS['ROLE_PERMS'] = [
    'super' => ['*'],                          // 최고관리자: 전권
    'staff' => ['view', 'suspend_user'],       // CS: 조회 + 계정 정지만 (예수금·설정·관리자 불가)
];

function adminRole() { return $_SESSION['admin_role'] ?? 'super'; }   // 레거시 세션은 super로 간주(기존 관리자)

function adminCan($perm)
{
    $role = adminRole();
    $perms = $GLOBALS['ROLE_PERMS'][$role] ?? [];
    return in_array('*', $perms, true) || in_array($perm, $perms, true);
}

// 서버측 강제: 권한 없으면 지정 경로로 되돌리며 차단 (액션 핸들러에서 호출)
function requireAdminCan($perm, $back = 'index.php')
{
    if (!adminCan($perm)) {
        header('Location: ' . $back . (strpos($back, '?') !== false ? '&' : '?') . 'err=' . urlencode('권한이 없습니다. (최고관리자 전용)'));
        exit;
    }
}

function roleLabel($role) { return $role === 'super' ? '최고관리자' : 'CS 담당자'; }

// ---- 관리자 계정 관리 (super 전용) ----
function getAllAdmins($conn)
{
    $res = mysqli_query($conn, "SELECT id, admin_id, name, COALESCE(role,'super') AS role FROM admins ORDER BY id");
    $rows = [];
    if ($res) while ($r = mysqli_fetch_assoc($res)) $rows[] = $r;
    return $rows;
}
function createAdmin($conn, $adminId, $password, $name, $role)
{
    $adminId = trim($adminId); $name = trim($name);
    $role = ($role === 'super') ? 'super' : 'staff';
    if ($adminId === '' || $password === '') throw new Exception('아이디와 비밀번호를 입력하세요.');
    $chk = mysqli_prepare($conn, "SELECT id FROM admins WHERE admin_id = ?");
    mysqli_stmt_bind_param($chk, "s", $adminId);
    mysqli_stmt_execute($chk);
    if (mysqli_num_rows(mysqli_stmt_get_result($chk)) > 0) throw new Exception('이미 존재하는 아이디입니다.');
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = mysqli_prepare($conn, "INSERT INTO admins (admin_id, password, name, role) VALUES (?, ?, ?, ?)");
    mysqli_stmt_bind_param($stmt, "ssss", $adminId, $hash, $name, $role);
    return mysqli_stmt_execute($stmt);
}
function setAdminRole($conn, $id, $role)
{
    $role = ($role === 'super') ? 'super' : 'staff';
    $stmt = mysqli_prepare($conn, "UPDATE admins SET role = ? WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "si", $role, $id);
    return mysqli_stmt_execute($stmt);
}
function deleteAdmin($conn, $id)
{
    $stmt = mysqli_prepare($conn, "DELETE FROM admins WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "i", $id);
    return mysqli_stmt_execute($stmt);
}

// 전체 회원 예수금 일괄 지급 (이벤트용). 감사로그 유저별 기록.
function grantCashToAll($conn, $adminId, $amount, $reason = '')
{
    $amount = (int) $amount;
    if ($amount <= 0) throw new Exception('지급 금액은 1원 이상이어야 합니다.');
    mysqli_begin_transaction($conn);
    try {
        mysqli_query($conn, "UPDATE accounts SET cash_balance = cash_balance + " . $amount);
        $affected = mysqli_affected_rows($conn);
        // 지급 후 잔액으로 유저별 로그 남기기
        $stmt = mysqli_prepare($conn, "
            INSERT INTO admin_cash_logs (admin_id, user_id, delta, balance_after, reason)
            SELECT ?, user_id, ?, cash_balance, ? FROM accounts
        ");
        $r = '[일괄지급] ' . $reason;
        mysqli_stmt_bind_param($stmt, "iis", $adminId, $amount, $r);
        mysqli_stmt_execute($stmt);
        mysqli_commit($conn);
        return $affected;
    } catch (Exception $e) {
        mysqli_rollback($conn);
        throw $e;
    }
}

// 기간 필터 → created_at 조건식 (period는 화이트리스트라 인젝션 안전)
function periodCond($period, $col = 'created_at')
{
    switch ($period) {
        case 'week':  return "$col >= (CURDATE() - INTERVAL 6 DAY)";
        case 'month': return "$col >= (CURDATE() - INTERVAL 29 DAY)";
        default:      return "DATE($col) = CURDATE()";   // today
    }
}

// ---- 거래·종목 분석 ----
function getTopTradedStocks($conn, $period, $limit = 5)
{
    $limit = (int) $limit;
    $cond = periodCond($period, 'o.created_at');
    $sql = "
        SELECT o.stock_code,
               COALESCE(MAX(sm.stock_name), o.stock_code) AS stock_name,
               COUNT(*) AS trades, SUM(o.quantity) AS vol, SUM(o.total_amount) AS amt
        FROM orders o
        LEFT JOIN stock_master sm ON sm.stock_code = o.stock_code
        WHERE $cond
        GROUP BY o.stock_code
        ORDER BY amt DESC
        LIMIT $limit
    ";
    $res = mysqli_query($conn, $sql);
    $rows = [];
    while ($row = mysqli_fetch_assoc($res)) $rows[] = $row;
    return $rows;
}

function getBuySellRatio($conn, $period)
{
    $cond = periodCond($period);
    $res = mysqli_query($conn, "
        SELECT order_type, COUNT(*) AS cnt, COALESCE(SUM(total_amount),0) AS amt
        FROM orders WHERE $cond GROUP BY order_type
    ");
    $out = ['buy' => ['cnt' => 0, 'amt' => 0], 'sell' => ['cnt' => 0, 'amt' => 0]];
    while ($row = mysqli_fetch_assoc($res)) {
        if (isset($out[$row['order_type']])) {
            $out[$row['order_type']] = ['cnt' => (int) $row['cnt'], 'amt' => (int) $row['amt']];
        }
    }
    return $out;
}

function getHourlyVolume($conn, $period)
{
    $cond = periodCond($period);
    $res = mysqli_query($conn, "
        SELECT HOUR(created_at) AS h, COUNT(*) AS cnt, COALESCE(SUM(total_amount),0) AS amt
        FROM orders WHERE $cond GROUP BY h ORDER BY h
    ");
    $map = [];
    while ($row = mysqli_fetch_assoc($res)) $map[(int) $row['h']] = ['cnt' => (int) $row['cnt'], 'amt' => (int) $row['amt']];
    return $map;
}

// ---- 수익률 리더보드 ----
// 유저 총자산 = 예수금 + Σ(보유수량 × 현재가), 초기 예수금 대비 수익률 (INITIAL_CASH는 config/app.php)
function getLeaderboard($conn, $dir = 'top', $limit = 5)
{
    $limit = (int) $limit;
    $order = ($dir === 'bottom') ? 'ASC' : 'DESC';
    $sql = "
        SELECT u.id, u.nickname,
               (a.cash_balance + COALESCE(SUM(h.quantity * sl.price), 0)) AS total_asset
        FROM users u
        JOIN accounts a ON a.user_id = u.id
        LEFT JOIN holdings h ON h.user_id = u.id
        LEFT JOIN stock_latest sl ON sl.stock_code = h.stock_code
        GROUP BY u.id, u.nickname, a.cash_balance
        ORDER BY total_asset $order
        LIMIT $limit
    ";
    $initial = getInitialCash($conn) ?: INITIAL_CASH;
    $res = mysqli_query($conn, $sql);
    $rows = [];
    while ($row = mysqli_fetch_assoc($res)) {
        $total = (int) $row['total_asset'];
        $row['profit'] = $total - $initial;
        $row['rate'] = $initial > 0 ? round(($total - $initial) / $initial * 100, 2) : 0;
        $rows[] = $row;
    }
    return $rows;
}

// ---- 시스템/수집 상태 ----
function getLastCollectTime($conn)
{
    $res = mysqli_query($conn, "SELECT MAX(updated_at) AS t FROM stock_latest");
    return $res ? (mysqli_fetch_assoc($res)['t'] ?? null) : null;
}
function isWsAlive($host = '127.0.0.1', $port = 8080)
{
    $fp = @fsockopen($host, $port, $errno, $errstr, 1);   // 1초 타임아웃
    if ($fp) { fclose($fp); return true; }
    return false;
}

// ---- 회원 관리 ----
function searchUsers($conn, $keyword = '', $limit = 50)
{
    $limit = (int) $limit;
    if ($keyword !== '') {
        $stmt = mysqli_prepare($conn, "
            SELECT u.id, u.email, u.nickname, COALESCE(u.status,'active') AS status,
                   u.created_at, COALESCE(a.cash_balance,0) AS cash_balance
            FROM users u LEFT JOIN accounts a ON a.user_id = u.id
            WHERE u.email LIKE ? OR u.nickname LIKE ?
            ORDER BY u.created_at DESC LIMIT $limit
        ");
        $like = "%{$keyword}%";
        mysqli_stmt_bind_param($stmt, "ss", $like, $like);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
    } else {
        $res = mysqli_query($conn, "
            SELECT u.id, u.email, u.nickname, COALESCE(u.status,'active') AS status,
                   u.created_at, COALESCE(a.cash_balance,0) AS cash_balance
            FROM users u LEFT JOIN accounts a ON a.user_id = u.id
            ORDER BY u.created_at DESC LIMIT $limit
        ");
    }
    $rows = [];
    while ($row = mysqli_fetch_assoc($res)) $rows[] = $row;
    return $rows;
}

function setUserStatus($conn, $userId, $status)
{
    $status = ($status === 'suspended') ? 'suspended' : 'active';
    $stmt = mysqli_prepare($conn, "UPDATE users SET status = ? WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "si", $status, $userId);
    return mysqli_stmt_execute($stmt);
}

// 예수금 조정 (delta는 +/- 가능). 감사로그 남김. 음수 잔액 방지.
function adjustUserCash($conn, $adminId, $userId, $delta, $reason = '')
{
    $delta = (int) $delta;
    mysqli_begin_transaction($conn);
    try {
        // 계좌 없으면 생성
        $stmt = mysqli_prepare($conn, "SELECT cash_balance FROM accounts WHERE user_id = ? FOR UPDATE");
        mysqli_stmt_bind_param($stmt, "i", $userId);
        mysqli_stmt_execute($stmt);
        $acc = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        if (!$acc) {
            $stmt = mysqli_prepare($conn, "INSERT INTO accounts (user_id, cash_balance) VALUES (?, 0)");
            mysqli_stmt_bind_param($stmt, "i", $userId);
            mysqli_stmt_execute($stmt);
            $cur = 0;
        } else {
            $cur = (int) $acc['cash_balance'];
        }
        $after = $cur + $delta;
        if ($after < 0) throw new Exception("잔액이 음수가 될 수 없습니다. (현재 " . number_format($cur) . "원)");

        $stmt = mysqli_prepare($conn, "UPDATE accounts SET cash_balance = ? WHERE user_id = ?");
        mysqli_stmt_bind_param($stmt, "ii", $after, $userId);
        mysqli_stmt_execute($stmt);

        $stmt = mysqli_prepare($conn, "
            INSERT INTO admin_cash_logs (admin_id, user_id, delta, balance_after, reason)
            VALUES (?, ?, ?, ?, ?)
        ");
        mysqli_stmt_bind_param($stmt, "iiiis", $adminId, $userId, $delta, $after, $reason);
        mysqli_stmt_execute($stmt);

        mysqli_commit($conn);
        return $after;
    } catch (Exception $e) {
        mysqli_rollback($conn);
        throw $e;
    }
}

// =============================================================
// [유저 상세조회(CS) · FDS · 공지 · 에러로그 · 토큰]
// =============================================================

// ---- 유저 상세 (CS) ----
function getUserProfile($conn, $userId)
{
    $stmt = mysqli_prepare($conn, "
        SELECT u.id, u.email, u.nickname, COALESCE(u.status,'active') AS status,
               u.created_at, COALESCE(a.cash_balance,0) AS cash_balance
        FROM users u LEFT JOIN accounts a ON a.user_id = u.id
        WHERE u.id = ?
    ");
    mysqli_stmt_bind_param($stmt, "i", $userId);
    mysqli_stmt_execute($stmt);
    return mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
}

function getUserHoldingsAdmin($conn, $userId)
{
    $stmt = mysqli_prepare($conn, "
        SELECT h.stock_code, COALESCE(sm.stock_name, h.stock_code) AS stock_name,
               h.quantity, h.avg_price, COALESCE(sl.price,0) AS price
        FROM holdings h
        LEFT JOIN stock_master sm ON sm.stock_code = h.stock_code
        LEFT JOIN stock_latest sl ON sl.stock_code = h.stock_code
        WHERE h.user_id = ?
        ORDER BY (h.quantity * COALESCE(sl.price,0)) DESC
    ");
    mysqli_stmt_bind_param($stmt, "i", $userId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $rows = [];
    while ($r = mysqli_fetch_assoc($res)) $rows[] = $r;
    return $rows;
}

function getUserOrdersAdmin($conn, $userId, $limit = 30)
{
    $limit = (int) $limit;
    $stmt = mysqli_prepare($conn, "
        SELECT o.created_at, COALESCE(sm.stock_name, o.stock_code) AS stock_name,
               o.order_type, o.quantity, o.price, o.total_amount
        FROM orders o LEFT JOIN stock_master sm ON sm.stock_code = o.stock_code
        WHERE o.user_id = ? ORDER BY o.created_at DESC LIMIT $limit
    ");
    mysqli_stmt_bind_param($stmt, "i", $userId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $rows = [];
    while ($r = mysqli_fetch_assoc($res)) $rows[] = $r;
    return $rows;
}

function getUserCashLogs($conn, $userId, $limit = 20)
{
    $limit = (int) $limit;
    $stmt = @mysqli_prepare($conn, "
        SELECT delta, balance_after, reason, created_at
        FROM admin_cash_logs WHERE user_id = ? ORDER BY created_at DESC LIMIT $limit
    ");
    if (!$stmt) return [];
    mysqli_stmt_bind_param($stmt, "i", $userId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $rows = [];
    while ($r = mysqli_fetch_assoc($res)) $rows[] = $r;
    return $rows;
}

function getUserAccessLogs($conn, $userId, $limit = 20)
{
    $limit = (int) $limit;
    $stmt = @mysqli_prepare($conn, "
        SELECT ip, action, created_at FROM access_logs
        WHERE user_id = ? ORDER BY created_at DESC LIMIT $limit
    ");
    if (!$stmt) return [];
    mysqli_stmt_bind_param($stmt, "i", $userId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $rows = [];
    while ($r = mysqli_fetch_assoc($res)) $rows[] = $r;
    return $rows;
}

// ---- 간단 FDS (이상거래 탐지) ----
// 최근 7일, 한 IP로 접속한 계정이 2개 이상 → 다계정 의심
function getFdsMultiAccountIps($conn)
{
    $res = @mysqli_query($conn, "
        SELECT ip, COUNT(DISTINCT user_id) AS accounts, COUNT(*) AS hits, MAX(created_at) AS last_at
        FROM access_logs
        WHERE created_at >= (NOW() - INTERVAL 7 DAY) AND ip <> '' AND user_id IS NOT NULL
        GROUP BY ip HAVING accounts >= 2 ORDER BY accounts DESC, hits DESC LIMIT 10
    ");
    $rows = [];
    if ($res) while ($r = mysqli_fetch_assoc($res)) $rows[] = $r;
    return $rows;
}
// 최근 10분간 거래 10건 이상 → 비정상 주기 거래 의심
function getFdsRapidTraders($conn, $windowMin = 10, $threshold = 10)
{
    $windowMin = (int) $windowMin; $threshold = (int) $threshold;
    $res = @mysqli_query($conn, "
        SELECT o.user_id, COALESCE(u.nickname, CONCAT('#', o.user_id)) AS nickname,
               COUNT(*) AS cnt, MAX(o.created_at) AS last_at
        FROM orders o LEFT JOIN users u ON u.id = o.user_id
        WHERE o.created_at >= (NOW() - INTERVAL $windowMin MINUTE)
        GROUP BY o.user_id, nickname HAVING cnt >= $threshold ORDER BY cnt DESC LIMIT 10
    ");
    $rows = [];
    if ($res) while ($r = mysqli_fetch_assoc($res)) $rows[] = $r;
    return $rows;
}

// ---- 공지 관리 ----
function getAllNotices($conn)
{
    $res = @mysqli_query($conn, "SELECT id, title, body, is_active, created_at FROM notices ORDER BY created_at DESC");
    $rows = [];
    if ($res) while ($r = mysqli_fetch_assoc($res)) $rows[] = $r;
    return $rows;
}
function createNotice($conn, $title, $body)
{
    $title = trim($title);
    if ($title === '') throw new Exception('공지 제목을 입력하세요.');
    $stmt = mysqli_prepare($conn, "INSERT INTO notices (title, body, is_active) VALUES (?, ?, 1)");
    mysqli_stmt_bind_param($stmt, "ss", $title, $body);
    return mysqli_stmt_execute($stmt);
}
function setNoticeActive($conn, $id, $active)
{
    $active = $active ? 1 : 0;
    $stmt = mysqli_prepare($conn, "UPDATE notices SET is_active = ? WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "ii", $active, $id);
    return mysqli_stmt_execute($stmt);
}
function deleteNotice($conn, $id)
{
    $stmt = mysqli_prepare($conn, "DELETE FROM notices WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "i", $id);
    return mysqli_stmt_execute($stmt);
}

// ---- 에러 로그 조회 ----
function getRecentErrors($conn, $limit = 15)
{
    $limit = (int) $limit;
    $res = @mysqli_query($conn, "SELECT level, context, message, created_at FROM error_logs ORDER BY created_at DESC LIMIT $limit");
    $rows = [];
    if ($res) while ($r = mysqli_fetch_assoc($res)) $rows[] = $r;
    return $rows;
}
function getTodayErrorCount($conn)
{
    $res = @mysqli_query($conn, "SELECT COUNT(*) c FROM error_logs WHERE DATE(created_at) = CURDATE()");
    return $res ? (int) mysqli_fetch_assoc($res)['c'] : 0;
}

// ---- KIS 토큰 만료 시각 (config/token_cache.json) ----
function getKisTokenExpiry()
{
    $f = __DIR__ . '/../config/token_cache.json';
    if (!file_exists($f)) return null;
    $j = json_decode(file_get_contents($f), true);
    return $j['expire_at'] ?? null;   // unix ts
}
