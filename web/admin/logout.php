<?php
require_once __DIR__ . '/../inc/bootstrap.php';
$_SESSION = [];
session_destroy();
header('Location: ' . route_admin('login'));
exit;
