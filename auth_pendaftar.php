<?php
session_start();
include 'koneksi.php';

$nisn = mysqli_real_escape_string($koneksi, $_POST['nisn']);
$hp   = mysqli_real_escape_string($koneksi, $_POST['hp']);

$login = mysqli_query($koneksi, "SELECT * FROM psb_pendaftar WHERE nisn='$nisn' AND no_hp_wali='$hp'");
$cek = mysqli_num_rows($login);

if($cek > 0){
    $data = mysqli_fetch_assoc($login);
    // Menggunakan nama sesi yang baru
    $_SESSION['status_pendaftar'] = "login";
    $_SESSION['id_pendaftar']  = $data['id'];
    $_SESSION['nama_pendaftar'] = $data['nama_lengkap'];
    
    // Arahkan ke portal pendaftar
    header("Location: portal_pendaftar.php");
} else {
    // Jika gagal, kembalikan ke halaman login
    header("Location: login_pendaftar.php?pesan=gagal");
}
?>