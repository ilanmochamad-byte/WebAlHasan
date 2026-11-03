<?php

declare(strict_types=1);

namespace App\Profil;

use App\Database\Schema;
use mysqli;
use mysqli_sql_exception;
use RuntimeException;
use Throwable;

/**
 * Akses data profil pribadi (migrasi 035). Tidak membuka transaksi sendiri
 * kecuali lewat `transaction()`; seluruh metode tulis dipanggil di dalam
 * transaksi pemanggil agar nama/HP master, akun, profil, dan audit berubah
 * bersama atau tidak sama sekali.
 */
final class ProfilRepository
{
    public const KOLOM_PEMILIK = ['guru' => 'guru_id', 'pengurus' => 'pengurus_id', 'wali' => 'wali_id', 'akun' => 'user_id'];

    public function __construct(private mysqli $db)
    {
    }

    public function db(): mysqli
    {
        return $this->db;
    }

    public function tersedia(): bool
    {
        return Schema::hasTable($this->db, 'profil_pribadi')
            && Schema::hasColumn($this->db, 'users', 'sesi_versi')
            && Schema::hasColumn($this->db, 'api_tokens', 'cakupan');
    }

    /**
     * Akun beserta identitas yang terhubung. `$kunci` mengunci baris akun dan
     * baris identitas yang tergabung (FOR UPDATE).
     *
     * @return array<string, mixed>|null
     */
    public function akun(int $userId, bool $kunci = false): ?array
    {
        $sesi = Schema::hasColumn($this->db, 'users', 'sesi_versi') ? 'u.sesi_versi' : '0';
        $milikGuru = Schema::hasColumn($this->db, 'pengurus', 'guru_id') ? 'p.guru_id' : 'NULL';
        $row = $this->one(
            "SELECT u.id, u.name, u.username, u.email, u.phone, u.password, u.is_active, u.force_password_change, {$sesi} AS sesi_versi,
                    u.guru_id, u.pengurus_id, u.wali_id,
                    g.nip, g.nama_guru, g.no_hp AS guru_no_hp, g.is_active AS guru_aktif, g.archived_at AS guru_arsip,
                    p.nama AS pengurus_nama, p.nomor_identitas AS pengurus_nomor, p.no_hp AS pengurus_no_hp, {$milikGuru} AS pengurus_guru_id,
                    p.is_active AS pengurus_aktif, p.archived_at AS pengurus_arsip,
                    w.nama AS wali_nama, w.no_hp AS wali_no_hp, w.alamat AS wali_alamat, w.is_active AS wali_aktif, w.archived_at AS wali_arsip,
                    (SELECT GROUP_CONCAT(DISTINCT r.slug ORDER BY r.slug SEPARATOR ',') FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = u.id) AS roles
               FROM users u
               LEFT JOIN guru g ON g.id = u.guru_id
               LEFT JOIN pengurus p ON p.id = u.pengurus_id
               LEFT JOIN wali w ON w.id = u.wali_id
              WHERE u.id = ?" . ($kunci ? ' FOR UPDATE' : ''),
            [$userId]
        );
        if ($row === null) {
            return null;
        }
        foreach (['id', 'is_active', 'force_password_change', 'sesi_versi'] as $kolom) {
            $row[$kolom] = (int) $row[$kolom];
        }
        foreach (['guru_id', 'pengurus_id', 'wali_id', 'pengurus_guru_id'] as $kolom) {
            $row[$kolom] = $row[$kolom] === null ? null : (int) $row[$kolom];
        }
        $row['roles'] = $row['roles'] === null || $row['roles'] === '' ? [] : explode(',', (string) $row['roles']);

        return $row;
    }

    /** @return array<string, mixed>|null */
    public function profil(string $jenis, int $pemilikId, bool $kunci = false): ?array
    {
        $kolom = self::KOLOM_PEMILIK[$jenis] ?? throw new RuntimeException('Jenis pemilik profil tidak dikenal.');

        return $this->one("SELECT * FROM profil_pribadi WHERE {$kolom} = ? LIMIT 1" . ($kunci ? ' FOR UPDATE' : ''), [$pemilikId]);
    }

    /** @return array<string, mixed>|null */
    public function profilById(int $id): ?array
    {
        return $this->one('SELECT * FROM profil_pribadi WHERE id = ? LIMIT 1', [$id]);
    }

    /**
     * Menulis isian tambahan profil (insert bila belum ada). Hanya kunci
     * `ProfilData::TAMBAHAN`, `nik`, dan foto yang diterima.
     *
     * @param array<string, ?string> $nilai
     * @return array<string, mixed> baris sesudah
     */
    public function tulisProfil(string $jenis, int $pemilikId, array $nilai, ?int $aktor): array
    {
        $boleh = array_merge(ProfilData::TAMBAHAN, ['nik', 'foto']);
        $nilai = array_intersect_key($nilai, array_flip($boleh));
        $kolomPemilik = self::KOLOM_PEMILIK[$jenis] ?? throw new RuntimeException('Jenis pemilik profil tidak dikenal.');
        $ada = $this->profil($jenis, $pemilikId, true);
        if ($ada === null) {
            $kolom = array_keys($nilai);
            $sql = 'INSERT INTO profil_pribadi (jenis_pemilik, ' . $kolomPemilik . ', ' . ($kolom === [] ? '' : implode(', ', $kolom) . ', ')
                . 'foto_versi, versi, diubah_oleh, created_at, updated_at) VALUES (?, ?, ' . str_repeat('?, ', count($kolom))
                . '?, 1, ?, NOW(), NOW())';
            $this->execute($sql, array_merge([$jenis, $pemilikId], array_values($nilai), [array_key_exists('foto', $nilai) && $nilai['foto'] !== null ? 1 : 0, $aktor]));
        } else {
            $set = [];
            foreach (array_keys($nilai) as $kolom) {
                $set[] = $kolom . ' = ?';
            }
            if (array_key_exists('foto', $nilai)) {
                $set[] = 'foto_versi = foto_versi + 1';
            }
            $set[] = 'versi = versi + 1';
            $set[] = 'diubah_oleh = ?';
            $set[] = 'updated_at = NOW()';
            $this->execute('UPDATE profil_pribadi SET ' . implode(', ', $set) . ' WHERE id = ?', array_merge(array_values($nilai), [$aktor, (int) $ada['id']]));
        }

        return $this->profil($jenis, $pemilikId) ?? throw new RuntimeException('Profil gagal dibaca ulang.');
    }

    // --- Identitas master ---------------------------------------------------

    /** @return array<string, mixed>|null */
    public function guru(int $id, bool $kunci = false): ?array
    {
        return $this->one('SELECT * FROM guru WHERE id = ?' . ($kunci ? ' FOR UPDATE' : ''), [$id]);
    }

    /** @return array<string, mixed>|null */
    public function pengurus(int $id, bool $kunci = false): ?array
    {
        return $this->one('SELECT * FROM pengurus WHERE id = ?' . ($kunci ? ' FOR UPDATE' : ''), [$id]);
    }

    /** @return array<string, mixed>|null */
    public function wali(int $id, bool $kunci = false): ?array
    {
        return $this->one('SELECT * FROM wali WHERE id = ?' . ($kunci ? ' FOR UPDATE' : ''), [$id]);
    }

    public function ubahGuru(int $id, string $nama, ?string $hp, ?string $nip = null, bool $ubahNip = false): void
    {
        $sql = 'UPDATE guru SET nama_guru = ?, no_hp = ?' . ($ubahNip ? ', nip = ?' : '') . ', updated_at = NOW() WHERE id = ?';
        $this->unik(fn () => $this->execute($sql, $ubahNip ? [$nama, $hp, $nip, $id] : [$nama, $hp, $id]));
    }

    public function ubahPengurus(int $id, string $nama, ?string $hp, ?string $nomor = null, bool $ubahNomor = false): void
    {
        $sql = 'UPDATE pengurus SET nama = ?, no_hp = ?' . ($ubahNomor ? ', nomor_identitas = ?' : '') . ', updated_at = NOW() WHERE id = ?';
        $this->unik(fn () => $this->execute($sql, $ubahNomor ? [$nama, $hp, $nomor, $id] : [$nama, $hp, $id]));
    }

    public function ubahWali(int $id, string $nama, ?string $hp, ?string $alamat, bool $ubahAlamat): void
    {
        $sql = 'UPDATE wali SET nama = ?, no_hp = ?' . ($ubahAlamat ? ', alamat = ?' : '') . ', updated_at = NOW() WHERE id = ?';
        $this->execute($sql, $ubahAlamat ? [$nama, $hp, $alamat, $id] : [$nama, $hp, $id]);
    }

    public function buatGuru(string $nama, ?string $hp, ?string $nip): int
    {
        $this->unik(fn () => $this->execute("INSERT INTO guru (nip, nama_guru, no_hp, status, is_active, created_at, updated_at) VALUES (?, ?, ?, 'Guru', 1, NOW(), NOW())", [$nip, $nama, $hp]));

        return (int) $this->db->insert_id;
    }

    public function buatPengurus(string $nama, ?string $hp, ?string $nomor, string $jabatan): int
    {
        $this->unik(fn () => $this->execute('INSERT INTO pengurus (nama, nomor_identitas, no_hp, jabatan, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, 1, NOW(), NOW())', [$nama, $nomor, $hp, $jabatan]));

        return (int) $this->db->insert_id;
    }

    /** PK-1: nama, HP, status, dan arsip identitas pengurus milik guru mengikuti data guru. */
    public function sinkronIdentitasGuru(int $guruId): int
    {
        if (!Schema::hasColumn($this->db, 'pengurus', 'guru_id')) {
            return 0;
        }

        return $this->affected(
            'UPDATE pengurus p JOIN guru g ON g.id = p.guru_id
                SET p.nama = g.nama_guru, p.no_hp = g.no_hp, p.is_active = g.is_active, p.archived_at = g.archived_at, p.updated_at = NOW()
              WHERE p.guru_id = ?
                AND (BINARY p.nama <> BINARY g.nama_guru OR NOT (p.no_hp <=> g.no_hp) OR p.is_active <> g.is_active OR NOT (p.archived_at <=> g.archived_at))',
            [$guruId]
        );
    }

    /** @return array<string, mixed>|null */
    public function identitasPengurusGuru(int $guruId): ?array
    {
        return Schema::hasColumn($this->db, 'pengurus', 'guru_id') ? $this->one('SELECT * FROM pengurus WHERE guru_id = ? LIMIT 1', [$guruId]) : null;
    }

    /**
     * Akun yang terhubung ke satu identitas (DIKUNCI).
     *
     * @return array<int, array<string, mixed>>
     */
    public function akunTerhubung(string $jenis, int $id): array
    {
        $kolom = ['guru' => 'guru_id', 'pengurus' => 'pengurus_id', 'wali' => 'wali_id'][$jenis] ?? null;
        if ($kolom === null) {
            return [];
        }

        return $this->all("SELECT id, name, phone, email FROM users WHERE {$kolom} = ? ORDER BY id FOR UPDATE", [$id]);
    }

    /** @return array<int, array<string, mixed>> akun terhubung tanpa kunci (tampilan). */
    public function akunTerhubungBaca(string $jenis, int $id): array
    {
        $kolom = ['guru' => 'guru_id', 'pengurus' => 'pengurus_id', 'wali' => 'wali_id'][$jenis] ?? null;

        return $kolom === null ? [] : $this->all("SELECT id, username, name, phone, email, is_active FROM users WHERE {$kolom} = ? ORDER BY id", [$id]);
    }

    /**
     * Menyamakan nama/HP (dan email bila diberikan) akun dengan identitasnya.
     * Mengembalikan jumlah baris akun yang berubah.
     */
    public function ubahAkun(int $userId, string $nama, ?string $hp, bool $ubahEmail = false, ?string $email = null): int
    {
        $sql = 'UPDATE users SET name = ?, phone = ?' . ($ubahEmail ? ', email = ?' : '') . ', updated_at = NOW() WHERE id = ?';
        try {
            return $this->affected($sql, $ubahEmail ? [$nama, $hp, $email, $userId] : [$nama, $hp, $userId]);
        } catch (RuntimeException $exception) {
            if ($exception->getCode() === 1062) {
                throw new ProfilException('Email sudah dipakai akun lain.', 409, ['email' => 'Email sudah dipakai akun lain.']);
            }
            throw $exception;
        }
    }

    public function emailDipakaiAkunLain(string $email, array $kecualiUserId): bool
    {
        $kecuali = array_values(array_unique(array_map('intval', $kecualiUserId))) ?: [0];

        return $this->one('SELECT id FROM users WHERE email = ? AND id NOT IN (' . implode(',', array_fill(0, count($kecuali), '?')) . ') LIMIT 1',
            array_merge([$email], $kecuali)) !== null;
    }

    /** Pemilik lain dengan NIK sama pada jenis pemilik yang sama (atau null). */
    public function pemilikNik(string $jenis, string $nik): ?int
    {
        $kolom = self::KOLOM_PEMILIK[$jenis];
        $row = $this->one("SELECT {$kolom} AS pemilik FROM profil_pribadi WHERE jenis_pemilik = ? AND nik = ? LIMIT 1", [$jenis, $nik]);

        return $row === null ? null : (int) $row['pemilik'];
    }

    // --- Pencocokan impor ----------------------------------------------------

    /**
     * Guru/pengurus berdasarkan NIP (pengurus: `nomor_identitas`, PI-9),
     * dibandingkan setelah dirapikan seperti `Normalizer::identifier`.
     *
     * @return array<int, array<string, mixed>>
     */
    public function cariNip(string $jenis, string $nip): array
    {
        return $jenis === 'guru'
            ? $this->all("SELECT * FROM guru WHERE UPPER(REPLACE(TRIM(nip), ' ', '')) = ? ORDER BY id", [$nip])
            : $this->all("SELECT * FROM pengurus WHERE UPPER(REPLACE(TRIM(nomor_identitas), ' ', '')) = ? ORDER BY id", [$nip]);
    }

    /** @return array<int, array<string, mixed>> master yang profilnya memakai NIK ini */
    public function cariNik(string $jenis, string $nik): array
    {
        return $jenis === 'guru'
            ? $this->all("SELECT g.* FROM profil_pribadi pr JOIN guru g ON g.id = pr.guru_id WHERE pr.jenis_pemilik = 'guru' AND pr.nik = ?", [$nik])
            : $this->all("SELECT p.* FROM profil_pribadi pr JOIN pengurus p ON p.id = pr.pengurus_id WHERE pr.jenis_pemilik = 'pengurus' AND pr.nik = ?", [$nik]);
    }

    /** @return array<int, array<string, mixed>> master bernama sama (tanpa membedakan huruf besar/kecil) */
    public function cariNama(string $jenis, string $nama): array
    {
        return $jenis === 'guru'
            ? $this->all('SELECT * FROM guru WHERE LOWER(TRIM(nama_guru)) = LOWER(?) ORDER BY id LIMIT 10', [$nama])
            : $this->all('SELECT * FROM pengurus WHERE LOWER(TRIM(nama)) = LOWER(?) ORDER BY id LIMIT 10', [$nama]);
    }

    // --- Password dan sesi --------------------------------------------------

    /** Mengganti hash password, melepas wajib ganti, dan menaikkan versi sesi. */
    public function gantiPassword(int $userId, string $hash): int
    {
        $berubah = $this->affected('UPDATE users SET password = ?, force_password_change = 0, sesi_versi = sesi_versi + 1, updated_at = NOW() WHERE id = ? AND is_active = 1', [$hash, $userId]);
        if ($berubah !== 1) {
            throw new ProfilException('Password gagal diubah. Silakan coba lagi.', 409);
        }
        $row = $this->one('SELECT sesi_versi FROM users WHERE id = ?', [$userId]);

        return (int) ($row['sesi_versi'] ?? 0);
    }

    /** Mencabut seluruh token aplikasi akun selain token yang sedang dipakai. */
    public function cabutTokenLain(int $userId, ?int $tokenSekarang): int
    {
        return $this->affected('UPDATE api_tokens SET revoked_at = NOW() WHERE user_id = ? AND revoked_at IS NULL AND id <> ?', [$userId, $tokenSekarang ?? 0]);
    }

    /**
     * Token yang dipakai mengganti password menjadi token penuh. Token terbatas
     * (berlaku 1 hari) memperoleh masa berlaku token biasa sejak saat ini.
     */
    public function jadikanTokenPenuh(int $tokenId, int $ttlHari): void
    {
        $this->execute("UPDATE api_tokens
                           SET expires_at = CASE WHEN cakupan = 'ganti_password' THEN DATE_ADD(NOW(), INTERVAL ? DAY) ELSE expires_at END,
                               cakupan = 'penuh'
                         WHERE id = ?", [max(1, min(365, $ttlHari)), $tokenId]);
    }

    /**
     * Perangkat push akun dicabut kecuali perangkat yang sedang dipakai.
     * Tanpa `device_id`, seluruh perangkat dicabut (aplikasi mendaftar ulang).
     */
    public function cabutPerangkatLain(int $userId, ?string $deviceId): int
    {
        if (!Schema::hasTable($this->db, 'perangkat_push')) {
            return 0;
        }

        return $deviceId === null || $deviceId === ''
            ? $this->affected("UPDATE perangkat_push SET dicabut_pada = NOW(), alasan_pencabutan = 'password_diganti', push_aktif = 0 WHERE user_id = ? AND dicabut_pada IS NULL", [$userId])
            : $this->affected("UPDATE perangkat_push SET dicabut_pada = NOW(), alasan_pencabutan = 'password_diganti', push_aktif = 0 WHERE user_id = ? AND dicabut_pada IS NULL AND NOT (device_id <=> ?)", [$userId, $deviceId]);
    }

    // --- Nama untuk tampilan penugasan --------------------------------------

    /**
     * @param array<int, int> $ids
     * @return array<int, string>
     */
    public function nama(string $tabel, array $ids): array
    {
        [$kolom, $tabelSah] = match ($tabel) {
            'kamar' => ['nama_kamar', 'kamar'],
            'kelas' => ['nama_kelas', 'kelas'],
            'mata_pelajaran' => ['nama', 'mata_pelajaran'],
            'tahun_ajaran' => ["CONCAT(tahun, ' ', semester)", 'tahun_ajaran'],
        };
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if ($ids === []) {
            return [];
        }
        $hasil = [];
        foreach ($this->all("SELECT id, {$kolom} AS nama FROM {$tabelSah} WHERE id IN (" . implode(',', array_fill(0, count($ids), '?')) . ')', $ids) as $row) {
            $hasil[(int) $row['id']] = (string) $row['nama'];
        }

        return $hasil;
    }

    // ------------------------------------------------------------------------

    /** Pelanggaran kunci unik NIP/nomor identitas → konflik yang dapat ditampilkan. */
    private function unik(callable $kerja): void
    {
        try {
            $kerja();
        } catch (RuntimeException $exception) {
            if ($exception->getCode() === 1062) {
                throw new ProfilException('NIP sudah dipakai data lain.', 409, ['nip' => 'NIP sudah dipakai data lain.']);
            }
            throw $exception;
        }
    }

    public function transaction(callable $work): mixed
    {
        if (!$this->db->begin_transaction()) {
            throw new RuntimeException('Transaksi profil tidak dapat dimulai.');
        }
        try {
            $result = $work();
            if (!$this->db->commit()) {
                throw new RuntimeException('Transaksi profil gagal disimpan.');
            }
            return $result;
        } catch (Throwable $exception) {
            $this->db->rollback();
            throw $exception;
        }
    }

    /** @return array<string, mixed>|null */
    private function one(string $sql, array $params): ?array
    {
        return $this->all($sql, $params)[0] ?? null;
    }

    /** @return array<int, array<string, mixed>> */
    private function all(string $sql, array $params): array
    {
        $statement = $this->statement($sql, $params);
        $result = $statement->get_result();
        $rows = $result === false ? [] : $result->fetch_all(MYSQLI_ASSOC);
        $statement->close();

        return $rows;
    }

    private function execute(string $sql, array $params): void
    {
        $this->statement($sql, $params)->close();
    }

    private function affected(string $sql, array $params): int
    {
        $statement = $this->statement($sql, $params);
        $jumlah = max(0, $statement->affected_rows);
        $statement->close();

        return $jumlah;
    }

    private function statement(string $sql, array $params): \mysqli_stmt
    {
        try {
            $statement = $this->db->prepare($sql);
            if ($statement === false) {
                throw new RuntimeException('Query profil tidak dapat disiapkan: ' . $this->db->error);
            }
            if (!$statement->execute($params)) {
                $pesan = $statement->error;
                $kode = $statement->errno;
                $statement->close();
                throw new RuntimeException('Query profil gagal: ' . $pesan, $kode);
            }
        } catch (mysqli_sql_exception $exception) {
            throw new RuntimeException('Query profil gagal: ' . $exception->getMessage(), (int) $exception->getCode(), $exception);
        }

        return $statement;
    }
}
