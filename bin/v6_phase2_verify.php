<?php

declare(strict_types=1);

/**
 * Verifier PRD V6 Fase 2 (migrasi 028, tagihan bulanan lengkap). Hanya membaca.
 *
 *   php bin/v6_phase2_verify.php [manifest.json]            # sesudah migrasi
 *   php bin/v6_phase2_verify.php --rollback [manifest.json] # sesudah rollback
 *
 * Sesudah migrasi: invarians Fase 1 (`bin/v6_phase1_verify.php`, termasuk
 * total tagihan = Σ komponen + Σ penyesuaian bertanda, terbayar = Σ
 * pembayaran sah, saldo = ledger) DITAMBAH sembilan tabel 028 (InnoDB, 28
 * kunci asing, 0 yatim) dan invarians tagihan bulanan:
 *   - tarif: total = Σ komponen; satu tarif Berlaku per unit+periode mulai;
 *     setiap tarif Diganti menunjuk penggantinya pada unit+periode yang sama;
 *   - tagihan: riwayat versi 1..versi tanpa celah, versi/total/jatuh tempo
 *     terakhir sama dengan tagihan, status Dibatalkan ⇔ riwayat terakhir
 *     Dibatalkan, tagihan dibatalkan tanpa pembayaran sah/menunggu;
 *     terbayar + transfer menunggu ≤ total;
 *   - pembayaran: Sah ⇒ verifikator + kuitansi + tanpa pembatalan; Menunggu ⇒
 *     transfer tanpa kuitansi/pembatalan; Dibatalkan ⇒ tepat satu entri
 *     pembatalan; kuitansi: total = terbayar sebelum + nominal + sisa
 *     sesudah; nomor kuitansi final (bukan sementara);
 *   - penerbitan: jumlah header = hasil per santri; tagihan hasil Terbit pada
 *     santri dan periode yang sama; baris penyesuaian dari penyesuaian santri
 *     milik santri tagihan itu.
 * Dengan manifest: jumlah baris seluruh tabel lama dan sidik jari ID serta
 * isi uang 027 tetap.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once dirname(__DIR__) . '/app/bootstrap.php';

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
$angka = static fn (string $sql): int => (int) $db->query($sql)->fetch_row()[0];
$tabelAda = static function (string $t) use ($db): bool {
    $s = $db->prepare('SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $s->bind_param('s', $t);
    $s->execute();
    $ada = (int) $s->get_result()->fetch_assoc()['n'] === 1;
    $s->close();

    return $ada;
};
$fase2 = \App\Bsa\TagihanRepository::TABEL;
$tercatat = $angka("SELECT COUNT(*) FROM schema_migrations WHERE migration = '028_v6_fase2_tagihan_bulanan.sql'");

if ($rollback) {
    foreach ($fase2 as $t) {
        $periksa(!$tabelAda($t), 'Tabel ' . $t . ' sudah dilepas');
    }
    $periksa($tercatat === 0, 'Migrasi 028 tidak lagi tercatat');
    foreach (['bsa_tagihan', 'bsa_tagihan_komponen', 'bsa_tabungan_akun', 'bsa_tabungan_transaksi', 'bsa_pembayaran', 'bsa_idempotensi'] as $t) {
        $periksa($tabelAda($t), 'Tabel 027 ' . $t . ' tetap ada');
    }
} else {
    echo '--- Invarians Fase 1 (027) ---' . PHP_EOL;
    $keluaran = [];
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/v6_phase1_verify.php') . ' 2>&1', $keluaran, $kodeFase1);
    foreach ($keluaran as $baris) {
        if (!str_starts_with($baris, '[belum diperiksa]')) {
            echo '  ' . $baris . PHP_EOL;
        }
    }
    $periksa($kodeFase1 === 0, 'Seluruh invarians Fase 1 lulus');
    echo '--- Fase 2 (028) ---' . PHP_EOL;
    foreach ($fase2 as $t) {
        $periksa($tabelAda($t), 'Tabel ' . $t);
    }
    $periksa($tercatat === 1, 'Migrasi 028 tercatat sekali');
    $daftar = "'" . implode("', '", $fase2) . "'";
    $periksa($angka("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ($daftar) AND ENGINE <> 'InnoDB'") === 0, 'Seluruh tabel Fase 2 memakai InnoDB');
    $fks = $db->query("SELECT TABLE_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ($daftar) AND REFERENCED_TABLE_NAME IS NOT NULL ORDER BY TABLE_NAME, COLUMN_NAME")->fetch_all(MYSQLI_ASSOC);
    $periksa(count($fks) === 28, 'Kunci asing tabel Fase 2 terpasang (' . count($fks) . ' dari 28)');
    $yatim = 0;
    foreach ($fks as $fk) {
        foreach ($fk as $nama) {
            if (!preg_match('/^[a-zA-Z0-9_]+$/D', (string) $nama)) {
                exit(2);
            }
        }
        [$c, $col, $p, $key] = [$fk['TABLE_NAME'], $fk['COLUMN_NAME'], $fk['REFERENCED_TABLE_NAME'], $fk['REFERENCED_COLUMN_NAME']];
        $n = $angka("SELECT COUNT(*) FROM `$c` c LEFT JOIN `$p` p ON p.`$key` = c.`$col` WHERE c.`$col` IS NOT NULL AND p.`$key` IS NULL");
        $yatim += $n;
        $periksa($n === 0, "Referensi $c.$col → $p tidak yatim");
    }
    echo 'Jumlah referensi yatim tabel Fase 2: ' . $yatim . PHP_EOL;

    // Tarif.
    $periksa($angka('SELECT COUNT(*) FROM bsa_tarif t WHERE t.total <> (SELECT COALESCE(SUM(k.nominal), 0) FROM bsa_tarif_komponen k WHERE k.tarif_id = t.id)') === 0, 'Total tarif = Σ komponen tarif');
    $periksa($angka("SELECT COUNT(*) FROM (SELECT jenjang, berlaku_mulai FROM bsa_tarif WHERE status = 'Berlaku' GROUP BY jenjang, berlaku_mulai HAVING COUNT(*) > 1) g") === 0, 'Paling banyak satu tarif berlaku per unit per periode mulai');
    $periksa($angka("SELECT COUNT(*) FROM bsa_tarif t LEFT JOIN bsa_tarif b ON b.id = t.diganti_oleh_id
        WHERE t.status = 'Diganti' AND (b.id IS NULL OR b.jenjang <> t.jenjang OR b.berlaku_mulai <> t.berlaku_mulai OR b.id <= t.id)") === 0, 'Setiap tarif Diganti menunjuk pengganti yang lebih baru pada unit dan periode yang sama');
    $periksa($angka("SELECT COUNT(*) FROM bsa_tarif WHERE status = 'Berlaku' AND diganti_oleh_id IS NOT NULL") === 0, 'Tarif berlaku tidak memiliki pengganti');

    // Tagihan dan riwayat versi.
    $periksa($angka('SELECT COUNT(*) FROM bsa_tagihan t WHERE EXISTS (SELECT 1 FROM bsa_tagihan_riwayat r WHERE r.tagihan_id = t.id)
        AND (t.versi <> (SELECT COUNT(*) FROM bsa_tagihan_riwayat r WHERE r.tagihan_id = t.id) OR t.versi <> (SELECT MAX(r.versi) FROM bsa_tagihan_riwayat r WHERE r.tagihan_id = t.id))') === 0,
        'Riwayat versi tagihan 1..versi tanpa celah');
    $periksa($angka('SELECT COUNT(*) FROM bsa_tagihan t WHERE t.versi > 1 AND NOT EXISTS (SELECT 1 FROM bsa_tagihan_riwayat r WHERE r.tagihan_id = t.id)') === 0, 'Setiap tagihan yang pernah direvisi/dibatalkan punya riwayat');
    $periksa($angka("SELECT COUNT(*) FROM bsa_tagihan t JOIN bsa_tagihan_riwayat r ON r.tagihan_id = t.id AND r.versi = t.versi
        WHERE r.total <> t.total OR r.jatuh_tempo <> t.jatuh_tempo OR ((t.status = 'Dibatalkan') <> (r.tindakan = 'Dibatalkan'))") === 0, 'Riwayat versi terakhir = total, jatuh tempo, dan status tagihan');
    $periksa($angka("SELECT COUNT(*) FROM bsa_tagihan_riwayat WHERE versi = 1 AND tindakan <> 'Terbit'") === 0 && $angka("SELECT COUNT(*) FROM bsa_tagihan_riwayat WHERE versi > 1 AND tindakan = 'Terbit'") === 0,
        'Versi 1 = Terbit; versi berikutnya Revisi/Dibatalkan beralasan');
    $periksa($angka("SELECT COUNT(*) FROM bsa_tagihan t WHERE t.status = 'Dibatalkan' AND (t.terbayar <> 0 OR EXISTS (SELECT 1 FROM bsa_pembayaran p WHERE p.tagihan_id = t.id AND p.status IN ('Sah', 'Menunggu')))") === 0,
        'Tagihan dibatalkan tanpa pembayaran sah atau menunggu');
    $periksa($angka("SELECT COUNT(*) FROM bsa_tagihan t WHERE t.terbayar + (SELECT COALESCE(SUM(p.nominal), 0) FROM bsa_pembayaran p WHERE p.tagihan_id = t.id AND p.status = 'Menunggu') > t.total") === 0,
        'Terbayar + transfer menunggu ≤ total (tidak ada pembayaran berlebih)');
    $periksa($angka('SELECT COUNT(*) FROM bsa_tagihan_penyesuaian b JOIN bsa_tagihan t ON t.id = b.tagihan_id WHERE b.versi > t.versi') === 0, 'Baris penyesuaian tagihan tidak melampaui versi tagihan');
    $periksa($angka('SELECT COUNT(*) FROM bsa_tagihan_penyesuaian b JOIN bsa_tagihan t ON t.id = b.tagihan_id JOIN bsa_penyesuaian j ON j.id = b.penyesuaian_id WHERE j.santri_id <> t.santri_id') === 0,
        'Baris dari penyesuaian santri hanya pada tagihan santri itu');

    // Pembayaran, kuitansi, pembatalan.
    $periksa($angka("SELECT COUNT(*) FROM bsa_pembayaran p LEFT JOIN bsa_pembayaran_kuitansi k ON k.pembayaran_id = p.id
        WHERE p.status = 'Sah' AND (p.diverifikasi_oleh IS NULL OR p.diverifikasi_pada IS NULL OR k.pembayaran_id IS NULL)") === 0, 'Pembayaran sah punya verifikator, waktu verifikasi, dan kuitansi');
    $periksa($angka("SELECT COUNT(*) FROM bsa_pembayaran p WHERE p.status = 'Menunggu' AND (p.sumber <> 'Transfer' OR p.diverifikasi_oleh IS NOT NULL
        OR EXISTS (SELECT 1 FROM bsa_pembayaran_kuitansi k WHERE k.pembayaran_id = p.id) OR EXISTS (SELECT 1 FROM bsa_pembayaran_pembatalan x WHERE x.pembayaran_id = p.id))") === 0,
        'Pembayaran menunggu hanya transfer, tanpa verifikator, kuitansi, atau pembatalan');
    $periksa($angka("SELECT COUNT(*) FROM bsa_pembayaran p LEFT JOIN bsa_pembayaran_pembatalan x ON x.pembayaran_id = p.id WHERE (p.status = 'Dibatalkan') <> (x.pembayaran_id IS NOT NULL)") === 0,
        'Pembayaran dibatalkan ⇔ tepat satu entri pembatalan beralasan');
    $periksa($angka("SELECT COUNT(*) FROM bsa_pembayaran p JOIN bsa_pembayaran_pembatalan x ON x.pembayaran_id = p.id LEFT JOIN bsa_pembayaran_kuitansi k ON k.pembayaran_id = p.id
        WHERE (x.status_sebelum = 'Sah') <> (k.pembayaran_id IS NOT NULL)") === 0, 'Pembatalan pembayaran sah menyimpan kuitansinya; penolakan transfer tanpa kuitansi');
    $periksa($angka('SELECT COUNT(*) FROM bsa_pembayaran_kuitansi k JOIN bsa_pembayaran p ON p.id = k.pembayaran_id WHERE k.total_tagihan <> k.terbayar_sebelum + p.nominal + k.sisa_sesudah') === 0,
        'Kuitansi: total tagihan = terbayar sebelum + nominal + sisa sesudah');
    $periksa($angka("SELECT COUNT(*) FROM bsa_pembayaran WHERE nomor_kuitansi NOT REGEXP '^BSA-KW-[0-9]{4}-[0-9]{6,}$'") === 0, 'Nomor kuitansi final (tidak ada nomor sementara)');
    $periksa($angka('SELECT COUNT(*) FROM bsa_pembayaran p JOIN bsa_tagihan t ON t.id = p.tagihan_id WHERE p.santri_id <> t.santri_id') === 0, 'Santri pembayaran = santri tagihan');
    $periksa($angka("SELECT COUNT(*) FROM bsa_pembayaran WHERE sumber = 'Transfer' AND (referensi IS NULL OR referensi = '')") === 0, 'Setiap pembayaran transfer punya referensi');

    // Penerbitan massal.
    $periksa($angka("SELECT COUNT(*) FROM bsa_penerbitan b WHERE b.jumlah_sasaran <> (SELECT COUNT(*) FROM bsa_penerbitan_santri h WHERE h.penerbitan_id = b.id)
        OR b.jumlah_terbit <> (SELECT COUNT(*) FROM bsa_penerbitan_santri h WHERE h.penerbitan_id = b.id AND h.hasil = 'Terbit')
        OR b.jumlah_sudah_ada <> (SELECT COUNT(*) FROM bsa_penerbitan_santri h WHERE h.penerbitan_id = b.id AND h.hasil = 'SudahAda')") === 0, 'Jumlah header penerbitan = hasil per santri');
    $periksa($angka("SELECT COUNT(*) FROM bsa_penerbitan_santri h JOIN bsa_penerbitan b ON b.id = h.penerbitan_id JOIN bsa_tagihan t ON t.id = h.tagihan_id
        WHERE t.santri_id <> h.santri_id OR t.periode <> b.periode") === 0, 'Tagihan hasil penerbitan pada santri dan periode yang sama');

    $bocor = $angka("SELECT COUNT(*) FROM audit_logs WHERE action LIKE 'bsa.%'
        AND (COALESCE(after_json, '') REGEXP '\"(nominal|saldo|total|terbayar|sisa|referensi|tujuan|catatan|alasan|nama)\"' OR COALESCE(after_json, '') LIKE '%TRF%')");
    $periksa($bocor === 0, 'audit_logs bsa.* (termasuk Fase 2) tanpa nominal, sisa, referensi, alasan, atau nama');
}

if (isset($args[0])) {
    $manifest = json_decode((string) file_get_contents($args[0]), true);
    if (!is_array($manifest) || ($manifest['fase'] ?? null) !== 'v6-fase2' || !is_array($manifest['legacy_counts'] ?? null)) {
        fwrite(STDERR, "Manifest tidak valid (gunakan manifest bin/v6_phase2_preflight.php).\n");
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
        $periksa($angka('SELECT COUNT(*) FROM `' . $t . '`') === (int) $sebelum, "Jumlah baris lama $t tetap $sebelum");
    }
    $sidik = $manifest['id_fingerprints'] ?? [];
    $db->query('SET SESSION group_concat_max_len = 4294967295');
    $kini = [];
    foreach (['santri', 'wali', 'santri_wali', 'users'] as $t) {
        $kini[$t] = (string) $db->query("SELECT SHA2(COALESCE(GROUP_CONCAT(id ORDER BY id SEPARATOR ','), ''), 256) AS h FROM `$t`")->fetch_assoc()['h'];
    }
    $kini['santri_wali_relasi'] = (string) $db->query("SELECT SHA2(COALESCE(GROUP_CONCAT(CONCAT_WS(':', id, santri_id, wali_id, COALESCE(archived_at, '-')) ORDER BY id SEPARATOR ','), ''), 256) AS h FROM santri_wali")->fetch_assoc()['h'];
    $kini['bsa_tagihan'] = (string) $db->query("SELECT SHA2(COALESCE(GROUP_CONCAT(CONCAT_WS(':', id, santri_id, periode, total, terbayar, status, versi) ORDER BY id SEPARATOR ','), ''), 256) AS h FROM bsa_tagihan")->fetch_assoc()['h'];
    $kini['bsa_tagihan_komponen'] = (string) $db->query("SELECT SHA2(COALESCE(GROUP_CONCAT(CONCAT_WS(':', id, tagihan_id, urutan, nominal) ORDER BY id SEPARATOR ','), ''), 256) AS h FROM bsa_tagihan_komponen")->fetch_assoc()['h'];
    $kini['bsa_tabungan'] = (string) $db->query("SELECT SHA2(COALESCE(GROUP_CONCAT(CONCAT_WS(':', id, santri_id, jenis, nominal, saldo_sesudah, status) ORDER BY id SEPARATOR ','), ''), 256) AS h FROM bsa_tabungan_transaksi")->fetch_assoc()['h'];
    $kini['bsa_saldo'] = (string) $db->query("SELECT SHA2(COALESCE(GROUP_CONCAT(CONCAT_WS(':', santri_id, saldo) ORDER BY santri_id SEPARATOR ','), ''), 256) AS h FROM bsa_tabungan_akun")->fetch_assoc()['h'];
    foreach ($kini as $kunci => $h) {
        $periksa(isset($sidik[$kunci]) && hash_equals((string) $sidik[$kunci], $h), "Sidik jari $kunci tetap");
    }
} else {
    echo "[belum diperiksa] Perbandingan baris tabel lama: berikan path manifest preflight Fase 2.\n";
}
exit($gagal > 0 ? 1 : 0);
