<?php
// config/db.php
// DB 연결
// 접속 정보($host, $dbname, $user, $pass)는 db_config.php 에 따로 둔다 (Git 제외).
// 비밀번호가 Git 에 올라가지 않게 하기 위함. 없으면 db_config.example.php 를 복사해서 만든다.

require_once __DIR__ . '/db_config.php';

$conn = mysqli_connect($host, $user, $pass, $dbname);

if (!$conn) {
    die("DB 연결 실패: " . mysqli_connect_error());
}

mysqli_set_charset($conn, "utf8mb4");

// 원격 공용 DB는 서버 시계가 UTC인데, 우리 데이터·cron·분봉 로직은 전부 KST 기준이다.
// 세션 타임존을 KST로 고정해야 NOW()/CURRENT_TIMESTAMP 가 기존 행과 같은 시각을 쓴다.
// (안 맞추면 새로 들어가는 행이 9시간 뒤로 찍혀서 '오늘' 조회·정렬·집계가 전부 깨짐)
mysqli_query($conn, "SET time_zone = '+09:00'");
