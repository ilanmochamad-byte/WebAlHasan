<?php
/*
 * Jadwal pengajian publik (U-7) — redesain website Tahap 8.
 *
 * Pilihan waktu sholat berupa kontrol segmen, saringan kelas dan pencarian
 * kitab/guru untuk wali yang mencari jadwal anaknya. Tabel menjadi kartu di
 * HP. Sumber data tetap `jadwal_ngaji` tahun ajaran aktif; jadwal yang sudah
 * dinonaktifkan/diarsipkan admin tidak ditampilkan. Query terikat.
 */
require_once __DIR__ . '/koneksi.php';

use App\Situs\Kerangka;

const WAKTU_NGAJI = ["Ba'da Shubuh", "Ba'da Ashar", "Ba'da Magrib", "Ba'da Isya"];

$e = static fn (mixed $v): string => Kerangka::e($v);
$tahun = $koneksi->query("SELECT id, tahun, semester FROM tahun_ajaran WHERE status = 'Aktif' ORDER BY id DESC LIMIT 1")->fetch_assoc();
$idTahun = (int) ($tahun['id'] ?? 0);
$waktu = is_string($_GET['waktu'] ?? null) && in_array($_GET['waktu'], WAKTU_NGAJI, true) ? $_GET['waktu'] : WAKTU_NGAJI[0];
$kelas = (int) filter_var($_GET['kelas'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['default' => 0, 'min_range' => 1]]);
$cari = is_string($_GET['cari'] ?? null) ? trim(mb_substr($_GET['cari'], 0, 80)) : '';

$aktif = 'j.id_tahun = ? AND j.is_active = 1 AND j.archived_at IS NULL';
$stmt = $koneksi->prepare("SELECT j.waktu_sholat, COUNT(*) n FROM jadwal_ngaji j WHERE $aktif GROUP BY j.waktu_sholat");
$stmt->bind_param('i', $idTahun);
$stmt->execute();
$jumlahPerWaktu = array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'n', 'waktu_sholat');
$stmt->close();

// Kelas gabungan (migrasi 034): pilihan kelas memuat seluruh kelas jadwal aktif.
$mencakup = App\Schedule\KelasJadwal::jadwalMencakup($koneksi);
$stmt = $koneksi->prepare("SELECT k.id, k.nama_kelas FROM kelas k WHERE EXISTS (SELECT 1 FROM jadwal_ngaji j WHERE $aktif AND " . App\Schedule\KelasJadwal::jadwalMencakup($koneksi, 'j', 'k.id') . ") ORDER BY k.nama_kelas");
$stmt->bind_param('i', $idTahun);
$stmt->execute();
$daftarKelas = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$sql = "SELECT j.jam, j.fan_ilmu, j.nama_kitab, j.tempat, g.nama_guru, " . App\Schedule\KelasJadwal::labelJadwal($koneksi) . " AS nama_kelas
        FROM jadwal_ngaji j JOIN guru g ON g.id = j.id_guru JOIN kelas k ON k.id = j.id_kelas
        WHERE $aktif AND j.waktu_sholat = ?";
$tipe = 'is';
$nilai = [$idTahun, $waktu];
if ($kelas > 0) {
    $sql .= ' AND ' . $mencakup;
    $tipe .= 'i';
    $nilai[] = $kelas;
}
if ($cari !== '') {
    $sql .= ' AND (j.nama_kitab LIKE ? OR j.fan_ilmu LIKE ? OR g.nama_guru LIKE ?)';
    $pola = '%' . addcslashes($cari, '%_\\') . '%';
    $tipe .= 'sss';
    array_push($nilai, $pola, $pola, $pola);
}
$sql .= ' ORDER BY nama_kelas, j.jam';
$stmt = $koneksi->prepare($sql);
$stmt->bind_param($tipe, ...$nilai);
$stmt->execute();
$jadwal = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$tautan = static fn (array $ubah): string => app_url('/jadwal_ngaji.php?' . http_build_query(array_filter(
    $ubah + ['waktu' => $waktu, 'kelas' => $kelas ?: null, 'cari' => $cari !== '' ? $cari : null],
    static fn ($v): bool => $v !== null && $v !== ''
)));

$situs = ['judul' => 'Jadwal pengajian', 'aktif' => 'jadwal', 'deskripsi' => 'Jadwal pengajian kitab Pesantren Al Hasan per waktu sholat.'];
include 'header.php';
?>
<div class="s-wrap pb-4">
    <header class="s-head">
        <ol class="s-crumbs"><li><a href="<?= $e(app_url('/index.php')) ?>">Beranda</a></li><li aria-current="page">Jadwal ngaji</li></ol>
        <h1>Jadwal pengajian kitab</h1>
        <p><?= $tahun ? 'Tahun ajaran ' . $e($tahun['tahun']) . ' · Semester ' . $e($tahun['semester']) : 'Tahun ajaran aktif belum ditetapkan.' ?></p>
    </header>

    <nav class="s-seg" aria-label="Waktu pengajian">
        <?php foreach (WAKTU_NGAJI as $w): ?>
            <a href="<?= $e($tautan(['waktu' => $w])) ?>"<?= $w === $waktu ? ' aria-current="page"' : '' ?>><?= $e($w) ?><span class="s-jumlah">(<?= (int) ($jumlahPerWaktu[$w] ?? 0) ?>)</span></a>
        <?php endforeach; ?>
    </nav>

    <form class="s-cari" method="get" action="<?= $e(app_url('/jadwal_ngaji.php')) ?>" role="search">
        <input type="hidden" name="waktu" value="<?= $e($waktu) ?>">
        <label class="s-vh" for="cari-jadwal">Cari kitab atau guru</label>
        <input class="s-input" id="cari-jadwal" type="search" name="cari" value="<?= $e($cari) ?>" placeholder="Cari kitab atau guru…" maxlength="80">
        <label class="s-vh" for="kelas-jadwal">Kelas</label>
        <select class="s-select" id="kelas-jadwal" name="kelas" style="flex:0 1 220px">
            <option value="">Semua kelas</option>
            <?php foreach ($daftarKelas as $k): ?>
                <option value="<?= (int) $k['id'] ?>"<?= (int) $k['id'] === $kelas ? ' selected' : '' ?>><?= $e($k['nama_kelas']) ?></option>
            <?php endforeach; ?>
        </select>
        <button class="s-btn s-btn--pri" type="submit" data-s-tanpa-kunci>Terapkan</button>
        <?php if ($kelas > 0 || $cari !== ''): ?><a class="s-btn" href="<?= $e(app_url('/jadwal_ngaji.php?waktu=' . rawurlencode($waktu))) ?>">Hapus saringan</a><?php endif; ?>
    </form>

    <?php if ($jadwal === []): ?>
        <div class="s-kosong"><b>Belum ada jadwal</b><?= ($kelas > 0 || $cari !== '') ? 'Tidak ada jadwal ' . $e($waktu) . ' yang cocok dengan saringan.' : 'Belum ada jadwal pengajian untuk waktu ' . $e($waktu) . '.' ?></div>
    <?php else: ?>
        <table class="s-table">
            <caption class="s-vh">Jadwal <?= $e($waktu) ?></caption>
            <thead><tr><th scope="col">Jam</th><th scope="col">Kelas</th><th scope="col">Fan ilmu</th><th scope="col">Kitab</th><th scope="col">Guru</th><th scope="col">Tempat</th></tr></thead>
            <tbody>
                <?php foreach ($jadwal as $j): ?>
                    <tr>
                        <td data-label="Jam"><?= $e($j['jam']) ?></td>
                        <td data-label="Kelas"><?= $e($j['nama_kelas']) ?></td>
                        <td data-label="Fan ilmu"><?= $e($j['fan_ilmu']) ?></td>
                        <td class="s-table__utama" data-label="Kitab"><?= $e($j['nama_kitab']) ?></td>
                        <td data-label="Guru"><?= $e($j['nama_guru']) ?></td>
                        <td data-label="Tempat"><?= $e($j['tempat']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
<?php include 'footer.php'; ?>
