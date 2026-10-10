<?php
// 없는 주소로 들어왔을 때 보여주는 화면.
// nginx 설정(error_page 404 /user/404.php)이 Lambda 구역의 '없는 파일' 요청을 전부 여기로 보낸다.
require_once 'func.php';
/** @var mysqli $conn */   // config/db.php 에서 넘어옴 (에디터 자동완성·오탐 방지용)
startUserSession();
http_response_code(404);

$pageTitle = '페이지를 찾을 수 없어요';
include 'includes/header.php';
?>

<div class="nf">
    <div class="code">404</div>
    <h1>페이지를 찾을 수 없어요</h1>
    <p>주소가 바뀌었거나 없는 페이지예요. 홈에서 다시 찾아보세요.</p>
    <div class="btns">
        <a href="index.php" class="primary">홈으로 가기</a>
        <a href="javascript:history.back()">이전 화면</a>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
