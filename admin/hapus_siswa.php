<?php
session_start();
include '../koneksi.php';

// Cek login dulu
if($_SESSION['status'] != "login"){
    header("Location: admin_login.php");
    exit;
}

$id = $_GET['id'];
mysqli_query($koneksi, "DELETE FROM psb_pendaftar WHERE id='$id'");

header("Location: admin_data.php");
?>