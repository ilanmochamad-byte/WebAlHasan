<?php

declare(strict_types=1);
require_once dirname(__DIR__).'/app/bootstrap.php';
if(getenv('V3_RUN_TESTS')!=='1' || app_config('database.database')!=='webalhasan_v3_phase1_test') {echo "BELUM DIJALANKAN: database khusus webalhasan_v3_phase1_test dan V3_RUN_TESTS=1 diperlukan.\n";exit(77);}
$r=new \App\V3\KatalogRepository(app_db());$s=v3_katalog_service();$fails=0;
$assert=static function(bool $v,string $label)use(&$fails){echo ($v?'[lulus] ':'[gagal] ').$label.PHP_EOL;if(!$v)$fails++;};
$reject=static function(callable $f,string $label,int $status=422)use($assert){try{$f();$assert(false,$label);}catch(\App\V3\V3Exception $e){$assert($e->status===$status,$label);}};
$admin=$r->rows("SELECT id FROM users WHERE username='sbx_admin'")[0];
$user=$r->rows("SELECT id FROM users WHERE username='sbx_pengurus_a'")[0];
$year=(int)$r->rows("SELECT id FROM tahun_ajaran WHERE status='Aktif' LIMIT 1")[0]['id'];
$tag='V3'.bin2hex(random_bytes(5));$period=['tanggal_mulai'=>date('Y-m-d'),'tanggal_selesai'=>'','is_active'=>'1'];
$cat=$period+['kode'=>$tag,'nama'=>'Kategori fiktif','uraian'=>'<script>alert(1)</script>'];
$id=$s->save('kategori',$cat,$admin);$assert($s->find('kategori',$id,$admin)['nama']==='Kategori fiktif','Kategori dibuat dan dibaca kembali');
$kind=$period+['kode'=>$tag,'nama'=>'Jenis fiktif','kategori_id'=>$id,'tingkat'=>'Ringan','poin_default'=>'5','uraian'=>'Contoh'];
$kid=$s->save('katalog',$kind,$admin);$assert((int)$s->find('katalog',$kid,$admin)['poin_default']===5,'Katalog dibuat dan dibaca kembali');
$threshold=$period+['tahun_ajaran_id'=>$year,'nilai_minimum'=>0,'nilai_maksimum'=>10,'label'=>'Rekomendasi fiktif','rekomendasi'=>'Pertimbangkan pendampingan'];
// Keep the test repeatable without deleting business records.
foreach($r->rows('SELECT * FROM v3_ambang WHERE is_active=1') as $old) { $s->save('ambang',array_replace($old,['is_active'=>0,'alasan'=>'Isolasi fixture']),$admin,(int)$old['id']); }
$aid=$s->save('ambang',$threshold,$admin);$assert($s->find('ambang',$aid,$admin)['label']==='Rekomendasi fiktif','Ambang dibuat dan dibaca kembali');
$before=(int)$r->rows('SELECT COUNT(*) n FROM v3_katalog')[0]['n'];
$reject(fn()=>$s->save('katalog',$kind,$admin),'Kode duplikat ditolak',409);
$assert((int)$r->rows('SELECT COUNT(*) n FROM v3_katalog')[0]['n']===$before,'Duplikat tidak menambah baris');
$reject(fn()=>$s->save('ambang',array_replace($threshold,['nilai_minimum'=>10,'nilai_maksimum'=>20]),$admin),'Batas bersentuhan bertumpang tindih ditolak',409);
foreach([['poin_default'=>-1],['tingkat'=>'Kritis'],['tanggal_selesai'=>'2000-01-01'],['tanggal_mulai'=>'2026-02-30'],['poin_default'=>[]],['kategori_id'=>99999999]] as $bad) { $reject(fn()=>$s->save('katalog',array_replace($kind,['kode'=>$tag.'X'],$bad),$admin),'Input katalog tidak valid',isset($bad['kategori_id'])?404:422); }
$reject(fn()=>$s->save('kategori',$cat,$user),'Pengurus tidak dapat mengelola katalog',403);
$reject(fn()=>$s->save('kategori',$cat,['id'=>$user['id'],'roles'=>['admin']]),'Role klien tidak dipercaya',403);
$reject(fn()=>$s->find('kategori',$id,$user),'IDOR baca admin ditolak',403);
$reject(fn()=>$s->save('ambang',array_replace($threshold,['nilai_minimum'=>-1]),$admin),'Ambang negatif ditolak');
$reject(fn()=>$s->save('ambang',array_replace($threshold,['nilai_maksimum'=>-1]),$admin),'Maksimum negatif ditolak');
$reject(fn()=>$s->save('ambang',array_replace($threshold,['nilai_minimum'=>15,'nilai_maksimum'=>10]),$admin),'Rentang terbalik ditolak');
$reject(fn()=>$s->page('katalog',['kategori_id'=>[]],$admin),'Manipulasi filter array ditolak');
$row=$s->find('katalog',$kid,$admin);
$auditBefore=count($s->history('katalog',$kid,$admin));
$s->save('katalog',array_replace($row,['poin_default'=>7,'alasan'=>'Perubahan fixture']),$admin,$kid);
$reject(fn()=>$s->save('katalog',array_replace($row,['alasan'=>'Versi lama']),$admin,$kid),'Optimistic version menolak tab lama',409);
$assert(count($s->history('katalog',$kid,$admin))===$auditBefore+1,'Satu audit per mutasi');
$assert(str_contains(ah_e($cat['uraian']),'&lt;script&gt;'),'Escape XSS pada keluaran');
// A failing audit insert must undo the preceding business INSERT.
$r->execute("CREATE TRIGGER v3_test_audit_failure BEFORE INSERT ON audit_logs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic audit failure'");
try { $reject(fn()=>$s->save('kategori',array_replace($cat,['kode'=>$tag.'F']),$admin),'Gagal audit membatalkan transaksi',503); }
finally {$r->execute('DROP TRIGGER v3_test_audit_failure');}
$assert($r->rows('SELECT id FROM v3_kategori WHERE kode=?',[$tag.'F'])===[],'Tidak ada baris setelah gagal audit');
$c=new \App\Auth\Capabilities(app_db());
foreach(['sbx_pengurus_a'=>'v3.pelanggaran.kelola','sbx_murobi_a'=>'v3.murobi.mengetahui','sbx_ortu_a'=>'v3.publikasi.baca'] as $name=>$key) {
    $u=$r->rows('SELECT id FROM users WHERE username=?',[$name])[0];$assert(isset($c->v3Capabilities($u)[$key]),'Capability aktif '.$key);
    app_db()->begin_transaction();
    try {
        if($name==='sbx_pengurus_a')$r->execute('UPDATE pembimbing_assignments SET tanggal_selesai=DATE_SUB(CURDATE(),INTERVAL 1 DAY),tanggal_mulai=DATE_SUB(CURDATE(),INTERVAL 2 DAY) WHERE pengurus_id=(SELECT pengurus_id FROM users WHERE id=?)',[$u['id']]);
        elseif($name==='sbx_murobi_a')$r->execute('UPDATE murobi_assignments SET tanggal_selesai=DATE_SUB(CURDATE(),INTERVAL 1 DAY),tanggal_mulai=DATE_SUB(CURDATE(),INTERVAL 2 DAY) WHERE guru_id=(SELECT guru_id FROM users WHERE id=?)',[$u['id']]);
        else $r->execute('UPDATE santri_wali SET archived_at=NOW() WHERE wali_id=(SELECT wali_id FROM users WHERE id=?)',[$u['id']]);
        $assert(!isset($c->v3Capabilities($u)[$key]),'Capability dicabut saat relasi/penugasan berakhir '.$key);
    } finally {app_db()->rollback();}
}
$childA=(int)$r->rows("SELECT sw.santri_id FROM santri_wali sw JOIN users u ON u.wali_id=sw.wali_id WHERE u.username='sbx_ortu_a' AND sw.archived_at IS NULL LIMIT 1")[0]['santri_id'];
$childB=(int)$r->rows("SELECT sw.santri_id FROM santri_wali sw JOIN users u ON u.wali_id=sw.wali_id WHERE u.username='sbx_ortu_b' AND sw.archived_at IS NULL LIMIT 1")[0]['santri_id'];
foreach(['sbx_pengurus_a'=>'v3.pelanggaran.kelola','sbx_murobi_a'=>'v3.murobi.mengetahui','sbx_ortu_a'=>'v3.publikasi.baca'] as $name=>$key) {
    $u=$r->rows('SELECT id FROM users WHERE username=?',[$name])[0];
    $assert($c->v3AppliesToSantri($u,$key,$childA,$year),'Cakupan anak sendiri '.$key);
    $assert(!$c->v3AppliesToSantri($u,$key,$childB,$year),'Cakupan anak lain ditolak '.$key);
}
$assert(array_column($r->rows('SELECT slug FROM roles ORDER BY slug'),'slug')===['admin','guru','orang_tua','pengurus'],'Empat role dasar tetap');
$assert($s->active('katalog',[],$user)['total']>0,'API baca katalog untuk pembimbing aktif');
$ortu=$r->rows("SELECT id FROM users WHERE username='sbx_ortu_a'")[0];$reject(fn()=>$s->active('katalog',[],$ortu),'Orang tua tidak membaca katalog internal',403);
$assert($r->rows("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='pelanggaran'")!==[],'Tabel lama tetap ada');
// Constraint checks really execute invalid SQL; rollback protects fixtures.
foreach([['UPDATE v3_katalog SET poin_default=-1 WHERE id=?',$kid],['UPDATE v3_katalog SET kategori_id=999999999 WHERE id=?',$kid],['UPDATE v3_ambang SET nilai_maksimum=-1 WHERE id=?',$aid]] as [$sql,$key]) {
    app_db()->begin_transaction();try { $r->execute($sql,[$key]);$assert(false,'Constraint database menolak data rusak'); } catch(\App\V3\V3Exception $e){$assert(true,'Constraint database menolak data rusak');} finally {app_db()->rollback();}
}
// Tahun aktif wajib menjadi pilihan pertama: ambang di tahun non-aktif tersimpan
// tanpa galat tetapi belum terbaca pembimbing, sehingga default yang salah
// menjadi jebakan konfigurasi yang sunyi.
$tahun=$s->options($admin)['tahun'];
$assert($tahun!==[]&&($tahun[0]['status']??'')==='Aktif','Tahun ajaran aktif menjadi pilihan pertama');
$assert(array_key_exists('status',$tahun[0]),'Status tahun ajaran ikut dikirim ke formulir');
$nonAktif=array_values(array_filter($tahun,static fn($y)=>($y['status']??'')!=='Aktif'));
$assert($nonAktif===[]||(int)$nonAktif[0]['id']!==(int)$tahun[0]['id'],'Tahun non-aktif tidak menempati posisi default');
$page=file_get_contents(APP_ROOT.'/admin/admin_v3_katalog.php');
$assert(str_contains($page,'non-aktif'),'Formulir menandai tahun non-aktif');
$assert(str_contains($page,'$defaults[$name]'),'Formulir memakai default per-field, bukan opsi pertama browser');
for($i=0;$i<26;$i++) { $s->save('katalog',array_replace($kind,['kode'=>$tag.'P'.$i]),$admin); }
$p1=$s->active('katalog',['kategori_id'=>$id,'page'=>1],$user);
$p2=$s->active('katalog',['kategori_id'=>$id,'page'=>2],$user);
$assert(count($p1['rows'])===25&&count($p2['rows'])===2&&$p1['total']===27,'Pagination katalog 25 + 2 baris');
$assert(array_intersect(array_column($p1['rows'],'id'),array_column($p2['rows'],'id'))===[],'Pagination tidak mengulang baris antarhalaman');
exit($fails?1:0);
