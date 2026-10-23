<?php
declare(strict_types=1);
use App\Auth\Capabilities;
use App\Penugasan\PenugasanJenis;
use App\V4\RaporStorage;
use App\V4\RaporTampilan;
use App\V4\V4Exception;
require_once dirname(__DIR__).'/app/bootstrap.php';

/*
 * Pratinjau gambar tanda tangan rapor (redesain website Tahap 7, R-1/R-7).
 *
 * Hanya untuk akun yang boleh memilih penanda tangan rencana
 * (`rapor.rencana`): Bagian Pendidikan melihat aset yang BERLAKU — aset yang
 * sama juga tampil di pratinjau penerbitan mereka — sedangkan aset nonaktif
 * hanya untuk admin pengelola penanda tangan. Berkas tetap privat: tidak ada
 * URL publik, isi dicocokkan dengan sha256 tersimpan, tanpa cache bersama.
 */
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
$user=authorization()->requireWebUser();
$caps=capabilities();
$tolak=static function(int $status):never{http_response_code($status);header('Content-Type: text/plain; charset=utf-8');echo 'Gambar tanda tangan tidak dapat diakses.';exit;};
$caps->forget((int)$user['id']);
if(!$caps->hasFeature($user,PenugasanJenis::CAP_RAPOR_RENCANA))$tolak(403);
$aset=(new RaporTampilan(app_db()))->aset((int)($_GET['aset']??0));
if($aset===null)$tolak(404);
$admin=in_array($caps->featureSource($user,PenugasanJenis::CAP_RAPOR_RENCANA),[Capabilities::SUMBER_ADMIN,Capabilities::SUMBER_KEDUANYA],true);
if(!(bool)$aset['berlaku']&&!$admin)$tolak(403);
try{$path=(new RaporStorage())->path((string)$aset['private_path']);}catch(V4Exception){$tolak(404);}
$bytes=@file_get_contents($path);
if($bytes===false||!hash_equals((string)$aset['sha256'],hash('sha256',$bytes)))$tolak(404);
if(!in_array($aset['mime_type'],['image/png','image/jpeg','image/webp'],true))$tolak(404);
header('Content-Type: '.$aset['mime_type']);
header("Content-Security-Policy: default-src 'none'; sandbox");
header('Content-Disposition: inline; filename="tanda-tangan-'.(int)$aset['id'].'"');
header('Content-Length: '.strlen($bytes));
echo $bytes;
