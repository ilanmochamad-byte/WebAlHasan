<?php
include 'koneksi.php';
include 'header.php';

// --- LOGIKA SIMPAN KE DATABASE ---
if(isset($_POST['simpan_cetak'])){
    $no_pendaftaran = mysqli_real_escape_string($koneksi, $_POST['no_pendaftaran']);
    $syahriyyah = (int)$_POST['syahriyyah'];
    $infaq = (int)$_POST['infaq'];
    $seragam_psas = (int)$_POST['seragam_psas'];
    $seragam_pramuka = (int)$_POST['seragam_pramuka'];
    
    $kategori_biaya = $_POST['kategori_biaya'];
    $total_wajib = (int)$_POST['total_wajib'];
    $rincian_wajib = $_POST['rincian_wajib_json'];
    $metode_pembayaran = mysqli_real_escape_string($koneksi, $_POST['metode_pembayaran']);
    
    $total_keseluruhan = $syahriyyah + $infaq + $seragam_psas + $seragam_pramuka + $total_wajib;

    $query = "INSERT INTO psb_pembayaran (
                no_pendaftaran, kategori_biaya, syahriyyah, infaq, seragam_psas, seragam_pramuka, rincian_wajib, total_wajib, total_keseluruhan, metode_pembayaran
              ) VALUES (
                '$no_pendaftaran', '$kategori_biaya', '$syahriyyah', '$infaq', '$seragam_psas', '$seragam_pramuka', '$rincian_wajib', '$total_wajib', '$total_keseluruhan', '$metode_pembayaran'
              )
              ON DUPLICATE KEY UPDATE 
                syahriyyah='$syahriyyah', infaq='$infaq', seragam_psas='$seragam_psas', seragam_pramuka='$seragam_pramuka', rincian_wajib='$rincian_wajib', total_wajib='$total_wajib', total_keseluruhan='$total_keseluruhan', metode_pembayaran='$metode_pembayaran'";
    
    if(mysqli_query($koneksi, $query)){
        // PERBAIKAN: Karena form dikirim ke Tab Baru (target="_blank"), 
        // kita cukup alihkan tab baru ini ke halaman cetak kwitansi.
        echo "<script>window.location.href='cetak_biaya_psb.php?noreg=$no_pendaftaran';</script>";
        exit;
    } else {
        echo "<script>alert('Gagal menyimpan data: ".mysqli_error($koneksi)."');</script>";
    }
}
?>

<div class='container py-5 mt-5'>
    <div class="row justify-content-center mb-4">
        <div class="col-lg-8 text-center" data-aos="fade-down">
            <h2 class="fw-bold text-success">Rincian Pembiayaan Santri Baru</h2>
            <p class="text-muted">Masukkan Nomor Pendaftaran atau NIK Santri untuk melihat dan mengatur rincian biaya pendaftaran.</p>
        </div>
    </div>

    <div class="row justify-content-center mb-5" data-aos="fade-up">
        <div class="col-md-6">
            <div class="card shadow-sm border-success border-2 border-top-0 border-bottom-0 border-end-0">
                <div class="card-body p-4">
                    <form method="GET">
                        <label class="fw-bold mb-2">Cari Data Pendaftar</label>
                        <div class="input-group">
                            <input type="text" name="cari" class="form-control form-control-lg" placeholder="No. Pendaftaran / NIK" value="<?php echo isset($_GET['cari']) ? $_GET['cari'] : ''; ?>" required>
                            <button type="submit" class="btn btn-success px-4 fw-bold"><i class="fas fa-search me-1"></i> Cari</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <?php 
    if(isset($_GET['cari'])){
        $cari = mysqli_real_escape_string($koneksi, $_GET['cari']);
        $q = mysqli_query($koneksi, "SELECT * FROM psb_pendaftar WHERE no_pendaftaran='$cari' OR nik='$cari'");
        
        if(mysqli_num_rows($q) > 0){
            $d = mysqli_fetch_assoc($q);
            $no_pendaftaran = $d['no_pendaftaran'];
            
            $is_alumni = (stripos(strtolower($d['sekolah_asal']), 'smp terpadu al hasan') !== false);
            $unit = $d['jenjang_tujuan'];
            $jk = $d['jenis_kelamin'];
            $jk_teks = ($jk == 'L') ? 'Laki-laki' : 'Perempuan';

            $kategori_biaya = "";
            $s_default = 0; $i_default = 0; 
            $psas_default = 0; $pramuka_default = 0;
            $m_default = 'Cash';
            $wajib_items = [];

            if($unit == 'SMP Terpadu Al Hasan'){
                $kategori_biaya = "SMP Terpadu Al Hasan";
                $s_default = 800000; $i_default = 1000000;
                if($jk == 'L'){ $psas_default = 190000; $pramuka_default = 215000; } 
                else { $psas_default = 225000; $pramuka_default = 230000; }

                $wajib_items = ['Formulir Pendaftaran'=>150000, 'Administrasi Santri Baru'=>250000, 'Lemari'=>600000, 'Kitab'=>230000, 'Laundry (20 Kg)'=>100000, 'Biaya Ujian Tahunan'=>250000];
                if($jk == 'L') $wajib_items += ['Seragam Yayasan'=>165000, 'Batik SMP'=>85000, 'Seragam Olahraga'=>155000, 'JAS Almamater'=>250000, 'Seragam Muslim'=>150000, 'Sabuk PSAS & Pramuka'=>35000, 'Dasi'=>17500];
                else $wajib_items += ['Seragam Yayasan'=>170000, 'Batik SMP'=>85000, 'Seragam Olahraga'=>160000, 'JAS Almamater'=>250000, 'Seragam Muslimah'=>150000, 'Sabuk PSAS & Pramuka'=>35000, 'Kerudung'=>100000];
            
            } else if($unit == 'SMK Terpadu Al Hasan'){
                if($jk == 'L'){ $psas_default = 200000; $pramuka_default = 200000; } 
                else { $psas_default = 230000; $pramuka_default = 230000; }

                if($is_alumni){
                    $kategori_biaya = "SMK Terpadu Al Hasan (Alumni)";
                    $s_default = 600000; $i_default = 0;
                    $wajib_items = ['Administrasi Santri Baru'=>250000, 'Kitab'=>200000, 'Laundry'=>100000, 'Biaya Ujian Tahunan'=>250000, 'Seragam PDH SMK'=>231000, 'Seragam Olahraga'=>190000];
                } else {
                    $kategori_biaya = "SMK Terpadu Al Hasan (Baru)";
                    $s_default = 800000; $i_default = 1000000;
                    $wajib_items = ['Formulir Pendaftaran'=>150000, 'Administrasi Santri Baru'=>250000, 'Lemari'=>600000, 'Kitab'=>270000, 'Laundry (20 Kg)'=>100000, 'Biaya Ujian Tahunan'=>250000];
                    if($jk == 'L') $wajib_items += ['Seragam PDH SMK'=>231000, 'Seragam Olahraga'=>190000, 'JAS Almamater'=>250000, 'Seragam Muslim'=>150000];
                    else $wajib_items += ['Seragam PDH SMK'=>231000, 'Seragam Olahraga'=>190000, 'JAS Almamater'=>250000, 'Seragam Muslimah'=>150000];
                }
            
            } else { 
                if($is_alumni){
                    $kategori_biaya = "Tsanawi Non-SMK (Alumni)";
                    $s_default = 600000; $i_default = 0;
                    $wajib_items = ['Administrasi Santri Baru'=>250000, 'Kitab'=>200000, 'Laundry'=>100000, 'Biaya Ujian Tahunan'=>150000];
                } else {
                    $kategori_biaya = "Tsanawi Non-SMK (Baru)";
                    $s_default = 800000; $i_default = 1000000;
                    $wajib_items = ['Formulir Pendaftaran'=>150000, 'Administrasi Santri Baru'=>250000, 'Lemari'=>600000, 'Kitab'=>270000, 'Laundry (20 Kg)'=>100000, 'Biaya Ujian Tahunan'=>150000];
                    if($jk == 'L') $wajib_items += ['JAS Almamater'=>250000, 'Seragam Muslim'=>150000];
                    else $wajib_items += ['JAS Almamater'=>250000, 'Seragam Muslimah'=>150000];
                }
            }

            $q_cek = mysqli_query($koneksi, "SELECT * FROM psb_pembayaran WHERE no_pendaftaran='$no_pendaftaran'");
            if(mysqli_num_rows($q_cek) > 0){
                $d_saved = mysqli_fetch_assoc($q_cek);
                $s_default = $d_saved['syahriyyah'];
                $i_default = $d_saved['infaq'];
                $psas_default = $d_saved['seragam_psas'];
                $pramuka_default = $d_saved['seragam_pramuka'];
                $m_default = $d_saved['metode_pembayaran'];
            }
    ?>
    <div class="row justify-content-center" data-aos="fade-up">
        <div class="col-lg-10">
            <div class="card shadow-lg border-0 rounded-4 overflow-hidden">
                <div class="card-header bg-success text-white p-4">
                    <h5 class="mb-0 fw-bold"><i class="fas fa-file-invoice-dollar me-2"></i> Konfirmasi & Atur Pembiayaan</h5>
                </div>
                <div class="card-body p-4 p-md-5">
                    
                    <div class="row mb-4 bg-light p-3 rounded-3 border">
                        <div class="col-md-6">
                            <table class="table table-sm table-borderless mb-0">
                                <tr><td width="40%" class="text-muted">Nama Pendaftar</td><td class="fw-bold">: <?php echo $d['nama_lengkap']; ?></td></tr>
                                <tr><td class="text-muted">No. Pendaftaran</td><td class="fw-bold text-success">: <?php echo $d['no_pendaftaran']; ?></td></tr>
                                <tr><td class="text-muted">Jenis Kelamin</td><td class="fw-bold">: <?php echo $jk_teks; ?></td></tr>
                            </table>
                        </div>
                        <div class="col-md-6 border-start">
                            <table class="table table-sm table-borderless mb-0">
                                <tr><td width="40%" class="text-muted">Tujuan Unit</td><td class="fw-bold text-primary">: <?php echo $d['jenjang_tujuan']; ?></td></tr>
                                <tr><td class="text-muted">Asal Sekolah</td><td class="fw-bold">: <?php echo $d['sekolah_asal']; ?></td></tr>
                                <tr><td class="text-muted">Kategori Biaya</td><td class="fw-bold text-danger">: <?php echo $kategori_biaya; ?></td></tr>
                            </table>
                        </div>
                    </div>

                    <form method="POST" target="_blank" onsubmit="setTimeout(function(){ window.location.href='biaya_psb.php'; }, 1000);">
                        <input type="hidden" name="no_pendaftaran" value="<?php echo $no_pendaftaran; ?>">
                        <input type="hidden" name="kategori_biaya" value="<?php echo $kategori_biaya; ?>">
                        
                        <input type="hidden" name="total_wajib" id="inpTotalWajib" value="0">
                        <input type="hidden" name="rincian_wajib_json" id="inpRincianWajib" value="{}">

                        <div class="row g-4">
                            <div class="col-md-6">
                                <h5 class="fw-bold border-bottom pb-2 text-primary">Biaya Pilihan (Bisa Diatur/Angsur)</h5>
                                <p class="small text-muted mb-3">Nominal di bawah ini dapat disesuaikan (ketik ulang menjadi Rp 0 jika ingin ditangguhkan).</p>
                                
                                <div class="mb-3">
                                    <label class="fw-bold">Syahriyyah (Bulanan)</label>
                                    <div class="input-group">
                                        <span class="input-group-text bg-light">Rp</span>
                                        <input type="number" name="syahriyyah" id="inpSyahriyyah" class="form-control form-control-lg text-end fw-bold text-primary calc-biaya" value="<?php echo $s_default; ?>" required>
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <label class="fw-bold">Infaq Bangunan</label>
                                    <div class="input-group">
                                        <span class="input-group-text bg-light">Rp</span>
                                        <input type="number" name="infaq" id="inpInfaq" class="form-control form-control-lg text-end fw-bold text-primary calc-biaya" value="<?php echo $i_default; ?>" required>
                                    </div>
                                </div>

                                <?php if($unit == 'SMP Terpadu Al Hasan' || $unit == 'SMK Terpadu Al Hasan'): ?>
                                    <div class="mb-3">
                                        <label class="fw-bold">Seragam PSAS</label>
                                        <div class="input-group">
                                            <span class="input-group-text bg-light">Rp</span>
                                            <input type="number" name="seragam_psas" id="inpPSAS" class="form-control form-control-lg text-end fw-bold text-primary calc-biaya" value="<?php echo $psas_default; ?>" required>
                                        </div>
                                    </div>
                                    <div class="mb-3">
                                        <label class="fw-bold">Seragam Pramuka</label>
                                        <div class="input-group">
                                            <span class="input-group-text bg-light">Rp</span>
                                            <input type="number" name="seragam_pramuka" id="inpPramuka" class="form-control form-control-lg text-end fw-bold text-primary calc-biaya" value="<?php echo $pramuka_default; ?>" required>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <input type="hidden" name="seragam_psas" id="inpPSAS" value="0">
                                    <input type="hidden" name="seragam_pramuka" id="inpPramuka" value="0">
                                <?php endif; ?>

                                <h5 class="fw-bold border-bottom pb-2 mt-4 text-dark">Metode Pembayaran</h5>
                                <div class="mb-3">
                                    <select name="metode_pembayaran" id="pilihMetode" class="form-select form-select-lg fw-bold" required onchange="toggleTransferInfo()">
                                        <option value="Cash" <?php echo ($m_default == 'Cash') ? 'selected' : ''; ?>>Tunai (Cash / Bayar di Tempat)</option>
                                        <option value="Transfer" <?php echo ($m_default == 'Transfer') ? 'selected' : ''; ?>>Transfer Bank</option>
                                    </select>
                                    
                                    <div id="infoTransfer" class="alert alert-info mt-3" style="display: <?php echo ($m_default == 'Transfer') ? 'block' : 'none'; ?>;">
                                        <i class="fas fa-info-circle me-1"></i> <b>Informasi Transfer:</b><br>
                                        Silakan transfer nominal ke salah satu rekening berikut:<br>
                                        - <b>BSI:</b> 1046-322-987<br>a.n Yayasan Pendidikan Ponpes Al Hasan<br>
                                        - <b>BRI:</b> 0104-01001-218567<br>a.n Yayasan Pendidikan Pondok Pesantren Al Hasan<br>
                                        <small class="text-danger mt-1 d-block">*Harap bawa bukti transfer / cetak kwitansi ini ke ruang bendahara untuk di-validasi.</small>
                                    </div>
                                </div>
                            </div>

                            <div class="col-md-6 border-start">
                                <h5 class="fw-bold border-bottom pb-2 text-danger">Biaya Wajib (Centang yang akan dibayar)</h5>
                                <div class="table-responsive">
                                    <table class="table table-sm table-striped align-middle">
                                        <?php 
                                        $is_new = !isset($d_saved);
                                        $saved_wajib = $is_new ? [] : json_decode($d_saved['rincian_wajib'], true);
                                        
                                        foreach($wajib_items as $item => $harga): 
                                            // Centang otomatis jika ini pendaftar baru, atau jika item ini ada di database pembayaran sebelumnya
                                            $isChecked = ($is_new || isset($saved_wajib[$item])) ? 'checked' : '';
                                        ?>
                                        <tr>
                                            <td>
                                                <div class="form-check mb-0">
                                                    <input class="form-check-input calc-wajib border-danger" type="checkbox" value="<?php echo $item; ?>" data-harga="<?php echo $harga; ?>" id="chk_<?php echo md5($item); ?>" <?php echo $isChecked; ?> style="cursor: pointer; transform: scale(1.2); margin-top: 0.3em;">
                                                    <label class="form-check-label ms-2 fw-bold" for="chk_<?php echo md5($item); ?>" style="cursor: pointer;"><?php echo $item; ?></label>
                                                </div>
                                            </td>
                                            <td class="text-end fw-bold">Rp <?php echo number_format($harga,0,',','.'); ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                        <tr class="table-danger">
                                            <td class="fw-bold">TOTAL WAJIB DIBAYAR</td>
                                            <td class="text-end fw-bold fs-6" id="txtTotalWajib">Rp 0</td>
                                        </tr>
                                    </table>
                                </div>
                            </div>
                        </div>

                        <hr class="my-4">

                        <div class="row align-items-center bg-light p-3 rounded-4 border border-success">
                            <div class="col-md-6">
                                <h4 class="mb-0 text-muted">Total Yang Harus Dibayar:</h4>
                                <small class="text-success">*Bawalah cetakan kwitansi ke ruang bendahara.</small>
                            </div>
                            <div class="col-md-6 text-end">
                                <h2 class="mb-0 fw-bold text-success" id="txtTotalBayar">Rp 0</h2>
                            </div>
                        </div>

                        <div class="text-center mt-5">
                            <button type="submit" name="simpan_cetak" class="btn btn-success btn-lg px-5 fw-bold shadow-lg rounded-pill">
                                <i class="fas fa-print me-2"></i> Simpan & Cetak Kwitansi
                            </button>
                        </div>
                    </form>

                </div>
            </div>
        </div>
    </div>
    
    <script>
        function hitungTotal() {
            let s = parseInt(document.getElementById('inpSyahriyyah').value) || 0;
            let i = parseInt(document.getElementById('inpInfaq').value) || 0;
            let p = parseInt(document.getElementById('inpPSAS') ? document.getElementById('inpPSAS').value : 0) || 0;
            let pr = parseInt(document.getElementById('inpPramuka') ? document.getElementById('inpPramuka').value : 0) || 0;
            
            // Hitung Biaya Wajib dari Checkbox
            let totWajib = 0;
            let rincianWajib = {};
            document.querySelectorAll('.calc-wajib:checked').forEach(cb => {
                let harga = parseInt(cb.getAttribute('data-harga')) || 0;
                totWajib += harga;
                rincianWajib[cb.value] = harga;
            });

            // Update UI dan Hidden Inputs
            document.getElementById('txtTotalWajib').innerText = 'Rp ' + totWajib.toLocaleString('id-ID');
            document.getElementById('inpTotalWajib').value = totWajib;
            document.getElementById('inpRincianWajib').value = JSON.stringify(rincianWajib);

            // Hitung Grand Total
            let grandTotal = s + i + p + pr + totWajib;
            document.getElementById('txtTotalBayar').innerText = 'Rp ' + grandTotal.toLocaleString('id-ID');
        }

        function toggleTransferInfo() {
            let metode = document.getElementById('pilihMetode').value;
            document.getElementById('infoTransfer').style.display = (metode === 'Transfer') ? 'block' : 'none';
        }

        // Jalankan trigger saat input diketik atau checkbox di-klik
        document.querySelectorAll('.calc-biaya, .calc-wajib').forEach(item => {
            item.addEventListener('input', hitungTotal);
            item.addEventListener('change', hitungTotal);
        });
        
        window.onload = hitungTotal;
    </script>
    <?php 
        } else {
            echo "<div class='container'><div class='alert alert-danger text-center shadow-sm'><i class='fas fa-exclamation-triangle fa-2x mb-3 d-block'></i> Data Pendaftar tidak ditemukan. Periksa kembali Nomor Pendaftaran/NIK.</div></div>";
        }
    }
    ?>
</div>

<?php include 'footer.php'; ?>