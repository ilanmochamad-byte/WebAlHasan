<?php

declare(strict_types=1);

namespace App\Database;

use mysqli;
use RuntimeException;

/** Query daftar web. SQL/kolom/urutan hanya konstanta repository, bukan input URL. */
final class PageQuery
{
    public static function term(mixed $value): string
    {
        return is_scalar($value) ? mb_substr(trim((string) $value), 0, 100) : '';
    }

    public static function fetch(mysqli $db, string $sql, array $params, string $order, int $page, string $q = '', array $columns = [], int $perPage = 20): array
    {
        $perPage = max(1, min(100, $perPage));
        $where = '';
        if ($q !== '' && $columns !== []) {
            $where = ' WHERE (' . implode(' OR ', array_map(static fn (string $column): string => $column . ' LIKE ?', $columns)) . ')';
            foreach ($columns as $_) { $params[] = '%' . $q . '%'; }
        }
        $from = ' FROM (' . $sql . ') listed' . $where;
        $total = (int) self::rows($db, 'SELECT COUNT(*) total' . $from, $params)[0]['total'];
        $page = max(1, min($page, max(1, (int) ceil($total / $perPage))));
        $rows = self::rows($db, 'SELECT *' . $from . ' ORDER BY ' . $order . ' LIMIT ? OFFSET ?', [...$params, $perPage, ($page - 1) * $perPage]);
        return compact('rows', 'total', 'page', 'perPage');
    }

    private static function rows(mysqli $db, string $sql, array $params): array
    {
        $stmt = $db->prepare($sql);
        if ($stmt === false) { throw new RuntimeException('Daftar tidak dapat dibaca.'); }
        if ($params !== []) {
            $types = implode('', array_map(static fn (mixed $value): string => is_int($value) ? 'i' : 's', $params));
            $stmt->bind_param($types, ...$params);
        }
        if (!$stmt->execute()) {
            $stmt->close();
            throw new RuntimeException('Daftar tidak dapat dibaca.');
        }
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }
}
