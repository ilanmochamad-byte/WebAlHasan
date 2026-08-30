<?php

declare(strict_types=1);

namespace App\Ui;

use App\Auth\Capabilities;

/**
 * Peta navigasi Sistem Al Hasan (paket perapihan V1–V2).
 *
 * Kelas ini SENGAJA tidak memuat guard apa pun. Ia hanya menjawab pertanyaan
 * "menu apa yang pantas ditampilkan kepada akun ini", sehingga komponen
 * navigasi dapat dipakai lintas peran (admin, guru, murobi, pengurus, orang
 * tua) tanpa menyeret `admin/_guard.php` yang khusus admin.
 *
 * PENTING: menyembunyikan menu BUKAN kontrol akses. Setiap halaman tujuan tetap
 * memeriksa hak dan cakupannya sendiri di server (`Authorization`,
 * `PortalGuard`, dan pemeriksaan cakupan tiap layanan). Peta ini hanya
 * mengurangi kebingungan pengguna, bukan menggantikan otorisasi.
 *
 * Struktur keluaran:
 *   [ ['label' => 'MODUL', 'items' => [ ['key','label','url','icon','badge'] ] ] ]
 */
final class Navigation
{
    /**
     * Kunci menu aktif ditebak dari nama skrip bila halaman tidak menyebutkannya.
     *
     * @var array<string, string>
     */
    private const SCRIPT_KEYS = [
        'index.php' => 'beranda',
        'izin_ringkasan.php' => 'izin.ringkasan',
        'izin.php' => 'izin.daftar',
        'izin_detail.php' => 'izin.daftar',
        'izin_buat.php' => 'izin.buat',
        'izin_antrean.php' => 'izin.antrean',
        'laporan.php' => 'izin.laporan',
        'notifikasi.php' => 'notifikasi',
        'admin_dashboard.php' => 'admin.dashboard',
        'admin_pengajian.php' => 'pengajian',
        'admin_jadwal_ngaji.php' => 'pengajian',
        'pertemuan_pengajian.php' => 'pengajian',
        'admin_laporan_absensi.php' => 'kehadiran',
        'laporan_absensi_detail.php' => 'kehadiran',
        'admin_master_santri.php' => 'master.santri',
        'admin_wali.php' => 'master.wali',
        'admin_wali_rekonsiliasi.php' => 'master.rekonsiliasi',
        'admin_guru.php' => 'master.guru',
        'admin_pengurus.php' => 'master.pengurus',
        'admin_kelas.php' => 'master.kelas',
        'admin_kamar.php' => 'master.kamar',
        'admin_tahun.php' => 'master.tahun',
        'admin_murobi.php' => 'master.murobi',
        'admin_pembimbing.php' => 'master.pembimbing',
        'admin_akun.php' => 'sistem.akun',
        'admin_akun_perizinan.php' => 'sistem.akun',
        'admin_notifikasi.php' => 'sistem.notifikasi',
        'ubah_password.php' => 'akun.password',
        'admin_data.php' => 'psb.pendaftar',
        'admin_pembayaran_psb.php' => 'psb.pembayaran',
        'admin_rekap_keuangan.php' => 'psb.rekap',
        'admin_alumni.php' => 'alumni',
        'admin_berita.php' => 'konten.berita',
        'admin_galeri.php' => 'konten.galeri',
        'admin_download.php' => 'konten.download',
        'admin_pelanggaran.php' => 'pelanggaran',
        'admin_santri.php' => 'master.santri_lama',
        'admin_rekap_santri.php' => 'master.rekap_santri',
    ];

    /**
     * @param array<string, mixed> $user
     * @param array<int, string> $capabilities
     * @return array<int, array{label:string, items:array<int, array<string, mixed>>}>
     */
    public static function forUser(array $user, array $capabilities, ?int $unreadCount = null): array
    {
        $roles = $user['roles'] ?? [];
        $isAdmin = in_array('admin', $roles, true);
        $isGuru = in_array('guru', $roles, true);
        $hasPerizinan = array_intersect(Capabilities::ALL, $capabilities) !== [];
        $canCreateIzin = array_intersect([Capabilities::ADMIN, Capabilities::PENGURUS], $capabilities) !== [];

        $groups = [];

        $beranda = [self::item('beranda', 'Beranda', '/portal/index.php', 'fa-house')];
        if ($isAdmin) {
            $beranda[] = self::item('admin.dashboard', 'Ringkasan Administrasi', '/admin/admin_dashboard.php', 'fa-gauge-high');
        }
        $groups[] = ['label' => 'Utama', 'items' => $beranda];

        if ($isGuru || $isAdmin) {
            $groups[] = ['label' => 'Pengajian', 'items' => [
                self::item('pengajian', 'Jadwal & Pertemuan', '/admin/admin_pengajian.php', 'fa-book-open'),
                self::item('kehadiran', 'Laporan Kehadiran', '/admin/admin_laporan_absensi.php', 'fa-chart-column'),
            ]];
        }

        if ($hasPerizinan) {
            $izin = [
                self::item('izin.ringkasan', 'Ringkasan Perizinan', '/portal/izin_ringkasan.php', 'fa-clipboard-list'),
                self::item('izin.daftar', 'Daftar Pengajuan', '/portal/izin.php', 'fa-file-shield'),
                self::item('izin.antrean', 'Antrean Keputusan', '/portal/izin_antrean.php', 'fa-inbox'),
            ];
            if ($canCreateIzin) {
                $izin[] = self::item('izin.buat', 'Buat Pengajuan', '/portal/izin_buat.php', 'fa-square-plus');
            }
            $izin[] = self::item('izin.laporan', 'Laporan Perizinan', '/portal/laporan.php', 'fa-file-lines');
            $groups[] = ['label' => 'Perizinan', 'items' => $izin];
        }

        if ($isAdmin) {
            $groups[] = ['label' => 'Master Data', 'items' => [
                self::item('master.santri', 'Data Santri', '/admin/admin_master_santri.php', 'fa-address-book'),
                self::item('master.wali', 'Orang Tua / Wali', '/admin/admin_wali.php', 'fa-people-roof'),
                self::item('master.rekonsiliasi', 'Rekonsiliasi Wali', '/admin/admin_wali_rekonsiliasi.php', 'fa-code-compare'),
                self::item('master.guru', 'Data Guru', '/admin/admin_guru.php', 'fa-chalkboard-user'),
                self::item('master.pengurus', 'Data Pengurus', '/admin/admin_pengurus.php', 'fa-user-tie'),
                self::item('master.kelas', 'Data Kelas', '/admin/admin_kelas.php', 'fa-school'),
                self::item('master.kamar', 'Data Kamar', '/admin/admin_kamar.php', 'fa-bed'),
                self::item('master.tahun', 'Tahun Ajaran', '/admin/admin_tahun.php', 'fa-calendar-days'),
            ]];
            $groups[] = ['label' => 'Penugasan', 'items' => [
                self::item('master.murobi', 'Penugasan Murobi', '/admin/admin_murobi.php', 'fa-user-group'),
                self::item('master.pembimbing', 'Penugasan Pembimbing', '/admin/admin_pembimbing.php', 'fa-user-shield'),
            ]];
        }

        $sistem = [];
        $sistem[] = self::item(
            'notifikasi',
            'Notifikasi Saya',
            '/portal/notifikasi.php',
            'fa-bell',
            $unreadCount !== null && $unreadCount > 0 ? ($unreadCount > 99 ? '99+' : (string) $unreadCount) : null,
            $unreadCount !== null && $unreadCount > 0 ? $unreadCount . ' notifikasi belum dibaca' : null
        );
        if ($isAdmin) {
            $sistem[] = self::item('sistem.akun', 'Akun & Hak Akses', '/admin/admin_akun.php', 'fa-user-lock');
            $sistem[] = self::item('sistem.notifikasi', 'Kanal Notifikasi', '/admin/admin_notifikasi.php', 'fa-tower-broadcast');
        }
        $sistem[] = self::item('akun.password', 'Ganti Password', '/admin/ubah_password.php', 'fa-key');
        $groups[] = ['label' => 'Akun & Sistem', 'items' => $sistem];

        if ($isAdmin) {
            $groups[] = ['label' => 'PSB & Keuangan', 'items' => [
                self::item('psb.pendaftar', 'Data Pendaftar', '/admin/admin_data.php', 'fa-users'),
                self::item('psb.pembayaran', 'Pembayaran PSB', '/admin/admin_pembayaran_psb.php', 'fa-money-bill'),
                self::item('psb.rekap', 'Rekap Keuangan PSB', '/admin/admin_rekap_keuangan.php', 'fa-chart-area'),
            ]];
            $groups[] = ['label' => 'Lain-lain', 'items' => [
                self::item('pelanggaran', 'Pelanggaran', '/admin/admin_pelanggaran.php', 'fa-triangle-exclamation'),
                self::item('alumni', 'Data Alumni', '/admin/admin_alumni.php', 'fa-paper-plane'),
                self::item('konten.berita', 'Berita / Artikel', '/admin/admin_berita.php', 'fa-newspaper'),
                self::item('konten.galeri', 'Galeri Foto', '/admin/admin_galeri.php', 'fa-images'),
                self::item('konten.download', 'File Download', '/admin/admin_download.php', 'fa-download'),
            ]];
        }

        return $groups;
    }

    /**
     * Kunci menu aktif untuk skrip yang sedang berjalan.
     */
    public static function activeKey(?string $script = null): string
    {
        $script = $script ?? basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));

        return self::SCRIPT_KEYS[$script] ?? '';
    }

    /**
     * @return array<string, mixed>
     */
    private static function item(
        string $key,
        string $label,
        string $path,
        string $icon,
        ?string $badge = null,
        ?string $badgeLabel = null
    ): array {
        return [
            'key' => $key,
            'label' => $label,
            'url' => app_url($path),
            'icon' => $icon,
            'badge' => $badge,
            'badge_label' => $badgeLabel,
        ];
    }
}
