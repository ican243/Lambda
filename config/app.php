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
    // nginx 가 맨 앞에서 직접 손님을 받으므로(앞단 프록시 없음) 진짜 IP는 REMOTE_ADDR 하나뿐이다.
    // X-Forwarded-For 는 손님이 마음대로 적어 보낼 수 있어서 믿으면 IP를 속일 수 있다 → 쓰지 않는다.
    return substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45);
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

// -----------------------------------------------------------
// CSRF 방어
// -----------------------------------------------------------
// 다른 사이트가 로그인된 브라우저를 시켜 우리 서버로 POST를 쏘는 공격(CSRF)을 막는다.
// 세션에만 있는 난수 토큰을 폼에 심고 서버에서 대조 → 외부 사이트는 토큰 값을 알 수 없어 실패.
// 세션 이름이 USER_SESSION / ADMIN_SESSION 으로 나뉘어 있으므로 토큰도 자동으로 분리된다.

function csrfToken()
{
    if (session_status() !== PHP_SESSION_ACTIVE) return '';
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

// 폼 안에 넣을 hidden input
function csrfField()
{
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrfToken(), ENT_QUOTES) . '">';
}

// 토큰이 유효한가? (hash_equals = 타이밍 공격 방지 비교)
function csrfValid()
{
    $sent = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    $have = $_SESSION['csrf_token'] ?? '';
    return $sent !== '' && $have !== '' && hash_equals($have, $sent);
}

// POST 요청 검증 실패 시 차단.
//   $mode     : 'redirect'(기본) | 'json'
//   $errValue : 리다이렉트 시 붙일 error 값. 에러코드로 분기하는 페이지는 'csrf' 같은 코드를,
//               메시지를 그대로 출력하는 페이지는 null(기본 문구)을 쓴다.
//   $errKey   : 쿼리스트링 키. user 쪽은 'error', admin 쪽은 'err' 를 쓴다.
function requireCsrf($redirectTo = 'index.php', $mode = 'redirect', $errValue = null, $errKey = 'error')
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;
    if (csrfValid()) return;

    if ($mode === 'json') {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'csrf']);
    } else {
        $sep = strpos($redirectTo, '?') === false ? '?' : '&';
        $err = $errValue ?? '보안 토큰이 유효하지 않습니다. 새로고침 후 다시 시도해주세요.';
        header('Location: ' . $redirectTo . $sep . $errKey . '=' . urlencode($err));
    }
    exit;
}

// -----------------------------------------------------------
// 로그인 시도 제한 (비밀번호 마구 넣어 보기 공격 방어)
// -----------------------------------------------------------
// 10분 안에 같은 IP에서 5번 틀리면, 마지막으로 틀린 때부터 10분 동안 로그인을 막는다.
// 여러 IP를 돌려 가며 한 계정만 노리는 경우도 있어서, 같은 아이디가 10번 틀려도 막는다.
// $scope 는 'user' / 'admin' — 사용자와 관리자 기록을 따로 센다.
const LOGIN_MAX_FAILS_IP = 5;
const LOGIN_MAX_FAILS_ID = 10;
const LOGIN_LOCK_SEC     = 600;

function ensureLoginAttemptSchema($conn)
{
    static $done = false;
    if ($done) return;
    $done = true;
    @mysqli_query($conn, "CREATE TABLE IF NOT EXISTS login_attempts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        scope VARCHAR(10) NOT NULL, ip VARCHAR(45) NOT NULL, ident VARCHAR(100) NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_ip (scope, ip, created_at), INDEX idx_ident (scope, ident, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

// 지금 막혀 있으면 남은 초, 아니면 0
function loginLockSeconds($conn, $scope, $ident)
{
    ensureLoginAttemptSchema($conn);
    $ip = clientIp();
    $ident = mb_strtolower(mb_substr(trim($ident), 0, 100));
    $wait = 0;
    foreach ([['ip', $ip, LOGIN_MAX_FAILS_IP], ['ident', $ident, LOGIN_MAX_FAILS_ID]] as [$col, $val, $max]) {
        if ($val === '') continue;
        $stmt = mysqli_prepare($conn, "SELECT COUNT(*) AS c, TIMESTAMPDIFF(SECOND, MAX(created_at), NOW()) AS ago
            FROM login_attempts WHERE scope = ? AND $col = ? AND created_at > NOW() - INTERVAL " . LOGIN_LOCK_SEC . " SECOND");
        if (!$stmt) continue;
        mysqli_stmt_bind_param($stmt, "ss", $scope, $val);
        mysqli_stmt_execute($stmt);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        if ($row && (int) $row['c'] >= $max) $wait = max($wait, LOGIN_LOCK_SEC - (int) $row['ago']);
    }
    return max(0, $wait);
}

function recordLoginFail($conn, $scope, $ident)
{
    ensureLoginAttemptSchema($conn);
    $ip = clientIp();
    $ident = mb_strtolower(mb_substr(trim($ident), 0, 100));
    $stmt = mysqli_prepare($conn, "INSERT INTO login_attempts (scope, ip, ident) VALUES (?, ?, ?)");
    if ($stmt) { mysqli_stmt_bind_param($stmt, "sss", $scope, $ip, $ident); @mysqli_stmt_execute($stmt); }
    @mysqli_query($conn, "DELETE FROM login_attempts WHERE created_at < NOW() - INTERVAL 1 DAY");   // 하루 지난 기록은 정리
}

// 로그인 성공 → 이 IP·아이디의 틀린 기록을 지운다
function clearLoginFails($conn, $scope, $ident)
{
    ensureLoginAttemptSchema($conn);
    $ip = clientIp();
    $ident = mb_strtolower(mb_substr(trim($ident), 0, 100));
    $stmt = mysqli_prepare($conn, "DELETE FROM login_attempts WHERE scope = ? AND (ip = ? OR ident = ?)");
    if ($stmt) { mysqli_stmt_bind_param($stmt, "sss", $scope, $ip, $ident); @mysqli_stmt_execute($stmt); }
}

// -----------------------------------------------------------
// 로그인 쿠키(세션) 보호 설정 — user/admin 공용
// -----------------------------------------------------------
//   httponly : 자바스크립트가 쿠키를 읽지 못함 (화면에 나쁜 코드가 끼어들어도 쿠키를 못 훔침)
//   secure   : https 연결에서만 쿠키를 보냄
//   samesite : 다른 사이트에서 보낸 요청에는 쿠키를 안 붙임 (Lax = 링크로 들어오는 건 허용)
//   strict_mode : 서버가 만든 적 없는 세션 번호는 받지 않음
function startSecureSession()
{
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    ini_set('session.use_strict_mode', '1');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}
