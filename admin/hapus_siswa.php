<?php
require_once __DIR__ . '/_guard.php';
admin_require_post();

$id = (int) ($_POST['id'] ?? 0);
$stmt = mysqli_prepare($koneksi, "DELETE FROM psb_pendaftar WHERE id=?");
mysqli_stmt_bind_param($stmt, 'i', $id);
mysqli_stmt_execute($stmt);

header("Location: admin_data.php");
?>
