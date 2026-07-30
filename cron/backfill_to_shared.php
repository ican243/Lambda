<?php
// cron/backfill_to_shared.php
// -----------------------------------------------------------------
// 로컬 Laragon DB → 원격 공용 DB 로 "시세 데이터만" 채워 넣는다.
//
// 왜 필요한가:
//   DB를 원격 공용으로 옮긴 뒤에도 stock-ws 는 한동안 로컬에 계속 수집했다.
//   그래서 원격엔 그 구간(어제 낮 ~ 지금)의 시세가 통째로 없고,
//   종목상세에 들어가면 과거 데이터가 없어서 실시간만 쌓이는 것처럼 보였다.
//
// 안전장치:
//   · 유저 데이터(users/accounts/holdings/orders/watchlist/stock_posts)와
//     stock_master 는 원격이 최신이므로 절대 건드리지 않는다. 시세 3개 테이블만.
//   · stock_logs 는 id 까지 그대로 복사 + INSERT IGNORE → 여러 번 돌려도 중복 없음.
//   · 분봉/현재가는 PK 기준 ON DUPLICATE KEY UPDATE → 멱등.
//
// 사용법:  php.exe cron/backfill_to_shared.php [--dry]
// -----------------------------------------------------------------

require_once __DIR__ . '/../config/db.php';   // $conn = 원격 공용 DB (세션 tz +09:00)
/** @var mysqli $conn */

$dry = in_array('--dry', $argv, true);
$t0  = microtime(true);

// 로컬 Laragon (수집기가 그동안 쓰던 곳)
$loc = @mysqli_connect('localhost', 'root', '', 'stock_db');
if (!$loc) { fwrite(STDERR, "로컬 DB 연결 실패: " . mysqli_connect_error() . "\n"); exit(1); }
mysqli_set_charset($loc, 'utf8mb4');
mysqli_query($loc, "SET time_zone = '+09:00'");

function one($cn, $sql) { $r = mysqli_query($cn, $sql); return $r ? mysqli_fetch_assoc($r) : null; }

echo ($dry ? "[DRY RUN] " : "") . "로컬 → 원격 시세 백필 시작\n";
echo str_repeat('-', 62) . "\n";

// =====================================================================
// 1) stock_logs
// -----------------------------------------------------------------
// ⚠️ id 를 그대로 복사하면 안 된다.
//    stock-ws 를 재시작해 수집이 원격으로 직접 들어가기 시작하면, 원격은 자기
//    auto_increment 로 새 id 를 쓴다. 그러면 로컬 id 와 번호가 겹치고,
//    INSERT IGNORE 는 그 행들을 "이미 있음"으로 조용히 버려서 데이터가 새어나간다.
//    → id 는 원격이 새로 매기게 하고, "어디까지 옮겼는지"는 워터마크로 따로 기억한다.
//
// 워터마크: 원격 app_settings 의 backfill_local_id (= 마지막으로 옮긴 로컬 id).
//    없으면 원격 MAX(id) 로 초기화한다 (지금은 id 가 정렬돼 있어 그 값이 정확).
// =====================================================================
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS app_settings (
    skey VARCHAR(50) PRIMARY KEY, svalue VARCHAR(255)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$wm = one($conn, "SELECT svalue v FROM app_settings WHERE skey = 'backfill_local_id'");
if ($wm && $wm['v'] !== null) {
    $watermark = (int) $wm['v'];
    $wmSrc = 'app_settings';
} else {
    $watermark = (int) (one($conn, "SELECT COALESCE(MAX(id), 0) mx FROM stock_logs")['mx']);
    $wmSrc = '원격 MAX(id) 로 초기화';
}

$gap = one($loc, "SELECT COUNT(*) c, MIN(id) mn, MAX(id) mx FROM stock_logs WHERE id > $watermark");
$need = (int) $gap['c'];
printf("stock_logs  워터마크 로컬 id=%s (%s) → 복사 대상 %s행 (id %s~%s)\n",
    number_format($watermark), $wmSrc, number_format($need), $gap['mn'] ?? '-', $gap['mx'] ?? '-');

if ($need > 0 && !$dry) {
    $remoteMaxId = $watermark;   // (아래 FK 검사에서 구간 선택에 사용)
    // FK(stock_code → stock_master) 위반 방지: 원격 마스터에 없는 코드는 건너뛴다
    $codesRes = mysqli_query($loc, "SELECT DISTINCT stock_code FROM stock_logs WHERE id > $remoteMaxId");
    $codes = [];
    while ($x = mysqli_fetch_row($codesRes)) $codes[] = $x[0];
    $inList = "'" . implode("','", array_map(function ($c) use ($conn) { return mysqli_real_escape_string($conn, $c); }, $codes)) . "'";
    $okRes = mysqli_query($conn, "SELECT stock_code FROM stock_master WHERE stock_code IN ($inList)");
    $known = [];
    while ($x = mysqli_fetch_row($okRes)) $known[$x[0]] = true;
    $skipCodes = array_diff($codes, array_keys($known));
    if ($skipCodes) echo "  ⚠️ 원격 stock_master 에 없어 건너뛰는 종목: " . implode(',', $skipCodes) . "\n";

    $BATCH = 1000;
    $copied = 0; $skipped = 0; $lastId = $watermark;

    // 워터마크는 배치마다 저장한다 → 중간에 끊겨도 이어서 돌릴 수 있고 중복도 안 생긴다
    $saveWm = mysqli_prepare($conn, "INSERT INTO app_settings (skey, svalue) VALUES ('backfill_local_id', ?)
        ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)");

    while (true) {
        $res = mysqli_query($loc, "
            SELECT id, stock_code, price, change_price, change_rate, volume, trade_value, created_at
            FROM stock_logs WHERE id > $lastId ORDER BY id ASC LIMIT $BATCH
        ");
        if (!$res || mysqli_num_rows($res) === 0) break;

        $vals = [];
        $batchLastId = $lastId;
        while ($r = mysqli_fetch_assoc($res)) {
            $batchLastId = (int) $r['id'];
            if (!isset($known[$r['stock_code']])) { $skipped++; continue; }
            // id 는 넣지 않는다 → 원격이 자기 번호를 매긴다 (재시작 후에도 충돌 없음)
            $vals[] = sprintf("('%s',%s,%s,%s,%s,%s,'%s')",
                mysqli_real_escape_string($conn, $r['stock_code']),
                (float) $r['price'], (float) $r['change_price'], (float) $r['change_rate'],
                (int) $r['volume'], (int) $r['trade_value'],
                mysqli_real_escape_string($conn, $r['created_at']));
        }
        if ($vals) {
            $sql = "INSERT INTO stock_logs
                    (stock_code, price, change_price, change_rate, volume, trade_value, created_at)
                    VALUES " . implode(',', $vals);
            if (!mysqli_query($conn, $sql)) {
                fwrite(STDERR, "  ❌ INSERT 실패(로컬 id~$batchLastId): " . mysqli_error($conn) . "\n");
                exit(1);
            }
            $copied += count($vals);
        }
        // 이 배치까지 확실히 반영됐으므로 워터마크 전진
        $lastId = $batchLastId;
        $wmStr = (string) $lastId;
        mysqli_stmt_bind_param($saveWm, "s", $wmStr);
        mysqli_stmt_execute($saveWm);

        if ($copied % 50000 < $BATCH) {
            printf("  ... %s행 (%.0f초)\n", number_format($copied), microtime(true) - $t0);
        }
    }
    printf("  ✅ %s행 복사, %s행 건너뜀 (워터마크 → 로컬 id %s)\n",
        number_format($copied), number_format($skipped), number_format($lastId));
}

// =====================================================================
// 2) stock_candles_1m — PK(stock_code, ts) 기준 upsert
// =====================================================================
$remoteMaxTs = one($conn, "SELECT COALESCE(MAX(ts), '2000-01-01') mx FROM stock_candles_1m")['mx'];
$cg = one($loc, "SELECT COUNT(*) c FROM stock_candles_1m WHERE ts > '$remoteMaxTs'");
printf("분봉        원격 MAX(ts)=%s → 복사 대상 %s행\n", $remoteMaxTs, number_format((int) $cg['c']));

if ((int) $cg['c'] > 0 && !$dry) {
    $res = mysqli_query($loc, "
        SELECT stock_code, ts, open_p, high_p, low_p, close_p, volume, vol_delta, trade_value, ticks
        FROM stock_candles_1m WHERE ts > '$remoteMaxTs' ORDER BY ts ASC
    ");
    $vals = []; $n = 0;
    $flush = function (&$vals) use ($conn, &$n) {
        if (!$vals) return;
        $sql = "INSERT INTO stock_candles_1m
                (stock_code, ts, open_p, high_p, low_p, close_p, volume, vol_delta, trade_value, ticks)
                VALUES " . implode(',', $vals) . "
                ON DUPLICATE KEY UPDATE
                    high_p = GREATEST(high_p, VALUES(high_p)),
                    low_p  = LEAST(low_p, VALUES(low_p)),
                    close_p = VALUES(close_p), volume = VALUES(volume),
                    vol_delta = VALUES(vol_delta), trade_value = VALUES(trade_value),
                    ticks = VALUES(ticks)";
        if (!mysqli_query($conn, $sql)) { fwrite(STDERR, "  ❌ 분봉 실패: " . mysqli_error($conn) . "\n"); exit(1); }
        $n += count($vals);
        $vals = [];
    };
    while ($r = mysqli_fetch_assoc($res)) {
        $vals[] = sprintf("('%s','%s',%s,%s,%s,%s,%s,%s,%s,%s)",
            mysqli_real_escape_string($conn, $r['stock_code']),
            mysqli_real_escape_string($conn, $r['ts']),
            (float) $r['open_p'], (float) $r['high_p'], (float) $r['low_p'], (float) $r['close_p'],
            (int) $r['volume'], (int) $r['vol_delta'], (int) $r['trade_value'], (int) $r['ticks']);
        if (count($vals) >= 500) $flush($vals);
    }
    $flush($vals);
    printf("  ✅ 분봉 %s행 반영\n", number_format($n));
}

// =====================================================================
// 3) stock_latest — 로컬이 더 최신인 종목만 upsert (홈 순위·현재가용)
// =====================================================================
if (!$dry) {
    $res = mysqli_query($loc, "
        SELECT stock_code, stock_name, price, change_price, change_rate, volume, trade_value, updated_at
        FROM stock_latest
    ");
    $n = 0;
    // ⚠️ 무조건 덮어쓰면 안 된다.
    //    stock-ws 를 재시작한 뒤엔 원격 stock_latest 가 실시간으로 갱신되는 쪽이고
    //    로컬은 멈춘 쪽이다. 그때 무조건 upsert 하면 최신 현재가를 옛 값으로 되돌린다.
    //    → 로컬 updated_at 이 원격보다 실제로 더 최신일 때만 갱신한다.
    //    (updated_at 을 마지막에 대입해야 앞의 비교들이 '갱신 전' 값을 본다)
    $stmt = mysqli_prepare($conn, "
        INSERT INTO stock_latest (stock_code, stock_name, price, change_price, change_rate, volume, trade_value, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            stock_name   = IF(VALUES(updated_at) > updated_at, VALUES(stock_name),   stock_name),
            price        = IF(VALUES(updated_at) > updated_at, VALUES(price),        price),
            change_price = IF(VALUES(updated_at) > updated_at, VALUES(change_price), change_price),
            change_rate  = IF(VALUES(updated_at) > updated_at, VALUES(change_rate),  change_rate),
            volume       = IF(VALUES(updated_at) > updated_at, VALUES(volume),       volume),
            trade_value  = IF(VALUES(updated_at) > updated_at, VALUES(trade_value),  trade_value),
            updated_at   = GREATEST(updated_at, VALUES(updated_at))
    ");
    while ($r = mysqli_fetch_assoc($res)) {
        // 타입: code s, name s, price d, change_price d, change_rate d(소수!), volume i, trade_value i, updated_at s
        mysqli_stmt_bind_param($stmt, "ssdddiis",
            $r['stock_code'], $r['stock_name'], $r['price'], $r['change_price'],
            $r['change_rate'], $r['volume'], $r['trade_value'], $r['updated_at']);
        if (mysqli_stmt_execute($stmt)) $n++;
    }
    printf("현재가      ✅ %s종목 갱신\n", number_format($n));
}

echo str_repeat('-', 62) . "\n";
printf("완료 (%.1f초)\n", microtime(true) - $t0);
