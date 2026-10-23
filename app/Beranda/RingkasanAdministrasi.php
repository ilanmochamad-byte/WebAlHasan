<?php

declare(strict_types=1);

namespace App\Beranda;

use mysqli;
use Throwable;

/**
 * Data tab "Ringkasan administrasi" Beranda admin (redesain Tahap 3, N-4).
 *
 * HANYA MEMBACA, prepared statement, untuk tiga tema: PSB, Santri & asrama,
 * Kegiatan. Bagian yang tabelnya belum ada (mis. modul belum dimigrasi)
 * dikembalikan null dan dicatat ke log, sehingga halaman tetap terbuka dan
 * menampilkan keadaan kosong. Pemanggil sudah melewati guard admin.
 *
 * "Santri aktif" = santri berstatus aktif dan tidak diarsipkan (dasbor lama
 * menghitung seluruh baris santri).
 */
final class RingkasanAdministrasi
{
    /** Kelompok unit tujuan PSB untuk grafik "Pendaftar per unit". */
    public const UNIT = ['SMP Terpadu', 'SMK Terpadu', 'RA Terpadu', 'Sekolah mitra', 'Lainnya'];

    public function __construct(private mysqli $db)
    {
    }

    /** @return array<string, mixed>|null */
    public function psb(): ?array
    {
        return $this->safe('psb', function (): array {
            $status = array_fill_keys(['Baru', 'Diterima', 'Cadangan', 'Ditolak', 'Dimigrasi'], 0);
            foreach ($this->all('SELECT status, COUNT(*) AS n FROM psb_pendaftar GROUP BY status') as $row) {
                if (isset($status[$row['status']])) {
                    $status[$row['status']] = (int) $row['n'];
                }
            }
            $unit = array_fill_keys(self::UNIT, 0);
            foreach ($this->all('SELECT jenjang_tujuan, COUNT(*) AS n FROM psb_pendaftar GROUP BY jenjang_tujuan') as $row) {
                $unit[self::unit((string) $row['jenjang_tujuan'])] += (int) $row['n'];
            }
            $bayar = $this->one("SELECT SUM(pb.status_pembayaran = 'Belum Lunas') AS belum,
                                        SUM(pb.status_pembayaran = 'Lunas') AS lunas,
                                        COALESCE(SUM(CASE WHEN pb.status_pembayaran = 'Lunas' THEN pb.total_keseluruhan ELSE 0 END), 0) AS kas
                                   FROM psb_pembayaran pb JOIN psb_pendaftar p ON p.no_pendaftaran = pb.no_pendaftaran");

            return [
                'total' => array_sum($status),
                'status' => $status,
                'unit' => $unit,
                'belum_lunas' => (int) ($bayar['belum'] ?? 0),
                'lunas' => (int) ($bayar['lunas'] ?? 0),
                'kas' => (int) ($bayar['kas'] ?? 0),
            ];
        });
    }

    /** @return array<string, mixed>|null */
    public function santri(int $tahun): ?array
    {
        return $this->safe('santri', function () use ($tahun): array {
            $jk = $this->one("SELECT COUNT(*) AS n, SUM(jenis_kelamin = 'L') AS l, SUM(jenis_kelamin = 'P') AS p
                                FROM santri WHERE is_active = 1 AND archived_at IS NULL");
            $jenjang = [];
            $berkelas = 0;
            if ($tahun > 0) {
                foreach ($this->all("SELECT COALESCE(NULLIF(k.jenjang, ''), 'Tanpa jenjang') AS jenjang, COUNT(DISTINCT s.id) AS n
                                       FROM santri s
                                       JOIN plotting_kelas pk ON pk.id_santri = s.id AND pk.id_tahun = ? AND pk.status = 'Aktif'
                                       JOIN kelas k ON k.id = pk.id_kelas
                                      WHERE s.is_active = 1 AND s.archived_at IS NULL
                                   GROUP BY jenjang ORDER BY jenjang", 'i', [$tahun]) as $row) {
                    $jenjang[(string) $row['jenjang']] = (int) $row['n'];
                    $berkelas += (int) $row['n'];
                }
            }
            $total = (int) ($jk['n'] ?? 0);
            if ($total - $berkelas > 0) {
                $jenjang['Belum berkelas'] = $total - $berkelas;
            }
            // Kamar belum punya kolom asrama putra/putri (keputusan AE, Tahap 6):
            // hunian dihitung untuk seluruh kamar, penghuni dirinci per L/P.
            $hunian = $this->one("SELECT (SELECT COALESCE(SUM(kapasitas), 0) FROM kamar) AS kapasitas,
                                         COUNT(DISTINCT s.id) AS terisi,
                                         COUNT(DISTINCT CASE WHEN s.jenis_kelamin = 'L' THEN s.id END) AS l,
                                         COUNT(DISTINCT CASE WHEN s.jenis_kelamin = 'P' THEN s.id END) AS p
                                    FROM plotting_kamar pm JOIN santri s ON s.id = pm.id_santri
                                   WHERE pm.id_tahun = ? AND s.is_active = 1 AND s.archived_at IS NULL", 'i', [$tahun]);
            $padat = $this->one("SELECT COUNT(*) AS n FROM (
                                    SELECT k.id FROM kamar k
                                      JOIN plotting_kamar pm ON pm.id_kamar = k.id AND pm.id_tahun = ?
                                      JOIN santri s ON s.id = pm.id_santri AND s.is_active = 1 AND s.archived_at IS NULL
                                     WHERE k.kapasitas > 0
                                  GROUP BY k.id, k.kapasitas HAVING COUNT(DISTINCT s.id) > 0.9 * k.kapasitas) padat", 'i', [$tahun]);

            return [
                'aktif' => $total,
                'l' => (int) ($jk['l'] ?? 0),
                'p' => (int) ($jk['p'] ?? 0),
                'jenjang' => $jenjang,
                'kapasitas' => (int) ($hunian['kapasitas'] ?? 0),
                'terisi' => (int) ($hunian['terisi'] ?? 0),
                'terisi_l' => (int) ($hunian['l'] ?? 0),
                'terisi_p' => (int) ($hunian['p'] ?? 0),
                'kamar_padat' => (int) ($padat['n'] ?? 0),
            ];
        });
    }

    /**
     * Izin bulan berjalan per status.
     *
     * @return array<string, int>|null
     */
    public function izinBulanIni(): ?array
    {
        return $this->safe('izin', function (): array {
            $hasil = [];
            foreach ($this->all("SELECT status, COUNT(*) AS n FROM izin_pengajuan
                                  WHERE COALESCE(diajukan_pada, created_at) >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
                               GROUP BY status ORDER BY n DESC") as $row) {
                $hasil[(string) $row['status']] = (int) $row['n'];
            }

            return $hasil;
        });
    }

    /** Pelanggaran yang dicatat bulan ini (modul Pembinaan). */
    public function pelanggaranBulanIni(): ?int
    {
        return $this->safe('pembinaan', fn (): int => (int) ($this->one(
            "SELECT COUNT(*) AS n FROM v3_pelanggaran WHERE waktu_kejadian >= DATE_FORMAT(CURDATE(), '%Y-%m-01')"
        )['n'] ?? 0));
    }

    /**
     * Persentase kehadiran (Hadir + Terlambat dari seluruh catatan) per pekan,
     * Senin–Ahad, untuk sejumlah pekan terakhir. Memakai sumber laporan
     * kehadiran yang sama dengan halaman laporan (`report_service`).
     *
     * @param callable(string, string): ?array<string, int> $ringkasan
     * @return array<string, float|null>
     */
    public static function kehadiranPekanan(callable $ringkasan, int $pekan = 10, ?int $hariIni = null): array
    {
        $hariIni ??= time();
        $senin = strtotime('monday this week', $hariIni);
        $hasil = [];
        for ($i = $pekan - 1; $i >= 0; $i--) {
            $mulai = strtotime('-' . $i . ' week', $senin);
            $akhir = strtotime('+6 day', $mulai);
            $status = $ringkasan(date('Y-m-d', $mulai), date('Y-m-d', $akhir));
            $total = $status === null ? 0 : array_sum($status);
            $label = (int) date('j', $mulai) . '/' . (int) date('n', $mulai);
            $hasil[$label] = $total > 0 ? round(100 * (($status['Hadir'] ?? 0) + ($status['Terlambat'] ?? 0)) / $total, 1) : null;
        }

        return $hasil;
    }

    public static function unit(string $jenjang): string
    {
        $nama = strtolower($jenjang);

        return match (true) {
            str_contains($nama, 'smp terpadu') => 'SMP Terpadu',
            str_contains($nama, 'smk terpadu') => 'SMK Terpadu',
            str_contains($nama, 'ra terpadu') => 'RA Terpadu',
            (bool) preg_match('/\b(man|sman|smkn|sma negeri|smk negeri)\b/', $nama) => 'Sekolah mitra',
            default => 'Lainnya',
        };
    }

    /**
     * @template T
     * @param callable(): T $hitung
     * @return T|null
     */
    private function safe(string $bagian, callable $hitung): mixed
    {
        try {
            return $hitung();
        } catch (Throwable $exception) {
            error_log('Ringkasan administrasi ' . $bagian . ' tidak dapat dihitung: ' . $exception->getMessage());

            return null;
        }
    }

    /** @return array<string, mixed>|null */
    private function one(string $sql, string $types = '', array $params = []): ?array
    {
        return $this->all($sql, $types, $params)[0] ?? null;
    }

    /** @return array<int, array<string, mixed>> */
    private function all(string $sql, string $types = '', array $params = []): array
    {
        $stmt = $this->db->prepare($sql);
        if ($stmt === false) {
            throw new \RuntimeException('Query ringkasan tidak dapat disiapkan.');
        }
        if ($types !== '') {
            $stmt->bind_param($types, ...$params);
        }
        $result = $stmt->execute() ? $stmt->get_result() : false;
        $stmt->close();
        if ($result === false) {
            throw new \RuntimeException('Query ringkasan gagal dijalankan.');
        }

        return $result->fetch_all(MYSQLI_ASSOC);
    }
}
