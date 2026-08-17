<?php
require_once __DIR__ . '/koneksi.php';
include 'header.php';

$id = $_GET['id'];
$query = mysqli_query($koneksi, "SELECT * FROM berita WHERE id='$id'");
$data = mysqli_fetch_array($query);
?>

<div class="container py-5 mt-5">
    <div class="row justify-content-center">
        <div class="col-lg-8">
            <a href="index.php" class="btn btn-light mb-4 shadow-sm"><i class="fas fa-arrow-left"></i> Kembali ke Beranda</a>
            
            <?php if(isset($data['judul'])) { ?>
                <h1 class="fw-bold mb-3"><?php echo $data['judul']; ?></h1>
                <p class="text-muted"><i class="far fa-calendar-alt me-2"></i> Diposting pada: <?php echo date('d F Y', strtotime($data['tanggal'])); ?></p>
                
                <img src="gambar/<?php echo $data['gambar']; ?>" class="img-fluid rounded-4 shadow mb-4 w-100" alt="<?php echo $data['judul']; ?>">
                
                <div class="content-text lh-lg mb-5" style="font-size: 1.1rem; text-align: justify;">
                    <?php echo $data['isi_berita']; ?>
                </div>

                <hr class="my-5">
                <p class="fw-bold text-center">Bagikan artikel ini:</p>
                <div class="d-flex justify-content-center gap-2">
                    <a href="https://wa.me/?text=<?php echo $data['judul']; ?> - Baca selengkapnya di website Al Hasan" target="_blank" class="btn btn-success rounded-pill"><i class="fab fa-whatsapp"></i> WhatsApp</a>
                    <a href="#" class="btn btn-primary rounded-pill"><i class="fab fa-facebook"></i> Facebook</a>
                </div>

            <?php } else { ?>
                <div class="alert alert-warning">Berita tidak ditemukan.</div>
            <?php } ?>
        </div>
    </div>
</div>

<?php include 'footer.php'; ?>
