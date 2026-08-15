<?php include 'header.php'; ?>

<div class="container py-5 mt-5">
    <div class="row justify-content-center">
        <div class="col-md-5">
            <div class="card shadow-lg border-0 rounded-4" data-aos="fade-up">
                <div class="card-body p-5 text-center">
                    <div class="mb-4">
                        <i class="fas fa-user-circle fa-4x text-success"></i>
                        <h3 class="fw-bold mt-3">Portal Pendaftar</h3>
                        <p class="text-muted small">Masuk untuk melengkapi berkas pendaftaran</p>
                    </div>

                    <?php if(isset($_GET['pesan']) && $_GET['pesan'] == 'gagal') { ?>
                        <div class="alert alert-danger small">NISN atau Nomor HP salah!</div>
                    <?php } ?>

                    <form action="auth_pendaftar.php" method="POST">
                        <div class="mb-3 text-start">
                            <label class="form-label small fw-bold">Username (NISN)</label>
                            <input type="number" name="nisn" class="form-control" placeholder="Masukkan 10 digit NISN" required>
                        </div>
                        <div class="mb-4 text-start">
                            <label class="form-label small fw-bold">Password (No. HP Wali)</label>
                            <input type="text" name="hp" class="form-control" placeholder="Contoh: 0812xxx" required>
                        </div>
                        <button type="submit" class="btn btn-success w-100 fw-bold py-2 shadow">MASUK KE PORTAL</button>
                    </form>
                    
                    <div class="mt-4 pt-3 border-top">
                        <p class="small text-muted mb-0">Belum mendaftar? <a href="psb.php" class="text-success fw-bold">Daftar Sekarang</a></p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include 'footer.php'; ?>