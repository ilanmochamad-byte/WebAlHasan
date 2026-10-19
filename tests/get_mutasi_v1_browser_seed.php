<?php
declare(strict_types=1);

// Additive synthetic fixture for tests/browser/uji-get-mutasi-v1.mjs.
if (PHP_SAPI !== 'cli' || getenv('GETMUTASI_RUN_TESTS') !== '1') exit(77);
require_once dirname(__DIR__) . '/app/bootstrap.php';
$root = realpath((string) getenv('GETMUTASI_WEB_ROOT'));
$manifest = (string) getenv('GETMUTASI_BROWSER_FIXTURE');
if (app_config('env') !== 'testing' || !str_ends_with((string) app_config('database.database'), '_test')
    || !$root || file_exists($root . '/.git') || !str_starts_with($manifest, '/private/tmp/')
    || str_contains($manifest, '..') || file_exists($manifest) || !is_dir(dirname($manifest))) exit(2);
$db = app_db();
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$fixture = ['prefix' => 'CAB-' . bin2hex(random_bytes(3))];
foreach (['terima', 'tolak', 'hapus'] as $key) {
    $no = $fixture['prefix'] . '-' . $key;
    $name = 'Synthetic browser ' . $key;
    $nisn = (string) random_int(9000000000, 9999999999);
    $nik = (string) random_int(9000000000000000, 9999999999999999);
    $s = $db->prepare("INSERT INTO psb_pendaftar (no_pendaftaran,nama_lengkap,nisn,nik,tempat_lahir,tgl_lahir,jenis_kelamin,
        alamat_jalan,desa,kecamatan,kab_kota,provinsi,nama_ayah,nama_ibu,no_hp_wali,sekolah_asal,jenjang_tujuan,status)
        VALUES (?,?,?,?,'Test','2014-01-01','L','Test','Test','Test','Test','Test','Synthetic father','Synthetic mother',
        '0800000000','Test','SMP Terpadu Al Hasan','Baru')");
    $s->bind_param('ssss', $no, $name, $nisn, $nik); $s->execute(); $fixture[$key] = (int) $db->insert_id;
}
$no = $fixture['prefix'] . '-terima';
$s = $db->prepare("INSERT INTO psb_pembayaran (no_pendaftaran,kategori_biaya,syahriyyah,infaq,rincian_wajib,total_wajib,total_keseluruhan)
    VALUES (?,'Synthetic',100,100,'Synthetic',200,200)");
$s->bind_param('s', $no); $s->execute(); $fixture['bayar'] = (int) $db->insert_id;
foreach (['berita' => ['gambar', 'admin/gambar/'], 'galeri' => ['gambar', 'gambar_galeri/'],
    'download' => ['nama_file', 'admin/file_download/']] as $table => [$col, $dir]) {
    $name = strtolower($fixture['prefix']) . '-' . $table . '.txt';
    $folder = $root . '/' . $dir;
    if (!is_dir($folder)) mkdir($folder, 0700, true);
    file_put_contents($folder . $name, 'Synthetic browser file');
    $extra = $table === 'berita' ? ', isi_berita' : ($table === 'galeri' ? ', keterangan' : '');
    $value = $extra !== '' ? ", 'Synthetic'" : '';
    $s = $db->prepare('INSERT INTO `' . $table . '` (judul, `' . $col . '`' . $extra . ") VALUES ('Synthetic browser', ?" . $value . ')');
    $s->bind_param('s', $name); $s->execute(); $fixture[$table] = (int) $db->insert_id;
}
file_put_contents($manifest, json_encode($fixture, JSON_THROW_ON_ERROR));
chmod($manifest, 0600);
echo "Synthetic browser fixture created; database rows retained.\n";
