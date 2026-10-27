<?php

declare(strict_types=1);

/**
 * Verifier rilis PRD V7 (Fase 5). Hanya membaca; aman dijalankan pada
 * produksi sesudah migrasi, pada salinan pulihan, maupun berkala.
 *
 *   php bin/v7_verify.php [manifest-privat.json]
 *
 * 1. Verifier per fase: `v7_phase2_verify.php` (skema 030–031, invarians
 *    pengajuan/tinjauan/perbaikan/pembatalan/keputusan, versi = 1 + peristiwa)
 *    dan `v7_phase3_verify.php` (notifikasi tahap generik, tanpa WhatsApp,
 *    tanpa ganda), sebagai proses terpisah.
 * 2. Invarians rilis Fase 5, ditulis ulang terpisah dari kode aplikasi:
 *    - setiap riwayat baris Wali adalah transisi sah (peristiwa, status
 *      sebelum → sesudah); riwayat tiap pengajuan membentuk rantai tanpa
 *      celah, diawali pembuatan dan berakhir pada status baris kini;
 *    - setiap peristiwa riwayat memiliki pelaku, dan alasan bila wajib; jumlah
 *      peristiwa per jenis = jumlah audit yang dipetakan (0 mutasi tanpa audit);
 *    - setiap audit V7 berpelaku dan menunjuk pengajuan Wali yang ada;
 *    - keputusan efektif tunggal: baris Disetujui/Ditolak (kedua asal) punya
 *      tepat satu `izin_keputusan` yang hasilnya sama dengan status; baris
 *      belum diputus/dibatalkan/ditolak pembimbing tanpa keputusan;
 *    - antrean: Diajukan selalu bermurobi, Perlu Penetapan Admin tanpa
 *      murobi; murobi tidak pernah ditetapkan sebelum penerusan;
 *    - tidak ada dua pengajuan aktif bertumpang tindih lintas jalur;
 *    - referensi yatim baru nol (penugasan, wali, akun, outbox, idempotensi,
 *      audit, tabel V7);
 *    - laporan V7 (`IzinLaporanRepository`, cakupan admin) = hitungan sumber
 *      per asal/status.
 * 3. Dengan manifest `bin/v7_preflight.php`: jumlah baris dan isi seluruh
 *    tabel lama, irisan V2, serta (bila manifest diambil sesudah V7) isi V7
 *    tetap. Jalankan segera sesudah migrasi (sebelum lalu lintas) atau pada
 *    DB pulihan. Irisan V2 juga harus tetap walau V7 dipakai di antaranya;
 *    `--irisan-v2` hanya membandingkan irisan itu.
 *
 * Kode keluar 0 bila seluruh pemeriksaan lulus.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/_v7_rilis.php';

use App\Izin\IzinLaporanRepository;

$db = app_db();
$args = array_slice($argv, 1);
$hanyaIrisan = in_array('--irisan-v2', $args, true);
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
$semua = static function (string $sql) use ($db): array {
    $r = $db->query($sql);
    if ($r === false) {
        throw new RuntimeException($db->error . ' :: ' . $sql);
    }

    return $r->fetch_all(MYSQLI_ASSOC);
};
echo '[info] Basis data terpilih: ' . (string) v7f1_nilai($db, 'SELECT DATABASE()') . ' (verifier hanya membaca).' . PHP_EOL;

$terpasang = v7_terpasang($db);
$periksa($terpasang === array_fill_keys(V7_MIGRASI, true), 'Migrasi 030 dan 031 tercatat (rilis V7 utuh, bukan sebagian)');
if ($terpasang !== array_fill_keys(V7_MIGRASI, true)) {
    echo PHP_EOL . 'Ringkasan verifier V7: ' . $lulus . ' lulus, ' . $gagal . ' gagal' . PHP_EOL;
    exit(1);
}

echo PHP_EOL . '=== 1. Verifier per fase ===' . PHP_EOL;
foreach (['v7_phase2_verify.php', 'v7_phase3_verify.php'] as $skrip) {
    $keluaran = [];
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/' . $skrip) . ' 2>&1', $keluaran, $kode);
    $gagalFase = array_values(array_filter($keluaran, static fn (string $l): bool => str_starts_with($l, '[gagal]')));
    foreach ($gagalFase as $l) {
        echo '    ' . $l . PHP_EOL;
    }
    $periksa($kode === 0 && $gagalFase === [], $skrip . ': exit ' . $kode . ', ' . count(array_filter($keluaran, static fn (string $l): bool => str_starts_with($l, '[lulus]'))) . ' lulus');
}

echo PHP_EOL . '=== 2. Invarians rilis ===' . PHP_EOL;
$wali = "p.asal_pengajuan = 'Wali'";

// 2a. Transisi dan rantai riwayat.
$riwayat = $semua("SELECT r.id, r.pengajuan_id, r.peristiwa, r.status_sebelum, r.status_sesudah, r.pelaku_user_id, r.alasan, p.status AS status_kini
    FROM izin_riwayat_status r JOIN izin_pengajuan p ON p.id = r.pengajuan_id WHERE $wali ORDER BY r.pengajuan_id, r.id");
$tidakSah = [];
$rantaiPutus = [];
$tanpaPelaku = [];
$tanpaAlasan = [];
$akhir = [];
$awal = [];
$per = [];
foreach ($riwayat as $r) {
    $pid = (int) $r['pengajuan_id'];
    $t = V7_TRANSISI[(string) $r['peristiwa']] ?? null;
    if ($t === null || !in_array($r['status_sebelum'], $t[0], true) || !in_array($r['status_sesudah'], $t[1], true)) {
        $tidakSah[] = (int) $r['id'];
    }
    if (!isset($akhir[$pid])) {
        $awal[$pid] = (string) $r['peristiwa'];
    } elseif ($akhir[$pid] !== $r['status_sebelum']) {
        $rantaiPutus[$pid] = true;
    }
    $akhir[$pid] = $r['status_sesudah'];
    $per[$pid] = (string) $r['status_kini'];
    if ($r['pelaku_user_id'] === null) {
        $tanpaPelaku[] = (int) $r['id'];
    }
    if (in_array($r['peristiwa'], V7_PERISTIWA_BERALASAN, true) && trim((string) $r['alasan']) === '') {
        $tanpaAlasan[] = (int) $r['id'];
    }
}
foreach ($per as $pid => $statusKini) {
    if ($awal[$pid] !== 'pengajuan_wali_dibuat' || $akhir[$pid] !== $statusKini) {
        $rantaiPutus[$pid] = true;
    }
}
$jumlahWali = $n("SELECT COUNT(*) FROM izin_pengajuan p WHERE $wali");
echo '[info] Pengajuan Wali: ' . $jumlahWali . '; riwayat: ' . count($riwayat) . PHP_EOL;
$periksa(count($per) === $jumlahWali, 'Setiap pengajuan Wali memiliki riwayat');
$periksa($tidakSah === [], 'Setiap riwayat baris Wali adalah transisi sah (peristiwa, status sebelum → sesudah)' . ($tidakSah === [] ? '' : ': ' . implode(',', array_slice($tidakSah, 0, 10))));
$periksa($rantaiPutus === [], 'Rantai riwayat tiap pengajuan Wali bersambung dari pembuatan sampai status kini (tanpa status ganda/lompatan)'
    . ($rantaiPutus === [] ? '' : ': ' . implode(',', array_slice(array_keys($rantaiPutus), 0, 10))));
$periksa($tanpaPelaku === [], 'Setiap riwayat baris Wali memiliki pelaku');
$periksa($tanpaAlasan === [], 'Peristiwa wajib beralasan (penetapan, permintaan perbaikan, tolak, batal, keputusan, koreksi) beralasan');

// 2b. Riwayat ↔ audit per pengajuan dan jenis.
$riwayatPer = [];
foreach ($riwayat as $r) {
    $aksi = V7_PERISTIWA_AUDIT[(string) $r['peristiwa']] ?? null;
    if ($aksi !== null) {
        $riwayatPer[(int) $r['pengajuan_id'] . '|' . $aksi] = ($riwayatPer[(int) $r['pengajuan_id'] . '|' . $aksi] ?? 0) + 1;
    }
}
$auditPer = [];
$aksiDipetakan = v7f2_in(array_values(array_unique(V7_PERISTIWA_AUDIT)));
foreach ($semua("SELECT a.entity_id, a.action, COUNT(*) AS n FROM audit_logs a JOIN izin_pengajuan p ON p.id = a.entity_id
    WHERE a.entity_type = 'izin_pengajuan' AND $wali AND a.action IN ($aksiDipetakan) GROUP BY a.entity_id, a.action") as $a) {
    $auditPer[(int) $a['entity_id'] . '|' . $a['action']] = (int) $a['n'];
}
ksort($riwayatPer);
ksort($auditPer);
$selisih = array_keys(array_diff_assoc($riwayatPer, $auditPer) + array_diff_assoc($auditPer, $riwayatPer));
$periksa($selisih === [], 'Jumlah peristiwa riwayat = jumlah audit terpetakan per pengajuan Wali dan jenis (' . array_sum($riwayatPer) . ' pasangan; 0 mutasi tanpa audit)'
    . ($selisih === [] ? '' : ': ' . implode(',', array_slice($selisih, 0, 10))));
$periksa($n("SELECT COUNT(*) FROM audit_logs WHERE (action LIKE 'izin\\_wali\\_%') AND actor_user_id IS NULL") === 0, 'Setiap audit izin_wali_* berpelaku');
$periksa($n("SELECT COUNT(*) FROM audit_logs a LEFT JOIN izin_pengajuan p ON p.id = a.entity_id
    WHERE a.action LIKE 'izin\\_wali\\_%' AND (a.entity_type <> 'izin_pengajuan' OR p.id IS NULL OR p.asal_pengajuan <> 'Wali')") === 0,
    'Setiap audit izin_wali_* menunjuk pengajuan Wali yang ada');

// 2c. Keputusan efektif tunggal dan antrean.
$tanpaKeputusan = "SELECT COUNT(*) FROM izin_pengajuan p WHERE p.status IN ('Disetujui', 'Ditolak')
    AND (SELECT COUNT(*) FROM izin_keputusan k WHERE k.pengajuan_id = p.id AND k.hasil = p.status) <> 1";
$periksa($n($tanpaKeputusan . " AND $wali") === 0, 'Setiap pengajuan Wali Disetujui/Ditolak memiliki tepat satu keputusan dengan hasil yang sama');
$periksa($n("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'izin_keputusan'
    AND INDEX_NAME = 'izin_keputusan_pengajuan_unique' AND NON_UNIQUE = 0") === 1, 'Indeks unik satu keputusan per pengajuan (kedua asal) terpasang');
// Baris Pengurus diputus tanpa keputusan hanya dapat berasal dari data sebelum
// V7 (warisan/impor); V7 tidak mengubahnya, sehingga dilaporkan saja.
echo '[info] Pengajuan Pengurus Disetujui/Ditolak tanpa keputusan yang cocok (data sebelum V7, termasuk warisan): '
    . $n($tanpaKeputusan . " AND p.asal_pengajuan = 'Pengurus'") . PHP_EOL;
$periksa($n("SELECT COUNT(*) FROM izin_keputusan k JOIN izin_pengajuan p ON p.id = k.pengajuan_id
    WHERE p.status NOT IN ('Disetujui', 'Ditolak')") === 0, 'Tidak ada keputusan untuk pengajuan yang belum diputus, dibatalkan, atau ditolak pembimbing');
$periksa($n("SELECT COUNT(*) FROM izin_pengajuan p WHERE $wali AND p.status = 'Diajukan' AND p.murobi_guru_id IS NULL") === 0,
    'Pengajuan Wali Diajukan selalu memiliki murobi tujuan');
$periksa($n("SELECT COUNT(*) FROM izin_pengajuan p WHERE $wali AND p.status = 'Perlu Penetapan Admin' AND p.murobi_guru_id IS NOT NULL") === 0,
    'Pengajuan Wali Perlu Penetapan Admin belum bermurobi');
$periksa($n("SELECT COUNT(*) FROM izin_pengajuan p WHERE $wali AND p.murobi_guru_id IS NOT NULL
    AND NOT EXISTS (SELECT 1 FROM izin_pembimbing_tinjauan t WHERE t.pengajuan_id = p.id AND t.tindakan = 'Teruskan')") === 0,
    'Murobi tidak pernah dituju sebelum pembimbing meneruskan');
$periksa($n("SELECT COUNT(*) FROM izin_riwayat_status r JOIN izin_pengajuan p ON p.id = r.pengajuan_id
    WHERE $wali AND r.peristiwa IN ('murobi_ditetapkan', 'murobi_ditetapkan_ulang', 'keputusan', 'routing_otomatis', 'routing_perlu_admin')
      AND NOT EXISTS (SELECT 1 FROM izin_riwayat_status t WHERE t.pengajuan_id = r.pengajuan_id AND t.peristiwa = 'tinjauan_diteruskan' AND t.id < r.id)") === 0,
    'Routing/penetapan/keputusan murobi selalu sesudah penerusan pembimbing (urutan riwayat)');
$menahan = v7f2_in(\App\Izin\IzinWriteRepository::STATUS_MENAHAN);
$periksa($n("SELECT COUNT(*) FROM izin_pengajuan a JOIN izin_pengajuan b ON b.santri_id = a.santri_id AND b.id > a.id
    WHERE a.status IN ($menahan) AND b.status IN ($menahan) AND a.tgl_izin <= b.tgl_kembali AND a.tgl_kembali >= b.tgl_izin
      AND (a.asal_pengajuan = 'Wali' OR b.asal_pengajuan = 'Wali')") === 0, 'Tidak ada dua pengajuan aktif bertumpang tindih yang melibatkan pengajuan Wali');

// 2d. Referensi yatim.
$yatim = [
    'pengajuan Wali → wali' => "SELECT COUNT(*) FROM izin_pengajuan p LEFT JOIN wali w ON w.id = p.wali_id WHERE $wali AND w.id IS NULL",
    'pengajuan Wali → akun pengaju' => "SELECT COUNT(*) FROM izin_pengajuan p LEFT JOIN users u ON u.id = p.diajukan_oleh_user_id WHERE $wali AND u.id IS NULL",
    'pengajuan → pembimbing tujuan' => 'SELECT COUNT(*) FROM izin_pengajuan p LEFT JOIN pengurus g ON g.id = p.pembimbing_pengurus_id WHERE p.pembimbing_pengurus_id IS NOT NULL AND g.id IS NULL',
    'pengajuan → penugasan pembimbing' => 'SELECT COUNT(*) FROM izin_pengajuan p LEFT JOIN pembimbing_assignments a ON a.id = p.pembimbing_assignment_id WHERE p.pembimbing_assignment_id IS NOT NULL AND a.id IS NULL',
    'pengajuan → murobi' => 'SELECT COUNT(*) FROM izin_pengajuan p LEFT JOIN guru g ON g.id = p.murobi_guru_id WHERE p.murobi_guru_id IS NOT NULL AND g.id IS NULL',
    'pengajuan → santri' => 'SELECT COUNT(*) FROM izin_pengajuan p LEFT JOIN santri s ON s.id = p.santri_id WHERE s.id IS NULL',
    'penetapan → pengajuan/penugasan' => 'SELECT COUNT(*) FROM izin_pembimbing_penetapan t LEFT JOIN izin_pengajuan p ON p.id = t.pengajuan_id
        LEFT JOIN pembimbing_assignments a ON a.id = t.pembimbing_assignment_id WHERE p.id IS NULL OR a.id IS NULL',
    'tinjauan → pengajuan/penugasan' => 'SELECT COUNT(*) FROM izin_pembimbing_tinjauan t LEFT JOIN izin_pengajuan p ON p.id = t.pengajuan_id
        LEFT JOIN pembimbing_assignments a ON a.id = t.pembimbing_assignment_id WHERE p.id IS NULL OR a.id IS NULL',
    'perbaikan → pengajuan/tinjauan' => 'SELECT COUNT(*) FROM izin_wali_perbaikan w LEFT JOIN izin_pengajuan p ON p.id = w.pengajuan_id
        LEFT JOIN izin_pembimbing_tinjauan t ON t.id = w.tinjauan_id WHERE p.id IS NULL OR t.id IS NULL',
    'keputusan → pengajuan' => 'SELECT COUNT(*) FROM izin_keputusan k LEFT JOIN izin_pengajuan p ON p.id = k.pengajuan_id WHERE p.id IS NULL',
    'riwayat → pengajuan' => 'SELECT COUNT(*) FROM izin_riwayat_status r LEFT JOIN izin_pengajuan p ON p.id = r.pengajuan_id WHERE p.id IS NULL',
    'idempotensi → pengajuan' => 'SELECT COUNT(*) FROM izin_idempotency_keys k LEFT JOIN izin_pengajuan p ON p.id = k.pengajuan_id WHERE k.pengajuan_id IS NOT NULL AND p.id IS NULL',
    'outbox V7 → pengajuan' => "SELECT COUNT(*) FROM notifikasi_outbox o LEFT JOIN izin_pengajuan p
        ON p.id = CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(o.event_key, ':', 3), ':', -1) AS UNSIGNED) WHERE o.event_key LIKE 'v7:izin:%' AND p.id IS NULL",
    'outbox V7 → penerima' => "SELECT COUNT(*) FROM notifikasi_outbox o LEFT JOIN users u ON u.id = o.penerima_user_id WHERE o.event_key LIKE 'v7:izin:%' AND u.id IS NULL",
    'relasi santri–wali' => 'SELECT COUNT(*) FROM santri_wali sw LEFT JOIN santri s ON s.id = sw.santri_id LEFT JOIN wali w ON w.id = sw.wali_id WHERE s.id IS NULL OR w.id IS NULL',
];
$adaYatim = [];
foreach ($yatim as $label => $sql) {
    $jumlah = $n($sql);
    if ($jumlah > 0) {
        $adaYatim[] = $label . ' ' . $jumlah;
    }
}
$periksa($adaYatim === [], 'Nol referensi yatim pada ' . count($yatim) . ' relasi perizinan/V7/outbox/identitas' . ($adaYatim === [] ? '' : ': ' . implode('; ', $adaYatim)));

// 2e. Laporan = sumber.
$laporan = (new IzinLaporanRepository($db))->ringkasan([], ['mode' => 'admin', 'tanggal' => date('Y-m-d')]);
$dariLaporan = [];
foreach ($laporan['per_asal_status'] as $b) {
    $dariLaporan[$b['asal'] . '|' . $b['status']] = (int) $b['jumlah'];
}
$dariSumber = [];
foreach ($semua('SELECT asal_pengajuan, status, COUNT(*) AS n FROM izin_pengajuan GROUP BY asal_pengajuan, status') as $b) {
    $dariSumber[$b['asal_pengajuan'] . '|' . $b['status']] = (int) $b['n'];
}
ksort($dariLaporan);
ksort($dariSumber);
$periksa($dariLaporan === $dariSumber, 'Laporan V7 (cakupan admin, seluruh waktu) = hitungan sumber per asal/status (' . array_sum($dariSumber) . ' pengajuan dihitung tepat sekali)');
echo '[info] Per asal: ' . json_encode($semua('SELECT asal_pengajuan, COUNT(*) AS n FROM izin_pengajuan GROUP BY asal_pengajuan')) . PHP_EOL;

echo PHP_EOL . '=== 3. Tabel lama, irisan V2, dan isi V7 ===' . PHP_EOL;
if ($manifestPath !== null) {
    $manifest = json_decode((string) @file_get_contents($manifestPath), true);
    $sah = is_array($manifest) && ($manifest['fase'] ?? null) === 'v7-rilis' && is_array($manifest['legacy_counts'] ?? null)
        && is_array($manifest['isi_tabel_lama'] ?? null) && is_array($manifest['irisan_v2'] ?? null) && is_array($manifest['isi_v7'] ?? null);
    $periksa($sah, 'Manifest privat V7 terbaca (bin/v7_preflight.php)');
    if ($sah) {
        $kini = v7_snapshot($db);
        // Nama boleh berbeda: DB pulihan dibandingkan dengan manifest sumbernya.
        echo '[info] Manifest dari basis data ' . (string) ($manifest['database'] ?? '?') . ', diambil ' . (string) ($manifest['captured_at'] ?? '?') . PHP_EOL;
        $kunciSama = static function (array $a, array $b): bool {
            $x = array_keys($a);
            $y = array_keys($b);
            sort($x);
            sort($y);

            return $x === $y;
        };
        $periksa($kunciSama($manifest['irisan_v2'], $kini['irisan_v2']), 'Manifest memuat seluruh irisan V2 (' . count($kini['irisan_v2']) . ')');
        foreach ($kini['irisan_v2'] as $nama => $nilai) {
            $periksa(($manifest['irisan_v2'][$nama] ?? null) === $nilai, "Irisan V2 $nama tetap ({$nilai['baris']} baris)");
        }
        if (!$hanyaIrisan) {
            // Daftar kosong/terpotong tidak boleh meloloskan preservasi.
            $periksa($kunciSama($manifest['legacy_counts'], $kini['legacy_counts']) && $kunciSama($manifest['isi_tabel_lama'], $kini['isi_tabel_lama']),
                'Manifest memuat tepat seluruh tabel lama (' . count($kini['legacy_counts']) . ' tabel)');
            $beda = array_keys(array_filter($kini['legacy_counts'], static fn (int $c, string $t): bool => ($manifest['legacy_counts'][$t] ?? null) !== $c, ARRAY_FILTER_USE_BOTH));
            $periksa($beda === [], 'Jumlah baris seluruh tabel lama tetap' . ($beda === [] ? '' : ' (berubah: ' . implode(', ', $beda) . ')'));
            $beda = array_keys(array_filter($kini['isi_tabel_lama'], static fn (array $h, string $t): bool => ($manifest['isi_tabel_lama'][$t] ?? null) !== $h, ARRAY_FILTER_USE_BOTH));
            $periksa($beda === [], 'Isi seluruh ' . count($kini['isi_tabel_lama']) . ' tabel lama (identitas, PSB, V1–V6, perizinan V2) tetap'
                . ($beda === [] ? '' : ' (berubah: ' . implode(', ', $beda) . ')'));
            $sesudahV7 = ($manifest['v7_terpasang'] ?? null) === array_fill_keys(V7_MIGRASI, true);
            $sebelumV7 = ($manifest['v7_terpasang'] ?? null) === array_fill_keys(V7_MIGRASI, false);
            $periksa($sebelumV7 || $sesudahV7, 'Manifest diambil sebelum atau sesudah rilis utuh V7 (bukan sebagian)');
            if ($sesudahV7) {
                $periksa($kunciSama($manifest['isi_v7'], $kini['isi_v7']) && count($kini['isi_v7']) === 1 + count(V7_TABEL),
                    'Manifest pasca-V7 memuat isi baris Wali dan ketiga tabel V7');
                foreach ($kini['isi_v7'] as $nama => $nilai) {
                    $periksa(($manifest['isi_v7'][$nama] ?? null) === $nilai, "Isi V7 $nama tetap ({$nilai['baris']} baris)");
                }
            } else {
                echo '[info] Manifest diambil sebelum 030–031; isi V7 tidak dibandingkan.' . PHP_EOL;
            }
        }
    }
} else {
    echo '[belum diperiksa] Tabel lama, irisan V2, dan isi V7: berikan manifest privat bin/v7_preflight.php.' . PHP_EOL;
}

echo PHP_EOL . 'Ringkasan verifier V7: ' . $lulus . ' lulus, ' . $gagal . ' gagal' . PHP_EOL;
exit($gagal > 0 ? 1 : 0);
