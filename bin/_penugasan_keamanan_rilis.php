<?php

declare(strict_types=1);

/**
 * Pembantu bersama alat rilis penugasan lintas guru/pengurus dan Keamanan
 * Fase 5 (`penugasan_keamanan_preflight.php`, `_verify.php`, `_manifest.php`,
 * `_rollback_preflight.php`, `_backup_restore_drill.php`). Hanya CLI; hanya
 * membaca basis data. Hash multiset dan pembantu skema memakai pembantu rilis
 * V7 yang sudah diaudit (`bin/_v7_rilis.php`).
 */

require_once __DIR__ . '/_v7_rilis.php';

/** Migrasi workstream ini (032 identitas guru, 033 Keamanan), dirilis bersama. */
const PK_MIGRASI = ['032_penugasan_keamanan_fase1_identitas_guru.sql', '033_penugasan_keamanan_fase3_keamanan.sql'];

/** Migrasi terakhir yang wajib sudah tercatat sebelum rilis (V7 Fase 2). */
const PK_PRASYARAT = '031_v7_fase2_tinjauan_pembimbing.sql';

/** Baseline audit Fase 4 yang diverifikasi (web, mobile). */
const PK_BASELINE_WEB = '39cc6445a996c7d1b3fe3fadd351d9aa8d05d306';
const PK_BASELINE_MOBILE = 'c514e4f012f7e535319ff79c418e7408c5de5638';

/** Tabel baru 033; kolom baru 032 pada `pengurus`. */
const PK_TABEL = 'keamanan_assignments';
const PK_KOLOM_PENGURUS = 'guru_id';

/** Kolom `keamanan_assignments` persis menurut migrasi 033. */
const PK_KOLOM_KEAMANAN = ['id', 'pengurus_id', 'tahun_ajaran_id', 'tanggal_mulai', 'tanggal_selesai', 'is_active', 'catatan', 'alasan_perubahan',
    'diakhiri_pada', 'diakhiri_oleh', 'alasan_pengakhiran', 'created_by', 'updated_by', 'created_at', 'updated_at'];

/** Kunci asing 033 → tabel induk. */
const PK_FK_KEAMANAN = ['keamanan_pengurus_fk' => 'pengurus', 'keamanan_tahun_fk' => 'tahun_ajaran', 'keamanan_creator_fk' => 'users',
    'keamanan_updater_fk' => 'users', 'keamanan_ender_fk' => 'users'];

/**
 * Kunci yang boleh ada di before/after audit `keamanan.*` (sama dengan
 * penyaring `AuditLogger::metadataKeamanan`, ditulis ulang terpisah agar
 * verifier tidak sekadar memanggil kode yang diperiksanya).
 */
const PK_KUNCI_AUDIT_KEAMANAN = ['pengajuan_id', 'pelanggaran_id', 'lampiran_id', 'kasus_id', 'santri_id', 'pertemuan_id', 'asal', 'status', 'jumlah', 'filter'];

/**
 * Tabel yang berubah SAH karena pemakaian workstream ini atau sesi biasa,
 * sehingga tidak masuk irisan "hanya baca": masuk/token (users,
 * api_tokens, api_idempotency_keys), role/identitas yang dibentuk penugasan
 * (user_roles; `pengurus` diperiksa tersendiri untuk baris biasa), seluruh
 * tabel penugasan, jejak audit, dan catatan migrasi/kunci worker.
 */
const PK_IRISAN_KECUALI = ['schema_migrations', 'audit_logs', 'users', 'user_roles', 'api_tokens', 'api_idempotency_keys', 'notifikasi_worker_lock',
    'pengurus', PK_TABEL];

/** @return array<string, bool> migrasi workstream → sudah tercatat */
function pk_terpasang(mysqli $db): array
{
    $hasil = [];
    foreach (PK_MIGRASI as $m) {
        $s = $db->prepare('SELECT COUNT(*) FROM schema_migrations WHERE migration = ?');
        $s->bind_param('s', $m);
        $s->execute();
        $hasil[$m] = (int) $s->get_result()->fetch_row()[0] === 1;
        $s->close();
    }

    return $hasil;
}

/** Waktu 032 tercatat (awal berlakunya pemisahan tugas per akun), atau null. */
function pk_waktu_rilis(mysqli $db): ?string
{
    $s = $db->prepare('SELECT applied_at FROM schema_migrations WHERE migration = ?');
    $m = PK_MIGRASI[0];
    $s->bind_param('s', $m);
    $s->execute();
    $nilai = $s->get_result()->fetch_row()[0] ?? null;
    $s->close();

    return $nilai === null ? null : (string) $nilai;
}

/** Daftar kolom terkutip satu tabel, tanpa kolom tertentu. */
function pk_daftar_kolom(mysqli $db, string $tabel, array $kecuali = []): string
{
    return implode(', ', array_map(static fn (string $k): string => '`' . $k . '`', array_values(array_diff(v7_kolom($db, $tabel), $kecuali))));
}

/**
 * Snapshot preservasi rilis. Hanya hitungan dan hash, tanpa isi.
 *
 *  - `legacy_counts`/`isi_tabel_lama`: jumlah baris dan hash isi seluruh tabel
 *    lama (di luar `schema_migrations` dan `keamanan_assignments`); `pengurus`
 *    tanpa kolom `guru_id` — untuk migrasi, restore, dan rollback tanpa lalu
 *    lintas;
 *  - `irisan_baca`: hash isi data yang dibaca Keamanan dan modul lain yang tidak
 *    boleh berubah oleh pemakaian workstream ini (seluruh tabel kecuali
 *    `PK_IRISAN_KECUALI` dan tabel penugasan), ditambah baris pengurus biasa;
 *  - `isi_pk`: bila 032/033 terpasang, hash identitas pengurus milik guru dan
 *    seluruh `keamanan_assignments` (untuk restore).
 *
 * @return array{legacy_counts:array<string,int>, isi_tabel_lama:array<string,array{baris:int,sha256:string}>,
 *               irisan_baca:array<string,array{baris:int,sha256:string}>, isi_pk:array<string,array{baris:int,sha256:string}>}
 */
function pk_snapshot(mysqli $db): array
{
    $adaGuruId = v7f1_ada_kolom($db, 'pengurus', PK_KOLOM_PENGURUS);
    $kolomPengurus = pk_daftar_kolom($db, 'pengurus', [PK_KOLOM_PENGURUS]);
    $counts = [];
    $isi = [];
    $irisan = [];
    foreach (v7_semua_tabel($db) as $t) {
        if (in_array($t, ['schema_migrations', PK_TABEL], true)) {
            continue;
        }
        $isi[$t] = $t === 'pengurus'
            ? v7_hash_kueri($db, "SELECT $kolomPengurus FROM pengurus")
            : v7_hash_kueri($db, 'SELECT * FROM `' . $t . '`');
        $counts[$t] = $isi[$t]['baris'];
        if (!in_array($t, PK_IRISAN_KECUALI, true) && !str_ends_with($t, '_assignments')) {
            $irisan[$t] = $isi[$t];
        }
    }
    $irisan['pengurus_biasa'] = v7_hash_kueri($db, "SELECT $kolomPengurus FROM pengurus" . ($adaGuruId ? ' WHERE guru_id IS NULL' : ''));

    $isiPk = [];
    if ($adaGuruId) {
        $isiPk['identitas_guru'] = v7_hash_kueri($db, 'SELECT * FROM pengurus WHERE guru_id IS NOT NULL');
    }
    if (v7f1_ada_tabel($db, PK_TABEL)) {
        $isiPk[PK_TABEL] = v7_hash_kueri($db, 'SELECT * FROM `' . PK_TABEL . '`');
    }

    return ['legacy_counts' => $counts, 'isi_tabel_lama' => $isi, 'irisan_baca' => $irisan, 'isi_pk' => $isiPk];
}

/**
 * Akun yang SAH memegang `keamanan.pantau` dari penugasan, dihitung ulang dari
 * basis data tanpa memakai `Capabilities` (pembanding independen resolver):
 * akun aktif ber-role pengurus, pengurus terhubung aktif/belum diarsipkan,
 * identitas milik guru hanya untuk akun guru itu dengan guru aktif, penugasan
 * Keamanan aktif pada tanggal kini, tahun ajaran belum diarsipkan.
 *
 * @return array<int, int> ID akun terurut
 */
function pk_akun_keamanan_sql(mysqli $db): array
{
    $syaratIdentitas = v7f1_ada_kolom($db, 'pengurus', PK_KOLOM_PENGURUS)
        ? 'AND (p.guru_id IS NULL OR (p.guru_id = u.guru_id AND EXISTS (SELECT 1 FROM guru g WHERE g.id = p.guru_id AND g.is_active = 1 AND g.archived_at IS NULL)))'
        : '';
    $r = $db->query("SELECT DISTINCT u.id FROM users u
        JOIN user_roles ur ON ur.user_id = u.id JOIN roles r ON r.id = ur.role_id AND r.slug = 'pengurus'
        JOIN pengurus p ON p.id = u.pengurus_id AND p.is_active = 1 AND p.archived_at IS NULL $syaratIdentitas
        JOIN keamanan_assignments a ON a.pengurus_id = p.id AND a.is_active = 1
             AND a.tanggal_mulai <= CURDATE() AND (a.tanggal_selesai IS NULL OR a.tanggal_selesai >= CURDATE())
        JOIN tahun_ajaran ta ON ta.id = a.tahun_ajaran_id AND ta.archived_at IS NULL
        WHERE u.is_active = 1 ORDER BY u.id");
    if ($r === false) {
        throw new RuntimeException($db->error);
    }

    return array_map(static fn (array $row): int => (int) $row[0], $r->fetch_all(MYSQLI_NUM));
}
