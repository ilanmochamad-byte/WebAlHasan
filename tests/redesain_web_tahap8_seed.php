<?php

declare(strict_types=1);

/**
 * Fixture sintetis redesain website Tahap 8 (situs publik & PSB).
 *
 *   REDESAIN_T8_SEED=1 KPSB_DB=test_redesain_t8_test KPSB_WEB_ROOT=/private/tmp/<privat>/web php tests/redesain_web_tahap8_seed.php
 *
 * Mengisi berita, galeri, unduhan, dan jadwal ngaji dengan baris berawalan
 * "[T8]" serta berkas gambar/PDF sintetis di SALINAN web (bukan checkout).
 * Pendaftar sintetis T8 memakai NISN 9700000xxx. DB wajib test_*_test.
 */

if (getenv('REDESAIN_T8_SEED') !== '1') {
    fwrite(STDERR, "Set REDESAIN_T8_SEED=1 untuk menjalankan.\n");
    exit(2);
}
$db = getenv('KPSB_DB') ?: 'test_redesain_t8_test';
$web = rtrim((string) getenv('KPSB_WEB_ROOT'), '/');
if (!preg_match('/^test_.*_test$/', $db) || $web === '' || !is_dir($web) || file_exists($web . '/.git')) {
    fwrite(STDERR, "Ditolak: DB test_*_test dan KPSB_WEB_ROOT salinan tanpa .git.\n");
    exit(2);
}
putenv('DB_NAME=' . $db);
require_once dirname(__DIR__) . '/app/bootstrap.php';
$m = app_db();
$q = static function (string $sql, array $p = []) use ($m): void {
    $s = $m->prepare($sql);
    if ($p !== []) {
        $s->bind_param(str_repeat('s', count($p)), ...array_map('strval', $p));
    }
    $s->execute();
    $s->close();
};

$gambar = static function (string $jalur, int $w, int $h, array $rgb): void {
    $img = imagecreatetruecolor($w, $h);
    imagefill($img, 0, 0, imagecolorallocate($img, ...$rgb));
    imagefilledrectangle($img, (int) ($w * .1), (int) ($h * .6), (int) ($w * .9), (int) ($h * .9), imagecolorallocate($img, 240, 240, 230));
    @mkdir(dirname($jalur), 0755, true);
    imagejpeg($img, $jalur, 80);
};

$q("DELETE FROM berita WHERE judul LIKE '[T8]%'");
$q("DELETE FROM galeri WHERE judul LIKE '[T8]%'");
$q("DELETE FROM download WHERE judul LIKE '[T8]%'");
for ($i = 1; $i <= 14; $i++) {
    $file = 't8_berita_' . $i . '.jpg';
    $gambar($web . '/gambar/' . $file, 640, 360, [20 + $i * 8, 100, 60]);
    $q('INSERT INTO berita (judul, isi_berita, gambar, tanggal) VALUES (?, ?, ?, ?)', [
        '[T8] Kabar sintetis nomor ' . $i . ($i === 3 ? ' dengan judul yang cukup panjang agar terpotong dua baris pada kartu' : ''),
        '<p>Isi berita sintetis ke-' . $i . ' untuk uji tampilan. <b>Teks tebal</b> dan paragraf kedua.</p><p>Paragraf kedua berita sintetis.</p>',
        $file, date('Y-m-d', strtotime('-' . $i . ' days')),
    ]);
}
for ($i = 1; $i <= 30; $i++) {
    $file = 't8 galeri ' . $i . '.jpg';
    $gambar($web . '/gambar_galeri/' . $file, 800, 600, [30, 80 + $i * 4, 120]);
    $q('INSERT INTO galeri (judul, keterangan, gambar, tanggal) VALUES (?, ?, ?, ?)', [
        '[T8] Foto kegiatan ' . $i, $i % 3 === 0 ? '' : 'Keterangan foto sintetis ke-' . $i, $file, date('Y-m-d', strtotime('-' . $i . ' days')),
    ]);
}
@mkdir($web . '/file_download', 0755, true);
file_put_contents($web . '/file_download/t8_brosur.pdf', "%PDF-1.4\n" . str_repeat('x', 2048) . "\n%%EOF\n");
file_put_contents($web . '/file_download/t8_formulir.docx', str_repeat('d', 4096));
$q('INSERT INTO download (judul, nama_file, tanggal) VALUES (?, ?, ?)', ['[T8] Brosur sintetis', 't8_brosur.pdf', date('Y-m-d')]);
$q('INSERT INTO download (judul, nama_file, tanggal) VALUES (?, ?, ?)', ['[T8] Formulir sintetis', 't8_formulir.docx', date('Y-m-d', strtotime('-3 days'))]);

// Jadwal ngaji tahun aktif: kelas dan guru sintetis yang sudah ada di sandbox.
$tahun = (int) ($m->query("SELECT id FROM tahun_ajaran WHERE status='Aktif' LIMIT 1")->fetch_row()[0] ?? 0);
$kelas = $m->query('SELECT id FROM kelas ORDER BY id LIMIT 3')->fetch_all();
$guru = (int) ($m->query('SELECT id FROM guru ORDER BY id LIMIT 1')->fetch_row()[0] ?? 0);
$q("DELETE FROM jadwal_ngaji WHERE nama_kitab LIKE '[T8]%'");
if ($tahun > 0 && $guru > 0 && $kelas !== []) {
    foreach (["Ba'da Shubuh", "Ba'da Ashar", "Ba'da Magrib", "Ba'da Isya"] as $w => $waktu) {
        foreach ($kelas as $k => [$idKelas]) {
            $q('INSERT INTO jadwal_ngaji (id_tahun, waktu_sholat, jam, id_kelas, fan_ilmu, nama_kitab, id_guru, tempat, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)', [
                $tahun, $waktu, sprintf('%02d.00–%02d.45', 5 + $w * 4, 5 + $w * 4), $idKelas, 'Fiqih', '[T8] Kitab sintetis ' . ($w + 1) . '-' . ($k + 1), $guru, 'Aula ' . ($k + 1),
                1,
            ]);
        }
    }
    // Jadwal yang dinonaktifkan admin tidak boleh tampil di situs publik.
    $q('INSERT INTO jadwal_ngaji (id_tahun, waktu_sholat, jam, id_kelas, fan_ilmu, nama_kitab, id_guru, tempat, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0)', [
        $tahun, "Ba'da Shubuh", '06.00', $kelas[0][0], 'Nahwu', '[T8] Kitab nonaktif', $guru, 'Aula 9',
    ]);
}
echo "Fixture T8 siap (DB $db).\n";
