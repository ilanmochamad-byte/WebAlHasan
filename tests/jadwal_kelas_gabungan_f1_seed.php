<?php

declare(strict_types=1);

/**
 * Skenario sintetis Fase 1 kelas gabungan, dibentuk lewat layanan resmi
 * (MasterDataService) di atas DB `tests/jadwal_kelas_gabungan_f1_db.sh`.
 * Dipanggil `require` oleh uji integrasi/HTTP; mengembalikan ID fixture.
 *
 *   kelas PA  (3 santri aktif)        — kelas utama jadwal gabungan
 *   kelas PI  (2 santri aktif + 1 santri yang keanggotaannya sudah Selesai)
 *   kelas C   (2 santri aktif)        — kelas lain, jadwal satu kelas
 *   guru G    = guru milik akun `sbx_guru_biasa` (token API guru)
 *   guru H, I = guru kedua dan ketiga (jadwal satu kelas, uji benturan)
 *
 * Seluruh nama berakhiran sufiks acak agar dapat dijalankan berulang.
 */

if (!function_exists('app_db')) {
    fwrite(STDERR, "Seed harus dimuat sesudah bootstrap.\n");
    exit(2);
}
if (!str_ends_with((string) app_config('database.database'), '_test')) {
    fwrite(STDERR, "Seed ditolak: DB_NAME wajib berakhiran _test.\n");
    exit(2);
}

return (static function (): array {
    $db = app_db();
    $master = master_data_service();
    $satu = static function (string $sql) use ($db): mixed {
        $r = $db->query($sql);
        if ($r === false) {
            throw new RuntimeException($db->error . ' :: ' . $sql);
        }
        return $r->fetch_row()[0] ?? null;
    };
    $sfx = strtoupper(bin2hex(random_bytes(3)));
    $adminId = (int) $satu("SELECT id FROM users WHERE username = 'sbx_admin'");
    $guruUserId = (int) $satu("SELECT id FROM users WHERE username = 'sbx_guru_biasa'");
    $guruG = (int) $satu("SELECT guru_id FROM users WHERE username = 'sbx_guru_biasa'");
    $tahun = (int) $satu("SELECT id FROM tahun_ajaran WHERE status = 'Aktif' AND archived_at IS NULL LIMIT 1");
    if ($adminId < 1 || $guruUserId < 1 || $guruG < 1 || $tahun < 1) {
        throw new RuntimeException('Fixture dasar (sbx_admin, sbx_guru_biasa, semester aktif) tidak tersedia.');
    }
    $guruH = $master->saveGuru(['nip' => 'JKGH' . $sfx, 'nama_guru' => 'Guru Gabungan H ' . $sfx, 'no_hp' => '', 'status' => 'Guru']);
    $guruI = $master->saveGuru(['nip' => 'JKGI' . $sfx, 'nama_guru' => 'Guru Gabungan I ' . $sfx, 'no_hp' => '', 'status' => 'Guru']);
    $kelas = [];
    foreach (['PA' => '1 IBTIDA PA ', 'PI' => '1 IBTIDA PI ', 'C' => '2 IBTIDA C '] as $kunci => $nama) {
        $kelas[$kunci] = $master->saveClass(['nama_kelas' => $nama . $sfx, 'jenjang' => 'Uji']);
    }
    $kelasArsip = $master->saveClass(['nama_kelas' => 'Kelas Arsip ' . $sfx, 'jenjang' => 'Uji']);
    $db->query('UPDATE kelas SET is_active = 0, archived_at = NOW() WHERE id = ' . (int) $kelasArsip);

    $santri = ['PA' => [], 'PI' => [], 'C' => []];
    $buat = static function (string $label) use ($master, $sfx): int {
        static $n = 0;
        $n++;
        return $master->saveSantri(['nis' => 'JKG' . $sfx . $n, 'nama_santri' => 'Santri ' . $label . ' ' . $sfx, 'jenis_kelamin' => str_contains($label, 'PI') ? 'P' : 'L',
            'tempat_lahir' => 'Ciamis', 'tgl_lahir' => '2012-01-0' . (($n % 9) + 1), 'alamat' => '', 'desa' => '', 'kecamatan' => '', 'kab_kota' => '', 'provinsi' => '',
            'nama_ayah' => '', 'no_hp_ayah' => '', 'nama_ibu' => '', 'no_hp_ibu' => '', 'asal_sekolah' => '', 'sekolah_saat_ini' => '', 'foto' => 'default.jpg']);
    };
    foreach (['PA' => 3, 'PI' => 2, 'C' => 2] as $kunci => $jumlah) {
        for ($i = 1; $i <= $jumlah; $i++) {
            $id = $buat($kunci . $i);
            $master->assignActiveClass(['santri_id' => $id, 'kelas_id' => $kelas[$kunci], 'tanggal_mulai' => date('Y-m-d')], $adminId);
            $santri[$kunci][] = $id;
        }
    }
    // Santri PI yang keanggotaannya sudah Selesai: bukan peserta aktif.
    $selesai = $buat('PI selesai');
    $master->assignActiveClass(['santri_id' => $selesai, 'kelas_id' => $kelas['PI'], 'tanggal_mulai' => date('Y-m-d')], $adminId);
    $master->endActiveClass($selesai);

    return [
        'sfx' => $sfx,
        'admin_id' => $adminId,
        'guru_user_id' => $guruUserId,
        'guru_g' => $guruG,
        'guru_h' => $guruH,
        'guru_i' => $guruI,
        'tahun' => $tahun,
        'kelas' => $kelas,
        'kelas_arsip' => $kelasArsip,
        'santri' => $santri,
        'santri_selesai' => $selesai,
    ];
})();
