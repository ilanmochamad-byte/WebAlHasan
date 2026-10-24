<?php

declare(strict_types=1);

/**
 * Verifier PRD V5 Fase 3 (migrasi 026). Hanya membaca.
 *
 *   php bin/v5_phase3_verify.php [manifest.json]            # sesudah migrasi
 *   php bin/v5_phase3_verify.php --rollback [manifest.json] # sesudah rollback
 *
 * Sesudah migrasi: empat tabel 026 ada, migrasi 026 tercatat sekali, tidak
 * ada referensi yatim (termasuk kunci asing gabungan versi → versi kunjungan
 * sumber), versi publikasi tanpa celah, tepat satu versi Terbit untuk kepala
 * Terbit dan nol untuk kepala Ditarik, setiap versi lahir dari satu peristiwa
 * Terbit/Ganti, Ganti/Tarik beralasan, revisi = jumlah peristiwa, santri
 * kepala = santri kunjungan, notifikasi V5 hanya penunjuk generik tanpa
 * WhatsApp dan satu InApp per penerima, serta audit_logs tanpa teks
 * ringkasan/alasan. Dengan manifest: jumlah baris seluruh tabel lama tetap.
 * Sesudah rollback: empat tabel dilepas, tabel 024–025 tetap.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Kesehatan\RingkasanService;

$db = app_db();
$args = array_slice($argv, 1);
$rollback = in_array('--rollback', $args, true);
$args = array_values(array_filter($args, static fn (string $a): bool => $a !== '--rollback'));
$gagal = 0;
$periksa = static function (bool $ok, string $label) use (&$gagal): void {
    echo ($ok ? '[lulus] ' : '[gagal] ') . $label . PHP_EOL;
    if (!$ok) {
        $gagal++;
    }
};
$tabelAda = static function (string $t) use ($db): bool {
    $s = $db->prepare('SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $s->bind_param('s', $t);
    $s->execute();
    $ada = (int) $s->get_result()->fetch_assoc()['n'] === 1;
    $s->close();

    return $ada;
};
$n = static fn (string $sql): int => (int) $db->query($sql)->fetch_row()[0];
$baru = ['kesehatan_ringkasan', 'kesehatan_ringkasan_versi', 'kesehatan_ringkasan_peristiwa', 'kesehatan_ringkasan_penerima'];
$lamaKesehatan = ['kesehatan_assignments', 'kesehatan_kunjungan', 'kesehatan_kunjungan_versi', 'kesehatan_idempotensi', 'kesehatan_akses_log',
    'kesehatan_obat', 'kesehatan_rujukan', 'kesehatan_tindak_lanjut', 'kesehatan_versi_rincian'];
$tercatat = $n("SELECT COUNT(*) FROM schema_migrations WHERE migration = '026_v5_fase3_ringkasan_wali.sql'");

foreach ($lamaKesehatan as $t) {
    $periksa($tabelAda($t), 'Tabel 024–025 ' . $t . ' tetap ada');
}
if ($rollback) {
    foreach ($baru as $t) {
        $periksa(!$tabelAda($t), 'Tabel ' . $t . ' sudah dilepas');
    }
    $periksa($tercatat === 0, 'Migrasi 026 tidak lagi tercatat');
} else {
    foreach ($baru as $t) {
        $periksa($tabelAda($t), 'Tabel ' . $t);
    }
    $periksa($tercatat === 1, 'Migrasi 026 tercatat sekali');

    $kendala = $db->query("SELECT k.CONSTRAINT_NAME, k.TABLE_NAME, k.REFERENCED_TABLE_NAME,
            GROUP_CONCAT(k.COLUMN_NAME ORDER BY k.ORDINAL_POSITION) kolom, GROUP_CONCAT(k.REFERENCED_COLUMN_NAME ORDER BY k.ORDINAL_POSITION) rujukan
          FROM information_schema.KEY_COLUMN_USAGE k
         WHERE k.TABLE_SCHEMA = DATABASE() AND k.TABLE_NAME IN ('kesehatan_ringkasan', 'kesehatan_ringkasan_versi', 'kesehatan_ringkasan_peristiwa', 'kesehatan_ringkasan_penerima')
           AND k.REFERENCED_TABLE_NAME IS NOT NULL
         GROUP BY k.CONSTRAINT_NAME, k.TABLE_NAME, k.REFERENCED_TABLE_NAME ORDER BY k.TABLE_NAME, k.CONSTRAINT_NAME")->fetch_all(MYSQLI_ASSOC);
    $periksa(count($kendala) === 14, 'Kunci asing tabel 026 terpasang (' . count($kendala) . ', termasuk satu gabungan)');
    $yatim = 0;
    foreach ($kendala as $fk) {
        foreach ([$fk['TABLE_NAME'], $fk['REFERENCED_TABLE_NAME'], $fk['kolom'], $fk['rujukan']] as $nama) {
            if (!preg_match('/^[a-zA-Z0-9_,]+$/D', (string) $nama)) {
                exit(2);
            }
        }
        $kolom = explode(',', (string) $fk['kolom']);
        $rujukan = explode(',', (string) $fk['rujukan']);
        $on = implode(' AND ', array_map(static fn (string $c, string $r): string => "p.`$r` = c.`$c`", $kolom, $rujukan));
        $tidakNull = implode(' AND ', array_map(static fn (string $c): string => "c.`$c` IS NOT NULL", $kolom));
        $hit = $n("SELECT COUNT(*) FROM `{$fk['TABLE_NAME']}` c LEFT JOIN `{$fk['REFERENCED_TABLE_NAME']}` p ON $on WHERE $tidakNull AND p.`{$rujukan[0]}` IS NULL");
        $yatim += $hit;
        $periksa($hit === 0, "Referensi {$fk['TABLE_NAME']}({$fk['kolom']}) → {$fk['REFERENCED_TABLE_NAME']} tidak yatim");
    }
    echo 'Jumlah referensi yatim tabel 026: ' . $yatim . PHP_EOL;

    $periksa($n('SELECT COUNT(*) FROM kesehatan_ringkasan g LEFT JOIN (SELECT ringkasan_id, MAX(versi) maks, COUNT(*) jumlah FROM kesehatan_ringkasan_versi GROUP BY ringkasan_id) v
        ON v.ringkasan_id = g.id WHERE v.maks IS NULL OR v.maks <> g.versi_terakhir OR v.jumlah <> g.versi_terakhir') === 0, 'Versi publikasi tanpa celah dan sama dengan versi terakhir');
    $periksa($n("SELECT COUNT(*) FROM kesehatan_ringkasan g WHERE g.status = 'Terbit' AND (SELECT COUNT(*) FROM kesehatan_ringkasan_versi v WHERE v.ringkasan_id = g.id AND v.status = 'Terbit'
        AND v.versi = g.versi_berlaku) <> 1") === 0, 'Kepala Terbit punya tepat satu versi Terbit = versi berlaku');
    $periksa($n("SELECT COUNT(*) FROM kesehatan_ringkasan_versi v JOIN kesehatan_ringkasan g ON g.id = v.ringkasan_id
        WHERE v.status = 'Terbit' AND (g.status <> 'Terbit' OR v.versi <> g.versi_berlaku)") === 0, 'Tidak ada versi Terbit lain (kepala Ditarik = nol versi Terbit)');
    $periksa($n("SELECT COUNT(*) FROM kesehatan_ringkasan_versi v WHERE (SELECT COUNT(*) FROM kesehatan_ringkasan_peristiwa e WHERE e.versi_id = v.id AND e.jenis IN ('Terbit', 'Ganti')) <> 1") === 0,
        'Setiap versi lahir dari tepat satu peristiwa Terbit/Ganti');
    $periksa($n("SELECT COUNT(*) FROM kesehatan_ringkasan_peristiwa WHERE jenis IN ('Ganti', 'Tarik') AND (alasan IS NULL OR CHAR_LENGTH(TRIM(alasan)) < 5)") === 0,
        'Setiap penggantian/penarikan punya alasan (≥ 5 karakter)');
    $periksa($n("SELECT COUNT(*) FROM kesehatan_ringkasan_peristiwa e JOIN kesehatan_ringkasan_versi v ON v.id = e.versi_id
        WHERE (e.jenis = 'Terbit' AND v.versi <> 1) OR (e.jenis = 'Ganti' AND v.versi = 1)") === 0, 'Terbit hanya untuk versi 1; Ganti untuk versi berikutnya');
    $periksa($n('SELECT COUNT(*) FROM kesehatan_ringkasan g WHERE g.revisi <> (SELECT COUNT(*) FROM kesehatan_ringkasan_peristiwa e WHERE e.ringkasan_id = g.id)') === 0,
        'Revisi kepala = jumlah peristiwa (tidak ada perubahan tanpa jejak)');
    $periksa($n('SELECT COUNT(*) FROM kesehatan_ringkasan g JOIN kesehatan_kunjungan k ON k.id = g.kunjungan_id WHERE g.santri_id <> k.santri_id') === 0,
        'Santri ringkasan = santri kunjungan sumber');
    $periksa($n('SELECT COUNT(*) FROM kesehatan_ringkasan_versi v JOIN kesehatan_ringkasan g ON g.id = v.ringkasan_id WHERE v.kunjungan_id <> g.kunjungan_id') === 0,
        'Snapshot versi menunjuk kunjungan yang sama dengan kepalanya');
    $periksa($n("SELECT COUNT(*) FROM kesehatan_ringkasan_penerima p JOIN kesehatan_ringkasan_peristiwa e ON e.id = p.peristiwa_id WHERE e.jenis = 'Tarik'") === 0,
        'Penarikan tidak memiliki penerima notifikasi');
    $periksa($n('SELECT COUNT(*) FROM kesehatan_ringkasan_peristiwa e WHERE e.jumlah_penerima <> (SELECT COUNT(*) FROM kesehatan_ringkasan_penerima p WHERE p.peristiwa_id = e.id)') === 0,
        'Jumlah penerima peristiwa = baris penerima');

    $v5 = $db->query("SELECT event_key, event_type, kanal, penerima_user_id, pengajuan_id, judul, isi, data_json FROM notifikasi_outbox WHERE event_type LIKE 'v5\\_kesehatan\\_%'")->fetch_all(MYSQLI_ASSOC);
    $tidakAman = 0;
    foreach ($v5 as $o) {
        $data = json_decode((string) $o['data_json'], true);
        $teks = RingkasanService::teksNotifikasi((string) $o['event_type']);
        $ok = in_array($o['event_type'], RingkasanService::EVENTS, true) && $o['pengajuan_id'] === null && $o['kanal'] !== 'WhatsApp'
            && is_array($data) && array_keys($data) === ['tipe', 'ringkasan_id'] && $data['tipe'] === RingkasanService::TIPE_TAUTAN && is_int($data['ringkasan_id'])
            && $o['judul'] === $teks['judul'] && $o['isi'] === $teks['isi'] && preg_match('/^v5:kesehatan:ringkasan:' . $data['ringkasan_id'] . ':v[1-9][0-9]*$/D', (string) $o['event_key']) === 1;
        $tidakAman += $ok ? 0 : 1;
    }
    $periksa($tidakAman === 0, 'Notifikasi V5 (' . count($v5) . ' baris): penunjuk + teks generik katalog, tanpa WhatsApp/pengajuan');
    $periksa($n("SELECT COUNT(*) FROM kesehatan_ringkasan_penerima p
        JOIN kesehatan_ringkasan_peristiwa e ON e.id = p.peristiwa_id JOIN kesehatan_ringkasan_versi v ON v.id = e.versi_id
        WHERE NOT EXISTS (SELECT 1 FROM notifikasi_outbox o WHERE o.kanal = 'InApp' AND o.penerima_user_id = p.user_id
            AND o.event_key = CONCAT('v5:kesehatan:ringkasan:', e.ringkasan_id, ':v', v.versi))") === 0, 'Setiap penerima peristiwa punya tepat satu InApp');
    $periksa($n("SELECT COUNT(*) FROM notifikasi_outbox o WHERE o.event_type LIKE 'v5\\_kesehatan\\_%' AND NOT EXISTS (
        SELECT 1 FROM kesehatan_ringkasan_penerima p JOIN kesehatan_ringkasan_peristiwa e ON e.id = p.peristiwa_id JOIN kesehatan_ringkasan_versi v ON v.id = e.versi_id
         WHERE p.user_id = o.penerima_user_id AND o.event_key = CONCAT('v5:kesehatan:ringkasan:', e.ringkasan_id, ':v', v.versi))") === 0, 'Tidak ada notifikasi V5 tanpa penerima tercatat');
    $bocor = $n("SELECT COUNT(*) FROM audit_logs WHERE action LIKE 'kesehatan.ringkasan.%'
        AND (COALESCE(before_json, '') REGEXP '\"(ringkasan|tindakan_umum|tindak_lanjut_wali|alasan|keluhan|catatan_klinis)\"'
          OR COALESCE(after_json, '') REGEXP '\"(ringkasan|tindakan_umum|tindak_lanjut_wali|alasan|keluhan|catatan_klinis)\"')");
    $periksa($bocor === 0, 'audit_logs ringkasan tanpa teks ringkasan, alasan, atau isi klinis');
}

if (isset($args[0])) {
    $manifest = json_decode((string) file_get_contents($args[0]), true);
    if (!is_array($manifest) || !isset($manifest['legacy_counts']) || !is_array($manifest['legacy_counts'])) {
        fwrite(STDERR, "Manifest tidak valid.\n");
        exit(2);
    }
    foreach ($manifest['legacy_counts'] as $t => $sebelum) {
        if (!preg_match('/^[a-zA-Z0-9_]+$/D', (string) $t)) {
            exit(2);
        }
        if (!$tabelAda((string) $t)) {
            $periksa(false, "Tabel lama $t masih ada");
            continue;
        }
        $kini = $n('SELECT COUNT(*) FROM `' . $t . '`');
        $periksa($kini === (int) $sebelum, "Jumlah baris lama $t tetap $sebelum");
    }
} else {
    echo "[belum diperiksa] Perbandingan baris tabel lama: berikan path manifest preflight.\n";
}
exit($gagal > 0 ? 1 : 0);
