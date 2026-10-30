<?php

declare(strict_types=1);

/**
 * Uji integrasi Fase 3 penugasan lintas guru/pengurus — penugasan Keamanan,
 * hanya baca (rancangan §3.3; PK-5, PK-6, PK-T2, PK-T4) di atas DB uji
 * `tests/penugasan_keamanan_f3_db.sh` dengan skenario `penugasan_keamanan_f3_seed.php`.
 *
 *   bash tests/penugasan_keamanan_f3_db.sh && php tests/penugasan_keamanan_f3_integration.php
 *
 *   I-1  jenis & capability: K (pengurus) dan G (guru lewat identitas) sah; N, admin tidak
 *   I-2  daftar izin: seluruh santri aktif, kedua asal, semua tahap; cocok oracle SQL
 *   I-3  rincian izin: isi lengkap; santri nonaktif/ID kosong 404; audit tanpa isi; audit gagal → 503
 *   I-4  pelanggaran: santri aktif saja; tautan kasus Rahasia tersembunyi; angka = daftar
 *   I-5  konseling: kasus Rahasia tidak di daftar, angka, pencarian, maupun rincian
 *   I-6  lampiran: terbaca dan unduhan diaudit
 *   I-7  tidak ada jalur tulis: ekspor CSV, catat/koreksi V3, keputusan izin ditolak
 *   I-8  penanda tanpa hak ditolak; parameter `peran` tidak dapat memilih Keamanan
 *   I-9  pencabutan: penugasan nonaktif / guru nonaktif langsung gugur; aktif lagi pulih
 *   I-10 pusat penugasan: jenis terdaftar, bukan "modul mendatang", hak efektif tampil
 *
 * Mengubah data uji; bangun ulang DB sebelum mengulang.
 */

$root = dirname(__DIR__);
$dbName = getenv('PKF3_DB') ?: 'test_pk_f3_test';
if (!preg_match('/^test_.*_test$/', $dbName)) {
    fwrite(STDERR, "DB uji harus bernama test_*_test.\n");
    exit(2);
}
putenv('DB_NAME=' . $dbName);
require_once $root . '/app/bootstrap.php';

use App\Audit\AuditLogger;
use App\Auth\Capabilities;
use App\Izin\IzinException;
use App\Keamanan\KeamananAkses;
use App\Keamanan\KeamananException;
use App\Keamanan\KeamananService;
use App\Penugasan\PenugasanJenis;
use App\V3\V3Exception;

$lulus = 0;
$gagal = [];
$assert = static function (bool $ok, string $pesan) use (&$lulus, &$gagal): void {
    echo ($ok ? '[lulus] ' : '[gagal] ') . $pesan . PHP_EOL;
    $ok ? $lulus++ : $gagal[] = $pesan;
};
$db = app_db();
$satu = static function (string $sql) use ($db): mixed {
    $r = $db->query($sql);
    if ($r === false) {
        throw new RuntimeException($db->error . ' :: ' . $sql);
    }

    return $r->fetch_row()[0] ?? null;
};
$kolom = static function (string $sql) use ($db): array {
    $r = $db->query($sql);
    if ($r === false) {
        throw new RuntimeException($db->error . ' :: ' . $sql);
    }

    return array_map(static fn (array $x): int => (int) $x[0], $r->fetch_all());
};
$ids = require __DIR__ . '/penugasan_keamanan_f3_seed.php';
register_shutdown_function(static function () use ($ids): void {
    @unlink($ids['lampiran_berkas']);
});
$caps = capabilities();
$akun = static function (int $userId) use ($satu, $caps): array {
    $caps->forget($userId);
    $roles = (string) $satu("SELECT GROUP_CONCAT(r.slug ORDER BY r.slug) FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = $userId");
    $guru = $satu("SELECT guru_id FROM users WHERE id = $userId");

    return ['id' => $userId, 'roles' => $roles === '' ? [] : explode(',', $roles), 'guru_id' => $guru === null ? null : (int) $guru];
};
$status = static function (callable $f): int {
    try {
        $f();

        return 200;
    } catch (KeamananException | IzinException $e) {
        return $e->status();
    } catch (V3Exception $e) {
        return $e->status;
    }
};
$auditKeamanan = static fn (): int => (int) $satu("SELECT COUNT(*) FROM audit_logs WHERE action LIKE 'keamanan.%'");

$k = $akun($ids['k']);
$g = $akun($ids['g']);
$n = $akun($ids['n']);
$admin = $akun($ids['admin']);
$kt = KeamananAkses::tandai($k);
$gt = KeamananAkses::tandai($g);

// --- I-1 jenis & capability --------------------------------------------------
$def = PenugasanJenis::definisi(PenugasanJenis::KEAMANAN);
$assert($def['cakupan'] === PenugasanJenis::CAKUPAN_SELURUH && $def['wajib_tahun_aktif'] === false && $def['subjek'] === 'pengurus'
    && $def['capabilities'] === [PenugasanJenis::CAP_KEAMANAN_PANTAU], 'I-1 jenis Keamanan: seluruh santri aktif, tidak gugur semester, subjek pengurus, satu capability baca');
$assert(KeamananAkses::sah($caps, $k) && $caps->featureSource($k, PenugasanJenis::CAP_KEAMANAN_PANTAU) === Capabilities::SUMBER_PENUGASAN,
    'I-1 K (pengurus biasa) memegang keamanan.pantau dari penugasan sendiri');
$assert(in_array('pengurus', $g['roles'], true) && KeamananAkses::sah($caps, $g)
    && (int) $satu('SELECT pengurus_id FROM users WHERE id = ' . $ids['g']) === $ids['g_pengurus'],
    'I-1 G (guru) memperoleh peran pengurus dan Keamanan lewat identitas pengurus miliknya (PK-1/PK-3)');
$assert(!KeamananAkses::sah($caps, $n), 'I-1 N (pengurus tanpa penugasan Keamanan) tidak sah');
$assert($caps->featureSource($admin, PenugasanJenis::CAP_KEAMANAN_PANTAU) === Capabilities::SUMBER_ADMIN && !KeamananAkses::sah($caps, $admin),
    'I-1 admin hanya memegang pengawasan (sumber admin), tidak membuka mode Keamanan');
$assert(!in_array(PenugasanJenis::CAP_KEAMANAN_PANTAU, $caps->forUser($k), true) && $caps->forUser($k) === $caps->forUser(['id' => $ids['k'], 'roles' => $k['roles'], 'guru_id' => null]),
    'I-1 daftar kemampuan perizinan V2 (`forUser`) tidak berubah bentuk');

// --- I-2 daftar izin -------------------------------------------------------------
$oracleIzin = (int) $satu('SELECT COUNT(*) FROM izin_pengajuan p JOIN santri s ON s.id = p.santri_id WHERE s.is_active = 1 AND s.archived_at IS NULL');
$lap = izin_laporan_service()->laporan($kt, []);
$assert($lap['peran'] === KeamananAkses::MODE && $lap['total'] === $oracleIzin && $lap['ringkasan']['total'] === $oracleIzin,
    'I-2 total daftar dan kartu = oracle SQL santri aktif (' . $lap['total'] . ' = ' . $oracleIzin . ')');
$semua = izin_laporan_service()->cetakKeamanan($kt, []);
$idSemua = array_column($semua['items'], 'id');
$assert(in_array($ids['izin_wali'], $idSemua, true) && in_array($ids['izin_aktif'], $idSemua, true) && !in_array($ids['izin_nonaktif'], $idSemua, true),
    'I-2 memuat izin asal Wali (tahap pembimbing) dan Pengurus; izin santri nonaktif tidak');
$assert(count($idSemua) === $oracleIzin && $lap['ringkasan']['per_asal']['Wali'] >= 1,
    'I-2 cetak memuat seluruh baris kriteria yang sama dengan daftar');
$itemWali = array_values(array_filter($semua['items'], static fn (array $i): bool => $i['id'] === $ids['izin_wali']))[0];
$assert($itemWali['detail']['web'] === '/portal/keamanan_izin_detail.php?id=' . $ids['izin_wali'] && $itemWali['detail']['api'] === null
    && !array_key_exists('alasan', $itemWali), 'I-2 baris menunjuk rincian Keamanan; daftar tanpa alasan izin');
$lapWali = izin_laporan_service()->laporan($kt, ['asal' => 'wali', 'tahap' => 'pembimbing']);
$assert($lapWali['total'] === (int) $satu("SELECT COUNT(*) FROM izin_pengajuan p JOIN santri s ON s.id = p.santri_id WHERE s.is_active = 1 AND s.archived_at IS NULL AND p.asal_pengajuan = 'Wali' AND p.status = 'Menunggu Pembimbing'"),
    'I-2 saringan asal/tahap mempersempit di dalam cakupan Keamanan');

// --- I-3 rincian izin ---------------------------------------------------------
$sebelum = $auditKeamanan();
$detail = keamanan_service()->izinDetail($k, $ids['izin_wali']);
$assert($detail['pengajuan']['alasan'] === $ids['alasan_wali'] && $detail['pengajuan']['catatan_wali'] === 'PKF3 catatan wali'
    && $detail['pengajuan']['asal'] === 'Wali' && $detail['riwayat'] !== [], 'I-3 rincian wali: alasan, catatan wali, dan riwayat lengkap (PK-T4)');
$detailV2 = keamanan_service()->izinDetail($k, $ids['izin_aktif']);
$assert($detailV2['keputusan'] !== null && $detailV2['pengajuan']['status'] === 'Disetujui' && $detailV2['pengajuan']['tgl_kembali'] !== '',
    'I-3 rincian pengurus: keputusan, status, tanggal keluar/kembali');
$jejak = $db->query("SELECT action, entity_id, after_json FROM audit_logs WHERE action = 'keamanan.izin.dilihat' ORDER BY id DESC LIMIT 2")->fetch_all(MYSQLI_ASSOC);
$assert($auditKeamanan() === $sebelum + 2 && (int) $jejak[1]['entity_id'] === $ids['izin_wali']
    && !str_contains((string) $jejak[1]['after_json'], 'PKF3') && !str_contains((string) $jejak[1]['after_json'], 'alasan'),
    'I-3 setiap pembukaan rincian diaudit dengan ID/asal/status saja, tanpa alasan');
$assert($status(static fn () => keamanan_service()->izinDetail($k, $ids['izin_nonaktif'])) === 404
    && $status(static fn () => keamanan_service()->izinDetail($k, 999999999)) === 404,
    'I-3 izin santri nonaktif dan ID tidak ada sama-sama 404');
$assert($status(static fn () => keamanan_service()->izinDetail($n, $ids['izin_wali'])) === 403
    && $status(static fn () => keamanan_service()->izinDetail($admin, $ids['izin_wali'])) === 403,
    'I-3 N dan admin (tanpa penugasan sendiri) ditolak 403 pada rincian Keamanan');
// Koneksi audit yang belum tersambung: setiap INSERT audit gagal (AuditLogger mengembalikan false).
$koneksiMati = mysqli_init();
$layananGagal = new KeamananService($db, $caps, new AuditLogger($koneksiMati), izin_repository(), new App\Izin\IzinWaliRepository($db), izin_laporan_service());
$sebelum = $auditKeamanan();
$assert($status(static fn () => $layananGagal->izinDetail($k, $ids['izin_wali'])) === 503 && $auditKeamanan() === $sebelum,
    'I-3 audit gagal disimpan → 503, rincian tidak dikembalikan');

// --- I-4 pelanggaran -------------------------------------------------------------
$oraclePel = $kolom('SELECT p.id FROM v3_pelanggaran p JOIN santri s ON s.id = p.santri_id WHERE s.is_active = 1 AND s.archived_at IS NULL AND p.archived_at IS NULL
    AND NOT EXISTS (SELECT 1 FROM v3_pelanggaran nx WHERE nx.revisi_dari_id = p.id) ORDER BY p.id DESC');
$halPel = v3_pelanggaran_service()->page($kt, []);
$assert(array_column($halPel['rows'], 'id') === $oraclePel && !in_array($ids['pelanggaran_nonaktif'], $oraclePel, true),
    'I-4 daftar pelanggaran = oracle SQL santri aktif; santri nonaktif tidak muncul');
$assert(array_sum(v3_pelanggaran_service()->statusCounts($kt, [])) === $halPel['total'], 'I-4 angka chip = total daftar');
$sebelum = $auditKeamanan();
$pelRahasia = v3_pelanggaran_service()->show($kt, $ids['pelanggaran_rahasia']);
$pelAdmin = v3_pelanggaran_service()->show($admin, $ids['pelanggaran_rahasia']);
$assert($pelRahasia['konseling'] === [] && in_array($ids['kasus_rahasia'], array_column($pelAdmin['konseling'], 'kasus_id'), true),
    'I-4 pelanggaran bertaut kasus Rahasia: Keamanan tidak melihat tautannya (admin melihat)');
$pelInternal = v3_pelanggaran_service()->show($kt, $ids['pelanggaran_internal']);
$assert(in_array($ids['kasus_internal'], array_column($pelInternal['konseling'], 'kasus_id'), true) && $pelInternal['pelanggaran']['uraian'] !== '',
    'I-4 pelanggaran bertaut kasus Internal: tautan dan uraian lengkap terbaca (PK-T4)');
$assert($auditKeamanan() === $sebelum + 2 && $status(static fn () => v3_pelanggaran_service()->show($kt, $ids['pelanggaran_nonaktif'])) === 403,
    'I-4 pembukaan rincian diaudit; pelanggaran santri nonaktif ditolak');
$uraian = (string) $satu('SELECT uraian FROM v3_pelanggaran WHERE id = ' . $ids['pelanggaran_internal']);
$assert((int) $satu("SELECT COUNT(*) FROM audit_logs WHERE action LIKE 'keamanan.%' AND after_json LIKE '%" . $db->real_escape_string(mb_substr($uraian, 0, 20)) . "%'") === 0,
    'I-4 jejak audit Keamanan tidak memuat uraian pelanggaran');

// --- I-5 konseling ---------------------------------------------------------------
$halKasus = v3_konseling_service()->page($kt, []);
$hitung = v3_konseling_service()->statusCounts($kt, []);
$oracleKasus = $kolom("SELECT k.id FROM v3_konseling_kasus k JOIN santri s ON s.id = k.santri_id WHERE s.is_active = 1 AND s.archived_at IS NULL AND k.archived_at IS NULL AND k.kerahasiaan <> 'Rahasia' ORDER BY k.id DESC");
$assert(array_column($halKasus['rows'], 'id') === $oracleKasus && !in_array($ids['kasus_rahasia'], $oracleKasus, true) && array_sum($hitung) === count($oracleKasus),
    'I-5 daftar dan angka chip konseling tanpa kasus Rahasia (= oracle SQL)');
$namaRahasia = (string) $satu('SELECT s.nama_santri FROM v3_konseling_kasus k JOIN santri s ON s.id = k.santri_id WHERE k.id = ' . $ids['kasus_rahasia']);
$cari = v3_konseling_service()->page($kt, ['q' => $namaRahasia]);
$assert(!in_array($ids['kasus_rahasia'], array_column($cari['rows'], 'id'), true) && array_sum(v3_konseling_service()->statusCounts($kt, ['q' => $namaRahasia])) === $cari['total'],
    'I-5 pencarian nama santri kasus Rahasia tidak memunculkan kasus itu');
$assert($status(static fn () => v3_konseling_service()->show($kt, $ids['kasus_rahasia'])) === 403
    && $status(static fn () => v3_konseling_service()->show($kt, 999999)) === 403,
    'I-5 rincian kasus Rahasia dan ID tidak ada diperlakukan sama');
$sebelum = $auditKeamanan();
$kasus = v3_konseling_service()->show($kt, $ids['kasus_internal']);
v3_konseling_service()->timeline($kt, $ids['kasus_internal'], $kasus);
$assert($kasus['akses_internal'] === true && ($kasus['kasus']['tujuan'] ?? '') !== '' && $auditKeamanan() === $sebelum + 1,
    'I-5 kasus Internal terbaca lengkap; satu pembukaan = satu jejak audit (timeline memakai detail yang sama)');

// --- I-6 lampiran ------------------------------------------------------------------
$sebelum = $auditKeamanan();
$berkas = v3_pelanggaran_service()->attachment($kt, $ids['lampiran']);
$assert(is_file($berkas['path']) && hash_file('sha256', $berkas['path']) === $berkas['sha256'] && $auditKeamanan() === $sebelum + 1
    && $satu("SELECT action FROM audit_logs ORDER BY id DESC LIMIT 1") === 'keamanan.pelanggaran.lampiran.diunduh',
    'I-6 lampiran pelanggaran terbaca dan unduhannya diaudit');

// --- I-7 tidak ada jalur tulis ---------------------------------------------------
$assert($status(static fn () => izin_laporan_service()->ekspor($kt, [])) === 403, 'I-7 ekspor CSV izin ditolak bagi Keamanan (PK-T2)');
$jumlahPel = (int) $satu('SELECT COUNT(*) FROM v3_pelanggaran');
$jumlahKasus = (int) $satu('SELECT COUNT(*) FROM v3_konseling_kasus');
$catat = ['santri_id' => '1', 'tahun_ajaran_id' => (string) $ids['tahun'], 'katalog_id' => '1', 'waktu_kejadian' => date('Y-m-d H:i'), 'uraian' => 'PKF3 coba', 'idempotency_key' => 'pkf3-coba-' . bin2hex(random_bytes(4))];
$assert($status(static fn () => v3_pelanggaran_service()->create($kt, $catat)) === 403
    && $status(static fn () => v3_pelanggaran_service()->create($gt, $catat)) === 403,
    'I-7 Keamanan tidak dapat mencatat pelanggaran (dengan maupun tanpa penanda)');
$assert($status(static fn () => v3_konseling_service()->createCase($kt, ['santri_id' => '1', 'tahun_ajaran_id' => (string) $ids['tahun'], 'tujuan' => 'x', 'kerahasiaan' => 'Internal', 'idempotency_key' => 'pkf3-kasus-' . bin2hex(random_bytes(4))])) === 403,
    'I-7 Keamanan tidak dapat membuka kasus konseling');
$assert($status(static fn () => v3_pelanggaran_service()->acknowledge($gt, $ids['pelanggaran_internal'], ['idempotency_key' => 'pkf3-ack-' . bin2hex(random_bytes(4))])) === 403,
    'I-7 Keamanan tidak dapat menandai mengetahui');
$versi = (int) $satu('SELECT version FROM izin_pengajuan WHERE id = ' . $ids['izin_wali']);
// V7 menyembunyikan pengajuan dari pengurus yang bukan pembimbing tujuan (404).
$assert(in_array($status(static fn () => izin_wali_service()->tinjau($kt, $ids['izin_wali'], 'teruskan', 'x', $versi, 'pkf3-tinjau-' . bin2hex(random_bytes(4)), ['ip' => '127.0.0.1', 'user_agent' => 'pkf3'])), [403, 404], true),
    'I-7 Keamanan tidak dapat meninjau pengajuan wali (ditolak 403/404)');
$assert((int) $satu('SELECT COUNT(*) FROM v3_pelanggaran') === $jumlahPel && (int) $satu('SELECT COUNT(*) FROM v3_konseling_kasus') === $jumlahKasus
    && (int) $satu('SELECT version FROM izin_pengajuan WHERE id = ' . $ids['izin_wali']) === $versi,
    'I-7 tidak ada baris pembinaan/izin yang berubah');

// --- I-8 penanda tanpa hak; parameter tidak memilih Keamanan ----------------------
$nt = KeamananAkses::tandai($n);
$assert($status(static fn () => izin_laporan_service()->laporan($nt, [])) === 403
    && $status(static fn () => v3_pelanggaran_service()->page($nt, [])) === 403
    && $status(static fn () => v3_konseling_service()->page($nt, [])) === 403
    && $status(static fn () => v3_pelanggaran_service()->page(KeamananAkses::tandai($admin), [])) === 403,
    'I-8 penanda Keamanan pada akun tanpa penugasan (N, admin) ditolak di layanan');
$biasa = izin_laporan_service()->laporan($n, ['peran' => 'keamanan']);
$assert($biasa['peran'] !== KeamananAkses::MODE && $biasa['total'] < $oracleIzin, 'I-8 parameter `peran=keamanan` tanpa penanda diabaikan');
$assert($status(static fn () => v3_pelanggaran_service()->page($k, [])) === 403, 'I-8 halaman pembinaan biasa tetap menolak K (hak tidak melebar)');

// --- I-9 pencabutan ---------------------------------------------------------------
penugasan_service()->nonaktifkan(PenugasanJenis::KEAMANAN, $ids['penugasan_k'], 'PKF3 uji pencabutan', $ids['admin']);
$assert(!KeamananAkses::sah($caps, $k) && $status(static fn () => izin_laporan_service()->laporan($kt, [])) === 403
    && $status(static fn () => v3_konseling_service()->show($kt, $ids['kasus_internal'])) === 403
    && $status(static fn () => keamanan_service()->izinDetail($k, $ids['izin_wali'])) === 403,
    'I-9 penugasan dinonaktifkan: hak gugur pada pemeriksaan berikutnya (daftar, rincian, V3)');
penugasan_service()->aktifkan(PenugasanJenis::KEAMANAN, $ids['penugasan_k'], 'PKF3 uji pemulihan', $ids['admin']);
$assert(KeamananAkses::sah($caps, $k) && izin_laporan_service()->laporan($kt, [])['total'] === $oracleIzin, 'I-9 diaktifkan lagi: hak pulih');
$gGuru = (int) $g['guru_id'];
$db->query("UPDATE guru SET is_active = 0 WHERE id = $gGuru");
$assert(!KeamananAkses::sah($caps, $g) && $status(static fn () => v3_pelanggaran_service()->page($gt, [])) === 403,
    'I-9 data guru G nonaktif: Keamanan lewat identitas guru gugur walau sinkronisasi terlewat');
$db->query("UPDATE guru SET is_active = 1 WHERE id = $gGuru");
$assert(KeamananAkses::sah($caps, $g), 'I-9 data guru G aktif lagi: hak pulih');

// --- I-10 pusat penugasan ----------------------------------------------------------
$daftar = penugasan_service()->daftar(PenugasanJenis::KEAMANAN, ['status' => 'aktif'], 1);
$assert($daftar['total'] === 2 && !in_array(PenugasanJenis::KEAMANAN, PenugasanJenis::MENDATANG, true) && penugasan_service()->skemaTersedia(PenugasanJenis::KEAMANAN),
    'I-10 pusat penugasan memuat dua penugasan Keamanan aktif; jenis bukan modul mendatang');
$urutan = array_keys(PenugasanJenis::semua());
$assert(array_search(PenugasanJenis::KEAMANAN, $urutan, true) < array_search(PenugasanJenis::PANITIA_PSB, $urutan, true)
    && PenugasanJenis::labelCapability(PenugasanJenis::CAP_KEAMANAN_PANTAU) !== PenugasanJenis::CAP_KEAMANAN_PANTAU
    && PenugasanJenis::hakLabel(PenugasanJenis::KEAMANAN) !== [],
    'I-10 Keamanan tampil sebelum kelompok modul mendatang dan berlabel');
$efektif = penugasan_service()->capabilityEfektif(PenugasanJenis::KEAMANAN, (int) $satu('SELECT pengurus_id FROM users WHERE id = ' . $ids['k']));
$assert(in_array(PenugasanJenis::CAP_KEAMANAN_PANTAU, array_merge(...array_values(array_map(static fn ($v): array => is_array($v) ? $v : [$v], $efektif))), true)
    || str_contains((string) json_encode($efektif), PenugasanJenis::CAP_KEAMANAN_PANTAU),
    'I-10 hak efektif subjek K menyebut keamanan.pantau');

echo PHP_EOL . 'Hasil: ' . $lulus . ' lulus, ' . count($gagal) . ' gagal.' . PHP_EOL;
exit($gagal === [] ? 0 : 1);
