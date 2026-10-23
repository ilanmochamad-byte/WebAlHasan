<?php

declare(strict_types=1);

/**
 * Fixture sintetis redesain website Tahap 5 (pembinaan).
 *
 * Dijalankan oleh `tests/redesain_web_tahap5_db.sh` di atas salinan DB Tahap 4
 * (`test_*_test`). Menolak DB lain. Tanpa data pribadi. Semua catatan dibuat
 * lewat layanan V3 yang sama dengan aplikasi (poin, rekomendasi, audit,
 * publikasi), bukan SQL langsung, kecuali satu penugasan pembimbing tambahan.
 *
 * Menambah:
 *   - penugasan pembimbing sbx_pengurus_b atas SBX Kamar A, sehingga Kamar A
 *     memiliki dua pembimbing (A dan B) dan satu murobi (A) — untuk menguji
 *     kasus Rahasia yang harus tersembunyi dari pembimbing lain dan murobi;
 *   - kategori "Ibadah", tiga jenis (Ringan/Sedang/Berat) dan satu jenis
 *     nonaktif; ambang lama diubah menjadi 10–29 (beralasan) dan ambang baru
 *     "Pembinaan khusus" 30+;
 *   - pelanggaran: A1 dua catatan (total 30, rekomendasi ambang 2 menunggu),
 *     A2 satu catatan beruraian tag HTML, C1 satu catatan;
 *   - kasus Internal A1 (sesi selesai + sesi terjadwal) dan kasus Rahasia A2
 *     milik pengurus A yang menautkan pelanggaran A2;
 *   - publikasi ke wali A: dari kasus Internal (belum dibaca), dari
 *     pelanggaran A1 (sudah dibaca), dan satu yang ditarik.
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

$namaDb = (string) app_config('database.database');
if (!preg_match('/^test_.*_test$/', $namaDb)) {
    fwrite(STDERR, 'Seed Tahap 5 hanya untuk DB test_*_test, bukan ' . $namaDb . PHP_EOL);
    exit(2);
}

$db = app_db();
$satu = static function (string $sql, array $params = []) use ($db): ?array {
    $stmt = $db->prepare($sql);
    if ($params !== []) {
        $stmt->bind_param(str_repeat('s', count($params)), ...array_map('strval', $params));
    }
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
};
$akun = static fn (string $u): array => $satu('SELECT * FROM users WHERE username=?', [$u]) ?? throw new RuntimeException('Akun ' . $u . ' tidak ada');
$admin = $akun('sbx_admin');
$pa = $akun('sbx_pengurus_a');
$pb = $akun('sbx_pengurus_b');
$ortuA = $akun('sbx_ortu_a');
$kunci = static fn (string $p): string => 't5-' . $p . '-' . bin2hex(random_bytes(6));
$tahun = (int) $satu("SELECT id FROM tahun_ajaran WHERE status='Aktif' AND archived_at IS NULL LIMIT 1")['id'];
$kamarA = (int) $satu("SELECT id FROM kamar WHERE nama_kamar='SBX Kamar A'")['id'];

// Pembimbing B ikut membina Kamar A.
$stmt = $db->prepare("INSERT INTO pembimbing_assignments (pengurus_id,tahun_ajaran_id,target_type,kamar_id,tanggal_mulai,is_active,created_by,catatan) VALUES (?,?,'Kamar',?,CURDATE() - INTERVAL 30 DAY,1,?,'Fixture Tahap 5')");
$pengurusB = (int) $pb['pengurus_id'];
$adminId = (int) $admin['id'];
$stmt->bind_param('iiii', $pengurusB, $tahun, $kamarA, $adminId);
$stmt->execute();
$stmt->close();

// Katalog & ambang lewat layanan katalog (riwayat audit untuk M-4).
$katalog = v3_katalog_service();
$hariIni = date('Y-m-d', strtotime('-30 days'));
$ibadah = $katalog->save('kategori', ['kode' => 'SBX-IBD', 'nama' => 'Ibadah', 'tanggal_mulai' => $hariIni, 'is_active' => '1', 'alasan' => ''], $admin);
$jenis = [];
foreach ([['SBX-TSB', 'Terlambat sholat berjamaah', 'Ringan', 5], ['SBX-BLS', 'Bolos pengajian', 'Sedang', 10], ['SBX-HP', 'Membawa HP tanpa izin', 'Berat', 25]] as [$kode, $nama, $tingkat, $poin]) {
    $jenis[$kode] = $katalog->save('katalog', ['kode' => $kode, 'nama' => $nama, 'kategori_id' => (string) $ibadah, 'tingkat' => $tingkat, 'poin_default' => (string) $poin, 'tanggal_mulai' => $hariIni, 'is_active' => '1', 'alasan' => ''], $admin);
}
$katalog->save('katalog', ['kode' => 'SBX-LAMA', 'nama' => 'Jenis lama nonaktif', 'kategori_id' => (string) $ibadah, 'tingkat' => 'Ringan', 'poin_default' => '1', 'tanggal_mulai' => $hariIni, 'is_active' => '0', 'alasan' => ''], $admin);
$ambangLama = $satu('SELECT * FROM v3_ambang ORDER BY id LIMIT 1');
$katalog->save('ambang', ['label' => $ambangLama['label'], 'nilai_minimum' => '10', 'nilai_maksimum' => '29', 'tahun_ajaran_id' => (string) $tahun, 'rekomendasi' => $ambangLama['rekomendasi'], 'tanggal_mulai' => $ambangLama['tanggal_mulai'],
    'is_active' => '1', 'version' => (string) $ambangLama['version'], 'alasan' => 'Keputusan rapat pengasuh: rentang dibatasi 10–29'], $admin, (int) $ambangLama['id']);
$katalog->save('ambang', ['label' => 'Pembinaan khusus', 'nilai_minimum' => '30', 'nilai_maksimum' => '', 'tahun_ajaran_id' => (string) $tahun, 'rekomendasi' => 'Buka kasus konseling dan libatkan wali.', 'tanggal_mulai' => $ambangLama['tanggal_mulai'], 'is_active' => '1', 'alasan' => ''], $admin);

// Pelanggaran lewat layanan (poin, ledger, rekomendasi).
$pel = v3_pelanggaran_service();
$santri = static fn (string $nama): int => (int) $satu('SELECT id FROM santri WHERE nama_santri=?', [$nama])['id'];
$catat = static function (string $nama, string $kode, string $uraian, string $waktu) use ($pel, $pa, $santri, $tahun, $jenis, $kunci): int {
    return (int) $pel->create($pa, ['santri_id' => $santri($nama), 'tahun_ajaran_id' => $tahun, 'katalog_id' => $jenis[$kode], 'waktu_kejadian' => $waktu,
        'tempat' => 'Masjid', 'uraian' => $uraian, 'saksi' => 'SBX Saksi', 'idempotency_key' => $kunci('pel')])['data']['pelanggaran']['id'];
};
$a1Tsb = $catat('SBX Santri A1', 'SBX-TSB', 'Datang setelah iqamah (sintetis).', date('Y-m-d H:i:s', strtotime('-3 days 05:10')));
$a1Bls = $catat('SBX Santri A1', 'SBX-BLS', 'Tidak hadir pengajian malam (sintetis).', date('Y-m-d H:i:s', strtotime('-2 days 19:30')));
$a2 = $catat('SBX Santri A2', 'SBX-TSB', 'Uraian <b>bertag</b> <script>window.T5_XSS=1</script> (sintetis).', date('Y-m-d H:i:s', strtotime('-1 days 05:15')));
$c1 = $catat('SBX Santri C1', 'SBX-BLS', 'Tidak hadir pengajian (sintetis).', date('Y-m-d H:i:s', strtotime('-1 days 19:30')));

// Kasus Internal A1 dengan sesi selesai dan sesi terjadwal.
$kon = v3_konseling_service();
$kasusInternal = (int) $kon->createCase($pa, ['santri_id' => $santri('SBX Santri A1'), 'tahun_ajaran_id' => $tahun, 'tujuan' => 'Kedisiplinan sholat berjamaah (sintetis).', 'kerahasiaan' => 'Internal',
    'dibuka_pada' => date('Y-m-d H:i:s', strtotime('-2 days')), 'pelanggaran_ids' => [$a1Tsb], 'idempotency_key' => $kunci('kasus')])['data']['kasus']['id'];
$sesi1 = (int) $kon->createSession($pa, $kasusInternal, ['jadwal' => date('Y-m-d H:i:s', strtotime('+1 hour')), 'idempotency_key' => $kunci('sesi')])['data']['sesi']['id'];
$versiSesi = (int) $satu('SELECT version FROM v3_konseling_sesi WHERE id=?', [$sesi1])['version'];
$kon->transitionSession($pa, $sesi1, ['status' => 'Selesai', 'version' => $versiSesi, 'realisasi' => date('Y-m-d H:i:s', strtotime('-1 hour')), 'ringkasan_internal' => 'PRIVATE-T5-RINGKASAN sesi pertama.', 'hasil' => 'Santri berkomitmen hadir tepat waktu.', 'idempotency_key' => $kunci('sesi-status')]);
$kon->createSession($pa, $kasusInternal, ['jadwal' => date('Y-m-d H:i:s', strtotime('+3 days 16:00')), 'tindak_lanjut' => 'Evaluasi pekan depan.', 'idempotency_key' => $kunci('sesi2')]);

// Kasus Rahasia A2 milik pengurus A, menautkan pelanggaran A2.
$kasusRahasia = (int) $kon->createCase($pa, ['santri_id' => $santri('SBX Santri A2'), 'tahun_ajaran_id' => $tahun, 'tujuan' => 'PRIVATE-T5-RAHASIA tujuan.', 'kerahasiaan' => 'Rahasia',
    'dibuka_pada' => date('Y-m-d H:i:s', strtotime('-1 days')), 'pelanggaran_ids' => [$a2], 'idempotency_key' => $kunci('kasus-r')])['data']['kasus']['id'];

// Publikasi ke wali A.
$pub = v3_publikasi_service();
$terbit = static function (string $tipe, int $sumber, string $ringkasan) use ($pub, $pa, $kunci, $satu): int {
    $opsi = $pub->options($pa, $tipe, $sumber);
    $pr = $pub->preview($pa, ['sumber_type' => $tipe, 'sumber_id' => $sumber, 'sumber_version' => $opsi['sumber_version'], 'wali_ids' => array_column($opsi['wali'], 'id'), 'ringkasan' => $ringkasan, 'tindak_lanjut' => 'Mohon dampingi anak (sintetis).']);

    return (int) $pub->publish($pa, ['pratinjau_token' => $pr['pratinjau_token'], 'konfirmasi' => true, 'idempotency_key' => $kunci('pub')])['data']['publikasi_ids'][0];
};
$pubKasus = $terbit('kasus', $kasusInternal, 'Ananda mengikuti pendampingan kedisiplinan sholat (sintetis).');
$pubPel = $terbit('pelanggaran', $a1Bls, 'Ananda tidak hadir pengajian malam (sintetis).');
$pub->markRead($ortuA, $pubPel, ['version' => 1]);
$pubTarik = $terbit('pelanggaran', $a1Tsb, 'Informasi yang kemudian ditarik (sintetis).');
$pub->withdraw($pa, $pubTarik, ['version' => 1, 'alasan' => 'Ditarik untuk uji tampilan (sintetis).', 'idempotency_key' => $kunci('tarik')]);

echo json_encode(['tahap' => 5, 'pelanggaran' => ['a1_tsb' => $a1Tsb, 'a1_bls' => $a1Bls, 'a2' => $a2, 'c1' => $c1], 'kasus_internal' => $kasusInternal, 'sesi_selesai' => $sesi1,
    'kasus_rahasia' => $kasusRahasia, 'publikasi' => ['kasus' => $pubKasus, 'pelanggaran' => $pubPel, 'ditarik' => $pubTarik]], JSON_PRETTY_PRINT) . PHP_EOL;
