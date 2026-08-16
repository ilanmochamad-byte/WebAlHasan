<?php
require_once __DIR__ . '/_guard.php';

// Proses Upload File
if(isset($_POST['upload_file'])){
    $judul = $_POST['judul'];
    $filename = $_FILES['file']['name'];
    $rand = rand();
    $new_name = $rand.'_'.$filename;
    
    // Validasi file (Opsional: bisa ditambah)
    if($filename != ""){
        move_uploaded_file($_FILES['file']['tmp_name'], 'file_download/'.$new_name);
        mysqli_query($koneksi, "INSERT INTO download (judul, nama_file) VALUES ('$judul','$new_name')");
        echo "<script>alert('File Berhasil Diupload'); window.location='admin_download.php';</script>";
    }
}

// Proses Hapus
if(isset($_GET['hapus'])){
    $id = $_GET['hapus'];
    $file = $_GET['file'];
    unlink('file_download/'.$file);
    mysqli_query($koneksi, "DELETE FROM download WHERE id='$id'");
    echo "<script>window.location='admin_download.php';</script>";
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
        <h2 class="h2 mb-4">Kelola File Download</h2>

    <div class="container mt-4">
        <div class="row">
            <div class="col-md-4">
                <div class="card shadow">
                    <div class="card-header bg-primary text-white">Upload File (PDF/Doc)</div>
                    <div class="card-body">
                        <form method="POST" enctype="multipart/form-data">
                            <div class="mb-3">
                                <label>Judul File / Dokumen</label>
                                <input type="text" name="judul" class="form-control" placeholder="Misal: Brosur PSB 2026" required>
                            </div>
                            <div class="mb-3">
                                <label>Pilih File</label>
                                <input type="file" name="file" class="form-control" required>
                            </div>
                            <button type="submit" name="upload_file" class="btn btn-success w-100">Upload</button>
                        </form>
                    </div>
                </div>
            </div>
            
            <div class="col-md-8">
                <div class="card shadow">
                    <div class="card-header">Daftar File Download</div>
                    <div class="card-body">
                        <table class="table table-bordered table-striped">
                            <thead>
                                <tr>
                                    <th>No</th>
                                    <th>Judul Dokumen</th>
                                    <th>Link</th>
                                    <th>Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $no = 1;
                                $data = mysqli_query($koneksi, "SELECT * FROM download ORDER BY id DESC");
                                while($d = mysqli_fetch_array($data)){
                                ?>
                                <tr>
                                    <td><?php echo $no++; ?></td>
                                    <td><?php echo $d['judul']; ?></td>
                                    <td>
                                        <a href="file_download/<?php echo $d['nama_file']; ?>" target="_blank" class="btn btn-sm btn-info text-white">Download</a>
                                    </td>
                                    <td>
                                        <a href="admin_download.php?hapus=<?php echo $d['id']; ?>&file=<?php echo $d['nama_file']; ?>" class="btn btn-danger btn-sm" onclick="return confirm('Hapus?')"><i class="fas fa-trash"></i></a>
                                    </td>
                                </tr>
                                <?php } ?>
                            </tbody>
                        </table>
                    </div>
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
