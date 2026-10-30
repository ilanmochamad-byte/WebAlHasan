<?php

declare(strict_types=1);

use App\V3\V3Exception;

require_once __DIR__ . '/_keamanan.php';

/**
 * Keamanan › Pembinaan › Rincian kasus konseling (penugasan lintas guru/pengurus Fase 3).
 *
 * Kasus Internal santri aktif, isi lengkap (tujuan, sesi, hasil, tindak
 * lanjut, revisi), hanya baca. Kasus Rahasia dan ID yang tidak ada
 * diperlakukan sama: 404, sehingga keberadaan kasus Rahasia tidak bocor
 * (PK-6). Setiap pembukaan dicatat `KonselingService` di audit tanpa isi.
 */

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id || $id < 1) {
    keamanan_tolak(404, 'ID kasus tidak valid.');
}
try {
    $service = v3_konseling_service();
    $detail = $service->show($pantauUser, (int) $id);
    $timeline = $service->timeline($pantauUser, (int) $id, $detail);
} catch (V3Exception $exception) {
    keamanan_tolak($exception->status === 403 ? 404 : $exception->status, $exception->status === 403 ? 'Kasus tidak ditemukan atau tidak dapat dibaca Keamanan.' : $exception->getMessage());
}
$case = $detail['kasus'];

ah_page_open([
    'title' => 'Kasus konseling · ' . $case['santri_nama'], 'user' => $currentUser, 'active' => 'keamanan_pembinaan',
    'description' => $case['santri_nama'] . ' · ' . $case['semester'] . ' ' . $case['tahun'] . ' · dibuka ' . ah_tanggal($case['dibuka_pada'], false) . ' · hanya baca',
    'actions' => '<a class="btn btn-outline-primary" target="_blank" rel="noopener" href="' . ah_e(keamanan_url('keamanan_konseling_cetak.php', ['id' => (int) $case['id']])) . '"><i class="fas fa-print" aria-hidden="true"></i> Cetak / PDF</a>'
        . '<a class="btn btn-outline-secondary" href="' . ah_e(keamanan_url('keamanan_konseling.php')) . '">Kembali ke daftar</a>',
    'breadcrumbs' => [['label' => 'Keamanan'], ['label' => 'Konseling', 'url' => keamanan_url('keamanan_konseling.php')], ['label' => $case['santri_nama']]],
]);
?>
<div class="ah-split">
    <div class="ah-split__col">
        <section class="ah-card" aria-labelledby="ah-kasus">
            <div class="ah-card__head"><span id="ah-kasus">Kasus</span><?= ah_status($case['status']) ?></div>
            <div class="ah-card__body"><dl class="ah-info-list">
                <div><dt>Santri</dt><dd><?= ah_e($case['santri_nama'] . ' · ' . $case['semester'] . ' ' . $case['tahun']) ?></dd></div>
                <div><dt>Kerahasiaan</dt><dd><?= ah_badge($case['kerahasiaan'], 'muted') ?></dd></div>
                <div><dt>Tujuan</dt><dd style="white-space:pre-wrap"><?= ah_e($case['tujuan'] ?? '—') ?></dd></div>
                <div><dt>Dibuka</dt><dd><?= ah_e(ah_waktu($case['dibuka_pada'])) ?></dd></div>
                <?php if ($case['ditutup_pada'] !== null): ?><div><dt>Ditutup</dt><dd><?= ah_e(ah_waktu((string) $case['ditutup_pada'])) ?></dd></div><?php endif ?>
                <?php if (($case['ringkasan_penutupan'] ?? null) !== null): ?><div><dt>Ringkasan penutupan</dt><dd style="white-space:pre-wrap"><?= ah_e($case['ringkasan_penutupan']) ?></dd></div><?php endif ?>
                <?php if (($case['alasan_pembatalan'] ?? null) !== null): ?><div><dt>Alasan pembatalan</dt><dd style="white-space:pre-wrap"><?= ah_e($case['alasan_pembatalan']) ?></dd></div><?php endif ?>
            </dl></div>
        </section>
        <section class="ah-card" aria-labelledby="ah-sesi">
            <div class="ah-card__head"><span id="ah-sesi">Sesi (<?= count($detail['sesi_aktif']) ?>)</span></div>
            <div class="ah-card__body"><?php if ($detail['sesi_aktif'] === []): ?><p class="text-muted mb-0">Belum ada sesi.</p><?php else: ?>
                <?= ah_timeline(array_map(static fn (array $s): array => [
                    'waktu' => ah_waktu((string) ($s['realisasi'] ?? $s['jadwal'])),
                    'pelaku' => 'sesi',
                    'judul' => (string) $s['status'],
                    'isi' => trim(($s['ringkasan_internal'] ? 'Ringkasan: ' . $s['ringkasan_internal'] : '') . ($s['hasil'] ? "\nHasil: " . $s['hasil'] : '') . ($s['tindak_lanjut'] ? "\nTindak lanjut: " . $s['tindak_lanjut'] : '')),
                ], $detail['sesi_aktif']), 'Sesi konseling') ?>
            <?php endif ?></div>
        </section>
    </div>
    <div class="ah-split__col">
        <section class="ah-card" aria-labelledby="ah-tautan">
            <div class="ah-card__head"><span id="ah-tautan">Pelanggaran tertaut</span></div>
            <div class="ah-card__body"><?php if ($detail['tautan'] === []): ?><p class="text-muted mb-0">Tidak ada pelanggaran tertaut.</p><?php else: ?>
                <ul class="ah-qlist"><?php foreach ($detail['tautan'] as $link): ?><li><a href="<?= ah_e(keamanan_url('keamanan_pelanggaran_detail.php', ['id' => (int) $link['pelanggaran_id']])) ?>"><span class="ah-qlist__main"><b><?= ah_e($link['kategori']) ?></b><small><?= ah_e(ah_waktu($link['waktu_kejadian']) . ' · ' . $link['poin'] . ' poin') ?></small></span><?= ah_status($link['status']) ?></a></li><?php endforeach ?></ul>
            <?php endif ?></div>
        </section>
        <section class="ah-card" aria-labelledby="ah-linimasa">
            <div class="ah-card__head"><span id="ah-linimasa">Linimasa</span></div>
            <div class="ah-card__body"><?= ah_timeline(array_map(static fn (array $ev): array => ['waktu' => ah_waktu((string) $ev['waktu']), 'pelaku' => 'kasus', 'judul' => (string) $ev['jenis']], $timeline['rows']), 'Linimasa kasus') ?></div>
        </section>
        <?php if ($detail['riwayat_revisi_kasus'] !== []): ?>
        <section class="ah-card" aria-labelledby="ah-revisi">
            <div class="ah-card__head"><span id="ah-revisi">Riwayat revisi kasus</span></div>
            <div class="ah-card__body"><?= ah_timeline(array_map(static fn (array $r): array => ['waktu' => ah_waktu((string) $r['created_at']), 'pelaku' => (string) $r['kapasitas'], 'judul' => 'Revisi dari versi ' . $r['versi_sebelum'], 'isi' => 'Alasan: ' . $r['alasan']], $detail['riwayat_revisi_kasus']), 'Riwayat revisi kasus') ?></div>
        </section>
        <?php endif ?>
        <p class="small text-muted" data-hanya-baca>Halaman ini hanya baca. Kasus Rahasia tidak pernah ditampilkan kepada Keamanan.</p>
    </div>
</div>
<?php
ah_page_close();
