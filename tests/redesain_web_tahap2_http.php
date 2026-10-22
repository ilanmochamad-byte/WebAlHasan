<?php

declare(strict_types=1);

/**
 * Uji server redesain website Tahap 2 — navigasi, peran & Beranda.
 *
 * Kriteria selesai Tahap 2 (cetak biru): setiap peran uji melihat menu dan
 * tugas tertundanya; alamat lama mengalihkan; notifikasi terbuka hanya untuk
 * pemiliknya. Skrip ini menjalankan server PHP lokal sendiri di atas DB uji
 * `test_*_test` dari `tests/redesain_web_tahap2_db.sh` dan memeriksa:
 *
 *   H2-A  peta menu per peran (A–K): admin 17 menu dalam 7 grup tanpa label
 *         versi; guru, murobi, guru mapel, pengurus (J), Bagian Pendidikan,
 *         orang tua (F, K); bilah bawah per peran;
 *   H2-B  setiap menu dan tab terbuka tanpa 500, dan menyorot menu/tab yang
 *         benar; alamat lama (?mode=, admin_santri, admin_izin, admin_login,
 *         proses_mutasi_alumni, murobi/pembimbing, rekonsiliasi) tetap
 *         berfungsi di tempat barunya;
 *   H2-C  peran aktif di sesi (AR): POST + CSRF saja, peran di luar hak
 *         ditolak, ?mode= lama menyelaraskan peran, halaman milik peran lain
 *         mengalihkan peran, masuk ulang kembali ke peran bawaan, dan peran
 *         tidak pernah membuka halaman yang tidak berhak;
 *   H2-D  Beranda per peran (B-1 s.d. B-5, L, P, AA) termasuk keadaan kosong;
 *   H2-E  notifikasi untuk semua akun (AK, N-3): terbuka bagi guru mapel dan
 *         Bagian Pendidikan, hanya milik sendiri (detail/buka/tandai milik
 *         orang lain 403 dan basis data tidak berubah), kategori, kelompok hari;
 *   H2-F  halaman masuk (A-1), ganti kata sandi (A-3), keluar (A-4), akses
 *         ditolak (A-5) termasuk teks polos V4 yang diganti;
 *   H2-G  tidak ada galat/peringatan PHP di log server.
 *
 * Jalankan setelah menyiapkan DB:
 *   bash tests/redesain_web_tahap2_db.sh
 *   php tests/redesain_web_tahap2_http.php
 */

$root = dirname(__DIR__);
$db = getenv('TAHAP2_DB') ?: 'test_redesain_t2_test';
$dbKosong = getenv('TAHAP2_DB_KOSONG') ?: 'test_redesain_t2_kosong_test';
$port = (int) (getenv('TAHAP2_PORT') ?: 8942);
$portKosong = $port + 1;
if (!preg_match('/^test_.*_test$/', $db) || !preg_match('/^test_.*_test$/', $dbKosong)) {
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

// --- Server lokal ------------------------------------------------------------
$logs = [];
$servers = [];
foreach ([[$port, $db], [$portKosong, $dbKosong]] as [$p, $nama]) {
    $log = tempnam(sys_get_temp_dir(), 'tahap2-server-');
    $logs[$p] = $log;
    $servers[] = proc_open(
        ['php', '-S', '127.0.0.1:' . $p, $root . '/tests/redesain_web_tahap1_router.php'],
        [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
        $pipes,
        $root,
        array_merge(getenv(), ['DB_NAME' => $nama])
    );
}
register_shutdown_function(static function () use ($servers): void {
    foreach ($servers as $server) {
        if (is_resource($server)) {
            proc_terminate($server);
        }
    }
});
foreach ([$port, $portKosong] as $p) {
    for ($i = 0; $i < 50; $i++) {
        $sock = @fsockopen('127.0.0.1', $p);
        if ($sock !== false) {
            fclose($sock);
            break;
        }
        usleep(100000);
    }
}

final class Klien
{
    private string $jar;

    public function __construct(private string $base)
    {
        $this->jar = tempnam(sys_get_temp_dir(), 'tahap2-jar-');
    }

    /** @return array{status:int, body:string, location:?string} */
    public function req(string $path, ?array $post = null): array
    {
        $h = curl_init($this->base . $path);
        curl_setopt_array($h, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar, CURLOPT_TIMEOUT => 30,
        ]);
        if ($post !== null) {
            curl_setopt($h, CURLOPT_POST, true);
            curl_setopt($h, CURLOPT_POSTFIELDS, http_build_query($post));
        }
        $raw = (string) curl_exec($h);
        $status = (int) curl_getinfo($h, CURLINFO_RESPONSE_CODE);
        $size = (int) curl_getinfo($h, CURLINFO_HEADER_SIZE);
        curl_close($h);
        $headers = substr($raw, 0, $size);
        preg_match('/^Location:\s*(.+)$/mi', $headers, $m);

        return ['status' => $status, 'body' => substr($raw, $size), 'location' => isset($m[1]) ? trim($m[1]) : null];
    }

    public function csrf(string $path = '/portal/index.php'): string
    {
        $r = $this->req($path);
        if (preg_match('/name="_csrf" value="([^"]+)"/', $r['body'], $m) !== 1) {
            throw new RuntimeException('Token CSRF tidak ditemukan pada ' . $path);
        }

        return $m[1];
    }

    public function login(string $user): array
    {
        return $this->req('/admin/cek_login.php', ['_csrf' => $this->csrf(), 'username' => $user, 'password' => 'Sandbox#123']);
    }
}

$base = 'http://127.0.0.1:' . $port;
$klien = static function (string $user) use ($base): Klien {
    $k = new Klien($base);
    $k->login($user);

    return $k;
};

/** @return array{grup:array<int,string>, menu:array<int,string>, aktif:?string, tab:?string, bar:array<int,string>, peran:array<int,string>} */
$peta = static function (string $html): array {
    $side = substr($html, (int) strpos($html, 'id="ah-sidebar"'));
    $side = substr($side, 0, (int) strpos($side, 'ah-drawer-foot'));
    preg_match_all('/class="ah-nav-group__text">([^<]*)</', $side, $g);
    preg_match_all('/<a href="[^"]*"(?: aria-current="page")?>\s*<i[^>]*><\/i>\s*<span>([^<]*)<\/span>/', $side, $m);
    preg_match('/aria-current="page">\s*<i[^>]*><\/i>\s*<span>([^<]*)<\/span>/', $side, $aktif);
    preg_match('/ah-tabs--module.*?aria-current="page"><span>([^<]*)</s', $html, $tab);
    $bar = substr($html, (int) strpos($html, 'class="ah-bottombar"'));
    preg_match_all('/<span>([^<]*)<\/span>/', substr($bar, 0, (int) strpos($bar, '</nav>')), $b);
    preg_match_all('/name="peran" value="([^"]+)"/', $html, $p);

    return [
        'grup' => array_map('html_entity_decode', $g[1]),
        'menu' => array_map(static fn ($x) => html_entity_decode($x, ENT_QUOTES), $m[1]),
        'aktif' => isset($aktif[1]) ? html_entity_decode($aktif[1], ENT_QUOTES) : null,
        'tab' => isset($tab[1]) ? html_entity_decode($tab[1], ENT_QUOTES) : null,
        'bar' => strpos($html, 'class="ah-bottombar"') === false ? [] : array_map(static fn ($x) => html_entity_decode($x, ENT_QUOTES), $b[1]),
        'peran' => array_values(array_unique($p[1])),
    ];
};
$teks = static fn (string $html): string => html_entity_decode(preg_replace('/\s+/', ' ', strip_tags(preg_replace('#<script.*?</script>#s', '', $html))), ENT_QUOTES);
$sql = static function (string $query): array {
    $r = app_db()->query($query);
    return $r === true ? [] : $r->fetch_all(MYSQLI_ASSOC);
};

// ============================================================ H2-A peta menu
echo PHP_EOL . '=== H2-A. Peta menu per peran ===' . PHP_EOL;
$harapan = [
    'sbx_admin' => [
        'menu' => ['Beranda', 'Perizinan', 'Pengajian', 'Pembinaan', 'Penilaian & rapor', 'Data santri', 'Asatidz & pengurus', 'Kelas & kobong', 'Pendaftar', 'Pembayaran', 'Rekap keuangan', 'Konten website', 'Tahun ajaran', 'Penugasan', 'Akun & hak akses', 'Kanal notifikasi', 'Data sistem lama'],
        'grup' => ['Kegiatan santri', 'Akademik', 'Data pesantren', 'PSB', 'Website', 'Pengaturan'],
        'bar' => ['Beranda', 'Perizinan', 'Santri', 'Notifikasi', 'Menu'],
    ],
    'sbx_guru_biasa' => ['menu' => ['Beranda', 'Pengajian'], 'grup' => ['Mengajar'], 'bar' => ['Beranda', 'Pengajian', 'Notifikasi', 'Menu']],
    'sbx_murobi_a' => ['menu' => ['Beranda', 'Pengajian', 'Antrean izin', 'Sikap binaan', 'Pembinaan binaan'], 'grup' => ['Mengajar', 'Murobi'], 'bar' => ['Beranda', 'Pengajian', 'Antrean', 'Notifikasi', 'Menu']],
    'sbx_guru_mapel' => ['menu' => ['Beranda', 'Nilai semester'], 'grup' => ['Mengajar'], 'bar' => ['Beranda', 'Nilai', 'Notifikasi', 'Menu']],
    'sbx_pengurus_a' => ['menu' => ['Beranda', 'Perizinan', 'Sikap semester', 'Pembinaan'], 'grup' => ['Santri binaan'], 'bar' => ['Beranda', 'Perizinan', 'Binaan', 'Notifikasi', 'Menu']],
    'sbx_pengurus_biasa' => ['menu' => ['Beranda', 'Perizinan'], 'grup' => ['Santri binaan'], 'bar' => ['Beranda', 'Perizinan', 'Notifikasi', 'Menu']],
    'sbx_pendidikan' => ['menu' => ['Beranda', 'Penilaian & rapor', 'Nilai pengganti'], 'grup' => ['Akademik'], 'bar' => ['Beranda', 'Rapor', 'Notifikasi', 'Menu']],
    'sbx_ortu_a' => ['menu' => ['Beranda', 'Izin anak', 'Rapor', 'Informasi pembinaan', 'Notifikasi'], 'grup' => [], 'bar' => ['Beranda', 'Izin', 'Rapor', 'Info', 'Notifikasi']],
];
$beranda = [];
foreach ($harapan as $user => $h) {
    $k = $klien($user);
    $r = $k->req('/portal/index.php');
    $beranda[$user] = $r['body'];
    $p = $peta($r['body']);
    $assert($r['status'] === 200, "{$user}: Beranda 200");
    $assert($p['menu'] === $h['menu'], "{$user}: menu = " . implode(' · ', $p['menu']));
    $assert($p['grup'] === $h['grup'], "{$user}: grup = " . implode(' · ', $p['grup']));
    $assert($p['bar'] === $h['bar'], "{$user}: bilah bawah = " . implode(' · ', $p['bar']));
    $assert(preg_match('/\b(V2|V3|V4)\b/', implode(' ', array_merge($p['menu'], $p['grup']))) !== 1, "{$user}: tanpa label versi di menu");
    $assert(str_contains($r['body'], 'class="ah-topbar__btn ah-topbar__bell"'), "{$user}: lonceng notifikasi tampil (AK)");
}
$assert(count($harapan['sbx_admin']['menu']) === 17 && count($harapan['sbx_admin']['grup']) + 1 === 7, 'Admin: 17 menu dalam 7 grup (Beranda + 6 grup bernama)');

// ============================================================ H2-B semua menu & tab, alamat lama
echo PHP_EOL . '=== H2-B. Setiap menu/tab dan alamat lama ===' . PHP_EOL;
foreach (array_keys($harapan) as $user) {
    $k = $klien($user);
    preg_match('/id="ah-menu-data">(.*?)<\/script>/s', $beranda[$user], $m);
    $indeks = json_decode($m[1] ?? '[]', true) ?: [];
    $buruk = [];
    foreach ($indeks as $entri) {
        $path = (string) parse_url($entri['url'], PHP_URL_PATH) . (parse_url($entri['url'], PHP_URL_QUERY) ? '?' . parse_url($entri['url'], PHP_URL_QUERY) : '');
        $r = $k->req($path);
        $p = $peta($r['body']);
        $layout = str_contains($r['body'], 'class="ah-topbar"');
        $harapanMenu = $entri['context'] === 'Akun' || $entri['context'] === '' || in_array($entri['context'], $harapan[$user]['grup'], true) ? $entri['label'] : $entri['context'];
        $menuBenar = !$layout || $entri['context'] === 'Akun' || $p['aktif'] === $harapanMenu;
        $tabBenar = !$layout || !in_array($entri['context'], $harapan[$user]['menu'], true) || $p['tab'] === $entri['label'];
        if ($r['status'] >= 500 || $r['status'] === 403 || !$menuBenar || !$tabBenar) {
            $buruk[] = "{$entri['label']} ({$path}) status {$r['status']} menu=" . ($p['aktif'] ?? '-') . ' tab=' . ($p['tab'] ?? '-');
        }
    }
    $assert($indeks !== [] && $buruk === [], "{$user}: " . count($indeks) . ' menu/tab terbuka dan menyorot tempatnya' . ($buruk ? ' — ' . implode('; ', $buruk) : ''));
}

$admin = $klien('sbx_admin');
$lama = [
    ['/admin/admin_santri.php', 'Data santri', 'Penempatan'],
    ['/admin/proses_mutasi_alumni.php', 'Data santri', 'Kelulusan & alumni'],
    ['/admin/admin_kelulusan_santri.php', 'Data santri', 'Kelulusan & alumni'],
    ['/admin/admin_wali_rekonsiliasi.php', 'Data santri', 'Orang tua/wali'],
    ['/admin/admin_murobi.php', 'Penugasan', null],
    ['/admin/admin_pembimbing.php', 'Penugasan', null],
    ['/admin/admin_izin.php', 'Perizinan', null],
    ['/portal/izin_buat.php', 'Perizinan', 'Pengajuan'],
    ['/portal/izin_detail.php?id=' . (int) $sql('SELECT MIN(id) id FROM izin_pengajuan')[0]['id'], 'Perizinan', 'Pengajuan'],
    ['/admin/admin_penugasan.php?jenis=mata_pelajaran', 'Penilaian & rapor', 'Mata pelajaran'],
];
foreach ($lama as [$path, $menu, $tab]) {
    $r = $admin->req($path);
    if ($r['status'] >= 300 && $r['status'] < 400 && $r['location'] !== null) {
        $tujuan = (string) parse_url($r['location'], PHP_URL_PATH) . (parse_url($r['location'], PHP_URL_QUERY) ? '?' . parse_url($r['location'], PHP_URL_QUERY) : '');
        $r = $admin->req($tujuan);
    }
    $p = $peta($r['body']);
    $assert($r['status'] === 200 && $p['aktif'] === $menu && ($tab === null || $p['tab'] === $tab), "Alamat lama {$path} → {$menu}" . ($tab ? " › {$tab}" : '') . " (status {$r['status']}, menu " . ($p['aktif'] ?? '-') . ', tab ' . ($p['tab'] ?? '-') . ')');
}
$r = (new Klien($base))->req('/admin/admin_login.php?pesan=sesi');
$assert($r['status'] === 302 && str_contains((string) $r['location'], '/portal/index.php?pesan=sesi'), 'admin_login.php lama mengalihkan ke pintu masuk satu');
$r = $admin->req('/admin/admin_izin.php', ['_csrf' => $admin->csrf(), 'aksi' => 'setujui']);
$assert(in_array($r['status'], [403, 405, 410, 419], true), "admin_izin.php lama tetap menolak mutasi (status {$r['status']})");

// ============================================================ H2-C peran aktif
echo PHP_EOL . '=== H2-C. Peran aktif di sesi ===' . PHP_EOL;
$ganda = $klien('sbx_ganda');
$r = $ganda->req('/portal/index.php');
$p = $peta($r['body']);
$assert($p['peran'] === ['admin', 'guru'], 'Akun admin + guru mendapat pilihan peran Admin dan Guru: ' . implode(',', $p['peran']));
$assert(str_contains($r['body'], 'Peran: Admin') && $p['menu'][1] === 'Perizinan', 'Peran bawaan setelah masuk adalah Admin');
$assert($ganda->req('/portal/peran.php')['status'] === 405, 'GET pemilih peran ditolak 405');
$assert($ganda->req('/portal/peran.php', ['peran' => 'guru'])['status'] === 419, 'POST pemilih peran tanpa CSRF ditolak 419');
$r = $ganda->req('/portal/peran.php', ['_csrf' => $ganda->csrf(), 'peran' => 'orang_tua']);
$p = $peta($ganda->req('/portal/index.php')['body']);
$assert($r['status'] === 302 && $p['menu'][1] === 'Perizinan', 'Peran di luar hak akun (orang tua) ditolak; peran tetap Admin');
$r = $ganda->req('/portal/peran.php', ['_csrf' => $ganda->csrf(), 'peran' => 'guru', 'kembali' => '/admin/admin_master_santri.php']);
$assert($r['status'] === 302 && str_ends_with((string) $r['location'], '/portal/index.php'), 'Beralih ke Guru; halaman yang bukan milik peran Guru kembali ke Beranda');
$h = $ganda->req('/portal/index.php')['body'];
$p = $peta($h);
$assert($p['menu'] === ['Beranda', 'Pengajian', 'Antrean izin', 'Sikap binaan', 'Pembinaan binaan'] && str_contains($h, 'Peran aktif: Guru'), 'Peran Guru: menu dan Beranda guru, pesan peran aktif');
$assert(str_contains($teks($h), 'Jadwal hari ini') && !str_contains($teks($h), 'Perlu tindakan'), 'Beranda peran Guru menampilkan jadwal, bukan kartu admin');
$r = $ganda->req('/portal/peran.php', ['_csrf' => $ganda->csrf(), 'peran' => 'guru', 'kembali' => '/portal/izin_antrean.php']);
$assert(str_ends_with((string) $r['location'], '/portal/izin_antrean.php'), 'Halaman asal milik peran baru dipertahankan setelah beralih');
$h = $ganda->req('/portal/izin_antrean.php')['body'];
$assert(str_contains($h, 'Cakupan data: <b>Murobi') && !str_contains($h, 'btn-group flex-wrap'), 'Perizinan memakai cakupan peran sesi (Murobi); deretan tombol mode dihapus');
$h = $ganda->req('/admin/admin_master_santri.php')['body'];
$assert(str_contains($h, 'Peran beralih ke Admin untuk membuka halaman ini.') && $peta($h)['aktif'] === 'Data santri', 'Halaman milik peran lain akun ini mengalihkan peran ke Admin');
$h = $ganda->req('/portal/izin_antrean.php?mode=murobi')['body'];
$assert(str_contains($h, 'Peran: Guru') && str_contains($h, 'Cakupan data: <b>Murobi'), 'Alamat lama ?mode=murobi menyelaraskan peran aktif ke Guru');
$h = $ganda->req('/portal/izin.php?mode=orang_tua')['body'];
$assert(str_contains($h, 'Peran: Guru') && !str_contains($h, 'Cakupan data: <b>Orang tua'), '?mode= di luar hak akun diabaikan server');
$ganda->login('sbx_ganda');
$assert(str_contains($ganda->req('/portal/index.php')['body'], 'Peran: Admin'), 'Masuk ulang kembali ke peran bawaan (AR)');
$guru = $klien('sbx_murobi_a');
$r = $guru->req('/portal/peran.php', ['_csrf' => $guru->csrf(), 'peran' => 'admin']);
$assert($guru->req('/admin/admin_master_santri.php')['status'] === 403, 'Peran tidak memberi hak: murobi tetap 403 di halaman admin setelah mencoba memilih Admin');
$pendidikan = $klien('sbx_pendidikan');
$p = $peta($pendidikan->req('/portal/index.php')['body']);
$assert($p['peran'] === ['pendidikan', 'pengurus'], 'Bagian Pendidikan yang juga pengurus mendapat dua peran');
$pendidikan->req('/portal/peran.php', ['_csrf' => $pendidikan->csrf(), 'peran' => 'pengurus']);
$p = $peta($pendidikan->req('/portal/index.php')['body']);
$assert($p['menu'] === ['Beranda', 'Perizinan'], 'Peran Pengurus tanpa pembimbing hanya Beranda + Perizinan (keputusan J)');
$pendidikan->req('/portal/peran.php', ['_csrf' => $pendidikan->csrf(), 'peran' => 'pendidikan']);

// ============================================================ H2-D Beranda
echo PHP_EOL . '=== H2-D. Beranda per peran ===' . PHP_EOL;
$h = $beranda['sbx_admin'];
preg_match_all('/data-ah-kartu="([^"]+)"/', $h, $kartu);
$assert($kartu[1] === ['izin_penetapan', 'psb_baru', 'psb_belum_lunas', 'notifikasi_gagal'], 'B-1: empat kartu prioritas teratas sesuai urutan L: ' . implode(',', $kartu[1]));
$assert(str_contains($teks($h), '+4 lainnya · Lihat semua'), 'B-1: kelebihan disebut "+4 lainnya · Lihat semua"');
$semua = $admin->req('/portal/index.php?semua=1')['body'];
preg_match_all('/data-ah-kartu="([^"]+)"/', $semua, $kartuSemua);
$assert($kartuSemua[1] === ['izin_penetapan', 'psb_baru', 'psb_belum_lunas', 'notifikasi_gagal', 'izin_tertunda', 'bahan_perbaikan', 'santri_tanpa_tempat', 'rekomendasi'], 'B-1: "Lihat semua" menampilkan kedelapan kartu (L + AA)');
$assert(substr_count($semua, 'ah-task--warn') >= 5 && str_contains($teks($semua), 'Ada yang tertunda lebih dari 1 hari'), 'B-1: kartu bertunda > 1 hari berwarna kuning (P)');
$hitung = $sql("SELECT (SELECT COUNT(*) FROM izin_pengajuan WHERE status='Perlu Penetapan Admin') a, (SELECT COUNT(*) FROM psb_pendaftar WHERE status='Baru') b, (SELECT COUNT(*) FROM v3_rekomendasi WHERE status IN ('Baru','Ditinjau')) c")[0];
$assert(preg_match('/<p class="ah-task__num">' . number_format((int) $hitung['a'], 0, ',', '.') . '<\/p><p class="ah-task__title">Izin menunggu penetapan murobi/', $h) === 1
    && preg_match('/<p class="ah-task__num">' . (int) $hitung['b'] . '<\/p><p class="ah-task__title">Pendaftar PSB baru/', $h) === 1
    && preg_match('/<p class="ah-task__num">' . (int) $hitung['c'] . '<\/p><p class="ah-task__title">Rekomendasi pembinaan/', $semua) === 1, 'B-1: angka kartu sama dengan basis data');
$assert(str_contains($h, 'href="/portal/izin_antrean.php"') && str_contains($semua, 'href="/admin/admin_penempatan_santri.php?status=tanpa_kelas"') && str_contains($semua, 'href="/portal/izin.php?status=Diajukan"'), 'B-1: kartu membuka daftar yang tersaring');
foreach (['Santri aktif', 'Izin aktif hari ini', 'Pertemuan hari ini', 'Hunian asrama', 'Pengajian hari ini', 'Pengajuan izin terbaru'] as $bagian) {
    $assert(str_contains($teks($h), $bagian), "B-1: memuat {$bagian}");
}
$p = $peta($h);
$assert(str_contains($h, 'ah-tabs--module') && preg_match('/ah-tabs--module.*Tugas saya.*Ringkasan administrasi/s', $h) === 1, 'B-1: tab Tugas saya | Ringkasan administrasi');
$kosong = new Klien('http://127.0.0.1:' . $portKosong);
$kosong->login('sbx_admin');
$hk = $kosong->req('/portal/index.php')['body'];
$assert(str_contains($teks($hk), 'Tidak ada yang perlu ditindaklanjuti') && !str_contains($hk, 'data-ah-kartu='), 'B-1: semua 0 → "Tidak ada yang perlu ditindaklanjuti" tanpa kartu');

$h = $beranda['sbx_murobi_a'];
$assert(str_contains($teks($h), 'Jadwal hari ini') && str_contains($teks($h), 'Selesai ✓') && str_contains($h, 'data-ah-kartu="murobi_antrean"') && str_contains($h, 'data-ah-kartu="murobi_sikap"'), 'B-2: murobi melihat jadwal hari ini (tombol sesuai status) dan bagian murobi');
$h = $beranda['sbx_guru_biasa'];
$assert(str_contains($teks($h), 'Jadwal hari ini') && !str_contains($h, 'data-ah-kartu="murobi_antrean"'), 'B-2: guru tanpa penugasan murobi tidak melihat bagian murobi');
$h = $beranda['sbx_guru_mapel'];
$assert(str_contains($teks($h), 'Nilai semester yang harus diisi') && str_contains($teks($h), 'Fiqih · SBX Kelas 1') && !str_contains($teks($h), 'Jadwal hari ini'), 'B-2: guru mapel melihat kemajuan nilai per mapel–kelas, tanpa jadwal pengajian');
$h = $beranda['sbx_pengurus_a'];
foreach (['Izin aktif', 'Menunggu keputusan', 'Sikap belum diisi', 'Pelanggaran bulan ini', 'Santri mencapai ambang', 'Pengajuan izin saya', 'Santri binaan'] as $bagian) {
    $assert(str_contains($teks($h), $bagian), "B-3: pengurus/pembimbing melihat {$bagian}");
}
$assert(str_contains($h, 'href="/portal/izin_buat.php"') && str_contains($teks($h), 'Ajukan izin'), 'B-3: tombol Ajukan izin selalu di atas');
$assert(preg_match('/Santri mencapai ambang<\/p><p class="ah-stat__value">1</', $h) === 1 && preg_match('/Pelanggaran bulan ini<\/p><p class="ah-stat__value">1</', $h) === 1, 'B-3: angka ambang (AA) dan pelanggaran bulan ini dari cakupan binaan');
$h = $beranda['sbx_pengurus_biasa'];
$assert(!str_contains($teks($h), 'Sikap belum diisi') && !str_contains($teks($h), 'Santri mencapai ambang'), 'B-3: pengurus bukan pembimbing tidak melihat angka sikap/pembinaan (J)');
$h = $beranda['sbx_pendidikan'];
$assert(str_contains($teks($h), 'Kemajuan semester Ganjil') && str_contains($teks($h), 'Per kelas') && str_contains($h, 'href="/portal/verifikasi_semester.php?rencana_id='), 'B-4: Bagian Pendidikan melihat kemajuan per kelas yang membuka kelengkapan');
$assert(preg_match('/Perlu perbaikan<\/p><p class="ah-stat__value">2</', $h) === 1, 'B-4: jumlah bahan perlu perbaikan sesuai data');
$h = $beranda['sbx_ortu_a'];
$assert(str_contains($h, 'aria-label="Pilih anak"') && str_contains($teks($h), 'Izin terakhir') && str_contains($teks($h), 'Informasi pembinaan') && !str_contains($h, 'method="post" class="d-'), 'B-5: orang tua memilih anak, kartu izin/rapor/info, tanpa tombol pengubah data');
$assert(!preg_match('/Pembimbing<\/dt>/', $h), 'B-5: nama pembimbing belum ditampilkan ke orang tua (menunggu keputusan Z, Tahap 5)');
$ortu = $klien('sbx_ortu_a');
$anakLain = (int) ($sql("SELECT id FROM santri WHERE id NOT IN (SELECT santri_id FROM santri_wali WHERE wali_id = 1) ORDER BY id LIMIT 1")[0]['id'] ?? 0);
$namaLain = (string) ($sql("SELECT nama_santri FROM santri WHERE id = {$anakLain}")[0]['nama_santri'] ?? '');
$h = $ortu->req('/portal/index.php?anak=' . $anakLain)['body'];
$assert($namaLain !== '' && !str_contains($h, 'id="ah-anak-nama">' . htmlspecialchars($namaLain)), 'B-5: ?anak= santri yang bukan anaknya diabaikan');
$anak2 = (int) ($sql("SELECT santri_id FROM santri_wali WHERE wali_id = 1 ORDER BY id DESC LIMIT 1")[0]['santri_id']);
$nama2 = (string) $sql("SELECT nama_santri FROM santri WHERE id = {$anak2}")[0]['nama_santri'];
$assert(str_contains($ortu->req('/portal/index.php?anak=' . $anak2)['body'], 'id="ah-anak-nama">' . htmlspecialchars($nama2)), 'B-5: anak kedua dapat dipilih');

// ============================================================ H2-E notifikasi
echo PHP_EOL . '=== H2-E. Notifikasi untuk semua akun ===' . PHP_EOL;
foreach (['sbx_guru_mapel', 'sbx_pendidikan', 'sbx_guru_biasa', 'sbx_admin', 'sbx_ortu_a'] as $user) {
    $r = $klien($user)->req('/portal/notifikasi.php');
    $assert($r['status'] === 200 && str_contains($r['body'], '<h1>Notifikasi</h1>'), "{$user}: pusat notifikasi terbuka (AK)");
}
$guruMapel = $klien('sbx_guru_mapel');
$milikLain = (int) $sql("SELECT n.id FROM notifikasi_outbox n JOIN users u ON u.id = n.penerima_user_id WHERE u.username = 'sbx_ortu_a' AND n.kanal = 'InApp' AND n.dibaca_pada IS NULL ORDER BY n.id LIMIT 1")[0]['id'];
$judulLain = (string) $sql("SELECT judul FROM notifikasi_outbox WHERE id = {$milikLain}")[0]['judul'];
$isiLain = (string) $sql("SELECT isi FROM notifikasi_outbox WHERE id = {$milikLain}")[0]['isi'];
$list = $guruMapel->req('/portal/notifikasi.php')['body'];
$assert(!str_contains($list, $isiLain), 'Daftar guru mapel tidak memuat notifikasi akun lain');
$r = $guruMapel->req('/portal/notifikasi.php?detail=' . $milikLain);
// Kontrak V2 Fase 4 (WN-5a): daftar milik sendiri tetap tampil dengan pesan
// penolakan (layanan menolak 403), isi notifikasi orang lain tidak pernah tampil.
$assert($r['status'] === 200 && str_contains($r['body'], 'Notifikasi tidak dapat dibuka') && !str_contains($r['body'], $isiLain), 'Detail notifikasi milik orang lain ditolak tanpa isi');
$r = $guruMapel->req('/portal/notifikasi.php?buka=' . $milikLain);
$assert($r['location'] === null && str_contains($r['body'], 'Notifikasi tidak dapat dibuka') && !str_contains($r['body'], $isiLain), 'Buka notifikasi milik orang lain ditolak tanpa pengalihan dan tanpa isi');
$guruMapel->req('/portal/notifikasi.php', ['_csrf' => $guruMapel->csrf('/portal/notifikasi.php'), 'aksi' => 'tandai_dibaca', 'id' => (string) $milikLain]);
$assert($sql("SELECT dibaca_pada FROM notifikasi_outbox WHERE id = {$milikLain}")[0]['dibaca_pada'] === null, 'Tandai dibaca milik orang lain tidak mengubah basis data');
$assert($guruMapel->req('/portal/notifikasi.php', ['aksi' => 'tandai_semua'])['status'] === 419, 'Mutasi notifikasi tanpa CSRF ditolak 419');
$sebelum = (int) $sql("SELECT COUNT(*) n FROM notifikasi_outbox WHERE dibaca_pada IS NULL AND penerima_user_id <> (SELECT id FROM users WHERE username = 'sbx_guru_mapel')")[0]['n'];
$guruMapel->req('/portal/notifikasi.php', ['_csrf' => $guruMapel->csrf('/portal/notifikasi.php'), 'aksi' => 'tandai_semua']);
$sesudah = (int) $sql("SELECT COUNT(*) n FROM notifikasi_outbox WHERE dibaca_pada IS NULL AND penerima_user_id <> (SELECT id FROM users WHERE username = 'sbx_guru_mapel')")[0]['n'];
$milikSendiriBelum = (int) $sql("SELECT COUNT(*) n FROM notifikasi_outbox WHERE dibaca_pada IS NULL AND kanal = 'InApp' AND penerima_user_id = (SELECT id FROM users WHERE username = 'sbx_guru_mapel')")[0]['n'];
$assert($sebelum === $sesudah && $milikSendiriBelum === 0, 'Tandai semua dibaca hanya menyentuh notifikasi milik sendiri');
$ortuKlien = $klien('sbx_ortu_a');
$milik = (int) $sql("SELECT n.id FROM notifikasi_outbox n JOIN users u ON u.id = n.penerima_user_id WHERE u.username = 'sbx_ortu_a' AND n.kanal = 'InApp' AND n.event_type = 'v4_rapor_terbit' LIMIT 1")[0]['id'];
$r = $ortuKlien->req('/portal/notifikasi.php?buka=' . $milik);
$assert($r['status'] === 302 && $sql("SELECT dibaca_pada IS NOT NULL d FROM notifikasi_outbox WHERE id = {$milik}")[0]['d'] === '1', 'Buka notifikasi milik sendiri menandai dibaca lalu menuju tujuannya');
$hr = $ortuKlien->req('/portal/notifikasi.php?jenis=rapor')['body'];
$hp = $ortuKlien->req('/portal/notifikasi.php?jenis=pembinaan')['body'];
$assert(str_contains($hr, 'Rapor semester tersedia') && !str_contains($hr, 'Ada informasi pembinaan untuk anak') && str_contains($hp, 'Ada informasi pembinaan untuk anak') && !str_contains($hp, 'Rapor semester anak Anda'), 'Chip Rapor/Pembinaan menyaring menurut jenis peristiwa');
$assert(preg_match('/ah-notif-group__title">(Hari ini|Kemarin|Pekan ini|Lebih lama)</', $ortuKlien->req('/portal/notifikasi.php')['body']) === 1, 'Daftar dikelompokkan per hari');
$assert($ortuKlien->req('/portal/notifikasi.php?jenis=%27%20OR%201%3D1--')['status'] === 200, 'Kategori tak dikenal diabaikan (bukan masukan SQL)');
$bell = $ortuKlien->req('/portal/index.php')['body'];
$assert(substr_count(substr($bell, (int) strpos($bell, 'ah-bell__list'), 6000), 'ah-bell__item') <= 5 && str_contains($bell, 'Lihat semua notifikasi'), 'Lonceng memuat pratinjau paling banyak 5 dan tautan Lihat semua');
$r = (new Klien($base))->req('/portal/notifikasi.php');
$assert($r['status'] === 302 && str_contains((string) $r['location'], 'pesan=sesi'), 'Tanpa sesi: notifikasi mengarah ke halaman masuk');

// ============================================================ H2-F akun
echo PHP_EOL . '=== H2-F. Masuk, kata sandi, keluar, akses ditolak ===' . PHP_EOL;
$anon = new Klien($base);
$h = $anon->req('/portal/index.php')['body'];
$assert(str_contains($h, 'ah-masuk__id') && str_contains($h, 'data-ah-reveal="password"') && str_contains($h, 'Nama pengguna') && str_contains($h, 'Kata sandi'), 'A-1: dua panel, tombol tampilkan kata sandi');
foreach ([
    'gagal' => 'Nama pengguna atau kata sandi salah.',
    'sesi' => 'Sesi Anda berakhir. Silakan masuk lagi.',
    'logout' => 'Anda sudah keluar.',
    'terkunci' => 'Terlalu banyak percobaan. Coba lagi beberapa saat lagi.',
    'tanpa_akses' => 'Akun ini belum punya hak akses. Hubungi admin.',
] as $kode => $pesan) {
    $assert(str_contains($anon->req('/portal/index.php?pesan=' . $kode)['body'], $pesan), "A-1: pesan {$kode}");
}
$r = $anon->req('/admin/cek_login.php', ['_csrf' => $anon->csrf(), 'username' => 'sbx_admin', 'password' => 'salah']);
$assert(str_contains((string) $r['location'], 'pesan=gagal'), 'A-1: kata sandi salah kembali dengan pesan gagal');

$wajib = $klien('sbx_sandi_wajib');
$r = $wajib->req('/portal/index.php');
$assert($r['status'] === 302 && str_contains((string) $r['location'], 'ubah_password.php'), 'A-3: kata sandi sementara diarahkan ke ganti kata sandi');
$h = $wajib->req('/admin/ubah_password.php')['body'];
$assert(str_contains($h, 'ah-bare') && !str_contains($h, 'id="ah-sidebar"') && !str_contains($h, 'ah-menu-data') && str_contains($h, 'Ganti kata sandi sebelum melanjutkan') && str_contains($h, 'ah-logout'), 'A-3: saat wajib, menu disembunyikan, pesan tampil, Keluar tersedia');
$assert(substr_count($h, 'data-ah-rule=') === 4, 'A-3: empat syarat sebagai daftar centang');
$r = $wajib->req('/admin/ubah_password.php', ['_csrf' => $wajib->csrf('/admin/ubah_password.php'), 'password_saat_ini' => 'Sandbox#123', 'password_baru' => 'pendek', 'konfirmasi_password' => 'pendek']);
$assert(str_contains($r['body'], 'minimal 12 karakter'), 'A-3: server tetap memeriksa syarat kata sandi');
$h = $admin->req('/admin/ubah_password.php')['body'];
$assert(str_contains($h, 'id="ah-sidebar"') && str_contains($h, 'Simpan kata sandi') && !str_contains($h, 'ah-bare'), 'A-3: tanpa kewajiban, halaman di dalam kerangka biasa');

$h = $admin->req('/portal/index.php')['body'];
$assert(preg_match('/<form method="post" action="\/admin\/logout\.php" class="ah-logout" data-confirm="Anda perlu masuk lagi untuk membuka data\." data-confirm-title="Keluar dari Sistem Al Hasan\?" data-confirm-ok="Ya, keluar"><input type="hidden" name="_csrf"/', $h) === 1, 'A-4: Keluar berupa POST ber-CSRF dengan dialog');
$assert(!preg_match('/<a[^>]+href="\/admin\/logout\.php"/', $h), 'A-4: tidak ada tautan GET untuk keluar di kerangka');
$keluar = $klien('sbx_guru_biasa');
$r = $keluar->req('/admin/logout.php');
$assert($r['status'] === 200 && str_contains($r['body'], 'Keluar dari Sistem Al Hasan?') && str_contains($keluar->req('/portal/index.php')['body'], 'ah-topbar'), 'A-4: GET logout hanya menampilkan konfirmasi; sesi tetap');
$assert($keluar->req('/admin/logout.php', ['x' => '1'])['status'] === 419, 'A-4: POST logout tanpa CSRF ditolak');
$r = $keluar->req('/admin/logout.php', ['_csrf' => $keluar->csrf()]);
$assert(str_contains((string) $r['location'], 'pesan=logout') && str_contains($keluar->req('/portal/index.php')['body'], 'ah-masuk__id'), 'A-4: POST ber-CSRF mengakhiri sesi');

foreach ([
    ['sbx_guru_mapel', '/admin/admin_master_santri.php', 'hanya untuk admin'],
    ['sbx_guru_mapel', '/portal/izin.php', 'Perizinan hanya untuk'],
    ['sbx_ortu_a', '/portal/verifikasi_semester.php', 'Kelengkapan semester hanya untuk Bagian Pendidikan'],
    ['sbx_ortu_a', '/portal/rencana_nilai.php', 'Rencana nilai hanya untuk Bagian Pendidikan'],
    ['sbx_murobi_a', '/portal/rapor_semester.php', 'Penerbitan rapor hanya untuk Bagian Pendidikan'],
    ['sbx_pengurus_a', '/portal/rapor_penanda.php', 'Penanda tangan rapor hanya untuk admin'],
    ['sbx_guru_biasa', '/portal/sikap_semester.php', 'Sikap semester hanya untuk'],
    ['sbx_ortu_a', '/portal/nilai_semester.php', 'Nilai semester hanya untuk'],
] as [$user, $path, $pesan]) {
    $r = $klien($user)->req($path);
    $assert($r['status'] === 403 && str_contains($r['body'], 'class="ah-topbar"') && str_contains($r['body'], 'Halaman ini bukan untuk peran Anda') && str_contains($r['body'], $pesan) && str_contains($r['body'], 'minta admin pesantren') && !str_contains($r['body'], '403 — Akses ditolak.'), "A-5: {$user} {$path} → 403 di kerangka: {$pesan}");
}

// ============================================================ H2-G log server
echo PHP_EOL . '=== H2-G. Log server ===' . PHP_EOL;
usleep(300000);
$galat = [];
foreach ($logs as $log) {
    foreach (file($log) ?: [] as $baris) {
        if (preg_match('/(PHP (Fatal|Warning|Notice|Deprecated)|Uncaught|Beranda: )/', $baris) === 1) {
            $galat[] = trim($baris);
        }
    }
}
$assert($galat === [], 'Tanpa galat/peringatan PHP dan tanpa kartu Beranda yang gagal dihitung' . ($galat ? ': ' . implode(' | ', array_slice($galat, 0, 5)) : ''));

echo PHP_EOL . 'Ringkasan: ' . $lulus . ' lulus, ' . count($gagal) . ' gagal' . PHP_EOL;
foreach ($gagal as $pesan) {
    echo ' - ' . $pesan . PHP_EOL;
}
exit($gagal === [] ? 0 : 1);
