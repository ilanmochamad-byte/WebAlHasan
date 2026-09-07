<?php

declare(strict_types=1);

/**
 * Pemeriksaan statis "Fondasi Penugasan dan Hak Akses Lintas PRD V3–V6"
 * (keputusan pengguna 7 September 2026).
 *
 * Tidak memerlukan basis data maupun peramban. Yang diperiksa:
 *
 *   FS-1  seluruh berkas yang dibuat/diubah lolos lint PHP;
 *   FS-2  role dasar tidak berubah: tidak ada role login baru pada migrasi,
 *         katalog jenis, maupun AccountRepository::ROLES;
 *   FS-3  daftar capability perizinan V2 (`Capabilities::ALL`) tidak berubah;
 *   FS-4  katalog jenis memuat tepat tujuh penugasan dengan tabel per domain,
 *         role dasar guru/pengurus, dan capability yang terdokumentasi;
 *   FS-5  migrasi 012 aditif dan idempoten (tanpa DROP/DELETE/UPDATE/TRUNCATE,
 *         tanpa INSERT ke tabel penugasan/roles), berpasangan dengan rollback;
 *   FS-6  seluruh tabel penugasan baru punya kunci asing, kunci unik, dan CHECK;
 *   FS-7  halaman admin: guard admin, mutasi hanya lewat POST, tidak ada query
 *         langsung, tidak ada penghapusan permanen, keluaran di-escape;
 *   FS-8  layanan: transaksi + audit wajib + pemeriksaan admin + tumpang tindih;
 *   FS-9  repository hanya prepared statement, tidak ada interpolasi input;
 *   FS-10 resolver capability membaca role dari basis data, bukan dari input;
 *   FS-11 kontrak API lama: struktur `capabilities` dan `default_mode` tidak
 *         berubah; field baru `feature_capabilities` bersifat aditif;
 *   FS-12 navigasi memuat Pusat Penugasan dan mempertahankan halaman lama;
 *   FS-13 halaman lama murobi/pembimbing tetap ada dan menaut ke pusat;
 *   FS-14 halaman akun menjelaskan role dasar vs penugasan dan menaut ke pusat;
 *   FS-15 tidak ada fitur bisnis V3–V6 (tabel nilai, rapor, tagihan, kuitansi,
 *         konseling, pelanggaran baru) yang dibangun paket ini;
 *   FS-16 tidak ada perubahan pada aplikasi perangkat (tidak ada berkas
 *         alhasanApps di repositori ini yang disentuh) dan tidak ada menu mobile baru;
 *   FS-17 dokumentasi fondasi tersedia dan menyebut capability yang sama
 *         dengan katalog;
 *   FS-18 skrip CLI preflight/verify hanya membaca.
 *
 * Jalankan:
 *   php tests/penugasan_static.php
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
$tanpaKomentarSql = static fn (string $sql): string => preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;

$berkasBaru = [
    'app/Penugasan/PenugasanJenis.php',
    'app/Penugasan/PenugasanException.php',
    'app/Penugasan/PenugasanRepository.php',
    'app/Penugasan/PenugasanService.php',
    'admin/admin_penugasan.php',
    'bin/penugasan_preflight.php',
    'bin/penugasan_verify.php',
    'tests/penugasan_static.php',
    'tests/penugasan_integration.php',
    'tests/penugasan_web_smoke.php',
];
$berkasDiubah = [
    'app/Auth/Capabilities.php',
    'app/Api/ApiAuthService.php',
    'app/bootstrap.php',
    'app/Ui/Navigation.php',
    'admin/admin_akun.php',
    'admin/admin_murobi.php',
    'admin/admin_pembimbing.php',
];

// ============================================================== FS-1
foreach ([...$berkasBaru, ...$berkasDiubah] as $berkas) {
    $keluaran = [];
    $kode = 0;
    exec(escapeshellcmd(PHP_BINARY) . ' -l ' . escapeshellarg($root . '/' . $berkas) . ' 2>&1', $keluaran, $kode);
    $assert($kode === 0, 'FS-1 lint bersih: ' . $berkas);
}

$jenis = $source('app/Penugasan/PenugasanJenis.php');
$capabilities = $source('app/Auth/Capabilities.php');
$service = $tanpaKomentar($source('app/Penugasan/PenugasanService.php'));
$repo = $tanpaKomentar($source('app/Penugasan/PenugasanRepository.php'));
$halaman = $tanpaKomentar($source('admin/admin_penugasan.php'));
$migrasi = $source('database/migrations/012_fondasi_penugasan_v3_v6.sql');
$rollback = $source('database/rollbacks/012_fondasi_penugasan_v3_v6.sql');
$migrasiKode = $tanpaKomentarSql($migrasi);
$api = $source('app/Api/ApiAuthService.php');
$navigasi = $source('app/Ui/Navigation.php');
$akun = $source('admin/admin_akun.php');
$accountRepo = $source('app/Account/AccountRepository.php');

// ============================================================== FS-2
$assert(
    preg_match('/INSERT\s+INTO\s+roles/i', $migrasiKode) !== 1,
    'FS-2 migrasi 012 tidak menambah baris ke tabel roles'
);
$assert(
    preg_match('/const ROLES = \[\'admin\', \'guru\', \'pengurus\', \'orang_tua\'\]/', $accountRepo) === 1,
    'FS-2 AccountRepository::ROLES tetap empat role dasar'
);
foreach (['murobi', 'pembimbing', 'pendidikan', 'bendahara', 'panitia_psb', 'bendahara_psb', 'guru_mapel'] as $bukanRole) {
    $assert(
        preg_match("/'role_dasar' => '" . preg_quote($bukanRole, '/') . "'/", $jenis) !== 1,
        'FS-2 "' . $bukanRole . '" tidak dipakai sebagai role dasar'
    );
}
$assert(
    preg_match_all("/'role_dasar' => '(guru|pengurus)'/", $jenis) === 7,
    'FS-2 seluruh tujuh jenis memakai role dasar guru atau pengurus'
);

// ============================================================== FS-3
$assert(
    str_contains($capabilities, 'public const ALL = [self::ADMIN, self::PENGURUS, self::MUROBI, self::ORANG_TUA];'),
    'FS-3 Capabilities::ALL tidak berubah (admin, pengurus, murobi, orang_tua)'
);
$assert(
    preg_match('/public function forUser\(array \$user\): array\s*\{.*?return \$this->cache\[\$userId\] = \$capabilities;/s', $capabilities) === 1
    && !str_contains(preg_replace('/public function featureCapabilities.*/s', '', $capabilities) ?? '', 'PenugasanJenis::semua()'),
    'FS-3 forUser() tidak menyertakan capability fondasi ke daftar perizinan V2'
);

// ============================================================== FS-4
$assert(
    preg_match_all("/'tabel' => '([a-z_]+)'/", $jenis, $tabel) === 7 && count(array_unique($tabel[1])) === 7,
    'FS-4 tujuh jenis penugasan masing-masing memakai tabel sendiri'
);
foreach (['murobi_assignments', 'pembimbing_assignments', 'guru_mapel_assignments', 'pendidikan_assignments',
    'bendahara_bulanan_assignments', 'panitia_psb_assignments', 'bendahara_psb_assignments'] as $t) {
    $assert(in_array($t, $tabel[1] ?? [], true), 'FS-4 katalog memuat tabel ' . $t);
}
foreach (['nilai.input', 'nilai.lihat_sendiri', 'nilai.koreksi_sendiri', 'rapor.verifikasi', 'rapor.finalisasi', 'rapor.buka_koreksi', 'rapor.cetak',
    'pembiayaan_bulanan.tagihan', 'pembiayaan_bulanan.pembayaran', 'pembiayaan_bulanan.verifikasi', 'pembiayaan_bulanan.laporan',
    'psb.pendaftar', 'psb.verifikasi', 'psb.seleksi', 'psb.penerimaan',
    'psb_keuangan.tagihan', 'psb_keuangan.pembayaran', 'psb_keuangan.verifikasi', 'psb_keuangan.laporan',
    'murobi.binaan', 'pembimbing.binaan'] as $cap) {
    $assert(str_contains($jenis, "'" . $cap . "'"), 'FS-4 capability terdefinisi: ' . $cap);
}
$assert(
    preg_match('/self::PANITIA_PSB => \[.*?\'capabilities\' => \[self::CAP_PSB_PENDAFTAR/s', $jenis) === 1
    && preg_match('/self::BENDAHARA_PSB => \[.*?\'capabilities\' => \[self::CAP_PSB_KEUANGAN_TAGIHAN/s', $jenis) === 1
    && preg_match('/self::PANITIA_PSB => \[.*?CAP_PSB_KEUANGAN.*?\],\s*self::BENDAHARA_PSB/s', $jenis) !== 1,
    'FS-4 panitia PSB dan bendahara PSB memakai himpunan capability yang terpisah'
);

// ============================================================== FS-5
foreach (['DROP TABLE', 'DROP COLUMN', 'TRUNCATE', 'DELETE FROM'] as $terlarang) {
    $assert(stripos($migrasiKode, $terlarang) === false, 'FS-5 migrasi 012 tidak memuat ' . trim($terlarang));
}
$assert(
    preg_match('/^\s*UPDATE\s/mi', $migrasiKode) !== 1 && preg_match("/'UPDATE\s/i", $migrasiKode) !== 1,
    'FS-5 migrasi 012 tidak memuat pernyataan UPDATE (ON UPDATE CURRENT_TIMESTAMP bukan pernyataan)'
);
$assert(
    preg_match('/INSERT\s+INTO\s+(guru_mapel|pendidikan|bendahara|panitia|murobi|pembimbing|mata_pelajaran)/i', $migrasiKode) !== 1,
    'FS-5 migrasi 012 tidak mengisi tabel penugasan berdasarkan tebakan'
);
$assert(substr_count($migrasiKode, 'CREATE TABLE IF NOT EXISTS') === 6, 'FS-5 enam tabel baru dibuat dengan IF NOT EXISTS (idempoten)');
$assert(
    substr_count($migrasiKode, 'information_schema.COLUMNS') === 10 && substr_count($migrasiKode, 'information_schema.TABLE_CONSTRAINTS') === 4,
    'FS-5 seluruh ALTER pada tabel lama dijaga pemeriksaan information_schema'
);
$assert($rollback !== '' && substr_count($rollback, 'DROP TABLE IF EXISTS') === 6, 'FS-5 rollback 012 berpasangan dan melepas enam tabel baru');
$assert(str_contains($rollback, 'PERINGATAN KEHILANGAN DATA'), 'FS-5 rollback memperingatkan kehilangan data secara eksplisit');
$assert(preg_match('/^(?!.*DROP COLUMN.*(target_type|tanggal_mulai|tanggal_selesai|is_active|archived_at|target_key))/s', $rollback) === 1, 'FS-5 rollback tidak melepas kolom lama murobi/pembimbing');

// ============================================================== FS-6
foreach (['guru_mapel', 'pendidikan', 'bendahara_bulanan', 'panitia_psb', 'bendahara_psb'] as $p) {
    $assert(str_contains($migrasiKode, $p . '_assignment_unique'), 'FS-6 kunci unik ' . $p . '_assignment_unique');
    $assert(str_contains($migrasiKode, $p . '_range_check CHECK (tanggal_selesai IS NULL OR tanggal_selesai >= tanggal_mulai)'), 'FS-6 CHECK rentang tanggal ' . $p);
    $assert(str_contains($migrasiKode, $p . '_tahun_fk FOREIGN KEY (tahun_ajaran_id) REFERENCES tahun_ajaran (id)'), 'FS-6 kunci asing tahun ajaran ' . $p);
    $assert(
        str_contains($migrasiKode, $p . '_creator_fk') && str_contains($migrasiKode, $p . '_updater_fk') && str_contains($migrasiKode, $p . '_ender_fk'),
        'FS-6 kunci asing pelaku (created_by/updated_by/diakhiri_oleh) ' . $p
    );
}
$assert(str_contains($migrasiKode, 'guru_mapel_guru_fk FOREIGN KEY (guru_id) REFERENCES guru (id)'), 'FS-6 guru mapel merujuk guru lewat kunci asing');
$assert(str_contains($migrasiKode, 'guru_mapel_mapel_fk FOREIGN KEY (mata_pelajaran_id) REFERENCES mata_pelajaran (id)'), 'FS-6 guru mapel merujuk mata pelajaran lewat kunci asing');
$assert(str_contains($migrasiKode, 'guru_mapel_kelas_fk FOREIGN KEY (kelas_id) REFERENCES kelas (id)'), 'FS-6 guru mapel merujuk kelas lewat kunci asing');
$assert(substr_count($migrasiKode, 'REFERENCES pengurus (id)') === 4, 'FS-6 empat penugasan pengurus merujuk pengurus lewat kunci asing');
$assert(!str_contains($migrasiKode, 'target_id'), 'FS-6 tidak ada kolom target_id generik tanpa kunci asing');

// ============================================================== FS-7
$assert(str_contains($halaman, "require_once __DIR__ . '/_guard.php';"), 'FS-7 halaman memakai guard admin');
$assert(
    preg_match('/REQUEST_METHOD.{0,20}===\s*.POST./', $halaman) === 1,
    'FS-7 seluruh mutasi berada di cabang REQUEST_METHOD === POST'
);
foreach (['buat', 'ubah', 'akhiri', 'nonaktifkan', 'aktifkan', 'mapel_simpan', 'mapel_status'] as $aksi) {
    $assert(str_contains($halaman, "case '" . $aksi . "':"), 'FS-7 aksi ' . $aksi . ' hanya ditangani lewat POST');
}
$assert(preg_match('/\$_GET\[\'action\'\]/', $halaman) !== 1, 'FS-7 tidak ada aksi yang dibaca dari GET');
foreach (['mysqli_query', '->query(', '->prepare(', 'INSERT INTO', 'UPDATE ', 'DELETE FROM'] as $terlarang) {
    $assert(!str_contains($halaman, $terlarang), 'FS-7 halaman tidak menyentuh basis data langsung: ' . trim($terlarang));
}
$assert(
    preg_match('/case \'(hapus|delete)\'/', $halaman) !== 1 && !str_contains($halaman, '?hapus=') && preg_match('/value="(hapus|delete)"/', $halaman) !== 1,
    'FS-7 halaman tidak menyediakan aksi penghapusan permanen'
);
$assert(substr_count($halaman, 'master_csrf()') >= 6, 'FS-7 setiap formulir mutasi menyertakan token CSRF');
$assert(
    preg_match('/<\?=\s*\$r\[[^\]]+\]\s*\?>/', $halaman) !== 1 && preg_match('/<\?=\s*\$mp\[[^\]]+\]\s*\?>/', $halaman) !== 1,
    'FS-7 tidak ada nilai baris yang dicetak tanpa escape'
);
$assert(substr_count($halaman, 'master_e(') + substr_count($halaman, 'ah_e(') >= 60, 'FS-7 keluaran data di-escape (master_e/ah_e)');
$assert(str_contains($halaman, 'data-confirm=') && str_contains($halaman, 'name="alasan"'), 'FS-7 tindakan berdampak meminta konfirmasi dan alasan');

// ============================================================== FS-8
$assert(str_contains($service, '$this->repository->transaction(function ()'), 'FS-8 layanan memakai transaksi untuk mutasi');
$assert(substr_count($service, '$this->auditRequired(') >= 8, 'FS-8 setiap mutasi menulis audit yang kegagalannya membatalkan transaksi');
$assert(str_contains($service, 'throw new RuntimeException(\'Perubahan penugasan dibatalkan karena audit tidak dapat disimpan'), 'FS-8 kegagalan audit menggagalkan mutasi');
$assert(substr_count($service, '$this->requireAdmin($actorId);') >= 6, 'FS-8 seluruh mutasi (buat, ubah, akhiri, status aktif, mata pelajaran) memeriksa role admin pelaku di server');
foreach (['public function buat(', 'public function ubah(', 'public function akhiri(', 'private function ubahStatusAktif(', 'public function simpanMataPelajaran(', 'public function setMataPelajaranState('] as $metode) {
    $posisi = strpos($service, $metode);
    $potongan = $posisi === false ? '' : substr($service, $posisi, 900);
    $assert(str_contains($potongan, '$this->requireAdmin($actorId);'), 'FS-8 ' . trim($metode, '(') . ' memanggil requireAdmin');
}
$assert(str_contains($service, 'lockForSubject(') && str_contains($service, 'tolakTumpangTindih('), 'FS-8 pemeriksaan tumpang tindih dijalankan di bawah kunci baris');
$assert(str_contains($service, 'FOR UPDATE') || str_contains($repo, 'FOR UPDATE'), 'FS-8 penguncian baris SELECT ... FOR UPDATE dipakai');
$assert(str_contains($service, "'penugasan.capability_berubah'"), 'FS-8 perubahan capability akun dicatat tersendiri');
$assert(str_contains($service, "'penugasan.tolak_tumpang_tindih'"), 'FS-8 percobaan duplikat/tumpang tindih dicatat pada audit');
foreach (["'penugasan.buat'", "'penugasan.ubah'", "'penugasan.akhiri'", "'penugasan.nonaktifkan'", "'penugasan.aktifkan'"] as $aksi) {
    $assert(str_contains($service, $aksi), 'FS-8 aksi audit ' . trim($aksi, "'"));
}
$assert(!str_contains($service, 'password') && !str_contains($service, 'token'), 'FS-8 layanan tidak pernah menyentuh password/token');
$assert(!str_contains($repo, 'DELETE FROM'), 'FS-8 repository tidak menghapus baris penugasan');
$assert(
    str_contains($service, "\$input[\$definisi['subjek_kolom']] = \$lama[\$definisi['subjek_kolom']];")
    && str_contains($service, "\$input['tahun_ajaran_id'] = \$lama['tahun_ajaran_id'];"),
    'FS-8 subjek dan tahun ajaran tidak dapat diganti lewat parameter ubah (anti-IDOR)'
);

// ============================================================== FS-9
$assert(
    preg_match('/\$this->db->query\(/', $repo) !== 1 && str_contains($repo, '$this->db->prepare('),
    'FS-9 repository hanya memakai prepared statement'
);
$assert(
    preg_match('/prepare\([^)]*\$_(GET|POST|REQUEST)/', $repo) !== 1 && !str_contains($repo, '$_GET') && !str_contains($repo, '$_POST'),
    'FS-9 repository tidak membaca input HTTP'
);
$assert(str_contains($repo, "PenugasanJenis::definisi(\$jenis)") && !str_contains($repo, "\$filters['tabel']"), 'FS-9 nama tabel berasal dari katalog, bukan dari input');

// ============================================================== FS-10
$kodeCap = $tanpaKomentar($capabilities);
$assert(str_contains($kodeCap, 'private function rolesFromDatabase('), 'FS-10 resolver membaca ulang role dari basis data');
$assert(
    preg_match('/public function featureCapabilities\(array \$user\): array.*?\$roles = \$this->rolesFromDatabase\(\$userId\);/s', $kodeCap) === 1,
    'FS-10 featureCapabilities tidak mempercayai $user[\'roles\'] dari pemanggil'
);
$assert(str_contains($kodeCap, 'CURDATE()') && str_contains($kodeCap, 'tanggal_mulai <= CURDATE()'), 'FS-10 masa berlaku dinilai pada tanggal berjalan di dalam query');
$assert(str_contains($kodeCap, "ta.status = 'Aktif'") && str_contains($kodeCap, "wajib_tahun_aktif"), 'FS-10 tahun ajaran diperhitungkan (Aktif untuk non-PSB)');
foreach (['featureAppliesToKelas', 'featureAppliesToKamar', 'featureAppliesToMataPelajaran', 'featureAppliesToTahunAjaran', 'featureAppliesToSemester', 'featureAppliesToGelombangPsb', 'featureSource', 'hasFeature'] as $metode) {
    $assert(str_contains($kodeCap, 'public function ' . $metode . '('), 'FS-10 metode terpusat ' . $metode . ' tersedia');
}

// ============================================================== FS-11
$assert(
    preg_match('/\'list\' => array_values\(\$list\),\s*\'default_mode\' => \$this->defaultMode\(\$list\),\s*\'konteks\' => \[/s', $api) === 1
    && preg_match('/\'menus\' => \$this->menus\(\$list, \$roles\),\s*\'aksi\' => \$this->actionFlags\(\$list\),/s', $api) === 1,
    'FS-11 struktur capabilities (list/default_mode/konteks/menus/aksi) tidak berubah'
);
$assert(
    str_contains($api, 'foreach ([Capabilities::ADMIN, Capabilities::PENGURUS, Capabilities::MUROBI, Capabilities::ORANG_TUA] as $candidate)'),
    'FS-11 default_mode tetap dipilih dari empat mode lama'
);
$assert(!preg_match("/'key' => '(pendidikan|bendahara|panitia_psb|bendahara_psb|nilai|rapor|psb)/", $api), 'FS-11 tidak ada menu/mode aplikasi baru untuk fitur yang belum ada');
$assert(str_contains($api, "'feature_capabilities' => \$this->featureCapabilityPayload(\$user)"), 'FS-11 field aditif feature_capabilities pada profil');
$assert(str_contains($api, "\$profile['feature_capabilities'] = \$this->featureCapabilityPayload(\$user);"), 'FS-11 respons login juga memuat field aditif');
$assert(!str_contains($api, "unset(\$profile['capabilities']") && str_contains($api, "'roles' => array_values(\$user['roles']),"), 'FS-11 field lama profil tidak dihapus/diubah');

// ============================================================== FS-12
$assert(str_contains($navigasi, "'admin_penugasan.php' => 'master.penugasan'"), 'FS-12 navigasi memetakan halaman pusat penugasan');
$assert(str_contains($navigasi, "self::item('master.penugasan', 'Pusat Penugasan', '/admin/admin_penugasan.php'"), 'FS-12 menu Pusat Penugasan tersedia untuk admin');
$assert(
    str_contains($navigasi, "'/admin/admin_murobi.php'") && str_contains($navigasi, "'/admin/admin_pembimbing.php'"),
    'FS-12 menu murobi dan pembimbing lama dipertahankan'
);
$assert(
    preg_match('/if \(\$isAdmin\) \{\s*\$groups\[\] = \[\'label\' => \'Master Data\'.*?self::item\(\'master\.penugasan\'/s', $navigasi) === 1,
    'FS-12 Pusat Penugasan hanya tampil pada blok admin (dan tetap bukan kontrol akses)'
);

// ============================================================== FS-13
foreach (['admin/admin_murobi.php', 'admin/admin_pembimbing.php'] as $lama) {
    $isi = $source($lama);
    $assert(str_contains($isi, 'admin_penugasan.php?jenis='), 'FS-13 ' . $lama . ' menaut ke Pusat Penugasan');
    $assert(str_contains($isi, "if (\$_SERVER['REQUEST_METHOD'] === 'POST')") && str_contains($isi, "\$service->"), 'FS-13 ' . $lama . ' tetap memproses formulirnya sendiri (fungsi lama utuh)');
}

// ============================================================== FS-14
$assert(str_contains($akun, 'Role dasar dan penugasan adalah dua hal berbeda'), 'FS-14 halaman akun menjelaskan role dasar vs penugasan');
$assert(substr_count($akun, "app_url('/admin/admin_penugasan.php')") >= 2, 'FS-14 halaman akun menaut ke Pusat Penugasan');
$assert(str_contains($akun, 'function akun_ringkasan_penugasan(') && str_contains($akun, 'capabilities()->featureCapabilities('), 'FS-14 ringkasan penugasan efektif dihitung dari resolver server');
$assert(
    preg_match('/<option[^>]*>(Murobi|Pembimbing|Bendahara|Panitia|Pendidikan)/', $akun) !== 1,
    'FS-14 penugasan fungsional tidak ditambahkan ke pilihan role dasar'
);

// ============================================================== FS-15
foreach (['nilai_semester', 'nilai_santri', 'rapor', 'tagihan', 'kuitansi', 'jurnal', 'konseling', 'seleksi_psb'] as $tabelBisnis) {
    $assert(preg_match('/CREATE TABLE[^(]*\b' . $tabelBisnis . '\b/i', $migrasiKode) !== 1, 'FS-15 migrasi tidak membuat tabel bisnis ' . $tabelBisnis);
}
foreach (glob($root . '/admin/*.php') ?: [] as $berkas) {
    $nama = basename($berkas);
    $assert(
        preg_match('/^admin_(nilai|rapor|tagihan|pembayaran_bulanan|kuitansi|konseling|seleksi_psb)/', $nama) !== 1,
        'FS-15 tidak ada halaman fitur bisnis V3–V6: ' . $nama
    );
}

// ============================================================== FS-16
$assert(!is_dir($root . '/alhasanApps') || count(glob($root . '/alhasanApps/*') ?: []) === 0, 'FS-16 repositori aplikasi perangkat tidak berada/berubah di dalam repo ini');
$assert(!str_contains($api, "'key' => 'penugasan'"), 'FS-16 tidak ada menu aplikasi baru pada payload profil');

// ============================================================== FS-17
$dokumen = 'docs/fondasi-penugasan-v3-v6';
foreach (['README.md', 'ringkasan-desain.md', 'matriks-capability.md', 'migrasi-dan-rollback.md', 'kontrak-api.md', 'panduan-admin.md', 'test-results.md', 'cpanel-deployment.md', 'acceptance-status.md'] as $doc) {
    $assert(is_file($root . '/' . $dokumen . '/' . $doc), 'FS-17 dokumen tersedia: ' . $dokumen . '/' . $doc);
}
$matriks = $source($dokumen . '/matriks-capability.md');
foreach (['nilai.input', 'rapor.finalisasi', 'pembiayaan_bulanan.tagihan', 'psb.pendaftar', 'psb_keuangan.laporan', 'murobi.binaan', 'pembimbing.binaan'] as $cap) {
    $assert(str_contains($matriks, '`' . $cap . '`'), 'FS-17 matriks mendokumentasikan ' . $cap);
}
$assert(str_contains($source($dokumen . '/README.md'), 'belum diimplementasikan'), 'FS-17 dokumentasi menegaskan fitur bisnis V3–V6 belum diimplementasikan');
$assert(str_contains($source('docs/perapihan-v1-v2/matriks-hak-akses.md'), 'admin_penugasan.php'), 'FS-17 matriks hak akses lama diperbarui');
$assert(str_contains($source('docs/api-v1.md'), 'feature_capabilities'), 'FS-17 dokumentasi API menyebut field aditif');

// ============================================================== FS-18
foreach (['bin/penugasan_preflight.php', 'bin/penugasan_verify.php'] as $skrip) {
    $isi = $tanpaKomentar($source($skrip));
    foreach (['INSERT ', 'UPDATE ', 'DELETE ', 'ALTER ', 'DROP '] as $tulis) {
        $assert(!str_contains(strtoupper($isi), $tulis), 'FS-18 ' . $skrip . ' hanya membaca (tanpa ' . trim($tulis) . ')');
    }
    $assert(str_contains($isi, "PHP_SAPI !== 'cli'"), 'FS-18 ' . $skrip . ' hanya dapat dijalankan dari CLI');
}

echo PHP_EOL . 'Total gagal: ' . count($failures) . PHP_EOL;
exit($failures === [] ? 0 : 1);
