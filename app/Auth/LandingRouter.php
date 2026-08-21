<?php

declare(strict_types=1);

namespace App\Auth;

/**
 * Penentu halaman tujuan setelah autentikasi.
 *
 * Satu sumber kebenaran untuk `admin/cek_login.php`, `admin/admin_login.php`, dan
 * `admin/ubah_password.php`, sehingga ketiganya tidak bisa lagi berbeda pendapat
 * tentang ke mana seorang pengguna harus mendarat.
 *
 * Aturan (urut, yang pertama cocok menang):
 *
 * | Kondisi                                   | Tujuan                                   |
 * |-------------------------------------------|------------------------------------------|
 * | role `admin`                              | `/admin/admin_dashboard.php`             |
 * | capability `murobi`                       | `/portal/izin_antrean.php?mode=murobi`   |
 * | role `guru` (tanpa capability murobi)     | `/admin/pertemuan_pengajian.php`         |
 * | role `pengurus` atau `orang_tua`          | `/portal/index.php`                      |
 *
 * Yang PENTING: baris murobi memakai `Capabilities`, yang menuntut role `guru`
 * DAN penugasan `murobi_assignments` aktif pada tahun ajaran aktif. Role `guru`
 * saja tidak pernah cukup — guru biasa tetap mendarat di jadwal mengajar.
 *
 * Kelas ini hanya menentukan TUJUAN NAVIGASI. Ia bukan kontrol akses: setiap
 * halaman tujuan tetap memeriksa sendiri hak pengaksesnya di server
 * (`PortalGuard`, `Authorization`), sehingga menebak URL tidak membuka apa pun.
 */
final class LandingRouter
{
    public function __construct(private Capabilities $capabilities)
    {
    }

    /**
     * @param array{id:int, roles:array<int,string>, guru_id:int|null} $user
     * @return array{url:?string, label:string}
     *         `url` bernilai null bila akun tidak memiliki tujuan yang sah.
     */
    public function destination(array $user): array
    {
        $roles = $user['roles'] ?? [];

        if (in_array('admin', $roles, true)) {
            return ['url' => app_url('/admin/admin_dashboard.php'), 'label' => 'Lanjut ke dashboard'];
        }

        // Capability, bukan role: guru tanpa penugasan murobi aktif tidak lewat sini.
        if ($this->capabilities->has($user, Capabilities::MUROBI)) {
            return [
                'url' => app_url('/portal/izin_antrean.php?mode=' . Capabilities::MUROBI),
                'label' => 'Lanjut ke antrean perizinan',
            ];
        }

        if (in_array('guru', $roles, true)) {
            return ['url' => app_url('/admin/pertemuan_pengajian.php'), 'label' => 'Lanjut ke tugas pengajian'];
        }

        if (in_array('pengurus', $roles, true) || in_array('orang_tua', $roles, true)) {
            return ['url' => app_url('/portal/index.php'), 'label' => 'Lanjut ke portal perizinan'];
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
}
