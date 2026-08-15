<?php
session_start();
if($_SESSION['status'] != "login"){ header("Location: admin_login.php"); exit; }
include '../koneksi.php';

// TAMBAH IZIN
if(isset($_POST['tambah'])){
    $id_santri = $_POST['id_santri'];
    $tgl_izin = $_POST['tgl_izin'];
    $tgl_kembali = $_POST['tgl_kembali'];
    $alasan = $_POST['alasan'];
    mysqli_query($koneksi, "INSERT INTO perizinan (id_santri, tgl_izin, tgl_kembali, alasan, status) VALUES ('$id_santri','$tgl_izin','$tgl_kembali','$alasan','Pending')");
    header("Location: admin_izin.php");
}
// UPDATE STATUS & HAPUS (Sama seperti kode sebelumnya)
if(isset($_GET['status'])){ mysqli_query($koneksi, "UPDATE perizinan SET status='$_GET[status]' WHERE id='$_GET[id]'"); header("Location: admin_izin.php"); }
if(isset($_GET['hapus'])){ mysqli_query($koneksi, "DELETE FROM perizinan WHERE id='$_GET[hapus]'"); header("Location: admin_izin.php"); }
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <title>Data Perizinan</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css">
</head>
<body>
<div class="container-fluid">
    <div class="row">
        <?php include 'sidebar.php'; ?>
        <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4">
            <h2 class="h2 mb-4">Data Izin Santri</h2>
            <button type="button" class="btn btn-primary mb-3" data-bs-toggle="modal" data-bs-target="#modalTambah"><i class="fas fa-plus"></i> Buat Izin</button>
            
            <div class="card shadow border-0">
                <div class="card-body">
                    <table id="tabelData" class="table table-striped table-hover">
                        <thead><tr><th>No</th><th>Nama Santri</th><th>Tgl Izin</th><th>Alasan</th><th>Status</th><th>Aksi</th></tr></thead>
                        <tbody>
                            <?php
                            $no = 1;
                            // JOIN KE TABEL SANTRI (BUKAN PSB)
                            $query = "SELECT p.*, s.nama_santri FROM perizinan p JOIN santri s ON p.id_santri = s.id ORDER BY p.id DESC";
                            $data = mysqli_query($koneksi, $query);
                            while($d = mysqli_fetch_array($data)){
                            ?>
                            <tr>
                                <td><?php echo $no++; ?></td>
                                <td class="fw-bold"><?php echo $d['nama_santri']; ?></td>
                                <td><?php echo date('d/m/Y', strtotime($d['tgl_izin'])); ?> - <?php echo date('d/m/Y', strtotime($d['tgl_kembali'])); ?></td>
                                <td><?php echo $d['alasan']; ?></td>
                                <td><?php echo $d['status']; ?></td>
                                <td>
                                    <?php if($d['status']=='Pending'){ ?><a href="admin_izin.php?id=<?php echo $d['id']; ?>&status=Disetujui" class="btn btn-success btn-sm"><i class="fas fa-check"></i></a><?php } ?>
                                    <a href="admin_izin.php?hapus=<?php echo $d['id']; ?>" class="btn btn-danger btn-sm" onclick="return confirm('Hapus?')"><i class="fas fa-trash"></i></a>
                                </td>
                            </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </main>
    </div>
</div>

<div class="modal fade" id="modalTambah" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header"><h5 class="modal-title">Form Izin</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <form method="POST">
                <div class="modal-body">
                    <div class="mb-3">
                        <label>Pilih Santri</label>
                        <select name="id_santri" class="form-select select2" required>
                            <option value="">-- Cari Nama --</option>
                            <?php 
                            $qs = mysqli_query($koneksi, "SELECT * FROM santri ORDER BY nama_santri ASC");
                            while($s = mysqli_fetch_array($qs)){ echo "<option value='$s[id]'>$s[nis] - $s[nama_santri]</option>"; }
                            ?>
                        </select>
                    </div>
                    <div class="row"><div class="col-6"><input type="date" name="tgl_izin" class="form-control" required></div><div class="col-6"><input type="date" name="tgl_kembali" class="form-control" required></div></div>
                    <div class="mt-3"><textarea name="alasan" class="form-control" placeholder="Alasan" required></textarea></div>
                </div>
                <div class="modal-footer"><button type="submit" name="tambah" class="btn btn-primary">Simpan</button></div>
            </form>
        </div>
    </div>
</div>
<script src="https://code.jquery.com/jquery-3.5.1.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>
<script>$(document).ready(function () { $('#tabelData').DataTable(); });</script>
</body>
</html>