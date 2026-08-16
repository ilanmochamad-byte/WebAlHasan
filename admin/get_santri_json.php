<?php
require_once __DIR__ . '/_guard.php';

// Ambil Tahun Ajaran Aktif
$q_tahun = mysqli_query($koneksi, "SELECT id FROM tahun_ajaran WHERE status='Aktif' LIMIT 1");
$tahun_aktif = mysqli_fetch_array($q_tahun);
$id_tahun = $tahun_aktif ? $tahun_aktif['id'] : 0;

$where = "WHERE 1=1";
if(!empty($_GET['jk'])) $where .= " AND s.jenis_kelamin = '$_GET[jk]'";
if(!empty($_GET['kecamatan'])) $where .= " AND s.kecamatan LIKE '%$_GET[kecamatan]%'";
if(!empty($_GET['kab_kota'])) $where .= " AND s.kab_kota LIKE '%$_GET[kab_kota]%'";
if(!empty($_GET['sekolah_saat_ini'])) $where .= " AND s.sekolah_saat_ini = '$_GET[sekolah_saat_ini]'";
if(!empty($_GET['id_kelas'])) $where .= " AND pk.id_kelas = '$_GET[id_kelas]'";

$query = "SELECT s.*, k.nama_kelas, km.nama_kamar 
          FROM santri s 
          LEFT JOIN plotting_kelas pk ON s.id = pk.id_santri AND pk.id_tahun = '$id_tahun'
          LEFT JOIN kelas k ON pk.id_kelas = k.id 
          LEFT JOIN plotting_kamar pkm ON s.id = pkm.id_santri AND pkm.id_tahun = '$id_tahun'
          LEFT JOIN kamar km ON pkm.id_kamar = km.id
          $where ORDER BY s.nis DESC";

$exec = mysqli_query($koneksi, $query);

$data_excel = [];
$no = 1;
while($d = mysqli_fetch_array($exec)){
    // Membangun array JSON yang langsung dimengerti oleh SheetJS
    $data_excel[] = [
        'NO' => $no++,
        'NIS' => $d['nis'],
        'NAMA SANTRI' => $d['nama_santri'],
        'L/P' => $d['jenis_kelamin'],
        'TEMPAT LAHIR' => $d['tempat_lahir'],
        'TGL LAHIR' => $d['tgl_lahir'],
        'ALAMAT LENGKAP' => $d['alamat'],
        'DESA' => $d['desa'],
        'KECAMATAN' => $d['kecamatan'],
        'KAB/KOTA' => $d['kab_kota'],
        'PROVINSI' => $d['provinsi'],
        'NAMA AYAH' => $d['nama_ayah'],
        'HP AYAH' => $d['no_hp_ayah'],
        'NAMA IBU' => $d['nama_ibu'],
        'HP IBU' => $d['no_hp_ibu'],
        'SEKOLAH ASAL' => $d['asal_sekolah'],
        'UNIT SEKARANG' => $d['sekolah_saat_ini'],
        'KELAS AKTIF' => $d['nama_kelas'] ?: 'Belum Diplot',
        'KAMAR AKTIF' => $d['nama_kamar'] ?: 'Belum Diplot'
    ];
}

// Berikan respon sebagai JSON mentah ke javascript
header('Content-Type: application/json');
echo json_encode($data_excel);
exit;
?>
