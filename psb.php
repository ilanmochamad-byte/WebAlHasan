<?php
/*
 * Formulir PSB bertahap (P-1) dan halaman pendaftaran berhasil (P-2) —
 * redesain website Tahap 8.
 *
 * Empat langkah: Calon santri → Alamat → Orang tua → Periksa & kirim. Tanpa
 * JavaScript keempat langkah tampil berurutan dalam satu halaman. Jenjang
 * dikelompokkan "Unit Al Hasan" dan "Sekolah Mitra" (AN); RA dan MI tampil
 * tetapi belum dapat dipilih. NISN/NIK/No. HP berupa isian teks berpapan
 * angka. Persetujuan Kebijakan Privasi wajib dan tidak disimpan (AO).
 *
 * Workstream keamanan PSB tetap: sesi dimulai sebelum keluaran (CSRF),
 * pesan hasil tidak menampilkan teks dari alamat apa adanya, dan hak cetak
 * bukti hanya untuk peramban yang baru mendaftar.
 */
require_once __DIR__ . '/koneksi.php';

use App\Http\Csrf;
use App\Psb\Jenjang;
use App\Situs\IsiSitus;
use App\Situs\Kerangka;

$statusDaftar = is_string($_GET['status'] ?? null) ? $_GET['status'] : '';
$noregDaftar = is_string($_GET['noreg'] ?? null) ? $_GET['noreg'] : '';
$buktiDaftar = $_SESSION['_psb_bukti'] ?? null;
$bolehCetakDaftar = is_array($buktiDaftar) && hash_equals((string) ($buktiDaftar['no'] ?? ''), $noregDaftar);
$galatDaftar = $statusDaftar === 'gagal' && is_array($_SESSION['_psb_daftar_galat'] ?? null) ? $_SESSION['_psb_daftar_galat'] : [];
$isianLama = $statusDaftar === 'gagal' && is_array($_SESSION['_psb_daftar_isian'] ?? null) ? $_SESSION['_psb_daftar_isian'] : [];
unset($_SESSION['_psb_daftar_galat'], $_SESSION['_psb_daftar_isian']);

$e = static fn (mixed $v): string => Kerangka::e($v);
$tahun = IsiSitus::tahunPsb();
$sukses = $statusDaftar === 'sukses' && preg_match('/^PSB-\d{4}-\d{4}$/', $noregDaftar) === 1;

// Nilai awal isian: kiriman yang gagal (sesi ini saja) atau jenjang dari tautan unit.
$nilai = static fn (string $k): string => is_scalar($isianLama[$k] ?? null) ? (string) $isianLama[$k] : '';
$jenjangAwal = $nilai('jenjang_tujuan');
if ($jenjangAwal === '' && is_string($_GET['jenjang'] ?? null) && Jenjang::dibuka($_GET['jenjang'])) {
    $jenjangAwal = $_GET['jenjang'];
}
$galat = static fn (string $k): string => isset($galatDaftar[$k]) && is_string($galatDaftar[$k])
    ? '<small class="s-error" id="galat-' . Kerangka::e($k) . '">' . Kerangka::e($galatDaftar[$k]) . '</small>' : '';
$kelasField = static fn (string $k): string => 's-field' . (isset($galatDaftar[$k]) ? ' is-galat' : '');
$aria = static fn (string $k, string $bantu = ''): string => ($bantu !== '' || isset($galatDaftar[$k]))
    ? ' aria-describedby="' . trim(($bantu !== '' ? $bantu : '') . (isset($galatDaftar[$k]) ? ' galat-' . $k : '')) . '"' . (isset($galatDaftar[$k]) ? ' aria-invalid="true"' : '') : '';

// Langkah yang memuat galat pertama (dibuka lebih dulu oleh JavaScript).
$langkahIsian = [
    0 => ['nama_lengkap', 'nisn', 'nik', 'tempat_lahir', 'tgl_lahir', 'jenis_kelamin', 'jenjang_tujuan', 'sekolah_asal'],
    1 => ['provinsi', 'kab_kota', 'kecamatan', 'desa', 'alamat_jalan'],
    2 => ['nama_ayah', 'nama_ibu', 'no_hp_wali', 'no_hp_wali_ulang'],
    3 => ['setuju_privasi'],
];
$langkahGalat = null;
foreach ($langkahIsian as $i => $isian) {
    if (array_intersect($isian, array_keys($galatDaftar)) !== []) {
        $langkahGalat = $i;
        break;
    }
}

$situs = ['judul' => $sukses ? 'Pendaftaran berhasil' : 'Pendaftaran santri baru ' . $tahun, 'tanpa_kaki' => true,
    'deskripsi' => 'Formulir pendaftaran santri baru Pesantren Al Hasan ' . $tahun . '.'];
include 'header.php';
$langkahLabel = ['Calon santri', 'Alamat', 'Orang tua', 'Periksa & kirim'];
?>
<div class="s-wrap py-4">
<?php if ($sukses): ?>
    <div style="max-width:560px;margin:0 auto">
        <ol class="s-stepper" aria-label="Tahap pendaftaran">
            <?php foreach ($langkahLabel as $l): ?><li class="is-selesai"><span><?= $e($l) ?></span></li><?php endforeach; ?>
        </ol>
        <section class="s-panel" aria-labelledby="judul-sukses">
            <div class="s-panel__head"><h1 id="judul-sukses" class="h4 fw-bold m-0">Pendaftaran berhasil</h1><span class="ah-badge ah-badge--info">Baru</span></div>
            <p class="mb-1">Data tersimpan. Nomor pendaftaran Anda:</p>
            <p class="s-nomor" id="nomor-pendaftaran"><?= $e($noregDaftar) ?></p>
            <p class="s-muted">Simpan nomor ini. Untuk masuk Portal Pendaftar, gunakan NISN calon santri dan No. HP wali yang baru diisi.</p>
            <div class="s-acts">
                <button class="s-btn s-js-only" type="button" data-s-salin="<?= $e($noregDaftar) ?>" data-s-salin-pesan="Nomor pendaftaran disalin.">Salin nomor</button>
                <?php if ($bolehCetakDaftar): ?>
                    <a class="s-btn" href="cetak_bukti.php?noreg=<?= $e(rawurlencode($noregDaftar)) ?>" target="_blank" rel="noopener">Cetak bukti pendaftaran<span class="s-vh"> (tab baru)</span></a>
                <?php endif; ?>
                <a class="s-btn s-btn--pri" href="<?= $e(app_url('/login_pendaftar.php')) ?>">Masuk Portal Pendaftar</a>
            </div>
            <?php if (!$bolehCetakDaftar): ?><p class="s-hint mt-2">Bukti pendaftaran dapat dicetak dari Portal Pendaftar.</p><?php endif; ?>
        </section>
        <section class="s-panel" aria-labelledby="judul-berikutnya">
            <h2 id="judul-berikutnya" class="h5 fw-bold">Langkah berikutnya</h2>
            <ol class="s-vsteps">
                <li>Masuk Portal Pendaftar, lalu unggah Kartu Keluarga, akta kelahiran, dan KTP orang tua.</li>
                <li>Panitia memeriksa data dan berkas Anda.</li>
                <li>Lihat hasil seleksi di Portal Pendaftar.</li>
                <li>Daftar ulang dan pembiayaan.</li>
            </ol>
        </section>
    </div>
<?php else: ?>
    <header class="s-head" style="padding-top:.5rem">
        <ol class="s-crumbs"><li><a href="<?= $e(app_url('/index.php')) ?>">Beranda</a></li><li aria-current="page">Pendaftaran santri baru</li></ol>
        <h1>Pendaftaran santri baru <?= $e($tahun) ?></h1>
        <p>Siapkan Kartu Keluarga dan ijazah/SKL. Isian bertanda <span class="s-error" aria-hidden="true">*</span><span class="s-vh">bintang</span> wajib diisi.</p>
    </header>
    <?php if (!IsiSitus::psbDibuka()): ?>
        <div class="s-kosong"><b>Pendaftaran belum dibuka</b>Informasi pembukaan pendaftaran akan diumumkan di situs ini.</div>
    <?php else: ?>
    <?php if ($statusDaftar === 'gagal'): ?>
        <div class="ah-note ah-note--danger" role="alert"><div><strong class="ah-note__title">Pendaftaran belum terkirim</strong>
            <span><?= $galatDaftar === [] ? 'NISN mungkin sudah terdaftar. Masuk ke Portal Pendaftar bila Anda sudah mendaftar.' : 'Perbaiki ' . count($galatDaftar) . ' isian bertanda merah: ' . $e(implode(' ', array_map('strval', $galatDaftar))) ?></span></div></div>
    <?php elseif ($statusDaftar === 'token'): ?>
        <div class="ah-note ah-note--warn" role="alert"><div><strong class="ah-note__title">Halaman kedaluwarsa</strong><span>Halaman formulir sudah kedaluwarsa sehingga data belum tersimpan. Silakan isi dan kirim ulang.</span></div></div>
    <?php endif; ?>

    <div class="s-split s-split--2-1">
        <form class="s-panel" action="proses_psb.php" method="POST" data-s-langkah<?= $langkahGalat !== null ? ' data-s-langkah-galat="' . $langkahGalat . '"' : '' ?>>
            <?= Csrf::input() ?>
            <ol class="s-stepper s-js-only" data-s-penanda aria-label="Langkah pendaftaran">
                <?php foreach ($langkahLabel as $i => $l): ?><li<?= $i === 0 ? ' aria-current="step"' : '' ?>><span><?= $e($l) ?></span></li><?php endforeach; ?>
            </ol>

            <fieldset class="s-langkah is-aktif s-fieldset">
                <legend>1. Calon santri</legend>
                <div class="s-form s-form--2">
                    <div class="<?= $kelasField('nama_lengkap') ?> s-field--penuh">
                        <label class="s-label" for="nama_lengkap">Nama lengkap <em>*</em></label>
                        <input class="s-input" id="nama_lengkap" name="nama_lengkap" required maxlength="100" autocomplete="name" value="<?= $e($nilai('nama_lengkap')) ?>"<?= $aria('nama_lengkap', 'bantu-nama') ?>>
                        <small class="s-hint" id="bantu-nama">Sesuai Kartu Keluarga.</small><?= $galat('nama_lengkap') ?>
                    </div>
                    <div class="<?= $kelasField('nisn') ?>">
                        <label class="s-label" for="nisn">NISN <em>*</em></label>
                        <input class="s-input" id="nisn" name="nisn" type="text" inputmode="numeric" pattern="[0-9]{10}" maxlength="10" required autocomplete="off" value="<?= $e($nilai('nisn')) ?>" title="NISN berisi 10 angka."<?= $aria('nisn', 'bantu-nisn') ?>>
                        <small class="s-hint" id="bantu-nisn">10 angka. Dipakai untuk masuk Portal Pendaftar.</small><?= $galat('nisn') ?>
                    </div>
                    <div class="<?= $kelasField('nik') ?>">
                        <label class="s-label" for="nik">NIK <em>*</em></label>
                        <input class="s-input" id="nik" name="nik" type="text" inputmode="numeric" pattern="[0-9]{16}" maxlength="16" required autocomplete="off" value="<?= $e($nilai('nik')) ?>" title="NIK berisi 16 angka."<?= $aria('nik', 'bantu-nik') ?>>
                        <small class="s-hint" id="bantu-nik">16 angka, sesuai Kartu Keluarga.</small><?= $galat('nik') ?>
                    </div>
                    <div class="<?= $kelasField('tempat_lahir') ?>">
                        <label class="s-label" for="tempat_lahir">Tempat lahir <em>*</em></label>
                        <input class="s-input" id="tempat_lahir" name="tempat_lahir" required maxlength="50" value="<?= $e($nilai('tempat_lahir')) ?>"<?= $aria('tempat_lahir') ?>><?= $galat('tempat_lahir') ?>
                    </div>
                    <div class="<?= $kelasField('tgl_lahir') ?>">
                        <label class="s-label" for="inputTanggal">Tanggal lahir <em>*</em></label>
                        <input class="s-input" id="inputTanggal" name="tgl_lahir" type="date" required max="<?= date('Y-m-d') ?>" value="<?= $e($nilai('tgl_lahir')) ?>"<?= $aria('tgl_lahir') ?>><?= $galat('tgl_lahir') ?>
                    </div>
                    <div class="<?= $kelasField('jenis_kelamin') ?> s-field--penuh" role="radiogroup" aria-labelledby="label-jk">
                        <span class="s-label" id="label-jk">Jenis kelamin <em>*</em></span>
                        <div class="s-radios">
                            <label><input type="radio" name="jenis_kelamin" value="L" required<?= $nilai('jenis_kelamin') === 'L' ? ' checked' : '' ?>> Laki-laki</label>
                            <label><input type="radio" name="jenis_kelamin" value="P"<?= $nilai('jenis_kelamin') === 'P' ? ' checked' : '' ?>> Perempuan</label>
                        </div><?= $galat('jenis_kelamin') ?>
                    </div>
                    <div class="<?= $kelasField('jenjang_tujuan') ?>">
                        <label class="s-label" for="jenjang_tujuan">Jenjang tujuan <em>*</em></label>
                        <select class="s-select" id="jenjang_tujuan" name="jenjang_tujuan" required<?= $aria('jenjang_tujuan', 'bantu-jenjang') ?>>
                            <option value="">Pilih jenjang…</option>
                            <optgroup label="Unit Al Hasan">
                                <?php foreach (Jenjang::UNIT_AL_HASAN as $nilaiJenjang => $labelJenjang): $buka = Jenjang::dibuka($nilaiJenjang); ?>
                                    <option value="<?= $e($nilaiJenjang) ?>"<?= $buka ? '' : ' disabled' ?><?= $buka && $jenjangAwal === $nilaiJenjang ? ' selected' : '' ?>><?= $e($labelJenjang) ?><?= $buka ? '' : ' (belum dibuka)' ?></option>
                                <?php endforeach; ?>
                            </optgroup>
                            <optgroup label="Sekolah Mitra">
                                <?php foreach (Jenjang::SEKOLAH_MITRA as $nilaiJenjang => $labelJenjang): ?>
                                    <option value="<?= $e($nilaiJenjang) ?>"<?= $jenjangAwal === $nilaiJenjang ? ' selected' : '' ?>><?= $e($labelJenjang) ?></option>
                                <?php endforeach; ?>
                            </optgroup>
                        </select>
                        <small class="s-hint" id="bantu-jenjang">RA dan MI belum dibuka sampai biayanya ditetapkan panitia.</small><?= $galat('jenjang_tujuan') ?>
                    </div>
                    <div class="<?= $kelasField('sekolah_asal') ?>">
                        <label class="s-label" for="sekolah_asal">Sekolah asal <em>*</em></label>
                        <input class="s-input" id="sekolah_asal" name="sekolah_asal" required maxlength="100" value="<?= $e($nilai('sekolah_asal')) ?>"<?= $aria('sekolah_asal') ?>><?= $galat('sekolah_asal') ?>
                    </div>
                </div>
                <div class="s-langkah__nav"><button class="s-btn s-btn--pri" type="button" data-s-lanjut>Lanjut ke Alamat</button></div>
            </fieldset>

            <fieldset class="s-langkah s-fieldset">
                <legend>2. Alamat</legend>
                <div class="s-form" data-s-wilayah data-s-wilayah-wajib>
                    <div class="s-form s-form--2" data-s-wilayah-pilih hidden>
                        <div class="s-field"><label class="s-label" for="provinsi">Provinsi <em>*</em></label><select class="s-select" id="provinsi"><option value="">Pilih provinsi…</option></select></div>
                        <div class="s-field"><label class="s-label" for="kabupaten">Kabupaten/kota <em>*</em></label><select class="s-select" id="kabupaten" disabled><option value="">Pilih kabupaten/kota…</option></select></div>
                        <div class="s-field"><label class="s-label" for="kecamatan">Kecamatan <em>*</em></label><select class="s-select" id="kecamatan" disabled><option value="">Pilih kecamatan…</option></select></div>
                        <div class="s-field"><label class="s-label" for="desa">Desa/kelurahan <em>*</em></label><select class="s-select" id="desa" disabled><option value="">Pilih desa/kelurahan…</option></select></div>
                        <div class="ah-note ah-note--warn s-field--penuh" data-s-wilayah-pesan hidden role="alert"><div>
                            <strong class="ah-note__title">Daftar wilayah gagal dimuat</strong>
                            <span>Periksa koneksi internet lalu coba lagi, atau ketik nama wilayah sendiri.</span>
                            <div class="s-acts mt-2"><button class="s-btn s-btn--sm" type="button" data-s-wilayah-ulang>Coba lagi</button><button class="s-btn s-btn--sm" type="button" data-s-wilayah-ketik>Ketik manual</button></div>
                        </div></div>
                    </div>
                    <div class="s-form s-form--2" data-s-wilayah-manual>
                        <?php foreach (['nama_provinsi' => ['provinsi', 'Provinsi'], 'nama_kabupaten' => ['kab_kota', 'Kabupaten/kota'], 'nama_kecamatan' => ['kecamatan', 'Kecamatan'], 'nama_desa' => ['desa', 'Desa/kelurahan']] as $isian => [$kunciGalat, $labelWilayah]): ?>
                            <div class="<?= $kelasField($kunciGalat) ?>">
                                <label class="s-label" for="<?= $e($isian) ?>"><?= $e($labelWilayah) ?> <em>*</em></label>
                                <input class="s-input" id="<?= $e($isian) ?>" name="<?= $e($isian) ?>" required maxlength="50" value="<?= $e($nilai($isian)) ?>"><?= $galat($kunciGalat) ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="<?= $kelasField('alamat_jalan') ?> mt-3">
                    <label class="s-label" for="alamat_jalan">Jalan, dusun, RT/RW <em>*</em></label>
                    <textarea class="s-textarea" id="alamat_jalan" name="alamat_jalan" rows="2" required maxlength="1000" autocomplete="street-address"<?= $aria('alamat_jalan') ?>><?= $e($nilai('alamat_jalan')) ?></textarea><?= $galat('alamat_jalan') ?>
                </div>
                <div class="s-langkah__nav"><button class="s-btn" type="button" data-s-kembali>Kembali</button><button class="s-btn s-btn--pri" type="button" data-s-lanjut>Lanjut ke Orang tua</button></div>
            </fieldset>

            <fieldset class="s-langkah s-fieldset">
                <legend>3. Orang tua</legend>
                <div class="s-form s-form--2">
                    <div class="<?= $kelasField('nama_ayah') ?>">
                        <label class="s-label" for="nama_ayah">Nama ayah <em>*</em></label>
                        <input class="s-input" id="nama_ayah" name="nama_ayah" required maxlength="100" value="<?= $e($nilai('nama_ayah')) ?>"<?= $aria('nama_ayah') ?>><?= $galat('nama_ayah') ?>
                    </div>
                    <div class="<?= $kelasField('nama_ibu') ?>">
                        <label class="s-label" for="nama_ibu">Nama ibu <em>*</em></label>
                        <input class="s-input" id="nama_ibu" name="nama_ibu" required maxlength="100" value="<?= $e($nilai('nama_ibu')) ?>"<?= $aria('nama_ibu') ?>><?= $galat('nama_ibu') ?>
                    </div>
                    <div class="<?= $kelasField('no_hp_wali') ?>">
                        <label class="s-label" for="no_hp_wali">No. HP/WhatsApp wali <em>*</em></label>
                        <input class="s-input" id="no_hp_wali" name="no_hp_wali" type="text" inputmode="numeric" pattern="[0-9]{8,15}" maxlength="15" required autocomplete="tel" value="<?= $e($nilai('no_hp_wali')) ?>" title="No. HP berisi 8–15 angka."<?= $aria('no_hp_wali', 'bantu-hp') ?>>
                        <small class="s-hint" id="bantu-hp">Nomor ini dipakai untuk masuk Portal Pendaftar.</small><?= $galat('no_hp_wali') ?>
                    </div>
                    <div class="<?= $kelasField('no_hp_wali_ulang') ?>">
                        <label class="s-label" for="no_hp_wali_ulang">Ketik ulang No. HP wali <em>*</em></label>
                        <input class="s-input" id="no_hp_wali_ulang" name="no_hp_wali_ulang" type="text" inputmode="numeric" pattern="[0-9]{8,15}" maxlength="15" required autocomplete="off" value="<?= $e($nilai('no_hp_wali_ulang')) ?>"<?= $aria('no_hp_wali_ulang', 'bantu-hp-ulang') ?>>
                        <small class="s-hint" id="bantu-hp-ulang">Harus sama dengan nomor di samping.</small><?= $galat('no_hp_wali_ulang') ?>
                    </div>
                </div>
                <div class="s-langkah__nav"><button class="s-btn" type="button" data-s-kembali>Kembali</button><button class="s-btn s-btn--pri" type="button" data-s-lanjut>Lanjut ke Periksa &amp; kirim</button></div>
            </fieldset>

            <fieldset class="s-langkah s-fieldset">
                <legend>4. Periksa &amp; kirim</legend>
                <div class="s-ringkas s-js-only">
                    <section>
                        <header><h3>Calon santri</h3><button class="s-btn s-btn--sm" type="button" data-s-ke="0">Ubah<span class="s-vh"> data calon santri</span></button></header>
                        <dl class="s-dl">
                            <dt>Nama</dt><dd data-s-ringkas="nama_lengkap"></dd>
                            <dt>NISN</dt><dd data-s-ringkas="nisn"></dd>
                            <dt>NIK</dt><dd data-s-ringkas="nik"></dd>
                            <dt>Tempat lahir</dt><dd data-s-ringkas="tempat_lahir"></dd>
                            <dt>Tanggal lahir</dt><dd data-s-ringkas="tgl_lahir"></dd>
                            <dt>Jenis kelamin</dt><dd data-s-ringkas="jenis_kelamin"></dd>
                            <dt>Jenjang tujuan</dt><dd data-s-ringkas="jenjang_tujuan"></dd>
                            <dt>Sekolah asal</dt><dd data-s-ringkas="sekolah_asal"></dd>
                        </dl>
                    </section>
                    <section>
                        <header><h3>Alamat</h3><button class="s-btn s-btn--sm" type="button" data-s-ke="1">Ubah<span class="s-vh"> alamat</span></button></header>
                        <dl class="s-dl"><dt>Wilayah</dt><dd data-s-ringkas-wilayah></dd><dt>Jalan</dt><dd data-s-ringkas="alamat_jalan"></dd></dl>
                    </section>
                    <section>
                        <header><h3>Orang tua</h3><button class="s-btn s-btn--sm" type="button" data-s-ke="2">Ubah<span class="s-vh"> data orang tua</span></button></header>
                        <dl class="s-dl"><dt>Ayah</dt><dd data-s-ringkas="nama_ayah"></dd><dt>Ibu</dt><dd data-s-ringkas="nama_ibu"></dd><dt>No. HP wali</dt><dd data-s-ringkas="no_hp_wali"></dd></dl>
                    </section>
                </div>
                <div class="<?= $kelasField('setuju_privasi') ?> mt-3">
                    <label class="s-check" for="setuju_privasi">
                        <input type="checkbox" id="setuju_privasi" name="setuju_privasi" value="1" required>
                        <span>Saya telah membaca dan menyetujui <a href="<?= $e(app_url('/privacy')) ?>" target="_blank" rel="noopener">Kebijakan Privasi<span class="s-vh"> (tab baru)</span></a>. <em class="s-error" aria-hidden="true">*</em></span>
                    </label><?= $galat('setuju_privasi') ?>
                </div>
                <div class="s-langkah__nav" style="display:flex"><button class="s-btn s-js-only" type="button" data-s-kembali>Kembali</button>
                    <button class="s-btn s-btn--pri" type="submit" name="daftar" value="1" data-s-memproses="Mengirim…">Kirim pendaftaran</button></div>
            </fieldset>
        </form>

        <aside>
            <section class="s-panel" aria-labelledby="judul-siapkan">
                <h2 id="judul-siapkan" class="h6 fw-bold">Yang perlu disiapkan</h2>
                <ul class="mb-0">
                    <li>Kartu Keluarga</li>
                    <li>Akta kelahiran</li>
                    <li>Ijazah / SKL</li>
                    <li>KTP orang tua</li>
                </ul>
                <p class="s-hint mt-2 mb-0">Berkas diunggah di Portal Pendaftar setelah formulir terkirim.</p>
            </section>
            <section class="s-panel" aria-labelledby="judul-sudah">
                <h2 id="judul-sudah" class="h6 fw-bold">Sudah mendaftar?</h2>
                <p>Masuk untuk melihat status dan mengunggah berkas.</p>
                <a class="s-btn" href="<?= $e(app_url('/login_pendaftar.php')) ?>">Masuk Portal Pendaftar</a>
            </section>
        </aside>
    </div>
    <?php endif; ?>
<?php endif; ?>
</div>
<?php include 'footer.php'; ?>
