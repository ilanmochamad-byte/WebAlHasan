<?php

declare(strict_types=1);

namespace App\Izin;

use mysqli;
use mysqli_stmt;
use RuntimeException;

/**
 * Routing pengajuan izin kepada murobi (PRD 5.3 dan Fase 2 §5–6).
 *
 * Murobi adalah GURU dengan `murobi_assignments` aktif — tidak ada role `murobi`.
 * Kandidat dinilai dari:
 *   - penugasan murobi aktif pada tanggal pengajuan,
 *   - tahun ajaran aktif,
 *   - kamar aktif santri (`plotting_kamar`) atau kelas aktif santri (`plotting_kelas`).
 *
 * Hasil:
 *   - tepat satu guru kandidat  -> pengajuan langsung masuk antrean murobi tersebut,
 *   - nol atau lebih dari satu  -> `Perlu Penetapan Admin` (tidak pernah menebak).
 *
 * Kelas ini hanya membaca; keputusan penulisan ada pada IzinWorkflowService.
 */
final class IzinRouter
{
    public const STATUS_TERARAH = 'Diajukan';
    public const STATUS_PERLU_ADMIN = 'Perlu Penetapan Admin';

    public function __construct(private mysqli $db)
    {
    }

    /**
     * Seluruh kandidat murobi untuk satu santri pada satu tanggal.
     *
     * @return array<int, array<string, mixed>> daftar unik per guru
     */
    public function candidates(int $santriId, string $onDate): array
    {
        $rows = $this->select(
            "SELECT ma.id AS assignment_id, ma.target_type, ma.tahun_ajaran_id,
                    g.id AS guru_id, g.nama_guru,
                    COALESCE(km.nama_kamar, kl.nama_kelas) AS target_name
               FROM murobi_assignments ma
               JOIN guru g ON g.id = ma.guru_id AND g.is_active = 1 AND g.archived_at IS NULL
               JOIN tahun_ajaran ta ON ta.id = ma.tahun_ajaran_id
                    AND ta.status = 'Aktif' AND ta.archived_at IS NULL
               LEFT JOIN plotting_kamar pkm ON pkm.id_kamar = ma.kamar_id
                    AND pkm.id_tahun = ma.tahun_ajaran_id AND pkm.id_santri = ?
               LEFT JOIN plotting_kelas pkl ON pkl.id_kelas = ma.kelas_id
                    AND pkl.id_tahun = ma.tahun_ajaran_id AND pkl.id_santri = ?
                    AND pkl.status = 'Aktif'
               LEFT JOIN kamar km ON km.id = ma.kamar_id
               LEFT JOIN kelas kl ON kl.id = ma.kelas_id
                    AND kl.is_active = 1 AND kl.archived_at IS NULL
              WHERE ma.is_active = 1 AND ma.archived_at IS NULL
                AND ma.tanggal_mulai <= ?
                AND (ma.tanggal_selesai IS NULL OR ma.tanggal_selesai >= ?)
                AND (
                        (ma.target_type = 'Kamar' AND pkm.id IS NOT NULL)
                     OR (ma.target_type = 'Kelas' AND pkl.id IS NOT NULL AND kl.id IS NOT NULL)
                    )
              ORDER BY g.nama_guru, g.id, ma.id",
            [$santriId, $santriId, $onDate, $onDate]
        );

        $unique = [];
        foreach ($rows as $row) {
            $guruId = (int) $row['guru_id'];
            if (isset($unique[$guruId])) {
                // Guru yang sama menjadi murobi kamar sekaligus kelas tetap SATU kandidat.
                $unique[$guruId]['targets'][] = $row['target_type'] . ': ' . (string) $row['target_name'];
                continue;
            }
            $row['guru_id'] = $guruId;
            $row['targets'] = [$row['target_type'] . ': ' . (string) $row['target_name']];
            $unique[$guruId] = $row;
        }

        return array_values($unique);
    }

    /**
     * Keputusan routing untuk satu santri.
     *
     * @return array{status:string, murobi_guru_id:?int, jumlah:int, catatan:string,
     *               kandidat:array<int, array<string, mixed>>}
     */
    public function resolve(int $santriId, string $onDate): array
    {
        $candidates = $this->candidates($santriId, $onDate);
        $jumlah = count($candidates);

        if ($jumlah === 1) {
            $only = $candidates[0];
            return [
                'status' => self::STATUS_TERARAH,
                'murobi_guru_id' => (int) $only['guru_id'],
                'jumlah' => 1,
                'catatan' => 'Routing otomatis: satu murobi aktif cocok (' . implode(', ', $only['targets']) . ').',
                'kandidat' => $candidates,
            ];
        }

        if ($jumlah === 0) {
            return [
                'status' => self::STATUS_PERLU_ADMIN,
                'murobi_guru_id' => null,
                'jumlah' => 0,
                'catatan' => 'Tidak ada penugasan murobi aktif yang cocok dengan kamar/kelas santri pada tahun ajaran aktif.',
                'kandidat' => [],
            ];
        }

        $nama = array_map(static fn (array $row): string => (string) $row['nama_guru'], $candidates);

        return [
            'status' => self::STATUS_PERLU_ADMIN,
            'murobi_guru_id' => null,
            'jumlah' => $jumlah,
            'catatan' => 'Ditemukan ' . $jumlah . ' murobi kandidat (' . mb_substr(implode(', ', $nama), 0, 160) . '); admin harus menetapkan satu.',
            'kandidat' => $candidates,
        ];
    }

    /**
     * Guru yang boleh dipilih admin sebagai murobi: aktif dan benar-benar memiliki
     * penugasan murobi aktif. Tanpa ini, guru terpilih tidak akan pernah memperoleh
     * kemampuan keputusan (PRD 5.1) dan pengajuan akan macet.
     *
     * @return array<int, array<string, mixed>>
     */
    public function eligibleMurobi(string $onDate): array
    {
        return $this->select(
            "SELECT DISTINCT g.id AS guru_id, g.nama_guru, g.nip
               FROM murobi_assignments ma
               JOIN guru g ON g.id = ma.guru_id AND g.is_active = 1 AND g.archived_at IS NULL
               JOIN tahun_ajaran ta ON ta.id = ma.tahun_ajaran_id
                    AND ta.status = 'Aktif' AND ta.archived_at IS NULL
               LEFT JOIN kelas kl ON kl.id = ma.kelas_id
                    AND kl.is_active = 1 AND kl.archived_at IS NULL
              WHERE ma.is_active = 1 AND ma.archived_at IS NULL
                AND ma.tanggal_mulai <= ?
                AND (ma.tanggal_selesai IS NULL OR ma.tanggal_selesai >= ?)
                AND (ma.target_type = 'Kamar' OR (ma.target_type = 'Kelas' AND kl.id IS NOT NULL))
              ORDER BY g.nama_guru, g.id",
            [$onDate, $onDate]
        );
    }

    public function isEligibleMurobi(int $guruId, string $onDate): bool
    {
        return $this->select(
            "SELECT g.id
               FROM murobi_assignments ma
               JOIN guru g ON g.id = ma.guru_id AND g.is_active = 1 AND g.archived_at IS NULL
               JOIN tahun_ajaran ta ON ta.id = ma.tahun_ajaran_id
                    AND ta.status = 'Aktif' AND ta.archived_at IS NULL
               LEFT JOIN kelas kl ON kl.id = ma.kelas_id
                    AND kl.is_active = 1 AND kl.archived_at IS NULL
              WHERE ma.guru_id = ?
                AND ma.is_active = 1 AND ma.archived_at IS NULL
                AND ma.tanggal_mulai <= ?
                AND (ma.tanggal_selesai IS NULL OR ma.tanggal_selesai >= ?)
                AND (ma.target_type = 'Kamar' OR (ma.target_type = 'Kelas' AND kl.id IS NOT NULL))
              LIMIT 1",
            [$guruId, $onDate, $onDate]
        ) !== [];
    }

    /**
     * Cakupan terkini untuk pengajuan Wali (keputusan Human Developer F5-1).
     * Ekspresi SQL hanya dari kode pemanggil; dua placeholder tanggal wajib
     * diisi tanggal aplikasi yang sama. Jalur Pengurus tetap memakai aturan V2.
     */
    public static function sahSql(string $guruExpr, string $santriExpr): string
    {
        return "EXISTS (
            SELECT 1 FROM murobi_assignments v7ma
            JOIN guru v7g ON v7g.id = v7ma.guru_id AND v7g.is_active = 1 AND v7g.archived_at IS NULL
            JOIN tahun_ajaran v7ta ON v7ta.id = v7ma.tahun_ajaran_id AND v7ta.status = 'Aktif' AND v7ta.archived_at IS NULL
            JOIN santri v7s ON v7s.id = $santriExpr AND v7s.is_active = 1 AND v7s.archived_at IS NULL
            LEFT JOIN kelas v7kl ON v7kl.id = v7ma.kelas_id AND v7kl.is_active = 1 AND v7kl.archived_at IS NULL
            WHERE v7ma.guru_id = $guruExpr AND v7ma.is_active = 1 AND v7ma.archived_at IS NULL
              AND v7ma.tanggal_mulai <= ? AND (v7ma.tanggal_selesai IS NULL OR v7ma.tanggal_selesai >= ?)
              AND ((v7ma.target_type = 'Kamar' AND EXISTS (
                       SELECT 1 FROM plotting_kamar v7pk WHERE v7pk.id_santri = $santriExpr
                       AND v7pk.id_kamar = v7ma.kamar_id AND v7pk.id_tahun = v7ma.tahun_ajaran_id))
                OR (v7ma.target_type = 'Kelas' AND v7kl.id IS NOT NULL AND EXISTS (
                       SELECT 1 FROM plotting_kelas v7pc WHERE v7pc.id_santri = $santriExpr
                       AND v7pc.id_kelas = v7ma.kelas_id AND v7pc.id_tahun = v7ma.tahun_ajaran_id AND v7pc.status = 'Aktif')))
        )";
    }

    /** Mutasi mengunci penugasan lalu membaca ulang kelayakan sesudah menunggu. */
    public function coversSantri(int $guruId, int $santriId, string $onDate, bool $lock = false): bool
    {
        $sah = fn (): bool => $this->select('SELECT 1 FROM (SELECT ? AS guru_id, ? AS santri_id) v7scope WHERE '
            . self::sahSql('v7scope.guru_id', 'v7scope.santri_id'), [$guruId, $santriId, $onDate, $onDate]) !== [];
        if (!$sah()) return false;
        if (!$lock) return true;
        foreach ($this->candidates($santriId, $onDate) as $candidate) {
            if ((int) $candidate['guru_id'] !== $guruId) continue;
            $id = (int) $candidate['assignment_id'];
            if ($this->select('SELECT id FROM murobi_assignments WHERE id = ? LOCK IN SHARE MODE', [$id]) === []) return false;
            foreach ($this->candidates($santriId, $onDate) as $current) {
                if ((int) $current['guru_id'] === $guruId && (int) $current['assignment_id'] === $id) return $sah();
            }
            return false;
        }
        return false;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function select(string $sql, array $params = []): array
    {
        $statement = $this->db->prepare($sql);
        if ($statement === false || !$this->run($statement, $params)) {
            throw new RuntimeException('Routing perizinan tidak dapat dihitung.');
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
