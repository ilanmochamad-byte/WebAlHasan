<?php
session_start();
if(!isset($_SESSION['status']) || $_SESSION['status'] != "login"){
    header("Location: admin_login.php");
    exit;
}
include '../koneksi.php';

// PROSES TAMBAH MANUAL
if(isset($_POST['tambah_manual'])){
    $nama = mysqli_real_escape_string($koneksi, $_POST['nama']);
    $nisn = mysqli_real_escape_string($koneksi, $_POST['nisn']);
    $nik = mysqli_real_escape_string($koneksi, $_POST['nik']);
    $jk = $_POST['jk'];
    $tmp_lahir = mysqli_real_escape_string($koneksi, $_POST['tmp_lahir']);
    $tgl_lahir = $_POST['tgl_lahir'];
    $alamat = mysqli_real_escape_string($koneksi, $_POST['alamat']);
    $desa = mysqli_real_escape_string($koneksi, $_POST['desa']);
    $kec = mysqli_real_escape_string($koneksi, $_POST['kecamatan']);
    $kab = mysqli_real_escape_string($koneksi, $_POST['kab_kota']);
    $prov = mysqli_real_escape_string($koneksi, $_POST['provinsi']);
    $ayah = mysqli_real_escape_string($koneksi, $_POST['ayah']);
    $ibu = mysqli_real_escape_string($koneksi, $_POST['ibu']);
    $hp = mysqli_real_escape_string($koneksi, $_POST['no_hp_wali']);
    $asal = mysqli_real_escape_string($koneksi, $_POST['sekolah_asal']);
    $jenjang = $_POST['jenjang_tujuan'];

    if(empty($nisn) || $nisn == '-') {
        $nisn = 'TMP' . date('ymd') . rand(1000, 9999);
    }

    $no_pendaftaran = "PSB" . date('Y') . rand(1000, 9999);

    $query = "INSERT INTO psb_pendaftar (
        no_pendaftaran, nama_lengkap, nisn, nik, jenis_kelamin, tempat_lahir, tgl_lahir,
        alamat_jalan, desa, kecamatan, kab_kota, provinsi,
        nama_ayah, nama_ibu, no_hp_wali, sekolah_asal, jenjang_tujuan, status
    ) VALUES (
        '$no_pendaftaran', '$nama', '$nisn', '$nik', '$jk', '$tmp_lahir', '$tgl_lahir',
        '$alamat', '$desa', '$kec', '$kab', '$prov',
        '$ayah', '$ibu', '$hp', '$asal', '$jenjang', 'Baru'
    )";

    if(mysqli_query($koneksi, $query)){
        echo "<script>alert('Pendaftar berhasil ditambahkan secara manual!'); window.location='admin_data.php';</script>";
    } else {
        echo "<script>alert('Gagal Menambahkan: Cek apakah NISN tersebut sudah digunakan.'); window.location='admin_data.php';</script>";
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <title>Tindak Lanjut PSB - Admin</title>
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
            <h2 class="h2 mb-4">Validasi & Tindak Lanjut PSB</h2>

            <div class="row g-3 mb-4">
                <?php
                $tot_baru = mysqli_num_rows(mysqli_query($koneksi, "SELECT id FROM psb_pendaftar WHERE status='Baru'"));
                // Menghitung yang masih berstatus Diterima di PPDB maupun yang sudah dialihkan ke Master Santri
                $tot_terima = mysqli_num_rows(mysqli_query($koneksi, "SELECT id FROM psb_pendaftar WHERE status='Diterima' OR status='Dimigrasi'"));
                $tot_tolak = mysqli_num_rows(mysqli_query($koneksi, "SELECT id FROM psb_pendaftar WHERE status='Ditolak'"));
                    // $tot_baru = mysqli_num_rows(mysqli_query($koneksi, "SELECT id FROM psb_pendaftar WHERE status='Baru'"));
                    // $tot_terima = mysqli_num_rows(mysqli_query($koneksi, "SELECT id FROM psb_pendaftar WHERE status='Diterima'"));
                    // $tot_tolak = mysqli_num_rows(mysqli_query($koneksi, "SELECT id FROM psb_pendaftar WHERE status='Ditolak'"));
                ?>
                <div class="col-md-4">
                    <div class="card shadow-sm border-0 border-start border-primary border-5">
                        <div class="card-body">
                            <h6 class="text-muted">Pendaftar Baru (Belum Diproses)</h6>
                            <h3 class="fw-bold text-primary mb-0"><?php echo $tot_baru; ?></h3>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card shadow-sm border-0 border-start border-success border-5">
                        <div class="card-body">
                            <h6 class="text-muted">Diterima</h6>
                            <h3 class="fw-bold text-success mb-0"><?php echo $tot_terima; ?></h3>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card shadow-sm border-0 border-start border-danger border-5">
                        <div class="card-body">
                            <h6 class="text-muted">Ditolak / Cadangan</h6>
                            <h3 class="fw-bold text-danger mb-0"><?php echo $tot_tolak; ?></h3>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card shadow-sm border-0 mb-4 bg-light">
                <div class="card-body">
                    <form method="GET" class="row g-2">
                        <div class="col-md-2">
                            <select name="jk" class="form-select form-select-sm">
                                <option value="">Semua JK</option>
                                <option value="L" <?php if(isset($_GET['jk']) && $_GET['jk']=='L') echo 'selected'; ?>>Laki-laki</option>
                                <option value="P" <?php if(isset($_GET['jk']) && $_GET['jk']=='P') echo 'selected'; ?>>Perempuan</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <select name="jenjang" class="form-select form-select-sm">
                                <option value="">Semua Pilihan Unit</option>
                                <option value="SMKN 2 Ciamis" <?php if(isset($_GET['jenjang']) && $_GET['jenjang']=='SMKN 2 Ciamis') echo 'selected'; ?>>SMKN 2 Ciamis</option>
                                <option value="SMKN 1 Ciamis" <?php if(isset($_GET['jenjang']) && $_GET['jenjang']=='SMKN 1 Ciamis') echo 'selected'; ?>>SMKN 1 Ciamis</option>
                                <option value="SMAN 2 Ciamis" <?php if(isset($_GET['jenjang']) && $_GET['jenjang']=='SMAN 2 Ciamis') echo 'selected'; ?>>SMAN 2 Ciamis</option>
                                <option value="SMAN 1 Ciamis" <?php if(isset($_GET['jenjang']) && $_GET['jenjang']=='SMAN 1 Ciamis') echo 'selected'; ?>>SMAN 1 Ciamis</option>
                                <option value="MAN 2 Ciamis" <?php if(isset($_GET['jenjang']) && $_GET['jenjang']=='MAN 2 Ciamis') echo 'selected'; ?>>MAN 2 Ciamis</option>
                                <option value="SMK Terpadu Al Hasan" <?php if(isset($_GET['jenjang']) && $_GET['jenjang']=='SMK Terpadu Al Hasan') echo 'selected'; ?>>SMK Terpadu Al Hasan</option>
                                <option value="SMP Terpadu Al Hasan" <?php if(isset($_GET['jenjang']) && $_GET['jenjang']=='SMP Terpadu Al Hasan') echo 'selected'; ?>>SMP Terpadu Al Hasan</option>
                                <option value="RA Terpadu Al Hasan" <?php if(isset($_GET['jenjang']) && $_GET['jenjang']=='RA Terpadu Al Hasan') echo 'selected'; ?>>RA Terpadu Al Hasan</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <input type="text" name="kecamatan" class="form-control form-control-sm" placeholder="Filter Kecamatan..." value="<?php echo isset($_GET['kecamatan'])?$_GET['kecamatan']:''; ?>">
                        </div>
                        <div class="col-md-3">
                            <div class="input-group input-group-sm">
                                <input type="text" name="cari" class="form-control" placeholder="Cari Nama/No Daftar..." value="<?php echo isset($_GET['cari'])?$_GET['cari']:''; ?>">
                                <button class="btn btn-primary" type="submit"><i class="fas fa-search"></i> Cari</button>
                            </div>
                        </div>
                        <div class="col-md-1">
                            <a href="admin_data.php" class="btn btn-secondary btn-sm w-100"><i class="fas fa-sync"></i></a>
                        </div>
                    </form>
                </div>
            </div>

            <div class="mb-3 d-flex flex-wrap gap-2">
                <button type="button" class="btn btn-success shadow-sm fw-bold" data-bs-toggle="modal" data-bs-target="#modalInputManual">
                    <i class="fas fa-edit me-2"></i> Input Manual
                </button>
                <button type="button" class="btn btn-primary shadow-sm fw-bold" data-bs-toggle="modal" data-bs-target="#modalImportPSB">
                    <i class="fas fa-file-import me-2"></i> Import Excel
                </button>
                
                <button type="button" id="btnExportData" onclick="exportDataExcel()" class="btn btn-warning shadow-sm fw-bold text-dark ms-auto">
                    <i class="fas fa-file-excel me-2"></i> Export Excel Sesuai Filter
                </button>
            </div>

            <div class="card shadow border-0 mb-5">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                    <h6 class="m-0 fw-bold text-success"><i class="fas fa-tasks me-2"></i>Daftar Calon Santri Baru</h6>
                </div>
                <div class="card-body p-3">
                    <div class="table-responsive">
                        <table id="tabelSantri" class="table table-hover table-striped table-bordered align-middle">
                            <thead class="table-dark">
                                <tr>
                                    <th>No</th>
                                    <th>No. Daftar & Nama</th>
                                    <th>Pilihan Unit</th>
                                    <th>Asal Sekolah</th>
                                    <th>Kontak Wali</th>
                                    <th class="text-center">Status</th>
                                    <th class="text-center" width="200">Tindak Lanjut</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $no = 1;
                                // PERBAIKAN: Secara default sembunyikan pendaftar yang sudah dimigrasi ke data master santri
                                $where = "WHERE status != 'Dimigrasi'";
                                
                                if(!empty($_GET['jk'])) $where .= " AND jenis_kelamin = '$_GET[jk]'";
                                if(!empty($_GET['jenjang'])) $where .= " AND jenjang_tujuan = '$_GET[jenjang]'";
                                if(!empty($_GET['kecamatan'])) $where .= " AND kecamatan LIKE '%$_GET[kecamatan]%'";
                                if(!empty($_GET['cari'])) $where .= " AND (nama_lengkap LIKE '%$_GET[cari]%' OR no_pendaftaran LIKE '%$_GET[cari]%')";

                                $data_pendaftar = [];
                                $data = mysqli_query($koneksi, "SELECT * FROM psb_pendaftar $where ORDER BY id DESC");
                                // $no = 1;
                                // $where = "WHERE 1=1";
                                // if(!empty($_GET['jk'])) $where .= " AND jenis_kelamin = '$_GET[jk]'";
                                // if(!empty($_GET['jenjang'])) $where .= " AND jenjang_tujuan = '$_GET[jenjang]'";
                                // if(!empty($_GET['kecamatan'])) $where .= " AND kecamatan LIKE '%$_GET[kecamatan]%'";
                                // if(!empty($_GET['cari'])) $where .= " AND (nama_lengkap LIKE '%$_GET[cari]%' OR no_pendaftaran LIKE '%$_GET[cari]%')";

                                // $data_pendaftar = [];
                                // $data = mysqli_query($koneksi, "SELECT * FROM psb_pendaftar $where ORDER BY id DESC");
                                
                                while($d = mysqli_fetch_array($data)){
                                    $data_pendaftar[] = $d;
                                ?>
                                <tr>
                                    <td><?php echo $no++; ?></td>
                                    <td>
                                        <div class="fw-bold text-dark"><?php echo $d['nama_lengkap']; ?></div>
                                        <small class="text-success fw-bold"><?php echo $d['no_pendaftaran']; ?></small>
                                    </td>
                                    <td><span class="badge bg-secondary"><?php echo $d['jenjang_tujuan']; ?></span></td>
                                    <td><?php echo $d['sekolah_asal']; ?></td>
                                    <td>
                                        <a href="https://wa.me/<?php echo '62'.substr($d['no_hp_wali'], 1); ?>" target="_blank" class="text-success fw-bold text-decoration-none">
                                            <i class="fab fa-whatsapp"></i> <?php echo $d['no_hp_wali']; ?>
                                        </a>
                                    </td>
                                    <td class="text-center">
                                        <?php if($d['status'] == 'Baru'){ ?>
                                            <span class="badge bg-primary rounded-pill">Baru</span>
                                        <?php } else if($d['status'] == 'Diterima'){ ?>
                                            <span class="badge bg-success rounded-pill">DITERIMA</span>
                                        <?php } else if($d['status'] == 'Ditolak'){ ?>
                                            <span class="badge bg-danger rounded-pill">Ditolak</span>
                                        <?php } else { ?>
                                            <span class="badge bg-warning text-dark rounded-pill">Cadangan</span>
                                        <?php } ?>
                                    </td>
                                    <td class="text-center">
                                        
                                        <div class="btn-group btn-group-sm mb-1 w-100">
                                            <button class="btn btn-info text-white fw-bold" data-bs-toggle="modal" data-bs-target="#modalDetail<?php echo $d['id']; ?>" title="Lihat Detail">
                                                <i class="fas fa-eye"></i> Detail
                                            </button>

                                            <?php if($d['status'] == 'Baru' || $d['status'] == 'Cadangan'){ ?>
                                                <a href="proses_psb_admin.php?act=status&id=<?php echo $d['id']; ?>&val=Diterima" class="btn btn-success" title="Terima Santri"><i class="fas fa-check"></i></a>
                                                <a href="proses_psb_admin.php?act=status&id=<?php echo $d['id']; ?>&val=Ditolak" class="btn btn-danger" onclick="return confirm('Yakin ingin menolak pendaftar ini?')"><i class="fas fa-times"></i></a>
                                            <?php } ?>
                                        </div>

                                        <?php if($d['status'] == 'Diterima'){ ?>
                                            <a href="proses_psb_admin.php?act=migrasi&id=<?php echo $d['id']; ?>" class="btn btn-primary btn-sm w-100 fw-bold mb-1" onclick="return confirm('Ini akan memindahkan data santri ke Master Data secara otomatis. Lanjutkan?')">
                                                <i class="fas fa-share-square me-1"></i> Migrasi Master
                                            </a>
                                        <?php } ?>

                                        <div class="mt-1 d-flex gap-1 justify-content-center">
                                            <a href="../cetak_bukti.php?noreg=<?php echo $d['no_pendaftaran']; ?>" target="_blank" class="btn btn-outline-secondary btn-sm w-50" title="Cetak Bukti"><i class="fas fa-print"></i> Cetak</a>
                                            <a href="hapus_siswa.php?id=<?php echo $d['id']; ?>" class="btn btn-outline-danger btn-sm w-50" onclick="return confirm('Hapus data pendaftaran ini permanen?')"><i class="fas fa-trash"></i> Hapus</a>
                                        </div>
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

<div class="modal fade" id="modalInputManual" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="fas fa-edit me-2"></i> Input Pendaftaran Manual</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form method="POST">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <h6 class="fw-bold border-bottom pb-2">1. Identitas Santri</h6>
                            <div class="mb-2"><label class="small fw-bold">Nama Lengkap</label><input type="text" name="nama" class="form-control" required></div>
                            <div class="row">
                                <div class="col-6 mb-2"><label class="small fw-bold">NISN</label><input type="text" name="nisn" class="form-control" placeholder="Kosongkan jika tidak ada"></div>
                                <div class="col-6 mb-2"><label class="small fw-bold">NIK</label><input type="text" name="nik" class="form-control" placeholder="No KTP/KK"></div>
                            </div>
                            <div class="row">
                                <div class="col-6 mb-2"><label class="small fw-bold">Tempat Lahir</label><input type="text" name="tmp_lahir" class="form-control"></div>
                                <div class="col-6 mb-2"><label class="small fw-bold">Tgl Lahir</label><input type="date" name="tgl_lahir" class="form-control" required></div>
                            </div>
                            <div class="mb-3"><label class="small fw-bold">Jenis Kelamin</label><select name="jk" class="form-select"><option value="L">Laki-laki</option><option value="P">Perempuan</option></select></div>

                            <h6 class="fw-bold border-bottom pb-2">2. Asal & Tujuan Sekolah</h6>
                            <div class="mb-2"><label class="small fw-bold">Asal Sekolah</label><input type="text" name="sekolah_asal" class="form-control"></div>
                            <div class="mb-2">
                                <label class="small fw-bold">Pilihan Unit Sekolah</label>
                                <select name="jenjang_tujuan" class="form-select" required>
                                    <option value="SMKN 2 Ciamis">SMKN 2 Ciamis</option>
                                    <option value="SMKN 1 Ciamis">SMKN 1 Ciamis</option>
                                    <option value="SMAN 2 Ciamis">SMAN 2 Ciamis</option>
                                    <option value="SMAN 1 Ciamis">SMAN 1 Ciamis</option>
                                    <option value="MAN 2 Ciamis">MAN 2 Ciamis</option>
                                    <option value="SMK Terpadu Al Hasan">SMK Terpadu Al Hasan</option>
                                    <option value="SMP Terpadu Al Hasan">SMP Terpadu Al Hasan</option>
                                    <option value="RA Terpadu Al Hasan">RA Terpadu Al Hasan</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <h6 class="fw-bold border-bottom pb-2">3. Alamat Domisili</h6>
                            <div class="mb-2"><label class="small fw-bold">Jalan / Dusun</label><input type="text" name="alamat" class="form-control"></div>
                            <div class="row">
                                <div class="col-6 mb-2"><label class="small fw-bold">Desa</label><input type="text" name="desa" class="form-control"></div>
                                <div class="col-6 mb-2"><label class="small fw-bold">Kecamatan</label><input type="text" name="kecamatan" class="form-control"></div>
                                <div class="col-6 mb-2"><label class="small fw-bold">Kab/Kota</label><input type="text" name="kab_kota" class="form-control"></div>
                                <div class="col-6 mb-2"><label class="small fw-bold">Provinsi</label><input type="text" name="provinsi" class="form-control"></div>
                            </div>

                            <h6 class="fw-bold border-bottom pb-2 mt-2">4. Data Orang Tua Wali</h6>
                            <div class="row">
                                <div class="col-6 mb-2"><label class="small fw-bold">Nama Ayah</label><input type="text" name="ayah" class="form-control"></div>
                                <div class="col-6 mb-2"><label class="small fw-bold">Nama Ibu</label><input type="text" name="ibu" class="form-control"></div>
                                <div class="col-12 mb-2"><label class="small fw-bold">No WhatsApp Wali (Aktif)</label><input type="text" name="no_hp_wali" class="form-control"></div>
                            </div>
                        </div>
                    </div>
                    <div class="mt-4 text-end">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" name="tambah_manual" class="btn btn-success px-4 fw-bold">Simpan Pendaftar</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php foreach($data_pendaftar as $d): ?>
<div class="modal fade" id="modalDetail<?php echo $d['id']; ?>" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title fw-bold"><i class="fas fa-id-card me-2"></i> Detail Calon Santri: <?php echo $d['nama_lengkap']; ?></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                
                <div class="row g-4">
                    <div class="col-lg-7 border-end">
                        <h6 class="fw-bold text-success border-bottom pb-2 mb-3">Biodata Pendaftar</h6>
                        <table class="table table-sm table-borderless">
                            <tr><td width="35%" class="text-muted">No. Pendaftaran</td><td class="fw-bold">: <?php echo $d['no_pendaftaran']; ?></td></tr>
                            <tr><td class="text-muted">Tanggal Daftar</td><td>: <?php echo isset($d['tgl_daftar']) ? date('d-m-Y H:i', strtotime($d['tgl_daftar'])) : '-'; ?></td></tr>
                            <tr><td class="text-muted">NIK</td><td>: <?php echo $d['nik']; ?></td></tr>
                            <tr><td class="text-muted">NISN</td><td>: <?php echo $d['nisn']; ?></td></tr>
                            <tr><td class="text-muted">Nama Lengkap</td><td class="fw-bold text-uppercase">: <?php echo $d['nama_lengkap']; ?></td></tr>
                            <tr><td class="text-muted">Tempat, Tgl Lahir</td><td>: <?php echo $d['tempat_lahir'].', '.date('d-m-Y', strtotime($d['tgl_lahir'])); ?></td></tr>
                            <tr><td class="text-muted">Jenis Kelamin</td><td>: <?php echo ($d['jenis_kelamin']=='L') ? 'Laki-laki' : 'Perempuan'; ?></td></tr>
                            <tr><td class="text-muted">Alamat Lengkap</td><td>: <?php echo $d['alamat_jalan']; ?></td></tr>
                            <tr><td class="text-muted">Wilayah</td><td>: Ds. <?php echo $d['desa']; ?>, Kec. <?php echo $d['kecamatan']; ?>, <?php echo $d['kab_kota']; ?>, <?php echo $d['provinsi']; ?></td></tr>
                            <tr><td class="text-muted">Asal Sekolah</td><td>: <?php echo $d['sekolah_asal']; ?></td></tr>
                            <tr><td class="text-muted">Jenjang Tujuan</td><td class="fw-bold text-primary">: <?php echo $d['jenjang_tujuan']; ?></td></tr>
                        </table>

                        <h6 class="fw-bold text-success border-bottom pb-2 mt-4 mb-3">Data Orang Tua Wali</h6>
                        <table class="table table-sm table-borderless">
                            <tr><td width="35%" class="text-muted">Nama Ayah</td><td>: <?php echo $d['nama_ayah']; ?></td></tr>
                            <tr><td class="text-muted">Nama Ibu</td><td>: <?php echo $d['nama_ibu']; ?></td></tr>
                            <tr><td class="text-muted">No. WhatsApp Wali</td><td class="fw-bold">: <a href="https://wa.me/<?php echo '62'.substr($d['no_hp_wali'], 1); ?>" target="_blank" class="text-success"><i class="fab fa-whatsapp"></i> <?php echo $d['no_hp_wali']; ?></a></td></tr>
                        </table>
                    </div>

                    <div class="col-lg-5">
                        <h6 class="fw-bold text-success border-bottom pb-2 mb-3">Dokumen Berkas Pendaftar</h6>
                        <div class="alert alert-light border small mb-3">
                            <i class="fas fa-info-circle me-1"></i> Klik tombol <b>Lihat</b> untuk memeriksa berkas yang telah diunggah oleh pendaftar.
                        </div>

                        <div class="d-grid gap-2">
                            <div class="d-flex justify-content-between align-items-center p-2 border rounded">
                                <span class="small fw-bold"><i class="fas fa-id-card text-secondary me-2"></i> KTP Orang Tua</span>
                                <?php if($d['file_ktp']): ?>
                                    <a href="../berkas_psb/<?php echo $d['file_ktp']; ?>" target="_blank" class="btn btn-sm btn-outline-success rounded-pill px-3">Lihat KTP</a>
                                <?php else: ?>
                                    <span class="badge bg-danger rounded-pill">Belum Ada</span>
                                <?php endif; ?>
                            </div>
                            
                            <div class="d-flex justify-content-between align-items-center p-2 border rounded">
                                <span class="small fw-bold"><i class="fas fa-users text-secondary me-2"></i> Kartu Keluarga</span>
                                <?php if($d['file_kk']): ?>
                                    <a href="../berkas_psb/<?php echo $d['file_kk']; ?>" target="_blank" class="btn btn-sm btn-outline-success rounded-pill px-3">Lihat KK</a>
                                <?php else: ?>
                                    <span class="badge bg-danger rounded-pill">Belum Ada</span>
                                <?php endif; ?>
                            </div>

                            <div class="d-flex justify-content-between align-items-center p-2 border rounded">
                                <span class="small fw-bold"><i class="fas fa-baby text-secondary me-2"></i> Akta Kelahiran</span>
                                <?php if($d['file_akta']): ?>
                                    <a href="../berkas_psb/<?php echo $d['file_akta']; ?>" target="_blank" class="btn btn-sm btn-outline-success rounded-pill px-3">Lihat Akta</a>
                                <?php else: ?>
                                    <span class="badge bg-danger rounded-pill">Belum Ada</span>
                                <?php endif; ?>
                            </div>

                            <div class="d-flex justify-content-between align-items-center p-2 border rounded">
                                <span class="small fw-bold"><i class="fas fa-graduation-cap text-secondary me-2"></i> Ijazah / SKL</span>
                                <?php if($d['file_ijazah']): ?>
                                    <a href="../berkas_psb/<?php echo $d['file_ijazah']; ?>" target="_blank" class="btn btn-sm btn-outline-success rounded-pill px-3">Lihat Ijazah</a>
                                <?php else: ?>
                                    <span class="badge bg-danger rounded-pill">Belum Ada</span>
                                <?php endif; ?>
                            </div>

                            <div class="d-flex justify-content-between align-items-center p-2 border rounded">
                                <span class="small fw-bold"><i class="fas fa-file-alt text-secondary me-2"></i> Rapor / Nilai</span>
                                <?php if($d['file_nilai']): ?>
                                    <a href="../berkas_psb/<?php echo $d['file_nilai']; ?>" target="_blank" class="btn btn-sm btn-outline-success rounded-pill px-3">Lihat Nilai</a>
                                <?php else: ?>
                                    <span class="badge bg-danger rounded-pill">Belum Ada</span>
                                <?php endif; ?>
                            </div>

                            <div class="d-flex justify-content-between align-items-center p-2 border rounded">
                                <span class="small fw-bold"><i class="fas fa-trophy text-secondary me-2"></i> Sertifikat Prestasi</span>
                                <?php if($d['file_prestasi']): ?>
                                    <a href="../berkas_psb/<?php echo $d['file_prestasi']; ?>" target="_blank" class="btn btn-sm btn-outline-success rounded-pill px-3">Lihat Sertifikat</a>
                                <?php else: ?>
                                    <span class="badge bg-secondary rounded-pill">Kosong</span>
                                <?php endif; ?>
                            </div>
                        </div>

                    </div>
                </div>
                
            </div>
            <div class="modal-footer bg-light">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup Detail</button>
            </div>
        </div>
    </div>
</div>
<?php endforeach; ?>

<div class="modal fade" id="modalImportPSB" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="fas fa-file-excel me-2"></i> Import Data Pendaftar Fisik</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="formImportPSB">
                <div class="modal-body">
                    <div class="alert alert-info small">
                        <i class="fas fa-info-circle me-1"></i> <strong>PANDUAN SUSUNAN KOLOM EXCEL:</strong><br>
                        Sistem akan membaca Excel dari kiri ke kanan. Pastikan susunan kolomnya persis seperti ini:<br><br>
                        <b>Kolom A:</b> Nama Lengkap (Wajib)<br>
                        <b>Kolom B:</b> NISN<br>
                        <b>Kolom C:</b> NIK<br>
                        <b>Kolom D:</b> JK (L/P)<br>
                        <b>Kolom E s.d K:</b> Tempat Lahir, Tgl Lahir, Alamat, Desa, Kec, Kab/Kota, Prov<br>
                        <b>Kolom L s.d Q:</b> Nama Ayah, Nama Ibu, No HP Wali, Sekolah Asal, Jenjang Tujuan<br>
                        <br><i>*Baris ke-1 (Judul Kolom) akan diabaikan secara otomatis. Nomor Pendaftaran akan dibuatkan otomatis oleh sistem.</i>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Pilih File Data Pendaftar (.xlsx)</label>
                        <input type="file" id="file_excel_psb" class="form-control form-control-lg" accept=".xlsx" required>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" id="btnProsesImportPSB" class="btn btn-primary fw-bold">Upload & Mulai Proses</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.5.1.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>

<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>

<script>
    $(document).ready(function () {
        // KONFIGURASI DATATABLES DENGAN PAGINATION PENUH
        $('#tabelSantri').DataTable({
            "lengthMenu": [[25, 50, 100, -1], [25, 50, 100, "Semua"]],
            "pageLength": 25,
            "language": {
                "lengthMenu": "Tampilkan _MENU_ data",
                "zeroRecords": "Tidak ada data pendaftar ditemukan.",
                "info": "Menampilkan _START_ sampai _END_ dari _TOTAL_ pendaftar",
                "infoEmpty": "Data kosong",
                "infoFiltered": "(filter dari _MAX_ data)",
                "search": "Cari Cepat:",
                "paginate": { "first": "Awal", "last": "Akhir", "next": "Maju", "previous": "Mundur" }
            }
        });
    });

    // ===============================================
    // FUNGSI EKSPOR EXCEL CLIENT-SIDE MENGGUNAKAN SHEETJS
    // ===============================================
    function exportDataExcel() {
        const btn = document.getElementById('btnExportData');
        const originalText = btn.innerHTML;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i> Merakit File...';
        btn.disabled = true;

        // Ambil parameter filter dari URL
        const urlParams = new URLSearchParams(window.location.search);
        
        fetch('export_psb.php?' + urlParams.toString())
        .then(res => res.json())
        .then(data => {
            if(data.length === 0) {
                alert('Tidak ada data yang cocok dengan filter untuk diekspor.');
                btn.innerHTML = originalText;
                btn.disabled = false;
                return;
            }
            
            // Konversi dari JSON ke Worksheet Excel
            const ws = XLSX.utils.json_to_sheet(data);
            const wb = XLSX.utils.book_new();
            XLSX.utils.book_append_sheet(wb, ws, "Data Calon Santri");
            
            // Penamaan File Dinamis dengan Tanggal
            const date = new Date();
            const fileName = "Data_PSB_AlHasan_" + date.getFullYear() + "-" + (date.getMonth()+1) + "-" + date.getDate() + ".xlsx";
            
            // Unduh file secara otomatis
            XLSX.writeFile(wb, fileName);
            
            btn.innerHTML = originalText;
            btn.disabled = false;
        })
        .catch(err => {
            alert('Gagal mengekspor data: ' + err);
            btn.innerHTML = originalText;
            btn.disabled = false;
        });
    }

    // ===============================================
    // FUNGSI IMPORT EXCEL CLIENT-SIDE MENGGUNAKAN SHEETJS
    // ===============================================
    document.getElementById('formImportPSB').addEventListener('submit', function(e) {
        e.preventDefault();
        
        const fileInput = document.getElementById('file_excel_psb');
        const file = fileInput.files[0];
        if (!file) return;

        const btnProses = document.getElementById('btnProsesImportPSB');
        btnProses.disabled = true;
        btnProses.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Membaca & Memasukkan Data...';

        const reader = new FileReader();
        reader.onload = function(e) {
            try {
                const data = new Uint8Array(e.target.result);
                const workbook = XLSX.read(data, { type: 'array' });
                const firstSheetName = workbook.SheetNames[0];
                const worksheet = workbook.Sheets[firstSheetName];
                
                const jsonRows = XLSX.utils.sheet_to_json(worksheet, { 
                    header: 1, 
                    defval: "", 
                    raw: false, 
                    dateNF: 'yyyy-mm-dd' 
                });
                
                const formData = new FormData();
                formData.append('payload', JSON.stringify(jsonRows));

                fetch('proses_import_psb.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.text())
                .then(result => {
                    alert(result);
                    window.location.reload();
                })
                .catch(error => {
                    alert('Error Koneksi: ' + error);
                    btnProses.disabled = false;
                    btnProses.innerHTML = 'Upload & Mulai Proses';
                });
            } catch (err) {
                alert("Gagal membaca file. Pastikan format file sesuai.");
                btnProses.disabled = false;
                btnProses.innerHTML = 'Upload & Mulai Proses';
            }
        };
        reader.readAsArrayBuffer(file);
    });
</script>

</body>
</html>