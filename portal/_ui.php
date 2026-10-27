<?php

declare(strict_types=1);

use App\Auth\Capabilities;
use App\Http\Csrf;
use App\Ui\Layout;

require_once __DIR__ . '/_guard.php';

/**
 * Adaptor tampilan halaman portal perizinan.
 *
 * Sejak paket perapihan V1–V2 (30 Agustus 2026) berkas ini tidak lagi
 * menggambar navbar sendiri. Kerangka halaman berasal dari `App\Ui\Layout`
 * yang sama dengan halaman admin, sehingga seluruh sistem terasa sebagai satu
 * aplikasi dan label "Portal Perizinan" tidak lagi menjadi identitas seluruh
 * sistem — perizinan hanyalah salah satu modul di dalam Sistem Al Hasan.
 *
 * Guard kemampuan perizinan tetap berada di `portal/_guard.php` dan tetap
 * dimuat di sini: halaman yang memakai berkas ini memang halaman perizinan.
 */

function portal_e(mixed $value): string
{
    return ah_e($value);
}

function portal_csrf(): string
{
    return Csrf::input();
}

/**
 * Kunci idempotensi untuk satu formulir mutasi.
 *
 * Dibuat saat formulir dirender lalu dikirim sebagai field tersembunyi, sehingga
 * klik ganda, refresh POST, atau retry jaringan memakai kunci yang SAMA dan hanya
 * menghasilkan satu pengajuan/keputusan (PRD 5.6).
 */
function portal_idempotency_key(): string
{
    return bin2hex(random_bytes(16));
}

/**
 * @param 'sukses'|'gagal'|'info' $jenis
 */
function portal_flash_set(string $jenis, string $pesan): void
{
    $_SESSION['portal_flash'] = ['jenis' => $jenis, 'pesan' => $pesan];
}

function portal_flash_render(): void
{
    $flash = $_SESSION['portal_flash'] ?? null;
    unset($_SESSION['portal_flash']);
    if (!is_array($flash)) {
        return;
    }
    Layout::note(match ((string) $flash['jenis']) {
        'sukses' => 'success',
        'gagal' => 'danger',
        default => 'info',
    }, (string) $flash['pesan']);
}

/**
 * Konteks request untuk riwayat status (tanpa credential).
 *
 * @return array{ip:?string, user_agent:?string}
 */
function portal_request_meta(): array
{
    return [
        'ip' => isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : null,
        'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? (string) $_SERVER['HTTP_USER_AGENT'] : null,
    ];
}

function portal_capability_label(string $capability): string
{
    return Layout::capabilityLabel($capability);
}

/**
 * Jumlah notifikasi belum dibaca milik pengguna yang sedang masuk (Fase 4).
 *
 * @param array<string, mixed> $user
 */
function portal_unread_count(array $user): int
{
    return (int) (ui_context($user)['unread'] ?? 0);
}

/**
 * @param array<string, mixed> $replace
 */
function portal_query(array $replace = []): string
{
    return ah_query($replace);
}

/**
 * Kerangka halaman portal.
 *
 * Tanda tangan lama dipertahankan agar seluruh halaman portal Fase 1–5 tetap
 * berjalan tanpa ditulis ulang.
 *
 * @param array<int, string> $capabilities
 * @param array<string, mixed> $user
 * @param array<string, mixed> $options Opsi kerangka tambahan.
 */
function portal_header(string $title, array $capabilities, string $activeMode, array $user, array $options = []): void
{
    $options['title'] = $title;
    $options['user'] = $user;
    $options['capabilities'] = $capabilities;
    $options['breadcrumbs'] ??= [
        ['label' => 'Beranda', 'url' => app_url('/portal/index.php')],
        ['label' => 'Perizinan', 'url' => app_url('/portal/izin_ringkasan.php')],
        ['label' => $title],
    ];
    $options['heading'] ??= $title;
    ah_page_open($options);
}

function portal_footer(): void
{
    ah_page_close();
}

/**
 * Keterangan cakupan untuk akun dengan lebih dari satu kemampuan.
 *
 * Deretan tombol mode per halaman dihapus di redesain Tahap 2 (A-2): cakupan
 * kini mengikuti peran aktif yang dipilih dari pil "Peran" di topbar atau di
 * laci menu. Tanda tangan fungsi dipertahankan agar halaman lama tetap jalan.
 *
 * @param array<int, string> $capabilities
 */
function portal_mode_switcher(array $capabilities, string $activeMode, string $page): void
{
    if (count($capabilities) < 2) {
        return;
    }
    echo '<p class="small text-muted ah-no-print" data-ah-cakupan>Cakupan data: <b>'
        . portal_e(izin_service()->label($activeMode))
        . '</b>. Ganti peran dari menu Peran untuk melihat cakupan lain.</p>';
}

function portal_pagination(int $total, int $page, int $perPage): void
{
    ah_pagination($total, $page, $perPage);
}

function portal_status_badge(string $status): string
{
    // Kamus warna bersama (redesain Tahap 1); hasil untuk kelima status izin
    // sama dengan pemetaan lama: Disetujui hijau, Diajukan biru, Perlu Penetapan
    // Admin kuning, Ditolak merah, Dibatalkan abu.
    return ah_status($status);
}

/**
 * Tautan modul pengajian bagi akun ber-role guru.
 *
 * Dipisahkan sebagai fungsi agar hanya ada satu tempat yang menentukan alamat
 * jalan kembali guru/murobi: modul Pengajian terpadu
 * (`/admin/admin_pengajian.php`) yang menggantikan dua menu terpisah
 * "Jadwal Pengajian" dan "Pertemuan Pengajian".
 *
 * @param array<string, mixed> $user
 */
function portal_pengajian_url(array $user): ?string
{
    if (!in_array('guru', $user['roles'] ?? [], true) && !in_array('admin', $user['roles'] ?? [], true)) {
        return null;
    }

    return app_url('/admin/admin_pengajian.php');
}

/**
 * Menjaga kompatibilitas alamat lama `admin/pertemuan_pengajian.php`.
 */
function portal_pertemuan_url(): string
{
    return app_url('/admin/pertemuan_pengajian.php');
}

/**
 * Kemampuan perizinan yang dimiliki akun, untuk dipakai halaman portal.
 *
 * @param array<int, string> $capabilities
 */
function portal_has_capability(array $capabilities, string $capability): bool
{
    return in_array($capability, $capabilities, true) && in_array($capability, Capabilities::ALL, true);
}

// ---------------------------------------------------------------------------
// Redesain website Tahap 4 (I-1 s.d. I-7)
// ---------------------------------------------------------------------------

/**
 * Asal data untuk tampilan: "Sistem baru" atau "Arsip sistem lama", tanpa
 * label versi (I-2). Nilai di basis data dan kontrak API tidak berubah.
 *
 * @param array<string, mixed> $row
 */
function portal_sumber_label(array $row): string
{
    return !empty($row['is_legacy']) ? 'Arsip sistem lama' : 'Sistem baru';
}

/** Label pelaku lama "Data warisan" ditulis "Sistem lama" di layar baru. */
function portal_label(?string $text): string
{
    return $text === 'Data warisan' ? 'Sistem lama' : (string) $text;
}

/**
 * Tombol "Ajukan izin" untuk cakupan pengurus/admin (I-1, I-2, Beranda).
 */
function portal_ajukan_button(string $mode, string $class = 'btn btn-primary'): string
{
    if (!in_array($mode, [Capabilities::PENGURUS, Capabilities::ADMIN], true)) {
        return '';
    }

    return '<a class="' . portal_e($class) . '" href="' . portal_e(app_url('/portal/izin_buat.php') . '?mode=' . rawurlencode($mode)) . '">'
        . '<i class="fas fa-plus" aria-hidden="true"></i> Ajukan izin</a>';
}

/** Alamat detail izin dengan cakupan yang sama. */
function portal_detail_url(int $id, string $mode, array $extra = []): string
{
    return app_url('/portal/izin_detail.php') . '?' . http_build_query(array_merge(['id' => $id, 'mode' => $mode], $extra));
}

/**
 * Pengajuan yang menunggu lebih dari 1 hari (keputusan P).
 *
 * @param array<string, mixed> $row
 */
function portal_izin_tertunda(array $row): bool
{
    if (!in_array((string) ($row['status'] ?? ''), ['Diajukan', 'Perlu Penetapan Admin'], true)) {
        return false;
    }
    $waktu = strtotime((string) ($row['diajukan_pada'] ?? $row['created_at'] ?? ''));

    return $waktu !== false && $waktu < time() - 86400;
}

/** Lama menunggu yang manusiawi: "3 jam", "2 hari". */
function portal_lama(?string $sejak): string
{
    $waktu = $sejak === null ? false : strtotime($sejak);
    if ($waktu === false) {
        return '—';
    }
    $detik = max(0, time() - $waktu);
    if ($detik < 3600) {
        return max(1, (int) floor($detik / 60)) . ' menit';
    }
    if ($detik < 86400) {
        return (int) floor($detik / 3600) . ' jam';
    }

    return (int) floor($detik / 86400) . ' hari';
}

/**
 * Status pengajuan untuk tampilan. "Perlu Penetapan Admin" ditulis singkat
 * "Perlu penetapan"; warna dan nilai yang disimpan tidak berubah.
 */
function portal_status(string $status): string
{
    return ah_status($status, $status === 'Perlu Penetapan Admin' ? 'Perlu penetapan' : null);
}

/**
 * Nama peristiwa riwayat untuk manusia; kode yang disimpan tidak berubah.
 */
function portal_peristiwa_label(string $kode): string
{
    return match ($kode) {
        'pengajuan_dibuat' => 'Mengajukan izin',
        'routing_otomatis' => 'Diteruskan ke murobi',
        'routing_perlu_admin' => 'Menunggu penetapan murobi oleh admin',
        'murobi_ditetapkan' => 'Admin menetapkan murobi',
        'murobi_ditetapkan_ulang' => 'Admin mengganti murobi',
        'keputusan' => 'Memutuskan izin',
        'pembatalan' => 'Membatalkan pengajuan',
        'keputusan_dikoreksi' => 'Mengoreksi keputusan',
        // PRD V7 Fase 1: pengajuan wali dan routing pembimbing.
        'pengajuan_wali_dibuat' => 'Wali mengajukan izin',
        'routing_pembimbing_otomatis' => 'Diarahkan ke pembimbing',
        'routing_pembimbing_perlu_admin' => 'Menunggu penetapan pembimbing oleh admin',
        'pembimbing_ditetapkan' => 'Admin menetapkan pembimbing',
        'pembimbing_ditetapkan_ulang' => 'Admin mengganti pembimbing',
        // PRD V7 Fase 2: tinjauan pembimbing, perbaikan, dan pembatalan wali.
        'tinjauan_minta_perbaikan' => 'Pembimbing meminta perbaikan',
        'tinjauan_ditolak_pembimbing' => 'Ditolak pembimbing',
        'tinjauan_diteruskan' => 'Pembimbing meneruskan ke murobi',
        'perbaikan_wali_dikirim' => 'Wali mengirim perbaikan',
        'pembatalan_wali' => 'Wali membatalkan pengajuan',
        default => ucfirst(str_replace('_', ' ', $kode)),
    };
}
