<?php
session_start();
include 'koneksi.php';

if(isset($_POST['upload'])){
    $id_user = $_SESSION['id_pendaftar'];
    $allowed_ext = ['png', 'jpg', 'jpeg', 'pdf'];
    
    // Array untuk map input name ke kolom database
    $files = ['file_ktp', 'file_kk', 'file_akta', 'file_ijazah', 'file_nilai', 'file_prestasi'];

    foreach($files as $file_key){
        if(!empty($_FILES[$file_key]['name'])){
            $filename = $_FILES[$file_key]['name'];
            $ext      = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            
            if(in_array($ext, $allowed_ext)){
                $new_name = $file_key . "_" . $id_user . "_" . time() . "." . $ext;
                move_uploaded_file($_FILES[$file_key]['tmp_name'], 'berkas_psb/' . $new_name);
                
                // Update database per file
                mysqli_query($koneksi, "UPDATE psb_pendaftar SET $file_key = '$new_name' WHERE id = '$id_user'");
            }
        }
    }

    echo "<script>alert('Berkas berhasil diperbarui!'); window.location='portal_pendaftar.php';</script>";
}
?>