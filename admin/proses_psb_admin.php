<?php
session_start();
if(!isset($_SESSION['status']) || $_SESSION['status'] != "login"){ header("Location: admin_login.php"); exit; }
include '../koneksi.php';

$act = $_GET['act'];
$id = (int)$_GET['id'];

// 1. FUNGSI UBAH STATUS (Diterima / Ditolak / Cadangan)
if($act == 'status'){
    $status = mysqli_real_escape_string($koneksi, $_GET['val']);
    mysqli_query($koneksi, "UPDATE psb_pendaftar SET status='$status' WHERE id='$id'");
    echo "<script>window.location='admin_data.php';</script>";
}

// =================================================================
// PROSES MIGRASI DATA PENDAFTAR KE MASTER SANTRI DENGAN NIS CUSTOM
// =================================================================
if($act == 'migrasi'){
    // (PENTING: Jangan deklarasikan session_start() dan include koneksi lagi di sini)
    
    // Ambil data pendaftar dari tabel PSB
    $q_psb = mysqli_query($koneksi, "SELECT * FROM psb_pendaftar WHERE id='$id'");
    $d = mysqli_fetch_assoc($q_psb);
    
    if($d){
        // 1. Ambil 2 Digit Tahun (Contoh: 2026 -> 26)
        $tahun = date('y'); 
        
        // 2. Tentukan Kode Unit (01 untuk SMP, 02 untuk Selain SMP)
        $kode_unit = ($d['jenjang_tujuan'] == 'SMP Terpadu Al Hasan') ? '01' : '02';
        
        // Gabungkan Prefix (Contoh: 2601)
        $prefix = $tahun . $kode_unit;
        
        // 3. Cari Nomor Urut Terakhir di Database berdasarkan Prefix tersebut
        $q_max = mysqli_query($koneksi, "SELECT max(nis) as maxKode FROM santri WHERE nis LIKE '$prefix%'");
        $d_max = mysqli_fetch_array($q_max);
        $nis_terakhir = $d_max['maxKode'];
        
        if($nis_terakhir){
            // Mengambil 3 karakter dari index ke-4 (contoh 2601001 -> ambil 001)
            $urutan = (int) substr($nis_terakhir, 4, 3);
            $urutan++;
        } else {
            $urutan = 1;
        }
        
        // 4. Rakit NIS Baru (Prefix + 3 Digit Urutan)
        $nis_baru = $prefix . sprintf("%03s", $urutan);

        // 5. ESCAPE STRING (MENCEGAH ERROR TANDA KUTIP PADA ALAMAT/NAMA/ASAL SEKOLAH)
        $nama        = mysqli_real_escape_string($koneksi, $d['nama_lengkap']);
        $jk          = mysqli_real_escape_string($koneksi, $d['jenis_kelamin']);
        $tempat      = mysqli_real_escape_string($koneksi, $d['tempat_lahir']);
        $tgl         = mysqli_real_escape_string($koneksi, $d['tgl_lahir']);
        $alamat      = mysqli_real_escape_string($koneksi, $d['alamat_jalan']);
        $desa        = mysqli_real_escape_string($koneksi, $d['desa']);
        $kecamatan   = mysqli_real_escape_string($koneksi, $d['kecamatan']);
        $kab_kota    = mysqli_real_escape_string($koneksi, $d['kab_kota']);
        $provinsi    = mysqli_real_escape_string($koneksi, $d['provinsi']);
        $nama_ayah   = mysqli_real_escape_string($koneksi, $d['nama_ayah']);
        $no_hp_ayah  = mysqli_real_escape_string($koneksi, $d['no_hp_wali']);
        $nama_ibu    = mysqli_real_escape_string($koneksi, $d['nama_ibu']);
        $no_hp_ibu   = mysqli_real_escape_string($koneksi, $d['no_hp_wali']);
        $asal_sekolah = mysqli_real_escape_string($koneksi, $d['sekolah_asal']);
        $tujuan      = mysqli_real_escape_string($koneksi, $d['jenjang_tujuan']);

        // 6. Masukkan ke tabel Master Santri
        $insert = mysqli_query($koneksi, "INSERT INTO santri 
            (nis, nama_santri, jenis_kelamin, tempat_lahir, tgl_lahir, alamat, desa, kecamatan, kab_kota, provinsi, nama_ayah, no_hp_ayah, nama_ibu, no_hp_ibu, asal_sekolah, sekolah_saat_ini, foto) 
            VALUES 
            ('$nis_baru', '$nama', '$jk', '$tempat', '$tgl', '$alamat', '$desa', '$kecamatan', '$kab_kota', '$provinsi', '$nama_ayah', '$no_hp_ayah', '$nama_ibu', '$no_hp_ibu', '$asal_sekolah', '$tujuan', 'default.jpg')");
        
        if($insert){
            // Ubah status menjadi 'Dimigrasi'
            mysqli_query($koneksi, "UPDATE psb_pendaftar SET status='Dimigrasi' WHERE id='$id'");
            echo "<script>alert('Migrasi Sukses! Santri mendapatkan NIS: $nis_baru'); window.location='admin_data.php';</script>";
        } else {
            // Tampilkan error SQL yang spesifik jika masih gagal
            $error_db = mysqli_error($koneksi);
            echo "<script>alert('Gagal Migrasi Data! DB Error: $error_db'); window.location='admin_data.php';</script>";
        }
    }
}
?>