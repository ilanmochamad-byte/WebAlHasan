<?php
session_start();
if(!isset($_SESSION['status']) || $_SESSION['status'] != "login"){ header("Location: admin_login.php"); exit; }
include '../koneksi.php';

// PROSES HAPUS ALUMNI PERMANEN
if(isset($_GET['hapus'])){
    $id = (int)$_GET['hapus'];
    $q = mysqli_query($koneksi, "SELECT foto FROM alumni WHERE id='$id'");
    $d = mysqli_fetch_array($q);
    if($d['foto'] != "default.jpg" && file_exists('../gambar_galeri/'.$d['foto'])){ unlink('../gambar_galeri/'.$d['foto']); }
    mysqli_query($koneksi, "DELETE FROM alumni WHERE id='$id'");
    echo "<script>window.location='admin_alumni.php';</script>";
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <title>Data Alumni & Mutasi - Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css">
    <style> .avatar-small { width: 40px; height: 40px; object-fit: cover; border-radius: 50%; border: 2px solid #dee2e6; } </style>
</head>
<body>
<div class="container-fluid">
    <div class="row">
        <?php include 'sidebar.php'; ?>

        <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4">
            <h2 class="h2 mb-4">Database Alumni & Mutasi</h2>

            <div class="card shadow-sm border-0 mb-4 bg-light">
                <div class="card-body">
                    <form method="GET" class="row g-2">
                        <div class="col-md-3">
                            <select name="tahun" class="form-select form-select-sm">
                                <option value="">Semua Tahun Angkatan</option>
                                <?php 
                                $qt = mysqli_query($koneksi, "SELECT DISTINCT tahun_angkatan FROM alumni ORDER BY tahun_angkatan DESC");
                                while($t = mysqli_fetch_array($qt)){
                                    $sel = (isset($_GET['tahun']) && $_GET['tahun']==$t['tahun_angkatan']) ? 'selected' : '';
                                    echo "<option value='".$t['tahun_angkatan']."' $sel>".$t['tahun_angkatan']."</option>";
                                }
                                ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <select name="tingkat" class="form-select form-select-sm">
                                <option value="">Semua Tingkat</option>
                                <option value="Ibtida" <?php if(isset($_GET['tingkat']) && $_GET['tingkat']=='Ibtida') echo 'selected'; ?>>Ibtida</option>
                                <option value="Tsanawi" <?php if(isset($_GET['tingkat']) && $_GET['tingkat']=='Tsanawi') echo 'selected'; ?>>Tsanawi</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <select name="status" class="form-select form-select-sm">
                                <option value="">Semua Status</option>
                                <option value="Lulus" <?php if(isset($_GET['status']) && $_GET['status']=='Lulus') echo 'selected'; ?>>Lulus Normal</option>
                                <option value="Pindah" <?php if(isset($_GET['status']) && $_GET['status']=='Pindah') echo 'selected'; ?>>Pindah</option>
                                <option value="Berhenti" <?php if(isset($_GET['status']) && $_GET['status']=='Berhenti') echo 'selected'; ?>>Berhenti</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <div class="input-group input-group-sm">
                                <input type="text" name="cari" class="form-control" placeholder="Cari Nama / NIS..." value="<?php echo isset($_GET['cari'])?$_GET['cari']:''; ?>">
                                <button class="btn btn-dark" type="submit"><i class="fas fa-search"></i> Tampilkan</button>
                            </div>
                        </div>
                        <div class="col-md-1"><a href="admin_alumni.php" class="btn btn-secondary btn-sm w-100"><i class="fas fa-sync"></i></a></div>
                    </form>
                </div>
            </div>

            <div class="card shadow border-0">
                <div class="card-body">
                    <div class="table-responsive">
                        <table id="tabelAlumni" class="table table-hover align-middle">
                            <thead class="table-dark small text-uppercase">
                                <tr>
                                    <th>Profil & Nama</th>
                                    <th>Status Keluar</th>
                                    <th>Tingkat</th>
                                    <th>Tahun Angkatan</th>
                                    <th class="text-center">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $where = "WHERE 1=1";
                                if(!empty($_GET['tahun'])) $where .= " AND tahun_angkatan = '$_GET[tahun]'";
                                if(!empty($_GET['tingkat'])) $where .= " AND tingkat = '$_GET[tingkat]'";
                                if(!empty($_GET['status'])) $where .= " AND status_keluar = '$_GET[status]'";
                                if(!empty($_GET['cari'])) $where .= " AND (nama_santri LIKE '%$_GET[cari]%' OR nis LIKE '%$_GET[cari]%')";

                                $query = "SELECT * FROM alumni $where ORDER BY tgl_keluar DESC";
                                $exec = mysqli_query($koneksi, $query);
                                
                                $data_alumni = []; // Array untuk menampung data Modal

                                // DIHAPUS: Logika if(mysqli_num_rows > 0) agar tidak ada colspan PHP yang membuat error DataTables
                                while($d = mysqli_fetch_array($exec)){
                                    $data_alumni[] = $d; // Simpan ke array
                                ?>
                                <tr>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <?php if($d['foto'] != "default.jpg" && file_exists('../gambar_galeri/'.$d['foto'])) { ?>
                                                <img src="../gambar_galeri/<?php echo $d['foto']; ?>" class="avatar-small me-3">
                                            <?php } else { ?>
                                                <div class="avatar-small bg-secondary text-white d-flex align-items-center justify-content-center fw-bold me-3">
                                                    <?php echo substr($d['nama_santri'],0,1); ?>
                                                </div>
                                            <?php } ?>
                                            <div>
                                                <div class="fw-bold text-dark"><?php echo $d['nama_santri']; ?></div>
                                                <small class="text-muted">NIS: <?php echo $d['nis']; ?></small>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <?php 
                                            if($d['status_keluar'] == 'Lulus') echo '<span class="badge bg-success">Lulus Normal</span>';
                                            else if($d['status_keluar'] == 'Pindah') echo '<span class="badge bg-warning text-dark">Pindah</span>';
                                            else echo '<span class="badge bg-danger">Berhenti</span>';
                                        ?><br>
                                        <small class="text-muted"><i class="far fa-calendar-alt me-1"></i> <?php echo date('d-m-Y', strtotime($d['tgl_keluar'])); ?></small>
                                    </td>
                                    <td class="fw-bold text-primary"><?php echo $d['tingkat']; ?></td>
                                    <td class="fw-bold fs-6"><?php echo $d['tahun_angkatan']; ?></td>
                                    <td class="text-center">
                                        <button class="btn btn-info btn-sm text-white" data-bs-toggle="modal" data-bs-target="#modalDetail<?php echo $d['id']; ?>"><i class="fas fa-eye"></i></button>
                                        <a href="admin_alumni.php?hapus=<?php echo $d['id']; ?>" class="btn btn-danger btn-sm" onclick="return confirm('Yakin hapus data alumni ini permanen?')"><i class="fas fa-trash"></i></a>
                                    </td>
                                </tr>
                                <?php } ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </main>
    </div>
</div>

<?php foreach($data_alumni as $d): ?>
<div class="modal fade" id="modalDetail<?php echo $d['id']; ?>" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title"><i class="fas fa-history me-2"></i> Rekam Jejak Alumni</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="text-center mb-3">
                    <h5 class="fw-bold mb-0"><?php echo $d['nama_santri']; ?></h5>
                    <span class="text-muted">Angkatan: <?php echo $d['tahun_angkatan']; ?> | Tingkat: <?php echo $d['tingkat']; ?></span>
                </div>
                <table class="table table-sm table-borderless">
                    <tr><td width="35%" class="text-muted">No. Induk (NIS)</td><td class="fw-bold">: <?php echo $d['nis']; ?></td></tr>
                    <tr><td class="text-muted">Unit Terakhir</td><td>: <?php echo $d['unit_terakhir']; ?></td></tr>
                    <tr><td class="text-muted">Status Keluar</td><td>: <?php echo $d['status_keluar']; ?></td></tr>
                    <tr><td class="text-muted">Tanggal Keluar</td><td>: <?php echo date('d F Y', strtotime($d['tgl_keluar'])); ?></td></tr>
                    <tr><td colspan="2"><hr class="my-1"></td></tr>
                    <tr><td class="text-muted">Alamat</td><td>: <?php echo $d['alamat'].', Ds. '.$d['desa'].', Kec. '.$d['kecamatan'].', '.$d['kab_kota']; ?></td></tr>
                    <tr><td class="text-muted">No HP Orang Tua</td><td>: <?php echo $d['no_hp_ayah'] ?: $d['no_hp_ibu']; ?></td></tr>
                </table>
            </div>
        </div>
    </div>
</div>
<?php endforeach; ?>

<script src="https://code.jquery.com/jquery-3.5.1.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>
<script> 
    $(document).ready(function () { 
        $('#tabelAlumni').DataTable({
            "language": {
                "emptyTable": "Tidak ada data alumni yang ditemukan."
            }
        }); 
    }); 
</script>
</body>
</html>