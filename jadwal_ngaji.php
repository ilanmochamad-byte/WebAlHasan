<?php 
include 'koneksi.php';
include 'header.php'; 

// Ambil Tahun Ajaran Aktif
$q_tahun = mysqli_query($koneksi, "SELECT id, tahun, semester FROM tahun_ajaran WHERE status='Aktif' LIMIT 1");
$tahun_aktif = mysqli_fetch_array($q_tahun);
$id_tahun = $tahun_aktif ? $tahun_aktif['id'] : 0;
?>

<style>
    /* CSS Kustom Untuk Tab Navigasi Waktu */
    .custom-pills {
        background-color: #f1f3f5;
        border-radius: 50px;
        padding: 8px;
        box-shadow: inset 0 2px 4px rgba(0,0,0,0.05); /* Bayangan ke dalam agar terlihat seperti wadah */
    }
    .custom-pills .nav-item {
        margin: 0 4px;
    }
    .custom-pills .nav-link {
        color: #495057; /* Warna teks tidak aktif yang JAUH LEBIH KONTRAS */
        font-weight: 700;
        border-radius: 50px;
        padding: 12px 20px;
        transition: all 0.3s ease;
        border: none;
        letter-spacing: 0.5px;
    }
    .custom-pills .nav-link:hover:not(.active) {
        color: #212529; /* Semakin gelap saat disorot mouse */
        background-color: #e2e6ea;
    }
    .custom-pills .nav-link.active {
        color: #ffffff !important;
        background-color: #198754; /* Hijau solid untuk tab aktif */
        box-shadow: 0 4px 12px rgba(25, 135, 84, 0.4); /* Efek timbul */
        transform: translateY(-2px); /* Sedikit naik agar terlihat diklik */
    }
    
    /* Desain Tabel Tambahan */
    .table-jadwal thead th {
        background-color: #198754;
        color: white;
        font-weight: 600;
        letter-spacing: 0.5px;
        border-bottom: none;
    }
    .table-jadwal tbody tr:hover {
        background-color: #f8fff9;
    }
</style>

<div class="container py-5 mt-5">
    <div class="row justify-content-center mb-5">
        <div class="col-lg-8 text-center" data-aos="fade-down">
            <h2 class="fw-bold text-success mb-2">Jadwal Pengajian Kitab Kuning</h2>
            <p class="text-muted fs-5">Pondok Pesantren Al Hasan Ciamis <br> <span class="badge bg-light text-success border border-success mt-2 px-3 py-2">Tahun Ajaran <?php echo $tahun_aktif ? $tahun_aktif['tahun'].' - Semester '.$tahun_aktif['semester'] : '-'; ?></span></p>
            <div class="divider mx-auto bg-success mt-3" style="height: 4px; width: 80px; border-radius: 5px;"></div>
        </div>
    </div>

    <div class="row" data-aos="fade-up" data-aos-delay="100">
        <div class="col-12">
            <div class="card shadow-lg border-0 rounded-4">
                <div class="card-body p-4 p-md-5">
                    
                    <ul class="nav nav-pills nav-fill mb-5 custom-pills" id="jadwalTabs" role="tablist">
                        <?php 
                        $waktu_list = ["Ba'da Shubuh", "Ba'da Ashar", "Ba'da Magrib", "Ba'da Isya"];
                        foreach($waktu_list as $index => $waktu) {
                            $active = ($index == 0) ? 'active' : '';
                            $id_tab = "tab-" . $index;
                            
                            // Ikon menyesuaikan waktu sholat agar lebih estetik
                            $icon = "";
                            if($index == 0) $icon = "fa-sun";         // Shubuh
                            else if($index == 1) $icon = "fa-cloud-sun"; // Ashar
                            else if($index == 2) $icon = "fa-moon";      // Magrib
                            else $icon = "fa-star";                      // Isya

                            echo "
                            <li class='nav-item' role='presentation'>
                                <button class='nav-link $active' id='$id_tab-tab' data-bs-toggle='tab' data-bs-target='#$id_tab' type='button' role='tab'>
                                    <i class='fas $icon me-2'></i> $waktu
                                </button>
                            </li>";
                        }
                        ?>
                    </ul>

                    <div class="tab-content" id="jadwalTabsContent">
                        <?php 
                        foreach($waktu_list as $index => $waktu) {
                            $active_pane = ($index == 0) ? 'show active' : '';
                            $id_tab = "tab-" . $index;
                        ?>
                        <div class="tab-pane fade <?php echo $active_pane; ?>" id="<?php echo $id_tab; ?>" role="tabpanel">
                            <div class="table-responsive rounded-3 shadow-sm border overflow-hidden">
                                <table class="table table-hover align-middle mb-0 table-jadwal">
                                    <thead class="text-center">
                                        <tr>
                                            <th class="py-3">Jam Pelaksanaan</th>
                                            <th class="py-3">Kelas / Jenjang</th>
                                            <th class="py-3">Fan Ilmu</th>
                                            <th class="py-3">Nama Kitab</th>
                                            <th class="py-3">Guru Pengajar</th>
                                            <th class="py-3">Tempat</th>
                                        </tr>
                                    </thead>
                                    <tbody class="border-top-0">
                                        <?php
                                        // Mengamankan kata "Ba'da" dari error SQL
                                        $waktu_aman = mysqli_real_escape_string($koneksi, $waktu);
                                        
                                        $q_jadwal = "SELECT j.*, g.nama_guru, k.nama_kelas 
                                                     FROM jadwal_ngaji j 
                                                     JOIN guru g ON j.id_guru = g.id 
                                                     JOIN kelas k ON j.id_kelas = k.id 
                                                     WHERE j.id_tahun = '$id_tahun' AND j.waktu_sholat = '$waktu_aman'
                                                     ORDER BY k.nama_kelas ASC";
                                        $exec = mysqli_query($koneksi, $q_jadwal);
                                        
                                        if(mysqli_num_rows($exec) > 0){
                                            while($row = mysqli_fetch_array($exec)){
                                        ?>
                                        <tr>
                                            <td class="text-center fw-bold text-secondary">
                                                <div class="bg-light rounded py-1 px-2 border d-inline-block">
                                                    <i class="far fa-clock me-1 text-success"></i> <?php echo $row['jam']; ?>
                                                </div>
                                            </td>
                                            <td class="text-center"><span class="badge bg-secondary px-3 py-2 rounded-pill"><?php echo $row['nama_kelas']; ?></span></td>
                                            <td class="fw-bold text-success fs-6"><?php echo $row['fan_ilmu']; ?></td>
                                            <td class="fst-italic text-dark fw-semibold"><?php echo $row['nama_kitab']; ?></td>
                                            <td class="text-dark"><i class="fas fa-chalkboard-teacher text-muted me-1"></i> <?php echo $row['nama_guru']; ?></td>
                                            <td class="text-center text-primary fw-bold"><i class="fas fa-map-marker-alt text-danger me-1"></i> <?php echo $row['tempat']; ?></td>
                                        </tr>
                                        <?php 
                                            }
                                        } else {
                                            echo "<tr><td colspan='6' class='text-center py-5 text-muted'>
                                                <i class='fas fa-folder-open fa-3x text-light mb-3 d-block'></i>
                                                Belum ada jadwal pengajian untuk waktu <b>$waktu</b>.
                                            </td></tr>";
                                        }
                                        ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <?php } ?>
                    </div>

                </div>
            </div>
        </div>
    </div>
</div>

<?php include 'footer.php'; ?>