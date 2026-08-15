<?php
// Pastikan session_start() hanya dipanggil sekali di bagian paling atas
session_start();
if(!isset($_SESSION['status']) || $_SESSION['status'] != "login"){ header("Location: admin_login.php"); exit; }
include '../koneksi.php';

// --- 1. FUNGSI GENERATE NIS OTOMATIS BERDASARKAN UNIT ---
function generateNIS($koneksi, $unit) {
    $tahun = date('y'); 
    $kode_unit = ($unit == 'SMP Terpadu Al Hasan') ? '01' : '02';
    $prefix = $tahun . $kode_unit; 
    
    $query = mysqli_query($koneksi, "SELECT max(nis) as maxKode FROM santri WHERE nis LIKE '$prefix%'");
    $data = mysqli_fetch_array($query);
    $nis_terakhir = $data['maxKode'];
    
    if($nis_terakhir){
        $urutan = (int) substr($nis_terakhir, 4, 3);
        $urutan++;
    } else {
        $urutan = 1;
    }
    return $prefix . sprintf("%03s", $urutan);
}

// --- 2. LOGIKA TAMBAH DATA MANUAL ---
if(isset($_POST['tambah'])){
    $mode_nis = $_POST['mode_nis'];
    $sekolah = $_POST['sekolah_saat_ini'];
    
    if($mode_nis == 'auto'){
        $nis = generateNIS($koneksi, $sekolah);
    } else {
        $nis = mysqli_real_escape_string($koneksi, $_POST['nis_manual']);
        $cek = mysqli_query($koneksi, "SELECT nis FROM santri WHERE nis='$nis'");
        if(mysqli_num_rows($cek) > 0){
            echo "<script>alert('GAGAL DISIMPAN: NIS ( $nis ) sudah terdaftar.'); window.location='admin_master_santri.php';</script>";
            exit;
        }
    }

    $nama = mysqli_real_escape_string($koneksi, $_POST['nama']);
    $jk = $_POST['jk']; 
    $tmp = mysqli_real_escape_string($koneksi, $_POST['tmp_lahir']); 
    $tgl = $_POST['tgl_lahir'];
    $alm = mysqli_real_escape_string($koneksi, $_POST['alamat']); 
    $desa = mysqli_real_escape_string($koneksi, $_POST['desa']);
    $kec = mysqli_real_escape_string($koneksi, $_POST['kecamatan']); 
    $kab = mysqli_real_escape_string($koneksi, $_POST['kab_kota']);
    $prov = mysqli_real_escape_string($koneksi, $_POST['provinsi']); 
    $ayah = mysqli_real_escape_string($koneksi, $_POST['ayah']);
    $hp_ayah = mysqli_real_escape_string($koneksi, $_POST['no_hp_ayah']);
    $ibu = mysqli_real_escape_string($koneksi, $_POST['ibu']);
    $hp_ibu = mysqli_real_escape_string($koneksi, $_POST['no_hp_ibu']);
    $asal = mysqli_real_escape_string($koneksi, $_POST['asal_sekolah']);
    
    $foto_nama = "default.jpg";
    if(!empty($_FILES['foto']['name'])){
        $ext = pathinfo($_FILES['foto']['name'], PATHINFO_EXTENSION);
        $foto_nama = time().'_'.rand(100,999).'.'.$ext;
        move_uploaded_file($_FILES['foto']['tmp_name'], '../gambar_galeri/'.$foto_nama);
    }

    $query = "INSERT INTO santri (
                nis, nama_santri, jenis_kelamin, tempat_lahir, tgl_lahir, 
                alamat, desa, kecamatan, kab_kota, provinsi, 
                nama_ayah, no_hp_ayah, nama_ibu, no_hp_ibu, 
                asal_sekolah, sekolah_saat_ini, foto
              ) VALUES (
                '$nis', '$nama', '$jk', '$tmp', '$tgl', 
                '$alm', '$desa', '$kec', '$kab', '$prov', 
                '$ayah', '$hp_ayah', '$ibu', '$hp_ibu', 
                '$asal', '$sekolah', '$foto_nama'
              )";
    
    if(mysqli_query($koneksi, $query)){
        echo "<script>alert('Data Berhasil Disimpan dengan NIS: $nis'); window.location='admin_master_santri.php';</script>";
    } else {
        echo "<script>alert('Gagal Menyimpan: ".mysqli_error($koneksi)."'); window.location='admin_master_santri.php';</script>";
    }
}

// --- 3. LOGIKA UPDATE DATA ---
if(isset($_POST['update'])){
    $id = $_POST['id'];
    $nama = mysqli_real_escape_string($koneksi, $_POST['nama']);
    $jk = $_POST['jk']; 
    $tmp = mysqli_real_escape_string($koneksi, $_POST['tmp_lahir']); 
    $tgl = $_POST['tgl_lahir'];
    $alm = mysqli_real_escape_string($koneksi, $_POST['alamat']); 
    $desa = mysqli_real_escape_string($koneksi, $_POST['desa']);
    $kec = mysqli_real_escape_string($koneksi, $_POST['kecamatan']); 
    $kab = mysqli_real_escape_string($koneksi, $_POST['kab_kota']);
    $prov = mysqli_real_escape_string($koneksi, $_POST['provinsi']); 
    $ayah = mysqli_real_escape_string($koneksi, $_POST['ayah']);
    $hp_ayah = mysqli_real_escape_string($koneksi, $_POST['no_hp_ayah']);
    $ibu = mysqli_real_escape_string($koneksi, $_POST['ibu']);
    $hp_ibu = mysqli_real_escape_string($koneksi, $_POST['no_hp_ibu']);
    $asal = mysqli_real_escape_string($koneksi, $_POST['asal_sekolah']);
    $sekolah = $_POST['sekolah_saat_ini'];
    $foto_lama = $_POST['foto_lama'];

    if(!empty($_FILES['foto']['name'])){
        $ext = pathinfo($_FILES['foto']['name'], PATHINFO_EXTENSION);
        $foto_nama = time().'_'.rand(100,999).'.'.$ext;
        move_uploaded_file($_FILES['foto']['tmp_name'], '../gambar_galeri/'.$foto_nama);
        if($foto_lama != "default.jpg" && file_exists('../gambar_galeri/'.$foto_lama)){ unlink('../gambar_galeri/'.$foto_lama); }
    } else {
        $foto_nama = $foto_lama;
    }

    $query = "UPDATE santri SET 
                nama_santri='$nama', jenis_kelamin='$jk', tempat_lahir='$tmp', tgl_lahir='$tgl', 
                alamat='$alm', desa='$desa', kecamatan='$kec', kab_kota='$kab', provinsi='$prov', 
                nama_ayah='$ayah', no_hp_ayah='$hp_ayah', nama_ibu='$ibu', no_hp_ibu='$hp_ibu', 
                asal_sekolah='$asal', sekolah_saat_ini='$sekolah', foto='$foto_nama' 
              WHERE id='$id'";
    
    if(mysqli_query($koneksi, $query)){
        echo "<script>alert('Data Berhasil Diupdate'); window.location='admin_master_santri.php';</script>";
    } else {
        echo "<script>alert('Gagal Update: ".mysqli_error($koneksi)."'); window.location='admin_master_santri.php';</script>";
    }
}

// --- 4. LOGIKA HAPUS ---
if(isset($_GET['hapus'])){
    $id = $_GET['hapus'];
    $q = mysqli_query($koneksi, "SELECT foto FROM santri WHERE id='$id'");
    $d = mysqli_fetch_array($q);
    if($d['foto'] != "default.jpg" && file_exists('../gambar_galeri/'.$d['foto'])){ unlink('../gambar_galeri/'.$d['foto']); }
    mysqli_query($koneksi, "DELETE FROM santri WHERE id='$id'");
    echo "<script>window.location='admin_master_santri.php';</script>";
}

// Ambil Tahun Ajaran Aktif
$q_tahun = mysqli_query($koneksi, "SELECT id, tahun, semester FROM tahun_ajaran WHERE status='Aktif' LIMIT 1");
$tahun_aktif = mysqli_fetch_array($q_tahun);
$id_tahun = $tahun_aktif ? $tahun_aktif['id'] : 0;
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <title>Master Data Santri</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css">
    <style>
        .avatar-small { width: 45px; height: 45px; object-fit: cover; border-radius: 50%; border: 2px solid #e9ecef; }
        .avatar-large { width: 120px; height: 120px; object-fit: cover; border-radius: 10px; border: 4px solid #fff; box-shadow: 0 5px 15px rgba(0,0,0,0.1); }
        .table td { vertical-align: middle; }
    </style>
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
            <h2 class="h2 mb-4">Master Data Santri (Keseluruhan)</h2>

            <div class="card shadow-sm border-0 mb-4 bg-light">
                <div class="card-body">
                    <form method="GET" class="row g-2" id="filterForm">
                        <div class="col-md-2">
                            <select name="jk" class="form-select form-select-sm">
                                <option value="">Semua JK</option>
                                <option value="L" <?php if(isset($_GET['jk']) && $_GET['jk']=='L') echo 'selected'; ?>>Laki-laki</option>
                                <option value="P" <?php if(isset($_GET['jk']) && $_GET['jk']=='P') echo 'selected'; ?>>Perempuan</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <select name="sekolah_saat_ini" class="form-select form-select-sm">
                                <option value="">Unit Sekolah</option>
                                <option value="SMKN 2 Ciamis" <?php if(isset($_GET['sekolah_saat_ini']) && $_GET['sekolah_saat_ini']=='SMKN 2 Ciamis') echo 'selected'; ?>>SMKN 2 Ciamis</option>
                                <option value="SMKN 1 Ciamis" <?php if(isset($_GET['sekolah_saat_ini']) && $_GET['sekolah_saat_ini']=='SMKN 1 Ciamis') echo 'selected'; ?>>SMKN 1 Ciamis</option>
                                <option value="SMAN 2 Ciamis" <?php if(isset($_GET['sekolah_saat_ini']) && $_GET['sekolah_saat_ini']=='SMAN 2 Ciamis') echo 'selected'; ?>>SMAN 2 Ciamis</option>
                                <option value="SMAN 1 Ciamis" <?php if(isset($_GET['sekolah_saat_ini']) && $_GET['sekolah_saat_ini']=='SMAN 1 Ciamis') echo 'selected'; ?>>SMAN 1 Ciamis</option>
                                <option value="MAN 2 Ciamis" <?php if(isset($_GET['sekolah_saat_ini']) && $_GET['sekolah_saat_ini']=='MAN 2 Ciamis') echo 'selected'; ?>>MAN 2 Ciamis</option>
                                <option value="SMK Terpadu Al Hasan" <?php if(isset($_GET['sekolah_saat_ini']) && $_GET['sekolah_saat_ini']=='SMK Terpadu Al Hasan') echo 'selected'; ?>>SMK Terpadu Al Hasan</option>
                                <option value="SMP Terpadu Al Hasan" <?php if(isset($_GET['sekolah_saat_ini']) && $_GET['sekolah_saat_ini']=='SMP Terpadu Al Hasan') echo 'selected'; ?>>SMP Terpadu Al Hasan</option>
                                <option value="RA Terpadu Al Hasan" <?php if(isset($_GET['sekolah_saat_ini']) && $_GET['sekolah_saat_ini']=='RA Terpadu Al Hasan') echo 'selected'; ?>>RA Terpadu Al Hasan</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <select name="id_kelas" class="form-select form-select-sm">
                                <option value="">Pilih Kelas...</option>
                                <?php 
                                $qk = mysqli_query($koneksi, "SELECT id, nama_kelas, jenjang FROM kelas ORDER BY jenjang ASC, nama_kelas ASC");
                                while($rk = mysqli_fetch_array($qk)){
                                    $sel_k = (isset($_GET['id_kelas']) && $_GET['id_kelas'] == $rk['id']) ? 'selected' : '';
                                    echo "<option value='".$rk['id']."' $sel_k>".$rk['nama_kelas']."</option>";
                                }
                                ?>
                            </select>
                        </div>
                        <div class="col-md-2"><input type="text" name="kecamatan" class="form-control form-control-sm" placeholder="Kecamatan" value="<?php echo isset($_GET['kecamatan'])?$_GET['kecamatan']:''; ?>"></div>
                        <div class="col-md-2"><input type="text" name="kab_kota" class="form-control form-control-sm" placeholder="Kab/Kota" value="<?php echo isset($_GET['kab_kota'])?$_GET['kab_kota']:''; ?>"></div>
                        <div class="col-md-2">
                            <div class="input-group input-group-sm">
                                <button class="btn btn-primary w-50" type="submit"><i class="fas fa-search"></i> Cari</button>
                                <a href="admin_master_santri.php" class="btn btn-secondary w-50"><i class="fas fa-sync"></i> Reset</a>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <div class="mb-3 d-flex flex-wrap gap-2">
                <button type="button" class="btn btn-success shadow-sm fw-bold" data-bs-toggle="modal" data-bs-target="#modalTambah">
                    <i class="fas fa-user-plus me-2"></i> Input Manual
                </button>
                <button type="button" class="btn btn-primary shadow-sm fw-bold" data-bs-toggle="modal" data-bs-target="#modalImport">
                    <i class="fas fa-file-import me-2"></i> Import Excel
                </button>
                <button type="button" class="btn btn-dark shadow-sm fw-bold text-white" data-bs-toggle="modal" data-bs-target="#modalBulkAlumni">
                    <i class="fas fa-graduation-cap me-2"></i> Kelulusan Massal
                </button>

                <button type="button" id="btnExportData" onclick="exportDataExcel()" class="btn btn-warning shadow-sm fw-bold text-dark ms-auto">
                    <i class="fas fa-file-excel me-2"></i> Export Excel
                </button>
            </div>

            <div class="card shadow border-0">
                <div class="card-body p-3">
                    <div class="table-responsive">
                        <table id="tabelMasterSantri" class="table table-hover table-striped mb-0">
                            <thead class="table-success text-uppercase small">
                                <tr>
                                    <th class="px-3 text-center" width="8%">Foto Profil</th>
                                    <th width="12%">NIS</th>
                                    <th>Nama Santri</th>
                                    <th class="text-center" width="10%">Jenis Kelamin</th>
                                    <th>Unit & Penempatan (Kelas/Kamar)</th>
                                    <th>Alamat Domisili</th>
                                    <th class="text-center" width="12%">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $where = "WHERE 1=1";
                                if(!empty($_GET['jk'])) $where .= " AND s.jenis_kelamin = '$_GET[jk]'";
                                if(!empty($_GET['kecamatan'])) $where .= " AND s.kecamatan LIKE '%$_GET[kecamatan]%'";
                                if(!empty($_GET['kab_kota'])) $where .= " AND s.kab_kota LIKE '%$_GET[kab_kota]%'";
                                if(!empty($_GET['sekolah_saat_ini'])) $where .= " AND s.sekolah_saat_ini = '$_GET[sekolah_saat_ini]'";
                                if(!empty($_GET['id_kelas'])) $where .= " AND pk.id_kelas = '$_GET[id_kelas]'";

                                $query = "SELECT s.*, k.nama_kelas, km.nama_kamar 
                                          FROM santri s 
                                          LEFT JOIN plotting_kelas pk ON s.id = pk.id_santri AND pk.id_tahun = '$id_tahun'
                                          LEFT JOIN kelas k ON pk.id_kelas = k.id 
                                          LEFT JOIN plotting_kamar pkm ON s.id = pkm.id_santri AND pkm.id_tahun = '$id_tahun'
                                          LEFT JOIN kamar km ON pkm.id_kamar = km.id
                                          $where ORDER BY s.nis DESC";
                                $exec = mysqli_query($koneksi, $query);
                                
                                $data_santri = [];

                                while($d = mysqli_fetch_array($exec)){
                                    $data_santri[] = $d; 
                                ?>
                                <tr>
                                    <td class="px-3 text-center">
                                        <?php if($d['foto'] != "default.jpg" && file_exists('../gambar_galeri/'.$d['foto'])) { ?>
                                            <img src="../gambar_galeri/<?php echo $d['foto']; ?>" class="avatar-small">
                                        <?php } else { ?>
                                            <div class="avatar-small bg-secondary d-flex align-items-center justify-content-center text-white fw-bold mx-auto">
                                                <?php echo substr($d['nama_santri'],0,1); ?>
                                            </div>
                                        <?php } ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-success fs-6"><?php echo $d['nis']; ?></span>
                                    </td>
                                    <td>
                                        <div class="fw-bold text-dark fs-6"><?php echo $d['nama_santri']; ?></div>
                                    </td>
                                    <td class="text-center">
                                        <?php if($d['jenis_kelamin'] == 'L') { ?>
                                            <span class="badge bg-primary px-2 py-1"><i class="fas fa-mars me-1"></i> Laki-laki</span>
                                        <?php } else { ?>
                                            <span class="badge bg-danger px-2 py-1"><i class="fas fa-venus me-1"></i> Perempuan</span>
                                        <?php } ?>
                                    </td>
                                    <td>
                                        <div class="fw-bold text-dark"><?php echo $d['sekolah_saat_ini']; ?></div>
                                        <div class="small mt-1">
                                            <span class="badge bg-primary"><i class="fas fa-door-open me-1"></i> Kelas: <?php echo $d['nama_kelas'] ?: 'Belum Diplot'; ?></span>
                                            <span class="badge bg-info text-dark"><i class="fas fa-bed me-1"></i> Kamar: <?php echo $d['nama_kamar'] ?: 'Belum Diplot'; ?></span>
                                        </div>
                                    </td>
                                    <td class="small text-muted">
                                        <i class="fas fa-map-marker-alt me-1"></i> <?php echo $d['kecamatan']; ?>, <?php echo $d['kab_kota']; ?>
                                    </td>
                                    <td class="text-center">
                                        <div class="btn-group btn-group-sm">
                                            <button class="btn btn-info text-white" data-bs-toggle="modal" data-bs-target="#modalDetail<?php echo $d['id']; ?>"><i class="fas fa-eye"></i></button>
                                            <button class="btn btn-warning" data-bs-toggle="modal" data-bs-target="#modalEdit<?php echo $d['id']; ?>"><i class="fas fa-pencil-alt"></i></button>
                                            <button class="btn btn-dark text-white" data-bs-toggle="modal" data-bs-target="#modalAlumni<?php echo $d['id']; ?>" title="Luluskan / Mutasi Keluar"><i class="fas fa-user-graduate"></i></button>
                                            <a href="admin_master_santri.php?hapus=<?php echo $d['id']; ?>" class="btn btn-danger" onclick="return confirm('Yakin hapus data ini?')"><i class="fas fa-trash"></i></a>
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

<div class="modal fade" id="modalBulkAlumni" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title"><i class="fas fa-graduation-cap me-2"></i> Kelulusan / Mutasi Massal Per Kelas</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form action="proses_mutasi_alumni.php" method="POST">
                <div class="modal-body">
                    <div class="alert alert-danger small">
                        <i class="fas fa-exclamation-triangle me-1"></i> <b>PENTING:</b> Fitur ini akan memindahkan <b>SELURUH SANTRI AKTIF</b> di dalam kelas terpilih ke database alumni secara permanen.
                    </div>
                    <div class="mb-3">
                        <label class="fw-bold small">Pilih Kelas yang Lulus / Keluar</label>
                        <select name="id_kelas" class="form-select" required>
                            <option value="">-- Pilih Kelas --</option>
                            <?php 
                            $q_b = mysqli_query($koneksi, "SELECT id, nama_kelas, jenjang FROM kelas ORDER BY jenjang ASC, nama_kelas ASC");
                            while($rb = mysqli_fetch_array($q_b)){
                                echo "<option value='".$rb['id']."'>".$rb['nama_kelas']." (".$rb['jenjang'].")</option>";
                            }
                            ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="fw-bold small">Status Keluar</label>
                        <select name="status_keluar" class="form-select" required>
                            <option value="Lulus">Lulus Normal</option>
                            <option value="Pindah">Pindah Pesantren/Sekolah</option>
                            <option value="Berhenti">Berhenti / Dikeluarkan</option>
                        </select>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="fw-bold small">Tahun Angkatan Keluar</label>
                            <input type="number" name="tahun_angkatan" class="form-control" value="<?php echo date('Y'); ?>" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="fw-bold small">Tingkat Kelulusan</label>
                            <select name="tingkat" class="form-select" required>
                                <option value="Ibtida">Ibtida (Dasar)</option>
                                <option value="Tsanawi">Tsanawi (Lanjutan)</option>
                            </select>
                        </div>
                    </div>
                    <div class="mb-2">
                        <label class="fw-bold small">Tanggal Keluar / Lulus</label>
                        <input type="date" name="tgl_keluar" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" name="bulk_mutasi" class="btn btn-danger fw-bold" onclick="return confirm('APAKAH ANDA YAKIN? Tindakan meluluskan satu kelas sekaligus ini tidak dapat dibatalkan.')">Eksekusi Kelulusan Massal</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php foreach($data_santri as $d): ?>
<div class="modal fade" id="modalDetail<?php echo $d['id']; ?>" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title"><i class="fas fa-id-card me-2"></i> Detail Santri</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row">
                    <div class="col-md-4 text-center border-end">
                        <?php if($d['foto']!="default.jpg" && file_exists('../gambar_galeri/'.$d['foto'])){ ?>
                            <img src="../gambar_galeri/<?php echo $d['foto']; ?>" class="avatar-large mb-3">
                        <?php } else { ?>
                            <div class="avatar-large bg-secondary mx-auto mb-3 d-flex align-items-center justify-content-center text-white h1"><i class="fas fa-user"></i></div>
                        <?php } ?>
                        <h5 class="fw-bold mb-1"><?php echo $d['nama_santri']; ?></h5>
                        <div class="badge bg-success mb-3"><?php echo $d['nis']; ?></div>
                    </div>
                    <div class="col-md-8">
                        <table class="table table-sm table-borderless">
                            <tr><td width="30%" class="text-muted">Tempat, Tgl Lahir</td><td class="fw-bold">: <?php echo $d['tempat_lahir'].', '.date('d-m-Y', strtotime($d['tgl_lahir'])); ?></td></tr>
                            <tr><td class="text-muted">Jenis Kelamin</td><td class="fw-bold">: <?php echo ($d['jenis_kelamin']=='L')?'Laki-laki':'Perempuan'; ?></td></tr>
                            <tr><td class="text-muted">Alamat Lengkap</td><td>: <?php echo $d['alamat']; ?></td></tr>
                            <tr><td class="text-muted">Wilayah</td><td>: Ds. <?php echo $d['desa']; ?>, Kec. <?php echo $d['kecamatan']; ?>, <?php echo $d['kab_kota']; ?>, <?php echo $d['provinsi']; ?></td></tr>
                            <tr><td colspan="2"><hr class="my-1"></td></tr>
                            <tr><td class="text-muted">Ayah</td><td>: <?php echo $d['nama_ayah']; ?> <small class="text-success ms-2"><i class="fab fa-whatsapp"></i> <?php echo $d['no_hp_ayah'] ?: '-'; ?></small></td></tr>
                            <tr><td class="text-muted">Ibu</td><td>: <?php echo $d['nama_ibu']; ?> <small class="text-success ms-2"><i class="fab fa-whatsapp"></i> <?php echo $d['no_hp_ibu'] ?: '-'; ?></small></td></tr>
                            <tr><td class="text-muted">Sekolah Asal</td><td>: <?php echo $d['asal_sekolah']; ?></td></tr>
                            <tr><td class="text-muted">Unit Sekarang</td><td>: <span class="text-primary fw-bold"><?php echo $d['sekolah_saat_ini']; ?></span></td></tr>
                            <tr><td class="text-muted">Kelas Aktif</td><td>: <span class="badge bg-primary"><?php echo $d['nama_kelas'] ?: 'Belum Diplot'; ?></span></td></tr>
                            <tr><td class="text-muted">Kamar Aktif</td><td>: <span class="badge bg-info text-dark"><?php echo $d['nama_kamar'] ?: 'Belum Diplot'; ?></span></td></tr>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="modalEdit<?php echo $d['id']; ?>" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header bg-warning">
                <h5 class="modal-title"><i class="fas fa-edit me-2"></i> Edit Data Santri</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" enctype="multipart/form-data">
                <div class="modal-body">
                    <input type="hidden" name="id" value="<?php echo $d['id']; ?>">
                    <input type="hidden" name="foto_lama" value="<?php echo $d['foto']; ?>">
                    
                    <div class="row g-3">
                        <div class="col-md-6">
                            <div class="mb-2"><label class="small fw-bold">Nama Lengkap</label><input type="text" name="nama" class="form-control" value="<?php echo $d['nama_santri']; ?>" required></div>
                            <div class="row">
                                <div class="col-6 mb-2"><label class="small fw-bold">Tempat Lahir</label><input type="text" name="tmp_lahir" class="form-control" value="<?php echo $d['tempat_lahir']; ?>"></div>
                                <div class="col-6 mb-2"><label class="small fw-bold">Tgl Lahir</label><input type="date" name="tgl_lahir" class="form-control" value="<?php echo $d['tgl_lahir']; ?>"></div>
                            </div>
                            <div class="mb-2">
                                <label class="small fw-bold">Jenis Kelamin</label>
                                <select name="jk" class="form-select">
                                    <option value="L" <?php echo ($d['jenis_kelamin']=='L')?'selected':''; ?>>Laki-laki</option>
                                    <option value="P" <?php echo ($d['jenis_kelamin']=='P')?'selected':''; ?>>Perempuan</option>
                                </select>
                            </div>
                            <div class="mb-2">
                                <label class="small fw-bold">Ganti Foto (Opsional)</label>
                                <input type="file" name="foto" class="form-control">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-2"><label class="small fw-bold">Alamat</label><input type="text" name="alamat" class="form-control" value="<?php echo $d['alamat']; ?>"></div>
                            <div class="row">
                                <div class="col-6 mb-2"><label class="small fw-bold">Desa</label><input type="text" name="desa" class="form-control" value="<?php echo $d['desa']; ?>"></div>
                                <div class="col-6 mb-2"><label class="small fw-bold">Kecamatan</label><input type="text" name="kecamatan" class="form-control" value="<?php echo $d['kecamatan']; ?>"></div>
                                <div class="col-6 mb-2"><label class="small fw-bold">Kab/Kota</label><input type="text" name="kab_kota" class="form-control" value="<?php echo $d['kab_kota']; ?>"></div>
                                <div class="col-6 mb-2"><label class="small fw-bold">Provinsi</label><input type="text" name="provinsi" class="form-control" value="<?php echo $d['provinsi']; ?>"></div>
                            </div>
                            <div class="row">
                                <div class="col-6 mb-2">
                                    <label class="small fw-bold">Nama Ayah</label>
                                    <input type="text" name="ayah" class="form-control" value="<?php echo $d['nama_ayah']; ?>">
                                    <input type="text" name="no_hp_ayah" class="form-control form-control-sm mt-1" placeholder="No HP Ayah" value="<?php echo $d['no_hp_ayah'] ?? ''; ?>">
                                </div>
                                <div class="col-6 mb-2">
                                    <label class="small fw-bold">Nama Ibu</label>
                                    <input type="text" name="ibu" class="form-control" value="<?php echo $d['nama_ibu']; ?>">
                                    <input type="text" name="no_hp_ibu" class="form-control form-control-sm mt-1" placeholder="No HP Ibu" value="<?php echo $d['no_hp_ibu'] ?? ''; ?>">
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-6 mb-2"><label class="small fw-bold">Asal Sekolah</label><input type="text" name="asal_sekolah" class="form-control" value="<?php echo $d['asal_sekolah']; ?>"></div>
                                <div class="col-6 mb-2">
                                    <label class="small fw-bold">Unit Sekarang</label>
                                    <select name="sekolah_saat_ini" class="form-select">
                                        <option value="SMKN 2 Ciamis" <?php echo ($d['sekolah_saat_ini']=='SMKN 2 Ciamis')?'selected':''; ?>>SMKN 2 Ciamis</option>
                                        <option value="SMKN 1 Ciamis" <?php echo ($d['sekolah_saat_ini']=='SMKN 1 Ciamis')?'selected':''; ?>>SMKN 1 Ciamis</option>
                                        <option value="SMAN 2 Ciamis" <?php echo ($d['sekolah_saat_ini']=='SMAN 2 Ciamis')?'selected':''; ?>>SMAN 2 Ciamis</option>
                                        <option value="SMAN 1 Ciamis" <?php echo ($d['sekolah_saat_ini']=='SMAN 1 Ciamis')?'selected':''; ?>>SMAN 1 Ciamis</option>
                                        <option value="MAN 2 Ciamis" <?php echo ($d['sekolah_saat_ini']=='MAN 2 Ciamis')?'selected':''; ?>>MAN 2 Ciamis</option>
                                        <option value="SMK Terpadu Al Hasan" <?php echo ($d['sekolah_saat_ini']=='SMK Terpadu Al Hasan')?'selected':''; ?>>SMK Terpadu Al Hasan</option>
                                        <option value="SMP Terpadu Al Hasan" <?php echo ($d['sekolah_saat_ini']=='SMP Terpadu Al Hasan')?'selected':''; ?>>SMP Terpadu Al Hasan</option>
                                        <option value="RA Terpadu Al Hasan" <?php echo ($d['sekolah_saat_ini']=='RA Terpadu Al Hasan')?'selected':''; ?>>RA Terpadu Al Hasan</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" name="update" class="btn btn-warning">Simpan Perubahan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="modalAlumni<?php echo $d['id']; ?>" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title"><i class="fas fa-user-graduate me-2"></i> Luluskan / Mutasi Keluar</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form action="proses_mutasi_alumni.php" method="POST">
                <div class="modal-body">
                    <input type="hidden" name="id_santri" value="<?php echo $d['id']; ?>">
                    <div class="alert alert-warning small">
                        <i class="fas fa-exclamation-triangle me-1"></i> Data <b><?php echo $d['nama_santri']; ?></b> akan dipindahkan dari tabel aktif ke tabel Alumni.
                    </div>
                    <div class="mb-3">
                        <label class="fw-bold small">Status Keluar</label>
                        <select name="status_keluar" class="form-select" required>
                            <option value="Lulus">Lulus Normal</option>
                            <option value="Pindah">Pindah Pesantren/Sekolah</option>
                            <option value="Berhenti">Berhenti / Dikeluarkan</option>
                        </select>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="fw-bold small">Tahun Angkatan Keluar</label>
                            <input type="number" name="tahun_angkatan" class="form-control" value="<?php echo date('Y'); ?>" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="fw-bold small">Tingkat Kelulusan</label>
                            <select name="tingkat" class="form-select" required>
                                <option value="Ibtida">Ibtida (Dasar)</option>
                                <option value="Tsanawi">Tsanawi (Lanjutan)</option>
                            </select>
                        </div>
                    </div>
                    <div class="mb-2">
                        <label class="fw-bold small">Tanggal Keluar / Lulus</label>
                        <input type="date" name="tgl_keluar" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" name="mutasi" class="btn btn-dark fw-bold">Pindahkan Data</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endforeach; ?>

<div class="modal fade" id="modalTambah" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title">Input Santri Baru</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form method="POST" enctype="multipart/form-data">
                    <div class="card bg-light border-0 mb-3">
                        <div class="card-body">
                            <h6 class="fw-bold mb-2">Pengaturan NIS (Nomor Induk Santri)</h6>
                            <div class="d-flex gap-4 mb-2">
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="mode_nis" id="nis_auto" value="auto" checked onchange="toggleNis()">
                                    <label class="form-check-label fw-bold" for="nis_auto">Otomatis Ter-generate</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="mode_nis" id="nis_manual" value="manual" onchange="toggleNis()">
                                    <label class="form-check-label fw-bold" for="nis_manual">Input Manual</label>
                                </div>
                            </div>
                            <div id="view_nis_auto">
                                <div class="alert alert-success py-2 mb-0 small">
                                    <i class="fas fa-info-circle me-1"></i> NIS akan di-generate otomatis <b>setelah data disimpan</b> menggunakan format: <b>Tahun + Kode Unit (01/02) + Urutan</b>.
                                </div>
                            </div>
                            <div id="view_nis_manual" style="display:none;">
                                <div class="input-group">
                                    <span class="input-group-text bg-warning text-dark">Input Manual</span>
                                    <input type="number" name="nis_manual" class="form-control" placeholder="Masukkan NIS Anda di sini...">
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <div class="mb-2"><label class="small fw-bold">Nama Lengkap</label><input type="text" name="nama" class="form-control" required></div>
                            <div class="row">
                                <div class="col-6 mb-2"><label class="small fw-bold">Tempat Lahir</label><input type="text" name="tmp_lahir" class="form-control"></div>
                                <div class="col-6 mb-2"><label class="small fw-bold">Tgl Lahir</label><input type="date" name="tgl_lahir" class="form-control" required></div>
                            </div>
                            <div class="mb-2"><label class="small fw-bold">Jenis Kelamin</label><select name="jk" class="form-select"><option value="L">Laki-laki</option><option value="P">Perempuan</option></select></div>
                            <div class="mb-2"><label class="small fw-bold">Foto Santri</label><input type="file" name="foto" class="form-control"></div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-2"><label class="small fw-bold">Alamat</label><input type="text" name="alamat" class="form-control"></div>
                            <div class="row">
                                <div class="col-6 mb-2"><label class="small fw-bold">Desa</label><input type="text" name="desa" class="form-control"></div>
                                <div class="col-6 mb-2"><label class="small fw-bold">Kecamatan</label><input type="text" name="kecamatan" class="form-control"></div>
                                <div class="col-6 mb-2"><label class="small fw-bold">Kab/Kota</label><input type="text" name="kab_kota" class="form-control"></div>
                                <div class="col-6 mb-2"><label class="small fw-bold">Provinsi</label><input type="text" name="provinsi" class="form-control"></div>
                            </div>
                            <div class="row">
                                <div class="col-6 mb-2"><label class="small fw-bold">Nama Ayah</label><input type="text" name="ayah" class="form-control"><input type="text" name="no_hp_ayah" class="form-control form-control-sm mt-1" placeholder="No HP Ayah"></div>
                                <div class="col-6 mb-2"><label class="small fw-bold">Nama Ibu</label><input type="text" name="ibu" class="form-control"><input type="text" name="no_hp_ibu" class="form-control form-control-sm mt-1" placeholder="No HP Ibu"></div>
                            </div>
                            <div class="row">
                                <div class="col-6 mb-2"><label class="small fw-bold">Asal Sekolah</label><input type="text" name="asal_sekolah" class="form-control"></div>
                                <div class="col-6 mb-2">
                                    <label class="small fw-bold text-danger">Unit Sekarang (Sangat Penting)*</label>
                                    <select name="sekolah_saat_ini" class="form-select border-danger" required>
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
                        </div>
                    </div>
                    <div class="mt-4 text-end"><button type="submit" name="tambah" class="btn btn-success px-4">Simpan Data Santri</button></div>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="modalImport" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="fas fa-file-import me-2"></i> Import Data Massal (Excel)</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="formImportExcel">
                <div class="modal-body">
                    <div class="alert alert-info small">
                        <i class="fas fa-info-circle me-1"></i> <strong>Aturan Import:</strong><br>
                        1. File harus berformat <strong>.XLSX</strong>.<br>
                        2. Urutan kolom minimal wajib: <b>Kolom A (NIS)</b> dan <b>Kolom B (Nama)</b>.
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Pilih File Excel (.xlsx)</label>
                        <input type="file" id="file_excel" class="form-control" accept=".xlsx" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" id="btnProsesImport" class="btn btn-primary fw-bold">Upload & Proses Data</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
<script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>

<script>
    $(document).ready(function() {
        $('#tabelMasterSantri').DataTable({
            "stateSave": true,
            "lengthMenu": [[25, 50, 100, -1], [25, 50, 100, "Semua"]],
            "pageLength": 25,
            "language": {
                "lengthMenu": "Tampilkan _MENU_ baris data",
                "zeroRecords": "Tidak ada data santri ditemukan.",
                "info": "Menampilkan _START_ sampai _END_ dari total _TOTAL_ santri",
                "infoEmpty": "Data kosong",
                "infoFiltered": "(difilter dari _MAX_ total data)",
                "search": "Cari Cepat:",
                "paginate": { "first": "Awal", "last": "Akhir", "next": "Selanjutnya", "previous": "Sebelumnya" }
            }
        });
    });

    function toggleNis() {
        var auto = document.getElementById('nis_auto');
        var viewAuto = document.getElementById('view_nis_auto');
        var viewManual = document.getElementById('view_nis_manual');
        if (auto.checked) { viewAuto.style.display = 'block'; viewManual.style.display = 'none'; } 
        else { viewAuto.style.display = 'none'; viewManual.style.display = 'block'; }
    }

    // ===============================================
    // FUNGSI EKSPOR EXCEL CLIENT-SIDE MENGGUNAKAN SHEETJS (AJAX)
    // ===============================================
    function exportDataExcel() {
        const btn = document.getElementById('btnExportData');
        const originalText = btn.innerHTML;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i> Merakit File...';
        btn.disabled = true;

        // Kumpulkan data filter dari form HTML
        const formFilter = document.getElementById('filterForm');
        const formData = new FormData(formFilter);
        const searchParams = new URLSearchParams(formData);

        // Buat permintaan AJAX ke file pengambil data mentah JSON
        fetch('get_santri_json.php?' + searchParams.toString())
        .then(res => res.json())
        .then(data => {
            if(data.length === 0) {
                alert('Tidak ada data yang cocok dengan filter untuk diekspor.');
                btn.innerHTML = originalText;
                btn.disabled = false;
                return;
            }
            
            // Konversi JSON ke file Excel menggunakan SheetJS
            const ws = XLSX.utils.json_to_sheet(data);
            const wb = XLSX.utils.book_new();
            XLSX.utils.book_append_sheet(wb, ws, "Master Data Santri");
            
            const date = new Date();
            const fileName = "Master_Santri_AlHasan_" + date.getFullYear() + "-" + (date.getMonth()+1) + "-" + date.getDate() + ".xlsx";
            
            XLSX.writeFile(wb, fileName);
            
            btn.innerHTML = originalText;
            btn.disabled = false;
        })
        .catch(err => {
            alert('Gagal mengekspor data. Pastikan file get_santri_json.php ada di server.');
            console.error(err);
            btn.innerHTML = originalText;
            btn.disabled = false;
        });
    }

    document.getElementById('formImportExcel').addEventListener('submit', function(e) {
        e.preventDefault();
        const fileInput = document.getElementById('file_excel');
        const file = fileInput.files[0];
        if (!file) return;

        const btnProses = document.getElementById('btnProsesImport');
        btnProses.disabled = true;
        btnProses.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Memproses...';

        const reader = new FileReader();
        reader.onload = function(e) {
            const data = new Uint8Array(e.target.result);
            const workbook = XLSX.read(data, { type: 'array' });
            const firstSheetName = workbook.SheetNames[0];
            const worksheet = workbook.Sheets[firstSheetName];
            const jsonRows = XLSX.utils.sheet_to_json(worksheet, { header: 1 });
            
            const formData = new FormData();
            formData.append('payload', JSON.stringify(jsonRows));

            fetch('proses_import_santri.php', { method: 'POST', body: formData })
            .then(response => response.text())
            .then(result => { alert(result); window.location.reload(); })
            .catch(error => { alert('Error: ' + error); btnProses.disabled = false; btnProses.innerHTML = 'Upload & Proses Data'; });
        };
        reader.readAsArrayBuffer(file);
    });
</script>
</body>
</html>