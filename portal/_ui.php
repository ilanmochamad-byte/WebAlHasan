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
