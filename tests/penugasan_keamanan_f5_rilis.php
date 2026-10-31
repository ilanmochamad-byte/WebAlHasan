<?php

declare(strict_types=1);

/**
 * Uji kesiapan rilis penugasan lintas guru/pengurus dan Keamanan (Fase 5) pada
 * SALINAN database uji.
 *
 *   PK_RUN_RILIS=1 php tests/penugasan_keamanan_f5_rilis.php
 *
 * Langkah (DB `test_*_test` tersendiri, dibangun ulang):
 *   R-1  salinan 031 berisi data V1–V7, tanpa 032–033 (titik awal rilis yang
 *        sesungguhnya); preflight lulus dan menulis manifest privat 0600;
 *        `--rilis` mencocokkan 33 migrasi + 2 rollback dengan manifest
 *        ter-commit; manifest diubah/terpotong ditolak;
 *   R-2  jam basis data ≠ jam aplikasi ditolak; migrasi sesudah 033 ditolak;
 *   R-3  `migrate up` memasang 032–033 sekaligus, `up` ulang kosong; verifier +
 *        manifest pra-migrasi lulus (jumlah dan isi seluruh tabel lama, irisan
 *        baca; `pengurus` tanpa kolom baru);
 *   R-4  pemasangan sebagian (032 tanpa 033) ditolak preflight dan verifier;
 *   R-5  turun tanpa data (033 → 032, preflight rollback lulus) mengembalikan
 *        snapshot pra-migrasi persis; naik lagi lulus — naik–turun–naik;
 *   R-6  dengan data: identitas guru tanpa penugasan Keamanan mengizinkan
 *        rollback 033 tetapi rollback 032 DITOLAK (preflight + penjaga SQL);
 *        sesudah penugasan Keamanan, rollback 033 DITOLAK; data dan catatan
 *        migrasi tetap, tidak ada tabel penjaga tersisa;
 *   R-7  latihan backup → restore → verifikasi → rollback ditolak, dua metode
 *        (BackupWriter aplikasi dan mariadb-dump runbook);
 *   R-8  kontrol pengrusakan: verifier mendeteksi identitas tidak sinkron/
 *        dipegang akun lain/berjabatan lain, audit pembentukan atau penugasan
 *        yang dihapus, audit `keamanan.*` berisi isi atau berpelaku bukan
 *        Keamanan, keputusan izin oleh pengaju, dan perubahan isi tabel lama.
 */

if (getenv('PK_RUN_RILIS') !== '1') {
    fwrite(STDERR, "Set PK_RUN_RILIS=1 untuk menjalankan.\n");
    exit(2);
}
$root = dirname(__DIR__);
$dbA = getenv('PKF5_RILIS_DB') ?: 'test_pk_f5_rilis_test';
if (!preg_match('/^test_.*_test$/', $dbA)) {
    exit(2);
}

$lulus = 0;
$gagal = [];
$assert = static function (bool $ok, string $pesan) use (&$lulus, &$gagal): void {
    echo ($ok ? '[lulus] ' : '[gagal] ') . $pesan . PHP_EOL;
    $ok ? $lulus++ : $gagal[] = $pesan;
};
/** @return array{0:int, 1:string} */
$sh = static function (string $perintah, string $db, array $env = []) use ($root): array {
    $proc = proc_open(['bash', '-c', $perintah], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, array_merge(getenv(), ['DB_NAME' => $db], $env));
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    $kode = proc_close($proc);

    return [$kode, (string) $out];
};
[$kode] = $sh('PKF5_TANPA_PK=1 PKF5_DB=' . $dbA . ' bash tests/penugasan_keamanan_f5_db.sh', $dbA);
putenv('DB_NAME=' . $dbA);
require_once $root . '/app/bootstrap.php';
require_once $root . '/bin/_penugasan_keamanan_rilis.php';
mysqli_report(MYSQLI_REPORT_OFF);
$a = new mysqli((string) app_config('database.host'), (string) app_config('database.username'), (string) app_config('database.password'), $dbA, (int) app_config('database.port'));
$a->set_charset('utf8mb4');
$scratch = sys_get_temp_dir() . '/pkf5-rilis-' . bin2hex(random_bytes(3));
mkdir($scratch, 0700);
$n = static fn (string $sql): int => (int) $a->query($sql)->fetch_row()[0];
$q = static function (string $sql) use ($a): void {
    if ($a->query($sql) === false) {
        throw new RuntimeException($a->error . ' :: ' . $sql);
    }
};
$terakhir = static fn (): string => (string) $a->query('SELECT migration FROM schema_migrations ORDER BY id DESC LIMIT 1')->fetch_row()[0];
$adaGuruId = static fn (): bool => $n("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pengurus' AND COLUMN_NAME = 'guru_id'") === 1;
$adaKeamanan = static fn (): bool => $n("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'keamanan_assignments'") === 1;
$penjaga = static fn (): int => $n("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE '%rollback\\_guard%'");
$snapshot = static function (string $berkas): array {
    $m = json_decode((string) file_get_contents($berkas), true);

    return [$m['legacy_counts'] ?? null, $m['isi_tabel_lama'] ?? null, $m['irisan_baca'] ?? null];
};
$verifikasi = static fn (string $arg = ''): array => $sh('php bin/penugasan_keamanan_verify.php ' . $arg, $dbA);
$segarkan = static function (): void {
    \App\Database\Schema::forget();
};

// ===================================================================== R-1
echo PHP_EOL . '=== R-1. Titik awal 031, preflight dan manifest rilis ===' . PHP_EOL;
$assert($kode === 0 && $terakhir() === PK_PRASYARAT && !$adaGuruId() && !$adaKeamanan(), 'Salinan 031 (V7 Fase 2 terakhir), tanpa kolom pengurus.guru_id dan tabel keamanan_assignments: ' . $dbA);
$assert($n('SELECT COUNT(*) FROM izin_pengajuan') > 0 && $n('SELECT COUNT(*) FROM izin_keputusan') > 0 && $n('SELECT COUNT(*) FROM v3_pelanggaran') > 0
    && $n('SELECT COUNT(*) FROM pengurus') > 0 && $n('SELECT COUNT(*) FROM users WHERE guru_id IS NOT NULL AND pengurus_id IS NOT NULL') === 1,
    'Data lama tersedia: izin V2/V7, keputusan, pembinaan V3, pengurus, dan satu akun ganda warisan');
$pre = $scratch . '/manifest-pra.json';
[$kode, $out] = $sh('php bin/penugasan_keamanan_preflight.php ' . escapeshellarg($pre) . ' --rilis=docs/penugasan-keamanan/fase-5/manifest-migrasi.json', $dbA);
$m = json_decode((string) @file_get_contents($pre), true);
$assert($kode === 0 && str_contains($out, 'Preflight penugasan Keamanan: lulus.') && ($m['pk_terpasang'] ?? null) === array_fill_keys(PK_MIGRASI, false)
    && ($m['isi_pk'] ?? null) === [] && (fileperms($pre) & 0777) === 0600 && ($m['irisan_baca']['pengurus_biasa']['baris'] ?? 0) === $n('SELECT COUNT(*) FROM pengurus'),
    'Preflight sebelum migrasi lulus; manifest privat 0600 tanpa isi 032/033, irisan memuat seluruh pengurus (semuanya biasa)');
$assert(substr_count($out, 'SHA-256 sesuai manifest rilis') === 35 && !str_contains($out, '[gagal]'), 'Preflight --rilis: 33 migrasi + 2 rollback = manifest ter-commit (35 SHA cocok)');
$assert(str_contains($out, '[info] Guru aktif yang namanya sama dengan pengurus biasa aktif') && str_contains($out, '[info] Akun guru yang memegang pengurus biasa (akun ganda warisan): 1'),
    'Preflight mencetak data ganda nama dan akun ganda warisan untuk ditinjau');
$rilis = json_decode((string) file_get_contents($root . '/docs/penugasan-keamanan/fase-5/manifest-migrasi.json'), true);
$ubah = $rilis;
$ubah['rollbacks'][1]['sha256'] = str_repeat('0', 64);
file_put_contents($scratch . '/rilis-ubah.json', json_encode($ubah));
[$kode, $out] = $sh('php bin/penugasan_keamanan_preflight.php --rilis=' . escapeshellarg($scratch . '/rilis-ubah.json'), $dbA);
$assert($kode === 1 && str_contains($out, '[gagal] SHA-256 sesuai manifest rilis: database/rollbacks/033_penugasan_keamanan_fase3_keamanan.sql'), 'Manifest rilis dengan hash rollback 033 berbeda ditolak');
$potong = $rilis;
array_pop($potong['migrations']);
file_put_contents($scratch . '/rilis-potong.json', json_encode($potong));
[$kode, $out] = $sh('php bin/penugasan_keamanan_preflight.php --rilis=' . escapeshellarg($scratch . '/rilis-potong.json'), $dbA);
$assert($kode === 1 && str_contains($out, '[gagal] Daftar lengkap dan unik manifest rilis: migrations'), 'Manifest rilis yang daftar migrasinya terpotong (tanpa 033) ditolak walau hash sisanya cocok');

// ===================================================================== R-2
echo PHP_EOL . '=== R-2. Jam basis data dan migrasi asing ===' . PHP_EOL;
[$kode, $out] = $sh('php bin/penugasan_keamanan_preflight.php', $dbA, ['APP_TIMEZONE' => 'Pacific/Kiritimati']);
$assert($kode === 1 && preg_match('/\[gagal\] Jam basis data .* selisih \d+ detik/', $out) === 1, 'APP_TIMEZONE berbeda dengan zona waktu DB → preflight gagal (tanggal penugasan aktif bergeser)');
$q("INSERT INTO schema_migrations (migration) VALUES ('034_contoh_asing.sql')");
[$kode, $out] = $sh('php bin/penugasan_keamanan_preflight.php', $dbA);
$q("DELETE FROM schema_migrations WHERE migration = '034_contoh_asing.sql'");
$assert($kode === 1 && str_contains($out, '[gagal] Tidak ada migrasi sesudah 033 yang tercatat'), 'Migrasi sesudah 033 yang tercatat ditolak preflight');

// ===================================================================== R-3
echo PHP_EOL . '=== R-3. Migrasi 032–033 sekaligus, verifier + manifest ===' . PHP_EOL;
[$kode, $out] = $sh('php bin/migrate.php up', $dbA);
$segarkan();
$assert($kode === 0 && preg_match_all('/^- 03[23]_penugasan_keamanan_/m', $out) === 2 && preg_match_all('/^- /m', $out) === 2 && $terakhir() === PK_MIGRASI[1]
    && $adaGuruId() && $adaKeamanan(), 'migrate up memasang 032 dan 033 (hanya keduanya)');
[$kode, $out] = $sh('php bin/migrate.php up', $dbA);
$assert($kode === 0 && preg_match_all('/^- /m', $out) === 0 && $terakhir() === PK_MIGRASI[1], 'migrate up ulang tidak memasang migrasi baru');
$assert($n('SELECT COUNT(*) FROM pengurus WHERE guru_id IS NOT NULL') === 0 && $n('SELECT COUNT(*) FROM keamanan_assignments') === 0, 'Migrasi tidak mengisi data: tanpa identitas guru dan penugasan Keamanan');
[$kode, $out] = $verifikasi(escapeshellarg($pre));
$assert($kode === 0 && preg_match('/Ringkasan verifier penugasan Keamanan: \\d+ lulus, 0 gagal/', $out) === 1 && str_contains($out, '[lulus] Isi isi_tabel_lama ('),
    'Verifier + manifest pra-migrasi lulus: jumlah dan isi seluruh tabel lama, irisan baca, `pengurus` tanpa kolom baru');
$post = $scratch . '/manifest-pasca.json';
[$kode] = $sh('php bin/penugasan_keamanan_preflight.php ' . escapeshellarg($post), $dbA);
$assert($kode === 0 && json_decode((string) file_get_contents($post), true)['pk_terpasang'] === array_fill_keys(PK_MIGRASI, true), 'Preflight sesudah migrasi tetap lulus (aman diulang pada salinan pulihan)');

// ===================================================================== R-4
echo PHP_EOL . '=== R-4. Pemasangan sebagian ditolak ===' . PHP_EOL;
[$kode, $out] = $sh('php bin/penugasan_keamanan_rollback_preflight.php', $dbA);
$assert($kode === 0 && str_contains($out, 'Rollback 033 diizinkan: keamanan_assignments kosong.'), 'Preflight rollback 033 tanpa data: diizinkan');
[$kode] = $sh('php bin/migrate.php rollback', $dbA);
$segarkan();
$assert($kode === 0 && $terakhir() === PK_MIGRASI[0] && !$adaKeamanan() && $adaGuruId(), 'Rollback 033 tanpa data melepas keamanan_assignments; 032 tetap');
[$kode, $out] = $sh('php bin/penugasan_keamanan_preflight.php', $dbA);
$assert($kode === 1 && str_contains($out, '[gagal] Tidak ada pemasangan sebagian 032–033'), 'Preflight menolak 032 tanpa 033 (lanjutkan ke 033 atau pulihkan backup)');
[$kode, $out] = $verifikasi();
$assert($kode === 1 && str_contains($out, '[gagal] Migrasi 032 dan 033 tercatat (rilis utuh, bukan sebagian)'), 'Verifier menolak rilis sebagian');

// ===================================================================== R-5
echo PHP_EOL . '=== R-5. Naik–turun–naik tanpa data ===' . PHP_EOL;
[$kode, $out] = $sh('php bin/penugasan_keamanan_rollback_preflight.php', $dbA);
$assert($kode === 0 && str_contains($out, 'Rollback 032 diizinkan: belum ada identitas pengurus milik guru.'), 'Preflight rollback 032 tanpa identitas: diizinkan');
[$kode] = $sh('php bin/migrate.php rollback', $dbA);
$segarkan();
$turun = $scratch . '/manifest-turun.json';
$sh('php bin/penugasan_keamanan_preflight.php ' . escapeshellarg($turun), $dbA);
$assert($kode === 0 && $terakhir() === PK_PRASYARAT && !$adaGuruId() && $snapshot($turun) === $snapshot($pre) && $penjaga() === 0,
    'Turun ke 031: snapshot (jumlah, isi seluruh tabel lama, irisan baca) persis sama dengan pra-migrasi, tanpa tabel penjaga');
[$kode, $out] = $sh('php bin/migrate.php up', $dbA);
$segarkan();
[$kode2, $out2] = $verifikasi(escapeshellarg($pre));
$assert($kode === 0 && $kode2 === 0 && $terakhir() === PK_MIGRASI[1] && str_contains($out2, '[lulus] Isi isi_tabel_lama ('), 'Naik lagi: verifier + manifest pra-migrasi lulus');

// ===================================================================== R-6
echo PHP_EOL . '=== R-6. Penjaga rollback dengan data ===' . PHP_EOL;
$admin = (int) $a->query("SELECT id FROM users WHERE username = 'sbx_admin'")->fetch_row()[0];
$tahun = (int) $a->query("SELECT id FROM tahun_ajaran WHERE status = 'Aktif' AND archived_at IS NULL ORDER BY id DESC LIMIT 1")->fetch_row()[0];
$guruAkun = (int) $a->query("SELECT guru_id FROM users WHERE username = 'sbx_murobi_b'")->fetch_row()[0];
$guruTanpaAkun = (int) $a->query('SELECT g.id FROM guru g WHERE g.is_active = 1 AND g.archived_at IS NULL AND NOT EXISTS (SELECT 1 FROM users u WHERE u.guru_id = g.id) ORDER BY g.id LIMIT 1')->fetch_row()[0];
foreach ([$guruAkun, $guruTanpaAkun] as $guru) {
    penugasan_service()->buat(\App\Penugasan\PenugasanJenis::KESEHATAN, ['pengurus_id' => 'guru:' . $guru, 'tahun_ajaran_id' => (string) $tahun, 'tanggal_mulai' => date('Y-m-d')], $admin);
}
$assert($n('SELECT COUNT(*) FROM pengurus WHERE guru_id IS NOT NULL') === 2 && $n("SELECT COUNT(*) FROM users u JOIN pengurus p ON p.id = u.pengurus_id WHERE p.guru_id = $guruAkun") === 1,
    'Dua guru (berakun dan tanpa akun) ditugaskan sebagai Petugas Kesehatan lewat layanan: dua identitas, akun guru berakun terhubung');
[$kode, $out] = $verifikasi();
$assert($kode === 0, 'Verifier lulus sesudah identitas dibentuk lewat layanan (audit pembentukan, penautan, sinkron)');
[$kode, $out] = $sh('php bin/penugasan_keamanan_rollback_preflight.php', $dbA);
$assert($kode === 0 && str_contains($out, 'Rollback 033 diizinkan'), 'Identitas tanpa penugasan Keamanan: rollback 033 diizinkan');
[$kode] = $sh('php bin/migrate.php rollback', $dbA);
$segarkan();
$assert($kode === 0 && $terakhir() === PK_MIGRASI[0] && !$adaKeamanan(), 'Rollback 033 dijalankan; identitas 032 tetap');
$sebelum = [$n('SELECT COUNT(*) FROM pengurus WHERE guru_id IS NOT NULL'), $n('SELECT COUNT(*) FROM users WHERE pengurus_id IS NOT NULL'), v7_hash_kueri($a, 'SELECT * FROM pengurus')];
[$kode, $out] = $sh('php bin/penugasan_keamanan_rollback_preflight.php', $dbA);
$assert($kode === 1 && str_contains($out, 'Rollback 032 DITOLAK.'), 'Preflight rollback 032 memblokir (ada identitas pengurus milik guru)');
[$kode, $out] = $sh('php bin/migrate.php rollback', $dbA);
$segarkan();
$assert($kode !== 0 && str_contains($out, 'penugasan_fase1_rollback_guard_kosong') && $terakhir() === PK_MIGRASI[0] && $adaGuruId() && $penjaga() === 0
    && [$n('SELECT COUNT(*) FROM pengurus WHERE guru_id IS NOT NULL'), $n('SELECT COUNT(*) FROM users WHERE pengurus_id IS NOT NULL'), v7_hash_kueri($a, 'SELECT * FROM pengurus')] === $sebelum,
    'migrate.php rollback tanpa preflight ditolak penjaga SQL 032; kolom, identitas, penautan akun, dan catatan migrasi tetap, tanpa tabel penjaga');
[$kode] = $sh('php bin/migrate.php up', $dbA);
$segarkan();
$assert($kode === 0 && $terakhir() === PK_MIGRASI[1] && $adaKeamanan(), 'Naik lagi ke 033');
$ids = require $root . '/tests/penugasan_keamanan_f3_seed.php';
$segarkan();
$kUser = ['id' => $ids['k'], 'roles' => ['pengurus'], 'guru_id' => null];
keamanan_service()->izinDetail($kUser, $ids['izin_aktif']);
keamanan_service()->izinDetail(['id' => $ids['g'], 'roles' => ['guru', 'pengurus'], 'guru_id' => (int) $a->query('SELECT guru_id FROM users WHERE id = ' . $ids['g'])->fetch_row()[0]], $ids['izin_wali']);
$assert($n('SELECT COUNT(*) FROM keamanan_assignments') === 2 && $n("SELECT COUNT(*) FROM audit_logs WHERE action = 'keamanan.izin.dilihat'") === 2,
    'Skenario Fase 3 (pengurus biasa + guru sebagai Keamanan) dan dua rincian izin dibaca lewat layanan (berjejak)');
[$kode, $out] = $verifikasi();
$assert($kode === 0 && str_contains($out, 'Akun ber-keamanan.pantau menurut resolver = hitungan SQL independen (2 akun)'), 'Verifier lulus: resolver = SQL independen untuk dua akun Keamanan');
$sebelum = [v7_hash_kueri($a, 'SELECT * FROM keamanan_assignments'), $n('SELECT COUNT(*) FROM schema_migrations')];
[$kode, $out] = $sh('php bin/penugasan_keamanan_rollback_preflight.php', $dbA);
$assert($kode === 1 && str_contains($out, 'Rollback 033 DITOLAK.'), 'Preflight rollback 033 memblokir (ada penugasan Keamanan)');
[$kode, $out] = $sh('php bin/migrate.php rollback', $dbA);
$segarkan();
$assert($kode !== 0 && str_contains($out, 'penugasan_fase3_rollback_guard_kosong') && $terakhir() === PK_MIGRASI[1] && $penjaga() === 0
    && [v7_hash_kueri($a, 'SELECT * FROM keamanan_assignments'), $n('SELECT COUNT(*) FROM schema_migrations')] === $sebelum,
    'migrate.php rollback tanpa preflight ditolak penjaga SQL 033; penugasan dan catatan migrasi tetap, tanpa tabel penjaga');
// Penugasan yang sudah berakhir tetap menahan rollback (riwayat pemegang hak baca).
penugasan_service()->akhiri(\App\Penugasan\PenugasanJenis::KEAMANAN, $ids['penugasan_k'], date('Y-m-d'), 'PKF5 akhiri penugasan uji', $admin);
[$kode, $out] = $sh('php bin/penugasan_keamanan_rollback_preflight.php', $dbA);
$assert($kode === 1 && str_contains($out, 'Rollback 033 DITOLAK.') && $n('SELECT COUNT(*) FROM keamanan_assignments WHERE diakhiri_pada IS NOT NULL') === 1,
    'Penugasan yang sudah diakhiri (beralasan) tetap menahan rollback 033');

// ===================================================================== R-7
echo PHP_EOL . '=== R-7. Latihan backup → restore → verifikasi ===' . PHP_EOL;
foreach (['writer' => 11, 'dump' => 12] as $metode => $harap) {
    [$kode, $out] = $sh('php bin/penugasan_keamanan_backup_restore_drill.php --metode=' . $metode, $dbA, ['PK_DRILL' => '1']);
    preg_match('/Ringkasan latihan: (\d+) lulus, (\d+) gagal/', $out, $r);
    $assert($kode === 0 && (int) ($r[1] ?? 0) === $harap && (int) ($r[2] ?? 1) === 0, 'Latihan ' . $metode . ': ' . ($r[1] ?? '?') . '/' . ($r[2] ?? '?')
        . ' (preflight, backup, restore, verifier + manifest, hash 25 tabel dan hak Keamanan sama, rollback berisi data ditolak)');
}
[$kode, $out] = $sh('php bin/penugasan_keamanan_backup_restore_drill.php', $dbA);
$assert($kode === 2 && str_contains($out, 'PK_DRILL=1'), 'Latihan tanpa PK_DRILL=1 ditolak');
[$kode, $out] = $sh('php bin/penugasan_keamanan_backup_restore_drill.php', 'webalhasan', ['PK_DRILL' => '1']);
$assert($kode === 2 && str_contains($out, 'wajib test_*_test'), 'Latihan pada DB bukan test_*_test ditolak');

// ===================================================================== R-8
echo PHP_EOL . '=== R-8. Kontrol pengrusakan verifier ===' . PHP_EOL;
$dasar = $scratch . '/manifest-dasar.json';
$sh('php bin/penugasan_keamanan_preflight.php ' . escapeshellarg($dasar), $dbA);
[$kode, $out] = $verifikasi(escapeshellarg($dasar));
$assert($kode === 0, 'Keadaan dasar R-8: verifier + manifest pasca-rilis lulus');
$identitas = $a->query('SELECT * FROM pengurus WHERE guru_id IS NOT NULL ORDER BY id LIMIT 1')->fetch_assoc();
$akunIdentitas = (int) $a->query('SELECT id FROM users WHERE pengurus_id = ' . (int) $ids['g_pengurus'])->fetch_row()[0];
// Pemulihan memakai `updated_at = updated_at` agar kolom ON UPDATE tidak ikut
// berubah: manifest dasar dibandingkan lagi sesudah seluruh kontrol.
$rusak = static function (string $label, array $rusakSql, array $pulihSql, string $harapGagal, string $arg = '') use ($q, $verifikasi, $assert): void {
    foreach ($rusakSql as $sql) {
        $q($sql);
    }
    [$kode, $out] = $verifikasi($arg);
    foreach ($pulihSql as $sql) {
        $q($sql);
    }
    [$kodePulih] = $verifikasi($arg);
    $assert($kode === 1 && str_contains($out, '[gagal] ' . $harapGagal) && $kodePulih === 0, $label . ' → "' . $harapGagal . '"; dipulihkan → lulus');
};
$rusak('Nama identitas diubah langsung (tidak sinkron dengan Data Guru)',
    ['UPDATE pengurus SET nama = CONCAT(nama, " X"), updated_at = updated_at WHERE id = ' . (int) $identitas['id']],
    ['UPDATE pengurus SET nama = "' . $a->real_escape_string((string) $identitas['nama']) . '", updated_at = updated_at WHERE id = ' . (int) $identitas['id']],
    'Nama, nomor HP, status aktif, dan arsip identitas = data guru');
$rusak('Jabatan identitas diubah',
    ['UPDATE pengurus SET jabatan = "Ketua", updated_at = updated_at WHERE id = ' . (int) $identitas['id']], ['UPDATE pengurus SET jabatan = "Guru", updated_at = updated_at WHERE id = ' . (int) $identitas['id']],
    'Identitas berjabatan "Guru" tanpa nomor identitas');
$lain = (int) $a->query("SELECT id FROM users WHERE username = 'sbx_murobi_c'")->fetch_row()[0];
$rusak('Identitas guru dipindah ke akun guru lain',
    ['UPDATE users SET pengurus_id = NULL WHERE id = ' . $akunIdentitas, 'UPDATE users SET pengurus_id = ' . (int) $ids['g_pengurus'] . ' WHERE id = ' . $lain],
    ['UPDATE users SET pengurus_id = NULL WHERE id = ' . $lain, 'UPDATE users SET pengurus_id = ' . (int) $ids['g_pengurus'] . ' WHERE id = ' . $akunIdentitas],
    'Identitas hanya dipegang akun guru pemiliknya, tanpa relasi wali');
$auditBuat = $a->query("SELECT * FROM audit_logs WHERE action = 'penugasan.identitas_guru.buat' AND entity_id = " . (int) $ids['g_pengurus'])->fetch_assoc();
$sisip = static fn (array $row): string => 'INSERT INTO audit_logs (' . implode(', ', array_keys($row)) . ') VALUES ('
    . implode(', ', array_map(static fn ($v): string => $v === null ? 'NULL' : '"' . $a->real_escape_string((string) $v) . '"', $row)) . ')';
$rusak('Audit pembentukan identitas dihapus', ['DELETE FROM audit_logs WHERE id = ' . (int) $auditBuat['id']], [$sisip($auditBuat)],
    'Setiap identitas memiliki audit penugasan.identitas_guru.buat');
$auditPenugasan = $a->query("SELECT * FROM audit_logs WHERE action = 'penugasan.buat' AND entity_type = 'keamanan_assignment' AND entity_id = " . (int) $ids['penugasan_k'])->fetch_assoc();
$rusak('Audit pembuatan penugasan Keamanan dihapus', ['DELETE FROM audit_logs WHERE id = ' . (int) $auditPenugasan['id']], [$sisip($auditPenugasan)],
    'Setiap penugasan Keamanan memiliki audit penugasan.buat');
$rusak('Audit keamanan.* berisi alasan izin disisipkan paksa',
    ["INSERT INTO audit_logs (actor_user_id, action, entity_type, entity_id, after_json, created_at) VALUES ({$ids['k']}, 'keamanan.izin.dilihat', 'izin_pengajuan', 1, '{\"pengajuan_id\":1,\"alasan\":\"PKF5 isi\"}', NOW())"],
    ["DELETE FROM audit_logs WHERE action = 'keamanan.izin.dilihat' AND after_json LIKE '%PKF5 isi%'"],
    'Audit keamanan.* hanya memuat kunci metadata');
$rusak('Audit keamanan.* berpelaku admin (bukan pemegang Keamanan) disisipkan',
    ["INSERT INTO audit_logs (actor_user_id, action, entity_type, entity_id, after_json, created_at) VALUES ($admin, 'keamanan.izin.dilihat', 'izin_pengajuan', 1, '{\"pengajuan_id\":1}', NOW())"],
    ["DELETE FROM audit_logs WHERE action = 'keamanan.izin.dilihat' AND actor_user_id = $admin"],
    'Setiap audit keamanan.* berpelaku akun yang memegang penugasan Keamanan');
$keputusan = $a->query('SELECT k.id, k.diputus_oleh_user_id, k.diputus_pada, p.diajukan_oleh_user_id FROM izin_keputusan k JOIN izin_pengajuan p ON p.id = k.pengajuan_id
    WHERE p.diajukan_oleh_user_id IS NOT NULL AND p.diajukan_oleh_user_id <> k.diputus_oleh_user_id ORDER BY k.id LIMIT 1')->fetch_assoc();
$rusak('Keputusan izin sesudah rilis oleh akun pengaju disisipkan paksa',
    ['UPDATE izin_keputusan SET diputus_oleh_user_id = ' . (int) $keputusan['diajukan_oleh_user_id'] . ', diputus_pada = NOW() WHERE id = ' . (int) $keputusan['id']],
    ['UPDATE izin_keputusan SET diputus_oleh_user_id = ' . (int) $keputusan['diputus_oleh_user_id'] . ', diputus_pada = "' . $keputusan['diputus_pada'] . '" WHERE id = ' . (int) $keputusan['id']],
    'Tidak ada keputusan izin sejak rilis oleh akun pengaju/peninjau pengajuan yang sama');
$biasa = $a->query('SELECT id, jabatan FROM pengurus WHERE guru_id IS NULL ORDER BY id LIMIT 1')->fetch_assoc();
$rusak('Baris pengurus biasa diubah sesudah manifest',
    ['UPDATE pengurus SET jabatan = "PKF5 diubah", updated_at = updated_at WHERE id = ' . (int) $biasa['id']],
    ['UPDATE pengurus SET jabatan = "' . $a->real_escape_string((string) $biasa['jabatan']) . '", updated_at = updated_at WHERE id = ' . (int) $biasa['id']],
    'Isi isi_tabel_lama (', escapeshellarg($dasar));
$izin = $a->query('SELECT id, alasan FROM izin_pengajuan ORDER BY id LIMIT 1')->fetch_assoc();
$rusak('Alasan izin diubah sesudah manifest (irisan baca)',
    ['UPDATE izin_pengajuan SET alasan = CONCAT(alasan, " PKF5"), updated_at = updated_at WHERE id = ' . (int) $izin['id']],
    ['UPDATE izin_pengajuan SET alasan = "' . $a->real_escape_string((string) $izin['alasan']) . '", updated_at = updated_at WHERE id = ' . (int) $izin['id']],
    'Isi irisan_baca (', escapeshellarg($dasar) . ' --irisan-baca');
[$kode, $out] = $verifikasi(escapeshellarg($dasar));
$assert($kode === 0 && $penjaga() === 0, 'Sesudah seluruh kontrol dipulihkan: verifier + manifest dasar lulus kembali');

echo PHP_EOL . 'Hasil: ' . $lulus . ' lulus, ' . count($gagal) . ' gagal' . PHP_EOL;
foreach ($gagal as $g) {
    echo '  - ' . $g . PHP_EOL;
}
exit($gagal === [] ? 0 : 1);
