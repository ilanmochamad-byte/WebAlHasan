<?php
session_start();
if(!isset($_SESSION['status']) || $_SESSION['status'] != "login"){ header("Location: admin_login.php"); exit; }
include '../koneksi.php';

// PROSES APPROVAL LUNAS
if(isset($_GET['act']) && $_GET['act'] == 'lunas'){
    $id = (int)$_GET['id'];
    mysqli_query($koneksi, "UPDATE psb_pembayaran SET status_pembayaran='Lunas', waktu_lunas=NOW() WHERE id='$id'");
    echo "<script>alert('Pembayaran divalidasi: LUNAS!'); window.location='admin_pembayaran_psb.php';</script>";
    exit;
}

// PROSES BATAL LUNAS
if(isset($_GET['act']) && $_GET['act'] == 'batal'){
    $id = (int)$_GET['id'];
    mysqli_query($koneksi, "UPDATE psb_pembayaran SET status_pembayaran='Belum Lunas', waktu_lunas=NULL WHERE id='$id'");
    echo "<script>window.location='admin_pembayaran_psb.php';</script>";
    exit;
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <title>Validasi Pembayaran PSB - Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css">
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
            <h2 class="h2 mb-4">Validasi Pembayaran Santri Baru</h2>

            <div class="row g-3 mb-4">
<?php 
    // Menggunakan JOIN agar hanya menghitung tagihan dari pendaftar yang datanya masih ada di tabel PSB
    $q_uang = mysqli_query($koneksi, "SELECT SUM(pb.total_keseluruhan) as uang_masuk FROM psb_pembayaran pb JOIN psb_pendaftar p ON pb.no_pendaftaran = p.no_pendaftaran WHERE pb.status_pembayaran='Lunas'");
    $uang = mysqli_fetch_assoc($q_uang);
    $tot_uang = $uang['uang_masuk'] ? $uang['uang_masuk'] : 0;
    
    $tot_lunas = mysqli_num_rows(mysqli_query($koneksi, "SELECT pb.id FROM psb_pembayaran pb JOIN psb_pendaftar p ON pb.no_pendaftaran = p.no_pendaftaran WHERE pb.status_pembayaran='Lunas'"));
    
    $tot_belum = mysqli_num_rows(mysqli_query($koneksi, "SELECT pb.id FROM psb_pembayaran pb JOIN psb_pendaftar p ON pb.no_pendaftaran = p.no_pendaftaran WHERE pb.status_pembayaran='Belum Lunas'"));
?>
                <div class="col-md-4">
                    <div class="card shadow-sm border-0 border-start border-danger border-5">
                        <div class="card-body">
                            <h6 class="text-muted">Menunggu Pembayaran</h6>
                            <h3 class="fw-bold text-danger mb-0"><?php echo $tot_belum; ?> <small class="fs-6 text-muted">Kwitansi</small></h3>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card shadow-sm border-0 border-start border-success border-5">
                        <div class="card-body">
                            <h6 class="text-muted">Lunas (Selesai Validasi)</h6>
                            <h3 class="fw-bold text-success mb-0"><?php echo $tot_lunas; ?> <small class="fs-6 text-muted">Santri</small></h3>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card shadow-sm border-0 border-start border-primary border-5 bg-primary text-white">
                        <div class="card-body">
                            <h6 class="text-white-50">Total Kas Masuk (Lunas)</h6>
                            <h3 class="fw-bold mb-0">Rp <?php echo number_format($tot_uang,0,',','.'); ?></h3>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card shadow-sm border-0 mb-4 bg-white">
                <div class="card-body">
                    <form method="GET" class="row g-2">
                        <div class="col-md-2">
                            <select name="status" class="form-select form-select-sm">
                                <option value="">Semua Status</option>
                                <option value="Belum Lunas" <?php if(isset($_GET['status']) && $_GET['status']=='Belum Lunas') echo 'selected'; ?>>Belum Lunas</option>
                                <option value="Lunas" <?php if(isset($_GET['status']) && $_GET['status']=='Lunas') echo 'selected'; ?>>Lunas</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <select name="jenjang" class="form-select form-select-sm">
                                <option value="">Semua Pilihan Unit</option>
                                <option value="SMP Terpadu Al Hasan" <?php if(isset($_GET['jenjang']) && $_GET['jenjang']=='SMP Terpadu Al Hasan') echo 'selected'; ?>>SMP Terpadu Al Hasan</option>
                                <option value="SMK Terpadu Al Hasan" <?php if(isset($_GET['jenjang']) && $_GET['jenjang']=='SMK Terpadu Al Hasan') echo 'selected'; ?>>SMK Terpadu Al Hasan</option>
                                <option value="RA Terpadu Al Hasan" <?php if(isset($_GET['jenjang']) && $_GET['jenjang']=='RA Terpadu Al Hasan') echo 'selected'; ?>>RA Terpadu Al Hasan</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <select name="jk" class="form-select form-select-sm">
                                <option value="">Semua JK</option>
                                <option value="L" <?php if(isset($_GET['jk']) && $_GET['jk']=='L') echo 'selected'; ?>>Laki-laki</option>
                                <option value="P" <?php if(isset($_GET['jk']) && $_GET['jk']=='P') echo 'selected'; ?>>Perempuan</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <div class="input-group input-group-sm">
                                <button class="btn btn-primary w-50 fw-bold" type="submit"><i class="fas fa-filter"></i> Filter</button>
                                <a href="admin_pembayaran_psb.php" class="btn btn-secondary w-50"><i class="fas fa-sync"></i> Reset</a>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <div class="mb-3 text-end">
                <button type="button" id="btnExportKeuangan" onclick="exportKeuanganExcel()" class="btn btn-warning shadow-sm fw-bold text-dark">
                    <i class="fas fa-file-excel me-2"></i> Export Laporan Keuangan
                </button>
            </div>

            <div class="card shadow border-0 mb-5">
                <div class="card-body p-3">
                    <div class="table-responsive">
                        <table id="tabelTagihan" class="table table-hover align-middle mb-0" style="font-size: 0.9rem;">
                            <thead class="table-dark text-uppercase small">
                                <tr>
                                    <th width="5%">No</th>
                                    <th>Pendaftar</th>
                                    <th>Unit & Kategori</th>
                                    <th>Total Tagihan</th>
                                    <th class="text-center">Status</th>
                                    <th class="text-center">Aksi / Kwitansi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $no = 1;
                                $where = "WHERE 1=1";
                                if(!empty($_GET['jk'])) $where .= " AND p.jenis_kelamin = '$_GET[jk]'";
                                if(!empty($_GET['jenjang'])) $where .= " AND p.jenjang_tujuan = '$_GET[jenjang]'";
                                if(!empty($_GET['status'])) $where .= " AND pb.status_pembayaran = '$_GET[status]'";

                                $query = "SELECT pb.*, p.nama_lengkap, p.jenis_kelamin, p.jenjang_tujuan 
                                          FROM psb_pembayaran pb 
                                          JOIN psb_pendaftar p ON pb.no_pendaftaran = p.no_pendaftaran 
                                          $where ORDER BY pb.status_pembayaran ASC, pb.id DESC";
                                $data = mysqli_query($koneksi, $query);
                                
                                while($d = mysqli_fetch_array($data)){
                                ?>
                                <tr>
                                    <td class="text-center fw-bold"><?php echo $no++; ?></td>
                                    <td>
                                        <div class="fw-bold text-dark fs-6"><?php echo $d['nama_lengkap']; ?></div>
                                        <div class="small text-muted"><span class="badge bg-secondary"><?php echo $d['no_pendaftaran']; ?></span> • <?php echo ($d['jenis_kelamin']=='L')?'Laki-laki':'Perempuan'; ?></div>
                                    </td>
                                    <td>
                                        <div class="fw-bold text-primary"><?php echo $d['jenjang_tujuan']; ?></div>
                                        <div class="small text-muted"><?php echo $d['kategori_biaya']; ?></div>
                                    </td>
                                    <td>
                                        <div class="fw-bold text-success fs-6">Rp <?php echo number_format($d['total_keseluruhan'],0,',','.'); ?></div>
                                        <div class="small mt-1">
                                            <?php if($d['metode_pembayaran'] == 'Transfer'): ?>
                                                <span class="badge bg-info text-dark"><i class="fas fa-university me-1"></i> Transfer</span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary"><i class="fas fa-money-bill-wave me-1"></i> Cash</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="text-center">
                                        <?php if($d['status_pembayaran'] == 'Belum Lunas'): ?>
                                            <span class="badge bg-danger rounded-pill px-3 py-2"><i class="fas fa-clock me-1"></i> Belum Lunas</span>
                                        <?php else: ?>
                                            <span class="badge bg-success rounded-pill px-3 py-2"><i class="fas fa-check-circle me-1"></i> LUNAS</span><br>
                                            <small class="text-muted" style="font-size: 10px;"><?php echo date('d/m/Y H:i', strtotime($d['waktu_lunas'])); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <button class="btn btn-sm btn-info text-white fw-bold shadow-sm mb-1 w-100" data-bs-toggle="modal" data-bs-target="#modalDetail<?php echo $d['id']; ?>">
                                            <i class="fas fa-list me-1"></i> Rincian Biaya
                                        </button>

                                        <?php if($d['status_pembayaran'] == 'Belum Lunas'): ?>
                                            <a href="admin_pembayaran_psb.php?act=lunas&id=<?php echo $d['id']; ?>" class="btn btn-sm btn-success fw-bold shadow-sm mb-1 w-100" onclick="return confirm('Validasi pembayaran menjadi LUNAS?')">
                                                <i class="fas fa-check me-1"></i> Approve (Lunas)
                                            </a>
                                        <?php else: ?>
                                            <a href="admin_pembayaran_psb.php?act=batal&id=<?php echo $d['id']; ?>" class="btn btn-sm btn-outline-danger mb-1 w-100" onclick="return confirm('Batalkan status lunas?')">
                                                <i class="fas fa-times me-1"></i> Batal Lunas
                                            </a>
                                        <?php endif; ?>
                                        <a href="../cetak_biaya_psb.php?noreg=<?php echo $d['no_pendaftaran']; ?>" target="_blank" class="btn btn-sm btn-dark w-100">
                                            <i class="fas fa-print me-1"></i> Cetak Kwitansi
                                        </a>
                                    </td>
                                </tr>

                                <div class="modal fade" id="modalDetail<?php echo $d['id']; ?>" tabindex="-1">
                                    <div class="modal-dialog modal-lg">
                                        <div class="modal-content">
                                            <div class="modal-header bg-info text-white border-0">
                                                <h5 class="modal-title fw-bold"><i class="fas fa-search-dollar me-2"></i> Rincian Pembayaran Santri</h5>
                                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                            </div>
                                            <div class="modal-body p-4 bg-light">
                                                
                                                <div class="d-flex justify-content-between align-items-center bg-white p-3 rounded shadow-sm mb-4 border-start border-info border-4">
                                                    <div>
                                                        <h5 class="fw-bold mb-1 text-dark"><?php echo $d['nama_lengkap']; ?></h5>
                                                        <span class="text-muted small"><?php echo $d['jenjang_tujuan']; ?> • <?php echo ($d['jenis_kelamin']=='L')?'Laki-laki':'Perempuan'; ?></span>
                                                    </div>
                                                    <div class="text-end">
                                                        <span class="badge bg-secondary mb-1 fs-6"><?php echo $d['no_pendaftaran']; ?></span><br>
                                                        <span class="small fw-bold text-primary"><?php echo $d['kategori_biaya']; ?></span>
                                                    </div>
                                                </div>

                                                <div class="row g-3">
                                                    <div class="col-md-6">
                                                        <div class="card h-100 border-0 shadow-sm">
                                                            <div class="card-header bg-white border-bottom-0 pt-3 pb-0">
                                                                <h6 class="fw-bold text-primary mb-0"><i class="fas fa-hand-holding-usd me-2"></i> A. Biaya Pilihan</h6>
                                                            </div>
                                                            <div class="card-body">
                                                                <ul class="list-group list-group-flush small">
                                                                    <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                                                                        Syahriyyah (Bulanan)
                                                                        <span class="fw-bold">Rp <?php echo number_format($d['syahriyyah'],0,',','.'); ?></span>
                                                                    </li>
                                                                    <?php if($d['infaq'] > 0): ?>
                                                                    <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                                                                        Infaq Bangunan
                                                                        <span class="fw-bold">Rp <?php echo number_format($d['infaq'],0,',','.'); ?></span>
                                                                    </li>
                                                                    <?php endif; ?>
                                                                    <?php if($d['seragam_psas'] > 0): ?>
                                                                    <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                                                                        Seragam PSAS
                                                                        <span class="fw-bold">Rp <?php echo number_format($d['seragam_psas'],0,',','.'); ?></span>
                                                                    </li>
                                                                    <?php endif; ?>
                                                                    <?php if($d['seragam_pramuka'] > 0): ?>
                                                                    <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                                                                        Seragam Pramuka
                                                                        <span class="fw-bold">Rp <?php echo number_format($d['seragam_pramuka'],0,',','.'); ?></span>
                                                                    </li>
                                                                    <?php endif; ?>
                                                                </ul>
                                                            </div>
                                                        </div>
                                                    </div>

                                                    <div class="col-md-6">
                                                        <div class="card h-100 border-0 shadow-sm">
                                                            <div class="card-header bg-white border-bottom-0 pt-3 pb-0">
                                                                <h6 class="fw-bold text-danger mb-0"><i class="fas fa-clipboard-check me-2"></i> B. Biaya Wajib Terbayar</h6>
                                                            </div>
                                                            <div class="card-body">
                                                                <ul class="list-group list-group-flush small">
                                                                    <?php 
                                                                    $rw = json_decode($d['rincian_wajib'], true);
                                                                    if(!empty($rw)){
                                                                        foreach($rw as $item => $harga): 
                                                                    ?>
                                                                    <li class="list-group-item d-flex justify-content-between align-items-center px-0 text-muted">
                                                                        <?php echo $item; ?>
                                                                        <span class="fw-bold text-dark">Rp <?php echo number_format($harga,0,',','.'); ?></span>
                                                                    </li>
                                                                    <?php 
                                                                        endforeach; 
                                                                    } else {
                                                                        echo "<li class='list-group-item px-0 text-center text-danger fst-italic border-0 pt-3'>Belum ada item wajib yang dicentang.</li>";
                                                                    }
                                                                    ?>
                                                                </ul>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>

                                                <?php $bg_alert = ($d['metode_pembayaran'] == 'Transfer') ? 'alert-info border-info' : 'alert-secondary border-secondary'; ?>
                                                <div class="alert <?php echo $bg_alert; ?> mt-3 mb-0 d-flex flex-wrap justify-content-between align-items-center shadow-sm">
                                                    <div>
                                                        <div class="small text-muted mb-1 text-uppercase fw-bold" style="letter-spacing: 1px;">Metode Pembayaran</div>
                                                        <div class="fs-5 fw-bold text-dark">
                                                            <i class="fas <?php echo ($d['metode_pembayaran'] == 'Transfer') ? 'fa-university' : 'fa-money-bill-wave'; ?> me-2"></i><?php echo strtoupper($d['metode_pembayaran']); ?>
                                                        </div>
                                                    </div>
                                                    <div class="text-end mt-2 mt-md-0">
                                                        <div class="small text-muted mb-1 text-uppercase fw-bold" style="letter-spacing: 1px;">Grand Total</div>
                                                        <h3 class="fw-bold text-success mb-0">Rp <?php echo number_format($d['total_keseluruhan'],0,',','.'); ?></h3>
                                                    </div>
                                                </div>

                                            </div>
                                            <div class="modal-footer border-0">
                                                <button type="button" class="btn btn-secondary fw-bold px-4" data-bs-dismiss="modal">Tutup</button>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <?php } ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </main>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.5.1.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>

<script>
    $(document).ready(function () {
        $('#tabelTagihan').DataTable({
            "lengthMenu": [[25, 50, 100, -1], [25, 50, 100, "Semua"]],
            "language": { "search": "Pencarian Cepat:" }
        });
    });

    function exportKeuanganExcel() {
        const btn = document.getElementById('btnExportKeuangan');
        const originalText = btn.innerHTML;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i> Merakit File...';
        btn.disabled = true;

        const urlParams = new URLSearchParams(window.location.search);
        
        fetch('export_pembayaran.php?' + urlParams.toString())
        .then(res => res.json())
        .then(data => {
            if(data.length === 0) {
                alert('Tidak ada data yang cocok dengan filter untuk diekspor.');
                btn.innerHTML = originalText;
                btn.disabled = false; return;
            }
            const ws = XLSX.utils.json_to_sheet(data);
            const wb = XLSX.utils.book_new();
            XLSX.utils.book_append_sheet(wb, ws, "Laporan Keuangan PSB");
            
            const date = new Date();
            const fileName = "Keuangan_PSB_AlHasan_" + date.getFullYear() + "-" + (date.getMonth()+1) + "-" + date.getDate() + ".xlsx";
            XLSX.writeFile(wb, fileName);
            
            btn.innerHTML = originalText;
            btn.disabled = false;
        })
        .catch(err => { alert('Gagal mengekspor data: ' + err); btn.innerHTML = originalText; btn.disabled = false; });
    }
</script>
</body>
</html>