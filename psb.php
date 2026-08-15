<?php include 'header.php'; ?>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">

<div class="container py-5 mt-5">
    <div class="row justify-content-center">
        <div class="col-lg-8">
            <div class="text-center mb-4" data-aos="fade-down">
                <span class="badge bg-success px-3 py-2 rounded-pill mb-2">Tahun Ajaran 2026/2027</span>
                <h2 class="fw-bold">Formulir Penerimaan Santri Baru</h2>
                <p class="text-muted">Silakan isi data diri dengan benar dan lengkap sesuai dokumen resmi (KK/Ijazah).</p>
            </div>

            <div class="alert alert-light border border-success shadow-sm rounded-4 mb-4 text-center" data-aos="fade-up">
                <p class="mb-2 text-dark">Sudah mendaftar sebelumnya dan ingin mengunggah berkas atau mencetak bukti?</p>
                <a href="login_pendaftar.php" class="btn btn-outline-success fw-bold rounded-pill px-4">
                    <i class="fas fa-sign-in-alt me-1"></i> Masuk Portal Pendaftar
                </a>
            </div>

            <div class="card shadow-lg border-0 rounded-4" data-aos="fade-up" data-aos-delay="100">
                <div class="card-body p-5">
                    
            <?php 
                if(isset($_GET['status'])) {
                if($_GET['status'] == 'sukses') {
                $noreg = $_GET['noreg'];
            echo "<div class='alert alert-success text-center'>
                <h4 class='alert-heading'><i class='fas fa-check-circle'></i> Pendaftaran Berhasil!</h4>
                <p>Data Anda telah tersimpan. Nomor Pendaftaran Anda: <strong class='fs-4'>$noreg</strong></p>
                <hr>
                <p class='mb-3'>Silakan unduh atau cetak bukti pendaftaran di bawah ini untuk dibawa ke sekretariat.</p>
                
                <a href='cetak_bukti.php?noreg=$noreg' target='_blank' class='btn btn-light fw-bold border border-success text-success'>
                    <i class='fas fa-print'></i> CETAK BUKTI PENDAFTARAN
                </a>
              </div>";
            } else if($_GET['status'] == 'gagal') {
                echo "<div class='alert alert-danger'><i class='fas fa-exclamation-circle'></i> Pendaftaran Gagal! NISN mungkin sudah terdaftar.</div>";
            }
            }
            ?>

                    <form action="proses_psb.php" method="POST">
                        <h5 class="text-success fw-bold mb-3"><i class="fas fa-user-graduate me-2"></i>Data Calon Santri</h5>
                        <div class="row g-3 mb-4">
                            <div class="col-md-12">
                                <label class="form-label">Nama Lengkap (Sesuai Ijazah)</label>
                                <input type="text" name="nama_lengkap" class="form-control" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">NISN</label>
                                <input type="number" name="nisn" class="form-control" required placeholder="10 digit angka">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">NIK</label>
                                <input type="number" name="nik" class="form-control" required placeholder="16 digit angka">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Tempat Lahir</label>
                                <input type="text" name="tempat_lahir" class="form-control" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Tanggal Lahir</label>
                                <input type="text" name="tgl_lahir" id="inputTanggal" class="form-control bg-white" required placeholder="Pilih Tanggal">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Jenis Kelamin</label>
                                <select name="jenis_kelamin" class="form-select" required>
                                    <option value="">-- Pilih --</option>
                                    <option value="L">Laki-laki</option>
                                    <option value="P">Perempuan</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Jenjang Tujuan</label>
                                <select name="jenjang_tujuan" class="form-select" required>
                                    <option value="">-- Pilih --</option>
                                    <option value="RA Terpadu Al Hasan">RA Terpadu Al Hasan</option>
                                    <option value="SMP Terpadu Al Hasan">SMP Terpadu Al Hasan</option>
                                    <option value="SMK Terpadu Al Hasan">SMK Terpadu Al Hasan</option>
                                    <option value="MAN 2 Ciamis">MAN 2 Ciamis</option>
                                    <option value="SMAN 1 Ciamis">SMAN 1 Ciamis</option>
                                    <option value="SMAN 2 Ciamis">SMAN 2 Ciamis</option>
                                    <option value="SMKN 1 Ciamis">SMKN 1 Ciamis</option>
                                    <option value="SMKN 2 Ciamis">SMKN 2 Ciamis</option>
                                </select>
                            </div>

                            <div class="col-12 mt-4"><h6 class="text-success fw-bold border-bottom pb-2">Alamat Domisili</h6></div>
                            <div class="col-md-6">
                                <label class="form-label small">Provinsi</label>
                                <select id="provinsi" class="form-select" required>
                                    <option value="">Pilih Provinsi...</option>
                                </select>
                                <input type="hidden" name="nama_provinsi" id="nama_provinsi">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small">Kabupaten / Kota</label>
                                <select id="kabupaten" class="form-select" disabled required>
                                    <option value="">Pilih Kabupaten...</option>
                                </select>
                                <input type="hidden" name="nama_kabupaten" id="nama_kabupaten">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small">Kecamatan</label>
                                <select id="kecamatan" class="form-select" disabled required>
                                    <option value="">Pilih Kecamatan...</option>
                                </select>
                                <input type="hidden" name="nama_kecamatan" id="nama_kecamatan">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small">Desa / Kelurahan</label>
                                <select id="desa" class="form-select" disabled required>
                                    <option value="">Pilih Desa...</option>
                                </select>
                                <input type="hidden" name="nama_desa" id="nama_desa">
                            </div>
                            <div class="col-12">
                                <label class="form-label small">Detail Jalan / RT / RW</label>
                                <textarea name="alamat_jalan" class="form-control" rows="2" placeholder="Nama Jalan/Dusun, Gg, RT/RW" required></textarea>
                            </div>
                            <div class="col-12 mt-4">
                                <label class="form-label text-success fw-bold">Asal Sekolah</label>
                                <input type="text" name="sekolah_asal" class="form-control" required placeholder="Contoh: SMPN 1 Ciamis">
                            </div>
                        </div>

                        <hr class="my-4">

                        <h5 class="text-success fw-bold mb-3"><i class="fas fa-users me-2"></i>Data Orang Tua / Wali</h5>
                        <div class="row g-3 mb-4">
                            <div class="col-md-6">
                                <label class="form-label">Nama Ayah</label>
                                <input type="text" name="nama_ayah" class="form-control" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Nama Ibu</label>
                                <input type="text" name="nama_ibu" class="form-control" required>
                            </div>
                            <div class="col-md-12">
                                <label class="form-label">Nomor WhatsApp Wali</label>
                                <input type="number" name="no_hp_wali" class="form-control" required placeholder="08xxxxxxxxxx">
                            </div>
                        </div>

                        <div class="d-grid gap-2">
                            <button type="submit" name="daftar" class="btn btn-warning btn-lg fw-bold text-white shadow">
                                <i class="fas fa-paper-plane me-2"></i> Kirim Pendaftaran
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include 'footer.php'; ?>

<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script src="https://npmcdn.com/flatpickr/dist/l10n/id.js"></script>

<script>
    // Inisialisasi Tanggal
    document.addEventListener("DOMContentLoaded", function() {
        flatpickr("#inputTanggal", { altInput: true, altFormat: "d-m-Y", dateFormat: "Y-m-d", locale: "id", maxDate: "today" });
        
        // Load Data Provinsi Pertama Kali
        fetch(`https://www.emsifa.com/api-wilayah-indonesia/api/provinces.json`)
            .then(response => response.json())
            .then(provinces => {
                let options = '<option value="">Pilih Provinsi...</option>';
                provinces.forEach(p => options += `<option value="${p.id}" data-name="${p.name}">${p.name}</option>`);
                document.getElementById('provinsi').innerHTML = options;
            });
    });

    // Event saat Provinsi dipilih -> Load Kabupaten
    document.getElementById('provinsi').addEventListener('change', function() {
        document.getElementById('nama_provinsi').value = this.options[this.selectedIndex].getAttribute('data-name');
        let id_prov = this.value;
        let kab = document.getElementById('kabupaten');
        
        document.getElementById('kecamatan').innerHTML = '<option value="">Pilih Kecamatan...</option>'; document.getElementById('kecamatan').disabled = true;
        document.getElementById('desa').innerHTML = '<option value="">Pilih Desa...</option>'; document.getElementById('desa').disabled = true;

        if(id_prov){
            kab.disabled = false; kab.innerHTML = '<option>Loading...</option>';
            fetch(`https://www.emsifa.com/api-wilayah-indonesia/api/regencies/${id_prov}.json`)
                .then(res => res.json())
                .then(regencies => {
                    let options = '<option value="">Pilih Kabupaten / Kota...</option>';
                    regencies.forEach(r => options += `<option value="${r.id}" data-name="${r.name}">${r.name}</option>`);
                    kab.innerHTML = options;
                });
        } else { kab.disabled = true; kab.innerHTML = '<option value="">Pilih Kabupaten...</option>'; }
    });

    // Event saat Kabupaten dipilih -> Load Kecamatan
    document.getElementById('kabupaten').addEventListener('change', function() {
        document.getElementById('nama_kabupaten').value = this.options[this.selectedIndex].getAttribute('data-name');
        let id_kab = this.value;
        let kec = document.getElementById('kecamatan');
        
        document.getElementById('desa').innerHTML = '<option value="">Pilih Desa...</option>'; document.getElementById('desa').disabled = true;

        if(id_kab){
            kec.disabled = false; kec.innerHTML = '<option>Loading...</option>';
            fetch(`https://www.emsifa.com/api-wilayah-indonesia/api/districts/${id_kab}.json`)
                .then(res => res.json())
                .then(districts => {
                    let options = '<option value="">Pilih Kecamatan...</option>';
                    districts.forEach(d => options += `<option value="${d.id}" data-name="${d.name}">${d.name}</option>`);
                    kec.innerHTML = options;
                });
        } else { kec.disabled = true; kec.innerHTML = '<option value="">Pilih Kecamatan...</option>'; }
    });

    // Event saat Kecamatan dipilih -> Load Desa
    document.getElementById('kecamatan').addEventListener('change', function() {
        document.getElementById('nama_kecamatan').value = this.options[this.selectedIndex].getAttribute('data-name');
        let id_kec = this.value;
        let desa = document.getElementById('desa');
        if(id_kec){
            desa.disabled = false; desa.innerHTML = '<option>Loading...</option>';
            fetch(`https://www.emsifa.com/api-wilayah-indonesia/api/villages/${id_kec}.json`)
                .then(res => res.json())
                .then(villages => {
                    let options = '<option value="">Pilih Desa / Kelurahan...</option>';
                    villages.forEach(v => options += `<option value="${v.id}" data-name="${v.name}">${v.name}</option>`);
                    desa.innerHTML = options;
                });
        } else { desa.disabled = true; desa.innerHTML = '<option value="">Pilih Desa...</option>'; }
    });

    // Event saat Desa dipilih -> Simpan Nama
    document.getElementById('desa').addEventListener('change', function() {
        document.getElementById('nama_desa').value = this.options[this.selectedIndex].getAttribute('data-name');
    });
</script>