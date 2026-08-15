<?php
include 'koneksi.php';

if(!isset($_GET['noreg'])){
    echo "Nomor Registrasi Tidak Ditemukan!";
    exit;
}

$noreg = mysqli_real_escape_string($koneksi, $_GET['noreg']);

$query = mysqli_query($koneksi, "SELECT p.*, pb.* FROM psb_pembayaran pb 
                                 JOIN psb_pendaftar p ON pb.no_pendaftaran = p.no_pendaftaran 
                                 WHERE pb.no_pendaftaran='$noreg'");

if(mysqli_num_rows($query) == 0){
    echo "Data Rincian Biaya belum diisi. Silakan isi terlebih dahulu di halaman sebelumnya.";
    exit;
}

$d = mysqli_fetch_assoc($query);
$rincian_wajib = json_decode($d['rincian_wajib'], true);

// ==============================================================
// LOGIKA PEMISAHAN ARRAY UNTUK TABEL 2 KOLOM (PILIHAN & WAJIB)
// ==============================================================
$pilihan_arr = [];
$no_p = 1;
$pilihan_arr[] = ['nama' => $no_p++.'. Syahriyyah (Bulanan)', 'nominal' => $d['syahriyyah']];
if($d['infaq'] > 0) $pilihan_arr[] = ['nama' => $no_p++.'. Infaq Bangunan', 'nominal' => $d['infaq']];
if($d['seragam_psas'] > 0) $pilihan_arr[] = ['nama' => $no_p++.'. Seragam PSAS', 'nominal' => $d['seragam_psas']];
if($d['seragam_pramuka'] > 0) $pilihan_arr[] = ['nama' => $no_p++.'. Seragam Pramuka', 'nominal' => $d['seragam_pramuka']];

$wajib_arr = [];
$no_w = 1;
if(!empty($rincian_wajib)){
    foreach($rincian_wajib as $item => $harga){
        $wajib_arr[] = ['nama' => $no_w++.'. '.$item, 'nominal' => $harga];
    }
}
// Cari jumlah baris terbanyak antara list Kiri dan list Kanan
$max_rows = max(count($pilihan_arr), count($wajib_arr));
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <title>Kwitansi Biaya Awal PSB - <?php echo $d['nama_lengkap']; ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        /* RESET & BASE SETUP */
        * { box-sizing: border-box; }
        body { 
            font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; 
            font-size: 13px; 
            margin: 0; 
            padding: 20px; 
            color: #333; 
            background-color: #f4f6f9;
        }

        /* INVOICE WRAPPER */
        .invoice-box { 
            max-width: 21cm; 
            min-height: 29.7cm; 
            margin: auto; 
            padding: 40px; 
            background-color: #fff;
            box-shadow: 0 0 20px rgba(0, 0, 0, 0.1); 
            position: relative; 
            overflow: hidden;
        }

        /* KOP SURAT */
        .kop-surat { display: flex; align-items: center; justify-content: space-between; border-bottom: 3px solid #198754; padding-bottom: 12px; margin-bottom: 20px; }
        .kop-logo img { width: 80px; height: auto; margin-left: 70px; }
        .kop-text { text-align: center; flex-grow: 1; padding: 0 15px; }
        .kop-text h2 { margin: 0; font-size: 20px; font-weight: 800; color: #198754; letter-spacing: 1px; }
        .kop-text p { margin: 4px 0 0 0; font-size: 12px; color: #555; }
        
        .invoice-title { text-align: center; font-size: 16px; font-weight: bold; text-decoration: underline; margin-bottom: 15px; letter-spacing: 1px; color: #333; }

        /* TABEL IDENTITAS */
        .info-table { width: 100%; margin-bottom: 15px; font-size: 12px; }
        .info-table td { padding: 4px 0; vertical-align: top; }
        .info-table .label { width: 18%; color: #555; }
        .info-table .separator { width: 2%; text-align: center; font-weight: bold; }
        .info-table .value { width: 30%; font-weight: bold; color: #000; }

        /* TABEL RINCIAN BIAYA DUA KOLOM */
        .item-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; z-index: 10; position: relative; }
        .item-table th, .item-table td { border: 1px solid #ccc; padding: 6px 10px; font-size: 12px; }
        .item-table th { background-color: #198754 !important; color: #fff !important; text-align: center; font-weight: bold; text-transform: uppercase; }
        .item-table .section-title { background-color: #e9ecef !important; font-weight: bold; color: #198754 !important; text-align: center; font-size: 11px; }
        .item-table .grand-total { background-color: #e8f5e9 !important; font-size: 14px; color: #198754 !important; }
        
        /* HELPER */
        .text-right { text-align: right; }
        .text-center { text-align: center; }
        .fw-bold { font-weight: bold; }
        .empty-cell { background-color: transparent !important; }

        /* CATATAN */
        .notes { font-size: 11px; color: #444; background: #fff3cd !important; padding: 12px; border-left: 4px solid #ffc107 !important; margin-bottom: 30px; line-height: 1.5; }
        .rekening-box { background-color: #fff !important; padding: 8px; border: 1px dashed #198754; margin-top: 6px; margin-bottom: 6px; font-size: 12px; }
        .rekening-box ul { margin: 0; padding-left: 20px; }
        
        .footer { width: 100%; z-index: 10; position: relative; margin-top: auto; page-break-inside: avoid; }
        .footer td { text-align: center; width: 50%; font-size: 12px; }
        .sign-area { height: 70px; }
        .sign-line { display: inline-block; width: 180px; border-bottom: 1px solid #000; margin-top: 60px; }

        .stempel-lunas {
            position: absolute; top: 40%; left: 50%; transform: translate(-50%, -50%) rotate(-25deg); 
            font-size: 80px; color: rgba(25, 135, 84, 0.1) !important; font-weight: 900; z-index: 1; 
            border: 12px solid rgba(25, 135, 84, 0.1) !important; padding: 10px 40px; border-radius: 20px; 
            pointer-events: none; letter-spacing: 8px; text-align: center; z-index: 9999;
        }

        /* PRINT SETTINGS (MENCEGAH HALAMAN KOSONG DI AKHIR) */
        @page { size: A4 portrait; margin: 10mm; }
        @media print { 
            body { background-color: #fff; padding: 0; margin: 0; -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; } 
            .invoice-box { box-shadow: none; border: none; padding: 0; margin: 0 auto; width: 100%; max-width: 100%; min-height: auto; }
            .no-print { display: none !important; } 
        }

        .btn-print { 
            padding: 12px 25px; background: #198754; color: white; border: none;
            border-radius: 5px; font-weight: bold; margin-bottom: 20px; display: inline-block; cursor: pointer;
            box-shadow: 0 4px 6px rgba(25, 135, 84, 0.2); transition: 0.3s;
        }
        .btn-print:hover { background: #146c43; transform: translateY(-2px);}
    </style>
</head>
<body>

<div class="text-center no-print">
    <button class="btn-print" onclick="window.print()"><i class="fas fa-print me-2"></i> Cetak Kwitansi (A4)</button>
</div>

<div class="invoice-box">
    
    <?php if($d['status_pembayaran'] == 'Lunas'): ?>
        <div class="stempel-lunas">LUNAS<br>DIBAYAR</div>
    <?php endif; ?>

    <div class="kop-surat">
        <div class="kop-logo">
            <img src="logo_alhasan.png" alt="Logo Pesantren">
        </div>
        <div class="kop-text">
            <h2>PONDOK PESANTREN AL HASAN CIAMIS</h2>
            <p>Jl. Jend. A. Yani No. 120 Ciamis 46213 Jawa Barat</p>
            <p>Email: info@alhasan.co.id | Telp: (0265) 7578925</p>
        </div>
        <div style="width: 80px;"></div> 
    </div>

    <div class="invoice-title">KWITANSI PEMBAYARAN AWAL SANTRI BARU</div>

    <table class="info-table">
        <tr>
            <td class="label">No. Pendaftaran</td><td class="separator">:</td><td class="value text-success"><?php echo $d['no_pendaftaran']; ?></td>
            <td class="label">Kategori Biaya</td><td class="separator">:</td><td class="value"><?php echo $d['kategori_biaya']; ?></td>
        </tr>
        <tr>
            <td class="label">Nama Lengkap</td><td class="separator">:</td><td class="value"><?php echo strtoupper($d['nama_lengkap']); ?></td>
            <td class="label">Unit Sekolah</td><td class="separator">:</td><td class="value"><?php echo $d['jenjang_tujuan']; ?></td>
        </tr>
        <tr>
            <td class="label">Jenis Kelamin</td><td class="separator">:</td><td class="value"><?php echo ($d['jenis_kelamin']=='L')?'Laki-laki':'Perempuan'; ?></td>
            <td class="label">Metode Bayar</td><td class="separator">:</td><td class="value text-primary"><?php echo strtoupper($d['metode_pembayaran']); ?></td>
        </tr>
    </table>

    <table class="item-table">
        <thead>
            <tr>
                <th colspan="2" width="50%">A. PEMBIAYAAN PILIHAN (DIANGSUR)</th>
                <th colspan="2" width="50%">B. PEMBIAYAAN WAJIB (DISETUJUI)</th>
            </tr>
            <tr class="section-title">
                <td width="35%">Uraian</td><td width="15%">Nominal (Rp)</td>
                <td width="35%">Uraian</td><td width="15%">Nominal (Rp)</td>
            </tr>
        </thead>
        <tbody>
            <?php 
            // Cetak Baris Tabel secara sejajar (Kiri & Kanan)
            if($max_rows > 0) {
                for($i = 0; $i < $max_rows; $i++){
                    // Ambil data Pilihan (Kiri)
                    $pil_nama = isset($pilihan_arr[$i]) ? $pilihan_arr[$i]['nama'] : '';
                    $pil_nom  = isset($pilihan_arr[$i]) ? number_format($pilihan_arr[$i]['nominal'],0,',','.') : '';
                    $pil_bg   = isset($pilihan_arr[$i]) ? '' : 'empty-cell'; // Warna abu jika kosong

                    // Ambil data Wajib (Kanan)
                    $waj_nama = isset($wajib_arr[$i]) ? $wajib_arr[$i]['nama'] : '';
                    $waj_nom  = isset($wajib_arr[$i]) ? number_format($wajib_arr[$i]['nominal'],0,',','.') : '';
                    $waj_bg   = isset($wajib_arr[$i]) ? '' : 'empty-cell';

                    echo "<tr>";
                    echo "<td class='$pil_bg'>$pil_nama</td><td class='text-right fw-bold $pil_bg'>$pil_nom</td>";
                    echo "<td class='$waj_bg'>$waj_nama</td><td class='text-right fw-bold $waj_bg'>$waj_nom</td>";
                    echo "</tr>";
                }
            } else {
                echo "<tr><td colspan='4' class='text-center text-muted'>Tidak ada rincian biaya.</td></tr>";
            }
            ?>
            
            <tr class="grand-total">
                <td colspan="3" class="fw-bold text-right">TOTAL KESELURUHAN DIBAYARKAN</td>
                <td class="fw-bold text-right">Rp <?php echo number_format($d['total_keseluruhan'],0,',','.'); ?></td>
            </tr>
        </tbody>
    </table>

    <div class="notes">
        <b>Catatan Penting:</b><br>
        1. Harap bawa kwitansi cetak ini ke ruang Bendahara Pesantren sebagai bukti sah validasi pembayaran.<br>
        
        <?php if($d['metode_pembayaran'] == 'Transfer'): ?>
            2. Anda memilih metode <b>TRANSFER BANK</b>. Silakan lakukan pembayaran sesuai dengan <b style="color: #dc3545;">TOTAL KESELURUHAN</b> di atas ke rekening berikut:
            <div class="rekening-box">
                <ul>
                    <li><b>BSI: 1046-322-987</b> a.n Yayasan Pendidikan Ponpes Al Hasan</li>
                    <li><b>BRI: 0104-01001-218567</b> a.n Yayasan Pendidikan Pondok Pesantren Al Hasan</li>
                </ul>
            </div>
            <span style="color: #dc3545;">3. PENTING: Harap lampirkan bukti struk transfer fisik bersama kwitansi ini saat diserahkan kepada Bendahara.</span>
        <?php else: ?>
            2. Mohon simpan bukti pembayaran ini dengan baik setelah divalidasi dan dicap lunas oleh pihak pesantren.
        <?php endif; ?>
    </div>

    <table class="footer">
        <tr>
            <td>
                Orang Tua / Wali Santri<br>
                <div class="sign-line"></div><br>
                (Nama Jelas & Tanda Tangan)
            </td>
            <td>
                Ciamis, <?php echo date('d F Y', strtotime($d['tanggal'])); ?><br>
                Bendahara Penerimaan<br>
                <div class="sign-line"></div><br>
                (Nama Jelas, Tanda Tangan, Stempel)
            </td>
        </tr>
    </table>
</div>

</body>
</html>