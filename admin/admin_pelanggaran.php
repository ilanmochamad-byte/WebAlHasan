<?php
require_once __DIR__ . '/_guard.php';

if(isset($_POST['tambah'])){
    $id_santri = $_POST['id_santri'];
    $tgl = $_POST['tgl_pelanggaran'];
    $jenis = $_POST['jenis_pelanggaran'];
    $poin = $_POST['poin'];
    $hukuman = $_POST['hukuman'];
    mysqli_query($koneksi, "INSERT INTO pelanggaran (id_santri, tgl_pelanggaran, jenis_pelanggaran, poin, hukuman) VALUES ('$id_santri','$tgl','$jenis','$poin','$hukuman')");
    header("Location: admin_pelanggaran.php");
}
if(isset($_GET['hapus'])){ mysqli_query($koneksi, "DELETE FROM pelanggaran WHERE id='$_GET[hapus]'"); header("Location: admin_pelanggaran.php"); }
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <title>Data Pelanggaran</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css">
</head>
<body>
<div class="container-fluid">
    <div class="row">
        <?php include 'sidebar.php'; ?>
        <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4">
            <h2 class="h2 mb-4 text-danger">Data Pelanggaran</h2>
            <button type="button" class="btn btn-danger mb-3" data-bs-toggle="modal" data-bs-target="#modalTambah"><i class="fas fa-plus"></i> Catat Pelanggaran</button>
            
            <div class="card shadow border-0">
                <div class="card-body">
                    <table id="tabelData" class="table table-striped table-hover">
                        <thead><tr><th>No</th><th>Nama Santri</th><th>Tanggal</th><th>Pelanggaran</th><th>Poin</th><th>Aksi</th></tr></thead>
                        <tbody>
                            <?php
                            $no = 1;
                            // JOIN KE TABEL SANTRI
                            $query = "SELECT p.*, s.nama_santri FROM pelanggaran p JOIN santri s ON p.id_santri = s.id ORDER BY p.id DESC";
                            $data = mysqli_query($koneksi, $query);
                            while($d = mysqli_fetch_array($data)){
                            ?>
                            <tr>
                                <td><?php echo $no++; ?></td>
                                <td class="fw-bold"><?php echo $d['nama_santri']; ?></td>
                                <td><?php echo date('d/m/Y', strtotime($d['tgl_pelanggaran'])); ?></td>
                                <td><?php echo $d['jenis_pelanggaran']; ?></td>
                                <td><span class="badge bg-danger"><?php echo $d['poin']; ?></span></td>
                                <td><a href="admin_pelanggaran.php?hapus=<?php echo $d['id']; ?>" class="btn btn-danger btn-sm" onclick="return confirm('Hapus?')"><i class="fas fa-trash"></i></a></td>
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
            <div class="modal-header bg-danger text-white"><h5 class="modal-title">Catat Pelanggaran</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <form method="POST">
                <div class="modal-body">
                    <div class="mb-3">
                        <label>Pilih Santri</label>
                        <select name="id_santri" class="form-select" required>
                            <option value="">-- Cari Nama --</option>
                            <?php 
                            $qs = mysqli_query($koneksi, "SELECT * FROM santri ORDER BY nama_santri ASC");
                            while($s = mysqli_fetch_array($qs)){ echo "<option value='$s[id]'>$s[nis] - $s[nama_santri]</option>"; }
                            ?>
                        </select>
                    </div>
                    <div class="mb-3"><input type="date" name="tgl_pelanggaran" class="form-control" required></div>
                    <div class="mb-3"><input type="text" name="jenis_pelanggaran" class="form-control" placeholder="Jenis Pelanggaran" required></div>
                    <div class="row"><div class="col-4"><input type="number" name="poin" class="form-control" placeholder="Poin" required></div><div class="col-8"><input type="text" name="hukuman" class="form-control" placeholder="Sanksi"></div></div>
                </div>
                <div class="modal-footer"><button type="submit" name="tambah" class="btn btn-danger">Simpan</button></div>
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
