<?php require_once __DIR__ . '/_guard.php'; ?>
<nav id="sidebarMenu" class="col-md-3 col-lg-2 d-md-block bg-dark sidebar collapse" style="min-height: 100vh;">
    <div class="position-sticky pt-3">
        <div class="text-center mb-4 text-white">
            <div class="bg-white rounded-circle d-inline-flex align-items-center justify-content-center mb-2" style="width: 60px; height: 60px;">
                <i class="fas fa-mosque fa-2x text-success"></i>
            </div>
            <h6 class="fw-bold mb-0">ADMIN AL HASAN</h6>
            <small class="text-white-50">Sistem Terpadu</small>
        </div>

        <ul class="nav flex-column">
            <li class="nav-item">
                <a class="nav-link text-white <?php echo (basename($_SERVER['PHP_SELF']) == 'admin_dashboard.php') ? 'active bg-success' : ''; ?>" href="admin_dashboard.php">
                    <i class="fas fa-tachometer-alt me-2"></i> Dashboard
                </a>
            </li>

            <li class="nav-item mt-3 text-white-50 small fw-bold px-3">PSB ONLINE</li>
            <li class="nav-item">
                <a class="nav-link text-white <?php echo (basename($_SERVER['PHP_SELF']) == 'admin_data.php') ? 'active bg-success' : ''; ?>" href="admin_data.php">
                    <i class="fas fa-users me-2"></i> Data Pendaftar
                </a>
            </li>

            <li class="nav-item mt-3 text-white-50 small fw-bold px-3">MASTER DATA & AKADEMIK</li>
            <li class="nav-item"><a class="nav-link text-white <?php echo (basename($_SERVER['PHP_SELF']) == 'admin_master_santri.php') ? 'active bg-success' : ''; ?>" href="admin_master_santri.php"><i class="fa fa-address-book me-2"></i> Master Data Santri</a></li>
            <li class="nav-item"><a class="nav-link text-white <?php echo (basename($_SERVER['PHP_SELF']) == 'admin_wali.php') ? 'active bg-success' : ''; ?>" href="admin_wali.php"><i class="fas fa-people-roof me-2"></i> Orang Tua / Wali</a></li>
            <li class="nav-item"><a class="nav-link text-white" href="admin_santri.php"><i class="fa fa-address-card"></i> Data Santri</a></li>
            <li class="nav-item"><a class="nav-link text-white" href="admin_rekap_santri.php"><i class ="fa fa-list-alt"></i> Rekapitulasi Santri</a></li>
            <li class="nav-item"><a class="nav-link text-white <?php echo (basename($_SERVER['PHP_SELF']) == 'admin_tahun.php') ? 'active bg-success' : ''; ?>" href="admin_tahun.php"><i class="fas fa-calendar-alt me-2"></i> Tahun Ajaran</a></li>
            <li class="nav-item"><a class="nav-link text-white <?php echo (basename($_SERVER['PHP_SELF']) == 'admin_guru.php') ? 'active bg-success' : ''; ?>" href="admin_guru.php"><i class="fas fa-chalkboard-teacher me-2"></i> Guru & Pembimbing</a></li>
            <li class="nav-item"><a class="nav-link text-white <?php echo (basename($_SERVER['PHP_SELF']) == 'admin_pengurus.php') ? 'active bg-success' : ''; ?>" href="admin_pengurus.php"><i class="fas fa-user-tie me-2"></i> Pengurus</a></li>
            <li class="nav-item"><a class="nav-link text-white <?php echo (basename($_SERVER['PHP_SELF']) == 'admin_murobi.php') ? 'active bg-success' : ''; ?>" href="admin_murobi.php"><i class="fas fa-user-group me-2"></i> Penugasan Murobi</a></li>
            <li class="nav-item"><a class="nav-link text-white <?php echo (basename($_SERVER['PHP_SELF']) == 'admin_pembimbing.php') ? 'active bg-success' : ''; ?>" href="admin_pembimbing.php"><i class="fas fa-user-shield me-2"></i> Penugasan Pembimbing</a></li>
            <li class="nav-item"><a class="nav-link text-white <?php echo (basename($_SERVER['PHP_SELF']) == 'admin_akun.php') ? 'active bg-success' : ''; ?>" href="admin_akun.php"><i class="fas fa-user-shield me-2"></i> Akun & Hak Akses</a></li>
            <li class="nav-item"><a class="nav-link text-white <?php echo (basename($_SERVER['PHP_SELF']) == 'admin_akun_perizinan.php') ? 'active bg-success' : ''; ?>" href="admin_akun_perizinan.php"><i class="fas fa-id-card-clip me-2"></i> Akun Pengurus & Orang Tua</a></li>
            <li class="nav-item"><a class="nav-link text-white <?php echo (basename($_SERVER['PHP_SELF']) == 'ubah_password.php') ? 'active bg-success' : ''; ?>" href="ubah_password.php"><i class="fas fa-key me-2"></i> Ganti Password</a></li>
            <li class="nav-item"><a class="nav-link text-white <?php echo (basename($_SERVER['PHP_SELF']) == 'admin_kelas.php') ? 'active bg-success' : ''; ?>" href="admin_kelas.php"><i class="fas fa-school me-2"></i> Data Kelas</a></li>
            <li class="nav-item"><a class="nav-link text-white <?php echo (basename($_SERVER['PHP_SELF']) == 'admin_jadwal_ngaji.php') ? 'active bg-success' : ''; ?>" href="admin_jadwal_ngaji.php"><i class="fas fa-book-open me-2"></i> Jadwal Pengajian</a></li>
            <li class="nav-item"><a class="nav-link text-white <?php echo (basename($_SERVER['PHP_SELF']) == 'pertemuan_pengajian.php') ? 'active bg-success' : ''; ?>" href="pertemuan_pengajian.php"><i class="fas fa-calendar-check me-2"></i> Pertemuan Pengajian</a></li>
            <li class="nav-item"><a class="nav-link text-white <?php echo in_array(basename($_SERVER['PHP_SELF']), ['admin_laporan_absensi.php', 'laporan_absensi_detail.php'], true) ? 'active bg-success' : ''; ?>" href="admin_laporan_absensi.php"><i class="fas fa-chart-column me-2"></i> Laporan Absensi</a></li>
            <li class="nav-item"><a class="nav-link text-white" href="admin_kamar.php"><i class="fas fa-bed me-2"></i> Data Kamar</a></li>
            <li class="nav-item"><a class="nav-link text-white" href="<?php echo htmlspecialchars(app_url('/portal/izin.php'), ENT_QUOTES, 'UTF-8'); ?>"><i class="fas fa-file-shield me-2"></i> Perizinan V2 (Portal)</a></li>
            <li class="nav-item"><a class="nav-link text-white" href="<?php echo htmlspecialchars(app_url('/portal/izin_antrean.php'), ENT_QUOTES, 'UTF-8'); ?>"><i class="fas fa-inbox me-2"></i> Antrean Penetapan Admin</a></li>
            <li class="nav-item"><a class="nav-link text-white" href="admin_pelanggaran.php"><i class="fas fa-exclamation-triangle me-2"></i> Pelanggaran</a></li>

            <li class="nav-item mt-3 text-white-50 small fw-bold px-3">KEUANGAN</li>
            <li class="nav-item"><a class="nav-link text-white" href="admin_pembayaran_psb.php"><i class="fa-solid fa-rupiah-sign"></i> Pembayaran PSB</a></li>
            <li class="nav-item"><a class="nav-link text-white" href="admin_rekap_keuangan.php"><i class="fa-solid fa-area-chart"></i> Rekap Keuangan PSB</a></li>
            
            <li class="nav-item mt-3 text-white-50 small fw-bold px-3">DATA ALUMNI</li>
            <li class="nav-item"><a class="nav-link text-white" href="admin_alumni.php"><i class="fa fa-paper-plane"></i> Data Alumni</a></li>
            
            <li class="nav-item mt-3 text-white-50 small fw-bold px-3">KONTEN WEBSITE</li>
            <li class="nav-item">
                <a class="nav-link text-white <?php echo (basename($_SERVER['PHP_SELF']) == 'admin_berita.php') ? 'active bg-success' : ''; ?>" href="admin_berita.php">
                    <i class="fas fa-newspaper me-2"></i> Berita / Artikel
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link text-white <?php echo (basename($_SERVER['PHP_SELF']) == 'admin_galeri.php') ? 'active bg-success' : ''; ?>" href="admin_galeri.php">
                    <i class="fas fa-images me-2"></i> Galeri Foto
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link text-white <?php echo (basename($_SERVER['PHP_SELF']) == 'admin_download.php') ? 'active bg-success' : ''; ?>" href="admin_download.php">
                    <i class="fas fa-download me-2"></i> File Download
                </a>
            </li>

            <li class="nav-item mt-5 border-top pt-3">
                <a class="nav-link text-danger fw-bold" href="logout.php">
                    <i class="fas fa-sign-out-alt me-2"></i> Logout
                </a>
            </li>
        </ul>
    </div>
</nav>
<script>
    window.ALHASAN_CSRF = <?= json_encode(\App\Http\Csrf::token(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('form[method="post"], form[method="POST"]').forEach(function (form) {
            if (form.querySelector('input[name="_csrf"]')) return;
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = '_csrf';
            input.value = window.ALHASAN_CSRF;
            form.appendChild(input);
        });
        if (window.jQuery) {
            window.jQuery.ajaxSetup({headers: {'X-CSRF-Token': window.ALHASAN_CSRF}});
        }
    });
</script>
