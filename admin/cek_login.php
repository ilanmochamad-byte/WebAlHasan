<?php
session_start();

// Ambil data dari form
$username = $_POST['username'];
$password = $_POST['password'];

// Cek kredensial (Ganti password ini nanti jika sudah live)
if($username == "admin" && $password == "alhasan123"){
    // Buat session
    $_SESSION['status'] = "login";
    $_SESSION['admin'] = "Administrator";
    header("Location: admin_dashboard.php");
} else {
    // Login gagal
    header("Location: admin_login.php?pesan=gagal");
}
?>