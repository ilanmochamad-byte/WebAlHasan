<?php

declare(strict_types=1);

namespace App\Auth;

/**
 * Penentu halaman tujuan setelah autentikasi.
 *
 * Satu sumber kebenaran untuk `portal/index.php`, `admin/cek_login.php`,
 * `admin/admin_login.php`, dan `admin/ubah_password.php`.
 *
 * **Perubahan 30 Agustus 2026 (koreksi ke-7, satu pintu masuk).**
 * Sebelumnya router ini memilih SATU halaman berdasarkan urutan role: admin ke
 * dashboard, murobi ke antrean, guru ke jadwal, pengurus/orang tua ke portal.
 * Akibatnya akun multi-peran kehilangan jalur peran lainnya pada saat mendarat,
 * dan tiap peran mendapat kerangka tampilan yang berbeda.
 *
 * Sekarang seluruh akun yang sah mendarat pada **satu beranda**
 * `/portal/index.php`. Beranda itulah yang menyusun panel dan menu dari
 * kemampuan nyata akun (admin, guru, murobi, pengurus, orang tua) sehingga:
 *
 *   - tidak ada role yang diabaikan saat menentukan halaman awal;
 *   - guru tanpa penugasan murobi tetap punya beranda (tidak lagi ditolak 403
 *     oleh pemeriksaan kemampuan perizinan);
 *   - akun multi-peran berpindah modul tanpa login ulang.
 *
 * Router ini tetap hanya menentukan TUJUAN NAVIGASI. Ia bukan kontrol akses:
 * setiap halaman tujuan memeriksa sendiri hak pengaksesnya di server
 * (`PortalGuard`, `Authorization`, dan pemeriksaan cakupan tiap layanan),
 * sehingga menebak URL tidak membuka apa pun.
 */
final class LandingRouter
{
    /**
     * Role yang membuat sebuah akun mempunyai beranda yang sah.
     *
     * @var array<int, string>
     */
    private const ROLE_BERANDA = ['admin', 'guru', 'pengurus', 'orang_tua'];

    public function __construct(private Capabilities $capabilities)
    {
    }

    /**
     * Beranda tunggal Sistem Al Hasan.
     */
    public function homeUrl(): string
    {
        return app_url('/portal/index.php');
    }

    /**
     * @param array{id:int, roles:array<int,string>, guru_id:int|null} $user
     * @return array{url:?string, label:string}
     *         `url` bernilai null bila akun tidak memiliki tujuan yang sah.
     */
    public function destination(array $user): array
    {
        $roles = $user['roles'] ?? [];

        if (array_intersect(self::ROLE_BERANDA, $roles) !== []) {
            return ['url' => $this->homeUrl(), 'label' => 'Lanjut ke beranda'];
        }

        return ['url' => null, 'label' => 'Keluar'];
    }

    /**
     * @param array{id:int, roles:array<int,string>, guru_id:int|null} $user
     */
    public function url(array $user): ?string
    {
        return $this->destination($user)['url'];
    }

    /**
     * Pintasan modul yang ditawarkan beranda kepada satu akun.
     *
     * Dipakai halaman beranda untuk menyusun panelnya. Sama seperti seluruh
     * kelas ini: hanya navigasi, bukan izin. Capability dihitung ulang dari
     * basis data, bukan dari nama role, sehingga guru tanpa penugasan murobi
     * tidak pernah mendapat pintasan antrean keputusan.
     *
     * @param array{id:int, roles:array<int,string>, guru_id:int|null} $user
     * @return array<int, array{key:string, label:string, description:string, url:string}>
     */
    public function shortcuts(array $user): array
    {
        $roles = $user['roles'] ?? [];
        $granted = $this->capabilities->forUser($user);
        $shortcuts = [];

        if (in_array('admin', $roles, true)) {
            $shortcuts[] = [
                'key' => 'admin',
                'label' => 'Ringkasan administrasi',
                'description' => 'Statistik pesantren, master data, akun, dan pengaturan sistem.',
                'url' => app_url('/admin/admin_dashboard.php'),
            ];
        }
        if (in_array('guru', $roles, true) || in_array('admin', $roles, true)) {
            $shortcuts[] = [
                'key' => 'pengajian',
                'label' => 'Jadwal & pertemuan pengajian',
                'description' => 'Pola mingguan dan pelaksanaan pertemuan pada tanggal tertentu.',
                'url' => app_url('/admin/admin_pengajian.php'),
            ];
        }
        if (in_array(Capabilities::MUROBI, $granted, true)) {
            $shortcuts[] = [
                'key' => 'murobi',
                'label' => 'Antrean keputusan perizinan',
                'description' => 'Pengajuan izin yang diarahkan kepada penugasan murobi Anda.',
                'url' => app_url('/portal/izin_antrean.php?mode=' . Capabilities::MUROBI),
            ];
        }
        if (in_array(Capabilities::PENGURUS, $granted, true)) {
            $shortcuts[] = [
                'key' => 'pengurus',
                'label' => 'Pengajuan izin santri',
                'description' => 'Ajukan dan pantau izin santri dalam cakupan pembimbing Anda.',
                'url' => app_url('/portal/izin_buat.php?mode=' . Capabilities::PENGURUS),
            ];
        }
        if (in_array(Capabilities::ORANG_TUA, $granted, true)) {
            $shortcuts[] = [
                'key' => 'orang_tua',
                'label' => 'Status izin anak',
                'description' => 'Status dan riwayat izin santri yang terhubung dengan Anda.',
                'url' => app_url('/portal/izin.php?mode=' . Capabilities::ORANG_TUA),
            ];
        }

        return $shortcuts;
    }
}
