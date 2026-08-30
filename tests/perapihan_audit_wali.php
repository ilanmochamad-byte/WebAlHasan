<?php
declare(strict_types=1);

if (getenv('PERAPIHAN_AUDIT_DB') !== '1') { exit(77); }
require_once dirname(__DIR__) . '/app/bootstrap.php';
if (!str_ends_with((string) app_config('database.database'), '_test')) { exit(2); }
$db = app_db();
$master = master_data_service();
$_SESSION['user_id'] = (int) $db->query("SELECT id FROM users WHERE username='sbx_admin'")->fetch_assoc()['id'];
$suffix = bin2hex(random_bytes(4));
$santri = []; $wali = []; $fail = 0;
$check = static function (bool $ok, string $label) use (&$fail): void {
    echo ($ok ? '[lulus] ' : '[gagal] ') . $label . PHP_EOL; $fail += !$ok;
};
try {
    foreach ([['Ayah Sama', ''], ['Ayah Sama', '081200000099'], ['Ayah  Sama', '081200000001'], ['', '']] as $i => [$oldName, $newPhone]) {
        $data = ['nis'=>'AW'.$suffix.$i, 'nama_santri'=>'Audit Wali '.$suffix.$i, 'jenis_kelamin'=>'L', 'tgl_lahir'=>'2010-01-01'];
        $id = $master->saveSantri($data + ['nama_ayah'=>$oldName, 'no_hp_ayah'=>'081200000001']); $santri[]=$id;
        $wid = $master->saveWali(['nama'=>'Ayah Sama', 'no_hp'=>$newPhone]); $wali[]=$wid;
        // Simulasikan spasi ganda data warisan yang tidak dinormalisasi importer.
        $db->execute_query('UPDATE santri SET nama_ayah=? WHERE id=?', [$oldName, $id]);
        $request = $data + ['wali'=>['Ayah'=>['mode'=>'pilih','wali_id'=>$wid]]];
        $blocked = false;
        try { $master->saveSantri($request, $id); } catch (App\MasterData\MasterDataException $e) { $blocked = str_contains($e->getMessage(), 'konfirmasi'); }
        $row = $master->santri($id);
        $check($blocked && $row['nama_ayah']===$oldName && $row['no_hp_ayah']==='081200000001', "A-02 kasus $i konflik nama/HP ditolak dan data lama utuh");
        $check($master->santriWali($id)===[], "A-02 kasus $i relasi ikut rollback");
        $master->saveSantri($request + ['konfirmasi_timpa'=>['Ayah'=>'1']], $id);
        $row = $master->santri($id);
        $check($row['nama_ayah']==='Ayah Sama' && (string)$row['no_hp_ayah']===$newPhone, "A-02 kasus $i konfirmasi menyalin nama dan HP");
        $audit = $db->query("SELECT before_json, after_json FROM audit_logs WHERE action='master.legacy.mirror' AND entity_id=$id ORDER BY id DESC LIMIT 1")->fetch_assoc();
        $before = json_decode($audit['before_json'] ?? '{}',true); $after = json_decode($audit['after_json'] ?? '{}',true);
        $check(($before['no_hp_ayah']??null)==='081200000001' && ($before['nama_ayah']??null)===$oldName && ($after['dikonfirmasi_admin']??false)===true, "A-02 kasus $i audit memuat nilai asli dan konfirmasi");
    }
} finally {
    // Hanya baris sintetis yang dibuat tes ini. Tidak menyentuh fixture lain.
    foreach ($santri as $id) { $db->query("DELETE FROM santri_wali WHERE santri_id=$id"); $db->query("DELETE FROM audit_logs WHERE entity_type='santri' AND entity_id=$id"); $db->query("DELETE FROM santri WHERE id=$id"); }
    foreach ($wali as $id) { $db->query("DELETE FROM wali WHERE id=$id"); }
}
exit($fail ? 1 : 0);
