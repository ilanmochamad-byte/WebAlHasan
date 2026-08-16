<?php
require_once __DIR__ . '/_guard.php';

// Ambil Tahun Ajaran Aktif untuk menampilkan data plotting
$q_tahun = mysqli_query($koneksi, "SELECT id, tahun, semester FROM tahun_ajaran WHERE status='Aktif' LIMIT 1");
$tahun_aktif = mysqli_fetch_array($q_tahun);
$id_tahun = $tahun_aktif ? $tahun_aktif['id'] : 0;

// PROSES TAMBAH KELAS
if(isset($_POST['tambah'])){
    $nama = mysqli_real_escape_string($koneksi, $_POST['nama_kelas']);
    $jenjang = mysqli_real_escape_string($koneksi, $_POST['jenjang']);
    mysqli_query($koneksi, "INSERT INTO kelas (nama_kelas, jenjang) VALUES ('$nama','$jenjang')");
    header("Location: admin_kelas.php");
}

// PROSES EDIT KELAS
if(isset($_POST['edit'])){
    $id = $_POST['id'];
    $nama = mysqli_real_escape_string($koneksi, $_POST['nama_kelas']);
    $jenjang = mysqli_real_escape_string($koneksi, $_POST['jenjang']);
    mysqli_query($koneksi, "UPDATE kelas SET nama_kelas='$nama', jenjang='$jenjang' WHERE id='$id'");
    header("Location: admin_kelas.php");
}

// PROSES HAPUS
if(isset($_GET['hapus'])){
    $id = $_GET['hapus'];
    mysqli_query($koneksi, "DELETE FROM kelas WHERE id='$id'");
    header("Location: admin_kelas.php");
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <title>Data Kelas - Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css">
</head>
<body>
<div class="container-fluid">
    <div class="row">
        <?php include 'sidebar.php'; ?>
        
        <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4">
            <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                <div>
                    <h1 class="h2 mb-0">Data Kelas</h1>
                    <span class="badge bg-success mt-1">Tahun Aktif: <?php echo $tahun_aktif ? $tahun_aktif['tahun'].' ('.$tahun_aktif['semester'].')' : 'Belum Diatur'; ?></span>
                </div>
                <button type="button" class="btn btn-primary fw-bold shadow-sm" data-bs-toggle="modal" data-bs-target="#modalTambah">
                    <i class="fas fa-plus me-1"></i> Tambah Kelas
                </button>
            </div>

            <div class="card shadow border-0 rounded-4">
                <div class="card-body">
                    <table id="tabelData" class="table table-striped table-hover align-middle">
                        <thead class="table-dark">
                            <tr>
                                <th width="5%">No</th>
                                <th>Nama Kelas</th>
                                <th>Jenjang</th>
                                <th class="text-center" width="20%">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $no = 1;
                            $data_kelas = [];
                            $data = mysqli_query($koneksi, "SELECT * FROM kelas ORDER BY jenjang ASC, nama_kelas ASC");
                            while($d = mysqli_fetch_array($data)){
                                $data_kelas[] = $d;
                            ?>
                            <tr>
                                <td><?php echo $no++; ?></td>
                                <td class="fw-bold text-success fs-6"><?php echo $d['nama_kelas']; ?></td>
                                <td><span class="badge bg-light text-dark border"><?php echo $d['jenjang']; ?></span></td>
                                <td class="text-center">
                                    <div class="btn-group btn-group-sm">
                                        <button class="btn btn-info text-white" data-bs-toggle="modal" data-bs-target="#modalSantri<?php echo $d['id']; ?>" title="Lihat Daftar Santri">
                                            <i class="fas fa-users"></i> Lihat Anggota
                                        </button>
                                        <button class="btn btn-warning" data-bs-toggle="modal" data-bs-target="#modalEdit<?php echo $d['id']; ?>" title="Edit Kelas">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <a href="admin_kelas.php?hapus=<?php echo $d['id']; ?>" class="btn btn-danger" onclick="return confirm('Hapus kelas ini permanen?')" title="Hapus Kelas">
                                            <i class="fas fa-trash"></i>
                                        </a>
                                    </div>
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
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="fas fa-plus-circle me-2"></i>Tambah Kelas Baru</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="fw-bold small">Nama Kelas</label>
                        <input type="text" name="nama_kelas" class="form-control" placeholder="Contoh: X RPL 1" required>
                    </div>
                    <div class="mb-3">
                        <label class="fw-bold small">Jenjang</label>
                        <select name="jenjang" class="form-select" required>
                            <option value="RA/TK/Sederajat">RA/TK/Sederajat</option>
                            <option value="SD/MI/Sederajat">SD/MI/Sederajat</option>
                            <option value="SMP/MTs/Sederajat">SMP/MTs/Sederajat</option>
                            <option value="SMA/SMK/MA/Sederajat">SMA/SMK/MA/Sederajat</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" name="tambah" class="btn btn-primary fw-bold">Simpan Kelas</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php foreach($data_kelas as $d): ?>
<div class="modal fade" id="modalEdit<?php echo $d['id']; ?>" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-warning">
                <h5 class="modal-title"><i class="fas fa-edit me-2"></i>Edit Data Kelas</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="id" value="<?php echo $d['id']; ?>">
                    <div class="mb-3">
                        <label class="fw-bold small">Nama Kelas</label>
                        <input type="text" name="nama_kelas" class="form-control" value="<?php echo $d['nama_kelas']; ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="fw-bold small">Jenjang</label>
                        <select name="jenjang" class="form-select" required>
                            <option value="RA/TK/Sederajat" <?php if($d['jenjang'] == 'RA/TK/Sederajat') echo 'selected'; ?>>RA/TK/Sederajat</option>
                            <option value="SD/MI/Sederajat" <?php if($d['jenjang'] == 'SD/MI/Sederajat') echo 'selected'; ?>>SD/MI/Sederajat</option>
                            <option value="SMP/MTs/Sederajat" <?php if($d['jenjang'] == 'SMP/MTs/Sederajat') echo 'selected'; ?>>SMP/MTs/Sederajat</option>
                            <option value="SMA/SMK/MA/Sederajat" <?php if($d['jenjang'] == 'SMA/SMK/MA/Sederajat') echo 'selected'; ?>>SMA/SMK/MA/Sederajat</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" name="edit" class="btn btn-warning fw-bold">Simpan Perubahan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="modalSantri<?php echo $d['id']; ?>" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title"><i class="fas fa-users me-2"></i>Daftar Santri: Kelas <?php echo $d['nama_kelas']; ?></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <?php if($id_tahun == 0): ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-triangle me-2"></i> Tahun Ajaran Aktif belum diatur. Silakan atur di menu Tahun Ajaran terlebih dahulu.
                    </div>
                <?php else: ?>
                    <div class="table-responsive border rounded">
                        <table class="table table-hover table-striped table-sm mb-0">
                            <thead class="table-light text-center">
                                <tr>
                                    <th width="5%">No</th>
                                    <th width="15%">NIS</th>
                                    <th class="text-start">Nama Santri</th>
                                    <th width="10%">L/P</th>
                                    <th>Unit Sekolah</th> </tr>
                            </thead>
                            <tbody>
                                <?php
                                $id_kelas_ini = $d['id'];
                                // PERBAIKAN: Menambahkan kolom s.sekolah_saat_ini pada query SELECT
                                $q_santri = mysqli_query($koneksi, "
                                    SELECT s.nis, s.nama_santri, s.jenis_kelamin, s.sekolah_saat_ini 
                                    FROM plotting_kelas pk 
                                    JOIN santri s ON pk.id_santri = s.id 
                                    WHERE pk.id_kelas = '$id_kelas_ini' AND pk.id_tahun = '$id_tahun' 
                                    ORDER BY s.nama_santri ASC
                                ");
                                
                                if(mysqli_num_rows($q_santri) > 0){
                                    $no_s = 1;
                                    while($s = mysqli_fetch_array($q_santri)){
                                ?>
                                <tr>
                                    <td class="text-center"><?php echo $no_s++; ?></td>
                                    <td class="text-center"><span class="badge bg-secondary"><?php echo $s['nis']; ?></span></td>
                                    <td class="fw-bold"><?php echo $s['nama_santri']; ?></td>
                                    <td class="text-center"><?php echo ($s['jenis_kelamin']=='L') ? 'Laki-laki' : 'Perempuan'; ?></td>
                                    <td class="text-center"> <span class="badge bg-primary opacity-75"><?php echo $s['sekolah_saat_ini'] ?: '-'; ?></span>
                                    </td>
                                </tr>
                                <?php 
                                    }
                                } else {
                                    echo "<tr><td colspan='5' class='text-center py-4 text-muted fst-italic'>Belum ada santri yang diploting ke kelas ini pada tahun ajaran aktif.</td></tr>";
                                }
                                ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
            <div class="modal-footer bg-light">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
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
        $('#tabelData').DataTable();
    });
</script>
</body>
</html>
