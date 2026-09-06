<?php
require_once __DIR__ . '/_guard.php';

// --- 1. DATA SUMMARY CARDS ---
$jml_psb = mysqli_num_rows(mysqli_query($koneksi, "SELECT id FROM psb_pendaftar"));
$jml_santri = mysqli_num_rows(mysqli_query($koneksi, "SELECT id FROM santri")); 
$jml_guru = mysqli_num_rows(mysqli_query($koneksi, "SELECT id FROM guru"));
$jml_berita = mysqli_num_rows(mysqli_query($koneksi, "SELECT id FROM berita"));

// --- 2. DATA CHART JENJANG PSB ---
$smk = mysqli_num_rows(mysqli_query($koneksi, "SELECT id FROM psb_pendaftar WHERE jenjang_tujuan LIKE '%SMK Terpadu Al Hasan%'"));
$nonsmk = mysqli_num_rows(mysqli_query($koneksi, "SELECT id FROM psb_pendaftar WHERE jenjang_tujuan LIKE '%MAN%' or jenjang_tujuan LIKE '%SMAN%' or jenjang_tujuan LIKE '%SMKN%'"));
$smp = mysqli_num_rows(mysqli_query($koneksi, "SELECT id FROM psb_pendaftar WHERE jenjang_tujuan LIKE '%SMP Terpadu Al Hasan%'"));
$ra  = mysqli_num_rows(mysqli_query($koneksi, "SELECT id FROM psb_pendaftar WHERE jenjang_tujuan LIKE '%RA Terpadu Al Hasan%'"));

// --- 3. DATA CHART STATUS PSB ---
$psb_baru = mysqli_num_rows(mysqli_query($koneksi, "SELECT id FROM psb_pendaftar WHERE status='Baru'"));
$psb_terima = mysqli_num_rows(mysqli_query($koneksi, "SELECT id FROM psb_pendaftar WHERE status='Diterima' OR status='Dimigrasi'"));
$psb_cadangan = mysqli_num_rows(mysqli_query($koneksi, "SELECT id FROM psb_pendaftar WHERE status='Cadangan'"));
$psb_tolak = mysqli_num_rows(mysqli_query($koneksi, "SELECT id FROM psb_pendaftar WHERE status='Ditolak'"));

// --- 4. DATA CHART GENDER SANTRI (MASTER DATA) ---
$santri_l = mysqli_num_rows(mysqli_query($koneksi, "SELECT id FROM santri WHERE jenis_kelamin='L'"));
$santri_p = mysqli_num_rows(mysqli_query($koneksi, "SELECT id FROM santri WHERE jenis_kelamin='P'"));

// --- 5. DATA KAPASITAS KAMAR (LOGISTIK) ---
$q_kapasitas = mysqli_query($koneksi, "SELECT SUM(kapasitas) as total FROM kamar");
$tot_kapasitas = mysqli_fetch_assoc($q_kapasitas)['total'] ?? 0;

$q_isi = mysqli_query($koneksi, "SELECT COUNT(id) as terisi FROM plotting_kamar");
$tot_isi = mysqli_fetch_assoc($q_isi)['terisi'] ?? 0;

$persen_kamar = ($tot_kapasitas > 0) ? round(($tot_isi / $tot_kapasitas) * 100) : 0;
// Logika warna: Merah (>85%), Kuning (>60%), Hijau (Aman)
$warna_kamar = $persen_kamar > 85 ? 'bg-danger' : ($persen_kamar > 60 ? 'bg-warning' : 'bg-success');

// Ringkasan Fase 5 memakai query laporan read-only yang sama dengan halaman detail.
$reportFrom = (string) ($_GET['report_from'] ?? date('Y-m-01'));
$reportTo = (string) ($_GET['report_to'] ?? date('Y-m-d'));
$attendanceDashboard = null;
$attendanceDashboardError = null;
try {
    $attendanceDashboard = report_service()->dashboardSummary([
        'date_from' => $reportFrom,
        'date_to' => $reportTo,
    ], $currentUser);
} catch (\App\Api\ApiException $exception) {
    $attendanceDashboardError = $exception->getMessage();
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Dashboard Admin - Al Hasan</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body { background-color: #f8f9fa; }
        .card-custom { border: none; border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); }
        .icon-bg { width: 60px; height: 60px; border-radius: 12px; display: flex; align-items: center; justify-content: center; }
    </style>
</head>
<body>

<header class="navbar navbar-dark sticky-top bg-success flex-md-nowrap p-0 shadow">
    <a class="navbar-brand col-md-3 col-lg-2 me-0 px-3 fw-bold" href="#">PANEL ADMIN</a>
    <button class="navbar-toggler position-absolute d-md-none collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#sidebarMenu">
        <span class="navbar-toggler-icon"></span>
    </button>
    <div class="w-100"></div> 
    <div class="navbar-nav">
        <div class="nav-item text-nowrap">
            <a class="nav-link px-3 text-white" href="logout.php"><i class="fas fa-sign-out-alt me-1"></i> Keluar</a>
        </div>
    </div>
</header>

<div class="container-fluid">
    <div class="row">
        
        <?php include 'sidebar.php'; ?>

        <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4">
            <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pb-2 mb-4 border-bottom">
                <h1 class="h2 fw-bold text-dark">Dashboard Analitik</h1>
                <div class="btn-toolbar mb-2 mb-md-0">
                    <div class="btn-group me-2">
                        <a href="admin_data.php" class="btn btn-sm btn-outline-success"><i class="fas fa-user-check me-1"></i> Validasi PSB</a>
                        <a href="../index.php" target="_blank" class="btn btn-sm btn-outline-primary"><i class="fas fa-globe me-1"></i> Lihat Web Utama</a>
                    </div>
                </div>
            </div>

            <section class="card card-custom mb-4" aria-labelledby="ringkasan-absensi">
                <div class="card-header bg-white border-0 pt-4 d-flex flex-wrap justify-content-between align-items-center gap-3">
                    <div><h2 id="ringkasan-absensi" class="h5 fw-bold mb-1">Ringkasan Pertemuan & Absensi</h2><p class="text-muted small mb-0">Total berasal dari sumber laporan Fase 5 yang sama.</p></div>
                    <form method="get" class="d-flex flex-wrap gap-2 align-items-end">
                        <div><label class="form-label small mb-1" for="report_from">Dari</label><input class="form-control form-control-sm" id="report_from" name="report_from" type="date" value="<?= htmlspecialchars($reportFrom, ENT_QUOTES, 'UTF-8') ?>"></div>
                        <div><label class="form-label small mb-1" for="report_to">Sampai</label><input class="form-control form-control-sm" id="report_to" name="report_to" type="date" value="<?= htmlspecialchars($reportTo, ENT_QUOTES, 'UTF-8') ?>"></div>
                        <button class="btn btn-sm btn-success" type="submit">Tampilkan</button>
                        <a class="btn btn-sm btn-outline-success" href="admin_laporan_absensi.php?date_from=<?= urlencode($reportFrom) ?>&amp;date_to=<?= urlencode($reportTo) ?>">Buka laporan</a>
                    </form>
                </div>
                <div class="card-body">
                    <?php if ($attendanceDashboardError !== null): ?><div class="alert alert-danger mb-0"><?= htmlspecialchars($attendanceDashboardError, ENT_QUOTES, 'UTF-8') ?></div>
                    <?php elseif ($attendanceDashboard !== null): $attendanceSummary = $attendanceDashboard['summary']; ?>
                    <div class="row g-3">
                        <div class="col-6 col-lg"><div class="border rounded p-3 h-100"><div class="text-muted small">Pertemuan</div><div class="fs-3 fw-bold"><?= $attendanceSummary['meeting_count'] ?></div></div></div>
                        <?php foreach ($attendanceSummary['statuses'] as $attendanceStatus => $attendanceCount): ?><div class="col-6 col-lg"><div class="border rounded p-3 h-100"><div class="text-muted small"><?= htmlspecialchars($attendanceStatus, ENT_QUOTES, 'UTF-8') ?></div><div class="fs-3 fw-bold"><?= $attendanceCount ?></div></div></div><?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </section>

            <div class="row g-4 mb-4">
                <div class="col-xl-3 col-md-6">
                    <div class="card card-custom h-100">
                        <div class="card-body d-flex align-items-center">
                            <div class="icon-bg bg-primary bg-opacity-10 me-3">
                                <i class="fas fa-users fa-2x text-primary"></i>
                            </div>
                            <div>
                                <h6 class="text-muted mb-1">Total Pendaftar</h6>
                                <h3 class="fw-bold mb-0"><?php echo $jml_psb; ?></h3>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6">
                    <div class="card card-custom h-100">
                        <div class="card-body d-flex align-items-center">
                            <div class="icon-bg bg-success bg-opacity-10 me-3">
                                <i class="fas fa-user-graduate fa-2x text-success"></i>
                            </div>
                            <div>
                                <h6 class="text-muted mb-1">Santri Aktif</h6>
                                <h3 class="fw-bold mb-0"><?php echo $jml_santri; ?></h3>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6">
                    <div class="card card-custom h-100">
                        <div class="card-body d-flex align-items-center">
                            <div class="icon-bg bg-warning bg-opacity-10 me-3">
                                <i class="fas fa-chalkboard-teacher fa-2x text-warning"></i>
                            </div>
                            <div>
                                <h6 class="text-muted mb-1">Guru & Staff</h6>
                                <h3 class="fw-bold mb-0"><?php echo $jml_guru; ?></h3>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6">
                    <div class="card card-custom h-100">
                        <div class="card-body d-flex align-items-center">
                            <div class="icon-bg bg-info bg-opacity-10 me-3">
                                <i class="fas fa-newspaper fa-2x text-info"></i>
                            </div>
                            <div>
                                <h6 class="text-muted mb-1">Total Berita</h6>
                                <h3 class="fw-bold mb-0"><?php echo $jml_berita; ?></h3>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row g-4 mb-4">
                <div class="col-lg-8">
                    <div class="card card-custom h-100">
                        <div class="card-header bg-white border-0 pt-4 pb-0">
                            <h6 class="fw-bold text-secondary text-uppercase">Distribusi Pendaftar per Unit Sekolah</h6>
                        </div>
                        <div class="card-body p-4">
                            <canvas id="chartJenjang" style="max-height: 300px;"></canvas>
                        </div>
                    </div>
                </div>
                <div class="col-lg-4">
                    <div class="card card-custom h-100">
                        <div class="card-header bg-white border-0 pt-4 pb-0">
                            <h6 class="fw-bold text-secondary text-uppercase">Status Pendaftaran (Pipeline)</h6>
                        </div>
                        <div class="card-body p-4 d-flex justify-content-center">
                            <canvas id="chartStatus" style="max-height: 250px;"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row g-4">
                <div class="col-lg-4">
                    <div class="card card-custom h-100">
                        <div class="card-header bg-white border-0 pt-4 pb-0">
                            <h6 class="fw-bold text-secondary text-uppercase">Rasio Gender Santri Aktif</h6>
                        </div>
                        <div class="card-body p-4 d-flex justify-content-center">
                            <canvas id="chartGender" style="max-height: 250px;"></canvas>
                        </div>
                    </div>
                </div>
                
                <div class="col-lg-4">
                    <div class="card card-custom h-100">
                        <div class="card-header bg-white border-0 pt-4 pb-0">
                            <h6 class="fw-bold text-secondary text-uppercase">Logistik Kamar Asrama</h6>
                        </div>
                        <div class="card-body p-4">
                            <div class="text-center mb-4">
                                <h1 class="display-3 fw-bold text-dark mb-0"><?php echo $persen_kamar; ?>%</h1>
                                <span class="text-muted">Tingkat Keterisian Ranjang</span>
                            </div>
                            <div class="progress" style="height: 15px; border-radius: 10px;">
                                <div class="progress-bar <?php echo $warna_kamar; ?>" role="progressbar" style="width: <?php echo $persen_kamar; ?>%;" aria-valuenow="<?php echo $persen_kamar; ?>" aria-valuemin="0" aria-valuemax="100"></div>
                            </div>
                            <div class="d-flex justify-content-between mt-2 small text-muted fw-bold">
                                <span>Terisi: <?php echo $tot_isi; ?></span>
                                <span>Kapasitas: <?php echo $tot_kapasitas; ?></span>
                            </div>
                            
                            <?php if($persen_kamar > 85) { ?>
                                <div class="alert alert-danger mt-4 small py-2 mb-0">
                                    <i class="fas fa-exclamation-triangle me-1"></i> Peringatan: Kapasitas asrama hampir penuh! Pertimbangkan penambahan ranjang/kamar.
                                </div>
                            <?php } ?>
                        </div>
                    </div>
                </div>

                <div class="col-lg-4">
                    <div class="card card-custom h-100">
                        <div class="card-header bg-white border-0 pt-4 pb-2">
                            <h6 class="fw-bold text-secondary text-uppercase">Aksi Cepat</h6>
                        </div>
                        <div class="list-group list-group-flush border-top-0 px-3 pb-3">
                            <a href="tambah_berita.php" class="list-group-item list-group-item-action border-0 rounded my-1 bg-light"><i class="fas fa-pen-alt me-2 text-primary"></i> Publikasi Berita Baru</a>
                            <a href="admin_penempatan_santri.php" class="list-group-item list-group-item-action border-0 rounded my-1 bg-light"><i class="fas fa-bed me-2 text-success"></i> Penempatan Kelas &amp; Kamar</a>
                            <a href="admin_pelanggaran.php" class="list-group-item list-group-item-action border-0 rounded my-1 bg-light"><i class="fas fa-clipboard-list me-2 text-danger"></i> Catat Pelanggaran Santri</a>
                            <a href="admin_master_santri.php" class="list-group-item list-group-item-action border-0 rounded my-1 bg-light"><i class="fas fa-database me-2 text-warning"></i> Master Data Santri</a>
                        </div>
                    </div>
                </div>
            </div>

        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // 1. Bar Chart: Pendaftar Per Jenjang
    const ctxJenjang = document.getElementById('chartJenjang');
    new Chart(ctxJenjang, {
        type: 'bar',
        data: {
            labels: ['SMK Terpadu Al Hasan', 'Non SMK Terpadu Al Hasan', 'SMP Terpadu Al Hasan', 'RA Terpadu'],
            datasets: [{
                label: 'Jumlah Pendaftar',
                data: [<?php echo $smk; ?>, <?php echo $nonsmk; ?>, <?php echo $smp; ?>, <?php echo $ra; ?>],
                backgroundColor: ['#0d6efd', '#f7c10f', '#0dcaf0', '#ffc107'],
                borderRadius: 6
            }]
        },
        options: {
            plugins: { legend: { display: false } },
            scales: { y: { beginAtZero: true, grid: { borderDash: [5, 5] } }, x: { grid: { display: false } } }
        }
    });

    // 2. Doughnut Chart: Status Pipeline PSB
    const ctxStatus = document.getElementById('chartStatus');
    new Chart(ctxStatus, {
        type: 'doughnut',
        data: {
            labels: ['Baru', 'Diterima', 'Cadangan', 'Ditolak'],
            datasets: [{
                data: [<?php echo $psb_baru; ?>, <?php echo $psb_terima; ?>, <?php echo $psb_cadangan; ?>, <?php echo $psb_tolak; ?>],
                backgroundColor: ['#0d6efd', '#198754', '#ffc107', '#dc3545'],
                borderWidth: 2,
                hoverOffset: 4
            }]
        },
        options: {
            cutout: '70%',
            plugins: { legend: { position: 'bottom' } }
        }
    });

    // 3. Pie Chart: Rasio Gender Santri Aktif
    const ctxGender = document.getElementById('chartGender');
    new Chart(ctxGender, {
        type: 'pie',
        data: {
            labels: ['Laki-laki', 'Perempuan'],
            datasets: [{
                data: [<?php echo $santri_l; ?>, <?php echo $santri_p; ?>],
                backgroundColor: ['#3b82f6', '#ec4899'],
                borderWidth: 2
            }]
        },
        options: {
            plugins: { legend: { position: 'bottom' } }
        }
    });
</script>

</body>
</html>
