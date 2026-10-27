<?php

declare(strict_types=1);

// Regresi penerimaan yang sengaja tetap merah sampai keputusan cakupan F5-1.
// DB fixture terpisah; perubahan penugasan selalu dipulihkan. Tidak ada HTTP/provider.
if (getenv('V7_RUN_AUDIT') !== '1' || !preg_match('/^test_[A-Za-z0-9_]*_test$/D', (string) getenv('DB_NAME'))) exit(2);
require_once dirname(__DIR__) . '/app/bootstrap.php';
use App\Izin\IzinException;
$db = app_db();
$n = static fn (string $sql): int => (int) $db->query($sql)->fetch_row()[0];
$user = static function (string $username) use ($db): array {
    $r = $db->query("SELECT id,guru_id FROM users WHERE username='$username'")->fetch_assoc();
    $roles = array_column($db->query('SELECT r.slug FROM user_roles ur JOIN roles r ON r.id=ur.role_id WHERE ur.user_id=' . (int) $r['id'])->fetch_all(MYSQLI_ASSOC), 'slug');
    return ['id' => (int) $r['id'], 'guru_id' => $r['guru_id'] === null ? null : (int) $r['guru_id'], 'roles' => $roles];
};
$code = static function (callable $call): int {
    try { $call(); return 200; } catch (IzinException $e) { return $e->status(); }
};
$pass=$fail=0;
$assert=static function(bool $ok,string $label) use (&$pass,&$fail): void {
    echo ($ok?'[lulus] ':'[gagal] ').$label.PHP_EOL; $ok?$pass++:$fail++;
};
$wali=$user('sbx_v7_wali_a'); $pb=$user('sbx_v7_pembimbing_satu'); $m=$user('sbx_murobi_a');
$sid=$n("SELECT id FROM santri WHERE nis='V7S0008'");
$key=static fn(): string=>'codex-f5-scope-'.bin2hex(random_bytes(8));
$day=(new DateTimeImmutable('2058-01-01'))->modify('+'.random_int(1,2000).' days')->format('Y-m-d');
$id=(int)izin_wali_service()->buat($wali,['santri_id'=>$sid,'tgl_izin'=>$day,'tgl_kembali'=>$day,'alasan'=>'Fixture cakupan auditor'], $key(),[])['id'];
izin_wali_service()->tinjau($pb,$id,'teruskan',null,1,$key(),[]);
$assignment=$n("SELECT ma.id FROM murobi_assignments ma JOIN plotting_kamar pk ON pk.id_kamar=ma.kamar_id AND pk.id_tahun=ma.tahun_ajaran_id
    WHERE pk.id_santri=$sid AND ma.guru_id={$m['guru_id']} AND ma.is_active=1 LIMIT 1");
$assert($assignment>0 && $n("SELECT murobi_guru_id FROM izin_pengajuan WHERE id=$id")===$m['guru_id'], 'Kontrol: routing otomatis kepada murobi yang mencakup santri');
$assert($code(fn()=>izin_wali_service()->detail($m,$id))===200, 'Kontrol: detail terbuka sebelum pencabutan');
try {
    $db->query("UPDATE murobi_assignments SET is_active=0 WHERE id=$assignment");
    capabilities()->forget($m['id']);
    $candidates=(new App\Izin\IzinRouter($db))->candidates($sid,date('Y-m-d'));
    $assert(!in_array($m['guru_id'], array_map('intval',array_column($candidates,'guru_id')),true)
        && capabilities()->has($m,'murobi'), 'Kontrol: cakupan santri benar-benar hilang, capability murobi masih sah dari penugasan lain');
    $v7=$code(fn()=>izin_wali_service()->detail($m,$id));
    $v2=$code(fn()=>izin_service()->detail($m,$id,'murobi'));
    $assert(in_array($v7,[403,404],true) && in_array($v2,[403,404],true), "Sesudah pencabutan cakupan: detail V7/V2 harus tertutup (aktual $v7/$v2)");
    $report=izin_laporan_service()->laporan($m,['asal'=>'wali','santri_id'=>(string)$sid,'dari'=>$day,'sampai'=>$day]);
    $assert($report['total']===0, 'Sesudah pencabutan cakupan: laporan tidak boleh memuat pengajuan (aktual '.$report['total'].')');
    $result=$code(fn()=>izin_workflow_service()->decide($m,$id,'Disetujui','Fixture cakupan auditor',null,2,$key(),[],'murobi'));
    $count=$n("SELECT COUNT(*) FROM izin_keputusan WHERE pengajuan_id=$id");
    $assert(in_array($result,[403,404],true) && $count===0, "Sesudah pencabutan cakupan: keputusan ditolak tanpa mutasi (aktual HTTP $result, $count keputusan)");
} finally {
    $db->query("UPDATE murobi_assignments SET is_active=1 WHERE id=$assignment");
    capabilities()->forget($m['id']);
}
echo "Hasil: $pass lulus, $fail gagal\n";
exit($fail===0?0:1);
