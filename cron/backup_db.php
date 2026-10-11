<?php
// 터미널(서버 시계 cron)에서만 실행 — 인터넷 주소로 열면 "없는 페이지"로 끝낸다
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
// =============================================================
// DB 매일 백업
// =============================================================
// 회원·주문·2년치 시세가 든 DB 전체를 파일 하나(.sql.gz)로 저장한다.
// 서버나 DB가 망가져도 이 파일 하나로 그날 새벽 상태로 되돌릴 수 있다.
//
// 저장 위치: /var/www/backups/lambda_db_YYYYMMDD_HHMMSS.sql.gz
//   - 웹 폴더 바깥이라 인터넷에서는 열 수 없고, 권한 600(서버 관리자만 읽기)
//   - 7일 지난 백업은 자동으로 지운다 (디스크가 꽉 차지 않게)
//
// 비밀번호는 명령어에 직접 쓰지 않는다:
//   명령어에 쓰면 서버의 "실행 중인 프로그램 목록"에 잠깐 보일 수 있어서,
//   나만 읽을 수 있는 임시 설정 파일에 적어 mysqldump 에게 넘기고 끝나면 바로 지운다.
//
// 되돌리는 법(복원):
//   gunzip < /var/www/backups/lambda_db_날짜.sql.gz | mysql -u root lambda_stock_db
//
// 사용법:  php cron/backup_db.php            (root crontab 에서 매일 새벽 실행)
// -------------------------------------------------------------
require __DIR__ . '/../config/db_config.php';   // $host, $dbname, $user, $pass

const BACKUP_DIR  = '/var/www/backups';
const KEEP_DAYS   = 7;

umask(077);   // 이 스크립트가 만드는 파일은 전부 "나만 읽기"
$start = microtime(true);
$out   = BACKUP_DIR . '/lambda_db_' . date('Ymd_His') . '.sql.gz';

// 1. 비밀번호를 담은 임시 설정 파일
$cnf = tempnam(sys_get_temp_dir(), 'lambda_dump_');
file_put_contents($cnf, "[client]\nuser=\"{$user}\"\npassword=\"" . addcslashes($pass, "\"\\") . "\"\nhost=\"{$host}\"\n");

// 2. 덤프 → 압축 → 파일
//   --single-transaction : 사이트를 멈추지 않고 "한 순간"의 모습을 그대로 저장
//   --no-tablespaces     : 일반 계정 권한으로도 백업되게 (테이블 공간 정보는 필요 없음)
//   pipefail             : mysqldump 가 실패하면 gzip 이 성공해도 실패로 판단
$cmd ='mysqldump --defaults-extra-file=' . escapeshellarg($cnf)
     . ' --single-transaction --quick --routines --triggers --no-tablespaces --default-character-set=utf8mb4 '
     . escapeshellarg($dbname) . ' | gzip -c > ' . escapeshellarg($out);
exec('bash -o pipefail -c ' . escapeshellarg($cmd) . ' 2>&1', $lines, $code);
@unlink($cnf);

// 3. 결과 확인 — 실패했거나 너무 작으면(빈 파일) 지우고 실패로 기록
$size = is_file($out) ? filesize($out) : 0;
if ($code !== 0 || $size < 1024) {
    @unlink($out);
    echo date('Y-m-d H:i:s') . " - ❌ 백업 실패 (code {$code}): " . implode(' / ', $lines) . "\n";
    exit(1);
}

// 4. 오래된 백업 정리 (이 스크립트가 만든 lambda_db_* 만)
$removed = 0;
foreach (glob(BACKUP_DIR . '/lambda_db_*.sql.gz') ?: [] as $f) {
    if (filemtime($f) < time() - KEEP_DAYS * 86400) { unlink($f); $removed++; }
}

printf("%s - ✅ 백업 완료: %s (%.1fMB, %.1f초) · 지난 백업 %d개 정리\n",
    date('Y-m-d H:i:s'), basename($out), $size / 1048576, microtime(true) - $start, $removed);
