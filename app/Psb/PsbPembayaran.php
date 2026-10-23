<?php

declare(strict_types=1);

namespace App\Psb;

use mysqli;

/**
 * Pembayaran dan rekap keuangan PSB untuk halaman admin (redesain Tahap 3,
 * K-6/K-7). Hanya pembayaran yang terhubung ke pendaftar yang dihitung, sama
 * dengan halaman lama. Status sesuai enum: Belum Lunas / Lunas.
 *
 * Query memakai parameter terikat; tandai/batal lunas tetap di
 * `admin/admin_pembayaran_psb.php` dengan perilaku lama.
 */
final class PsbPembayaran
{
    public const STATUS = ['Belum Lunas', 'Lunas'];
    public const PER_PAGE = 20;

    /** Kolom rekap: unit × jenis kelamin. */
    public const KOLOM = ['SMP_L', 'SMP_P', 'SMK_L', 'SMK_P', 'LAIN_L', 'LAIN_P'];
    public const UNIT_REKAP = ['SMP' => 'SMP Terpadu', 'SMK' => 'SMK Terpadu', 'LAIN' => 'Lainnya'];

    public function __construct(private mysqli $db)
    {
    }

    /**
     * @param array<string, mixed> $get
     * @return array{status:string, jk:string, jenjang:string, cari:string}
     */
    public static function filter(array $get): array
    {
        return [
            'status' => in_array($get['status'] ?? '', self::STATUS, true) ? (string) $get['status'] : '',
            'jk' => in_array($get['jk'] ?? '', ['L', 'P'], true) ? (string) $get['jk'] : '',
            'jenjang' => is_scalar($get['jenjang'] ?? null) ? mb_substr(trim((string) $get['jenjang']), 0, 50) : '',
            'cari' => is_scalar($get['cari'] ?? null) ? mb_substr(trim((string) $get['cari']), 0, 100) : '',
        ];
    }

    /** @return array{0:string, 1:array<int, string>} */
    private function where(array $f, bool $status = true): array
    {
        $where = [];
        $params = [];
        if ($status && $f['status'] !== '') {
            $where[] = 'pb.status_pembayaran = ?';
            $params[] = $f['status'];
        }
        if ($f['jk'] !== '') {
            $where[] = 'p.jenis_kelamin = ?';
            $params[] = $f['jk'];
        }
        if ($f['jenjang'] !== '') {
            $where[] = 'p.jenjang_tujuan = ?';
            $params[] = $f['jenjang'];
        }
        if (($f['cari'] ?? '') !== '') {
            $where[] = '(p.nama_lengkap LIKE ? OR pb.no_pendaftaran LIKE ?)';
            array_push($params, '%' . $f['cari'] . '%', '%' . $f['cari'] . '%');
        }

        return [$where === [] ? '' : ' WHERE ' . implode(' AND ', $where), $params];
    }

    private const FROM = ' FROM psb_pembayaran pb JOIN psb_pendaftar p ON pb.no_pendaftaran = p.no_pendaftaran';

    /** @return array{belum:int, lunas:int, kas:int} */
    public function ringkasan(): array
    {
        $row = $this->all("SELECT SUM(pb.status_pembayaran = 'Belum Lunas') AS belum, SUM(pb.status_pembayaran = 'Lunas') AS lunas,
                                  COALESCE(SUM(CASE WHEN pb.status_pembayaran = 'Lunas' THEN pb.total_keseluruhan ELSE 0 END), 0) AS kas" . self::FROM)[0] ?? [];

        return ['belum' => (int) ($row['belum'] ?? 0), 'lunas' => (int) ($row['lunas'] ?? 0), 'kas' => (int) ($row['kas'] ?? 0)];
    }

    /** @return array<string, int> 'Belum Lunas', 'Lunas', 'Semua' dengan saringan lain */
    public function statusCounts(array $f): array
    {
        [$where, $params] = $this->where($f, false);
        $row = $this->all("SELECT SUM(pb.status_pembayaran = 'Belum Lunas') AS belum, SUM(pb.status_pembayaran = 'Lunas') AS lunas, COUNT(*) AS semua" . self::FROM . $where, $params)[0] ?? [];

        return ['Belum Lunas' => (int) ($row['belum'] ?? 0), 'Lunas' => (int) ($row['lunas'] ?? 0), 'Semua' => (int) ($row['semua'] ?? 0)];
    }

    /** @return array{rows:array<int, array<string, mixed>>, total:int, page:int} */
    public function page(array $f, int $page): array
    {
        [$where, $params] = $this->where($f);
        $total = (int) ($this->all('SELECT COUNT(*) AS n' . self::FROM . $where, $params)[0]['n'] ?? 0);
        $page = max(1, min($page, max(1, (int) ceil($total / self::PER_PAGE))));
        $rows = $this->all('SELECT pb.*, p.id AS pendaftar_id, p.nama_lengkap, p.jenis_kelamin, p.jenjang_tujuan' . self::FROM . $where
            . ' ORDER BY pb.status_pembayaran ASC, pb.id DESC LIMIT ? OFFSET ?', [...$params, self::PER_PAGE, ($page - 1) * self::PER_PAGE]);

        return ['rows' => $rows, 'total' => $total, 'page' => $page];
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $id > 0 ? ($this->all('SELECT pb.*, p.id AS pendaftar_id, p.nama_lengkap, p.jenis_kelamin, p.jenjang_tujuan, p.sekolah_asal'
            . self::FROM . ' WHERE pb.id = ?', [$id])[0] ?? null) : null;
    }

    /** @return array<int, array<int, int|string>> */
    public function exportRows(array $f): array
    {
        [$where, $params] = $this->where($f);
        $rows = [['NO', 'NO PENDAFTARAN', 'NAMA LENGKAP', 'JK', 'UNIT SEKOLAH', 'KATEGORI BIAYA', 'METODE PEMBAYARAN', 'SYAHRIYYAH (Rp)', 'INFAQ (Rp)', 'PSAS (Rp)', 'PRAMUKA (Rp)', 'BIAYA WAJIB (Rp)', 'TOTAL KESELURUHAN (Rp)', 'STATUS', 'WAKTU LUNAS']];
        foreach ($this->all('SELECT pb.*, p.nama_lengkap, p.jenis_kelamin, p.jenjang_tujuan' . self::FROM . $where . ' ORDER BY pb.id DESC', $params) as $i => $d) {
            $rows[] = [$i + 1, $d['no_pendaftaran'], $d['nama_lengkap'], $d['jenis_kelamin'], $d['jenjang_tujuan'], $d['kategori_biaya'],
                strtoupper((string) $d['metode_pembayaran']), (int) $d['syahriyyah'], (int) $d['infaq'], (int) $d['seragam_psas'],
                (int) $d['seragam_pramuka'], (int) $d['total_wajib'], (int) $d['total_keseluruhan'], strtoupper((string) $d['status_pembayaran']),
                $d['waktu_lunas'] ?: '-'];
        }

        return $rows;
    }

    /**
     * Rincian biaya wajib yang dicentang pendaftar (JSON di `rincian_wajib`).
     *
     * @return array<string, int>
     */
    public static function rincianWajib(?string $json): array
    {
        $data = json_decode((string) $json, true);
        $hasil = [];
        foreach (is_array($data) ? $data : [] as $nama => $harga) {
            if (is_scalar($harga)) {
                $hasil[(string) $nama] = (int) $harga;
            }
        }

        return $hasil;
    }

    /**
     * Matriks rekap keuangan: komponen × unit × L/P, dengan aturan
     * pengelompokan yang sama dengan halaman lama. Komponen bernilai 0 di
     * semua kolom tidak ditampilkan.
     *
     * @return array{komponen:array<string, array<string, int>>, kolom:array<string, int>, total:int}
     */
    public function rekap(string $status): array
    {
        $where = $status === 'Semua' ? '' : ' WHERE pb.status_pembayaran = ?';
        $rows = $this->all('SELECT pb.syahriyyah, pb.infaq, pb.seragam_psas, pb.seragam_pramuka, pb.rincian_wajib, p.jenis_kelamin, p.jenjang_tujuan'
            . self::FROM . $where, $status === 'Semua' ? [] : [$status]);
        $rekap = ['Syahriyyah (Bulanan)' => [], 'Infaq Bangunan' => [], 'Seragam PSAS' => [], 'Seragam Pramuka' => []];
        foreach ($rows as $d) {
            $unit = strtolower((string) $d['jenjang_tujuan']);
            $kunci = (str_contains($unit, 'smp terpadu') ? 'SMP' : (str_contains($unit, 'smk terpadu') ? 'SMK' : 'LAIN')) . '_' . ($d['jenis_kelamin'] === 'P' ? 'P' : 'L');
            foreach (['Syahriyyah (Bulanan)' => 'syahriyyah', 'Infaq Bangunan' => 'infaq', 'Seragam PSAS' => 'seragam_psas', 'Seragam Pramuka' => 'seragam_pramuka'] as $nama => $kolom) {
                $rekap[$nama][$kunci] = ($rekap[$nama][$kunci] ?? 0) + (int) $d[$kolom];
            }
            foreach (self::rincianWajib($d['rincian_wajib']) as $nama => $harga) {
                $rekap[$nama][$kunci] = ($rekap[$nama][$kunci] ?? 0) + $harga;
            }
        }
        $komponen = [];
        $kolomTotal = array_fill_keys(self::KOLOM, 0);
        foreach ($rekap as $nama => $nilai) {
            $baris = [];
            foreach (self::KOLOM as $k) {
                $baris[$k] = (int) ($nilai[$k] ?? 0);
                $kolomTotal[$k] += $baris[$k];
            }
            if (array_sum($baris) > 0) {
                $komponen[$nama] = $baris;
            }
        }

        return ['komponen' => $komponen, 'kolom' => $kolomTotal, 'total' => array_sum($kolomTotal)];
    }

    /**
     * @param array<int, int|string> $params
     * @return array<int, array<string, mixed>>
     */
    private function all(string $sql, array $params = []): array
    {
        $stmt = $this->db->prepare($sql);
        if ($stmt === false) {
            throw new PsbException('Data pembayaran PSB tidak dapat dibaca.', [], 500);
        }
        if ($params !== []) {
            $stmt->bind_param(implode('', array_map(static fn ($v): string => is_int($v) ? 'i' : 's', $params)), ...$params);
        }
        $result = $stmt->execute() ? $stmt->get_result() : false;
        $stmt->close();
        if ($result === false) {
            throw new PsbException('Data pembayaran PSB tidak dapat dibaca.', [], 500);
        }

        return $result->fetch_all(MYSQLI_ASSOC);
    }
}
