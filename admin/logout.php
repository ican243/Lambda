<?php
require_once 'func.php';
startAdminSession();

session_unset();
session_destroy();

header('Location: login.php');
exit;
