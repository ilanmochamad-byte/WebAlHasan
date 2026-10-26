<?php

declare(strict_types=1);

/**
 * Pembantu bersama alat rilis PRD V6 Fase 5 (`v6_preflight.php`,
 * `v6_verify.php`, `v6_backup_restore_drill.php`, `v6_manifest.php`).
 * Hanya CLI; hanya membaca basis data.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/** Migrasi V6 (tagihan dan tabungan dirilis bersama: ketiganya sekaligus). */
const V6_MIGRASI = ['027_v6_fase1_bsa.sql', '028_v6_fase2_tagihan_bulanan.sql', '029_v6_fase3_tabungan.sql'];

/** Baseline audit Fase 4 yang diverifikasi (web, mobile). */
const V6_BASELINE_WEB = '75cb144685d02d10df563f67eb1e23a67eec030b';
const V6_BASELINE_MOBILE = '16bcdb7a7dc475f785c328cfdae90ad6b3072e6b';

/** @return array<int, string> seluruh tabel milik V6 (027–029). */
function v6_tabel(): array
{
    return [...\App\Bsa\BsaRepository::TABEL, ...\App\Bsa\TagihanRepository::TABEL, ...\App\Bsa\TabunganRepository::TABEL];
}

function v6_tabel_ada(mysqli $db, string $tabel): bool
{
    $s = $db->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $s->bind_param('s', $tabel);
    $s->execute();
    $ada = (int) $s->get_result()->fetch_row()[0] === 1;
    $s->close();

    return $ada;
}

/**
 * Jumlah baris seluruh tabel dasar di luar tabel V6, `schema_migrations`, dan
 * `audit_logs` (tabel lama: identitas, PSB, V1–V5, notifikasi). Tanpa isi.
 *
 * @return array<string, int>
 */
function v6_hitung_tabel_lama(mysqli $db): array
{
    $kecuali = array_merge(v6_tabel(), ['schema_migrations', 'audit_logs']);
    $hasil = [];
    foreach ($db->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME")->fetch_all(MYSQLI_NUM) as [$t]) {
        if (in_array($t, $kecuali, true)) {
            continue;
        }
        if (!preg_match('/^[a-zA-Z0-9_]+$/D', (string) $t)) {
            throw new RuntimeException('Nama tabel tidak terduga.');
        }
        $hasil[(string) $t] = (int) $db->query('SELECT COUNT(*) FROM `' . $t . '`')->fetch_row()[0];
    }

    return $hasil;
}

/**
 * Sidik jari (SHA-256, dibaca beruntun) yang wajib utuh melewati migrasi,
 * restore, dan rollback: ID + status identitas santri, wali, relasi, dan akun;
 * serta — bila tabel V6 ada — seluruh isi tabel uang V6 (tagihan, komponen,
 * ledger, saldo, pembayaran, kuitansi, pembatalan, setoran transfer, tarif,
 * penyesuaian, penerbitan). Hanya hash yang ditulis; tidak ada nama/nominal.
 *
 * @return array<string, array{baris:int, sha256:string}>
 */
function v6_sidik_jari(mysqli $db): array
{
    $kueri = [
        'santri' => 'SELECT id, nis, is_active, archived_at FROM santri ORDER BY id',
        'wali' => 'SELECT id, is_active, archived_at FROM wali ORDER BY id',
        'santri_wali' => 'SELECT id, santri_id, wali_id, archived_at FROM santri_wali ORDER BY id',
        'users' => 'SELECT id, is_active, pengurus_id, wali_id FROM users ORDER BY id',
    ];
    foreach (v6_tabel() as $t) {
        if ($t !== 'bsa_idempotensi' && v6_tabel_ada($db, $t)) {
            // Seluruh kolom (termasuk waktu dan kolom terbangkit), urut kolom pertama.
            $kueri[$t] = 'SELECT * FROM `' . $t . '` ORDER BY 1';
        }
    }
    $hasil = [];
    foreach ($kueri as $nama => $sql) {
        $baris = 0;
        $ctx = hash_init('sha256');
        $r = $db->query($sql, MYSQLI_USE_RESULT);
        while ($row = $r->fetch_row()) {
            hash_update($ctx, implode("\x1f", array_map(static fn ($v): string => $v === null ? "\0" : (string) $v, $row)) . "\n");
            $baris++;
        }
        $r->free();
        $hasil[$nama] = ['baris' => $baris, 'sha256' => hash_final($ctx)];
    }

    return $hasil;
}

/** @return array<string, bool> migrasi V6 → sudah tercatat */
function v6_terpasang(mysqli $db): array
{
    $hasil = [];
    foreach (V6_MIGRASI as $m) {
        $s = $db->prepare('SELECT COUNT(*) FROM schema_migrations WHERE migration = ?');
        $s->bind_param('s', $m);
        $s->execute();
        $hasil[$m] = (int) $s->get_result()->fetch_row()[0] === 1;
        $s->close();
    }

    return $hasil;
}
