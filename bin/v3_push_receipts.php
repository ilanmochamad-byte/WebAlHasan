<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/app/bootstrap.php';
$r=new App\V3\KonselingRepository(app_db());
// Baca saja; tidak mengirim, tidak mengubah kanal, tidak menampilkan tiket/token.
echo json_encode($r->all("SELECT status,receipt_status,receipt_kode,COUNT(*) jumlah FROM notifikasi_outbox WHERE kanal='Push' AND event_type LIKE 'v3_%' GROUP BY status,receipt_status,receipt_kode ORDER BY status,receipt_status"),JSON_PRETTY_PRINT).PHP_EOL;
