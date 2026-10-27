<?php

declare(strict_types=1);

namespace App\Izin;

use mysqli;
use mysqli_stmt;
use RuntimeException;

/**
 * Routing pengajuan wali kepada pembimbing (PRD V7 §5.2 dan Fase 1).
 *
 * Pembimbing adalah PENGURUS dengan `pembimbing_assignments` aktif — tidak ada
 * role baru. Seorang pembimbing SAH untuk satu santri pada satu tanggal bila:
 *   - penugasan aktif, tidak diarsipkan, dan berlaku pada tanggal itu;
 *   - tahun ajaran penugasan aktif;
 *   - santri ditempatkan di kamar penugasan (`plotting_kamar`) atau berada di
 *     kelas aktif penugasan (`plotting_kelas` berstatus Aktif) pada tahun itu;
 *   - master pengurus aktif; dan
 *   - pengurus itu memiliki akun aktif ber-role `pengurus` — tanpa akun,
 *     antrean tidak pernah terbaca sehingga pengajuan tidak boleh diarahkan
 *     kepadanya.
 *
 * Aturan kamar/kelas/tahun sama dengan routing murobi V2 (`IzinRouter`) dan
 * cakupan pengurus V2 (`IzinRepository::pembimbingAssignmentFor`). Satu
 * pengurus yang menjadi pembimbing kamar sekaligus kelas santri tetap SATU
 * kandidat. Kelas ini hanya membaca.
 */
final class PembimbingRouter
{
    public function __construct(private mysqli $db)
    {
    }

    /**
     * Fragmen SQL "pengurus `$pengurus` adalah pembimbing sah santri `$santri`".
     * Dua placeholder tanggal (`?`, `?`) harus diikat pemanggil berurutan;
     * bila `$santri` sendiri berupa `?`, ia muncul dua kali sesudah tanggal.
     * Dipakai juga oleh pembacaan antrean agar daftar dan tindakan memakai
     * definisi yang sama.
     */
    public static function sahSql(string $pengurus, string $santri): string
    {
        return "EXISTS (SELECT 1 FROM pembimbing_assignments pa_v7
               JOIN pengurus pg_v7 ON pg_v7.id = pa_v7.pengurus_id AND pg_v7.is_active = 1 AND pg_v7.archived_at IS NULL
               JOIN tahun_ajaran ta_v7 ON ta_v7.id = pa_v7.tahun_ajaran_id AND ta_v7.status = 'Aktif' AND ta_v7.archived_at IS NULL
              WHERE pa_v7.pengurus_id = {$pengurus}
                AND pa_v7.is_active = 1 AND pa_v7.archived_at IS NULL
                AND pa_v7.tanggal_mulai <= ? AND (pa_v7.tanggal_selesai IS NULL OR pa_v7.tanggal_selesai >= ?)
                AND (
                     (pa_v7.target_type = 'Kamar' AND EXISTS (
                          SELECT 1 FROM plotting_kamar pkm_v7
                           WHERE pkm_v7.id_kamar = pa_v7.kamar_id AND pkm_v7.id_tahun = pa_v7.tahun_ajaran_id
                             AND pkm_v7.id_santri = {$santri}))
                  OR (pa_v7.target_type = 'Kelas' AND EXISTS (
                          SELECT 1 FROM plotting_kelas pkl_v7
                            JOIN kelas kl_v7 ON kl_v7.id = pkl_v7.id_kelas AND kl_v7.is_active = 1 AND kl_v7.archived_at IS NULL
                           WHERE pkl_v7.id_kelas = pa_v7.kelas_id AND pkl_v7.id_tahun = pa_v7.tahun_ajaran_id
                             AND pkl_v7.id_santri = {$santri} AND pkl_v7.status = 'Aktif'))
                )
                AND EXISTS (SELECT 1 FROM users u_v7
                              JOIN user_roles ur_v7 ON ur_v7.user_id = u_v7.id
                              JOIN roles r_v7 ON r_v7.id = ur_v7.role_id AND r_v7.slug = 'pengurus'
                             WHERE u_v7.pengurus_id = pg_v7.id AND u_v7.is_active = 1))";
    }

    /**
     * Seluruh pembimbing sah untuk satu santri, unik per pengurus.
     *
     * @return array<int, array{pengurus_id:int, nama:string, assignment_id:int, targets:array<int,string>}>
     */
    public function candidates(int $santriId, string $onDate): array
    {
        $rows = $this->select(
            "SELECT pa.id AS assignment_id, pa.target_type, pg.id AS pengurus_id, pg.nama,
                    COALESCE(km.nama_kamar, kl.nama_kelas) AS target_name
               FROM pembimbing_assignments pa
               JOIN pengurus pg ON pg.id = pa.pengurus_id
               LEFT JOIN kamar km ON km.id = pa.kamar_id
               LEFT JOIN kelas kl ON kl.id = pa.kelas_id
              WHERE pa.is_active = 1 AND pa.archived_at IS NULL
                AND pa.tanggal_mulai <= ? AND (pa.tanggal_selesai IS NULL OR pa.tanggal_selesai >= ?)
                AND " . self::sahSql('pa.pengurus_id', '?') . "
                AND (
                     (pa.target_type = 'Kamar' AND EXISTS (
                          SELECT 1 FROM plotting_kamar pkm WHERE pkm.id_kamar = pa.kamar_id
                             AND pkm.id_tahun = pa.tahun_ajaran_id AND pkm.id_santri = ?))
                  OR (pa.target_type = 'Kelas' AND EXISTS (
                          SELECT 1 FROM plotting_kelas pkl
                            JOIN kelas k2 ON k2.id = pkl.id_kelas AND k2.is_active = 1 AND k2.archived_at IS NULL
                           WHERE pkl.id_kelas = pa.kelas_id AND pkl.id_tahun = pa.tahun_ajaran_id
                             AND pkl.id_santri = ? AND pkl.status = 'Aktif'))
                )
                AND EXISTS (SELECT 1 FROM tahun_ajaran ta WHERE ta.id = pa.tahun_ajaran_id AND ta.status = 'Aktif' AND ta.archived_at IS NULL)
              ORDER BY pg.nama, pg.id, pa.id",
            // sahSql memeriksa SEBARANG penugasan sah milik pengurus itu; dua
            // syarat EXISTS sesudahnya memastikan baris `pa` yang dipilih
            // sendiri juga penugasan yang menjangkau santri ini.
            [$onDate, $onDate, $onDate, $onDate, $santriId, $santriId, $santriId, $santriId]
        );

        $unique = [];
        foreach ($rows as $row) {
            $id = (int) $row['pengurus_id'];
            $target = $row['target_type'] . ': ' . (string) $row['target_name'];
            if (isset($unique[$id])) {
                $unique[$id]['targets'][] = $target;
                continue;
            }
            $unique[$id] = [
                'pengurus_id' => $id,
                'nama' => (string) $row['nama'],
                'assignment_id' => (int) $row['assignment_id'],
                'targets' => [$target],
            ];
        }

        return array_values($unique);
    }

    /**
     * Keputusan routing pembimbing untuk satu santri.
     *
     * @return array{jumlah:int, pengurus_id:?int, assignment_id:?int, catatan:string}
     */
    public function resolve(int $santriId, string $onDate): array
    {
        $candidates = $this->candidates($santriId, $onDate);
        $jumlah = count($candidates);
        if ($jumlah === 1) {
            return [
                'jumlah' => 1,
                'pengurus_id' => $candidates[0]['pengurus_id'],
                'assignment_id' => $candidates[0]['assignment_id'],
                'catatan' => 'Routing otomatis: satu pembimbing sah cocok (' . mb_substr(implode(', ', $candidates[0]['targets']), 0, 160) . ').',
            ];
        }

        return [
            'jumlah' => $jumlah,
            'pengurus_id' => null,
            'assignment_id' => null,
            'catatan' => $jumlah === 0
                ? 'Tidak ada pembimbing sah (penugasan aktif, kamar/kelas santri, akun pengurus aktif) pada tahun ajaran aktif; admin harus menetapkan pembimbing.'
                : 'Ditemukan ' . $jumlah . ' pembimbing sah; admin harus menetapkan satu.',
        ];
    }

    /**
     * Penugasan sah seorang pengurus atas santri (null = bukan pembimbing sah).
     * `$lock` mengunci baris penugasan dengan kunci baca sehingga pencabutan
     * bersamaan menunggu sampai transaksi pemanggil selesai.
     */
    public function assignmentFor(int $pengurusId, int $santriId, string $onDate, bool $lock = false): ?int
    {
        foreach ($this->candidates($santriId, $onDate) as $candidate) {
            if ($candidate['pengurus_id'] !== $pengurusId) {
                continue;
            }
            if (!$lock) {
                return $candidate['assignment_id'];
            }
            $locked = $this->select(
                'SELECT id FROM pembimbing_assignments WHERE id = ? AND pengurus_id = ? AND is_active = 1 AND archived_at IS NULL LOCK IN SHARE MODE',
                [$candidate['assignment_id'], $pengurusId]
            );

            return $locked === [] ? null : $candidate['assignment_id'];
        }

        return null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function select(string $sql, array $params = []): array
    {
        $statement = $this->db->prepare($sql);
        if ($statement === false || !$this->run($statement, $params)) {
            throw new RuntimeException('Routing pembimbing tidak dapat dihitung.');
        }
        $result = $statement->get_result();
        $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        $statement->close();

        return $rows;
    }

    private function run(mysqli_stmt $statement, array $params): bool
    {
        if ($params !== []) {
            $types = '';
            $references = [];
            foreach ($params as $key => &$value) {
                $types .= is_int($value) || is_bool($value) ? 'i' : (is_float($value) ? 'd' : 's');
                $references[$key] = &$value;
            }
            unset($value);
            if (!$statement->bind_param($types, ...$references)) {
                return false;
            }
        }

        return $statement->execute();
    }
}
