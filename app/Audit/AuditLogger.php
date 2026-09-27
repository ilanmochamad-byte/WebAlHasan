<?php

declare(strict_types=1);

namespace App\Audit;

use mysqli;
use Throwable;

final class AuditLogger
{
    public function __construct(private mysqli $db)
    {
    }

    public function log(
        string $action,
        string $entityType,
        ?int $entityId = null,
        ?array $before = null,
        ?array $after = null,
        ?int $actorUserId = null
    ): bool {
        try {
            // Katalog/kategori/ambang adalah data master admin tanpa tabel revisi;
            // nilai sebelum/sesudahnya harus tetap terbaca di audit.
            if (str_starts_with($action, 'v3.') && !in_array($entityType, ['v3_katalog', 'v3_kategori', 'v3_ambang'], true)) {
                $before = $before === null ? null : $this->v3Metadata($before);
                $after = $after === null ? null : $this->v3Metadata($after);
            }
            $actorUserId ??= isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
            $beforeJson = $before === null ? null : json_encode($before, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $afterJson = $after === null ? null : json_encode($after, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $ip = substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
            $userAgent = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);

            $statement = $this->db->prepare(
                'INSERT INTO audit_logs (actor_user_id, action, entity_type, entity_id, before_json, after_json, ip_address, user_agent, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())'
            );
            if ($statement === false) {
                return false;
            }
            $statement->bind_param('ississss', $actorUserId, $action, $entityType, $entityId, $beforeJson, $afterJson, $ip, $userAgent);
            $saved = $statement->execute();
            $statement->close();

            return $saved;
        } catch (Throwable $exception) {
            error_log('Audit log gagal disimpan.');
            return false;
        }
    }

    /** Riwayat isi tetap di tabel bisnis privat; audit V3 menyimpan jejak hash. */
    private function v3Metadata(array $data): array
    {
        $sensitive = ['tujuan','ringkasan','ringkasan_internal','ringkasan_penutupan','hasil','tindak_lanjut','catatan','uraian','saksi','santri_nama','nama_santri','alasan','alasan_revisi','alasan_revisi_terakhir','alasan_pembatalan','alasan_penjadwalan_ulang','tempat'];
        $result = [];
        foreach ($data as $key => $value) {
            if (in_array($key, $sensitive, true)) {
                $result[$key.'_sha256'] = $value === null ? null : hash('sha256', (string)$value);
            } else {
                $result[$key] = is_array($value) ? $this->v3Metadata($value) : $value;
            }
        }
        return $result;
    }
}
