<?php
session_start();
if($_SESSION['status'] != "login"){ header("Location: admin_login.php"); exit; }
?>
<!DOCTYPE html>
<html>
<head>
    <title>Tulis Berita - Editor Mode</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
    <link href="https://cdn.jsdelivr.net/npm/summernote@0.8.18/dist/summernote-lite.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/summernote@0.8.18/dist/summernote-lite.min.js"></script>
</head>
<body class="bg-light">
    <div class="container mt-5 mb-5" style="max-width: 900px;">
        <div class="card shadow">
            <div class="card-header bg-success text-white">
                <h5 class="m-0">Tulis Berita Baru</h5>
            </div>
            <div class="card-body">
                <form action="proses_berita.php?act=tambah" method="post" enctype="multipart/form-data">
                    
                    <div class="mb-3">
                        <label class="fw-bold mb-1">Judul Berita</label>
                        <input type="text" name="judul" class="form-control form-control-lg" placeholder="Masukkan judul berita..." required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="fw-bold mb-1">Gambar Utama (Thumbnail)</label>
                        <input type="file" name="foto" class="form-control" required>
                        <small class="text-muted">Gambar ini akan muncul di halaman depan website.</small>
                    </div>
                    
                    <div class="mb-3">
                        <label class="fw-bold mb-1">Isi Berita</label>
                        <textarea id="summernote" name="isi" required></textarea>
                    </div>

                    <hr>
                    <div class="d-flex justify-content-between">
                        <a href="admin_berita.php" class="btn btn-secondary px-4">Batal</a>
                        <button type="submit" class="btn btn-success px-4 fw-bold">Posting Berita</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
      $('#summernote').summernote({
        placeholder: 'Tulis isi berita disini... Anda bisa menyisipkan gambar langsung (drag & drop).',
        tabsize: 2,
        height: 400, // Tinggi editor
        toolbar: [
          ['style', ['style']],
          ['font', ['bold', 'underline', 'clear']],
          ['color', ['color']],
          ['para', ['ul', 'ol', 'paragraph']],
          ['table', ['table']],
          ['insert', ['link', 'picture', 'video']], // Fitur Gambar & Video
          ['view', ['fullscreen', 'codeview', 'help']]
        ]
      });
    </script>
</body>
</html>