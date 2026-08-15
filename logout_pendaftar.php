<?php
session_start();
// Hapus khusus sesi portal pendaftar
unset($_SESSION['status_pendaftar']);
unset($_SESSION['id_pendaftar']);
unset($_SESSION['nama_pendaftar']);

// Arahkan kembali ke halaman login pendaftar
header("Location: login_pendaftar.php");
exit;
?>