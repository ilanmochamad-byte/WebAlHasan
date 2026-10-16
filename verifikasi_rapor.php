<?php
declare(strict_types=1);
require_once __DIR__.'/app/bootstrap.php';
use App\V4\{VerifikasiPublik,V4Exception};
use App\Http\Csrf;
header('Cache-Control: private, no-store');header('Referrer-Policy: no-referrer');header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; script-src 'self'; style-src 'unsafe-inline'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
$result=null;$error=null;
if($_SERVER['REQUEST_METHOD']==='POST'){
 Csrf::requireValid(is_string($_POST['_csrf']??null)?$_POST['_csrf']:null);
 try{$upload=isset($_FILES['pdf'])&&($_FILES['pdf']['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE?$_FILES['pdf']:null;$result=(new VerifikasiPublik(app_db()))->check((string)($_SERVER['REMOTE_ADDR']??''),$_POST['kode']??null,$upload);}
 catch(V4Exception $e){http_response_code($e->status);if($e->status===429)header('Retry-After: 60');$error=$e->getMessage();}
 catch(Throwable){http_response_code(503);$error='Pemeriksaan belum tersedia. Coba lagi nanti.';}
}
?><!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Verifikasi Rapor Al Hasan</title><style>body{font:18px system-ui;margin:0;background:#edf3ef;color:#17382a}main{max-width:650px;margin:3rem auto;padding:1.2rem;background:white;border-radius:12px}label{display:block;margin:1rem 0}input{display:block;box-sizing:border-box;width:100%;font:inherit;padding:.6rem}button{font:inherit;background:#176349;color:white;padding:.8rem;border:0;border-radius:6px}p{overflow-wrap:anywhere}@media(max-width:700px){main{margin:1rem}}</style><script src="<?= ah_e(app_url('/assets/ui/verifikasi-rapor.js')) ?>" defer></script></head><body><main><h1>Verifikasi rapor</h1><p>Periksa status penerbitan dan kecocokan PDF. Identitas dan nilai santri tidak ditampilkan.</p>
<?php if($error):?><p role="alert"><?= ah_e($error) ?></p><?php endif ?><?php if($result):?><div role="status"><p>Status: <?= ah_e($result['status']) ?> · Versi <?= $result['version'] ?> · Tanggal terbit <?= ah_e($result['tanggal_terbit']) ?></p><?php if(array_key_exists('cocok',$result)):?><p>PDF <?= $result['cocok']?'cocok dengan berkas kanonis.':'tidak cocok dengan berkas kanonis.' ?></p><?php endif ?></div><?php endif ?>
<form method="post" enctype="multipart/form-data"><?= ah_csrf() ?><label>Kode verifikasi<input id="kode" name="kode" autocomplete="off" required pattern="[a-f0-9]{48}" maxlength="48"></label><label>PDF untuk dicocokkan (opsional, maksimal 10 MB)<input type="file" name="pdf" accept="application/pdf"></label><button>Periksa</button></form></main></body></html>
