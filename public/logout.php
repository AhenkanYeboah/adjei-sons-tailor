<?php
require_once __DIR__ . '/../config/bootstrap.php';

$_SESSION = [];
session_destroy();

header('Location: ' . BASE_URL . '/index.php');
exit;
