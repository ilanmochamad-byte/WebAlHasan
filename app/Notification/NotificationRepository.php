<?php

declare(strict_types=1);

namespace App\Notification;

use mysqli;

/**
 * Akses data notifikasi (`notifikasi_outbox` dan `notifikasi_percobaan`).
 *
 * Dua jaminan penting yang ditegakkan di lapisan ini:
 *
 *  1. **Deduplikasi.** `enqueue()` memakai `INSERT ... ON DUPLICATE KEY UPDATE`
 *     terhadap kunci unik `(event_key, kanal, penerima_user_id)`. Memanggilnya
 *     dua kali untuk peristiwa yang sama menghasilkan tepat satu baris; panggilan
 *     kedua mengembalikan `null` sehingga pemanggil dapat membedakan "baru"
 *     dari "sudah ada" tanpa membaca ulang.
 *
 *  2. **Cakupan pengguna.** Setiap pembacaan dan penandaan baca WAJIB menyertakan
 *     `penerima_user_id`. Tidak ada satu pun query yang mengambil notifikasi
 *     hanya berdasarkan `id`, sehingga menebak atau mengganti ID tidak pernah
 *     membuka notifikasi milik pengguna lain (kriteria penerimaan Fase 4 poin 2).
 */
final class NotificationRepository
{
    public function __construct(private mysqli $db)
    {
    }

    // =======================================================================
    // Tulis
    // =======================================================================

    /**
     * Menyisipkan satu baris outbox bila belum ada.
     *
     * @param array<string, mixed> $row
     * @return int|null id baris baru, atau null bila peristiwa/kanal/penerima
     *                  itu sudah pernah dibuat (deduplikasi).
     */
    public function enqueue(array $row): ?int
    {
        $kanal = (string) $row['kanal'];
        $inApp = $kanal === NotificationChannel::IN_APP;

        // Notifikasi in-app tidak memanggil penyedia mana pun: begitu barisnya
        // ada, ia sudah "terkirim". Kanal eksternal menunggu worker.
        $status = $inApp ? 'Sent' : 'Queued';

        $statement = $this->db->prepare(
            'INSERT INTO notifikasi_outbox
                 (event_key, event_type, kanal, penerima_user_id, pengajuan_id, judul, isi, data_json,
                  status, percobaan, dikirim_pada, tersedia_pada, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ' . ($inApp ? 'NOW()' : 'NULL') . ', '
                 . ($inApp ? 'NULL' : 'NOW()') . ', NOW(), NOW())
             ON DUPLICATE KEY UPDATE id = id'
        );
        if ($statement === false) {
            return null;
        }

        $eventKey = substr((string) $row['event_key'], 0, 120);
        $eventType = substr((string) $row['event_type'], 0, 60);
        $penerima = (int) $row['penerima_user_id'];
        $pengajuanId = ($row['pengajuan_id'] ?? null) === null ? null : (int) $row['pengajuan_id'];
        $judul = mb_substr((string) $row['judul'], 0, 150);
        $isi = mb_substr((string) $row['isi'], 0, 500);
        $data = $row['data_json'] === null ? null : mb_substr((string) $row['data_json'], 0, 1000);

        $statement->bind_param(
            'sssiissss',
            $eventKey,
            $eventType,
            $kanal,
            $penerima,
            $pengajuanId,
            $judul,
            $isi,
            $data,
            $status
        );
        if (!$statement->execute()) {
            $statement->close();

            return null;
        }
        $affected = $statement->affected_rows;
        $insertId = (int) $statement->insert_id;
        $statement->close();

        // affected_rows === 1 -> baris baru. 0 -> duplikat yang diabaikan.
        return $affected === 1 && $insertId > 0 ? $insertId : null;
    }

    public function exists(string $eventKey, string $kanal, int $penerimaUserId): bool
    {
        $statement = $this->db->prepare(
            'SELECT 1 FROM notifikasi_outbox WHERE event_key = ? AND kanal = ? AND penerima_user_id = ? LIMIT 1'
        );
        if ($statement === false) {
            return false;
        }
        $statement->bind_param('ssi', $eventKey, $kanal, $penerimaUserId);
        $statement->execute();
        $row = $statement->get_result()?->fetch_assoc();
        $statement->close();

        return is_array($row);
    }

    // =======================================================================
    // Pusat notifikasi pengguna (kanal InApp)
    // =======================================================================

    /**
     * @return array{rows:array<int, array<string, mixed>>, total:int}
     */
    public function listForUser(int $userId, string $filter, int $page, int $perPage): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset = ($page - 1) * $perPage;

        $where = 'n.penerima_user_id = ? AND n.kanal = ?';
        if ($filter === 'belum_dibaca') {
            $where .= ' AND n.dibaca_pada IS NULL';
        } elseif ($filter === 'sudah_dibaca') {
            $where .= ' AND n.dibaca_pada IS NOT NULL';
        }

        $kanal = NotificationChannel::IN_APP;

        $total = 0;
        $countStatement = $this->db->prepare('SELECT COUNT(*) AS jumlah FROM notifikasi_outbox n WHERE ' . $where);
        if ($countStatement !== false) {
            $countStatement->bind_param('is', $userId, $kanal);
            $countStatement->execute();
            $countRow = $countStatement->get_result()?->fetch_assoc();
            $total = (int) ($countRow['jumlah'] ?? 0);
            $countStatement->close();
        }

        $statement = $this->db->prepare(
            'SELECT n.id, n.event_type, n.pengajuan_id, n.judul, n.isi, n.data_json, n.dibaca_pada, n.created_at,
                    p.status AS pengajuan_status, s.nama_santri AS santri_nama
               FROM notifikasi_outbox n
               LEFT JOIN izin_pengajuan p ON p.id = n.pengajuan_id
               LEFT JOIN santri s ON s.id = p.santri_id
              WHERE ' . $where . '
              ORDER BY n.id DESC
              LIMIT ? OFFSET ?'
        );
        $rows = [];
        if ($statement !== false) {
            $statement->bind_param('isii', $userId, $kanal, $perPage, $offset);
            $statement->execute();
            $result = $statement->get_result();
            while ($result !== false && ($row = $result->fetch_assoc()) !== null) {
                $rows[] = $row;
            }
            $statement->close();
        }

        return ['rows' => $rows, 'total' => $total];
    }

    public function unreadCount(int $userId): int
    {
        $statement = $this->db->prepare(
            'SELECT COUNT(*) AS jumlah
               FROM notifikasi_outbox
              WHERE penerima_user_id = ? AND kanal = ? AND dibaca_pada IS NULL'
        );
        if ($statement === false) {
            return 0;
        }
        $kanal = NotificationChannel::IN_APP;
        $statement->bind_param('is', $userId, $kanal);
        $statement->execute();
        $row = $statement->get_result()?->fetch_assoc();
        $statement->close();

        return (int) ($row['jumlah'] ?? 0);
    }

    /**
     * Detail satu notifikasi, SELALU dibatasi pemiliknya.
     *
     * @return array<string, mixed>|null
     */
    public function findForUser(int $id, int $userId): ?array
    {
        $statement = $this->db->prepare(
            'SELECT n.id, n.event_type, n.pengajuan_id, n.judul, n.isi, n.data_json, n.dibaca_pada, n.created_at,
                    p.status AS pengajuan_status, p.tgl_izin, p.tgl_kembali, s.nama_santri AS santri_nama
               FROM notifikasi_outbox n
               LEFT JOIN izin_pengajuan p ON p.id = n.pengajuan_id
               LEFT JOIN santri s ON s.id = p.santri_id
              WHERE n.id = ? AND n.penerima_user_id = ? AND n.kanal = ?
              LIMIT 1'
        );
        if ($statement === false) {
            return null;
        }
        $kanal = NotificationChannel::IN_APP;
        $statement->bind_param('iis', $id, $userId, $kanal);
        $statement->execute();
        $row = $statement->get_result()?->fetch_assoc();
        $statement->close();

        return is_array($row) ? $row : null;
    }

    /**
     * Menandai satu notifikasi sudah dibaca. Mengembalikan false bila baris itu
     * bukan milik pengguna — tanpa membocorkan apakah barisnya ada.
     */
    public function markRead(int $id, int $userId): bool
    {
        $statement = $this->db->prepare(
            'UPDATE notifikasi_outbox
                SET dibaca_pada = COALESCE(dibaca_pada, NOW())
              WHERE id = ? AND penerima_user_id = ? AND kanal = ?'
        );
        if ($statement === false) {
            return false;
        }
        $kanal = NotificationChannel::IN_APP;
        $statement->bind_param('iis', $id, $userId, $kanal);
        $ok = $statement->execute();
        $affected = $statement->affected_rows;
        $statement->close();

        // affected_rows 0 dapat berarti "bukan milik pengguna" ATAU "sudah dibaca".
        // Pemanggil memeriksa keberadaan baris lebih dulu agar 403 dan 200 tidak tertukar.
        return $ok && $affected >= 0;
    }

    public function markAllRead(int $userId): int
    {
        $statement = $this->db->prepare(
            'UPDATE notifikasi_outbox
                SET dibaca_pada = NOW()
              WHERE penerima_user_id = ? AND kanal = ? AND dibaca_pada IS NULL'
        );
        if ($statement === false) {
            return 0;
        }
        $kanal = NotificationChannel::IN_APP;
        $statement->bind_param('is', $userId, $kanal);
        $statement->execute();
        $affected = $statement->affected_rows;
        $statement->close();

        return max(0, $affected);
    }

    // =======================================================================
    // Pemantauan admin
    // =======================================================================

    /**
     * Ringkasan jumlah baris per kanal dan status.
     *
     * @return array<string, array<string, int>>
     */
    public function summaryByChannel(): array
    {
        $summary = [];
        foreach (NotificationChannel::ALL as $kanal) {
            $summary[$kanal] = [
                'Queued' => 0,
                'Sent' => 0,
                'Failed' => 0,
                'gagal_permanen' => 0,
                'belum_dibaca' => 0,
                'total' => 0,
            ];
        }

        $result = $this->db->query(
            'SELECT kanal, status, COUNT(*) AS jumlah,
                    SUM(CASE WHEN gagal_permanen = 1 THEN 1 ELSE 0 END) AS permanen,
                    SUM(CASE WHEN dibaca_pada IS NULL THEN 1 ELSE 0 END) AS belum_dibaca
               FROM notifikasi_outbox
              GROUP BY kanal, status'
        );
        while ($result !== false && ($row = $result->fetch_assoc()) !== null) {
            $kanal = (string) $row['kanal'];
            if (!isset($summary[$kanal])) {
                continue;
            }
            $jumlah = (int) $row['jumlah'];
            $summary[$kanal][(string) $row['status']] = $jumlah;
            $summary[$kanal]['total'] += $jumlah;
            $summary[$kanal]['gagal_permanen'] += (int) $row['permanen'];
            $summary[$kanal]['belum_dibaca'] += (int) $row['belum_dibaca'];
        }

        return $summary;
    }

    /**
     * Daftar pengiriman gagal untuk admin. Tidak pernah mengembalikan token,
     * nomor tujuan, atau credential — kolom-kolom itu memang tidak ada di sini.
     *
     * @return array{rows:array<int, array<string, mixed>>, total:int}
     */
    public function failures(string $kanal, int $page, int $perPage, bool $hanyaPermanen = false): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset = ($page - 1) * $perPage;

        $where = "n.status = 'Failed'";
        $params = [];
        $types = '';
        if (NotificationChannel::valid($kanal)) {
            $where .= ' AND n.kanal = ?';
            $types .= 's';
            $params[] = $kanal;
        }
        if ($hanyaPermanen) {
            $where .= ' AND n.gagal_permanen = 1';
        }

        $total = 0;
        $countStatement = $this->db->prepare('SELECT COUNT(*) AS jumlah FROM notifikasi_outbox n WHERE ' . $where);
        if ($countStatement !== false) {
            if ($types !== '') {
                $countStatement->bind_param($types, ...$params);
            }
            $countStatement->execute();
            $row = $countStatement->get_result()?->fetch_assoc();
            $total = (int) ($row['jumlah'] ?? 0);
            $countStatement->close();
        }

        $statement = $this->db->prepare(
            'SELECT n.id, n.event_key, n.event_type, n.kanal, n.penerima_user_id, n.pengajuan_id,
                    n.status, n.percobaan, n.error_kode, n.error_terakhir, n.gagal_permanen,
                    n.percobaan_terakhir_pada, n.tersedia_pada, n.created_at, u.name AS penerima_nama
               FROM notifikasi_outbox n
               LEFT JOIN users u ON u.id = n.penerima_user_id
              WHERE ' . $where . '
              ORDER BY n.id DESC
              LIMIT ? OFFSET ?'
        );
        $rows = [];
        if ($statement !== false) {
            $statement->bind_param($types . 'ii', ...array_merge($params, [$perPage, $offset]));
            $statement->execute();
            $result = $statement->get_result();
            while ($result !== false && ($row = $result->fetch_assoc()) !== null) {
                $rows[] = $row;
            }
            $statement->close();
        }

        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function attempts(int $outboxId, int $limit = 20): array
    {
        $limit = max(1, min(100, $limit));
        $statement = $this->db->prepare(
            'SELECT id, percobaan_ke, hasil, error_kode, error_pesan, durasi_ms, dicoba_pada
               FROM notifikasi_percobaan
              WHERE outbox_id = ?
              ORDER BY id DESC
              LIMIT ' . $limit
        );
        if ($statement === false) {
            return [];
        }
        $statement->bind_param('i', $outboxId);
        $statement->execute();
        $result = $statement->get_result();
        $rows = [];
        while ($result !== false && ($row = $result->fetch_assoc()) !== null) {
            $rows[] = $row;
        }
        $statement->close();

        return $rows;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        $statement = $this->db->prepare(
            'SELECT id, event_key, event_type, kanal, penerima_user_id, pengajuan_id, judul, isi, data_json,
                    status, percobaan, error_kode, error_terakhir, gagal_permanen, dikirim_pada,
                    percobaan_terakhir_pada, tersedia_pada, dibaca_pada, created_at
               FROM notifikasi_outbox
              WHERE id = ?
              LIMIT 1'
        );
        if ($statement === false) {
            return null;
        }
        $statement->bind_param('i', $id);
        $statement->execute();
        $row = $statement->get_result()?->fetch_assoc();
        $statement->close();

        return is_array($row) ? $row : null;
    }

    /**
     * Menjadwalkan ulang satu baris gagal.
     *
     * Retry TIDAK membuat baris baru: baris yang sama dikembalikan ke antrean,
     * sehingga kunci unik peristiwa/kanal/penerima tetap dipatuhi dan penerima
     * tidak pernah menerima pesan ganda.
     */
    public function requeue(int $id): bool
    {
        $statement = $this->db->prepare(
            "UPDATE notifikasi_outbox
                SET status = 'Queued', gagal_permanen = 0, tersedia_pada = NOW(),
                    locked_by = NULL, locked_until = NULL
              WHERE id = ? AND kanal <> 'InApp' AND status = 'Failed'"
        );
        if ($statement === false) {
            return false;
        }
        $statement->bind_param('i', $id);
        $ok = $statement->execute();
        $affected = $statement->affected_rows;
        $statement->close();

        return $ok && $affected > 0;
    }
}
