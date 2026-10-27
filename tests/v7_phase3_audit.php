<?php
declare(strict_types=1);

// Probe auditor. Jalankan sesudah integrasi Fase 3 pada DB sintetis yang sama.
if (getenv('V7_RUN_AUDIT') !== '1' || !preg_match('/^test_[A-Za-z0-9_]*_test$/D', (string)getenv('DB_NAME'))) exit(2);
require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Izin\IzinLaporanRepository;
use App\Izin\IzinLaporanService;
use App\Izin\IzinWaliRepository;
use App\Izin\IzinException;

$db = app_db();
$value = static fn(string $sql): mixed => $db->query($sql)->fetch_row()[0];
$account = static function(string $name) use ($db): array {
    $s = $db->prepare('SELECT id,guru_id FROM users WHERE username=?');
    $s->bind_param('s',$name); $s->execute(); $u=$s->get_result()->fetch_assoc();
    return ['id'=>(int)$u['id'],'guru_id'=>$u['guru_id']===null?null:(int)$u['guru_id']];
};
$pass=$fail=0;
$assert=static function(bool $ok,string $label) use (&$pass,&$fail): void {
    echo ($ok?'[lulus] ':'[gagal] ').$label.PHP_EOL; $ok?$pass++:$fail++;
};
$w=$account('sbx_v7_wali_a'); $other=$account('sbx_v7_wali_b'); $admin=$account('sbx_admin');
$sid=(int)$value("SELECT id FROM santri WHERE nis='V7S0008'");
$nonce=bin2hex(random_bytes(6));
$date=(new DateTimeImmutable('2038-01-01'))->modify('+'.random_int(1,3000).' days')->format('Y-m-d');
$id=(int)izin_wali_service()->buat($w,['santri_id'=>$sid,'tgl_izin'=>$date,'tgl_kembali'=>$date,'alasan'=>'Fixture audit fase tiga'], 'audit-f3-'.$nonce,[])['id'];

// F-1: dua koneksi DB, penulis commit tepat di antara COUNT dan daftar.
final class ReportInterleaveDb extends mysqli {
    public ?Closure $interleave=null;
    public function prepare(string $query): mysqli_stmt|false {
        if ($this->interleave!==null && str_contains($query,'SELECT p.id, p.asal_pengajuan')) {
            $f=$this->interleave; $this->interleave=null; $f();
        }
        return parent::prepare($query);
    }
}
$config=app_config('database');
$conn=new ReportInterleaveDb($config['host'],$config['username'],$config['password'],$config['database'],$config['port']);
$conn->set_charset($config['charset']);
$report=new IzinLaporanService(new IzinLaporanRepository($conn),new IzinWaliRepository($conn),capabilities(),audit_logger());
$conn->interleave=static function() use ($db,$id): void {
    if (!$db->query("UPDATE izin_pengajuan SET status='Perlu Perbaikan Wali' WHERE id=$id")) throw new RuntimeException('Penulis kontrol gagal.');
};
try {
    $r=$report->laporan($admin,['asal'=>'wali','santri_id'=>(string)$sid,'tahap'=>'pembimbing','dari'=>$date,'sampai'=>$date]);
    $assert($conn->interleave===null && $value("SELECT status FROM izin_pengajuan WHERE id=$id")==='Perlu Perbaikan Wali','Kontrol: penulis lain benar-benar commit di antara bacaan laporan');
    $assert($r['total']===count($r['items']) && $r['total']===$r['ringkasan']['total'],
        'Laporan satu snapshot: total='.$r['total'].', daftar='.count($r['items']).', kartu='.$r['ringkasan']['total']);
} finally { $db->query("UPDATE izin_pengajuan SET status='Menunggu Pembimbing' WHERE id=$id"); $conn->close(); }

// F-2: proyeksi API, termasuk ringkasan serta filter yang dapat menjadi oracle.
$r=izin_laporan_service()->laporan($other,['asal'=>'wali','santri_id'=>(string)$sid]);
$assert(!isset($r['ringkasan']['rerata_lama_jam']),'Wali kedua tidak menerima durasi internal lewat ringkasan API');
foreach (['durasi_min_jam'=>'1','durasi_maks_jam'=>'1','pembimbing_id'=>'1','murobi_id'=>'1'] as $field=>$v) {
    foreach (['laporan','ekspor'] as $method) {
        $code=200;
        try { izin_laporan_service()->$method($other,[$field=>$v]); } catch (IzinException $e) { $code=$e->status(); }
        $assert($code===422,"Wali kedua: $method menolak filter internal $field (HTTP $code)");
    }
}

// Rollback izin SETELAH outbox/audit/idempotensi lengkap ditulis, sebelum COMMIT.
final class FailCommitDb extends mysqli {
    public bool $failCommit=true;
    public int $pendingOutbox=0;
    public function commit(int $flags=0, ?string $name=null): bool {
        if ($this->failCommit) {
            $this->pendingOutbox=(int)$this->query("SELECT COUNT(*) FROM notifikasi_outbox WHERE event_key LIKE 'v7:%'")->fetch_row()[0];
            throw new RuntimeException('COMMIT gagal sintetis');
        }
        return parent::commit($flags,$name);
    }
}
$f=new FailCommitDb($config['host'],$config['username'],$config['password'],$config['database'],$config['port']);
$f->set_charset($config['charset']);
$repo=new IzinWaliRepository($f);
$service=new App\Izin\IzinWaliService($repo,new App\Izin\IzinWriteRepository($f),new App\Izin\IzinRepository($f),new App\Izin\PembimbingRouter($f),new App\Izin\IzinIdempotency($f),capabilities(),new App\Audit\AuditLogger($f),new App\Izin\IzinRouter($f));
$tables=['izin_pengajuan','izin_riwayat_status','izin_idempotency_keys','audit_logs','notifikasi_outbox'];
$counts=static fn(): array => array_map(static fn(string $table): int=>(int)$value("SELECT COUNT(*) FROM $table"),$tables);
$before=$counts(); $outboxBefore=(int)$value("SELECT COUNT(*) FROM notifikasi_outbox WHERE event_key LIKE 'v7:%'");
$rollbackDate=(new DateTimeImmutable($date))->modify('+1 day')->format('Y-m-d');
$input=['santri_id'=>$sid,'tgl_izin'=>$rollbackDate,'tgl_kembali'=>$rollbackDate,'alasan'=>'Rollback fixture auditor'];
try { $service->buat($w,$input,'audit-f3-rollback-'.$nonce,[]); $assert(false,'Kegagalan COMMIT harus dilempar'); }
catch (RuntimeException $e) { $assert($e->getMessage()==='COMMIT gagal sintetis' && $f->pendingOutbox>$outboxBefore,'Fault terjadi setelah notifikasi ditulis dalam transaksi izin'); }
$assert($before===$counts(),'Rollback izin menghapus pengajuan/riwayat/kunci/audit/outbox tanpa sisa');
$f->failCommit=false;
$saved=$service->buat($w,$input,'audit-f3-rollback-'.$nonce,[]);
$assert(($saved['idempotent_replay']??true)===false,'Retry kunci transaksi rollback dapat membuat pengajuan baru');
$n=(int)$value("SELECT COUNT(*) FROM notifikasi_outbox WHERE event_key LIKE 'v7:izin:".(int)$saved['id'].":%'");
$replay=$service->buat($w,$input,'audit-f3-rollback-'.$nonce,[]);
$assert(($replay['idempotent_replay']??false) && (int)$saved['id']===(int)$replay['id'] && $n>0 && $n===(int)$value("SELECT COUNT(*) FROM notifikasi_outbox WHERE event_key LIKE 'v7:izin:".(int)$saved['id'].":%'"),'Retry sesudah commit memakai satu ID dan satu set outbox');
$f->close();

// Pindai per baris: GROUP_CONCAT dapat terpotong dan menyembunyikan kebocoran.
$needles=['RAHASIA-V7F3','Bearer ','ExponentPushToken[','ExpoPushToken[','Rollback fixture auditor','Fixture audit fase tiga'];
foreach ($db->query('SELECT nama_santri FROM santri')->fetch_all(MYSQLI_ASSOC) as $row) if ($row['nama_santri']!=='') $needles[]=$row['nama_santri'];
foreach ($db->query("SELECT no_hp FROM wali UNION SELECT no_hp FROM pengurus")->fetch_all(MYSQLI_ASSOC) as $row) if (strlen((string)$row['no_hp'])>=6) $needles[]=$row['no_hp'];
foreach ([
    'outbox'=>"SELECT judul,isi,data_json,error_terakhir FROM notifikasi_outbox WHERE event_key LIKE 'v7:%'",
    'audit'=>"SELECT before_json,after_json FROM audit_logs WHERE action LIKE 'izin_wali%' OR action LIKE 'izin_v7%'",
    'percobaan'=>"SELECT c.error_kode,c.error_pesan FROM notifikasi_percobaan c JOIN notifikasi_outbox o ON o.id=c.outbox_id WHERE o.event_key LIKE 'v7:%'",
] as $label=>$sql) {
    $leaks=0; $rows=$db->query($sql)->fetch_all(MYSQLI_ASSOC);
    foreach ($rows as $row) foreach ($needles as $needle) if (str_contains(json_encode($row,JSON_UNESCAPED_UNICODE),$needle)) $leaks++;
    $assert($leaks===0,"Pemindaian $label per baris: ".count($rows).' baris, tanpa penanda sensitif yang diuji');
}
echo "Hasil: $pass lulus, $fail gagal\n";
exit($fail===0?0:1);
