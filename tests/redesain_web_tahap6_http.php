<?php

declare(strict_types=1);

/**
 * Uji server redesain website Tahap 6 — data pesantren & penugasan
 * (D-1 s.d. D-10, M-1 s.d. M-3, G-1 · AB, AC, AD, AE, AF).
 *
 * Kriteria selesai Tahap 6 (cetak biru): migrasi kolom asrama kamar dan
 * tabel penanda "bukan ganda" teruji dengan rollback; profil binaan menolak
 * santri di luar cakupan. Skrip menjalankan server PHP lokal sendiri di atas
 * DB uji dari `tests/redesain_web_tahap6_db.sh` dan memeriksa:
 *
 *   H6-A  migrasi 023: naik, ulang (idempoten), batasan tabel, rollback
 *         (kolom/tabel hilang, data lain utuh), halaman tetap jalan tanpa
 *         migrasi, naik lagi — pada salinan DB tersendiri;
 *   H6-B  keputusan AB: profil santri binaan untuk pembimbing/murobi hanya
 *         dalam cakupan penugasan aktif; di luar cakupan, tidak ada, peran
 *         lain → 403; pencabutan penugasan menutup akses; kasus Rahasia tetap
 *         hanya untuk pemilik; data santri admin tetap khusus admin;
 *   H6-C  keputusan AE: asrama wajib untuk kamar baru; penempatan tidak
 *         sesuai asrama memunculkan peringatan dan hanya tersimpan dengan
 *         konfirmasi + alasan (jalur tinjau dan jalur cepat lama), tercatat
 *         di audit;
 *   H6-D  keputusan AD: "Bukan ganda" menyimpan tanda beralasan, pasangan
 *         hilang dari kandidat, tanda ganda ditolak, pembatalan memunculkan
 *         lagi; penggabungan pasangan berakun diblokir; CSRF wajib;
 *   H6-E  keputusan AC: impor diperiksa tanpa menyimpan (NIS terdaftar, NIS
 *         ganda dalam berkas, validasi), impor menyimpan hanya baris siap,
 *         jalur lama tetap; templat; GET → 405;
 *   H6-F  layar D/M/G untuk admin, non-admin ditolak, alamat lama dialihkan,
 *         tanpa isian ID, nama bukan nomor;
 *   H6-G  D-8/D-9 kelulusan 3 langkah dan tindakan alumni beralasan;
 *   H6-H  M-1/M-2/M-3/G-1: panel, chip, kartu kamar, semester aktif, menu
 *         jenis penugasan dan "modul mendatang" (AF), arsip/pulihkan;
 *   H6-I  tidak ada galat/peringatan PHP di log server.
 *
 *   bash tests/redesain_web_tahap6_db.sh
 *   php tests/redesain_web_tahap6_http.php
 *
 * Skrip mengubah data uji; bangun ulang DB sebelum mengulang bersama uji
 * browser.
 */

$root = dirname(__DIR__);
$db = getenv('TAHAP6_DB') ?: 'test_redesain_t6_test';
$dbMigrasi = 'test_redesain_t6_migrasi_test';
$port = (int) (getenv('TAHAP6_PORT') ?: 8985);
$portMigrasi = $port + 1;
if (!preg_match('/^test_.*_test$/', $db)) {
    fwrite(STDERR, "Ditolak: DB uji harus bernama test_*_test.\n");
    exit(2);
}
putenv('DB_NAME=' . $db);
require_once $root . '/app/bootstrap.php';

$lulus = 0;
$gagal = [];
$assert = static function (bool $ok, string $pesan) use (&$lulus, &$gagal): void {
    echo ($ok ? '[lulus] ' : '[gagal] ') . $pesan . PHP_EOL;
    $ok ? $lulus++ : $gagal[] = $pesan;
};

$logs = [];
$servers = [];
$mulaiServer = static function (string $dbName, int $p) use ($root, &$logs, &$servers): void {
    $log = tempnam(sys_get_temp_dir(), 'tahap6-server-');
    $logs[] = $log;
    $servers[] = proc_open(['php', '-S', '127.0.0.1:' . $p, $root . '/tests/redesain_web_tahap1_router.php'],
        [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, $root, array_merge(getenv(), ['DB_NAME' => $dbName]));
    for ($i = 0; $i < 50; $i++) {
        $sock = @fsockopen('127.0.0.1', $p);
        if ($sock !== false) {
            fclose($sock);
            return;
        }
        usleep(100000);
    }
};
register_shutdown_function(static function () use (&$servers): void {
    foreach ($servers as $s) {
        if (is_resource($s)) {
            proc_terminate($s);
        }
    }
});
$mulaiServer($db, $port);

final class Klien
{
    private string $jar;

    public function __construct(private string $base)
    {
        $this->jar = tempnam(sys_get_temp_dir(), 'tahap6-jar-');
    }

    /** @return array{status:int, body:string, location:?string, type:string} */
    public function req(string $path, ?array $post = null, array $headers = []): array
    {
        $h = curl_init($this->base . $path);
        curl_setopt_array($h, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar, CURLOPT_TIMEOUT => 60, CURLOPT_HTTPHEADER => $headers]);
        if ($post !== null) {
            curl_setopt($h, CURLOPT_POST, true);
            curl_setopt($h, CURLOPT_POSTFIELDS, http_build_query($post));
        }
        $raw = (string) curl_exec($h);
        $status = (int) curl_getinfo($h, CURLINFO_RESPONSE_CODE);
        $size = (int) curl_getinfo($h, CURLINFO_HEADER_SIZE);
        $type = (string) curl_getinfo($h, CURLINFO_CONTENT_TYPE);
        curl_close($h);
        preg_match('/^Location:\s*(.+)$/mi', substr($raw, 0, $size), $m);

        return ['status' => $status, 'body' => substr($raw, $size), 'location' => isset($m[1]) ? trim($m[1]) : null, 'type' => $type];
    }

    public function csrf(string $path = '/portal/index.php'): string
    {
        $r = $this->req($path);
        if (preg_match('/name="_csrf" value="([^"]+)"/', $r['body'], $m) !== 1) {
            throw new RuntimeException('Token CSRF tidak ditemukan pada ' . $path);
        }

        return $m[1];
    }

    public function login(string $user): void
    {
        $this->req('/admin/cek_login.php', ['_csrf' => $this->csrf(), 'username' => $user, 'password' => 'Sandbox#123']);
    }

    public function peran(string $peran): void
    {
        $this->req('/portal/peran.php', ['_csrf' => $this->csrf(), 'peran' => $peran]);
    }
}

$klien = static function (string $user, int $p) : Klien {
    $k = new Klien('http://127.0.0.1:' . $p);
    $k->login($user);

    return $k;
};
$teks = static fn (string $html): string => trim(html_entity_decode((string) preg_replace('/\s+/', ' ', (string) preg_replace('/<[^>]+>/', ' ', (string) preg_replace('#<(script|style)\b.*?</\1>#s', '', $html))), ENT_QUOTES));
$main = static fn (string $html): string => (string) substr($html, (int) strpos($html, '<main'));
$q = static function (string $query, string $dbName = '') use ($db): array {
    $conn = app_db();
    if ($dbName !== '') {
        $conn->select_db($dbName);
    }
    $r = $conn->query($query);
    if ($dbName !== '') {
        $conn->select_db($db);
    }
    if ($r === false) {
        throw new RuntimeException($conn->error . ' :: ' . $query);
    }

    return $r === true ? [] : $r->fetch_all(MYSQLI_ASSOC);
};
$satu = static fn (string $query, string $dbName = ''): mixed => array_values($q($query, $dbName)[0] ?? [null])[0];
$isianId = static fn (string $html): int => preg_match_all('/<input(?![^>]*type="(?:hidden|checkbox|radio)")[^>]*name="[a-z_]*_id(?:\[\])?"/i', $main($html));

$id = [
    'a1' => (int) $satu("SELECT id FROM santri WHERE nama_santri='SBX Santri A1'"),
    'a2' => (int) $satu("SELECT id FROM santri WHERE nama_santri='SBX Santri A2'"),
    'b1' => (int) $satu("SELECT id FROM santri WHERE nama_santri='SBX Santri B1'"),
    'c1' => (int) $satu("SELECT id FROM santri WHERE nama_santri='SBX Santri C1'"),
    'putri' => (int) $satu("SELECT id FROM santri WHERE nis='SBX-T6-PUTRI'"),
    'luar' => (int) $satu("SELECT id FROM santri WHERE nis='SBX-T6-LUAR'"),
    'kamarA' => (int) $satu("SELECT id FROM kamar WHERE nama_kamar='SBX Kamar A'"),
    'kamarP5' => (int) $satu("SELECT id FROM kamar WHERE nama_kamar LIKE 'P5 Kamar%' ORDER BY id LIMIT 1"),
    'alumni' => (int) $satu("SELECT id FROM alumni WHERE nis='SBX-T6-LULUS'"),
    'rahasia' => (int) $satu("SELECT id FROM v3_konseling_kasus WHERE kerahasiaan='Rahasia' ORDER BY id LIMIT 1"),
    'paA' => (int) $satu("SELECT pa.id FROM pembimbing_assignments pa JOIN users u ON u.pengurus_id = pa.pengurus_id WHERE u.username='sbx_pengurus_a' AND pa.kamar_id = (SELECT id FROM kamar WHERE nama_kamar='SBX Kamar A')"),
];
$assert(!in_array(0, $id, true), 'Fixture Tahap 6 tersedia: ' . json_encode($id));

$akun = ['sbx_admin', 'sbx_pengurus_a', 'sbx_pengurus_b', 'sbx_murobi_a', 'sbx_murobi_c', 'sbx_ortu_a', 'sbx_guru_biasa', 'sbx_pendidikan', 'sbx_pengurus_biasa'];
$k = [];
foreach ($akun as $u) {
    $k[$u] = $klien($u, $port);
}

// ===================================================================== H6-A
echo PHP_EOL . '=== H6-A. Migrasi 023 dan rollback ===' . PHP_EOL;
$conn = app_db();
$conn->query("DROP DATABASE IF EXISTS `$dbMigrasi`");
$conn->query("CREATE DATABASE `$dbMigrasi` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$baca = static fn (string $kunci): string => (string) (preg_match('/^' . $kunci . '=(.*)$/m', (string) file_get_contents($root . '/.env'), $m) ? trim($m[1], "\"'") : '');
$env = 'MYSQL_PWD=' . escapeshellarg($baca('DB_PASS') . $baca('DB_PASSWORD'));
$hubung = '-h' . escapeshellarg($baca('DB_HOST') ?: '127.0.0.1') . ' -P' . escapeshellarg($baca('DB_PORT') ?: '3306') . ' -u' . escapeshellarg($baca('DB_USER'));
exec($env . ' mysqldump ' . $hubung . ' --single-transaction --routines --triggers ' . escapeshellarg($db) . ' 2>/dev/null | ' . $env . ' mysql ' . $hubung . ' ' . escapeshellarg($dbMigrasi) . ' 2>&1', $out, $kode);
$assert($kode === 0, 'Salinan DB migrasi dibuat: ' . $dbMigrasi);
$migrator = static function (string $perintah) use ($root, $dbMigrasi): string {
    exec('DB_NAME=' . escapeshellarg($dbMigrasi) . ' php ' . escapeshellarg($root . '/bin/migrate.php') . ' ' . $perintah . ' 2>&1', $o, $c);
    return implode("\n", $o) . ' (exit ' . $c . ')';
};
$kolom = static fn (): int => (int) $satu("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='$dbMigrasi' AND TABLE_NAME='kamar' AND COLUMN_NAME='asrama'");
$tabel = static fn (): int => (int) $satu("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='$dbMigrasi' AND TABLE_NAME='wali_bukan_ganda'");
$hitung = static fn (): string => json_encode([
    (int) $satu('SELECT COUNT(*) FROM kamar', $dbMigrasi), (int) $satu('SELECT SUM(kapasitas) FROM kamar', $dbMigrasi),
    (int) $satu('SELECT COUNT(*) FROM wali', $dbMigrasi), (int) $satu('SELECT COUNT(*) FROM santri_wali', $dbMigrasi),
    (int) $satu('SELECT COUNT(*) FROM plotting_kamar', $dbMigrasi), (int) $satu('SELECT COUNT(*) FROM audit_logs', $dbMigrasi),
]);
$assert($kolom() === 1 && $tabel() === 1 && (int) $satu("SELECT COUNT(*) FROM schema_migrations WHERE migration LIKE '023%'", $dbMigrasi) === 1, 'Migrasi 023 tercatat: kolom kamar.asrama dan tabel wali_bukan_ganda ada');
$sqlMigrasi = (string) file_get_contents($root . '/database/migrations/023_redesain_web_tahap6_data_pesantren.sql');
$jalankan = static function (string $sql) use ($env, $hubung, $dbMigrasi): int {
    $berkas = tempnam(sys_get_temp_dir(), 'm023-');
    file_put_contents($berkas, $sql);
    exec($env . ' mysql ' . $hubung . ' ' . escapeshellarg($dbMigrasi) . ' < ' . escapeshellarg($berkas) . ' 2>&1', $o, $c);
    return $c;
};
$assert($jalankan($sqlMigrasi) === 0 && $jalankan($sqlMigrasi) === 0 && $kolom() === 1 && $tabel() === 1, 'SQL migrasi 023 dapat dijalankan ulang (idempoten)');
$sebelum = $hitung();
$wA = (int) $satu("SELECT id FROM wali WHERE nama='SBX Ganda HP Satu'", $dbMigrasi);
$wB = (int) $satu("SELECT id FROM wali WHERE nama='SBX Ganda HP Dua'", $dbMigrasi);
$coba = static function (string $sql) use ($conn, $dbMigrasi, $db): bool {
    $conn->select_db($dbMigrasi);
    $ok = $conn->query($sql) === true;
    $conn->select_db($db);
    return $ok;
};
$assert(!$coba("INSERT INTO wali_bukan_ganda (wali_a_id, wali_b_id, alasan) VALUES ($wB, $wA, 'urutan terbalik')"), 'Batasan: pasangan wajib terurut (wali_a_id < wali_b_id)');
$assert($coba("INSERT INTO wali_bukan_ganda (wali_a_id, wali_b_id, alasan) VALUES ($wA, $wB, 'tanda pertama')")
    && !$coba("INSERT INTO wali_bukan_ganda (wali_a_id, wali_b_id, alasan) VALUES ($wA, $wB, 'tanda kedua')"), 'Batasan: paling banyak satu tanda aktif per pasangan');
$assert($coba("UPDATE wali_bukan_ganda SET dibatalkan_pada = NOW(), alasan_batal = 'uji' WHERE wali_a_id = $wA")
    && $coba("INSERT INTO wali_bukan_ganda (wali_a_id, wali_b_id, alasan) VALUES ($wA, $wB, 'tanda baru')"), 'Tanda dibatalkan tetap tersimpan; tanda baru boleh dibuat');
$assert(!$coba("INSERT INTO wali_bukan_ganda (wali_a_id, wali_b_id, alasan) VALUES ($wA, 999999999, 'fk')"), 'Batasan: kunci asing ke wali');
$assert(!$coba("UPDATE kamar SET asrama = 'Campur' WHERE id = 1"), 'Asrama hanya Putra/Putri');
$asramaTerisi = (int) $satu('SELECT COUNT(*) FROM kamar WHERE asrama IS NOT NULL', $dbMigrasi);
$keluaran = $migrator('rollback');
$assert(str_contains($keluaran, 'Rollback selesai: 023') && $kolom() === 0 && $tabel() === 0, 'Rollback 023: kolom asrama dan tabel bukan ganda dilepas — ' . $keluaran);
$assert($hitung() === $sebelum, 'Rollback tidak menyentuh baris kamar, wali, relasi, penempatan, audit (' . $sebelum . ')');
$assert((int) $satu("SELECT COUNT(*) FROM schema_migrations WHERE migration LIKE '023%'", $dbMigrasi) === 0, 'Rollback menghapus catatan migrasi 023 sehingga dapat dinaikkan lagi');
$mulaiServer($dbMigrasi, $portMigrasi);
$km = $klien('sbx_admin', $portMigrasi);
$halamanTanpa = ['/admin/admin_kamar.php', '/admin/admin_kamar.php?action=create', '/admin/admin_penempatan_santri.php', '/admin/admin_wali_rekonsiliasi.php', '/admin/admin_wali_rekonsiliasi.php?bagian=bukan_ganda', '/admin/admin_wali.php', '/admin/admin_master_santri.php'];
$semua = true;
foreach ($halamanTanpa as $u) {
    $r = $km->req($u);
    $semua = $semua && $r['status'] === 200;
}
$assert($semua, 'Tanpa migrasi (sesudah rollback) halaman kamar, penempatan, wali, rekonsiliasi tetap 200');
$h = $main($km->req('/admin/admin_wali_rekonsiliasi.php')['body']);
$assert(str_contains($h, 'migrasi basis data Tahap 6 belum dijalankan') && !str_contains($h, 'name="action" value="bukan_ganda"'), 'Tanpa migrasi: tombol Bukan ganda tidak ada, ada keterangan');
$h = $main($km->req('/admin/admin_kamar.php?action=create')['body']);
$assert(!str_contains($h, 'name="asrama"') && str_contains($h, 'Label asrama putra/putri belum tersedia'), 'Tanpa migrasi: formulir kamar tanpa asrama, ada keterangan');
$r = $km->req('/admin/admin_kamar.php', ['_csrf' => $km->csrf('/admin/admin_kamar.php'), 'action' => 'save', 'nama_kamar' => 'SBX Kamar Tanpa Migrasi', 'kapasitas' => '5']);
$assert($r['status'] === 302 && (int) $satu("SELECT COUNT(*) FROM kamar WHERE nama_kamar='SBX Kamar Tanpa Migrasi'", $dbMigrasi) === 1, 'Tanpa migrasi: kamar tetap dapat disimpan tanpa asrama');
$keluaran = $migrator('up');
$assert(str_contains($keluaran, '023_redesain_web_tahap6_data_pesantren.sql') && $kolom() === 1 && $tabel() === 1, 'Migrasi naik lagi sesudah rollback');
$assert((int) $satu('SELECT COUNT(*) FROM kamar WHERE asrama IS NOT NULL', $dbMigrasi) === 0 && $asramaTerisi > 0, 'Sesudah rollback+naik, asrama kosong lagi (kehilangan data yang didokumentasikan rollback)');
$r = $km->req('/admin/admin_kamar.php', ['_csrf' => $km->csrf('/admin/admin_kamar.php'), 'action' => 'save', 'nama_kamar' => 'SBX Kamar Sesudah Naik', 'kapasitas' => '5']);
$assert($r['status'] === 422 && (int) $satu("SELECT COUNT(*) FROM kamar WHERE nama_kamar='SBX Kamar Sesudah Naik'", $dbMigrasi) === 0, 'Sesudah naik lagi: kamar baru tanpa asrama ditolak (pemeriksaan skema per permintaan)');

// ===================================================================== H6-B
echo PHP_EOL . '=== H6-B. Profil santri binaan (keputusan AB) ===' . PHP_EOL;
$profil = static fn (int $santri, string $tab = ''): string => '/portal/santri_binaan.php?id=' . $santri . ($tab === '' ? '' : '&tab=' . $tab);
$matriks = [
    // [akun, santri, status]
    ['sbx_pengurus_a', $id['a1'], 200], ['sbx_pengurus_a', $id['c1'], 200], ['sbx_pengurus_a', $id['b1'], 403], ['sbx_pengurus_a', $id['luar'], 403], ['sbx_pengurus_a', 99999999, 403],
    ['sbx_pengurus_b', $id['a2'], 200], ['sbx_pengurus_b', $id['b1'], 200], ['sbx_pengurus_b', $id['c1'], 403],
    ['sbx_murobi_a', $id['a1'], 200], ['sbx_murobi_a', $id['c1'], 403],
    ['sbx_murobi_c', $id['c1'], 200], ['sbx_murobi_c', $id['b1'], 403],
    ['sbx_ortu_a', $id['a1'], 403], ['sbx_guru_biasa', $id['a1'], 403], ['sbx_pengurus_biasa', $id['a1'], 403],
];
foreach ($matriks as [$u, $sid, $harap]) {
    foreach (['', 'izin', 'sikap', 'pembinaan'] as $tab) {
        $r = $k[$u]->req($profil($sid, $tab));
        if ($r['status'] !== $harap) {
            $assert(false, "$u santri $sid tab " . ($tab ?: 'ringkasan') . " = {$r['status']}, harap $harap");
            continue 2;
        }
    }
    $assert(true, "$u → santri $sid: $harap di keempat tab");
}
$r403a = $k['sbx_pengurus_a']->req($profil($id['b1']));
$r403b = $k['sbx_pengurus_a']->req($profil(99999999));
$assert($teks($main($r403a['body'])) === $teks($main($r403b['body'])) && !str_contains($r403a['body'], 'SBX Santri B1'), 'Di luar cakupan dan tidak ada dijawab sama, tanpa nama santri');
$r = $k['sbx_admin']->req($profil($id['a1']));
$assert($r['status'] === 302 && str_contains((string) $r['location'], 'admin_master_santri.php?action=detail&id=' . $id['a1']), 'Admin dialihkan ke profil 360° (D-2)');
$r = $k['sbx_pendidikan']->req($profil($id['a1']));
$assert($r['status'] === 403, 'Peran Bagian Pendidikan ditolak (403)');
$h = $main($k['sbx_pengurus_a']->req($profil($id['a1']))['body']);
$t = $teks($h);
$assert(str_contains($t, 'SBX Santri A1') && str_contains($t, 'SBX Kelas 1') && str_contains($t, 'SBX Kamar A') && str_contains($t, 'Wali utama') && str_contains($t, 'Izin semester ini')
    && str_contains($h, 'izin_buat.php?santri_id=' . $id['a1']) && !str_contains($t, 'Nilai & rapor') && !str_contains($h, 'method="post"'), 'Ringkasan hanya-baca: kelas, kamar, wali utama, izin semester; Ajukan izin; tanpa tab Nilai & rapor, tanpa formulir ubah');
$h = $main($k['sbx_murobi_a']->req($profil($id['a1']))['body']);
$assert(!str_contains($h, 'izin_buat.php'), 'Murobi tidak mendapat tombol Ajukan izin');
$izinDb = (int) $satu("SELECT COUNT(*) FROM izin_pengajuan WHERE santri_id = {$id['a1']} AND tahun_ajaran_id = (SELECT id FROM tahun_ajaran WHERE status='Aktif' AND archived_at IS NULL) AND status <> 'Dibatalkan'");
$assert(str_contains($teks($main($k['sbx_pengurus_a']->req($profil($id['a1'], 'izin'))['body'])), 'Izin semester ini ' . $izinDb), 'Tab Izin: jumlah izin semester = basis data (' . $izinDb . ')');
$hA = $main($k['sbx_pengurus_a']->req($profil($id['a2'], 'pembinaan'))['body']);
$hB = $main($k['sbx_pengurus_b']->req($profil($id['a2'], 'pembinaan'))['body']);
$hM = $main($k['sbx_murobi_a']->req($profil($id['a2'], 'pembinaan'))['body']);
$assert(str_contains($hA, 'v3_konseling_detail.php?id=' . $id['rahasia']) && !str_contains($hB, 'v3_konseling_detail.php?id=' . $id['rahasia']) && !str_contains($hM, 'v3_konseling_detail.php?id=' . $id['rahasia']),
    'Tab Pembinaan: kasus Rahasia hanya untuk pembimbing pemiliknya (bukan pembimbing lain/murobi)');
foreach (['/admin/admin_master_santri.php?action=detail&id=' . $id['a1'], '/admin/admin_wali.php', '/admin/admin_penempatan_santri.php'] as $u) {
    $assert($k['sbx_pengurus_a']->req($u)['status'] === 403 && $k['sbx_murobi_c']->req($u)['status'] === 403, 'Data santri admin tetap khusus admin: ' . $u);
}
$conn->query('UPDATE pembimbing_assignments SET is_active = 0 WHERE id = ' . $id['paA']);
$r = $k['sbx_pengurus_a']->req($profil($id['a1']));
$conn->query('UPDATE pembimbing_assignments SET is_active = 1 WHERE id = ' . $id['paA']);
$assert($r['status'] === 403, 'Penugasan pembimbing dinonaktifkan → sesi yang sama langsung 403');
$conn->query("UPDATE pembimbing_assignments SET tanggal_selesai = CURDATE() - INTERVAL 1 DAY, tanggal_mulai = CURDATE() - INTERVAL 30 DAY WHERE id = " . $id['paA']);
$r = $k['sbx_pengurus_a']->req($profil($id['a1']));
$conn->query('UPDATE pembimbing_assignments SET tanggal_selesai = NULL WHERE id = ' . $id['paA']);
$assert($r['status'] === 403, 'Penugasan pembimbing berakhir kemarin → 403');
$conn->query("UPDATE santri SET is_active = 0 WHERE id = " . $id['a1']);
$r = $k['sbx_pengurus_a']->req($profil($id['a1']));
$conn->query("UPDATE santri SET is_active = 1 WHERE id = " . $id['a1']);
$assert($r['status'] === 403, 'Santri dinonaktifkan → keluar dari cakupan (403)');
$r = $k['sbx_pengurus_a']->req($profil($id['a1']));
$assert($r['status'] === 200, 'Dipulihkan → 200 lagi');
$h = $main($k['sbx_pengurus_a']->req('/portal/santri_binaan.php')['body']);
$assert(str_contains($h, 'SBX Santri A1') && str_contains($h, 'SBX Santri C1') && !str_contains($h, 'SBX Santri B1') && !str_contains($h, 'SBX T6 Luar'), 'Daftar santri binaan hanya cakupan sendiri');
$h = $main($k['sbx_pengurus_a']->req('/portal/index.php')['body']);
$assert(str_contains($h, 'santri_binaan.php?id=' . $id['a1']) && str_contains($h, 'santri_binaan.php"'), 'Beranda pengurus menautkan santri binaan ke profil (D-10)');

// ===================================================================== H6-C
echo PHP_EOL . '=== H6-C. Asrama kamar dan penempatan (keputusan AE) ===' . PHP_EOL;
$adm = $k['sbx_admin'];
$tok = static fn (string $path = '/admin/admin_kamar.php'): string => $adm->csrf($path);
$r = $adm->req('/admin/admin_kamar.php', ['_csrf' => $tok(), 'action' => 'save', 'nama_kamar' => 'SBX T6 Kamar Baru', 'kapasitas' => '4']);
$assert($r['status'] === 422 && str_contains($r['body'], 'Pilih asrama kamar') && (int) $satu("SELECT COUNT(*) FROM kamar WHERE nama_kamar='SBX T6 Kamar Baru'") === 0, 'Kamar baru tanpa asrama ditolak 422');
$r = $adm->req('/admin/admin_kamar.php', ['_csrf' => $tok(), 'action' => 'save', 'nama_kamar' => 'SBX T6 Kamar Baru', 'kapasitas' => '4', 'asrama' => 'Campur']);
$assert($r['status'] === 422, 'Nilai asrama di luar Putra/Putri ditolak');
$r = $adm->req('/admin/admin_kamar.php', ['_csrf' => $tok(), 'action' => 'save', 'nama_kamar' => 'SBX T6 Kamar Baru', 'kapasitas' => '4', 'asrama' => 'Putra']);
$kamarBaru = (int) $satu("SELECT id FROM kamar WHERE nama_kamar='SBX T6 Kamar Baru'");
$assert($r['status'] === 302 && $satu("SELECT asrama FROM kamar WHERE id=$kamarBaru") === 'Putra', 'Kamar baru dengan asrama Putra tersimpan');
$r = $adm->req('/admin/admin_kamar.php?action=edit&id=' . $kamarBaru, ['_csrf' => $tok(), 'action' => 'save', 'id' => $kamarBaru, 'nama_kamar' => 'SBX T6 Kamar Baru', 'kapasitas' => '5']);
$assert($r['status'] === 302 && $satu("SELECT asrama FROM kamar WHERE id=$kamarBaru") === 'Putra' && (int) $satu("SELECT kapasitas FROM kamar WHERE id=$kamarBaru") === 5, 'Ubah tanpa isian asrama (pemanggil lama) tidak mengosongkan asrama');
$audit = $q("SELECT after_json FROM audit_logs WHERE entity_type='kamar' AND entity_id=$kamarBaru ORDER BY id DESC LIMIT 1")[0]['after_json'] ?? '';
$assert(str_contains((string) $audit, '"asrama":"Putra"'), 'Audit kamar memuat asrama');
$h = $main($adm->req('/admin/admin_kamar.php?asrama=belum')['body']);
$belum = (int) $satu('SELECT COUNT(*) FROM kamar WHERE asrama IS NULL');
$assert(preg_match('/Belum ditentukan<\/span> <span class="ah-chips__count">' . $belum . '</', $h) === 1 && !str_contains($h, '>SBX Kamar A<'), 'Chip "Belum ditentukan" = basis data (' . $belum . ') dan menyaring');
$h = $main($adm->req('/admin/admin_kamar.php?action=detail&id=' . (int) $satu("SELECT id FROM kamar WHERE nama_kamar='SBX Kamar C'"))['body']);
$assert(str_contains($h, 'Asrama putri') && str_contains($h, 'tidak sesuai asrama') && str_contains($h, 'Tempatkan santri'), 'Panel penghuni: label asrama, penghuni tidak sesuai, Tempatkan santri');

$pen = '/admin/admin_penempatan_santri.php';
$r = $adm->req($pen, ['_csrf' => $tok($pen), 'action' => 'tempatkan_kamar', 'tahap' => 'tinjau', 'santri_ids' => [$id['putri']], 'kamar_id' => $id['kamarA']]);
$t = $teks($main($r['body']));
$assert($r['status'] === 200 && str_contains($t, 'SBX T6 Putri (P) akan ditempatkan di SBX Kamar A (asrama putra)') && str_contains($r['body'], 'name="konfirmasi_asrama"') && str_contains($r['body'], 'name="alasan_asrama"'), 'Tinjau: peringatan per santri + konfirmasi dan alasan');
preg_match('/name="form_token" value="([^"]+)"/', $r['body'], $m);
$token = $m[1] ?? '';
$r = $adm->req($pen, ['_csrf' => $tok($pen), 'action' => 'tempatkan_kamar', 'tahap' => 'terapkan', 'form_token' => $token, 'santri_ids' => [$id['putri']], 'kamar_id' => $id['kamarA']]);
$assert($r['status'] === 422 && (int) $satu("SELECT COUNT(*) FROM plotting_kamar WHERE id_santri={$id['putri']}") === 0 && str_contains($r['body'], 'name="konfirmasi_asrama"'), 'Terapkan tanpa konfirmasi: 422, tidak tersimpan, tinjauan tampil lagi');
preg_match('/name="form_token" value="([^"]+)"/', $r['body'], $m);
$r = $adm->req($pen, ['_csrf' => $tok($pen), 'action' => 'tempatkan_kamar', 'tahap' => 'terapkan', 'form_token' => $m[1] ?? '', 'santri_ids' => [$id['putri']], 'kamar_id' => $id['kamarA'], 'konfirmasi_asrama' => '1', 'alasan_asrama' => 'abc']);
$assert($r['status'] === 422 && (int) $satu("SELECT COUNT(*) FROM plotting_kamar WHERE id_santri={$id['putri']}") === 0, 'Alasan kurang dari 5 karakter ditolak');
$r = $adm->req($pen, ['_csrf' => $tok($pen), 'action' => 'tempatkan_kamar', 'tahap' => 'langsung', 'santri_ids' => [$id['putri']], 'kamar_id' => $id['kamarA']]);
$assert($r['status'] === 422 && (int) $satu("SELECT COUNT(*) FROM plotting_kamar WHERE id_santri={$id['putri']}") === 0, 'Jalur cepat lama (tahap=langsung) juga ditolak tanpa konfirmasi');
$assert(str_contains($r['body'], 'name="konfirmasi_asrama"'), 'Jalur cepat yang ditolak menampilkan tinjauan dengan konfirmasi');
preg_match('/name="form_token" value="([^"]+)"/', $r['body'], $m);
$r = $adm->req($pen, ['_csrf' => $tok($pen), 'action' => 'tempatkan_kamar', 'tahap' => 'terapkan', 'form_token' => $m[1] ?? '', 'santri_ids' => [$id['putri']], 'kamar_id' => $id['kamarA'], 'konfirmasi_asrama' => '1', 'alasan_asrama' => 'Sementara selama renovasi asrama putri']);
$assert($r['status'] === 302 && (int) $satu("SELECT id_kamar FROM plotting_kamar WHERE id_santri={$id['putri']}") === $id['kamarA'], 'Dengan konfirmasi + alasan: tersimpan');
$audit = (string) ($q("SELECT after_json FROM audit_logs WHERE action='penempatan.kamar.tetapkan' ORDER BY id DESC LIMIT 1")[0]['after_json'] ?? '');
$assert(str_contains($audit, '"asrama_tidak_sesuai":true') && str_contains($audit, 'Sementara selama renovasi asrama putri') && str_contains($audit, '"asrama_kamar":"Putra"'), 'Audit penempatan memuat tanda tidak sesuai, asrama, dan alasan');
$r = $adm->req($pen, ['_csrf' => $tok($pen), 'action' => 'tempatkan_kamar', 'tahap' => 'tinjau', 'santri_ids' => [$id['luar']], 'kamar_id' => (int) $satu("SELECT id FROM kamar WHERE nama_kamar LIKE 'P5 Kamar%' ORDER BY id LIMIT 1 OFFSET 1")]);
$assert($r['status'] === 200 && !str_contains($r['body'], 'name="konfirmasi_asrama"'), 'Kamar dengan asrama belum ditentukan: tanpa peringatan');
$r = $adm->req($pen, ['_csrf' => $tok($pen), 'action' => 'tempatkan_kamar', 'tahap' => 'tujuan', 'santri_ids' => [$id['a1'], $id['a2']]]);
$assert($r['status'] === 200 && str_contains($teks($main($r['body'])), '2 santri dipilih: SBX Santri A1, SBX Santri A2') && str_contains($r['body'], 'name="kamar_id"'), 'Bilah pilihan → langkah "pilih tujuan" dengan nama santri');
$r = $adm->req($pen, ['_csrf' => $tok($pen), 'action' => 'keluarkan_kamar', 'tahap' => 'tujuan', 'santri_ids' => [$id['putri']]]);
$assert($r['status'] === 200 && str_contains($r['body'], 'name="alasan"') && str_contains($teks($main($r['body'])), 'mengeluarkan dari kamar'), 'Keluarkan dari bilah pilihan langsung ke tinjauan beralasan');
$r = $adm->req($pen . '?action=tempatkan_kamar');
$assert($r['status'] === 405, 'GET bermutasi ke penempatan tetap 405');
$h = $main($adm->req($pen . '?ubah=' . $id['a1'])['body']);
$assert(str_contains($h, 'id="ah-panel"') && str_contains($h, 'SBX Santri A1') && str_contains($h, 'name="kamar_id"') && str_contains($h, '· Putra'), 'Panel "Ubah penempatan": pilihan kamar berlabel asrama');
$r = $adm->req($pen, ['action' => 'tempatkan_kamar', 'tahap' => 'tinjau', 'santri_ids' => [$id['a1']], 'kamar_id' => $id['kamarA']]);
$assert(in_array($r['status'], [400, 403, 419], true), 'POST penempatan tanpa CSRF ditolak (' . $r['status'] . ')');

// ===================================================================== H6-D
echo PHP_EOL . '=== H6-D. Rekonsiliasi dan "Bukan ganda" (keputusan AD) ===' . PHP_EOL;
$rek = '/admin/admin_wali_rekonsiliasi.php';
$pasangan = static fn (): int => master_data_service()->reconciliationSummary()['duplikat'];
$awal = $pasangan();
$h = $main($adm->req($rek)['body']);
$assert($awal >= 3 && str_contains($h, 'Kemungkinan ganda · 1 dari ' . $awal) && preg_match('/Kemungkinan ganda<\/span> <span class="ah-chips__count">' . $awal . '</', $h) === 1, 'Satu pasangan per layar; chip = jumlah pasangan (' . $awal . ')');
$hpA = (int) $satu("SELECT id FROM wali WHERE nama='SBX Ganda HP Satu'");
$hpB = (int) $satu("SELECT id FROM wali WHERE nama='SBX Ganda HP Dua'");
$r = $adm->req($rek, ['_csrf' => $adm->csrf($rek), 'action' => 'bukan_ganda', 'wali_a' => $hpB, 'wali_b' => $hpA, 'alasan' => 'abc']);
$assert((int) $satu('SELECT COUNT(*) FROM wali_bukan_ganda') === 0, 'Alasan pendek ditolak, tidak ada tanda');
$r = $adm->req($rek, ['action' => 'bukan_ganda', 'wali_a' => $hpB, 'wali_b' => $hpA, 'alasan' => 'Orang berbeda, alamat berbeda']);
$assert(in_array($r['status'], [400, 403, 419], true) && (int) $satu('SELECT COUNT(*) FROM wali_bukan_ganda') === 0, 'Tanpa CSRF ditolak');
$r = $adm->req($rek, ['_csrf' => $adm->csrf($rek), 'action' => 'bukan_ganda', 'wali_a' => $hpB, 'wali_b' => $hpA, 'alasan' => 'Orang berbeda, alamat berbeda']);
$tanda = $q('SELECT * FROM wali_bukan_ganda WHERE dibatalkan_pada IS NULL');
$assert(count($tanda) === 1 && (int) $tanda[0]['wali_a_id'] === min($hpA, $hpB) && (int) $tanda[0]['wali_b_id'] === max($hpA, $hpB) && (int) $tanda[0]['ditandai_oleh'] === (int) $satu("SELECT id FROM users WHERE username='sbx_admin'") && $tanda[0]['alasan'] === 'Orang berbeda, alamat berbeda',
    'Tanda tersimpan terurut, dengan alasan dan pelaku (urutan masukan terbalik pun diterima)');
$assert($pasangan() === $awal - 1, 'Pasangan hilang dari kemungkinan ganda (' . $awal . ' → ' . $pasangan() . ')');
$assert((int) $satu("SELECT COUNT(*) FROM audit_logs WHERE action='master.wali.bukan_ganda'") === 1, 'Audit master.wali.bukan_ganda tercatat');
$adm->req($rek, ['_csrf' => $adm->csrf($rek), 'action' => 'bukan_ganda', 'wali_a' => $hpA, 'wali_b' => $hpB, 'alasan' => 'Tanda kedua untuk pasangan sama']);
$assert((int) $satu('SELECT COUNT(*) FROM wali_bukan_ganda') === 1, 'Tanda kedua untuk pasangan aktif ditolak');
$lain = (int) $satu("SELECT id FROM wali WHERE nama='SBX Wali A' LIMIT 1");
$adm->req($rek, ['_csrf' => $adm->csrf($rek), 'action' => 'bukan_ganda', 'wali_a' => $hpA, 'wali_b' => $lain ?: 1, 'alasan' => 'Bukan kandidat sama sekali']);
$assert((int) $satu('SELECT COUNT(*) FROM wali_bukan_ganda') === 1, 'Pasangan yang bukan kandidat (nama/HP berbeda) tidak dapat ditandai');
$h = $main($adm->req($rek . '?bagian=bukan_ganda')['body']);
$assert(str_contains($h, 'SBX Ganda HP Satu') && str_contains($h, 'Orang berbeda, alamat berbeda') && str_contains($h, 'SBX Administrator') && str_contains($h, 'name="action" value="batal_bukan_ganda"'), 'Chip "Ditandai bukan ganda": pasangan, alasan, pelaku, Batalkan tanda');
$adm->req($rek, ['_csrf' => $adm->csrf($rek), 'action' => 'batal_bukan_ganda', 'tanda_id' => (int) $tanda[0]['id'], 'alasan' => 'Ternyata perlu diperiksa ulang']);
$assert((int) $satu('SELECT COUNT(*) FROM wali_bukan_ganda WHERE dibatalkan_pada IS NOT NULL AND alasan_batal IS NOT NULL') === 1 && $pasangan() === $awal, 'Pembatalan menyimpan jejak (baris tidak dihapus) dan pasangan muncul lagi');
$h = '';
for ($p = 1; $p <= $pasangan(); $p++) {
    $h = $main($adm->req($rek . '?page=' . $p)['body']);
    if (str_contains($h, 'SBX Ganda Akun')) { break; }
}
$assert(str_contains($h, 'Penggabungan diblokir: kedua identitas memiliki akun login') && !str_contains($h, 'name="action" value="merge"'), 'Pasangan yang keduanya berakun: tanpa formulir gabung');
// Kolasi MySQL mengabaikan huruf besar dan spasi akhir: ambil menurut urutan ID.
$nmA = (int) $satu("SELECT id FROM wali WHERE nama='SBX Ganda Nama' ORDER BY id LIMIT 1");
$nmB = (int) $satu("SELECT id FROM wali WHERE nama='SBX Ganda Nama' ORDER BY id LIMIT 1 OFFSET 1");
$h = '';
for ($p = 1; $p <= $pasangan(); $p++) {
    $h = $adm->req($rek . '?page=' . $p)['body'];
    if (str_contains($h, 'value="' . $nmA . ':' . $nmB . '"')) { break; }
}
preg_match('/name="form_token" value="([^"]+)"/', $h, $m);
$adm->req($rek, ['_csrf' => $adm->csrf($rek), 'action' => 'merge', 'form_token' => $m[1] ?? '', 'pilihan' => $nmA . ':' . $nmB]);
$assert((int) $satu("SELECT COUNT(*) FROM wali WHERE id=$nmB AND merged_into_wali_id IS NULL") === 1, 'Gabung tanpa centang konfirmasi ditolak');
$h = $adm->req($rek . '?page=' . $p)['body'];
preg_match('/name="form_token" value="([^"]+)"/', $h, $m);
$r = $adm->req($rek, ['_csrf' => $adm->csrf($rek), 'action' => 'merge', 'form_token' => $m[1] ?? '', 'pilihan' => $nmA . ':' . $nmB, 'konfirmasi' => '1']);
preg_match('/<div class="ah-note[^"]*"[^>]*>.*?<\/div>/s', $adm->req($rek . '?bagian=tanpa_relasi')['body'], $catatan);
$assert((int) $satu("SELECT merged_into_wali_id FROM wali WHERE id=$nmB") === $nmA && (int) $satu("SELECT COUNT(*) FROM santri_wali WHERE wali_id=$nmA AND archived_at IS NULL") === 2, 'Gabungkan (pertahankan A): B diarsipkan dengan penanda, relasi pindah ke A' . ($satu("SELECT merged_into_wali_id FROM wali WHERE id=$nmB") === null ? ' [' . $teks($catatan[0] ?? '') . ' token=' . ($m[1] ?? '-') . ' halaman=' . $p . ']' : ''));
$h = $main($adm->req('/admin/admin_wali.php')['body']);
$total = master_data_service()->reconciliationSummary()['total'];
$assert(str_contains($h, 'Rekonsiliasi wali <span class="ah-chips__count">' . number_format($total, 0, ',', '.') . '</span>'), 'Tombol Rekonsiliasi wali di D-6 menyebut jumlah yang perlu ditinjau (' . $total . ')');
$h = $main($adm->req('/admin/admin_wali.php?action=detail&id=' . $nmA)['body']);
$assert(str_contains($h, 'id="ah-panel"') && str_contains($h, 'SBX T6 Putri') && str_contains($h, '+ Hubungkan santri') && str_contains($h, 'admin_akun.php?wali_id=' . $nmA), 'Panel wali: santri terhubung, hubungkan santri, Buat akun');

// ===================================================================== H6-E
echo PHP_EOL . '=== H6-E. Impor dengan pemeriksaan (keputusan AC) ===' . PHP_EOL;
$imp = '/admin/proses_import_santri.php';
$baris = [
    ['NIS', 'Nama', 'L/P'],
    ['SBX-T6-I1', 'SBX Impor Satu', 'L', 'Ciamis', '2014-01-01', 'Alamat', 'Desa', 'Kec', 'Kab', 'Prov', 'Ayah', '081200000001', 'Ibu', '', 'SD', 'SMP'],
    ['SBX-T6-I2', 'SBX Impor Dua', 'P', 'Ciamis', '2014-01-02', '', '', '', '', '', '', '', '', '', '', ''],
    ['SBX-S-001', 'Sudah Ada', 'L', '', '2014-01-01'],
    ['SBX-T6-I1', 'Ganda Dalam Berkas', 'L', '', '2014-01-01'],
    ['SBX-T6-I3', 'Tanggal Salah', 'X', '', '2014-13-40'],
    ['', '', ''],
];
$jumlahSantri = (int) $satu('SELECT COUNT(*) FROM santri');
$r = $adm->req($imp, ['_csrf' => $adm->csrf(), 'mode' => 'periksa', 'payload' => json_encode($baris)], ['Accept: application/json']);
$j = json_decode($r['body'], true);
$masalah = array_column(array_filter($j['hasil'] ?? [], static fn (array $b): bool => !$b['siap']), 'masalah', 'baris');
$assert($r['status'] === 200 && ($j['ok'] ?? false) && $j['baris'] === 5 && $j['siap'] === 2 && $j['bermasalah'] === 3, 'Periksa: 5 baris, 2 siap, 3 bermasalah (baris judul dan kosong dilewati)');
$assert(str_contains(implode(' ', $masalah[4] ?? []), 'sudah terdaftar') && str_contains(implode(' ', $masalah[5] ?? []), 'ganda di dalam berkas') && str_contains(implode(' ', $masalah[6] ?? []), 'Jenis kelamin') && str_contains(implode(' ', $masalah[6] ?? []), 'Tanggal lahir'),
    'Masalah per baris: NIS terdaftar, NIS ganda dalam berkas, aturan formulir santri');
$assert((int) $satu('SELECT COUNT(*) FROM santri') === $jumlahSantri && !array_key_exists('input', $j['hasil'][0] ?? []), 'Periksa TIDAK menyimpan apa pun; jawaban tanpa isi mentah');
$assert($conn->query("INSERT INTO santri (nis, nama_santri, jenis_kelamin, tempat_lahir, tgl_lahir, alamat, desa, kecamatan, kab_kota, provinsi, nama_ayah, nama_ibu, asal_sekolah, sekolah_saat_ini, is_active)
    VALUES ('SBX-T6-I2', 'Disela Orang Lain', 'L', 'Kota', '2014-01-01', 'Alamat', 'Desa', 'Kec', 'Kab', 'Prov', 'Ayah', 'Ibu', 'SD', 'SMP', 1)") === true, 'Santri lain memakai NIS baris 3 sesudah pemeriksaan (simulasi perubahan data)');
$r = $adm->req($imp, ['_csrf' => $adm->csrf(), 'mode' => 'impor', 'payload' => json_encode($baris)], ['Accept: application/json']);
$j = json_decode($r['body'], true);
$assert($r['status'] === 200 && $j['disimpan'] === 1 && $j['bermasalah'] === 4 && (int) $satu("SELECT COUNT(*) FROM santri WHERE nis='SBX-T6-I1'") === 1 && (int) $satu("SELECT COUNT(*) FROM santri WHERE nama_santri='SBX Impor Dua'") === 0,
    'Impor memeriksa ulang: NIS yang terisi sejak pemeriksaan ditolak, hanya baris siap tersimpan');
$r = $adm->req($imp, ['_csrf' => $adm->csrf(), 'mode' => 'periksa', 'payload' => 'bukan json'], ['Accept: application/json']);
$assert($r['status'] === 422, 'Isi bukan daftar baris ditolak 422');
$r = $adm->req($imp, ['_csrf' => $adm->csrf(), 'payload' => json_encode([['SBX-T6-L1', 'SBX Impor Lama', 'L', '', '2014-01-01']])]);
$assert($r['status'] === 200 && str_starts_with($r['body'], 'IMPORT SELESAI') && str_contains($r['body'], 'Berhasil: 1 santri'), 'Jalur lama (tanpa mode) tetap: teks IMPORT SELESAI');
$assert($adm->req($imp)['status'] === 405, 'GET tanpa templat → 405');
$r = $adm->req($imp . '?templat=1');
$assert($r['status'] === 200 && str_contains($r['type'], 'spreadsheetml') && str_starts_with($r['body'], 'PK'), 'Templat Excel dapat diunduh');
$assert($k['sbx_pengurus_a']->req($imp . '?templat=1')['status'] === 403, 'Templat/impor khusus admin');
$r = $adm->req($imp, ['mode' => 'periksa', 'payload' => json_encode($baris)]);
$assert(in_array($r['status'], [400, 403, 419], true), 'Impor tanpa CSRF ditolak');

// ===================================================================== H6-F
echo PHP_EOL . '=== H6-F. Layar, akses, alamat lama ===' . PHP_EOL;
$layar = [
    '/admin/admin_master_santri.php', '/admin/admin_master_santri.php?state=archived', '/admin/admin_master_santri.php?action=create', '/admin/admin_master_santri.php?action=edit&id=' . $id['a1'],
    '/admin/admin_master_santri.php?action=impor',
    '/admin/admin_penempatan_santri.php', '/admin/admin_wali.php', '/admin/admin_wali.php?action=create', '/admin/admin_wali_rekonsiliasi.php?bagian=relasi_belum_lengkap',
    '/admin/admin_kelulusan_santri.php', '/admin/admin_alumni.php', '/admin/admin_guru.php', '/admin/admin_pengurus.php', '/admin/admin_kelas.php', '/admin/admin_kamar.php',
    '/admin/admin_tahun.php', '/admin/admin_penugasan.php', '/admin/admin_penugasan.php?jenis=panitia_psb',
];
foreach (['biodata', 'wali', 'tempat', 'izin', 'pembinaan', 'rapor'] as $tab) {
    $layar[] = '/admin/admin_master_santri.php?action=detail&id=' . $id['a1'] . '&tab=' . $tab;
}
$ok = true;
$adaId = [];
foreach ($layar as $u) {
    $r = $adm->req($u);
    $ok = $ok && $r['status'] === 200;
    if ($isianId($r['body']) > 0) { $adaId[] = $u; }
    if ($k['sbx_pengurus_a']->req($u)['status'] !== 403) { $assert(false, 'Non-admin tidak ditolak: ' . $u); }
}
$assert($ok, 'Seluruh layar D/M/G (' . count($layar) . ' alamat) 200 untuk admin');
$assert($adaId === [], 'Tanpa isian ID yang diketik di layar D/M/G' . ($adaId === [] ? '' : ': ' . implode(', ', $adaId)));
foreach (['/admin/admin_murobi.php' => 'jenis=murobi', '/admin/admin_pembimbing.php' => 'jenis=pembimbing', '/admin/admin_santri.php' => 'admin_penempatan_santri.php'] as $lama => $tujuan) {
    $r = $adm->req($lama);
    $assert(in_array($r['status'], [301, 302], true) && str_contains((string) $r['location'], $tujuan), 'Alamat lama ' . $lama . ' dialihkan → ' . $tujuan);
}
$h = $main($adm->req('/admin/admin_master_santri.php')['body']);
$assert(!preg_match('/<th[^>]*>ID<\/th>/', $h) && str_contains($h, 'Aktif</span> <span class="ah-chips__count">') && str_contains($h, 'action=impor') && str_contains($h, 'templat=1'), 'D-1: tanpa kolom ID, chip status berangka, Impor/Ekspor/Templat di ⋯');
$aktifDb = (int) $satu('SELECT COUNT(*) FROM santri WHERE is_active=1 AND archived_at IS NULL');
$assert(str_contains($h, 'Aktif</span> <span class="ah-chips__count">' . number_format($aktifDb, 0, ',', '.') . '<'), 'Chip Aktif = basis data (' . $aktifDb . ')');
$h = $main($adm->req('/admin/admin_master_santri.php?kamar_id=' . $id['kamarA'])['body']);
$assert(str_contains($h, 'SBX Santri A1') && !str_contains($h, 'SBX Santri B1'), 'D-1 saringan kamar bernama');
$h = $teks($main($adm->req('/admin/admin_master_santri.php?action=detail&id=' . $id['a1'] . '&tab=tempat')['body']));
$assert(str_contains($h, 'SBX Kelas 1') && str_contains($h, 'Riwayat kamar') && str_contains($h, 'SBX Kamar A'), 'D-2 tab Kelas & kamar: kelas, kamar, riwayat');
$h = $teks($main($adm->req('/admin/admin_master_santri.php?action=detail&id=' . $id['a2'] . '&tab=pembinaan')['body']));
$assert(str_contains($h, 'Rahasia'), 'D-2 admin melihat kasus Rahasia (pengawasan)');
$r = $adm->req('/admin/admin_master_santri.php?action=detail&id=99999999');
$assert($r['status'] === 404, 'D-2 santri tidak ada → 404');
// D-3: konflik data lama → dialog konfirmasi, bukan kotak centang di formulir.
$conn->query("UPDATE santri SET nama_ayah = 'Nama Lama Berbeda' WHERE id = " . $id['putri']);
$h = $adm->req('/admin/admin_master_santri.php?action=edit&id=' . $id['putri'])['body'];
preg_match('/name="form_token" value="([^"]+)"/', $h, $m);
$wali = (int) $satu("SELECT id FROM wali WHERE nama='SBX Ganda HP Satu'");
$isi = ['_csrf' => $adm->csrf(), 'action' => 'save', 'id' => $id['putri'], 'form_token' => $m[1] ?? '', 'nis' => 'SBX-T6-PUTRI', 'nama_santri' => 'SBX T6 Putri', 'jenis_kelamin' => 'P', 'tgl_lahir' => '2013-02-02',
    'wali' => ['Ayah' => ['mode' => 'pilih', 'wali_id' => $wali], 'Ibu' => ['mode' => 'abaikan'], 'Wali' => ['mode' => 'abaikan']]];
$assert(!str_contains($h, 'konfirmasi_timpa'), 'D-3: formulir tidak lagi memuat kotak centang penimpaan');
$r = $adm->req('/admin/admin_master_santri.php', $isi);
$h = $adm->req((string) $r['location'])['body'];
$assert(str_contains($h, 'id="dialog-timpa"') && str_contains($h, 'name="konfirmasi_timpa[Ayah]" value="1"') && $satu('SELECT nama_ayah FROM santri WHERE id=' . $id['putri']) === 'Nama Lama Berbeda', 'D-3: perbedaan data lama memunculkan dialog konfirmasi; belum tersimpan');
preg_match('/name="form_token" value="([^"]+)"/', $h, $m);
$isi['form_token'] = $m[1] ?? '';
$isi['_csrf'] = $adm->csrf();
$isi['konfirmasi_timpa'] = ['Ayah' => '1'];
$r = $adm->req('/admin/admin_master_santri.php', $isi);
$assert($r['status'] === 302 && $satu('SELECT nama_ayah FROM santri WHERE id=' . $id['putri']) === 'SBX Ganda HP Satu', 'D-3: "Ganti dan simpan" mengganti data lama dan menyimpan');

// ===================================================================== H6-G
echo PHP_EOL . '=== H6-G. Kelulusan (D-8) dan alumni (D-9) ===' . PHP_EOL;
$lul = '/admin/admin_kelulusan_santri.php';
$h = $main($adm->req($lul . '?santri_id=' . $id['luar'])['body']);
$assert(str_contains($h, 'Lanjut ke keterangan') && str_contains($h, 'data-ah-select') && str_contains($h, 'Pilih santri'), 'Langkah 1: santri tercentang, Lanjut ke keterangan');
$r = $adm->req($lul, ['_csrf' => $adm->csrf($lul), 'tahap' => 'keterangan', 'sumber' => 'santri', 'santri_id' => $id['luar'], 'santri_ids' => [$id['luar']]]);
$assert($r['status'] === 200 && str_contains($r['body'], 'name="status_keluar"') && str_contains($r['body'], 'value="tinjau"'), 'Langkah 2: keterangan keluar');
$r = $adm->req($lul, ['_csrf' => $adm->csrf($lul), 'tahap' => 'keterangan', 'sumber' => 'santri', 'santri_id' => $id['luar'], 'santri_ids' => []]);
$assert($r['status'] === 422, 'Langkah 2 tanpa santri dicentang ditolak');
$r = $adm->req($lul, ['_csrf' => $adm->csrf($lul), 'tahap' => 'tinjau', 'sumber' => 'santri', 'santri_id' => $id['luar'], 'santri_ids' => [$id['luar']], 'status_keluar' => 'Pindah', 'tgl_keluar' => date('Y-m-d'), 'tahun_angkatan' => '2026', 'tingkat' => 'Tsanawi', 'catatan' => '']);
preg_match('/name="form_token" value="([^"]+)"/', $r['body'], $m);
$assert($r['status'] === 200 && isset($m[1]) && str_contains($r['body'], 'value="terapkan"'), 'Langkah 3: tinjau & proses');
$r = $adm->req($lul, ['_csrf' => $adm->csrf($lul), 'tahap' => 'terapkan', 'form_token' => $m[1] ?? '', 'sumber' => 'santri', 'santri_id' => $id['luar'], 'santri_ids' => [$id['luar']], 'status_keluar' => 'Pindah', 'tgl_keluar' => date('Y-m-d'), 'tahun_angkatan' => '2026', 'tingkat' => 'Tsanawi', 'catatan' => '']);
$alumniBaru = (int) $satu("SELECT id FROM alumni WHERE nis='SBX-T6-LUAR'");
$assert($r['status'] === 302 && $alumniBaru > 0 && $satu("SELECT archived_at IS NOT NULL FROM santri WHERE id={$id['luar']}") == 1, 'Proses: santri menjadi alumni dan diarsipkan');
$h = $main($adm->req('/admin/admin_alumni.php')['body']);
$assert(str_contains($h, 'SBX T6 Lulus') && !preg_match('/<th[^>]*>Diproses oleh<\/th>/', $h) && substr_count((string) preg_replace('/.*?<thead>(.*?)<\/thead>.*/s', '$1', $h), '<th') === 7, 'D-9: 6 kolom data + tindakan');
$h = $main($adm->req('/admin/admin_alumni.php?action=detail&id=' . $id['alumni'])['body']);
$assert(str_contains($h, 'id="ah-panel"') && str_contains($h, 'Penempatan terakhir') && str_contains($h, 'Orang tua saat keluar') && str_contains($h, 'Jejak proses') && str_contains($h, 'id="aksi-arsip"'), 'D-9 panel detail: penempatan terakhir, data orang tua, jejak, tindakan beralasan');
$adm->req('/admin/admin_alumni.php', ['_csrf' => $adm->csrf('/admin/admin_alumni.php'), 'action' => 'arsip', 'id' => $id['alumni'], 'alasan' => 'x']);
$assert($satu('SELECT archived_at FROM alumni WHERE id=' . $id['alumni']) === null, 'Arsip alumni dengan alasan pendek ditolak');
$adm->req('/admin/admin_alumni.php', ['_csrf' => $adm->csrf('/admin/admin_alumni.php'), 'action' => 'arsip', 'id' => $id['alumni'], 'alasan' => 'Data ganda dari impor lama']);
$assert($satu('SELECT archived_at FROM alumni WHERE id=' . $id['alumni']) !== null, 'Arsip alumni dengan alasan tersimpan (tidak dihapus)');

// ===================================================================== H6-H
echo PHP_EOL . '=== H6-H. Asatidz & pengurus, kelas, tahun ajaran, penugasan ===' . PHP_EOL;
$h = $main($adm->req('/admin/admin_guru.php?action=detail&id=205')['body']);
$assert(str_contains($h, 'id="ah-panel"') && (str_contains($h, 'admin_akun.php?guru_id=205') || str_contains($h, '@sbx_guru_mapel')) && str_contains($h, 'admin_penugasan.php?jenis=murobi'), 'M-1 panel asatidz: akun terhubung / Buat akun, tautan Penugasan');
$h = $main($adm->req('/admin/admin_pengurus.php')['body']);
$assert(str_contains($h, 'Kamar/kelas binaan') && str_contains($h, 'SBX Kamar A, SBX Kamar C'), 'M-1 tab Pengurus: kolom kamar/kelas binaan dari penugasan aktif');
$r = $adm->req('/admin/export_master.php?entity=pengurus');
$assert($r['status'] === 200 && str_contains($r['body'], 'Kamar/Kelas Binaan'), 'Ekspor CSV pengurus');
$h = $main($adm->req('/admin/admin_kelas.php')['body']);
$anggota = (int) $satu("SELECT COUNT(*) FROM plotting_kelas WHERE id_kelas=1 AND status='Aktif' AND id_tahun=(SELECT id FROM tahun_ajaran WHERE status='Aktif' AND archived_at IS NULL)");
$assert(str_contains($h, $anggota . ' santri') && str_contains($h, 'admin_penempatan_santri.php?kelas_id=1') && !str_contains($h, 'Tempatkan Santri pada Semester Aktif'), 'M-2 Kelas: anggota semester aktif, Atur anggota, kartu tempatkan ganda dihapus');
$adm->req('/admin/admin_tahun.php', ['_csrf' => $adm->csrf('/admin/admin_tahun.php'), 'action' => 'save', 'tahun' => '2027/2028', 'semester' => 'Ganjil']);
$assert((int) $satu("SELECT COUNT(*) FROM tahun_ajaran WHERE tahun='2027/2028' AND status='Non-Aktif'") === 1, 'M-3: semester baru tersimpan Non-Aktif');
$h = $main($adm->req('/admin/admin_tahun.php')['body']);
$assert(str_contains($h, 'Semester aktif') && str_contains($h, '2026/2027') && str_contains($h, 'otomatis menjadi Non-Aktif'), 'M-3: kartu semester aktif, dialog aktifkan menjelaskan dampak');
$h = $main($adm->req('/admin/admin_penugasan.php?jenis=panitia_psb')['body']);
$assert(str_contains($h, 'Disiapkan untuk modul mendatang') && str_contains($h, 'belum memberi akses ke halaman apa pun') && str_contains($h, 'Panitia PSB'), 'G-1/AF: jenis modul mendatang dipisah dan diberi keterangan');
$h = $main($adm->req('/admin/admin_penugasan.php?jenis=murobi')['body']);
$assert(str_contains($h, 'Memutus izin') && !str_contains($h, 'murobi.binaan') && str_contains($h, 'Hak yang didapat') && !str_contains($h, '>Mata Pelajaran<'), 'G-1: "Hak yang didapat" dengan kata biasa; Mata pelajaran tidak di menu jenis');
$paB = (int) $satu("SELECT id FROM pembimbing_assignments WHERE pengurus_id = 2 AND kamar_id = (SELECT id FROM kamar WHERE nama_kamar='SBX Kamar B')");
$pg = '/admin/admin_penugasan.php?jenis=pembimbing';
$adm->req($pg, ['_csrf' => $adm->csrf($pg), 'jenis' => 'pembimbing', 'action' => 'arsipkan', 'id' => $paB, 'alasan' => 'Uji arsip Tahap 6']);
$assert($satu("SELECT archived_at FROM pembimbing_assignments WHERE id=$paB") !== null, 'G-1: arsipkan penugasan pembimbing (sebelumnya hanya di halaman lama)');
$adm->req($pg, ['_csrf' => $adm->csrf($pg), 'jenis' => 'pembimbing', 'action' => 'pulihkan', 'id' => $paB, 'alasan' => 'Uji pulihkan Tahap 6']);
$assert($satu("SELECT archived_at FROM pembimbing_assignments WHERE id=$paB") === null, 'G-1: pulihkan penugasan');
$h = $main($adm->req($pg . '&kelola=' . $paB . '&aksi=akhiri')['body']);
$assert(str_contains($h, 'id="aksi-akhiri" data-ah-dialog open') && str_contains($h, 'name="tanggal_selesai"'), 'G-1: ⋯ Akhiri membuka dialog tanggal selesai + alasan di panel');
$r = $adm->req('/admin/admin_murobi.php', ['_csrf' => $adm->csrf($pg), 'action' => 'x']);
$assert($r['status'] === 302, 'POST halaman lama murobi tetap diproses (kompatibilitas)');

// ===================================================================== H6-I
echo PHP_EOL . '=== H6-I. Log server ===' . PHP_EOL;
$isi = '';
foreach ($logs as $log) {
    $isi .= (string) file_get_contents($log);
}
preg_match_all('/PHP (Warning|Notice|Deprecated|Fatal error|Parse error)[^\n]*/', $isi, $m);
$assert($m[0] === [], 'Server tanpa galat/peringatan PHP' . ($m[0] === [] ? '' : ': ' . implode(' | ', array_slice(array_unique($m[0]), 0, 3))));
$conn->query("DROP DATABASE IF EXISTS `$dbMigrasi`");

echo PHP_EOL . 'Ringkasan: ' . $lulus . ' lulus, ' . count($gagal) . ' gagal' . PHP_EOL;
foreach ($gagal as $g) {
    echo '  - ' . $g . PHP_EOL;
}
exit($gagal === [] ? 0 : 1);
