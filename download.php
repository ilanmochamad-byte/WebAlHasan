<?php include 'header.php'; ?>
<?php include 'koneksi.php'; ?>

<section class="py-5 mt-5">
    <div class="container py-4">
        <div class="row justify-content-center">
            <div class="col-lg-8">
                <div class="text-center mb-5">
                    <h2 class="fw-bold">Download Area</h2>
                    <p class="text-muted">Unduh brosur, formulir manual, jadwal imsakiyah, dan dokumen resmi lainnya.</p>
                </div>

                <div class="card shadow border-0 rounded-4">
                    <div class="card-body p-0">
                        <div class="list-group list-group-flush rounded-4">
                            <?php
                            $data = mysqli_query($koneksi, "SELECT * FROM download ORDER BY id DESC");
                            if(mysqli_num_rows($data) > 0){
                                while($d = mysqli_fetch_array($data)){
                            ?>
                            
                            <div class="list-group-item p-4 d-flex justify-content-between align-items-center">
                                <div class="d-flex align-items-center">
                                    <div class="bg-light p-3 rounded-circle me-3 text-danger">
                                        <i class="fas fa-file-pdf fa-2x"></i>
                                    </div>
                                    <div>
                                        <h5 class="fw-bold mb-1"><?php echo $d['judul']; ?></h5>
                                        <small class="text-muted"><i class="fas fa-calendar-alt me-1"></i> Diupload: <?php echo date('d M Y', strtotime($d['tanggal'])); ?></small>
                                    </div>
                                </div>
                                <a href="file_download/<?php echo $d['nama_file']; ?>" class="btn btn-outline-success rounded-pill fw-bold px-4" download>
                                    <i class="fas fa-download me-2"></i> Unduh
                                </a>
                            </div>

                            <?php 
                                } 
                            } else {
                                echo "<div class='p-5 text-center text-muted'>Belum ada file yang diupload.</div>";
                            }
                            ?>
                        </div>
                    </div>
                </div>
                
                <div class="alert alert-info mt-4 d-flex align-items-center">
                    <i class="fas fa-info-circle fa-2x me-3"></i>
                    <div>
                        <strong>Butuh bantuan?</strong><br>
                        Jika Anda mengalami kendala saat mengunduh file, silakan hubungi admin melalui WhatsApp.
                    </div>
                </div>

            </div>
        </div>
    </div>
</section>

<?php include 'footer.php'; ?>