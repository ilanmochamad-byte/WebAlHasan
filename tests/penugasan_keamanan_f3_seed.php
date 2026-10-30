<?php

declare(strict_types=1);

/**
 * Skenario sintetis Fase 3 (penugasan Keamanan, hanya baca) untuk uji
 * integrasi, HTTP, dan browser, di atas DB `tests/penugasan_keamanan_f3_db.sh`.
 * Penugasan dan pengajuan wali dibentuk lewat layanan resmi. Dua langkah
 * memaksa data fixture: santri 4 dinonaktifkan (cakupan "santri aktif") dan
 * satu lampiran pelanggaran sintetis ditulis ke penyimpanan privat.
 *
 * Akun:
 *   K  `sbx_pengurus_biasa` — pengurus biasa, penugasan Keamanan aktif.
 *   G  `sbx_guru_biasa`     — guru; penugasan Keamanan lewat identitas
 *                              pengurus miliknya (Fase 1), memilih peran.
 *   N  `sbx_pengurus_b`     — pengurus tanpa Keamanan (kontrol).
 *   A  `sbx_admin`          — admin (pengawasan; tidak membuka halaman Keamanan).
 *
 * Data pembinaan fixture: kasus 1 Internal (santri 1, tautan pelanggaran 2),
 * kasus 2 Rahasia (santri 2, tautan pelanggaran 4), pelanggaran 5 milik
 * santri 4 (dinonaktifkan di sini).
 *
 *   php tests/penugasan_keamanan_f3_seed.php     # DB dari PKF3_DB, mencetak ID JSON
 *   $ids = require 'tests/penugasan_keamanan_f3_seed.php';   # dari uji lain (DB sudah dipilih)
 *
 * Hanya sekali per DB; bangun ulang DB sebelum mengulang.
 */

use App\Penugasan\PenugasanJenis;

if (!function_exists('app_db')) {
    $nama = getenv('PKF3_DB') ?: 'test_pk_f3_browser_test';
    if (!preg_match('/^test_.*_test$/', $nama)) {
        fwrite(STDERR, "DB uji harus bernama test_*_test.\n");
        exit(2);
    }
    putenv('DB_NAME=' . $nama);
    require_once dirname(__DIR__) . '/app/bootstrap.php';
}

$pkf3Ids = (static function (): array {
    $conn = app_db();
    $satu = static function (string $sql) use ($conn): mixed {
        $r = $conn->query($sql);
        if ($r === false) {
            throw new RuntimeException($conn->error . ' :: ' . $sql);
        }

        return $r->fetch_row()[0] ?? null;
    };
    $jalankan = static function (string $sql) use ($conn): void {
        if ($conn->query($sql) === false) {
            throw new RuntimeException($conn->error . ' :: ' . $sql);
        }
    };
    if ((int) $satu("SELECT COUNT(*) FROM keamanan_assignments") > 0) {
        throw new RuntimeException('Seed Fase 3 sudah dijalankan pada DB ini; bangun ulang DB.');
    }
    $user = static fn (string $username): int => (int) $satu("SELECT id FROM users WHERE username = '" . $conn->real_escape_string($username) . "'");
    $admin = $user('sbx_admin');
    $tahun = (int) $satu("SELECT id FROM tahun_ajaran WHERE status = 'Aktif' AND archived_at IS NULL ORDER BY id DESC LIMIT 1");
    $hari = date('Y-m-d');

    // K: pengurus biasa → Keamanan.
    $k = $user('sbx_pengurus_biasa');
    $kPengurus = (int) $satu("SELECT pengurus_id FROM users WHERE id = $k");
    $penugasanK = penugasan_service()->buat(PenugasanJenis::KEAMANAN, ['pengurus_id' => (string) $kPengurus, 'tahun_ajaran_id' => (string) $tahun, 'tanggal_mulai' => $hari], $admin);

    // G: guru → Keamanan lewat identitas pengurus miliknya (PK-1/PK-3).
    $g = $user('sbx_guru_biasa');
    $gGuru = (int) $satu("SELECT guru_id FROM users WHERE id = $g");
    $penugasanG = penugasan_service()->buat(PenugasanJenis::KEAMANAN, ['pengurus_id' => 'guru:' . $gGuru, 'tahun_ajaran_id' => (string) $tahun, 'tanggal_mulai' => $hari], $admin);

    // Pengajuan asal Wali (V7) pada tahap pembimbing, lewat layanan resmi.
    $wali = $user('sbx_v7_wali_a');
    $waliSantri = (int) $satu("SELECT sw.santri_id FROM users u JOIN santri_wali sw ON sw.wali_id = u.wali_id AND sw.archived_at IS NULL
        JOIN santri s ON s.id = sw.santri_id AND s.is_active = 1 AND s.archived_at IS NULL WHERE u.id = $wali ORDER BY sw.santri_id LIMIT 1");
    capabilities()->forget($wali);
    $hasilWali = izin_wali_service()->buat(
        ['id' => $wali, 'roles' => ['orang_tua'], 'guru_id' => null],
        ['santri_id' => $waliSantri, 'tgl_izin' => $hari, 'tgl_kembali' => date('Y-m-d', strtotime('+2 day')),
            'alasan' => 'PKF3 alasan wali: menjenguk kakek di rumah sakit', 'catatan' => 'PKF3 catatan wali'],
        'pkf3-wali-' . bin2hex(random_bytes(6)),
        ['ip' => '127.0.0.1', 'user_agent' => 'pkf3-seed']
    );
    $izinWali = (int) $hasilWali['id'];

    // Pengajuan asal Pengurus (V2) santri aktif, dan santri yang dinonaktifkan.
    $izinAktif = (int) $satu("SELECT p.id FROM izin_pengajuan p JOIN izin_keputusan k ON k.pengajuan_id = p.id
        JOIN santri s ON s.id = p.santri_id AND s.is_active = 1 AND s.id <> 4
        WHERE p.asal_pengajuan = 'Pengurus' AND p.status = 'Disetujui' ORDER BY p.id LIMIT 1");
    $izinNonaktif = (int) $satu("SELECT id FROM izin_pengajuan WHERE santri_id = 4 ORDER BY id LIMIT 1");
    // Fixture dipaksa: santri 4 keluar dari "santri aktif" (PK-5).
    $jalankan('UPDATE santri SET is_active = 0 WHERE id = 4');

    // Lampiran sintetis pada pelanggaran 1 (santri 1), privat.
    $isi = "PKF3 lampiran sintetis\n";
    // `AttachmentStorage::absolute()` hanya membaca nama berkas di akar folder privat.
    $relatif = 'pkf3-' . bin2hex(random_bytes(8)) . '.pdf';
    $berkas = APP_ROOT . '/storage/private/v3/' . $relatif;
    if (file_put_contents($berkas, $isi) === false) {
        throw new RuntimeException('Lampiran sintetis tidak dapat ditulis.');
    }
    $sha = hash('sha256', $isi);
    $jalankan("INSERT INTO v3_lampiran (pelanggaran_id, sumber_version, nama_aman, mime, ukuran, sha256, lokasi_privat, created_by, updated_by)
        VALUES (1, 1, 'bukti-pkf3.pdf', 'application/pdf', " . strlen($isi) . ", '$sha', '" . $conn->real_escape_string($relatif) . "', 2, 2)");
    $lampiran = (int) $conn->insert_id;

    return [
        'admin' => $admin, 'k' => $k, 'g' => $g, 'n' => $user('sbx_pengurus_b'), 'murobi' => $user('sbx_murobi_a'),
        'tahun' => $tahun, 'penugasan_k' => $penugasanK, 'penugasan_g' => $penugasanG,
        'g_pengurus' => (int) $satu("SELECT id FROM pengurus WHERE guru_id = $gGuru"),
        'izin_wali' => $izinWali, 'izin_aktif' => $izinAktif, 'izin_nonaktif' => $izinNonaktif,
        'kasus_internal' => 1, 'kasus_rahasia' => 2, 'pelanggaran_internal' => 2, 'pelanggaran_rahasia' => 4,
        'pelanggaran_nonaktif' => 5, 'pelanggaran_lampiran' => 1, 'lampiran' => $lampiran, 'lampiran_berkas' => $berkas,
        'alasan_wali' => 'PKF3 alasan wali: menjenguk kakek di rumah sakit',
    ];
})();

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    echo json_encode($pkf3Ids, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
}

return $pkf3Ids;
