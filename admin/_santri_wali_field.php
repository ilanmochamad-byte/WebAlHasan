<?php

declare(strict_types=1);

/**
 * Berkas ini adalah POTONGAN TAMPILAN, bukan halaman.
 *
 * Ia hanya boleh dimuat dari halaman yang sudah menjalankan guard servernya
 * sendiri. Permintaan langsung ke alamat berkas ini ditolak, sehingga tidak ada
 * jalur masuk yang melewati pemeriksaan otorisasi.
 */
if (!defined('AH_PARTIAL')) {
    http_response_code(404);
    exit;
}


/**
 * Satu blok pemilihan wali pada formulir santri (koreksi ke-2).
 *
 * Dirender tiga kali: Ayah, Ibu, dan Wali lain. Setiap blok menawarkan:
 *   - biarkan seperti sekarang (tidak menyentuh relasi yang ada);
 *   - pilih wali yang SUDAH terdaftar (lewat pencarian atau daftar lengkap);
 *   - buat wali baru langsung dari formulir ini;
 *   - lepaskan relasi.
 *
 * Nama dan nomor HP pada kotak pencarian hanyalah petunjuk. Tidak ada
 * penggabungan identitas otomatis: admin yang memilih ID wali yang tepat.
 * Untuk saudara kandung, admin memilih ulang wali yang sama.
 *
 * @var string $hubungan  'Ayah' | 'Ibu' | 'Wali'
 * @var string $judul
 * @var string $keterangan
 * @var array<string, mixed>|null $relasi  Relasi aktif yang sedang berlaku.
 * @var array<int, array<string, mixed>> $daftarWali Cadangan tanpa JavaScript.
 * @var bool $daftarTerpotong
 * @var string|null $nilaiLama Nilai kolom lama untuk hubungan ini (Ayah/Ibu).
 */

$slug = strtolower($hubungan);
$terpilihId = $relasi === null ? 0 : (int) $relasi['wali_id'];
$konflikKolomLama = $hubungan !== 'Wali'
    && $nilaiLama !== null
    && trim((string) $nilaiLama) !== ''
    && $relasi !== null
    && strcasecmp(trim((string) $nilaiLama), trim((string) $relasi['nama'])) !== 0;
?>
<fieldset class="ah-fieldset" data-wali-blok="<?= ah_e($slug) ?>">
    <legend><?= ah_e($judul) ?></legend>
    <p class="ah-fieldset__hint"><?= ah_e($keterangan) ?></p>

    <?php if ($relasi !== null): ?>
        <p class="mb-3">
            Saat ini terhubung ke <strong><?= ah_e($relasi['nama']) ?></strong>
            <span class="text-muted">(ID <?= (int) $relasi['wali_id'] ?><?= $relasi['no_hp'] ? ' · ' . ah_e($relasi['no_hp']) : '' ?>)</span>
        </p>
    <?php else: ?>
        <p class="mb-3 text-muted">Belum ada wali terhubung untuk peran ini.</p>
    <?php endif; ?>

    <?php if ($hubungan !== 'Wali' && $nilaiLama !== null && trim((string) $nilaiLama) !== ''): ?>
        <p class="mb-3 small">
            Kolom lama <code><?= $hubungan === 'Ayah' ? 'nama_ayah' : 'nama_ibu' ?></code> berisi
            <strong><?= ah_e($nilaiLama) ?></strong>. Kolom itu kini hanya cermin dari identitas wali di atas
            dan tidak dapat diketik langsung.
        </p>
    <?php endif; ?>

    <div class="mb-2">
        <span class="form-label d-block" id="label_mode_<?= ah_e($slug) ?>">Tindakan</span>
        <div role="radiogroup" aria-labelledby="label_mode_<?= ah_e($slug) ?>" class="d-flex flex-wrap gap-3">
            <label class="d-inline-flex align-items-center gap-1">
                <input type="radio" name="wali[<?= ah_e($hubungan) ?>][mode]" value="abaikan" checked
                       data-wali-mode="<?= ah_e($slug) ?>">
                <span><?= $relasi === null ? 'Kosongkan' : 'Biarkan seperti sekarang' ?></span>
            </label>
            <label class="d-inline-flex align-items-center gap-1">
                <input type="radio" name="wali[<?= ah_e($hubungan) ?>][mode]" value="pilih" data-wali-mode="<?= ah_e($slug) ?>">
                <span>Pilih wali terdaftar</span>
            </label>
            <label class="d-inline-flex align-items-center gap-1">
                <input type="radio" name="wali[<?= ah_e($hubungan) ?>][mode]" value="baru" data-wali-mode="<?= ah_e($slug) ?>">
                <span>Buat wali baru</span>
            </label>
            <?php if ($relasi !== null): ?>
                <label class="d-inline-flex align-items-center gap-1">
                    <input type="radio" name="wali[<?= ah_e($hubungan) ?>][mode]" value="lepas" data-wali-mode="<?= ah_e($slug) ?>">
                    <span>Lepaskan relasi</span>
                </label>
            <?php endif; ?>
        </div>
    </div>

    <div class="row g-3 mt-1" data-wali-panel="pilih" hidden>
        <div class="col-md-6">
            <label class="form-label" for="cari_<?= ah_e($slug) ?>">Cari wali terdaftar</label>
            <input class="form-control" id="cari_<?= ah_e($slug) ?>" type="search" autocomplete="off"
                   placeholder="Ketik nama atau nomor HP" data-wali-cari="<?= ah_e($slug) ?>"
                   aria-describedby="bantuan_cari_<?= ah_e($slug) ?>">
            <div class="form-text" id="bantuan_cari_<?= ah_e($slug) ?>">
                Hasil pencarian hanyalah kandidat. Pastikan Anda memilih orang yang benar — sistem tidak menebak.
            </div>
            <div class="mt-2" data-wali-hasil="<?= ah_e($slug) ?>" role="status" aria-live="polite"></div>
        </div>
        <div class="col-md-6">
            <label class="form-label" for="wali_<?= ah_e($slug) ?>">Wali terpilih</label>
            <select class="form-select" id="wali_<?= ah_e($slug) ?>" name="wali[<?= ah_e($hubungan) ?>][wali_id]"
                    data-wali-select="<?= ah_e($slug) ?>">
                <option value="">— belum dipilih —</option>
                <?php foreach ($daftarWali as $kandidat): ?>
                    <option value="<?= (int) $kandidat['id'] ?>" <?= $terpilihId === (int) $kandidat['id'] ? 'selected' : '' ?>>
                        <?= ah_e($kandidat['nama'] . ($kandidat['no_hp'] ? ' · ' . $kandidat['no_hp'] : '')
                            . ' · ' . (int) $kandidat['jumlah_santri'] . ' santri · ID ' . (int) $kandidat['id']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <div class="form-text">
                <?php if ($daftarTerpotong): ?>
                    Daftar dibatasi. Gunakan kotak pencarian di sebelah kiri untuk menemukan wali yang tidak tampil di sini.
                <?php else: ?>
                    Memilih wali yang sudah dipakai saudara kandung adalah cara yang benar untuk menyatukan keluarga.
                <?php endif; ?>
            </div>
            <p class="small mt-2 mb-0" data-wali-ringkas="<?= ah_e($slug) ?>"></p>
        </div>
    </div>

    <div class="row g-3 mt-1" data-wali-panel="baru" hidden>
        <div class="col-md-5">
            <label class="form-label" for="wali_baru_nama_<?= ah_e($slug) ?>">Nama wali baru</label>
            <input class="form-control" id="wali_baru_nama_<?= ah_e($slug) ?>" maxlength="100"
                   name="wali[<?= ah_e($hubungan) ?>][nama]">
        </div>
        <div class="col-md-3">
            <label class="form-label" for="wali_baru_hp_<?= ah_e($slug) ?>">Nomor HP</label>
            <input class="form-control" id="wali_baru_hp_<?= ah_e($slug) ?>" inputmode="tel" maxlength="20"
                   name="wali[<?= ah_e($hubungan) ?>][no_hp]" aria-describedby="bantuan_hp_<?= ah_e($slug) ?>">
            <div class="form-text" id="bantuan_hp_<?= ah_e($slug) ?>">Boleh sama dengan wali lain.</div>
        </div>
        <div class="col-md-4">
            <label class="form-label" for="wali_baru_alamat_<?= ah_e($slug) ?>">Alamat</label>
            <input class="form-control" id="wali_baru_alamat_<?= ah_e($slug) ?>" maxlength="200"
                   name="wali[<?= ah_e($hubungan) ?>][alamat]">
        </div>
        <div class="col-12">
            <p class="small text-muted mb-0">Membuat wali di sini <strong>tidak</strong> membuat akun login. Akun dibuat terpisah pada halaman Akun &amp; Hak Akses.</p>
        </div>
    </div>

    <?php if ($hubungan !== 'Wali'): ?>
        <div class="mt-3" data-wali-panel="timpa" <?= $konflikKolomLama ? '' : 'hidden' ?>>
            <div class="ah-danger-zone">
                <label class="d-inline-flex align-items-start gap-2 mb-0">
                    <input type="checkbox" name="konfirmasi_timpa[<?= ah_e($hubungan) ?>]" value="1" class="mt-1">
                    <span>
                        <strong>Ganti nama dan nomor HP pada kolom lama <?= ah_e($hubungan) ?>.</strong>
                        Centang hanya bila identitas wali yang Anda pilih memang benar. Nilai sebelum dan sesudah dicatat pada audit.
                    </span>
                </label>
            </div>
        </div>
    <?php endif; ?>
</fieldset>
