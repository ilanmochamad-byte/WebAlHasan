<?php

declare(strict_types=1);

namespace App\Notification;

use mysqli;

/**
 * Antrean outbox untuk kanal eksternal (Push dan WhatsApp).
 *
 * Alur satu baris: `Queued` -> diklaim worker -> `Sent` atau `Failed`.
 * Baris `Failed` yang belum permanen kembali menjadi kandidat setelah
 * `tersedia_pada` terlampaui (backoff), sampai batas percobaan tercapai dan
 * `gagal_permanen` menjadi 1.
 *
 * Klaim memakai satu UPDATE atomik dengan penanda pemilik (`locked_by`) dan
 * masa berlaku (`locked_until`). Dua worker yang berjalan bersamaan tidak
 * pernah memperoleh baris yang sama: yang kalah balapan mendapat 0 baris.
 * Inilah pengaman yang membuat "retry event yang sama tidak menghasilkan pesan
 * ganda" tetap berlaku bahkan saat cron tumpang tindih.
 */
final class OutboxRepository
{
    /** Batas percobaan sebelum baris ditandai gagal permanen. */
    public const MAX_PERCOBAAN = 5;

    /** Backoff dasar (detik). Percobaan ke-n menunggu BASE * 2^(n-1). */
    public const BACKOFF_BASE = 60;

    /** Batas atas backoff (detik) agar antrean tidak tertahan berjam-jam. */
    public const BACKOFF_MAX = 3600;

    public function __construct(private mysqli $db)
    {
    }

    /**
     * Mengklaim sejumlah baris siap kirim untuk satu kanal.
     *
     * @return array<int, array<string, mixed>>
     */
    public function claim(string $kanal, string $owner, int $limit = 25, int $leaseSeconds = 300): array
    {
        if (!in_array($kanal, NotificationChannel::EKSTERNAL, true)) {
            return [];
        }
        $limit = max(1, min(100, $limit));
        $leaseSeconds = max(30, min(3600, $leaseSeconds));
        $owner = substr($owner, 0, 64);

        $statement = $this->db->prepare(
            "UPDATE notifikasi_outbox
                SET locked_by = ?, locked_until = DATE_ADD(NOW(), INTERVAL ? SECOND)
              WHERE kanal = ?
                AND gagal_permanen = 0
                AND status IN ('Queued','Failed')
                AND (tersedia_pada IS NULL OR tersedia_pada <= NOW())
                AND (locked_until IS NULL OR locked_until <= NOW())
              ORDER BY id
              LIMIT ?"
        );
        if ($statement === false) {
            return [];
        }
        $statement->bind_param('sisi', $owner, $leaseSeconds, $kanal, $limit);
        if (!$statement->execute()) {
            $statement->close();

            return [];
        }
        $claimed = $statement->affected_rows;
        $statement->close();

        if ($claimed < 1) {
            return [];
        }

        $select = $this->db->prepare(
            'SELECT id, event_key, event_type, kanal, penerima_user_id, pengajuan_id, judul, isi, data_json,
                    status, percobaan
               FROM notifikasi_outbox
              WHERE kanal = ? AND locked_by = ? AND locked_until > NOW()
              ORDER BY id'
        );
        if ($select === false) {
            return [];
        }
        $select->bind_param('ss', $kanal, $owner);
        $select->execute();
        $result = $select->get_result();
        $rows = [];
        while ($result !== false && ($row = $result->fetch_assoc()) !== null) {
            $rows[] = $row;
        }
        $select->close();

        return $rows;
    }

    /**
     * Memperpanjang seluruh klaim milik worker yang masih memproses batch.
     *
     * Batch dapat memakan waktu lebih lama daripada sewa awal ketika penyedia
     * lambat. Tanpa heartbeat ini, worker kedua dapat mengambil baris tersisa
     * setelah menit kelima dan mengirim peristiwa yang sama dua kali.
     */
    public function renewClaims(string $owner, int $leaseSeconds = 300): bool
    {
        $owner = substr($owner, 0, 64);
        $leaseSeconds = max(30, min(3600, $leaseSeconds));
        $statement = $this->db->prepare(
            'UPDATE notifikasi_outbox
                SET locked_until = DATE_ADD(NOW(), INTERVAL ? SECOND)
              WHERE locked_by = ?'
        );
        if ($statement === false) {
            return false;
        }
        $statement->bind_param('is', $leaseSeconds, $owner);
        $ok = $statement->execute();
        $statement->close();
        if (!$ok) {
            return false;
        }

        $check = $this->db->prepare(
            'SELECT 1 FROM notifikasi_outbox
              WHERE locked_by = ? AND locked_until > NOW()
              LIMIT 1'
        );
        if ($check === false) {
            return false;
        }
        $check->bind_param('s', $owner);
        $check->execute();
        $owned = $check->get_result()?->fetch_row();
        $check->close();

        return is_array($owned);
    }

    /**
     * Menandai satu baris berhasil dikirim ke penyedia.
     */
    /**
     * @param ?string $tiketId Id tiket penyedia (V2 Fase 5). Bila diisi, baris
     *                         masuk antrean pengambilan receipt AKHIR sehingga
     *                         status `Sent` dapat direkonsiliasi menjadi
     *                         terkirim/gagal berdasarkan jawaban FCM/APNs —
     *                         bukan berhenti pada tiket awal seperti Fase 4.
     */
    public function markSent(int $id, string $owner, int $durasiMs = 0, ?string $tiketId = null): bool
    {
        $tiketId = $tiketId === null || trim($tiketId) === '' ? null : substr(trim($tiketId), 0, 120);
        $statement = $this->db->prepare(
            "UPDATE notifikasi_outbox
                SET status = 'Sent',
                    percobaan = percobaan + 1,
                    percobaan_terakhir_pada = NOW(),
                    dikirim_pada = NOW(),
                    error_kode = NULL,
                    error_terakhir = NULL,
                    tersedia_pada = NULL,
                    locked_by = NULL,
                    locked_until = NULL,
                    tiket_id = ?,
                    receipt_status = CASE WHEN ? IS NULL THEN 'Belum Diperlukan' ELSE 'Menunggu' END,
                    receipt_kode = NULL,
                    receipt_pesan = NULL,
                    receipt_diperiksa_pada = NULL,
                    receipt_percobaan = 0
              WHERE id = ? AND locked_by = ?"
        );
        if ($statement === false) {
            return false;
        }
        $ownerShort = substr($owner, 0, 64);
        $statement->bind_param('ssis', $tiketId, $tiketId, $id, $ownerShort);
        $ok = $statement->execute();
        $affected = $statement->affected_rows;
        $statement->close();

        if ($ok && $affected > 0) {
            $percobaan = (int) ($this->scalar('SELECT percobaan FROM notifikasi_outbox WHERE id = ?', $id) ?? 1);
            $this->recordAttempt($id, $percobaan, 'Sent', null, null, $durasiMs);
        }

        return $ok && $affected > 0;
    }

    /**
     * Menandai satu percobaan gagal, dengan backoff terbatas.
     *
     * @param bool $permanen paksa berhenti mencoba (mis. token tidak terdaftar,
     *                       kanal dimatikan, atau konfigurasi tidak valid).
     */
    public function markFailed(int $id, string $owner, string $kode, string $pesan, bool $permanen = false, int $durasiMs = 0): bool
    {
        $kodeAman = SafeError::code($kode);
        $pesanAman = SafeError::message($pesan);

        $current = (int) ($this->scalar('SELECT percobaan FROM notifikasi_outbox WHERE id = ?', $id) ?? 0);
        $percobaanBaru = $current + 1;
        $habis = $permanen || $percobaanBaru >= self::MAX_PERCOBAAN;
        $backoff = $habis ? 0 : $this->backoffSeconds($percobaanBaru);

        $statement = $this->db->prepare(
            "UPDATE notifikasi_outbox
                SET status = 'Failed',
                    percobaan = percobaan + 1,
                    percobaan_terakhir_pada = NOW(),
                    error_kode = ?,
                    error_terakhir = ?,
                    gagal_permanen = ?,
                    tersedia_pada = " . ($habis ? 'NULL' : 'DATE_ADD(NOW(), INTERVAL ? SECOND)') . ",
                    locked_by = NULL,
                    locked_until = NULL
              WHERE id = ? AND locked_by = ?"
        );
        if ($statement === false) {
            return false;
        }
        $permanenFlag = $habis ? 1 : 0;
        $ownerShort = substr($owner, 0, 64);
        if ($habis) {
            $statement->bind_param('ssiis', $kodeAman, $pesanAman, $permanenFlag, $id, $ownerShort);
        } else {
            $statement->bind_param('ssiiis', $kodeAman, $pesanAman, $permanenFlag, $backoff, $id, $ownerShort);
        }
        $ok = $statement->execute();
        $affected = $statement->affected_rows;
        $statement->close();

        if ($ok && $affected > 0) {
            $this->recordAttempt($id, $percobaanBaru, 'Failed', $kodeAman, $pesanAman, $durasiMs);
        }

        return $ok && $affected > 0;
    }

    /**
     * Melepaskan klaim tanpa menghitungnya sebagai percobaan.
     *
     * Dipakai ketika worker berhenti karena alasan di luar penerima — misalnya
     * kanal dimatikan admin di tengah putaran. Baris kembali ke antrean apa
     * adanya sehingga tidak ada percobaan yang terbuang.
     */
    public function release(int $id, string $owner): bool
    {
        $statement = $this->db->prepare(
            'UPDATE notifikasi_outbox
                SET locked_by = NULL, locked_until = NULL
              WHERE id = ? AND locked_by = ?'
        );
        if ($statement === false) {
            return false;
        }
        $ownerShort = substr($owner, 0, 64);
        $statement->bind_param('is', $id, $ownerShort);
        $ok = $statement->execute();
        $statement->close();

        return $ok;
    }

    /**
     * Membuang baris antrean kanal yang sedang dimatikan.
     *
     * Dipakai saat admin mematikan sebuah kanal: baris yang belum terkirim
     * ditandai gagal permanen dengan alasan yang jelas, sehingga worker tidak
     * lagi mengambilnya dan admin tetap dapat melihat apa yang tidak jadi
     * dikirim. Notifikasi in-app TIDAK tersentuh.
     */
    public function cancelQueued(string $kanal, string $alasan): int
    {
        if (!in_array($kanal, NotificationChannel::EKSTERNAL, true)) {
            return 0;
        }
        $statement = $this->db->prepare(
            "UPDATE notifikasi_outbox
                SET status = 'Failed', gagal_permanen = 1, error_kode = 'KANAL_NONAKTIF',
                    error_terakhir = ?, tersedia_pada = NULL, locked_by = NULL, locked_until = NULL
              WHERE kanal = ? AND status = 'Queued' AND gagal_permanen = 0"
        );
        if ($statement === false) {
            return 0;
        }
        $pesan = SafeError::message($alasan, 'Kanal dinonaktifkan admin.');
        $statement->bind_param('ss', $pesan, $kanal);
        $statement->execute();
        $affected = $statement->affected_rows;
        $statement->close();

        return max(0, $affected);
    }

    /**
     * Jumlah baris yang siap dikirim untuk satu kanal (untuk tampilan admin).
     */
    public function pendingCount(string $kanal): int
    {
        $statement = $this->db->prepare(
            "SELECT COUNT(*) AS jumlah
               FROM notifikasi_outbox
              WHERE kanal = ? AND gagal_permanen = 0 AND status IN ('Queued','Failed')"
        );
        if ($statement === false) {
            return 0;
        }
        $statement->bind_param('s', $kanal);
        $statement->execute();
        $row = $statement->get_result()?->fetch_assoc();
        $statement->close();

        return (int) ($row['jumlah'] ?? 0);
    }

    // -----------------------------------------------------------------------
    // V2 Fase 5 — receipt AKHIR push
    //
    // Fase 4 berhenti pada tiket awal Expo. Tiket hanya membuktikan Expo
    // MENERIMA pesan. Bagian ini mengambil jawaban akhir FCM/APNs sehingga
    // status pengiriman dapat direkonsiliasi dengan kenyataan.
    // -----------------------------------------------------------------------

    /** Umur minimum tiket sebelum receipt-nya layak diminta (kontrak Expo). */
    public const RECEIPT_TUNGGU_DETIK = 900;

    /** Batas percobaan pengambilan receipt sebelum ditandai tidak tersedia. */
    public const RECEIPT_MAKS_PERCOBAAN = 6;

    /**
     * Baris push terkirim yang menunggu receipt akhir dan sudah cukup umur.
     *
     * Tidak memakai penguncian pemilik seperti `claim()`: pengambilan receipt
     * bersifat IDEMPOTEN — dua worker yang membaca tiket yang sama hanya akan
     * menuliskan hasil yang sama, dan tidak ada pesan yang terkirim ulang.
     *
     * @return array<int, array{id:int, tiket_id:string, penerima_user_id:int}>
     */
    public function pendingReceipts(int $limit = 100, int $minimalUmurDetik = self::RECEIPT_TUNGGU_DETIK): array
    {
        $statement = $this->db->prepare(
            "SELECT id, tiket_id, penerima_user_id
               FROM notifikasi_outbox
              WHERE kanal = 'Push'
                AND status = 'Sent'
                AND receipt_status = 'Menunggu'
                AND tiket_id IS NOT NULL
                AND dikirim_pada IS NOT NULL
                AND dikirim_pada <= DATE_SUB(NOW(), INTERVAL ? SECOND)
                AND receipt_percobaan < ?
              ORDER BY dikirim_pada ASC, id ASC
              LIMIT ?"
        );
        if ($statement === false) {
            return [];
        }
        $umur = max(0, $minimalUmurDetik);
        $maks = self::RECEIPT_MAKS_PERCOBAAN;
        $batas = max(1, min(1000, $limit));
        $statement->bind_param('iii', $umur, $maks, $batas);
        $statement->execute();
        $result = $statement->get_result();
        $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        $statement->close();

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'tiket_id' => (string) $row['tiket_id'],
            'penerima_user_id' => (int) $row['penerima_user_id'],
        ], $rows);
    }

    /**
     * Menuliskan hasil receipt akhir.
     *
     * `$status` wajib salah satu dari `Terkirim`, `Gagal`, `Tidak Tersedia`.
     * `$kode`/`$pesan` WAJIB sudah melewati `SafeError` di sisi pemanggil.
     *
     * Catatan penting: baris yang receipt-nya `Gagal` TIDAK dikembalikan ke
     * antrean kirim. Pesan sudah benar-benar dikirim ke penyedia; mengirim
     * ulang akan menghasilkan notifikasi ganda pada perangkat penerima dan
     * melanggar jaminan deduplikasi Fase 4. Kegagalan receipt adalah INFORMASI
     * operasional untuk admin, bukan pemicu retry.
     */
    public function markReceipt(int $id, string $status, ?string $kode = null, ?string $pesan = null): bool
    {
        if (!in_array($status, ['Terkirim', 'Gagal', 'Tidak Tersedia'], true)) {
            return false;
        }
        $statement = $this->db->prepare(
            "UPDATE notifikasi_outbox
                SET receipt_status = ?,
                    receipt_kode = ?,
                    receipt_pesan = ?,
                    receipt_diperiksa_pada = NOW(),
                    receipt_percobaan = receipt_percobaan + 1
              WHERE id = ? AND receipt_status = 'Menunggu'"
        );
        if ($statement === false) {
            return false;
        }
        $kodeAman = $kode === null ? null : substr(SafeError::code($kode), 0, 60);
        $pesanAman = $pesan === null ? null : substr(SafeError::message($pesan, ''), 0, 255);
        $statement->bind_param('sssi', $status, $kodeAman, $pesanAman, $id);
        $ok = $statement->execute();
        $affected = $statement->affected_rows;
        $statement->close();

        return $ok && $affected > 0;
    }

    /**
     * Menaikkan penghitung percobaan tanpa menetapkan hasil, untuk tiket yang
     * penyedianya BELUM menjawab. Setelah `RECEIPT_MAKS_PERCOBAAN` kali, baris
     * berhenti diminta dan ditandai `Tidak Tersedia` — bukan `Gagal`, karena
     * tidak adanya jawaban bukan bukti kegagalan pengantaran.
     */
    public function noteReceiptPending(int $id): bool
    {
        $statement = $this->db->prepare(
            "UPDATE notifikasi_outbox
                SET receipt_percobaan = receipt_percobaan + 1,
                    receipt_diperiksa_pada = NOW(),
                    receipt_status = CASE
                        WHEN receipt_percobaan + 1 >= ? THEN 'Tidak Tersedia'
                        ELSE 'Menunggu' END,
                    receipt_pesan = CASE
                        WHEN receipt_percobaan + 1 >= ?
                        THEN 'Penyedia tidak mengembalikan receipt akhir dalam batas percobaan.'
                        ELSE receipt_pesan END
              WHERE id = ? AND receipt_status = 'Menunggu'"
        );
        if ($statement === false) {
            return false;
        }
        $maks = self::RECEIPT_MAKS_PERCOBAAN;
        $statement->bind_param('iii', $maks, $maks, $id);
        $ok = $statement->execute();
        $statement->close();

        return $ok;
    }

    /**
     * Sebaran status receipt untuk panel admin dan `--status` worker.
     *
     * @return array<string, int>
     */
    public function receiptSummary(): array
    {
        $result = $this->db->query(
            "SELECT receipt_status, COUNT(*) AS jumlah
               FROM notifikasi_outbox
              WHERE kanal = 'Push'
              GROUP BY receipt_status"
        );
        $sebaran = [
            'Belum Diperlukan' => 0,
            'Menunggu' => 0,
            'Terkirim' => 0,
            'Gagal' => 0,
            'Tidak Tersedia' => 0,
        ];
        if ($result === false) {
            return $sebaran;
        }
        while ($row = $result->fetch_assoc()) {
            $sebaran[(string) $row['receipt_status']] = (int) $row['jumlah'];
        }

        return $sebaran;
    }

    public function backoffSeconds(int $percobaan): int
    {
        $percobaan = max(1, $percobaan);
        $delay = self::BACKOFF_BASE * (2 ** ($percobaan - 1));

        return (int) min(self::BACKOFF_MAX, $delay);
    }

    /**
     * Riwayat percobaan bersifat aditif dan tidak pernah menimpa baris lama.
     * Kunci unik `(outbox_id, percobaan_ke)` mencegah duplikat bila pemanggil
     * mengulang pencatatan untuk percobaan yang sama.
     */
    private function recordAttempt(
        int $outboxId,
        int $percobaanKe,
        string $hasil,
        ?string $kode,
        ?string $pesan,
        int $durasiMs
    ): void {
        $kanal = (string) ($this->scalarString('SELECT kanal FROM notifikasi_outbox WHERE id = ?', $outboxId) ?? '');
        if ($kanal === '') {
            return;
        }
        $statement = $this->db->prepare(
            'INSERT INTO notifikasi_percobaan
                 (outbox_id, kanal, percobaan_ke, hasil, error_kode, error_pesan, durasi_ms, dicoba_pada)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE outbox_id = outbox_id'
        );
        if ($statement === false) {
            return;
        }
        $durasi = max(0, $durasiMs);
        $statement->bind_param('isisssi', $outboxId, $kanal, $percobaanKe, $hasil, $kode, $pesan, $durasi);
        $statement->execute();
        $statement->close();
    }

    private function scalar(string $sql, int $id): ?int
    {
        $value = $this->scalarString($sql, $id);

        return $value === null ? null : (int) $value;
    }

    private function scalarString(string $sql, int $id): ?string
    {
        $statement = $this->db->prepare($sql);
        if ($statement === false) {
            return null;
        }
        $statement->bind_param('i', $id);
        $statement->execute();
        $row = $statement->get_result()?->fetch_row();
        $statement->close();

        return is_array($row) && isset($row[0]) ? (string) $row[0] : null;
    }
}
