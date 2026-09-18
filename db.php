<?php
$host = 'localhost';
$db   = 'clothes-store1';
$user = 'root';
$pass = '';

try {
    // Use TCP explicitly on XAMPP. It avoids localhost resolving differently
    // between PHP/MySQL installations and makes connection failures clearer.
    $pdo = new PDO(
        "mysql:host=127.0.0.1;port=3306;dbname=$db;charset=utf8mb4",
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => 5,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4",
        ]
    );
} catch (\PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}
?>