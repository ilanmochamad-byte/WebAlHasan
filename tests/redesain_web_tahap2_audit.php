<?php
declare(strict_types=1);

/**
 * Probe independen audit Tahap 2. Jalankan sesudah fixture dan server 8941:
 * DB_NAME=test_redesain_t2_test php tests/redesain_web_tahap2_audit.php
 * Exit 1 berarti kriteria belum terpenuhi, bukan galat infrastruktur.
 * Fixture layanan di-rollback. Fixture HTTP Admin+Pendidikan terlihat server
 * lalu dibersihkan di finally; hanya DB khusus Tahap 2.
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
$rx = $dom($recommendations['body']);
$actualIds = [];
foreach ($rx->query('//section[@id="rekomendasi"]//*[@data-rekomendasi]') as $row) {
    $actualIds[] = (int) $row->getAttribute('data-rekomendasi');
}
$expectedIds = array_map('intval', array_column(app_db()->query("SELECT r.id FROM v3_rekomendasi r JOIN santri s ON s.id=r.santri_id
    WHERE r.archived_at IS NULL AND r.tidak_berlaku_pada IS NULL AND r.ditindaklanjuti_kasus_id IS NULL AND r.status='Baru'")->fetch_all(MYSQLI_ASSOC), 'id'));
sort($actualIds); sort($expectedIds);
$cardCount = (int) str_replace('.', '', $x->evaluate('string(//*[@data-ah-kartu="rekomendasi"]/*[contains(@class,"ah-task__num")])'));
$check($recommendations['status'] === 200 && $actualIds !== [] && $actualIds === $expectedIds && count($actualIds) === $cardCount,
    'B-1/AA: pemantauan Admin memuat tepat rekomendasi tertunda yang dihitung', ['kartu'=>$cardCount,'daftar'=>$actualIds]);
$check(!str_contains($recommendations['body'], 'name="rekomendasi_ids[]"') && str_contains($recommendations['body'], 'daftar ini untuk pemantauan'),
    'V3: pemantauan Admin menjelaskan tindak lanjut oleh pembimbing');
$adminUser = (new App\Auth\AuthRepository(app_db()))->findByUsername('sbx_admin');
$reference = app_db()->query('SELECT santri_id,tahun_ajaran_id FROM v3_rekomendasi WHERE id='.$expectedIds[0])->fetch_assoc();
try {
    v3_konseling_service()->createCase($adminUser, ['santri_id'=>$reference['santri_id'], 'tahun_ajaran_id'=>$reference['tahun_ajaran_id'],
        'tujuan'=>'SBX audit kewenangan', 'kerahasiaan'=>'Internal', 'rekomendasi_ids'=>[$expectedIds[0]],
        'idempotency_key'=>'audit-admin-'.bin2hex(random_bytes(12))]);
    $check(false,'V3: Admin murni tetap ditolak membuka kasus tanpa penugasan pembimbing');
} catch (App\V3\V3Exception $e) {
    $check($e->status === 403,'V3: Admin murni tetap ditolak membuka kasus tanpa penugasan pembimbing',$e->status);
}
$pembimbing = new AuditT2Client($base); $pembimbing->login('sbx_pengurus_a');
$check(str_contains($pembimbing->req('/portal/v3_konseling.php')['body'], 'name="rekomendasi_ids[]"'),
    'V3: pembimbing tetap dapat memilih rekomendasi untuk tindak lanjut');
$perbaikan = $dom($admin->req($cards['bahan_perbaikan'])['body']);
$materialCount = $perbaikan->query('//article[contains(@class,"bahan-card")]')->length;
$expectedMaterials = (int)str_replace('.', '', $x->evaluate('string(//*[@data-ah-kartu="bahan_perbaikan"]/*[contains(@class,"ah-task__num")])'));
$check($materialCount === $expectedMaterials, 'F-4: daftar perbaikan hanya memuat bahan yang dihitung kartu', ['daftar'=>$materialCount,'kartu'=>$expectedMaterials]);
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
foreach ([['aksi'=>'buat','mode'=>'admin'], ['aksi'=>'tetapkan']] as $payload) {
    // ID kosong sengaja dipakai agar probe sebelum koreksi tidak menulis data.
    $post = $ganda->req('/portal/izin_aksi.php', ['_csrf'=>$ganda->token()] + $payload);
    $check($post['status'] === 403 && str_contains($post['body'], 'Buka sebagai Admin'),
        'F-1: POST pemroses izin tidak memakai kapasitas Admin dari peran Guru',
        ['payload'=>$payload,'http'=>$post['status']]);
}

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

// Sidik awal sengaja dibuat SAH untuk semua sumber, kemudian masing-masing
// jenis diubah. Ini membuktikan invalidasi, bukan hanya kesetaraan dua nol.
$db->begin_transaction();
try {
    $vs = new App\V4\VerifikasiService($db, audit_logger());
    $plans = $vs->options($pendidikan);
    foreach ($plans as $p) foreach ($vs->read($pendidikan, (int)$p['id'])['matriks'] as $st) foreach ($st['bahan'] as $b) {
        $plan = (int)$p['id']; $sid = (int)$st['santri']['id']; $kind = $b['jenis']; $key = (int)$b['mapel_key'];
        $status = $kind === 'sikap' ? 'Terverifikasi' : 'Dikembalikan';
        $hash = App\V4\BahanRepository::fingerprint($b['sumber']); $actor = (int)$pendidikan['id']; $capacity = 'pendidikan';
        $q = $db->prepare('INSERT INTO v4_bahan(rencana_id,santri_id,jenis,mapel_key,status,sumber_hash,actor_user_id,kapasitas)
            VALUES (?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE status=VALUES(status),sumber_hash=VALUES(sumber_hash)');
        $q->bind_param('iisissis',$plan,$sid,$kind,$key,$status,$hash,$actor,$capacity); $q->execute(); $q->close();
    }
    $compare = static function (string $stage) use ($db,$pendidikan,$vs,$plans,$check): array {
        $expected = ['terverifikasi'=>0,'perbaikan'=>0]; $actual = $expected;
        foreach ($plans as $p) {
            foreach ($vs->read($pendidikan,(int)$p['id'])['matriks'] as $st) foreach ($st['bahan'] as $b) {
                $expected['terverifikasi'] += $b['pemeriksaan']['status'] === 'Terverifikasi' ? 1 : 0;
                $expected['perbaikan'] += $b['pemeriksaan']['status'] === 'Dikembalikan' ? 1 : 0;
            }
            $sum = (new App\Beranda\BerandaService($db,new App\Auth\Capabilities($db)))->ringkasBahan((int)$p['id'],(int)$p['tahun_ajaran_id'],(int)$p['kelas_id']);
            foreach ($actual as $key=>$value) $actual[$key] += $sum[$key];
        }
        $check($expected === $actual,'F-3: sumber sah lalu '.$stage.' tetap setara matriks',$actual);
        return $actual;
    };
    $initial = $compare('keadaan awal');
    $check($initial['terverifikasi'] > 0 && $initial['perbaikan'] > 0,'Fixture F-3 mempunyai status sah pada kedua hitungan');
    $db->query('UPDATE v4_nilai SET nilai=nilai+1 ORDER BY id LIMIT 1');
    $afterNilai = $compare('nilai berubah');
    $check($afterNilai['perbaikan'] === $initial['perbaikan']-1,'F-3: perubahan nilai mengeluarkan satu bahan perbaikan');
    $db->query("UPDATE v4_sikap SET catatan='SBX audit sumber berubah' ORDER BY id LIMIT 1");
    $afterSikap = $compare('sikap berubah');
    $check($afterSikap['terverifikasi'] === $afterNilai['terverifikasi']-1,'F-3: perubahan sikap membatalkan satu verifikasi sah');
    $year = (int)$plans[0]['tahun_ajaran_id']; $class = (int)$plans[0]['kelas_id'];
    $sid = (int)$db->query("SELECT id_santri FROM plotting_kelas WHERE id_kelas=$class AND id_tahun=$year ORDER BY id_santri LIMIT 1")->fetch_row()[0];
    $meeting = (int)$db->query("SELECT p.id FROM pertemuan_pengajian p JOIN jadwal_ngaji j ON j.id=p.jadwal_id WHERE p.status='Selesai' AND j.id_tahun=$year
        AND NOT EXISTS (SELECT 1 FROM pertemuan_peserta pp WHERE pp.pertemuan_id=p.id AND pp.santri_id=$sid) LIMIT 1")->fetch_row()[0];
    $db->query("INSERT INTO pertemuan_peserta(pertemuan_id,santri_id,nis_snapshot,nama_santri_snapshot,kelas_id_snapshot,tahun_ajaran_id_snapshot)
        SELECT $meeting,id,nis,nama_santri,$class,$year FROM santri WHERE id=$sid");
    $afterHadir = $compare('kehadiran berubah');
    $check($afterHadir['perbaikan'] === $afterSikap['perbaikan']-1,'F-3: perubahan kehadiran mengeluarkan satu bahan perbaikan');
} finally { $db->rollback(); }

// HTTP akun Admin+Pendidikan: dua kelas berbeda jenjang, penugasan hanya
// mencakup kelas awal. Fixture tambahan terlihat server lalu dibersihkan.
$uid = (int)$pendidikan['id'];
$adminRole = (int)$db->query("SELECT id FROM roles WHERE slug='admin'")->fetch_row()[0];
$assignment = $db->query('SELECT id,jenjang FROM pendidikan_assignments WHERE pengurus_id=86 ORDER BY id LIMIT 1')->fetch_assoc();
$initialPlan = $db->query('SELECT r.*,k.jenjang FROM v4_rencana r JOIN kelas k ON k.id=r.kelas_id ORDER BY r.id LIMIT 1')->fetch_assoc();
$otherStudent = (int)$db->query("SELECT s.id FROM santri s WHERE s.is_active=1 AND s.archived_at IS NULL AND NOT EXISTS
    (SELECT 1 FROM plotting_kelas pk WHERE pk.id_santri=s.id AND pk.id_kelas=".(int)$initialPlan['kelas_id']." AND pk.id_tahun=".(int)$initialPlan['tahun_ajaran_id'].") ORDER BY s.id DESC LIMIT 1")->fetch_row()[0];
$addedRole = false; $classId = 0; $planId = 0; $placementId = 0;
try {
    $db->query("INSERT IGNORE INTO user_roles(user_id,role_id) VALUES ($uid,$adminRole)");
    $addedRole = $db->affected_rows === 1;
    $db->query("UPDATE pendidikan_assignments SET jenjang='".$db->real_escape_string($initialPlan['jenjang'])."' WHERE id=".(int)$assignment['id']);
    $db->query("INSERT INTO kelas(nama_kelas,jenjang,is_active) VALUES ('SBX Audit di luar Pendidikan','AUDIT-OUT',1)");
    $classId = (int)$db->insert_id; $year = (int)$initialPlan['tahun_ajaran_id'];
    $db->query("INSERT INTO v4_rencana(tahun_ajaran_id,kelas_id,status,tempat,created_by) VALUES ($year,$classId,'Siap','Uji',$uid)");
    $planId = (int)$db->insert_id;
    $db->query("INSERT INTO plotting_kelas(id_santri,id_kelas,id_tahun,tanggal_mulai,status) VALUES ($otherStudent,$classId,$year,CURDATE(),'Aktif')");
    $placementId = (int)$db->insert_id;
    $hash = App\V4\BahanRepository::fingerprint([]);
    $db->query("INSERT INTO v4_bahan(rencana_id,santri_id,jenis,mapel_key,status,sumber_hash,actor_user_id,kapasitas)
        VALUES ($planId,$otherStudent,'kehadiran',0,'Dikembalikan','$hash',$uid,'pendidikan')");
    $client = new AuditT2Client($base); $client->login('sbx_pendidikan');
    $client->req('/portal/peran.php', ['_csrf'=>$client->token(), 'peran'=>'pendidikan']);
    $home = $client->req('/portal/index.php');
    $check(!str_contains($home['body'], 'SBX Audit di luar Pendidikan'), 'F-2: Beranda akun Admin+Pendidikan hanya kelas penugasan sendiri');
    $destination = $client->req('/portal/verifikasi_semester.php?status=Dikembalikan');
    $check($destination['status'] === 200 && !str_contains($destination['body'], 'SBX Audit di luar Pendidikan'),
        'F-4: tujuan perbaikan Pendidikan tetap dalam kelas yang dihitung Beranda');
    foreach ([null, ['_csrf'=>$client->token(), 'rencana_id'=>(string)$planId, 'action'=>'periksa_finalisasi']] as $post) {
        $outside = $client->req('/portal/verifikasi_semester.php?rencana_id='.$planId, $post);
        $check($outside['status'] === 403 && str_contains($outside['body'], 'Buka sebagai Admin'),
            'F-1/F-2: rencana di luar Pendidikan menawarkan Admin sebelum GET/POST berjalan', $post === null ? 'GET' : 'POST');
    }
    $client->req('/portal/peran.php', ['_csrf'=>$client->token(), 'peran'=>'admin']);
    $check(str_contains($client->req('/portal/verifikasi_semester.php?status=Dikembalikan')['body'], 'SBX Audit di luar Pendidikan'),
        'F-2: pengawasan semua kelas tetap tersedia setelah memilih Admin');
} finally {
    if ($planId) { $db->query("DELETE FROM v4_bahan WHERE rencana_id=$planId"); $db->query("DELETE FROM v4_rencana WHERE id=$planId"); }
    if ($placementId) $db->query("DELETE FROM plotting_kelas WHERE id=$placementId");
    if ($classId) $db->query("DELETE FROM kelas WHERE id=$classId");
    $jenjang = $assignment['jenjang'] === null ? 'NULL' : "'".$db->real_escape_string($assignment['jenjang'])."'";
    $db->query('UPDATE pendidikan_assignments SET jenjang='.$jenjang.' WHERE id='.(int)$assignment['id']);
    if ($addedRole) $db->query("DELETE FROM user_roles WHERE user_id=$uid AND role_id=$adminRole");
}
echo "Ringkasan: $passed lulus, $failed gagal\n";
exit($failed ? 1 : 0);
