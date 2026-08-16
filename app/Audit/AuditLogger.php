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
    ): void {
        try {
            $actorUserId ??= isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
            $beforeJson = $before === null ? null : json_encode($before, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $afterJson = $after === null ? null : json_encode($after, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $ip = substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
            $userAgent = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);

            $statement = $this->db->prepare(
                'INSERT INTO audit_logs (actor_user_id, action, entity_type, entity_id, before_json, after_json, ip_address, user_agent, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())'
            );
            if ($statement === false) {
                return;
            }
            $statement->bind_param('ississss', $actorUserId, $action, $entityType, $entityId, $beforeJson, $afterJson, $ip, $userAgent);
            $statement->execute();
            $statement->close();
        } catch (Throwable $exception) {
            error_log('Audit log gagal: ' . $exception->getMessage());
        }
    }
}

