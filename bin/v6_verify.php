<?php

declare(strict_types=1);

/**
 * Verifier rilis bersama PRD V6 (Fase 5). Hanya membaca; aman dijalankan
 * pada produksi sesudah migrasi, pada salinan pulihan, maupun berkala.
 *
 *   php bin/v6_verify.php [manifest-privat.json]
 *
 * 1. Verifier per fase (`v6_phase3_verify.php`, berantai ke Fase 2 dan 1):
 *    tabel, kunci asing, referensi yatim, saldo akun = ledger = jumlah
 *    berjalan, terbayar = Σ pembayaran sah, snapshot, pasangan internal,
 *    audit tanpa data uang.
 * 2. Invarians keuangan Fase 5 (ditulis ulang secara terpisah dari kode
 *    aplikasi dan dari verifier fase):
 *    - per santri: saldo akun = Σ setoran − Σ penarikan − Σ belanja −
 *      Σ pembayaran tagihan dari tabungan + Σ pembalik masuk − Σ pembalik
 *      keluar, dihitung menurut JENIS; selisih Rp0 per santri dan agregat;
 *    - per tagihan: total − Σ pembayaran sah = sisa (total − terbayar);
 *      Σ sah ≤ total; Σ sah + menunggu ≤ total; satu tagihan per
 *      santri–periode;
 *    - rekonsiliasi seluruh waktu: penerimaan kas baru = setoran tunai/
 *      transfer + pembayaran tunai/transfer sah; pembayaran dari tabungan
 *      tidak pernah menjadi kas; debit tabungan = pembayaran dari tabungan;
 *    - setiap koreksi/pembatalan beraktor, berwaktu, beralasan, berasal, dan
 *      punya audit; setiap baris uang efektif punya audit (0 mutasi tanpa
 *      jejak);
 *    - laporan (`LaporanRepository`, hak admin, seluruh waktu) = kueri
 *      sumber terpisah;
 *    - notifikasi V6: 0 ganda, 0 WhatsApp, teks generik, data penunjuk saja.
 *    - [info] santri nonaktif yang masih bersaldo/bertunggakan (R-10).
 * 3. Dengan manifest `bin/v6_preflight.php`: jumlah baris seluruh tabel lama
 *    dan sidik jari identitas (serta isi uang V6 bila manifest memuatnya)
 *    tidak berubah. Jalankan segera sesudah migrasi (sebelum transaksi baru)
 *    atau pada DB pulihan.
 *
 * Kode keluar 0 bila seluruh pemeriksaan lulus.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once __DIR__ . '/_v6_rilis.php';

use App\Bsa\BsaNotifikasi;
use App\Bsa\BsaRepository;
use App\Bsa\LaporanRepository;
use App\Bsa\TagihanRepository;

$db = app_db();
$manifestPath = $argv[1] ?? null;
$lulus = 0;
$gagal = 0;
$periksa = static function (bool $ok, string $label) use (&$lulus, &$gagal): void {
    echo ($ok ? '[lulus] ' : '[gagal] ') . $label . PHP_EOL;
    $ok ? $lulus++ : $gagal++;
};
$n = static fn (string $sql): int => (int) $db->query($sql)->fetch_row()[0];

foreach (v6_tabel() as $t) {
    if (!v6_tabel_ada($db, $t)) {
        echo '[gagal] Tabel V6 ' . $t . ' tidak ada: rilis V6 memerlukan 027–029 sekaligus.' . PHP_EOL;
        exit(1);
    }
}

echo '=== 1. Verifier per fase (Fase 3 → 2 → 1) ===' . PHP_EOL;
$keluaran = [];
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/v6_phase3_verify.php') . ' 2>&1', $keluaran, $kode);
foreach (array_filter($keluaran, static fn (string $l): bool => str_contains($l, '[gagal]')) as $l) {
    echo '    ' . trim($l) . PHP_EOL;
}
$periksa($kode === 0, 'v6_phase3_verify.php (berantai Fase 1–2): exit ' . $kode . ', '
    . count(array_filter($keluaran, static fn (string $l): bool => str_contains($l, '[lulus]'))) . ' pemeriksaan lulus');

echo PHP_EOL . '=== 2a. Saldo per santri menurut jenis ===' . PHP_EOL;
$menurutJenis = "SELECT santri_id,
        SUM(CASE WHEN jenis = 'Setoran' THEN nominal ELSE 0 END)
      - SUM(CASE WHEN jenis = 'Penarikan' THEN nominal ELSE 0 END)
      - SUM(CASE WHEN jenis = 'Belanja' THEN nominal ELSE 0 END)
      - SUM(CASE WHEN jenis = 'PembayaranTagihan' THEN nominal ELSE 0 END)
      + SUM(CASE WHEN jenis = 'Pembalik' AND arah = 'Masuk' THEN nominal ELSE 0 END)
      - SUM(CASE WHEN jenis = 'Pembalik' AND arah = 'Keluar' THEN nominal ELSE 0 END) AS hitung
    FROM bsa_tabungan_transaksi WHERE status = 'Sah' GROUP BY santri_id";
$selisihSantri = $n("SELECT COUNT(*) FROM bsa_tabungan_akun a LEFT JOIN ($menurutJenis) h ON h.santri_id = a.santri_id WHERE a.saldo <> COALESCE(h.hitung, 0)")
    + $n("SELECT COUNT(*) FROM ($menurutJenis) h LEFT JOIN bsa_tabungan_akun a ON a.santri_id = h.santri_id WHERE a.santri_id IS NULL AND h.hitung <> 0");
$periksa($selisihSantri === 0, 'Saldo akun = setoran − penarikan − belanja − bayar tagihan + pembalik masuk − pembalik keluar, per santri (' . $selisihSantri . ' santri selisih)');
$agregat = $n('SELECT COALESCE(SUM(saldo), 0) FROM bsa_tabungan_akun') - $n("SELECT COALESCE(SUM(hitung), 0) FROM ($menurutJenis) h");
$periksa($agregat === 0, 'Agregat Σ saldo akun − Σ ledger menurut jenis = Rp' . $agregat);
$periksa($n("SELECT COUNT(*) FROM bsa_tabungan_transaksi r JOIN bsa_tabungan_transaksi a ON a.id = r.pembalik_dari_id
    WHERE NOT ((a.jenis = 'Setoran' AND r.arah = 'Keluar') OR (a.jenis IN ('Penarikan', 'Belanja', 'PembayaranTagihan') AND r.arah = 'Masuk'))") === 0,
    'Arah pembalik sesuai jenis asal (setoran → keluar; penarikan/belanja/bayar tagihan → masuk)');

echo PHP_EOL . '=== 2b. Tagihan: total − pembayaran sah = sisa ===' . PHP_EOL;
$bayarSah = "SELECT tagihan_id, SUM(CASE WHEN status = 'Sah' THEN nominal ELSE 0 END) AS sah, SUM(CASE WHEN status = 'Menunggu' THEN nominal ELSE 0 END) AS menunggu
    FROM bsa_pembayaran GROUP BY tagihan_id";
$periksa($n("SELECT COUNT(*) FROM bsa_tagihan t LEFT JOIN ($bayarSah) p ON p.tagihan_id = t.id WHERE t.total - COALESCE(p.sah, 0) <> t.total - t.terbayar") === 0,
    'Setiap tagihan: total − Σ pembayaran sah = sisa');
$periksa($n("SELECT COUNT(*) FROM bsa_tagihan t JOIN ($bayarSah) p ON p.tagihan_id = t.id WHERE p.sah > t.total OR p.sah + p.menunggu > t.total") === 0,
    'Tidak ada pembayaran sah (atau sah + menunggu) melebihi total tagihan');
$periksa($n('SELECT COUNT(*) FROM (SELECT santri_id, periode FROM bsa_tagihan GROUP BY santri_id, periode HAVING COUNT(*) > 1) g') === 0, 'Satu tagihan per santri per periode (0 tagihan ganda)');
$periksa($n("SELECT COUNT(*) FROM bsa_tagihan WHERE status = 'Dibatalkan' AND terbayar <> 0") === 0, 'Tagihan dibatalkan tanpa sisa pembayaran sah');
$periksa($n("SELECT COUNT(*) FROM (SELECT nomor_kuitansi FROM bsa_pembayaran GROUP BY nomor_kuitansi HAVING COUNT(*) > 1) k") === 0, 'Nomor kuitansi pembayaran unik');

echo PHP_EOL . '=== 2c. Rekonsiliasi seluruh waktu: kas vs perpindahan internal ===' . PHP_EOL;
$setoranKas = $n("SELECT COALESCE(SUM(nominal), 0) FROM bsa_tabungan_transaksi WHERE status = 'Sah' AND jenis = 'Setoran' AND metode IN ('Tunai', 'Transfer')");
$bayarKas = $n("SELECT COALESCE(SUM(nominal), 0) FROM bsa_pembayaran WHERE sumber IN ('Tunai', 'Transfer') AND diverifikasi_pada IS NOT NULL");
$bayarTabungan = $n("SELECT COALESCE(SUM(nominal), 0) FROM bsa_pembayaran WHERE sumber = 'Tabungan'");
$debit = $n("SELECT COALESCE(SUM(nominal), 0) FROM bsa_tabungan_transaksi WHERE jenis = 'PembayaranTagihan'");
$repo = new LaporanRepository($db, new BsaRepository($db), new TagihanRepository($db, new BsaRepository($db)));
$hak = ['mode' => 'admin', 'user_id' => 0, 'jenjang' => null];
$f = ['santri_id' => null, 'unit' => null];
[$mulai, $akhir, $dari, $sampai] = ['1970-01-01 00:00:00', '2999-01-01 00:00:00', '1970-01', '2999-12'];
$lap = $repo->snapshot(static fn (): array => [
    'ledger' => $repo->ledgerBulanan($hak, $f, $mulai, $akhir),
    'bayar' => $repo->bayarBulanan($hak, $f, $mulai, $akhir),
    'batal' => $repo->batalBulanan($hak, $f, $mulai, $akhir),
    'tagihan' => $repo->tagihan($hak, $f, $dari, $sampai, date('Y-m-d')),
    'bayar_tagihan' => $repo->pembayaranTagihan($hak, $f, $dari, $sampai),
    'santri' => $repo->perSantri($hak, $f, $dari, $sampai, $mulai, $akhir, '', 1),
    'saldo' => $repo->saldoSebelum($hak, $f, $akhir),
]);
$jumlah = static function (array $rows, callable $pilih): int {
    $t = 0;
    foreach ($rows as $r) {
        $t += $pilih($r) ? (int) $r['nominal'] : 0;
    }

    return $t;
};
$kasLaporan = $jumlah($lap['ledger'], static fn (array $r): bool => $r['jenis'] === 'Setoran' && in_array($r['metode'], ['Tunai', 'Transfer'], true))
    + $jumlah($lap['bayar'], static fn (array $r): bool => $r['sumber'] !== 'Tabungan');
$periksa($kasLaporan === $setoranKas + $bayarKas, 'Penerimaan kas baru laporan Rp' . $kasLaporan . ' = setoran tunai/transfer Rp' . $setoranKas . ' + pembayaran tunai/transfer sah Rp' . $bayarKas);
$periksa($jumlah($lap['bayar'], static fn (array $r): bool => $r['sumber'] === 'Tabungan') === $bayarTabungan,
    'Pembayaran dari tabungan Rp' . $bayarTabungan . ' tercatat terpisah sebagai perpindahan internal (tidak termasuk kas baru)');
$periksa($debit === $bayarTabungan && $jumlah($lap['ledger'], static fn (array $r): bool => $r['jenis'] === 'PembayaranTagihan') === $debit,
    'Debit tabungan untuk tagihan Rp' . $debit . ' = pembayaran dari tabungan Rp' . $bayarTabungan . ' (laporan dan sumber)');
$batalSumber = $n("SELECT COALESCE(SUM(b.nominal), 0) FROM bsa_pembayaran b JOIN bsa_pembayaran_pembatalan x ON x.pembayaran_id = b.id AND x.status_sebelum = 'Sah'");
$periksa($jumlah($lap['batal'], static fn (): bool => true) === $batalSumber, 'Pembatalan pembayaran sah laporan = sumber (Rp' . $batalSumber . ')');

echo PHP_EOL . '=== 2d. Laporan seluruh waktu = sumber ===' . PHP_EOL;
$t = $lap['tagihan'];
$periksa($t['terbit_jumlah'] === $n("SELECT COUNT(*) FROM bsa_tagihan WHERE status = 'Terbit'") && $t['terbit_nominal'] === $n("SELECT COALESCE(SUM(total), 0) FROM bsa_tagihan WHERE status = 'Terbit'")
    && $t['terbayar'] === $n("SELECT COALESCE(SUM(terbayar), 0) FROM bsa_tagihan WHERE status = 'Terbit'") && $t['batal_jumlah'] === $n("SELECT COUNT(*) FROM bsa_tagihan WHERE status = 'Dibatalkan'"),
    'Agregat tagihan laporan = sumber (' . $t['terbit_jumlah'] . ' terbit, Rp' . $t['terbit_nominal'] . ', terbayar Rp' . $t['terbayar'] . ')');
$periksa($t['selisih'] === 0, 'Laporan: 0 tagihan dengan terbayar ≠ Σ pembayaran sah');
$sahLaporan = $jumlah($lap['bayar_tagihan'], static fn (array $r): bool => $r['status'] === 'Sah');
$periksa($sahLaporan === $t['terbayar'], 'Σ pembayaran sah menurut sumber Rp' . $sahLaporan . ' = Σ terbayar tagihan');
$j = $lap['santri']['jumlah'];
$periksa($lap['saldo'] === $n('SELECT COALESCE(SUM(saldo), 0) FROM bsa_tabungan_akun') && $j['saldo_akhir'] === $lap['saldo'] && $j['saldo_akun'] === $lap['saldo'],
    'Saldo akhir laporan = Σ saldo per santri = Σ saldo akun (Rp' . $lap['saldo'] . ')');
$periksa($j['santri_selisih_saldo'] === 0 && $j['santri_selisih_akun'] === 0, 'Laporan per santri: 0 santri dengan selisih saldo atau akun ≠ ledger');
$periksa($j['tagihan'] === $t['terbit_nominal'] && $j['terbayar'] === $t['terbayar'] && $j['setoran'] === $jumlah($lap['ledger'], static fn (array $r): bool => $r['jenis'] === 'Setoran'),
    'Jumlah per santri (tagihan, terbayar, setoran) = agregat laporan');

echo PHP_EOL . '=== 2e. Koreksi dan pembatalan berjejak ===' . PHP_EOL;
$audit = static fn (string $aksi, string $entitas, string $id, string $aktor = ''): string => "EXISTS (SELECT 1 FROM audit_logs al WHERE al.action = '$aksi' AND al.entity_type = '$entitas' AND al.entity_id = $id"
    . ($aktor === '' ? '' : " AND al.actor_user_id = $aktor") . ')';
$periksa($n("SELECT COUNT(*) FROM bsa_tabungan_transaksi x WHERE x.jenis = 'Pembalik' AND (x.alasan IS NULL OR CHAR_LENGTH(x.alasan) < 5 OR x.pembalik_dari_id IS NULL
    OR NOT EXISTS (SELECT 1 FROM users u WHERE u.id = x.dicatat_oleh) OR x.created_at IS NULL)") === 0, 'Setiap pembalik ledger: aktor, waktu, alasan, dan asal');
$periksa($n("SELECT COUNT(*) FROM bsa_tabungan_transaksi x WHERE x.jenis = 'Pembalik' AND x.metode <> 'Internal' AND NOT " . $audit('bsa.tabungan.koreksi', 'bsa_tabungan_transaksi', 'x.id', 'x.dicatat_oleh')) === 0,
    'Setiap koreksi tabungan punya audit oleh pencatatnya');
$periksa($n("SELECT COUNT(*) FROM bsa_pembayaran_pembatalan x WHERE CHAR_LENGTH(x.alasan) < 5 OR NOT EXISTS (SELECT 1 FROM users u WHERE u.id = x.dibatalkan_oleh)
    OR NOT " . $audit('bsa.pembayaran.batal', 'bsa_pembayaran', 'x.pembayaran_id', 'x.dibatalkan_oleh')) === 0,
    'Setiap pembatalan/penolakan pembayaran: aktor, waktu, alasan, asal, dan audit');
$periksa($n("SELECT COUNT(*) FROM bsa_tabungan_transaksi x JOIN bsa_pembayaran p ON p.tabungan_transaksi_id = x.pembalik_dari_id
    WHERE x.jenis = 'Pembalik' AND x.metode = 'Internal' AND NOT EXISTS (SELECT 1 FROM bsa_pembayaran_pembatalan b WHERE b.pembayaran_id = p.id AND b.dibatalkan_oleh = x.dicatat_oleh)") === 0,
    'Setiap pengembalian ke tabungan terhubung ke pembatalan pembayaran oleh aktor yang sama');
$periksa($n("SELECT COUNT(*) FROM bsa_setoran_transfer s WHERE s.status = 'Ditolak' AND (s.diputus_oleh IS NULL OR s.diputus_pada IS NULL OR CHAR_LENGTH(s.alasan_tolak) < 5
    OR NOT " . $audit('bsa.tabungan.tolak', 'bsa_setoran_transfer', 's.id', 's.diputus_oleh') . ')') === 0, 'Setiap setoran transfer ditolak: aktor, waktu, alasan, dan audit');
foreach (['Revisi' => 'bsa.tagihan.revisi', 'Dibatalkan' => 'bsa.tagihan.batal'] as $tindakan => $aksi) {
    $periksa($n("SELECT COUNT(*) FROM (SELECT r.tagihan_id, COUNT(*) c FROM bsa_tagihan_riwayat r WHERE r.tindakan = '$tindakan' GROUP BY r.tagihan_id) r
        LEFT JOIN (SELECT entity_id, COUNT(*) c FROM audit_logs WHERE action = '$aksi' AND entity_type = 'bsa_tagihan' GROUP BY entity_id) a ON a.entity_id = r.tagihan_id
        WHERE COALESCE(a.c, 0) <> r.c") === 0
        && $n("SELECT COUNT(*) FROM bsa_tagihan_riwayat r WHERE r.tindakan = '$tindakan' AND (CHAR_LENGTH(r.alasan) < 5 OR NOT EXISTS (SELECT 1 FROM users u WHERE u.id = r.aktor))") === 0,
        "Setiap riwayat tagihan $tindakan: aktor, alasan, dan satu audit per versi");
}
$periksa($n("SELECT COUNT(*) FROM bsa_penyesuaian p WHERE p.status = 'Dicabut' AND NOT " . $audit('bsa.penyesuaian.cabut', 'bsa_penyesuaian', 'p.id', 'p.dicabut_oleh')) === 0,
    'Setiap penyesuaian dicabut beralasan (CHECK) dan punya audit');
$periksa($n("SELECT COUNT(*) FROM bsa_tarif t JOIN bsa_tarif b ON b.id = t.diganti_oleh_id WHERE t.status = 'Diganti' AND (b.alasan IS NULL OR CHAR_LENGTH(b.alasan) < 5)") === 0,
    'Setiap tarif yang diganti punya pengganti beralasan');

echo PHP_EOL . '=== 2f. Setiap baris uang efektif punya audit ===' . PHP_EOL;
$periksa($n("SELECT COUNT(*) FROM bsa_tabungan_transaksi x WHERE x.jenis IN ('Setoran', 'Penarikan', 'Belanja') AND x.idempotency_key NOT LIKE 'transfer#%'
    AND NOT EXISTS (SELECT 1 FROM audit_logs al WHERE al.action = CONCAT('bsa.tabungan.', LOWER(x.jenis)) AND al.entity_type = 'bsa_tabungan_transaksi' AND al.entity_id = x.id)") === 0,
    'Setoran/penarikan/belanja: audit per baris ledger');
$periksa($n("SELECT COUNT(*) FROM bsa_setoran_transfer s WHERE s.status = 'Sah' AND NOT " . $audit('bsa.tabungan.verifikasi', 'bsa_setoran_transfer', 's.id', 's.diputus_oleh')) === 0,
    'Setoran transfer terverifikasi: audit verifikasi oleh pemutusnya');
$periksa($n("SELECT COUNT(*) FROM bsa_tabungan_transaksi x JOIN bsa_pembayaran p ON p.tabungan_transaksi_id = x.id WHERE NOT " . $audit('bsa.pembayaran.tabungan', 'bsa_pembayaran', 'p.id', 'x.dicatat_oleh')) === 0,
    'Debit tabungan untuk tagihan: audit pembayaran dari tabungan');
$periksa($n("SELECT COUNT(*) FROM bsa_pembayaran p WHERE p.sumber <> 'Tabungan' AND NOT " . $audit('bsa.pembayaran.catat', 'bsa_pembayaran', 'p.id', 'p.dicatat_oleh')) === 0
    && $n("SELECT COUNT(*) FROM bsa_pembayaran p WHERE p.diverifikasi_oleh IS NOT NULL
        AND EXISTS (SELECT 1 FROM audit_logs c WHERE c.action = 'bsa.pembayaran.catat' AND c.entity_type = 'bsa_pembayaran' AND c.entity_id = p.id
                     AND JSON_UNQUOTE(JSON_EXTRACT(c.after_json, '$.status')) = 'Menunggu')
        AND NOT " . $audit('bsa.pembayaran.verifikasi', 'bsa_pembayaran', 'p.id', 'p.diverifikasi_oleh')) === 0,
    'Pembayaran tunai/transfer: audit pencatatan dan verifikasi');
$periksa($n("SELECT COUNT(*) FROM bsa_tagihan t WHERE NOT " . $audit('bsa.tagihan.terbit', 'bsa_tagihan', 't.id', 't.diterbitkan_oleh')) === 0, 'Setiap tagihan punya audit penerbitan oleh penerbitnya');

echo PHP_EOL . '=== 2g. Pemberitahuan V6 ===' . PHP_EOL;
$v6 = "event_type LIKE 'v6\\_bsa\\_%'";
$periksa($n("SELECT COUNT(*) FROM (SELECT event_key, kanal, penerima_user_id FROM notifikasi_outbox WHERE $v6 GROUP BY event_key, kanal, penerima_user_id HAVING COUNT(*) > 1) d") === 0,
    '0 notifikasi V6 efektif ganda (peristiwa × kanal × penerima)');
$periksa($n("SELECT COUNT(*) FROM notifikasi_outbox WHERE $v6 AND kanal = 'WhatsApp'") === 0, '0 notifikasi V6 kanal WhatsApp');
$tidakGenerik = 0;
foreach ($db->query("SELECT event_type, judul, isi, data_json FROM notifikasi_outbox WHERE $v6")->fetch_all(MYSQLI_ASSOC) as $o) {
    $teks = BsaNotifikasi::teks((string) $o['event_type']);
    $data = json_decode((string) $o['data_json'], true);
    $tautan = BsaNotifikasi::TAUTAN[$o['event_type']] ?? null;
    if ($o['judul'] !== $teks['judul'] || $o['isi'] !== $teks['isi'] || $tautan === null || !is_array($data)
        || array_keys($data) !== ['tipe', $tautan[1]] || $data['tipe'] !== $tautan[0] || !is_int($data[$tautan[1]])) {
        $tidakGenerik++;
    }
}
$periksa($tidakGenerik === 0, 'Seluruh notifikasi V6 memakai teks generik katalog dan data penunjuk saja (tanpa nominal/nama/referensi)');

echo PHP_EOL . '=== Info ===' . PHP_EOL;
$nonaktif = $db->query("SELECT COUNT(*), COALESCE(SUM(COALESCE(a.saldo, 0)), 0), COALESCE(SUM(COALESCE(u.sisa, 0)), 0) FROM santri s
    LEFT JOIN bsa_tabungan_akun a ON a.santri_id = s.id
    LEFT JOIN (SELECT santri_id, SUM(total - terbayar) sisa FROM bsa_tagihan WHERE status = 'Terbit' GROUP BY santri_id) u ON u.santri_id = s.id
    WHERE (s.is_active = 0 OR s.archived_at IS NOT NULL) AND (COALESCE(a.saldo, 0) > 0 OR COALESCE(u.sisa, 0) > 0)")->fetch_row();
echo '[info] Santri nonaktif bersaldo/bertunggakan (R-10, hanya dilaporkan): ' . (int) $nonaktif[0] . ' santri, saldo Rp' . (int) $nonaktif[1] . ', sisa tagihan Rp' . (int) $nonaktif[2] . PHP_EOL;
echo '[info] Transfer menunggu verifikasi (tidak dihitung saldo/terbayar): ' . $n("SELECT COUNT(*) FROM bsa_setoran_transfer WHERE status = 'Menunggu'") . ' setoran, '
    . $n("SELECT COUNT(*) FROM bsa_pembayaran WHERE status = 'Menunggu'") . ' pembayaran' . PHP_EOL;

echo PHP_EOL . '=== 3. Tabel lama dan identitas ===' . PHP_EOL;
if ($manifestPath !== null) {
    $manifest = json_decode((string) @file_get_contents($manifestPath), true);
    $sah = is_array($manifest) && ($manifest['fase'] ?? null) === 'v6-rilis' && is_array($manifest['legacy_counts'] ?? null) && is_array($manifest['sidik_jari'] ?? null);
    $periksa($sah, 'Manifest privat V6 terbaca (bin/v6_preflight.php)');
    if ($sah) {
        $kini = v6_hitung_tabel_lama($db);
        $kunci = array_keys($manifest['legacy_counts']);
        $kunciKini = array_keys($kini);
        sort($kunci);
        sort($kunciKini);
        // Daftar kosong/terpotong tidak boleh meloloskan preservasi hanya karena perbandingan dilewati.
        $periksa($kunci === $kunciKini, 'Manifest memuat tepat seluruh tabel lama (' . count($kunciKini) . ' tabel)');
        $beda = array_keys(array_filter($kini, static fn (int $c, string $t): bool => ($manifest['legacy_counts'][$t] ?? null) !== $c, ARRAY_FILTER_USE_BOTH));
        $periksa($beda === [], 'Jumlah baris seluruh tabel lama tetap' . ($beda === [] ? '' : ' (berubah: ' . implode(', ', $beda) . ')'));
        $sidik = v6_sidik_jari($db);
        foreach (['santri', 'wali', 'santri_wali', 'users'] as $t) {
            $periksa(($manifest['sidik_jari'][$t] ?? null) === $sidik[$t], "Sidik jari $t tetap ({$sidik[$t]['baris']} baris)");
        }
        $uang = array_diff_key($manifest['sidik_jari'], array_flip(['santri', 'wali', 'santri_wali', 'users']));
        foreach ($uang as $t => $nilai) {
            $periksa(($sidik[$t] ?? null) === $nilai, "Isi uang $t tetap ({$nilai['baris']} baris)");
        }
        if ($uang === []) {
            echo '[info] Manifest diambil sebelum tabel V6 ada; isi uang V6 tidak dibandingkan.' . PHP_EOL;
        }
    }
} else {
    echo '[belum diperiksa] Tabel lama dan sidik jari: berikan manifest privat bin/v6_preflight.php.' . PHP_EOL;
}

echo PHP_EOL . 'Ringkasan verifier V6: ' . $lulus . ' lulus, ' . $gagal . ' gagal' . PHP_EOL;
exit($gagal > 0 ? 1 : 0);
