<?php
session_start();
if(!isset($_SESSION['status']) || $_SESSION['status'] != "login"){ 
    header("Location: ../admin_login.php"); 
    exit; 
}
include '../koneksi.php';

$where = "WHERE 1=1";

if(!empty($_GET['jk'])) {
    $jk = mysqli_real_escape_string($koneksi, $_GET['jk']);
    $where .= " AND jenis_kelamin = '$jk'";
}
if(!empty($_GET['jenjang'])) {
    $jenjang = mysqli_real_escape_string($koneksi, $_GET['jenjang']);
    $where .= " AND jenjang_tujuan = '$jenjang'";
}
if(!empty($_GET['kecamatan'])) {
    $kec = mysqli_real_escape_string($koneksi, $_GET['kecamatan']);
    $where .= " AND kecamatan LIKE '%$kec%'";
}

$query = mysqli_query($koneksi, "SELECT * FROM psb_pendaftar $where ORDER BY id DESC");

$data_excel = [];
$no = 1;
while($d = mysqli_fetch_array($query)){
    $data_excel[] = [
        'NO' => $no++,
        'NO PENDAFTARAN' => $d['no_pendaftaran'],
        'NAMA LENGKAP' => $d['nama_lengkap'],
        'NISN' => (string)$d['nisn'],
        'NIK' => (string)$d['nik'],
        'JK' => $d['jenis_kelamin'],
        'TEMPAT LAHIR' => $d['tempat_lahir'],
        'TGL LAHIR' => $d['tgl_lahir'],
        'ALAMAT JALAN' => $d['alamat_jalan'],
        'DESA' => $d['desa'],
        'KECAMATAN' => $d['kecamatan'],
        'KAB/KOTA' => $d['kab_kota'],
        'PROVINSI' => $d['provinsi'],
        'NAMA AYAH' => $d['nama_ayah'],
        'NAMA IBU' => $d['nama_ibu'],
        'NO HP WALI' => (string)$d['no_hp_wali'],
        'ASAL SEKOLAH' => $d['sekolah_asal'],
        'JENJANG TUJUAN' => $d['jenjang_tujuan'],
        'STATUS' => $d['status']
    ];
}

// Kirimkan output sebagai JSON murni
header('Content-Type: application/json');
echo json_encode($data_excel);
exit;
?>