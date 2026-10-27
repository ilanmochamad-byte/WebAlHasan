<?php

declare(strict_types=1);

/**
 * Pembantu bersama alat rilis PRD V7 Fase 5 (`v7_preflight.php`,
 * `v7_verify.php`, `v7_manifest.php`, `v7_backup_restore_drill.php`).
 * Hanya CLI; hanya membaca basis data. Memakai pembantu Fase 1–2.
 */

require_once __DIR__ . '/_v7_phase2.php';

/** Migrasi V7 (030 pengajuan wali, 031 tinjauan pembimbing), dirilis bersama. */
const V7_MIGRASI = ['030_v7_fase1_pengajuan_wali.sql', '031_v7_fase2_tinjauan_pembimbing.sql'];

/** Baseline audit Fase 4 yang diverifikasi (web, mobile). */
const V7_BASELINE_WEB = '851ef0a26d1b8dd4e1db80ecf4f4ce8104ee8da7';
const V7_BASELINE_MOBILE = '7478f7cb153e4f8af1a1b85791ce843a29733bd2';

/** Tabel baru milik V7 (030–031). */
const V7_TABEL = ['izin_pembimbing_penetapan', 'izin_pembimbing_tinjauan', 'izin_wali_perbaikan'];

/** Kolom aditif 030 pada `izin_pengajuan`; kolom lain milik V2/warisan. */
const V7_KOLOM_PENGAJUAN = ['asal_pengajuan', 'wali_id', 'catatan_wali', 'pembimbing_pengurus_id', 'pembimbing_routing_kandidat',
    'pembimbing_routing_catatan', 'pembimbing_routing_pada', 'pembimbing_ditetapkan_oleh_user_id', 'pembimbing_ditetapkan_pada'];

/**
 * Tabel yang isinya berubah karena pemakaian biasa (sesi, token, percobaan
 * masuk, pembatas laju, antrean worker). Jumlah barisnya tetap dibandingkan,
 * tetapi isinya tidak di-hash: verifier dijalankan segera sesudah migrasi atau
 * pada DB pulihan tanpa lalu lintas, dan isi kredensial tidak perlu dibaca.
 */
const V7_TABEL_TIDAK_DIHASH = ['audit_logs', 'schema_migrations'];

/** @return array<string, bool> migrasi V7 → sudah tercatat */
function v7_terpasang(mysqli $db): array
{
    $hasil = [];
    foreach (V7_MIGRASI as $m) {
        $s = $db->prepare('SELECT COUNT(*) FROM schema_migrations WHERE migration = ?');
        $s->bind_param('s', $m);
        $s->execute();
        $hasil[$m] = (int) $s->get_result()->fetch_row()[0] === 1;
        $s->close();
    }

    return $hasil;
}

/**
 * Hash multiset isi satu kueri: setiap baris di-hash SHA-256, lalu dijumlah
 * per lajur modulo bilangan prima. Tidak bergantung urutan baris (tabel tanpa
 * kunci tunggal, ORDER BY yang tidak stabil) dan baris kembar tidak saling
 * meniadakan. Hanya hash dan jumlah yang dikembalikan; tidak ada isi.
 *
 * @return array{baris:int, sha256:string}
 */
function v7_hash_kueri(mysqli $db, string $sql): array
{
    $p = 2305843009213693951; // 2^61 − 1
    $lajur = array_fill(0, 4, 0);
    $baris = 0;
    $r = $db->query($sql, MYSQLI_USE_RESULT);
    if ($r === false) {
        throw new RuntimeException($db->error . ' :: ' . $sql);
    }
    while ($row = $r->fetch_row()) {
        $h = hash('sha256', implode("\x1f", array_map(static fn ($v): string => $v === null ? "\0" : (string) $v, $row)));
        for ($i = 0; $i < 4; $i++) {
            $lajur[$i] = ($lajur[$i] + hexdec(substr($h, $i * 13, 13))) % $p;
        }
        $baris++;
    }
    $r->free();

    return ['baris' => $baris, 'sha256' => hash('sha256', $baris . ':' . implode(':', $lajur))];
}

/** @return array<int, string> kolom tabel menurut urutan ordinal */
function v7_kolom(mysqli $db, string $tabel): array
{
    $s = $db->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION');
    $s->bind_param('s', $tabel);
    $s->execute();
    $kolom = array_column($s->get_result()->fetch_all(MYSQLI_NUM), 0);
    $s->close();

    return array_map('strval', $kolom);
}

/** @return array<int, string> seluruh tabel dasar */
function v7_semua_tabel(mysqli $db): array
{
    $hasil = [];
    foreach ($db->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME")->fetch_all(MYSQLI_NUM) as [$t]) {
        if (!preg_match('/^[a-zA-Z0-9_]+$/D', (string) $t)) {
            throw new RuntimeException('Nama tabel tidak terduga.');
        }
        $hasil[] = (string) $t;
    }

    return $hasil;
}

/**
 * Snapshot preservasi rilis V7. Hanya hitungan dan hash.
 *
 *  - `legacy_counts`: jumlah baris seluruh tabel lama (di luar tabel V7,
 *    `schema_migrations`, `audit_logs`); `izin_pengajuan` hanya baris
 *    berasal Pengurus/warisan.
 *  - `isi_tabel_lama`: hash multiset ISI seluruh kolom setiap tabel lama
 *    selain `izin_pengajuan` — identitas, PSB, V1–V6 (kesehatan, BSA), dan
 *    tabel perizinan V2 — untuk migrasi, restore, dan rollback tanpa
 *    lalu lintas.
 *  - `irisan_v2`: hash isi V2/warisan yang TIDAK boleh berubah oleh pemakaian
 *    V7: kolom V2 baris Pengurus `izin_pengajuan`, serta keputusan, riwayat,
 *    dan kunci idempotensi milik baris itu; `perizinan` warisan. Tetap sama
 *    walaupun pengajuan wali dibuat/diputus di antaranya.
 *  - `isi_v7`: bila 030–031 terpasang, hash isi tabel V7 dan seluruh kolom
 *    baris Wali `izin_pengajuan` (untuk restore).
 *
 * @return array{legacy_counts:array<string,int>, isi_tabel_lama:array<string,array{baris:int,sha256:string}>,
 *               irisan_v2:array<string,array{baris:int,sha256:string}>, isi_v7:array<string,array{baris:int,sha256:string}>}
 */
function v7_snapshot(mysqli $db): array
{
    $adaAsal = v7f1_ada_kolom($db, 'izin_pengajuan', 'asal_pengajuan');
    $kecuali = array_merge(V7_TABEL, V7_TABEL_TIDAK_DIHASH);
    $counts = [];
    $isi = [];
    foreach (v7_semua_tabel($db) as $t) {
        if (in_array($t, $kecuali, true)) {
            continue;
        }
        if ($t === 'izin_pengajuan') {
            $counts[$t] = (int) v7f1_nilai($db, 'SELECT COUNT(*) FROM izin_pengajuan' . ($adaAsal ? " WHERE asal_pengajuan = 'Pengurus'" : ''));
            continue;
        }
        $isi[$t] = v7_hash_kueri($db, 'SELECT * FROM `' . $t . '`');
        $counts[$t] = $isi[$t]['baris'];
    }

    $kolomV2 = array_values(array_diff(v7_kolom($db, 'izin_pengajuan'), V7_KOLOM_PENGAJUAN));
    $daftar = implode(', ', array_map(static fn (string $k): string => 'p.`' . $k . '`', $kolomV2));
    $barisV2 = $adaAsal ? "p.asal_pengajuan = 'Pengurus'" : '1 = 1';
    $irisan = [
        'izin_pengajuan_v2' => v7_hash_kueri($db, "SELECT $daftar FROM izin_pengajuan p WHERE $barisV2"),
        'izin_keputusan_v2' => v7_hash_kueri($db, "SELECT k.* FROM izin_keputusan k JOIN izin_pengajuan p ON p.id = k.pengajuan_id WHERE $barisV2"),
        'izin_riwayat_status_v2' => v7_hash_kueri($db, "SELECT r.* FROM izin_riwayat_status r JOIN izin_pengajuan p ON p.id = r.pengajuan_id WHERE $barisV2"),
        'izin_idempotency_keys_v2' => v7_hash_kueri($db, "SELECT k.* FROM izin_idempotency_keys k LEFT JOIN izin_pengajuan p ON p.id = k.pengajuan_id
            WHERE k.operation NOT LIKE 'izin.wali.%' AND (k.pengajuan_id IS NULL OR $barisV2)"),
        'perizinan' => v7_hash_kueri($db, 'SELECT * FROM perizinan'),
        'identitas' => v7_hash_kueri($db, 'SELECT s.id, s.nis, s.is_active, s.archived_at, sw.id, sw.wali_id, sw.archived_at
            FROM santri s LEFT JOIN santri_wali sw ON sw.santri_id = s.id'),
    ];

    $isiV7 = [];
    if ($adaAsal) {
        $isiV7['izin_pengajuan_wali'] = v7_hash_kueri($db, "SELECT * FROM izin_pengajuan WHERE asal_pengajuan = 'Wali'");
        foreach (V7_TABEL as $t) {
            if (v7f1_ada_tabel($db, $t)) {
                $isiV7[$t] = v7_hash_kueri($db, 'SELECT * FROM `' . $t . '`');
            }
        }
    }

    return ['legacy_counts' => $counts, 'isi_tabel_lama' => $isi, 'irisan_v2' => $irisan, 'isi_v7' => $isiV7];
}

/** Peristiwa riwayat baris Wali → aksi audit yang wajib menyertainya. */
const V7_PERISTIWA_AUDIT = [
    'pengajuan_wali_dibuat' => 'izin_wali_pengajuan_dibuat',
    'pembimbing_ditetapkan' => 'izin_wali_pembimbing_ditetapkan',
    'pembimbing_ditetapkan_ulang' => 'izin_wali_pembimbing_ditetapkan',
    'tinjauan_minta_perbaikan' => 'izin_wali_diminta_perbaikan',
    'tinjauan_ditolak_pembimbing' => 'izin_wali_ditolak_pembimbing',
    'tinjauan_diteruskan' => 'izin_wali_diteruskan',
    'perbaikan_wali_dikirim' => 'izin_wali_perbaikan_dikirim',
    'pembatalan_wali' => 'izin_wali_dibatalkan',
    'murobi_ditetapkan' => 'izin_murobi_assigned',
    'murobi_ditetapkan_ulang' => 'izin_murobi_assigned',
    'keputusan' => 'izin_decision_recorded',
    'keputusan_dikoreksi' => 'izin_decision_corrected',
];

/** Peristiwa yang wajib beralasan/bercatatan pada riwayat. */
const V7_PERISTIWA_BERALASAN = ['pembimbing_ditetapkan', 'pembimbing_ditetapkan_ulang', 'tinjauan_minta_perbaikan', 'tinjauan_ditolak_pembimbing',
    'pembatalan_wali', 'murobi_ditetapkan', 'murobi_ditetapkan_ulang', 'keputusan', 'keputusan_dikoreksi'];

/**
 * Transisi sah baris Wali: peristiwa → [status sebelum yang boleh, status
 * sesudah yang boleh]. `null` sebelum hanya untuk pembuatan.
 */
const V7_TRANSISI = [
    'pengajuan_wali_dibuat' => [[null], ['Menunggu Pembimbing', 'Perlu Penetapan Pembimbing']],
    'routing_pembimbing_otomatis' => [['Menunggu Pembimbing'], ['Menunggu Pembimbing']],
    'routing_pembimbing_perlu_admin' => [['Perlu Penetapan Pembimbing'], ['Perlu Penetapan Pembimbing']],
    'pembimbing_ditetapkan' => [['Menunggu Pembimbing', 'Perlu Penetapan Pembimbing'], ['Menunggu Pembimbing']],
    'pembimbing_ditetapkan_ulang' => [['Menunggu Pembimbing', 'Perlu Penetapan Pembimbing'], ['Menunggu Pembimbing']],
    'tinjauan_minta_perbaikan' => [['Menunggu Pembimbing'], ['Perlu Perbaikan Wali']],
    'tinjauan_ditolak_pembimbing' => [['Menunggu Pembimbing'], ['Ditolak Pembimbing']],
    'tinjauan_diteruskan' => [['Menunggu Pembimbing'], ['Diajukan', 'Perlu Penetapan Admin']],
    'perbaikan_wali_dikirim' => [['Perlu Perbaikan Wali'], ['Menunggu Pembimbing']],
    'routing_otomatis' => [['Diajukan'], ['Diajukan']],
    'routing_perlu_admin' => [['Perlu Penetapan Admin'], ['Perlu Penetapan Admin']],
    'murobi_ditetapkan' => [['Diajukan', 'Perlu Penetapan Admin'], ['Diajukan']],
    'murobi_ditetapkan_ulang' => [['Diajukan', 'Perlu Penetapan Admin'], ['Diajukan']],
    // Murobi memutus dari Diajukan; admin pengganti juga dari Perlu Penetapan Admin.
    'keputusan' => [['Diajukan', 'Perlu Penetapan Admin'], ['Disetujui', 'Ditolak']],
    'keputusan_dikoreksi' => [['Disetujui', 'Ditolak'], ['Disetujui', 'Ditolak']],
    'pembatalan_wali' => [['Menunggu Pembimbing', 'Perlu Penetapan Pembimbing', 'Perlu Perbaikan Wali', 'Diajukan', 'Perlu Penetapan Admin'], ['Dibatalkan']],
];
