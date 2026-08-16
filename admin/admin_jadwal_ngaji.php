<?php
require_once __DIR__ . '/_guard.php';

// Ambil Tahun Ajaran Aktif
$q_tahun = mysqli_query($koneksi, "SELECT id, tahun, semester FROM tahun_ajaran WHERE status='Aktif' LIMIT 1");
$tahun_aktif = mysqli_fetch_array($q_tahun);
$id_tahun = $tahun_aktif ? $tahun_aktif['id'] : 0;

// ==========================================
// PROSES TAMBAH JADWAL (SUDAH DIPERBAIKI)
// ==========================================
if(isset($_POST['tambah'])){
    if($id_tahun == 0) {
        echo "<script>alert('Gagal: Tahun Ajaran Aktif belum diatur!'); window.location='admin_jadwal_ngaji.php';</script>"; exit;
    }
    // Ditambahkan mysqli_real_escape_string untuk menangani tanda petik pada kata "Ba'da"
    $waktu    = mysqli_real_escape_string($koneksi, $_POST['waktu_sholat']);
    $jam      = mysqli_real_escape_string($koneksi, $_POST['jam']);
    $id_kelas = (int)$_POST['id_kelas'];
    $fan      = mysqli_real_escape_string($koneksi, $_POST['fan_ilmu']);
    $kitab    = mysqli_real_escape_string($koneksi, $_POST['nama_kitab']);
    $id_guru  = (int)$_POST['id_guru'];
    $tempat   = mysqli_real_escape_string($koneksi, $_POST['tempat']);
    
    mysqli_query($koneksi, "INSERT INTO jadwal_ngaji (id_tahun, waktu_sholat, jam, id_kelas, fan_ilmu, nama_kitab, id_guru, tempat) 
                            VALUES ('$id_tahun', '$waktu', '$jam', '$id_kelas', '$fan', '$kitab', '$id_guru', '$tempat')");
    header("Location: admin_jadwal_ngaji.php");
    exit;
}

// ==========================================
// PROSES EDIT JADWAL (SUDAH DIPERBAIKI)
// ==========================================
if(isset($_POST['edit'])){
    $id       = (int)$_POST['id'];
    $waktu    = mysqli_real_escape_string($koneksi, $_POST['waktu_sholat']);
    $jam      = mysqli_real_escape_string($koneksi, $_POST['jam']);
    $id_kelas = (int)$_POST['id_kelas'];
    $fan      = mysqli_real_escape_string($koneksi, $_POST['fan_ilmu']);
    $kitab    = mysqli_real_escape_string($koneksi, $_POST['nama_kitab']);
    $id_guru  = (int)$_POST['id_guru'];
    $tempat   = mysqli_real_escape_string($koneksi, $_POST['tempat']);
    
    mysqli_query($koneksi, "UPDATE jadwal_ngaji SET waktu_sholat='$waktu', jam='$jam', id_kelas='$id_kelas', 
                            fan_ilmu='$fan', nama_kitab='$kitab', id_guru='$id_guru', tempat='$tempat' WHERE id='$id'");
    header("Location: admin_jadwal_ngaji.php");
    exit;
}

// ==========================================
// PROSES HAPUS JADWAL
// ==========================================
if(isset($_GET['hapus'])){
    $id = (int)$_GET['hapus'];
    mysqli_query($koneksi, "DELETE FROM jadwal_ngaji WHERE id='$id'");
    header("Location: admin_jadwal_ngaji.php");
    exit;
}

// Ambil Data Master untuk Dropdown
$data_guru = []; $qG = mysqli_query($koneksi, "SELECT id, nama_guru FROM guru ORDER BY nama_guru ASC"); while($g = mysqli_fetch_array($qG)){ $data_guru[] = $g; }
$data_kelas = []; $qK = mysqli_query($koneksi, "SELECT id, nama_kelas, jenjang FROM kelas ORDER BY jenjang, nama_kelas ASC"); while($k = mysqli_fetch_array($qK)){ $data_kelas[] = $k; }
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <title>Jadwal Pengajian - Admin</title>
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
                    <h1 class="h2 mb-0">Jadwal Pengajian Kelas</h1>
                    <span class="badge bg-success mt-1">Tahun Aktif: <?php echo $tahun_aktif ? $tahun_aktif['tahun'].' (' . $tahun_aktif['semester'] . ')' : 'Belum Diatur'; ?></span>
                </div>
                <button type="button" class="btn btn-primary fw-bold shadow-sm" data-bs-toggle="modal" data-bs-target="#modalTambah">
                    <i class="fas fa-plus me-1"></i> Tambah Jadwal
                </button>
            </div>

            <div class="card shadow border-0 rounded-4">
                <div class="card-body">
                    <table id="tabelData" class="table table-striped table-hover align-middle">
                        <thead class="table-dark">
                            <tr>
                                <th>Waktu & Jam</th>
                                <th>Kelas</th>
                                <th>Fan Ilmu & Kitab</th>
                                <th>Guru Pengajar</th>
                                <th>Tempat</th>
                                <th class="text-center">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $jadwal = [];
                            $query = "SELECT j.*, g.nama_guru, k.nama_kelas, k.jenjang 
                                      FROM jadwal_ngaji j 
                                      JOIN guru g ON j.id_guru = g.id 
                                      JOIN kelas k ON j.id_kelas = k.id 
                                      WHERE j.id_tahun = '$id_tahun'
                                      ORDER BY j.waktu_sholat ASC, k.nama_kelas ASC";
                            $data = mysqli_query($koneksi, $query);
                            while($d = mysqli_fetch_array($data)){
                                $jadwal[] = $d;
                            ?>
                            <tr>
                                <td>
                                    <span class="badge bg-primary mb-1"><?php echo $d['waktu_sholat']; ?></span><br>
                                    <small class="fw-bold text-muted"><i class="far fa-clock me-1"></i><?php echo $d['jam']; ?></small>
                                </td>
                                <td>
                                    <div class="fw-bold"><?php echo $d['nama_kelas']; ?></div>
                                    <small class="text-muted"><?php echo $d['jenjang']; ?></small>
                                </td>
                                <td>
                                    <div class="fw-bold text-success"><?php echo $d['fan_ilmu']; ?></div>
                                    <small class="fst-italic">Kitab: <?php echo $d['nama_kitab']; ?></small>
                                </td>
                                <td class="fw-bold"><?php echo $d['nama_guru']; ?></td>
                                <td><i class="fas fa-map-marker-alt text-danger me-1"></i> <?php echo $d['tempat']; ?></td>
                                <td class="text-center">
                                    <button class="btn btn-warning btn-sm" data-bs-toggle="modal" data-bs-target="#modalEdit<?php echo $d['id']; ?>"><i class="fas fa-edit"></i></button>
                                    <a href="admin_jadwal_ngaji.php?hapus=<?php echo $d['id']; ?>" class="btn btn-danger btn-sm" onclick="return confirm('Hapus jadwal ini?')"><i class="fas fa-trash"></i></a>
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
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="fas fa-calendar-plus me-2"></i>Tambah Jadwal Pengajian</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="fw-bold small">Waktu Pelaksanaan</label>
                            <select name="waktu_sholat" class="form-select" required>
                                <option value="Ba'da Shubuh">Ba'da Shubuh</option>
                                <option value="Ba'da Ashar">Ba'da Ashar</option>
                                <option value="Ba'da Magrib">Ba'da Magrib</option>
                                <option value="Ba'da Isya">Ba'da Isya</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="fw-bold small">Jam (Manual)</label>
                            <input type="text" name="jam" class="form-control" placeholder="Contoh: 05.00 - 06.00 WIB" required>
                        </div>
                        <div class="col-md-6">
                            <label class="fw-bold small">Pilih Kelas</label>
                            <select name="id_kelas" class="form-select" required>
                                <option value="">-- Pilih Kelas --</option>
                                <?php foreach($data_kelas as $k): ?>
                                    <option value="<?php echo $k['id']; ?>"><?php echo $k['nama_kelas'].' ('.$k['jenjang'].')'; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="fw-bold small">Guru Pengajar</label>
                            <select name="id_guru" class="form-select" required>
                                <option value="">-- Pilih Guru --</option>
                                <?php foreach($data_guru as $g): ?>
                                    <option value="<?php echo $g['id']; ?>"><?php echo $g['nama_guru']; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="fw-bold small">Fan Ilmu</label>
                            <input type="text" name="fan_ilmu" class="form-control" placeholder="Contoh: Fiqih / Tauhid / Nahwu" required>
                        </div>
                        <div class="col-md-6">
                            <label class="fw-bold small">Nama Kitab</label>
                            <input type="text" name="nama_kitab" class="form-control" placeholder="Contoh: Safinatun Najah" required>
                        </div>
                        <div class="col-md-12">
                            <label class="fw-bold small">Tempat Pelaksanaan</label>
                            <input type="text" name="tempat" class="form-control" placeholder="Contoh: Masjid Utama / Kelas X RPL" required>
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" name="tambah" class="btn btn-primary fw-bold">Simpan Jadwal</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php foreach($jadwal as $d): ?>
<div class="modal fade" id="modalEdit<?php echo $d['id']; ?>" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-warning">
                <h5 class="modal-title"><i class="fas fa-edit me-2"></i>Edit Jadwal Pengajian</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="id" value="<?php echo $d['id']; ?>">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="fw-bold small">Waktu Pelaksanaan</label>
                            <select name="waktu_sholat" class="form-select" required>
                                <option value="Ba'da Shubuh" <?php if($d['waktu_sholat']=="Ba'da Shubuh") echo 'selected'; ?>>Ba'da Shubuh</option>
                                <option value="Ba'da Ashar" <?php if($d['waktu_sholat']=="Ba'da Ashar") echo 'selected'; ?>>Ba'da Ashar</option>
                                <option value="Ba'da Magrib" <?php if($d['waktu_sholat']=="Ba'da Magrib") echo 'selected'; ?>>Ba'da Magrib</option>
                                <option value="Ba'da Isya" <?php if($d['waktu_sholat']=="Ba'da Isya") echo 'selected'; ?>>Ba'da Isya</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="fw-bold small">Jam (Manual)</label>
                            <input type="text" name="jam" class="form-control" value="<?php echo $d['jam']; ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="fw-bold small">Pilih Kelas</label>
                            <select name="id_kelas" class="form-select" required>
                                <?php foreach($data_kelas as $k): ?>
                                    <option value="<?php echo $k['id']; ?>" <?php if($d['id_kelas']==$k['id']) echo 'selected'; ?>><?php echo $k['nama_kelas'].' ('.$k['jenjang'].')'; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="fw-bold small">Guru Pengajar</label>
                            <select name="id_guru" class="form-select" required>
                                <?php foreach($data_guru as $g): ?>
                                    <option value="<?php echo $g['id']; ?>" <?php if($d['id_guru']==$g['id']) echo 'selected'; ?>><?php echo $g['nama_guru']; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="fw-bold small">Fan Ilmu</label>
                            <input type="text" name="fan_ilmu" class="form-control" value="<?php echo $d['fan_ilmu']; ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="fw-bold small">Nama Kitab</label>
                            <input type="text" name="nama_kitab" class="form-control" value="<?php echo $d['nama_kitab']; ?>" required>
                        </div>
                        <div class="col-md-12">
                            <label class="fw-bold small">Tempat Pelaksanaan</label>
                            <input type="text" name="tempat" class="form-control" value="<?php echo $d['tempat']; ?>" required>
                        </div>
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
<?php endforeach; ?>

<script src="https://code.jquery.com/jquery-3.5.1.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>
<script>$(document).ready(function () { $('#tabelData').DataTable(); });</script>
</body>
</html>
