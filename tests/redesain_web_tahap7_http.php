<?php

declare(strict_types=1);

/**
 * Uji server redesain website Tahap 7 — penilaian & rapor
 * (R-1 s.d. R-8, M-5 · U, V, W, X, Y).
 *
 * Kriteria selesai Tahap 7 (cetak biru): satu kelas 30 santri dapat diisi
 * nilainya di satu layar; tindakan massal berjalan per santri dengan bilah
 * kemajuan tanpa melewati batas waktu cPanel. Skrip menjalankan server PHP
 * lokal sendiri di atas DB uji `tests/redesain_web_tahap7_db.sh` dan memeriksa:
 *
 *   H7-A  hak akses setiap layar per akun (server, bukan menu) dan jalur
 *         gambar tanda tangan berpenjaga;
 *   H7-B  R-2/R-3 kisi: 30 santri satu halaman, simpan per santri yang
 *         berubah, koreksi wajib beralasan, santri di luar kelas ditolak,
 *         alasan sesi pengganti tercatat, serahkan per santri (JSON) dengan
 *         sidik, akun tanpa penugasan ditolak tanpa perubahan;
 *   H7-C  R-4 kisi sikap, serahkan & tandai diketahui per santri, murobi
 *         tidak dapat mengisi sebagai pembimbing;
 *   H7-D  R-5 verifikasi massal per bahan, versi usang ditolak, kembalikan
 *         wajib beralasan;
 *   H7-E  R-6 terbit massal per santri (durasi per permintaan), kunci
 *         idempotensi, sidik usang ditolak, catatan umum sesi ikut terbit,
 *         buka/tarik beralasan, batas 10 tanpa JavaScript;
 *   H7-F  R-1 salin semester lalu (X), R-7 ganti penanda beralasan, M-5 dan
 *         alamat lama, R-8 versi terbaru (Y) dan isolasi antarwali;
 *   H7-G  bentuk POST lama tetap diterima; GET tidak memutasi; CSRF wajib;
 *   H7-H  nama bukan nomor; tidak ada galat/peringatan PHP di log server.
 *
 *   bash tests/redesain_web_tahap7_db.sh
 *   php tests/redesain_web_tahap7_http.php
 *
 * Skrip mengubah data uji; bangun ulang DB sebelum mengulang bersama uji browser.
 */

$root = dirname(__DIR__);
$db = getenv('TAHAP7_DB') ?: 'test_redesain_t7_test';
$port = (int) (getenv('TAHAP7_PORT') ?: 8975);
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

$log = tempnam(sys_get_temp_dir(), 'tahap7-server-');
$server = proc_open(['php', '-S', '127.0.0.1:' . $port, $root . '/tests/redesain_web_tahap1_router.php'],
    [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, $root, array_merge(getenv(), ['DB_NAME' => $db]));
for ($i = 0; $i < 50; $i++) {
    $sock = @fsockopen('127.0.0.1', $port);
    if ($sock !== false) {
        fclose($sock);
        break;
    }
    usleep(100000);
}
register_shutdown_function(static function () use ($server): void {
    if (is_resource($server)) {
        proc_terminate($server);
    }
});

final class Klien
{
    private string $jar;

    public function __construct(private string $base)
    {
        $this->jar = tempnam(sys_get_temp_dir(), 'tahap7-jar-');
    }

    /** @return array{status:int, body:string, location:?string, type:string, detik:float} */
    public function req(string $path, ?array $post = null, array $headers = []): array
    {
        $h = curl_init($this->base . $path);
        curl_setopt_array($h, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar, CURLOPT_TIMEOUT => 120, CURLOPT_HTTPHEADER => $headers]);
        if ($post !== null) {
            curl_setopt($h, CURLOPT_POST, true);
            curl_setopt($h, CURLOPT_POSTFIELDS, http_build_query($post));
        }
        $mulai = microtime(true);
        $raw = (string) curl_exec($h);
        $detik = microtime(true) - $mulai;
        $status = (int) curl_getinfo($h, CURLINFO_RESPONSE_CODE);
        $size = (int) curl_getinfo($h, CURLINFO_HEADER_SIZE);
        $type = (string) curl_getinfo($h, CURLINFO_CONTENT_TYPE);
        curl_close($h);
        preg_match('/^Location:\s*(.+)$/mi', substr($raw, 0, $size), $m);

        return ['status' => $status, 'body' => substr($raw, $size), 'location' => isset($m[1]) ? trim($m[1]) : null, 'type' => $type, 'detik' => $detik, 'header' => substr($raw, 0, $size)];
    }

    /** POST yang mengharapkan JSON (sama dengan AlHasan.post). */
    public function json(string $path, array $post): array
    {
        $r = $this->req($path, $post + ['_csrf' => $this->csrf($path)], ['Accept: application/json']);
        $r['json'] = json_decode($r['body'], true);

        return $r;
    }

    public function csrf(string $path = '/portal/index.php'): string
    {
        $r = $this->req($path);
        if (preg_match('/name="_csrf" value="([^"]+)"/', $r['body'], $m) !== 1) {
            $r = $this->req('/portal/index.php');
            preg_match('/name="_csrf" value="([^"]+)"/', $r['body'], $m);
        }

        return $m[1] ?? '';
    }

    public function login(string $user): void
    {
        $this->req('/admin/cek_login.php', ['_csrf' => $this->csrf(), 'username' => $user, 'password' => 'Sandbox#123']);
    }
}

$klien = static function (string $user) use ($port): Klien {
    $k = new Klien('http://127.0.0.1:' . $port);
    $k->login($user);

    return $k;
};
$teks = static fn (string $html): string => trim(html_entity_decode((string) preg_replace('/\s+/', ' ', (string) preg_replace('/<[^>]+>/', ' ', (string) preg_replace('#<(script|style|template)\b.*?</\1>#s', '', $html))), ENT_QUOTES));
$main = static fn (string $html): string => (string) substr($html, (int) strpos($html, '<main'));
$q = static function (string $query): array {
    $r = app_db()->query($query);
    if ($r === false) {
        throw new RuntimeException(app_db()->error . ' :: ' . $query);
    }

    return $r === true ? [] : $r->fetch_all(MYSQLI_ASSOC);
};
$satu = static fn (string $query): mixed => array_values($q($query)[0] ?? [null])[0];
$ambil = static fn (string $html, string $pola): array => preg_match_all($pola, $html, $m) ? $m[1] : [];

$ids = json_decode((string) file_get_contents($root . '/storage/private/v4/redesain_t7_ids.json'), true);
$S = $ids['santri'];
$RM = $ids['rencana_mapel'];
$fiqih = (int) $RM[(string) $ids['mapel']['fiqih']];
$nahwu = (int) $RM[(string) $ids['mapel']['nahwu']];
$tafsir = (int) $RM[(string) $ids['mapel']['tafsir']];
$rencana = (int) $ids['rencana'];
$assert($fiqih > 0 && $rencana > 0 && count($S) === 30, 'Fixture Tahap 7 tersedia (rencana ' . $rencana . ', 30 santri)');

$akun = ['sbx_admin', 'sbx_pendidikan', 'sbx_guru_mapel', 'sbx_guru_biasa', 'sbx_pengurus_a', 'sbx_pengurus_biasa', 'sbx_murobi_c', 'sbx_ortu_a', 'sbx_t7_ortu'];
$k = [];
foreach ($akun as $u) {
    $k[$u] = $klien($u);
}
$adm = $k['sbx_admin'];
$pend = $k['sbx_pendidikan'];
$guru = $k['sbx_guru_mapel'];

// ===================================================================== H7-A
echo PHP_EOL . '=== H7-A. Hak akses setiap layar (server) ===' . PHP_EOL;
$layar = [
    'R-1' => ['/portal/rencana_nilai.php', ['sbx_admin' => 200, 'sbx_pendidikan' => 200, 'sbx_guru_mapel' => 403, 'sbx_pengurus_a' => 403, 'sbx_t7_ortu' => 403]],
    'R-2' => ['/portal/nilai_semester.php?rencana_mapel_id=' . $fiqih, ['sbx_guru_mapel' => 200, 'sbx_guru_biasa' => 403, 'sbx_pengurus_a' => 403, 'sbx_t7_ortu' => 403]],
    'R-3' => ['/portal/nilai_semester.php?pengganti=1&rencana_mapel_id=' . $fiqih, ['sbx_pendidikan' => 200, 'sbx_guru_mapel' => 403, 'sbx_pengurus_a' => 403]],
    'R-4' => ['/portal/sikap_semester.php', ['sbx_pengurus_a' => 200, 'sbx_pengurus_biasa' => 403, 'sbx_guru_mapel' => 403, 'sbx_t7_ortu' => 403]],
    'R-4m' => ['/portal/sikap_semester.php?mode=murobi', ['sbx_murobi_c' => 200, 'sbx_pengurus_a' => 403, 'sbx_guru_biasa' => 403]],
    'R-5' => ['/portal/verifikasi_semester.php?rencana_id=' . $rencana, ['sbx_pendidikan' => 200, 'sbx_admin' => 200, 'sbx_guru_mapel' => 403, 'sbx_pengurus_a' => 403, 'sbx_t7_ortu' => 403]],
    'R-6' => ['/portal/rapor_semester.php?rencana_id=' . $rencana, ['sbx_pendidikan' => 200, 'sbx_admin' => 200, 'sbx_guru_mapel' => 403, 'sbx_murobi_c' => 403, 'sbx_t7_ortu' => 403]],
    'R-7' => ['/portal/rapor_penanda.php', ['sbx_admin' => 200, 'sbx_pendidikan' => 403, 'sbx_guru_mapel' => 403]],
    'R-8' => ['/portal/rapor_wali.php', ['sbx_t7_ortu' => 200, 'sbx_ortu_a' => 200, 'sbx_guru_mapel' => 403, 'sbx_pendidikan' => 403]],
    'M-5' => ['/admin/admin_mata_pelajaran.php', ['sbx_admin' => 200, 'sbx_pendidikan' => 403, 'sbx_guru_mapel' => 403]],
];
foreach ($layar as $kode => [$alamat, $harap]) {
    foreach ($harap as $u => $status) {
        $r = $k[$u]->req($alamat);
        $assert($r['status'] === $status, "$kode $u → $status" . ($r['status'] === $status ? '' : ' (dapat ' . $r['status'] . ')'));
    }
}
$r = (new Klien('http://127.0.0.1:' . $port))->req('/portal/rapor_ttd.php?aset=1');
$assert(in_array($r['status'], [302, 303], true) && !str_contains($r['type'], 'image'), 'Gambar tanda tangan: tanpa sesi dialihkan ke halaman masuk');
$aktif = (int) $ids['penanda']['pendidikan']['aset'];
$lama = (int) $ids['penanda']['lama']['aset'];
foreach ([[$adm, $aktif, 200, 'admin aset aktif'], [$pend, $aktif, 200, 'Pendidikan aset aktif'], [$adm, $lama, 200, 'admin aset nonaktif'], [$pend, $lama, 403, 'Pendidikan aset nonaktif'],
    [$guru, $aktif, 403, 'guru mapel'], [$k['sbx_t7_ortu'], $aktif, 403, 'orang tua'], [$adm, 999999, 404, 'aset tidak ada']] as [$kl, $aset, $status, $label]) {
    $r = $kl->req('/portal/rapor_ttd.php?aset=' . $aset);
    $assert($r['status'] === $status && ($status !== 200 || (str_starts_with($r['type'], 'image/png') && str_starts_with($r['body'], "\x89PNG"))), "Gambar tanda tangan: $label → $status");
}
$r = $pend->req('/portal/rapor_ttd.php?aset=' . $aktif);
$assert(str_contains($r['header'], 'no-store') && str_contains($r['header'], 'nosniff') && str_contains($r['header'], "sandbox"), 'Gambar tanda tangan: no-store, nosniff, CSP sandbox');
$asli = (string) $satu("SELECT sha256 FROM v4_tanda_tangan_aset WHERE id=$aktif");
$q("UPDATE v4_tanda_tangan_aset SET sha256=REPEAT('0',64) WHERE id=$aktif");
$assert($adm->req('/portal/rapor_ttd.php?aset=' . $aktif)['status'] === 404, 'Gambar tanda tangan: berkas tidak cocok sidik sha256 tidak disajikan');
$q("UPDATE v4_tanda_tangan_aset SET sha256='" . $asli . "' WHERE id=$aktif");

// ===================================================================== H7-B
echo PHP_EOL . '=== H7-B. R-2/R-3 kisi nilai ===' . PHP_EOL;
$alamat = '/portal/nilai_semester.php?rencana_mapel_id=' . $fiqih;
$h = $guru->req($alamat)['body'];
$assert(substr_count($h, '<tr data-santri=') === 30 && preg_match_all('/name="n\[\d+\]\[(?:Harian:\d+|Tengah:0|Akhir:0)\]"/', $h) >= 120, 'R-2: satu halaman memuat 30 santri dengan isian per komponen');
$jumlahNilai = static fn (): int => (int) $satu("SELECT COUNT(*) FROM v4_nilai WHERE rencana_mapel_id=$fiqih");
$sebelum = $jumlahNilai();
$tok = $guru->csrf($alamat);
$post = ['_csrf' => $tok, 'aksi' => 'kisi', 'pengganti' => '0', 'rencana_mapel_id' => $fiqih,
    'n' => [$S[25] => ['Harian:1' => '81', 'Tengah:0' => '82', 'Akhir:0' => '83.5'], $S[26] => ['Harian:1' => '', 'Tengah:0' => ''], $S[22] => ['Tengah:0' => '70']]];
$r = $guru->req('/portal/nilai_semester.php', $post);
$assert($r['status'] === 302 && $jumlahNilai() === $sebelum + 4, 'Simpan draf: hanya santri yang berubah tersimpan (3 + 1 komponen baru), baris kosong diabaikan');
$assert((int) $satu("SELECT COUNT(DISTINCT santri_id) FROM v4_nilai WHERE rencana_mapel_id=$fiqih AND santri_id IN ({$S[25]},{$S[22]}) AND status='Draf' AND kapasitas='guru'") === 2
    && (int) $satu("SELECT COUNT(*) FROM v4_nilai_riwayat h JOIN v4_nilai n ON n.id=h.nilai_id WHERE n.rencana_mapel_id=$fiqih AND n.santri_id={$S[25]}") === 3, 'Setiap komponen baru punya riwayat revisi per santri');
$nilai13 = (string) $satu("SELECT nilai FROM v4_nilai WHERE rencana_mapel_id=$fiqih AND santri_id={$S[13]} AND komponen='Harian' AND nomor_harian=1");
$r = $guru->req('/portal/nilai_semester.php', ['_csrf' => $tok, 'aksi' => 'kisi', 'rencana_mapel_id' => $fiqih, 'n' => [$S[13] => ['Harian:1' => '55']]]);
$flash = $teks($guru->req($alamat)['body']);
$assert($satu("SELECT nilai FROM v4_nilai WHERE rencana_mapel_id=$fiqih AND santri_id={$S[13]} AND komponen='Harian' AND nomor_harian=1") === $nilai13 && str_contains($flash, 'memerlukan alasan koreksi'), 'Mengubah nilai tersimpan tanpa alasan koreksi ditolak per santri');
$ver = (int) $satu("SELECT version FROM v4_nilai WHERE rencana_mapel_id=$fiqih AND santri_id={$S[13]} AND komponen='Harian' AND nomor_harian=1");
$guru->req('/portal/nilai_semester.php', ['_csrf' => $tok, 'aksi' => 'kisi', 'rencana_mapel_id' => $fiqih, 'alasan_koreksi' => 'Koreksi uji server', 'n' => [$S[13] => ['Harian:1' => '55']], 'ver' => [$S[13] => ['Harian:1' => $ver]]]);
$baris = $q("SELECT nilai, alasan, version FROM v4_nilai WHERE rencana_mapel_id=$fiqih AND santri_id={$S[13]} AND komponen='Harian' AND nomor_harian=1")[0];
$assert($baris['nilai'] === '55.00' && $baris['alasan'] === 'Koreksi uji server' && (int) $baris['version'] === $ver + 1, 'Koreksi beralasan tersimpan sebagai versi baru dengan alasan');
$guru->req('/portal/nilai_semester.php', ['_csrf' => $tok, 'aksi' => 'kisi', 'rencana_mapel_id' => $fiqih, 'alasan_koreksi' => 'Versi usang', 'n' => [$S[13] => ['Harian:1' => '56']], 'ver' => [$S[13] => ['Harian:1' => $ver]]]);
$assert($satu("SELECT nilai FROM v4_nilai WHERE rencana_mapel_id=$fiqih AND santri_id={$S[13]} AND komponen='Harian' AND nomor_harian=1") === '55.00', 'Versi usang dari kisi ditolak (tidak menimpa)');
$luar = (int) $satu("SELECT id FROM santri WHERE nama_santri='SBX Santri A1'");
$guru->req('/portal/nilai_semester.php', ['_csrf' => $tok, 'aksi' => 'kisi', 'rencana_mapel_id' => $fiqih, 'n' => [$luar => ['Harian:1' => '90', 'Tengah:0' => '90', 'Akhir:0' => '90']]]);
$assert((int) $satu("SELECT COUNT(*) FROM v4_nilai WHERE rencana_mapel_id=$fiqih AND santri_id=$luar") === 0, 'Santri di luar kelas & semester ditolak dari kisi');
$sebelum = $jumlahNilai();
$tokBiasa = $k['sbx_guru_biasa']->csrf();
$r = $k['sbx_guru_biasa']->req('/portal/nilai_semester.php', ['_csrf' => $tokBiasa, 'aksi' => 'kisi', 'rencana_mapel_id' => $fiqih, 'n' => [$S[27] => ['Harian:1' => '90']]]);
$assert($r['status'] === 403 && $jumlahNilai() === $sebelum, 'Guru tanpa penugasan mapel: POST kisi 403 tanpa perubahan');
// Serahkan per santri (JSON) dengan sidik.
$h = $guru->req($alamat)['body'];
$pilih = $ambil($h, '/name="pilih\[\]" value="([^"]+)"/');
$assert(count($pilih) >= 8, 'Serahkan yang lengkap: ' . count($pilih) . ' santri lengkap siap diserahkan');
$r = $guru->json('/portal/nilai_semester.php', ['aksi' => 'serahkan_lengkap', 'pengganti' => '0', 'rencana_mapel_id' => $fiqih, 'pilih' => $pilih[0]]);
$sid0 = (int) explode(':', $pilih[0])[0];
$assert($r['status'] === 200 && ($r['json']['ok'] ?? false) === true && (int) $satu("SELECT COUNT(*) FROM v4_nilai WHERE rencana_mapel_id=$fiqih AND santri_id=$sid0 AND status<>'Dikirim'") === 0, 'Serahkan satu santri per permintaan (JSON); seluruh komponennya Diserahkan');
$r = $guru->json('/portal/nilai_semester.php', ['aksi' => 'serahkan_lengkap', 'rencana_mapel_id' => $fiqih, 'pilih' => $pilih[0]]);
$assert($r['status'] === 409 && ($r['json']['ok'] ?? true) === false, 'Sidik berubah (sudah diserahkan) → 409, tidak diserahkan ulang');
[$sid1, $sidik1] = explode(':', $pilih[1]);
$r = $guru->json('/portal/nilai_semester.php', ['aksi' => 'serahkan_lengkap', 'rencana_mapel_id' => $fiqih, 'pilih' => $sid1 . ':' . str_repeat('a', 64)]);
$assert($r['status'] === 409 && (int) $satu("SELECT COUNT(*) FROM v4_nilai WHERE rencana_mapel_id=$fiqih AND santri_id=$sid1 AND status='Dikirim'") === 0, 'Sidik palsu ditolak (409) tanpa perubahan');
// R-3 pengganti: alasan sesi.
$alamat3 = '/portal/nilai_semester.php?pengganti=1&rencana_mapel_id=' . $tafsir;
$h = $pend->req($alamat3)['body'];
$assert(str_contains($h, 'Mulai sesi nilai pengganti') && str_contains($h, '<fieldset disabled>'), 'R-3: kisi terkunci sampai alasan sesi diisi');
$tokP = $pend->csrf($alamat3);
$sebelum = (int) $satu("SELECT COUNT(*) FROM v4_nilai WHERE rencana_mapel_id=$tafsir");
$pend->req('/portal/nilai_semester.php', ['_csrf' => $tokP, 'aksi' => 'kisi', 'pengganti' => '1', 'rencana_mapel_id' => $tafsir, 'n' => [$S[28] => ['Harian:1' => '70']]]);
$assert((int) $satu("SELECT COUNT(*) FROM v4_nilai WHERE rencana_mapel_id=$tafsir") === $sebelum, 'R-3: tanpa alasan sesi, penyimpanan pengganti ditolak layanan');
$pend->req('/portal/nilai_semester.php', ['_csrf' => $tokP, 'aksi' => 'alasan_sesi', 'pengganti' => '1', 'rencana_mapel_id' => $tafsir, 'alasan_sesi' => 'Guru cuti sakit (uji)']);
$h = $pend->req($alamat3)['body'];
$assert(str_contains($teks($h), 'Anda mengisi atas nama') && str_contains($teks($h), 'Guru cuti sakit (uji)') && !str_contains($h, '<fieldset disabled>'), 'R-3: catatan "atas nama guru" + alasan; kisi terbuka');
$pend->req('/portal/nilai_semester.php', ['_csrf' => $tokP, 'aksi' => 'kisi', 'pengganti' => '1', 'rencana_mapel_id' => $tafsir, 'n' => [$S[28] => ['Harian:1' => '70']]]);
$row = $q("SELECT kapasitas, alasan FROM v4_nilai WHERE rencana_mapel_id=$tafsir AND santri_id={$S[28]}")[0] ?? [];
$assert(($row['kapasitas'] ?? '') === 'pendidikan_pengganti' && ($row['alasan'] ?? '') === 'Guru cuti sakit (uji)', 'R-3: alasan sesi tercatat pada setiap perubahan (kapasitas pengganti)');
$h = $adm->req('/portal/nilai_semester.php?rencana_mapel_id=' . $nahwu)['body'];
$assert(str_contains($h, 'Alasan tindakan admin'), 'R-2 admin: tindakan admin pada nilai meminta alasan sesi (V4Access)');

// ===================================================================== H7-C
echo PHP_EOL . '=== H7-C. R-4 sikap ===' . PHP_EOL;
$pa = $k['sbx_pengurus_a'];
$alamat = '/portal/sikap_semester.php?tahun_ajaran_id=' . $ids['tahun'];
$h = $pa->req($alamat)['body'];
$assert(substr_count($h, 'data-santri=') >= 30 && str_contains($h, 'name="s[' . $rencana . ':' . $S[20] . '][adab]"'), 'R-4: kisi santri × 3 aspek untuk santri binaan');
$tok = $pa->csrf($alamat);
$pa->req('/portal/sikap_semester.php', ['_csrf' => $tok, 'aksi' => 'kisi', 'mode' => 'pembimbing', 's' => [
    $rencana . ':' . $S[20] => ['adab' => 'Baik', 'kedisiplinan' => 'Baik', 'tanggung_jawab' => 'Sangat Baik'],
    $rencana . ':' . $S[21] => ['adab' => 'Baik', 'kedisiplinan' => '', 'tanggung_jawab' => ''],
]]);
$assert((int) $satu("SELECT COUNT(*) FROM v4_sikap WHERE santri_id={$S[20]} AND status='Draf'") === 1 && (int) $satu("SELECT COUNT(*) FROM v4_sikap WHERE santri_id={$S[21]}") === 0, 'Simpan draf sikap: baris lengkap tersimpan, baris sebagian ditolak');
$luarSikap = (int) $satu("SELECT id FROM santri WHERE nis='SBX-T6-LUAR'");
$pa->req('/portal/sikap_semester.php', ['_csrf' => $tok, 'aksi' => 'kisi', 'mode' => 'pembimbing', 's' => [$rencana . ':' . $luarSikap => ['adab' => 'Baik', 'kedisiplinan' => 'Baik', 'tanggung_jawab' => 'Baik']]]);
$assert((int) $satu("SELECT COUNT(*) FROM v4_sikap WHERE santri_id=$luarSikap") === 0, 'Santri di luar penugasan ditolak dari kisi sikap');
$ver = (int) $satu("SELECT version FROM v4_sikap WHERE santri_id={$S[20]}");
$r = $pa->json('/portal/sikap_semester.php', ['aksi' => 'serahkan_lengkap', 'mode' => 'pembimbing', 'pilih' => $rencana . ':' . $S[20] . ':' . $ver]);
$assert(($r['json']['ok'] ?? false) === true && $satu("SELECT status FROM v4_sikap WHERE santri_id={$S[20]}") === 'Dikirim', 'Serahkan sikap per santri (JSON)');
$mc = $k['sbx_murobi_c'];
$tokM = $mc->csrf('/portal/sikap_semester.php?mode=murobi');
$mc->req('/portal/sikap_semester.php', ['_csrf' => $tokM, 'aksi' => 'kisi', 'mode' => 'murobi', 's' => [$rencana . ':' . $S[22] => ['adab' => 'Baik', 'kedisiplinan' => 'Baik', 'tanggung_jawab' => 'Baik']]]);
$assert((int) $satu("SELECT COUNT(*) FROM v4_sikap WHERE santri_id={$S[22]}") === 0, 'Murobi tidak dapat mengisi sikap sebagai pembimbing lewat kisi');
$ver = (int) $satu("SELECT version FROM v4_sikap WHERE santri_id={$S[20]}");
$r = $mc->json('/portal/sikap_semester.php', ['aksi' => 'diketahui', 'mode' => 'murobi', 'pilih' => $rencana . ':' . $S[20] . ':' . $ver]);
$assert(($r['json']['ok'] ?? false) === true && $satu("SELECT diketahui_murobi_user_id FROM v4_sikap WHERE santri_id={$S[20]}") !== null, 'Tandai diketahui per santri (JSON)');
$r = $mc->json('/portal/sikap_semester.php', ['aksi' => 'diketahui', 'mode' => 'murobi', 'pilih' => $rencana . ':' . $S[20] . ':' . $ver]);
$assert($r['status'] === 409, 'Tandai diketahui dengan versi usang → 409');

// ===================================================================== H7-D
echo PHP_EOL . '=== H7-D. R-5 verifikasi ===' . PHP_EOL;
$alamat = '/portal/verifikasi_semester.php?rencana_id=' . $rencana;
$h = $pend->req($alamat)['body'];
$assert(substr_count($h, 'class="ah-matrix__cell"') === 150 && str_contains($h, 'class="ah-legend"'), 'R-5: matriks 30 santri × 5 bahan dengan legenda');
$pilih = $ambil($h, '/name="pilih\[\]" value="([^"]+)"/');
$assert($pilih !== [], 'Verifikasi massal: ' . count($pilih) . ' bahan diserahkan siap diverifikasi');
$r = $pend->json('/portal/verifikasi_semester.php', ['aksi' => 'verifikasi_massal', 'rencana_id' => $rencana, 'pilih' => $pilih[0], 'alasan' => '']);
[$vs, $vj, $vk] = explode('|', $pilih[0]);
$assert(($r['json']['ok'] ?? false) === true && $satu("SELECT status FROM v4_bahan WHERE rencana_id=$rencana AND santri_id=$vs AND jenis='$vj' AND mapel_key=$vk") === 'Terverifikasi', 'Verifikasi satu bahan per permintaan (JSON)');
$r = $pend->json('/portal/verifikasi_semester.php', ['aksi' => 'verifikasi_massal', 'rencana_id' => $rencana, 'pilih' => $pilih[0], 'alasan' => '']);
$assert($r['status'] === 409, 'Bahan berubah sejak dibuka (versi usang) → 409');
$r = $adm->json('/portal/verifikasi_semester.php', ['aksi' => 'verifikasi_massal', 'rencana_id' => $rencana, 'pilih' => $pilih[1] ?? $pilih[0], 'alasan' => '']);
$assert(($r['json']['ok'] ?? true) === false && str_contains((string) ($r['json']['message'] ?? ''), 'Alasan'), 'Admin wajib beralasan pada verifikasi massal');
if (isset($pilih[1])) {
    [$s1, $j1, $k1, $v1, $h1] = explode('|', $pilih[1]);
    $r = $pend->req('/portal/verifikasi_semester.php', ['_csrf' => $pend->csrf($alamat), 'rencana_id' => $rencana, 'santri_id' => $s1, 'jenis' => $j1, 'mapel_key' => $k1, 'version' => $v1, 'sumber_hash' => $h1, 'action' => 'kembalikan', 'alasan' => '']);
    $assert($satu("SELECT status FROM v4_bahan WHERE rencana_id=$rencana AND santri_id=$s1 AND jenis='$j1' AND mapel_key=$k1") === 'Dikirim', 'Kembalikan tanpa alasan ditolak');
}
$h = $pend->req($alamat . '&santri_id=' . $S[9] . '&bahan=nilai:' . $fiqih)['body'];
$assert(str_contains($teks($h), 'Nilai harian 2 perlu diperiksa ulang') && str_contains($teks($h), 'Diisi oleh SBX Guru Mapel'), 'Panel bahan: alasan pemeriksaan dan nama pengisi');

// ===================================================================== H7-E
echo PHP_EOL . '=== H7-E. R-6 penerbitan ===' . PHP_EOL;
$alamat = '/portal/rapor_semester.php?rencana_id=' . $rencana;
$h = $pend->req($alamat)['body'];
$siap = array_values(array_unique($ambil($h, '/name="pilih\[\]" form="terbit-pilih-form" value="([^"]+)"/')));
$assert(count($siap) >= 5, 'R-6: ' . count($siap) . ' santri siap terbit dapat dicentang');
$tok = $pend->csrf($alamat . '&santri_id=' . explode('|', $siap[0])[0]);
$pend->req('/portal/rapor_semester.php', ['_csrf' => $tok, 'aksi' => 'catatan', 'rencana_id' => $rencana, 'santri_id' => explode('|', $siap[0])[0], 'catatan_umum' => 'Pertahankan hafalan (uji).']);
$h = $pend->req($alamat)['body'];
$siap = array_values(array_unique($ambil($h, '/name="pilih\[\]" form="terbit-pilih-form" value="([^"]+)"/')));
$maks = 0.0;
$terbitOk = 0;
foreach (array_slice($siap, 0, 3) as $p) {
    $r = $pend->json('/portal/rapor_semester.php', ['aksi' => 'terbit_massal', 'rencana_id' => $rencana, 'pilih' => $p, 'alasan' => '']);
    $maks = max($maks, $r['detik']);
    $terbitOk += ($r['json']['ok'] ?? false) === true ? 1 : 0;
}
$s0 = (int) explode('|', $siap[0])[0];
$assert($terbitOk === 3 && (int) $satu("SELECT COUNT(*) FROM v4_rapor WHERE santri_id=$s0 AND status='Terbit' AND current_version=1") === 1, 'Terbit satu santri per permintaan (JSON), 3 rapor terbit');
$assert($maks < 20.0, sprintf('Durasi terlama satu permintaan terbit %.2f detik (< 20 detik; batas cPanel umumnya 30)', $maks));
$snap = json_decode((string) $satu("SELECT v.snapshot_json FROM v4_rapor_versi v JOIN v4_rapor r ON r.id=v.rapor_id WHERE r.santri_id=$s0"), true);
$assert(($snap['catatan_umum'] ?? '') === 'Pertahankan hafalan (uji).', 'Catatan umum draf sesi ikut terbit');
$sebelum = (int) $satu('SELECT COUNT(*) FROM v4_rapor_versi');
$r = $pend->json('/portal/rapor_semester.php', ['aksi' => 'terbit_massal', 'rencana_id' => $rencana, 'pilih' => $siap[0], 'alasan' => '']);
$assert(($r['json']['ok'] ?? false) === true && (int) $satu('SELECT COUNT(*) FROM v4_rapor_versi') === $sebelum, 'Kunci idempotensi sama: diulang tanpa versi ganda');
$p = explode('|', $siap[3]);
$p[1] = str_repeat('b', 64);
$r = $pend->json('/portal/rapor_semester.php', ['aksi' => 'terbit_massal', 'rencana_id' => $rencana, 'pilih' => implode('|', $p), 'alasan' => '']);
$assert($r['status'] === 409 && (int) $satu('SELECT COUNT(*) FROM v4_rapor WHERE santri_id=' . (int) $p[0]) === 0, 'Sidik pratinjau palsu ditolak (409) tanpa rapor');
$rap = $q("SELECT id, current_version, (SELECT MAX(id) FROM v4_rapor_operasi WHERE rapor_id=v4_rapor.id) rev FROM v4_rapor WHERE santri_id=$s0")[0];
$tok = $pend->csrf($alamat);
$pend->req('/portal/rapor_semester.php', ['_csrf' => $tok, 'action' => 'Tarik', 'rencana_id' => $rencana, 'santri_id' => $s0, 'current_version' => $rap['current_version'], 'revision' => $rap['rev'], 'idempotency_key' => bin2hex(random_bytes(16)), 'alasan' => '']);
$assert($satu("SELECT status FROM v4_rapor WHERE santri_id=$s0") === 'Terbit', 'Tarik tanpa alasan ditolak');
$pend->req('/portal/rapor_semester.php', ['_csrf' => $tok, 'action' => 'Buka', 'rencana_id' => $rencana, 'santri_id' => $s0, 'current_version' => $rap['current_version'], 'revision' => $rap['rev'], 'idempotency_key' => bin2hex(random_bytes(16)), 'alasan' => 'Koreksi nilai (uji)']);
$assert($satu("SELECT status FROM v4_rapor WHERE santri_id=$s0") === 'Perlu Perbaikan', 'Buka untuk koreksi beralasan');
$h = $pend->req($alamat . '&tampil=siap')['body'];
$assert(str_contains($h, 'Wajib karena ada versi pengganti'), 'Terbitkan yang siap meminta alasan bila ada versi pengganti');
$tokBiasa = $guru->csrf();
$r = $guru->json('/portal/rapor_semester.php', ['aksi' => 'terbit_massal', 'rencana_id' => $rencana, 'pilih' => $siap[4] ?? $siap[3], 'alasan' => '']);
$assert($r['status'] === 403, 'Guru mapel tidak dapat menerbitkan (403)');
// Tanpa JavaScript: paling banyak 10 per kiriman.
$palsu = [];
for ($i = 0; $i < 12; $i++) {
    $palsu[] = '999999|' . str_repeat('c', 64) . '|0|0|' . bin2hex(random_bytes(8));
}
$r = $pend->req('/portal/rapor_semester.php', ['_csrf' => $pend->csrf($alamat), 'aksi' => 'terbit_massal', 'rencana_id' => $rencana, 'pilih' => $palsu, 'alasan' => '']);
$assert(str_contains($teks($pend->req($alamat)['body']), '10 gagal · 2 belum diproses'), 'Tanpa JavaScript: diproses paling banyak 10, sisanya dilaporkan belum diproses');

// ===================================================================== H7-F
echo PHP_EOL . '=== H7-F. R-1, R-7, M-5, R-8 ===' . PHP_EOL;
$alamat = '/portal/rencana_nilai.php?tahun_ajaran_id=' . $ids['tahun'] . '&kelas_id=' . $ids['kelas_baru'];
$assert(!str_contains($pend->req($alamat)['body'], 'salin=1'), 'R-1: Pendidikan (penugasan semester aktif saja) tidak ditawari salin');
$h = $pend->req($alamat . '&salin=1')['body'];
$assert(str_contains($teks($h), 'di luar penugasan Anda') && !str_contains($h, 'Formulir diisi dari rencana'), 'R-1: salin oleh Pendidikan tanpa cakupan semester lalu ditolak layanan baca');
$h = $adm->req($alamat . '&salin=1')['body'];
$assert(str_contains($teks($h), 'Formulir diisi dari rencana') && str_contains($teks($h), 'SBX Guru Lama sudah nonaktif') && (int) $satu('SELECT COUNT(*) FROM v4_rencana WHERE kelas_id=' . $ids['kelas_baru'] . ' AND tahun_ajaran_id=' . $ids['tahun']) === 0, 'R-1: salin admin mengisi formulir tanpa menyimpan; guru nonaktif ditandai');
$h = $adm->req('/portal/rencana_nilai.php?tahun_ajaran_id=' . $ids['tahun'] . '&kelas_id=' . $ids['kelas'])['body'];
$assert(str_contains($teks($h), 'Terkunci · sudah ada nilai') && !str_contains($h, 'name="mata_pelajaran_id[]"'), 'R-1: rencana bernilai tampil terkunci (sesuai aturan layanan)');
$pd = $ids['penanda'];
$tok = $adm->csrf('/portal/rapor_penanda.php');
$v = (int) $satu("SELECT version FROM v4_rencana WHERE id=$rencana");
$adm->req('/portal/rapor_penanda.php', ['_csrf' => $tok, 'rencana_id' => $rencana, 'version' => $v, 'pendidikan_pasangan' => $pd['pendidikan']['penanda'] . ':' . $pd['pendidikan']['aset'], 'pimpinan_pasangan' => $pd['pimpinan']['penanda'] . ':' . $pd['pimpinan']['aset'], 'tempat' => 'Tasikmalaya', 'alasan' => '']);
$assert((int) $satu("SELECT version FROM v4_rencana WHERE id=$rencana") === $v, 'R-7: ganti penanda tanpa alasan ditolak');
$adm->req('/portal/rapor_penanda.php', ['_csrf' => $tok, 'rencana_id' => $rencana, 'version' => $v, 'pendidikan_pasangan' => $pd['pendidikan']['penanda'] . ':' . $pd['pendidikan']['aset'], 'pimpinan_pasangan' => $pd['pimpinan']['penanda'] . ':' . $pd['pimpinan']['aset'], 'tempat' => 'Tasikmalaya', 'alasan' => 'Upacara pindah (uji)']);
$h = $teks($adm->req('/portal/rapor_penanda.php?rencana_id=' . $rencana)['body']);
$assert((int) $satu("SELECT version FROM v4_rencana WHERE id=$rencana") === $v + 1 && str_contains($h, 'Tempat: Ciamis → Tasikmalaya') && str_contains($h, 'SBX Administrator (admin)'), 'R-7: perubahan beralasan tercatat sebagai kalimat dengan nama');
$r = $pend->req('/portal/rapor_penanda.php', ['_csrf' => $pend->csrf(), 'action' => 'signer_status', 'id' => $pd['pendidikan']['penanda'], 'is_active' => '0']);
$assert($r['status'] === 403 && (int) $satu('SELECT is_active FROM v4_penanda_tangan WHERE id=' . $pd['pendidikan']['penanda']) === 1, 'R-7: Pendidikan tidak dapat menonaktifkan penanda (403)');
$r = $adm->req('/admin/admin_penugasan.php?jenis=mata_pelajaran&q=Fiq');
$assert($r['status'] === 301 && str_contains((string) $r['location'], '/admin/admin_mata_pelajaran.php?q=Fiq'), 'M-5: alamat tab lama dialihkan 301');
$tok = $adm->csrf('/admin/admin_mata_pelajaran.php');
$adm->req('/admin/admin_mata_pelajaran.php', ['_csrf' => $tok, 'action' => 'mapel_simpan', 'nama' => 'Balaghah Uji', 'kode' => 'SBX-BLG', 'kategori' => 'Diniyah']);
$baru = (int) $satu("SELECT id FROM mata_pelajaran WHERE kode='SBX-BLG'");
$assert($baru > 0 && (int) $satu("SELECT COUNT(*) FROM audit_logs WHERE action='mata_pelajaran.buat' AND entity_id=$baru") === 1, 'M-5: tambah mapel lewat layanan dengan audit');
$r = $adm->req('/admin/admin_penugasan.php?jenis=mata_pelajaran', ['_csrf' => $tok, 'jenis' => 'mata_pelajaran', 'action' => 'mapel_status', 'id' => $baru, 'status_action' => 'deactivate']);
$assert($r['status'] === 302 && str_contains((string) $r['location'], 'admin_mata_pelajaran.php') && (int) $satu("SELECT is_active FROM mata_pelajaran WHERE id=$baru") === 0, 'M-5: POST lama di Pusat Penugasan tetap diproses lalu kembali ke M-5');
$h = $teks($adm->req('/admin/admin_mata_pelajaran.php?state=all&kategori=Diniyah')['body']);
$assert(str_contains($h, 'Balaghah Uji') && str_contains($h, 'Nonaktif') && preg_match('/Fiqih Diniyah 1 guru/', $h) === 1, 'M-5: saringan kategori/status; kolom guru pengampu semester aktif');
$ortu = $k['sbx_t7_ortu'];
$h = $ortu->req('/portal/rapor_wali.php?id=' . $ids['rapor'])['body'];
$assert(substr_count($h, 'class="ah-rapor-doc"') === 1 && str_contains($teks($h), 'menggantikan versi 1') && str_contains($h, 'version=2') && !str_contains($h, 'version=1&'), 'R-8: hanya versi terbaru dengan catatan menggantikan; unduhan versi terbaru');
$h = $ortu->req('/portal/rapor_wali.php?id=' . $ids['rapor'] . '&versi=1')['body'];
$assert(str_contains($teks($h), 'Sudah diganti') && !str_contains($teks($h), 'Rata-rata nilai'), 'R-8: versi sebelumnya bertanda "Sudah diganti" tanpa isi');
$assert($ortu->req('/portal/rapor_pdf.php?id=' . $ids['rapor'] . '&version=1')['status'] === 403, 'R-8: PDF versi lama tetap tidak dapat diunduh orang tua (aturan layanan)');
$r = $k['sbx_ortu_a']->req('/portal/rapor_wali.php?id=' . $ids['rapor']);
$assert($r['status'] === 403 && !str_contains($r['body'], 'SBX T7 Santri 01'), 'R-8: wali lain tidak dapat membuka rapor anak orang lain (403, tanpa nama)');

// ===================================================================== H7-G
echo PHP_EOL . '=== H7-G. Kompatibilitas POST lama, GET, CSRF ===' . PHP_EOL;
$alamat = '/portal/nilai_semester.php?rencana_mapel_id=' . $nahwu . '&santri_id=' . $S[29];
$guru->req('/portal/nilai_semester.php', ['_csrf' => $guru->csrf($alamat), 'pengganti' => '0', 'rencana_mapel_id' => $nahwu, 'santri_id' => $S[29], 'action' => 'draf',
    'komponen' => [['komponen' => 'Harian', 'nomor_harian' => 1, 'version' => 0, 'nilai' => '88', 'catatan' => 'Bentuk lama'], ['komponen' => 'Tengah', 'nomor_harian' => 0, 'version' => 0, 'nilai' => '', 'catatan' => '']]]);
$assert($satu("SELECT catatan FROM v4_nilai WHERE rencana_mapel_id=$nahwu AND santri_id={$S[29]}") === 'Bentuk lama', 'Nilai: bentuk POST lama per santri (komponen[...]) tetap diterima');
$pa->req('/portal/sikap_semester.php', ['_csrf' => $pa->csrf('/portal/sikap_semester.php'), 'mode' => 'pembimbing', 'rencana_id' => $rencana, 'santri_id' => $S[23], 'version' => 0, 'adab' => 'Cukup', 'kedisiplinan' => 'Cukup', 'tanggung_jawab' => 'Cukup', 'catatan' => 'Bentuk lama', 'action' => 'draf']);
$assert($satu("SELECT catatan FROM v4_sikap WHERE santri_id={$S[23]}") === 'Bentuk lama', 'Sikap: bentuk POST lama per santri tetap diterima');
$r = $pend->req('/portal/rapor_semester.php', ['_csrf' => $pend->csrf(), 'rencana_id' => $rencana, 'santri_id' => $S[5], 'catatan_umum' => 'Muat pratinjau lama']);
$assert($r['status'] === 302 && str_contains((string) $r['location'], 'santri_id=' . $S[5]), 'Penerbitan: POST lama "Muat pratinjau" membuka panel santri');
$tafsirAset = $pd['pendidikan'];
$r = $adm->req('/portal/rencana_nilai.php', ['_csrf' => $adm->csrf(), 'action' => 'signer_status', 'id' => $pd['lama']['penanda'], 'is_active' => '1']);
$assert((int) $satu('SELECT is_active FROM v4_penanda_tangan WHERE id=' . $pd['lama']['penanda']) === 1, 'Rencana: POST lama status penanda tangan tetap diterima');
$sebelumNilai = (int) $satu('SELECT COUNT(*) FROM v4_nilai');
$sebelumBahan = (int) $satu('SELECT COALESCE(SUM(version),0) FROM v4_bahan');
$sebelumRapor = (int) $satu('SELECT COUNT(*) FROM v4_rapor_versi');
foreach (['/portal/nilai_semester.php?aksi=serahkan_lengkap&rencana_mapel_id=' . $fiqih . '&pilih=' . urlencode($pilih[0] ?? ''),
    '/portal/verifikasi_semester.php?aksi=verifikasi_massal&rencana_id=' . $rencana . '&action=verifikasi',
    '/portal/rapor_semester.php?aksi=terbit_massal&rencana_id=' . $rencana . '&pilih=' . urlencode($siap[4] ?? ''),
    '/portal/sikap_semester.php?aksi=kisi&s[1:1][adab]=Baik'] as $get) {
    $pend->req($get);
    $guru->req($get);
}
$assert((int) $satu('SELECT COUNT(*) FROM v4_nilai') === $sebelumNilai && (int) $satu('SELECT COALESCE(SUM(version),0) FROM v4_bahan') === $sebelumBahan && (int) $satu('SELECT COUNT(*) FROM v4_rapor_versi') === $sebelumRapor, 'GET dengan parameter tindakan tidak memutasi');
$r = $guru->req('/portal/nilai_semester.php', ['_csrf' => 'salah', 'aksi' => 'kisi', 'rencana_mapel_id' => $fiqih, 'n' => [$S[30] => ['Harian:1' => '60']]]);
$assert($r['status'] >= 400 && (int) $satu("SELECT COUNT(*) FROM v4_nilai WHERE santri_id={$S[30]}") === 0, 'CSRF salah ditolak tanpa perubahan');

// ===================================================================== H7-H
echo PHP_EOL . '=== H7-H. Nama bukan nomor, log server ===' . PHP_EOL;
$semua = '';
foreach ([[$guru, '/portal/nilai_semester.php?rencana_mapel_id=' . $fiqih . '&santri_id=' . $S[13]], [$pend, '/portal/verifikasi_semester.php?rencana_id=' . $rencana . '&santri_id=' . $S[9] . '&bahan=sikap:0'],
    [$pend, '/portal/rapor_semester.php?rencana_id=' . $rencana . '&santri_id=' . $S[1]], [$adm, '/portal/rapor_penanda.php?rencana_id=' . $rencana], [$pa, '/portal/sikap_semester.php?rencana_id=' . $rencana . '&santri_id=' . $S[11]]] as [$kl, $al]) {
    $semua .= $teks($main($kl->req($al)['body']));
}
$assert(!preg_match('/(pelaku|Admin|aset|murobi|admin|pendidikan|guru|pembimbing) #\d+/i', $semua) && str_contains($semua, 'SBX Guru Mapel (guru)') && str_contains($semua, 'SBX Bagian Pendidikan'), 'Riwayat menyebut nama pelaku, bukan nomor ID');
$isi = (string) file_get_contents($log);
preg_match_all('/PHP (Warning|Notice|Deprecated|Fatal error|Parse error)[^\n]*/', $isi, $m);
$assert($m[0] === [], 'Server tanpa galat/peringatan PHP' . ($m[0] === [] ? '' : ': ' . implode(' | ', array_slice(array_unique($m[0]), 0, 3))));

echo PHP_EOL . 'Ringkasan: ' . $lulus . ' lulus, ' . count($gagal) . ' gagal' . PHP_EOL;
foreach ($gagal as $g) {
    echo '  - ' . $g . PHP_EOL;
}
exit($gagal === [] ? 0 : 1);
