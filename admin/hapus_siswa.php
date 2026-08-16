<?php
require_once __DIR__ . '/_guard.php';

$id = $_GET['id'];
mysqli_query($koneksi, "DELETE FROM psb_pendaftar WHERE id='$id'");

header("Location: admin_data.php");
?>
