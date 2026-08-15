<?php
session_start();
if($_SESSION['status'] != "login"){ header("Location: admin_login.php"); exit; }
include '../koneksi.php';

// Ambil ID dari URL
$id = $_GET['id'];
// Ambil data berita berdasarkan ID
$query = mysqli_query($koneksi, "SELECT * FROM berita WHERE id='$id'");
$d = mysqli_fetch_array($query);
?>

<!DOCTYPE html>
<html>
<head>
    <title>Edit Berita</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
    <link href="https://cdn.jsdelivr.net/npm/summernote@0.8.18/dist/summernote-lite.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/summernote@0.8.18/dist/summernote-lite.min.js"></script>
</head>
<body class="bg-light">
    <div class="container mt-5 mb-5" style="max-width: 900px;">
        <div class="card shadow">
            <div class="card-header bg-primary text-white">
                <h5 class="m-0">Edit Berita</h5>
            </div>
            <div class="card-body">
                <form action="proses_berita.php?act=update" method="post" enctype="multipart/form-data">
                    
                    <input type="hidden" name="id" value="<?php echo $d['id']; ?>">
                    <input type="hidden" name="gbr_lama" value="<?php echo $d['gambar']; ?>">

                    <div class="mb-3">
                        <label class="fw-bold mb-1">Judul Berita</label>
                        <input type="text" name="judul" class="form-control form-control-lg" value="<?php echo $d['judul']; ?>" required>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-md-4">
                            <label class="fw-bold mb-1">Gambar Saat Ini</label><br>
                            <img src="gambar/<?php echo $d['gambar']; ?>" class="img-thumbnail" style="width: 100%; height: 150px; object-fit: cover;">
                        </div>
                        <div class="col-md-8">
                            <label class="fw-bold mb-1">Ganti Gambar (Opsional)</label>
                            <input type="file" name="foto" class="form-control">
                            <small class="text-muted">Biarkan kosong jika tidak ingin mengubah gambar thumbnail.</small>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="fw-bold mb-1">Isi Berita</label>
                        <textarea id="summernote" name="isi" required><?php echo $d['isi_berita']; ?></textarea>
                    </div>

                    <hr>
                    <div class="d-flex justify-content-between">
                        <a href="admin_berita.php" class="btn btn-secondary px-4">Batal</a>
                        <button type="submit" class="btn btn-primary px-4 fw-bold">Simpan Perubahan</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
      $('#summernote').summernote({
        placeholder: 'Tulis isi berita disini...',
        tabsize: 2,
        height: 400,
        toolbar: [
          ['style', ['style']],
          ['font', ['bold', 'underline', 'clear']],
          ['color', ['color']],
          ['para', ['ul', 'ol', 'paragraph']],
          ['table', ['table']],
          ['insert', ['link', 'picture', 'video']],
          ['view', ['fullscreen', 'codeview', 'help']]
        ]
      });
    </script>
</body>
</html>