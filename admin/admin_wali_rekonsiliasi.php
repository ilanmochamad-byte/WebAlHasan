<?php

declare(strict_types=1);

use App\MasterData\MasterDataException;

/**
 * Rekonsiliasi data wali lama (koreksi ke-2, keputusan pengguna 30 Agustus 2026).
 *
 * Halaman ini MELAPORKAN, bukan memperbaiki sendiri:
 *
 *   - kandidat duplikasi identitas (nama yang sama, atau nomor HP yang sama);
 *   - identitas wali tanpa satu pun relasi santri aktif;
 *   - santri yang masih mengandalkan kolom lama tanpa relasi wali;
 *   - santri yang kolom lamanya bertentangan dengan relasi wali terverifikasi.
 *
 * Aturan keras yang dipegang halaman ini:
 *   - TIDAK ADA penggabungan massal dan tidak ada penggabungan otomatis;
 *   - kesamaan nama atau nomor HP hanyalah petunjuk — dua orang bernama sama
 *     tetap sah sebagai dua identitas berbeda, dan satu nomor HP boleh dipakai
 *     bersama;
 *   - setiap penggabungan dilakukan satu pasang, setelah admin melihat daftar
 *     santri terdampak dan mencentang konfirmasi;
 *   - bila calon penggabungan menyangkut akun login, prosesnya DIBLOKIR dan
 *     admin diminta menyelesaikannya secara eksplisit lebih dahulu;
 *   - ID lama dipertahankan: wali sumber diarsipkan dengan penanda
 *     `merged_into_wali_id`, tidak dihapus.
 */

require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/_master_ui.php';

$service = master_data_service();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if ((string) ($_POST['action'] ?? '') !== 'merge') {
            throw new MasterDataException('Aksi rekonsiliasi tidak dikenal.');
        }
        if (!ah_form_token_consume('rekonsiliasi', $_POST['form_token'] ?? null)) {
            throw new MasterDataException('Formulir penggabungan ini sudah pernah dikirim. Tidak ada penggabungan ganda yang dijalankan.');
        }
        $hasil = $service->mergeWali(
            (int) ($_POST['source_id'] ?? 0),
            (int) ($_POST['target_id'] ?? 0),
            (int) $currentUser['id'],
            (string) ($_POST['konfirmasi'] ?? '') === '1'
        );
        master_flash(
            'success',
            'Identitas digabungkan: ' . $hasil['dipindahkan'] . ' relasi dipindahkan, '
                . $hasil['diarsipkan'] . ' relasi duplikat diarsipkan, '
                . count($hasil['santri']) . ' santri terdampak.',
            '<p class="small mb-0 mt-2">Wali sumber tidak dihapus. ID lamanya dipertahankan dan barisnya ditandai sebagai digabungkan, sehingga laporan dan ekspor lama tetap dapat ditelusuri.</p>'
        );
    } catch (Throwable $exception) {
        master_flash('danger', $exception->getMessage());
    }
    master_redirect('admin_wali_rekonsiliasi.php');
}

$sections = ['duplikat' => 'Kandidat duplikasi', 'relasi_belum_lengkap' => 'Relasi belum lengkap', 'konflik_kolom_lama' => 'Konflik kolom lama', 'tanpa_relasi' => 'Wali tanpa relasi'];
$section = is_string($_GET['bagian'] ?? null) && isset($sections[$_GET['bagian']]) ? $_GET['bagian'] : 'duplikat';
$q = App\Database\PageQuery::term($_GET['q'] ?? '');
$result = $service->reconciliationPage($section, $q, max(1, (int) ($_GET['page'] ?? 1)));
$laporan = array_fill_keys(array_keys($sections), []);
$laporan[$section] = $result['rows'];

master_header('Rekonsiliasi Wali', [
    'description' => 'Laporan kandidat duplikasi, konflik, dan hubungan wali yang belum lengkap. Tidak ada perubahan data tanpa konfirmasi Anda.',
    'active' => 'master.rekonsiliasi',
    'tabs' => array_map(static fn (string $key): array => ['label' => $sections[$key], 'url' => '?' . ah_query(['bagian' => $key, 'page' => null, 'q' => null]), 'active' => $key === $section], array_keys($sections)),
    'breadcrumbs' => [
        ['label' => 'Beranda', 'url' => app_url('/portal/index.php')],
        ['label' => 'Master Data'],
        ['label' => 'Rekonsiliasi Wali'],
    ],
    'actions' => '<a class="btn btn-outline-primary" href="admin_wali.php">Data wali</a>'
        . '<a class="btn btn-outline-primary" href="admin_master_santri.php">Data santri</a>',
]);

ah_note(
    'info',
    'Halaman ini tidak memperbaiki data sendiri.',
    '<ul class="small mb-0 mt-2">'
        . '<li>Kesamaan nama atau nomor HP hanyalah <strong>petunjuk</strong>. Dua orang bernama sama tetap boleh disimpan sebagai dua orang berbeda, dan satu nomor HP boleh dipakai bersama.</li>'
        . '<li>Penggabungan dilakukan <strong>satu pasang</strong> setelah Anda memeriksa santri yang terdampak. Tidak ada penggabungan massal.</li>'
        . '<li>Wali sumber tidak dihapus: ID lamanya dipertahankan dan barisnya ditandai sebagai digabungkan.</li>'
        . '</ul>'
);
?>

<div class="ah-stats">
    <div class="ah-stat"><p class="ah-stat__label">Kandidat duplikasi</p><p class="ah-stat__value"><?= count($laporan['duplikat']) ?></p><p class="ah-stat__hint">kelompok</p></div>
    <div class="ah-stat"><p class="ah-stat__label">Wali tanpa relasi</p><p class="ah-stat__value"><?= count($laporan['tanpa_relasi']) ?></p></div>
    <div class="ah-stat"><p class="ah-stat__label">Relasi belum lengkap</p><p class="ah-stat__value"><?= count($laporan['relasi_belum_lengkap']) ?></p><p class="ah-stat__hint">santri</p></div>
    <div class="ah-stat"><p class="ah-stat__label">Konflik kolom lama</p><p class="ah-stat__value"><?= count($laporan['konflik_kolom_lama']) ?></p><p class="ah-stat__hint">santri</p></div>
</div>

<?php ah_list_search($q, $section === 'duplikat' ? 'Cari nama atau nomor HP kelompok duplikasi' : 'Cari NIS, nama, atau nomor HP', ['bagian' => $section]); ?>
<?php if ($section === 'duplikat'): ?>
<section class="ah-card" aria-labelledby="ah-duplikat">
    <div class="ah-card__head"><span id="ah-duplikat">Kandidat duplikasi identitas</span></div>
    <div class="ah-card__body">
        <?php if ($laporan['duplikat'] === []): ?>
            <?= ah_empty('Tidak ada kandidat duplikasi', 'Tidak ditemukan identitas wali aktif dengan nama atau nomor HP yang sama.') ?>
        <?php else: ?>
            <?php foreach ($laporan['duplikat'] as $indeks => $grup): ?>
                <div class="ah-fieldset">
                    <p class="mb-2">
                        <strong><?= $grup['jenis'] === 'nama' ? 'Nama sama' : 'Nomor HP sama' ?>:</strong>
                        <?= master_e($grup['kunci']) ?> — <?= (int) $grup['jumlah'] ?> identitas
                        <?= $grup['jumlah_akun'] > 0 ? ah_badge($grup['jumlah_akun'] . ' akun login terkait', 'warn') : '' ?>
                    </p>
                    <div class="ah-table-wrap"><table class="ah-table">
                        <caption class="ah-visually-hidden">Anggota kelompok kandidat duplikasi</caption>
                        <thead><tr><th scope="col">ID</th><th scope="col">Nama</th><th scope="col">HP</th><th scope="col">Santri terhubung</th><th scope="col">Akun login</th></tr></thead>
                        <tbody>
                        <?php foreach ($grup['anggota'] as $anggota): ?>
                            <tr>
                                <td><?= (int) $anggota['id'] ?></td>
                                <td><a href="admin_wali.php?action=detail&amp;id=<?= (int) $anggota['id'] ?>"><?= master_e($anggota['nama']) ?></a></td>
                                <td><?= master_e($anggota['no_hp'] ?: '-') ?></td>
                                <td><?= master_e($anggota['santri'] ?: '— belum ada —') ?></td>
                                <td><?= (int) $anggota['jumlah_akun'] > 0 ? ah_badge('Ada', 'warn') : ah_badge('Tidak ada', 'muted') ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table></div>

                    <?php if ($grup['diblokir']): ?>
                        <?php ah_note('warning', 'Penggabungan kelompok ini diblokir: lebih dari satu identitas memiliki akun login.',
                            '<p class="small mb-0 mt-2">Menggabungkannya akan mengubah santri yang dapat dilihat akun orang tua. Selesaikan akun terkait lebih dahulu pada halaman Akun &amp; Hak Akses, lalu kembali ke sini.</p>'); ?>
                    <?php else: ?>
                        <form method="post" class="row g-2 align-items-end">
                            <?= master_csrf() ?>
                            <input type="hidden" name="action" value="merge">
                            <input type="hidden" name="form_token" value="<?= master_e(ah_form_token('rekonsiliasi')) ?>">
                            <div class="col-md-4">
                                <label class="form-label" for="source_<?= $indeks ?>">Gabungkan identitas ini…</label>
                                <select class="form-select" id="source_<?= $indeks ?>" name="source_id" required>
                                    <option value="">Pilih identitas sumber</option>
                                    <?php foreach ($grup['anggota'] as $anggota): ?>
                                        <option value="<?= (int) $anggota['id'] ?>"><?= master_e('#' . $anggota['id'] . ' ' . $anggota['nama'] . ' (' . (int) $anggota['jumlah_santri'] . ' santri)') ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="target_<?= $indeks ?>">…ke identitas ini</label>
                                <select class="form-select" id="target_<?= $indeks ?>" name="target_id" required>
                                    <option value="">Pilih identitas tujuan</option>
                                    <?php foreach ($grup['anggota'] as $anggota): ?>
                                        <option value="<?= (int) $anggota['id'] ?>"><?= master_e('#' . $anggota['id'] . ' ' . $anggota['nama'] . ' (' . (int) $anggota['jumlah_santri'] . ' santri)') ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <div class="ah-danger-zone">
                                    <label class="d-inline-flex align-items-start gap-2 mb-2">
                                        <input type="checkbox" name="konfirmasi" value="1" class="mt-1" required>
                                        <span class="small">Saya sudah memeriksa daftar santri di atas dan yakin keduanya adalah orang yang sama.</span>
                                    </label>
                                    <button class="btn btn-danger btn-sm w-100" type="submit">Gabungkan pasangan ini</button>
                                </div>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</section>
<?php endif; ?>

<?php if ($section === 'relasi_belum_lengkap'): ?>
<section class="ah-card" aria-labelledby="ah-belum-lengkap">
    <div class="ah-card__head"><span id="ah-belum-lengkap">Santri dengan hubungan wali belum lengkap</span></div>
    <div class="ah-card__body">
        <p class="text-muted small">Biasanya berasal dari impor lama atau alur PSB, yang menulis nama orang tua pada kolom lama tanpa membuat identitas wali.</p>
        <?php if ($laporan['relasi_belum_lengkap'] === []): ?>
            <?= ah_empty('Semua santri sudah memiliki relasi wali', 'Tidak ada santri aktif yang bergantung pada kolom lama tanpa relasi wali.') ?>
        <?php else: ?>
            <div class="ah-table-wrap"><table class="ah-table">
                <caption class="ah-visually-hidden">Santri yang relasi walinya belum lengkap</caption>
                <thead><tr><th scope="col">NIS</th><th scope="col">Santri</th><th scope="col">Kolom lama ayah</th><th scope="col">Kolom lama ibu</th><th scope="col">Relasi aktif</th><th scope="col">Aksi</th></tr></thead>
                <tbody>
                <?php foreach ($laporan['relasi_belum_lengkap'] as $santri): ?>
                    <tr>
                        <td><?= master_e($santri['nis']) ?></td>
                        <td><?= master_e($santri['nama_santri']) ?></td>
                        <td><?= master_e($santri['nama_ayah'] ?: '-') ?><?= (int) $santri['relasi_ayah'] === 0 && trim((string) $santri['nama_ayah']) !== '' ? ' ' . ah_badge('tanpa relasi', 'warn') : '' ?></td>
                        <td><?= master_e($santri['nama_ibu'] ?: '-') ?><?= (int) $santri['relasi_ibu'] === 0 && trim((string) $santri['nama_ibu']) !== '' ? ' ' . ah_badge('tanpa relasi', 'warn') : '' ?></td>
                        <td><?= (int) $santri['jumlah_relasi'] ?></td>
                        <td><a class="btn btn-sm btn-outline-primary" href="admin_master_santri.php?action=edit&amp;id=<?= (int) $santri['id'] ?>">Hubungkan wali</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php endif; ?>
    </div>
</section>
<?php endif; ?>

<?php if ($section === 'konflik_kolom_lama'): ?>
<section class="ah-card" aria-labelledby="ah-konflik">
    <div class="ah-card__head"><span id="ah-konflik">Konflik antara kolom lama dan relasi wali</span></div>
    <div class="ah-card__body">
        <p class="text-muted small">Nilai lama tidak pernah ditimpa otomatis. Selesaikan satu per satu dari halaman santri, dengan konfirmasi; nilai sebelum dan sesudah tercatat pada audit.</p>
        <?php if ($laporan['konflik_kolom_lama'] === []): ?>
            <?= ah_empty('Tidak ada konflik', 'Kolom lama sejalan dengan identitas wali yang terverifikasi.') ?>
        <?php else: ?>
            <div class="ah-table-wrap"><table class="ah-table">
                <caption class="ah-visually-hidden">Santri yang kolom lamanya bertentangan dengan relasi wali</caption>
                <thead><tr><th scope="col">NIS</th><th scope="col">Santri</th><th scope="col">Kolom lama</th><th scope="col">Identitas wali terverifikasi</th><th scope="col">Aksi</th></tr></thead>
                <tbody>
                <?php foreach ($laporan['konflik_kolom_lama'] as $santri): ?>
                    <tr>
                        <td><?= master_e($santri['nis']) ?></td>
                        <td><?= master_e($santri['nama_santri']) ?></td>
                        <td>Ayah: <?= master_e($santri['nama_ayah'] ?: '-') ?><span class="ah-cell-sub">Ibu: <?= master_e($santri['nama_ibu'] ?: '-') ?></span></td>
                        <td>Ayah: <?= master_e($santri['wali_ayah'] ?: '-') ?><span class="ah-cell-sub">Ibu: <?= master_e($santri['wali_ibu'] ?: '-') ?></span></td>
                        <td><a class="btn btn-sm btn-outline-primary" href="admin_master_santri.php?action=edit&amp;id=<?= (int) $santri['id'] ?>">Selesaikan</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php endif; ?>
    </div>
</section>
<?php endif; ?>

<?php if ($section === 'tanpa_relasi'): ?>
<section class="ah-card" aria-labelledby="ah-tanpa-relasi">
    <div class="ah-card__head"><span id="ah-tanpa-relasi">Identitas wali tanpa relasi santri aktif</span></div>
    <div class="ah-card__body">
        <?php if ($laporan['tanpa_relasi'] === []): ?>
            <?= ah_empty('Tidak ada wali menganggur', 'Semua identitas wali aktif terhubung ke sedikitnya satu santri.') ?>
        <?php else: ?>
            <div class="ah-table-wrap"><table class="ah-table">
                <caption class="ah-visually-hidden">Identitas wali yang belum terhubung ke santri mana pun</caption>
                <thead><tr><th scope="col">ID</th><th scope="col">Nama</th><th scope="col">HP</th><th scope="col">Akun login</th><th scope="col">Dibuat</th><th scope="col">Aksi</th></tr></thead>
                <tbody>
                <?php foreach ($laporan['tanpa_relasi'] as $wali): ?>
                    <tr>
                        <td><?= (int) $wali['id'] ?></td>
                        <td><?= master_e($wali['nama']) ?></td>
                        <td><?= master_e($wali['no_hp'] ?: '-') ?></td>
                        <td><?= (int) $wali['jumlah_akun'] > 0 ? ah_badge('Ada', 'warn') : ah_badge('Tidak ada', 'muted') ?></td>
                        <td><?= master_e($wali['created_at']) ?></td>
                        <td><a class="btn btn-sm btn-outline-primary" href="admin_wali.php?action=detail&amp;id=<?= (int) $wali['id'] ?>">Periksa</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php endif; ?>
    </div>
</section>
<?php endif; ?>
<?php master_pagination((int) $result['total'], (int) $result['page'], 20); master_footer(); ?>
