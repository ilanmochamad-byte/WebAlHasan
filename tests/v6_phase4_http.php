<?php

declare(strict_types=1);

/**
 * Uji server PRD V6 Fase 4 — paritas aplikasi dan orang tua — lewat API yang
 * dipakai aplikasi Expo dan halaman web yang sama, di atas DB uji
 * `tests/v6_phase4_db.sh`. Server PHP lokal dengan beberapa pekerja
 * (`PHP_CLI_SERVER_WORKERS`) agar permintaan serentak benar-benar paralel.
 *
 *   V6F4_DB=test_v6_f4_http_test bash tests/v6_phase4_db.sh
 *   V6F4_DB=test_v6_f4_http_test php tests/v6_phase4_http.php
 *
 *   N-A  matriks hak API/web: petugas seluruh unit, petugas Tsanawi, admin,
 *        admin+petugas, wali satu anak (dua wali anak berbeda), wali dua
 *        anak, wali tanpa relasi, pengurus tanpa penugasan, token rusak;
 *   N-B  paritas: transaksi lewat API (aplikasi) terlihat sekali dengan angka
 *        identik di website petugas dan wali; retry kunci sama tidak
 *        menggandakan setoran, penarikan, tagihan, pembayaran, bayar dari
 *        tabungan, atau penerbitan massal; permintaan serentak kunci sama;
 *   N-C  konflik versi (409), validasi (422), sesi kedaluwarsa (401);
 *   N-D  pemberitahuan umum: InApp per wali aktif untuk tagihan terbit,
 *        pembayaran sah/dibatalkan, transaksi tabungan sah; tidak untuk
 *        antrean menunggu/ditolak; replay tidak mengantre ulang; teks tanpa
 *        nominal/nama/kuitansi; data hanya satu penunjuk; tautan
 *        /notifikasi dan halaman web; penunjuk dibuka wali lain → 404;
 *   N-E  Push: baris Push hanya untuk wali berperangkat bila sakelar menyala;
 *        worker (klien Expo palsu) mengirim teks generik + penunjuk saja,
 *        dan menolak baris yang relasinya dicabut atau keadaannya berubah;
 *   N-F  pencabutan saat sesi/token lama masih aktif: relasi wali satu anak
 *        dan penugasan petugas;
 *   N-G  privasi wali: daftar pembayaran dan tunggakan tanpa transfer
 *        menunggu (total dan jumlah); log server dan audit tanpa referensi,
 *        token, atau nominal.
 *
 * Mengubah data uji; bangun ulang DB sebelum mengulang.
 */

$root = dirname(__DIR__);
$dbName = getenv('V6F4_DB') ?: 'test_v6_f4_http_test';
// Port sendiri (8993 dipakai probe audit Fase 2).
$port = (int) (getenv('V6F4_PORT') ?: 8979);
if (!preg_match('/^test_.*_test$/', $dbName)) {
    fwrite(STDERR, "Ditolak: DB uji harus bernama test_*_test.\n");
    exit(2);
}
putenv('DB_NAME=' . $dbName);
require_once $root . '/app/bootstrap.php';

use App\Bsa\BsaNotifikasi;
use App\Notification\DeviceRepository;
use App\Notification\NotificationDispatcher;
use App\Notification\OutboxRepository;
use App\Notification\PushTokenProtector;
use App\Notification\SettingsRepository;
use App\Notification\WhatsApp\ProviderResult;
use App\Notification\WhatsApp\WhatsAppMessage;
use App\Notification\WhatsApp\WhatsAppProvider;
use App\Notification\WorkerLock;

$lulus = 0;
$gagal = [];
$assert = static function (bool $ok, string $pesan) use (&$lulus, &$gagal): void {
    echo ($ok ? '[lulus] ' : '[gagal] ') . $pesan . PHP_EOL;
    $ok ? $lulus++ : $gagal[] = $pesan;
};

$log = getenv('V6F4_LOG') ?: tempnam(sys_get_temp_dir(), 'v6f4-server-');
$server = proc_open(['php', '-S', '127.0.0.1:' . $port, $root . '/tests/redesain_web_tahap1_router.php'],
    [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, $root,
    array_merge(getenv(), ['DB_NAME' => $dbName, 'PHP_CLI_SERVER_WORKERS' => '4']));
for ($i = 0; $i < 50; $i++) {
    $sock = @fsockopen('127.0.0.1', $port);
    if ($sock !== false) {
        fclose($sock);
        break;
    }
    usleep(100000);
}
register_shutdown_function(static function () use ($server, $port): void {
    if (is_resource($server)) {
        proc_terminate($server);
    }
    // Pekerja PHP_CLI_SERVER_WORKERS tidak ikut berhenti bersama induknya.
    shell_exec('pkill -f ' . escapeshellarg('php -S 127.0.0.1:' . $port . ' '));
});

final class Klien
{
    private string $jar;

    public function __construct(private string $base)
    {
        $this->jar = tempnam(sys_get_temp_dir(), 'v6f4-jar-');
    }

    /** @return array{status:int, body:string, location:?string, header:string} */
    public function req(string $path, ?array $post = null, array $headers = [], ?string $raw = null, string $method = ''): array
    {
        $h = curl_init($this->base . $path);
        curl_setopt_array($h, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar, CURLOPT_TIMEOUT => 60, CURLOPT_HTTPHEADER => $headers]);
        if ($post !== null) {
            curl_setopt($h, CURLOPT_POST, true);
            curl_setopt($h, CURLOPT_POSTFIELDS, http_build_query($post));
        }
        if ($raw !== null) {
            curl_setopt($h, CURLOPT_POST, true);
            curl_setopt($h, CURLOPT_POSTFIELDS, $raw);
        }
        if ($method !== '') {
            curl_setopt($h, CURLOPT_CUSTOMREQUEST, $method);
        }
        $out = (string) curl_exec($h);
        $status = (int) curl_getinfo($h, CURLINFO_RESPONSE_CODE);
        $size = (int) curl_getinfo($h, CURLINFO_HEADER_SIZE);
        curl_close($h);
        preg_match('/^Location:\s*(.+)$/mi', substr($out, 0, $size), $m);

        return ['status' => $status, 'body' => substr($out, $size), 'location' => isset($m[1]) ? trim($m[1]) : null, 'header' => substr($out, 0, $size)];
    }

    public function login(string $user): void
    {
        $r = $this->req('/portal/index.php');
        preg_match('/name="_csrf" value="([^"]+)"/', $r['body'], $m);
        $this->req('/admin/cek_login.php', ['_csrf' => $m[1] ?? '', 'username' => $user, 'password' => 'Sandbox#123']);
    }
}

$base = 'http://127.0.0.1:' . $port;
$web = static function (string $user) use ($base): Klien {
    $k = new Klien($base);
    $k->login($user);

    return $k;
};
$api = static function (string $method, string $path, ?string $token, ?array $body = null) use ($base): array {
    $k = new Klien($base);
    $h = array_merge(['Accept: application/json', 'Content-Type: application/json'], $token === null ? [] : ['Authorization: Bearer ' . $token]);
    $r = $k->req('/api/v1' . $path, null, $h, $body === null ? null : (string) json_encode($body), $method === 'GET' ? '' : $method);
    $j = json_decode($r['body'], true);

    return ['status' => $r['status'], 'data' => $j['data'] ?? null, 'error' => $j['error'] ?? null, 'header' => $r['header']];
};
/** Beberapa permintaan API identik serentak (curl_multi) terhadap server multi-pekerja. */
$serentak = static function (int $n, string $path, string $token, array $body) use ($base): array {
    $mh = curl_multi_init();
    $hs = [];
    for ($i = 0; $i < $n; $i++) {
        $h = curl_init($base . '/api/v1' . $path);
        curl_setopt_array($h, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($body), CURLOPT_TIMEOUT => 60,
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json', 'Authorization: Bearer ' . $token]]);
        curl_multi_add_handle($mh, $h);
        $hs[] = $h;
    }
    do {
        curl_multi_exec($mh, $aktif);
        curl_multi_select($mh);
    } while ($aktif > 0);
    $kode = [];
    foreach ($hs as $h) {
        $kode[] = (int) curl_getinfo($h, CURLINFO_RESPONSE_CODE);
        curl_multi_remove_handle($mh, $h);
    }
    curl_multi_close($mh);
    sort($kode);

    return $kode;
};
$token = static function (string $user) use ($api): string {
    return (string) ($api('POST', '/auth/login', null, ['username' => $user, 'password' => 'Sandbox#123', 'device_name' => 'uji-v6-f4'])['data']['token'] ?? '');
};
$conn = app_db();
$satu = static function (string $sql) use ($conn): mixed {
    $r = $conn->query($sql);
    if ($r === false) {
        throw new RuntimeException($conn->error . ' :: ' . $sql);
    }

    return $r->fetch_row()[0] ?? null;
};
$semua = static function (string $sql) use ($conn): array {
    $r = $conn->query($sql);
    if ($r === false) {
        throw new RuntimeException($conn->error . ' :: ' . $sql);
    }

    return $r->fetch_all(MYSQLI_ASSOC);
};
$jalankan = static function (string $sql) use ($conn): void {
    if ($conn->query($sql) === false) {
        throw new RuntimeException($conn->error . ' :: ' . $sql);
    }
};
/** Jumlah baris tabel (<tr>) yang memuat teks — bukan dialog/template tersembunyi. */
$barisTabel = static fn (string $html, string $cari): int => count(array_filter(preg_match_all('#<tr\b.*?</tr>#s', $html, $m) ? $m[0] : [], static fn (string $tr): bool => str_contains($tr, $cari)));
$teks = static fn (string $html): string => trim(html_entity_decode((string) preg_replace('/\s+/', ' ', (string) preg_replace('/<[^>]+>/', ' ', (string) preg_replace('#<(script|style|template)\b.*?</\1>#s', '', $html))), ENT_QUOTES));
$n = 0;
$kunci = static function (string $p) use (&$n): string {
    $n++;

    return 'v6f4-' . $p . '-' . $n . '-' . bin2hex(random_bytes(4));
};
$uid = static fn (string $u): int => (int) $satu("SELECT id FROM users WHERE username='$u'");
$sid = static fn (string $nis): int => (int) $satu("SELECT id FROM santri WHERE nis='$nis'");
$sSmp = $sid('V6S0001');
$sTs = $sid('V6S0002');
$sTanpa = $sid('V6S0003');
$sNonaktif = $sid('V6S0004');
$wSmp = $uid('sbx_v6_wali_smp');
$wTs = $uid('sbx_v6_wali_tsanawi');
$wDua = $uid('sbx_v6_wali_dua');
$saldo = static fn (int $s): int => (int) $satu("SELECT COALESCE(MAX(saldo), 0) FROM bsa_tabungan_akun WHERE santri_id = $s");
$outbox = static fn (string $where): int => (int) $satu("SELECT COUNT(*) FROM notifikasi_outbox WHERE event_type LIKE 'v6\\_bsa\\_%' AND ($where)");
$mulaiAudit = (int) $satu('SELECT COALESCE(MAX(id), 0) FROM audit_logs');

$awal = (int) $satu('SELECT COUNT(*) FROM bsa_tabungan_transaksi') + (int) $satu('SELECT COUNT(*) FROM bsa_tagihan') + (int) $satu("SELECT COUNT(*) FROM notifikasi_outbox WHERE event_type LIKE 'v6%'");
$assert(min($sSmp, $sTs, $sTanpa, $sNonaktif, $wDua) > 0 && $awal === 0, 'Fixture V6 Fase 1–4 tersedia (wali dua anak); ledger, tagihan, dan antrean V6 kosong');
$jalankan('UPDATE pengaturan_notifikasi SET push_enabled = 0 WHERE singleton = 1');

// Token "aplikasi" diambil SEKALI di awal; dipakai lagi sesudah pencabutan.
$T = [];
foreach (['sbx_v6_bsa_b', 'sbx_v6_bsa_tsanawi', 'sbx_admin', 'sbx_v6_admin_bsa', 'sbx_v6_wali_smp', 'sbx_v6_wali_tsanawi', 'sbx_v6_wali_dua', 'sbx_ortu_a', 'sbx_pengurus_biasa'] as $u) {
    $T[$u] = $token($u);
}
$assert(!in_array('', $T, true), 'Login API sembilan akun uji berhasil (token terpisah per akun)');
$P = $T['sbx_v6_bsa_b'];

// ===========================================================================
// N-A  Matriks hak
// ===========================================================================
$harap = [
    'sbx_v6_bsa_b' => ['baca' => true, 'kelola' => true, 'sumber' => 'petugas'],
    'sbx_v6_bsa_tsanawi' => ['baca' => true, 'kelola' => true, 'sumber' => 'petugas'],
    'sbx_admin' => ['baca' => true, 'kelola' => false, 'sumber' => 'admin'],
    'sbx_v6_admin_bsa' => ['baca' => true, 'kelola' => false, 'sumber' => 'admin_petugas'],
    'sbx_v6_wali_smp' => ['baca' => true, 'kelola' => false, 'sumber' => 'wali'],
    'sbx_v6_wali_dua' => ['baca' => true, 'kelola' => false, 'sumber' => 'wali'],
    // Wali V1 (anak bukan fixture V6): sumber wali, tetapi hanya anaknya sendiri.
    'sbx_ortu_a' => ['baca' => true, 'kelola' => false, 'sumber' => 'wali'],
    'sbx_pengurus_biasa' => ['baca' => false, 'kelola' => false, 'sumber' => null],
];
foreach ($harap as $u => $h) {
    $r = $api('GET', '/v6/bsa/akses', $T[$u]);
    $assert($r['status'] === 200 && array_intersect_key($r['data'] ?? [], $h) == $h && str_contains($r['header'], 'no-store'), "$u: akses " . json_encode($h));
}
$idsAnak = static function (string $u) use ($api, $T): array {
    $r = $api('GET', '/v6/bsa/santri', $T[$u]);
    $ids = array_map(static fn (array $s): int => $s['id'], $r['data']['rows'] ?? []);
    sort($ids);

    return [$r['status'], $ids];
};
$assert($idsAnak('sbx_v6_wali_dua') === [200, [$sSmp, $sTs]], 'Wali dua anak: daftar memuat tepat dua anaknya');
$assert($idsAnak('sbx_v6_wali_smp') === [200, [$sSmp]] && $idsAnak('sbx_v6_wali_tsanawi') === [200, [$sTs]], 'Dua wali dari santri berbeda: masing-masing hanya anaknya');
$assert(array_intersect($idsAnak('sbx_ortu_a')[1], [$sSmp, $sTs, $sTanpa, $sNonaktif]) === [] && $idsAnak('sbx_pengurus_biasa')[0] === 403,
    'Wali tanpa relasi santri V6: daftar tanpa santri V6; pengurus tanpa penugasan → 403');
$matriks = [
    ['sbx_v6_wali_smp', $sTs, 404], ['sbx_v6_wali_tsanawi', $sSmp, 404], ['sbx_v6_wali_dua', $sTanpa, 404],
    ['sbx_v6_bsa_tsanawi', $sSmp, 404], ['sbx_ortu_a', $sSmp, 404], ['sbx_pengurus_biasa', $sSmp, 403], ['sbx_v6_bsa_tsanawi', $sTs, 200], ['sbx_admin', $sTanpa, 200], ['sbx_v6_wali_dua', $sTs, 200],
];
foreach ($matriks as [$u, $s, $kode]) {
    $assert($api('GET', "/v6/bsa/santri/$s", $T[$u])['status'] === $kode, "$u membaca santri $s → $kode");
}
$assert($api('GET', '/v6/bsa/akses', 'token-rusak-0000')['status'] === 401, 'Token rusak/kedaluwarsa → 401 (aplikasi meminta masuk ulang)');

// ===========================================================================
// N-B  Paritas dan retry
// ===========================================================================
$k1 = $kunci('setor');
$badan = ['idempotency_key' => $k1, 'nominal' => '100.000', 'metode' => 'Tunai', 'catatan' => 'Uji aplikasi'];
$r1 = $api('POST', "/v6/bsa/santri/$sSmp/setoran", $P, $badan);
$r2 = $api('POST', "/v6/bsa/santri/$sSmp/setoran", $P, $badan);
$r3 = $api('POST', "/v6/bsa/santri/$sSmp/setoran", $P, ['nominal' => 100001] + $badan);
$assert($r1['status'] === 201 && $r2['status'] === 200 && $r2['data']['replayed'] === true && $r1['data']['transaksi_id'] === $r2['data']['transaksi_id'] && $r3['status'] === 409
    && $saldo($sSmp) === 100000 && (int) $satu("SELECT COUNT(*) FROM bsa_tabungan_transaksi WHERE santri_id=$sSmp") === 1,
    'Setoran aplikasi + retry jaringan (kunci sama) → satu kredit; isi berbeda kunci sama → 409');
$kuitansiSetor = (string) $r1['data']['nomor_kuitansi'];
$kode = $serentak(4, "/v6/bsa/santri/$sSmp/penarikan", $P, ['idempotency_key' => $kunci('belanja'), 'nominal' => 30000, 'metode' => 'Tunai', 'jenis' => 'Belanja', 'tujuan' => 'Kitab uji']);
$assert(count(array_filter($kode, static fn (int $c): bool => in_array($c, [200, 201], true))) === 4 && in_array(201, $kode, true)
    && (int) $satu("SELECT COUNT(*) FROM bsa_tabungan_transaksi WHERE santri_id=$sSmp AND jenis='Belanja'") === 1 && $saldo($sSmp) === 70000,
    'Empat ketukan belanja serentak kunci sama (4 pekerja) → satu belanja, saldo Rp70.000 [' . implode(',', $kode) . ']');
$pw = $web('sbx_v6_bsa_b');
$halMentah = $pw->req("/portal/bsa_santri.php?id=$sSmp")['body'];
$hal = $teks($halMentah);
$wd = $web('sbx_v6_wali_dua');
$halWMentah = $wd->req("/portal/bsa_santri.php?id=$sSmp")['body'];
$halW = $teks($halWMentah);
$apiW = $api('GET', "/v6/bsa/santri/$sSmp", $T['sbx_v6_wali_dua'])['data'];
$assert($barisTabel($halMentah, $kuitansiSetor) === 1 && str_contains($hal, 'Rp70.000') && str_contains($hal, 'Rp100.000') && str_contains($halW, 'Rp70.000')
    && $barisTabel($halWMentah, $kuitansiSetor) === 1 && $apiW['saldo'] === 70000 && count($apiW['tabungan']['rows']) === 2,
    'Transaksi aplikasi tampil sekali dengan angka identik di website petugas dan wali (saldo Rp70.000, kuitansi satu kali)');

$kT = $kunci('tagihan');
$bT = ['idempotency_key' => $kT, 'santri_id' => $sSmp, 'periode' => '2026-10', 'jatuh_tempo' => '2026-10-31', 'komponen' => [['nama' => 'Syahriah', 'nominal' => 100000]]];
$t1 = $api('POST', '/v6/bsa/tagihan', $P, $bT);
$t2 = $api('POST', '/v6/bsa/tagihan', $P, $bT);
$tid = (int) ($t1['data']['id'] ?? 0);
$assert($t1['status'] === 201 && $t2['status'] === 200 && $t2['data']['id'] === $tid && (int) $satu("SELECT COUNT(*) FROM bsa_tagihan WHERE santri_id=$sSmp") === 1, 'Tagihan aplikasi + retry → satu tagihan');
$kB = $kunci('bayartab');
$kode = $serentak(3, "/v6/bsa/tagihan/$tid/bayar-tabungan", $P, ['idempotency_key' => $kB, 'nominal' => 50000, 'setuju' => true]);
$assert($kode === [200, 200, 201] && (int) $satu("SELECT COUNT(*) FROM bsa_pembayaran WHERE tagihan_id=$tid AND sumber='Tabungan'") === 1
    && (int) $satu("SELECT COUNT(*) FROM bsa_tabungan_transaksi WHERE santri_id=$sSmp AND jenis='PembayaranTagihan'") === 1 && $saldo($sSmp) === 20000
    && (int) $satu("SELECT terbayar FROM bsa_tagihan WHERE id=$tid") === 50000,
    'Bayar dari tabungan diketuk tiga kali serentak → satu debit + satu pembayaran, saldo Rp20.000, sisa Rp50.000 [' . implode(',', $kode) . ']');
$tagW = $api('GET', "/v6/bsa/tagihan/$tid", $T['sbx_v6_wali_dua'])['data'];
$halTagMentah = $pw->req("/portal/bsa_tagihan.php?id=$tid")['body'];
$halTag = $teks($halTagMentah);
$kwTab = (string) $satu("SELECT nomor_kuitansi FROM bsa_pembayaran WHERE tagihan_id=$tid");
$assert($tagW['sisa'] === 50000 && count($tagW['pembayaran']) === 1 && $tagW['pembayaran'][0]['tabungan'] !== null && $barisTabel($halTagMentah, $kwTab) === 1 && str_contains($halTag, 'Rp50.000'),
    'Pembayaran dari tabungan sama di API wali dan web petugas (sisa Rp50.000, kuitansi sekali, rujukan debit)');
$kTunai = $kunci('bayar');
$b1 = $api('POST', "/v6/bsa/tagihan/$tid/pembayaran", $P, ['idempotency_key' => $kTunai, 'nominal' => 20000, 'sumber' => 'Tunai', 'tanggal_transaksi' => date('Y-m-d')]);
$b2 = $api('POST', "/v6/bsa/tagihan/$tid/pembayaran", $P, ['idempotency_key' => $kTunai, 'nominal' => 20000, 'sumber' => 'Tunai', 'tanggal_transaksi' => date('Y-m-d')]);
$lebih = $api('POST', "/v6/bsa/tagihan/$tid/pembayaran", $P, ['idempotency_key' => $kunci('lebih'), 'nominal' => 30001, 'sumber' => 'Tunai', 'tanggal_transaksi' => date('Y-m-d')]);
$assert($b1['status'] === 201 && $b2['data']['replayed'] === true && $b1['data']['nomor_kuitansi'] === $b2['data']['nomor_kuitansi'] && $lebih['status'] === 422
    && (int) $satu("SELECT COUNT(*) FROM bsa_pembayaran WHERE tagihan_id=$tid") === 2 && (int) $satu("SELECT terbayar FROM bsa_tagihan WHERE id=$tid") === 70000,
    'Pembayaran tunai aplikasi + retry → satu kuitansi; melebihi sisa → 422 tanpa mutasi');
$bayarTunai = (int) $b1['data']['id'];

// Penerbitan massal: pratinjau → terbit; retry kunci sama → replay; kunci baru → tidak ada tagihan baru.
$tarif = $api('POST', '/v6/bsa/tarif', $P, ['idempotency_key' => $kunci('tarif'), 'unit' => 'Tsanawi', 'berlaku_mulai' => '2026-11', 'komponen' => [['nama' => 'Syahriah', 'nominal' => 120000]], 'alasan' => 'Tarif uji Fase 4']);
$pra = $api('POST', '/v6/bsa/penerbitan/pratinjau', $P, ['periode' => '2026-11', 'jatuh_tempo' => '2026-11-15', 'unit' => 'Tsanawi']);
$kM = $kunci('massal');
$m1 = $api('POST', '/v6/bsa/penerbitan', $P, ['idempotency_key' => $kM, 'periode' => '2026-11', 'jatuh_tempo' => '2026-11-15', 'unit' => 'Tsanawi', 'pratinjau_hash' => $pra['data']['hash'] ?? '']);
$m2 = $api('POST', '/v6/bsa/penerbitan', $P, ['idempotency_key' => $kM, 'periode' => '2026-11', 'jatuh_tempo' => '2026-11-15', 'unit' => 'Tsanawi', 'pratinjau_hash' => $pra['data']['hash'] ?? '']);
$jumlahTs = (int) $satu("SELECT COUNT(*) FROM bsa_tagihan WHERE periode='2026-11'");
$pra2 = $api('POST', '/v6/bsa/penerbitan/pratinjau', $P, ['periode' => '2026-11', 'jatuh_tempo' => '2026-11-15', 'unit' => 'Tsanawi']);
$m3 = $api('POST', '/v6/bsa/penerbitan', $P, ['idempotency_key' => $kunci('massal'), 'periode' => '2026-11', 'jatuh_tempo' => '2026-11-15', 'unit' => 'Tsanawi', 'pratinjau_hash' => $pra2['data']['hash'] ?? '']);
$assert($tarif['status'] === 201 && $m1['status'] === 201 && $m1['data']['jumlah_terbit'] >= 1 && $m2['data']['replayed'] === true && $m3['status'] === 422
    && (int) $satu("SELECT COUNT(*) FROM bsa_tagihan WHERE periode='2026-11'") === $jumlahTs && $jumlahTs === $m1['data']['jumlah_terbit'],
    "Penerbitan massal aplikasi: pratinjau → $jumlahTs tagihan; retry kunci sama → replay; ulang periode → 422 tanpa tagihan kedua");
$tidTs = (int) $satu("SELECT id FROM bsa_tagihan WHERE santri_id=$sTs AND periode='2026-11'");

// ===========================================================================
// N-C  Konflik versi, validasi, admin/wali tidak memutasi
// ===========================================================================
$rv1 = $api('POST', "/v6/bsa/tagihan/$tidTs/revisi", $P, ['idempotency_key' => $kunci('rev'), 'versi' => 1, 'jatuh_tempo' => '2026-11-20', 'alasan' => 'Perpanjang uji']);
$rv2 = $api('POST', "/v6/bsa/tagihan/$tidTs/revisi", $T['sbx_v6_bsa_tsanawi'], ['idempotency_key' => $kunci('rev'), 'versi' => 1, 'jatuh_tempo' => '2026-11-25', 'alasan' => 'Layar lama uji']);
$assert($rv1['status'] === 200 && $rv2['status'] === 409 && ($rv2['error']['code'] ?? '') === 'CONFLICT' && str_contains((string) ($rv2['error']['message'] ?? ''), 'muat ulang')
    && (string) $satu("SELECT jatuh_tempo FROM bsa_tagihan WHERE id=$tidTs") === '2026-11-20',
    'Dua petugas mengubah versi yang sama: kedua → 409 berpesan "muat ulang", tanpa menimpa');
$v = $api('POST', "/v6/bsa/santri/$sSmp/setoran", $P, ['idempotency_key' => $kunci('v'), 'nominal' => '10,5', 'metode' => 'Tunai']);
$v2 = $api('POST', "/v6/bsa/santri/$sSmp/setoran", $P, ['idempotency_key' => $kunci('v'), 'nominal' => 1000, 'metode' => 'Transfer']);
$assert($v['status'] === 422 && $v2['status'] === 422 && $saldo($sSmp) === 20000, 'Nominal pecahan dan transfer tanpa referensi → 422 tanpa mutasi');
foreach (['sbx_admin', 'sbx_v6_admin_bsa', 'sbx_v6_wali_dua'] as $u) {
    $r = $api('POST', "/v6/bsa/santri/$sSmp/setoran", $T[$u], ['idempotency_key' => $kunci('x'), 'nominal' => 1000, 'metode' => 'Tunai']);
    $assert($r['status'] === 403 && $saldo($sSmp) === 20000, "$u: setoran lewat API → 403 tanpa mutasi");
}
$assert($api('POST', "/v6/bsa/santri/$sTs/setoran", $T['sbx_v6_bsa_b'], ['idempotency_key' => $kunci('x'), 'nominal' => 1000, 'metode' => 'Tunai'])['status'] === 201
    && $api('POST', "/v6/bsa/santri/$sSmp/setoran", $T['sbx_v6_bsa_tsanawi'], ['idempotency_key' => $kunci('x'), 'nominal' => 1000, 'metode' => 'Tunai'])['status'] === 404,
    'Petugas seluruh unit menyetor Tsanawi (201); petugas Tsanawi atas santri SMP → 404');

// ===========================================================================
// N-D  Pemberitahuan umum
// ===========================================================================
$ev = static fn (string $event, int $id): string => "event_key = '" . BsaNotifikasi::eventKey($event, $id) . "'";
$idSetor = (int) $r1['data']['transaksi_id'];
$assert($outbox($ev(BsaNotifikasi::EVENT_TABUNGAN, $idSetor) . " AND kanal='InApp' AND penerima_user_id IN ($wSmp, $wDua)") === 2
    && $outbox($ev(BsaNotifikasi::EVENT_TABUNGAN, $idSetor)) === 2,
    'Setoran sah → tepat satu InApp untuk tiap wali aktif santri (wali SMP + wali dua anak); retry tidak mengantre ulang');
$idDebit = (int) $satu("SELECT id FROM bsa_tabungan_transaksi WHERE santri_id=$sSmp AND jenis='PembayaranTagihan'");
$idBayarTab = (int) $satu("SELECT id FROM bsa_pembayaran WHERE tagihan_id=$tid AND sumber='Tabungan'");
$assert($outbox($ev(BsaNotifikasi::EVENT_TAGIHAN, $tid)) === 2 && $outbox($ev(BsaNotifikasi::EVENT_PEMBAYARAN, $idBayarTab)) === 2
    && $outbox($ev(BsaNotifikasi::EVENT_TABUNGAN, $idDebit)) === 0 && $outbox($ev(BsaNotifikasi::EVENT_PEMBAYARAN, $bayarTunai)) === 2,
    'Tagihan terbit, bayar dari tabungan (satu pemberitahuan pembayaran, bukan dua), dan pembayaran tunai → masing-masing satu per wali');
$assert($outbox("penerima_user_id = $wTs AND (data_json LIKE '%\"tagihan_id\":$tid}%' OR event_key LIKE 'v6:bsa:tabungan:$idSetor')") === 0
    && $outbox("penerima_user_id = $wTs AND event_type = '" . BsaNotifikasi::EVENT_TAGIHAN . "'") === $outbox("event_type = '" . BsaNotifikasi::EVENT_TAGIHAN . "' AND data_json = '{\"tipe\":\"bsa_tagihan\",\"tagihan_id\":$tidTs}' AND penerima_user_id = $wTs"),
    'Wali santri lain tidak menerima pemberitahuan anak yang bukan relasinya; tagihan massal Tsanawi hanya ke wali Tsanawi');
$jumlahSebelum = $outbox('1=1');
$tr = $api('POST', "/v6/bsa/santri/$sSmp/setoran", $P, ['idempotency_key' => $kunci('tr'), 'nominal' => 15000, 'metode' => 'Transfer', 'referensi' => 'REF-RAHASIA-F4-1']);
$trB = $api('POST', "/v6/bsa/tagihan/$tid/pembayaran", $P, ['idempotency_key' => $kunci('trb'), 'nominal' => 5000, 'sumber' => 'Transfer', 'tanggal_transaksi' => date('Y-m-d'), 'referensi' => 'REF-RAHASIA-F4-2']);
$tr2 = $api('POST', "/v6/bsa/santri/$sSmp/setoran", $P, ['idempotency_key' => $kunci('tr'), 'nominal' => 7000, 'metode' => 'Transfer', 'referensi' => 'REF-RAHASIA-F4-3']);
$assert($tr['status'] === 202 && $trB['data']['status_pembayaran'] === 'Menunggu' && $outbox('1=1') === $jumlahSebelum, 'Transfer menunggu (setoran dan pembayaran) → tanpa pemberitahuan');
$tolak = $api('POST', '/v6/bsa/setoran-transfer/' . $tr2['data']['setoran_transfer_id'] . '/tolak', $P, ['idempotency_key' => $kunci('tolak'), 'alasan' => 'Dana tidak masuk uji']);
$assert($tolak['status'] === 200 && $outbox('1=1') === $jumlahSebelum, 'Setoran transfer ditolak → tanpa pemberitahuan (antrean tidak terlihat wali)');

// N-G (sebagian) privasi wali sebelum diverifikasi: transfer menunggu tidak dihitung/ditampilkan.
$dpW = $api('GET', '/v6/bsa/pembayaran', $T['sbx_v6_wali_dua'])['data'];
$dpP = $api('GET', '/v6/bsa/pembayaran?q=V6S0001', $P)['data'];
$tgW = $api('GET', '/v6/bsa/tunggakan', $T['sbx_v6_wali_dua'])['data'];
$tgP = $api('GET', '/v6/bsa/tunggakan?q=V6S0001', $P)['data'];
$menungguW = array_values(array_filter($tgW['rows'], static fn (array $r): bool => $r['tagihan_id'] === $tid));
$menungguP = array_values(array_filter($tgP['rows'], static fn (array $r): bool => $r['tagihan_id'] === $tid));
$assert($dpW['total'] === count($dpW['rows']) && !in_array('Menunggu', array_column($dpW['rows'], 'status'), true) && $dpP['total'] === $dpW['total'] + 1
    && ($menungguW[0]['menunggu_verifikasi'] ?? -1) === 0 && ($menungguP[0]['menunggu_verifikasi'] ?? 0) === 5000
    && $api('GET', '/v6/bsa/pembayaran?status=Menunggu', $T['sbx_v6_wali_dua'])['data']['total'] === $dpW['total'],
    'Wali: total daftar pembayaran = baris tanpa transfer menunggu; tunggakan wali tanpa jumlah menunggu (petugas tetap melihat Rp5.000)');
$halTW = $teks($wd->req('/portal/bsa_tunggakan.php')['body']);
$assert(!str_contains($halTW, 'Rp5.000') && !str_contains($halTW, 'REF-RAHASIA'), 'Halaman tunggakan web wali tanpa nominal transfer menunggu dan referensi');

$ver = $api('POST', '/v6/bsa/setoran-transfer/' . $tr['data']['setoran_transfer_id'] . '/verifikasi', $P, ['idempotency_key' => $kunci('ver')]);
$verB = $api('POST', '/v6/bsa/pembayaran/' . $trB['data']['id'] . '/verifikasi', $P, ['idempotency_key' => $kunci('verb')]);
$assert($ver['status'] === 200 && $outbox($ev(BsaNotifikasi::EVENT_TABUNGAN, (int) $ver['data']['transaksi_id'])) === 2 && $outbox($ev(BsaNotifikasi::EVENT_PEMBAYARAN, (int) $trB['data']['id'])) === 2,
    'Verifikasi setoran dan pembayaran transfer → satu pemberitahuan per wali, saat sah');
$batal = $api('POST', "/v6/bsa/pembayaran/$idBayarTab/batal", $P, ['idempotency_key' => $kunci('batal'), 'alasan' => 'Salah potong uji']);
$koreksi = $api('POST', "/v6/bsa/tabungan/$idSetor/koreksi", $P, ['idempotency_key' => $kunci('kor'), 'alasan' => 'Salah catat uji']);
$assert($batal['status'] === 200 && $outbox($ev(BsaNotifikasi::EVENT_PEMBAYARAN_BATAL, $idBayarTab)) === 2 && $outbox($ev(BsaNotifikasi::EVENT_TABUNGAN, (int) $batal['data']['transaksi_id'])) === 0,
    'Pembatalan pembayaran dari tabungan → satu pemberitahuan pembaruan pembayaran (pengembalian tidak diberitahukan terpisah)');
$assert(in_array($koreksi['status'], [201, 422], true), 'Koreksi setoran awal: 201 bila saldo cukup, 422 bila dana sudah dipakai (' . $koreksi['status'] . ')');
$idKor = (int) ($koreksi['data']['id'] ?? 0);
$assert($koreksi['status'] !== 201 || $outbox($ev(BsaNotifikasi::EVENT_TABUNGAN, $idKor)) === 2, 'Koreksi pembalik sah → pemberitahuan transaksi tabungan');
$baris = $semua("SELECT judul, isi, data_json, event_type FROM notifikasi_outbox WHERE event_type LIKE 'v6\\_bsa\\_%'");
$bersih = array_filter($baris, static fn (array $b): bool => preg_match('/Rp|\d|SBX|BSA-|REF-|V6S/', $b['judul'] . ' ' . $b['isi']) === 1
    || count(json_decode($b['data_json'], true)) !== 2 || !isset(BsaNotifikasi::TAUTAN[$b['event_type']]));
$assert(count($baris) > 10 && $bersih === [], 'Seluruh ' . count($baris) . ' baris pemberitahuan V6: teks generik tanpa angka/nama/kuitansi/referensi; data hanya {tipe, id}');

$nl = $api('GET', '/notifikasi?per_page=50', $T['sbx_v6_wali_dua'])['data']['items'] ?? [];
$v6 = array_values(array_filter($nl, static fn (array $x): bool => str_starts_with($x['event_type'], 'v6_bsa_')));
$tipe = array_unique(array_map(static fn (array $x): string => $x['tautan']['tipe'], $v6));
sort($tipe);
$tagNotif = array_values(array_filter($v6, static fn (array $x): bool => $x['tautan']['tipe'] === 'bsa_tagihan' && $x['tautan']['tagihan_id'] === $tid))[0] ?? null;
$assert($tipe === ['bsa_pembayaran', 'bsa_tabungan', 'bsa_tagihan'] && $tagNotif !== null && $tagNotif['tautan']['tagihan_id'] === $tid && $tagNotif['santri_nama'] === null,
    'API /notifikasi wali: tautan bsa_tagihan/bsa_pembayaran/bsa_tabungan dengan penunjuk id, tanpa nama santri');
$ptr = $tagNotif['tautan']['tagihan_id'] ?? 0;
$assert($api('GET', "/v6/bsa/tagihan/$ptr", $T['sbx_v6_wali_dua'])['status'] === 200 && $api('GET', "/v6/bsa/tagihan/$ptr", $T['sbx_v6_wali_tsanawi'])['status'] === 404
    && $api('GET', "/v6/bsa/pembayaran/$idBayarTab", $T['sbx_v6_wali_tsanawi'])['status'] === 404 && $api('GET', "/v6/bsa/tabungan/$idSetor", $T['sbx_v6_wali_tsanawi'])['status'] === 404,
    'Penunjuk notifikasi dibuka pemilik → 200; dibuka wali anak lain (ID ditebak) → 404');
$halN = $wd->req('/portal/notifikasi.php?jenis=keuangan')['body'];
$assert(str_contains($halN, 'Keuangan') && str_contains($teks($halN), 'Tagihan bulanan') && str_contains($teks($halN), 'Transaksi tabungan'), 'Pusat notifikasi web: kategori Keuangan menampilkan pemberitahuan V6');
$nid = (int) ($tagNotif['id'] ?? 0);
$detail = $wd->req("/portal/notifikasi.php?detail=$nid")['body'];
$assert(str_contains($detail, "/portal/bsa_tagihan.php?id=$tid") && str_contains($teks($detail), 'Buka tagihan'), 'Detail notifikasi web menaut ke halaman tagihan (halaman tujuan memeriksa ulang hak)');

// ===========================================================================
// N-E  Push (klien Expo palsu)
// ===========================================================================
$push = new class implements App\Notification\Push\PushClient, App\Notification\Push\PushReceiptClient {
    public array $messages = [];

    public function send(array $messages): array
    {
        $this->messages = array_merge($this->messages, $messages);

        return ['ok' => true, 'tickets' => array_map(static fn () => ['status' => 'ok', 'id' => 'v6f4-' . bin2hex(random_bytes(6))], $messages), 'kode' => 'OK', 'pesan' => 'Palsu', 'permanen' => false];
    }

    public function getReceipts(array $ids): array
    {
        return ['ok' => true, 'receipts' => array_fill_keys($ids, ['status' => 'ok']), 'kode' => 'OK', 'pesan' => 'Palsu', 'permanen' => false];
    }
};
$wa = new class implements WhatsAppProvider {
    public function name(): string { return 'uji'; }
    public function mengirimNyata(): bool { return false; }
    public function readiness(): array { return ['siap' => true, 'pesan' => 'Palsu', 'detail' => []]; }
    public function verify(): ProviderResult { return ProviderResult::ok(); }
    public function send(WhatsAppMessage $m): ProviderResult { return ProviderResult::ok(); }
};
$settings = new SettingsRepository($conn);
$devices = new DeviceRepository($conn);
$protector = new PushTokenProtector(base64_encode(str_repeat('6', 32)));
$dispatcher = new NotificationDispatcher($conn, new OutboxRepository($conn), $devices, $protector, $push, $wa, $settings, new WorkerLock($conn));
$jalankan("UPDATE notifikasi_outbox SET tersedia_pada = DATE_ADD(NOW(), INTERVAL 1 DAY) WHERE kanal = 'Push' AND status IN ('Queued','Failed')");
$tokenPush = 'ExponentPushToken[v6f4-' . bin2hex(random_bytes(10)) . ']';
$devices->register(['user_id' => $wDua, 'token_hash' => $protector->hash($tokenPush), 'token_terlindungi' => $protector->protect($tokenPush),
    'platform' => 'android', 'device_id' => 'v6f4-dev', 'device_label' => 'Fixture V6 Fase 4', 'app_version' => 'uji']);
$jalankan('UPDATE pengaturan_notifikasi SET push_enabled = 1 WHERE singleton = 1');
$sPush = $api('POST', "/v6/bsa/santri/$sSmp/setoran", $P, ['idempotency_key' => $kunci('push'), 'nominal' => 40000, 'metode' => 'Tunai']);
$idPush = (int) $sPush['data']['transaksi_id'];
$assert($outbox($ev(BsaNotifikasi::EVENT_TABUNGAN, $idPush) . " AND kanal='Push'") === 1 && $outbox($ev(BsaNotifikasi::EVENT_TABUNGAN, $idPush) . " AND kanal='Push' AND penerima_user_id=$wDua") === 1
    && $outbox($ev(BsaNotifikasi::EVENT_TABUNGAN, $idPush) . " AND kanal='InApp'") === 2,
    'Sakelar Push menyala: baris Push hanya untuk wali berperangkat (wali dua anak); InApp tetap untuk kedua wali');
$dispatcher->run('Push');
$pesanPush = $push->messages[0] ?? [];
$assert(count($push->messages) === 1 && ($pesanPush['data'] ?? null) === ['tipe' => 'bsa_tabungan', 'transaksi_id' => $idPush] && ($pesanPush['title'] ?? '') === 'Transaksi tabungan'
    && preg_match('/Rp|\d|SBX/', ($pesanPush['title'] ?? '') . ($pesanPush['body'] ?? '')) === 0,
    'Worker Push mengirim teks generik + penunjuk {tipe, transaksi_id} saja');
// Pembayaran sah diantre Push, lalu dibatalkan sebelum worker → baris "sah" ditolak, "batal" terkirim.
$bp = $api('POST', "/v6/bsa/tagihan/$tid/pembayaran", $P, ['idempotency_key' => $kunci('bp'), 'nominal' => 1000, 'sumber' => 'Tunai', 'tanggal_transaksi' => date('Y-m-d')]);
$api('POST', '/v6/bsa/pembayaran/' . $bp['data']['id'] . '/batal', $P, ['idempotency_key' => $kunci('bpb'), 'alasan' => 'Batal uji push']);
$push->messages = [];
$dispatcher->run('Push');
$stSah = (string) $satu("SELECT CONCAT(status, ':', COALESCE(error_kode, '')) FROM notifikasi_outbox WHERE " . $ev(BsaNotifikasi::EVENT_PEMBAYARAN, (int) $bp['data']['id']) . " AND kanal='Push'");
$assert(count($push->messages) === 1 && ($push->messages[0]['data'] ?? null) === ['tipe' => 'bsa_pembayaran', 'pembayaran_id' => (int) $bp['data']['id']] && str_contains($stSah, 'AKSES_BERUBAH'),
    'Pembayaran dibatalkan sebelum worker: Push "pembayaran tercatat" ditolak (AKSES_BERUBAH), Push "pembaruan" terkirim [' . $stSah . ']');

// ===========================================================================
// N-F  Pencabutan saat sesi/token lama masih aktif
// ===========================================================================
$sPush2 = $api('POST', "/v6/bsa/santri/$sTs/setoran", $P, ['idempotency_key' => $kunci('push'), 'nominal' => 2000, 'metode' => 'Tunai']);
$relTs = (int) $satu("SELECT sw.id FROM santri_wali sw JOIN users u ON u.wali_id = sw.wali_id WHERE u.id = $wDua AND sw.santri_id = $sTs");
$jalankan("UPDATE santri_wali SET archived_at = NOW() WHERE id = $relTs");
$push->messages = [];
$dispatcher->run('Push');
$stCabut = (string) $satu("SELECT CONCAT(status, ':', COALESCE(error_kode, '')) FROM notifikasi_outbox WHERE " . $ev(BsaNotifikasi::EVENT_TABUNGAN, (int) $sPush2['data']['transaksi_id']) . " AND kanal='Push'");
$assert($push->messages === [] && str_contains($stCabut, 'AKSES_BERUBAH'), 'Relasi dicabut sesudah antre: worker tidak mengirim Push anak itu (AKSES_BERUBAH)');
$wTokenLama = $T['sbx_v6_wali_dua'];
$assert($idsAnak('sbx_v6_wali_dua') === [200, [$sSmp]] && $api('GET', "/v6/bsa/santri/$sTs", $wTokenLama)['status'] === 404
    && $api('GET', "/v6/bsa/tagihan/$tidTs", $wTokenLama)['status'] === 404 && $api('GET', "/v6/bsa/santri/$sSmp", $wTokenLama)['status'] === 200
    && in_array($wd->req("/portal/bsa_santri.php?id=$sTs")['status'], [403, 404], true),
    'Token/sesi wali lama sesudah satu relasi dicabut: anak itu 404 di API dan web, anak lain tetap terbaca');
$sesudah = $api('POST', "/v6/bsa/santri/$sTs/setoran", $P, ['idempotency_key' => $kunci('cabut'), 'nominal' => 3000, 'metode' => 'Tunai']);
$assert($outbox($ev(BsaNotifikasi::EVENT_TABUNGAN, (int) $sesudah['data']['transaksi_id']) . " AND penerima_user_id = $wDua") === 0
    && $outbox($ev(BsaNotifikasi::EVENT_TABUNGAN, (int) $sesudah['data']['transaksi_id']) . " AND penerima_user_id = $wTs") === 1,
    'Sesudah pencabutan, transaksi baru anak itu tidak diberitahukan ke wali tercabut; wali aktif tetap menerima');
$tugasB = (int) $satu("SELECT b.id FROM bendahara_bulanan_assignments b JOIN users u ON u.pengurus_id = b.pengurus_id WHERE u.username = 'sbx_v6_bsa_b'");
$jalankan("UPDATE bendahara_bulanan_assignments SET is_active = 0 WHERE id = $tugasB");
$ak = $api('GET', '/v6/bsa/akses', $P);
$mut = $api('POST', "/v6/bsa/santri/$sSmp/setoran", $P, ['idempotency_key' => $kunci('cabut'), 'nominal' => 1000, 'metode' => 'Tunai']);
$assert(($ak['data']['baca'] ?? true) === false && $mut['status'] === 403 && $api('GET', "/v6/bsa/santri/$sSmp", $P)['status'] === 403
    && in_array($pw->req("/portal/bsa_santri.php?id=$sSmp")['status'], [403, 404], true),
    'Penugasan petugas dinonaktifkan: token/sesi lama → akses kosong, mutasi 403, baca 403 di API dan web');
$jalankan("UPDATE bendahara_bulanan_assignments SET is_active = 1 WHERE id = $tugasB");
$jalankan("UPDATE santri_wali SET archived_at = NULL WHERE id = $relTs");
$jalankan('UPDATE pengaturan_notifikasi SET push_enabled = 0 WHERE singleton = 1');
$jalankan("UPDATE perangkat_push SET push_aktif = 0, dicabut_pada = NOW() WHERE device_label = 'Fixture V6 Fase 4'");

// ===========================================================================
// N-G  Log server dan audit
// ===========================================================================
usleep(300000);
$isiLog = (string) file_get_contents($log);
$audit = $semua("SELECT action, after_json FROM audit_logs WHERE id > $mulaiAudit AND action LIKE 'bsa.%'");
$bocor = array_filter($audit, static fn (array $a): bool => preg_match('/REF-RAHASIA|"nominal"|"saldo|"referensi"/', (string) $a['after_json']) === 1);
$assert(preg_match('/PHP (Warning|Notice|Fatal|Deprecated)|Uncaught/', $isiLog) === 0 && !str_contains($isiLog, 'REF-RAHASIA') && !str_contains($isiLog, $wTokenLama) && !str_contains($isiLog, 'ExponentPushToken'),
    'Log server: nol galat PHP, nol referensi transfer, nol token API/Push');
$assert(count($audit) > 10 && $bocor === [], 'Audit ' . count($audit) . ' baris bsa.* tanpa nominal, saldo, atau referensi');
$assert(!str_contains((string) $satu("SELECT COALESCE(GROUP_CONCAT(CONCAT(judul, isi, COALESCE(data_json, ''))), '') FROM notifikasi_outbox WHERE event_type LIKE 'v6%'"), 'REF-RAHASIA'), 'Antrean notifikasi V6 tanpa referensi transfer');

echo PHP_EOL . "Ringkasan: $lulus lulus, " . count($gagal) . " gagal" . PHP_EOL;
foreach ($gagal as $g) {
    echo " - $g" . PHP_EOL;
}
exit($gagal === [] ? 0 : 1);
