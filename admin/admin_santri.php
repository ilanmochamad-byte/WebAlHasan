<?php
session_start();
if(!isset($_SESSION['status']) || $_SESSION['status'] != "login"){ 
    header("Location: ../admin_login.php"); 
    exit; 
}
include '../koneksi.php';

// Ambil Tahun Ajaran Aktif (Diletakkan di luar agar bisa dipakai oleh seluruh fungsi)
$q_tahun = mysqli_query($koneksi, "SELECT id FROM tahun_ajaran WHERE status='Aktif' LIMIT 1");
$d_tahun = mysqli_fetch_assoc($q_tahun);
$id_tahun = $d_tahun ? $d_tahun['id'] : 0;

// =========================================================
// 1. ENDPOINT AJAX (UNTUK MENYIMPAN OTOMATIS)
// =========================================================

// A. Simpan Individual (1 per 1)
if(isset($_POST['action']) && $_POST['action'] == 'update_plot'){
    header('Content-Type: application/json');
    $id_santri = mysqli_real_escape_string($koneksi, $_POST['id_santri']);
    $tipe = mysqli_real_escape_string($koneksi, $_POST['tipe']); 
    $id_val = mysqli_real_escape_string($koneksi, $_POST['id_val']);

    if($id_tahun == 0){ echo json_encode(['status'=>'error', 'msg'=>'Tahun Ajaran Aktif belum diatur!']); exit; }

    // --- LOGIKA CEK KAPASITAS KAMAR (INDIVIDU) ---
    if($tipe == 'kamar' && !empty($id_val)){
        $q_k = mysqli_query($koneksi, "SELECT kapasitas, nama_kamar FROM kamar WHERE id='$id_val'");
        $d_k = mysqli_fetch_assoc($q_k);
        
        $q_t = mysqli_query($koneksi, "SELECT COUNT(id) as terisi FROM plotting_kamar WHERE id_kamar='$id_val' AND id_tahun='$id_tahun'");
        $d_t = mysqli_fetch_assoc($q_t);

        // Pastikan santri ini belum ada di kamar tersebut agar tidak salah hitung kapasitas
        $q_cek = mysqli_query($koneksi, "SELECT id FROM plotting_kamar WHERE id_santri='$id_santri' AND id_kamar='$id_val' AND id_tahun='$id_tahun'");
        if(mysqli_num_rows($q_cek) == 0 && $d_t['terisi'] >= $d_k['kapasitas']){
            echo json_encode(['status'=>'error', 'msg'=>"GAGAL: Kamar ".$d_k['nama_kamar']." sudah PENUH! (Maks: ".$d_k['kapasitas']." orang)"]); 
            exit;
        }
    }

    if($tipe == 'kelas'){
        mysqli_query($koneksi, "DELETE FROM plotting_kelas WHERE id_santri='$id_santri' AND id_tahun='$id_tahun'");
        if(!empty($id_val)){ mysqli_query($koneksi, "INSERT INTO plotting_kelas (id_santri, id_kelas, id_tahun) VALUES ('$id_santri', '$id_val', '$id_tahun')"); }
    } else if($tipe == 'kamar'){
        mysqli_query($koneksi, "DELETE FROM plotting_kamar WHERE id_santri='$id_santri' AND id_tahun='$id_tahun'");
        if(!empty($id_val)){ mysqli_query($koneksi, "INSERT INTO plotting_kamar (id_santri, id_kamar, id_tahun) VALUES ('$id_santri', '$id_val', '$id_tahun')"); }
    }
    echo json_encode(['status'=>'success']); exit;
}

// B. Simpan Massal (Bulk Action)
if(isset($_POST['action']) && $_POST['action'] == 'bulk_update_plot'){
    header('Content-Type: application/json');
    $id_santris = json_decode($_POST['id_santris'], true);
    $tipe = mysqli_real_escape_string($koneksi, $_POST['tipe']); 
    $id_val = mysqli_real_escape_string($koneksi, $_POST['id_val']);

    if($id_tahun == 0){ echo json_encode(['status'=>'error', 'msg'=>'Tahun Ajaran Aktif belum diatur!']); exit; }
    if(!is_array($id_santris) || empty($id_santris)){ echo json_encode(['status'=>'error', 'msg'=>'Tidak ada santri yang dipilih!']); exit; }

    // --- LOGIKA CEK KAPASITAS KAMAR (MASSAL) ---
    if($tipe == 'kamar' && !empty($id_val)){
        $q_k = mysqli_query($koneksi, "SELECT kapasitas, nama_kamar FROM kamar WHERE id='$id_val'");
        $d_k = mysqli_fetch_assoc($q_k);
        
        $q_t = mysqli_query($koneksi, "SELECT COUNT(id) as terisi FROM plotting_kamar WHERE id_kamar='$id_val' AND id_tahun='$id_tahun'");
        $d_t = mysqli_fetch_assoc($q_t);

        // Hitung berapa santri baru (dari seleksi massal) yang belum ada di kamar ini
        $jumlah_baru = 0;
        foreach($id_santris as $id_s){
            $s_id = mysqli_real_escape_string($koneksi, $id_s);
            $q_cek = mysqli_query($koneksi, "SELECT id FROM plotting_kamar WHERE id_santri='$s_id' AND id_kamar='$id_val' AND id_tahun='$id_tahun'");
            if(mysqli_num_rows($q_cek) == 0){ $jumlah_baru++; }
        }

        // Cek total isi vs kapasitas
        if(($d_t['terisi'] + $jumlah_baru) > $d_k['kapasitas']){
            $sisa_slot = $d_k['kapasitas'] - $d_t['terisi'];
            echo json_encode(['status'=>'error', 'msg'=>"GAGAL: Kamar ".$d_k['nama_kamar']." tidak cukup!\nSisa slot: $sisa_slot orang.\nAnda mencoba memasukkan: $jumlah_baru orang."]); 
            exit;
        }
    }

    // Looping penyimpanan untuk semua santri yang di-checklist
    foreach($id_santris as $id_santri){
        $id_s = mysqli_real_escape_string($koneksi, $id_santri);
        if($tipe == 'kelas'){
            mysqli_query($koneksi, "DELETE FROM plotting_kelas WHERE id_santri='$id_s' AND id_tahun='$id_tahun'");
            if(!empty($id_val)){ mysqli_query($koneksi, "INSERT INTO plotting_kelas (id_santri, id_kelas, id_tahun) VALUES ('$id_s', '$id_val', '$id_tahun')"); }
        } else if($tipe == 'kamar'){
            mysqli_query($koneksi, "DELETE FROM plotting_kamar WHERE id_santri='$id_s' AND id_tahun='$id_tahun'");
            if(!empty($id_val)){ mysqli_query($koneksi, "INSERT INTO plotting_kamar (id_santri, id_kamar, id_tahun) VALUES ('$id_s', '$id_val', '$id_tahun')"); }
        }
    }
    echo json_encode(['status'=>'success']); exit;
}
// =========================================================

// Ambil data Master Kelas
$list_kelas = [];
$qK = mysqli_query($koneksi, "SELECT * FROM kelas ORDER BY jenjang, nama_kelas ASC");
while($k = mysqli_fetch_assoc($qK)) { $list_kelas[] = $k; }

// Ambil data Master Kamar beserta jumlah yang TERISI saat ini (Subquery)
$list_kamar = [];
$qKm = mysqli_query($koneksi, "
    SELECT km.id, km.nama_kamar, km.kapasitas,
           (SELECT COUNT(id) FROM plotting_kamar pk WHERE pk.id_kamar = km.id AND pk.id_tahun = '$id_tahun') as terisi
    FROM kamar km
    ORDER BY km.nama_kamar ASC
");
while($km = mysqli_fetch_assoc($qKm)) { $list_kamar[] = $km; }
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <title>Penempatan Santri - Admin</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css">
    <style>
        .card-filter { background-color: #f8f9fa; border: 1px solid #e9ecef; }
        .avatar-tiny { width: 35px; height: 35px; object-fit: cover; border-radius: 50%; border: 2px solid #fff; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .select-inline {
            background-color: #f8f9fa; border: 1px solid #dee2e6; font-size: 0.85rem; font-weight: 600; cursor: pointer; transition: all 0.2s;
        }
        .select-inline:hover, .select-inline:focus {
            background-color: #fff; border-color: #0d6efd; box-shadow: 0 0 0 0.25rem rgba(13, 110, 253, 0.25);
        }
        .is-saving { opacity: 0.5; pointer-events: none; }
        
        /* Floating Bar Aksi Massal */
        #bulkActionBar {
            display: none; position: fixed; bottom: 20px; left: 50%; transform: translateX(-50%);
            z-index: 1050; background: #fff; padding: 15px 25px; border-radius: 50px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2); border: 2px solid #0d6efd;
            animation: slideUp 0.3s ease-out;
        }
        @keyframes slideUp { from { bottom: -100px; opacity: 0; } to { bottom: 20px; opacity: 1; } }
    </style>
</head>
<body class="bg-light">

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
            
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h2 class="h3 fw-bold mb-0">Penempatan Kelas & Asrama</h2>
                    <p class="text-muted small mb-0"><i class="fas fa-magic text-warning me-1"></i> Centang kotak di samping nama santri untuk menempatkan kelas/kamar secara massal.</p>
                </div>
            </div>
            
            <div class="card card-filter shadow-sm mb-4 rounded-4 border-0">
                <div class="card-body">
                    <form method="GET" class="row g-2 align-items-end">
                        <div class="col-md-2">
                            <label class="small fw-bold mb-1">Status Penempatan</label>
                            <select name="filter_status" class="form-select form-select-sm">
                                <option value="">Semua Status</option>
                                <option value="no_class" <?php echo (isset($_GET['filter_status']) && $_GET['filter_status']=='no_class') ? 'selected' : ''; ?>>Belum Punya Kelas</option>
                                <option value="no_room" <?php echo (isset($_GET['filter_status']) && $_GET['filter_status']=='no_room') ? 'selected' : ''; ?>>Belum Punya Kamar</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="small fw-bold mb-1">Unit Sekolah</label>
                            <select name="sekolah" class="form-select form-select-sm">
                                <option value="">Semua Unit</option>
                                <option value="SMKN 2 Ciamis" <?php echo (isset($_GET['sekolah']) && $_GET['sekolah']=='SMKN 2 Ciamis') ? 'selected' : ''; ?>>SMKN 2 Ciamis</option>
                                <option value="SMKN 1 Ciamis" <?php echo (isset($_GET['sekolah']) && $_GET['sekolah']=='SMKN 1 Ciamis') ? 'selected' : ''; ?>>SMKN 1 Ciamis</option>
                                <option value="SMAN 2 Ciamis" <?php echo (isset($_GET['sekolah']) && $_GET['sekolah']=='SMAN 2 Ciamis') ? 'selected' : ''; ?>>SMAN 2 Ciamis</option>
                                <option value="SMAN 1 Ciamis" <?php echo (isset($_GET['sekolah']) && $_GET['sekolah']=='SMAN 1 Ciamis') ? 'selected' : ''; ?>>SMAN 1 Ciamis</option>
                                <option value="MAN 2 Ciamis" <?php echo (isset($_GET['sekolah']) && $_GET['sekolah']=='MAN 2 Ciamis') ? 'selected' : ''; ?>>MAN 2 Ciamis</option>
                                <option value="SMK Terpadu Al Hasan" <?php echo (isset($_GET['sekolah']) && $_GET['sekolah']=='SMK Terpadu Al Hasan') ? 'selected' : ''; ?>>SMK Terpadu Al Hasan</option>
                                <option value="SMP Terpadu Al Hasan" <?php echo (isset($_GET['sekolah']) && $_GET['sekolah']=='SMP Terpadu Al Hasan') ? 'selected' : ''; ?>>SMP Terpadu Al Hasan</option>
                                <option value="RA Terpadu Al Hasan" <?php echo (isset($_GET['sekolah']) && $_GET['sekolah']=='RA Terpadu Al Hasan') ? 'selected' : ''; ?>>RA Terpadu Al Hasan</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="small fw-bold mb-1">Jenis Kelamin</label>
                            <select name="jk" class="form-select form-select-sm">
                                <option value="">Semua</option>
                                <option value="L" <?php echo (isset($_GET['jk']) && $_GET['jk']=='L') ? 'selected' : ''; ?>>Laki-laki</option>
                                <option value="P" <?php echo (isset($_GET['jk']) && $_GET['jk']=='P') ? 'selected' : ''; ?>>Perempuan</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="small fw-bold mb-1">Pencarian Cepat</label>
                            <div class="input-group input-group-sm">
                                <span class="input-group-text bg-white"><i class="fas fa-search"></i></span>
                                <input type="text" name="cari" class="form-control" placeholder="Ketik Nama/NIS..." value="<?php echo isset($_GET['cari']) ? $_GET['cari'] : ''; ?>">
                            </div>
                        </div>
                        <div class="col-md-2">
                            <button type="submit" class="btn btn-primary btn-sm w-100 fw-bold">Filter Data</button>
                        </div>
                        <div class="col-md-1">
                            <a href="admin_santri.php" class="btn btn-secondary btn-sm w-100"><i class="fas fa-sync"></i></a>
                        </div>
                    </form>
                </div>
            </div>

            <div class="card shadow border-0 rounded-4 overflow-hidden mb-5">
                <div class="table-responsive" style="min-height: 400px;">
                    <table id="tabelPenempatan" class="table table-hover align-middle mb-0" style="font-size: 0.9rem;">
                        <thead class="table-dark text-uppercase small">
                            <tr>
                                <th width="5%" class="text-center">
                                    <input class="form-check-input" type="checkbox" id="checkAll" style="transform: scale(1.2); cursor: pointer;">
                                </th>
                                <th class="ps-2">Santri</th>
                                <th>Unit Sekolah</th>
                                <th width="25%"><i class="fas fa-chalkboard text-warning me-1"></i> Kelas Saat Ini</th>
                                <th width="25%"><i class="fas fa-bed text-warning me-1"></i> Kamar Saat Ini</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $where = "WHERE 1=1";
                            if(!empty($_GET['jk'])) $where .= " AND s.jenis_kelamin = '$_GET[jk]'";
                            if(!empty($_GET['sekolah'])) $where .= " AND s.sekolah_saat_ini = '$_GET[sekolah]'";
                            if(!empty($_GET['cari'])) $where .= " AND (s.nama_santri LIKE '%$_GET[cari]%' OR s.nis LIKE '%$_GET[cari]%')";
                            if(!empty($_GET['filter_status'])){
                                if($_GET['filter_status'] == 'no_class') $where .= " AND k.nama_kelas IS NULL";
                                if($_GET['filter_status'] == 'no_room') $where .= " AND km.nama_kamar IS NULL";
                            }

                            // Left Join Database
                            $query = "SELECT s.id, s.nis, s.nama_santri, s.jenis_kelamin, s.sekolah_saat_ini, s.foto, 
                                             k.id as id_kelas_aktif, km.id as id_kamar_aktif
                                      FROM santri s
                                      LEFT JOIN plotting_kelas pk ON s.id = pk.id_santri AND pk.id_tahun='$id_tahun'
                                      LEFT JOIN kelas k ON pk.id_kelas = k.id
                                      LEFT JOIN plotting_kamar pkm ON s.id = pkm.id_santri AND pkm.id_tahun='$id_tahun'
                                      LEFT JOIN kamar km ON pkm.id_kamar = km.id
                                      $where
                                      GROUP BY s.id
                                      ORDER BY s.nama_santri ASC";
                            
                            $exec = mysqli_query($koneksi, $query);

                            if(mysqli_num_rows($exec) > 0){
                                while($d = mysqli_fetch_array($exec)){
                            ?>
                            <tr>
                                <td class="text-center">
                                    <input class="form-check-input check-santri border-secondary" type="checkbox" value="<?php echo $d['id']; ?>" style="transform: scale(1.3); cursor: pointer;">
                                </td>
                                <td class="ps-2">
                                    <div class="d-flex align-items-center">
                                        <?php if($d['foto'] != "default.jpg" && file_exists('../gambar_galeri/'.$d['foto'])) { ?>
                                            <img src="../gambar_galeri/<?php echo $d['foto']; ?>" class="avatar-tiny me-3">
                                        <?php } else { ?>
                                            <div class="avatar-tiny bg-secondary d-flex align-items-center justify-content-center text-white small fw-bold me-3">
                                                <?php echo substr($d['nama_santri'],0,1); ?>
                                            </div>
                                        <?php } ?>
                                        <div>
                                            <div class="fw-bold text-dark mb-0"><?php echo $d['nama_santri']; ?></div>
                                            <div class="text-muted" style="font-size: 0.75rem;"><?php echo $d['nis']; ?> • <?php echo ($d['jenis_kelamin']=='L')?'Laki-laki':'Perempuan'; ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td><span class="badge bg-light text-dark border"><?php echo $d['sekolah_saat_ini']; ?></span></td>
                                
                                <td>
                                    <select class="form-select select-inline ajax-plot" data-id="<?php echo $d['id']; ?>" data-tipe="kelas">
                                        <option value="" class="text-danger fw-bold">-- Pilih Kelas --</option>
                                        <?php foreach($list_kelas as $kls): ?>
                                            <option value="<?php echo $kls['id']; ?>" <?php echo ($d['id_kelas_aktif'] == $kls['id']) ? 'selected' : ''; ?>>
                                                <?php echo $kls['nama_kelas']; ?> (<?php echo $kls['jenjang']; ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>

                                <td>
                                    <select class="form-select select-inline ajax-plot" data-id="<?php echo $d['id']; ?>" data-tipe="kamar">
                                        <option value="" class="text-danger fw-bold">-- Pilih Kamar --</option>
                                        <?php foreach($list_kamar as $kmr): 
                                            // Indikator Penuh
                                            $is_full = ($kmr['terisi'] >= $kmr['kapasitas']);
                                            $is_current = ($d['id_kamar_aktif'] == $kmr['id']);
                                            $color_class = ($is_full && !$is_current) ? 'text-danger fw-bold' : '';
                                        ?>
                                            <option value="<?php echo $kmr['id']; ?>" class="<?php echo $color_class; ?>" <?php echo $is_current ? 'selected' : ''; ?>>
                                                <?php echo $kmr['nama_kamar']; ?> (<?php echo $kmr['terisi'].'/'.$kmr['kapasitas']; ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                            </tr>
                            <?php 
                                }
                            } else {
                                echo "<tr><td colspan='5' class='text-center py-5 text-muted'>Tidak ada data santri ditemukan.</td></tr>";
                            }
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
        </main>
    </div>
</div>

<div id="bulkActionBar">
    <div class="d-flex align-items-center justify-content-between gap-4">
        <div class="fw-bold text-primary">
            <span id="countSelected" class="badge bg-primary rounded-circle fs-6 me-1 px-2 py-2">0</span> Santri Terpilih
        </div>
        
        <div class="d-flex align-items-center gap-2 border-start ps-4">
            <select id="bulkKelas" class="form-select form-select-sm border-primary" style="width: 160px;">
                <option value="">-- Set Kelas --</option>
                <option value="KOSONG" class="text-danger fw-bold">KOSONGKAN KELAS</option>
                <?php foreach($list_kelas as $kls): ?>
                    <option value="<?php echo $kls['id']; ?>"><?php echo $kls['nama_kelas']; ?> (<?php echo $kls['jenjang']; ?>)</option>
                <?php endforeach; ?>
            </select>
            <button class="btn btn-sm btn-primary fw-bold px-3" onclick="applyBulk('kelas')">Terapkan</button>
        </div>

        <div class="d-flex align-items-center gap-2 border-start ps-4">
            <select id="bulkKamar" class="form-select form-select-sm border-success" style="width: 160px;">
                <option value="">-- Set Kamar --</option>
                <option value="KOSONG" class="text-danger fw-bold">KOSONGKAN KAMAR</option>
                <?php foreach($list_kamar as $kmr): 
                    $is_full = ($kmr['terisi'] >= $kmr['kapasitas']);
                    $color_class = $is_full ? 'text-danger fw-bold' : '';
                ?>
                    <option value="<?php echo $kmr['id']; ?>" class="<?php echo $color_class; ?>" <?php echo $is_full ? 'disabled' : ''; ?>>
                        <?php echo $kmr['nama_kamar']; ?> (<?php echo $kmr['terisi'].'/'.$kmr['kapasitas']; ?>)
                    </option>
                <?php endforeach; ?>
            </select>
            <button class="btn btn-sm btn-success fw-bold px-3" onclick="applyBulk('kamar')">Terapkan</button>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>

<script>
    $(document).ready(function() {

        $('#tabelPenempatan').DataTable({
            "lengthMenu": [[25, 50, 100, -1], [25, 50, 100, "Semua"]],
            "pageLength": 25,
            "columnDefs": [ { "orderable": false, "targets": 0 } ],
            "language": {
                "lengthMenu": "Tampilkan _MENU_ baris",
                "zeroRecords": "Tidak ada data yang cocok dengan pencarian",
                "info": "Menampilkan _START_ sampai _END_ dari _TOTAL_ santri",
                "infoEmpty": "Data kosong",
                "infoFiltered": "(filter dari _MAX_ data)",
                "search": "Pencarian Instan:",
                "paginate": { "first": "Awal", "last": "Akhir", "next": "Maju", "previous": "Mundur" }
            }
        });

        // --- 1. Aksi Ubah Individual (Tanpa Refresh) ---
        $('.ajax-plot').change(function() {
            let el = $(this);
            let id_santri = el.data('id');
            let tipe = el.data('tipe');
            let id_val = el.val();
            let prev_val = el.data('prev'); // Simpan nilai sebelumnya jika gagal

            el.addClass('is-saving');
            $.ajax({
                url: 'admin_santri.php', type: 'POST', dataType: 'json',
                data: { action: 'update_plot', id_santri: id_santri, tipe: tipe, id_val: id_val },
                success: function(response) {
                    el.removeClass('is-saving');
                    if(response.status === 'success') {
                        el.addClass('border-success text-success bg-white');
                        setTimeout(() => { el.removeClass('border-success text-success bg-white'); location.reload(); }, 1000);
                    } else { 
                        alert(response.msg); 
                        location.reload(); // Refresh untuk mereset dropdown kembali ke nilai asli jika gagal
                    }
                }
            });
        });

        // --- 2. Logika Checkbox Massal ---
        $('#checkAll').change(function() {
            $('.check-santri').prop('checked', this.checked);
            toggleBulkBar();
        });

        $(document).on('change', '.check-santri', function() {
            if ($('.check-santri:checked').length == $('.check-santri').length) {
                $('#checkAll').prop('checked', true);
            } else {
                $('#checkAll').prop('checked', false);
            }
            toggleBulkBar();
        });
    });

    function toggleBulkBar() {
        let count = $('.check-santri:checked').length;
        $('#countSelected').text(count);
        if (count > 0) {
            $('#bulkActionBar').fadeIn(200).css('display', 'flex');
        } else {
            $('#bulkActionBar').fadeOut(200);
        }
    }

    // --- 3. Aksi Eksekusi Massal (Bulk Action) ---
    function applyBulk(tipe) {
        let id_val = (tipe === 'kelas') ? $('#bulkKelas').val() : $('#bulkKamar').val();
        
        if (id_val === "") {
            alert('Pilih ' + tipe + ' terlebih dahulu dari dropdown!');
            return;
        }

        if (id_val === "KOSONG") { id_val = ""; }

        let selectedIds = [];
        $('.check-santri:checked').each(function() {
            selectedIds.push($(this).val());
        });

        if (selectedIds.length === 0) return;

        let conf = confirm(`Anda akan menempatkan ${selectedIds.length} santri sekaligus. Lanjutkan?`);
        if (!conf) return;

        $.ajax({
            url: 'admin_santri.php',
            type: 'POST',
            data: {
                action: 'bulk_update_plot',
                id_santris: JSON.stringify(selectedIds),
                tipe: tipe,
                id_val: id_val
            },
            dataType: 'json',
            success: function(response) {
                if(response.status === 'success') {
                    alert('Berhasil! ' + selectedIds.length + ' santri telah ditempatkan.');
                    location.reload(); 
                } else {
                    alert('Error: ' + response.msg);
                }
            },
            error: function() {
                alert('Gagal memproses data massal. Cek koneksi Anda.');
            }
        });
    }
</script>

</body>
</html>