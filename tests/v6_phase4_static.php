<?php

declare(strict_types=1);

/**
 * Uji statis PRD V6 Fase 4 — paritas aplikasi dan orang tua. Tanpa basis
 * data. Membaca checkout mobile `~/alhasanApps` (atau `V6F4_MOBILE`) bila ada.
 *
 *   php tests/v6_phase4_static.php
 *
 *   W-1  tanpa migrasi baru; SQL ≤ 029 tidak berubah dari baseline audit Fase 3;
 *   W-2  pemberitahuan: katalog teks generik tanpa data, data hanya satu
 *        penunjuk, diantre di DALAM transaksi mutasi sesudah audit, tidak
 *        pada cabang replay, tidak untuk antrean menunggu/ditolak, WhatsApp
 *        tidak diantrekan; worker Push memeriksa ulang sebelum provider;
 *   W-3  privasi wali: daftar pembayaran disaring di SQL; tunggakan tanpa
 *        jumlah menunggu bagi wali;
 *   W-4  tautan notifikasi web/API untuk tipe keuangan;
 *   W-5  batas Fase 4 terhadap baseline: Auth, PSB, V1–V5, penugasan,
 *        halaman BSA (kecuali tidak ada), migrasi; berkas berubah hanya dalam
 *        daftar yang diizinkan;
 *   W-6  aplikasi: klien `/v6/bsa` tanpa cache disk/log, setiap mutasi
 *        memakai kunci idempotensi dari penjaga mutasi, teks Indonesia untuk
 *        galat, layar membaca server lewat `usePrivateResource`;
 *   W-7  lint PHP berkas baru/berubah.
 */

$root = dirname(__DIR__);
$baseline = getenv('V6F4_BASELINE') ?: '1f2dbca574805954566305789e589540e27348f0';
$mobile = getenv('V6F4_MOBILE') ?: (getenv('HOME') . '/alhasanApps');
$lulus = 0;
$gagal = [];
$assert = static function (bool $ok, string $pesan) use (&$lulus, &$gagal): void {
    echo ($ok ? '[lulus] ' : '[gagal] ') . $pesan . PHP_EOL;
    $ok ? $lulus++ : $gagal[] = $pesan;
};
$baca = static fn (string $f): string => (string) @file_get_contents($root . '/' . $f);
$git = static fn (string $args): string => (string) shell_exec('cd ' . escapeshellarg($root) . ' && git ' . $args . ' 2>/dev/null');
$blok = static function (string $src, string $awal, string $akhir): string {
    $i = strpos($src, $awal);
    $j = $i === false ? false : strpos($src, $akhir, $i + 1);

    return $i === false || $j === false ? '' : substr($src, $i, $j - $i);
};
$berubah = array_values(array_filter(array_map('trim', explode("\n", $git("diff --name-only $baseline") . $git('ls-files --others --exclude-standard')))));
$berubah = array_values(array_unique(array_filter($berubah, static fn (string $f): bool => !str_starts_with($f, 'docs/panduan-pengguna-v4/') && !str_starts_with($f, 'docs/presentasi-al-hasan-v4/') && !str_starts_with($f, 'output/'))));

// ===================================================================== W-1
echo '=== W-1. Tanpa migrasi baru ===' . PHP_EOL;
$assert(trim($git("cat-file -t $baseline")) === 'commit', "Baseline audit Fase 3 $baseline tersedia");
$assert(array_filter($berubah, static fn (string $f): bool => str_starts_with($f, 'database/')) === [], 'Tidak ada berkas database/ (migrasi/rollback) yang ditambah atau diubah');

// ===================================================================== W-2
echo PHP_EOL . '=== W-2. Pemberitahuan umum ===' . PHP_EOL;
$notif = $baca('app/Bsa/BsaNotifikasi.php');
$teks = $blok($notif, 'public static function teks', 'public static function eventKey');
preg_match_all("/'(judul|isi)' => '([^']+)'/", $teks, $m);
$assert(count($m[2]) === 8 && array_filter($m[2], static fn (string $t): bool => preg_match('/\d|Rp|\$|%s|\{/', $t) === 1) === [],
    'Katalog delapan teks generik tanpa angka, rupiah, atau sisipan data');
$assert(str_contains($notif, "return ['tipe' => \$tipe, \$kunci => \$id];") && str_contains($notif, 'NotificationChannel::IN_APP') && str_contains($notif, 'NotificationChannel::PUSH')
    && !str_contains($notif, 'WHATSAPP') && !str_contains($notif, 'WhatsApp::'), 'Data hanya {tipe, id}; kanal InApp + Push saja (WhatsApp tidak diantrekan)');
$assert(str_contains($notif, "push_enabled'] === true ? \$this->devices->usersWithActiveDevice") && str_contains($notif, 'waliSantri($santriId)')
    && str_contains($notif, "throw new BsaException('Pemberitahuan tidak dapat diantrekan"), 'Push hanya bila sakelar menyala + perangkat aktif; penerima = wali aktif; gagal antre → 503 (mutasi batal)');
$layanan = $baca('app/Bsa/BsaService.php') . $baca('app/Bsa/TagihanService.php') . $baca('app/Bsa/TabunganService.php');
$panggilan = substr_count($layanan, 'beritahuWali(') - 1;
$assert($panggilan === 9, "Sembilan titik pemberitahuan (tagihan tunggal/massal, setoran/penarikan/belanja, verifikasi setoran, koreksi, pembayaran tunai/transfer sah, verifikasi, pembatalan, bayar dari tabungan) — ditemukan $panggilan");
$sebelumReplay = true;
foreach (preg_split('/public function /', $layanan) as $fungsi) {
    $iReplay = strpos($fungsi, "'replayed' => true");
    $iBeritahu = strpos($fungsi, 'beritahuWali(');
    $iAudit = strrpos(substr($fungsi, 0, $iBeritahu === false ? 0 : $iBeritahu), 'wajibAudit(');
    if ($iBeritahu !== false && !str_starts_with($fungsi, 'beritahuWali') && ($iAudit === false || ($iReplay !== false && $iReplay > $iBeritahu && !str_contains(substr($fungsi, $iBeritahu, $iReplay - $iBeritahu), 'return')))) {
        $sebelumReplay = false;
    }
}
$assert($sebelumReplay, 'Setiap pemberitahuan diantre sesudah audit di cabang mutasi baru (bukan cabang replay)');
$tagihan = $baca('app/Bsa/TagihanService.php');
$assert(str_contains($tagihan, "if (\$terverifikasi) {\n                    \$this->bsa->beritahuWali(") && str_contains($tagihan, "if (\$p['status'] === 'Sah') {\n                \$this->bsa->beritahuWali(")
    && str_contains($baca('app/Bsa/TabunganService.php'), "if (\$transaksiId !== null) {\n                \$this->bsa->beritahuWali("),
    'Transfer menunggu, penolakan transfer, dan setoran ditolak tidak diberitahukan');
$disp = $baca('app/Notification/NotificationDispatcher.php');
$iCek = strpos($disp, 'BsaNotifikasi::pushMasihBerhak');
$assert($iCek !== false && $iCek < strpos($disp, '$this->expo->send(') && str_contains($disp, "\$data = \$penunjukBsa;") && str_contains($notif, 's.is_active = 1 AND s.archived_at IS NULL'),
    'Worker Push memeriksa ulang keadaan sumber, santri aktif, dan relasi wali sebelum provider; data = penunjuk yang diperiksa');

// ===================================================================== W-3
echo PHP_EOL . '=== W-3. Privasi wali ===' . PHP_EOL;
$assert(str_contains($baca('app/Bsa/TagihanRepository.php'), "\$sql .= \" AND b.status <> 'Menunggu'\";") && str_contains($tagihan, 'halaman($input[\'page\'] ?? 1), $wali);'),
    'Daftar pembayaran wali menyaring transfer menunggu di SQL (total dan halaman benar)');
$assert(str_contains($tagihan, "'menunggu_verifikasi' => \$wali ? 0 : (int) \$r['menunggu'],"), 'Tunggakan wali tanpa jumlah transfer menunggu');

// ===================================================================== W-4
echo PHP_EOL . '=== W-4. Tautan notifikasi ===' . PHP_EOL;
$pusat = $baca('app/Notification/NotificationCenterService.php');
$assert(str_contains($pusat, "'tagihan_id' =>") && str_contains($pusat, "'pembayaran_id' =>") && str_contains($pusat, "'transaksi_id' =>") && str_contains($baca('app/Notification/NotificationRepository.php'), "'keuangan' => 'v6%'"),
    'Tautan /notifikasi menambah penunjuk keuangan (aditif) dan kategori Keuangan');
$hal = $baca('portal/notifikasi.php');
$assert(str_contains($hal, "'bsa_tagihan' => ['tagihan_id', '/portal/bsa_tagihan.php']") && str_contains($hal, "'bsa_tabungan' => ['transaksi_id', '/portal/bsa_tabungan_kuitansi.php']"),
    'Pusat notifikasi web menaut ke halaman BSA yang memeriksa ulang hak');

// ===================================================================== W-5
echo PHP_EOL . '=== W-5. Batas Fase 4 ===' . PHP_EOL;
$izin = [
    'AGENTS.md', 'PRD-V6.md', 'docs/api-v1.md', 'app/bootstrap.php', 'portal/notifikasi.php',
    'app/Bsa/BsaNotifikasi.php', 'app/Bsa/BsaService.php', 'app/Bsa/TagihanService.php', 'app/Bsa/TagihanRepository.php', 'app/Bsa/TabunganService.php',
    'app/Notification/NotificationDispatcher.php', 'app/Notification/NotificationCenterService.php', 'app/Notification/NotificationRepository.php', 'app/Notification/NotificationEvent.php',
    'tests/v6_phase4_static.php', 'tests/v6_phase4_http.php', 'tests/v6_phase4_seed.php', 'tests/v6_phase4_db.sh', 'tests/browser/uji-v6-fase4-app.mjs',
];
$luar = array_values(array_filter($berubah, static fn (string $f): bool => !in_array($f, $izin, true) && !str_starts_with($f, 'docs/phase-v6-4/')));
$assert($luar === [], 'Berkas berubah hanya dalam daftar Fase 4' . ($luar === [] ? '' : ': ' . implode(', ', $luar)));
$assert(array_filter($berubah, static fn (string $f): bool => (bool) preg_match('#^(app/Auth|app/Psb|app/Penugasan|app/Kesehatan|app/V[2-5]|admin/|api/v1/)#i', $f) || preg_match('#^portal/(bsa|psb|kesehatan)#', $f) === 1) === [],
    'Auth, PSB, penugasan, V2–V5, admin, rute API, dan halaman BSA tidak berubah');

// ===================================================================== W-6
echo PHP_EOL . '=== W-6. Aplikasi (checkout mobile) ===' . PHP_EOL;
if (!is_dir($mobile . '/src')) {
    echo "[lewati] Checkout mobile tidak ditemukan di $mobile" . PHP_EOL;
} else {
    $m = static fn (string $f): string => (string) @file_get_contents($mobile . '/' . $f);
    $klien = $m('src/api/keuangan.ts');
    $assert(str_contains($klien, "const dasar = '/v6/bsa';") && str_contains($klien, 'idempotency_key: key') && !preg_match('/AsyncStorage|SecureStore|localStorage|console\./', $klien),
        'Klien /v6/bsa: kunci idempotensi per mutasi, tanpa cache disk atau log');
    $layar = glob($mobile . '/src/app/keuangan/{,*/}*.tsx', GLOB_BRACE) ?: [];
    $isi = implode("\n", array_map(static fn (string $f): string => (string) file_get_contents($f), $layar)) . $m('src/components/keuangan-shared.tsx') . $m('src/components/keuangan-setoran.tsx');
    $assert(count($layar) >= 17 && !preg_match('/AsyncStorage|SecureStore|localStorage|sessionStorage|console\.|FileSystem|Sharing/', $isi), count($layar) . ' layar keuangan tanpa penyimpanan disk, log konsol, atau berbagi berkas');
    $mutasi = preg_match_all('/keuangan\.(setoran|keluar|putusSetoran|koreksi|bayar|bayarTabungan|putusPembayaran|revisi|batalTagihan|terbitkan)\(/', $isi);
    $dijaga = preg_match_all('/guard\.run\(sidikJari\(/', $isi);
    $assert($mutasi >= 10 && $mutasi === $dijaga, "Setiap mutasi aplikasi ($mutasi) lewat useMutationGuard + sidik jari isi ($dijaga): ketukan ganda diabaikan, retry kunci sama");
    $assert(substr_count($isi, 'usePrivateResource(') >= 15 && str_contains($m('src/hooks/use-private-resource.ts'), "AppState.addEventListener"), 'Layar membaca server lewat usePrivateResource (dimuat ulang saat fokus/aktif, dibuang di latar)');
    $assert(str_contains($m('src/notifications/notification-context.tsx'), '...Object.keys(TIPE_KEUANGAN)') && str_contains($m('src/rapor/forms.ts'), "p.tipe === 'bsa_tagihan' ? 'tagihan_id'"),
        'Ketukan notifikasi keuangan hanya membawa penunjuk; bertahan melewati login; layar tujuan meminta server');
    $assert(str_contains($isi, 'Muat versi terbaru (isian ini dibuang)') && str_contains($isi, '<Centang ') && str_contains($m('src/components/keuangan-shared.tsx'), 'accessibilityRole="checkbox"'),
        'Konflik versi menawarkan muat ulang eksplisit; persetujuan memakai kotak centang aksesibel');
}

// ===================================================================== W-7
echo PHP_EOL . '=== W-7. Lint ===' . PHP_EOL;
$php = array_values(array_filter($berubah, static fn (string $f): bool => str_ends_with($f, '.php') && is_file($root . '/' . $f)));
$salah = array_filter($php, static fn (string $f): bool => !str_contains((string) shell_exec('php -l ' . escapeshellarg($root . '/' . $f) . ' 2>&1'), 'No syntax errors'));
$assert($php !== [] && $salah === [], 'Lint ' . count($php) . ' berkas PHP Fase 4');

echo PHP_EOL . "Ringkasan: $lulus lulus, " . count($gagal) . ' gagal' . PHP_EOL;
foreach ($gagal as $g) {
    echo " - $g" . PHP_EOL;
}
exit($gagal === [] ? 0 : 1);
