<?php

declare(strict_types=1);

namespace App\Notification;

use mysqli;

/**
 * Sewa (lease) proses worker berbasis baris.
 *
 * cPanel menjalankan cron tanpa jaminan proses sebelumnya sudah selesai. Tanpa
 * pengaman, dua proses dapat memproses baris outbox yang sama dan penerima
 * menerima pesan dua kali.
 *
 * Sewa ini diambil dengan SATU pernyataan UPDATE bersyarat. Karena UPDATE
 * bersifat atomik pada InnoDB, hanya satu proses yang pernah memperoleh
 * `affected_rows > 0`; proses lain berhenti dengan tenang (bukan error).
 * Sewa memiliki kedaluwarsa sehingga proses yang mati mendadak tidak mengunci
 * antrean selamanya.
 *
 * Sewa ini adalah lapisan PERTAMA. Lapisan kedua — klaim per baris pada
 * `OutboxRepository::claim()` — tetap berlaku, sehingga bahkan bila sewa
 * kedaluwarsa di tengah jalan, satu baris tidak dapat diproses dua worker.
 */
final class WorkerLock
{
    public const PUSH = 'notifikasi:push';
    public const WHATSAPP = 'notifikasi:whatsapp';

    private ?string $owner = null;
    private ?string $name = null;

    public function __construct(private mysqli $db)
    {
    }

    /**
     * @param int $ttlSeconds umur sewa; harus lebih panjang dari satu putaran worker.
     */
    public function acquire(string $name, int $ttlSeconds = 300): bool
    {
        $ttlSeconds = max(30, min(3600, $ttlSeconds));
        $owner = substr(gethostname() . ':' . getmypid() . ':' . bin2hex(random_bytes(4)), 0, 64);

        // Baris sewa dibuat migrasi 008; INSERT di sini hanya jaring pengaman
        // bila baris terhapus manual.
        $seed = $this->db->prepare(
            'INSERT INTO notifikasi_worker_lock (nama, pemilik, dikunci_pada, kedaluwarsa_pada)
             VALUES (?, \'\', \'1970-01-02 00:00:00\', \'1970-01-02 00:00:00\')
             ON DUPLICATE KEY UPDATE nama = VALUES(nama)'
        );
        if ($seed !== false) {
            $seed->bind_param('s', $name);
            $seed->execute();
            $seed->close();
        }

        $statement = $this->db->prepare(
            'UPDATE notifikasi_worker_lock
                SET pemilik = ?, dikunci_pada = NOW(), kedaluwarsa_pada = DATE_ADD(NOW(), INTERVAL ? SECOND)
              WHERE nama = ? AND kedaluwarsa_pada <= NOW()'
        );
        if ($statement === false) {
            return false;
        }
        $statement->bind_param('sis', $owner, $ttlSeconds, $name);
        $ok = $statement->execute();
        $affected = $statement->affected_rows;
        $statement->close();

        if (!$ok || $affected < 1) {
            return false;
        }

        $this->owner = $owner;
        $this->name = $name;

        return true;
    }

    /**
     * Memperpanjang sewa di tengah putaran panjang. Hanya berhasil bila proses
     * ini masih pemiliknya.
     */
    public function renew(int $ttlSeconds = 300): bool
    {
        if ($this->owner === null || $this->name === null) {
            return false;
        }
        $ttlSeconds = max(30, min(3600, $ttlSeconds));
        $statement = $this->db->prepare(
            'UPDATE notifikasi_worker_lock
                SET kedaluwarsa_pada = DATE_ADD(NOW(), INTERVAL ? SECOND)
              WHERE nama = ? AND pemilik = ?'
        );
        if ($statement === false) {
            return false;
        }
        $statement->bind_param('iss', $ttlSeconds, $this->name, $this->owner);
        $ok = $statement->execute();
        $statement->close();

        return $ok;
    }

    /**
     * Melepas sewa. Aman dipanggil berkali-kali dan tidak pernah melepas sewa
     * milik proses lain.
     */
    public function release(): void
    {
        if ($this->owner === null || $this->name === null) {
            return;
        }
        $statement = $this->db->prepare(
            'UPDATE notifikasi_worker_lock
                SET pemilik = \'\', kedaluwarsa_pada = \'1970-01-02 00:00:00\'
              WHERE nama = ? AND pemilik = ?'
        );
        if ($statement !== false) {
            $statement->bind_param('ss', $this->name, $this->owner);
            $statement->execute();
            $statement->close();
        }
        $this->owner = null;
        $this->name = null;
    }

    public function owner(): ?string
    {
        return $this->owner;
    }
}
