<?php
// config/db.php
// DB 연결 정보 (Laragon 기본값)

$host = 'localhost';
$dbname = 'stock_db';
$user = 'root';
$pass = '';   // Laragon 기본 비밀번호는 보통 빈 값

$conn = mysqli_connect($host, $user, $pass, $dbname);

if (!$conn) {
    die("DB 연결 실패: " . mysqli_connect_error());
}

mysqli_set_charset($conn, "utf8mb4");
