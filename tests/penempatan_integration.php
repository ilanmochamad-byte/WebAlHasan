<?php

declare(strict_types=1);

/**
 * Pengujian integrasi "Penempatan Kelas & Kamar Santri"
 * (keputusan pengguna 6 September 2026) pada basis data sungguhan.
 *
 *   PN-1  menempatkan satu santri ke kelas;
 *   PN-2  memindahkan santri ke kelas lain — riwayat lama menjadi Selesai;
 *   PN-3  mengakhiri penempatan kelas;
 *   PN-4  menempatkan satu santri ke kamar;
 *   PN-5  menempatkan ulang ke kamar yang sama bersifat idempoten;
 *   PN-6  memindahkan ke kamar lain mempertahankan ID penempatan;
 *   PN-7  mengeluarkan dari kamar; alasan wajib dan tercatat;
 *   PN-8  menempatkan beberapa santri sekaligus;
 *   PN-9  operasi massal ditolak saat sisa kapasitas tidak cukup;
 *   PN-10 kegagalan pada santri terakhir tidak menyisakan perubahan sebelumnya;
 *   PN-11 santri, kelas, kamar, atau tahun tidak aktif ditolak;
 *   PN-12 relasi tahun ajaran sebelumnya tetap utuh;
 *   PN-13 snapshot peserta pertemuan dan absensi lama tidak berubah;
 *   PN-14 routing murobi mengikuti penempatan aktif yang benar;
 *   PN-15 audit memuat pelaku, jenis tindakan, sebelum/sesudah, mode, dan alasan;
 *   PN-16 audit yang gagal membatalkan perubahan penempatan (atomik);
 *   PN-17 penempatan ganda/retry tidak menghasilkan relasi ganda;
 *   PN-18 konflik data warisan ditolak, bukan diperbaiki otomatis;
 *   PN-19 batas operasi massal dan validasi ID dijaga di server;
 *   PN-20 santri nonaktif/arsip tetap dapat DIKELUARKAN dari kamar;
 *   PN-21 filter kamar tetap menemukan santri yang datanya berkonflik;
 *   PN-22 tindakan massal yang tidak mengubah apa pun tidak menulis audit;
 *   PN-23 perpindahan kamar tidak pernah menyentuh baris tahun ajaran lain;
 *   PN-24 tanggal tak terpakai tidak memblokir tindakan kamar.
 *
 * Seluruh fixture memakai data FIKTIF berakhiran acak dan dihapus kembali pada
 * blok `finally`. Tidak ada permintaan jaringan keluar dan tidak ada data
 * produksi yang disentuh.
 *
 * Jalankan hanya pada database berakhiran `_test`:
 *   PENEMPATAN_RUN_INTEGRATION=1 php tests/penempatan_integration.php
 */

$root = dirname(__DIR__);
if (getenv('PENEMPATAN_RUN_INTEGRATION') !== '1') {
    fwrite(STDOUT, "[lewati] Set PENEMPATAN_RUN_INTEGRATION=1 dan arahkan DB_NAME ke database khusus *_test.\n");
    exit(77);
}

require_once $root . '/app/bootstrap.php';

use App\MasterData\MasterDataException;
use App\MasterData\PenempatanService;

if (!str_ends_with((string) app_config('database.database'), '_test')) {
    fwrite(STDERR, "Ditolak: pengujian ini hanya boleh berjalan pada database berakhiran _test.\n");
    exit(2);
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    echo ($condition ? '[lulus] ' : '[gagal] ') . $message . PHP_EOL;
    if (!$condition) {
        $failures[] = $message;
    }
};
$tolak = static function (callable $aksi, string $pesan) use ($assert): void {
    try {
        $aksi();
        $assert(false, $pesan . ' (ternyata TIDAK ditolak)');
    } catch (Throwable $exception) {
        $assert(true, $pesan . ' [' . $exception->getMessage() . ']');
    }
};

$db = app_db();
$suffix = strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
$kecil = strtolower($suffix);

$satu = static function (string $sql) use ($db): array {
    $rs = $db->query($sql);

    return ($rs && $row = $rs->fetch_assoc()) ? $row : [];
};
$semua = static function (string $sql) use ($db): array {
    $rs = $db->query($sql);

    return $rs ? $rs->fetch_all(MYSQLI_ASSOC) : [];
};
$angka = static fn (string $sql): int => (int) ($satu($sql)['n'] ?? 0);
$exec = static function (string $sql, array $params = []) use ($db): int {
    $statement = $db->prepare($sql);
    if ($statement === false) {
        throw new RuntimeException('Query gagal disiapkan: ' . $db->error . ' — ' . $sql);
    }
    if ($params !== []) {
        $types = '';
        $refs = [];
        foreach ($params as $key => &$value) {
            $types .= is_int($value) ? 'i' : 's';
            $refs[$key] = &$value;
        }
        unset($value);
        $statement->bind_param($types, ...$refs);
    }
    if (!$statement->execute()) {
        $error = $statement->error;
        $statement->close();
        throw new RuntimeException('Query gagal dijalankan: ' . $error);
    }
    $id = (int) $statement->insert_id;
    $statement->close();

    return $id;
};

$dibuat = ['users' => [], 'santri' => [], 'kelas' => [], 'kamar' => [], 'guru' => [], 'murobi' => [], 'jadwal' => [], 'pertemuan' => [], 'tahun' => []];
$tahunLamaDinonaktifkan = false;

try {
    $service = penempatan_service();
    $master = master_data_service();

    // ------------------------------------------------------------- fixture
    $adminId = $exec(
        'INSERT INTO users (name, username, password, is_active, force_password_change, created_at, updated_at)
         VALUES (?, ?, ?, 1, 0, NOW(), NOW())',
        ['Admin Penempatan ' . $suffix, 'pn.admin.' . $kecil, password_hash('UjiPenempatan123Aa', PASSWORD_DEFAULT)]
    );
    $dibuat['users'][] = $adminId;
    $exec("INSERT INTO user_roles (user_id, role_id, assigned_by) SELECT ?, id, ? FROM roles WHERE slug = 'admin'", [$adminId, $adminId]);
    $_SESSION['user_id'] = $adminId;

    $year = $service->activeYear();
    $assert(is_array($year), 'PN-0 tahun ajaran aktif tersedia untuk pengujian');
    $yearId = (int) $year['id'];

    $kelasA = $exec("INSERT INTO kelas (nama_kelas, jenjang, is_active, created_at, updated_at) VALUES (?, 'Uji', 1, NOW(), NOW())", ['PN Kelas A ' . $suffix]);
    $kelasB = $exec("INSERT INTO kelas (nama_kelas, jenjang, is_active, created_at, updated_at) VALUES (?, 'Uji', 1, NOW(), NOW())", ['PN Kelas B ' . $suffix]);
    $kelasArsip = $exec("INSERT INTO kelas (nama_kelas, jenjang, is_active, archived_at, created_at, updated_at) VALUES (?, 'Uji', 0, NOW(), NOW(), NOW())", ['PN Kelas Arsip ' . $suffix]);
    $dibuat['kelas'] = [$kelasA, $kelasB, $kelasArsip];

    $kamarA = $exec('INSERT INTO kamar (nama_kamar, kapasitas) VALUES (?, 3)', ['PN Kamar A ' . $suffix]);
    $kamarB = $exec('INSERT INTO kamar (nama_kamar, kapasitas) VALUES (?, 3)', ['PN Kamar B ' . $suffix]);
    $kamarSempit = $exec('INSERT INTO kamar (nama_kamar, kapasitas) VALUES (?, 2)', ['PN Kamar Sempit ' . $suffix]);
    $dibuat['kamar'] = [$kamarA, $kamarB, $kamarSempit];

    $santri = [];
    foreach (['A', 'B', 'C', 'D'] as $index => $tanda) {
        $santri[$tanda] = $exec(
            "INSERT INTO santri (nis, nama_santri, jenis_kelamin, tempat_lahir, tgl_lahir, alamat, desa, kecamatan, kab_kota, provinsi,
                                 nama_ayah, nama_ibu, asal_sekolah, sekolah_saat_ini, foto, is_active, created_at, updated_at)
             VALUES (?, ?, 'L', 'Ciamis', '2012-01-0" . ($index + 1) . "', 'Jl Uji', 'Desa', 'Kec', 'Kab', 'Prov', '', '', '', ?, 'default.jpg', 1, NOW(), NOW())",
            ['PN' . $suffix . $tanda, 'Santri Penempatan ' . $tanda . ' ' . $suffix, 'SMK Uji ' . $suffix]
        );
        $dibuat['santri'][] = $santri[$tanda];
    }

    // ============================================================== PN-1..3
    $service->apply(PenempatanService::AKSI_KELAS_TETAPKAN, [$santri['A']], ['kelas_id' => $kelasA, 'tanggal_mulai' => '2026-09-01'], $adminId);
    $aktif = $satu("SELECT id, id_kelas, tanggal_mulai, status FROM plotting_kelas WHERE id_santri = {$santri['A']} AND id_tahun = {$yearId} AND status = 'Aktif'");
    $assert((int) ($aktif['id_kelas'] ?? 0) === $kelasA, 'PN-1 satu santri ditempatkan ke kelas pada semester aktif');
    $assert(($aktif['tanggal_mulai'] ?? '') === '2026-09-01', 'PN-1 tanggal mulai penempatan kelas tersimpan');
    $idKelasPertama = (int) $aktif['id'];

    $service->apply(PenempatanService::AKSI_KELAS_TETAPKAN, [$santri['A']], ['kelas_id' => $kelasB, 'tanggal_mulai' => '2026-09-15'], $adminId);
    $lama = $satu("SELECT status, tanggal_selesai FROM plotting_kelas WHERE id = {$idKelasPertama}");
    $baru = $satu("SELECT id, id_kelas FROM plotting_kelas WHERE id_santri = {$santri['A']} AND id_tahun = {$yearId} AND status = 'Aktif'");
    $assert(($lama['status'] ?? '') === 'Selesai' && ($lama['tanggal_selesai'] ?? '') === '2026-09-15', 'PN-2 penempatan kelas lama diselesaikan dengan tanggal selesai');
    $assert((int) ($baru['id_kelas'] ?? 0) === $kelasB, 'PN-2 penempatan kelas baru menjadi aktif');
    $assert($angka("SELECT COUNT(*) n FROM plotting_kelas WHERE id_santri = {$santri['A']}") === 2, 'PN-2 riwayat kelas lama tidak dihapus');

    $tolak(
        static fn () => $service->apply(PenempatanService::AKSI_KELAS_KELUARKAN, [$santri['A']], [], $adminId),
        'PN-3 mengeluarkan dari kelas tanpa alasan ditolak'
    );
    $service->apply(PenempatanService::AKSI_KELAS_KELUARKAN, [$santri['A']], ['alasan' => 'Pindah unit sekolah'], $adminId);
    $assert($angka("SELECT COUNT(*) n FROM plotting_kelas WHERE id_santri = {$santri['A']} AND status = 'Aktif'") === 0, 'PN-3 penempatan kelas aktif berakhir');
    $assert($angka("SELECT COUNT(*) n FROM plotting_kelas WHERE id_santri = {$santri['A']}") === 2, 'PN-3 baris riwayat kelas tetap ada setelah dikeluarkan');

    // ============================================================== PN-4..7
    $hasil = $service->apply(PenempatanService::AKSI_KAMAR_TETAPKAN, [$santri['A']], ['kamar_id' => $kamarA], $adminId);
    $barisKamar = $satu("SELECT id, id_kamar FROM plotting_kamar WHERE id_santri = {$santri['A']} AND id_tahun = {$yearId}");
    $idKamarBaris = (int) ($barisKamar['id'] ?? 0);
    $assert((int) ($barisKamar['id_kamar'] ?? 0) === $kamarA && $hasil['diterapkan'] === 1, 'PN-4 satu santri ditempatkan ke kamar');

    $ulang = $service->apply(PenempatanService::AKSI_KAMAR_TETAPKAN, [$santri['A']], ['kamar_id' => $kamarA], $adminId);
    $assert(
        $ulang['diterapkan'] === 0 && $ulang['tidak_berubah'] === 1
        && $angka("SELECT COUNT(*) n FROM plotting_kamar WHERE id_santri = {$santri['A']} AND id_tahun = {$yearId}") === 1,
        'PN-5 penempatan ke kamar yang sama idempoten: tidak ada baris baru'
    );

    $auditSebelumPindah = $angka('SELECT COUNT(*) n FROM audit_logs');
    $service->apply(PenempatanService::AKSI_KAMAR_TETAPKAN, [$santri['A']], ['kamar_id' => $kamarB], $adminId);
    $setelah = $satu("SELECT id, id_kamar FROM plotting_kamar WHERE id_santri = {$santri['A']} AND id_tahun = {$yearId}");
    $assert((int) ($setelah['id'] ?? 0) === $idKamarBaris, 'PN-6 ID penempatan kamar dipertahankan saat pindah (bukan hapus lalu sisip)');
    $assert((int) ($setelah['id_kamar'] ?? 0) === $kamarB, 'PN-6 kamar berubah ke tujuan baru');
    $auditPindah = $satu("SELECT action, before_json, after_json, actor_user_id FROM audit_logs WHERE entity_type = 'plotting_kamar' ORDER BY id DESC LIMIT 1");
    $assert(
        ($auditPindah['action'] ?? '') === 'penempatan.kamar.tetapkan'
        && str_contains((string) $auditPindah['before_json'], '"kamar_id":' . $kamarA)
        && str_contains((string) $auditPindah['after_json'], '"kamar_id":' . $kamarB)
        && (int) $auditPindah['actor_user_id'] === $adminId,
        'PN-6 audit perpindahan kamar memuat nilai sebelum, sesudah, dan pelakunya'
    );
    $assert($angka('SELECT COUNT(*) n FROM audit_logs') > $auditSebelumPindah, 'PN-6 audit bertambah pada perpindahan kamar');

    $tolak(
        static fn () => $service->apply(PenempatanService::AKSI_KAMAR_KELUARKAN, [$santri['A']], ['alasan' => ''], $adminId),
        'PN-7 mengeluarkan dari kamar tanpa alasan ditolak'
    );
    $service->apply(PenempatanService::AKSI_KAMAR_KELUARKAN, [$santri['A']], ['alasan' => 'Pulang tahunan'], $adminId);
    $assert($angka("SELECT COUNT(*) n FROM plotting_kamar WHERE id_santri = {$santri['A']} AND id_tahun = {$yearId}") === 0, 'PN-7 santri keluar dari kamar pada semester aktif');
    $auditKeluar = $satu("SELECT action, before_json, after_json FROM audit_logs WHERE entity_type = 'plotting_kamar' ORDER BY id DESC LIMIT 1");
    $assert(
        ($auditKeluar['action'] ?? '') === 'penempatan.kamar.keluarkan'
        && str_contains((string) $auditKeluar['before_json'], '"kamar_id":' . $kamarB)
        && str_contains((string) $auditKeluar['after_json'], 'Pulang tahunan'),
        'PN-7 audit pengeluaran memuat kamar sebelumnya dan alasan admin'
    );

    // ================================================================= PN-8
    $massal = $service->apply(PenempatanService::AKSI_KAMAR_TETAPKAN, [$santri['A'], $santri['B'], $santri['C']], ['kamar_id' => $kamarA], $adminId);
    $assert(
        $massal['diterapkan'] === 3
        && $angka("SELECT COUNT(*) n FROM plotting_kamar WHERE id_kamar = {$kamarA} AND id_tahun = {$yearId}") === 3,
        'PN-8 tiga santri ditempatkan sekaligus ke satu kamar'
    );
    $ringkasan = $satu("SELECT action, after_json FROM audit_logs WHERE action = 'penempatan.kamar.massal' ORDER BY id DESC LIMIT 1");
    $assert(
        str_contains((string) ($ringkasan['after_json'] ?? ''), '"jumlah_santri":3')
        && str_contains((string) ($ringkasan['after_json'] ?? ''), '"diterapkan":3'),
        'PN-8 audit ringkasan massal mencatat jumlah santri dan jumlah yang diterapkan'
    );
    $massalKelas = $service->apply(PenempatanService::AKSI_KELAS_TETAPKAN, [$santri['B'], $santri['C']], ['kelas_id' => $kelasA], $adminId);
    $assert($massalKelas['diterapkan'] === 2, 'PN-8 penempatan kelas massal berhasil untuk dua santri');

    // ================================================================= PN-9
    $service->apply(PenempatanService::AKSI_KAMAR_TETAPKAN, [$santri['D']], ['kamar_id' => $kamarSempit], $adminId);
    $tolak(
        static fn () => $service->apply(PenempatanService::AKSI_KAMAR_TETAPKAN, [$santri['A'], $santri['B']], ['kamar_id' => $kamarSempit], $adminId),
        'PN-9 operasi massal ditolak karena sisa kapasitas kamar tidak cukup'
    );
    $assert(
        $angka("SELECT COUNT(*) n FROM plotting_kamar WHERE id_kamar = {$kamarSempit} AND id_tahun = {$yearId}") === 1
        && $angka("SELECT COUNT(*) n FROM plotting_kamar WHERE id_kamar = {$kamarA} AND id_tahun = {$yearId}") === 3,
        'PN-9 penolakan kapasitas tidak memindahkan satu santri pun'
    );
    // Santri yang sudah berada di kamar tujuan tidak dihitung sebagai tambahan.
    $service->apply(PenempatanService::AKSI_KAMAR_TETAPKAN, [$santri['D'], $santri['A']], ['kamar_id' => $kamarSempit], $adminId);
    $assert(
        $angka("SELECT COUNT(*) n FROM plotting_kamar WHERE id_kamar = {$kamarSempit} AND id_tahun = {$yearId}") === 2,
        'PN-9 santri yang sudah berada di kamar tujuan tidak dihitung sebagai tambahan kapasitas'
    );

    // ================================================================ PN-10
    // Santri terakhir dinonaktifkan; seluruh operasi harus dibatalkan.
    $exec('UPDATE santri SET is_active = 0 WHERE id = ?', [$santri['C']]);
    $kamarSebelum = $semua("SELECT id, id_santri, id_kamar FROM plotting_kamar WHERE id_tahun = {$yearId} AND id_santri IN ({$santri['A']},{$santri['B']},{$santri['C']}) ORDER BY id");
    $tolak(
        static fn () => $service->apply(PenempatanService::AKSI_KAMAR_TETAPKAN, [$santri['A'], $santri['B'], $santri['C']], ['kamar_id' => $kamarB], $adminId),
        'PN-10 operasi massal gagal karena santri terakhir tidak aktif'
    );
    $kamarSesudah = $semua("SELECT id, id_santri, id_kamar FROM plotting_kamar WHERE id_tahun = {$yearId} AND id_santri IN ({$santri['A']},{$santri['B']},{$santri['C']}) ORDER BY id");
    $assert($kamarSebelum === $kamarSesudah, 'PN-10 kegagalan santri terakhir tidak meninggalkan perubahan santri sebelumnya');
    $exec('UPDATE santri SET is_active = 1 WHERE id = ?', [$santri['C']]);

    // ================================================================ PN-11
    $exec('UPDATE santri SET archived_at = NOW(), is_active = 0 WHERE id = ?', [$santri['D']]);
    $tolak(
        static fn () => $service->apply(PenempatanService::AKSI_KAMAR_TETAPKAN, [$santri['D']], ['kamar_id' => $kamarA], $adminId),
        'PN-11 santri yang diarsipkan ditolak'
    );
    $exec('UPDATE santri SET archived_at = NULL, is_active = 1 WHERE id = ?', [$santri['D']]);
    $tolak(
        static fn () => $service->apply(PenempatanService::AKSI_KELAS_TETAPKAN, [$santri['A']], ['kelas_id' => $kelasArsip], $adminId),
        'PN-11 kelas yang diarsipkan ditolak sebagai tujuan'
    );
    $tolak(
        static fn () => $service->apply(PenempatanService::AKSI_KAMAR_TETAPKAN, [$santri['A']], ['kamar_id' => 999999999], $adminId),
        'PN-11 kamar yang tidak ada ditolak sebagai tujuan'
    );
    $tolak(
        static fn () => $service->apply(PenempatanService::AKSI_KAMAR_TETAPKAN, [$santri['A']], ['kamar_id' => 'bukan-angka'], $adminId),
        'PN-11 ID kamar bukan bilangan bulat positif ditolak'
    );
    $exec("UPDATE tahun_ajaran SET status = 'Non-Aktif' WHERE id = ?", [$yearId]);
    $tahunLamaDinonaktifkan = true;
    $tolak(
        static fn () => $service->apply(PenempatanService::AKSI_KAMAR_TETAPKAN, [$santri['A']], ['kamar_id' => $kamarA], $adminId),
        'PN-11 tanpa tahun ajaran aktif seluruh penempatan ditolak'
    );
    $exec("UPDATE tahun_ajaran SET status = 'Aktif' WHERE id = ?", [$yearId]);
    $tahunLamaDinonaktifkan = false;

    // ================================================================ PN-12
    $tahunLama = $exec("INSERT INTO tahun_ajaran (tahun, semester, status, created_at, updated_at) VALUES (?, 'Genap', 'Non-Aktif', NOW(), NOW())", ['2019/20' . substr($suffix, 0, 2)]);
    $dibuat['tahun'][] = $tahunLama;
    $barisLama = $exec('INSERT INTO plotting_kamar (id_santri, id_kamar, id_tahun) VALUES (?, ?, ?)', [$santri['A'], $kamarB, $tahunLama]);
    $service->apply(PenempatanService::AKSI_KAMAR_TETAPKAN, [$santri['A']], ['kamar_id' => $kamarB], $adminId);
    $service->apply(PenempatanService::AKSI_KAMAR_KELUARKAN, [$santri['A']], ['alasan' => 'Uji tahun lama'], $adminId);
    $assert(
        $angka("SELECT COUNT(*) n FROM plotting_kamar WHERE id = {$barisLama}") === 1,
        'PN-12 penempatan kamar tahun ajaran sebelumnya tidak ikut terhapus'
    );

    // ================================================================ PN-13
    $guruId = $exec("INSERT INTO guru (nip, nama_guru, no_hp, status, is_active, created_at, updated_at) VALUES (?, ?, NULL, 'Guru', 1, NOW(), NOW())", ['PN' . $suffix, 'Guru Penempatan ' . $suffix]);
    $dibuat['guru'][] = $guruId;
    $service->apply(PenempatanService::AKSI_KELAS_TETAPKAN, [$santri['A']], ['kelas_id' => $kelasA, 'tanggal_mulai' => date('Y-m-d')], $adminId);
    $plotAktif = (int) $satu("SELECT id FROM plotting_kelas WHERE id_santri = {$santri['A']} AND status = 'Aktif'")['id'];
    $jadwalId = $exec(
        "INSERT INTO jadwal_ngaji (id_tahun, waktu_sholat, hari, jam, waktu_mulai, waktu_selesai, id_kelas, fan_ilmu, nama_kitab, id_guru, tempat, is_active, created_at, updated_at)
         VALUES (?, 'Ba''da Isya', 'Senin', '19:30', '19:30:00', '20:30:00', ?, 'Fikih', 'Uji', ?, 'Aula Uji', 1, NOW(), NOW())",
        [$yearId, $kelasA, $guruId]
    );
    $dibuat['jadwal'][] = $jadwalId;
    $pertemuanId = $exec(
        "INSERT INTO pertemuan_pengajian (jadwal_id, tanggal_pertemuan, status, created_by, opened_by, opened_at, created_at, updated_at)
         VALUES (?, ?, 'Dibuka', ?, ?, NOW(), NOW(), NOW())",
        [$jadwalId, date('Y-m-d', strtotime('-1 day')), $adminId, $adminId]
    );
    $dibuat['pertemuan'][] = $pertemuanId;
    $exec(
        'INSERT INTO pertemuan_peserta (pertemuan_id, santri_id, plotting_kelas_id, nis_snapshot, nama_santri_snapshot, kelas_id_snapshot, tahun_ajaran_id_snapshot)
         VALUES (?, ?, ?, ?, ?, ?, ?)',
        [$pertemuanId, $santri['A'], $plotAktif, 'PN' . $suffix . 'A', 'Santri Penempatan A ' . $suffix, $kelasA, $yearId]
    );
    $snapshotSebelum = $semua("SELECT nis_snapshot, nama_santri_snapshot, kelas_id_snapshot, tahun_ajaran_id_snapshot FROM pertemuan_peserta WHERE pertemuan_id = {$pertemuanId}");
    $service->apply(PenempatanService::AKSI_KELAS_TETAPKAN, [$santri['A']], ['kelas_id' => $kelasB, 'tanggal_mulai' => date('Y-m-d')], $adminId);
    $snapshotSesudah = $semua("SELECT nis_snapshot, nama_santri_snapshot, kelas_id_snapshot, tahun_ajaran_id_snapshot FROM pertemuan_peserta WHERE pertemuan_id = {$pertemuanId}");
    $assert($snapshotSebelum === $snapshotSesudah, 'PN-13 snapshot peserta pertemuan tidak berubah saat kelas santri berubah');
    $assert(
        $angka("SELECT COUNT(*) n FROM pertemuan_peserta WHERE pertemuan_id = {$pertemuanId} AND kelas_id_snapshot = {$kelasA}") === 1,
        'PN-13 kelas pada snapshot tetap kelas saat pertemuan dibuka'
    );

    // ================================================================ PN-14
    $murobiId = $exec(
        "INSERT INTO murobi_assignments (guru_id, tahun_ajaran_id, target_type, kamar_id, kelas_id, tanggal_mulai, tanggal_selesai, is_active, created_by, created_at, updated_at)
         VALUES (?, ?, 'Kamar', ?, NULL, ?, NULL, 1, ?, NOW(), NOW())",
        [$guruId, $yearId, $kamarA, date('Y-m-d', strtotime('-30 day')), $adminId]
    );
    $dibuat['murobi'][] = $murobiId;
    $router = izin_router();
    $service->apply(PenempatanService::AKSI_KAMAR_TETAPKAN, [$santri['A']], ['kamar_id' => $kamarA], $adminId);
    $kandidatDiKamarA = $router->candidates($santri['A'], date('Y-m-d'));
    $assert(
        count(array_filter($kandidatDiKamarA, static fn (array $k): bool => (int) $k['guru_id'] === $guruId)) === 1,
        'PN-14 murobi kamar menjadi kandidat setelah santri ditempatkan di kamarnya'
    );
    $service->apply(PenempatanService::AKSI_KAMAR_TETAPKAN, [$santri['A']], ['kamar_id' => $kamarB], $adminId);
    $kandidatDiKamarB = $router->candidates($santri['A'], date('Y-m-d'));
    $assert(
        count(array_filter($kandidatDiKamarB, static fn (array $k): bool => (int) $k['guru_id'] === $guruId)) === 0,
        'PN-14 setelah pindah kamar, murobi kamar lama tidak lagi menjadi kandidat'
    );

    // ================================================================ PN-15
    $service->apply(PenempatanService::AKSI_KAMAR_TETAPKAN, [$santri['B'], $santri['C']], ['kamar_id' => $kamarB], $adminId);
    $auditBaris = $satu("SELECT after_json FROM audit_logs WHERE action = 'penempatan.kamar.tetapkan' ORDER BY id DESC LIMIT 1");
    $isi = json_decode((string) $auditBaris['after_json'], true);
    $assert(
        is_array($isi)
        && ($isi['mode'] ?? '') === 'massal'
        && (int) ($isi['jumlah_santri'] ?? 0) === 2
        && (int) ($isi['tahun_ajaran_id'] ?? 0) === $yearId
        && array_key_exists('nis', $isi) && array_key_exists('nama_santri', $isi) && array_key_exists('alasan', $isi),
        'PN-15 audit baris memuat santri, tahun ajaran, mode, jumlah, dan alasan'
    );
    $assert(
        str_contains(json_encode($isi, JSON_UNESCAPED_UNICODE), 'password') === false
        && str_contains(json_encode($isi, JSON_UNESCAPED_UNICODE), 'token') === false,
        'PN-15 audit tidak memuat password atau token'
    );

    // ================================================================ PN-16
    // Audit dibuat gagal dengan mengganti nama tabelnya sementara.
    $keadaanSebelum = $semua("SELECT id, id_santri, id_kamar FROM plotting_kamar WHERE id_tahun = {$yearId} ORDER BY id");
    $db->query('RENAME TABLE audit_logs TO audit_logs_pn_' . $kecil);
    $galatAudit = null;
    try {
        $service->apply(PenempatanService::AKSI_KAMAR_TETAPKAN, [$santri['A']], ['kamar_id' => $kamarA], $adminId);
    } catch (Throwable $exception) {
        $galatAudit = $exception->getMessage();
    } finally {
        $db->query('RENAME TABLE audit_logs_pn_' . $kecil . ' TO audit_logs');
    }
    $keadaanSesudah = $semua("SELECT id, id_santri, id_kamar FROM plotting_kamar WHERE id_tahun = {$yearId} ORDER BY id");
    $assert($galatAudit !== null, 'PN-16 kegagalan audit menghentikan operasi penempatan [' . (string) $galatAudit . ']');
    $assert($keadaanSebelum === $keadaanSesudah, 'PN-16 kegagalan audit membatalkan perubahan penempatan (transaksi sama)');

    // ================================================================ PN-17
    $sebelumRetry = $angka("SELECT COUNT(*) n FROM plotting_kamar WHERE id_santri = {$santri['B']} AND id_tahun = {$yearId}");
    for ($i = 0; $i < 3; $i++) {
        $service->apply(PenempatanService::AKSI_KAMAR_TETAPKAN, [$santri['B']], ['kamar_id' => $kamarB], $adminId);
    }
    $assert(
        $angka("SELECT COUNT(*) n FROM plotting_kamar WHERE id_santri = {$santri['B']} AND id_tahun = {$yearId}") === $sebelumRetry
        && $sebelumRetry === 1,
        'PN-17 pengiriman ulang tiga kali tidak menghasilkan relasi kamar ganda'
    );
    for ($i = 0; $i < 3; $i++) {
        $service->apply(PenempatanService::AKSI_KELAS_TETAPKAN, [$santri['B']], ['kelas_id' => $kelasA], $adminId);
    }
    $assert(
        $angka("SELECT COUNT(*) n FROM plotting_kelas WHERE id_santri = {$santri['B']} AND id_tahun = {$yearId} AND status = 'Aktif'") === 1,
        'PN-17 pengiriman ulang kelas tidak menghasilkan dua penempatan aktif'
    );

    // ================================================================ PN-18
    $duplikat = $exec('INSERT INTO plotting_kamar (id_santri, id_kamar, id_tahun) VALUES (?, ?, ?)', [$santri['B'], $kamarA, $yearId]);
    $tolak(
        static fn () => $service->apply(PenempatanService::AKSI_KAMAR_TETAPKAN, [$santri['B']], ['kamar_id' => $kamarB], $adminId),
        'PN-18 konflik data warisan (dua kamar untuk satu santri) ditolak, bukan diperbaiki otomatis'
    );
    $assert(
        $angka("SELECT COUNT(*) n FROM plotting_kamar WHERE id_santri = {$santri['B']} AND id_tahun = {$yearId}") === 2,
        'PN-18 data yang berkonflik tidak diubah oleh sistem'
    );
    $preflight = (new App\MasterData\PenempatanRepository($db))->conflictDuplicateRoom();
    $assert(
        count(array_filter($preflight, static fn (array $r): bool => (int) $r['id_santri'] === $santri['B'])) === 1,
        'PN-18 preflight melaporkan konflik duplikasi kamar'
    );
    $exec('DELETE FROM plotting_kamar WHERE id = ?', [$duplikat]);

    // ================================================================ PN-19
    $tolak(
        static fn () => $service->apply(PenempatanService::AKSI_KAMAR_TETAPKAN, [], ['kamar_id' => $kamarA], $adminId),
        'PN-19 daftar santri kosong ditolak'
    );
    $tolak(
        static fn () => $service->apply(PenempatanService::AKSI_KAMAR_TETAPKAN, [$santri['A'], '0'], ['kamar_id' => $kamarA], $adminId),
        'PN-19 ID santri bukan bilangan bulat positif ditolak'
    );
    $tolak(
        static fn () => $service->apply('hapus_semua', [$santri['A']], [], $adminId),
        'PN-19 tindakan yang tidak dikenal ditolak'
    );
    $tolak(
        static fn () => $service->apply(PenempatanService::AKSI_KAMAR_TETAPKAN, range(1, PenempatanService::BATAS_MASSAL + 1), ['kamar_id' => $kamarA], $adminId),
        'PN-19 operasi massal di atas batas ditolak sebelum menyentuh basis data'
    );

    // Duplikasi ID pada satu permintaan dihitung sekali saja.
    $service->apply(PenempatanService::AKSI_KAMAR_KELUARKAN, [$santri['A']], ['alasan' => 'Bersih-bersih uji'], $adminId);
    $service->apply(PenempatanService::AKSI_KAMAR_TETAPKAN, [$santri['A'], $santri['A'], $santri['A']], ['kamar_id' => $kamarA], $adminId);
    $assert(
        $angka("SELECT COUNT(*) n FROM plotting_kamar WHERE id_santri = {$santri['A']} AND id_tahun = {$yearId}") === 1,
        'PN-19 ID santri ganda pada satu permintaan hanya menghasilkan satu penempatan'
    );

    // Daftar halaman: filter server bekerja dan hanya mengembalikan santri aktif.
    $daftar = $service->listPage(['q' => 'PN' . $suffix, 'status' => ''], $yearId, 1, 50);
    $assert((int) $daftar['total'] >= 4, 'PN-19 daftar penempatan menemukan santri uji lewat pencarian NIS');
    $tanpaKamar = $service->listPage(['q' => 'PN' . $suffix, 'status' => 'tanpa_kamar'], $yearId, 1, 50);
    $assert(
        count(array_filter($tanpaKamar['rows'], static fn (array $r): bool => $r['id_kamar'] !== null)) === 0,
        'PN-19 filter "belum mempunyai kamar" hanya menampilkan santri tanpa kamar'
    );
    $preview = $service->preview(PenempatanService::AKSI_KAMAR_TETAPKAN, [$santri['A'], $santri['B']], ['kamar_id' => $kamarA]);
    $assert(
        $preview['kapasitas']['kapasitas'] === 3 && $preview['jumlah'] === 2 && $preview['mode'] === 'massal',
        'PN-19 tinjauan menampilkan kapasitas kamar dan jumlah santri terpilih'
    );

    // ================================================================ PN-20
    // Santri yang diarsipkan tidak boleh menahan tempat tidur selamanya.
    $service->apply(PenempatanService::AKSI_KAMAR_TETAPKAN, [$santri['C']], ['kamar_id' => $kamarB], $adminId);
    $exec('UPDATE santri SET is_active = 0, archived_at = NOW() WHERE id = ?', [$santri['C']]);
    $tolak(
        static fn () => $service->apply(PenempatanService::AKSI_KAMAR_TETAPKAN, [$santri['C']], ['kamar_id' => $kamarA], $adminId),
        'PN-20 santri arsip tetap TIDAK dapat ditempatkan'
    );
    $terlihat = $service->listPage(['status' => 'nonaktif_berkamar'], $yearId, 1, 100);
    $assert(
        count(array_filter($terlihat['rows'], static fn (array $r): bool => (int) $r['id'] === $santri['C'])) === 1,
        'PN-20 filter "nonaktif/arsip tetapi masih berkamar" menemukan santri itu'
    );
    $service->apply(PenempatanService::AKSI_KAMAR_KELUARKAN, [$santri['C']], ['alasan' => 'Santri sudah diarsipkan'], $adminId);
    $assert(
        $angka("SELECT COUNT(*) n FROM plotting_kamar WHERE id_santri = {$santri['C']} AND id_tahun = {$yearId}") === 0,
        'PN-20 santri arsip dapat dikeluarkan sehingga tempatnya kembali tersedia'
    );
    $ringkasNonaktif = (new App\MasterData\PenempatanRepository($db))->countWithoutPlacement($yearId);
    $assert(array_key_exists('nonaktif_berkamar', $ringkasNonaktif), 'PN-20 ringkasan memuat hitungan santri nonaktif yang masih berkamar');
    $exec('UPDATE santri SET is_active = 1, archived_at = NULL WHERE id = ?', [$santri['C']]);

    // ================================================================ PN-21
    // Santri dengan dua kamar hanya terwakili baris ber-ID terkecil pada JOIN;
    // filter kamar harus tetap menemukannya dari sisi kamar yang lain.
    $service->apply(PenempatanService::AKSI_KAMAR_TETAPKAN, [$santri['C']], ['kamar_id' => $kamarA], $adminId);
    $konflikBaris = $exec('INSERT INTO plotting_kamar (id_santri, id_kamar, id_tahun) VALUES (?, ?, ?)', [$santri['C'], $kamarB, $yearId]);
    $dariKamarB = $service->listPage(['kamar_id' => $kamarB], $yearId, 1, 100);
    $assert(
        count(array_filter($dariKamarB['rows'], static fn (array $r): bool => (int) $r['id'] === $santri['C'])) === 1,
        'PN-21 filter kamar menemukan santri berkonflik dari sisi kamar kedua'
    );
    $exec('DELETE FROM plotting_kamar WHERE id = ?', [$konflikBaris]);

    // ================================================================ PN-22
    $auditSebelumTetap = $angka('SELECT COUNT(*) n FROM audit_logs');
    $tanpaPerubahan = $service->apply(PenempatanService::AKSI_KAMAR_TETAPKAN, [$santri['C'], $santri['A']], ['kamar_id' => $kamarA], $adminId);
    $assert(
        $tanpaPerubahan['diterapkan'] === 0
        && $angka('SELECT COUNT(*) n FROM audit_logs') === $auditSebelumTetap,
        'PN-22 tindakan massal yang tidak mengubah apa pun tidak meninggalkan jejak audit'
    );

    // ================================================================ PN-23
    $tahunLain = $exec("INSERT INTO tahun_ajaran (tahun, semester, status, created_at, updated_at) VALUES (?, 'Ganjil', 'Non-Aktif', NOW(), NOW())", ['2018/20' . substr($suffix, 0, 2)]);
    $dibuat['tahun'][] = $tahunLain;
    $barisTahunLain = $exec('INSERT INTO plotting_kamar (id_santri, id_kamar, id_tahun) VALUES (?, ?, ?)', [$santri['C'], $kamarA, $tahunLain]);
    $service->apply(PenempatanService::AKSI_KAMAR_TETAPKAN, [$santri['C']], ['kamar_id' => $kamarB], $adminId);
    $barisLamaSesudah = $satu("SELECT id_kamar, id_tahun FROM plotting_kamar WHERE id = {$barisTahunLain}");
    $assert(
        (int) ($barisLamaSesudah['id_kamar'] ?? 0) === $kamarA && (int) ($barisLamaSesudah['id_tahun'] ?? 0) === $tahunLain,
        'PN-23 perpindahan kamar tidak menyentuh baris tahun ajaran lain'
    );

    // ================================================================ PN-24
    // Formulir massal mengirim satu tanggal untuk kelas maupun kamar; tanggal
    // yang tidak dipakai tidak boleh memblokir perpindahan kamar.
    $service->apply(PenempatanService::AKSI_KAMAR_TETAPKAN, [$santri['C']], ['kamar_id' => $kamarA, 'tanggal_mulai' => '06/09/2026'], $adminId);
    $assert(
        (int) ($satu("SELECT id_kamar FROM plotting_kamar WHERE id_santri = {$santri['C']} AND id_tahun = {$yearId}")['id_kamar'] ?? 0) === $kamarA,
        'PN-24 tanggal tidak valid pada tindakan kamar diabaikan, bukan memblokir'
    );
    $tolak(
        static fn () => $service->apply(PenempatanService::AKSI_KELAS_TETAPKAN, [$santri['C']], ['kelas_id' => $kelasA, 'tanggal_mulai' => '06/09/2026'], $adminId),
        'PN-24 tanggal tidak valid pada tindakan KELAS tetap ditolak'
    );
} catch (Throwable $exception) {
    $assert(false, 'Pengujian berhenti karena galat tak terduga: ' . $exception->getMessage() . ' @ ' . $exception->getFile() . ':' . $exception->getLine());
} finally {
    // ------------------------------------------------------------ bersih-bersih
    try {
        if ($tahunLamaDinonaktifkan && isset($yearId)) {
            $db->query("UPDATE tahun_ajaran SET status = 'Aktif' WHERE id = " . (int) $yearId);
        }
        if ($db->query("SHOW TABLES LIKE 'audit_logs_pn_" . $kecil . "'")?->num_rows) {
            $db->query('RENAME TABLE audit_logs_pn_' . $kecil . ' TO audit_logs');
        }
        foreach ($dibuat['santri'] as $id) {
            $db->query('DELETE FROM pertemuan_peserta WHERE santri_id = ' . (int) $id);
            $db->query('DELETE FROM plotting_kamar WHERE id_santri = ' . (int) $id);
            $db->query('DELETE FROM plotting_kelas WHERE id_santri = ' . (int) $id);
        }
        foreach ($dibuat['pertemuan'] as $id) {
            $db->query('DELETE FROM pertemuan_peserta WHERE pertemuan_id = ' . (int) $id);
            $db->query('DELETE FROM pertemuan_pengajian WHERE id = ' . (int) $id);
        }
        foreach ($dibuat['jadwal'] as $id) {
            $db->query('DELETE FROM jadwal_ngaji WHERE id = ' . (int) $id);
        }
        foreach ($dibuat['murobi'] as $id) {
            $db->query('DELETE FROM murobi_assignments WHERE id = ' . (int) $id);
        }
        foreach ($dibuat['santri'] as $id) {
            $db->query('DELETE FROM santri WHERE id = ' . (int) $id);
        }
        foreach ($dibuat['kelas'] as $id) {
            $db->query('DELETE FROM plotting_kelas WHERE id_kelas = ' . (int) $id);
            $db->query('DELETE FROM kelas WHERE id = ' . (int) $id);
        }
        foreach ($dibuat['kamar'] as $id) {
            $db->query('DELETE FROM plotting_kamar WHERE id_kamar = ' . (int) $id);
            $db->query('DELETE FROM kamar WHERE id = ' . (int) $id);
        }
        foreach ($dibuat['guru'] as $id) {
            $db->query('DELETE FROM guru WHERE id = ' . (int) $id);
        }
        foreach ($dibuat['tahun'] as $id) {
            $db->query('DELETE FROM plotting_kamar WHERE id_tahun = ' . (int) $id);
            $db->query('DELETE FROM tahun_ajaran WHERE id = ' . (int) $id);
        }
        foreach ($dibuat['users'] as $id) {
            $db->query('DELETE FROM user_roles WHERE user_id = ' . (int) $id);
            $db->query('DELETE FROM audit_logs WHERE actor_user_id = ' . (int) $id);
            $db->query('DELETE FROM users WHERE id = ' . (int) $id);
        }
    } catch (Throwable $exception) {
        echo '[gagal] Bersih-bersih fixture: ' . $exception->getMessage() . PHP_EOL;
        $failures[] = 'bersih-bersih fixture';
    }
}

echo PHP_EOL;
if ($failures === []) {
    echo 'SELURUH PENGUJIAN INTEGRASI PENEMPATAN LULUS.' . PHP_EOL;
    exit(0);
}
echo 'GAGAL (' . count($failures) . '):' . PHP_EOL . ' - ' . implode(PHP_EOL . ' - ', $failures) . PHP_EOL;
exit(1);
