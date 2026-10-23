<?php

declare(strict_types=1);

use App\MasterData\MasterDataException;

/**
 * Data santri › Orang tua/wali › Rekonsiliasi wali (redesain website Tahap 6, D-7).
 *
 * Dibuka dari tombol "Rekonsiliasi wali" di halaman Orang tua/wali (keputusan
 * D). Halaman ini MELAPORKAN, bukan memperbaiki sendiri:
 *
 *   - kemungkinan ganda ditinjau SATU PASANGAN per layar, berdampingan; admin
 *     memilih identitas yang dipertahankan lalu Gabungkan (dialog konfirmasi);
 *   - "Bukan ganda" (keputusan AD) menyimpan tanda pasangan beralasan sehingga
 *     pasangan itu tidak muncul lagi; tanda dapat dibatalkan dari chip
 *     "Ditandai bukan ganda";
 *   - "Lewati" hanya melompat ke pasangan berikutnya tanpa menyimpan apa pun;
 *   - relasi belum lengkap, konflik data lama, dan wali tanpa santri tampil
 *     sebagai daftar dengan tombol "Perbaiki".
 *
 * Aturan keras yang tetap dipegang: tidak ada penggabungan massal atau
 * otomatis; kesamaan nama/HP hanya petunjuk; penggabungan yang menyangkut dua
 * akun login diblokir; wali sumber diarsipkan dengan `merged_into_wali_id`,
 * tidak dihapus.
 */

require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/_master_ui.php';

$service = master_data_service();
$halaman = 'admin_wali_rekonsiliasi.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aksi = (string) ($_POST['action'] ?? '');
    $kembali = $halaman . (is_string($_POST['kembali'] ?? null) && preg_match('/^\?[a-z_=&0-9%.+-]*$/i', $_POST['kembali']) === 1 ? $_POST['kembali'] : '');
    try {
        if ($aksi === 'merge') {
            if (!ah_form_token_consume('rekonsiliasi', $_POST['form_token'] ?? null)) {
                throw new MasterDataException('Formulir penggabungan ini sudah pernah dikirim. Tidak ada penggabungan ganda yang dijalankan.');
            }
            // D-7: pilihan "pertahankan A/B" dikirim sebagai "tujuan:sumber".
            $sumber = (int) ($_POST['source_id'] ?? 0);
            $tujuan = (int) ($_POST['target_id'] ?? 0);
            if (is_string($_POST['pilihan'] ?? null) && preg_match('/^([1-9][0-9]*):([1-9][0-9]*)$/', $_POST['pilihan'], $pilih) === 1) {
                [$tujuan, $sumber] = [(int) $pilih[1], (int) $pilih[2]];
            }
            $hasil = $service->mergeWali(
                $sumber,
                $tujuan,
                (int) $currentUser['id'],
                (string) ($_POST['konfirmasi'] ?? '') === '1'
            );
            master_flash('success', 'Identitas digabungkan: ' . $hasil['dipindahkan'] . ' relasi dipindahkan, ' . $hasil['diarsipkan'] . ' relasi duplikat diarsipkan, '
                . count($hasil['santri']) . ' santri terdampak. Wali sumber tidak dihapus; barisnya ditandai sudah digabungkan.');
        } elseif ($aksi === 'bukan_ganda') {
            $service->markNotDuplicate((int) ($_POST['wali_a'] ?? 0), (int) ($_POST['wali_b'] ?? 0), (string) ($_POST['alasan'] ?? ''), (int) $currentUser['id']);
            master_flash('success', 'Pasangan ditandai bukan ganda dan tidak akan muncul lagi sebagai kemungkinan ganda. Tanda dapat dibatalkan dari chip “Ditandai bukan ganda”.');
        } elseif ($aksi === 'batal_bukan_ganda') {
            $service->cancelNotDuplicate((int) ($_POST['tanda_id'] ?? 0), (string) ($_POST['alasan'] ?? ''), (int) $currentUser['id']);
            master_flash('success', 'Tanda bukan ganda dibatalkan. Pasangan itu kembali muncul bila masih bernama atau ber-HP sama.');
        } else {
            throw new MasterDataException('Aksi rekonsiliasi tidak dikenal.');
        }
    } catch (Throwable $exception) {
        master_flash('danger', $exception instanceof MasterDataException ? $exception->getMessage() : 'Perubahan tidak dapat disimpan. Muat ulang halaman lalu coba lagi.');
    }
    header('Location: ' . app_url('/admin/' . $kembali));
    exit;
}

$tersedia = $service->bukanGandaTersedia();
$sections = ['duplikat' => 'Kemungkinan ganda', 'relasi_belum_lengkap' => 'Relasi belum lengkap', 'konflik_kolom_lama' => 'Konflik data lama', 'tanpa_relasi' => 'Wali tanpa santri'];
if ($tersedia) {
    $sections['bukan_ganda'] = 'Ditandai bukan ganda';
}
$section = is_string($_GET['bagian'] ?? null) && isset($sections[$_GET['bagian']]) ? $_GET['bagian'] : 'duplikat';
$q = App\Database\PageQuery::term($_GET['q'] ?? '');
$page = max(1, (int) ($_GET['page'] ?? 1));
$jumlah = $service->reconciliationSummary();

master_header('Rekonsiliasi wali', [
    'active' => 'santri',
    'description' => 'Kemungkinan ganda, relasi belum lengkap, dan konflik data lama. Tidak ada penggabungan massal; setiap pasangan dikonfirmasi satu per satu.',
    'breadcrumbs' => [['label' => 'Data santri', 'url' => app_url('/admin/admin_master_santri.php')], ['label' => 'Orang tua/wali', 'url' => app_url('/admin/admin_wali.php')], ['label' => 'Rekonsiliasi wali']],
    'actions' => '<a class="btn btn-outline-secondary" href="admin_wali.php"><i class="fas fa-arrow-left" aria-hidden="true"></i> Kembali ke daftar wali</a>',
]);

echo ah_chips(array_map(static fn (string $k, string $label): array => [
    'label' => $label,
    'url' => '?' . http_build_query(['bagian' => $k]),
    'active' => $k === $section,
    'count' => $jumlah[$k] ?? 0,
], array_keys($sections), $sections), 'Bagian rekonsiliasi');

if (!$tersedia) {
    ah_note('info', 'Tombol “Bukan ganda” belum tersedia karena migrasi basis data Tahap 6 belum dijalankan.');
}

// ---------------------------------------------------------------------------
// Kemungkinan ganda: satu pasangan per layar
// ---------------------------------------------------------------------------
if ($section === 'duplikat'):
    $hasil = $service->duplicatePair($q, $page);
    $pasangan = $hasil['pasangan'];
    $total = (int) $hasil['total'];
    $nomor = (int) $hasil['page'];
    ah_list_search($q, 'Cari nama atau nomor HP', ['bagian' => 'duplikat']);
    if ($pasangan === null):
        echo '<section class="ah-card"><div class="ah-card__body">' . ($q !== ''
            ? ah_empty('Tidak ada pasangan yang cocok', 'Coba kata lain atau bersihkan pencarian.')
            : ah_empty('Tidak ada kemungkinan ganda', 'Tidak ada identitas wali aktif dengan nama atau nomor HP yang sama.')) . '</div></section>';
    else:
        $a = $pasangan['a'];
        $b = $pasangan['b'];
        $kembali = '?' . http_build_query(array_filter(['bagian' => 'duplikat', 'q' => $q, 'page' => $nomor > 1 ? $nomor : null]));
        $lewati = '?' . http_build_query(array_filter(['bagian' => 'duplikat', 'q' => $q, 'page' => $nomor < $total ? $nomor + 1 : 1]));
        $sama = static fn (string $kolom): bool => in_array($kolom, $pasangan['jenis'], true);
        $kartu = static function (array $w, string $huruf) use ($sama): string {
            $santri = $w['relations'] === [] ? '<li class="text-muted">Belum ada santri</li>'
                : implode('', array_map(static fn (array $r): string => '<li>' . ah_e($r['nama_santri']) . ' <span class="text-muted">(' . ah_e($r['hubungan']) . ')</span></li>', $w['relations']));
            return '<article class="ah-compare__item"><h3>Identitas ' . $huruf . '</h3><dl class="ah-info-list">'
                . '<dt>Nama</dt><dd>' . ($sama('nama') ? '<mark>' . ah_e($w['nama']) . '</mark>' : ah_e($w['nama'])) . '</dd>'
                . '<dt>Nomor HP</dt><dd>' . ($sama('no_hp') ? '<mark>' . ah_e($w['no_hp'] ?: '—') . '</mark>' : ah_e($w['no_hp'] ?: '—')) . '</dd>'
                . '<dt>Alamat</dt><dd>' . ah_e($w['alamat'] ?: '—') . '</dd>'
                . '<dt>Akun login</dt><dd>' . ($w['akun'] !== null ? '@' . ah_e($w['akun']['username']) : 'Tidak ada') . '</dd>'
                . '<dt>Santri terhubung</dt><dd><ul class="mb-0">' . $santri . '</ul></dd></dl>'
                . '<a class="small" href="admin_wali.php?action=detail&amp;id=' . (int) $w['id'] . '">Buka data wali</a></article>';
        }; ?>
        <section class="ah-card" aria-labelledby="ah-pasangan">
            <div class="ah-card__head"><h2 class="h6 mb-0" id="ah-pasangan">Kemungkinan ganda · <?= $nomor ?> dari <?= $total ?></h2>
                <span class="text-muted small">Sama: <?= ah_e(implode(' dan ', array_map(static fn (string $j): string => $j === 'nama' ? 'nama' : 'nomor HP', $pasangan['jenis']))) ?></span></div>
            <div class="ah-card__body">
                <div class="ah-compare"><?= $kartu($a, 'A') ?><?= $kartu($b, 'B') ?></div>
                <?php if ($pasangan['diblokir']): ?>
                    <?php ah_note('warning', 'Penggabungan diblokir: kedua identitas memiliki akun login.', '<p class="small mb-0 mt-2">Menggabungkannya mengubah santri yang dapat dilihat akun orang tua. Selesaikan salah satu akun di Akun &amp; hak akses lebih dahulu.</p>'); ?>
                <?php endif; ?>
                <div class="ah-acts mt-3 d-flex flex-wrap gap-2 justify-content-end">
                    <?php if ($tersedia): ?>
                        <?php ah_action_dialog_open('dialog-bukan-ganda', 'Bukan ganda', 'Tandai A dan B sebagai dua orang berbeda?'); ?>
                            <form method="post">
                                <?= master_csrf() ?>
                                <input type="hidden" name="action" value="bukan_ganda"><input type="hidden" name="kembali" value="<?= ah_e($kembali) ?>">
                                <input type="hidden" name="wali_a" value="<?= (int) $a['id'] ?>"><input type="hidden" name="wali_b" value="<?= (int) $b['id'] ?>">
                                <p class="small text-muted">Pasangan ini tidak akan muncul lagi sebagai kemungkinan ganda. Alasan dan nama Anda tercatat.</p>
                                <label class="form-label" for="alasan-bukan-ganda">Alasan singkat <span class="text-danger" aria-hidden="true">*</span></label>
                                <input class="form-control mb-2" id="alasan-bukan-ganda" name="alasan" minlength="5" maxlength="500" required placeholder="mis. Nama sama, orang berbeda (beda alamat)">
                                <div class="ah-dialog__actions"><button class="btn btn-primary" type="submit">Simpan tanda</button></div>
                            </form>
                        <?php ah_action_dialog_close(); ?>
                    <?php endif; ?>
                    <a class="btn btn-outline-secondary" href="<?= ah_e($lewati) ?>">Lewati</a>
                    <?php if (!$pasangan['diblokir']): ?>
                        <?php ah_action_dialog_open('dialog-gabung', 'Gabungkan', 'Gabungkan dua identitas ini?', 'primary'); ?>
                            <form method="post">
                                <?= master_csrf() ?>
                                <input type="hidden" name="action" value="merge"><input type="hidden" name="kembali" value="<?= ah_e($kembali) ?>">
                                <input type="hidden" name="form_token" value="<?= master_e(ah_form_token('rekonsiliasi')) ?>">
                                <fieldset class="mb-2"><legend class="form-label">Identitas yang dipertahankan</legend>
                                    <?php foreach ([[$a, $b, 'A'], [$b, $a, 'B']] as [$simpan, $lepas, $huruf]): $diblokirSumber = $lepas['akun'] !== null; ?>
                                        <label class="d-flex gap-2 align-items-start mb-1"><input type="radio" name="pilihan" value="<?= (int) $simpan['id'] ?>:<?= (int) $lepas['id'] ?>" required <?= $diblokirSumber ? 'disabled' : '' ?>>
                                            <span>Pertahankan <?= $huruf ?> — <?= ah_e($simpan['nama']) ?><?= $diblokirSumber ? ' <span class="text-muted">(tidak bisa: identitas lain punya akun login)</span>' : '' ?></span></label>
                                    <?php endforeach; ?>
                                </fieldset>
                                <label class="d-flex gap-2 align-items-start mb-2"><input type="checkbox" name="konfirmasi" value="1" required class="mt-1">
                                    <span class="small">Saya sudah memeriksa santri terhubung dan yakin keduanya orang yang sama. Identitas yang tidak dipertahankan diarsipkan, tidak dihapus.</span></label>
                                <div class="ah-dialog__actions"><button class="btn btn-danger" type="submit">Gabungkan</button></div>
                            </form>
                        <?php ah_action_dialog_close(); ?>
                    <?php endif; ?>
                </div>
            </div>
        </section>
        <?php
    endif;
    master_footer();
    exit;
endif;

// ---------------------------------------------------------------------------
// Ditandai bukan ganda
// ---------------------------------------------------------------------------
if ($section === 'bukan_ganda'):
    $daftar = $service->notDuplicatePage($q, $page);
    ah_list_search($q, 'Cari nama atau nomor HP', ['bagian' => 'bukan_ganda']); ?>
    <div class="ah-card ah-table-wrap"><table class="ah-table ah-table--cards">
        <caption class="ah-visually-hidden">Pasangan wali yang ditandai bukan ganda</caption>
        <thead><tr><th scope="col">Pasangan</th><th scope="col">Alasan</th><th scope="col">Ditandai</th><th scope="col"><span class="ah-visually-hidden">Tindakan</span></th></tr></thead>
        <tbody>
        <?php foreach ($daftar['rows'] as $row): ?>
            <tr>
                <td><span class="ah-cell-wrap"><b><?= master_e($row['nama_a']) ?></b> &amp; <b><?= master_e($row['nama_b']) ?></b><span class="ah-cell-sub"><?= master_e(trim(($row['hp_a'] ?: '—') . ' · ' . ($row['hp_b'] ?: '—'))) ?></span></span></td>
                <td><span class="ah-cell-wrap"><?= master_e($row['alasan']) ?></span></td>
                <td><span class="ah-cell-wrap"><?= master_e(ah_tanggal((string) $row['ditandai_pada'], false)) ?><span class="ah-cell-sub"><?= master_e($row['ditandai_oleh_nama'] ?? '—') ?></span></span></td>
                <td data-label=""><?php ah_action_dialog_open('batal-' . (int) $row['id'], 'Batalkan tanda', 'Batalkan tanda bukan ganda?', 'outline-danger'); ?>
                    <form method="post"><?= master_csrf() ?>
                        <input type="hidden" name="action" value="batal_bukan_ganda"><input type="hidden" name="tanda_id" value="<?= (int) $row['id'] ?>"><input type="hidden" name="kembali" value="?bagian=bukan_ganda">
                        <p class="small text-muted"><?= master_e($row['nama_a'] . ' dan ' . $row['nama_b']) ?> akan kembali muncul sebagai kemungkinan ganda.</p>
                        <label class="form-label" for="alasan-batal-<?= (int) $row['id'] ?>">Alasan pembatalan <span class="text-danger" aria-hidden="true">*</span></label>
                        <input class="form-control mb-2" id="alasan-batal-<?= (int) $row['id'] ?>" name="alasan" minlength="5" maxlength="500" required>
                        <div class="ah-dialog__actions"><button class="btn btn-danger" type="submit">Batalkan tanda</button></div>
                    </form>
                <?php ah_action_dialog_close(); ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($daftar['rows'] === []): ?><tr><td colspan="4" data-label=""><?= ah_empty('Belum ada tanda bukan ganda', 'Pasangan yang Anda tandai dari bagian Kemungkinan ganda tampil di sini.') ?></td></tr><?php endif; ?>
        </tbody>
    </table></div>
    <?php
    master_pagination((int) $daftar['total'], (int) $daftar['page'], 20);
    master_footer();
    exit;
endif;

// ---------------------------------------------------------------------------
// Daftar lain dengan tombol "Perbaiki"
// ---------------------------------------------------------------------------
$result = $service->reconciliationPage($section, $q, $page);
ah_list_search($q, 'Cari nama, NIS, atau nomor HP', ['bagian' => $section]);
$kosong = match ($section) {
    'relasi_belum_lengkap' => ['Semua santri sudah memiliki relasi wali', 'Tidak ada santri yang bergantung pada data lama tanpa relasi wali.'],
    'konflik_kolom_lama' => ['Tidak ada konflik', 'Data lama sejalan dengan identitas wali yang terverifikasi.'],
    default => ['Tidak ada wali tanpa santri', 'Semua identitas wali aktif terhubung ke sedikitnya satu santri.'],
};
?>
<?php if ($section === 'relasi_belum_lengkap'): ?><p class="text-muted small">Biasanya dari impor lama atau PSB, yang menulis nama orang tua pada data lama tanpa membuat identitas wali.</p><?php endif; ?>
<?php if ($section === 'konflik_kolom_lama'): ?><p class="text-muted small">Nilai lama tidak pernah ditimpa otomatis. Selesaikan satu per satu dari formulir santri; nilai sebelum dan sesudah tercatat pada audit.</p><?php endif; ?>
<div class="ah-card ah-table-wrap"><table class="ah-table ah-table--cards">
    <caption class="ah-visually-hidden"><?= ah_e($sections[$section]) ?></caption>
    <?php if ($section === 'tanpa_relasi'): ?>
        <thead><tr><th scope="col">Nama</th><th scope="col">HP</th><th scope="col">Akun login</th><th scope="col"><span class="ah-visually-hidden">Tindakan</span></th></tr></thead>
        <tbody>
        <?php foreach ($result['rows'] as $wali): ?>
            <tr><td><?= master_e($wali['nama']) ?></td><td><?= master_e($wali['no_hp'] ?: '—') ?></td>
                <td><?= (int) $wali['jumlah_akun'] > 0 ? ah_badge('Ada', 'warn') : ah_badge('Tidak ada', 'muted') ?></td>
                <td data-label=""><a class="btn btn-sm btn-outline-primary" href="admin_wali.php?action=detail&amp;id=<?= (int) $wali['id'] ?>">Perbaiki</a></td></tr>
        <?php endforeach; ?>
    <?php else: ?>
        <thead><tr><th scope="col">Santri</th><th scope="col">Data lama</th><th scope="col"><?= $section === 'konflik_kolom_lama' ? 'Wali terverifikasi' : 'Relasi aktif' ?></th><th scope="col"><span class="ah-visually-hidden">Tindakan</span></th></tr></thead>
        <tbody>
        <?php foreach ($result['rows'] as $santri): ?>
            <tr><td><span class="ah-cell-wrap"><b><?= master_e($santri['nama_santri']) ?></b><span class="ah-cell-sub">NIS <?= master_e($santri['nis']) ?></span></span></td>
                <td><span class="ah-cell-wrap">Ayah: <?= master_e($santri['nama_ayah'] ?: '—') ?><span class="ah-cell-sub">Ibu: <?= master_e($santri['nama_ibu'] ?: '—') ?></span></span></td>
                <td><?php if ($section === 'konflik_kolom_lama'): ?><span class="ah-cell-wrap">Ayah: <?= master_e($santri['wali_ayah'] ?: '—') ?><span class="ah-cell-sub">Ibu: <?= master_e($santri['wali_ibu'] ?: '—') ?></span></span>
                    <?php else: ?><?= (int) $santri['jumlah_relasi'] ?> relasi<?php endif; ?></td>
                <td data-label=""><a class="btn btn-sm btn-outline-primary" href="admin_master_santri.php?action=edit&amp;id=<?= (int) $santri['id'] ?>#bagian-wali">Perbaiki</a></td></tr>
        <?php endforeach; ?>
    <?php endif; ?>
    <?php if ($result['rows'] === []): ?><tr><td colspan="4" data-label=""><?= $q !== '' ? ah_empty('Tidak ada hasil sesuai pencarian', 'Coba kata lain atau bersihkan pencarian.') : ah_empty($kosong[0], $kosong[1]) ?></td></tr><?php endif; ?>
    </tbody>
</table></div>
<?php master_pagination((int) $result['total'], (int) $result['page'], 20); master_footer(); ?>
