<?php
declare(strict_types=1);
if (!preg_match('/^test_.*_test$/D', getenv('DB_NAME') ?: '')) exit(2);
require dirname(__DIR__, 3) . '/app/bootstrap.php';
use App\Bsa\BsaException;
use App\Bsa\BsaNotifikasi;
$db=app_db();$ok=0;$bad=0;
$scalar=static fn(string $s):mixed=>$db->query($s)->fetch_row()[0];
$check=static function(bool $b,string $s)use(&$ok,&$bad):void{echo ($b?'[lulus] ':'[gagal] ').$s.PHP_EOL;$b?$ok++:$bad++;};
$user=['id'=>(int)$scalar("SELECT id FROM users WHERE username='sbx_v6_bsa_b'")];
$wali=(int)$scalar("SELECT id FROM users WHERE username='sbx_v6_wali_dua'");
$s=(int)$scalar("SELECT id FROM santri WHERE nis='V6S0001'");
$counts=static function()use($scalar):array{$a=[];foreach(['bsa_tagihan','bsa_pembayaran','bsa_tabungan_akun','bsa_tabungan_transaksi','bsa_pembayaran_kuitansi','bsa_idempotensi','audit_logs','notifikasi_outbox']as$t)$a[$t]=(int)$scalar("SELECT COUNT(*) FROM $t");return $a;};
$key='audit-outbox-'.bin2hex(random_bytes(8));
$payload=['idempotency_key'=>$key,'nominal'=>100000,'metode'=>'Tunai'];
// Akun saldo nol disiapkan di luar transaksi sejak Fase 1.
// Siapkan dahulu agar probe mengisolasi rollback uang dan pemberitahuan.
(new \App\Bsa\BsaRepository($db))->pastikanAkun($s);
$before=$counts();
// Sengaja gagal pada penerima kedua: uang, audit, idempotensi, dan penerima
// pertama yang sudah terantre harus semuanya ikut rollback.
$db->query("CREATE TRIGGER audit_v6_outbox BEFORE INSERT ON notifikasi_outbox FOR EACH ROW BEGIN IF NEW.event_type LIKE 'v6%' AND NEW.penerima_user_id=$wali THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='audit_outbox_failure'; END IF; END");
try {tabungan_service()->setoran($user,$s,$payload,'api');$status=201;}catch(BsaException $e){$status=$e->status;}catch(Throwable){$status=500;}finally{$db->query('DROP TRIGGER IF EXISTS audit_v6_outbox');}
$check($status===503,'Kegagalan outbox → 503 yang dapat dicoba ulang');
$check($before===$counts(),'Rollback penuh: uang, akun saldo, audit, klaim, dan seluruh penerima outbox tetap');
$r=tabungan_service()->setoran($user,$s,$payload,'api');
$check(!$r['replayed'] && bsa_service()->saldo($user,$s)['saldo']===100000,'Retry kunci sama sesudah kegagalan → satu kredit Rp100.000');
$after=$counts();$r2=tabungan_service()->setoran($user,$s,$payload,'api');
$check($r2['replayed'] && $r2['id']===$r['id'] && $after===$counts(),'Replay tidak menggandakan kredit, audit, atau notifikasi');
$event=BsaNotifikasi::EVENT_TABUNGAN;$id=(int)$r['id'];
$row=$db->query("SELECT * FROM notifikasi_outbox WHERE event_type='$event' AND penerima_user_id=$wali ORDER BY id DESC LIMIT 1")->fetch_assoc();
$check(BsaNotifikasi::pushMasihBerhak($db,$row)===BsaNotifikasi::data($event,$id),'Worker mengembalikan tepat penunjuk yang sah');
$row['data_json']=json_encode(['tipe'=>'bsa_tabungan','transaksi_id'=>$id,'referensi'=>'RAHASIA_AUDIT']);
$check(BsaNotifikasi::pushMasihBerhak($db,$row)===BsaNotifikasi::data($event,$id),'Kunci tambahan tak dipercaya tidak masuk payload provider');
$row['event_key']='v6:bsa:tabungan:999999';
$check(BsaNotifikasi::pushMasihBerhak($db,$row)===null,'Penunjuk dan event_key berbeda → ditolak');
$row['event_key']=BsaNotifikasi::eventKey($event,$id);
$db->query("UPDATE wali w JOIN users u ON u.wali_id=w.id SET w.is_active=0 WHERE u.id=$wali");
$check(BsaNotifikasi::pushMasihBerhak($db,$row)===null,'Master wali dicabut, relasi masih ada → worker menolak');
$db->query("UPDATE wali w JOIN users u ON u.wali_id=w.id SET w.is_active=1 WHERE u.id=$wali");
$db->query("UPDATE santri SET is_active=0 WHERE id=$s");
$check(BsaNotifikasi::pushMasihBerhak($db,$row)===null,'Santri dinonaktifkan → worker menolak');
$db->query("UPDATE santri SET is_active=1 WHERE id=$s");
echo "Ringkasan: $ok lulus, $bad gagal\n";exit($bad?1:0);
