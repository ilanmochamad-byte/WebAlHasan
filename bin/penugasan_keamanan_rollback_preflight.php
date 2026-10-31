<?php

declare(strict_types=1);

/**
 * Preflight rollback penugasan lintas guru/pengurus dan Keamanan (Fase 5).
 * Hanya membaca. Menilai migrasi yang akan dilepas `bin/migrate.php rollback`
 * berikutnya (migrasi terakhir yang tercatat):
 *
 *   php bin/penugasan_keamanan_rollback_preflight.php && php bin/migrate.php rollback
 *
 *   - 033 diizinkan hanya bila `keamanan_assignments` kosong (termasuk
 *     penugasan yang sudah berakhir: riwayat pemegang hak baca tidak boleh
 *     hilang);
 *   - 032 diizinkan hanya bila belum ada identitas pengurus milik guru;
 *   - selain itu: bukan migrasi workstream ini, keluar 1.
 * Penjaga SQL di berkas rollback menolak keadaan yang sama walau preflight ini
 * dilewati, asalkan CHECK ditegakkan; karena itu CHECK diperiksa juga.
 * Kode keluar 0 = rollback berikutnya aman dijalankan.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/_penugasan_keamanan_rilis.php';

$db = app_db();
$n = static fn (string $sql): int => (int) v7f1_nilai($db, $sql);
echo '[info] Basis data terpilih: ' . (string) v7f1_nilai($db, 'SELECT DATABASE()') . ' (hanya membaca).' . PHP_EOL;
$terakhir = (string) v7f1_nilai($db, 'SELECT migration FROM schema_migrations ORDER BY id DESC LIMIT 1');
echo '[info] Migrasi terakhir (yang akan dilepas rollback berikutnya): ' . $terakhir . PHP_EOL;

if (!v7f1_check_aktif($db)) {
    echo "[gagal] CHECK tidak aktif pada sesi/global: penjaga SQL rollback tidak dapat menolak keadaan berisi data. Jangan rollback.\n";
    exit(1);
}

if ($terakhir === PK_MIGRASI[1]) {
    $baris = v7f1_ada_tabel($db, PK_TABEL) ? $n('SELECT COUNT(*) FROM ' . PK_TABEL) : 0;
    echo '[info] Baris keamanan_assignments: ' . $baris . ' (aktif ' . ($baris > 0 ? $n('SELECT COUNT(*) FROM ' . PK_TABEL . ' WHERE is_active = 1') : 0) . ')' . PHP_EOL;
    echo '[info] Jejak akses keamanan.* di audit_logs: ' . $n("SELECT COUNT(*) FROM audit_logs WHERE action LIKE 'keamanan.%'") . ' (tetap, tidak dilepas rollback)' . PHP_EOL;
    if ($baris > 0) {
        echo "Rollback 033 DITOLAK. Riwayat penugasan Keamanan tidak boleh hilang; akhiri penugasan lewat halaman Penugasan, pulihkan dari backup, atau buat migrasi korektif.\n";
        exit(1);
    }
    echo "Rollback 033 diizinkan: keamanan_assignments kosong. Menu Keamanan hilang; jenis Keamanan tampil \"belum dipasang\".\n";
    exit(0);
}

if ($terakhir === PK_MIGRASI[0]) {
    $identitas = v7f1_ada_kolom($db, 'pengurus', PK_KOLOM_PENGURUS) ? $n('SELECT COUNT(*) FROM pengurus WHERE guru_id IS NOT NULL') : 0;
    echo '[info] Identitas pengurus milik guru: ' . $identitas . PHP_EOL;
    if ($identitas > 0) {
        echo "Rollback 032 DITOLAK. Identitas pengurus milik guru akan menjadi pengurus biasa yang terhubung ke akun guru dan kehilangan sinkronisasi status; pulihkan dari backup atau buat migrasi korektif.\n";
        exit(1);
    }
    echo "Rollback 032 diizinkan: belum ada identitas pengurus milik guru. Pemilihan guru pada jenis berbasis pengurus tampil \"belum dipasang\".\n";
    exit(0);
}

echo "Migrasi terakhir bukan 032/033 penugasan Keamanan; preflight ini tidak menilai rollback tersebut.\n";
exit(1);
