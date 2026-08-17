<?php
require_once __DIR__ . '/koneksi.php';
include 'header.php';
?>

<section class="py-5 bg-dark text-white" style="margin-top: 60px;">
    <div class="container text-center">
        <h1 class="fw-bold">Kabar Pesantren</h1>
        <p class="lead">Berita, Artikel, dan Informasi Terkini Seputar PP Al Hasan Ciamis</p>
    </div>
</section>

<section class="py-5 bg-light">
    <div class="container">
        
        <div class="row justify-content-center mb-5">
            <div class="col-md-6">
                <form action="berita.php" method="GET" class="d-flex shadow-sm rounded-pill bg-white overflow-hidden">
                    <input type="text" name="cari" class="form-control border-0 px-4 py-3" placeholder="Cari berita atau kegiatan..." value="<?php if(isset($_GET['cari'])){ echo $_GET['cari']; } ?>">
                    <button type="submit" class="btn btn-success px-4 fw-bold"><i class="fas fa-search"></i> Cari</button>
                </form>
            </div>
        </div>

        <div class="row g-4">
            <?php
            // Logika Pencarian
            if(isset($_GET['cari'])){
                $cari = mysqli_real_escape_string($koneksi, $_GET['cari']);
                // Cari berdasarkan Judul ATAU Isi Berita
                $query = "SELECT * FROM berita WHERE judul LIKE '%$cari%' OR isi_berita LIKE '%$cari%' ORDER BY id DESC";
            } else {
                // Jika tidak mencari, tampilkan semua berita
                $query = "SELECT * FROM berita ORDER BY id DESC";
            }

            $data = mysqli_query($koneksi, $query);
            $jumlah_berita = mysqli_num_rows($data);

            if($jumlah_berita > 0){
                while($b = mysqli_fetch_array($data)){
                    // Bersihkan tag HTML dari Summernote untuk tampilan preview
                    $isi_murni = strip_tags($b['isi_berita']);
                    // Potong teks jadi 150 karakter
                    $isi_pendek = substr($isi_murni, 0, 150) . "...";
            ?>
            
            <div class="col-md-4 d-flex align-items-stretch" data-aos="fade-up">
                <div class="card border-0 shadow-sm w-100 rounded-4 overflow-hidden hover-shadow transition">
                    <div style="height: 220px; overflow: hidden;">
                        <img src="gambar/<?php echo $b['gambar']; ?>" class="w-100 h-100" style="object-fit: cover; transition: 0.5s;" alt="<?php echo $b['judul']; ?>">
                    </div>
                    
                    <div class="card-body p-4 d-flex flex-column">
                        <div class="small text-muted mb-2">
                            <i class="far fa-calendar-alt me-2"></i> <?php echo date('d M Y', strtotime($b['tanggal'])); ?>
                        </div>
                        <h4 class="card-title fw-bold mb-3">
                            <a href="detail.php?id=<?php echo $b['id']; ?>" class="text-decoration-none text-dark hover-success">
                                <?php echo $b['judul']; ?>
                            </a>
                        </h4>
                        <p class="card-text text-muted small flex-grow-1">
                            <?php echo $isi_pendek; ?>
                        </p>
                        <div class="mt-3">
                            <a href="detail.php?id=<?php echo $b['id']; ?>" class="btn btn-outline-success rounded-pill w-100 fw-bold">Baca Selengkapnya</a>
                        </div>
                    </div>
                </div>
            </div>

            <?php 
                } // End While 
            } else { 
            ?>
                <div class="col-12 text-center py-5">
                    <img src="https://cdn-icons-png.flaticon.com/512/7486/7486754.png" width="150" class="mb-3 opacity-50">
                    <h3 class="text-muted">Berita tidak ditemukan</h3>
                    <p>Coba cari dengan kata kunci lain.</p>
                    <a href="berita.php" class="btn btn-secondary btn-sm">Reset Pencarian</a>
                </div>
            <?php } ?>
        </div>
    </div>
</section>

<style>
    .hover-shadow:hover {
        transform: translateY(-5px);
        box-shadow: 0 1rem 3rem rgba(0,0,0,.175)!important;
    }
    .hover-success:hover {
        color: #198754 !important; /* Warna Hijau Bootstrap */
    }
    .card:hover img {
        transform: scale(1.1);
    }
</style>

<?php include 'footer.php'; ?>
