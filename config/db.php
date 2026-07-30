<?php
// config/db.php
// DB 연결 정보 (Laragon 기본값)

$host = 'lambda.cm-vw.com';
$dbname = 'lambda_stock_db';
$user = 'lambda';
$pass = 'dlawlsgnl99';

$conn = mysqli_connect($host, $user, $pass, $dbname);

if (!$conn) {
    die("DB 연결 실패: " . mysqli_connect_error());
}

mysqli_set_charset($conn, "utf8mb4");

// 원격 공용 DB는 서버 시계가 UTC인데, 우리 데이터·cron·분봉 로직은 전부 KST 기준이다.
// 세션 타임존을 KST로 고정해야 NOW()/CURRENT_TIMESTAMP 가 기존 행과 같은 시각을 쓴다.
// (안 맞추면 새로 들어가는 행이 9시간 뒤로 찍혀서 '오늘' 조회·정렬·집계가 전부 깨짐)
mysqli_query($conn, "SET time_zone = '+09:00'");
