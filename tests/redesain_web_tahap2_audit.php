<?php
declare(strict_types=1);

/**
 * Probe independen audit Tahap 2. Jalankan sesudah fixture dan server 8941:
 * DB_NAME=test_redesain_t2_test php tests/redesain_web_tahap2_audit.php
 * Exit 1 berarti kriteria belum terpenuhi, bukan galat infrastruktur.
 * Perubahan fixture layanan selalu di-rollback; hanya DB khusus Tahap 2.
 */
putenv('DB_NAME=test_redesain_t2_test');
require_once dirname(__DIR__) . '/app/bootstrap.php';
if (app_config('database.database') !== 'test_redesain_t2_test') {
    throw new RuntimeException('Hanya DB fixture Tahap 2.');
}
$base = getenv('BASE_URL') ?: 'http://127.0.0.1:8941';
if (!preg_match('#^http://127\.0\.0\.1:\d+$#D', $base)) {
    throw new RuntimeException('Hanya server lokal.');
}
$passed = 0; $failed = 0;
$check = static function (bool $ok, string $label, mixed $observed = null) use (&$passed, &$failed): void {
    $ok ? $passed++ : $failed++;
    echo ($ok ? '[lulus] ' : '[gagal] ') . $label;
    if ($observed !== null) echo ' | ' . json_encode($observed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    echo PHP_EOL;
};
final class AuditT2Client
{
    private string $jar;
    public function __construct(private string $base) { $this->jar = tempnam(sys_get_temp_dir(), 'audit-t2-'); }
    public function __destruct() { @unlink($this->jar); }
    public function req(string $path, ?array $post = null): array {
        $h = curl_init($this->base . $path);
        curl_setopt_array($h, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_HEADER=>true,
            CURLOPT_COOKIEJAR=>$this->jar, CURLOPT_COOKIEFILE=>$this->jar, CURLOPT_TIMEOUT=>20]);
        if ($post !== null) { curl_setopt($h, CURLOPT_POST, true); curl_setopt($h, CURLOPT_POSTFIELDS, http_build_query($post)); }
        $raw = curl_exec($h);
        if ($raw === false) throw new RuntimeException(curl_error($h));
        $size = curl_getinfo($h, CURLINFO_HEADER_SIZE); $status = curl_getinfo($h, CURLINFO_RESPONSE_CODE);
        curl_close($h);
        return ['status'=>$status, 'body'=>substr($raw, $size), 'headers'=>substr($raw, 0, $size)];
    }
    public function token(string $path = '/portal/index.php'): string {
        preg_match('/name="_csrf" value="([^"]+)"/', $this->req($path)['body'], $m);
        return $m[1] ?? throw new RuntimeException('Token tidak ditemukan.');
    }
    public function login(string $name): void {
        $r = $this->req('/admin/cek_login.php', ['_csrf'=>$this->token(), 'username'=>$name, 'password'=>'Sandbox#123']);
        if ($r['status'] !== 302) throw new RuntimeException('Masuk gagal.');
    }
}
$dom = static function (string $html): DOMXPath {
    $d = new DOMDocument(); @$d->loadHTML('<?xml encoding="UTF-8">' . $html);
    return new DOMXPath($d);
};
$admin = new AuditT2Client($base); $admin->login('sbx_admin');
$x = $dom($admin->req('/portal/index.php?semua=1')['body']);
$cards = [];
foreach ($x->query('//*[@data-ah-kartu]') as $card) {
    $cards[$card->getAttribute('data-ah-kartu')] = $x->evaluate('string(.//a/@href)', $card);
}
$check(count($cards) === 8, 'Fixture menampilkan seluruh delapan prioritas admin', array_keys($cards));
$psb = $admin->req($cards['psb_baru']);
$px = $dom($psb['body']);
$statuses = [];
foreach ($px->query('//table[@id="tabelSantri"]//tbody/tr/td[6]') as $cell) $statuses[] = trim($cell->textContent);
// Status pendaftar berada pada kolom keenam tabel lama.
$check($statuses !== [] && array_filter($statuses, static fn ($s) => $s !== 'Baru') === [],
    'B-1: kartu pendaftar baru hanya menampilkan pendaftar Baru', ['url'=>$cards['psb_baru'], 'status'=>$statuses]);
$recommendations = $admin->req($cards['rekomendasi']);
$check(str_contains($recommendations['body'], 'name="rekomendasi_ids[]"'),
    'B-1/AA: kartu rekomendasi menyediakan rekomendasi yang bisa ditindaklanjuti', $cards['rekomendasi']);
$ganda = new AuditT2Client($base); $ganda->login('sbx_ganda');
$ganda->req('/portal/peran.php', ['_csrf'=>$ganda->token(), 'peran'=>'guru']);
$ganda->req('/portal/notifikasi.php');
$check(str_contains($ganda->req('/portal/index.php')['body'], 'Peran: Guru'), 'AR: halaman bersama mempertahankan peran Guru');
$other = $ganda->req('/admin/admin_master_santri.php');
$check($other['status'] === 403 && str_contains($other['body'], 'Buka sebagai'),
    'A-2/A-5: halaman Admin dari peran Guru menawarkan perpindahan eksplisit',
    ['http'=>$other['status'], 'otomatis'=>str_contains($other['body'], 'Peran beralih ke Admin')]);
$check(str_contains($ganda->req('/portal/index.php')['body'], 'Peran: Guru'),
    'AR: membuka tautan peran lain tidak mengganti pilihan sesi tanpa pilihan pengguna');

$db = app_db();
$repo = new App\Auth\AuthRepository($db);
$pendidikan = $repo->findByUsername('sbx_pendidikan');
$multi = $repo->findByUsername('sbx_ganda');
$check($pendidikan !== null && $multi !== null, 'Fixture akun layanan tersedia');
$db->begin_transaction();
try {
    // Bahan yang diverifikasi kemudian berubah sumbernya: status efektif
    // layanan V4 adalah Draf/perlu verifikasi ulang, walau status tersimpan lama.
    $db->query("UPDATE v4_bahan SET status='Terverifikasi', sumber_hash=REPEAT('0',64)");
    $service = new App\V4\VerifikasiService($db, audit_logger());
    $expected = 0;
    foreach ($service->options($pendidikan) as $plan) {
        foreach ($service->read($pendidikan, (int)$plan['id'])['matriks'] as $student) {
            foreach ($student['bahan'] as $material) {
                if ($material['pemeriksaan']['status'] === 'Terverifikasi') $expected++;
            }
        }
    }
    $beranda = new App\Beranda\BerandaService($db, new App\Auth\Capabilities($db));
    $actual = array_sum(array_column($beranda->kemajuanKelas($pendidikan), 'terverifikasi'));
    $check($actual === $expected, 'B-4: jumlah terverifikasi mengikuti status efektif sumber V4', ['beranda'=>$actual, 'matriks'=>$expected]);
    // Satu dari dua mapel ditugaskan ke akun admin+guru. Beranda Guru harus
    // menghitung tugas miliknya; pengawasan Admin tetap tersedia di peran Admin.
    $db->query("UPDATE guru_mapel_assignments SET guru_id=206 WHERE id=(SELECT guru_mapel_assignment_id FROM v4_rencana_mapel ORDER BY id LIMIT 1)");
    $caps = new App\Auth\Capabilities($db);
    $own = $caps->featureScopes($multi, App\Penugasan\PenugasanJenis::CAP_NILAI_INPUT);
    $nilai = (new App\Beranda\BerandaService($db, $caps))->kemajuanNilai($multi);
    $check(count($own) === 1, 'Fixture akun ganda hanya ditugaskan pada satu mapel', count($own));
    $check(count($nilai) === 1, 'B-2: tugas nilai peran Guru dibatasi ke penugasan sendiri', array_column($nilai, 'label'));
} finally { $db->rollback(); }
echo "Ringkasan: $passed lulus, $failed gagal\n";
exit($failed ? 1 : 0);
