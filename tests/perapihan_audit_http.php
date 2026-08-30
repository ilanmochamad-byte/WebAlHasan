<?php
declare(strict_types=1);
if (getenv('PERAPIHAN_AUDIT_DB') !== '1') { exit(77); }
require_once dirname(__DIR__) . '/app/bootstrap.php';
if (!str_ends_with((string) app_config('database.database'), '_test')) { exit(2); }
$base=getenv('BASE_URL')?:'http://127.0.0.1:8940';
if (!preg_match('#^http://127\.0\.0\.1:\d+$#',$base)) { exit(2); }
$fail=0; $jars=[];
$check=static function(bool $ok,string $text)use(&$fail):void {echo ($ok?'[lulus] ':'[gagal] ').$text.PHP_EOL; $fail+=!$ok;};
$request=static function(string $path,?array $post=null,?string $jar=null)use($base):array {
    $c=curl_init($base.$path);
    curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,CURLOPT_TIMEOUT=>15]);
    if($jar) curl_setopt_array($c,[CURLOPT_COOKIEFILE=>$jar,CURLOPT_COOKIEJAR=>$jar]);
    if($post!==null) curl_setopt_array($c,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($post)]);
    $raw=(string)curl_exec($c); $len=curl_getinfo($c,CURLINFO_HEADER_SIZE); $status=curl_getinfo($c,CURLINFO_RESPONSE_CODE);
    $headers=substr($raw,0,$len); preg_match('/^Location: (.*)$/mi',$headers,$m);
    return ['status'=>$status,'body'=>substr($raw,$len),'location'=>trim($m[1]??''),'headers'=>$headers];
};
$field=static function(string $html,string $name):string {
    $doc=new DOMDocument(); @$doc->loadHTML($html);
    return (new DOMXPath($doc))->evaluate('string(//input[@name="'.$name.'"]/@value)');
};
try {
    foreach(['sbx_admin','sbx_guru_biasa','sbx_murobi_a','sbx_pengurus_a','sbx_ortu_a'] as $user) {
        $jar=tempnam(sys_get_temp_dir(),'audit-http-'); $jars[$user]=$jar;
        $login=$request('/portal/');
        $login=$request('/portal/',null,$jar);
        $r=$request('/admin/cek_login.php',['_csrf'=>$field($login['body'],'_csrf'),'username'=>$user,'password'=>'Sandbox#123'],$jar);
        $check($r['status']===302,'HTTP login '.$user);
        if($user!=='sbx_admin') $check($request('/admin/get_wali_json.php?q=SBX',null,$jar)['status']===403,'HTTP JSON wali ditolak '.$user);
    }
    $admin=$jars['sbx_admin']; $guru=$jars['sbx_guru_biasa'];
    foreach(['_pengajian_jadwal.php','_pengajian_pertemuan.php','_santri_wali_field.php'] as $partial) {
        $check($request('/admin/'.$partial)['status']===404 && $request('/admin/'.$partial,null,$admin)['status']===404,'HTTP partial '.$partial.' 404 anonim/admin');
    }
    foreach(['admin_jadwal_ngaji.php','pertemuan_pengajian.php','admin_akun_perizinan.php'] as $old) {
        $r=$request('/admin/'.$old,['action'=>'save','_csrf'=>'invalid'],$admin);
        $check($r['status']===419 && $r['location']==='','HTTP POST lama '.$old.' ditolak CSRF tanpa redirect (419 sesuai kontrak Csrf)');
    }
    $csrf=$field($request('/admin/logout.php',null,$guru)['body'],'_csrf');
    $db=app_db(); $before=(int)$db->query('SELECT COUNT(*) c FROM jadwal_ngaji')->fetch_assoc()['c'];
    foreach(['/admin/admin_pengajian.php?tab=jadwal','/admin/admin_jadwal_ngaji.php'] as $path) {
        $r=$request($path,['_csrf'=>$csrf,'tab'=>'jadwal','action'=>'save','id_guru'=>'1'],$guru);
        $check($r['status']===403 && (int)$db->query('SELECT COUNT(*) c FROM jadwal_ngaji')->fetch_assoc()['c']===$before,'HTTP guru tidak dapat POST jadwal '.$path);
    }
    $form=$request('/admin/admin_master_santri.php?action=create',null,$admin);
    $post=['_csrf'=>$field($form['body'],'_csrf'),'form_token'=>$field($form['body'],'form_token'),'action'=>'save','nis'=>'A04TEST','nama_santri'=>'Audit Form','jenis_kelamin'=>'invalid','tgl_lahir'=>'2010-01-01','wali'=>['Ayah'=>['mode'=>'baru','nama'=>'Ayah Audit Form','no_hp'=>'081200009999','alamat'=>'Alamat audit']]];
    $r=$request('/admin/admin_master_santri.php?action=create',$post,$admin);
    $after=$request($r['location'],null,$admin);
    $doc=new DOMDocument(); @$doc->loadHTML($after['body']); $xp=new DOMXPath($doc);
    $check($field($after['body'],'nama_santri')==='Audit Form','A-04 identitas santri dipertahankan saat validasi gagal');
    $check($field($after['body'],'wali[Ayah][nama]')==='Ayah Audit Form' && $field($after['body'],'wali[Ayah][no_hp]')==='081200009999' && $field($after['body'],'wali[Ayah][alamat]')==='Alamat audit','A-04 nama/HP/alamat wali dipertahankan saat validasi gagal');
    $check($xp->evaluate('string(//input[@name="wali[Ayah][mode]" and @checked]/@value)')==='baru','A-04 mode wali dipertahankan saat validasi gagal');

    // A-02: formulir palsu tidak membuka kembali penyuntingan kolom lama.
    $form=$request('/admin/admin_master_santri.php?action=create',null,$admin);
    $before=(int)$db->query('SELECT COUNT(*) c FROM santri')->fetch_assoc()['c'];
    $post=['_csrf'=>$field($form['body'],'_csrf'),'form_token'=>$field($form['body'],'form_token'),'action'=>'save','nis'=>'AUDLEGACY','nama_santri'=>'Audit Legacy','jenis_kelamin'=>'L','tgl_lahir'=>'2010-01-01','nama_ayah'=>'Tidak melalui wali'];
    $r=$request('/admin/admin_master_santri.php?action=create',$post,$admin);
    $after=$request($r['location'],null,$admin);
    $check(str_contains($after['body'],'Kolom lama hanya dapat diperbarui') && (int)$db->query('SELECT COUNT(*) c FROM santri')->fetch_assoc()['c']===$before,'A-02 POST kolom ayah palsu ditolak tanpa santri tercipta');

    // Pengiriman ganda nyata: dua POST identik, bukan sekadar mencari token di kode.
    foreach(['santri','wali'] as $entity) {
        $path=$entity==='santri'?'/admin/admin_master_santri.php':'/admin/admin_wali.php';
        $form=$request($path.'?action=create',null,$admin);
        $tag='AUD'.bin2hex(random_bytes(5));
        $data=$entity==='santri'?['nis'=>$tag,'nama_santri'=>$tag,'jenis_kelamin'=>'L','tgl_lahir'=>'2010-01-01']:['nama'=>$tag];
        $post=$data+['_csrf'=>$field($form['body'],'_csrf'),'form_token'=>$field($form['body'],'form_token'),'action'=>'save'];
        $first=$request($path,$post,$admin); $second=$request($path,$post,$admin);
        $column=$entity==='santri'?'nis':'nama';
        $count=(int)$db->query("SELECT COUNT(*) c FROM $entity WHERE $column='$tag'")->fetch_assoc()['c'];
        $check($first['status']===302 && $second['status']===302 && $count===1,'HTTP POST ulang '.$entity.' menghasilkan tepat satu identitas sintetis');
    }
    $manifest=getenv('AUDIT_FIXTURE_MANIFEST');
    if($manifest && is_file($manifest)) {
        $fixture=json_decode(file_get_contents($manifest),true,512,JSON_THROW_ON_ERROR);
        if($fixture['database']!==app_config('database.database')) throw new RuntimeException('Database manifest tidak sesuai.');
        $foreign=(int)$fixture['schedules'][1];
        foreach(['/admin/admin_pengajian.php?tab=jadwal&action=detail&id=','/admin/admin_jadwal_ngaji.php?action=detail&id='] as $path) {
            $r=$request($path.$foreign,null,$guru);
            if($r['status']===302) $r=$request($r['location'],null,$guru);
            $check($r['status']===403,'HTTP detail jadwal guru lain ditolak: '.$path);
        }
    }
    if(getenv('AUDIT_CHECK_OPEN_FINDINGS')==='1') {
        foreach(['sbx_guru_biasa','sbx_murobi_a'] as $user) {
            $home=$request('/portal/index.php',null,$jars[$user]);
            foreach(['/admin/admin_laporan_absensi.php','/portal/notifikasi.php'] as $path) {
                $shown=str_contains($home['body'],'href="'.$path.'"');
                $target=$request($path,null,$jars[$user]);
                $check(!$shown || $target['status']===200,'A-07 menu '.$user.' '.$path.' terlihat='.($shown?'ya':'tidak').' HTTP='.$target['status']);
            }
        }
    }
} finally { foreach($jars as $jar) unlink($jar); }
exit($fail?1:0);
