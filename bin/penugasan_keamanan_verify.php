<?php

declare(strict_types=1);

/**
 * Verifier rilis penugasan lintas guru/pengurus dan Keamanan (Fase 5). Hanya
 * membaca; aman dijalankan pada produksi sesudah migrasi, pada salinan
 * pulihan, maupun berkala.
 *
 *   php bin/penugasan_keamanan_verify.php [manifest-privat.json] [--irisan-baca]
 *
 * 1. Verifier fondasi penugasan `bin/penugasan_verify.php` (skema 012, role
 *    dasar, resolver) sebagai proses terpisah.
 * 2. Skema 032 (`pengurus.guru_id` bertipe sama dengan `guru.id`, UNIQUE,
 *    kunci asing) dan 033 (`keamanan_assignments`: kolom, UNIQUE, lima kunci
 *    asing, CHECK rentang tanggal, InnoDB).
 * 3. Identitas pengurus milik guru (PK-1), ditulis ulang terpisah dari kode
 *    aplikasi:
 *    - jabatan "Guru", tanpa nomor identitas; nama/HP/status/arsip = data guru;
 *    - hanya akun guru itu sendiri yang memegangnya, tanpa relasi wali;
 *    - setiap identitas dan setiap penautan akun memiliki audit pembentukannya;
 *    - identitas yang tidak sah (guru nonaktif/diarsipkan) tidak memberi
 *      capability penugasan apa pun lewat `Capabilities`.
 * 4. Penugasan Keamanan (PK-5/PK-6): setiap baris diaudit `penugasan.buat`,
 *    pengakhiran beralasan; himpunan akun ber-`keamanan.pantau` menurut
 *    `Capabilities` = hitungan SQL independen; setiap audit `keamanan.*`
 *    berpelaku pemegang penugasan Keamanan dan hanya memuat kunci metadata.
 * 5. Pemisahan tugas izin (Fase 2) sejak 032 tercatat: tidak ada keputusan
 *    atau koreksi oleh akun pengaju/peninjau pengajuan yang sama.
 * 6. Dengan manifest `bin/penugasan_keamanan_preflight.php`: jumlah baris dan
 *    isi seluruh tabel lama (`pengurus` tanpa `guru_id`) serta, bila manifest
 *    diambil sesudah rilis, isi 032/033 tetap. Jalankan segera sesudah migrasi
 *    (sebelum lalu lintas) atau pada DB pulihan. `--irisan-baca` hanya
 *    membandingkan data yang dibaca Keamanan dan baris pengurus biasa, yang
 *    tetap sama walau Keamanan dipakai dan identitas guru dibentuk.
 *
 * Kode keluar 0 bila seluruh pemeriksaan lulus.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/_penugasan_keamanan_rilis.php';

use App\Auth\Capabilities;
use App\Penugasan\PenugasanJenis;

$db = app_db();
$args = array_slice($argv, 1);
$hanyaIrisan = in_array('--irisan-baca', $args, true);
$manifestPath = null;
foreach ($args as $a) {
    if (!str_starts_with($a, '--')) {
        $manifestPath = $a;
    }
}
$lulus = 0;
$gagal = 0;
$periksa = static function (bool $ok, string $label) use (&$lulus, &$gagal): void {
    echo ($ok ? '[lulus] ' : '[gagal] ') . $label . PHP_EOL;
    $ok ? $lulus++ : $gagal++;
};
$n = static fn (string $sql): int => (int) v7f1_nilai($db, $sql);
$daftar = static function (string $sql) use ($db): array {
    $r = $db->query($sql);
    if ($r === false) {
        throw new RuntimeException($db->error . ' :: ' . $sql);
    }

    return array_map(static fn (array $row): int => (int) $row[0], $r->fetch_all(MYSQLI_NUM));
};
$ringkas = static fn (array $ids): string => $ids === [] ? '' : ' (ID: ' . implode(', ', array_slice($ids, 0, 10)) . (count($ids) > 10 ? ', …' : '') . ')';
echo '[info] Basis data terpilih: ' . (string) v7f1_nilai($db, 'SELECT DATABASE()') . ' (verifier hanya membaca).' . PHP_EOL;

$terpasang = pk_terpasang($db);
$periksa($terpasang === array_fill_keys(PK_MIGRASI, true), 'Migrasi 032 dan 033 tercatat (rilis utuh, bukan sebagian)');
if ($terpasang !== array_fill_keys(PK_MIGRASI, true)) {
    echo PHP_EOL . 'Ringkasan verifier penugasan Keamanan: ' . $lulus . ' lulus, ' . $gagal . ' gagal' . PHP_EOL;
    exit(1);
}

if (!$hanyaIrisan) {
    echo PHP_EOL . '=== 1. Verifier fondasi penugasan (proses terpisah) ===' . PHP_EOL;
    $out = [];
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(APP_ROOT . '/bin/penugasan_verify.php') . ' 2>&1', $out, $kode);
    foreach (array_filter($out, static fn (string $l): bool => str_starts_with($l, '[gagal]')) as $l) {
        echo '    ' . $l . PHP_EOL;
    }
    $periksa($kode === 0, 'bin/penugasan_verify.php (skema fondasi 012, role dasar, resolver) exit 0');

    echo PHP_EOL . '=== 2. Skema 032–033 ===' . PHP_EOL;
    $kolom = static fn (string $tabel, string $nama): ?array => $db->query("SELECT COLUMN_TYPE, IS_NULLABLE FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$tabel' AND COLUMN_NAME = '$nama'")->fetch_assoc();
    $guruId = $kolom('pengurus', 'guru_id');
    $periksa($guruId !== null && $guruId['IS_NULLABLE'] === 'YES' && strtolower($guruId['COLUMN_TYPE']) === strtolower((string) ($kolom('guru', 'id')['COLUMN_TYPE'] ?? '')),
        'Kolom pengurus.guru_id NULL bertipe sama dengan guru.id');
    $periksa((string) v7f1_nilai($db, "SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pengurus' AND INDEX_NAME = 'pengurus_guru_unique' AND NON_UNIQUE = 0") === 'guru_id',
        'UNIQUE pengurus_guru_unique (guru_id): satu guru paling banyak satu identitas');
    // Saringan skema/tabel langsung pada KEY_COLUMN_USAGE: tanpa itu MariaDB
    // membuka seluruh tabel di server (lambat pada hosting bersama).
    $fk = static fn (string $tabel, string $nama): string => (string) v7f1_nilai($db, "SELECT CONCAT(REFERENCED_TABLE_NAME, '.', REFERENCED_COLUMN_NAME)
        FROM information_schema.KEY_COLUMN_USAGE
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$tabel' AND CONSTRAINT_NAME = '$nama' AND REFERENCED_TABLE_NAME IS NOT NULL");
    $periksa($fk('pengurus', 'pengurus_guru_fk') === 'guru.id', 'Kunci asing pengurus_guru_fk → guru.id');
    $periksa(v7_kolom($db, PK_TABEL) === PK_KOLOM_KEAMANAN, 'Kolom keamanan_assignments persis menurut 033 (' . count(PK_KOLOM_KEAMANAN) . ' kolom)');
    $periksa(strtolower((string) v7f1_nilai($db, "SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" . PK_TABEL . "'")) === 'innodb',
        'keamanan_assignments bermesin InnoDB');
    $periksa((string) v7f1_nilai($db, "SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" . PK_TABEL . "' AND INDEX_NAME = 'keamanan_assignment_unique' AND NON_UNIQUE = 0") === 'pengurus_id,tahun_ajaran_id,tanggal_mulai',
        'UNIQUE keamanan_assignment_unique (pengurus_id, tahun_ajaran_id, tanggal_mulai)');
    foreach (PK_FK_KEAMANAN as $nama => $induk) {
        $periksa(str_starts_with($fk(PK_TABEL, $nama), $induk . '.'), 'Kunci asing ' . $nama . ' → ' . $induk);
    }
    $periksa($n("SELECT COUNT(*) FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'keamanan_range_check'") === 1,
        'CHECK keamanan_range_check (tanggal_selesai ≥ tanggal_mulai) terpasang');
    $periksa($n("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'penugasan\\_fase%rollback\\_guard'") === 0,
        'Tidak ada tabel penjaga rollback tersisa');

    echo PHP_EOL . '=== 3. Identitas pengurus milik guru (PK-1) ===' . PHP_EOL;
    $jumlahIdentitas = $n('SELECT COUNT(*) FROM pengurus WHERE guru_id IS NOT NULL');
    echo '[info] Identitas pengurus milik guru: ' . $jumlahIdentitas . '; terhubung ke akun: '
        . $n('SELECT COUNT(*) FROM users u JOIN pengurus p ON p.id = u.pengurus_id WHERE p.guru_id IS NOT NULL') . PHP_EOL;
    $ids = $daftar("SELECT id FROM pengurus WHERE guru_id IS NOT NULL AND (jabatan <> 'Guru' OR nomor_identitas IS NOT NULL) ORDER BY id");
    $periksa($ids === [], 'Identitas berjabatan "Guru" tanpa nomor identitas (tidak diubah dari Data Pengurus)' . $ringkas($ids));
    $ids = $daftar('SELECT p.id FROM pengurus p JOIN guru g ON g.id = p.guru_id
        WHERE BINARY p.nama <> BINARY g.nama_guru OR NOT (p.no_hp <=> g.no_hp) OR p.is_active <> g.is_active OR NOT (p.archived_at <=> g.archived_at) ORDER BY p.id');
    $periksa($ids === [], 'Nama, nomor HP, status aktif, dan arsip identitas = data guru' . $ringkas($ids));
    $ids = $daftar('SELECT u.id FROM users u JOIN pengurus p ON p.id = u.pengurus_id
        WHERE p.guru_id IS NOT NULL AND (u.guru_id IS NULL OR u.guru_id <> p.guru_id OR u.wali_id IS NOT NULL) ORDER BY u.id');
    $periksa($ids === [], 'Identitas hanya dipegang akun guru pemiliknya, tanpa relasi wali' . $ringkas($ids));
    $ids = $daftar("SELECT p.id FROM pengurus p WHERE p.guru_id IS NOT NULL AND NOT EXISTS (
        SELECT 1 FROM audit_logs al WHERE al.action = 'penugasan.identitas_guru.buat' AND al.entity_type = 'pengurus' AND al.entity_id = p.id) ORDER BY p.id");
    $periksa($ids === [], 'Setiap identitas memiliki audit penugasan.identitas_guru.buat' . $ringkas($ids));
    $ids = $daftar("SELECT u.id FROM users u JOIN pengurus p ON p.id = u.pengurus_id WHERE p.guru_id IS NOT NULL AND NOT EXISTS (
        SELECT 1 FROM audit_logs al WHERE al.action = 'account_identitas_guru_linked' AND al.entity_type = 'user' AND al.entity_id = u.id
           AND CAST(JSON_UNQUOTE(JSON_EXTRACT(al.after_json, '$.pengurus_id')) AS UNSIGNED) = p.id) ORDER BY u.id");
    $periksa($ids === [], 'Setiap penautan akun ke identitas memiliki audit account_identitas_guru_linked' . $ringkas($ids));
    echo '[info] Akun terhubung identitas tanpa role pengurus (penugasan tidak berlaku): ' . $n("SELECT COUNT(*) FROM users u JOIN pengurus p ON p.id = u.pengurus_id
        WHERE p.guru_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM user_roles ur JOIN roles r ON r.id = ur.role_id AND r.slug = 'pengurus' WHERE ur.user_id = u.id)") . PHP_EOL;
    echo '[info] Guru berakun dengan identitas yang belum terhubung ke akunnya: ' . $n('SELECT COUNT(*) FROM pengurus p JOIN users u ON u.guru_id = p.guru_id
        WHERE p.guru_id IS NOT NULL AND NOT (u.pengurus_id <=> p.id)') . PHP_EOL;
    echo '[info] Akun guru yang memegang pengurus biasa (akun ganda warisan): ' . $n('SELECT COUNT(*) FROM users u JOIN pengurus p ON p.id = u.pengurus_id
        WHERE u.guru_id IS NOT NULL AND p.guru_id IS NULL') . PHP_EOL;

    // Identitas tidak sah → tidak ada capability penugasan apa pun dari jenis berbasis pengurus.
    $capPengurus = [];
    foreach (PenugasanJenis::semua() as $definisi) {
        if ($definisi['subjek'] === 'pengurus') {
            $capPengurus = array_merge($capPengurus, $definisi['capabilities']);
        }
    }
    $caps = new Capabilities($db);
    $bocor = [];
    foreach ($daftar('SELECT u.id FROM users u JOIN pengurus p ON p.id = u.pengurus_id JOIN guru g ON g.id = p.guru_id
        WHERE g.is_active <> 1 OR g.archived_at IS NOT NULL ORDER BY u.id') as $uid) {
        $fitur = $caps->featureCapabilities(['id' => $uid, 'roles' => []]);
        foreach ($capPengurus as $cap) {
            if (in_array($fitur[$cap]['sumber'] ?? null, [Capabilities::SUMBER_PENUGASAN, Capabilities::SUMBER_KEDUANYA], true)) {
                $bocor[] = $uid;
                break;
            }
        }
    }
    $periksa($bocor === [], 'Identitas milik guru nonaktif/diarsipkan tidak memberi capability penugasan (resolver Capabilities)' . $ringkas($bocor));

    echo PHP_EOL . '=== 4. Penugasan Keamanan (PK-5/PK-6) ===' . PHP_EOL;
    echo '[info] Penugasan Keamanan: ' . $n('SELECT COUNT(*) FROM ' . PK_TABEL) . ' baris, aktif kini '
        . $n('SELECT COUNT(*) FROM ' . PK_TABEL . ' WHERE is_active = 1 AND tanggal_mulai <= CURDATE() AND (tanggal_selesai IS NULL OR tanggal_selesai >= CURDATE())')
        . ' (pengurus biasa ' . $n('SELECT COUNT(*) FROM ' . PK_TABEL . ' a JOIN pengurus p ON p.id = a.pengurus_id WHERE p.guru_id IS NULL AND a.is_active = 1')
        . ', identitas guru ' . $n('SELECT COUNT(*) FROM ' . PK_TABEL . ' a JOIN pengurus p ON p.id = a.pengurus_id WHERE p.guru_id IS NOT NULL AND a.is_active = 1') . ')' . PHP_EOL;
    $ids = $daftar("SELECT a.id FROM keamanan_assignments a WHERE NOT EXISTS (SELECT 1 FROM audit_logs al
        WHERE al.action = 'penugasan.buat' AND al.entity_type = 'keamanan_assignment' AND al.entity_id = a.id) ORDER BY a.id");
    $periksa($ids === [], 'Setiap penugasan Keamanan memiliki audit penugasan.buat' . $ringkas($ids));
    $ids = $daftar("SELECT id FROM keamanan_assignments WHERE diakhiri_pada IS NOT NULL AND TRIM(COALESCE(alasan_pengakhiran, '')) = '' ORDER BY id");
    $periksa($ids === [], 'Setiap pengakhiran penugasan Keamanan beralasan' . $ringkas($ids));
    $sql = pk_akun_keamanan_sql($db);
    $resolver = [];
    foreach ($daftar("SELECT DISTINCT u.id FROM users u JOIN user_roles ur ON ur.user_id = u.id JOIN roles r ON r.id = ur.role_id AND r.slug = 'pengurus'
        WHERE u.is_active = 1 ORDER BY u.id") as $uid) {
        if (in_array($caps->featureSource(['id' => $uid, 'roles' => []], PenugasanJenis::CAP_KEAMANAN_PANTAU), [Capabilities::SUMBER_PENUGASAN, Capabilities::SUMBER_KEDUANYA], true)) {
            $resolver[] = $uid;
        }
    }
    $periksa($resolver === $sql, 'Akun ber-keamanan.pantau menurut resolver = hitungan SQL independen (' . count($sql) . ' akun)'
        . ($resolver === $sql ? '' : ' (resolver ' . json_encode($resolver) . ' ≠ SQL ' . json_encode($sql) . ')'));
    echo '[info] Penugasan Keamanan aktif tanpa akun sah (hak tidak berlaku sampai akun dibuat/dihubungkan): '
        . $n("SELECT COUNT(*) FROM keamanan_assignments a WHERE a.is_active = 1 AND (a.tanggal_selesai IS NULL OR a.tanggal_selesai >= CURDATE()) AND NOT EXISTS (
            SELECT 1 FROM users u JOIN user_roles ur ON ur.user_id = u.id JOIN roles r ON r.id = ur.role_id AND r.slug = 'pengurus'
            WHERE u.pengurus_id = a.pengurus_id AND u.is_active = 1)") . PHP_EOL;

    $buruk = [];
    $pelakuAsing = [];
    $jumlahAudit = 0;
    $r = $db->query("SELECT id, actor_user_id, before_json, after_json FROM audit_logs WHERE action LIKE 'keamanan.%' ORDER BY id", MYSQLI_USE_RESULT);
    $baris = $r->fetch_all(MYSQLI_ASSOC);
    $r->free();
    $pemegang = array_flip($daftar('SELECT DISTINCT u.id FROM users u JOIN keamanan_assignments a ON a.pengurus_id = u.pengurus_id'));
    foreach ($baris as $row) {
        $jumlahAudit++;
        foreach (['before_json', 'after_json'] as $k) {
            if ($row[$k] === null) {
                continue;
            }
            $isi = json_decode((string) $row[$k], true);
            if (!is_array($isi) || array_diff(array_keys($isi), PK_KUNCI_AUDIT_KEAMANAN) !== []
                || (isset($isi['filter']) && (!is_array($isi['filter']) || array_filter($isi['filter'], static fn ($v): bool => !is_scalar($v) && $v !== null) !== []))) {
                $buruk[] = (int) $row['id'];
                break;
            }
        }
        if ($row['actor_user_id'] === null || !isset($pemegang[(int) $row['actor_user_id']])) {
            $pelakuAsing[] = (int) $row['id'];
        }
    }
    echo '[info] Jejak akses keamanan.*: ' . $jumlahAudit . ' baris' . PHP_EOL;
    $periksa($buruk === [], 'Audit keamanan.* hanya memuat kunci metadata (ID, status, asal, jumlah, saringan skalar) — tanpa alasan/uraian/isi' . $ringkas($buruk));
    $periksa($pelakuAsing === [], 'Setiap audit keamanan.* berpelaku akun yang memegang penugasan Keamanan' . $ringkas($pelakuAsing));

    echo PHP_EOL . '=== 5. Pemisahan tugas izin per akun (Fase 2) ===' . PHP_EOL;
    $sejak = pk_waktu_rilis($db);
    echo '[info] 032 tercatat ' . $sejak . ' — keputusan sebelum waktu itu adalah riwayat lama dan hanya dilaporkan.' . PHP_EOL;
    $terlibat = 'SELECT p.diajukan_oleh_user_id FROM izin_pengajuan p WHERE p.id = %1$s.pengajuan_id
        UNION SELECT t.ditinjau_oleh_user_id FROM izin_pembimbing_tinjauan t WHERE t.pengajuan_id = %1$s.pengajuan_id';
    $s = $db->real_escape_string((string) $sejak);
    $ids = $daftar("SELECT k.id FROM izin_keputusan k WHERE k.diputus_pada >= '$s' AND k.diputus_oleh_user_id IN (" . sprintf($terlibat, 'k') . ') ORDER BY k.id');
    $periksa($ids === [], 'Tidak ada keputusan izin sejak rilis oleh akun pengaju/peninjau pengajuan yang sama' . $ringkas($ids));
    $ids = $daftar("SELECT c.id FROM izin_keputusan_koreksi c WHERE c.dikoreksi_pada >= '$s' AND c.dikoreksi_oleh_user_id IN (" . sprintf($terlibat, 'c') . ') ORDER BY c.id');
    $periksa($ids === [], 'Tidak ada koreksi keputusan sejak rilis oleh akun pengaju/peninjau pengajuan yang sama' . $ringkas($ids));
    echo '[info] Keputusan sebelum rilis oleh akun pengaju/peninjau (riwayat lama, tidak diubah): '
        . $n("SELECT COUNT(*) FROM izin_keputusan k WHERE k.diputus_pada < '$s' AND k.diputus_oleh_user_id IN (" . sprintf($terlibat, 'k') . ')') . PHP_EOL;
    echo '[info] Pengajuan sejak rilis yang murobinya guru milik akun pengaju/peninjau (tinjau penugasan; keputusan tetap ditolak per akun): '
        . $n("SELECT COUNT(*) FROM izin_pengajuan p JOIN users u ON u.guru_id = p.murobi_guru_id
            WHERE GREATEST(COALESCE(p.routing_pada, '1970-01-01'), COALESCE(p.murobi_ditetapkan_pada, '1970-01-01')) >= '$s'
              AND u.id IN (SELECT p.diajukan_oleh_user_id UNION SELECT t.ditinjau_oleh_user_id FROM izin_pembimbing_tinjauan t WHERE t.pengajuan_id = p.id)") . PHP_EOL;
}

if ($manifestPath !== null) {
    echo PHP_EOL . '=== 6. Preservasi terhadap manifest ' . ($hanyaIrisan ? '(irisan baca)' : '') . ' ===' . PHP_EOL;
    $m = json_decode((string) @file_get_contents($manifestPath), true);
    $kunci = $hanyaIrisan ? ['irisan_baca'] : ['legacy_counts', 'isi_tabel_lama', 'irisan_baca'];
    $valid = is_array($m) && ($m['fase'] ?? null) === 'penugasan-keamanan-rilis';
    foreach ($kunci as $k) {
        $valid = $valid && is_array($m[$k] ?? null) && $m[$k] !== [];
    }
    $periksa($valid, 'Manifest privat terbaca dan lengkap: ' . $manifestPath);
    if ($valid) {
        $kini = pk_snapshot($db);
        foreach ($kunci as $k) {
            $beda = [];
            foreach ($m[$k] as $t => $nilai) {
                if (($kini[$k][$t] ?? null) !== $nilai) {
                    $beda[] = $t;
                }
            }
            $hilang = array_diff(array_keys($kini[$k]), array_keys($m[$k]));
            $periksa($beda === [] && $hilang === [], 'Isi ' . $k . ' (' . count($m[$k]) . ' entri) = manifest'
                . ($beda === [] ? '' : ' (berbeda: ' . implode(', ', $beda) . ')') . ($hilang === [] ? '' : ' (tabel baru: ' . implode(', ', $hilang) . ')'));
        }
        if (!$hanyaIrisan && ($m['pk_terpasang'] ?? null) === array_fill_keys(PK_MIGRASI, true)) {
            $periksa(($m['isi_pk'] ?? null) === $kini['isi_pk'], 'Isi identitas guru dan keamanan_assignments = manifest (diambil sesudah rilis)');
        }
    }
}

echo PHP_EOL . 'Ringkasan verifier penugasan Keamanan: ' . $lulus . ' lulus, ' . $gagal . ' gagal' . PHP_EOL;
exit($gagal > 0 ? 1 : 0);
