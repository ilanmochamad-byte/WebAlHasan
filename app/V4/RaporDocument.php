<?php
declare(strict_types=1);
namespace App\V4;

final class RaporDocument
{
    public static function body(array $s): string
    {
        $e=static fn($x)=>htmlspecialchars((string)$x,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        $html='<h1>Rapor Semester Al Hasan</h1><p>'.$e($s['santri']['nama_santri']).' · NIS '.$e($s['santri']['nis']??'').'<br>Kelas '.$e($s['kelas']).' · '.$e($s['tahun']).' / '.$e($s['semester']).'</p>';
        $html.='<table><thead><tr><th>Mata pelajaran</th><th>Nilai</th><th>Predikat</th><th>Target</th></tr></thead><tbody>';
        foreach($s['mapel'] as $m){$r=$m['hasil'];$html.='<tr><td>'.$e($m['nama']).'</td><td>'.$e($r['nilai_tampil']??'Belum lengkap').'</td><td>'.$e($r['predikat']??'—').'</td><td>'.$e($m['target']).'</td></tr>';
            if($m['catatan']!=='')$html.='<tr><td colspan="4">Catatan: '.nl2br($e($m['catatan'])).'</td></tr>';
        }
        $html.='</tbody></table><p>Rata-rata nilai: '.$e($s['rata_rata']??'Belum lengkap').'</p><h2>Sikap</h2>';
        foreach(['adab'=>'Adab/akhlak','kedisiplinan'=>'Kedisiplinan','tanggung_jawab'=>'Tanggung jawab'] as $key=>$label)$html.='<p>'.$label.': '.$e($s['sikap'][$key]??'Belum lengkap').'</p>';
        $html.='<p>Catatan pembimbing: '.nl2br($e($s['sikap']['catatan']??'')).'</p><h2>Kehadiran</h2><p>';
        foreach($s['kehadiran']['hitungan'] as $k=>$v)$html.=$e($k).': '.$e($v).' &nbsp; ';
        $html.='</p><p>Pertemuan: '.$e($s['kehadiran']['jumlah_pertemuan']).' · Belum dicatat: '.$e($s['kehadiran']['belum_dicatat']).'</p><h2>Catatan umum</h2><p>'.nl2br($e($s['catatan_umum'])).'</p>';
        if($s['kekurangan'])$html.='<h2>Kekurangan data</h2><p>'.implode('<br>',array_map($e,$s['kekurangan'])).'</p>';
        $html.='<p>'.$e($s['tempat']).', '.$e($s['tanggal']).' · Versi '.$e($s['version']).'</p><table class="sign"><tr>';
        foreach($s['penanda'] as $p)$html.='<td>'.$e($p['jabatan']).'<br>'.(isset($p['gambar'])?'<img width="150" height="65" src="'.$e($p['gambar']).'" alt="Tanda tangan">':'').'<br>'.$e($p['nama'].' '.$p['gelar']).'</td>';
        return $html.'</tr></table>';
    }
    public function render(array $snapshot,string $url,string $code): string
    {
        if(!is_file(APP_ROOT.'/vendor/autoload.php'))throw new V4Exception('Dependensi PDF belum tersedia.',503);
        require_once APP_ROOT.'/vendor/autoload.php';
        $options=new \Dompdf\Options(['isRemoteEnabled'=>false,'isPhpEnabled'=>false,'isJavascriptEnabled'=>false,'chroot'=>APP_ROOT.'/storage/private/v4','defaultFont'=>'DejaVu Sans','tempDir'=>APP_ROOT.'/storage/private/v4','fontCache'=>APP_ROOT.'/storage/private/v4','logOutputFile'=>'']);
        $pdf=new \Dompdf\Dompdf($options);
        $qr=(new \chillerlan\QRCode\QRCode(new \chillerlan\QRCode\QROptions(['outputType'=>\chillerlan\QRCode\QRCode::OUTPUT_MARKUP_SVG,'svgUseFillAttributes'=>true])))->render($url);
        $e=static fn($x)=>htmlspecialchars($x,ENT_QUOTES,'UTF-8');
        $html='<html><head><meta charset="UTF-8"><style>@page{margin:35px}body{font:10pt "DejaVu Sans";color:#182c25}h1{font-size:18pt}h2{font-size:12pt;margin:12px 0 5px}table{border-collapse:collapse;width:100%}td,th{border:1px solid #ccd8d1;padding:7px;vertical-align:top}th{text-align:left;background:#edf3ef}p{margin:5px 0;overflow-wrap:anywhere}.sign td{border:0;text-align:center}.sign{page-break-inside:avoid}.verify{page-break-inside:avoid}td{word-wrap:break-word}</style></head><body>'.self::body($snapshot).'<div class="verify"><img width="110" height="110" src="'.$e($qr).'"><p>Kode verifikasi: '.$e($code).'<br>Pindai QR untuk status. Cocokkan berkas PDF melalui halaman verifikasi.<br>Gambar tanda tangan; bukan tanda tangan sertifikat kriptografis.</p></div></body></html>';
        $pdf->loadHtml($html,'UTF-8');$pdf->setPaper('A4');$pdf->render();return $pdf->output();
    }
}
