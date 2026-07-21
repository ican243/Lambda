<?php
require_once 'func.php';
startUserSession();

session_unset();     // 세션에 저장된 값 전부 제거
session_destroy();   // 세션 자체를 파괴

header('Location: login.php');
exit;
