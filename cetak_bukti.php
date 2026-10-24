<?php
use App\Psb\PendaftarSesi;
use App\Psb\PsbAkses;
use App\Report\PrintLayout;
use App\Situs\IsiSitus;
use App\Situs\Kerangka;

require_once __DIR__ . '/koneksi.php';

// Workstream keamanan PSB: bukti hanya untuk admin, pendaftar pemilik yang
// sedang masuk portal, atau peramban yang baru mengirim pendaftaran ini.
// Nomor pendaftaran diikat sebagai parameter query dan isi di-escape.
//
// Redesain Tahap 8 (P-8): kop dan huruf sama dengan templat cetak bersama
// (X-1, `App\Report\PrintLayout`); bilah atas tidak ikut tercetak.
$akses = new PsbAkses($koneksi, new PendaftarSesi($koneksi));
$d = $akses->cari(is_string($_GET['noreg'] ?? null) ? $_GET['noreg'] : '');
if ($d === null) {
    PsbAkses::tolak($akses->admin() ? 404 : 403);
}
if (!$akses->boleh($d, true)) {
    PsbAkses::tolak(403);
}
header('Cache-Control: private, no-store');

$e = static fn (mixed $v): string => PrintLayout::e($v);
$tahun = IsiSitus::tahunPsb();
$keterangan = trim('Panitia Penerimaan Santri Baru' . (IsiSitus::ada('alamat') ? ' · ' . IsiSitus::teks('alamat') : ''));
$kembali = $akses->admin() ? app_url('/admin/admin_data.php') : app_url($akses->pendaftar() !== null ? '/portal_pendaftar.php' : '/psb.php');
$waktuDaftar = !empty($d['tgl_daftar']) ? Kerangka::tanggal((string) $d['tgl_daftar']) : '';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Bukti pendaftaran <?= $e($d['no_pendaftaran']) ?></title>
    <style>
        <?= PrintLayout::cssDasar() ?>
        @page{size:A4 portrait;margin:14mm 14mm}
        body{font-size:10.5pt;max-width:190mm;margin:0 auto}
        @media print{@media (orientation:portrait){body{font-size:10.5pt}h1{font-size:16pt}h2{font-size:12pt}}}
        h2{font-size:12pt}
        .kapital{text-transform:uppercase}
        .report-nav{display:flex;flex-wrap:wrap;gap:8px;justify-content:flex-end;padding:8px 0;border-bottom:1px solid #ccd8cf}
        .report-nav a,.report-nav button{font:inherit;font-size:10pt;padding:8px 14px;border-radius:6px;border:1px solid #9dbfa8;background:#fff;color:#17231c;text-decoration:none;cursor:pointer;min-height:40px}
        .report-nav button{background:#146c43;border-color:#146c43;color:#fff}
        .isi{display:grid;grid-template-columns:minmax(0,1fr) 34mm;gap:8mm;margin-top:6mm}
        .kv{width:100%;border-collapse:collapse;table-layout:auto}
        .kv td{border:0;padding:3px 0;vertical-align:top}
        .kv td:first-child{width:42mm;color:#4a544e}
        .kv b{font-size:11pt}
        .foto{width:30mm;height:40mm;border:1px solid #17231c;display:flex;align-items:center;justify-content:center;text-align:center;font-size:8pt;color:#59665e}
        .noreg{margin-top:4mm;border:2px solid #17231c;padding:2mm;text-align:center;font-weight:700;font-family:monospace;font-size:10pt;word-break:break-all}
        .catatan{margin-top:8mm;border:1px solid #bac8bd;border-radius:4px;padding:3mm 4mm;font-size:9.5pt}
        .catatan ol{margin:2mm 0 0;padding-left:5mm}
        .tanggal-ttd{text-align:right;margin-top:8mm}
        .ttd-kolom{width:60mm}
    </style>
</head>
<body>
    <nav class="report-nav" aria-label="Tindakan dokumen">
        <a href="<?= $e($kembali) ?>">Kembali</a>
        <button type="button" onclick="window.print()">Cetak / Simpan PDF</button>
    </nav>
    <div class="lembar">
        <?= PrintLayout::kop('Kartu bukti pendaftaran' . ($tahun !== '' ? ' ' . $tahun : ''), $keterangan) ?>
        <div class="isi">
            <table class="kv">
                <tr><td>No. pendaftaran</td><td><b><?= $e($d['no_pendaftaran']) ?></b></td></tr>
                <tr><td>Nama lengkap</td><td><b class="kapital"><?= $e($d['nama_lengkap']) ?></b></td></tr>
                <tr><td>NISN</td><td><?= $e($d['nisn']) ?></td></tr>
                <tr><td>Tempat, tanggal lahir</td><td><?= $e($d['tempat_lahir'] . ', ' . Kerangka::tanggal((string) $d['tgl_lahir'], false)) ?></td></tr>
                <tr><td>Jenis kelamin</td><td><?= $d['jenis_kelamin'] === 'L' ? 'Laki-laki' : 'Perempuan' ?></td></tr>
                <tr><td>Sekolah asal</td><td><?= $e($d['sekolah_asal']) ?></td></tr>
                <tr><td>Jenjang tujuan</td><td><b><?= $e($d['jenjang_tujuan']) ?></b></td></tr>
                <?php if ($waktuDaftar !== ''): ?><tr><td>Tanggal daftar</td><td><?= $e($waktuDaftar) ?></td></tr><?php endif; ?>
            </table>
            <div>
                <div class="foto">Tempel foto<br>3 × 4</div>
                <div class="noreg"><?= $e($d['no_pendaftaran']) ?></div>
            </div>
        </div>
        <div class="catatan">
            <b>Catatan penting</b>
            <ol>
                <li>Kartu ini wajib dibawa saat daftar ulang atau tes seleksi.</li>
                <li>Lengkapi berkas digital (KK, akta kelahiran, KTP orang tua, ijazah/SKL) melalui Portal Pendaftar.</li>
                <li>Masuk Portal Pendaftar dengan NISN dan No. HP wali yang didaftarkan.</li>
            </ol>
        </div>
        <p class="tanggal-ttd">Ciamis, <?= $e(Kerangka::tanggal(date('Y-m-d'), false)) ?></p>
        <?= PrintLayout::tandaTangan('Panitia PSB') ?>
        <div class="lembar-footer"><span class="lembar-catatan">Dicetak <?= $e(date('d-m-Y H.i')) ?> WIB</span><span class="lembar-nomor">Halaman 1 dari 1</span></div>
    </div>
</body>
</html>
