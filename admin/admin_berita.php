<?php
session_start();
if($_SESSION['status'] != "login"){ header("Location: admin_login.php"); exit; }
include '../koneksi.php';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <title>Kelola Berita</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css">
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
            <h2 class="h2 mb-4">Kelola Berita Website</h2>

            <div class="card shadow border-0">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                    <h5 class="m-0 fw-bold text-success"><i class="fas fa-newspaper me-2"></i> Daftar Berita</h5>
                    <a href="tambah_berita.php" class="btn btn-success btn-sm"><i class="fas fa-plus"></i> Tulis Berita Baru</a>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover">
                            <thead class="table-light">
                                <tr>
                                    <th width="5%">No</th>
                                    <th width="15%">Gambar</th>
                                    <th>Judul Berita</th>
                                    <th>Tanggal</th>
                                    <th width="10%">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $no = 1;
                                $data = mysqli_query($koneksi, "SELECT * FROM berita ORDER BY id DESC");
                                while($d = mysqli_fetch_array($data)){
                                ?>
                                <tr>
                                    <td><?php echo $no++; ?></td>
                                    <td><img src="gambar/<?php echo $d['gambar']; ?>" width="100" class="img-thumbnail"></td>
                                    <td><?php echo $d['judul']; ?></td>
                                    <td><?php echo $d['tanggal']; ?></td>
                                    <td>
                                       <a href="edit_berita.php?id=<?php echo $d['id']; ?>" class="btn btn-primary btn-sm"><i class="fas fa-edit"></i></a>
                                       <a href="proses_berita.php?act=hapus&id=<?php echo $d['id']; ?>&gbr=<?php echo $d['gambar']; ?>" class="btn btn-danger btn-sm" onclick="return confirm('Hapus?')"><i class="fas fa-trash"></i></a>
                                    </td>
                                </tr>
                                <?php } ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </main>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>