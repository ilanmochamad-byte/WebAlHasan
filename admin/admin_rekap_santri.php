<?php

declare(strict_types=1);

use App\Ui\Chart;

require_once __DIR__ . '/_guard.php';

/**
 * Data santri › Rekap jumlah (redesain website Tahap 3, K-8).
 *
 * Jumlah santri aktif per kelas pada tahun ajaran aktif, laki-laki dan
 * perempuan, dengan baris total tebal dan grafik pendamping. Kelas yang hanya
 * berbeda akhiran PA/PI digabung seperti halaman lama. Unduhan Excel dibuat
 * di server (CSS DataTables dan SheetJS lama dibuang).
 */

$tahun = beranda_service()->tahunAktif();
$idTahun = (int) ($tahun['id'] ?? 0);
$rekap = [];
$jenjang = [];
$belum = ['l' => 0, 'p' => 0, 'tot' => 0];
if ($idTahun > 0) {
    $stmt = $koneksi->prepare("SELECT k.nama_kelas, COALESCE(NULLIF(k.jenjang, ''), 'Tanpa jenjang') AS jenjang,
            SUM(CASE WHEN s.jenis_kelamin = 'L' THEN 1 ELSE 0 END) AS total_l,
            SUM(CASE WHEN s.jenis_kelamin = 'P' THEN 1 ELSE 0 END) AS total_p,
            COUNT(s.id) AS total_kelas
        FROM kelas k
        LEFT JOIN plotting_kelas pk ON k.id = pk.id_kelas AND pk.id_tahun = ? AND pk.status = 'Aktif'
        LEFT JOIN santri s ON pk.id_santri = s.id
        GROUP BY k.id, k.nama_kelas, k.jenjang
        ORDER BY k.jenjang ASC, k.nama_kelas ASC");
    $stmt->bind_param('i', $idTahun);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $d) {
        // "1 IBTIDA PA" dan "1 IBTIDA PI" digabung menjadi "1 IBTIDA".
        $nama = preg_replace('/\s+(PA|PI)$/i', '', (string) $d['nama_kelas']);
        $rekap[$nama] ??= ['l' => 0, 'p' => 0, 'tot' => 0];
        $rekap[$nama]['l'] += (int) $d['total_l'];
        $rekap[$nama]['p'] += (int) $d['total_p'];
        $rekap[$nama]['tot'] += (int) $d['total_kelas'];
        $jenjang[(string) $d['jenjang']] = ($jenjang[(string) $d['jenjang']] ?? 0) + (int) $d['total_kelas'];
    }
    $stmt->close();
    $stmt = $koneksi->prepare("SELECT SUM(jenis_kelamin = 'L') AS l, SUM(jenis_kelamin = 'P') AS p, COUNT(id) AS tot
        FROM santri WHERE is_active = 1 AND archived_at IS NULL
          AND id NOT IN (SELECT id_santri FROM plotting_kelas WHERE id_tahun = ? AND status = 'Aktif')");
    $stmt->bind_param('i', $idTahun);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
    $belum = ['l' => (int) ($row['l'] ?? 0), 'p' => (int) ($row['p'] ?? 0), 'tot' => (int) ($row['tot'] ?? 0)];
}
$total = ['l' => $belum['l'], 'p' => $belum['p'], 'tot' => $belum['tot']];
foreach ($rekap as $data) {
    $total['l'] += $data['l'];
    $total['p'] += $data['p'];
    $total['tot'] += $data['tot'];
}
if ($belum['tot'] > 0) {
    $jenjang['Belum berkelas'] = $belum['tot'];
}

if (isset($_GET['unduh']) && $idTahun > 0) {
    $baris = [['NO', 'KELAS', 'LAKI-LAKI', 'PEREMPUAN', 'JUMLAH']];
    $no = 1;
    foreach ($rekap as $nama => $data) {
        $baris[] = [$no++, $nama, $data['l'], $data['p'], $data['tot']];
    }
    if ($belum['tot'] > 0) {
        $baris[] = ['-', 'Belum ditempatkan di kelas', $belum['l'], $belum['p'], $belum['tot']];
    }
    $baris[] = ['', 'TOTAL SANTRI AKTIF', $total['l'], $total['p'], $total['tot']];
    ah_xlsx_download('Rekap_Jumlah_Santri_AlHasan_' . date('Y-m-d') . '.xlsx', $baris);
}

$angka = static fn (int $nilai): string => $nilai > 0 ? number_format($nilai, 0, ',', '.') : '–';
ah_page_open([
    'title' => 'Rekap jumlah santri',
    'user' => $currentUser,
    'active' => 'santri',
    'description' => $tahun !== null ? 'Tahun ajaran aktif ' . $tahun['tahun'] . ' ' . $tahun['semester'] . '. Santri dihitung dari penempatan kelas berstatus aktif.' : null,
    'actions' => $idTahun > 0 ? '<a class="btn btn-outline-secondary" href="' . ah_e(app_url('/admin/admin_rekap_santri.php?unduh=1')) . '">Unduh Excel</a>' : '',
]);
if ($idTahun === 0):
    ah_note('danger', 'Tahun ajaran aktif belum diatur. Atur di Pengaturan › Tahun ajaran agar jumlah santri dapat dihitung.', ' <a href="' . ah_e(app_url('/admin/admin_tahun.php')) . '">Buka tahun ajaran</a>');
else:
    ?>
    <div class="ah-chart-grid">
        <section class="ah-card" aria-labelledby="judul-rekap-santri">
            <div class="ah-card__head"><h2 class="h6 mb-0" id="judul-rekap-santri">Jumlah per kelas</h2></div>
            <div class="ah-table-wrap">
                <table class="ah-table" id="tabelRekapSantri">
                    <caption class="ah-visually-hidden">Jumlah santri aktif per kelas, laki-laki dan perempuan</caption>
                    <thead><tr><th scope="col">Kelas</th><th scope="col">Laki-laki</th><th scope="col">Perempuan</th><th scope="col">Jumlah</th></tr></thead>
                    <tbody>
                    <?php foreach ($rekap as $nama => $data): ?>
                        <tr><th scope="row"><?= ah_e($nama) ?></th><td><?= $angka($data['l']) ?></td><td><?= $angka($data['p']) ?></td><td><b><?= $angka($data['tot']) ?></b></td></tr>
                    <?php endforeach; ?>
                    <?php if ($belum['tot'] > 0): ?>
                        <tr><th scope="row"><?= ah_badge('Perlu penempatan', 'warn') ?> Belum ditempatkan di kelas</th><td><?= $angka($belum['l']) ?></td><td><?= $angka($belum['p']) ?></td><td><b><a href="<?= ah_e(app_url('/admin/admin_penempatan_santri.php?status=tanpa_kelas')) ?>"><?= $angka($belum['tot']) ?></a></b></td></tr>
                    <?php endif; ?>
                    <?php if ($rekap === [] && $belum['tot'] === 0): ?>
                        <tr><td colspan="4"><?= ah_empty('Belum ada kelas atau santri aktif', 'Tambahkan kelas dan tempatkan santri pada semester aktif.') ?></td></tr>
                    <?php endif; ?>
                    </tbody>
                    <tfoot><tr><th scope="row">Total santri aktif</th><th><?= number_format($total['l'], 0, ',', '.') ?></th><th><?= number_format($total['p'], 0, ',', '.') ?></th><th><?= number_format($total['tot'], 0, ',', '.') ?></th></tr></tfoot>
                </table>
            </div>
        </section>
        <div class="ah-editor__side">
            <section class="ah-card ah-card__body"><?= Chart::bar('Santri per jenjang', $jenjang, 'Belum ada santri aktif.') ?></section>
            <section class="ah-card ah-card__body"><?= Chart::donut('Rasio laki-laki dan perempuan', ['Laki-laki' => $total['l'], 'Perempuan' => $total['p']], 'Belum ada santri aktif.') ?></section>
        </div>
    </div>
    <?php
endif;
ah_page_close();
