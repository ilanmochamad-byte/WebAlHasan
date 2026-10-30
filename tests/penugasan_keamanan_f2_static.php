<?php

declare(strict_types=1);

/**
 * Uji statis Fase 2 penugasan lintas guru/pengurus — pemisahan tugas per akun
 * (tanpa basis data).
 *
 *   php tests/penugasan_keamanan_f2_static.php
 *
 *   T-1 izin: routing dengan pengecualian, penolakan keputusan/koreksi/penetapan sesudah kunci baris
 *   T-2 izin: tampilan hanya cermin; kunci aksi REST tetap lima
 *   T-3 sikap V4: pemeriksaan pengisi sesudah baris sikap dikunci; matriks memakai murobi sah
 *   T-4 PK-T1: pemeriksaan pengisi nilai di dalam transaksi, kapasitas Pendidikan saja, di luar sidik jari
 *   T-5 V3: pemeriksaan pemilik catatan di dalam transaksi tanda mengetahui
 *   T-6 batas Fase 2 terhadap baseline (tanpa migrasi, Auth, Penugasan, mobile)
 *   T-7 lint PHP seluruh berkas baru/berubah
 */

$root = dirname(__DIR__);
$baseline = getenv('PKF2_BASELINE') ?: '464d9a1';
$lulus = 0;
$gagal = [];
$assert = static function (bool $ok, string $pesan) use (&$lulus, &$gagal): void {
    echo ($ok ? '[lulus] ' : '[gagal] ') . $pesan . PHP_EOL;
    $ok ? $lulus++ : $gagal[] = $pesan;
};
$baca = static fn (string $p): string => (string) file_get_contents($root . '/' . $p);
/** Potongan teks di antara dua penanda (penanda kedua dicari sesudah yang pertama). */
$potong = static function (string $isi, string $awal, string $akhir): string {
    $a = strpos($isi, $awal);
    if ($a === false) {
        return '';
    }
    $b = strpos($isi, $akhir, $a + strlen($awal));

    return $b === false ? substr($isi, $a) : substr($isi, $a, $b - $a);
};

// ===================================================================== T-1
echo PHP_EOL . '=== T-1. Izin: routing dan penolakan ===' . PHP_EOL;
$pt = $baca('app/Izin/PemisahanTugas.php');
$assert(str_contains($pt, 'SELECT diajukan_oleh_user_id AS user_id FROM izin_pengajuan') && str_contains($pt, 'ditinjau_oleh_user_id')
    && str_contains($pt, "Schema::hasTable(\$this->db, 'izin_pembimbing_tinjauan')"), 'Akun terlibat = pengaju + peninjau V7 (tabel V7 diperiksa keberadaannya)');
$assert(str_contains($pt, 'SELECT guru_id FROM users WHERE guru_id IS NOT NULL AND id IN'), 'Guru terlibat dibaca dari users.guru_id (level akun)');
$assert(!preg_match('/\b(INSERT|UPDATE|DELETE)\b/', $pt), 'PemisahanTugas izin hanya membaca');
$router = $baca('app/Izin/IzinRouter.php');
$assert(str_contains($router, 'public function resolve(int $santriId, string $onDate, array $kecualiGuru = []): array')
    && str_contains($router, '$candidates = self::tanpaGuru($semua, $kecualiGuru);'), 'Router: kandidat dikurangi guru terlibat sebelum dihitung');
$assert(str_contains($router, "255 - mb_strlen(\$catatanPemisahan)"), 'Catatan routing dibatasi 255 karakter dengan akhiran pemisahan tugas');
$alur = $baca('app/Izin/IzinWorkflowService.php');
$create = $potong($alur, 'public function create(', 'public function assignMurobi(');
$assert(str_contains($create, '$this->router->resolve($santriId, $onDate, $this->pemisahan()->guruAkun([$userId]))'), 'create(): guru milik akun pengaju dikecualikan dari routing');
$assign = $potong($alur, 'public function assignMurobi(', 'public function decide(');
$assert(strpos($assign, '$row = $this->requirePending($pengajuanId);') < strpos($assign, 'guruTerlibat($pengajuanId)')
    && strpos($assign, 'guruTerlibat($pengajuanId)') < strpos($assign, 'updateStatusWithVersion'), 'assignMurobi(): guru terlibat ditolak sesudah baris dikunci, sebelum perubahan');
$decide = $potong($alur, 'public function decide(', 'public function cancel(');
$assert(strpos($decide, '$row = $this->requirePending($pengajuanId);') < strpos($decide, 'wajibBukanTerlibat($userId, $pengajuanId)')
    && strpos($decide, 'wajibBukanTerlibat($userId, $pengajuanId)') < strpos($decide, 'insertKeputusan'), 'decide(): akun terlibat ditolak sesudah kunci, sebelum keputusan; berlaku untuk murobi dan admin pengganti');
$koreksi = $potong($alur, 'public function correctDecision(', 'public function actionsFor(');
$assert(strpos($koreksi, 'lockPengajuan($pengajuanId);' . "\n" . '            if ($row === null)') !== false
    && strpos($koreksi, 'wajibBukanTerlibat($userId, $pengajuanId)') < strpos($koreksi, 'insertKoreksi'), 'correctDecision(): akun terlibat ditolak sebelum koreksi');
$waliSvc = $baca('app/Izin/IzinWaliService.php');
$tinjau = $potong($waliSvc, 'public function tinjau(', 'public function perbaiki(');
$assert(str_contains($tinjau, "(new PemisahanTugas(\$this->write->db()))->guruTerlibat(\$id, [\$userId])")
    && strpos($tinjau, '$row = $this->write->lockPengajuan($id);') < strpos($tinjau, 'guruTerlibat'), 'V7 tinjau(): pengaju, peninjau sekarang, dan peninjau sebelumnya dikecualikan sesudah baris dikunci');
$assert(str_contains($alur, 'IzinRouter::tanpaGuru($eligible, $this->pemisahan()->guruTerlibat($pengajuanId))')
    && str_contains($alur, 'isset($pengajuan[\'id\']) ? $this->pemisahan()->guruTerlibat((int) $pengajuan[\'id\']) : []'), 'Daftar murobi berhak dan kandidat tampilan mengecualikan guru terlibat');

// ===================================================================== T-2
echo PHP_EOL . '=== T-2. Izin: tampilan ===' . PHP_EOL;
$aksi = $potong($alur, 'public function actionsFor(', 'public function terlibat(');
preg_match_all("/'([a-z_]+)' => /", $potong($aksi, 'return [', '];'), $m);
$assert($m[1] === ['putuskan_murobi', 'putuskan_admin', 'tetapkan_murobi', 'batalkan', 'koreksi'], 'actionsFor(): tetap lima kunci (tipe IzinActions aplikasi tidak berubah)');
$assert(str_contains($aksi, 'public function actionsFor(array $pengajuan, array $scope, ?int $userId = null): array'), 'actionsFor(): parameter akun opsional; pemanggil lama tetap sah');
foreach (['portal/izin_detail.php', 'portal/izin_antrean.php'] as $f) {
    $assert(str_contains($baca($f), "actionsFor(\$izin, \$scope, (int) \$currentUser['id'])"), "$f meneruskan akun yang melihat");
}
$api = $baca('app/Api/IzinApiService.php');
$assert(substr_count($api, '(int) $user[\'id\']') >= 3 && str_contains($api, "'aksi' => \$this->workflow->actionsFor(\$row, \$scope, \$userId)"), 'REST rincian dan daftar meneruskan akun');
$assert(str_contains($waliSvc, "'putuskan_pengganti' => \$belumDiputus && !\$terlibat") && str_contains($waliSvc, "'putuskan' => \$status === IzinRouter::STATUS_TERARAH && !\$terlibat"),
    'Rincian V7 menyembunyikan keputusan bagi akun terlibat');

// ===================================================================== T-3
echo PHP_EOL . '=== T-3. Sikap V4 ===' . PHP_EOL;
$sikap = $baca('app/V4/SikapService.php');
$simpan = $potong($sikap, 'public function save(', '$uid=(int)$u[\'id\'];$ver=$version+1;');
$assert(strpos($simpan, 'FROM v4_sikap WHERE santri_id=? AND tahun_ajaran_id=? FOR UPDATE') < strpos($simpan, "\$mode==='murobi'&&in_array((int)\$u['id'],\$this->pengisiPembimbing("),
    'save(): pengisi dibaca sesudah baris sikap dikunci, untuk seluruh tindakan murobi (diketahui dan koreksi)');
$assert(str_contains($sikap, "UNION SELECT sikap_id,actor_user_id FROM v4_sikap_riwayat WHERE kapasitas='pembimbing'"), 'Pengisi = pembimbing pertama + pelaku riwayat berkapasitas pembimbing');
$assert(str_contains($sikap, 'in_array((int)$old[\'diketahui_murobi_user_id\'],$this->murobiSah($p,$sid,$old),true)'), 'Tanda lama dari pengisi tidak mengunci murobi lain');
$verif = $baca('app/V4/VerifikasiService.php');
$assert(str_contains($verif, '$sah=(new SikapService($this->db,$this->audit))->murobiSah($p,$sid,$row?:null);') && str_contains($verif, "in_array((int)(\$row['diketahui_murobi_user_id']??0),\$sah,true)"),
    'Matriks: diketahui sah hanya dari murobi yang bukan pengisi; penghalang khusus bila seluruh murobi pengisi');
$assert(str_contains($sikap, "if(!in_array(\$action,\$mode==='murobi'?['diketahui','koreksi']:['draf','serahkan','koreksi'],true))"), 'Tanpa tindakan baru: tidak ada jalur admin/pengganti untuk mengetahui (PK-T7)');

// ===================================================================== T-4
echo PHP_EOL . '=== T-4. PK-T1 verifikasi nilai ===' . PHP_EOL;
$act = $potong($verif, 'public function act(', 'catch(Throwable $e)');
$assert(strpos($act, 'SELECT id FROM v4_rencana WHERE id=? FOR UPDATE') < strpos($act, '$this->pengisiNilai($material[\'sumber\'])'),
    'act(): pengisi nilai dibaca sesudah rencana dikunci (penyimpanan nilai mengunci rencana yang sama)');
$assert(str_contains($act, "\$kind==='nilai'&&in_array(\$action,['verifikasi','kembalikan'],true)&&\$capacity==='pendidikan'"), 'Hanya bahan nilai, verifikasi/kembalikan, kapasitas Pendidikan (admin tetap jalur pengganti)');
$assert(str_contains($act, '$p=$this->scope->plan($plan);$capacity=$this->scope->education('), 'Kapasitas dihitung ulang di dalam transaksi');
$assert(str_contains($verif, "'matriks_hash'=>\$sid?BahanRepository::fingerprint(\$matrix[0]):null,'diisi_sendiri'=>\$sendiri"), 'Penanda diisi sendiri di luar matriks bersidik jari');
$assert(str_contains($verif, 'SELECT DISTINCT pelaku_user_id FROM v4_nilai_riwayat'), 'Pengisi nilai mencakup riwayat (guru maupun Pendidikan pengganti)');
$hal = $baca('portal/verifikasi_semester.php');
$assert(str_contains($hal, "!isset(\$sendiri[(int)\$st['santri']['id'].':'.(int)\$m['mapel_key']])"), 'Verifikasi massal tidak memuat nilai isian sendiri');

// ===================================================================== T-5
echo PHP_EOL . '=== T-5. V3 tanda mengetahui ===' . PHP_EOL;
$pel = $baca('app/V3/PelanggaranService.php');
$ack = $potong($pel, 'public function acknowledge(', 'public function page(');
$assert(strpos($ack, '$current=$this->repo->violation($id,true);') < strpos($ack, 'pelanggaranMilik($actorId,$id)') && strpos($ack, 'pelanggaranMilik') < strpos($ack, 'insertMurobiNote'),
    'Pelanggaran: pemilik diperiksa sesudah baris dikunci, sebelum catatan murobi');
$kons = $baca('app/V3/KonselingService.php');
$ackK = $potong($kons, 'public function acknowledge(', 'public function page(');
$assert(strpos($ackK, "\$this->repo->case((int)\$case['id'],true)") < strpos($ackK, '$this->milikAkun($actorId,$type,$id)') && strpos($ackK, 'milikAkun') < strpos($ackK, 'insertMurobiNote'),
    'Kasus/sesi: pemilik diperiksa sesudah kasus dikunci, sebelum catatan murobi');
$v3pt = $baca('app/V3/PemisahanTugas.php');
$assert(str_contains($v3pt, 'revisi_dari_id') && str_contains($v3pt, 'v3_konseling_kasus_revisi') && str_contains($v3pt, 'SELECT pengurus_id FROM users WHERE id = ?')
    && !preg_match('/\b(INSERT|UPDATE|DELETE)\b/', $v3pt), 'Pemilik = pembuat/pembaru/revisi atau identitas pembimbing akun; hanya membaca');

// ===================================================================== T-6
echo PHP_EOL . '=== T-6. Batas Fase 2 ===' . PHP_EOL;
exec('cd ' . escapeshellarg($root) . ' && git diff --name-only ' . escapeshellarg($baseline) . ' -- . 2>/dev/null', $berubah, $kode);
exec('cd ' . escapeshellarg($root) . ' && git ls-files --others --exclude-standard 2>/dev/null', $baru);
$semua = array_values(array_unique(array_merge($berubah, $baru)));
$boleh = static fn (string $f): bool => in_array($f, [
    'app/Izin/PemisahanTugas.php', 'app/Izin/IzinRouter.php', 'app/Izin/IzinWorkflowService.php', 'app/Izin/IzinWaliService.php', 'app/Api/IzinApiService.php',
    'app/V4/SikapService.php', 'app/V4/VerifikasiService.php', 'app/Beranda/BerandaService.php',
    'app/V3/PemisahanTugas.php', 'app/V3/PelanggaranRepository.php', 'app/V3/PelanggaranService.php', 'app/V3/KonselingRepository.php', 'app/V3/KonselingService.php',
    'portal/izin_detail.php', 'portal/izin_antrean.php', 'portal/sikap_semester.php', 'portal/verifikasi_semester.php', 'portal/v3_pelanggaran_detail.php', 'portal/v3_konseling_detail.php',
    'AGENTS.md',
], true) || str_starts_with($f, 'tests/penugasan_keamanan_f2') || str_starts_with($f, 'tests/browser/uji-penugasan-keamanan-f2') || str_starts_with($f, 'tests/browser/tangkapan-penugasan-keamanan-f')
    || str_starts_with($f, 'docs/penugasan-keamanan/') || str_starts_with($f, 'docs/panduan-pengguna-v4/') || str_starts_with($f, 'docs/presentasi-al-hasan-v4/') || str_starts_with($f, 'output/');
$liar = array_values(array_filter($semua, static fn (string $f): bool => !$boleh($f)));
$assert($kode === 0 && $liar === [], 'Hanya berkas Fase 2 yang berubah terhadap baseline ' . $baseline . ($liar === [] ? '' : ': ' . implode(', ', $liar)));
$assert(array_filter($semua, static fn (string $f): bool => (bool) preg_match('#^(database/|app/(Auth|Penugasan|Account|MasterData|Kesehatan|Bsa|Notification)/|api/)#', $f)) === [],
    'Tanpa migrasi; Auth, Penugasan, akun, master, kesehatan, BSA, notifikasi, dan rute API tidak diubah');

// ===================================================================== T-7
echo PHP_EOL . '=== T-7. Lint ===' . PHP_EOL;
foreach (array_filter($semua, static fn (string $f): bool => str_ends_with($f, '.php') && is_file($root . '/' . $f) && !str_starts_with($f, 'docs/') && !str_starts_with($f, 'output/')) as $berkas) {
    exec('php -l ' . escapeshellarg($root . '/' . $berkas) . ' 2>&1', $keluaran, $hasil);
    $assert($hasil === 0, "lint $berkas");
}

echo PHP_EOL . 'Hasil: ' . $lulus . ' lulus, ' . count($gagal) . ' gagal.' . PHP_EOL;
exit($gagal === [] ? 0 : 1);
