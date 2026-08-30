<?php

declare(strict_types=1);

use App\Auth\Capabilities;
use App\Http\Csrf;
use App\Schedule\ScheduleException;
use App\Ui\Denial;

/**
 * Modul Pengajian terpadu (koreksi ke-4, keputusan pengguna 30 Agustus 2026).
 *
 * Sebelumnya "Jadwal Pengajian" dan "Pertemuan Pengajian" adalah dua menu
 * terpisah dengan kerangka halaman berbeda, sehingga pengguna kehilangan
 * konteks saat berpindah. Sekarang keduanya menjadi SATU menu dengan dua tab:
 *
 *   - `?tab=jadwal`    : pola mingguan;
 *   - `?tab=pertemuan` : pelaksanaan pada tanggal tertentu.
 *
 * Yang TIDAK berubah:
 *   - penyimpanan jadwal dan pertemuan tetap terpisah (tabel dan layanan yang
 *     sama seperti Fase 3); modul ini hanya menyatukan navigasinya;
 *   - keunikan jadwal–tanggal, status pertemuan, snapshot peserta, dan audit
 *     tetap ditegakkan `App\Schedule\ScheduleService`;
 *   - alamat lama `admin/admin_jadwal_ngaji.php` dan
 *     `admin/pertemuan_pengajian.php` tetap berfungsi.
 *
 * Kewenangan (ditegakkan di server, bukan dengan menyembunyikan tombol):
 *   - guru : hanya melihat jadwal miliknya dan mengelola pertemuannya sendiri.
 *            Seluruh aksi POST tab Jadwal ditolak untuk non-admin, dan filter
 *            guru dipaksa ke `guru_id` miliknya sehingga jadwal guru lain tidak
 *            pernah ikut terbaca — termasuk bila parameter URL diubah manual.
 *   - admin: mengelola jadwal dan membuka pertemuan mana pun.
 *   `ScheduleService` tetap memeriksa kepemilikan pertemuan secara terpisah.
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

// Menandai bahwa potongan tampilan di bawah dimuat dari halaman ber-guard.
define('AH_PARTIAL', true);

$currentUser = authorization()->requireWebUser();
if (!in_array('admin', $currentUser['roles'], true) && !in_array('guru', $currentUser['roles'], true)) {
    Denial::render(
        'Akun ini tidak memiliki hak jadwal atau pertemuan pengajian.',
        'Modul Pengajian terbuka bagi admin dan guru. Beranda serta menu lain yang menjadi hak Anda tetap dapat dibuka.'
    );
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid($_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
}

$bolehKelolaJadwal = in_array('admin', $currentUser['roles'], true);
$guruId = isset($currentUser['guru_id']) ? (int) $currentUser['guru_id'] : 0;

// Tautan ke antrean perizinan hanya untuk akun yang benar-benar memiliki
// capability murobi (guru + penugasan murobi aktif). Menyembunyikan tautan ini
// BUKAN kontrol akses: `/portal/*` tetap dijaga PortalGuard di sisi server.
$bolehAntreanIzin = capabilities()->has($currentUser, Capabilities::MUROBI);

$service = schedule_service();

$tab = ($_REQUEST['tab'] ?? 'jadwal') === 'pertemuan' ? 'pertemuan' : 'jadwal';

/**
 * Kembali ke tab yang sama dengan konteks/filter yang sedang dipakai, sehingga
 * pengguna tidak kehilangan tempatnya setelah menyimpan.
 */
$kembali = static function (array $replace = []) use ($tab): never {
    $query = ah_query(array_merge(['tab' => $tab], $replace), $_GET);
    header('Location: ' . app_url('/admin/admin_pengajian.php') . ($query === '' ? '' : '?' . $query));
    exit;
};

// ---------------------------------------------------------------------------
// Aksi
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $tabPost = ($_POST['tab'] ?? $tab) === 'pertemuan' ? 'pertemuan' : 'jadwal';

    if ($tabPost === 'jadwal') {
        // Pengelolaan jadwal adalah kewenangan admin. Guru yang mengirim POST
        // ke sini — termasuk lewat formulir yang dipalsukan — ditolak server.
        if (!$bolehKelolaJadwal) {
            Denial::render(
                'Hanya admin yang dapat mengelola jadwal pengajian.',
                'Akun guru dapat melihat jadwalnya dan mengelola pertemuannya sendiri pada tab Pertemuan.'
            );
        }
        try {
            $id = (int) ($_POST['id'] ?? 0);
            if ($action === 'save') {
                $result = $service->save($_POST, (int) $currentUser['id'], $id > 0 ? $id : null);
                $pesan = ($id > 0 ? 'Perubahan jadwal' : 'Jadwal baru') . ' berhasil disimpan (ID ' . $result['id'] . ').';
                ah_flash_set($result['warnings'] === [] ? 'success' : 'warning', $pesan . ($result['warnings'] === [] ? '' : ' ' . implode(' ', $result['warnings'])));
                ah_old_clear();
            } else {
                $result = $service->setState($id, $action, (int) $currentUser['id']);
                ah_flash_set(
                    $result['warnings'] === [] ? 'success' : 'warning',
                    'Status jadwal diperbarui tanpa menghapus riwayat pertemuan atau absensi.'
                        . ($result['warnings'] === [] ? '' : ' ' . implode(' ', $result['warnings']))
                );
            }
        } catch (ScheduleException $exception) {
            // Isian pengguna dipertahankan agar tidak perlu mengetik ulang.
            ah_old_keep($_POST, ['id_tahun', 'hari', 'waktu_sholat', 'waktu_mulai', 'waktu_selesai', 'id_kelas', 'id_guru', 'tempat', 'fan_ilmu', 'nama_kitab']);
            ah_flash_set('danger', $exception->getMessage());
            $kembali(['action' => $id > 0 ? 'edit' : 'create', 'id' => $id > 0 ? $id : null]);
        }
        $kembali(['action' => null, 'id' => null]);
    }

    try {
        if ($action === 'draft' || $action === 'open') {
            $meetingId = $action === 'draft'
                ? $service->createDraft((int) ($_POST['schedule_id'] ?? 0), (string) ($_POST['tanggal_pertemuan'] ?? ''), (string) ($_POST['catatan'] ?? ''), $currentUser)
                : $service->open((int) ($_POST['schedule_id'] ?? 0), (string) ($_POST['tanggal_pertemuan'] ?? ''), (string) ($_POST['catatan'] ?? ''), $currentUser);
            ah_flash_set('success', $action === 'draft'
                ? 'Draf pertemuan berhasil dibuat.'
                : 'Pertemuan dibuka dan daftar peserta dibekukan sebagai snapshot.');
            $kembali(['id' => $meetingId, 'schedule_id' => null, 'action' => null]);
        }
        if ($action === 'complete') {
            $meetingId = (int) ($_POST['meeting_id'] ?? 0);
            $service->complete($meetingId, $currentUser);
            ah_flash_set('success', 'Pertemuan diselesaikan dan waktu selesai tercatat.');
            $kembali(['id' => $meetingId, 'schedule_id' => null, 'action' => null]);
        }
        throw new ScheduleException('Aksi pertemuan tidak valid.');
    } catch (ScheduleException $exception) {
        ah_flash_set('danger', $exception->getMessage());
        $kembali([
            'id' => !empty($_POST['meeting_id']) ? (int) $_POST['meeting_id'] : null,
            'schedule_id' => !empty($_POST['schedule_id']) ? (int) $_POST['schedule_id'] : null,
            'action' => null,
        ]);
    }
}

// ---------------------------------------------------------------------------
// Data tampilan
// ---------------------------------------------------------------------------
$years = $service->years();
$teachers = $service->teachers();
$classes = $service->classes();
$activeYear = null;
foreach ($years as $year) {
    if ($year['status'] === 'Aktif') {
        $activeYear = $year;
        break;
    }
}

$filters = [
    'q' => (string) ($_GET['q'] ?? ''),
    'year_id' => $_GET['year_id'] ?? ($activeYear['id'] ?? ''),
    // Guru tidak dapat melihat jadwal guru lain: nilai filter dipaksa di server.
    'teacher_id' => $bolehKelolaJadwal ? ($_GET['teacher_id'] ?? '') : $guruId,
    'class_id' => $_GET['class_id'] ?? '',
    'day' => (string) ($_GET['day'] ?? ''),
    'state' => (string) ($_GET['state'] ?? 'active'),
    'page' => max(1, (int) ($_GET['page'] ?? 1)),
];

$result = ['rows' => [], 'total' => 0];
$selected = null;
$mode = (string) ($_GET['action'] ?? '');
$scheduleOptions = [];
$meetings = [];
$selectedMeeting = null;
$selectedScheduleId = (int) ($_GET['schedule_id'] ?? 0);
$galat = null;

if ($tab === 'jadwal') {
    if (!$bolehKelolaJadwal && $guruId < 1) {
        // Akun guru tanpa relasi data guru tidak boleh melihat jadwal siapa pun.
        $result = ['rows' => [], 'total' => 0];
        $galat = 'Akun Anda ber-role guru tetapi belum terhubung ke data guru mana pun, sehingga belum ada jadwal yang dapat ditampilkan. Hubungi admin.';
    } else {
        $result = $service->list($filters, $filters['page']);
        if (isset($_GET['id'])) {
            $kandidat = $service->find((int) $_GET['id']);
            // Guru hanya boleh membuka detail jadwal miliknya sendiri.
            $selected = $kandidat !== null && ($bolehKelolaJadwal || (int) $kandidat['id_guru'] === $guruId) ? $kandidat : null;
            if ($kandidat !== null && $selected === null) {
                Denial::render(
                    'Jadwal ini bukan jadwal Anda.',
                    'Guru hanya dapat membuka jadwal dan pertemuan miliknya sendiri.'
                );
            }
        }
    }
    if (!$bolehKelolaJadwal) {
        $mode = $mode === 'detail' ? 'detail' : '';
    }
} else {
    $scheduleOptions = $service->activeScheduleOptions($currentUser);
    $meetings = $service->meetings($currentUser);
    try {
        $selectedMeeting = isset($_GET['id']) ? $service->meeting((int) $_GET['id'], $currentUser) : null;
    } catch (ScheduleException $exception) {
        Denial::render('Pertemuan ini bukan pertemuan Anda.', $exception->getMessage());
    }
}

// ---------------------------------------------------------------------------
// Tampilan
// ---------------------------------------------------------------------------
$konteks = ah_query(['tab' => null, 'action' => null, 'id' => null, 'page' => null]);
$tabs = [
    [
        'label' => 'Jadwal',
        'url' => app_url('/admin/admin_pengajian.php') . '?tab=jadwal' . ($konteks === '' ? '' : '&' . $konteks),
        'active' => $tab === 'jadwal',
    ],
    [
        'label' => 'Pertemuan',
        'url' => app_url('/admin/admin_pengajian.php') . '?tab=pertemuan'
            . ($selectedScheduleId > 0 ? '&schedule_id=' . $selectedScheduleId : ''),
        'active' => $tab === 'pertemuan',
    ],
];

$aksi = '';
if ($tab === 'jadwal' && $bolehKelolaJadwal) {
    $aksi = '<a class="btn btn-primary" href="' . ah_e(app_url('/admin/admin_pengajian.php?tab=jadwal&action=create')) . '">Tambah jadwal</a>';
}
if ($bolehAntreanIzin) {
    $aksi .= '<a class="btn btn-outline-primary" href="'
        . ah_e(app_url('/portal/izin_antrean.php?mode=' . Capabilities::MUROBI)) . '">Antrean perizinan</a>';
}

ah_page_open([
    'title' => 'Pengajian',
    'heading' => 'Pengajian',
    'description' => $bolehKelolaJadwal
        ? 'Jadwal adalah pola mingguan; pertemuan adalah pelaksanaannya pada tanggal tertentu. Keduanya tersimpan terpisah dan tetap saling tertaut.'
        : 'Jadwal mengajar Anda dan pertemuan yang Anda buka. Pengelolaan jadwal adalah kewenangan admin.',
    'user' => $currentUser,
    'active' => 'pengajian',
    'tabs' => $tabs,
    'actions' => $aksi,
    'breadcrumbs' => [
        ['label' => 'Beranda', 'url' => app_url('/portal/index.php')],
        ['label' => 'Pengajian'],
        ['label' => $tab === 'jadwal' ? 'Jadwal' : 'Pertemuan'],
    ],
]);

if ($galat !== null) {
    ah_note('warning', $galat);
}

if ($tab === 'jadwal') {
    require __DIR__ . '/_pengajian_jadwal.php';
} else {
    require __DIR__ . '/_pengajian_pertemuan.php';
}

ah_page_close();
