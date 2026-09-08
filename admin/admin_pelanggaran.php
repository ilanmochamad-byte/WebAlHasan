<?php

declare(strict_types=1);
require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/_master_ui.php';
if ($_SERVER['REQUEST_METHOD']!=='GET' || isset($_GET['hapus'])) {
    http_response_code(405);header('Allow: GET');
    master_header('Data warisan pelanggaran');
    echo '<div class="alert alert-warning">Data warisan hanya dapat dibaca. Pencatatan dan penghapusan lama telah ditutup.</div>';
    master_footer();exit;
}
master_header('Data warisan pelanggaran',['active'=>'pelanggaran','description'=>'Catatan lama dipertahankan apa adanya. Pelaku, katalog, dan tahun ajaran tidak diasumsikan.']);
try {
    $page=v3_katalog_service()->legacy($currentUser,(int)($_GET['page']??1));
    echo '<div class="table-responsive"><table class="table"><thead><tr><th>Santri</th><th>Tanggal</th><th>Jenis lama</th><th>Poin lama</th><th>Catatan hukuman lama</th></tr></thead><tbody>';
    foreach($page['rows'] as $row) {
        echo '<tr>';foreach([$row['nama_santri']??'Referensi santri tidak tersedia',$row['tgl_pelanggaran'],$row['jenis_pelanggaran'],$row['poin'],$row['hukuman']] as $cell) { echo '<td>'.ah_e($cell).'</td>'; }echo '</tr>';
    }
    echo '</tbody></table></div>';master_pagination($page['total'],$page['page'],$page['per_page']);
} catch(\App\V3\V3Exception $e) { http_response_code($e->status);echo '<div class="alert alert-danger">'.ah_e($e->getMessage()).'</div>'; }
master_footer();
