<?php
require_once __DIR__ . '/_guard.php';

// --- TAMBAH TAHUN AJARAN ---
if(isset($_POST['tambah'])){
    $tahun = $_POST['tahun'];
    $semester = $_POST['semester'];
    // Default status non-aktif saat ditambah
    mysqli_query($koneksi, "INSERT INTO tahun_ajaran (tahun, semester, status) VALUES ('$tahun', '$semester', 'Non-Aktif')");
    header("Location: admin_tahun.php");
}

// --- AKTIFKAN TAHUN AJARAN (LOGIKA PENTING) ---
if(isset($_GET['aktifkan'])){
    $id = $_GET['aktifkan'];
    
    // 1. Matikan SEMUA tahun ajaran dulu
    mysqli_query($koneksi, "UPDATE tahun_ajaran SET status='Non-Aktif'");
    
    // 2. Aktifkan HANYA yang dipilih
    mysqli_query($koneksi, "UPDATE tahun_ajaran SET status='Aktif' WHERE id='$id'");
    
    header("Location: admin_tahun.php");
}

// --- HAPUS ---
if(isset($_GET['hapus'])){
    mysqli_query($koneksi, "DELETE FROM tahun_ajaran WHERE id='$_GET[hapus]'");
    header("Location: admin_tahun.php");
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <title>Pengaturan Tahun Ajaran</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css">
</head>
<body>

<header class="navbar navbar-dark sticky-top bg-success flex-md-nowrap p-0 shadow d-md-none">
    <a class="navbar-brand col-md-3 col-lg-2 me-0 px-3" href="#">Menu Admin</a>
    <button class="navbar-toggler position-absolute d-md-none collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#sidebarMenu">
        <span class="navbar-toggler-icon"></span>
    </button>
</header>

<div class="container-fluid">
    <div class="row">
        <?php include 'sidebar.php'; ?>

        <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4">
            <h2 class="h2 mb-4">Pengaturan Tahun Ajaran</h2>

            <div class="row">
                <div class="col-md-4 mb-4">
                    <div class="card shadow border-0">
                        <div class="card-header bg-primary text-white">
                            <h6 class="mb-0"><i class="fas fa-plus-circle me-2"></i>Tambah Tahun Baru</h6>
                        </div>
                        <div class="card-body">
                            <form method="POST">
                                <div class="mb-3">
                                    <label>Tahun Ajaran</label>
                                    <input type="text" name="tahun" class="form-control" placeholder="Contoh: 2025/2026" required>
                                </div>
                                <div class="mb-3">
                                    <label>Semester</label>
                                    <select name="semester" class="form-select">
                                        <option value="Ganjil">Ganjil</option>
                                        <option value="Genap">Genap</option>
                                    </select>
                                </div>
                                <button type="submit" name="tambah" class="btn btn-primary w-100">Simpan</button>
                            </form>
                        </div>
                    </div>
                </div>

                <div class="col-md-8">
                    <div class="card shadow border-0">
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-hover align-middle">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Tahun</th>
                                            <th>Semester</th>
                                            <th>Status</th>
                                            <th>Aksi</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        $q = mysqli_query($koneksi, "SELECT * FROM tahun_ajaran ORDER BY tahun DESC, semester DESC");
                                        while($d = mysqli_fetch_array($q)){
                                        ?>
                                        <tr class="<?php echo ($d['status']=='Aktif') ? 'table-success border-start border-5 border-success' : ''; ?>">
                                            <td class="fw-bold"><?php echo $d['tahun']; ?></td>
                                            <td><?php echo $d['semester']; ?></td>
                                            <td>
                                                <?php if($d['status']=='Aktif'){ ?>
                                                    <span class="badge bg-success"><i class="fas fa-check-circle me-1"></i> AKTIF</span>
                                                <?php } else { ?>
                                                    <span class="badge bg-secondary">Non-Aktif</span>
                                                <?php } ?>
                                            </td>
                                            <td>
                                                <?php if($d['status']!='Aktif'){ ?>
                                                    <a href="admin_tahun.php?aktifkan=<?php echo $d['id']; ?>" class="btn btn-outline-success btn-sm" title="Aktifkan ini"><i class="fas fa-power-off"></i></a>
                                                    <a href="admin_tahun.php?hapus=<?php echo $d['id']; ?>" class="btn btn-outline-danger btn-sm" onclick="return confirm('Hapus?')" title="Hapus"><i class="fas fa-trash"></i></a>
                                                <?php } else { ?>
                                                    <span class="text-muted small fst-italic">Sedang Berjalan</span>
                                                <?php } ?>
                                            </td>
                                        </tr>
                                        <?php } ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
