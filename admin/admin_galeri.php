<?php
require_once __DIR__ . '/_guard.php';

// Proses Upload & Hapus (Tetap sama seperti logika file Anda)
if(isset($_POST['upload_foto'])){
    $judul = $_POST['judul'];
    $ket   = $_POST['keterangan'];
    $filename = $_FILES['foto']['name'];
    $rand = rand();
    $new_name = $rand.'_'.$filename;
    
    if($filename != ""){
        move_uploaded_file($_FILES['foto']['tmp_name'], '../gambar_galeri/'.$new_name);
        mysqli_query($koneksi, "INSERT INTO galeri (judul, keterangan, gambar) VALUES ('$judul','$ket','$new_name')");
        echo "<script>alert('Foto Berhasil Diupload'); window.location='admin_galeri.php';</script>";
    }
}
if(isset($_GET['hapus'])){
    $id = $_GET['hapus'];
    $gbr = $_GET['file'];
    unlink('../gambar_galeri/'.$gbr);
    mysqli_query($koneksi, "DELETE FROM galeri WHERE id='$id'");
    echo "<script>window.location='admin_galeri.php';</script>";
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Admin Galeri</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body>

<header class="navbar navbar-dark sticky-top bg-success flex-md-nowrap p-0 shadow d-md-none">
    <a class="navbar-brand col-md-3 col-lg-2 me-0 px-3" href="#">Menu Admin</a>
    <button class="navbar-toggler position-absolute d-md-none collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#sidebarMenu">
        <span class="navbar-toggler-icon"></span>
    </button>
</header>

<div class="container-fluid">
    <div class="row">
        <?php include 'sidebar.php'; ?>

        <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4">
            <h2 class="h2 mb-4">Kelola Galeri Foto</h2>
            
            <div class="row">
                <div class="col-md-4">
                    <div class="card shadow mb-4">
                        <div class="card-header bg-primary text-white">Upload Foto Baru</div>
                        <div class="card-body">
                            <form method="POST" enctype="multipart/form-data">
                                <div class="mb-3">
                                    <label>Judul Foto</label>
                                    <input type="text" name="judul" class="form-control" required>
                                </div>
                                <div class="mb-3">
                                    <label>Keterangan</label>
                                    <textarea name="keterangan" class="form-control"></textarea>
                                </div>
                                <div class="mb-3">
                                    <label>File Foto</label>
                                    <input type="file" name="foto" class="form-control" required>
                                </div>
                                <button type="submit" name="upload_foto" class="btn btn-success w-100">Upload</button>
                            </form>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-8">
                    <div class="card shadow">
                        <div class="card-header">Data Galeri</div>
                        <div class="card-body">
                            <table class="table table-bordered">
                                thead><tr><th>Gambar</th><th>Info</th><th>Aksi</th></tr></thead>
                                <tbody>
                                    <?php
                                    $data = mysqli_query($koneksi, "SELECT * FROM galeri ORDER BY id DESC");
                                    while($d = mysqli_fetch_array($data)){
                                    ?>
                                    <tr>
                                        <td><img src="../gambar_galeri/<?php echo $d['gambar']; ?>" width="80"></td>
                                        <td><b><?php echo $d['judul']; ?></b><br><small><?php echo $d['keterangan']; ?></small></td>
                                        <td><a href="admin_galeri.php?hapus=<?php echo $d['id']; ?>&file=<?php echo $d['gambar']; ?>" class="btn btn-danger btn-sm" onclick="return confirm('Hapus?')"><i class="fas fa-trash"></i></a></td>
                                    </tr>
                                    <?php } ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
