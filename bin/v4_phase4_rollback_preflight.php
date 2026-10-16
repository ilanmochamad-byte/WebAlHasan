<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/bootstrap.php';
if(PHP_SAPI!=='cli')exit(77);
$r=app_db()->query('SELECT (SELECT COUNT(*) FROM v4_rapor)+(SELECT COUNT(*) FROM v4_rapor_operasi)+(SELECT COUNT(*) FROM v4_rencana_penanda_riwayat) n')->fetch_assoc();
if((int)$r['n']){fwrite(STDERR,"Rollback ditolak: data publikasi/riwayat harus dipertahankan.\n");exit(1);}echo "Tabel bisnis Fase 4 kosong. Hentikan penulis sebelum rollback.\n";
