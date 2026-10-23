<?php
declare(strict_types=1);

// Probe independen Codex. Jalankan sesudah DB Tahap 6 dibangun ulang dan
// server router pada 8981 hidup. Hanya fixture sintetis pada DB *_test.
$root = dirname(__DIR__);
$dbName = getenv('DB_NAME') ?: 'test_redesain_t6_test';
if (!preg_match('/^test_.*_test$/', $dbName)) { exit(77); }
putenv('DB_NAME=' . $dbName);
require $root . '/app/bootstrap.php';
$base = getenv('BASE_URL') ?: 'http://127.0.0.1:8981';
if (!preg_match('#^http://127\.0\.0\.1:\d+$#', $base)) { exit(77); }
$db = app_db();
$pass = 0; $fail = 0;
$check = static function (bool $ok, string $label) use (&$pass, &$fail): void {
    $ok ? $pass++ : $fail++;
    echo ($ok ? '[lulus] ' : '[gagal] ') . $label . PHP_EOL;
};
$one = static fn (string $sql): array => $db->query($sql)->fetch_assoc() ?? [];
final class T6AuditClient {
    private string $jar;
    public function __construct(private string $base, string $user) {
        $this->jar = tempnam(sys_get_temp_dir(), 't6audit-');
        $this->req('/admin/cek_login.php', ['_csrf' => $this->token($this->req('/portal/index.php')['body'], '_csrf'), 'username' => $user, 'password' => 'Sandbox#123']);
    }
    public function __destruct() { @unlink($this->jar); }
    public function req(string $path, ?array $data = null): array {
        $c = curl_init($this->base . $path);
        curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_COOKIEFILE => $this->jar, CURLOPT_COOKIEJAR => $this->jar, CURLOPT_TIMEOUT => 30]);
        if ($data !== null) { curl_setopt($c, CURLOPT_POSTFIELDS, http_build_query($data)); }
        $raw = (string) curl_exec($c); $size = curl_getinfo($c, CURLINFO_HEADER_SIZE);
        $code = curl_getinfo($c, CURLINFO_RESPONSE_CODE); curl_close($c);
        preg_match('/^Location:\s*(.+)$/mi', substr($raw, 0, $size), $m);
        return ['status' => $code, 'body' => substr($raw, $size), 'location' => trim($m[1] ?? '')];
    }
    public function token(string $html, string $name): string {
        preg_match('/name="' . preg_quote($name, '/') . '" value="([^"]+)"/', $html, $m);
        return html_entity_decode($m[1] ?? '', ENT_QUOTES);
    }
}
$admin = new T6AuditClient($base, 'sbx_admin');
$actor = (int) $one("SELECT id FROM users WHERE username='sbx_admin'")['id'];
$_SESSION['user_id'] = $actor;
$svc = master_data_service();
$suffix = substr(bin2hex(random_bytes(5)), 0, 10);

// F-1: dua konflik orang tua harus selesai dari dialog yang benar-benar
// ditawarkan UI. Jangan menyuntik konfirmasi tersembunyi yang tidak tersedia.
$sid = $svc->saveSantri(['nis' => 'AUD6-' . $suffix, 'nama_santri' => 'Audit T6 Dua Orang Tua', 'jenis_kelamin' => 'P', 'tgl_lahir' => '2013-02-02', 'nama_ayah' => 'Ayah lama', 'nama_ibu' => 'Ibu lama']);
$ayah = $svc->saveWali(['nama' => 'Ayah baru ' . $suffix, 'no_hp' => '', 'alamat' => '']);
$ibu = $svc->saveWali(['nama' => 'Ibu baru ' . $suffix, 'no_hp' => '', 'alamat' => '']);
$path = '/admin/admin_master_santri.php';
$h = $admin->req($path . '?action=edit&id=' . $sid)['body'];
$form = ['action' => 'save', 'id' => $sid, 'nis' => 'AUD6-' . $suffix, 'nama_santri' => 'Audit T6 Dua Orang Tua', 'jenis_kelamin' => 'P', 'tgl_lahir' => '2013-02-02',
    'wali' => ['Ayah' => ['mode' => 'pilih', 'wali_id' => $ayah], 'Ibu' => ['mode' => 'pilih', 'wali_id' => $ibu]]];
$send = static function (array $extra = []) use ($admin, $path, &$h, $form): array {
    $r = $admin->req($path, $form + ['_csrf' => $admin->token($h, '_csrf'), 'form_token' => $admin->token($h, 'form_token')] + $extra);
    $h = $r['location'] !== '' ? $admin->req($r['location'])['body'] : $r['body'];
    return $r;
};
$send();
$check($one("SELECT nama_ayah, nama_ibu FROM santri WHERE id=$sid") === ['nama_ayah' => 'Ayah lama', 'nama_ibu' => 'Ibu lama'], 'D-3 kedua konflik: permintaan awal tidak menimpa data');
$check(str_contains($h, 'dialog-timpa'), 'D-3 kedua konflik: dialog muncul');
for ($i = 0; $i < 2; $i++) {
    if (!str_contains($h, 'dialog-timpa')) { break; }
    preg_match('/<button[^>]*form="form-santri"[^>]*name="([^"]+)"[^>]*value="([^"]+)"/', $h, $m);
    if (isset($m[1]) && preg_match('/^konfirmasi_timpa\[(Ayah|Ibu)\]$/', $m[1], $which)) {
        $send(['konfirmasi_timpa' => [$which[1] => $m[2]]]);
    } elseif (($m[1] ?? '') === 'konfirmasi_timpa_semua') {
        $check(str_contains($h, 'Ayah lama') && str_contains($h, 'Ibu lama') && str_contains($h, 'Ayah baru') && str_contains($h, 'Ibu baru'), 'D-3 dialog gabungan menampilkan nilai lama dan calon kedua orang tua');
        $send(['konfirmasi_timpa_semua' => '1']);
    } else { break; }
}
$row = $one("SELECT nama_ayah, nama_ibu FROM santri WHERE id=$sid");
$check($row === ['nama_ayah' => 'Ayah baru ' . $suffix, 'nama_ibu' => 'Ibu baru ' . $suffix], 'D-3 kedua konflik: konfirmasi UI selesai tanpa berputar Ayah/Ibu');
$check((int) $one("SELECT COUNT(*) n FROM audit_logs WHERE entity_type='santri' AND entity_id=$sid AND action='master.legacy.mirror'")['n'] === 2, 'D-3 kedua perubahan meninggalkan tepat dua audit mirror');

// AB: pencabutan pada sesi lama, di semua tab dan daftar; keadaan dipulihkan
// setiap skenario agar kegagalan tidak mencemari skenario berikutnya.
$santri = (int) $one("SELECT id FROM santri WHERE nama_santri='SBX Santri A1'")['id'];
foreach ([['sbx_pengurus_a', 'pengurus', 'pembimbing_assignments', 'pengurus_id'], ['sbx_murobi_a', 'guru', 'murobi_assignments', 'guru_id']] as [$username, $master, $table, $column]) {
    $client = new T6AuditClient($base, $username);
    $u = $one("SELECT id, $column master_id FROM users WHERE username='$username'");
    $uid = (int) $u['id']; $mid = (int) $u['master_id'];
    $a = $one("SELECT id, tahun_ajaran_id FROM $table WHERE $column=$mid AND kamar_id=(SELECT id FROM kamar WHERE nama_kamar='SBX Kamar A')");
    $aid = (int) $a['id']; $tid = (int) $a['tahun_ajaran_id'];
    $check($client->req('/portal/santri_binaan.php?id=' . $santri)['status'] === 200, "AB $username baseline 200");
    $scenarios = [
        [$master, $mid, ['is_active' => 0], 'master nonaktif'],
        [$master, $mid, ['archived_at' => date('Y-m-d H:i:s')], 'master arsip'],
        [$table, $aid, ['archived_at' => date('Y-m-d H:i:s')], 'penugasan arsip'],
        [$table, $aid, ['tanggal_mulai' => date('Y-m-d', time() + 86400)], 'penugasan belum mulai'],
        ['tahun_ajaran', $tid, ['status' => 'Non-Aktif'], 'semester nonaktif'],
        ['santri', $santri, ['archived_at' => date('Y-m-d H:i:s')], 'santri arsip'],
    ];
    foreach ($scenarios as [$t, $id, $changes, $label]) {
        $before = $one("SELECT * FROM $t WHERE id=$id");
        $update = static function (array $values) use ($db, $t, $id): void {
            $set = []; foreach ($values as $k => $v) { $set[] = "$k=" . ($v === null ? 'NULL' : "'" . $db->real_escape_string((string) $v) . "'"); }
            $db->query("UPDATE $t SET " . implode(',', $set) . " WHERE id=$id");
        };
        try {
            $update($changes); $ok = true;
            foreach (['ringkasan', 'izin', 'sikap', 'pembinaan'] as $tab) {
                $r = $client->req('/portal/santri_binaan.php?id=' . $santri . '&tab=' . $tab);
                $ok = $ok && $r['status'] === 403 && !str_contains($r['body'], 'SBX Santri A1');
            }
            $list = $client->req('/portal/santri_binaan.php');
            $check($ok && !str_contains($list['body'], 'SBX Santri A1'), "AB $username $label: empat tab 403 dan hilang dari daftar pada sesi lama");
        } finally { $update(array_intersect_key($before, $changes)); }
    }
    $check($client->req('/portal/santri_binaan.php?id=' . $santri)['status'] === 200, "AB $username dipulihkan 200");
}

// AE: tujuan berubah setelah tinjauan; apply wajib membaca ulang dan menolak.
$room = $svc->saveRoom(['nama_kamar' => 'Audit T6 ' . $suffix, 'kapasitas' => 5, 'asrama' => 'Putri']);
$options = ['kamar_id' => $room];
$preview = penempatan_service()->preview('tempatkan_kamar', [$sid], $options);
$check($preview['peringatan'] === [], 'AE tinjauan awal sesuai asrama');
$db->query("UPDATE kamar SET asrama='Putra' WHERE id=$room");
$rejected = false;
try { penempatan_service()->apply('tempatkan_kamar', [$sid], $options, $actor); }
catch (App\MasterData\MasterDataException $e) { $rejected = str_contains($e->getMessage(), 'tidak sesuai asrama'); }
$check($rejected && (int) $one("SELECT COUNT(*) n FROM plotting_kamar WHERE id_santri=$sid")['n'] === 0, 'AE perubahan asrama setelah tinjau ditolak tanpa konfirmasi dan tanpa penempatan');
penempatan_service()->apply('tempatkan_kamar', [$sid], $options + ['konfirmasi_asrama' => '1', 'alasan_asrama' => 'Pengecualian sintetis auditor'], $actor);
$check((int) $one("SELECT COUNT(*) n FROM plotting_kamar WHERE id_santri=$sid AND id_kamar=$room")['n'] === 1, 'AE perubahan asrama boleh setelah konfirmasi beralasan');

// AC: read-only sampai tabel wali/relasi/audit, bukan sekadar jumlah santri.
$counts = static fn (): array => array_map(static fn ($t): int => (int) $one("SELECT COUNT(*) n FROM $t")['n'], ['santri', 'wali', 'santri_wali', 'audit_logs']);
$before = $counts();
$result = $svc->periksaImpor([['AUD6-I-' . $suffix, 'Audit Impor', 'P', '', '2013-02-02', '', '', '', '', '', 'Ayah', '', 'Ibu', '']]);
$check($result['siap'] === 1 && $counts() === $before, 'AC periksa tidak menulis santri, wali, relasi maupun audit');

// D-9: setiap dialog tindakan beralasan, termasuk memasangkan data warisan.
$db->query("INSERT INTO alumni (nis, nama_santri, jenis_kelamin, tempat_lahir, tgl_lahir, alamat, desa, kecamatan, kab_kota, provinsi, nama_ayah, nama_ibu, asal_sekolah, unit_terakhir, tahun_angkatan, tingkat, status_keluar, tgl_keluar, foto) VALUES ('AUD6-L-$suffix', 'Audit Alumni', 'P', '', '2013-02-02', '', '', '', '', '', '', '', '', '', '2026', 'Ibtida', 'Lulus', '2026-06-30', 'default.jpg')");
$alid = (int) $db->insert_id;
$alpath = '/admin/admin_alumni.php?action=detail&id=' . $alid . '&aksi=hubungkan';
$h = $admin->req($alpath)['body'];
$check(preg_match('/id="aksi-hubungkan"(?:(?!<\/form>).)*name="alasan"/s', $h) === 1, 'D-9 dialog Hubungkan menyediakan alasan');
$post = ['action' => 'hubungkan', 'id' => $alid, 'santri_id' => $sid, '_csrf' => $admin->token($h, '_csrf')];
$r = $admin->req($alpath, $post);
$check($r['status'] === 422 && $one("SELECT santri_id FROM alumni WHERE id=$alid")['santri_id'] === null, 'D-9 hubungkan tanpa alasan ditolak server tanpa mutasi');
// Isolasi percobaan sebelum koreksi yang terlanjur tersimpan pada fixture ini.
$db->query("UPDATE alumni SET santri_id=NULL WHERE id=$alid");
$r = $admin->req($alpath, $post + ['alasan' => 'Identitas cocok berdasarkan arsip sintetis']);
$check($r['status'] === 302 && (int) $one("SELECT santri_id FROM alumni WHERE id=$alid")['santri_id'] === $sid, 'D-9 hubungkan dengan alasan berhasil');
$audit = $one("SELECT after_json FROM audit_logs WHERE action='alumni.hubungkan' AND entity_id=$alid ORDER BY id DESC LIMIT 1");
$check(str_contains($audit['after_json'] ?? '', 'Identitas cocok berdasarkan arsip sintetis'), 'D-9 alasan hubungkan tersimpan di audit');
echo "Ringkasan: $pass lulus, $fail gagal\n";
exit($fail > 0 ? 1 : 0);
