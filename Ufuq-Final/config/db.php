<?php
require_once __DIR__ . '/../includes/env.php';

$host = env('DB_HOST', 'localhost');
$port = env('DB_PORT', '8889');
$name = env('DB_NAME', 'ufuq');
$user = env('DB_USER', 'root');
$pass = env('DB_PASSWORD', 'root');


$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];



$pdo = new PDO(
    "mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4",
    $user,
    $pass,
    $options
);