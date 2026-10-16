<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(77);
require dirname(__DIR__).'/vendor/autoload.php';
$decoded=(string)(new chillerlan\QRCode\QRCode())->readFromFile('/private/tmp/v4-fase4-qr-page.png');$code=trim(file_get_contents('/private/tmp/v4-fase4-code.txt'));
if(!str_ends_with($decoded,'/verifikasi_rapor.php#'.$code))exit(1);
echo "[lulus] QR pada render PDF kanonis dapat dipindai; URL fragment cocok dengan kode 48 hex.\n";
