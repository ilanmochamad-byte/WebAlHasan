<?php

declare(strict_types=1);

namespace App\Schedule;

use App\Database\Schema;
use mysqli;
use RuntimeException;

/**
 * Usulan status "Izin" otomatis pada peserta pertemuan pengajian (migrasi 036).
 *
 * Keputusan Human Developer 23 November 2026 (revisi keputusan S, IP-1…IP-6).
 * Satu tempat untuk aturan pengisian, dipanggil di DALAM transaksi
 * `PesertaPertemuan::bekukan()` dan `PesertaPertemuan::perbarui()`, sehingga web
 * dan REST selalu sama. Pemanggil sudah mengunci baris pertemuan/jadwal.
 *
 * Aturan (rencana §3.3):
 *   - izin memenuhi bila `izin_pengajuan.status = 'Disetujui'` DAN memiliki
 *     baris `izin_keputusan` berhasil 'Disetujui' (kapasitas Murobi atau Admin
 *     Pengganti), serta `tgl_izin <= tanggal pertemuan <= tgl_kembali` (per hari
 *     penuh, inklusif; IP-2). Asal Pengurus (V2) dan Wali (V7) sama. Izin warisan
 *     tanpa baris keputusan tidak dihitung (IP-4). Tumpang tindih → ID terbesar;
 *   - keterangan umum IP-3, tanpa alasan, catatan, keputusan, atau nama pengaju;
 *   - hanya peserta yang BELUM berabsensi yang diselaraskan. Peserta berabsensi
 *     tidak pernah diubah (absensi milik guru, keputusan R). Pertemuan Selesai
 *     tidak dipanggil oleh pemanggil.
 *
 * "Izin" otomatis hanya usulan: tidak ada baris `absensi_santri` yang ditulis.
 * Sebelum migrasi 036 / sesudah rollback seluruh fungsi tidak berbuat apa-apa
 * dan pembacaan mengembalikan kolom NULL (perilaku baseline).
 */
final class IzinPeserta
{
    private const BULAN = [1 => 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];

    public static function terpasang(mysqli $db): bool
    {
        return Schema::hasColumn($db, 'pertemuan_peserta', 'izin_pengajuan_id')
            && Schema::hasColumn($db, 'pertemuan_peserta', 'izin_keterangan');
    }

    /**
     * Fragmen kolom untuk SELECT dari `pertemuan_peserta` (alias `$pp`):
     * `izin_pengajuan_id`, `izin_keterangan`, dan `izin_sampai` (tanggal kembali
     * izin yang diusulkan). Diawali koma. Sebelum 036 seluruhnya NULL.
     */
    public static function kolom(mysqli $db, string $pp = 'pp'): string
    {
        if (!self::terpasang($db)) {
            return ', NULL AS izin_pengajuan_id, NULL AS izin_keterangan, NULL AS izin_sampai';
        }
        return ", {$pp}.izin_pengajuan_id, {$pp}.izin_keterangan,
                  (SELECT izs.tgl_kembali FROM izin_pengajuan izs WHERE izs.id = {$pp}.izin_pengajuan_id) AS izin_sampai";
    }

    /**
     * Bentuk payload aditif `izin` (rencana §3.4) dari baris yang memuat
     * kolom `kolom()`; `null` bila tanpa usulan.
     *
     * @param array<string, mixed> $row
     * @return array{pengajuan_id:int, keterangan:string, sampai:?string}|null
     */
    public static function payload(array $row): ?array
    {
        if (($row['izin_pengajuan_id'] ?? null) === null) {
            return null;
        }
        return [
            'pengajuan_id' => (int) $row['izin_pengajuan_id'],
            'keterangan' => (string) ($row['izin_keterangan'] ?? ''),
            'sampai' => $row['izin_sampai'] === null ? null : (string) $row['izin_sampai'],
        ];
    }

    /** "Izin No. 123 — disetujui murobi s.d. 25 Nov" (IP-3). */
    public static function keterangan(int $pengajuanId, string $kapasitas, string $tglKembali): string
    {
        $pemutus = $kapasitas === 'Admin Pengganti' ? 'admin pengganti' : 'murobi';
        $waktu = strtotime($tglKembali . ' 00:00:00');
        $tanggal = $waktu === false
            ? $tglKembali
            : (int) date('j', $waktu) . ' ' . self::BULAN[(int) date('n', $waktu)];

        return 'Izin No. ' . $pengajuanId . ' — disetujui ' . $pemutus . ' s.d. ' . $tanggal;
    }

    /**
     * Menyelaraskan usulan seluruh peserta pertemuan yang BELUM berabsensi
     * dengan izin yang berlaku pada tanggal pertemuan saat ini.
     *
     * @return array{diisi:int, dikosongkan:int} jumlah peserta yang usulannya
     *         diisi/diganti dan dikosongkan (tanpa isi izin, aman untuk audit).
     */
    public static function selaraskan(mysqli $db, int $meetingId): array
    {
        $hasil = ['diisi' => 0, 'dikosongkan' => 0];
        if (!self::terpasang($db)) {
            return $hasil;
        }

        $tanggal = self::query($db, 'SELECT tanggal_pertemuan FROM pertemuan_pengajian WHERE id = ?', [$meetingId])[0]['tanggal_pertemuan'] ?? null;
        if ($tanggal === null) {
            return $hasil;
        }

        $peserta = self::query(
            $db,
            'SELECT pp.santri_id, pp.izin_pengajuan_id, pp.izin_keterangan
               FROM pertemuan_peserta pp
              WHERE pp.pertemuan_id = ?
                AND NOT EXISTS (SELECT 1 FROM absensi_santri a WHERE a.pertemuan_id = pp.pertemuan_id AND a.santri_id = pp.santri_id)',
            [$meetingId]
        );
        if ($peserta === []) {
            return $hasil;
        }

        $santriIds = array_map(static fn (array $row): int => (int) $row['santri_id'], $peserta);
        $usulan = [];
        foreach (self::query(
            $db,
            "SELECT p.id, p.santri_id, p.tgl_kembali, k.kapasitas
               FROM izin_pengajuan p
               JOIN izin_keputusan k ON k.pengajuan_id = p.id AND k.hasil = 'Disetujui'
              WHERE p.status = 'Disetujui' AND p.tgl_izin <= ? AND p.tgl_kembali >= ?
                AND p.santri_id IN (" . implode(',', array_fill(0, count($santriIds), '?')) . ')
              ORDER BY p.id DESC',
            [(string) $tanggal, (string) $tanggal, ...$santriIds]
        ) as $row) {
            $usulan[(int) $row['santri_id']] ??= [
                'id' => (int) $row['id'],
                'keterangan' => self::keterangan((int) $row['id'], (string) $row['kapasitas'], (string) $row['tgl_kembali']),
            ];
        }

        foreach ($peserta as $row) {
            $santriId = (int) $row['santri_id'];
            $sekarangId = $row['izin_pengajuan_id'] === null ? null : (int) $row['izin_pengajuan_id'];
            $sekarangKet = $row['izin_keterangan'] === null ? null : (string) $row['izin_keterangan'];
            $target = $usulan[$santriId] ?? null;
            if ($target === null) {
                if ($sekarangId === null && $sekarangKet === null) {
                    continue;
                }
                self::execute($db, 'UPDATE pertemuan_peserta SET izin_pengajuan_id = NULL, izin_keterangan = NULL WHERE pertemuan_id = ? AND santri_id = ?', [$meetingId, $santriId]);
                $hasil['dikosongkan']++;
                continue;
            }
            if ($sekarangId === $target['id'] && $sekarangKet === $target['keterangan']) {
                continue;
            }
            self::execute($db, 'UPDATE pertemuan_peserta SET izin_pengajuan_id = ?, izin_keterangan = ? WHERE pertemuan_id = ? AND santri_id = ?', [$target['id'], $target['keterangan'], $meetingId, $santriId]);
            $hasil['diisi']++;
        }

        return $hasil;
    }

    /**
     * @param list<int|string> $params
     * @return list<array<string, mixed>>
     */
    private static function query(mysqli $db, string $sql, array $params): array
    {
        $statement = $db->prepare($sql);
        if ($statement === false) {
            throw new RuntimeException('Query usulan izin peserta tidak dapat disiapkan: ' . $db->error);
        }
        try {
            if (!$statement->execute($params)) {
                throw new RuntimeException('Query usulan izin peserta gagal: ' . $statement->error);
            }
            $result = $statement->get_result();
            return $result === false ? [] : array_values($result->fetch_all(MYSQLI_ASSOC));
        } finally {
            $statement->close();
        }
    }

    /** @param list<int|string> $params */
    private static function execute(mysqli $db, string $sql, array $params): void
    {
        $statement = $db->prepare($sql);
        if ($statement === false) {
            throw new RuntimeException('Query usulan izin peserta tidak dapat disiapkan: ' . $db->error);
        }
        try {
            if (!$statement->execute($params)) {
                throw new RuntimeException('Query usulan izin peserta gagal: ' . $statement->error);
            }
        } finally {
            $statement->close();
        }
    }
}
