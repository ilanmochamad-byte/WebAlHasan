<?php

declare(strict_types=1);

/**
 * Pemeriksaan statis "Koreksi Pengelolaan Alumni"
 * (keputusan pengguna 6 September 2026).
 *
 * Tidak memerlukan basis data maupun peramban. Yang diperiksa:
 *
 *   AS-1  seluruh berkas yang dibuat/diubah lolos lint PHP;
 *   AS-2  tidak ada lagi penghapusan permanen catatan alumni di mana pun;
 *   AS-3  tidak ada lagi perubahan data lewat parameter GET (`?hapus=ID`);
 *   AS-4  halaman alumni tidak menyentuh basis data langsung;
 *   AS-5  seluruh SQL alumni memakai prepared statement tanpa interpolasi;
 *   AS-6  halaman memakai kerangka bersama master_header/footer + sidebar;
 *   AS-7  navigasi memuat kunci `alumni.kelulusan` beserta tautannya;
 *   AS-8  alamat lama hanya mengalihkan GET dan MENOLAK POST;
 *   AS-9  mutasi hanya lewat POST, ber-CSRF, dan bertoken sekali pakai;
 *   AS-10 layanan memakai transaksi, penguncian baris, dan urutan kunci tetap;
 *   AS-11 audit ditulis di dalam transaksi dan kegagalannya membatalkan operasi;
 *   AS-12 tidak ada penghapusan berkas foto dan tidak ada perbaikan data otomatis;
 *   AS-13 seluruh keluaran data di halaman di-escape;
 *   AS-14 halaman menyediakan seluruh filter, pagination, dan keadaan kosong;
 *   AS-15 tidak ada keputusan keamanan yang hanya bergantung pada JavaScript;
 *   AS-16 migrasi 011 aditif, idempoten, dan berpasangan dengan rollback;
 *   AS-17 tidak ada deduplikasi berdasarkan kesamaan nama;
 *   AS-18 tautan alur kelulusan tersedia dari halaman Master Data Santri;
 *   AS-19 status keluar terbatas pada Lulus/Pindah/Berhenti;
 *   AS-20 skrip CLI hanya membaca kecuali diminta `--terapkan`.
 *
 * Jalankan:
 *   php tests/alumni_static.php
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
    'app/MasterData/AlumniRepository.php',
    'app/MasterData/AlumniService.php',
    'app/MasterData/AlumniConflictException.php',
    'admin/admin_kelulusan_santri.php',
    'bin/alumni_preflight.php',
    'bin/alumni_backfill.php',
    'bin/alumni_verify.php',
    'tests/alumni_static.php',
    'tests/alumni_integration.php',
    'tests/alumni_web_smoke.php',
    'tests/alumni_concurrency.php',
    'tests/alumni_concurrency_worker.php',
];
$berkasDiubah = [
    'app/bootstrap.php',
    'tests/penempatan_static.php',
    'app/Ui/Navigation.php',
    'admin/admin_alumni.php',
    'admin/proses_mutasi_alumni.php',
    'admin/admin_master_santri.php',
];

// ============================================================== AS-1
foreach ([...$berkasBaru, ...$berkasDiubah] as $berkas) {
    $keluaran = [];
    $kode = 0;
    exec(escapeshellcmd(PHP_BINARY) . ' -l ' . escapeshellarg($root . '/' . $berkas) . ' 2>&1', $keluaran, $kode);
    $assert($kode === 0, 'AS-1 lint bersih: ' . $berkas);
}

// ============================================================== AS-2
$halamanAlumni = $source('admin/admin_alumni.php');
$prosesLama = $source('admin/proses_mutasi_alumni.php');
$layanan = $source('app/MasterData/AlumniService.php');
$repo = $source('app/MasterData/AlumniRepository.php');
$halamanProses = $source('admin/admin_kelulusan_santri.php');

foreach ([
    'admin/admin_alumni.php' => $halamanAlumni,
    'admin/proses_mutasi_alumni.php' => $prosesLama,
    'admin/admin_kelulusan_santri.php' => $halamanProses,
    'app/MasterData/AlumniService.php' => $layanan,
    'app/MasterData/AlumniRepository.php' => $repo,
] as $nama => $isi) {
    $kode = $tanpaKomentar($isi);
    $assert(!str_contains($kode, 'DELETE FROM alumni'), 'AS-2 tidak ada penghapusan permanen catatan alumni: ' . $nama);
    $assert(!str_contains($kode, 'unlink('), 'AS-12 tidak ada penghapusan berkas foto: ' . $nama);
}
$assert(
    str_contains($tanpaKomentar($repo), 'archived_at = NOW()') && str_contains($tanpaKomentar($repo), 'archived_at = NULL'),
    'AS-2 penghapusan digantikan pasangan arsip dan pemulihan'
);

// ============================================================== AS-3
$kodeAlumni = $tanpaKomentar($halamanAlumni);
$assert(str_contains($kodeAlumni, "isset(\$_GET['hapus'])"), 'AS-3 alamat lama ?hapus=ID ditangani secara eksplisit');
$assert(str_contains($kodeAlumni, 'http_response_code(405)'), 'AS-3 alamat lama ?hapus=ID dijawab 405, bukan dijalankan');
$assert(
    preg_match('/\$_GET\[[^\]]*\]\s*;?\s*\n?\s*(mysqli_query|->query)/', $kodeAlumni) !== 1,
    'AS-3 tidak ada nilai GET yang langsung dieksekusi sebagai query'
);
foreach (['koreksi', 'arsip', 'pulihkan', 'batalkan', 'hubungkan'] as $aksi) {
    $assert(
        str_contains($kodeAlumni, "case '" . $aksi . "':"),
        'AS-3 aksi ' . $aksi . ' hanya ditangani pada cabang POST'
    );
}
$assert(
    preg_match('/REQUEST_METHOD.{0,20}===\s*.POST./', $kodeAlumni) === 1,
    'AS-3 seluruh aksi alumni berada di dalam cabang REQUEST_METHOD === POST'
);
$assert(
    str_contains($tanpaKomentar($halamanProses), "REQUEST_METHOD'] !== 'POST' && isset(\$_GET['action'])"),
    'AS-3 aksi proses lewat GET ditolak sebelum apa pun dikerjakan'
);

// ============================================================== AS-4
foreach (['mysqli_query', 'mysqli_real_escape_string', 'mysqli_fetch_array', '->query(', 'INSERT INTO alumni', 'SELECT * FROM alumni'] as $terlarang) {
    $assert(!str_contains($kodeAlumni, $terlarang), 'AS-4 halaman alumni tidak menyentuh basis data langsung: ' . $terlarang);
    $assert(!str_contains($tanpaKomentar($halamanProses), $terlarang), 'AS-4 halaman kelulusan tidak menyentuh basis data langsung: ' . $terlarang);
}

// ============================================================== AS-5
$repoKode = $tanpaKomentar($repo);
$assert(substr_count($repoKode, '$this->db->prepare(') >= 2, 'AS-5 repository menyiapkan seluruh query lewat prepare()');
$assert(!str_contains($repoKode, 'real_escape_string'), 'AS-5 repository tidak memakai escape string');
$assert(str_contains($repoKode, 'bind_param'), 'AS-5 seluruh parameter diikat dengan bind_param');
// Penyambungan string SQL hanya boleh memakai potongan yang disusun repository
// sendiri: daftar placeholder, klausa WHERE/FROM internal, dan pembatas LIMIT
// bertipe int. Nilai apa pun dari pemanggil harus lewat bind_param.
$assert(
    preg_match('/\$this->(all|one|execute|insert)\(\s*[\'"][^\'"]*[\'"]\s*\.\s*\$(?!placeholders|this->limit\(|from|where)/', $repoKode) !== 1,
    'AS-5 tidak ada nilai variabel yang disambung ke SQL selain potongan internal repository'
);
$posisiListWhere = strpos($repoKode, 'private function listWhere(');
$badanListWhere = $posisiListWhere === false ? '' : substr($repoKode, $posisiListWhere, 2000);
$assert($badanListWhere !== '', 'AS-5 penyusun klausa WHERE daftar alumni dapat diperiksa');
// Nilai filter boleh disambung ke NILAI PARAMETER (mis. '%' . $filters['q'] . '%'),
// tetapi tidak pernah ke potongan SQL-nya. Yang diperiksa adalah baris yang
// menambah klausa, bukan baris yang menambah parameter.
$barisKlausa = array_filter(
    explode("\n", $badanListWhere),
    static fn (string $baris): bool => str_contains($baris, '$klausa[]') || str_contains($baris, '$bagian[]')
);
$assert($barisKlausa !== [], 'AS-5 penyusun klausa WHERE ditemukan untuk diperiksa');
$assert(
    array_filter($barisKlausa, static fn (string $baris): bool => str_contains($baris, '$filters')) === [],
    'AS-5 tidak ada nilai filter yang disambung ke potongan SQL klausa WHERE'
);
$assert(
    substr_count($badanListWhere, '$params[] =') >= 4,
    'AS-5 seluruh nilai filter dikirim sebagai parameter terikat'
);
$assert(
    substr_count($repoKode, "implode(',', array_fill(0, count(") >= 2,
    'AS-5 daftar ID dan NIS dikirim sebagai placeholder, bukan nilai yang disambung'
);
$assert(str_contains($repoKode, 'private const KOLOM_CARI'), 'AS-5 kolom pencarian berasal dari konstanta repository, bukan input URL');
$assert(
    !preg_match('/LIMIT\s*[\'"]?\s*\.\s*\$(?!this->limit)/', $repoKode),
    'AS-5 nilai LIMIT selalu melewati pembatas repository'
);

// ============================================================== AS-6
foreach (['admin/admin_alumni.php' => $halamanAlumni, 'admin/admin_kelulusan_santri.php' => $halamanProses] as $nama => $isi) {
    $assert(str_contains($isi, "require_once __DIR__ . '/_guard.php'"), 'AS-6 ' . $nama . ' dijaga guard admin');
    $assert(str_contains($isi, "require_once __DIR__ . '/_master_ui.php'"), 'AS-6 ' . $nama . ' memakai adaptor kerangka bersama');
    $assert(str_contains($isi, 'master_header('), 'AS-6 ' . $nama . ' membuka kerangka bersama');
    $assert(str_contains($isi, 'master_footer('), 'AS-6 ' . $nama . ' menutup kerangka bersama');
    $assert(!str_contains($isi, '<!DOCTYPE'), 'AS-6 ' . $nama . ' tidak menggambar kerangka HTML sendiri');
    $assert(!str_contains($isi, "include 'sidebar.php'"), 'AS-6 ' . $nama . ' tidak memuat sidebar lama');
    $assert(!str_contains($isi, 'datatables'), 'AS-6 ' . $nama . ' tidak memuat DataTables lama');
    $assert(str_contains($isi, 'ah-card'), 'AS-6 ' . $nama . ' memakai komponen kartu bersama');
}

// ============================================================== AS-7
$navigasi = $source('app/Ui/Navigation.php');
$assert(str_contains($navigasi, "'admin_kelulusan_santri.php' => 'alumni.kelulusan'"), 'AS-7 navigasi mengenali halaman kelulusan');
$assert(str_contains($navigasi, "'proses_mutasi_alumni.php' => 'alumni.kelulusan'"), 'AS-7 alamat lama tetap menandai menu yang sama');
$assert(
    str_contains($navigasi, "self::item('alumni.kelulusan', 'Kelulusan & Mutasi Keluar', '/admin/admin_kelulusan_santri.php'"),
    'AS-7 menu Kelulusan & Mutasi Keluar tersedia untuk admin'
);
$assert(str_contains($navigasi, "self::item('alumni', 'Data Alumni'"), 'AS-7 menu Data Alumni lama tetap ada');

// ============================================================== AS-8
$kodeLama = $tanpaKomentar($prosesLama);
$assert(str_contains($kodeLama, 'http_response_code(410)'), 'AS-8 alamat lama menolak POST dengan 410');
$assert(str_contains($kodeLama, 'http_response_code(301)'), 'AS-8 alamat lama mengalihkan GET secara permanen');
$assert(!str_contains($kodeLama, 'INSERT IGNORE'), 'AS-8 pemrosesan INSERT IGNORE lama sudah hilang');
$assert(!str_contains($kodeLama, 'bulk_mutasi'), 'AS-8 endpoint massal lama tanpa transaksi sudah hilang');
$assert(!str_contains($kodeLama, 'mysqli_'), 'AS-8 alamat lama tidak lagi menyentuh basis data');
$assert(
    str_contains($kodeLama, "requireWebRole('admin')"),
    'AS-8 alamat lama tetap memeriksa peran admin sebelum menjawab apa pun'
);

// ============================================================== AS-9
$assert(str_contains($halamanProses, 'master_csrf()'), 'AS-9 formulir proses menyertakan token CSRF');
$assert(str_contains($halamanAlumni, 'master_csrf()'), 'AS-9 formulir alumni menyertakan token CSRF');
$assert(str_contains($halamanProses, "ah_form_token('alumni_proses')"), 'AS-9 layar konfirmasi memakai token sekali pakai');
$assert(str_contains($halamanProses, "ah_form_token_consume('alumni_proses'"), 'AS-9 token sekali pakai dikonsumsi saat penerapan');
$assert(str_contains($halamanAlumni, "ah_form_token_consume('alumni_koreksi'"), 'AS-9 formulir koreksi dilindungi token sekali pakai');
$assert(
    substr_count($halamanAlumni, 'method="post"') >= 4,
    'AS-9 seluruh tindakan alumni dikirim lewat POST'
);
$assert(
    !preg_match('/<a [^>]*href="[^"]*(action=(arsip|pulihkan|batalkan|hapus))/', $halamanAlumni),
    'AS-9 tidak ada tautan GET yang mengubah data'
);

// ============================================================== AS-10
$layananKode = $tanpaKomentar($layanan);
$assert(str_contains($layananKode, 'SET TRANSACTION ISOLATION LEVEL READ COMMITTED'), 'AS-10 transaksi memakai isolasi READ COMMITTED');
$assert(str_contains($layananKode, 'begin_transaction()'), 'AS-10 layanan membuka transaksi');
$assert(substr_count($layananKode, '->rollback()') >= 2, 'AS-10 setiap jalur galat melakukan rollback');
$assert(substr_count($layananKode, '->commit()') >= 2, 'AS-10 commit hanya pada akhir jalur sukses');
$assert(str_contains($repoKode, 'ORDER BY id FOR UPDATE'), 'AS-10 baris dikunci menurut ID menaik');
$assert(str_contains($layananKode, 'lockSantri('), 'AS-10 baris santri dikunci lebih dahulu');
$assert(str_contains($layananKode, 'lockActiveBySantri('), 'AS-10 catatan alumni aktif dikunci setelah santri');
$assert(str_contains($layananKode, 'lockActiveByNis('), 'AS-10 catatan alumni ber-NIS sama ikut dikunci');
$assert(
    strpos($layananKode, 'lockSantri(') < strpos($layananKode, 'lockActiveBySantri('),
    'AS-10 urutan penguncian tetap: santri lebih dahulu, lalu alumni'
);
$assert(str_contains($layananKode, 'const BATAS_MASSAL = 200'), 'AS-10 batas operasi massal ditetapkan di server');

// ============================================================== AS-11
$assert(str_contains($layananKode, 'private function wajibTercatat('), 'AS-11 audit wajib berhasil disimpan');
$assert(
    str_contains($layananKode, 'catatan audit tidak dapat disimpan'),
    'AS-11 kegagalan audit menghasilkan pembatalan, bukan diabaikan'
);
$assert(
    !str_contains($layananKode, '$this->audit->log(') || substr_count($layananKode, '$this->audit->log(') === 1,
    'AS-11 seluruh penulisan audit melewati satu jalur yang memeriksa hasilnya'
);
foreach (['alumni.proses', 'alumni.massal', 'alumni.koreksi', 'alumni.arsip', 'alumni.pulihkan', 'alumni.batalkan', 'alumni.hubungkan'] as $aksi) {
    $assert(str_contains($layananKode, "'" . $aksi . "'"), 'AS-11 aksi audit ' . $aksi . ' tercatat');
}
$assert(
    !str_contains($layananKode, 'CREATE TABLE') && !str_contains($layananKode, 'alumni_audit'),
    'AS-11 tidak ada sistem audit kedua; memakai audit_logs yang sudah ada'
);

// ============================================================== AS-12
$assert(
    !str_contains($tanpaKomentar($source('bin/alumni_preflight.php')), 'UPDATE ')
    && !str_contains($tanpaKomentar($source('bin/alumni_preflight.php')), 'DELETE '),
    'AS-12 preflight hanya membaca'
);
$assert(
    !str_contains($tanpaKomentar($source('bin/alumni_verify.php')), 'UPDATE ')
    && !str_contains($tanpaKomentar($source('bin/alumni_verify.php')), 'DELETE '),
    'AS-12 verifikasi hanya membaca'
);
$assert(
    str_contains($tanpaKomentar($layanan), 'Tidak ada penempatan') === false
    && str_contains($layananKode, 'membershipAssign') === false
    && str_contains($layananKode, 'createRoomAssignment') === false,
    'AS-12 pembatalan tidak membuat penempatan kelas atau kamar baru secara otomatis'
);
$assert(
    str_contains($repoKode, "DELETE FROM plotting_kamar WHERE id = ? AND id_tahun = ?"),
    'AS-12 pelepasan kamar dibatasi pada tahun ajaran berjalan saja'
);
$assert(
    !str_contains($repoKode, 'DELETE FROM plotting_kelas') && !str_contains($repoKode, 'DELETE FROM santri')
    && !str_contains($repoKode, 'DELETE FROM santri_wali') && !str_contains($repoKode, 'DELETE FROM wali')
    && !str_contains($repoKode, 'DELETE FROM users'),
    'AS-12 riwayat kelas, santri, relasi wali, dan akun tidak pernah dihapus'
);

// ============================================================== AS-13
$assert(
    preg_match('/<\?=\s*\$(row|dipilih|baris|d)\[[^\]]*\]\s*\?>/', $halamanAlumni) !== 1,
    'AS-13 tidak ada nilai basis data yang dicetak tanpa escaping di halaman alumni'
);
$assert(
    preg_match('/<\?=\s*\$(row|baris|kelas)\[[^\]]*\]\s*\?>/', $halamanProses) !== 1,
    'AS-13 tidak ada nilai basis data yang dicetak tanpa escaping di halaman kelulusan'
);
$assert(substr_count($halamanAlumni, 'master_e(') >= 30, 'AS-13 keluaran halaman alumni di-escape secara konsisten');
$assert(substr_count($halamanProses, 'master_e(') >= 20, 'AS-13 keluaran halaman kelulusan di-escape secara konsisten');

// ============================================================== AS-14
foreach ([
    'name="q"' => 'pencarian nama atau NIS',
    'name="status"' => 'filter status keluar',
    'name="tahun"' => 'filter tahun',
    'name="tingkat"' => 'filter tingkat',
    'name="state"' => 'filter status catatan (aktif/arsip)',
    'name="tautan"' => 'filter referensi santri',
] as $penanda => $label) {
    $assert(str_contains($halamanAlumni, $penanda), 'AS-14 halaman alumni menyediakan ' . $label);
}
$assert(str_contains($halamanAlumni, 'master_pagination('), 'AS-14 daftar alumni memakai pagination bersama');
$assert(substr_count($halamanAlumni, 'ah_empty(') >= 2, 'AS-14 keadaan kosong dibedakan antara "tanpa data" dan "tidak cocok filter"');
$assert(str_contains($halamanAlumni, 'ah-table-wrap'), 'AS-14 tabel dibungkus pembungkus responsif bersama');
$assert(str_contains($halamanAlumni, 'ah-stats'), 'AS-14 ringkasan keadaan arsip ditampilkan');
$assert(str_contains($halamanAlumni, 'Diproses oleh'), 'AS-14 daftar menunjukkan siapa yang memproses');
$assert(str_contains($halamanAlumni, 'Kamar terakhir'), 'AS-14 detail menunjukkan kelas dan kamar terakhir');

// ============================================================== AS-15
$assert(
    !str_contains($halamanProses, 'onclick=') && !str_contains($halamanAlumni, 'onclick='),
    'AS-15 tidak ada keputusan yang bergantung pada handler inline JavaScript'
);
$assert(
    str_contains($layananKode, 'private function normalizeIds(') && str_contains($layananKode, 'FILTER_VALIDATE_INT'),
    'AS-15 seluruh ID divalidasi di server'
);
$assert(
    str_contains($layananKode, 'private function status(') && str_contains($layananKode, 'private function tingkat(')
    && str_contains($layananKode, 'private function tanggal(') && str_contains($layananKode, 'private function tahunAngkatan('),
    'AS-15 status, tingkat, tanggal, dan tahun divalidasi ulang di server'
);
$assert(
    str_contains($layananKode, 'public const STATUS = ') && str_contains($layananKode, 'public const TINGKAT = '),
    'AS-15 daftar nilai sah berasal dari konstanta layanan, bukan dari hidden field'
);

// ============================================================== AS-16
$migrasi = $source('database/migrations/011_koreksi_alumni.sql');
$rollback = $source('database/rollbacks/011_koreksi_alumni.sql');
$assert($migrasi !== '', 'AS-16 migrasi 011 tersedia');
$assert($rollback !== '', 'AS-16 rollback 011 berpasangan tersedia');
$assert(
    substr_count($migrasi, 'information_schema') >= 10,
    'AS-16 setiap perubahan skema dijaga pemeriksaan information_schema (idempoten)'
);
/** Baris komentar `--` dibuang: yang dinilai PERNYATAAN SQL, bukan penjelasannya. */
$sqlSaja = static fn (string $sql): string => implode("\n", array_filter(
    explode("\n", $sql),
    static fn (string $baris): bool => !str_starts_with(ltrim($baris), '--')
));
$migrasiSql = $sqlSaja($migrasi);
foreach (['DROP TABLE', 'TRUNCATE', 'DELETE FROM alumni', 'DROP COLUMN'] as $terlarang) {
    $assert(!str_contains($migrasiSql, $terlarang), 'AS-16 migrasi tidak memuat ' . $terlarang);
}
$assert(
    !str_contains($sqlSaja($rollback), 'DELETE FROM alumni') && !str_contains($sqlSaja($rollback), 'DROP TABLE alumni'),
    'AS-16 rollback tidak menghapus satu baris alumni pun'
);
$assert(
    str_contains($migrasi, 'alumni_santri_aktif_unique') && str_contains($migrasi, 'alumni_nis_aktif_unique'),
    'AS-16 migrasi memasang kunci unik pencegah alumni aktif ganda'
);
$assert(
    strpos($migrasi, 'ADD UNIQUE KEY alumni_nis_aktif_unique') < strpos($migrasi, 'DROP INDEX nis'),
    'AS-16 kunci unik pengganti dipasang SEBELUM kunci unik lama dilepas'
);
$assert(
    str_contains($rollback, 'PEMERIKSAAN WAJIB') && str_contains($rollback, 'HAVING COUNT(*) > 1'),
    'AS-16 rollback menyertakan pemeriksaan sebelum memasang kembali kunci unik lama'
);
$assert(
    str_contains($migrasi, 'created_at TIMESTAMP NULL DEFAULT NULL'),
    'AS-16 baris warisan tidak dipaksa mengaku dibuat saat migrasi dijalankan'
);

// ============================================================== AS-17
foreach (['nama_ayah', 'nama_ibu'] as $kolom) {
    $assert(
        !preg_match('/(WHERE|AND)[^;]{0,80}' . $kolom . '\s*=/', $repoKode),
        'AS-17 tidak ada pencocokan alumni berdasarkan ' . $kolom
    );
}
$assert(
    !preg_match('/(WHERE|AND)[^;]{0,80}a?\.?nama_santri\s*=/', $repoKode),
    'AS-17 tidak ada deduplikasi berdasarkan kesamaan nama santri'
);
$backfill = $source('bin/alumni_backfill.php');
$assert(
    str_contains($backfill, 'santri_cocok') && str_contains($backfill, 'alumni_dengan_nis'),
    'AS-17 backfill hanya memasangkan bila NIS cocok persis satu'
);
$assert(
    !str_contains($tanpaKomentar($backfill), 'nama_ayah') && !str_contains($tanpaKomentar($backfill), 'nama_ibu'),
    'AS-17 backfill tidak memakai nama orang tua sebagai kunci'
);

// ============================================================== AS-18
$masterSantri = $source('admin/admin_master_santri.php');
$assert(
    str_contains($masterSantri, 'admin_kelulusan_santri.php?santri_id='),
    'AS-18 baris santri menyediakan tindakan Luluskan / Mutasi keluar'
);
$assert(
    str_contains($masterSantri, "(int) \$row['is_active'] === 1 && \$row['archived_at'] === null"),
    'AS-18 tindakan itu hanya tampil untuk santri yang masih aktif'
);
$assert(
    str_contains($masterSantri, 'admin_kelulusan_santri.php">Kelulusan / mutasi keluar'),
    'AS-18 alur massal dapat dibuka dari tindakan halaman Data Santri'
);

// ============================================================== AS-19
$assert(
    preg_match("/public const STATUS = \['Lulus', 'Pindah', 'Berhenti'\]/", $layananKode) === 1,
    'AS-19 status keluar terbatas pada Lulus, Pindah, dan Berhenti — tanpa status baru'
);
$assert(
    preg_match("/public const TINGKAT = \['Ibtida', 'Tsanawi'\]/", $layananKode) === 1,
    'AS-19 tingkat mengikuti ENUM kolom yang sudah ada'
);

// ============================================================== AS-20
$preflight = $source('bin/alumni_preflight.php');
foreach (['bin/alumni_preflight.php' => $preflight, 'bin/alumni_backfill.php' => $backfill, 'bin/alumni_verify.php' => $source('bin/alumni_verify.php')] as $nama => $isi) {
    $assert(str_contains($isi, "PHP_SAPI !== 'cli'"), 'AS-20 ' . $nama . ' hanya dapat dijalankan dari CLI');
}
$assert(str_contains($backfill, "in_array('--terapkan', \$argv, true)"), 'AS-20 backfill hanya menulis bila diminta --terapkan');
$assert(
    str_contains($backfill, 'LAPORAN SAJA (tidak menulis)'),
    'AS-20 mode bawaan backfill adalah laporan, bukan perubahan data'
);
$assert(
    !str_contains($tanpaKomentar($backfill), 'DELETE '),
    'AS-20 backfill tidak pernah menghapus baris'
);

// ============================================================== AS-21
foreach ([
    'docs/koreksi-alumni/README.md',
    'docs/koreksi-alumni/aturan-bisnis.md',
    'docs/koreksi-alumni/migrasi-dan-rollback.md',
    'docs/koreksi-alumni/cpanel-deployment.md',
    'docs/koreksi-alumni/test-results.md',
    'docs/koreksi-alumni/acceptance-status.md',
] as $dokumen) {
    $assert(is_file($root . '/' . $dokumen), 'AS-21 dokumentasi tersedia: ' . $dokumen);
}
$hasilUji = $source('docs/koreksi-alumni/test-results.md');
foreach (['LULUS', 'BELUM DIJALANKAN', 'MEMERLUKAN UJI PRODUKSI/STAGING'] as $label) {
    $assert(str_contains($hasilUji, $label), 'AS-21 laporan pengujian membedakan label "' . $label . '"');
}
$assert(
    str_contains($source('docs/koreksi-alumni/cpanel-deployment.md'), 'Smoke test setelah menarik branch'),
    'AS-21 panduan cPanel memuat ceklis smoke test'
);
$assert(
    is_file($root . '/bin/alumni_run_all_tests.sh') && is_executable($root . '/bin/alumni_run_all_tests.sh'),
    'AS-21 penjalan seluruh pengujian tersedia dan dapat dieksekusi'
);

echo PHP_EOL;
if ($failures === []) {
    echo 'SELURUH PEMERIKSAAN STATIS ALUMNI LULUS.' . PHP_EOL;
    exit(0);
}
echo 'GAGAL (' . count($failures) . '):' . PHP_EOL . ' - ' . implode(PHP_EOL . ' - ', $failures) . PHP_EOL;
exit(1);
