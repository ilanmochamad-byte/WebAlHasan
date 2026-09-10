<?php

declare(strict_types=1);

namespace App\V3;

use mysqli;
use mysqli_stmt;
use Throwable;

/** Seluruh SQL operasional pelanggaran V3 berada di repository ini. */
final class PelanggaranRepository
{
    public function __construct(private mysqli $db)
    {
    }

    public function transaction(callable $work): mixed
    {
        if (!$this->db->begin_transaction()) {
            throw new V3Exception('Transaksi tidak tersedia.', 503);
        }
        try {
            $result = $work();
            if (!$this->db->commit()) {
                throw new V3Exception('Transaksi tidak dapat disimpan.', 503);
            }
            return $result;
        } catch (Throwable $exception) {
            $this->db->rollback();
            throw $exception;
        }
    }

    /**
     * Kunci mutasi per subjek, bukan gerbang global schema_migrations.
     * INSERT idempoten membuat baris kunci pertama; SELECT FOR UPDATE
     * menyerialkan hanya santri/tahun ajaran yang sama.
     */
    public function lockSubject(int $santriId, int $tahunId, int $actorId): array
    {
        $this->execute(
            'INSERT INTO v3_poin_agregat
                 (santri_id,tahun_ajaran_id,total_poin,direkonsiliasi_pada,created_by,updated_by)
             VALUES (?,?,0,NOW(),?,?)
             ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)',
            [$santriId, $tahunId, $actorId, $actorId]
        );
        return $this->one(
            'SELECT id,santri_id,tahun_ajaran_id,total_poin,version
               FROM v3_poin_agregat
              WHERE santri_id=? AND tahun_ajaran_id=? FOR UPDATE',
            [$santriId, $tahunId]
        ) ?? throw new V3Exception('Kunci poin tidak tersedia.', 503);
    }

    public function claimIdempotency(int $userId, string $operation, string $key, string $hash): array
    {
        $this->execute(
            'INSERT INTO v3_idempotency
                 (user_id,operation,idempotency_key,request_hash,created_by,updated_by)
             VALUES (?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)',
            [$userId, $operation, $key, $hash, $userId, $userId]
        );
        return $this->one(
            'SELECT id,request_hash,response_json,status_code
               FROM v3_idempotency
              WHERE user_id=? AND operation=? AND idempotency_key=? FOR UPDATE',
            [$userId, $operation, $key]
        ) ?? throw new V3Exception('Idempotensi tidak tersedia.', 503);
    }

    public function completeIdempotency(int $id, array $response, int $status): void
    {
        $json = json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new V3Exception('Respons tidak dapat disimpan.', 503);
        }
        $this->execute(
            'UPDATE v3_idempotency
                SET response_json=?,status_code=?,version=version+1,updated_at=NOW()
              WHERE id=?',
            [$json, $status, $id]
        );
    }

    public function activeKatalog(int $id, string $date, bool $lock = false): ?array
    {
        return $this->one(
            'SELECT k.id,k.kode,k.nama,k.kategori_id,k.tingkat,k.poin_default,k.uraian,
                    c.nama AS kategori_nama
               FROM v3_katalog k
               JOIN v3_kategori c ON c.id=k.kategori_id
              WHERE k.id=? AND k.is_active=1 AND k.archived_at IS NULL
                AND k.tanggal_mulai<=? AND (k.tanggal_selesai IS NULL OR k.tanggal_selesai>=?)
                AND c.is_active=1 AND c.archived_at IS NULL
                AND c.tanggal_mulai<=? AND (c.tanggal_selesai IS NULL OR c.tanggal_selesai>=?)'
                . ($lock ? ' FOR UPDATE' : ''),
            [$id, $date, $date, $date, $date]
        );
    }

    public function pembimbingAssignment(int $userId, int $santriId, int $tahunId, string $date): ?array
    {
        return $this->one(
            "SELECT pa.id,pa.pengurus_id,pa.target_type,pa.kamar_id,pa.kelas_id,
                    pa.tanggal_mulai,pa.tanggal_selesai
               FROM users u
               JOIN user_roles ur ON ur.user_id=u.id
               JOIN roles r ON r.id=ur.role_id AND r.slug='pengurus'
               JOIN pengurus p ON p.id=u.pengurus_id AND p.is_active=1 AND p.archived_at IS NULL
               JOIN pembimbing_assignments pa ON pa.pengurus_id=p.id
               JOIN tahun_ajaran ta ON ta.id=pa.tahun_ajaran_id AND ta.status='Aktif' AND ta.archived_at IS NULL
              WHERE u.id=? AND u.is_active=1 AND pa.tahun_ajaran_id=?
                AND pa.is_active=1 AND pa.archived_at IS NULL
                AND pa.tanggal_mulai<=? AND (pa.tanggal_selesai IS NULL OR pa.tanggal_selesai>=?)
                AND ((pa.target_type='Kelas' AND EXISTS (
                        SELECT 1 FROM plotting_kelas pk
                         WHERE pk.id_santri=? AND pk.id_tahun=? AND pk.id_kelas=pa.kelas_id AND pk.status='Aktif'
                    )) OR (pa.target_type='Kamar' AND EXISTS (
                        SELECT 1 FROM plotting_kamar pm
                         WHERE pm.id_santri=? AND pm.id_tahun=? AND pm.id_kamar=pa.kamar_id
                    )))
              ORDER BY pa.id LIMIT 1",
            [$userId, $tahunId, $date, $date, $santriId, $tahunId, $santriId, $tahunId]
        );
    }

    public function murobiAssignment(int $userId, int $santriId, int $tahunId): ?array
    {
        return $this->one(
            "SELECT ma.id,ma.guru_id
               FROM users u
               JOIN user_roles ur ON ur.user_id=u.id
               JOIN roles r ON r.id=ur.role_id AND r.slug='guru'
               JOIN guru g ON g.id=u.guru_id AND g.is_active=1 AND g.archived_at IS NULL
               JOIN murobi_assignments ma ON ma.guru_id=g.id
               JOIN tahun_ajaran ta ON ta.id=ma.tahun_ajaran_id AND ta.status='Aktif' AND ta.archived_at IS NULL
              WHERE u.id=? AND u.is_active=1 AND ma.tahun_ajaran_id=?
                AND ma.is_active=1 AND ma.archived_at IS NULL
                AND ma.tanggal_mulai<=CURDATE() AND (ma.tanggal_selesai IS NULL OR ma.tanggal_selesai>=CURDATE())
                AND ((ma.target_type='Kelas' AND EXISTS (
                        SELECT 1 FROM plotting_kelas pk
                         WHERE pk.id_santri=? AND pk.id_tahun=? AND pk.id_kelas=ma.kelas_id AND pk.status='Aktif'
                    )) OR (ma.target_type='Kamar' AND EXISTS (
                        SELECT 1 FROM plotting_kamar pm
                         WHERE pm.id_santri=? AND pm.id_tahun=? AND pm.id_kamar=ma.kamar_id
                    )))
              ORDER BY ma.id LIMIT 1",
            [$userId, $tahunId, $santriId, $tahunId, $santriId, $tahunId]
        );
    }

    /** @return array<int,array<string,mixed>> */
    public function studentOptions(int $userId): array
    {
        return $this->all(
            "SELECT DISTINCT s.id AS santri_id,ta.id AS tahun_ajaran_id,s.nama_santri,ta.tahun,ta.semester
               FROM users u
               JOIN user_roles ur ON ur.user_id=u.id
               JOIN roles r ON r.id=ur.role_id AND r.slug='pengurus'
               JOIN pengurus p ON p.id=u.pengurus_id AND p.is_active=1 AND p.archived_at IS NULL
               JOIN pembimbing_assignments pa ON pa.pengurus_id=p.id
               JOIN tahun_ajaran ta ON ta.id=pa.tahun_ajaran_id AND ta.status='Aktif' AND ta.archived_at IS NULL
               JOIN santri s ON s.is_active=1 AND s.archived_at IS NULL
              WHERE u.id=? AND u.is_active=1 AND pa.is_active=1 AND pa.archived_at IS NULL
                AND pa.tanggal_mulai<=CURDATE() AND (pa.tanggal_selesai IS NULL OR pa.tanggal_selesai>=CURDATE())
                AND ((pa.target_type='Kelas' AND EXISTS (
                        SELECT 1 FROM plotting_kelas pk WHERE pk.id_santri=s.id AND pk.id_tahun=ta.id
                          AND pk.id_kelas=pa.kelas_id AND pk.status='Aktif'
                    )) OR (pa.target_type='Kamar' AND EXISTS (
                        SELECT 1 FROM plotting_kamar pm WHERE pm.id_santri=s.id AND pm.id_tahun=ta.id
                          AND pm.id_kamar=pa.kamar_id
                    )))
              ORDER BY s.nama_santri,s.id"
            , [$userId]
        );
    }

    /** @return array<int,array<string,mixed>> */
    public function katalogOptions(): array
    {
        return $this->all(
            "SELECT k.id,k.kode,k.nama,k.tingkat,k.poin_default,c.nama AS kategori_nama
               FROM v3_katalog k JOIN v3_kategori c ON c.id=k.kategori_id
              WHERE k.is_active=1 AND k.archived_at IS NULL
                AND k.tanggal_mulai<=CURDATE() AND (k.tanggal_selesai IS NULL OR k.tanggal_selesai>=CURDATE())
                AND c.is_active=1 AND c.archived_at IS NULL
                AND c.tanggal_mulai<=CURDATE() AND (c.tanggal_selesai IS NULL OR c.tanggal_selesai>=CURDATE())
              ORDER BY c.nama,k.tingkat,k.nama"
        );
    }

    public function insertViolation(array $row): int
    {
        return $this->insert(
            'INSERT INTO v3_pelanggaran
                (santri_id,tahun_ajaran_id,pembimbing_id,pembimbing_assignment_id,katalog_id,
                 waktu_kejadian,tempat,uraian,saksi,kategori_snapshot,tingkat_snapshot,poin_snapshot,
                 cakupan_snapshot,status,fingerprint,idempotency_key,revisi_dari_id,alasan_revisi,
                 created_by,updated_by)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [
                $row['santri_id'], $row['tahun_ajaran_id'], $row['pembimbing_id'],
                $row['pembimbing_assignment_id'], $row['katalog_id'], $row['waktu_kejadian'],
                $row['tempat'], $row['uraian'], $row['saksi'], $row['kategori_snapshot'],
                $row['tingkat_snapshot'], $row['poin_snapshot'], $row['cakupan_snapshot'],
                $row['status'], $row['fingerprint'], $row['idempotency_key'],
                $row['revisi_dari_id'], $row['alasan_revisi'], $row['created_by'], $row['updated_by'],
            ]
        );
    }

    public function violation(int $id, bool $lock = false): ?array
    {
        return $this->one(
            'SELECT p.*,s.nama_santri,ta.tahun,ta.semester,k.kode AS katalog_kode,
                    (SELECT c.id FROM v3_pelanggaran c WHERE c.revisi_dari_id=p.id LIMIT 1) AS digantikan_oleh_id
               FROM v3_pelanggaran p
               JOIN santri s ON s.id=p.santri_id
               JOIN tahun_ajaran ta ON ta.id=p.tahun_ajaran_id
               LEFT JOIN v3_katalog k ON k.id=p.katalog_id
              WHERE p.id=?' . ($lock ? ' FOR UPDATE' : ''),
            [$id]
        );
    }

    public function visibleViolation(int $id, int $userId, string $mode): ?array
    {
        [$scope, $params] = $this->scopeSql($mode, $userId, 'p');
        return $this->one(
            'SELECT p.*,s.nama_santri,ta.tahun,ta.semester,k.kode AS katalog_kode,
                    (SELECT c.id FROM v3_pelanggaran c WHERE c.revisi_dari_id=p.id LIMIT 1) AS digantikan_oleh_id
               FROM v3_pelanggaran p
               JOIN santri s ON s.id=p.santri_id
               JOIN tahun_ajaran ta ON ta.id=p.tahun_ajaran_id
               LEFT JOIN v3_katalog k ON k.id=p.katalog_id
              WHERE p.id=? AND (' . $scope . ')',
            [$id, ...$params]
        );
    }

    /** @return array{rows:array<int,array<string,mixed>>,total:int,page:int,per_page:int} */
    public function page(int $userId, string $mode, array $filters): array
    {
        foreach (['page','santri_id','tahun_ajaran_id','status'] as $key) {
            if (isset($filters[$key]) && !is_scalar($filters[$key])) {
                throw new V3Exception('Filter tidak valid.');
            }
        }
        $page = max(1, min(1000000, (int) ($filters['page'] ?? 1)));
        $size = 25;
        $status = (string) ($filters['status'] ?? '');
        if (!in_array($status, ['', 'Dicatat', 'Ditindaklanjuti', 'Selesai', 'Dibatalkan'], true)) {
            throw new V3Exception('Status filter tidak valid.');
        }
        foreach (['santri_id','tahun_ajaran_id'] as $key) {
            if (($filters[$key] ?? '') !== '' && !preg_match('/^[1-9][0-9]*$/D', (string) $filters[$key])) {
                throw new V3Exception('Filter angka tidak valid.');
            }
        }
        [$scope, $scopeParams] = $this->scopeSql($mode, $userId, 'p');
        $where = ['p.archived_at IS NULL', 'NOT EXISTS (SELECT 1 FROM v3_pelanggaran nx WHERE nx.revisi_dari_id=p.id)', '(' . $scope . ')'];
        $params = $scopeParams;
        if ($status !== '') { $where[] = 'p.status=?'; $params[] = $status; }
        foreach (['santri_id','tahun_ajaran_id'] as $key) {
            if (($filters[$key] ?? '') !== '') { $where[] = 'p.'.$key.'=?'; $params[] = (int) $filters[$key]; }
        }
        $from = ' FROM v3_pelanggaran p JOIN santri s ON s.id=p.santri_id JOIN tahun_ajaran ta ON ta.id=p.tahun_ajaran_id WHERE ' . implode(' AND ', $where);
        $total = (int) ($this->one('SELECT COUNT(*) AS n'.$from, $params)['n'] ?? 0);
        $rows = $this->all(
            'SELECT p.id,p.santri_id,p.tahun_ajaran_id,p.waktu_kejadian,p.tempat,p.kategori_snapshot,
                    p.tingkat_snapshot,p.poin_snapshot,p.status,p.version,p.created_at,
                    s.nama_santri,ta.tahun,ta.semester'.$from.' ORDER BY p.id DESC LIMIT ? OFFSET ?',
            [...$params, $size, ($page - 1) * $size]
        );
        return ['rows'=>$rows,'total'=>$total,'page'=>$page,'per_page'=>$size];
    }

    /**
     * Tandai catatan sumber sudah digantikan revisi. Fingerprint dilepas agar
     * hanya catatan yang masih berlaku menempati slot unik duplikasi; nilainya
     * turunan murni dari kolom bisnis yang tetap tersimpan.
     */
    public function updateViolationVersion(int $id, int $expectedVersion, int $actorId): bool
    {
        return $this->execute(
            'UPDATE v3_pelanggaran SET version=version+1,updated_by=?,fingerprint=NULL WHERE id=? AND version=?',
            [$actorId, $id, $expectedVersion]
        ) === 1;
    }

    /**
     * Pembatalan menyimpan alasannya pada kolom sendiri sehingga alasan revisi
     * milik koreksi sebelumnya tidak tertimpa, dan melepas fingerprint agar
     * kejadian yang sama boleh dicatat ulang setelah dibatalkan.
     */
    public function cancelViolation(int $id, int $expectedVersion, string $reason, int $actorId): bool
    {
        return $this->execute(
            "UPDATE v3_pelanggaran
                SET status='Dibatalkan',alasan_pembatalan=?,fingerprint=NULL,version=version+1,updated_by=?
              WHERE id=? AND version=? AND status<>'Dibatalkan'",
            [$reason, $actorId, $id, $expectedVersion]
        ) === 1;
    }

    public function positiveLedger(int $pelanggaranId): ?array
    {
        return $this->one(
            'SELECT l.* FROM v3_poin_ledger l
              WHERE l.pelanggaran_id=? AND l.perubahan_poin>=0 AND l.archived_at IS NULL
              ORDER BY l.id DESC LIMIT 1 FOR UPDATE',
            [$pelanggaranId]
        );
    }

    public function insertLedger(int $pelanggaranId, int $santriId, int $tahunId, int $delta, string $reason, string $eventKey, ?int $reverses, int $actorId): int
    {
        return $this->insert(
            'INSERT INTO v3_poin_ledger
                (pelanggaran_id,santri_id,tahun_ajaran_id,perubahan_poin,alasan,event_key,pembalik_dari_id,created_by,updated_by)
             VALUES (?,?,?,?,?,?,?,?,?)',
            [$pelanggaranId,$santriId,$tahunId,$delta,$reason,$eventKey,$reverses,$actorId,$actorId]
        );
    }

    public function reconcile(int $santriId, int $tahunId, int $actorId): int
    {
        $total = (int) ($this->one(
            'SELECT COALESCE(SUM(perubahan_poin),0) AS total
               FROM v3_poin_ledger
              WHERE santri_id=? AND tahun_ajaran_id=? AND archived_at IS NULL',
            [$santriId,$tahunId]
        )['total'] ?? 0);
        $this->execute(
            'UPDATE v3_poin_agregat
                SET total_poin=?,direkonsiliasi_pada=NOW(),updated_by=?,version=version+1
              WHERE santri_id=? AND tahun_ajaran_id=?',
            [$total,$actorId,$santriId,$tahunId]
        );
        return $total;
    }

    public function aggregate(int $santriId, int $tahunId): ?array
    {
        return $this->one(
            'SELECT total_poin,direkonsiliasi_pada,version
               FROM v3_poin_agregat WHERE santri_id=? AND tahun_ajaran_id=?',
            [$santriId,$tahunId]
        );
    }

    /** @return array<int,array<string,mixed>> */
    public function ledgerHistory(int $santriId, int $tahunId): array
    {
        return $this->all(
            'SELECT id,pelanggaran_id,perubahan_poin,alasan,event_key,pembalik_dari_id,created_at
               FROM v3_poin_ledger
              WHERE santri_id=? AND tahun_ajaran_id=? AND archived_at IS NULL
              ORDER BY id',
            [$santriId,$tahunId]
        );
    }

    /** @return array<int,array<string,mixed>> */
    public function activeThresholds(int $tahunId, int $total): array
    {
        return $this->all(
            "SELECT a.id,a.label,a.rekomendasi
               FROM v3_ambang a JOIN tahun_ajaran ta ON ta.id=a.tahun_ajaran_id
              WHERE a.tahun_ajaran_id=? AND ta.status='Aktif' AND ta.archived_at IS NULL
                AND a.is_active=1 AND a.archived_at IS NULL
                AND a.tanggal_mulai<=CURDATE() AND (a.tanggal_selesai IS NULL OR a.tanggal_selesai>=CURDATE())
                AND a.nilai_minimum<=? AND (a.nilai_maksimum IS NULL OR a.nilai_maksimum>=?)
              ORDER BY a.nilai_minimum DESC,a.id",
            [$tahunId,$total,$total]
        );
    }

    public function insertRecommendation(int $santriId, int $tahunId, array $threshold, int $violationId, int $total, int $actorId): ?int
    {
        $event = 'v3:rekomendasi:'.$santriId.':'.$tahunId.':'.(int)$threshold['id'];
        $affected = $this->execute(
            'INSERT INTO v3_rekomendasi
                (santri_id,tahun_ajaran_id,ambang_id,dipicu_oleh_pelanggaran_id,total_poin_snapshot,
                 label_snapshot,rekomendasi_snapshot,event_key,created_by,updated_by)
             VALUES (?,?,?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)',
            [$santriId,$tahunId,(int)$threshold['id'],$violationId,$total,$threshold['label'],$threshold['rekomendasi'],$event,$actorId,$actorId]
        );
        if ($affected !== 1) { return null; }
        return (int) $this->db->insert_id;
    }

    /**
     * Selaraskan masa berlaku rekomendasi dengan total poin terkini.
     *
     * Rekomendasi tidak pernah dihapus dan tetap unik per santri/tahun/ambang.
     * Ketika pembatalan atau koreksi menarik total keluar dari rentang ambang,
     * barisnya ditandai tidak berlaku; ketika total kembali masuk rentang,
     * tanda itu dilepas sehingga pembimbing melihat antrean yang jujur.
     *
     * @return array{dinonaktifkan:array<int,int>,dipulihkan:array<int,int>}
     */
    public function refreshRecommendationValidity(int $santriId, int $tahunId, int $total, int $actorId): array
    {
        $rows = $this->all(
            'SELECT r.id,r.tidak_berlaku_pada,a.nilai_minimum,a.nilai_maksimum
               FROM v3_rekomendasi r
               JOIN v3_ambang a ON a.id=r.ambang_id
              WHERE r.santri_id=? AND r.tahun_ajaran_id=? AND r.archived_at IS NULL
              ORDER BY r.id FOR UPDATE',
            [$santriId, $tahunId]
        );
        $disabled = [];
        $restored = [];
        foreach ($rows as $row) {
            $maximum = $row['nilai_maksimum'] === null ? null : (int) $row['nilai_maksimum'];
            $inBand = (int) $row['nilai_minimum'] <= $total && ($maximum === null || $maximum >= $total);
            $marked = $row['tidak_berlaku_pada'] !== null;
            if (!$inBand && !$marked) {
                $this->execute(
                    'UPDATE v3_rekomendasi
                        SET tidak_berlaku_pada=NOW(),tidak_berlaku_alasan=?,updated_by=?,version=version+1
                      WHERE id=? AND tidak_berlaku_pada IS NULL',
                    ['Total poin ' . $total . ' berada di luar rentang ambang.', $actorId, (int) $row['id']]
                );
                $disabled[] = (int) $row['id'];
                continue;
            }
            if ($inBand && $marked) {
                $this->execute(
                    'UPDATE v3_rekomendasi
                        SET tidak_berlaku_pada=NULL,tidak_berlaku_alasan=NULL,updated_by=?,version=version+1
                      WHERE id=? AND tidak_berlaku_pada IS NOT NULL',
                    [$actorId, (int) $row['id']]
                );
                $restored[] = (int) $row['id'];
            }
        }
        return ['dinonaktifkan' => $disabled, 'dipulihkan' => $restored];
    }

    /** @return array<int,array<string,mixed>> */
    public function recommendations(int $santriId, int $tahunId): array
    {
        return $this->all(
            'SELECT id,ambang_id,dipicu_oleh_pelanggaran_id,total_poin_snapshot,label_snapshot,
                    rekomendasi_snapshot,status,tidak_berlaku_pada,tidak_berlaku_alasan,created_at
               FROM v3_rekomendasi
              WHERE santri_id=? AND tahun_ajaran_id=? AND archived_at IS NULL ORDER BY id',
            [$santriId,$tahunId]
        );
    }

    /** @return array<int,string> */
    public function configurationWarnings(): array
    {
        $warnings = [];
        $activeYears = $this->all("SELECT id,tahun,semester FROM tahun_ajaran WHERE status='Aktif' AND archived_at IS NULL ORDER BY id");
        foreach ($activeYears as $year) {
            $active = (int) ($this->one(
                "SELECT COUNT(*) AS n FROM v3_ambang WHERE tahun_ajaran_id=? AND is_active=1 AND archived_at IS NULL
                  AND tanggal_mulai<=CURDATE() AND (tanggal_selesai IS NULL OR tanggal_selesai>=CURDATE())",
                [(int)$year['id']]
            )['n'] ?? 0);
            if ($active === 0) {
                $warnings[] = 'Belum ada ambang rekomendasi aktif untuk '.$year['tahun'].' / '.$year['semester'].'.';
            }
        }
        $inactive = (int) ($this->one(
            "SELECT COUNT(*) AS n FROM v3_ambang a JOIN tahun_ajaran ta ON ta.id=a.tahun_ajaran_id
              WHERE a.is_active=1 AND a.archived_at IS NULL AND ta.status<>'Aktif'"
        )['n'] ?? 0);
        if ($inactive > 0) {
            $warnings[] = $inactive.' ambang tersimpan pada tahun non-aktif dan belum dapat memicu rekomendasi.';
        }
        return $warnings;
    }

    /** @return array<int,int> */
    public function relatedMurobiUsers(int $santriId, int $tahunId): array
    {
        return array_map('intval', array_column($this->all(
            "SELECT DISTINCT u.id
               FROM users u JOIN user_roles ur ON ur.user_id=u.id JOIN roles r ON r.id=ur.role_id AND r.slug='guru'
               JOIN guru g ON g.id=u.guru_id AND g.is_active=1 AND g.archived_at IS NULL
               JOIN murobi_assignments ma ON ma.guru_id=g.id
               JOIN tahun_ajaran ta ON ta.id=ma.tahun_ajaran_id AND ta.status='Aktif' AND ta.archived_at IS NULL
              WHERE u.is_active=1 AND ma.tahun_ajaran_id=? AND ma.is_active=1 AND ma.archived_at IS NULL
                AND ma.tanggal_mulai<=CURDATE() AND (ma.tanggal_selesai IS NULL OR ma.tanggal_selesai>=CURDATE())
                AND ((ma.target_type='Kelas' AND EXISTS (SELECT 1 FROM plotting_kelas pk WHERE pk.id_santri=? AND pk.id_tahun=? AND pk.id_kelas=ma.kelas_id AND pk.status='Aktif'))
                  OR (ma.target_type='Kamar' AND EXISTS (SELECT 1 FROM plotting_kamar pm WHERE pm.id_santri=? AND pm.id_tahun=? AND pm.id_kamar=ma.kamar_id)))",
            [$tahunId,$santriId,$tahunId,$santriId,$tahunId]
        ), 'id'));
    }

    /** @return array<int,int> */
    public function relatedPembimbingUsers(int $santriId, int $tahunId): array
    {
        return array_map('intval', array_column($this->all(
            "SELECT DISTINCT u.id
               FROM users u JOIN user_roles ur ON ur.user_id=u.id JOIN roles r ON r.id=ur.role_id AND r.slug='pengurus'
               JOIN pengurus p ON p.id=u.pengurus_id AND p.is_active=1 AND p.archived_at IS NULL
               JOIN pembimbing_assignments pa ON pa.pengurus_id=p.id
               JOIN tahun_ajaran ta ON ta.id=pa.tahun_ajaran_id AND ta.status='Aktif' AND ta.archived_at IS NULL
              WHERE u.is_active=1 AND pa.tahun_ajaran_id=? AND pa.is_active=1 AND pa.archived_at IS NULL
                AND pa.tanggal_mulai<=CURDATE() AND (pa.tanggal_selesai IS NULL OR pa.tanggal_selesai>=CURDATE())
                AND ((pa.target_type='Kelas' AND EXISTS (SELECT 1 FROM plotting_kelas pk WHERE pk.id_santri=? AND pk.id_tahun=? AND pk.id_kelas=pa.kelas_id AND pk.status='Aktif'))
                  OR (pa.target_type='Kamar' AND EXISTS (SELECT 1 FROM plotting_kamar pm WHERE pm.id_santri=? AND pm.id_tahun=? AND pm.id_kamar=pa.kamar_id)))",
            [$tahunId,$santriId,$tahunId,$santriId,$tahunId]
        ), 'id'));
    }

    public function enqueueGeneric(string $eventKey, string $eventType, int $userId, string $title, string $body, string $data): void
    {
        $this->insertOutbox($eventKey,$eventType,'InApp',$userId,$title,$body,$data,'Sent');
        $push = $this->one(
            'SELECT 1 AS ada FROM pengaturan_notifikasi pn
              WHERE pn.singleton=1 AND pn.push_enabled=1 AND EXISTS (
                    SELECT 1 FROM perangkat_push pp
                     WHERE pp.user_id=? AND pp.dicabut_pada IS NULL AND pp.push_aktif=1
              ) LIMIT 1',
            [$userId]
        );
        if ($push !== null) {
            $this->insertOutbox($eventKey,$eventType,'Push',$userId,$title,$body,$data,'Queued');
        }
    }

    private function insertOutbox(string $eventKey, string $eventType, string $channel, int $userId, string $title, string $body, string $data, string $status): void
    {
        $this->execute(
            'INSERT INTO notifikasi_outbox
                (event_key,event_type,kanal,penerima_user_id,pengajuan_id,judul,isi,data_json,status,
                 percobaan,dikirim_pada,tersedia_pada,created_at,updated_at)
             VALUES (?,?,?,?,NULL,?,?,?,?,0,'.($channel==='InApp'?'NOW()':'NULL').','.($channel==='Push'?'NOW()':'NULL').',NOW(),NOW())
             ON DUPLICATE KEY UPDATE id=id',
            [$eventKey,$eventType,$channel,$userId,$title,$body,$data,$status]
        );
    }

    public function insertMurobiNote(int $violationId, int $sourceVersion, int $guruId, int $assignmentId, ?string $note, string $eventKey, int $actorId): int
    {
        return $this->insert(
            'INSERT INTO v3_murobi_catatan
                (pelanggaran_id,sumber_version,guru_id,murobi_assignment_id,dilihat_pada,diketahui_pada,
                 catatan,event_key,created_by,updated_by)
             VALUES (?,?,?,?,NOW(),NOW(),?,?,?,?)',
            [$violationId,$sourceVersion,$guruId,$assignmentId,$note,$eventKey,$actorId,$actorId]
        );
    }

    /** @return array<int,array<string,mixed>> */
    public function murobiNotes(int $violationId): array
    {
        return $this->all(
            'SELECT id,guru_id,dilihat_pada,diketahui_pada,catatan,sumber_version,created_at
               FROM v3_murobi_catatan WHERE pelanggaran_id=? AND archived_at IS NULL ORDER BY id',
            [$violationId]
        );
    }

    public function insertAttachment(int $violationId, int $sourceVersion, array $file, int $actorId): int
    {
        return $this->insert(
            'INSERT INTO v3_lampiran
                (pelanggaran_id,sumber_version,nama_aman,mime,ukuran,sha256,lokasi_privat,created_by,updated_by)
             VALUES (?,?,?,?,?,?,?,?,?)',
            [$violationId,$sourceVersion,$file['name'],$file['mime'],$file['size'],$file['sha256'],$file['path'],$actorId,$actorId]
        );
    }

    public function attachment(int $id): ?array
    {
        return $this->one(
            'SELECT id,pelanggaran_id,nama_aman,mime,ukuran,sha256,lokasi_privat,created_at
               FROM v3_lampiran WHERE id=? AND archived_at IS NULL',
            [$id]
        );
    }

    /** @return array<int,array<string,mixed>> */
    public function attachments(int $violationId): array
    {
        return $this->all(
            'SELECT id,nama_aman,mime,ukuran,sha256,created_at
               FROM v3_lampiran WHERE pelanggaran_id=? AND archived_at IS NULL ORDER BY id',
            [$violationId]
        );
    }

    /** @return array<int,array<string,mixed>> */
    public function directRevisions(int $parentId): array
    {
        return $this->all('SELECT id FROM v3_pelanggaran WHERE revisi_dari_id=? ORDER BY id',[$parentId]);
    }

    private function scopeSql(string $mode, int $userId, string $alias): array
    {
        if ($mode === 'admin') { return ['1=1', []]; }
        $assignment = $mode === 'pembimbing' ? 'pembimbing_assignments' : 'murobi_assignments';
        $master = $mode === 'pembimbing' ? 'pengurus' : 'guru';
        $userColumn = $mode === 'pembimbing' ? 'pengurus_id' : 'guru_id';
        $role = $mode === 'pembimbing' ? 'pengurus' : 'guru';
        $prefix = $mode === 'pembimbing' ? 'pa' : 'ma';
        $sql = "EXISTS (SELECT 1 FROM users ux
                 JOIN user_roles urx ON urx.user_id=ux.id
                 JOIN roles rx ON rx.id=urx.role_id AND rx.slug='{$role}'
                 JOIN {$master} mx ON mx.id=ux.{$userColumn} AND mx.is_active=1 AND mx.archived_at IS NULL
                 JOIN {$assignment} {$prefix} ON {$prefix}.{$userColumn}=mx.id
                 JOIN tahun_ajaran tx ON tx.id={$prefix}.tahun_ajaran_id AND tx.status='Aktif' AND tx.archived_at IS NULL
                WHERE ux.id=? AND ux.is_active=1 AND {$prefix}.tahun_ajaran_id={$alias}.tahun_ajaran_id
                  AND {$prefix}.is_active=1 AND {$prefix}.archived_at IS NULL
                  AND {$prefix}.tanggal_mulai<=CURDATE() AND ({$prefix}.tanggal_selesai IS NULL OR {$prefix}.tanggal_selesai>=CURDATE())
                  AND (({$prefix}.target_type='Kelas' AND EXISTS (SELECT 1 FROM plotting_kelas pk WHERE pk.id_santri={$alias}.santri_id AND pk.id_tahun={$alias}.tahun_ajaran_id AND pk.id_kelas={$prefix}.kelas_id AND pk.status='Aktif'))
                    OR ({$prefix}.target_type='Kamar' AND EXISTS (SELECT 1 FROM plotting_kamar pm WHERE pm.id_santri={$alias}.santri_id AND pm.id_tahun={$alias}.tahun_ajaran_id AND pm.id_kamar={$prefix}.kamar_id))))";
        return [$sql, [$userId]];
    }

    public function all(string $sql, array $params = []): array
    {
        $statement = $this->statement($sql,$params);
        try {
            $result = $statement->get_result();
            if ($result === false) { $this->fail($statement->errno); }
            return $result->fetch_all(MYSQLI_ASSOC);
        } finally { $statement->close(); }
    }

    public function one(string $sql, array $params = []): ?array
    {
        return $this->all($sql,$params)[0] ?? null;
    }

    public function execute(string $sql, array $params = []): int
    {
        $statement = $this->statement($sql,$params);
        $affected = $statement->affected_rows;
        $statement->close();
        return $affected;
    }

    private function insert(string $sql, array $params): int
    {
        $statement = $this->statement($sql,$params);
        $id = (int) $statement->insert_id;
        $statement->close();
        if ($id < 1) { throw new V3Exception('Data tidak dapat disimpan.',503); }
        return $id;
    }

    private function statement(string $sql, array $params): mysqli_stmt
    {
        try {
            $statement = $this->db->prepare($sql);
            if ($statement === false) { $this->fail($this->db->errno); }
            if ($params !== []) {
                $types = implode('',array_map(static fn($value): string => is_int($value) ? 'i' : 's',$params));
                if (!$statement->bind_param($types,...$params)) { $this->fail($statement->errno); }
            }
            if (!$statement->execute()) { $this->fail($statement->errno); }
            return $statement;
        } catch (\mysqli_sql_exception $exception) {
            $this->fail((int)$exception->getCode());
        }
    }

    private function fail(int $errno): never
    {
        if ($errno === 1062) { throw new V3Exception('Permintaan menduplikasi catatan yang sudah ada.',409); }
        if (in_array($errno,[1205,1213],true)) { throw new V3Exception('Data sedang diperbarui. Muat ulang lalu coba lagi.',409); }
        throw new V3Exception('Data operasional tidak dapat diproses. Silakan coba lagi.',503);
    }
}
