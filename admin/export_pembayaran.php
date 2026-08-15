<?php
session_start();
if(!isset($_SESSION['status']) || $_SESSION['status'] != "login"){ header("Location: ../admin_login.php"); exit; }
include '../koneksi.php';

$where = "WHERE 1=1";

if(!empty($_GET['jk'])) {
    $jk = mysqli_real_escape_string($koneksi, $_GET['jk']);
    $where .= " AND p.jenis_kelamin = '$jk'";
}
if(!empty($_GET['jenjang'])) {
    $jenjang = mysqli_real_escape_string($koneksi, $_GET['jenjang']);
    $where .= " AND p.jenjang_tujuan = '$jenjang'";
}
if(!empty($_GET['status'])) {
    $status = mysqli_real_escape_string($koneksi, $_GET['status']);
    $where .= " AND pb.status_pembayaran = '$status'";
}

$query = "SELECT pb.*, p.nama_lengkap, p.jenis_kelamin, p.jenjang_tujuan, p.sekolah_asal, p.no_hp_wali 
          FROM psb_pembayaran pb 
          JOIN psb_pendaftar p ON pb.no_pendaftaran = p.no_pendaftaran 
          $where ORDER BY pb.id DESC";

$exec = mysqli_query($koneksi, $query);

$data_excel = [];
$no = 1;
while($d = mysqli_fetch_array($exec)){
    $data_excel[] = [
        'NO' => $no++,
        'NO PENDAFTARAN' => $d['no_pendaftaran'],
        'NAMA LENGKAP' => $d['nama_lengkap'],
        'JK' => $d['jenis_kelamin'],
        'UNIT SEKOLAH' => $d['jenjang_tujuan'],
        'KATEGORI BIAYA' => $d['kategori_biaya'],
        'METODE PEMBAYARAN' => strtoupper($d['metode_pembayaran']),
        'SYAHRIYYAH (Rp)' => $d['syahriyyah'],
        'INFAQ (Rp)' => $d['infaq'],
        'PSAS (Rp)' => $d['seragam_psas'],
        'PRAMUKA (Rp)' => $d['seragam_pramuka'],
        'BIAYA WAJIB (Rp)' => $d['total_wajib'],
        'TOTAL KESELURUHAN (Rp)' => $d['total_keseluruhan'],
        'STATUS' => strtoupper($d['status_pembayaran']),
        'WAKTU LUNAS' => $d['waktu_lunas'] ? $d['waktu_lunas'] : '-'
    ];
}

header('Content-Type: application/json');
echo json_encode($data_excel);
exit;
?>