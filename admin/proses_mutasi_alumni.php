<?php
require_once __DIR__ . '/_guard.php';

// Ambil Tahun Ajaran Aktif
$q_tahun = mysqli_query($koneksi, "SELECT id FROM tahun_ajaran WHERE status='Aktif' LIMIT 1");
$t_aktif = mysqli_fetch_array($q_tahun);
$id_tahun = $t_aktif ? $t_aktif['id'] : 0;

// ==========================================
// 1. PROSES MUTASI MASSAL (PER KELAS)
// ==========================================
if(isset($_POST['bulk_mutasi'])){
    $id_kelas      = (int)$_POST['id_kelas'];
    $tahun         = mysqli_real_escape_string($koneksi, $_POST['tahun_angkatan']);
    $tingkat       = mysqli_real_escape_string($koneksi, $_POST['tingkat']);
    $status_keluar = mysqli_real_escape_string($koneksi, $_POST['status_keluar']);
    $tgl_keluar    = $_POST['tgl_keluar'];

    if($id_tahun == 0 || $id_kelas == 0){
        echo "<script>alert('Gagal: Kelas atau Tahun Ajaran Aktif tidak valid!'); window.location='admin_master_santri.php';</script>";
        exit;
    }

    // Ambil semua santri yang berada di kelas tersebut pada tahun ajaran aktif
    $q_santri = mysqli_query($koneksi, "SELECT s.* FROM plotting_kelas pk JOIN santri s ON pk.id_santri = s.id WHERE pk.id_kelas='$id_kelas' AND pk.id_tahun='$id_tahun'");
    
    if(mysqli_num_rows($q_santri) == 0) {
        echo "<script>alert('Gagal: Tidak ditemukan santri aktif di kelas tersebut pada tahun ajaran ini.'); window.location='admin_master_santri.php';</script>";
        exit;
    }

    $berhasil = 0;
    // Gunakan Prepared Statement agar eksekusi looping massal super cepat dan aman
    $query_insert = "INSERT IGNORE INTO alumni (
        nis, nama_santri, jenis_kelamin, tempat_lahir, tgl_lahir, 
        alamat, desa, kecamatan, kab_kota, provinsi, 
        nama_ayah, no_hp_ayah, nama_ibu, no_hp_ibu, 
        asal_sekolah, unit_terakhir, tahun_angkatan, tingkat, status_keluar, tgl_keluar, foto
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    
    $stmt = mysqli_prepare($koneksi, $query_insert);

    while($d = mysqli_fetch_array($q_santri)){
        $id_santri = $d['id'];
        
        mysqli_stmt_bind_param($stmt, "sssssssssssssssssssss", 
            $d['nis'], $d['nama_santri'], $d['jenis_kelamin'], $d['tempat_lahir'], $d['tgl_lahir'], 
            $d['alamat'], $d['desa'], $d['kecamatan'], $d['kab_kota'], $d['provinsi'], 
            $d['nama_ayah'], $d['no_hp_ayah'], $d['nama_ibu'], $d['no_hp_ibu'], 
            $d['asal_sekolah'], $d['sekolah_saat_ini'], $tahun, $tingkat, $status_keluar, $tgl_keluar, $d['foto']
        );

        if(mysqli_stmt_execute($stmt)){
            // Hapus dari santri aktif dan hapus seluruh plotting kelas & kamarnya
            mysqli_query($koneksi, "DELETE FROM santri WHERE id='$id_santri'");
            mysqli_query($koneksi, "DELETE FROM plotting_kelas WHERE id_santri='$id_santri'");
            mysqli_query($koneksi, "DELETE FROM plotting_kamar WHERE id_santri='$id_santri'");
            $berhasil++;
        }
    }
    mysqli_stmt_close($stmt);
    
    echo "<script>alert('SUKSES MUTASI MASSAL!\\nSebanyak $berhasil santri berhasil dipindahkan ke Database Alumni.'); window.location='admin_master_santri.php';</script>";
    exit;
}

// ==========================================
// 2. PROSES MUTASI SATU PER SATU (INDIVIDU)
// ==========================================
if(isset($_POST['mutasi'])){
    $id_santri     = (int)$_POST['id_santri'];
    $tahun         = mysqli_real_escape_string($koneksi, $_POST['tahun_angkatan']);
    $tingkat       = mysqli_real_escape_string($koneksi, $_POST['tingkat']);
    $status_keluar = mysqli_real_escape_string($koneksi, $_POST['status_keluar']);
    $tgl_keluar    = $_POST['tgl_keluar'];

    $q = mysqli_query($koneksi, "SELECT * FROM santri WHERE id='$id_santri'");
    $d = mysqli_fetch_array($q);

    if($d){
        $query_insert = "INSERT INTO alumni (
            nis, nama_santri, jenis_kelamin, tempat_lahir, tgl_lahir, 
            alamat, desa, kecamatan, kab_kota, provinsi, 
            nama_ayah, no_hp_ayah, nama_ibu, no_hp_ibu, 
            asal_sekolah, unit_terakhir, tahun_angkatan, tingkat, status_keluar, tgl_keluar, foto
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = mysqli_prepare($koneksi, $query_insert);
        mysqli_stmt_bind_param($stmt, "sssssssssssssssssssss", 
            $d['nis'], $d['nama_santri'], $d['jenis_kelamin'], $d['tempat_lahir'], $d['tgl_lahir'], 
            $d['alamat'], $d['desa'], $d['kecamatan'], $d['kab_kota'], $d['provinsi'], 
            $d['nama_ayah'], $d['no_hp_ayah'], $d['nama_ibu'], $d['no_hp_ibu'], 
            $d['asal_sekolah'], $d['sekolah_saat_ini'], $tahun, $tingkat, $status_keluar, $tgl_keluar, $d['foto']
        );

        if(mysqli_stmt_execute($stmt)){
            mysqli_query($koneksi, "DELETE FROM santri WHERE id='$id_santri'");
            mysqli_query($koneksi, "DELETE FROM plotting_kelas WHERE id_santri='$id_santri'");
            mysqli_query($koneksi, "DELETE FROM plotting_kamar WHERE id_santri='$id_santri'");
            echo "<script>alert('Berhasil: Santri telah dipindahkan ke Data Alumni!'); window.location='admin_master_santri.php';</script>";
        } else {
            echo "<script>alert('Gagal memindahkan data!'); window.location='admin_master_santri.php';</script>";
        }
        mysqli_stmt_close($stmt);
    }
} else {
    header("Location: admin_master_santri.php");
}
?>
