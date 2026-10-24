<?php
use App\Psb\PendaftarSesi;
use App\Psb\PsbAkses;
use App\Report\PrintLayout;
use App\Situs\IsiSitus;
use App\Situs\Kerangka;

require_once __DIR__ . '/koneksi.php';

// Workstream keamanan PSB: kwitansi hanya untuk admin atau pendaftar pemilik
// yang sedang masuk portal; nomor diikat sebagai parameter dan isi di-escape.
//
// Redesain Tahap 8 (P-8): kop dan huruf sama dengan templat cetak bersama
// (X-1, `App\Report\PrintLayout`); penanda tangan tetap Orang tua/wali dan
// Bendahara penerimaan; bilah atas tidak ikut tercetak.
if(!isset($_GET['noreg']) || !is_string($_GET['noreg'])){
    echo "Nomor Registrasi Tidak Ditemukan!";
    exit;
}

$akses = new PsbAkses($koneksi, new PendaftarSesi($koneksi));
$pendaftarKwitansi = $akses->cari($_GET['noreg']);
if ($pendaftarKwitansi === null) {
    PsbAkses::tolak($akses->admin() ? 404 : 403);
}
if (!$akses->boleh($pendaftarKwitansi)) {
    PsbAkses::tolak(403);
}
header('Cache-Control: private, no-store');

$noreg = (string) $pendaftarKwitansi['no_pendaftaran'];
$stmtKwitansi = mysqli_prepare($koneksi, "SELECT p.*, pb.* FROM psb_pembayaran pb 
                                 JOIN psb_pendaftar p ON pb.no_pendaftaran = p.no_pendaftaran 
                                 WHERE pb.no_pendaftaran=?");
mysqli_stmt_bind_param($stmtKwitansi, 's', $noreg);
mysqli_stmt_execute($stmtKwitansi);
$query = mysqli_stmt_get_result($stmtKwitansi);

if(mysqli_num_rows($query) == 0){
    echo "Data Rincian Biaya belum diisi. Silakan isi terlebih dahulu di halaman sebelumnya.";
    exit;
}

$d = mysqli_fetch_assoc($query);
$rincian_wajib = json_decode($d['rincian_wajib'], true);

$pilihan = [['Syahriyyah (bulanan)', (int) $d['syahriyyah']]];
if ((int) $d['infaq'] > 0) {
    $pilihan[] = ['Infaq bangunan', (int) $d['infaq']];
}
if ((int) $d['seragam_psas'] > 0) {
    $pilihan[] = ['Seragam PSAS', (int) $d['seragam_psas']];
}
if ((int) $d['seragam_pramuka'] > 0) {
    $pilihan[] = ['Seragam Pramuka', (int) $d['seragam_pramuka']];
}
$wajib = [];
if (is_array($rincian_wajib)) {
    foreach ($rincian_wajib as $item => $harga) {
        $wajib[] = [(string) $item, (int) $harga];
    }
}
$baris = max(count($pilihan), count($wajib));
$e = static fn (mixed $v): string => PrintLayout::e($v);
$rp = static fn (int $n): string => number_format($n, 0, ',', '.');
$lunas = $d['status_pembayaran'] === 'Lunas';
$keterangan = trim('Panitia Penerimaan Santri Baru' . (IsiSitus::ada('alamat') ? ' · ' . IsiSitus::teks('alamat') : ''));
$kembali = $akses->admin() ? app_url('/admin/admin_pembayaran_psb.php') : app_url('/biaya_psb.php');
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Kwitansi <?= $e($d['no_pendaftaran']) ?></title>
    <style>
        <?= PrintLayout::cssDasar() ?>
        @page{size:A4 portrait;margin:14mm 14mm}
        body{font-size:10pt;max-width:190mm;margin:0 auto}
        @media print{@media (orientation:portrait){body{font-size:10pt}h1{font-size:16pt}h2{font-size:12pt}th{font-size:9pt}}}
        h2{font-size:12pt}
        .kapital{text-transform:uppercase}
        .report-nav{display:flex;flex-wrap:wrap;gap:8px;justify-content:flex-end;padding:8px 0;border-bottom:1px solid #ccd8cf}
        .report-nav a,.report-nav button{font:inherit;font-size:10pt;padding:8px 14px;border-radius:6px;border:1px solid #9dbfa8;background:#fff;color:#17231c;text-decoration:none;cursor:pointer;min-height:40px}
        .report-nav button{background:#146c43;border-color:#146c43;color:#fff}
        .lembar{position:relative}
        .info{display:grid;grid-template-columns:1fr 1fr;gap:1mm 8mm;margin:5mm 0}
        .info div{display:flex;gap:3mm}
        .info span{color:#4a544e;min-width:30mm;flex:0 0 auto}
        .info b{min-width:0;overflow-wrap:anywhere}
        @media screen and (max-width:600px){.info{grid-template-columns:minmax(0,1fr)}.report-nav{justify-content:space-between}}
        @media screen and (max-width:600px){table{font-size:8.5pt}th,td{padding:3px 2px}}
        th{font-size:9pt}
        td.nominal{text-align:right;white-space:nowrap;font-weight:700}
        td.kosong{background:#f6f8f6}
        tr.total td{font-weight:700;background:#e8f2ea}
        .catatan{margin-top:5mm;border:1px solid #bac8bd;border-radius:4px;padding:3mm 4mm;font-size:9pt}
        .catatan ol{margin:2mm 0 0;padding-left:5mm}
        .rek{margin:2mm 0 0;padding-left:5mm}
        .ttd-dua{display:grid;grid-template-columns:1fr 1fr;gap:10mm;margin-top:8mm;text-align:center;break-inside:avoid}
        .ttd-dua .ttd-ruang{height:18mm}
        .ttd-dua .ttd-garis{border-bottom:1px solid #17231c;margin:0 8mm}
        .stempel{position:absolute;top:30mm;right:6mm;transform:rotate(-12deg);border:3px solid #146c43;color:#146c43;font-weight:800;padding:2mm 4mm;text-align:center;font-size:14pt;letter-spacing:.08em}
    </style>
</head>
<body>
    <nav class="report-nav" aria-label="Tindakan dokumen">
        <a href="<?= $e($kembali) ?>">Kembali</a>
        <button type="button" onclick="window.print()">Cetak / Simpan PDF</button>
    </nav>
    <div class="lembar">
        <?= PrintLayout::kop('Kwitansi pembayaran awal santri baru', $keterangan) ?>
        <?php if ($lunas): ?><div class="stempel" aria-label="Status: lunas">LUNAS<br><small>DIBAYAR</small></div><?php endif; ?>
        <div class="info">
            <div><span>No. pendaftaran</span><b><?= $e($d['no_pendaftaran']) ?></b></div>
            <div><span>Kategori biaya</span><b><?= $e($d['kategori_biaya']) ?></b></div>
            <div><span>Nama lengkap</span><b class="kapital"><?= $e($d['nama_lengkap']) ?></b></div>
            <div><span>Jenjang</span><b><?= $e($d['jenjang_tujuan']) ?></b></div>
            <div><span>Jenis kelamin</span><b><?= $d['jenis_kelamin'] === 'L' ? 'Laki-laki' : 'Perempuan' ?></b></div>
            <div><span>Cara bayar</span><b><?= $d['metode_pembayaran'] === 'Transfer' ? 'Transfer bank' : 'Tunai' ?></b></div>
            <div><span>Status</span><b><?= $lunas ? 'Lunas' : 'Belum lunas' ?></b></div>
        </div>
        <table>
            <thead>
                <tr><th colspan="2" style="width:50%">A. Pembiayaan pilihan (dapat diangsur)</th><th colspan="2" style="width:50%">B. Pembiayaan wajib (dipilih)</th></tr>
                <tr><th style="width:32%">Uraian</th><th style="width:18%">Nominal (Rp)</th><th style="width:32%">Uraian</th><th style="width:18%">Nominal (Rp)</th></tr>
            </thead>
            <tbody>
                <?php for ($i = 0; $i < $baris; $i++): ?>
                    <tr>
                        <?php if (isset($pilihan[$i])): ?><td><?= $i + 1 ?>. <?= $e($pilihan[$i][0]) ?></td><td class="nominal"><?= $rp($pilihan[$i][1]) ?></td><?php else: ?><td class="kosong"></td><td class="kosong"></td><?php endif; ?>
                        <?php if (isset($wajib[$i])): [$item, $harga] = $wajib[$i]; ?><td><?= $i + 1 ?>. <?= ah_e($item) ?></td><td class="nominal"><?= $rp($harga) ?></td><?php else: ?><td class="kosong"></td><td class="kosong"></td><?php endif; ?>
                    </tr>
                <?php endfor; ?>
                <tr class="total"><td colspan="3" style="text-align:right">Total keseluruhan dibayarkan</td><td class="nominal">Rp <?= $rp((int) $d['total_keseluruhan']) ?></td></tr>
            </tbody>
        </table>
        <div class="catatan">
            <b>Catatan penting</b>
            <ol>
                <li>Bawa kwitansi ini ke ruang bendahara pesantren sebagai bukti validasi pembayaran.</li>
                <?php if ($d['metode_pembayaran'] === 'Transfer'): ?>
                    <li>Transfer sesuai total keseluruhan di atas ke salah satu rekening berikut, lalu lampirkan bukti transfer bersama kwitansi ini:
                        <ul class="rek">
                            <li>BSI 1046-322-987 a.n. Yayasan Pendidikan Ponpes Al Hasan</li>
                            <li>BRI 0104-01001-218567 a.n. Yayasan Pendidikan Pondok Pesantren Al Hasan</li>
                        </ul>
                    </li>
                <?php else: ?>
                    <li>Simpan kwitansi ini setelah divalidasi dan dicap lunas oleh pesantren.</li>
                <?php endif; ?>
            </ol>
        </div>
        <p style="text-align:right;margin-top:6mm">Ciamis, <?= $e(Kerangka::tanggal((string) $d['tanggal'], false) ?: Kerangka::tanggal(date('Y-m-d'))) ?></p>
        <div class="ttd-dua">
            <div><div class="ttd-jabatan">Orang tua/wali santri</div><div class="ttd-ruang"></div><div class="ttd-garis"></div><small>Nama jelas dan tanda tangan</small></div>
            <div><div class="ttd-jabatan">Bendahara penerimaan</div><div class="ttd-ruang"></div><div class="ttd-garis"></div><small>Nama jelas, tanda tangan, stempel</small></div>
        </div>
        <div class="lembar-footer"><span class="lembar-catatan">Dicetak <?= $e(date('d-m-Y H.i')) ?> WIB</span><span class="lembar-nomor">Halaman 1 dari 1</span></div>
    </div>
</body>
</html>
