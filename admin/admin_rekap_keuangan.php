<?php
require_once __DIR__ . '/_guard.php';

// Filter Status Pembayaran (Default: Lunas)
$filter_status = isset($_GET['status']) ? mysqli_real_escape_string($koneksi, $_GET['status']) : 'Lunas';

$where = "WHERE 1=1";
if($filter_status != 'Semua'){
    $where .= " AND pb.status_pembayaran = '$filter_status'";
}

// Mengambil Data Pembayaran + Pendaftar
$query = "SELECT pb.*, p.jenis_kelamin, p.jenjang_tujuan 
          FROM psb_pembayaran pb 
          JOIN psb_pendaftar p ON pb.no_pendaftaran = p.no_pendaftaran 
          $where";
$exec = mysqli_query($koneksi, $query);

// Variabel Penampung Rekapitulasi Matriks
$rekap = [];
$komponen_list = [
    'Syahriyyah (Bulanan)' => true,
    'Infaq Bangunan' => true,
    'Seragam PSAS' => true,
    'Seragam Pramuka' => true
];

// Inisialisasi Kolom (SMP L/P, SMK L/P, LAIN L/P)
$kolom = ['SMP_L', 'SMP_P', 'SMK_L', 'SMK_P', 'LAIN_L', 'LAIN_P'];

// Proses Pemecahan dan Pengelompokan Data
while($d = mysqli_fetch_array($exec)){
    $jk = $d['jenis_kelamin']; // L atau P
    $unit = $d['jenjang_tujuan'];
    
    // Klasifikasi Kolom Unit
    if(strpos(strtolower($unit), 'smp terpadu') !== false) {
        $kunci_kolom = 'SMP_' . $jk;
    } else if (strpos(strtolower($unit), 'smk terpadu') !== false) {
        $kunci_kolom = 'SMK_' . $jk;
    } else {
        $kunci_kolom = 'LAIN_' . $jk;
    }

    // Rekap Biaya Pilihan Tetap
    if(!isset($rekap['Syahriyyah (Bulanan)'][$kunci_kolom])) $rekap['Syahriyyah (Bulanan)'][$kunci_kolom] = 0;
    $rekap['Syahriyyah (Bulanan)'][$kunci_kolom] += $d['syahriyyah'];

    if(!isset($rekap['Infaq Bangunan'][$kunci_kolom])) $rekap['Infaq Bangunan'][$kunci_kolom] = 0;
    $rekap['Infaq Bangunan'][$kunci_kolom] += $d['infaq'];

    if(!isset($rekap['Seragam PSAS'][$kunci_kolom])) $rekap['Seragam PSAS'][$kunci_kolom] = 0;
    $rekap['Seragam PSAS'][$kunci_kolom] += $d['seragam_psas'];

    if(!isset($rekap['Seragam Pramuka'][$kunci_kolom])) $rekap['Seragam Pramuka'][$kunci_kolom] = 0;
    $rekap['Seragam Pramuka'][$kunci_kolom] += $d['seragam_pramuka'];

    // Rekap Biaya Wajib (Dari JSON Ceklis)
    $rincian_wajib = json_decode($d['rincian_wajib'], true);
    if(is_array($rincian_wajib)){
        foreach($rincian_wajib as $item => $harga){
            $komponen_list[$item] = true; // Daftarkan nama komponen ke master list
            
            if(!isset($rekap[$item][$kunci_kolom])) $rekap[$item][$kunci_kolom] = 0;
            $rekap[$item][$kunci_kolom] += $harga;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <title>Rekapitulasi Keuangan PSB - Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .table-rekap th { vertical-align: middle; text-align: center; }
        .table-rekap td { vertical-align: middle; }
        .col-angka { text-align: right; font-family: monospace; font-size: 14px;}
        .bg-putra { background-color: #e0f7fa !important; }
        .bg-putri { background-color: #fce4ec !important; }
        .bg-total-col { background-color: #fff3cd !important; font-weight: bold;}
    </style>
</head>
<body class="bg-light">

<header class="navbar navbar-dark sticky-top bg-success flex-md-nowrap p-0 shadow d-md-none">
    <a class="navbar-brand col-md-3 col-lg-2 me-0 px-3" href="#">Menu Bendahara</a>
    <button class="navbar-toggler position-absolute d-md-none collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#sidebarMenu">
        <span class="navbar-toggler-icon"></span>
    </button>
</header>

<div class="container-fluid">
    <div class="row">
        <?php include 'sidebar.php'; ?>

        <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2 class="h2 mb-0">Rekapitulasi Arus Kas PSB</h2>
                <button type="button" onclick="exportTableToExcel('tabelRekap', 'Rekap_Keuangan_PSB_AlHasan')" class="btn btn-warning fw-bold shadow-sm">
                    <i class="fas fa-file-excel me-1"></i> Download Excel
                </button>
            </div>

            <div class="card shadow-sm border-0 mb-4 bg-white">
                <div class="card-body">
                    <form method="GET" class="row g-2 align-items-center">
                        <div class="col-md-auto fw-bold">Filter Status Dana:</div>
                        <div class="col-md-3">
                            <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
                                <option value="Lunas" <?php if($filter_status == 'Lunas') echo 'selected'; ?>>Hanya Dana Masuk (LUNAS)</option>
                                <option value="Belum Lunas" <?php if($filter_status == 'Belum Lunas') echo 'selected'; ?>>Hanya Tagihan (Belum Lunas)</option>
                                <option value="Semua" <?php if($filter_status == 'Semua') echo 'selected'; ?>>Gabung Semua Data (Potensi Kas)</option>
                            </select>
                        </div>
                        <div class="col-md-auto text-muted small fst-italic">
                            *Sistem otomatis membongkar komponen biaya dari tagihan pendaftar secara terperinci.
                        </div>
                    </form>
                </div>
            </div>

            <div class="card shadow border-0 mb-5 rounded-4 overflow-hidden">
                <div class="table-responsive">
                    <table id="tabelRekap" class="table table-bordered table-hover table-rekap mb-0">
                        <thead class="table-dark small text-uppercase">
                            <tr>
                                <th rowspan="2" width="20%">Komponen Biaya</th>
                                <th colspan="2" class="bg-primary border-primary">SMP Terpadu</th>
                                <th colspan="2" class="bg-success border-success">SMK Terpadu</th>
                                <th colspan="2" class="bg-secondary border-secondary">Unit Tsanawi Lainnya</th>
                                <th rowspan="2" class="bg-warning text-dark border-warning" width="12%">GRAND TOTAL</th>
                            </tr>
                            <tr>
                                <th class="bg-primary border-primary" style="background-image: linear-gradient(rgba(255,255,255,0.2), rgba(255,255,255,0.2));">Putra</th>
                                <th class="bg-primary border-primary">Putri</th>
                                <th class="bg-success border-success" style="background-image: linear-gradient(rgba(255,255,255,0.2), rgba(255,255,255,0.2));">Putra</th>
                                <th class="bg-success border-success">Putri</th>
                                <th class="bg-secondary border-secondary" style="background-image: linear-gradient(rgba(255,255,255,0.2), rgba(255,255,255,0.2));">Putra</th>
                                <th class="bg-secondary border-secondary">Putri</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            // Array untuk menampung total kolom terbawah
                            $grand_col = [
                                'SMP_L'=>0, 'SMP_P'=>0, 
                                'SMK_L'=>0, 'SMK_P'=>0, 
                                'LAIN_L'=>0, 'LAIN_P'=>0, 
                                'ALL'=>0
                            ];

                            // Looping dari daftar komponen (Keys)
                            foreach($komponen_list as $komp_name => $val):
                                // Hitung total baris ini
                                $tot_row = 0;
                                foreach($kolom as $k) {
                                    $nilai = isset($rekap[$komp_name][$k]) ? $rekap[$komp_name][$k] : 0;
                                    $tot_row += $nilai;
                                }

                                // Sembunyikan baris jika nilainya 0 di semua unit (agar tabel bersih)
                                if($tot_row == 0) continue; 
                            ?>
                            <tr>
                                <td class="fw-bold text-dark"><?php echo $komp_name; ?></td>
                                
                                <?php 
                                foreach($kolom as $k): 
                                    $nilai = isset($rekap[$komp_name][$k]) ? $rekap[$komp_name][$k] : 0;
                                    $grand_col[$k] += $nilai; // Tambahkan ke total kolom
                                    $grand_col['ALL'] += $nilai; // Tambahkan ke total keseluruhan
                                    
                                    // Beri warna pembeda untuk putra dan putri
                                    $bg_class = (substr($k, -1) == 'L') ? 'bg-putra' : 'bg-putri';
                                ?>
                                    <td class="col-angka <?php echo $bg_class; ?>">
                                        <?php echo ($nilai > 0) ? number_format($nilai,0,',','.') : '-'; ?>
                                    </td>
                                <?php endforeach; ?>

                                <td class="col-angka bg-total-col text-success fs-6">
                                    <?php echo number_format($tot_row,0,',','.'); ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot class="table-dark fs-6">
                            <tr>
                                <th class="text-end pe-3 text-uppercase">Total Akumulasi</th>
                                <th class="col-angka"><?php echo number_format($grand_col['SMP_L'],0,',','.'); ?></th>
                                <th class="col-angka"><?php echo number_format($grand_col['SMP_P'],0,',','.'); ?></th>
                                <th class="col-angka"><?php echo number_format($grand_col['SMK_L'],0,',','.'); ?></th>
                                <th class="col-angka"><?php echo number_format($grand_col['SMK_P'],0,',','.'); ?></th>
                                <th class="col-angka"><?php echo number_format($grand_col['LAIN_L'],0,',','.'); ?></th>
                                <th class="col-angka"><?php echo number_format($grand_col['LAIN_P'],0,',','.'); ?></th>
                                <th class="col-angka text-warning fs-5"><?php echo number_format($grand_col['ALL'],0,',','.'); ?></th>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
            
        </main>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
<script>
    function exportTableToExcel(tableID, filename = ''){
        var tableSelect = document.getElementById(tableID);
        var wb = XLSX.utils.table_to_book(tableSelect, {sheet:"Rekapitulasi"});
        var date = new Date();
        var dStr = date.getFullYear() + "-" + (date.getMonth()+1) + "-" + date.getDate();
        filename = filename?filename+'_'+dStr+'.xlsx':'excel_data.xlsx';
        XLSX.writeFile(wb, filename);
    }
</script>
</body>
</html>
