<?php

declare(strict_types=1);

namespace App\Izin;

use mysqli;
use mysqli_stmt;
use RuntimeException;

/**
 * Idempotensi mutasi perizinan (PRD 5.6 dan Fase 2 §4, §9).
 *
 * Kontrak pemakaian — SELALU di dalam satu transaksi yang sama dengan mutasinya:
 *
 *   $repo->beginTransaction();
 *   $replay = $idempotency->begin($userId, 'izin.create', $key, $payload);
 *   if ($replay !== null) { $repo->commit(); return $replay['response']; }
 *   ... lakukan mutasi ...
 *   $idempotency->complete($userId, 'izin.create', $key, 201, $response, $pengajuanId);
 *   $repo->commit();
 *
 * Mengapa aman terhadap dua request bersamaan:
 *   - Baris kunci disisipkan LEBIH DULU di dalam transaksi. Request kedua yang
 *     memakai kunci sama akan menunggu pada indeks unik sampai request pertama
 *     commit atau rollback.
 *   - Bila request pertama commit, request kedua menerima error duplikat lalu
 *     membaca baris itu dengan penguncian (`FOR UPDATE`) sehingga selalu membaca
 *     versi terbaru, bukan snapshot lama, dan mengembalikan respons tersimpan.
 *   - Bila request pertama rollback, baris kunci ikut hilang dan request kedua
 *     melanjutkan seperti request pertama.
 *
 * Hash request membedakan RETRY (payload sama -> putar ulang respons) dari
 * KONFLIK (kunci sama tetapi payload berbeda -> 409).
 */
final class IzinIdempotency
{
    public const OP_CREATE = 'izin.create';
    public const OP_DECISION = 'izin.decision';
    public const OP_CANCEL = 'izin.cancel';
    public const OP_ASSIGN = 'izin.assign';
    public const OP_CORRECTION = 'izin.correction';

    public function __construct(private mysqli $db)
    {
    }

    /**
     * Memvalidasi bentuk kunci idempotensi yang dikirim klien.
     */
    public function normalizeKey(?string $key): string
    {
        $key = trim((string) $key);
        if ($key === '') {
            throw IzinException::invalid('Kunci idempotensi wajib disertakan pada setiap mutasi perizinan.');
        }
        if (strlen($key) > 100 || !preg_match('/^[A-Za-z0-9._:-]{8,100}$/', $key)) {
            throw IzinException::invalid('Kunci idempotensi tidak valid (8–100 karakter, hanya huruf, angka, titik, titik dua, garis bawah, atau strip).');
        }

        return $key;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{status:int, response:array<string, mixed>, pengajuan_id:?int}|null
     *         null berarti pemanggil harus melanjutkan mutasi.
     */
    public function begin(int $userId, string $operation, string $key, array $payload): ?array
    {
        $hash = $this->hash($payload);

        $statement = $this->db->prepare(
            'INSERT INTO izin_idempotency_keys (user_id, operation, idempotency_key, request_hash) VALUES (?, ?, ?, ?)'
        );
        if ($statement === false) {
            throw new RuntimeException('Penjaga idempotensi tidak dapat disiapkan.');
        }
        $statement->bind_param('isss', $userId, $operation, $key, $hash);
        if ($statement->execute()) {
            $statement->close();
            return null;
        }

        $errno = $statement->errno;
        $statement->close();
        if ($errno !== 1062) {
            throw new RuntimeException('Penjaga idempotensi gagal dijalankan.');
        }

        $existing = $this->lockExisting($userId, $operation, $key);
        if ($existing === null) {
            // Baris hilang antara error duplikat dan pembacaan (rollback bersamaan).
            throw IzinException::conflict('Permintaan bersamaan dengan kunci idempotensi yang sama sedang diproses. Coba lagi.');
        }

        if (!hash_equals((string) $existing['request_hash'], $hash)) {
            throw IzinException::conflict('Kunci idempotensi ini sudah dipakai untuk permintaan dengan isi berbeda.');
        }

        if ($existing['completed_at'] === null) {
            throw IzinException::conflict('Permintaan dengan kunci idempotensi yang sama sedang diproses. Coba lagi sesaat lagi.');
        }

        $decoded = $existing['response_json'] === null
            ? []
            : (array) json_decode((string) $existing['response_json'], true);

        return [
            'status' => (int) ($existing['response_status'] ?? 200),
            'response' => $decoded,
            'pengajuan_id' => $existing['pengajuan_id'] === null ? null : (int) $existing['pengajuan_id'],
        ];
    }

    /**
     * @param array<string, mixed> $response
     */
    public function complete(
        int $userId,
        string $operation,
        string $key,
        int $status,
        array $response,
        ?int $pengajuanId
    ): void {
        $json = json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $statement = $this->db->prepare(
            'UPDATE izin_idempotency_keys
                SET response_status = ?, response_json = ?, pengajuan_id = ?, completed_at = NOW()
              WHERE user_id = ? AND operation = ? AND idempotency_key = ?'
        );
        if ($statement === false) {
            throw new RuntimeException('Penjaga idempotensi tidak dapat diselesaikan.');
        }
        $statement->bind_param('isiiss', $status, $json, $pengajuanId, $userId, $operation, $key);
        if (!$statement->execute()) {
            $statement->close();
            throw new RuntimeException('Penjaga idempotensi gagal dicatat.');
        }
        $statement->close();
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function hash(array $payload): string
    {
        ksort($payload);

        return hash('sha256', (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function lockExisting(int $userId, string $operation, string $key): ?array
    {
        // FOR UPDATE = pembacaan terkunci: selalu membaca versi commit terbaru.
        $statement = $this->db->prepare(
            'SELECT request_hash, response_status, response_json, pengajuan_id, completed_at
               FROM izin_idempotency_keys
              WHERE user_id = ? AND operation = ? AND idempotency_key = ?
              LIMIT 1 FOR UPDATE'
        );
        if ($statement === false) {
            throw new RuntimeException('Penjaga idempotensi tidak dapat dibaca.');
        }
        $statement->bind_param('iss', $userId, $operation, $key);
        if (!$this->execute($statement)) {
            throw new RuntimeException('Penjaga idempotensi tidak dapat dibaca.');
        }
        $row = $statement->get_result()?->fetch_assoc();
        $statement->close();

        return is_array($row) ? $row : null;
    }

    private function execute(mysqli_stmt $statement): bool
    {
        return $statement->execute();
    }
}
