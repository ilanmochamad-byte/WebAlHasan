<?php
require_once __DIR__ . '/koneksi.php';
include 'header.php';
?>

<link href="https://cdnjs.cloudflare.com/ajax/libs/lightbox2/2.11.3/css/lightbox.min.css" rel="stylesheet" />

<style>
    /* Header Section */
    .gallery-header {
        background: linear-gradient(135deg, var(--primary-color), #0a3d25);
        padding: 100px 0 60px;
        color: white;
        border-radius: 0 0 50px 50px;
        margin-bottom: 40px;
    }

    /* Masonry Grid Item */
    .grid-item {
        width: 33.333%; /* Default 3 kolom di desktop */
        padding: 10px;
    }
    @media (max-width: 992px) { .grid-item { width: 50%; } } /* 2 kolom di tablet */
    @media (max-width: 576px) { .grid-item { width: 100%; } } /* 1 kolom di HP */

    /* Card Foto Modern */
    .photo-card {
        position: relative;
        overflow: hidden;
        border-radius: 15px;
        box-shadow: 0 5px 15px rgba(0,0,0,0.08);
        cursor: pointer;
    }
    
    /* Gambar */
    .photo-card img {
        width: 100%;
        height: auto;
        display: block;
        transition: transform 0.5s ease;
    }

    /* Overlay Gradient saat Hover */
    .photo-overlay {
        position: absolute;
        top: 0; left: 0; right: 0; bottom: 0;
        background: linear-gradient(to top, rgba(0,0,0,0.8) 0%, rgba(0,0,0,0) 60%);
        opacity: 0;
        transition: opacity 0.4s ease;
        display: flex;
        flex-direction: column;
        justify-content: flex-end;
        padding: 20px;
        color: white;
        z-index: 2;
    }
    
    /* Teks dalam Overlay */
    .photo-info {
        transform: translateY(20px);
        transition: transform 0.4s ease;
    }

    /* Efek Hover */
    .photo-card:hover img {
        transform: scale(1.08); /* Zoom in halus */
    }
    .photo-card:hover .photo-overlay {
        opacity: 1; /* Munculkan overlay */
    }
    .photo-card:hover .photo-info {
        transform: translateY(0); /* Teks naik ke atas */
    }

    /* Judul dan Keterangan */
    .photo-title { font-weight: 700; margin-bottom: 5px; font-size: 1.1rem; }
    .photo-desc { font-size: 0.85rem; opacity: 0.8; margin-bottom: 0; }
</style>


<section class="gallery-header text-center">
    <div class="container" data-aos="fade-down">
        <h1 class="fw-bold display-5">Galeri Pesantren</h1>
        <p class="lead opacity-75 w-75 mx-auto mb-0">Melihat lebih dekat aktivitas harian, fasilitas, dan momen-momen berharga di Pondok Pesantren Al Hasan Ciamis.</p>
    </div>
</section>

<section class="pb-5">
    <div class="container">
        <div class="row grid">
            <?php
            // Mengambil data terbaru
            $data = mysqli_query($koneksi, "SELECT * FROM galeri ORDER BY id DESC");
            
            // Cek ada data atau tidak
            if(mysqli_num_rows($data) > 0){
                while($d = mysqli_fetch_array($data)){
            ?>
            
            <div class="grid-item" data-aos="fade-up">
                <a href="gambar_galeri/<?php echo $d['gambar']; ?>" data-lightbox="galeri-utama" data-title="<strong><?php echo $d['judul']; ?></strong><br><?php echo $d['keterangan']; ?>">
                    <div class="photo-card">
                        <img src="gambar_galeri/<?php echo $d['gambar']; ?>" alt="<?php echo $d['judul']; ?>" loading="lazy">
                        
                        <div class="photo-overlay">
                            <div class="photo-info">
                                <h5 class="photo-title"><?php echo $d['judul']; ?></h5>
                                <?php if(!empty($d['keterangan'])) { ?>
                                    <p class="photo-desc"><i class="far fa-comment-alt me-1"></i> <?php echo substr($d['keterangan'], 0, 80) . (strlen($d['keterangan']) > 80 ? '...' : ''); ?></p>
                                <?php } ?>
                            </div>
                        </div>
                    </div>
                </a>
            </div>
            
            <?php 
                } // End While
            } else {
                // Jika Galeri Kosong
                echo '<div class="col-12 text-center py-5 text-muted">Belum ada foto yang diunggah di galeri.</div>';
            }
            ?>
            
        </div> </div>
</section>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/lightbox2/2.11.3/js/lightbox.min.js"></script>
<script src="https://unpkg.com/masonry-layout@4/dist/masonry.pkgd.min.js"></script>
<script src="https://unpkg.com/imagesloaded@5/imagesloaded.pkgd.min.js"></script>

<script>
    // Inisialisasi Masonry setelah semua gambar selesai diload
    $(document).ready(function() {
        var $grid = $('.grid').masonry({
            itemSelector: '.grid-item',
            percentPosition: true
        });
        
        // layout Masonry again after all images have loaded
        $grid.imagesLoaded().progress( function() {
            $grid.masonry('layout');
        });
    });

    // Config Lightbox agar menampilkan caption HTML
    lightbox.option({
      'resizeDuration': 200,
      'wrapAround': true,
      'showImageNumberLabel': false
    })
</script>

<?php include 'footer.php'; ?>
