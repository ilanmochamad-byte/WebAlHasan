<?php

declare(strict_types=1);

use App\Auth\Capabilities;
use App\Http\Csrf;
use App\Http\SafeRedirect;
use App\Beranda\BerandaService;
use App\Ui\Layout;
use App\Ui\Navigation;
use App\Ui\Peran;

/**
 * SATU PINTU MASUK Sistem Al Hasan (koreksi ke-7, keputusan 30 Agustus 2026).
 *
 * Berkas ini punya dua wajah, ditentukan oleh keadaan sesi:
 *
 *   1. Belum masuk  → halaman "Masuk Sistem Al Hasan" (redesain Tahap 2, A-1).
 *   2. Sudah masuk  → Beranda PERAN AKTIF (redesain Tahap 2, B-1 s.d. B-5):
 *      tugas yang menunggu untuk peran itu, bukan daftar pintasan.
 *
 * Yang PENTING: halaman ini TIDAK memakai `portal/_guard.php` maupun
 * `portal/_ui.php` (yang membawa guard itu). Guard itu
 * menuntut kemampuan perizinan, sehingga guru tanpa penugasan murobi dulu
 * selalu ditolak 403 di beranda umum. Halaman ini hanya menuntut "sudah
 * masuk"; setiap angka dihitung pada cakupan nyata akun (`BerandaService`) dan
 * setiap halaman tujuan tetap memeriksa hak dan cakupannya sendiri di server.
 * Menyembunyikan kartu BUKAN kontrol akses.
 *
 * Autentikasi memakai sistem yang sudah ada (`AuthService`, `AuthRepository`,
 * sesi, CSRF). Tidak ada sistem login kedua: formulir di bawah mengirim ke
 * `admin/cek_login.php`, penangan POST login yang sama dengan alamat lama.
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

$currentUser = authorization()->currentUser();
$next = SafeRedirect::sanitize($_GET['next'] ?? null);

// ---------------------------------------------------------------------------
// 1. Belum masuk: halaman masuk (A-1).
// ---------------------------------------------------------------------------
if ($currentUser === null) {
    $pesan = match ((string) ($_GET['pesan'] ?? '')) {
        'gagal' => ['danger', 'Nama pengguna atau kata sandi salah.'],
        'sesi' => ['warning', 'Sesi Anda berakhir. Silakan masuk lagi.'],
        'logout' => ['success', 'Anda sudah keluar. Sampai jumpa.'],
        'terkunci' => ['danger', 'Terlalu banyak percobaan. Coba lagi beberapa saat lagi.'],
        'tanpa_akses' => ['warning', 'Akun ini belum punya hak akses. Hubungi admin.'],
        default => null,
    };
    ?>
<!DOCTYPE html>
<html lang="id" class="ah-nojs">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Masuk Sistem Al Hasan</title>
    <script>document.documentElement.className = document.documentElement.className.replace('ah-nojs', 'ah-js');</script>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="<?= ah_e(Layout::asset('/assets/ui/alhasan.css')) ?>">
</head>
<body class="ah ah-masuk">
<main class="ah-masuk__wrap">
    <section class="ah-masuk__id" aria-hidden="true">
        <span class="ah-masuk__mark"><i class="fas fa-mosque"></i></span>
        <p class="ah-masuk__name">Sistem Al Hasan</p>
        <p class="ah-masuk__tag">Satu pintu masuk untuk admin, guru, murobi, pengurus, dan orang tua.</p>
    </section>
    <section class="ah-masuk__form">
        <div class="text-center mb-3 ah-masuk__head">
            <span class="ah-masuk__mark ah-masuk__mark--small" aria-hidden="true"><i class="fas fa-mosque"></i></span>
            <h1 class="h4 mb-1">Masuk Sistem Al Hasan</h1>
            <p class="text-muted small mb-0">Pesantren Al Hasan Ciamis</p>
        </div>

        <div class="ah-card"><div class="ah-card__body">
            <?php if ($pesan !== null) {
                Layout::note($pesan[0], $pesan[1]);
            } ?>

            <form action="<?= ah_e(app_url('/admin/cek_login.php')) ?>" method="post" novalidate>
                <?= Csrf::input() ?>
                <?php if ($next !== null): ?>
                    <input type="hidden" name="next" value="<?= ah_e($next) ?>">
                <?php endif; ?>
                <div class="mb-3">
                    <label class="form-label" for="username">Nama pengguna</label>
                    <input class="form-control" id="username" name="username" type="text"
                           autocomplete="username" autocapitalize="none" spellcheck="false" required autofocus>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="password">Kata sandi</label>
                    <div class="ah-reveal">
                        <input class="form-control" id="password" name="password" type="password"
                               autocomplete="current-password" required>
                        <button class="ah-reveal__btn" type="button" data-ah-reveal="password" aria-pressed="false" aria-controls="password">
                            <i class="fas fa-eye" aria-hidden="true"></i><span>Tampilkan</span>
                        </button>
                    </div>
                </div>
                <button class="btn btn-primary w-100" type="submit">Masuk</button>
            </form>
        </div></div>

        <p class="text-center small text-muted mt-3 mb-0">
            Lupa kata sandi? Hubungi admin pesantren.<br>
            <a class="link-secondary" href="<?= ah_e(app_url('/index.php')) ?>">← Kembali ke situs Al Hasan</a>
        </p>
    </section>
</main>
<script>window.ALHASAN_CSRF = <?= json_encode(Csrf::token(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
<script src="<?= ah_e(Layout::asset('/assets/ui/alhasan.js')) ?>"></script>
</body>
</html>
    <?php
    exit;
}

// ---------------------------------------------------------------------------
// 2. Sudah masuk: kata sandi sementara wajib diselesaikan lebih dulu.
// ---------------------------------------------------------------------------
if (!empty($currentUser['force_password_change'])) {
    header('Location: ' . app_url('/admin/ubah_password.php')
        . ($next === null ? '' : '?next=' . rawurlencode($next)));
    exit;
}

// Tujuan yang tersimpan sebelum masuk dipulihkan sekali. Guard halaman tujuan
// tetap memeriksa haknya sendiri, termasuk setelah berganti akun.
if ($next !== null) {
    header('Location: ' . $next);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid($_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
}

$context = ui_context($currentUser);
$capabilities = $context['capabilities'];
$peran = Peran::active($currentUser);
$beranda = beranda_service();
$tahun = $beranda->tahunAktif();
$semesterLabel = $tahun === null ? null : 'Semester ' . $tahun['semester'] . ' ' . $tahun['tahun'];

/**
 * Tanggal panjang: "Rabu, 22 Oktober 2026" (Minggu ditulis Ahad, keputusan T).
 */
function beranda_tanggal(int $time): string
{
    $bulan = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

    return Layout::dayName($time) . ', ' . (int) date('j', $time) . ' ' . $bulan[(int) date('n', $time) - 1] . ' ' . date('Y', $time);
}

/**
 * Rentang tanggal izin ringkas: "12–14 Okt 2026".
 */
function beranda_rentang(?string $mulai, ?string $selesai): string
{
    $a = $mulai !== null ? strtotime($mulai) : false;
    $b = $selesai !== null ? strtotime($selesai) : false;
    if ($a === false) {
        return '';
    }
    if ($b === false || $a === $b) {
        return Layout::date($a);
    }
    if (date('Y-m', $a) === date('Y-m', $b)) {
        return (int) date('j', $a) . '–' . Layout::date($b);
    }

    return Layout::date($a, false) . ' – ' . Layout::date($b);
}

/**
 * @param array<int, array{label:string, nilai:string, url?:?string, sorot?:bool}> $stat
 */
function beranda_statistik(array $stat): void
{
    if ($stat === []) {
        return;
    }
    echo '<div class="ah-stats ah-beranda__stats">';
    foreach ($stat as $item) {
        $isi = '<p class="ah-stat__label">' . ah_e($item['label']) . '</p><p class="ah-stat__value">' . ah_e($item['nilai']) . '</p>';
        $kelas = 'ah-stat' . (!empty($item['sorot']) ? ' ah-stat--warn' : '');
        echo !empty($item['url'])
            ? '<a class="' . $kelas . ' ah-stat--link" href="' . ah_e($item['url']) . '">' . $isi . '</a>'
            : '<div class="' . $kelas . '">' . $isi . '</div>';
    }
    echo '</div>';
}

/**
 * Kartu tugas: angka besar, judul, satu tombol.
 */
function beranda_kartu_tugas(int $jumlah, string $judul, string $tombol, string $url, bool $sorot = false, string $kunci = '', ?string $catatan = null): string
{
    return '<article class="ah-task' . ($sorot ? ' ah-task--warn' : '') . '"' . ($kunci !== '' ? ' data-ah-kartu="' . ah_e($kunci) . '"' : '') . '>'
        . '<p class="ah-task__num">' . number_format($jumlah, 0, ',', '.') . '</p>'
        . '<p class="ah-task__title">' . ah_e($judul) . '</p>'
        . ($catatan !== null ? '<p class="ah-task__note">' . ah_e($catatan) . '</p>' : '')
        . '<a class="btn btn-sm ' . ($sorot ? 'btn-warning' : 'btn-outline-primary') . ' ah-task__btn" href="' . ah_e($url) . '">' . ah_e($tombol) . '</a>'
        . '</article>';
}

/**
 * Daftar pengajuan izin ringkas.
 *
 * @param array<int, array<string, mixed>> $rows
 */
function beranda_daftar_izin(array $rows, bool $denganAlasan = false): string
{
    if ($rows === []) {
        return '<p class="text-muted small mb-0">Belum ada pengajuan izin dalam cakupan Anda.</p>';
    }
    $html = '<ul class="ah-mini-list">';
    foreach ($rows as $row) {
        $html .= '<li><a href="' . ah_e(app_url('/portal/izin_detail.php?id=' . (int) $row['id'])) . '">'
            . '<span class="ah-mini-list__main"><b>' . ah_e($row['nama_santri']) . '</b>'
            . '<small>#' . (int) $row['id'] . ' · ' . ah_e(beranda_rentang($row['tgl_izin'] ?? null, $row['tgl_kembali'] ?? null))
            . ($denganAlasan && !empty($row['alasan']) ? ' · ' . ah_e(mb_strimwidth((string) $row['alasan'], 0, 40, '…')) : '')
            . '</small></span>' . ah_status((string) $row['status']) . '</a></li>';
    }

    return $html . '</ul>';
}

$nama = (string) ($currentUser['name'] ?? '');
$salam = 'Assalamu\'alaikum, ' . $nama;
$keterangan = beranda_tanggal(time()) . ($semesterLabel !== null ? ' · ' . $semesterLabel : '');
$pageOptions = [
    'title' => 'Beranda',
    'heading' => $salam,
    'description' => $keterangan,
    'user' => $currentUser,
    'capabilities' => $capabilities,
    'unread' => $context['unread'],
    'v3_capabilities' => $context['v3_capabilities'],
    'active' => 'beranda',
    'breadcrumbs' => [],
];

// ---------------------------------------------------------------------------
// 3. Tanpa peran: dijelaskan tanpa memberi akses tambahan apa pun.
// ---------------------------------------------------------------------------
if ($peran === null) {
    ah_page_open($pageOptions);
    Layout::note(
        'warning',
        'Akun Anda belum memiliki peran atau hubungan data yang sah, sehingga belum ada modul yang dapat dibuka.',
        '<p class="mb-0 mt-2 small">Yang perlu disiapkan admin: peran akun, dan hubungan ke data master '
            . '(guru untuk pengajian, pengurus untuk perizinan, atau wali untuk akun orang tua). '
            . 'Selama itu belum ada, akun ini tidak memperoleh akses tambahan apa pun.</p>'
    );
    ah_page_close();
    exit;
}

// ---------------------------------------------------------------------------
// 4. Beranda per peran.
// ---------------------------------------------------------------------------
$map = Navigation::build($currentUser, $capabilities, $context['unread'], $context['v3_capabilities'], $peran);
$punya = static fn (string $key): bool => Navigation::find($map['groups'], $key) !== null;

if ($peran === Peran::ADMIN) {
    // B-1: "Perlu tindakan" daftar prioritas 4 tempat (keputusan L, P, AA).
    $prioritas = $beranda->prioritasAdmin($currentUser);
    $semua = isset($_GET['semua']);
    $tampil = $semua ? $prioritas : array_slice($prioritas, 0, BerandaService::TEMPAT_PRIORITAS);
    $lebih = count($prioritas) - count($tampil);
    $statistik = $beranda->statistikAdmin();
    $jadwal = [];
    $jadwalGagal = false;
    try {
        $jadwal = $beranda->jadwalHariIni($currentUser, true, 5);
    } catch (Throwable $exception) {
        $jadwalGagal = true;
        error_log('Beranda admin: jadwal hari ini gagal dibaca: ' . $exception->getMessage());
    }
    $izinTerbaru = null;
    try {
        $izinTerbaru = izin_service()->list($currentUser, [], 1, 5, Capabilities::ADMIN)['rows'];
    } catch (Throwable $exception) {
        error_log('Beranda admin: izin terbaru gagal dibaca: ' . $exception->getMessage());
    }

    ah_page_open($pageOptions);
    ?>
    <section class="ah-beranda__section" aria-labelledby="perlu-tindakan" id="perlu-tindakan-bagian">
        <div class="ah-section-head">
            <h2 class="h6 mb-0" id="perlu-tindakan">Perlu tindakan</h2>
            <?php if ($lebih > 0): ?>
                <a class="small" href="?semua=1#perlu-tindakan-bagian">+<?= $lebih ?> lainnya · Lihat semua</a>
            <?php elseif ($semua && count($prioritas) > BerandaService::TEMPAT_PRIORITAS): ?>
                <a class="small" href="<?= ah_e(app_url('/portal/index.php')) ?>">Tampilkan 4 teratas</a>
            <?php endif; ?>
        </div>
        <?php if ($prioritas === []): ?>
            <?= ah_empty('Tidak ada yang perlu ditindaklanjuti', 'Kartu muncul lagi saat ada pekerjaan baru.') ?>
        <?php else: ?>
            <div class="ah-task-grid">
                <?php foreach ($tampil as $kartu): ?>
                    <?= beranda_kartu_tugas($kartu['jumlah'], $kartu['judul'], $kartu['tombol'], $kartu['url'], $kartu['tertunda'], $kartu['key'], $kartu['tertunda'] ? 'Ada yang tertunda lebih dari 1 hari' : null) ?>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <?php beranda_statistik($statistik); ?>

    <div class="row g-3">
        <div class="col-lg-6">
            <section class="ah-card mb-0 h-100" aria-labelledby="ah-pengajian-hari-ini">
                <div class="ah-card__head">
                    <span id="ah-pengajian-hari-ini">Pengajian hari ini</span>
                    <a class="btn btn-sm btn-outline-primary" href="<?= ah_e(app_url('/admin/admin_pengajian.php?tab=pertemuan')) ?>">Buka</a>
                </div>
                <?php if ($jadwalGagal): ?>
                    <div class="ah-card__body"><?php Layout::note('danger', 'Jadwal hari ini belum dapat dimuat.'); ?></div>
                <?php elseif ($jadwal === []): ?>
                    <div class="ah-card__body"><?= ah_empty('Tidak ada jadwal hari ini', 'Jadwal aktif semester berjalan untuk hari ini akan tampil di sini.') ?></div>
                <?php else: ?>
                    <div class="ah-table-wrap"><table class="ah-table ah-table--cards">
                        <caption class="ah-visually-hidden">Jadwal pengajian hari ini dan status pertemuannya</caption>
                        <thead><tr><th scope="col">Jadwal</th><th scope="col">Kelas</th><th scope="col">Status</th></tr></thead>
                        <tbody>
                        <?php foreach ($jadwal as $baris): ?>
                            <tr>
                                <td><span><?= ah_e($baris['waktu_sholat'] . ' · ' . ($baris['fan_ilmu'] ?? '')) ?>
                                    <span class="ah-cell-sub"><?= ah_e(trim(($baris['nama_guru'] ?? '') . ($baris['waktu_mulai'] ? ' · ' . substr((string) $baris['waktu_mulai'], 0, 5) : ''), ' ·')) ?></span></span></td>
                                <td><?= ah_e($baris['nama_kelas']) ?></td>
                                <td><?= ah_status($baris['status_label']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table></div>
                <?php endif; ?>
            </section>
        </div>
        <div class="col-lg-6">
            <section class="ah-card mb-0 h-100" aria-labelledby="ah-izin-terbaru">
                <div class="ah-card__head">
                    <span id="ah-izin-terbaru">Pengajuan izin terbaru</span>
                    <a class="btn btn-sm btn-outline-primary" href="<?= ah_e(app_url('/portal/izin.php')) ?>">Buka</a>
                </div>
                <div class="ah-card__body">
                    <?= $izinTerbaru === null
                        ? '<p class="text-muted small mb-0">Pengajuan izin belum dapat dimuat.</p>'
                        : beranda_daftar_izin($izinTerbaru) ?>
                </div>
            </section>
        </div>
    </div>
    <?php
    ah_page_close();
    exit;
}

if ($peran === Peran::GURU) {
    // B-2: jadwal hari ini, kemajuan nilai, dan bagian murobi bila ditugaskan.
    $jadwal = null;
    if ($punya('pengajian')) {
        try {
            $jadwal = $beranda->jadwalHariIni($currentUser, false, 8);
        } catch (Throwable $exception) {
            error_log('Beranda guru: jadwal gagal dibaca: ' . $exception->getMessage());
        }
    }
    $nilai = null;
    if ($punya('nilai')) {
        try {
            $nilai = $beranda->kemajuanNilai($currentUser);
        } catch (Throwable $exception) {
            error_log('Beranda guru: kemajuan nilai gagal dibaca: ' . $exception->getMessage());
        }
    }
    $murobi = [];
    if ($punya('antrean')) {
        try {
            $murobi[] = ['jumlah' => izin_service()->queueCount($currentUser, Capabilities::MUROBI), 'judul' => 'Izin menunggu keputusan Anda', 'tombol' => 'Putuskan', 'url' => app_url('/portal/izin_antrean.php?mode=' . Capabilities::MUROBI), 'key' => 'murobi_antrean'];
        } catch (Throwable $exception) {
            error_log('Beranda murobi: antrean gagal dibaca: ' . $exception->getMessage());
        }
    }
    if ($punya('sikap_binaan')) {
        try {
            $murobi[] = ['jumlah' => $beranda->sikapPerluDiketahui($currentUser), 'judul' => 'Sikap binaan perlu diketahui', 'tombol' => 'Periksa', 'url' => app_url('/portal/sikap_semester.php?mode=murobi&status=perlu_diketahui'), 'key' => 'murobi_sikap'];
        } catch (Throwable $exception) {
            error_log('Beranda murobi: sikap gagal dibaca: ' . $exception->getMessage());
        }
    }

    ah_page_open($pageOptions);
    $adaIsi = false;
    if ($jadwal !== null):
        $adaIsi = true; ?>
        <section class="ah-beranda__section" aria-labelledby="jadwal-hari-ini">
            <div class="ah-section-head"><h2 class="h6 mb-0" id="jadwal-hari-ini">Jadwal hari ini</h2>
                <a class="small" href="<?= ah_e(app_url('/admin/admin_pengajian.php?tab=jadwal')) ?>">Semua jadwal</a></div>
            <?php if ($jadwal === []): ?>
                <?= ah_empty('Tidak ada jadwal hari ini', 'Jadwal pengajian Anda untuk hari ini akan tampil di sini.') ?>
            <?php else: ?>
                <div class="ah-schedule-grid">
                    <?php foreach ($jadwal as $baris): ?>
                        <article class="ah-schedule">
                            <p class="ah-schedule__title"><?= ah_e($baris['waktu_sholat'] . ' · ' . ($baris['fan_ilmu'] ?? '')) ?></p>
                            <p class="ah-schedule__meta"><?= ah_e(implode(' · ', array_filter([
                                $baris['nama_kelas'],
                                $baris['tempat'] ?? null,
                                $baris['waktu_mulai'] ? substr((string) $baris['waktu_mulai'], 0, 5) : null,
                            ]))) ?></p>
                            <?php if ($baris['tindakan']['url'] === null): ?>
                                <span class="btn btn-sm btn-outline-secondary disabled" aria-disabled="true"><?= ah_e($baris['tindakan']['label']) ?></span>
                            <?php else: ?>
                                <a class="btn btn-sm <?= $baris['tindakan']['utama'] ? 'btn-primary' : 'btn-outline-secondary' ?>" href="<?= ah_e($baris['tindakan']['url']) ?>">
                                    <?= ah_e($baris['tindakan']['label']) ?><?= $baris['tindakan']['label'] === 'Selesai' ? ' ✓' : '' ?></a>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <div class="row g-3">
        <?php if ($nilai !== null): $adaIsi = true; ?>
            <div class="col-lg-6">
                <section class="ah-card mb-0 h-100" aria-labelledby="ah-nilai-diisi">
                    <div class="ah-card__head"><span id="ah-nilai-diisi">Nilai semester yang harus diisi</span>
                        <a class="btn btn-sm btn-outline-primary" href="<?= ah_e(app_url('/portal/nilai_semester.php')) ?>">Buka</a></div>
                    <div class="ah-card__body">
                        <?php if ($nilai === []): ?>
                            <p class="text-muted small mb-0">Belum ada rencana nilai semester aktif untuk penugasan Anda.</p>
                        <?php else: ?>
                            <ul class="ah-progress-list">
                                <?php foreach ($nilai as $baris): ?>
                                    <?php $persen = $baris['total'] > 0 ? (int) round(100 * $baris['terisi'] / $baris['total']) : 0; ?>
                                    <li><a href="<?= ah_e($baris['url']) ?>">
                                        <span class="ah-progress-list__label"><?= ah_e($baris['label']) ?></span>
                                        <span class="ah-progress-list__bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= $persen ?>" aria-label="<?= ah_e($baris['label']) ?>"><i style="width:<?= $persen ?>%"></i></span>
                                        <span class="ah-progress-list__num"><?= (int) $baris['terisi'] ?>/<?= (int) $baris['total'] ?></span>
                                    </a></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                </section>
            </div>
        <?php endif; ?>
        <?php if ($murobi !== []): $adaIsi = true; ?>
            <div class="col-lg-6">
                <section class="ah-card mb-0 h-100" aria-labelledby="ah-sebagai-murobi">
                    <div class="ah-card__head"><span id="ah-sebagai-murobi">Sebagai murobi</span></div>
                    <div class="ah-card__body"><div class="ah-task-grid ah-task-grid--one">
                        <?php foreach ($murobi as $kartu): ?>
                            <?= beranda_kartu_tugas($kartu['jumlah'], $kartu['judul'], $kartu['tombol'], $kartu['url'], $kartu['jumlah'] > 0, $kartu['key']) ?>
                        <?php endforeach; ?>
                    </div></div>
                </section>
            </div>
        <?php endif; ?>
    </div>
    <?php if (!$adaIsi): ?>
        <?= ah_empty('Belum ada tugas untuk akun guru ini', 'Jadwal pengajian, penugasan mata pelajaran, atau penugasan murobi yang aktif akan tampil di sini. Hubungi admin bila seharusnya sudah ada.') ?>
    <?php endif; ?>
    <?php
    ah_page_close();
    exit;
}

if ($peran === Peran::PENGURUS) {
    // B-3: tombol Ajukan izin, angka binaan, pengajuan saya, santri binaan.
    $bisaAjukan = in_array(Capabilities::PENGURUS, $capabilities, true);
    $binaan = [];
    if ($bisaAjukan) {
        try {
            $binaan = izin_service()->santriInScope($currentUser, Capabilities::PENGURUS);
        } catch (Throwable $exception) {
            error_log('Beranda pengurus: santri binaan gagal dibaca: ' . $exception->getMessage());
        }
    }
    $target = array_values(array_unique(array_filter(array_column($binaan, 'target_name'))));
    $jumlahBinaan = count(array_unique(array_column($binaan, 'id')));
    if ($binaan !== []) {
        $pageOptions['description'] = 'Pembimbing ' . implode(', ', array_slice($target, 0, 3))
            . (count($target) > 3 ? ' dan ' . (count($target) - 3) . ' lainnya' : '') . ' · ' . $jumlahBinaan . ' santri';
    }
    if ($bisaAjukan) {
        $pageOptions['actions'] = '<a class="btn btn-primary ah-btn-block-sm" href="' . ah_e(app_url('/portal/izin_buat.php')) . '">'
            . '<i class="fas fa-plus" aria-hidden="true"></i> Ajukan izin</a>';
    }
    $statistik = $bisaAjukan
        ? $beranda->statistikPengurus($currentUser, $punya('sikap'), $punya('pembinaan'))
        : [];
    $izinSaya = null;
    if ($bisaAjukan) {
        try {
            $izinSaya = izin_service()->list($currentUser, [], 1, 3, Capabilities::PENGURUS)['rows'];
        } catch (Throwable $exception) {
            error_log('Beranda pengurus: izin gagal dibaca: ' . $exception->getMessage());
        }
    }

    ah_page_open($pageOptions);
    if (!$bisaAjukan) {
        Layout::note('info', 'Akun pengurus ini belum terhubung ke data pengurus yang aktif, sehingga perizinan belum dapat dibuka. Hubungi admin pesantren.');
    }
    beranda_statistik($statistik);
    if ($bisaAjukan): ?>
        <div class="row g-3">
            <div class="col-lg-6">
                <section class="ah-card mb-0 h-100" aria-labelledby="ah-izin-saya">
                    <div class="ah-card__head"><span id="ah-izin-saya">Pengajuan izin saya</span>
                        <a class="btn btn-sm btn-outline-primary" href="<?= ah_e(app_url('/portal/izin.php')) ?>">Semua</a></div>
                    <div class="ah-card__body">
                        <?= $izinSaya === null ? '<p class="text-muted small mb-0">Pengajuan izin belum dapat dimuat.</p>' : beranda_daftar_izin($izinSaya, true) ?>
                    </div>
                </section>
            </div>
            <div class="col-lg-6">
                <section class="ah-card mb-0 h-100" aria-labelledby="ah-santri-binaan">
                    <div class="ah-card__head"><span id="ah-santri-binaan">Santri binaan</span>
                        <a class="btn btn-sm btn-outline-primary" href="<?= ah_e(app_url('/portal/izin_ringkasan.php')) ?>">Lihat semua</a></div>
                    <div class="ah-card__body">
                        <?php if ($binaan === []): ?>
                            <p class="text-muted small mb-0">Belum ada santri binaan pada penugasan pembimbing aktif Anda.</p>
                        <?php else: ?>
                            <ul class="ah-mini-list">
                                <?php $tampilBinaan = []; foreach ($binaan as $s) { $tampilBinaan[(int) $s['id']] ??= $s; } ?>
                                <?php foreach (array_slice($tampilBinaan, 0, 6) as $s): ?>
                                    <li><span class="ah-mini-list__row">
                                        <span class="ah-avatar ah-avatar--soft" aria-hidden="true"><?= ah_e(mb_strtoupper(mb_substr((string) $s['nama_santri'], 0, 1))) ?></span>
                                        <span class="ah-mini-list__main"><b><?= ah_e($s['nama_santri']) ?></b><small><?= ah_e(trim(($s['nis'] ?? '') . ' · ' . ($s['target_name'] ?? ''), ' ·')) ?></small></span>
                                    </span></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                </section>
            </div>
        </div>
    <?php endif;
    ah_page_close();
    exit;
}

if ($peran === Peran::PENDIDIKAN) {
    // B-4: kemajuan semester per kelas, dari nilai masuk sampai rapor terbit.
    $kelas = null;
    try {
        $kelas = $beranda->kemajuanKelas($currentUser);
    } catch (Throwable $exception) {
        error_log('Beranda Bagian Pendidikan: kemajuan kelas gagal dibaca: ' . $exception->getMessage());
    }
    $pageOptions['heading'] = 'Kemajuan ' . ($semesterLabel !== null ? mb_strtolower(mb_substr($semesterLabel, 0, 1)) . mb_substr($semesterLabel, 1) : 'semester');
    $pageOptions['description'] = $salam . ' · ' . beranda_tanggal(time());
    if ($punya('rapor')) {
        $pageOptions['actions'] = '<a class="btn btn-primary" href="' . ah_e(app_url('/portal/verifikasi_semester.php')) . '">Buka kelengkapan</a>';
    }
    ah_page_open($pageOptions);
    if ($kelas === null) {
        Layout::note('danger', 'Kemajuan semester belum dapat dimuat. Muat ulang halaman untuk mencoba lagi.');
    } elseif ($kelas === []) {
        echo ah_empty('Belum ada rencana kelas semester aktif', 'Rencana nilai kelas dalam cakupan Anda akan tampil di sini setelah disusun.',
            $punya('rapor') ? '<a class="btn btn-sm btn-primary" href="' . ah_e(app_url('/portal/rencana_nilai.php')) . '">Buka rencana nilai</a>' : null);
    } else {
        $harapan = array_sum(array_column($kelas, 'harapan'));
        $terverifikasi = array_sum(array_column($kelas, 'terverifikasi'));
        $perbaikan = array_sum(array_column($kelas, 'perbaikan'));
        $kelasTerbit = count(array_filter($kelas, static fn (array $k): bool => $k['santri'] > 0 && $k['terbit'] >= $k['santri']));
        beranda_statistik([
            ['label' => 'Kelas', 'nilai' => (string) count($kelas)],
            ['label' => 'Bahan terverifikasi', 'nilai' => $harapan > 0 ? round(100 * $terverifikasi / $harapan) . '%' : '—'],
            ['label' => 'Perlu perbaikan', 'nilai' => (string) $perbaikan, 'sorot' => $perbaikan > 0, 'url' => app_url('/portal/verifikasi_semester.php?status=Dikembalikan')],
            ['label' => 'Rapor terbit', 'nilai' => $kelasTerbit . ' kelas'],
        ]);
        ?>
        <section class="ah-card" aria-labelledby="ah-per-kelas">
            <div class="ah-card__head"><span id="ah-per-kelas">Per kelas</span></div>
            <div class="ah-table-wrap"><table class="ah-table ah-table--cards">
                <caption class="ah-visually-hidden">Kemajuan bahan rapor per kelas semester aktif</caption>
                <thead><tr><th scope="col">Kelas</th><th scope="col">Nilai masuk</th><th scope="col">Sikap</th><th scope="col">Kehadiran</th><th scope="col" class="ah-col-secondary">Terverifikasi</th><th scope="col">Rapor</th><th scope="col"><span class="ah-visually-hidden">Tindakan</span></th></tr></thead>
                <tbody>
                <?php foreach ($kelas as $baris): ?>
                    <tr>
                        <td><span><b><?= ah_e($baris['kelas']) ?></b><span class="ah-cell-sub"><?= (int) $baris['santri'] ?> santri</span></span></td>
                        <td><?= $baris['nilai_persen'] === null ? '—' : (int) $baris['nilai_persen'] . '%' ?></td>
                        <td><?= $baris['sikap_persen'] === null ? '—' : (int) $baris['sikap_persen'] . '%' ?></td>
                        <td><?= $baris['kehadiran_belum'] > 0 ? ah_status('Belum lengkap') . ' <span class="ah-cell-sub">' . (int) $baris['kehadiran_belum'] . ' belum dicatat</span>' : ah_status('Lengkap') ?></td>
                        <td class="ah-col-secondary"><?= $baris['terverifikasi_persen'] === null ? '—' : (int) $baris['terverifikasi_persen'] . '%' ?><?= $baris['perbaikan'] > 0 ? ' <a class="ah-cell-sub" href="' . ah_e($baris['url_perbaikan']) . '">' . (int) $baris['perbaikan'] . ' perlu perbaikan</a>' : '' ?></td>
                        <td><?= $baris['santri'] > 0 && $baris['terbit'] >= $baris['santri'] ? ah_status('Terbit') : ($baris['terbit'] > 0 ? ah_status('Terbit', 'Terbit ' . (int) $baris['terbit'] . '/' . (int) $baris['santri']) : ah_status('Belum terbit')) ?></td>
                        <td><a class="btn btn-sm btn-outline-primary" href="<?= ah_e($baris['url']) ?>">Buka<span class="ah-visually-hidden"> kelengkapan <?= ah_e($baris['kelas']) ?></span></a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        </section>
        <?php
    }
    ah_page_close();
    exit;
}

// B-5: orang tua — semua baca-saja; tidak ada tombol yang mengubah data.
$anak = [];
try {
    $anak = in_array(Capabilities::ORANG_TUA, $capabilities, true)
        ? izin_service()->santriInScope($currentUser, Capabilities::ORANG_TUA)
        : [];
} catch (Throwable $exception) {
    error_log('Beranda orang tua: daftar anak gagal dibaca: ' . $exception->getMessage());
}
$anakIds = array_map('intval', array_column($anak, 'id'));
$dipilih = (int) ($_GET['anak'] ?? 0);
// Hanya anak yang terhubung dengan akun ini yang dapat dipilih.
if (!in_array($dipilih, $anakIds, true)) {
    $dipilih = $anakIds[0] ?? 0;
}
$pageOptions['heading'] = $salam;
ah_page_open($pageOptions);
if ($anak === []) {
    echo ah_empty('Belum ada santri yang terhubung', 'Akun ini belum terhubung dengan data santri. Hubungi admin pesantren untuk menghubungkan akun wali.');
    ah_page_close();
    exit;
}
$ringkas = $beranda->ringkasanAnak($currentUser, $dipilih);
$profil = null;
foreach ($anak as $s) {
    if ((int) $s['id'] === $dipilih) {
        $profil = $s;
    }
}
?>
<?php if (count($anak) > 1): ?>
    <ul class="ah-tabs ah-chips" aria-label="Pilih anak">
        <?php foreach ($anak as $s): ?>
            <li><a href="?anak=<?= (int) $s['id'] ?>"<?= (int) $s['id'] === $dipilih ? ' aria-current="page"' : '' ?>><?= ah_e($s['nama_santri']) ?></a></li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>
<section class="ah-card ah-child" aria-labelledby="ah-anak-nama">
    <div class="ah-card__head"><span id="ah-anak-nama"><?= ah_e($profil['nama_santri'] ?? '') ?></span></div>
    <div class="ah-card__body">
        <dl class="ah-kv">
            <div><dt>NIS</dt><dd><?= ah_e($profil['nis'] ?? '—') ?></dd></div>
            <div><dt>Kelas</dt><dd><?= ah_e($ringkas['kelas'] ?? 'Belum ditempatkan') ?></dd></div>
            <div><dt>Kamar</dt><dd><?= ah_e($ringkas['kamar'] ?? 'Belum ditempatkan') ?></dd></div>
            <?php if (!empty($profil['hubungan'])): ?><div><dt>Hubungan</dt><dd><?= ah_e($profil['hubungan']) ?></dd></div><?php endif; ?>
        </dl>
    </div>
</section>
<div class="ah-parent-grid">
    <?php if ($punya('izin_anak')): ?>
        <article class="ah-parent-card">
            <p class="ah-parent-card__title">Izin terakhir</p>
            <?php if ($ringkas['izin'] === null): ?>
                <p class="ah-parent-card__text">Belum ada pengajuan izin.</p>
                <a class="btn btn-outline-primary" href="<?= ah_e(app_url('/portal/izin.php')) ?>">Lihat</a>
            <?php else: $izin = $ringkas['izin']; ?>
                <p class="ah-parent-card__text"><?= ah_e(beranda_rentang($izin['tgl_izin'] ?? null, $izin['tgl_kembali'] ?? null)) ?> <?= ah_status((string) $izin['status']) ?></p>
                <a class="btn btn-outline-primary" href="<?= ah_e(app_url('/portal/izin_detail.php?id=' . (int) $izin['id'])) ?>">Lihat</a>
            <?php endif; ?>
        </article>
    <?php endif; ?>
    <?php if ($punya('rapor_wali')): ?>
        <?php $rapor = $ringkas['rapor']; ?>
        <article class="ah-parent-card<?= $rapor !== null ? ' ah-parent-card--hl' : '' ?>">
            <p class="ah-parent-card__title"><?= $rapor !== null ? 'Rapor terbit' : 'Rapor' ?></p>
            <?php if ($rapor === null): ?>
                <p class="ah-parent-card__text">Belum ada rapor yang terbit.</p>
                <a class="btn btn-outline-primary" href="<?= ah_e(app_url('/portal/rapor_wali.php')) ?>">Lihat</a>
            <?php else: ?>
                <p class="ah-parent-card__text"><?= ah_e($rapor['semester'] . ' ' . $rapor['tahun']) ?></p>
                <a class="btn btn-primary" href="<?= ah_e(app_url('/portal/rapor_wali.php?id=' . (int) $rapor['id'])) ?>">Buka rapor</a>
            <?php endif; ?>
        </article>
    <?php endif; ?>
    <?php if ($punya('info')): ?>
        <?php $baru = $ringkas['info_belum_dibaca']; ?>
        <article class="ah-parent-card">
            <p class="ah-parent-card__title">Informasi pembinaan</p>
            <p class="ah-parent-card__text"><?= $baru === null ? 'Belum dapat dimuat.' : ($baru > 0 ? $baru . ' belum dibaca' : 'Tidak ada informasi baru') ?></p>
            <a class="btn btn-outline-primary" href="<?= ah_e(app_url('/portal/v3_publikasi.php')) ?>">Baca</a>
        </article>
    <?php endif; ?>
</div>
<?php
ah_page_close();
