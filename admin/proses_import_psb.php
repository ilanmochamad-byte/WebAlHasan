<?php
session_start();
if(!isset($_SESSION['status']) || $_SESSION['status'] != "login"){ 
    header("Location: ../admin_login.php"); 
    exit; 
}
include '../koneksi.php';

if (isset($_POST['payload'])) {
    $data = json_decode($_POST['payload'], true);
    $berhasil = 0;
    $gagal = 0;
    
    // Gunakan try-catch agar error baris tertentu tidak mematikan seluruh sistem
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

    $query = "INSERT INTO psb_pendaftar (
        no_pendaftaran, nama_lengkap, nisn, nik, jenis_kelamin, tempat_lahir, tgl_lahir,
        alamat_jalan, desa, kecamatan, kab_kota, provinsi,
        nama_ayah, nama_ibu, no_hp_wali, sekolah_asal, jenjang_tujuan, status
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Baru')";
    
    $stmt = mysqli_prepare($koneksi, $query);

    foreach ($data as $indeks => $baris) {
        // Lewati baris judul (Header Excel)
        if ($indeks == 0) continue;

        if (!empty($baris[0])) {
            
            // Nomor Pendaftaran: PSB + Tahun + Indeks + Random
            $no_pendaftaran = "PSB" . date('Y') . str_pad($indeks, 3, '0', STR_PAD_LEFT) . rand(10, 99);
            
            $nama       = trim($baris[0]);
            
            // PENTING: Handling NISN Kosong agar tidak kena error "Duplicate Entry" di MySQL
            $nisn       = trim(isset($baris[1]) ? $baris[1] : '');
            if(empty($nisn) || $nisn == '-') {
                $nisn = 'TMP' . date('ymd') . rand(1000, 9999) . $indeks; 
            }

            $nik        = trim(isset($baris[2]) ? $baris[2] : '-');
            $jk         = (!empty($baris[3]) && strtoupper(trim($baris[3])) == 'P') ? 'P' : 'L';
            $tmp_lahir  = trim(isset($baris[4]) ? $baris[4] : '');
            
            // Tgl Lahir Handling (Memaksa konversi tanggal Excel ke format MySQL)
            $raw_tgl    = trim(isset($baris[5]) ? $baris[5] : '');
            $tgl_lahir  = (!empty($raw_tgl)) ? date('Y-m-d', strtotime($raw_tgl)) : date('Y-m-d');
            
            $alamat     = trim(isset($baris[6]) ? $baris[6] : '');
            $desa       = trim(isset($baris[7]) ? $baris[7] : '');
            $kecamatan  = trim(isset($baris[8]) ? $baris[8] : '');
            $kab_kota   = trim(isset($baris[9]) ? $baris[9] : '');
            $provinsi   = trim(isset($baris[10]) ? $baris[10] : '');
            $ayah       = trim(isset($baris[11]) ? $baris[11] : '');
            $ibu        = trim(isset($baris[12]) ? $baris[12] : '');
            $hp_wali    = trim(isset($baris[13]) ? $baris[13] : '');
            $asal       = trim(isset($baris[14]) ? $baris[14] : '');
            $jenjang    = trim(isset($baris[15]) ? $baris[15] : '-');

            try {
                mysqli_stmt_bind_param($stmt, "sssssssssssssssss",
                    $no_pendaftaran, $nama, $nisn, $nik, $jk, $tmp_lahir, $tgl_lahir,
                    $alamat, $desa, $kecamatan, $kab_kota, $provinsi,
                    $ayah, $ibu, $hp_wali, $asal, $jenjang
                );

                mysqli_stmt_execute($stmt);
                $berhasil++;
            } catch (Exception $e) {
                // Jika terjadi error pada baris ini, abaikan dan lanjut ke santri berikutnya
                $gagal++;
            }
        }
    }
    
    mysqli_stmt_close($stmt);
    echo "IMPORT PSB SELESAI!\n- Data Masuk: $berhasil Pendaftar\n- Gagal/Lewat: $gagal Data";
} else {
    echo "Akses ditolak.";
}
?>