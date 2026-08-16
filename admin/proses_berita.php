<?php
require_once __DIR__ . '/_guard.php';

$act = $_GET['act'];

// --- PROSES TAMBAH BERITA ---
if($act == "tambah"){
    // Gunakan real_escape_string agar tanda kutip dalam HTML editor tidak merusak query
    $judul = mysqli_real_escape_string($koneksi, $_POST['judul']);
    $isi   = mysqli_real_escape_string($koneksi, $_POST['isi']); // Ini sekarang berisi HTML
    $tgl   = date('Y-m-d');
    
    // Upload Gambar Utama (Thumbnail)
    $rand = rand();
    $filename = $_FILES['foto']['name'];
    
    if($filename != ""){
        $nama_gambar = $rand.'_'.$filename;
        move_uploaded_file($_FILES['foto']['tmp_name'], '../gambar/'.$nama_gambar);
        
        $query = "INSERT INTO berita (judul, isi_berita, gambar, tanggal) VALUES ('$judul', '$isi', '$nama_gambar', '$tgl')";
        
        if(mysqli_query($koneksi, $query)){
            header("Location: admin_berita.php");
        } else {
            echo "Gagal menyimpan: " . mysqli_error($koneksi);
        }
    }
}

// --- PROSES UPDATE BERITA ---
elseif($act == "update"){
    $id    = $_POST['id'];
    $judul = mysqli_real_escape_string($koneksi, $_POST['judul']);
    $isi   = mysqli_real_escape_string($koneksi, $_POST['isi']);
    $gbr_lama = $_POST['gbr_lama'];
    
    // Cek apakah user mengganti gambar?
    $filename = $_FILES['foto']['name'];
    
    if($filename != ""){
        // JIKA GANTI GAMBAR
        
        // 1. Upload gambar baru
        $rand = rand();
        $ext = pathinfo($filename, PATHINFO_EXTENSION);
        $nama_gambar_baru = $rand.'_'.$filename;
        move_uploaded_file($_FILES['foto']['tmp_name'], '../gambar/'.$nama_gambar_baru);
        
        // 2. Hapus gambar lama dari folder (supaya hemat penyimpanan)
        if(file_exists("gambar/".$gbr_lama)){
            unlink("gambar/".$gbr_lama);
        }
        
        // 3. Update database dengan gambar baru
        mysqli_query($koneksi, "UPDATE berita SET judul='$judul', isi_berita='$isi', gambar='$nama_gambar_baru' WHERE id='$id'");
        
    } else {
        // JIKA TIDAK GANTI GAMBAR (Hanya update teks)
        mysqli_query($koneksi, "UPDATE berita SET judul='$judul', isi_berita='$isi' WHERE id='$id'");
    }
    
    header("Location: admin_berita.php");
}

// --- PROSES HAPUS BERITA ---
elseif($act == "hapus"){
    $id = $_GET['id'];
    $gbr = $_GET['gbr'];
    
    // Hapus file gambar fisik di folder
    if(file_exists("gambar/".$gbr)){
        unlink("gambar/".$gbr);
    }
    
    // Hapus data di database
    mysqli_query($koneksi, "DELETE FROM berita WHERE id='$id'");
    header("Location: admin_berita.php");
}
?>
