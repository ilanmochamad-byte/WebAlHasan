<?php
// koneksi.php
$host = "localhost";
$user = "k1807225_userwebalhasan"; // User database Anda
$pass = "Alhasan120!@#";            // Password database Anda
$db   = "k1807225_webalhasan";      // Nama database Anda

$koneksi = mysqli_connect($host, $user, $pass, $db);

if (!$koneksi) {
    die("Koneksi Gagal: " . mysqli_connect_error());
}
?>