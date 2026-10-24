<?php

declare(strict_types=1);

namespace App\Psb;

use mysqli;
use mysqli_sql_exception;

/**
 * Pendaftaran PSB publik (`proses_psb.php`) dan ubah biodata oleh pendaftar
 * (`portal_pendaftar.php`) — workstream keamanan PSB.
 *
 * Isian yang sama dengan formulir lama, kini diperiksa di server (wajib,
 * panjang sesuai kolom, pilihan tetap, tanggal sah, NISN/NIK/No. HP angka)
 * dan disimpan dengan query terikat. Pendaftar hanya dapat mengubah barisnya
 * sendiri; status, nomor pendaftaran, NISN, dan berkas tidak ikut diubah.
 */
final class PendaftarData
{
    /** Isian => [label, wajib, panjang maks]. */
    private const ISIAN = [
        'nama_lengkap' => ['Nama lengkap', true, 100],
        'nik' => ['NIK', true, 20],
        'tempat_lahir' => ['Tempat lahir', true, 50],
        'tgl_lahir' => ['Tanggal lahir', true, 10],
        'jenis_kelamin' => ['Jenis kelamin', true, 1],
        'jenjang_tujuan' => ['Jenjang tujuan', true, 50],
        'alamat_jalan' => ['Alamat jalan', true, 1000],
        'nama_ayah' => ['Nama ayah', true, 100],
        'nama_ibu' => ['Nama ibu', true, 100],
        'no_hp_wali' => ['No. HP wali', true, 15],
        'sekolah_asal' => ['Asal sekolah', true, 100],
    ];

    /** Nama isian wilayah di formulir => kolom basis data. */
    private const WILAYAH = ['nama_provinsi' => 'provinsi', 'nama_kabupaten' => 'kab_kota', 'nama_kecamatan' => 'kecamatan', 'nama_desa' => 'desa'];

    public function __construct(private mysqli $db)
    {
    }

    /**
     * @param array<string, mixed> $post
     * @return array{id:int, no:string}
     */
    public function daftar(array $post): array
    {
        $data = $this->periksa($post, true);
        for ($coba = 0; $coba < 5; $coba++) {
            $no = 'PSB-' . date('Y') . '-' . random_int(1000, 9999);
            $stmt = $this->db->prepare(
                'INSERT INTO psb_pendaftar (no_pendaftaran, nama_lengkap, nisn, nik, tempat_lahir, tgl_lahir, jenis_kelamin, alamat_jalan, desa, kecamatan, kab_kota, provinsi, nama_ayah, nama_ibu, no_hp_wali, sekolah_asal, jenjang_tujuan)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            if ($stmt === false) {
                throw new PsbException('Pendaftaran belum dapat disimpan. Coba lagi.', [], 500);
            }
            $stmt->bind_param(str_repeat('s', 17), $no, $data['nama_lengkap'], $data['nisn'], $data['nik'], $data['tempat_lahir'],
                $data['tgl_lahir'], $data['jenis_kelamin'], $data['alamat_jalan'], $data['desa'], $data['kecamatan'], $data['kab_kota'],
                $data['provinsi'], $data['nama_ayah'], $data['nama_ibu'], $data['no_hp_wali'], $data['sekolah_asal'], $data['jenjang_tujuan']);
            try {
                $ok = $stmt->execute();
                $errno = $stmt->errno;
                $id = (int) $stmt->insert_id;
            } catch (mysqli_sql_exception $exception) {
                $ok = false;
                $errno = $exception->getCode();
                $id = 0;
            }
            $stmt->close();
            if ($ok) {
                return ['id' => $id, 'no' => $no];
            }
            if ($errno !== 1062) {
                throw new PsbException('Pendaftaran belum dapat disimpan. Coba lagi.', [], 500);
            }
            if ($this->nisnTerpakai($data['nisn'])) {
                throw new PsbException('NISN sudah terdaftar.', ['nisn' => 'NISN sudah terdaftar. Masuk ke Portal Pendaftar bila Anda sudah mendaftar.'], 409);
            }
            // Nomor pendaftaran acak bertabrakan: coba nomor lain.
        }

        throw new PsbException('Nomor pendaftaran tidak dapat dibuat. Coba lagi.', [], 500);
    }

    /**
     * Ubah biodata milik pendaftar yang sedang masuk.
     *
     * @param array<string, mixed> $post
     * @return array<string, mixed> baris sesudah diubah
     */
    public function ubah(int $id, array $post): array
    {
        $data = $this->periksa($post, false);
        $set = 'nama_lengkap = ?, nik = ?, tempat_lahir = ?, tgl_lahir = ?, jenis_kelamin = ?, jenjang_tujuan = ?, alamat_jalan = ?, nama_ayah = ?, nama_ibu = ?, no_hp_wali = ?, sekolah_asal = ?';
        $nilai = [$data['nama_lengkap'], $data['nik'], $data['tempat_lahir'], $data['tgl_lahir'], $data['jenis_kelamin'], $data['jenjang_tujuan'],
            $data['alamat_jalan'], $data['nama_ayah'], $data['nama_ibu'], $data['no_hp_wali'], $data['sekolah_asal']];
        // Wilayah hanya diganti bila pendaftar memilih wilayah baru (aturan lama).
        if ($data['provinsi'] !== '') {
            $set .= ', provinsi = ?, kab_kota = ?, kecamatan = ?, desa = ?';
            array_push($nilai, $data['provinsi'], $data['kab_kota'], $data['kecamatan'], $data['desa']);
        }
        $nilai[] = $id;
        $stmt = $this->db->prepare('UPDATE psb_pendaftar SET ' . $set . ' WHERE id = ?');
        if ($stmt === false) {
            throw new PsbException('Terjadi kesalahan saat menyimpan data.', [], 500);
        }
        $stmt->bind_param(str_repeat('s', count($nilai) - 1) . 'i', ...$nilai);
        $ok = $stmt->execute();
        $stmt->close();
        if (!$ok) {
            throw new PsbException('Terjadi kesalahan saat menyimpan data.', [], 500);
        }
        $stmt = $this->db->prepare('SELECT * FROM psb_pendaftar WHERE id = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!is_array($row)) {
            throw new PsbException('Data pendaftar tidak ditemukan.', [], 404);
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $post
     * @return array<string, string>
     */
    private function periksa(array $post, bool $baru): array
    {
        $teks = static fn (mixed $v): string => is_scalar($v) ? trim((string) $v) : '';
        $data = [];
        $galat = [];
        $isian = self::ISIAN + ($baru ? ['nisn' => ['NISN', true, 20]] : []);
        foreach ($isian as $kunci => [$label, $wajib, $maks]) {
            $data[$kunci] = $teks($post[$kunci] ?? null);
            if ($wajib && $data[$kunci] === '') {
                $galat[$kunci] = $label . ' wajib diisi.';
            } elseif (mb_strlen($data[$kunci]) > $maks) {
                $galat[$kunci] = $label . ' paling banyak ' . $maks . ' karakter.';
            }
        }
        foreach (self::WILAYAH as $isianForm => $kolom) {
            $data[$kolom] = $teks($post[$isianForm] ?? null);
            if (mb_strlen($data[$kolom]) > 50) {
                $galat[$kolom] = 'Nama wilayah paling banyak 50 karakter.';
            }
        }
        foreach (['nisn' => 'NISN', 'nik' => 'NIK'] as $kunci => $label) {
            if (isset($data[$kunci]) && !isset($galat[$kunci]) && !preg_match('/^\d+$/', $data[$kunci])) {
                $galat[$kunci] = $label . ' hanya berisi angka.';
            }
        }
        if (!isset($galat['no_hp_wali']) && !preg_match('/^\d{8,15}$/', $data['no_hp_wali'])) {
            $galat['no_hp_wali'] = 'No. HP wali berisi 8–15 angka.';
        }
        if (!isset($galat['jenis_kelamin']) && !in_array($data['jenis_kelamin'], ['L', 'P'], true)) {
            $galat['jenis_kelamin'] = 'Jenis kelamin tidak dikenal.';
        }
        if (!isset($galat['jenjang_tujuan']) && !in_array($data['jenjang_tujuan'], PsbAdmin::UNIT, true)) {
            $galat['jenjang_tujuan'] = 'Jenjang tujuan tidak dikenal.';
        }
        if (!isset($galat['tgl_lahir']) && (!PsbAdmin::tanggal($data['tgl_lahir']) || $data['tgl_lahir'] > date('Y-m-d'))) {
            $galat['tgl_lahir'] = 'Tanggal lahir tidak valid.';
        }
        if ($baru && !isset($galat['nisn']) && $this->nisnTerpakai($data['nisn'])) {
            $galat['nisn'] = 'NISN sudah terdaftar. Masuk ke Portal Pendaftar bila Anda sudah mendaftar.';
        }
        if ($galat !== []) {
            throw new PsbException('Data belum tersimpan.', $galat);
        }

        return $data;
    }

    private function nisnTerpakai(string $nisn): bool
    {
        $stmt = $this->db->prepare('SELECT id FROM psb_pendaftar WHERE nisn = ? LIMIT 1');
        $stmt->bind_param('s', $nisn);
        $stmt->execute();
        $ada = $stmt->get_result()->fetch_assoc() !== null;
        $stmt->close();

        return $ada;
    }
}
