<?php
session_start();
if(!isset($_SESSION['status_pendaftar']) || $_SESSION['status_pendaftar'] != "login"){
    header("Location: login_pendaftar.php");
    exit;
}
include 'koneksi.php';
include 'header.php';

$id_user = $_SESSION['id_pendaftar'];

// ==========================================
// PROSES UPDATE DATA DIRI
// ==========================================
if(isset($_POST['update_data'])){
    $nama         = mysqli_real_escape_string($koneksi, $_POST['nama_lengkap']);
    $nik          = mysqli_real_escape_string($koneksi, $_POST['nik']);
    $tempat_lahir = mysqli_real_escape_string($koneksi, $_POST['tempat_lahir']);
    $tgl_lahir    = mysqli_real_escape_string($koneksi, $_POST['tgl_lahir']);
    $jk           = mysqli_real_escape_string($koneksi, $_POST['jenis_kelamin']);
    $jenjang      = mysqli_real_escape_string($koneksi, $_POST['jenjang_tujuan']);
    $alamat_jalan = mysqli_real_escape_string($koneksi, $_POST['alamat_jalan']);
    $ayah         = mysqli_real_escape_string($koneksi, $_POST['nama_ayah']);
    $ibu          = mysqli_real_escape_string($koneksi, $_POST['nama_ibu']);
    $hp           = mysqli_real_escape_string($koneksi, $_POST['no_hp_wali']);
    $sekolah      = mysqli_real_escape_string($koneksi, $_POST['sekolah_asal']);

    $update_wilayah = "";
    if(!empty($_POST['nama_provinsi'])){
        $prov = mysqli_real_escape_string($koneksi, $_POST['nama_provinsi']);
        $kab  = mysqli_real_escape_string($koneksi, $_POST['nama_kabupaten']);
        $kec  = mysqli_real_escape_string($koneksi, $_POST['nama_kecamatan']);
        $desa = mysqli_real_escape_string($koneksi, $_POST['nama_desa']);
        $update_wilayah = ", provinsi='$prov', kab_kota='$kab', kecamatan='$kec', desa='$desa'";
    }

    $q_update = "UPDATE psb_pendaftar SET 
                 nama_lengkap='$nama', nik='$nik', tempat_lahir='$tempat_lahir', 
                 tgl_lahir='$tgl_lahir', jenis_kelamin='$jk', jenjang_tujuan='$jenjang',
                 alamat_jalan='$alamat_jalan', nama_ayah='$ayah', nama_ibu='$ibu', 
                 no_hp_wali='$hp', sekolah_asal='$sekolah' $update_wilayah 
                 WHERE id='$id_user'";
                 
    if(mysqli_query($koneksi, $q_update)){
        $_SESSION['nama_pendaftar'] = $nama; 
        echo "<script>alert('Data Diri berhasil diperbarui!'); window.location='portal_pendaftar.php';</script>";
    } else {
        echo "<script>alert('Terjadi kesalahan saat menyimpan data.');</script>";
    }
}

$query = mysqli_query($koneksi, "SELECT * FROM psb_pendaftar WHERE id='$id_user'");
$d     = mysqli_fetch_array($query);

// ==========================================
// LOGIKA PROGRESS BAR (VISUAL ALUR)
// ==========================================
// Cek berkas wajib (KTP ortu, KK, Akta)
$berkas_lengkap = (!empty($d['file_ktp']) && !empty($d['file_kk']) && !empty($d['file_akta']));
$status_psb = $d['status']; // 'Baru', 'Diterima', 'Cadangan', 'Ditolak'

$step1 = "completed"; // Step 1: Selalu selesai
$step2 = $berkas_lengkap ? "completed" : "active";

$step3 = "";
if($status_psb == 'Diterima' || $status_psb == 'Ditolak' || $status_psb == 'Cadangan'){
    $step3 = "completed";
} elseif ($berkas_lengkap && $status_psb == 'Baru') {
    $step3 = "active";
}

$step4 = "";
if($status_psb == 'Diterima'){
    $step4 = "completed";
} elseif ($status_psb == 'Ditolak'){
    $step4 = "failed";
} elseif ($status_psb == 'Cadangan'){
    $step4 = "active"; 
}

$step5 = "";
if($status_psb == 'Diterima'){
    $step5 = "active";
}
?>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
<style>
    /* CSS Kustom Untuk Tab Modern */
    .custom-tabs { background-color: #f1f3f5; border-radius: 50px; padding: 6px; margin-bottom: 25px; }
    .custom-tabs .nav-link { border-radius: 50px; color: #6c757d; font-weight: 600; padding: 12px 25px; border: none; transition: all 0.3s ease; }
    .custom-tabs .nav-link:hover:not(.active) { background-color: #e9ecef; color: #495057; }
    .custom-tabs .nav-link.active { background-color: #198754; color: #ffffff !important; box-shadow: 0 4px 12px rgba(25, 135, 84, 0.3); }
    
    .upload-card { border: 2px dashed #ced4da; border-radius: 15px; padding: 25px 15px; text-align: center; background-color: #ffffff; transition: all 0.3s ease; height: 100%; display: flex; flex-direction: column; justify-content: space-between; }
    .upload-card:hover { border-color: #198754; background-color: #f8fff9; }
    .upload-icon { font-size: 2.5rem; color: #adb5bd; margin-bottom: 15px; transition: color 0.3s; }
    .upload-card:hover .upload-icon { color: #198754; }
    
    .section-title { font-size: 0.85rem; text-transform: uppercase; letter-spacing: 1.5px; color: #198754; font-weight: 700; margin-top: 1.5rem; margin-bottom: 1rem; border-bottom: 2px solid #e9ecef; padding-bottom: 0.5rem; }

    /* CSS PROGRESS BAR (STEPPER) */
    .stepper-wrapper { display: flex; justify-content: space-between; align-items: flex-start; position: relative; margin-bottom: 20px; padding: 0 10px; }
    .stepper-item { flex: 1; text-align: center; position: relative; z-index: 1; display: flex; flex-direction: column; align-items: center; }
    
    /* Garis Penghubung Kiri & Kanan */
    .stepper-item::before, .stepper-item::after { content: ''; position: absolute; top: 18px; width: 100%; height: 4px; background: #dee2e6; z-index: -1; }
    .stepper-item::before { left: -50%; }
    .stepper-item::after { left: 50%; }
    .stepper-item:first-child::before { display: none; }
    .stepper-item:last-child::after { display: none; }

    /* Status Selesai (Hijau) */
    .stepper-item.completed::before, .stepper-item.completed::after { background: #198754; }
    /* Status Aktif / Sedang Berjalan (Hijau di Kiri, Abu di Kanan) */
    .stepper-item.active::before { background: #198754; }
    .stepper-item.active::after { background: #dee2e6; }
    
    /* Bulatan Angka */
    .step-counter { width: 40px; height: 40px; border-radius: 50%; background: #dee2e6; color: white; display: flex; align-items: center; justify-content: center; font-weight: bold; font-size: 1rem; margin-bottom: 8px; transition: 0.3s; }
    .stepper-item.completed .step-counter { background: #198754; }
    .stepper-item.active .step-counter { background: #ffc107; color: #000; box-shadow: 0 0 0 5px rgba(255,193,7,0.3); }
    .stepper-item.failed .step-counter { background: #dc3545; box-shadow: 0 0 0 5px rgba(220,53,69,0.3); }
    
    /* Teks Label */
    .step-name { font-size: 0.75rem; font-weight: 700; color: #adb5bd; line-height: 1.2; }
    .stepper-item.completed .step-name { color: #198754; }
    .stepper-item.active .step-name { color: #b38600; }
    .stepper-item.failed .step-name { color: #dc3545; }
    
    @media (max-width: 768px) { .step-name { font-size: 0.65rem; } .step-counter { width: 30px; height: 30px; font-size: 0.85rem; top: 5px; } .stepper-item::before, .stepper-item::after { top: 13px; } }
</style>

<div class="container py-5 mt-5">
    <div class="row">
        <div class="col-lg-4 mb-4">
            <div class="card border-0 shadow-sm rounded-4 sticky-top" style="top: 100px; z-index: 1;">
                <div class="card-body p-4 text-center">
                    <div class="bg-success bg-opacity-10 rounded-circle d-inline-flex p-4 mb-3">
                        <i class="fas fa-user-graduate fa-3x text-success"></i>
                    </div>
                    <h5 class="fw-bold mb-1"><?php echo $d['nama_lengkap']; ?></h5>
                    <span class="badge bg-light text-success border border-success mb-3 px-3 py-2 rounded-pill"><?php echo $d['no_pendaftaran']; ?></span>
                    <hr class="text-muted">
                    <div class="text-start small">
                        <div class="d-flex justify-content-between mb-2">
                            <span class="text-muted">Status Akun</span>
                            <span class="fw-bold text-primary"><i class="fas fa-check-circle"></i> Terdaftar</span>
                        </div>
                        <div class="d-flex justify-content-between mb-2">
                            <span class="text-muted">Jenjang Pilihan</span>
                            <span class="fw-bold text-dark"><?php echo $d['jenjang_tujuan']; ?></span>
                        </div>
                    </div>
                    
                    <a href="cetak_bukti.php?noreg=<?php echo $d['no_pendaftaran']; ?>" target="_blank" class="btn btn-warning w-100 mt-4 fw-bold shadow-sm rounded-pill py-2">
                        <i class="fas fa-print me-2"></i> Cetak Bukti Pendaftaran
                    </a>
                    
                    <a href="biaya_psb.php?cari=<?php echo $d['no_pendaftaran']; ?>" class="btn btn-primary w-100 mt-3 fw-bold shadow-sm rounded-pill py-2">
                        <i class="fas fa-file-invoice-dollar me-2"></i> Rincian & Pembayaran
                    </a>

                    <a href="logout_pendaftar.php" class="btn btn-outline-danger w-100 mt-3 fw-bold rounded-pill py-2">
                        <i class="fas fa-sign-out-alt me-2"></i> Keluar Portal
                    </a>
                </div>
            </div>
        </div>

        <div class="col-lg-8">
            
            <div class="card border-0 shadow-sm rounded-4 mb-4">
                <div class="card-body p-4 text-center">
                    <h6 class="fw-bold text-success mb-4"><i class="fas fa-route me-2"></i>Status & Tahapan Pendaftaran</h6>
                    
                    <div class="stepper-wrapper">
                        <div class="stepper-item <?php echo $step1; ?>">
                            <div class="step-counter"><i class="fas fa-pen"></i></div>
                            <div class="step-name">Isi Data</div>
                        </div>
                        <div class="stepper-item <?php echo $step2; ?>">
                            <div class="step-counter"><i class="fas fa-folder-open"></i></div>
                            <div class="step-name">Upload<br>Berkas</div>
                        </div>
                        <div class="stepper-item <?php echo $step3; ?>">
                            <div class="step-counter"><i class="fas fa-search"></i></div>
                            <div class="step-name">Proses<br>Verifikasi</div>
                        </div>
                        <div class="stepper-item <?php echo $step4; ?>">
                            <div class="step-counter">
                                <?php echo ($step4 == 'failed') ? '<i class="fas fa-times"></i>' : '<i class="fas fa-clipboard-check"></i>'; ?>
                            </div>
                            <div class="step-name">Hasil<br>Seleksi</div>
                        </div>
                        <div class="stepper-item <?php echo $step5; ?>">
                            <div class="step-counter"><i class="fas fa-handshake"></i></div>
                            <div class="step-name">Daftar<br>Ulang</div>
                        </div>
                    </div>
                    
                    <?php if($status_psb == 'Cadangan'){ ?>
                        <div class="alert alert-warning mt-3 mb-0 small"><strong>Info:</strong> Status Anda saat ini <b>Cadangan</b>. Harap menunggu informasi lebih lanjut.</div>
                    <?php } elseif($status_psb == 'Ditolak'){ ?>
                        <div class="alert alert-danger mt-3 mb-0 small"><strong>Info:</strong> Mohon maaf, Anda dinyatakan <b>Tidak Lulus Seleksi</b>.</div>
                    <?php } elseif($status_psb == 'Diterima'){ ?>
                        <div class="alert alert-success mt-3 mb-0 small"><strong>Selamat!</strong> Anda dinyatakan <b>Diterima</b>. Silakan ikuti instruksi Daftar Ulang di pesantren.</div>
                    <?php } elseif($status_psb == 'Baru' && !$berkas_lengkap){ ?>
                        <div class="alert alert-info mt-3 mb-0 small"><strong>Tindakan:</strong> Silakan lengkapi dokumen di tab <b>Upload Berkas</b> agar data Anda bisa diverifikasi panitia.</div>
                    <?php } else { ?>
                        <div class="alert alert-primary mt-3 mb-0 small"><i class="fas fa-spinner fa-spin me-1"></i> Data dan berkas Anda sedang <b>diperiksa oleh panitia</b>. Harap bersabar.</div>
                    <?php } ?>
                </div>
            </div>

            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-body p-4 p-md-5">
                    
                    <ul class="nav nav-pills nav-fill custom-tabs" id="portalTabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="data-tab" data-bs-toggle="tab" data-bs-target="#tab-data" type="button" role="tab">
                                <i class="fas fa-user-edit me-2"></i> Biodata Pendaftar
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="berkas-tab" data-bs-toggle="tab" data-bs-target="#tab-berkas" type="button" role="tab">
                                <i class="fas fa-folder-open me-2"></i> Dokumen & Berkas
                            </button>
                        </li>
                    </ul>

                    <div class="tab-content" id="portalTabsContent">
                        
                        <div class="tab-pane fade show active" id="tab-data" role="tabpanel">
                            
                            <form action="" method="POST">
                                <div class="section-title"><i class="fas fa-id-card me-2"></i>Identitas Diri</div>
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label small fw-bold text-muted">NISN (Username Login)</label>
                                        <input type="text" class="form-control bg-light text-muted" value="<?php echo $d['nisn']; ?>" readonly>
                                        <small class="text-danger" style="font-size: 11px;"><i class="fas fa-lock me-1"></i>NISN tidak dapat diubah secara mandiri.</small>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label small fw-bold">NIK KTP/KK</label>
                                        <input type="number" name="nik" class="form-control" value="<?php echo $d['nik']; ?>" required>
                                    </div>
                                    <div class="col-md-12">
                                        <label class="form-label small fw-bold">Nama Lengkap (Sesuai Ijazah)</label>
                                        <input type="text" name="nama_lengkap" class="form-control" value="<?php echo $d['nama_lengkap']; ?>" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label small fw-bold">Tempat Lahir</label>
                                        <input type="text" name="tempat_lahir" class="form-control" value="<?php echo $d['tempat_lahir']; ?>" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label small fw-bold">Tanggal Lahir</label>
                                        <input type="text" name="tgl_lahir" id="inputTanggal" class="form-control bg-white" value="<?php echo $d['tgl_lahir']; ?>" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label small fw-bold">Jenis Kelamin</label>
                                        <select name="jenis_kelamin" class="form-select" required>
                                            <option value="L" <?php echo ($d['jenis_kelamin']=='L')?'selected':''; ?>>Laki-laki</option>
                                            <option value="P" <?php echo ($d['jenis_kelamin']=='P')?'selected':''; ?>>Perempuan</option>
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label small fw-bold">Jenjang Tujuan</label>
                                        <select name="jenjang_tujuan" class="form-select" required>
                                            <option value="RA Terpadu Al Hasan" <?php echo ($d['jenjang_tujuan']=='RA Terpadu Al Hasan')?'selected':''; ?>>RA Terpadu Al Hasan</option>
                                            <option value="SMP Terpadu Al Hasan" <?php echo ($d['jenjang_tujuan']=='SMP Terpadu Al Hasan')?'selected':''; ?>>SMP Terpadu Al Hasan</option>
                                            <option value="SMK Terpadu Al Hasan" <?php echo ($d['jenjang_tujuan']=='SMK Terpadu Al Hasan')?'selected':''; ?>>SMK Terpadu Al Hasan</option>
                                        </select>
                                    </div>
                                </div>

                                <div class="section-title mt-4"><i class="fas fa-map-marker-alt me-2"></i>Domisili & Asal Sekolah</div>
                                <div class="row g-3">
                                    <div class="col-12">
                                        <div class="p-3 bg-light rounded-3 border mb-2 d-flex justify-content-between align-items-center">
                                            <div>
                                                <p class="mb-0 small fw-bold text-secondary">Wilayah Terdaftar Saat Ini:</p>
                                                <p class="mb-0 fw-bold text-dark">Ds. <?php echo $d['desa']; ?>, Kec. <?php echo $d['kecamatan']; ?>, <?php echo $d['kab_kota']; ?>, <?php echo $d['provinsi']; ?></p>
                                            </div>
                                        </div>
                                        <div class="form-check form-switch mb-3">
                                            <input class="form-check-input border-success" type="checkbox" id="toggleWilayah" onchange="showEditWilayah()">
                                            <label class="form-check-label small fw-bold text-success" for="toggleWilayah">Pindah / Ubah Wilayah Provinsi & Kabupaten.</label>
                                        </div>
                                    </div>

                                    <div class="col-12 m-0 p-0" id="editWilayahBox" style="display: none;">
                                        <div class="row g-2 px-2 pb-3">
                                            <div class="col-md-6">
                                                <select id="provinsi" class="form-select form-select-sm">
                                                    <option value="">Pilih Provinsi Baru...</option>
                                                </select>
                                                <input type="hidden" name="nama_provinsi" id="nama_provinsi">
                                            </div>
                                            <div class="col-md-6">
                                                <select id="kabupaten" class="form-select form-select-sm" disabled>
                                                    <option value="">Pilih Kabupaten...</option>
                                                </select>
                                                <input type="hidden" name="nama_kabupaten" id="nama_kabupaten">
                                            </div>
                                            <div class="col-md-6">
                                                <select id="kecamatan" class="form-select form-select-sm" disabled>
                                                    <option value="">Pilih Kecamatan...</option>
                                                </select>
                                                <input type="hidden" name="nama_kecamatan" id="nama_kecamatan">
                                            </div>
                                            <div class="col-md-6">
                                                <select id="desa" class="form-select form-select-sm" disabled>
                                                    <option value="">Pilih Desa...</option>
                                                </select>
                                                <input type="hidden" name="nama_desa" id="nama_desa">
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="col-12">
                                        <label class="form-label small fw-bold">Detail Jalan / Gg / RT / RW</label>
                                        <textarea name="alamat_jalan" class="form-control" rows="2" required><?php echo $d['alamat_jalan']; ?></textarea>
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label small fw-bold">Asal Sekolah</label>
                                        <input type="text" name="sekolah_asal" class="form-control" value="<?php echo $d['sekolah_asal']; ?>" required>
                                    </div>
                                </div>

                                <div class="section-title mt-4"><i class="fas fa-users me-2"></i>Data Orang Tua Wali</div>
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label small fw-bold">Nama Ayah</label>
                                        <input type="text" name="nama_ayah" class="form-control" value="<?php echo $d['nama_ayah']; ?>" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label small fw-bold">Nama Ibu</label>
                                        <input type="text" name="nama_ibu" class="form-control" value="<?php echo $d['nama_ibu']; ?>" required>
                                    </div>
                                    <div class="col-md-12">
                                        <label class="form-label small fw-bold">No WhatsApp Wali (Password Login)</label>
                                        <input type="number" name="no_hp_wali" class="form-control" value="<?php echo $d['no_hp_wali']; ?>" required>
                                        <small class="text-danger" style="font-size: 11px;"><i class="fas fa-exclamation-circle me-1"></i>Jika diubah, password untuk login ke portal ini akan ikut berubah menjadi nomor yang baru.</small>
                                    </div>
                                </div>

                                <div class="mt-5 pt-3 border-top">
                                    <button type="submit" name="update_data" class="btn btn-success w-100 fw-bold py-3 rounded-pill shadow-sm">
                                        <i class="fas fa-save me-2"></i> SIMPAN PERUBAHAN BIODATA
                                    </button>
                                </div>
                            </form>
                        </div>
                        
                        <div class="tab-pane fade" id="tab-berkas" role="tabpanel">
                            
                            <div class="alert alert-info border-0 rounded-4 small mb-4">
                                <i class="fas fa-info-circle me-1"></i> Format file yang diperbolehkan: <strong>JPG, PNG, PDF</strong> (Maks 2MB).<br>
                                <span class="text-muted">Mengunggah file baru pada slot yang sama akan otomatis menggantikan dokumen lama Anda.</span>
                            </div>

                            <form action="proses_upload_berkas.php" method="POST" enctype="multipart/form-data">
                                <div class="row g-4">
                                    
                                    <div class="col-md-6">
                                        <div class="upload-card">
                                            <i class="fas fa-id-card upload-icon"></i>
                                            <h6 class="fw-bold mb-2">KTP Orang Tua</h6>
                                            <?php if($d['file_ktp']): ?>
                                                <div class="mb-3"><span class="badge bg-success rounded-pill px-3"><i class="fas fa-check-circle me-1"></i> Terupload</span></div>
                                            <?php else: ?>
                                                <div class="mb-3"><span class="badge bg-danger rounded-pill px-3"><i class="fas fa-times-circle me-1"></i> Belum Ada</span></div>
                                            <?php endif; ?>
                                            <input type="file" name="file_ktp" class="form-control form-control-sm">
                                        </div>
                                    </div>

                                    <div class="col-md-6">
                                        <div class="upload-card">
                                            <i class="fas fa-users upload-icon"></i>
                                            <h6 class="fw-bold mb-2">Kartu Keluarga (KK)</h6>
                                            <?php if($d['file_kk']): ?>
                                                <div class="mb-3"><span class="badge bg-success rounded-pill px-3"><i class="fas fa-check-circle me-1"></i> Terupload</span></div>
                                            <?php else: ?>
                                                <div class="mb-3"><span class="badge bg-danger rounded-pill px-3"><i class="fas fa-times-circle me-1"></i> Belum Ada</span></div>
                                            <?php endif; ?>
                                            <input type="file" name="file_kk" class="form-control form-control-sm">
                                        </div>
                                    </div>

                                    <div class="col-md-6">
                                        <div class="upload-card">
                                            <i class="fas fa-baby upload-icon"></i>
                                            <h6 class="fw-bold mb-2">Akta Kelahiran</h6>
                                            <?php if($d['file_akta']): ?>
                                                <div class="mb-3"><span class="badge bg-success rounded-pill px-3"><i class="fas fa-check-circle me-1"></i> Terupload</span></div>
                                            <?php else: ?>
                                                <div class="mb-3"><span class="badge bg-danger rounded-pill px-3"><i class="fas fa-times-circle me-1"></i> Belum Ada</span></div>
                                            <?php endif; ?>
                                            <input type="file" name="file_akta" class="form-control form-control-sm">
                                        </div>
                                    </div>

                                    <div class="col-md-6">
                                        <div class="upload-card">
                                            <i class="fas fa-graduation-cap upload-icon"></i>
                                            <h6 class="fw-bold mb-2">Ijazah / SKL</h6>
                                            <?php if($d['file_ijazah']): ?>
                                                <div class="mb-3"><span class="badge bg-success rounded-pill px-3"><i class="fas fa-check-circle me-1"></i> Terupload</span></div>
                                            <?php else: ?>
                                                <div class="mb-3"><span class="badge bg-danger rounded-pill px-3"><i class="fas fa-times-circle me-1"></i> Belum Ada</span></div>
                                            <?php endif; ?>
                                            <input type="file" name="file_ijazah" class="form-control form-control-sm">
                                        </div>
                                    </div>

                                    <div class="col-md-6">
                                        <div class="upload-card">
                                            <i class="fas fa-file-alt upload-icon"></i>
                                            <h6 class="fw-bold mb-2">Rapor / Nilai Akhir</h6>
                                            <?php if($d['file_nilai']): ?>
                                                <div class="mb-3"><span class="badge bg-success rounded-pill px-3"><i class="fas fa-check-circle me-1"></i> Terupload</span></div>
                                            <?php else: ?>
                                                <div class="mb-3"><span class="badge bg-danger rounded-pill px-3"><i class="fas fa-times-circle me-1"></i> Belum Ada</span></div>
                                            <?php endif; ?>
                                            <input type="file" name="file_nilai" class="form-control form-control-sm">
                                        </div>
                                    </div>

                                    <div class="col-md-6">
                                        <div class="upload-card">
                                            <i class="fas fa-trophy upload-icon"></i>
                                            <h6 class="fw-bold mb-2">Sertifikat Prestasi</h6>
                                            <?php if($d['file_prestasi']): ?>
                                                <div class="mb-3"><span class="badge bg-success rounded-pill px-3"><i class="fas fa-check-circle me-1"></i> Terupload</span></div>
                                            <?php else: ?>
                                                <div class="mb-3"><span class="badge bg-secondary rounded-pill px-3">Opsional</span></div>
                                            <?php endif; ?>
                                            <input type="file" name="file_prestasi" class="form-control form-control-sm">
                                        </div>
                                    </div>

                                </div>

                                <div class="mt-5 pt-3 border-top">
                                    <button type="submit" name="upload" class="btn btn-success w-100 fw-bold py-3 rounded-pill shadow-sm">
                                        <i class="fas fa-cloud-upload-alt me-2"></i> UNGGAH & SIMPAN BERKAS
                                    </button>
                                </div>
                            </form>
                        </div>

                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include 'footer.php'; ?>

<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script src="https://npmcdn.com/flatpickr/dist/l10n/id.js"></script>

<script>
    document.addEventListener("DOMContentLoaded", function() {
        flatpickr("#inputTanggal", { altInput: true, altFormat: "d-m-Y", dateFormat: "Y-m-d", locale: "id", maxDate: "today" });
    });

    function showEditWilayah() {
        let box = document.getElementById('editWilayahBox');
        let prov = document.getElementById('provinsi');
        if(document.getElementById('toggleWilayah').checked) {
            box.style.display = 'block'; prov.setAttribute('required', 'required');
            if(prov.options.length <= 1) {
                prov.innerHTML = '<option>Loading...</option>';
                fetch(`https://www.emsifa.com/api-wilayah-indonesia/api/provinces.json`).then(res => res.json()).then(provinces => {
                    let options = '<option value="">Pilih Provinsi Baru...</option>';
                    provinces.forEach(p => options += `<option value="${p.id}" data-name="${p.name}">${p.name}</option>`);
                    prov.innerHTML = options;
                });
            }
        } else {
            box.style.display = 'none'; prov.removeAttribute('required');
            document.getElementById('nama_provinsi').value = "";
        }
    }

    document.getElementById('provinsi').addEventListener('change', function() {
        document.getElementById('nama_provinsi').value = this.options[this.selectedIndex].getAttribute('data-name');
        let id_prov = this.value; let kab = document.getElementById('kabupaten');
        document.getElementById('kecamatan').innerHTML = '<option value="">Pilih Kecamatan...</option>'; document.getElementById('kecamatan').disabled = true;
        document.getElementById('desa').innerHTML = '<option value="">Pilih Desa...</option>'; document.getElementById('desa').disabled = true;
        if(id_prov){
            kab.disabled = false; kab.innerHTML = '<option>Loading...</option>';
            fetch(`https://www.emsifa.com/api-wilayah-indonesia/api/regencies/${id_prov}.json`).then(res => res.json()).then(regencies => {
                let options = '<option value="">Pilih Kabupaten / Kota...</option>';
                regencies.forEach(r => options += `<option value="${r.id}" data-name="${r.name}">${r.name}</option>`);
                kab.innerHTML = options;
            });
        }
    });

    document.getElementById('kabupaten').addEventListener('change', function() {
        document.getElementById('nama_kabupaten').value = this.options[this.selectedIndex].getAttribute('data-name');
        let id_kab = this.value; let kec = document.getElementById('kecamatan');
        document.getElementById('desa').innerHTML = '<option value="">Pilih Desa...</option>'; document.getElementById('desa').disabled = true;
        if(id_kab){
            kec.disabled = false; kec.innerHTML = '<option>Loading...</option>';
            fetch(`https://www.emsifa.com/api-wilayah-indonesia/api/districts/${id_kab}.json`).then(res => res.json()).then(districts => {
                let options = '<option value="">Pilih Kecamatan...</option>';
                districts.forEach(d => options += `<option value="${d.id}" data-name="${d.name}">${d.name}</option>`);
                kec.innerHTML = options;
            });
        }
    });

    document.getElementById('kecamatan').addEventListener('change', function() {
        document.getElementById('nama_kecamatan').value = this.options[this.selectedIndex].getAttribute('data-name');
        let id_kec = this.value; let desa = document.getElementById('desa');
        if(id_kec){
            desa.disabled = false; desa.innerHTML = '<option>Loading...</option>';
            fetch(`https://www.emsifa.com/api-wilayah-indonesia/api/villages/${id_kec}.json`).then(res => res.json()).then(villages => {
                let options = '<option value="">Pilih Desa / Kelurahan...</option>';
                villages.forEach(v => options += `<option value="${v.id}" data-name="${v.name}">${v.name}</option>`);
                desa.innerHTML = options;
            });
        }
    });

    document.getElementById('desa').addEventListener('change', function() {
        document.getElementById('nama_desa').value = this.options[this.selectedIndex].getAttribute('data-name');
    });
</script>