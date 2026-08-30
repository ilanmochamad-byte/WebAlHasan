<?php
declare(strict_types=1);
if (getenv('PERAPIHAN_AUDIT_DB') !== '1') { exit(77); }
require_once dirname(__DIR__) . '/app/bootstrap.php';
if (!str_ends_with((string) app_config('database.database'), '_test')) { exit(2); }
$db=app_db(); $fail=0; $created=[]; $original=[];
$check=static function(bool $ok,string $text)use(&$fail):void {echo ($ok?'[lulus] ':'[gagal] ').$text.PHP_EOL; $fail+=!$ok;};
$count=static fn():int => (int)$db->query("SELECT COUNT(*) c FROM users u JOIN user_roles ur ON ur.user_id=u.id JOIN roles r ON r.id=ur.role_id WHERE r.slug='admin' AND u.is_active=1")->fetch_assoc()['c'];
$role=(int)$db->query("SELECT id FROM roles WHERE slug='admin'")->fetch_assoc()['id'];
try {
    $original=$db->query("SELECT ur.user_id FROM user_roles ur JOIN users u ON u.id=ur.user_id WHERE ur.role_id=$role AND u.is_active=1")->fetch_all(MYSQLI_ASSOC);
    for($i=0;$i<5;$i++) {
        $db->execute_query("INSERT INTO users (name,username,password,is_active,force_password_change) VALUES (?,?,?,1,0)",['Audit concurrency','auditcc'.bin2hex(random_bytes(5)),password_hash(bin2hex(random_bytes(16)),PASSWORD_DEFAULT)]);
        $created[]=$db->insert_id;
    }
    foreach($created as $id) $db->query("INSERT INTO user_roles(user_id,role_id) VALUES ($id,$role)");
    foreach($original as $r) $db->query("DELETE FROM user_roles WHERE user_id=".(int)$r['user_id']." AND role_id=$role");
    for($repeat=1;$repeat<=3;$repeat++) foreach(['revoke','nonaktif','mixed','self-and-other'] as $variant) {
        foreach($created as $id) { $db->query("UPDATE users SET is_active=1 WHERE id=$id"); $db->query("INSERT IGNORE INTO user_roles(user_id,role_id) VALUES ($id,$role)"); }
        $jobs=[]; $at=(string)(microtime(true)+0.3);
        $tasks=[];
        foreach($created as $i=>$id) $tasks[]=[$id,$created[($i+1)%5],$variant==='nonaktif'||($variant==='mixed'&&$i%2)?'nonaktif':'revoke'];
        if($variant==='self-and-other') $tasks[]=[$created[0],$created[0],'revoke'];
        foreach($tasks as [$id,$actor,$action]) {
            $pipes=[];
            $process=proc_open([PHP_BINARY,__DIR__.'/perapihan_akun_concurrency_worker.php','--at='.$at,'--user='.$id,'--actor='.$actor,'--aksi='.$action],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
            $jobs[]=[$process,$pipes];
        }
        $minimum=5; $deadline=microtime(true)+10;
        do {
            $minimum=min($minimum,$count()); $running=false;
            foreach($jobs as [$process]) $running=$running||proc_get_status($process)['running'];
            if($running) usleep(10000);
        } while($running && microtime(true)<$deadline);
        $success=0; $selfRejected=$variant!=='self-and-other';
        foreach($jobs as $index=>[$process,$pipes]) {
            $out=json_decode(stream_get_contents($pipes[1]),true); $err=stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]); proc_close($process);
            $success+=(int)($out['berhasil']??false);
            if($variant==='self-and-other' && $index===5) $selfRejected=!($out['berhasil']??true)&&str_contains((string)($out['pesan']??''),'sendiri');
        }
        $check(!$running && $minimum>=1 && $count()===1 && $success===4 && $selfRejected,"ADMIN $repeat/$variant: minimum=$minimum akhir=".$count()." sukses=$success; invariant dan penolakan diri sendiri");
    }
} finally {
    foreach($original as $r) $db->query("INSERT IGNORE INTO user_roles(user_id,role_id) VALUES (".(int)$r['user_id'].",$role)");
    foreach($created as $id) { $db->query("DELETE FROM user_roles WHERE user_id=$id"); $db->query("DELETE FROM users WHERE id=$id"); }
    $check($count()===count($original),'ADMIN fixture awal dipulihkan');
}
exit($fail?1:0);
