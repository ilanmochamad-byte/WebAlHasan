<?php

declare(strict_types=1);

namespace App\Psb;

use mysqli;
use mysqli_sql_exception;

/**
 * Data pendaftar PSB untuk halaman admin (redesain website Tahap 3, K-5).
 *
 * Satu tempat untuk saringan, hitungan chip, daftar berhalaman, detail,
 * ekspor, tambah manual, dan impor Excel. Seluruh query memakai parameter
 * terikat; kolom, status, dan unit hanya dari daftar tetap di kelas ini.
 * Alur bisnis PSB (status, migrasi, NIS) tidak berubah dan tetap di
 * `admin/proses_psb_admin.php`. Pemanggil sudah melewati guard admin.
 */
final class PsbAdmin
{
    /** Status sesuai enum `psb_pendaftar.status`. */
    public const STATUS = ['Baru', 'Diterima', 'Cadangan', 'Ditolak', 'Dimigrasi'];

    /** Pilihan unit tujuan yang sudah dipakai formulir lama. */
    public const UNIT = [
        'SMP Terpadu Al Hasan', 'SMK Terpadu Al Hasan', 'RA Terpadu Al Hasan',
        'SMKN 2 Ciamis', 'SMKN 1 Ciamis', 'SMAN 2 Ciamis', 'SMAN 1 Ciamis', 'MAN 2 Ciamis',
    ];

    /** Kolom berkas unggahan pendaftar dan labelnya. */
    public const BERKAS = [
        'file_ktp' => 'KTP orang tua',
        'file_kk' => 'Kartu keluarga',
        'file_akta' => 'Akta kelahiran',
        'file_ijazah' => 'Ijazah / SKL',
        'file_nilai' => 'Rapor / nilai',
        'file_prestasi' => 'Sertifikat prestasi',
    ];

    public const PER_PAGE = 20;

    /** Kolom formulir tambah manual => [label, wajib, panjang maks]. */
    public const FORM = [
        'nama' => ['Nama lengkap', true, 100],
        'nisn' => ['NISN', false, 20],
        'nik' => ['NIK', false, 20],
        'jk' => ['Jenis kelamin', true, 1],
        'tmp_lahir' => ['Tempat lahir', false, 50],
        'tgl_lahir' => ['Tanggal lahir', true, 10],
        'sekolah_asal' => ['Asal sekolah', false, 100],
        'jenjang_tujuan' => ['Unit tujuan', true, 50],
        'alamat' => ['Jalan / dusun', false, 1000],
        'desa' => ['Desa', false, 50],
        'kecamatan' => ['Kecamatan', false, 50],
        'kab_kota' => ['Kabupaten/kota', false, 50],
        'provinsi' => ['Provinsi', false, 50],
        'ayah' => ['Nama ayah', false, 100],
        'ibu' => ['Nama ibu', false, 100],
        'no_hp_wali' => ['No. WhatsApp wali', false, 15],
    ];

    public function __construct(private mysqli $db)
    {
    }

    /**
     * Saringan dari alamat. Nilai di luar daftar tetap diabaikan.
     *
     * @param array<string, mixed> $get
     * @return array{status:string, jk:string, jenjang:string, kecamatan:string, cari:string}
     */
    public static function filter(array $get): array
    {
        $text = static fn (mixed $value, int $max): string => is_scalar($value) ? mb_substr(trim((string) $value), 0, $max) : '';

        return [
            'status' => in_array($get['status'] ?? '', self::STATUS, true) ? (string) $get['status'] : '',
            'jk' => in_array($get['jk'] ?? '', ['L', 'P'], true) ? (string) $get['jk'] : '',
            'jenjang' => $text($get['jenjang'] ?? '', 50),
            'kecamatan' => $text($get['kecamatan'] ?? '', 50),
            'cari' => $text($get['cari'] ?? ($get['q'] ?? ''), 100),
        ];
    }

    /**
     * @param array{status:string, jk:string, jenjang:string, kecamatan:string, cari:string} $f
     * @return array{0:string, 1:array<int, string>}
     */
    private function where(array $f, bool $status = true): array
    {
        $where = [];
        $params = [];
        if ($status) {
            // "Semua" = belum dimigrasi, seperti daftar lama; yang sudah
            // dipindah ke data santri dibuka lewat chip Dimigrasi.
            if ($f['status'] === '') {
                $where[] = "status <> 'Dimigrasi'";
            } else {
                $where[] = 'status = ?';
                $params[] = $f['status'];
            }
        }
        if ($f['jk'] !== '') {
            $where[] = 'jenis_kelamin = ?';
            $params[] = $f['jk'];
        }
        if ($f['jenjang'] !== '') {
            $where[] = 'jenjang_tujuan = ?';
            $params[] = $f['jenjang'];
        }
        if ($f['kecamatan'] !== '') {
            $where[] = 'kecamatan LIKE ?';
            $params[] = '%' . $f['kecamatan'] . '%';
        }
        if ($f['cari'] !== '') {
            $where[] = '(nama_lengkap LIKE ? OR no_pendaftaran LIKE ? OR nisn LIKE ?)';
            array_push($params, '%' . $f['cari'] . '%', '%' . $f['cari'] . '%', '%' . $f['cari'] . '%');
        }

        return [$where === [] ? '' : ' WHERE ' . implode(' AND ', $where), $params];
    }

    /**
     * Jumlah per chip status dengan saringan lain tetap berlaku.
     *
     * @return array<string, int> 'Semua' + setiap status
     */
    public function statusCounts(array $f): array
    {
        [$where, $params] = $this->where($f, false);
        $counts = array_fill_keys(self::STATUS, 0);
        foreach ($this->all('SELECT status, COUNT(*) AS n FROM psb_pendaftar' . $where . ' GROUP BY status', $params) as $row) {
            if (isset($counts[$row['status']])) {
                $counts[$row['status']] = (int) $row['n'];
            }
        }

        return ['Semua' => array_sum($counts) - $counts['Dimigrasi']] + $counts;
    }

    /**
     * @return array{rows:array<int, array<string, mixed>>, total:int, page:int}
     */
    public function page(array $f, int $page): array
    {
        [$where, $params] = $this->where($f);
        $total = (int) ($this->all('SELECT COUNT(*) AS n FROM psb_pendaftar' . $where, $params)[0]['n'] ?? 0);
        $page = max(1, min($page, max(1, (int) ceil($total / self::PER_PAGE))));
        $berkas = implode(' + ', array_map(static fn (string $c): string => "(COALESCE($c, '') <> '')", array_keys(self::BERKAS)));
        $rows = $this->all(
            'SELECT id, no_pendaftaran, tgl_daftar, nama_lengkap, jenis_kelamin, sekolah_asal, jenjang_tujuan, status, (' . $berkas . ') AS berkas
               FROM psb_pendaftar' . $where . ' ORDER BY id DESC LIMIT ? OFFSET ?',
            [...$params, self::PER_PAGE, ($page - 1) * self::PER_PAGE]
        );

        return ['rows' => $rows, 'total' => $total, 'page' => $page];
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $id > 0 ? ($this->all('SELECT * FROM psb_pendaftar WHERE id = ?', [$id])[0] ?? null) : null;
    }

    /** @return array<string, mixed>|null */
    public function pembayaran(string $noPendaftaran): ?array
    {
        return $this->all('SELECT * FROM psb_pembayaran WHERE no_pendaftaran = ?', [$noPendaftaran])[0] ?? null;
    }

    /**
     * Baris ekspor sesuai saringan daftar (judul kolom sama dengan ekspor lama).
     *
     * @return array<int, array<int, int|string>>
     */
    public function exportRows(array $f): array
    {
        [$where, $params] = $this->where($f);
        $rows = [['NO', 'NO PENDAFTARAN', 'NAMA LENGKAP', 'NISN', 'NIK', 'JK', 'TEMPAT LAHIR', 'TGL LAHIR', 'ALAMAT JALAN', 'DESA', 'KECAMATAN', 'KAB/KOTA', 'PROVINSI', 'NAMA AYAH', 'NAMA IBU', 'NO HP WALI', 'ASAL SEKOLAH', 'JENJANG TUJUAN', 'STATUS']];
        foreach ($this->all('SELECT * FROM psb_pendaftar' . $where . ' ORDER BY id DESC', $params) as $i => $d) {
            $rows[] = [$i + 1, $d['no_pendaftaran'], $d['nama_lengkap'], (string) $d['nisn'], (string) $d['nik'], $d['jenis_kelamin'],
                $d['tempat_lahir'], $d['tgl_lahir'], $d['alamat_jalan'], $d['desa'], $d['kecamatan'], $d['kab_kota'], $d['provinsi'],
                $d['nama_ayah'], $d['nama_ibu'], (string) $d['no_hp_wali'], $d['sekolah_asal'], $d['jenjang_tujuan'], $d['status']];
        }

        return $rows;
    }

    /**
     * Tambah pendaftar manual (aturan lama: NISN kosong → nomor sementara
     * TMP…, nomor pendaftaran PSB + tahun + acak, status Baru).
     *
     * @param array<string, mixed> $input
     * @return array{id:int, no:string}
     */
    public function tambah(array $input): array
    {
        $data = [];
        $errors = [];
        foreach (self::FORM as $field => [$label, $wajib, $max]) {
            $value = is_scalar($input[$field] ?? null) ? trim((string) $input[$field]) : '';
            if ($wajib && $value === '') {
                $errors[$field] = $label . ' wajib diisi.';
            } elseif (mb_strlen($value) > $max) {
                $errors[$field] = $label . ' paling banyak ' . $max . ' karakter.';
            }
            $data[$field] = $value;
        }
        if (!isset($errors['jk']) && !in_array($data['jk'], ['L', 'P'], true)) {
            $errors['jk'] = 'Jenis kelamin tidak dikenal.';
        }
        if (!isset($errors['jenjang_tujuan']) && !in_array($data['jenjang_tujuan'], self::UNIT, true)) {
            $errors['jenjang_tujuan'] = 'Unit tujuan tidak dikenal.';
        }
        if (!isset($errors['tgl_lahir']) && !self::tanggal($data['tgl_lahir'])) {
            $errors['tgl_lahir'] = 'Tanggal lahir tidak valid.';
        }
        if ($data['nisn'] !== '' && $data['nisn'] !== '-' && $this->all('SELECT id FROM psb_pendaftar WHERE nisn = ?', [$data['nisn']]) !== []) {
            $errors['nisn'] = 'NISN sudah dipakai pendaftar lain.';
        }
        if ($errors !== []) {
            throw new PsbException('Data pendaftar belum tersimpan.', $errors);
        }
        if ($data['nisn'] === '' || $data['nisn'] === '-') {
            $data['nisn'] = 'TMP' . date('ymd') . random_int(1000, 9999);
        }
        for ($coba = 0; $coba < 5; $coba++) {
            $no = 'PSB' . date('Y') . random_int(1000, 9999);
            try {
                $id = $this->insert([$no, $data['nama'], $data['nisn'], $data['nik'], $data['jk'], $data['tmp_lahir'], $data['tgl_lahir'],
                    $data['alamat'], $data['desa'], $data['kecamatan'], $data['kab_kota'], $data['provinsi'],
                    $data['ayah'], $data['ibu'], $data['no_hp_wali'], $data['sekolah_asal'], $data['jenjang_tujuan']]);

                return ['id' => $id, 'no' => $no];
            } catch (mysqli_sql_exception $exception) {
                if ($exception->getCode() !== 1062) {
                    throw new PsbException('Pendaftar tidak dapat disimpan. Coba lagi.');
                }
                if ($this->all('SELECT id FROM psb_pendaftar WHERE nisn = ?', [$data['nisn']]) !== []) {
                    throw new PsbException('Data pendaftar belum tersimpan.', ['nisn' => 'NISN sudah dipakai pendaftar lain.']);
                }
            }
        }

        throw new PsbException('Nomor pendaftaran tidak dapat dibuat. Coba lagi.');
    }

    /**
     * Pemeriksaan impor TANPA menyimpan (wizard K-5 langkah 2). Susunan kolom
     * sama dengan panduan impor lama: A nama … P jenjang tujuan; baris 1 judul.
     *
     * @param array<int, array<int, mixed>> $sheet
     * @return array{siap:array<int, array<string, string>>, lewat:array<int, array{baris:int, nama:string, alasan:string}>}
     */
    public function periksaImpor(array $sheet): array
    {
        $siap = [];
        $lewat = [];
        $nisnBerkas = [];
        foreach ($sheet as $index => $baris) {
            if ($index === 0) {
                continue;
            }
            $cell = static fn (int $i): string => isset($baris[$i]) && is_scalar($baris[$i]) ? trim((string) $baris[$i]) : '';
            $nama = $cell(0);
            if ($nama === '' && implode('', array_map(static fn ($v): string => is_scalar($v) ? trim((string) $v) : '', $baris)) === '') {
                continue;
            }
            $nomor = $index + 1;
            if ($nama === '') {
                $lewat[] = ['baris' => $nomor, 'nama' => '—', 'alasan' => 'Nama lengkap (kolom A) kosong.'];
                continue;
            }
            $nisn = $cell(1);
            $tanggalMentah = $cell(5);
            $tanggal = $tanggalMentah === '' ? date('Y-m-d') : self::tanggalExcel($tanggalMentah);
            if ($tanggal === null) {
                $lewat[] = ['baris' => $nomor, 'nama' => $nama, 'alasan' => 'Tanggal lahir (kolom F) tidak dikenali.'];
                continue;
            }
            if ($nisn !== '' && $nisn !== '-') {
                if (isset($nisnBerkas[$nisn])) {
                    $lewat[] = ['baris' => $nomor, 'nama' => $nama, 'alasan' => 'NISN sama dengan baris ' . $nisnBerkas[$nisn] . ' di berkas ini.'];
                    continue;
                }
                if ($this->all('SELECT id FROM psb_pendaftar WHERE nisn = ?', [$nisn]) !== []) {
                    $lewat[] = ['baris' => $nomor, 'nama' => $nama, 'alasan' => 'NISN sudah terdaftar.'];
                    continue;
                }
                $nisnBerkas[$nisn] = $nomor;
            }
            $jenjang = $cell(15) === '' ? '-' : $cell(15);
            $panjang = ['nama' => [$nama, 100], 'nisn' => [$nisn, 20], 'nik' => [$cell(2), 20], 'tempat lahir' => [$cell(4), 50],
                'desa' => [$cell(7), 50], 'kecamatan' => [$cell(8), 50], 'kab/kota' => [$cell(9), 50], 'provinsi' => [$cell(10), 50],
                'nama ayah' => [$cell(11), 100], 'nama ibu' => [$cell(12), 100], 'no HP wali' => [$cell(13), 15],
                'asal sekolah' => [$cell(14), 100], 'jenjang tujuan' => [$jenjang, 50]];
            foreach ($panjang as $label => [$nilai, $max]) {
                if (mb_strlen($nilai) > $max) {
                    $lewat[] = ['baris' => $nomor, 'nama' => $nama, 'alasan' => ucfirst($label) . ' lebih dari ' . $max . ' karakter.'];
                    continue 2;
                }
            }
            $siap[] = [
                'baris' => (string) $nomor, 'nama' => $nama, 'nisn' => $nisn, 'nik' => $cell(2) === '' ? '-' : $cell(2),
                'jk' => strtoupper($cell(3)) === 'P' ? 'P' : 'L', 'tmp_lahir' => $cell(4), 'tgl_lahir' => $tanggal,
                'alamat' => $cell(6), 'desa' => $cell(7), 'kecamatan' => $cell(8), 'kab_kota' => $cell(9), 'provinsi' => $cell(10),
                'ayah' => $cell(11), 'ibu' => $cell(12), 'no_hp_wali' => $cell(13), 'sekolah_asal' => $cell(14), 'jenjang_tujuan' => $jenjang,
            ];
        }

        return ['siap' => $siap, 'lewat' => $lewat];
    }

    /**
     * Menyimpan baris yang sudah diperiksa, satu per satu (baris gagal tidak
     * menggagalkan baris lain, seperti impor lama).
     *
     * @param array<int, array<string, string>> $siap
     * @return array{berhasil:int, gagal:array<int, array{baris:int, nama:string, alasan:string}>}
     */
    public function simpanImpor(array $siap): array
    {
        $berhasil = 0;
        $gagal = [];
        foreach ($siap as $i => $d) {
            $nisn = $d['nisn'] === '' || $d['nisn'] === '-' ? 'TMP' . date('ymd') . random_int(1000, 9999) . $i : $d['nisn'];
            $no = 'PSB' . date('Y') . str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT) . random_int(10, 99);
            try {
                $this->insert([$no, $d['nama'], $nisn, $d['nik'], $d['jk'], $d['tmp_lahir'], $d['tgl_lahir'], $d['alamat'], $d['desa'],
                    $d['kecamatan'], $d['kab_kota'], $d['provinsi'], $d['ayah'], $d['ibu'], $d['no_hp_wali'], $d['sekolah_asal'], $d['jenjang_tujuan']]);
                $berhasil++;
            } catch (mysqli_sql_exception $exception) {
                $gagal[] = ['baris' => (int) $d['baris'], 'nama' => $d['nama'], 'alasan' => $exception->getCode() === 1062
                    ? 'NISN atau nomor pendaftaran sudah dipakai.' : 'Tidak dapat disimpan.'];
            }
        }

        return ['berhasil' => $berhasil, 'gagal' => $gagal];
    }

    /** @param array<int, string> $v */
    private function insert(array $v): int
    {
        $stmt = $this->db->prepare(
            "INSERT INTO psb_pendaftar (no_pendaftaran, nama_lengkap, nisn, nik, jenis_kelamin, tempat_lahir, tgl_lahir,
                alamat_jalan, desa, kecamatan, kab_kota, provinsi, nama_ayah, nama_ibu, no_hp_wali, sekolah_asal, jenjang_tujuan, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Baru')"
        );
        if ($stmt === false) {
            throw new mysqli_sql_exception('Query pendaftar tidak dapat disiapkan.', (int) $this->db->errno);
        }
        $stmt->bind_param(str_repeat('s', 17), ...$v);
        try {
            // Koneksi memakai MYSQLI_REPORT_OFF: galat dibaca dari errno.
            if (!$stmt->execute()) {
                throw new mysqli_sql_exception('Pendaftar tidak dapat disimpan.', (int) $stmt->errno);
            }

            return (int) $stmt->insert_id;
        } finally {
            $stmt->close();
        }
    }

    public static function tanggal(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value;
    }

    /** Tanggal dari sel Excel: Y-m-d, d/m/Y, d-m-Y, atau nomor seri Excel. */
    public static function tanggalExcel(string $value): ?string
    {
        $value = trim(explode(' ', $value)[0]);
        if (preg_match('/^\d{4,5}$/', $value) && (int) $value > 59) {
            return gmdate('Y-m-d', ((int) $value - 25569) * 86400);
        }
        foreach (['!Y-m-d', '!d/m/Y', '!d-m-Y', '!j/n/Y', '!Y/m/d'] as $format) {
            $date = \DateTimeImmutable::createFromFormat($format, $value);
            if ($date !== false && \DateTimeImmutable::getLastErrors() === false) {
                return $date->format('Y-m-d');
            }
        }

        return null;
    }

    /**
     * @param array<int, int|string> $params
     * @return array<int, array<string, mixed>>
     */
    private function all(string $sql, array $params = []): array
    {
        $stmt = $this->db->prepare($sql);
        if ($stmt === false) {
            throw new PsbException('Data PSB tidak dapat dibaca.', [], 500);
        }
        if ($params !== []) {
            $stmt->bind_param(implode('', array_map(static fn ($v): string => is_int($v) ? 'i' : 's', $params)), ...$params);
        }
        $result = $stmt->execute() ? $stmt->get_result() : false;
        $stmt->close();
        if ($result === false) {
            throw new PsbException('Data PSB tidak dapat dibaca.', [], 500);
        }

        return $result->fetch_all(MYSQLI_ASSOC);
    }
}
