<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$host = getenv('DB_HOST') ?: "localhost";
$user = "u894129685_adonisvm"; // Your Hostinger DB User
$pass = "Xcel007!"; // Your Hostinger DB Password
$db   = "u894129685_vmsportal";   // Your Hostinger DB Name

$dsn = "mysql:host=$host;dbname=$db;";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    die("Koneksi gagal: " . $e->getMessage());
}

$mail = 'teofilus.franky@kawanlamacorp.com';
$name = 'Teofilus Franky';
$role = 'vendormgt_head';
$password_asli = '045824.FRANKY'; // Password yang ingin Anda gunakan

// TAHAP REGISTRASI HASHING
$password_hashed = password_hash($password_asli, PASSWORD_DEFAULT);

$sql = "INSERT INTO users (email, full_name, role, password_hash) VALUES (?, ?, ?, ?)";
$stmt = $pdo->prepare($sql);
$stmt->execute([$mail, $name, $role, $password_hashed]);

echo "User berhasil diregistrasikan dengan password ter-hash!";
?>