<?php

declare(strict_types=1);

/**
 * Uji integrasi perbaikan peserta absensi (6 November 2026) lewat layanan
 * resmi web (ScheduleService, MasterDataService) dan REST API (TeacherService):
 *
 *   JKG_DB=test_ppa_test bash tests/jadwal_kelas_gabungan_f1_db.sh
 *   PPA_DB=test_ppa_test php tests/perbaikan_peserta_absensi_integration.php
 *
 *   P-1 pertemuan baru (web/API) tidak memuat santri nonaktif/arsip walau
 *       penempatan kelasnya masih Aktif (data lama)
 *   P-2 Nonaktifkan/Arsipkan di Data Santri menutup penempatan kelas Aktif
 *       (riwayat tetap, audit berisi ID penempatan); Aktifkan tidak membukanya
 *   P-3 jumlah anggota Data Kelas hanya santri aktif
 *   P-4 "Perbarui daftar peserta" (web): tambah santri baru, keluarkan santri
 *       keluar/pindah, pertahankan yang sudah berabsensi, kelas pertemuan tetap
 *       yang dibekukan, pengulangan tanpa perubahan, audit tercatat
 *   P-5 hanya pertemuan Dibuka; guru lain ditolak
 *   P-6 API: respons + `participant_changes`; simpan absensi memakai daftar baru,
 *       daftar lama ditolak 422
 *   P-7 alat rapikan data: laporan tidak menulis, --terapkan menutup, ulangan kosong
 *
 * Mengubah data uji; bangun ulang DB sebelum mengulang.
 */

use App\Api\ApiException;
use App\Schedule\ScheduleException;

$root = dirname(__DIR__);
$dbName = getenv('PPA_DB') ?: 'test_ppa_test';
if (!preg_match('/^test_.*_test$/', $dbName)) {
    fwrite(STDERR, "DB uji harus bernama test_*_test.\n");
    exit(2);
}
putenv('DB_NAME=' . $dbName);
require_once $root . '/app/bootstrap.php';

$lulus = 0;
$gagal = [];
$assert = static function (bool $ok, string $pesan) use (&$lulus, &$gagal): void {
    echo ($ok ? '[lulus] ' : '[gagal] ') . $pesan . PHP_EOL;
    $ok ? $lulus++ : $gagal[] = $pesan;
};
$db = app_db();
$satu = static function (string $sql) use ($db): mixed {
    $r = $db->query($sql);
    if ($r === false) {
        throw new RuntimeException($db->error . ' :: ' . $sql);
    }
    return $r->fetch_row()[0] ?? null;
};
$kolom = static function (string $sql) use ($db): array {
    $r = $db->query($sql);
    if ($r === false) {
        throw new RuntimeException($db->error . ' :: ' . $sql);
    }
    $ids = array_map(static fn (array $x): int => (int) $x[0], $r->fetch_all());
    sort($ids);
    return $ids;
};
$galat = static function (callable $aksi): ?Throwable {
    try {
        $aksi();
        return null;
    } catch (ScheduleException|ApiException $e) {
        return $e;
    }
};
$urut = static function (array $ids): array {
    $ids = array_map('intval', $ids);
    sort($ids);
    return $ids;
};

$ids = require __DIR__ . '/jadwal_kelas_gabungan_f1_seed.php';
$k = $ids['kelas'];
$sn = $ids['santri'];
$admin = ['id' => $ids['admin_id'], 'roles' => ['admin'], 'guru_id' => null, 'name' => 'Admin uji'];
$guru = ['id' => $ids['guru_user_id'], 'roles' => ['guru'], 'guru_id' => $ids['guru_g'], 'name' => 'Guru uji'];
$guruLain = ['id' => $ids['admin_id'], 'roles' => ['guru'], 'guru_id' => $ids['guru_h'], 'name' => 'Guru lain'];
$s = schedule_service();
$api = teacher_api_service();
$master = master_data_service();
$tanggal = static fn (int $minggu): string => (new DateTimeImmutable('next thursday'))->modify('+' . (7 * $minggu) . ' days')->format('Y-m-d');
$dasar = ['id_tahun' => $ids['tahun'], 'waktu_sholat' => "Ba'da Isya", 'hari' => 'Kamis', 'waktu_mulai' => '20:00', 'waktu_selesai' => '20:45',
    'fan_ilmu' => 'Nahwu ' . $ids['sfx'], 'nama_kitab' => 'Jurumiyah', 'id_guru' => $ids['guru_g'], 'tempat' => 'Ruang ' . $ids['sfx']];
$peserta = static fn (int $pid): array => $kolom("SELECT santri_id FROM pertemuan_peserta WHERE pertemuan_id = $pid");
$aktifDi = static fn (int $sid): array => $kolom("SELECT id FROM plotting_kelas WHERE id_santri = $sid AND status = 'Aktif'");
$buatSantri = static function (string $label) use ($master, $ids): int {
    static $n = 100;
    $n++;
    return $master->saveSantri(['nis' => 'PPA' . $ids['sfx'] . $n, 'nama_santri' => 'Santri ' . $label . ' ' . $ids['sfx'], 'jenis_kelamin' => 'L',
        'tempat_lahir' => 'Ciamis', 'tgl_lahir' => '2012-02-02', 'alamat' => '', 'desa' => '', 'kecamatan' => '', 'kab_kota' => '', 'provinsi' => '',
        'nama_ayah' => '', 'no_hp_ayah' => '', 'nama_ibu' => '', 'no_hp_ibu' => '', 'asal_sekolah' => '', 'sekolah_saat_ini' => '', 'foto' => 'default.jpg']);
};
$tempatkan = static function (int $sid, int $kelas) use ($master, $ids): void {
    $master->assignActiveClass(['santri_id' => $sid, 'kelas_id' => $kelas, 'tanggal_mulai' => date('Y-m-d')], $ids['admin_id']);
};

// Jadwal: C satu kelas (guru G), PA+PI gabungan (guru G, jam lain).
$SC = (int) $s->save(array_replace($dasar, ['id_kelas' => $k['C']]), $ids['admin_id'])['id'];
$SG = (int) $s->save(array_replace($dasar, ['id_kelas' => $k['PA'], 'kelas_gabungan' => [(string) $k['PI']], 'waktu_mulai' => '21:00', 'waktu_selesai' => '21:45']), $ids['admin_id'])['id'];

echo "=== P-1 pertemuan baru hanya memuat santri aktif ===\n";
// Data lama: santri dinonaktifkan langsung (seperti sebelum perbaikan) — penempatan masih Aktif.
$keluarLama = $sn['C'][0];
$db->query("UPDATE santri SET is_active = 0 WHERE id = $keluarLama");
$arsipLama = $sn['C'][1];
$db->query("UPDATE santri SET is_active = 0, archived_at = NOW() WHERE id = $arsipLama");
$aktifC = $buatSantri('C aktif');
$tempatkan($aktifC, $k['C']);
$assert($aktifDi($keluarLama) !== [] && $aktifDi($arsipLama) !== [], 'Fixture: dua santri nonaktif/arsip masih punya penempatan kelas Aktif');
$m1 = $s->open($SC, $tanggal(1), '', $admin);
$assert($peserta($m1) === [$aktifC], 'Web: pertemuan baru hanya memuat santri aktif (nonaktif & arsip tidak ikut)');
$apiM = $api->openMeeting($SC, ['date' => $tanggal(2), 'idempotency_key' => 'ppa-open-' . $ids['sfx']], $guru)['data'];
$assert(array_column($apiM['students'], 'student_id') === [$aktifC], 'API: pertemuan baru hanya memuat santri aktif');

echo "\n=== P-2 Nonaktifkan/Arsipkan menutup penempatan kelas ===\n";
$sNon = $sn['PA'][0];
$plotSebelum = $aktifDi($sNon);
$master->setSantriState($sNon, 'deactivate');
$assert($aktifDi($sNon) === [], 'Nonaktifkan: penempatan kelas Aktif ditutup');
$assert((string) $satu('SELECT status FROM plotting_kelas WHERE id = ' . $plotSebelum[0]) === 'Selesai'
    && (string) $satu('SELECT tanggal_selesai FROM plotting_kelas WHERE id = ' . $plotSebelum[0]) === date('Y-m-d'), 'Baris penempatan tetap ada sebagai riwayat (Selesai, tanggal hari ini)');
$auditNon = json_decode((string) $satu("SELECT after_json FROM audit_logs WHERE action = 'master.deactivate' AND entity_type = 'santri' AND entity_id = $sNon ORDER BY id DESC LIMIT 1"), true);
$assert(($auditNon['penempatan_kelas_ditutup'] ?? null) === $plotSebelum, 'Audit Nonaktifkan memuat ID penempatan yang ditutup');
$master->setSantriState($sNon, 'activate');
$assert($aktifDi($sNon) === [] && (int) $satu("SELECT is_active FROM santri WHERE id = $sNon") === 1, 'Aktifkan kembali tidak membuka penempatan otomatis');
$sArsip = $sn['PA'][1];
$master->setSantriState($sArsip, 'archive');
$assert($aktifDi($sArsip) === [] && $satu("SELECT archived_at FROM santri WHERE id = $sArsip") !== null, 'Arsipkan: santri diarsipkan dan penempatan kelas ditutup');
$assert((int) $satu("SELECT COUNT(*) FROM plotting_kelas WHERE id_santri = $sArsip") >= 1, 'Arsipkan tidak menghapus baris penempatan');

echo "\n=== P-3 jumlah anggota Data Kelas ===\n";
$hitung = static function (int $kelasId) use ($master): ?int {
    $hasil = $master->classesPage('', 1, 'all');
    foreach (($hasil['rows'] ?? $hasil['data'] ?? $hasil) as $row) {
        if (is_array($row) && (int) ($row['id'] ?? 0) === $kelasId) {
            return (int) $row['anggota'];
        }
    }
    return null;
};
// Kelas C: penempatan Aktif = keluarLama, arsipLama (data lama) dan $aktifC; hanya $aktifC yang aktif.
$assert($hitung($k['C']) === 1, 'Data Kelas C menghitung hanya santri aktif (1), bukan 3 penempatan Aktif');

echo "\n=== P-4 Perbarui daftar peserta (web) ===\n";
$m2 = $s->open($SG, $tanggal(1), '', $admin);
// PA: sn PA[2] aktif (PA[0] ditutup, PA[1] diarsip); PI: sn PI[0], PI[1].
$assert($peserta($m2) === $urut([$sn['PA'][2], $sn['PI'][0], $sn['PI'][1]]), 'Pertemuan gabungan dibuka dengan 3 peserta aktif');
$baruPA = $buatSantri('PA baru');
$tempatkan($baruPA, $k['PA']);
$master->setSantriState($sn['PI'][0], 'deactivate'); // keluar
$sudahAbsen = $sn['PI'][1];
$master->endActiveClass($sudahAbsen); // pindah/keluar kelas…
// …tetapi absensinya sudah tercatat pada pertemuan ini.
$db->query("INSERT INTO absensi_santri (pertemuan_id, santri_id, status, dicatat_pada, dicatat_oleh, created_at, updated_at) VALUES ($m2, $sudahAbsen, 'Hadir', NOW(), {$ids['admin_id']}, NOW(), NOW())");
// Kelas C ditambahkan ke jadwal SESUDAH dibuka: tidak boleh ikut pertemuan ini.
$s->save(array_replace($dasar, ['id_kelas' => $k['PA'], 'kelas_gabungan' => [(string) $k['PI'], (string) $k['C']], 'waktu_mulai' => '21:00', 'waktu_selesai' => '21:45']), $ids['admin_id'], $SG);
$hasil = $s->refreshParticipants($m2, $admin);
$assert($hasil['ditambah'] === [$baruPA], 'Santri baru di kelas pertemuan ditambahkan');
$assert($hasil['dikeluarkan'] === [$sn['PI'][0]], 'Santri yang keluar (tanpa absensi) dikeluarkan');
$assert($hasil['dipertahankan'] === [$sudahAbsen], 'Santri yang sudah berabsensi tetap tercantum');
$assert($peserta($m2) === $urut([$sn['PA'][2], $baruPA, $sudahAbsen]), 'Peserta akhir sesuai; santri kelas C (ditambahkan ke jadwal sesudah dibuka) tidak ikut');
$assert((int) $satu("SELECT kelas_id_snapshot FROM pertemuan_peserta WHERE pertemuan_id = $m2 AND santri_id = $baruPA") === $k['PA'], 'Kelas asal peserta baru tersimpan');
$assert((int) $satu("SELECT COUNT(*) FROM absensi_santri WHERE pertemuan_id = $m2 AND santri_id = $sudahAbsen") === 1, 'Absensi yang sudah tercatat tidak dihapus');
$ulang = $s->refreshParticipants($m2, $admin);
$assert($ulang['ditambah'] === [] && $ulang['dikeluarkan'] === [] && $ulang['dipertahankan'] === [$sudahAbsen], 'Pengulangan tidak mengubah apa pun');
$aud = $satu("SELECT after_json FROM audit_logs WHERE action = 'meeting.refresh_participants' AND entity_id = $m2 ORDER BY id LIMIT 1");
$aud = json_decode((string) $aud, true);
$assert(($aud['ditambah'] ?? null) === [$baruPA] && ($aud['participant_count'] ?? null) === 3, 'Audit web mencatat perubahan dan jumlah peserta');

echo "\n=== P-5 hanya Dibuka; guru lain ditolak ===\n";
$draf = $s->createDraft($SC, $tanggal(3), '', $admin);
$e = $galat(fn () => $s->refreshParticipants($draf, $admin));
$assert($e !== null && str_contains($e->getMessage(), 'Dibuka'), 'Pertemuan Draf ditolak');
$s->complete($m1, $admin);
$e = $galat(fn () => $s->refreshParticipants($m1, $admin));
$assert($e !== null && str_contains($e->getMessage(), 'Dibuka'), 'Pertemuan Selesai ditolak (riwayat tidak diubah)');
$e = $galat(fn () => $api->refreshParticipants($m1, $guru));
$assert($e instanceof ApiException && str_contains($e->getMessage(), 'Dibuka'), 'API: pertemuan Selesai ditolak');
$e = $galat(fn () => $s->refreshParticipants($m2, $guruLain));
$assert($e !== null, 'Web: guru lain tidak dapat memperbarui pertemuan bukan miliknya');
$e = $galat(fn () => $api->refreshParticipants((int) $apiM['id'], $guruLain));
$assert($e instanceof ApiException, 'API: guru lain ditolak');
$e = $galat(fn () => $api->refreshParticipants(999999999, $guru));
$assert($e instanceof ApiException && str_contains($e->getMessage(), 'tidak ditemukan'), 'API: pertemuan tidak ada → tidak ditemukan');

echo "\n=== P-6 API refresh + simpan absensi ===\n";
$mApi = (int) $apiM['id'];
$lama = array_column($apiM['students'], 'student_id');
$baruC = $buatSantri('C baru');
$tempatkan($baruC, $k['C']);
$master->setSantriState($aktifC, 'archive');
$r = $api->refreshParticipants($mApi, $guru);
$assert($r['participant_changes'] === ['added' => [$baruC], 'removed' => [$aktifC], 'kept_with_attendance' => []], 'API participant_changes tepat');
$assert(array_column($r['students'], 'student_id') === [$baruC] && $r['status'] === 'Dibuka' && isset($r['task']['classes']), 'API mengembalikan isi pertemuan terbaru (bentuk sama dengan GET)');
$e = $galat(fn () => $api->saveAttendance($mApi, ['idempotency_key' => 'ppa-old-' . $ids['sfx'], 'teacher' => ['status' => 'Hadir'],
    'students' => array_map(static fn (int $id): array => ['student_id' => $id, 'status' => 'Hadir'], $lama)], $guru));
$assert($e instanceof ApiException && str_contains($e->getMessage(), 'snapshot'), 'Simpan dengan daftar lama ditolak (daftar harus sama dengan peserta)');
$simpan = $api->saveAttendance($mApi, ['idempotency_key' => 'ppa-new-' . $ids['sfx'], 'teacher' => ['status' => 'Hadir'],
    'students' => [['student_id' => $baruC, 'status' => 'Hadir']]], $guru)['data'];
$assert($simpan['status'] === 'Selesai' && count($simpan['students']) === 1, 'Simpan dengan daftar baru berhasil dan pertemuan selesai');
$assert((int) $satu("SELECT COUNT(*) FROM audit_logs WHERE action = 'api.meeting.refresh_participants' AND entity_id = $mApi") === 1, 'Audit API tercatat');

echo "\n=== P-7 alat rapikan data lama ===\n";
$sebelum = $aktifDi($keluarLama);
$cmd = 'DB_NAME=' . escapeshellarg($dbName) . ' php ' . escapeshellarg($root . '/bin/tutup_penempatan_santri_nonaktif.php');
exec($cmd . ' 2>&1', $out, $kode);
$teks = implode("\n", $out);
$assert($kode === 0 && str_contains($teks, 'LAPORAN SAJA') && str_contains($teks, 'santri #' . $keluarLama) && $aktifDi($keluarLama) === $sebelum, 'Laporan saja: mendaftar santri tanpa menulis');
$out = [];
exec($cmd . ' --terapkan 2>&1', $out, $kode);
$assert($kode === 0 && $aktifDi($keluarLama) === [] && $aktifDi($arsipLama) === [], '--terapkan menutup penempatan santri nonaktif dan arsip');
$assert((int) $satu("SELECT COUNT(*) FROM plotting_kelas pk JOIN santri s ON s.id = pk.id_santri WHERE pk.status = 'Aktif' AND (s.is_active = 0 OR s.archived_at IS NOT NULL)") === 0, 'Tidak ada lagi santri nonaktif/arsip dengan penempatan Aktif');
$assert((int) $satu("SELECT COUNT(*) FROM audit_logs WHERE action = 'master.santri_penempatan_ditutup' AND entity_id = $keluarLama") === 1, 'Audit alat rapikan tercatat per santri');
$assert((int) $satu("SELECT is_active FROM santri WHERE id = $keluarLama") === 0, 'Status santri tidak diubah alat rapikan');
$out = [];
exec($cmd . ' --terapkan 2>&1', $out, $kode);
$assert($kode === 0 && str_contains(implode("\n", $out), 'Tidak ada santri'), 'Ulangan: tidak ada yang perlu ditutup');

echo PHP_EOL . "Hasil: {$lulus} lulus, " . count($gagal) . " gagal" . PHP_EOL;
foreach ($gagal as $pesan) {
    echo " - {$pesan}" . PHP_EOL;
}
exit($gagal === [] ? 0 : 1);
