<?php
include 'koneksi.php';

$noreg = $_GET['noreg'];
$data = mysqli_query($koneksi, "SELECT * FROM psb_pendaftar WHERE no_pendaftaran = '$noreg'");
$d = mysqli_fetch_array($data);

// Jika data tidak ditemukan
if (!$d) {
    die("Data tidak ditemukan. Pastikan Nomor Pendaftaran benar.");
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Cetak Bukti - <?php echo $d['nama_lengkap']; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { font-family: 'Times New Roman', serif; color: #000; }
        .kop-surat { border-bottom: 3px double #000; padding-bottom: 10px; margin-bottom: 20px; }
        .logo-box { width: 80px; height: 80px; background: #ddd; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 10px; }
        .table-data tr td { padding: 5px 10px; }
        .box-noreg { border: 2px solid #000; padding: 10px; display: inline-block; font-weight: bold; font-size: 1.2rem; margin-top: 10px; }
        .waktu-daftar { font-size: 0.85rem; font-weight: bold; margin-top: 5px; }
        
        /* Hilangkan tombol saat dicetak */
        @media print {
            .no-print { display: none !important; }
        }
    </style>
</head>
<body>

    <div class="container mt-4" style="max-width: 800px;">
        <div class="no-print mb-4 text-center">
            <!--<a href="index.php" class="btn btn-secondary me-2">Kembali ke Home</a>-->
            <button onclick="window.print()" class="btn btn-success"><i class="fas fa-print"></i> Cetak Halaman Ini</button>
        </div>

        <div class="card border-dark shadow-sm">
            <div class="card-body p-5">
                
                <div class="row align-items-center kop-surat text-center">
                    <div class="col-2">
                        <img src="logo_alhasan.png" alt="Logo" width="90">
                    </div>
                    <div class="col-10 text-center">
                        <h5 class="mb-0 fw-bold">PANITIA PENERIMAAN SANTRI BARU</h5>
                        <h4 class="fw-bold text-uppercase">YAYASAN PONDOK PESANTREN AL HASAN</h4>
                        <p class="mb-0 small fw-bold">RA TERPADU - SMP TERPADU - SMK TERPADU AL HASAN</p>
                        <p class="mb-0 small fst-italic">Alamat: Jl. Jendral Ahmad Yani, Ciamis, Jawa Barat. Telp: (0265) 123456</p>
                    </div>
                </div>

                <div class="text-center mb-4 mt-3">
                    <h5 class="fw-bold text-decoration-underline mb-1">KARTU BUKTI PENDAFTARAN</h5>
                    <p class="fw-bold">Tahun Ajaran 2026/2027</p>
                </div>

                <div class="row">
                    <div class="col-8">
                        <table class="table table-borderless table-data mb-0">
                            <tr>
                                <td width="160">No. Pendaftaran</td>
                                <td width="10">:</td>
                                <td class="fw-bold fs-5 text-success"><?php echo $d['no_pendaftaran']; ?></td>
                            </tr>
                            <tr>
                                <td>Nama Lengkap</td>
                                <td>:</td>
                                <td class="text-uppercase fw-bold"><?php echo $d['nama_lengkap']; ?></td>
                            </tr>
                            <tr>
                                <td>NISN</td>
                                <td>:</td>
                                <td><?php echo $d['nisn']; ?></td>
                            </tr>
                            <tr>
                                <td>Tempat, Tgl Lahir</td>
                                <td>:</td>
                                <td><?php echo $d['tempat_lahir'] . ', ' . date('d-m-Y', strtotime($d['tgl_lahir'])); ?></td>
                            </tr>
                            <tr>
                                <td>Jenis Kelamin</td>
                                <td>:</td>
                                <td><?php echo ($d['jenis_kelamin'] == 'L') ? 'Laki-laki' : 'Perempuan'; ?></td>
                            </tr>
                            <tr>
                                <td>Asal Sekolah</td>
                                <td>:</td>
                                <td><?php echo $d['sekolah_asal']; ?></td>
                            </tr>
                            <tr>
                                <td>Jenjang Pilihan</td>
                                <td>:</td>
                                <td><span class="fw-bold border border-dark px-2 py-1 bg-light"><?php echo $d['jenjang_tujuan']; ?></span></td>
                            </tr>
                        </table>
                    </div>
                    
                    <div class="col-4 text-center d-flex flex-column align-items-center justify-content-center">
                        <div class="border border-dark mb-2" style="height: 120px; width: 90px; display: flex; align-items: center; justify-content: center; background: #f8f9fa;">
                            <small class="text-muted">Tempel Foto<br>3x4</small>
                        </div>
                        
                        <div class="box-noreg mb-0">
                            <?php echo $d['no_pendaftaran']; ?>
                        </div>
                        
                        <div class="waktu-daftar text-muted">
                            <?php 
                            if(isset($d['tgl_daftar']) && !empty($d['tgl_daftar'])) {
                                echo "Waktu Daftar: " . date('d/m/Y', strtotime($d['tgl_daftar']));
                                // echo "Waktu Daftar: " . date('d/m/Y H:i', strtotime($d['tgl_daftar'])) . " WIB";
                            } else {
                                echo "Tgl Cetak: " . date('d/m/Y');
                            }
                            ?>
                        </div>
                        </div>
                </div>

                <div class="row mt-5 pt-3">
                    <div class="col-8">
                        <div class="border border-dark p-3 bg-light">
                            <p class="small mb-1 fw-bold text-decoration-underline">Catatan Penting:</p>
                            <ol class="small mb-0 ps-3">
                                <li>Kartu ini wajib dibawa saat daftar ulang / tes seleksi.</li>
                                <li>Silakan lengkapi berkas digital (KTP, KK, Akta, Ijazah) melalui <b>Portal Pendaftar</b>.</li>
                                <li>Simpan bukti pendaftaran ini dengan baik.</li>
                            </ol>
                        </div>
                    </div>
                    <div class="col-4 text-center">
                        <p class="mb-5">Ciamis, <?php echo date('d F Y'); ?><br>Panitia PSB,</p>
                        <br>
                        <p class="fw-bold text-decoration-underline mt-4 mb-0">______________________</p>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/js/all.min.js"></script>
    
    <script>
        window.onload = function() {
            // setTimeout(function(){ window.print(); }, 1000);
        }
    </script>
</body>
</html>