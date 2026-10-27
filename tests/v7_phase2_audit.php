<?php
declare(strict_types=1);

// Probe independen auditor; hanya DB sintetis, tanpa mengubah oracle implementator.
if (getenv('V7_RUN_AUDIT') !== '1' || !preg_match('/^test_[A-Za-z0-9_]*_test$/D', (string) getenv('DB_NAME'))) exit(2);
require_once dirname(__DIR__) . '/app/bootstrap.php';
$db = app_db();
$value = static fn (string $sql): mixed => $db->query($sql)->fetch_row()[0];
$account = static function (string $name) use ($db): array {
    $s = $db->prepare('SELECT u.id,u.guru_id,GROUP_CONCAT(r.slug) roles FROM users u LEFT JOIN user_roles ur ON ur.user_id=u.id LEFT JOIN roles r ON r.id=ur.role_id WHERE u.username=? GROUP BY u.id,u.guru_id');
    $s->bind_param('s', $name); $s->execute(); $r = $s->get_result()->fetch_assoc();
    return ['id'=>(int)$r['id'], 'guru_id'=>$r['guru_id'] === null ? null : (int)$r['guru_id'], 'roles'=>explode(',', $r['roles'])];
};
$meta = ['ip'=>null,'user_agent'=>'audit-v7-f2'];
$call = static function (string $action, array $user, int $id, ?int $version, string $key, ?int $target = null) use ($meta): array {
    return match ($action) {
        'decision' => izin_workflow_service()->decide($user,$id,'Disetujui','Keputusan fixture audit',null,$version,$key,$meta,'murobi'),
        'admin' => izin_workflow_service()->decide($user,$id,'Ditolak','Keputusan fixture audit','Murobi berhalangan',$version,$key,$meta,'admin'),
        'assign' => izin_workflow_service()->assignMurobi($user,$id,(int)$target,'Penetapan fixture audit',$version,$key,$meta),
        'correct' => izin_workflow_service()->correctDecision($user,$id,'Ditolak','Keputusan terkoreksi','Koreksi fixture audit',$version,$key,$meta),
        'review' => izin_wali_service()->tinjau($user,$id,'teruskan',null,$version,$key,$meta),
        'cancel' => izin_wali_service()->batalkan($user,$id,'Pembatalan fixture audit',$version,$key,$meta),
        default => throw new RuntimeException('Aksi asing'),
    };
};
$result = static function (callable $f): array {
    try { return ['http'=>200,'data'=>$f()]; }
    catch (App\Izin\IzinException $e) { return ['http'=>$e->status()]; }
    catch (Throwable $e) { return ['http'=>500,'error'=>$e->getMessage()]; }
};
if (($argv[1] ?? '') === '--worker') {
    [$action,$name,$id,$version,$key,$start] = array_slice($argv,2,6);
    $user = $account($name);
    echo $db->thread_id . PHP_EOL; flush();
    while (microtime(true) < (float)$start) usleep(500);
    echo json_encode($result(fn () => $call($action,$user,(int)$id,(int)$version,$key,isset($argv[8])?(int)$argv[8]:null))) . PHP_EOL;
    exit;
}
$good = $bad = 0;
$assert = static function (bool $pass,string $label) use (&$good,&$bad): void {
    echo ($pass?'[lulus] ':'[gagal] ') . $label . PHP_EOL; $pass ? $good++ : $bad++;
};
$w = $account('sbx_v7_wali_a'); $pb = $account('sbx_v7_pembimbing_satu');
$m = $account('sbx_murobi_a'); $a = $account('sbx_admin');
$sid = (int)$value("SELECT id FROM santri WHERE nis='V7S0008'");
$nonce = bin2hex(random_bytes(5)); $no = (int)$value("SELECT COUNT(*) FROM izin_pengajuan WHERE asal_pengajuan='Wali'");
$create = static function (bool $forward = true) use (&$no,$nonce,$w,$pb,$sid,$meta): int {
    $d = (new DateTimeImmutable('2034-01-01'))->modify('+' . (++$no * 2) . ' days')->format('Y-m-d');
    $id = (int)izin_wali_service()->buat($w,['santri_id'=>$sid,'tgl_izin'=>$d,'tgl_kembali'=>$d,'alasan'=>'Fixture audit independen'],'audit-create-'.$nonce.'-'.$no,$meta)['id'];
    if ($forward) izin_wali_service()->tinjau($pb,$id,'teruskan',null,1,'audit-forward-'.$nonce.'-'.$no,$meta);
    return $id;
};
$state = static function (int $id) use ($db): string {
    $rows = [];
    foreach (['izin_pengajuan'=>'id','izin_keputusan'=>'pengajuan_id','izin_keputusan_koreksi'=>'pengajuan_id','izin_pembimbing_tinjauan'=>'pengajuan_id','izin_riwayat_status'=>'pengajuan_id'] as $table=>$key) {
        $rows[$table] = $db->query("SELECT * FROM $table WHERE $key=$id ORDER BY id")->fetch_all(MYSQLI_ASSOC);
    }
    return hash('sha256',json_encode($rows));
};
$start = static function (string $action,string $name,int $id,int $version,string $key,float $at=0,?int $target=null): array {
    $p = proc_open([PHP_BINARY,__FILE__,'--worker',$action,$name,(string)$id,(string)$version,$key,(string)$at,(string)$target], [1=>['pipe','w'],2=>['pipe','w']],$pipes);
    return [$p,$pipes,(int)fgets($pipes[1])];
};
$finish = static function (array $p): array {
    $out=stream_get_contents($p[1][1]); $err=stream_get_contents($p[1][2]); proc_close($p[0]);
    return json_decode(trim($out),true) ?? ['http'=>500,'error'=>$err.$out];
};
$wait = static function (int $thread,string|array $fragment) use ($value): bool {
    $end=microtime(true)+8;
    do {
        $sql = (string)$value('SELECT INFO FROM information_schema.PROCESSLIST WHERE ID='.$thread);
        foreach ((array)$fragment as $part) if (str_contains($sql,$part)) return true;
        usleep(20000);
    } while(microtime(true)<$end);
    return false;
};

// Hak yang dicabut ketika keputusan sudah lolos guard awal dan menunggu baris.
foreach (['decision','admin','assign','correct'] as $action) {
    $id=$create(); $before=$state($id);
    if ($action==='correct') {
        $call('decision',$m,$id,2,'audit-before-correction-'.$nonce);
        $before=$state($id);
    }
    $old=$db->query('SELECT id,is_active FROM murobi_assignments WHERE guru_id='.$m['guru_id'])->fetch_all(MYSQLI_ASSOC);
    try {
        $db->begin_transaction(); $db->query("SELECT id FROM izin_pengajuan WHERE id=$id FOR UPDATE");
        // Memegang akun lebih dahulu memungkinkan pencabutan sungguh commit,
        // baik sumber asli (menunggu pengajuan) maupun koreksi (menunggu akun).
        if ($action!=='decision') $db->query('SELECT id FROM users WHERE id='.$a['id'].' FOR UPDATE');
        $target=(int)$value("SELECT guru_id FROM users WHERE username='sbx_murobi_b'");
        $p=$start($action,$action==='decision'?'sbx_murobi_a':'sbx_admin',$id,$action==='correct'?3:2,'audit-revoke-'.$nonce.'-'.$action,0,$target);
        $assert($wait($p[2],$action==='decision'?'SELECT p.* FROM izin_pengajuan':['SELECT id FROM users','SELECT p.* FROM izin_pengajuan','INSERT INTO izin_idempotency_keys']), 'Kontrol '.$action.': permintaan sudah tertahan pada kunci');
        $changed = $action==='decision'
            ? $db->query('UPDATE murobi_assignments SET is_active=0 WHERE guru_id='.$m['guru_id'])
            : $db->query('UPDATE users SET is_active=0 WHERE id='.$a['id']);
        $assert($changed && $db->affected_rows>0, 'Kontrol '.$action.': pencabutan benar-benar tersimpan sebelum melepas kunci');
        $db->commit(); $r=$finish($p);
        $assert($r['http']===403 && $state($id)===$before,'Hak '.$action.' dicabut saat menunggu → 403 tanpa mutasi (HTTP '.$r['http'].')');
    } finally {
        $db->rollback(); $db->query('UPDATE users SET is_active=1 WHERE id='.$a['id']);
        foreach ($old as $r) $db->query('UPDATE murobi_assignments SET is_active='.(int)$r['is_active'].' WHERE id='.(int)$r['id']);
        capabilities()->forget($m['id']); capabilities()->forget($a['id']);
    }
}

// Putar ulang tetap menguji hak sasaran dan konflik versi dalam isi request.
foreach (['decision','assign','correct'] as $action) {
    $id=$create();
    if ($action==='correct') $call('decision',$m,$id,2,'audit-null-setup-'.$nonce);
    $before=$state($id);
    $target=(int)$value("SELECT guru_id FROM users WHERE username='sbx_murobi_b'");
    $r=$result(fn()=>$call($action,$action==='decision'?$m:$a,$id,null,'audit-null-'.$nonce.'-'.$action,$target));
    $assert($r['http']===422 && $state($id)===$before,'Versi wajib pada '.$action.' pengajuan wali → 422 tanpa mutasi (HTTP '.$r['http'].')');
}
$id=$create(); $key='audit-replay-'.$nonce;
$call('decision',$m,$id,2,$key); $before=$state($id);
$r=$result(fn()=>$call('decision',$m,$id,999,$key));
$assert($r['http']===409 && $state($id)===$before,'Kunci keputusan sama dengan versi berbeda → 409 tanpa mutasi (HTTP '.$r['http'].')');
$old=$db->query('SELECT id,is_active FROM murobi_assignments WHERE guru_id='.$m['guru_id'])->fetch_all(MYSQLI_ASSOC);
try {
    $db->query('UPDATE murobi_assignments SET is_active=0 WHERE guru_id='.$m['guru_id']); capabilities()->forget($m['id']);
    $r=$result(fn()=>$call('decision',$m,$id,2,$key));
    $assert($r['http']===403 && $state($id)===$before,'Retry keputusan sesudah penugasan dicabut → 403 tanpa membuka hasil');
} finally {
    foreach($old as $r) $db->query('UPDATE murobi_assignments SET is_active='.(int)$r['is_active'].' WHERE id='.(int)$r['id']);
    capabilities()->forget($m['id']);
}
$id=$create(); $other=(int)$value("SELECT guru_id FROM users WHERE username='sbx_murobi_b'");
$key='audit-assign-'.$nonce; $call('assign',$a,$id,2,$key,$other); $before=$state($id);
$r=$result(fn()=>$call('assign',$a,$id,999,$key,$other));
$assert($r['http']===409 && $state($id)===$before,'Kunci penetapan sama dengan versi berbeda → 409 (HTTP '.$r['http'].')');
$call('admin',$a,$id,3,'audit-admin-'.$nonce);
$key='audit-correct-'.$nonce; $call('correct',$a,$id,4,$key); $before=$state($id);
$r=$result(fn()=>$call('correct',$a,$id,999,$key));
$assert($r['http']===409 && $state($id)===$before,'Kunci koreksi sama dengan versi berbeda → 409 (HTTP '.$r['http'].')');

// Kunci sama benar-benar serentak pada jalur keputusan yang dipakai V7.
for($round=0;$round<3;$round++) {
    $id=$create(); $at=microtime(true)+1; $jobs=[]; $key='audit-click-'.$nonce.'-'.$round;
    for($i=0;$i<8;$i++) $jobs[]=$start('decision','sbx_murobi_a',$id,2,$key,$at);
    $r=array_map($finish,$jobs); $codes=array_count_values(array_column($r,'http'));
    $assert(($codes[200]??0)===8 && (int)$value("SELECT COUNT(*) FROM izin_keputusan WHERE pengajuan_id=$id")===1,
        'Delapan retry keputusan serentak: 8 sukses, satu keputusan '.json_encode($codes));
}
// Balap ulang menggunakan kunci yang sama per kubu, lalu retry kedua hasil.
foreach([0,1,2] as $round) {
    $id=$create(); $at=microtime(true)+1; $jobs=[];
    $dk='audit-race-d-'.$nonce.'-'.$round; $ck='audit-race-c-'.$nonce.'-'.$round;
    for($i=0;$i<4;$i++) {
        $jobs[]=$start('decision','sbx_murobi_a',$id,2,$dk,$at+($round===1?0.15:0));
        $jobs[]=$start('cancel','sbx_v7_wali_a',$id,2,$ck,$at+($round===2?0.15:0));
    }
    $r=array_map($finish,$jobs); $before=$state($id);
    $r[]=$result(fn()=>$call('decision',$m,$id,2,$dk));
    $r[]=$result(fn()=>$call('cancel',$w,$id,2,$ck));
    $codes=array_count_values(array_column($r,'http'));
    $st=$value("SELECT status FROM izin_pengajuan WHERE id=$id"); $count=(int)$value("SELECT COUNT(*) FROM izin_keputusan WHERE pengajuan_id=$id");
    $assert(array_diff(array_keys($codes),[200,409])===[] && $state($id)===$before
        && (($st==='Dibatalkan' && $count===0)||($st==='Disetujui' && $count===1)),
        'Batal vs keputusan dengan retry serentak + retry hasil: terminal '.$st.', keputusan '.$count.' '.json_encode($codes));
}

// Pembimbing dan relasi wali dicabut ketika pembacaan terkunci tertahan.
foreach (['review','cancel'] as $action) {
    $id=$create(false); $before=$state($id);
    $pa=(int)$value("SELECT pembimbing_assignment_id FROM izin_pengajuan WHERE id=$id");
    $relation=(int)$value('SELECT sw.id FROM santri_wali sw JOIN users u ON u.wali_id=sw.wali_id WHERE u.id='.$w['id'].' AND sw.santri_id='.$sid.' AND sw.archived_at IS NULL');
    try {
        $db->begin_transaction();
        // Relasi memiliki FK wali. Kunci induknya dahulu supaya kontrol
        // pencabutan tidak berbalik menunggu kunci baca induk milik worker.
        if ($action==='cancel') $db->query('SELECT id FROM wali WHERE id=(SELECT wali_id FROM users WHERE id='.$w['id'].') FOR UPDATE');
        $db->query($action==='review'?"SELECT id FROM pembimbing_assignments WHERE id=$pa FOR UPDATE":"SELECT id FROM santri_wali WHERE id=$relation FOR UPDATE");
        $p=$start($action,$action==='review'?'sbx_v7_pembimbing_satu':'sbx_v7_wali_a',$id,1,'audit-wait-'.$nonce.'-'.$action);
        $assert($wait($p[2],$action==='review'?'SELECT id FROM pembimbing_assignments':'SELECT w.id'),'Kontrol '.$action.': pemeriksaan hak menunggu kunci');
        $changed=$db->query($action==='review'?"UPDATE pembimbing_assignments SET is_active=0 WHERE id=$pa":"UPDATE santri_wali SET archived_at=NOW() WHERE id=$relation");
        if (!$changed) echo '[info] Kontrol '.$action.': errno '.$db->errno.' '.$db->error.PHP_EOL;
        $assert($changed && $db->affected_rows===1,'Kontrol '.$action.': pencabutan tersimpan');
        $db->commit(); $r=$finish($p);
        $assert($r['http']===404 && $state($id)===$before, $action.' sesudah pencabutan terkunci → 404 tanpa mutasi');
    } finally {
        $db->rollback();
        $db->query("UPDATE pembimbing_assignments SET is_active=1 WHERE id=$pa");
        $db->query("UPDATE santri_wali SET archived_at=NULL WHERE id=$relation");
    }
}

// Batal vs penerusan memakai retry yang sama; hasil penerusan diselesaikan
// dengan pembatalan versi terkini, lalu seluruh kiriman lama diulang.
foreach([0,1,2] as $round) {
    $id=$create(false); $at=microtime(true)+1; $jobs=[];
    $rk='audit-forward-race-'.$nonce.'-'.$round; $ck='audit-cancel-race-'.$nonce.'-'.$round;
    for($i=0;$i<4;$i++) {
        $jobs[]=$start('review','sbx_v7_pembimbing_satu',$id,1,$rk,$at+($round===1?0.15:0));
        $jobs[]=$start('cancel','sbx_v7_wali_a',$id,1,$ck,$at+($round===2?0.15:0));
    }
    $r=array_map($finish,$jobs); $initial=$value("SELECT status FROM izin_pengajuan WHERE id=$id");
    if($initial==='Diajukan') $call('cancel',$w,$id,2,'audit-final-cancel-'.$nonce.'-'.$round);
    $before=$state($id);
    $r[]=$result(fn()=>$call('review',$pb,$id,1,$rk));
    $r[]=$result(fn()=>$call('cancel',$w,$id,1,$ck));
    $codes=array_count_values(array_column($r,'http'));
    $assert(array_diff(array_keys($codes),[200,409])===[] && $state($id)===$before
        && $value("SELECT status FROM izin_pengajuan WHERE id=$id")==='Dibatalkan'
        && (int)$value("SELECT COUNT(*) FROM izin_keputusan WHERE pengajuan_id=$id")===0
        && (int)$value("SELECT COUNT(*) FROM izin_pembimbing_tinjauan WHERE pengajuan_id=$id")===($initial==='Diajukan'?1:0),
        'Batal vs penerusan + retry sampai terminal: awal '.$initial.', akhir Dibatalkan '.json_encode($codes));
}
echo "Ringkasan: $good lulus, $bad gagal\n";
exit($bad ? 1 : 0);
