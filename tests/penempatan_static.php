<?php

declare(strict_types=1);

/**
 * Pemeriksaan statis "Penempatan Kelas & Kamar Santri"
 * (keputusan pengguna 6 September 2026).
 *
 * Tidak memerlukan basis data maupun peramban. Yang diperiksa:
 *
 *   PS-1  seluruh berkas yang dibuat/diubah lolos lint PHP;
 *   PS-2  tidak ada lagi query kamar yang ditulis langsung di halaman admin;
 *   PS-3  seluruh SQL penempatan memakai prepared statement (tanpa interpolasi);
 *   PS-4  halaman penempatan memakai kerangka bersama master_header/footer;
 *   PS-5  navigasi memuat kunci baru `master.penempatan` dan tautannya;
 *   PS-6  alamat lama hanya mengalihkan GET dan MENOLAK POST (bukan redirect buta);
 *   PS-7  mutasi hanya lewat POST, ber-CSRF, dan bertoken sekali pakai;
 *   PS-8  layanan memakai transaksi, penguncian baris, dan urutan kunci tetap;
 *   PS-9  audit ditulis di dalam transaksi dan kegagalannya membatalkan operasi;
 *   PS-10 tidak ada penghapusan riwayat tahun ajaran lain dan tidak ada
 *         perbaikan data otomatis;
 *   PS-11 seluruh keluaran data di halaman di-escape;
 *   PS-12 halaman menyediakan seluruh filter, pagination, dan keadaan kosong
 *         yang diminta;
 *   PS-13 tidak ada keputusan keamanan yang hanya bergantung pada JavaScript;
 *   PS-14 tautan menuju penempatan tersedia dari Santri, Kelas, dan Kamar;
 *   PS-15 tidak ada berkas migrasi baru (deployment cukup pembaruan kode).
 *
 * Jalankan:
 *   php tests/penempatan_static.php
 */

$root = dirname(__DIR__);

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    echo ($condition ? '[lulus] ' : '[gagal] ') . $message . PHP_EOL;
    if (!$condition) {
        $failures[] = $message;
    }
};
$source = static fn (string $path): string => (string) @file_get_contents($root . '/' . $path);

/** Membuang komentar sebelum menilai larangan: yang dinilai KODE, bukan dokumentasi. */
$tanpaKomentar = static function (string $php): string {
    if (trim($php) === '') {
        return '';
    }
    $bersih = '';
    foreach (token_get_all($php) as $token) {
        if (is_array($token)) {
            if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $bersih .= $token[1];
            continue;
        }
        $bersih .= $token;
    }

    return $bersih;
};

$berkasBaru = [
    'app/MasterData/PenempatanRepository.php',
    'app/MasterData/PenempatanService.php',
    'app/MasterData/PenempatanConflictException.php',
    'admin/admin_penempatan_santri.php',
    'bin/penempatan_preflight.php',
    'tests/penempatan_static.php',
    'tests/penempatan_integration.php',
    'tests/penempatan_concurrency.php',
    'tests/penempatan_concurrency_worker.php',
    'tests/penempatan_web_smoke.php',
];
$berkasDiubah = [
    'app/bootstrap.php',
    'app/Ui/Navigation.php',
    'admin/admin_santri.php',
    'admin/admin_master_santri.php',
    'admin/admin_kelas.php',
    'admin/admin_kamar.php',
    'admin/admin_dashboard.php',
];

// ============================================================== PS-1
foreach ([...$berkasBaru, ...$berkasDiubah] as $berkas) {
    $keluaran = [];
    $kode = 0;
    exec(escapeshellcmd(PHP_BINARY) . ' -l ' . escapeshellarg($root . '/' . $berkas) . ' 2>&1', $keluaran, $kode);
    $assert($kode === 0, 'PS-1 lint bersih: ' . $berkas);
}

// ============================================================== PS-2
$lama = $source('admin/admin_santri.php');
$assert(!str_contains($lama, 'DELETE FROM plotting_kamar'), 'PS-2 halaman lama tidak lagi menghapus baris kamar');
$assert(!str_contains($lama, 'INSERT INTO plotting_kamar'), 'PS-2 halaman lama tidak lagi menyisipkan baris kamar');
$assert(!str_contains($lama, 'mysqli_real_escape_string'), 'PS-2 halaman lama tidak lagi menyusun SQL dengan escape string');
$assert(!str_contains($tanpaKomentar($lama), 'bulk_update_plot') && !str_contains($tanpaKomentar($lama), 'update_plot'), 'PS-2 endpoint AJAX lama tanpa CSRF sudah hilang');

$halaman = $source('admin/admin_penempatan_santri.php');
foreach (['plotting_kamar', 'plotting_kelas', 'mysqli_query', 'mysqli_real_escape_string', '->query('] as $terlarang) {
    $assert(!str_contains($tanpaKomentar($halaman), $terlarang), 'PS-2 halaman penempatan tidak menyentuh basis data langsung: ' . $terlarang);
}

// ============================================================== PS-3
$repo = $source('app/MasterData/PenempatanRepository.php');
$repoKode = $tanpaKomentar($repo);
$assert(substr_count($repoKode, '$this->db->prepare(') >= 2, 'PS-3 repository menyiapkan seluruh query lewat prepare()');
$assert(!str_contains($repoKode, 'real_escape_string'), 'PS-3 repository tidak memakai escape string');
$assert(preg_match('/\$this->(all|one|execute|insert)\(\s*[\'"][^\'"]*\.\s*\$(?!placeholders)/', $repoKode) !== 1, 'PS-3 tidak ada nilai variabel yang disambung ke SQL selain placeholder');
$assert(
    substr_count($repoKode, "implode(',', array_fill(0, count(") >= 2,
    'PS-3 daftar ID dikirim sebagai placeholder, bukan nilai yang disambung'
);
$assert(str_contains($repoKode, 'bind_param'), 'PS-3 seluruh parameter diikat dengan bind_param');
$assert(
    str_contains($repoKode, 'private const KOLOM_CARI'),
    'PS-3 kolom pencarian berasal dari konstanta repository, bukan input URL'
);

// ============================================================== PS-4
$assert(str_contains($halaman, "require_once __DIR__ . '/_master_ui.php'"), 'PS-4 halaman memakai adaptor kerangka bersama');
$assert(str_contains($halaman, 'master_header(') && str_contains($halaman, 'master_footer()'), 'PS-4 halaman memakai master_header dan master_footer');
$assert(str_contains($halaman, "'breadcrumbs' =>"), 'PS-4 breadcrumb halaman diatur eksplisit');
$assert(str_contains($halaman, "'active' => 'master.penempatan'"), 'PS-4 menu aktif ditandai dengan kunci baru');
foreach (['ah-card', 'ah-table', 'ah-actions', 'ah_badge(', 'ah-stat'] as $komponen) {
    $assert(str_contains($halaman, $komponen), 'PS-4 halaman memakai komponen bersama: ' . $komponen);
}

// ============================================================== PS-5
$nav = $source('app/Ui/Navigation.php');
$assert(str_contains($nav, "self::item('master.penempatan', 'Penempatan Kelas & Kamar', '/admin/admin_penempatan_santri.php'"), 'PS-5 menu Master Data memuat Penempatan Kelas & Kamar');
$assert(str_contains($nav, "'admin_penempatan_santri.php' => 'master.penempatan'"), 'PS-5 skrip baru dipetakan ke kunci menu aktif');
$assert(str_contains($nav, "'admin_santri.php' => 'master.penempatan'"), 'PS-5 alamat lama dipetakan ke kunci menu yang sama');
$assert(!str_contains($nav, 'master.santri_lama'), 'PS-5 kunci menu lama yang membingungkan sudah tidak dipakai');

// ============================================================== PS-6
$assert(str_contains($lama, "http_response_code(301)"), 'PS-6 alamat lama mengalihkan permintaan GET (bookmark tetap hidup)');
$assert(str_contains($lama, 'admin_penempatan_santri.php'), 'PS-6 pengalihan menuju halaman penempatan baru');
$assert(str_contains($lama, "http_response_code(410)"), 'PS-6 POST lama dihentikan dengan 410, bukan dialihkan');
$assert(
    preg_match('/REQUEST_METHOD\'\]\s*!==\s*\'GET\'/', $lama) === 1,
    'PS-6 pemeriksaan metode dilakukan sebelum pengalihan apa pun'
);
$assert(
    str_contains($lama, "authorization()->requireWebRole('admin')"),
    'PS-6 alamat lama tetap dijaga peran admin'
);
$assert(
    strpos($lama, "requireWebRole('admin')") < strpos($lama, 'http_response_code(410)'),
    'PS-6 guard admin berjalan sebelum jawaban 410, sehingga alamat lama tidak terbuka untuk tamu'
);
foreach (['cari' => 'q', 'filter_status' => 'status'] as $parameterLama => $parameterBaru) {
    $assert(str_contains($lama, "'" . $parameterLama . "'") && str_contains($lama, "'" . $parameterBaru . "'"), 'PS-6 parameter lama ' . $parameterLama . ' dipetakan ke ' . $parameterBaru);
}

// ============================================================== PS-7
$assert(str_contains($halaman, "require_once __DIR__ . '/_guard.php'"), 'PS-7 halaman dijaga guard admin (peran admin + CSRF POST)');
$assert(substr_count($halaman, 'master_csrf()') >= 3, 'PS-7 setiap formulir menyertakan token CSRF');
$assert(str_contains($halaman, "\$_SERVER['REQUEST_METHOD'] === 'POST'"), 'PS-7 mutasi hanya diproses pada POST');
$assert(str_contains($halaman, 'http_response_code(405)'), 'PS-7 aksi lewat metode selain POST ditolak 405');
$assert(str_contains($halaman, 'ah_form_token_consume('), 'PS-7 penerapan memakai token sekali pakai');
$assert(!preg_match('/<form[^>]*method="get"[^>]*>[^<]*<input[^>]*name="action"/i', $halaman), 'PS-7 tidak ada formulir GET yang mengirim aksi mutasi');
$assert(substr_count($halaman, "method=\"post\"") >= 3, 'PS-7 seluruh formulir aksi memakai metode POST');

// ============================================================== PS-8
$service = $source('app/MasterData/PenempatanService.php');
$serviceKode = $tanpaKomentar($service);
$assert(str_contains($serviceKode, 'begin_transaction()') && str_contains($serviceKode, '->commit()') && str_contains($serviceKode, '->rollback()'), 'PS-8 penerapan berjalan dalam satu transaksi dengan rollback');
$assert(str_contains($serviceKode, 'READ COMMITTED'), 'PS-8 transaksi memakai isolasi yang membuat kunci baris benar-benar menjaga kapasitas');
$assert(str_contains($serviceKode, 'lockSantri(') && str_contains($serviceKode, 'lockRooms('), 'PS-8 baris santri dan kamar dikunci sebelum perubahan');
$assert(str_contains($repoKode, 'FOR UPDATE'), 'PS-8 penguncian memakai SELECT ... FOR UPDATE');
$assert(substr_count($repoKode, 'ORDER BY id FOR UPDATE') >= 2, 'PS-8 penguncian selalu menurut ID menaik (urutan konsisten, mengurangi deadlock)');
$badanApply = (string) substr($serviceKode, (int) strpos($serviceKode, 'public function apply('));
$badanApply = (string) substr($badanApply, 0, (int) strpos($badanApply, 'private function rencana('));
$badanRencana = (string) substr($serviceKode, (int) strpos($serviceKode, 'private function rencana('));
$assert(
    strpos($badanApply, 'lockSantri(') !== false
    && strpos($badanApply, 'lockSantri(') < strpos($badanApply, '$this->rencana('),
    'PS-8 santri dikunci lebih dahulu, kamar menyusul di dalam rencana'
);
$assert(
    strpos($badanRencana, 'roomAssignments(') < strpos($badanRencana, 'lockRooms(')
    && strpos($badanRencana, 'lockRooms(') < strpos($badanRencana, 'roomOccupancy('),
    'PS-8 kapasitas dihitung SETELAH baris kamar terkunci'
);
$assert(str_contains($serviceKode, 'KODE_KONFLIK_KUNCI') && str_contains($repoKode, 'PenempatanConflictException'), 'PS-8 deadlock/lock wait diterjemahkan menjadi pesan "coba lagi"');
$assert(
    str_contains($serviceKode, 'translateFailure(') && str_contains($serviceKode, '$errno = $db->errno;'),
    'PS-8 galat dari jalur kelas (MasterDataRepository) ikut diterjemahkan, bukan menjadi galat 500'
);
$assert(
    str_contains($serviceKode, "\$db->query('SET TRANSACTION ISOLATION LEVEL READ COMMITTED') === false")
    && str_contains($serviceKode, '$db->begin_transaction() === false'),
    'PS-8 nilai balik penyetelan isolasi dan pembukaan transaksi diperiksa, bukan diabaikan'
);
$assert(
    str_contains($serviceKode, 'KODE_BINLOG_STATEMENT'),
    'PS-8 galat binlog_format=STATEMENT diterjemahkan menjadi pesan konfigurasi server'
);
$assert(
    str_contains($source('bin/penempatan_preflight.php'), 'binlog_format'),
    'PS-8 preflight memeriksa binlog_format sebelum rilis'
);
$assert(
    str_contains($serviceKode, 'isRelease(') && str_contains($serviceKode, 'assertSantriUsable($ids, $santri, $this->isRelease($aksi))'),
    'PS-8 santri nonaktif/arsip tetap dapat DIKELUARKAN dari kamar meski tidak dapat ditempatkan'
);
$assert(str_contains($serviceKode, 'BATAS_MASSAL'), 'PS-8 operasi massal dibatasi agar transaksi tidak mengunci terlalu lama');
$assert(str_contains($serviceKode, 'roomOccupancy('), 'PS-8 kapasitas dihitung ulang di dalam transaksi');

// ============================================================== PS-9
$assert(str_contains($serviceKode, '$this->audit->log('), 'PS-9 audit ditulis oleh layanan, bukan halaman');
$assert(
    preg_match('/if\s*\(!\$this->audit->log\(/', $serviceKode) === 1
    || str_contains($serviceKode, 'if (!$tercatat)'),
    'PS-9 kegagalan audit membatalkan perubahan penempatan'
);
$assert(
    str_contains($serviceKode, "'penempatan.' . \$jenis . '.' . (\$keluar ? 'keluarkan' : 'tetapkan')"),
    'PS-9 nama tindakan audit membedakan kelas/kamar dan tetapkan/keluarkan'
);
$assert(
    str_contains($serviceKode, "'penempatan.' . \$rencana['jenis'] . '.massal'"),
    'PS-9 tindakan massal memiliki catatan audit ringkasannya sendiri'
);
$assert(
    str_contains($serviceKode, "'plotting_kamar'") && str_contains($serviceKode, "'plotting_kelas'"),
    'PS-9 audit menyebut entitas plotting_kamar dan plotting_kelas'
);
foreach (['jumlah_santri', 'mode', 'alasan', 'tahun_ajaran_id', 'nis', 'nama_santri'] as $bidang) {
    $assert(str_contains($serviceKode, "'" . $bidang . "'"), 'PS-9 audit memuat bidang wajib: ' . $bidang);
}
foreach (['password', 'token', 'api_token'] as $rahasia) {
    $assert(!preg_match('/[\'"]' . $rahasia . '[\'"]\s*=>/', $serviceKode), 'PS-9 audit tidak pernah memuat ' . $rahasia);
}

// ============================================================== PS-10
$assert(
    substr_count($repoKode, 'DELETE FROM plotting_kamar') === 1
    && str_contains($repoKode, 'DELETE FROM plotting_kamar WHERE id = ? AND id_tahun = ?'),
    'PS-10 penghapusan kamar hanya untuk satu baris pada tahun ajaran yang sedang dikerjakan'
);
$assert(!str_contains($repoKode, 'DELETE FROM plotting_kelas'), 'PS-10 riwayat kelas tidak pernah dihapus');
foreach (['TRUNCATE', 'DROP TABLE', 'DROP COLUMN'] as $terlarang) {
    $assert(!str_contains($repoKode, $terlarang), 'PS-10 repository tidak memuat perintah merusak: ' . $terlarang);
}
$preflight = $source('bin/penempatan_preflight.php');
$preflightKode = $tanpaKomentar($preflight);
foreach (['INSERT', 'UPDATE ', 'DELETE'] as $tulis) {
    $assert(!str_contains($preflightKode, $tulis), 'PS-10 preflight hanya membaca, tidak pernah menulis: ' . $tulis);
}
$assert(str_contains($serviceKode, 'penempatan_preflight.php'), 'PS-10 konflik data mengarahkan admin ke preflight, bukan diperbaiki otomatis');

// ============================================================== PS-11
$assert(!preg_match('/<\?=\s*\$row\[[^\]]+\]\s*\?>/', $halaman), 'PS-11 tidak ada nilai baris yang dicetak tanpa escape');
$assert(!preg_match('/<\?=\s*\$_(GET|POST|REQUEST)/', $halaman), 'PS-11 tidak ada input pengguna yang dicetak langsung');
$assert(substr_count($halaman, 'master_e(') >= 20, 'PS-11 keluaran data memakai master_e()');

// ============================================================== PS-12
foreach ([
    'name="q"' => 'pencarian nama atau NIS',
    'name="jk"' => 'filter jenis kelamin',
    'name="sekolah"' => 'filter unit sekolah',
    'id="filter_kelas"' => 'filter kelas',
    'id="filter_kamar"' => 'filter kamar',
    'value="tanpa_kelas"' => 'filter belum mempunyai kelas',
    'value="tanpa_kamar"' => 'filter belum mempunyai kamar',
] as $penanda => $keterangan) {
    $assert(str_contains($halaman, $penanda), 'PS-12 halaman menyediakan ' . $keterangan);
}
$assert(str_contains($halaman, 'master_pagination('), 'PS-12 daftar memakai pagination server');
$assert(str_contains($halaman, 'listPage('), 'PS-12 data diambil per halaman, bukan seluruh santri sekaligus');
$assert(substr_count($halaman, 'ah_empty(') >= 2, 'PS-12 tersedia keadaan kosong dan keadaan "tidak ada hasil pencarian"');
$assert(str_contains($halaman, 'data-jumlah-terpilih'), 'PS-12 jumlah santri terpilih ditampilkan');
$assert(str_contains($halaman, 'sisa '), 'PS-12 sisa tempat kamar ditampilkan pada pilihan kamar');
$assert(str_contains($halaman, 'Konfirmasi perubahan penempatan'), 'PS-12 tersedia layar konfirmasi sebelum perubahan diterapkan');
$assert(str_contains($halaman, 'santri pada halaman lain tidak pernah ikut terpilih'), 'PS-12 perilaku pilihan lintas halaman dijelaskan kepada admin');
$assert(
    substr_count($halaman, 'aria-label="Tempatkan <?= master_e($row[\'nama_santri\']) ?> ke') === 2,
    'PS-12 setiap pilihan pada baris tabel memiliki nama aksesibel yang menyebut santrinya'
);
$assert(
    substr_count($halaman, '<label class="form-label" for=') >= 8,
    'PS-12 setiap kontrol filter dan massal memiliki label yang terhubung'
);
$assert(str_contains($halaman, 'aria-label="Pilih semua santri pada halaman ini"'), 'PS-12 kotak centang "pilih semua" punya label yang jujur');

// ============================================================== PS-13
$posisiScript = strpos($halaman, '<script>');
$skrip = $posisiScript === false ? '' : substr($halaman, $posisiScript);
$assert($skrip !== '', 'PS-13 halaman memuat blok skrip bantu tampilan');
foreach (['fetch(', 'XMLHttpRequest', '$.ajax'] as $panggilan) {
    $assert(!str_contains($skrip, $panggilan), 'PS-13 tidak ada mutasi lewat AJAX tanpa formulir ber-CSRF: ' . $panggilan);
}
$assert(str_contains($halaman, 'bekerja penuh tanpa JavaScript'), 'PS-13 halaman menyatakan bekerja tanpa JavaScript');
$assert(str_contains($serviceKode, 'assignableClass(') && str_contains($serviceKode, 'assignableRoom('), 'PS-13 kelas/kamar tujuan divalidasi ulang di server (dropdown bukan validasi)');

// ============================================================== PS-14
$assert(str_contains($source('admin/admin_master_santri.php'), 'admin_penempatan_santri.php'), 'PS-14 tautan penempatan tersedia dari Data Santri');
$assert(str_contains($source('admin/admin_kelas.php'), 'admin_penempatan_santri.php?kelas_id='), 'PS-14 Data Kelas menautkan penempatan dengan filter kelas');
$kamarPage = $source('admin/admin_kamar.php');
$assert(str_contains($kamarPage, 'admin_penempatan_santri.php?kamar_id='), 'PS-14 Data Kamar menautkan penempatan dengan filter kamar');
$assert(str_contains($kamarPage, 'Kelola penempatan kamar ini'), 'PS-14 daftar penghuni kamar menyediakan tindakan menuju penempatan');
$assert(str_contains($source('admin/admin_dashboard.php'), 'admin_penempatan_santri.php'), 'PS-14 aksi cepat dasbor menunjuk halaman penempatan baru');

// ============================================================== PS-15
$migrasi = glob($root . '/database/migrations/*.sql') ?: [];
$rollback = glob($root . '/database/rollbacks/*.sql') ?: [];
// Disesuaikan pada paket "Koreksi Pengelolaan Alumni" (6 September 2026).
//
// Sebelumnya baris ini mematok JUMLAH berkas migrasi seluruh repositori pada
// angka 10. Patokan itu keliru sasaran: yang hendak dijamin PS-15 adalah
// "paket PENEMPATAN tidak menambah migrasi", bukan "repositori ini tidak boleh
// pernah bertambah migrasi lagi". Paket alumni menambah `011_koreksi_alumni.sql`
// secara sah, dan patokan lama akan melaporkannya sebagai kegagalan penempatan.
//
// Yang diperiksa sekarang adalah maksud aslinya: tidak ada satu pun berkas
// migrasi milik paket penempatan.
$migrasiPenempatan = array_filter(
    $migrasi,
    static fn (string $berkas): bool => str_contains(strtolower(basename($berkas)), 'penempatan')
);
$assert($migrasiPenempatan === [], 'PS-15 paket penempatan tidak menambah berkas migrasi apa pun');
$assert(count($migrasi) === count($rollback), 'PS-15 setiap migrasi tetap berpasangan dengan rollback');
$assert(is_file($root . '/docs/penempatan-santri/migration-and-rollback.md'), 'PS-15 dokumen migrasi/rollback tersedia dan menyatakan keputusan tanpa migrasi');
$dokumenMigrasi = $source('docs/penempatan-santri/migration-and-rollback.md');
$assert(str_contains($dokumenMigrasi, 'TIDAK ADA MIGRASI'), 'PS-15 dokumen menyatakan secara eksplisit bahwa deployment hanya perlu pembaruan kode');
foreach ([
    'docs/penempatan-santri/rencana.md',
    'docs/penempatan-santri/aturan-bisnis.md',
    'docs/penempatan-santri/acceptance-status.md',
    'docs/penempatan-santri/test-results.md',
    'docs/penempatan-santri/cpanel-deployment.md',
] as $dokumen) {
    $assert(is_file($root . '/' . $dokumen), 'PS-15 dokumentasi tersedia: ' . $dokumen);
}

echo PHP_EOL;
if ($failures === []) {
    echo 'SELURUH PEMERIKSAAN STATIS PENEMPATAN LULUS.' . PHP_EOL;
    exit(0);
}
echo 'GAGAL (' . count($failures) . '):' . PHP_EOL . ' - ' . implode(PHP_EOL . ' - ', $failures) . PHP_EOL;
exit(1);
