<?php
include 'koneksi.php';

if (isset($_POST['daftar'])) {
    $nama        = mysqli_real_escape_string($koneksi, $_POST['nama_lengkap']);
    $nisn        = mysqli_real_escape_string($koneksi, $_POST['nisn']);
    $nik         = mysqli_real_escape_string($koneksi, $_POST['nik']);
    $tempat_lahir= mysqli_real_escape_string($koneksi, $_POST['tempat_lahir']);
    $tgl_lahir   = mysqli_real_escape_string($koneksi, $_POST['tgl_lahir']);
    $jk          = mysqli_real_escape_string($koneksi, $_POST['jenis_kelamin']);
    
    // Alamat Terpisah
    $alamat_jalan= mysqli_real_escape_string($koneksi, $_POST['alamat_jalan']);
    $provinsi    = mysqli_real_escape_string($koneksi, $_POST['nama_provinsi']);
    $kab_kota    = mysqli_real_escape_string($koneksi, $_POST['nama_kabupaten']);
    $kecamatan   = mysqli_real_escape_string($koneksi, $_POST['nama_kecamatan']);
    $desa        = mysqli_real_escape_string($koneksi, $_POST['nama_desa']);

    $ayah        = mysqli_real_escape_string($koneksi, $_POST['nama_ayah']);
    $ibu         = mysqli_real_escape_string($koneksi, $_POST['nama_ibu']);
    $hp          = mysqli_real_escape_string($koneksi, $_POST['no_hp_wali']);
    $sekolah     = mysqli_real_escape_string($koneksi, $_POST['sekolah_asal']);
    $jenjang     = mysqli_real_escape_string($koneksi, $_POST['jenjang_tujuan']);

    $tahun = date('Y');
    $random = rand(1000, 9999);
    $no_pendaftaran = "PSB-" . $tahun . "-" . $random;

    $query = "INSERT INTO psb_pendaftar 
              (no_pendaftaran, nama_lengkap, nisn, nik, tempat_lahir, tgl_lahir, jenis_kelamin, alamat_jalan, desa, kecamatan, kab_kota, provinsi, nama_ayah, nama_ibu, no_hp_wali, sekolah_asal, jenjang_tujuan)
              VALUES 
              ('$no_pendaftaran', '$nama', '$nisn', '$nik', '$tempat_lahir', '$tgl_lahir', '$jk', '$alamat_jalan', '$desa', '$kecamatan', '$kab_kota', '$provinsi', '$ayah', '$ibu', '$hp', '$sekolah', '$jenjang')";

    $result = mysqli_query($koneksi, $query);

    if ($result) {
        header("Location: psb.php?status=sukses&noreg=$no_pendaftaran");
    } else {
        header("Location: psb.php?status=gagal");
    }
} else {
    header("Location: psb.php");
}
?>