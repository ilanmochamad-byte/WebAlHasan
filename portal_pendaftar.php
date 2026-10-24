<?php
/*
 * Portal Pendaftar (P-4 status, P-5 data diri, P-6 berkas) — redesain website
 * Tahap 8. Pembiayaan (P-7) tetap di `biaya_psb.php` dan dibuka dari menu
 * Pembiayaan portal (keputusan Human Developer 24 Oktober 2026, butir 3).
 *
 * Satu halaman dengan tiga bagian (#status, #data, #berkas). Dengan
 * JavaScript bagian tampil sebagai tab; tanpa JavaScript semua bagian tampil
 * berurutan. Data diri dibaca dulu, diubah per bagian. Berkas diunggah satu
 * per satu dengan tombolnya sendiri.
 *
 * Workstream keamanan PSB tetap: sesi pendaftar diperiksa ulang setiap
 * permintaan (pendaftar dihapus atau NISN/No. HP berubah dari sesi lain ->
 * keluar), dan biodata hanya dapat diubah untuk baris milik sendiri lewat
 * POST ber-CSRF (`PendaftarData::ubah`). Setiap formulir ubah mengirim
 * seluruh isian biodata seperti formulir lama.
 */
use App\Http\Csrf;
use App\Psb\Jenjang;
use App\Psb\PendaftarData;
use App\Psb\PendaftarSesi;
use App\Psb\PsbAdmin;
use App\Psb\PsbException;
use App\Situs\Kerangka;

require_once __DIR__ . '/koneksi.php';

$sesiPendaftar = new PendaftarSesi($koneksi);
$d = $sesiPendaftar->wajib('login_pendaftar.php');
$id_user = (int) $d['id'];

/** Isian biodata per bagian ubah (P-5). */
$bagianIsian = [
    'calon' => ['nama_lengkap', 'nik', 'tempat_lahir', 'tgl_lahir', 'jenis_kelamin', 'jenjang_tujuan', 'sekolah_asal'],
    'alamat' => ['alamat_jalan', 'provinsi', 'kab_kota', 'kecamatan', 'desa'],
    'ortu' => ['nama_ayah', 'nama_ibu', 'no_hp_wali'],
];

// ==========================================
// PROSES UPDATE DATA DIRI
// ==========================================
$pesanPortal = null;
$galatUbah = [];
$bagianUbah = null;
$kiriman = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_data'])) {
    $bagianUbah = is_string($_POST['bagian_ubah'] ?? null) && isset($bagianIsian[$_POST['bagian_ubah']]) ? $_POST['bagian_ubah'] : 'calon';
    if (!Csrf::validate($_POST['_csrf'] ?? null)) {
        $pesanPortal = ['danger', 'Halaman sudah kedaluwarsa. Data belum tersimpan; muat ulang halaman lalu simpan lagi.', 'data'];
    } else {
        try {
            $d = (new PendaftarData($koneksi))->ubah($id_user, $_POST);
            $sesiPendaftar->perbarui($d);
            $_SESSION['_psb_portal_pesan'] = 'Data diri berhasil diperbarui.';
            $_SESSION['_psb_portal_bagian'] = 'data';
            header('Location: portal_pendaftar.php#data');
            exit;
        } catch (PsbException $exception) {
            $galatUbah = $exception->errors();
            $kiriman = array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $_POST);
            // Buka bagian yang memuat galat pertama (isian tersembunyi bisa berisi data lama tidak sah).
            foreach ($bagianIsian as $kunci => $isian) {
                if (array_intersect($isian, array_keys($galatUbah)) !== []) {
                    $bagianUbah = $kunci;
                    break;
                }
            }
            $pesanPortal = ['danger', $exception->getMessage() . ($galatUbah === [] ? '' : ' ' . implode(' ', $galatUbah)), 'data'];
        }
    }
}
if ($pesanPortal === null && isset($_SESSION['_psb_portal_pesan'])) {
    $teks = (string) $_SESSION['_psb_portal_pesan'];
    $bagianPesan = (string) ($_SESSION['_psb_portal_bagian'] ?? 'status');
    $pesanPortal = [str_contains($teks, 'Belum tersimpan') || str_contains($teks, 'kedaluwarsa') || str_contains($teks, 'terlalu besar') || str_starts_with($teks, 'Pilih') ? 'warn' : 'ok', $teks, $bagianPesan];
}
$hasilBerkas = is_array($_SESSION['_psb_berkas_hasil'] ?? null) ? $_SESSION['_psb_berkas_hasil'] : [];
unset($_SESSION['_psb_portal_pesan'], $_SESSION['_psb_portal_bagian'], $_SESSION['_psb_berkas_hasil']);

// ==========================================
// STATUS DAN TAHAPAN
// ==========================================
$wajib = ['file_kk' => 'Kartu Keluarga', 'file_akta' => 'Akta kelahiran', 'file_ktp' => 'KTP orang tua'];
$opsional = ['file_ijazah' => ['Ijazah / SKL', 'Boleh menyusul'], 'file_nilai' => ['Rapor / nilai akhir', ''], 'file_prestasi' => ['Sertifikat prestasi', '']];
$kurang = array_values(array_filter($wajib, static fn (string $l, string $k): bool => empty($d[$k]), ARRAY_FILTER_USE_BOTH));
$berkas_lengkap = $kurang === [];
$status_psb = (string) $d['status'];
$tahap = [
    ['Isi data', 'is-selesai'],
    ['Unggah berkas', $berkas_lengkap ? 'is-selesai' : 'kini'],
    ['Verifikasi', in_array($status_psb, ['Diterima', 'Ditolak', 'Cadangan', 'Dimigrasi'], true) ? 'is-selesai' : ($berkas_lengkap ? 'kini' : '')],
    ['Hasil seleksi', match ($status_psb) { 'Diterima', 'Dimigrasi' => 'is-selesai', 'Ditolak' => 'is-gagal', 'Cadangan' => 'kini', default => '' }],
    ['Daftar ulang', $status_psb === 'Dimigrasi' ? 'is-selesai' : ($status_psb === 'Diterima' ? 'kini' : '')],
];
$daftarKurang = static function (array $label): string {
    return count($label) === 1 ? $label[0] : implode(', ', array_slice($label, 0, -1)) . ' dan ' . end($label);
};
[$badgeJenis, $badgeTeks, $langkahTeks, $langkahTombol] = match (true) {
    $status_psb === 'Ditolak' => ['danger', 'Tidak diterima', 'Mohon maaf, Anda dinyatakan tidak lulus seleksi. Hubungi panitia PSB bila ada pertanyaan.', null],
    $status_psb === 'Cadangan' => ['warn', 'Cadangan', 'Status Anda Cadangan. Harap menunggu informasi lebih lanjut dari panitia.', null],
    $status_psb === 'Dimigrasi' => ['ok', 'Sudah daftar ulang', 'Data Anda sudah dipindahkan ke data santri pesantren.', null],
    $status_psb === 'Diterima' => ['ok', 'Diterima', 'Selamat! Anda dinyatakan diterima. Lanjutkan dengan rincian pembiayaan daftar ulang.', ['Buka pembiayaan', app_url('/biaya_psb.php')]],
    !$berkas_lengkap => ['warn', 'Belum lengkap', 'Unggah ' . count($kurang) . ' berkas wajib lagi: ' . $daftarKurang($kurang) . '.', ['Unggah berkas', '#berkas']],
    default => ['info', 'Diperiksa', 'Data dan berkas Anda sedang diperiksa panitia. Hasil seleksi akan tampil di halaman ini.', null],
};

$e = static fn (mixed $v): string => Kerangka::e($v);
$nilai = static fn (string $k): string => array_key_exists($k, $kiriman) ? $kiriman[$k] : (string) ($d[$k] ?? '');
$galat = static fn (string $k): string => isset($galatUbah[$k]) ? '<small class="s-error">' . Kerangka::e($galatUbah[$k]) . '</small>' : '';
$tgl = strtotime((string) $d['tgl_lahir']);
$nilaiTampil = static fn (mixed $v): string => trim((string) $v) === '' ? '—' : Kerangka::e($v);

/** Isian tersembunyi untuk biodata di luar bagian yang sedang diubah. */
$tersembunyi = static function (string $bagian) use ($bagianIsian, $d, $e): string {
    $html = '';
    foreach (['nama_lengkap', 'nik', 'tempat_lahir', 'tgl_lahir', 'jenis_kelamin', 'jenjang_tujuan', 'sekolah_asal', 'alamat_jalan', 'nama_ayah', 'nama_ibu', 'no_hp_wali'] as $k) {
        if (!in_array($k, $bagianIsian[$bagian], true)) {
            $html .= '<input type="hidden" name="' . $e($k) . '" value="' . $e($d[$k] ?? '') . '">';
        }
    }

    return $html;
};
$kepalaForm = static fn (string $bagian): string => '<input type="hidden" name="update_data" value="1"><input type="hidden" name="bagian_ubah" value="' . $bagian . '">';
$pesanDi = static function (string $bagian) use ($pesanPortal, $e): string {
    if ($pesanPortal === null || $pesanPortal[2] !== $bagian) {
        return '';
    }
    [$jenis, $teks] = $pesanPortal;
    $judul = ['ok' => 'Berhasil', 'warn' => 'Perhatian', 'danger' => 'Belum tersimpan'][$jenis] ?? 'Informasi';

    return '<div class="ah-note ah-note--' . $jenis . '" role="' . ($jenis === 'danger' ? 'alert' : 'status') . '" data-s-toast="' . $jenis . '" data-s-toast-teks="' . $e($teks) . '">'
        . '<div><strong class="ah-note__title">' . $judul . '</strong><span>' . $e($teks) . '</span></div></div>';
};
$bagianAwal = $bagianUbah !== null ? 'data' : ($pesanPortal[2] ?? 'status');

Kerangka::bukaPortal($d, 'status', 'Status pendaftaran', true);
?>
<div data-s-bagian-awal="<?= $e($bagianAwal) ?>" hidden></div>

<section class="s-portal-sec" id="status" aria-labelledby="judul-status">
    <h1 id="judul-status">Status pendaftaran</h1>
    <p class="s-sub"><span class="s-mono"><?= $e($d['no_pendaftaran']) ?></span> · <?= $e($d['jenjang_tujuan']) ?></p>
    <?= $pesanDi('status') ?>
    <ol class="s-stepper s-stepper--tegak" aria-label="Tahapan pendaftaran">
        <?php foreach ($tahap as [$label, $keadaan]): ?>
            <li<?= $keadaan === 'kini' ? ' aria-current="step"' : ($keadaan !== '' ? ' class="' . $keadaan . '"' : '') ?>><span><?= $e($label) ?><?= $keadaan === 'is-selesai' ? '<span class="s-vh"> (selesai)</span>' : ($keadaan === 'kini' ? '<span class="s-vh"> (tahap sekarang)</span>' : ($keadaan === 'is-gagal' ? '<span class="s-vh"> (tidak lulus)</span>' : '')) ?></span></li>
        <?php endforeach; ?>
    </ol>
    <section class="s-panel" aria-labelledby="judul-langkah">
        <div class="s-panel__head"><h2 id="judul-langkah">Langkah berikutnya</h2><span class="ah-badge ah-badge--<?= $e($badgeJenis) ?>"><?= $e($badgeTeks) ?></span></div>
        <p class="<?= $langkahTombol === null ? 'mb-0' : '' ?>"><?= $e($langkahTeks) ?></p>
        <?php if ($langkahTombol !== null): ?>
            <a class="s-btn s-btn--pri" href="<?= $e($langkahTombol[1]) ?>"<?= str_starts_with($langkahTombol[1], '#') ? ' data-s-bagian-ke' : '' ?>><?= $e($langkahTombol[0]) ?></a>
        <?php endif; ?>
    </section>
    <div class="s-split" style="grid-template-columns:repeat(auto-fit,minmax(min(100%,260px),1fr));margin-top:1rem">
        <section class="s-panel" aria-labelledby="judul-ringkasan">
            <h2 id="judul-ringkasan" class="h6 fw-bold">Ringkasan</h2>
            <dl class="s-dl">
                <dt>Nama</dt><dd><?= $e($d['nama_lengkap']) ?></dd>
                <dt>Jenjang</dt><dd><?= $e($d['jenjang_tujuan']) ?></dd>
                <dt>Didaftar</dt><dd><?= $e(Kerangka::tanggal((string) ($d['tgl_daftar'] ?? '')) ?: '—') ?></dd>
            </dl>
            <p class="mt-3 mb-0"><a href="<?= $e(app_url('/cetak_bukti.php?noreg=' . rawurlencode((string) $d['no_pendaftaran']))) ?>" target="_blank" rel="noopener">Cetak bukti pendaftaran<span class="s-vh"> (tab baru)</span></a></p>
        </section>
        <section class="s-panel" aria-labelledby="judul-pembiayaan">
            <h2 id="judul-pembiayaan" class="h6 fw-bold">Pembiayaan</h2>
            <p>Rincian biaya daftar ulang, pilihan komponen, cara bayar, dan kwitansi.</p>
            <a class="s-btn" href="<?= $e(app_url('/biaya_psb.php')) ?>">Rincian &amp; pembayaran</a>
        </section>
    </div>
</section>

<section class="s-portal-sec" id="data" aria-labelledby="judul-data">
    <h1 id="judul-data">Data diri</h1>
    <p class="s-sub">Periksa data Anda. Ubah per bagian bila ada yang keliru.</p>
    <?= $pesanDi('data') ?>

    <section class="s-panel" aria-labelledby="judul-calon">
        <details class="s-ubah"<?= $bagianUbah === 'calon' ? ' open' : '' ?>>
            <summary class="s-panel__head"><h2 id="judul-calon">Calon santri</h2><span class="s-btn s-btn--sm">Ubah<span class="s-vh"> data calon santri</span></span></summary>
            <form class="s-ubah__form s-form s-form--2" method="POST" action="portal_pendaftar.php">
                <?= Csrf::input() ?>
                <?= $kepalaForm('calon') . $tersembunyi('calon') ?>
                <div class="s-field s-field--penuh"><label class="s-label" for="u-nama">Nama lengkap <em>*</em></label><input class="s-input" id="u-nama" name="nama_lengkap" required maxlength="100" value="<?= $e($nilai('nama_lengkap')) ?>"><?= $galat('nama_lengkap') ?></div>
                <div class="s-field"><label class="s-label" for="u-nisn">NISN</label><input class="s-input" id="u-nisn" value="<?= $e($d['nisn']) ?>" readonly aria-describedby="u-nisn-bantu"><small class="s-hint" id="u-nisn-bantu">NISN tidak dapat diubah. Hubungi panitia bila keliru.</small></div>
                <div class="s-field"><label class="s-label" for="u-nik">NIK <em>*</em></label><input class="s-input" id="u-nik" name="nik" inputmode="numeric" pattern="[0-9]{1,20}" maxlength="20" required value="<?= $e($nilai('nik')) ?>"><?= $galat('nik') ?></div>
                <div class="s-field"><label class="s-label" for="u-tempat">Tempat lahir <em>*</em></label><input class="s-input" id="u-tempat" name="tempat_lahir" required maxlength="50" value="<?= $e($nilai('tempat_lahir')) ?>"><?= $galat('tempat_lahir') ?></div>
                <div class="s-field"><label class="s-label" for="u-tgl">Tanggal lahir <em>*</em></label><input class="s-input" id="u-tgl" name="tgl_lahir" type="date" max="<?= date('Y-m-d') ?>" required value="<?= $e($nilai('tgl_lahir')) ?>"><?= $galat('tgl_lahir') ?></div>
                <div class="s-field s-field--penuh" role="radiogroup" aria-labelledby="u-jk-label">
                    <span class="s-label" id="u-jk-label">Jenis kelamin <em>*</em></span>
                    <div class="s-radios">
                        <label><input type="radio" name="jenis_kelamin" value="L" required<?= $nilai('jenis_kelamin') === 'L' ? ' checked' : '' ?>> Laki-laki</label>
                        <label><input type="radio" name="jenis_kelamin" value="P"<?= $nilai('jenis_kelamin') === 'P' ? ' checked' : '' ?>> Perempuan</label>
                    </div><?= $galat('jenis_kelamin') ?>
                </div>
                <div class="s-field">
                    <label class="s-label" for="u-jenjang">Jenjang tujuan <em>*</em></label>
                    <select class="s-select" id="u-jenjang" name="jenjang_tujuan" required>
                        <?php $jenjangKini = $nilai('jenjang_tujuan'); $tersimpan = (string) $d['jenjang_tujuan']; ?>
                        <?php if (!isset(Jenjang::UNIT_AL_HASAN[$tersimpan]) && !isset(Jenjang::SEKOLAH_MITRA[$tersimpan])): ?>
                            <option value="<?= $e($tersimpan) ?>" selected><?= $e($tersimpan) ?></option>
                        <?php endif; ?>
                        <?php foreach (['Unit Al Hasan' => Jenjang::UNIT_AL_HASAN, 'Sekolah Mitra' => Jenjang::SEKOLAH_MITRA] as $grup => $pilihan): ?>
                            <optgroup label="<?= $e($grup) ?>">
                                <?php foreach ($pilihan as $v => $l): $boleh = Jenjang::galat($v, $tersimpan) === null; ?>
                                    <option value="<?= $e($v) ?>"<?= $jenjangKini === $v ? ' selected' : '' ?><?= $boleh ? '' : ' disabled' ?>><?= $e($l) ?><?= $boleh ? '' : ' (belum dibuka)' ?></option>
                                <?php endforeach; ?>
                            </optgroup>
                        <?php endforeach; ?>
                    </select><?= $galat('jenjang_tujuan') ?>
                </div>
                <div class="s-field"><label class="s-label" for="u-sekolah">Sekolah asal <em>*</em></label><input class="s-input" id="u-sekolah" name="sekolah_asal" required maxlength="100" value="<?= $e($nilai('sekolah_asal')) ?>"><?= $galat('sekolah_asal') ?></div>
                <div class="s-acts s-field--penuh"><button class="s-btn s-btn--pri" type="submit" data-s-memproses="Menyimpan…">Simpan data calon santri</button></div>
            </form>
        </details>
        <dl class="s-dl">
            <dt>Nama</dt><dd><?= $nilaiTampil($d['nama_lengkap']) ?></dd>
            <dt>NISN</dt><dd><span class="s-mono"><?= $nilaiTampil($d['nisn']) ?></span> <small class="s-muted">(tidak dapat diubah)</small></dd>
            <dt>NIK</dt><dd class="s-mono"><?= $nilaiTampil($d['nik']) ?></dd>
            <dt>Tempat, tanggal lahir</dt><dd><?= $nilaiTampil($d['tempat_lahir']) ?>, <?= $tgl ? $e(Kerangka::tanggal((string) $d['tgl_lahir'])) : $nilaiTampil($d['tgl_lahir']) ?></dd>
            <dt>Jenis kelamin</dt><dd><?= $d['jenis_kelamin'] === 'P' ? 'Perempuan' : 'Laki-laki' ?></dd>
            <dt>Jenjang tujuan</dt><dd><?= $nilaiTampil($d['jenjang_tujuan']) ?></dd>
            <dt>Sekolah asal</dt><dd><?= $nilaiTampil($d['sekolah_asal']) ?></dd>
        </dl>
    </section>

    <section class="s-panel" aria-labelledby="judul-alamat">
        <details class="s-ubah"<?= $bagianUbah === 'alamat' ? ' open' : '' ?>>
            <summary class="s-panel__head"><h2 id="judul-alamat">Alamat</h2><span class="s-btn s-btn--sm">Ubah<span class="s-vh"> alamat</span></span></summary>
            <form class="s-ubah__form s-form" method="POST" action="portal_pendaftar.php">
                <?= Csrf::input() ?>
                <?= $kepalaForm('alamat') . $tersembunyi('alamat') ?>
                <div class="s-field"><label class="s-label" for="u-jalan">Jalan, dusun, RT/RW <em>*</em></label><textarea class="s-textarea" id="u-jalan" name="alamat_jalan" rows="2" required maxlength="1000"><?= $e($nilai('alamat_jalan')) ?></textarea><?= $galat('alamat_jalan') ?></div>
                <div class="s-form" data-s-wilayah data-s-wilayah-tunda>
                    <label class="s-check s-js-only" for="u-ganti-wilayah"><input type="checkbox" id="u-ganti-wilayah" data-s-wilayah-aktifkan> <span>Pindah wilayah (provinsi, kabupaten/kota, kecamatan, desa)</span></label>
                    <div class="s-form s-form--2" data-s-wilayah-pilih hidden>
                        <div class="s-field"><label class="s-label" for="provinsi">Provinsi</label><select class="s-select" id="provinsi"><option value="">Pilih provinsi…</option></select></div>
                        <div class="s-field"><label class="s-label" for="kabupaten">Kabupaten/kota</label><select class="s-select" id="kabupaten" disabled><option value="">Pilih kabupaten/kota…</option></select></div>
                        <div class="s-field"><label class="s-label" for="kecamatan">Kecamatan</label><select class="s-select" id="kecamatan" disabled><option value="">Pilih kecamatan…</option></select></div>
                        <div class="s-field"><label class="s-label" for="desa">Desa/kelurahan</label><select class="s-select" id="desa" disabled><option value="">Pilih desa/kelurahan…</option></select></div>
                        <div class="ah-note ah-note--warn s-field--penuh" data-s-wilayah-pesan hidden role="alert"><div><strong class="ah-note__title">Daftar wilayah gagal dimuat</strong><span>Coba lagi atau ketik nama wilayah sendiri.</span>
                            <div class="s-acts mt-2"><button class="s-btn s-btn--sm" type="button" data-s-wilayah-ulang>Coba lagi</button><button class="s-btn s-btn--sm" type="button" data-s-wilayah-ketik>Ketik manual</button></div></div></div>
                    </div>
                    <div class="s-form s-form--2" data-s-wilayah-manual>
                        <p class="s-hint s-field--penuh mb-0">Isi hanya bila pindah wilayah; kosongkan untuk mempertahankan wilayah sekarang.</p>
                        <?php foreach (['nama_provinsi' => 'Provinsi', 'nama_kabupaten' => 'Kabupaten/kota', 'nama_kecamatan' => 'Kecamatan', 'nama_desa' => 'Desa/kelurahan'] as $isian => $labelWilayah): ?>
                            <div class="s-field"><label class="s-label" for="u-<?= $e($isian) ?>"><?= $e($labelWilayah) ?></label><input class="s-input" id="u-<?= $e($isian) ?>" name="<?= $e($isian) ?>" maxlength="50" value="<?= $e($kiriman[$isian] ?? '') ?>"></div>
                        <?php endforeach; ?>
                    </div>
                    <?= $galat('provinsi') . $galat('kab_kota') . $galat('kecamatan') . $galat('desa') ?>
                </div>
                <div class="s-acts"><button class="s-btn s-btn--pri" type="submit" data-s-memproses="Menyimpan…">Simpan alamat</button></div>
            </form>
        </details>
        <dl class="s-dl">
            <dt>Jalan</dt><dd><?= $nilaiTampil($d['alamat_jalan']) ?></dd>
            <dt>Desa · kecamatan</dt><dd><?= $nilaiTampil($d['desa']) ?> · <?= $nilaiTampil($d['kecamatan']) ?></dd>
            <dt>Kab./kota · provinsi</dt><dd><?= $nilaiTampil($d['kab_kota']) ?> · <?= $nilaiTampil($d['provinsi']) ?></dd>
        </dl>
    </section>

    <section class="s-panel" aria-labelledby="judul-ortu">
        <details class="s-ubah"<?= $bagianUbah === 'ortu' ? ' open' : '' ?>>
            <summary class="s-panel__head"><h2 id="judul-ortu">Orang tua</h2><span class="s-btn s-btn--sm">Ubah<span class="s-vh"> data orang tua</span></span></summary>
            <form class="s-ubah__form s-form s-form--2" method="POST" action="portal_pendaftar.php" data-s-konfirmasi-ubah="no_hp_wali"
                  data-s-konfirmasi-judul="Ganti No. HP wali?" data-s-konfirmasi-teks="Nomor baru akan dipakai untuk masuk Portal Pendaftar. Sesi di perangkat lain akan berakhir." data-s-konfirmasi-ok="Ya, ganti nomor">
                <?= Csrf::input() ?>
                <?= $kepalaForm('ortu') . $tersembunyi('ortu') ?>
                <div class="s-field"><label class="s-label" for="u-ayah">Nama ayah <em>*</em></label><input class="s-input" id="u-ayah" name="nama_ayah" required maxlength="100" value="<?= $e($nilai('nama_ayah')) ?>"><?= $galat('nama_ayah') ?></div>
                <div class="s-field"><label class="s-label" for="u-ibu">Nama ibu <em>*</em></label><input class="s-input" id="u-ibu" name="nama_ibu" required maxlength="100" value="<?= $e($nilai('nama_ibu')) ?>"><?= $galat('nama_ibu') ?></div>
                <div class="s-field s-field--penuh"><label class="s-label" for="u-hp">No. HP/WhatsApp wali <em>*</em></label>
                    <input class="s-input" id="u-hp" name="no_hp_wali" inputmode="numeric" pattern="[0-9]{8,15}" maxlength="15" required value="<?= $e($nilai('no_hp_wali')) ?>" aria-describedby="u-hp-bantu">
                    <small class="s-hint" id="u-hp-bantu">Nomor ini dipakai untuk masuk portal. Bila diganti, masuk berikutnya memakai nomor baru.</small><?= $galat('no_hp_wali') ?></div>
                <div class="s-acts s-field--penuh"><button class="s-btn s-btn--pri" type="submit" data-s-memproses="Menyimpan…">Simpan data orang tua</button></div>
            </form>
        </details>
        <dl class="s-dl">
            <dt>Ayah</dt><dd><?= $nilaiTampil($d['nama_ayah']) ?></dd>
            <dt>Ibu</dt><dd><?= $nilaiTampil($d['nama_ibu']) ?></dd>
            <dt>No. HP wali</dt><dd><span class="s-mono"><?= $nilaiTampil($d['no_hp_wali']) ?></span> <small class="s-muted">· dipakai untuk masuk</small></dd>
        </dl>
    </section>
</section>

<section class="s-portal-sec" id="berkas" aria-labelledby="judul-berkas">
    <h1 id="judul-berkas">Berkas pendaftaran</h1>
    <p class="s-sub">JPG, PNG, atau PDF · maksimal 2 MB per berkas. Mengunggah ulang menggantikan berkas sebelumnya.</p>
    <?= $pesanDi('berkas') ?>
    <?php $jumlahWajib = count($wajib) - count($kurang); ?>
    <div class="s-panel mb-3">
        <div class="s-panel__head"><h2 class="h6 fw-bold m-0">Berkas wajib</h2><span><?= $jumlahWajib ?> dari <?= count($wajib) ?></span></div>
        <div class="s-meter" role="img" aria-label="<?= $jumlahWajib ?> dari <?= count($wajib) ?> berkas wajib terunggah"><span style="width:<?= (int) round($jumlahWajib / count($wajib) * 100) ?>%"></span></div>
    </div>
    <?php foreach (['Wajib' => array_map(static fn (string $l): array => [$l, 'Wajib'], $wajib), 'Opsional' => $opsional] as $grup => $daftarBerkas): ?>
        <h2 class="h6 fw-bold mt-4"><?= $e($grup) ?></h2>
        <ul class="s-upload">
            <?php foreach ($daftarBerkas as $kolom => [$label, $ket]): $ada = !empty($d[$kolom]); $hasil = $hasilBerkas[$kolom] ?? null; ?>
                <li id="berkas-<?= $e($kolom) ?>">
                    <div class="s-upload__head">
                        <b><?= $e($label) ?></b>
                        <?= $ada ? '<span class="ah-badge ah-badge--ok">Terunggah</span>' : '<span class="ah-badge ah-badge--' . ($grup === 'Wajib' ? 'warn' : 'muted') . '">Belum ada</span>' ?>
                        <?php if ($ket !== '' && !$ada): ?><small class="s-muted"><?= $e($ket) ?></small><?php endif; ?>
                        <?php if ($ada): ?><a class="s-btn s-btn--sm s-btn--teks" href="<?= $e(app_url('/lihat_berkas_psb.php?jenis=' . $kolom)) ?>" target="_blank" rel="noopener">Lihat<span class="s-vh"> <?= $e($label) ?> (tab baru)</span></a><?php endif; ?>
                    </div>
                    <form action="proses_upload_berkas.php" method="POST" enctype="multipart/form-data" data-s-unggah="<?= 2 * 1024 * 1024 ?>">
                        <?= Csrf::input() ?>
                        <input type="hidden" name="upload" value="1">
                        <label class="s-vh" for="f-<?= $e($kolom) ?>">Pilih berkas <?= $e($label) ?></label>
                        <input id="f-<?= $e($kolom) ?>" type="file" name="<?= $e($kolom) ?>" accept=".jpg,.jpeg,.png,.pdf,image/jpeg,image/png,application/pdf" required>
                        <button class="s-btn s-btn--sm<?= $ada ? '' : ' s-btn--pri' ?>" type="submit" data-s-memproses="Mengunggah…"><?= $ada ? 'Ganti' : 'Unggah' ?><span class="s-vh"> <?= $e($label) ?></span></button>
                    </form>
                    <p class="s-upload__pesan<?= $hasil !== null ? ' s-upload__pesan--' . ($hasil[0] === 'ok' ? 'ok' : 'galat') : '' ?>" data-s-unggah-pesan role="status"<?= $hasil === null ? ' hidden' : '' ?>><?= $hasil !== null ? $e($hasil[1]) : '' ?></p>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endforeach; ?>
</section>
<?php Kerangka::tutupPortal(); ?>
