<?php
require_once __DIR__ . '/_guard.php';

// Panggil library pembentuk Excel murni bebas error warning
require_once 'SimpleXLSXGen.php';

// Ambil Tahun Ajaran Aktif (Diperlukan jika admin memfilter berdasarkan kelas)
$q_tahun = mysqli_query($koneksi, "SELECT id FROM tahun_ajaran WHERE status='Aktif' LIMIT 1");
$t_aktif = mysqli_fetch_array($q_tahun);
$id_tahun = $t_aktif ? $t_aktif['id'] : 0;

// ==========================================
// LOGIKA PEMBENTUKAN FILTER QUERY EKSPOR
// ==========================================
$where = "WHERE 1=1";
$join = "";

if(!empty($_GET['jk'])) {
    $jk = mysqli_real_escape_string($koneksi, $_GET['jk']);
    $where .= " AND s.jenis_kelamin = '$jk'";
}
if(!empty($_GET['sekolah_saat_ini'])) {
    $unit = mysqli_real_escape_string($koneksi, $_GET['sekolah_saat_ini']);
    $where .= " AND s.sekolah_saat_ini = '$unit'";
}
if(!empty($_GET['kecamatan'])) {
    $kec = mysqli_real_escape_string($koneksi, $_GET['kecamatan']);
    $where .= " AND s.kecamatan LIKE '%$kec%'";
}
if(!empty($_GET['kab_kota'])) {
    $kab = mysqli_real_escape_string($koneksi, $_GET['kab_kota']);
    $where .= " AND s.kab_kota LIKE '%$kab%'";
}
if(!empty($_GET['id_kelas'])) {
    $id_kelas = (int)$_GET['id_kelas'];
    $join .= " LEFT JOIN plotting_kelas pk ON s.id = pk.id_santri AND pk.id_tahun = '$id_tahun' AND pk.status = 'Aktif' ";
    $where .= " AND pk.id_kelas = '$id_kelas'";
}

// Jalankan query dengan join kelas & kamar agar hasil ekspor memiliki data penempatan lengkap
$query_str = "SELECT s.*, k.nama_kelas, km.nama_kamar 
              FROM santri s 
              $join
              LEFT JOIN kelas k ON (SELECT id_kelas FROM plotting_kelas WHERE id_santri=s.id AND id_tahun='$id_tahun' AND status='Aktif' LIMIT 1) = k.id
              LEFT JOIN plotting_kamar pkm ON s.id = pkm.id_santri AND pkm.id_tahun = '$id_tahun'
              LEFT JOIN kamar km ON pkm.id_kamar = km.id
              $where ORDER BY s.nis ASC";

$query = mysqli_query($koneksi, $query_str);

// Susun susunan kolom Excel (Header baris pertama)
$data_excel = [
    ['NO', 'NIS', 'NAMA SANTRI', 'JENIS KELAMIN', 'UNIT SEKOLAH', 'KELAS', 'KAMAR', 'TEMPAT LAHIR', 'TANGGAL LAHIR', 'ALAMAT', 'DESA', 'KECAMATAN', 'KAB/KOTA', 'PROVINSI', 'NAMA AYAH', 'NO HP AYAH', 'NAMA IBU', 'NO HP IBU', 'ASAL SEKOLAH']
];

$no = 1;
while($d = mysqli_fetch_array($query)){
    
    // Inject apostrof agar angka 0 dan NIS panjang tidak pecah / terpotong oleh format cell Excel
    $nis_teks = "'" . $d['nis'];
    $hp_ayah  = "'" . $d['no_hp_ayah'];
    $hp_ibu   = "'" . $d['no_hp_ibu'];

    $data_excel[] = [
        $no++,
        $nis_teks,
        $d['nama_santri'],
        $d['jenis_kelamin'],
        $d['sekolah_saat_ini'],
        $d['nama_kelas'] ?: 'Belum Diplot',
        $d['nama_kamar'] ?: 'Belum Diplot',
        $d['tempat_lahir'],
        $d['tgl_lahir'],
        $d['alamat'],
        $d['desa'],
        $d['kecamatan'],
        $d['kab_kota'],
        $d['provinsi'],
        $d['nama_ayah'],
        $hp_ayah,
        $d['nama_ibu'],
        $hp_ibu,
        $d['asal_sekolah']
    ];
}

// Set penamaan berkas dinamis sesuai tanggal download
$nama_berkas = "Data_Filter_Santri_AlHasan_" . date('d-m-Y_H-i') . ".xlsx";

// Kirim file murni .xlsx langsung ke client browser
SimpleXLSXGen::fromArray($data_excel)->downloadAs($nama_berkas);
exit;
?>
