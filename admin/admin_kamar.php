<?php
require_once __DIR__ . '/_guard.php';

// A-11: hapus lewat GET tidak aman dan bertentangan dengan preservasi data.
if (isset($_GET['hapus'])) {
    http_response_code(405);
    exit('Penghapusan kamar tidak tersedia. Data dan riwayat kamar tetap dipertahankan.');
}
$service = master_data_service();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!isset($_POST['tambah']) && !isset($_POST['edit'])) {
            throw new App\MasterData\MasterDataException('Aksi kamar tidak dikenal.');
        }
        $id = isset($_POST['edit']) ? filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : null;
        if ($id === false) { throw new App\MasterData\MasterDataException('ID kamar tidak valid.'); }
        $service->saveRoom($_POST, $id);
        header('Location: admin_kamar.php');
        exit;
    } catch (App\MasterData\MasterDataException $exception) {
        http_response_code(422);
        exit(htmlspecialchars($exception->getMessage(), ENT_QUOTES, 'UTF-8'));
    }
}
$q_tahun = mysqli_query($koneksi, "SELECT id FROM tahun_ajaran WHERE status='Aktif' LIMIT 1");
$d_tahun = mysqli_fetch_assoc($q_tahun);
$id_tahun = $d_tahun ? (int) $d_tahun['id'] : 0;
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <title>Data Kamar - Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css">
</head>
<body class="bg-light">
<div class="container-fluid">
    <div class="row">
        <?php include 'sidebar.php'; ?>
        <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4">
            <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-4 border-bottom">
                <h1 class="h2 fw-bold text-success">Data Kamar Santri</h1>
                <button type="button" class="btn btn-success fw-bold shadow-sm" data-bs-toggle="modal" data-bs-target="#modalTambah">
                    <i class="fas fa-plus me-1"></i> Tambah Kamar
                </button>
            </div>
            
            <div class="card shadow border-0 rounded-4">
                <div class="card-body p-4">
                    <div class="table-responsive">
                        <table id="tabelData" class="table table-striped table-hover align-middle">
                            <thead class="table-dark">
                                <tr>
                                    <th width="5%">No</th>
                                    <th>Nama Kamar / Kobong</th>
                                    <th class="text-center">Kapasitas (Terisi / Maks)</th>
                                    <th class="text-center" width="20%">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $no = 1;
                                $data_kamar = [];
                                // Ambil data kamar beserta jumlah yang terisi saat ini
                                $data = mysqli_query($koneksi, "
                                    SELECT km.*, 
                                           (SELECT COUNT(id) FROM plotting_kamar pk WHERE pk.id_kamar = km.id AND pk.id_tahun = '$id_tahun') as terisi
                                    FROM kamar km 
                                    ORDER BY km.nama_kamar ASC
                                ");
                                while($d = mysqli_fetch_array($data)){
                                    $data_kamar[] = $d;
                                    
                                    // Tentukan warna badge berdasarkan kapasitas
                                    $is_full = ($d['terisi'] >= $d['kapasitas']);
                                    $badge_color = $is_full ? 'bg-danger' : 'bg-info text-dark';
                                ?>
                                <tr>
                                    <td class="fw-bold"><?php echo $no++; ?></td>
                                    <td class="fw-bold text-primary fs-6"><?php echo $d['nama_kamar']; ?></td>
                                    <td class="text-center">
                                        <span class="badge <?php echo $badge_color; ?> px-3 py-2 fs-6 rounded-pill">
                                            <?php echo $d['terisi']; ?> / <?php echo $d['kapasitas']; ?>
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <button class="btn btn-primary btn-sm fw-bold shadow-sm text-white" data-bs-toggle="modal" data-bs-target="#modalPenghuni<?php echo $d['id']; ?>" title="Lihat Daftar Penghuni">
                                            <i class="fas fa-users"></i>
                                        </button>
                                        <button class="btn btn-warning btn-sm fw-bold shadow-sm" data-bs-toggle="modal" data-bs-target="#modalEdit<?php echo $d['id']; ?>" title="Edit Kamar">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <a href="admin_kamar.php?hapus=<?php echo $d['id']; ?>" class="btn btn-danger btn-sm fw-bold shadow-sm" onclick="return confirm('Yakin ingin menghapus kamar ini?')" title="Hapus Kamar">
                                            <i class="fas fa-trash"></i>
                                        </a>
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

<div class="modal fade" id="modalTambah" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title fw-bold"><i class="fas fa-plus-circle me-2"></i>Tambah Kamar Asrama</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="fw-bold mb-1">Nama Kamar / Kobong</label>
                        <input type="text" name="nama_kamar" class="form-control form-control-lg" placeholder="Contoh: Al-Ghazali 01" required>
                    </div>
                    <div class="mb-3">
                        <label class="fw-bold mb-1">Kapasitas (Orang)</label>
                        <input type="number" name="kapasitas" class="form-control form-control-lg" placeholder="Masukkan batas maksimal..." required>
                    </div>
                </div>
                <div class="modal-footer bg-light border-0">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" name="tambah" class="btn btn-success fw-bold px-4">Simpan Data</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php foreach($data_kamar as $d): ?>
<div class="modal fade" id="modalEdit<?php echo $d['id']; ?>" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-warning">
                <h5 class="modal-title fw-bold"><i class="fas fa-edit me-2"></i>Edit Data Kamar</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body p-4">
                    <input type="hidden" name="id" value="<?php echo $d['id']; ?>">
                    <div class="mb-3">
                        <label class="fw-bold mb-1">Nama Kamar / Kobong</label>
                        <input type="text" name="nama_kamar" class="form-control form-control-lg" value="<?php echo $d['nama_kamar']; ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="fw-bold mb-1">Kapasitas (Orang)</label>
                        <input type="number" name="kapasitas" class="form-control form-control-lg" value="<?php echo $d['kapasitas']; ?>" required>
                    </div>
                </div>
                <div class="modal-footer bg-light border-0">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" name="edit" class="btn btn-warning fw-bold px-4 text-dark">Simpan Perubahan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="modalPenghuni<?php echo $d['id']; ?>" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title fw-bold"><i class="fas fa-users me-2"></i>Daftar Penghuni Kamar: <?php echo $d['nama_kamar']; ?></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <div class="alert alert-info border-info d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <strong>Kapasitas Ruangan:</strong><br>
                        <?php echo $d['terisi']; ?> Terisi / <?php echo $d['kapasitas']; ?> Maksimal
                    </div>
                    <?php if($d['terisi'] >= $d['kapasitas']): ?>
                        <span class="badge bg-danger fs-6 px-3 py-2 rounded-pill"><i class="fas fa-ban me-1"></i> KAMAR PENUH</span>
                    <?php else: ?>
                        <span class="badge bg-success fs-6 px-3 py-2 rounded-pill"><i class="fas fa-check me-1"></i> TERSEDIA</span>
                    <?php endif; ?>
                </div>

                <div class="table-responsive">
                    <table class="table table-bordered table-striped table-hover align-middle">
                        <thead class="table-light text-center">
                            <tr>
                                <th width="5%">No</th>
                                <th width="15%">NIS</th>
                                <th>Nama Santri</th>
                                <th width="10%">L/P</th>
                                <th>Unit Sekolah</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $id_kmr = $d['id'];
                            // Ambil data santri yang menempati kamar ini di tahun ajaran aktif
                            $q_penghuni = mysqli_query($koneksi, "
                                SELECT s.nis, s.nama_santri, s.jenis_kelamin, s.sekolah_saat_ini
                                FROM plotting_kamar pk
                                JOIN santri s ON pk.id_santri = s.id
                                WHERE pk.id_kamar = '$id_kmr' AND pk.id_tahun = '$id_tahun'
                                ORDER BY s.nama_santri ASC
                            ");
                            
                            if(mysqli_num_rows($q_penghuni) > 0){
                                $no_p = 1;
                                while($p = mysqli_fetch_assoc($q_penghuni)){
                                    echo "<tr>";
                                    echo "<td class='text-center'>".$no_p++."</td>";
                                    echo "<td class='text-center'>".$p['nis']."</td>";
                                    echo "<td class='fw-bold text-dark'>".$p['nama_santri']."</td>";
                                    echo "<td class='text-center'>".$p['jenis_kelamin']."</td>";
                                    echo "<td class='text-center'><span class='badge bg-secondary'>".$p['sekolah_saat_ini']."</span></td>";
                                    echo "</tr>";
                                }
                            } else {
                                echo "<tr><td colspan='5' class='text-muted fst-italic text-center py-4'><i class='fas fa-bed fa-2x mb-2 d-block text-secondary opacity-50'></i>Kamar ini masih kosong. Belum ada santri yang ditempatkan.</td></tr>";
                            }
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer bg-light border-0">
                <button type="button" class="btn btn-secondary fw-bold px-4" data-bs-dismiss="modal">Tutup</button>
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
        $('#tabelData').DataTable({
            "language": { "search": "Pencarian Cepat:" }
        }); 
    });
</script>
</body>
</html>
