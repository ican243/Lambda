<?php
require_once __DIR__ . '/../config/db.php';

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
