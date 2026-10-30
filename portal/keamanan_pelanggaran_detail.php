<?php

declare(strict_types=1);

use App\V3\V3Exception;

require_once __DIR__ . '/_keamanan.php';

/**
 * Keamanan › Pembinaan › Rincian pelanggaran (penugasan lintas guru/pengurus Fase 3).
 *
 * Catatan pelanggaran lengkap (uraian, saksi, poin, lampiran, riwayat; PK-T4)
 * untuk santri aktif, hanya baca. Kasus konseling Rahasia yang menindaklanjuti
 * catatan ini tidak muncul (PK-6). Setiap pembukaan dicatat
 * `PelanggaranService` di audit tanpa isi.
 */

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id || $id < 1) {
    keamanan_tolak(404, 'ID pelanggaran tidak valid.');
}
try {
    $detail = v3_pelanggaran_service()->show($pantauUser, (int) $id);
} catch (V3Exception $exception) {
    keamanan_tolak($exception->status === 403 ? 404 : $exception->status, $exception->status === 403 ? 'Pelanggaran tidak ditemukan atau santrinya tidak aktif.' : $exception->getMessage());
}
$row = $detail['pelanggaran'];
$judul = (string) ($row['jenis'] ?? $row['kategori']);
$current = $row['digantikan_oleh_id'] === null;

ah_page_open([
    'title' => $judul . ' · ' . $row['santri_nama'], 'heading' => $judul, 'user' => $currentUser, 'active' => 'keamanan_pembinaan',
    'description' => $row['santri_nama'] . ' · ' . ah_waktu($row['waktu_kejadian']) . ($row['tempat'] ? ' · ' . $row['tempat'] : '') . ' · hanya baca',
    'actions' => '<a class="btn btn-outline-primary" target="_blank" rel="noopener" href="' . ah_e(keamanan_url('keamanan_pelanggaran_cetak.php', ['id' => (int) $row['id']])) . '"><i class="fas fa-print" aria-hidden="true"></i> Cetak / PDF</a>'
        . '<a class="btn btn-outline-secondary" href="' . ah_e(keamanan_url('keamanan_pelanggaran.php')) . '">Kembali ke daftar</a>',
    'breadcrumbs' => [['label' => 'Keamanan'], ['label' => 'Pelanggaran', 'url' => keamanan_url('keamanan_pelanggaran.php')], ['label' => $row['santri_nama'] . ' · ' . ah_tanggal($row['waktu_kejadian'], false, false)]],
]);
if (!$current) {
    ah_note('info', 'Catatan ini sudah dikoreksi dan digantikan revisi yang lebih baru.', '<p class="mb-0 mt-2"><a href="' . ah_e(keamanan_url('keamanan_pelanggaran_detail.php', ['id' => (int) $row['digantikan_oleh_id']])) . '">Buka revisi terbaru</a></p>');
}
?>
<div class="ah-split">
    <div class="ah-split__col">
        <section class="ah-card" aria-labelledby="ah-catatan">
            <div class="ah-card__head"><span id="ah-catatan">Catatan</span><?= ah_status($row['status']) ?></div>
            <div class="ah-card__body"><dl class="ah-info-list">
                <div><dt>Santri</dt><dd><?= ah_e($row['santri_nama']) ?> · <?= ah_e($row['semester'] . ' ' . $row['tahun']) ?></dd></div>
                <div><dt>Jenis</dt><dd><?= ah_e($judul) ?><?= ($row['jenis'] ?? null) !== null ? ' · ' . ah_e($row['kategori']) : '' ?> <?= ah_tingkat($row['tingkat']) ?></dd></div>
                <div><dt>Poin</dt><dd><?= (int) $row['poin'] ?></dd></div>
                <div><dt>Waktu &amp; tempat</dt><dd><?= ah_e(ah_waktu($row['waktu_kejadian']) . ' · ' . ($row['tempat'] ?? 'Tempat tidak dicatat')) ?></dd></div>
                <div><dt>Uraian</dt><dd style="white-space:pre-wrap"><?= ah_e($row['uraian']) ?></dd></div>
                <div><dt>Saksi</dt><dd style="white-space:pre-wrap"><?= ah_e($row['saksi'] ?? '—') ?></dd></div>
                <div><dt>Dicatat</dt><dd><?= ah_e(($row['pencatat'] ?? 'Admin pesantren') . ' · ' . ah_waktu($row['created_at'])) ?></dd></div>
                <?php if ($row['alasan_revisi']): ?><div><dt>Alasan koreksi</dt><dd style="white-space:pre-wrap"><?= ah_e($row['alasan_revisi']) ?></dd></div><?php endif ?>
                <?php if ($row['alasan_pembatalan']): ?><div><dt>Alasan pembatalan</dt><dd style="white-space:pre-wrap"><?= ah_e($row['alasan_pembatalan']) ?></dd></div><?php endif ?>
            </dl></div>
        </section>
        <section class="ah-card" aria-labelledby="ah-riwayat">
            <div class="ah-card__head"><span id="ah-riwayat">Riwayat catatan</span></div>
            <div class="ah-card__body"><?php
                $linimasa = [];
                foreach ($detail['riwayat_revisi'] as $revision) {
                    $linimasa[] = ['t' => (string) $revision['created_at'], 'waktu' => ah_waktu($revision['created_at']), 'pelaku' => $revision['id'] === (int) $id ? 'catatan ini' : 'revisi lain',
                        'judul' => $revision['revisi_dari_id'] === null ? 'Catatan dibuat' : 'Koreksi menjadi revisi baru',
                        'isi' => trim(($revision['alasan_revisi'] ? 'Alasan koreksi: ' . $revision['alasan_revisi'] : '') . ($revision['alasan_pembatalan'] ? "\nDibatalkan: " . $revision['alasan_pembatalan'] : '')), 'tone' => $revision['status'] === 'Dibatalkan' ? 'warn' : ''];
                }
                foreach ($detail['murobi'] as $note) {
                    $linimasa[] = ['t' => (string) $note['diketahui_pada'], 'waktu' => ah_waktu($note['diketahui_pada']), 'pelaku' => 'murobi', 'judul' => 'Murobi menandai mengetahui', 'isi' => (string) ($note['catatan'] ?? '')];
                }
                usort($linimasa, static fn (array $a, array $b): int => strcmp($a['t'], $b['t']));
                echo ah_timeline(array_map(static fn (array $i): array => array_diff_key($i, ['t' => true]), $linimasa), 'Riwayat catatan dan tanda mengetahui');
            ?></div>
        </section>
    </div>
    <div class="ah-split__col">
        <section class="ah-card" aria-labelledby="ah-poin">
            <div class="ah-card__head"><span id="ah-poin">Poin santri <?= ah_e($row['semester'] . ' ' . $row['tahun']) ?></span></div>
            <div class="ah-card__body">
                <p class="ah-big mb-2"><?= (int) $detail['total_poin'] ?> <small>poin</small></p>
                <?php if ($detail['ledger'] !== []): ?>
                    <ul class="ah-mini-list mb-0"><?php foreach ($detail['ledger'] as $ledger): ?>
                        <li><span><?= ah_e(ah_waktu($ledger['created_at']) . ' · ' . ($ledger['pembalik_dari_id'] ? 'Pengurangan karena koreksi/pembatalan' : $ledger['alasan'])) ?></span><b><?= $ledger['perubahan_poin'] >= 0 ? '+' : '' ?><?= (int) $ledger['perubahan_poin'] ?></b></li>
                    <?php endforeach ?></ul>
                <?php endif ?>
            </div>
        </section>
        <section class="ah-card" aria-labelledby="ah-lampiran">
            <div class="ah-card__head"><span id="ah-lampiran">Lampiran privat</span></div>
            <div class="ah-card__body"><?php if ($detail['lampiran'] === []): ?><p class="text-muted mb-0">Tidak ada lampiran.</p><?php else: ?>
                <ul class="ah-qlist"><?php foreach ($detail['lampiran'] as $file): ?><li><a href="<?= ah_e(keamanan_url('keamanan_lampiran.php', ['id' => (int) $file['id']])) ?>"><span class="ah-qlist__main"><b><?= ah_e($file['nama']) ?></b><small><?= ah_e($file['mime'] . ' · ' . number_format($file['ukuran'] / 1024, 1, ',', '.') . ' KB · ' . ah_waktu($file['created_at'])) ?></small></span><i class="fas fa-download" aria-hidden="true"></i></a></li><?php endforeach ?></ul>
                <p class="small text-muted mt-2 mb-0">Setiap unduhan tercatat di audit akses.</p>
            <?php endif ?></div>
        </section>
        <section class="ah-card" aria-labelledby="ah-konseling">
            <div class="ah-card__head"><span id="ah-konseling">Tindak lanjut konseling</span></div>
            <div class="ah-card__body"><?php if ($detail['konseling'] === []): ?><p class="text-muted mb-0">Belum ditautkan ke kasus konseling yang dapat dibaca.</p><?php else: $perKasus = []; foreach ($detail['konseling'] as $f) { $perKasus[$f['kasus_id']][] = $f; } ?>
                <ul class="ah-qlist"><?php foreach ($perKasus as $kasusId => $items): ?><li><a href="<?= ah_e(keamanan_url('keamanan_konseling_detail.php', ['id' => (int) $kasusId])) ?>"><span class="ah-qlist__main"><b>Kasus konseling <?= ah_e($row['santri_nama']) ?></b><small><?= ah_e(implode(' · ', array_map(static fn (array $f): string => $f['sesi_id'] ? 'Sesi ' . ah_tanggal((string) $f['jadwal'], false) . ' ' . $f['sesi_status'] : 'Belum ada sesi', $items))) ?></small></span><?= ah_status($items[0]['kasus_status']) ?></a></li><?php endforeach ?></ul>
            <?php endif ?></div>
        </section>
        <p class="small text-muted" data-hanya-baca>Halaman ini hanya baca. Pencatatan, koreksi, dan tanda mengetahui dilakukan pembimbing, murobi, atau admin.</p>
    </div>
</div>
<?php
ah_page_close();
