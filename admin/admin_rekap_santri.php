<?php
require_once __DIR__ . '/_guard.php';

// Ambil Tahun Ajaran Aktif
$q_tahun = mysqli_query($koneksi, "SELECT id, tahun, semester FROM tahun_ajaran WHERE status='Aktif' LIMIT 1");
$tahun_aktif = mysqli_fetch_array($q_tahun);
$id_tahun = $tahun_aktif ? $tahun_aktif['id'] : 0;
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <title>Rekapitulasi Santri - Admin</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css">
    <style>
        .table-rekap th { text-transform: uppercase; letter-spacing: 1px; }
        .col-angka { font-size: 15px; font-weight: bold; }
        .bg-putra { background-color: #e0f7fa !important; }
        .bg-putri { background-color: #fce4ec !important; }
        .bg-jumlah { background-color: #fff3cd !important; color: #198754 !important;}
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
            
            <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center mb-4">
                <div>
                    <h2 class="h2 mb-1 fw-bold text-success">Rekapitulasi Jumlah Santri</h2>
                    <span class="badge bg-primary fs-6 shadow-sm">
                        <i class="fas fa-calendar-alt me-1"></i> Tahun Ajaran Aktif: <?php echo $tahun_aktif ? $tahun_aktif['tahun'].' ('.$tahun_aktif['semester'].')' : 'Belum Diatur'; ?>
                    </span>
                </div>
                <button type="button" onclick="exportExcel()" class="btn btn-warning shadow-sm fw-bold mt-2 mt-md-0">
                    <i class="fas fa-file-excel me-2"></i> Download Excel
                </button>
            </div>

            <?php if($id_tahun == 0): ?>
                <div class="alert alert-danger shadow-sm rounded-4">
                    <i class="fas fa-exclamation-triangle me-2"></i> Tahun Ajaran Aktif belum diatur. Silakan atur di menu Tahun Ajaran terlebih dahulu agar data dapat dihitung.
                </div>
            <?php else: ?>

            <div class="card shadow-sm border-0 rounded-4 overflow-hidden mb-5">
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table id="tabelRekapSantri" class="table table-bordered table-hover align-middle text-center mb-0 table-rekap">
                            <thead class="table-success align-middle">
                                <tr>
                                    <th width="5%" class="py-3">No</th>
                                    <th class="text-start">Kelas</th>
                                    <th width="15%" class="bg-info bg-opacity-25 border-info text-dark"><i class="fas fa-male me-1"></i> Laki-Laki (L)</th>
                                    <th width="15%" class="bg-danger bg-opacity-25 border-danger text-dark"><i class="fas fa-female me-1"></i> Perempuan (P)</th>
                                    <th width="20%" class="bg-warning bg-opacity-25 border-warning text-dark"><i class="fas fa-users me-1"></i> Jumlah Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $no = 1;
                                $grand_l = 0;
                                $grand_p = 0;
                                $grand_total = 0;

                                // QUERY AWAL DARI TABEL KELAS
                                $query = "
                                    SELECT 
                                        k.nama_kelas,
                                        SUM(CASE WHEN s.jenis_kelamin = 'L' THEN 1 ELSE 0 END) as total_l,
                                        SUM(CASE WHEN s.jenis_kelamin = 'P' THEN 1 ELSE 0 END) as total_p,
                                        COUNT(s.id) as total_kelas
                                    FROM kelas k
                                    LEFT JOIN plotting_kelas pk ON k.id = pk.id_kelas AND pk.id_tahun = '$id_tahun'
                                    LEFT JOIN santri s ON pk.id_santri = s.id
                                    GROUP BY k.id, k.nama_kelas, k.jenjang
                                    ORDER BY k.jenjang ASC, k.nama_kelas ASC
                                ";
                                $exec = mysqli_query($koneksi, $query);

                                // Variabel penampung array untuk menggabungkan kelas
                                $rekap_kelas = [];

                                while($d = mysqli_fetch_array($exec)){
                                    $nama_asli = $d['nama_kelas'];
                                    
                                    // LOGIKA AJAIB: Hapus spasi beserta kata "PA" atau "PI" jika berada di paling akhir nama kelas
                                    // Contoh: "1 IBTIDA PA" menjadi "1 IBTIDA"
                                    $nama_gabung = preg_replace('/\s+(PA|PI)$/i', '', $nama_asli);

                                    // Jika nama kelas ini belum ada di array penampung, buat kerangkanya
                                    if(!isset($rekap_kelas[$nama_gabung])) {
                                        $rekap_kelas[$nama_gabung] = ['l' => 0, 'p' => 0, 'tot' => 0];
                                    }
                                    
                                    // Jumlahkan angka ke dalam kerangka array tersebut
                                    $rekap_kelas[$nama_gabung]['l'] += $d['total_l'] ? $d['total_l'] : 0;
                                    $rekap_kelas[$nama_gabung]['p'] += $d['total_p'] ? $d['total_p'] : 0;
                                    $rekap_kelas[$nama_gabung]['tot'] += $d['total_kelas'] ? $d['total_kelas'] : 0;
                                }

                                // Cetak tabel berdasarkan array yang sudah digabungkan secara rapi
                                foreach($rekap_kelas as $nama_kelas => $data){
                                    $l = $data['l'];
                                    $p = $data['p'];
                                    $tot = $data['tot'];

                                    $grand_l += $l;
                                    $grand_p += $p;
                                    $grand_total += $tot;
                                ?>
                                <tr>
                                    <td class="fw-bold"><?php echo $no++; ?></td>
                                    <td class="text-start fw-bold text-secondary"><?php echo $nama_kelas; ?></td>
                                    <td class="col-angka bg-putra text-primary"><?php echo $l > 0 ? $l : '-'; ?></td>
                                    <td class="col-angka bg-putri text-danger"><?php echo $p > 0 ? $p : '-'; ?></td>
                                    <td class="col-angka bg-jumlah fs-5"><?php echo $tot > 0 ? $tot : '-'; ?></td>
                                </tr>
                                <?php } ?>

                                <?php 
                                // QUERY TAMBAHAN: SANTRI YANG BELUM MENDAPATKAN KELAS
                                $q_belum = mysqli_query($koneksi, "
                                    SELECT 
                                        SUM(CASE WHEN jenis_kelamin = 'L' THEN 1 ELSE 0 END) as total_l,
                                        SUM(CASE WHEN jenis_kelamin = 'P' THEN 1 ELSE 0 END) as total_p,
                                        COUNT(id) as total_semua
                                    FROM santri 
                                    WHERE id NOT IN (SELECT id_santri FROM plotting_kelas WHERE id_tahun = '$id_tahun')
                                ");
                                $d_belum = mysqli_fetch_array($q_belum);
                                $blm_l = $d_belum['total_l'] ? $d_belum['total_l'] : 0;
                                $blm_p = $d_belum['total_p'] ? $d_belum['total_p'] : 0;
                                $blm_tot = $d_belum['total_semua'] ? $d_belum['total_semua'] : 0;

                                // Tambahkan ke Grand Total keseluruhan database santri aktif
                                $grand_l += $blm_l;
                                $grand_p += $blm_p;
                                $grand_total += $blm_tot;

                                if($blm_tot > 0):
                                ?>
                                <tr class="table-warning">
                                    <td class="fw-bold">-</td>
                                    <td class="text-start fw-bold text-danger fst-italic">
                                        <i class="fas fa-exclamation-circle me-1"></i> BELUM DITEMPATKAN DI KELAS MANAPUN
                                    </td>
                                    <td class="col-angka text-primary"><?php echo $blm_l > 0 ? $blm_l : '-'; ?></td>
                                    <td class="col-angka text-danger"><?php echo $blm_p > 0 ? $blm_p : '-'; ?></td>
                                    <td class="col-angka text-dark fs-5"><?php echo $blm_tot > 0 ? $blm_tot : '-'; ?></td>
                                </tr>
                                <?php endif; ?>

                            </tbody>
                            <tfoot class="table-dark fs-5">
                                <tr>
                                    <th colspan="2" class="text-end pe-4 py-3">GRAND TOTAL SANTRI AKTIF</th>
                                    <th class="col-angka text-info"><?php echo number_format($grand_l,0,',','.'); ?></th>
                                    <th class="col-angka text-warning"><?php echo number_format($grand_p,0,',','.'); ?></th>
                                    <th class="col-angka text-success bg-white fs-4"><?php echo number_format($grand_total,0,',','.'); ?></th>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>

            <?php endif; ?>

        </main>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
<script>
    // Fitur Export Langsung dari Tabel HTML ke Excel
    function exportExcel() {
        var table = document.getElementById("tabelRekapSantri");
        var wb = XLSX.utils.table_to_book(table, {sheet: "Rekapitulasi Santri"});
        
        // Generate Nama File dengan Tanggal
        var date = new Date();
        var dStr = date.getFullYear() + "-" + (date.getMonth()+1) + "-" + date.getDate();
        var fileName = "Rekap_Jumlah_Santri_AlHasan_" + dStr + ".xlsx";
        
        XLSX.writeFile(wb, fileName);
    }
</script>

</body>
</html>
