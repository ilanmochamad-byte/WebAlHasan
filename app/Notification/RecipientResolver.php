<?php

declare(strict_types=1);

namespace App\Notification;

use mysqli;

/**
 * Penentu penerima notifikasi perizinan.
 *
 * PRD Fase 4 §4 menuntut penerima ditentukan dari **kemampuan dan relasi
 * nyata**, bukan sekadar nama role:
 *
 *  - Murobi  : akun `guru` yang terhubung ke `guru.id` tujuan pengajuan DAN
 *              memiliki `murobi_assignments` aktif pada tahun ajaran aktif.
 *              Guru tanpa penugasan murobi aktif tidak pernah menjadi penerima
 *              keputusan, persis seperti aturan `Capabilities::MUROBI`.
 *  - Pengurus: akun yang terhubung ke baris `pengurus` aktif yang menjadi
 *              pengaju, atau pengurus pemilik penugasan pembimbing pengajuan.
 *  - Admin   : akun aktif ber-role `admin` (kemampuan admin memang berasal dari
 *              role tersebut, sesuai `Capabilities::ADMIN`).
 *  - Orang tua: akun yang terhubung ke `wali` aktif yang memiliki relasi
 *              `santri_wali` AKTIF dengan santri pengajuan.
 *
 * Setiap query menyaring `users.is_active = 1` dan status aktif master data,
 * sehingga akun nonaktif tidak pernah menerima notifikasi. Hasilnya selalu
 * berupa daftar `user_id` unik; pemanggil tidak pernah menerima duplikat.
 */
final class RecipientResolver
{
    public function __construct(private mysqli $db)
    {
    }

    /**
     * Penerima untuk satu peristiwa.
     *
     * @param array<string, mixed> $pengajuan Baris `izin_pengajuan` (minimal:
     *        id, santri_id, pengurus_id, diajukan_oleh_user_id, murobi_guru_id).
     * @param array<string, mixed> $opsi      murobi_sebelumnya_guru_id, aktor_user_id.
     * @return array<int, int> daftar user_id unik
     */
    public function forEvent(string $event, array $pengajuan, array $opsi = []): array
    {
        $santriId = (int) ($pengajuan['santri_id'] ?? 0);
        $pengurusId = ($pengajuan['pengurus_id'] ?? null) === null ? null : (int) $pengajuan['pengurus_id'];
        $murobiGuruId = ($pengajuan['murobi_guru_id'] ?? null) === null ? null : (int) $pengajuan['murobi_guru_id'];
        $pengaju = ($pengajuan['diajukan_oleh_user_id'] ?? null) === null ? null : (int) $pengajuan['diajukan_oleh_user_id'];
        $murobiSebelumnya = ($opsi['murobi_sebelumnya_guru_id'] ?? null) === null
            ? null
            : (int) $opsi['murobi_sebelumnya_guru_id'];

        $penerima = match ($event) {
            // Pengajuan yang berhasil dirutekan ke satu murobi: murobi itulah
            // yang harus bertindak. Admin tidak dibanjiri pengajuan normal.
            NotificationEvent::PENGAJUAN_DIBUAT => $this->murobi($murobiGuruId),

            // Nol atau lebih dari satu kandidat: hanya admin yang dapat
            // menyelesaikannya. Pengurus pengaju tidak diberi notifikasi di
            // sini karena dialah pelaku peristiwa ini — status "Perlu Penetapan
            // Admin" sudah terlihat pada daftar pengajuannya sendiri, dan ia
            // akan menerima notifikasi begitu admin menetapkan murobi.
            NotificationEvent::ROUTING_PERLU_ADMIN => $this->admin(),

            NotificationEvent::MUROBI_DITETAPKAN => array_merge(
                $this->murobi($murobiGuruId),
                $this->pengurus($pengurusId)
            ),

            // Penetapan ulang: murobi baru perlu bertindak, murobi lama perlu
            // tahu pengajuan itu bukan lagi tanggung jawabnya.
            NotificationEvent::MUROBI_DITETAPKAN_ULANG => array_merge(
                $this->murobi($murobiGuruId),
                $this->murobi($murobiSebelumnya),
                $this->pengurus($pengurusId)
            ),

            NotificationEvent::KEPUTUSAN_DISETUJUI,
            NotificationEvent::KEPUTUSAN_DITOLAK => array_merge(
                $this->pengurus($pengurusId),
                $pengaju === null ? [] : [$pengaju],
                $this->waliSantri($santriId)
            ),

            // Keputusan pengganti juga memberi tahu murobi yang ditetapkan,
            // karena keputusan diambil alih dari dirinya.
            NotificationEvent::KEPUTUSAN_ADMIN_PENGGANTI => array_merge(
                $this->pengurus($pengurusId),
                $pengaju === null ? [] : [$pengaju],
                $this->waliSantri($santriId),
                $this->murobi($murobiGuruId)
            ),

            // Pembatalan membersihkan antrean: murobi tujuan (bila ada) dan
            // admin perlu tahu; pengurus pemilik juga, karena admin dapat
            // membatalkan atas namanya.
            NotificationEvent::PEMBATALAN => array_merge(
                $this->murobi($murobiGuruId),
                $this->pengurus($pengurusId),
                $pengaju === null ? [] : [$pengaju],
                $murobiGuruId === null ? $this->admin() : []
            ),

            // Koreksi mengubah hasil yang sudah diumumkan: semua pihak yang
            // menerima keputusan sebelumnya harus diberi tahu.
            NotificationEvent::KOREKSI => array_merge(
                $this->pengurus($pengurusId),
                $pengaju === null ? [] : [$pengaju],
                $this->waliSantri($santriId),
                $this->murobi($murobiGuruId)
            ),

            default => [],
        };

        return $this->normalise($penerima, $opsi);
    }

    /**
     * Akun aktif ber-role admin.
     *
     * @return array<int, int>
     */
    public function admin(): array
    {
        return $this->ids(
            "SELECT u.id
               FROM users u
               JOIN user_roles ur ON ur.user_id = u.id
               JOIN roles r ON r.id = ur.role_id AND r.slug = 'admin'
              WHERE u.is_active = 1"
        );
    }

    /**
     * Akun guru pemegang penugasan murobi AKTIF untuk `guru_id` tertentu.
     *
     * Pemeriksaan penugasan disengaja identik dengan `Capabilities::MUROBI`
     * agar penerima notifikasi tidak pernah lebih luas daripada pemegang hak
     * keputusan.
     *
     * @return array<int, int>
     */
    public function murobi(?int $guruId): array
    {
        if ($guruId === null || $guruId < 1) {
            return [];
        }

        return $this->ids(
            "SELECT DISTINCT u.id
               FROM users u
               JOIN guru g ON g.id = u.guru_id
               JOIN user_roles ur ON ur.user_id = u.id
               JOIN roles r ON r.id = ur.role_id AND r.slug = 'guru'
               JOIN murobi_assignments ma ON ma.guru_id = g.id
               JOIN tahun_ajaran ta ON ta.id = ma.tahun_ajaran_id
               LEFT JOIN kelas kl ON kl.id = ma.kelas_id
                    AND kl.is_active = 1 AND kl.archived_at IS NULL
              WHERE g.id = ?
                AND u.is_active = 1
                AND g.is_active = 1 AND g.archived_at IS NULL
                AND ma.is_active = 1 AND ma.archived_at IS NULL
                AND ma.tanggal_mulai <= CURDATE()
                AND (ma.tanggal_selesai IS NULL OR ma.tanggal_selesai >= CURDATE())
                AND ta.status = 'Aktif' AND ta.archived_at IS NULL
                AND (ma.target_type = 'Kamar' OR (ma.target_type = 'Kelas' AND kl.id IS NOT NULL))",
            [$guruId]
        );
    }

    /**
     * Akun yang terhubung ke satu baris `pengurus` aktif.
     *
     * @return array<int, int>
     */
    public function pengurus(?int $pengurusId): array
    {
        if ($pengurusId === null || $pengurusId < 1) {
            return [];
        }

        return $this->ids(
            "SELECT u.id
               FROM users u
               JOIN pengurus p ON p.id = u.pengurus_id
               JOIN user_roles ur ON ur.user_id = u.id
               JOIN roles r ON r.id = ur.role_id AND r.slug = 'pengurus'
              WHERE p.id = ?
                AND u.is_active = 1
                AND p.is_active = 1 AND p.archived_at IS NULL",
            [$pengurusId]
        );
    }

    /**
     * Akun orang tua dengan relasi wali AKTIF terhadap santri pengajuan.
     *
     * Relasi yang sudah diarsipkan (`santri_wali.archived_at`) tidak pernah
     * menghasilkan penerima, sehingga wali lama tidak menerima kabar izin anak
     * yang relasinya telah dicabut.
     *
     * @return array<int, int>
     */
    public function waliSantri(int $santriId): array
    {
        if ($santriId < 1) {
            return [];
        }

        return $this->ids(
            "SELECT DISTINCT u.id
               FROM users u
               JOIN wali w ON w.id = u.wali_id
               JOIN santri_wali sw ON sw.wali_id = w.id
               JOIN user_roles ur ON ur.user_id = u.id
               JOIN roles r ON r.id = ur.role_id AND r.slug = 'orang_tua'
              WHERE sw.santri_id = ?
                AND sw.archived_at IS NULL
                AND u.is_active = 1
                AND w.is_active = 1 AND w.archived_at IS NULL",
            [$santriId]
        );
    }

    /**
     * Membuang duplikat, nilai tidak valid, dan (bila diminta) aktor peristiwa.
     *
     * Aktor tidak diberi tahu tentang tindakannya sendiri: pengurus yang baru
     * saja mengirim pengajuan tidak perlu notifikasi "pengajuan dibuat".
     *
     * @param array<int, int> $penerima
     * @param array<string, mixed> $opsi
     * @return array<int, int>
     */
    private function normalise(array $penerima, array $opsi): array
    {
        $aktor = ($opsi['aktor_user_id'] ?? null) === null ? null : (int) $opsi['aktor_user_id'];
        $unik = [];
        foreach ($penerima as $userId) {
            $userId = (int) $userId;
            if ($userId < 1 || $userId === $aktor) {
                continue;
            }
            $unik[$userId] = true;
        }

        $hasil = array_keys($unik);
        sort($hasil);

        return $hasil;
    }

    /**
     * @param array<int, int> $params
     * @return array<int, int>
     */
    private function ids(string $sql, array $params = []): array
    {
        $statement = $this->db->prepare($sql);
        if ($statement === false) {
            return [];
        }
        if ($params !== []) {
            $statement->bind_param(str_repeat('i', count($params)), ...$params);
        }
        if (!$statement->execute()) {
            $statement->close();

            return [];
        }
        $result = $statement->get_result();
        $ids = [];
        while ($result !== false && ($row = $result->fetch_assoc()) !== null) {
            $ids[] = (int) $row['id'];
        }
        $statement->close();

        return $ids;
    }
}
