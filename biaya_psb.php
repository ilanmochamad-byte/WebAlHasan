<?php
use App\Http\Csrf;
use App\Psb\BiayaPsb;
use App\Psb\PendaftarSesi;
use App\Psb\PsbAkses;
use App\Psb\PsbException;

require_once __DIR__ . '/koneksi.php';

// Workstream keamanan PSB: rincian biaya hanya untuk admin atau pendaftar
// pemilik yang sedang masuk Portal Pendaftar (sebelumnya cukup mengetahui
// No. Pendaftaran/NIK). Simpan lewat POST ber-CSRF; kategori, harga komponen
// wajib, dan total dihitung ulang di server dari katalog yang sama
// (`App\Psb\BiayaPsb`). Halaman tetap di alamat ini (P-7 belum diputuskan).
$akses = new PsbAkses($koneksi, new PendaftarSesi($koneksi));
$pesanBiaya = null;

// --- LOGIKA SIMPAN KE DATABASE ---
if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['simpan_cetak'])){
    if (!Csrf::validate($_POST['_csrf'] ?? null)) {
        $pesanBiaya = 'Halaman sudah kedaluwarsa. Rincian belum tersimpan; muat ulang halaman lalu simpan lagi.';
    } else {
        $target = $akses->cari(is_string($_POST['no_pendaftaran'] ?? null) ? $_POST['no_pendaftaran'] : '');
        if ($target === null || !$akses->boleh($target)) {
            PsbAkses::tolak(403);
        }
        try {
            BiayaPsb::simpan($koneksi, (string) $target['no_pendaftaran'], BiayaPsb::dariKiriman($target, $_POST));
            // Formulir dikirim ke tab baru: tab ini langsung membuka kwitansi.
            header('Location: cetak_biaya_psb.php?noreg=' . rawurlencode((string) $target['no_pendaftaran']), true, 303);
            exit;
        } catch (PsbException $exception) {
            $pesanBiaya = $exception->getMessage();
        }
    }
}

// --- DATA YANG BOLEH DITAMPILKAN ---
$cari = is_string($_GET['cari'] ?? null) ? trim($_GET['cari']) : null;
$d = null;
$tidakDitemukan = false;
$bukanMilik = false;
$pendaftarMasuk = $akses->pendaftar();
if ($akses->admin()) {
    if ($cari !== null && $cari !== '') {
        $d = $akses->cari($cari, true);
        $tidakDitemukan = $d === null;
    }
} elseif ($pendaftarMasuk !== null) {
    // Pendaftar hanya melihat rincian miliknya sendiri.
    if ($cari === null || $cari === '' || $cari === (string) $pendaftarMasuk['no_pendaftaran'] || $cari === (string) $pendaftarMasuk['nik']) {
        $d = $pendaftarMasuk;
    } else {
        $bukanMilik = true;
    }
}

include 'header.php';
?>

<div class='container py-5 mt-5'>
    <div class="row justify-content-center mb-4">
        <div class="col-lg-8 text-center" data-aos="fade-down">
            <h2 class="fw-bold text-success">Rincian Pembiayaan Santri Baru</h2>
            <p class="text-muted">Masukkan Nomor Pendaftaran atau NIK Santri untuk melihat dan mengatur rincian biaya pendaftaran.</p>
        </div>
    </div>

    <?php if ($pesanBiaya !== null) { ?>
        <div class="row justify-content-center"><div class="col-lg-10"><div class="alert alert-danger" role="alert"><?php echo ah_e($pesanBiaya); ?></div></div></div>
    <?php } ?>

    <?php if ($akses->admin()) { ?>
    <div class="row justify-content-center mb-5" data-aos="fade-up">
        <div class="col-md-6">
            <div class="card shadow-sm border-success border-2 border-top-0 border-bottom-0 border-end-0">
                <div class="card-body p-4">
                    <form method="GET">
                        <label class="fw-bold mb-2">Cari Data Pendaftar</label>
                        <div class="input-group">
                            <input type="text" name="cari" class="form-control form-control-lg" placeholder="No. Pendaftaran / NIK" value="<?php echo ah_e($cari ?? ''); ?>" required>
                            <button type="submit" class="btn btn-success px-4 fw-bold"><i class="fas fa-search me-1"></i> Cari</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <?php } elseif ($pendaftarMasuk === null) { ?>
    <div class="row justify-content-center mb-5" data-aos="fade-up">
        <div class="col-md-6">
            <div class="alert alert-info text-center shadow-sm">
                <i class="fas fa-lock fa-2x mb-3 d-block"></i>
                Rincian pembiayaan hanya dapat dibuka oleh pendaftar setelah masuk Portal Pendaftar, atau oleh panitia.
                <div class="mt-3"><a href="login_pendaftar.php" class="btn btn-success fw-bold rounded-pill px-4">Masuk Portal Pendaftar</a></div>
            </div>
        </div>
    </div>
    <?php } ?>

    <?php 
    if($bukanMilik){
        echo "<div class='container'><div class='alert alert-warning text-center shadow-sm'>Anda hanya dapat membuka rincian biaya pendaftaran Anda sendiri.</div></div>";
    }
    if($d !== null || $tidakDitemukan){
        
        if($d !== null){
            $no_pendaftaran = $d['no_pendaftaran'];
            
            $unit = $d['jenjang_tujuan'];
            $jk = $d['jenis_kelamin'];
            $jk_teks = ($jk == 'L') ? 'Laki-laki' : 'Perempuan';

            // Katalog biaya yang sama dengan sebelumnya, kini satu sumber di server.
            $katalog = BiayaPsb::katalog($d);
            $kategori_biaya = $katalog['kategori'];
            $s_default = $katalog['syahriyyah']; $i_default = $katalog['infaq'];
            $psas_default = $katalog['psas']; $pramuka_default = $katalog['pramuka'];
            $m_default = 'Cash';
            $wajib_items = $katalog['wajib'];

            $stmtCek = mysqli_prepare($koneksi, "SELECT * FROM psb_pembayaran WHERE no_pendaftaran=?");
            mysqli_stmt_bind_param($stmtCek, 's', $no_pendaftaran);
            mysqli_stmt_execute($stmtCek);
            $q_cek = mysqli_stmt_get_result($stmtCek);
            $lunas = false;
            if(mysqli_num_rows($q_cek) > 0){
                $d_saved = mysqli_fetch_assoc($q_cek);
                $lunas = $d_saved['status_pembayaran'] === 'Lunas';
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
                                <tr><td width="40%" class="text-muted">Nama Pendaftar</td><td class="fw-bold">: <?php echo ah_e($d['nama_lengkap']); ?></td></tr>
                                <tr><td class="text-muted">No. Pendaftaran</td><td class="fw-bold text-success">: <?php echo ah_e($d['no_pendaftaran']); ?></td></tr>
                                <tr><td class="text-muted">Jenis Kelamin</td><td class="fw-bold">: <?php echo $jk_teks; ?></td></tr>
                            </table>
                        </div>
                        <div class="col-md-6 border-start">
                            <table class="table table-sm table-borderless mb-0">
                                <tr><td width="40%" class="text-muted">Tujuan Unit</td><td class="fw-bold text-primary">: <?php echo ah_e($d['jenjang_tujuan']); ?></td></tr>
                                <tr><td class="text-muted">Asal Sekolah</td><td class="fw-bold">: <?php echo ah_e($d['sekolah_asal']); ?></td></tr>
                                <tr><td class="text-muted">Kategori Biaya</td><td class="fw-bold text-danger">: <?php echo ah_e($kategori_biaya); ?></td></tr>
                            </table>
                        </div>
                    </div>

                    <form method="POST" target="_blank" onsubmit="setTimeout(function(){ window.location.href='biaya_psb.php'; }, 1000);">
                        <?php echo Csrf::input(); ?>
                        <input type="hidden" name="no_pendaftaran" value="<?php echo ah_e($no_pendaftaran); ?>">
                        <input type="hidden" name="kategori_biaya" value="<?php echo ah_e($kategori_biaya); ?>">
                        
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
                                        <input type="number" name="syahriyyah" id="inpSyahriyyah" class="form-control form-control-lg text-end fw-bold text-primary calc-biaya" value="<?php echo ah_e($s_default); ?>" required>
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <label class="fw-bold">Infaq Bangunan</label>
                                    <div class="input-group">
                                        <span class="input-group-text bg-light">Rp</span>
                                        <input type="number" name="infaq" id="inpInfaq" class="form-control form-control-lg text-end fw-bold text-primary calc-biaya" value="<?php echo ah_e($i_default); ?>" required>
                                    </div>
                                </div>

                                <?php if($unit == 'SMP Terpadu Al Hasan' || $unit == 'SMK Terpadu Al Hasan'): ?>
                                    <div class="mb-3">
                                        <label class="fw-bold">Seragam PSAS</label>
                                        <div class="input-group">
                                            <span class="input-group-text bg-light">Rp</span>
                                            <input type="number" name="seragam_psas" id="inpPSAS" class="form-control form-control-lg text-end fw-bold text-primary calc-biaya" value="<?php echo ah_e($psas_default); ?>" required>
                                        </div>
                                    </div>
                                    <div class="mb-3">
                                        <label class="fw-bold">Seragam Pramuka</label>
                                        <div class="input-group">
                                            <span class="input-group-text bg-light">Rp</span>
                                            <input type="number" name="seragam_pramuka" id="inpPramuka" class="form-control form-control-lg text-end fw-bold text-primary calc-biaya" value="<?php echo ah_e($pramuka_default); ?>" required>
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
                                                    <input class="form-check-input calc-wajib border-danger" type="checkbox" name="wajib[]" value="<?php echo ah_e($item); ?>" data-harga="<?php echo $harga; ?>" id="chk_<?php echo md5($item); ?>" <?php echo $isChecked; ?> style="cursor: pointer; transform: scale(1.2); margin-top: 0.3em;">
                                                    <label class="form-check-label ms-2 fw-bold" for="chk_<?php echo md5($item); ?>" style="cursor: pointer;"><?php echo ah_e($item); ?></label>
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
                            <?php if ($lunas) { ?>
                                <div class="alert alert-success">Pembayaran sudah ditandai <b>lunas</b> oleh bendahara. Rincian tidak dapat diubah lagi; hubungi panitia PSB bila perlu koreksi.</div>
                                <a href="cetak_biaya_psb.php?noreg=<?php echo ah_e(rawurlencode((string) $no_pendaftaran)); ?>" target="_blank" class="btn btn-success btn-lg px-5 fw-bold shadow-lg rounded-pill"><i class="fas fa-print me-2"></i> Cetak Kwitansi</a>
                            <?php } else { ?>
                            <button type="submit" name="simpan_cetak" class="btn btn-success btn-lg px-5 fw-bold shadow-lg rounded-pill">
                                <i class="fas fa-print me-2"></i> Simpan & Cetak Kwitansi
                            </button>
                            <?php } ?>
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