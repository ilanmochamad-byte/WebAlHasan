<?php

declare(strict_types=1);

use App\Keamanan\KeamananException;

require_once __DIR__ . '/_keamanan.php';

/**
 * Keamanan › Perizinan › Rincian (penugasan lintas guru/pengurus Fase 3).
 *
 * Isi lengkap pengajuan (alasan, catatan, keputusan, riwayat, koreksi; PK-T4)
 * untuk santri aktif, asal Pengurus maupun Wali, tahap apa pun. Hanya baca.
 * Setiap pembukaan dicatat `KeamananService` di audit tanpa isi.
 */

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id || $id < 1) {
    keamanan_tolak(404, 'ID pengajuan tidak valid.');
}
try {
    $detail = keamanan_service()->izinDetail($currentUser, (int) $id);
} catch (KeamananException $exception) {
    keamanan_tolak($exception->status(), $exception->getMessage());
}
$p = $detail['pengajuan'];
$k = $detail['keputusan'];
$baris = static function (string $label, ?string $nilai): void {
    echo '<dt class="col-sm-4">' . ah_e($label) . '</dt><dd class="col-sm-8">' . ($nilai === null || $nilai === '' ? '<span class="text-muted">—</span>' : nl2br(ah_e($nilai))) . '</dd>';
};

ah_page_open([
    'title' => 'Izin #' . (int) $p['id'] . ' · ' . $p['santri']['nama'],
    'user' => $currentUser,
    'active' => 'keamanan_izin',
    'description' => 'Hanya baca · asal ' . $p['asal'] . ' · ' . ah_rentang($p['tgl_izin'], $p['tgl_kembali']),
    'actions' => '<a class="btn btn-outline-secondary" href="' . ah_e(keamanan_url('keamanan_izin.php')) . '">Kembali ke daftar</a>',
    'breadcrumbs' => [
        ['label' => 'Beranda', 'url' => app_url('/portal/index.php')],
        ['label' => 'Perizinan santri', 'url' => keamanan_url('keamanan_izin.php')],
        ['label' => 'Izin #' . (int) $p['id']],
    ],
]);
?>
<section class="ah-card" aria-labelledby="ah-izin-pengajuan">
    <div class="ah-card__head"><h2 class="h6 mb-0" id="ah-izin-pengajuan">Pengajuan</h2><?= keamanan_izin_status((string) $p['status']) ?></div>
    <div class="ah-card__body"><dl class="row mb-0">
        <?php
        $baris('Santri', $p['santri']['nama'] . ' · NIS ' . $p['santri']['nis']);
        $baris('Kamar / kelas', trim(($p['kamar'] ?? '—') . ' · ' . ($p['kelas'] ?? '—')));
        $baris('Tahun ajaran', $p['tahun_ajaran']);
        $baris('Tanggal keluar', ah_tanggal($p['tgl_izin']));
        $baris('Tanggal kembali', ah_tanggal($p['tgl_kembali']));
        $baris('Alasan', $p['alasan']);
        $baris('Catatan pengurus', $p['catatan_pengurus']);
        if ($p['asal'] === 'Wali') {
            $baris('Catatan wali', $p['catatan_wali']);
            $baris('Wali pengaju', $p['wali']);
            $baris('Pembimbing tujuan', $p['pembimbing']);
        } else {
            $baris('Pengurus pengaju', $p['pengurus']);
        }
        $baris('Diajukan oleh', $p['pengaju']);
        $baris('Diajukan pada', $p['diajukan_pada'] === null ? null : ah_waktu($p['diajukan_pada']));
        $baris('Murobi', $p['murobi']);
        if ($p['dibatalkan_pada'] !== null) {
            $baris('Dibatalkan pada', ah_waktu($p['dibatalkan_pada']));
            $baris('Alasan pembatalan', $p['alasan_pembatalan']);
        }
        ?>
    </dl></div>
</section>

<section class="ah-card mt-3" aria-labelledby="ah-izin-keputusan">
    <div class="ah-card__head"><h2 class="h6 mb-0" id="ah-izin-keputusan">Keputusan</h2></div>
    <div class="ah-card__body">
    <?php if ($k === null): ?>
        <p class="text-muted mb-0">Belum diputus.</p>
    <?php else: ?>
        <dl class="row mb-0"><?php
            $baris('Hasil', (string) $k['hasil']);
            $baris('Alasan keputusan', $k['alasan'] === null ? null : (string) $k['alasan']);
            $baris('Diputus oleh', trim((string) ($k['pemberi_keputusan'] ?? '') . ' (' . (string) $k['kapasitas'] . ')'));
            $baris('Diputus pada', ah_waktu((string) $k['diputus_pada']));
            if (!empty($k['alasan_penggantian'])) {
                $baris('Alasan admin pengganti', (string) $k['alasan_penggantian']);
            }
        ?></dl>
    <?php endif; ?>
    <?php if ($detail['koreksi'] !== []): ?>
        <h3 class="h6 mt-3">Koreksi keputusan</h3>
        <?= ah_timeline(array_map(static fn (array $c): array => [
            'waktu' => ah_waktu((string) $c['dikoreksi_pada']),
            'pelaku' => (string) ($c['pelaku_nama'] ?? 'Sistem'),
            'judul' => $c['hasil_sebelum'] . ' → ' . $c['hasil_sesudah'],
            'isi' => 'Alasan koreksi: ' . $c['alasan_koreksi'],
        ], $detail['koreksi']), 'Koreksi keputusan') ?>
    <?php endif; ?>
    </div>
</section>

<section class="ah-card mt-3" aria-labelledby="ah-izin-riwayat">
    <div class="ah-card__head"><h2 class="h6 mb-0" id="ah-izin-riwayat">Riwayat</h2></div>
    <div class="ah-card__body">
        <?= ah_timeline(array_map(static fn (array $r): array => [
            'waktu' => ah_waktu((string) $r['created_at']),
            'pelaku' => trim((string) ($r['pelaku_nama'] ?? 'Sistem') . ($r['pelaku_kapasitas'] ? ' (' . $r['pelaku_kapasitas'] . ')' : '')),
            'judul' => ($r['status_sebelum'] ? \App\Izin\IzinWaliService::statusLabel((string) $r['status_sebelum']) . ' → ' : '')
                . \App\Izin\IzinWaliService::statusLabel((string) $r['status_sesudah']),
            'isi' => (string) ($r['alasan'] ?? ''),
        ], $detail['riwayat']), 'Riwayat status') ?>
    </div>
</section>
<?php
ah_page_close();
