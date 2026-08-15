<?php
session_start();
if(!isset($_SESSION['status']) || $_SESSION['status'] != "login"){ 
    header("Location: ../admin_login.php"); 
    exit; 
}
include '../koneksi.php';

// Menangkap payload JSON yang dikirim (misal via AJAX/Fetch API dari SheetJS)
if (isset($_POST['payload'])) {
    
    $data = json_decode($_POST['payload'], true);
    $berhasil = 0;
    $gagal = 0;

    // Siapkan Prepared Statement MySQLi
    // Menggunakan INSERT IGNORE agar baris dengan NIS yang sudah ada otomatis diabaikan (mencegah error duplikat)
    $query = "INSERT IGNORE INTO santri (
        nis, nama_santri, jenis_kelamin, tempat_lahir, tgl_lahir,
        alamat, desa, kecamatan, kab_kota, provinsi,
        nama_ayah, no_hp_ayah, nama_ibu, no_hp_ibu,
        asal_sekolah, sekolah_saat_ini, foto
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'default.jpg')";
    
    $stmt = mysqli_prepare($koneksi, $query);

    foreach ($data as $indeks => $baris) {
        
        // Lewati baris pertama jika itu adalah Judul Kolom (Header)
        if ($indeks == 0 && isset($baris[0]) && strtoupper($baris[0]) == 'NIS') {
            continue;
        }

        // Syarat Wajib: Index 0 (NIS) dan Index 1 (Nama) tidak boleh kosong
        if (!empty($baris[0]) && !empty($baris[1])) {
            
            // Logika Fallback (Isian opsional seperti yang Anda minta)
            $nis        = $baris[0];
            $nama       = $baris[1];
            
            // Default Laki-laki jika kosong
            $jk         = !empty($baris[2]) ? strtoupper($baris[2]) : 'L'; 
            
            // Kolom teks dibiarkan string kosong jika tidak diisi
            $tmp_lahir  = isset($baris[3]) ? $baris[3] : '';
            
            // Default hari ini jika kosong agar tidak error tipe DATE di database
            $tgl_lahir  = !empty($baris[4]) ? $baris[4] : date('Y-m-d'); 
            
            $alamat     = isset($baris[5]) ? $baris[5] : '';
            $desa       = isset($baris[6]) ? $baris[6] : '';
            $kecamatan  = isset($baris[7]) ? $baris[7] : '';
            $kab_kota   = isset($baris[8]) ? $baris[8] : '';
            $provinsi   = isset($baris[9]) ? $baris[9] : '';
            $ayah       = isset($baris[10]) ? $baris[10] : '';
            $hp_ayah    = isset($baris[11]) ? $baris[11] : '';
            $ibu        = isset($baris[12]) ? $baris[12] : '';
            $hp_ibu     = isset($baris[13]) ? $baris[13] : '';
            $asal       = isset($baris[14]) ? $baris[14] : '';
            $unit       = isset($baris[15]) ? $baris[15] : '';

            // Pasangkan variabel ke parameter (?, ?, ...)
            // 's' berarti String. Ada 16 parameter, jadi formatnya "ssssssssssssssss"
            mysqli_stmt_bind_param($stmt, "ssssssssssssssss",
                $nis, $nama, $jk, $tmp_lahir, $tgl_lahir,
                $alamat, $desa, $kecamatan, $kab_kota, $provinsi,
                $ayah, $hp_ayah, $ibu, $hp_ibu,
                $asal, $unit
            );

            // Eksekusi *Query*
            mysqli_stmt_execute($stmt);

            // Cek apakah data berhasil disisipkan (bukan data ganda yang di-ignore)
            if (mysqli_stmt_affected_rows($stmt) > 0) {
                $berhasil++;
            } else {
                $gagal++;
            }
        }
    }
    
    mysqli_stmt_close($stmt);

    // Mengembalikan pesan ke Frontend
    echo "IMPORT SELESAI!\n- Berhasil: $berhasil santri\n- Gagal/Duplikat: $gagal data";
} else {
    echo "Akses ditolak. Tidak ada payload data yang diterima.";
}
?>