<?php
// config/app.php
// -----------------------------------------------------------
// user·admin 공용 운영 계층: 설정(key-value) · 접속/에러 로깅 · 점검모드 · 공지
// 모든 테이블은 없으면 자동 생성 → 별도 마이그레이션 불필요.
// 함수는 $conn을 인자로 받아 user/admin 어디서든 재사용.
// -----------------------------------------------------------

if (!defined('INITIAL_CASH')) define('INITIAL_CASH', 10000000);   // 초기 예수금 기본값

function ensureOpsSchema($conn)
{
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS app_settings (
        skey VARCHAR(50) PRIMARY KEY, svalue VARCHAR(255)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    mysqli_query($conn, "INSERT IGNORE INTO app_settings (skey, svalue) VALUES
        ('initial_cash', '" . INITIAL_CASH . "'),
        ('maintenance_mode', '0'),
        ('maintenance_msg', '서비스 점검 중입니다. 잠시 후 다시 이용해주세요.')");

    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS access_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT, ip VARCHAR(45), ua VARCHAR(255), action VARCHAR(20),
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_ip (ip), INDEX idx_user (user_id), INDEX idx_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS notices (
        id INT AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(200), body TEXT, is_active TINYINT DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS error_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        level VARCHAR(20), context VARCHAR(50), message VARCHAR(500),
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP, INDEX idx_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

// ---- 설정(key-value) ----
function getSetting($conn, $key, $default = null)
{
    $stmt = @mysqli_prepare($conn, "SELECT svalue FROM app_settings WHERE skey = ?");
    if (!$stmt) return $default;
    mysqli_stmt_bind_param($stmt, "s", $key);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    return $row ? $row['svalue'] : $default;
}
function setSetting($conn, $key, $val)
{
    $stmt = mysqli_prepare($conn, "INSERT INTO app_settings (skey, svalue) VALUES (?, ?)
        ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)");
    mysqli_stmt_bind_param($stmt, "ss", $key, $val);
    return mysqli_stmt_execute($stmt);
}
function getInitialCash($conn) { return (int) getSetting($conn, 'initial_cash', INITIAL_CASH); }

// ---- 점검 모드 ----
function isMaintenance($conn) { return getSetting($conn, 'maintenance_mode', '0') === '1'; }
function maintenanceMsg($conn) { return getSetting($conn, 'maintenance_msg', '서비스 점검 중입니다.'); }

// ---- 접속/거래 IP 로깅 (FDS·CS 기반) ----
function clientIp()
{
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
    if ($ip && strpos($ip, ',') !== false) $ip = trim(explode(',', $ip)[0]);   // 프록시 체인 첫 IP
    return substr($ip, 0, 45);
}
function logAccess($conn, $userId, $action)
{
    $stmt = @mysqli_prepare($conn, "INSERT INTO access_logs (user_id, ip, ua, action) VALUES (?, ?, ?, ?)");
    if (!$stmt) { ensureOpsSchema($conn); $stmt = mysqli_prepare($conn, "INSERT INTO access_logs (user_id, ip, ua, action) VALUES (?, ?, ?, ?)"); }
    if (!$stmt) return;
    $ip = clientIp();
    $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);
    mysqli_stmt_bind_param($stmt, "isss", $userId, $ip, $ua, $action);
    @mysqli_stmt_execute($stmt);
}

// ---- 에러 로그 수집 ----
function logError($conn, $level, $context, $message)
{
    $stmt = @mysqli_prepare($conn, "INSERT INTO error_logs (level, context, message) VALUES (?, ?, ?)");
    if (!$stmt) { ensureOpsSchema($conn); $stmt = mysqli_prepare($conn, "INSERT INTO error_logs (level, context, message) VALUES (?, ?, ?)"); }
    if (!$stmt) return;
    $message = mb_substr($message, 0, 500);
    mysqli_stmt_bind_param($stmt, "sss", $level, $context, $message);
    @mysqli_stmt_execute($stmt);
}

// ---- 공지 (유저 배너용: 활성 공지) ----
function getActiveNotices($conn)
{
    $res = @mysqli_query($conn, "SELECT id, title, body, created_at FROM notices WHERE is_active = 1 ORDER BY created_at DESC LIMIT 5");
    $rows = [];
    if ($res) while ($r = mysqli_fetch_assoc($res)) $rows[] = $r;
    return $rows;
}
