<?php
declare(strict_types=1);
namespace App\V4;

use mysqli;

/**
 * Pembacaan tampilan Penilaian & rapor (redesain website Tahap 7).
 *
 * Hanya SELECT. Kelas ini TIDAK memeriksa hak akses: halaman memanggilnya
 * SESUDAH layanan V4 (`NilaiService::read`, `SikapService::options`,
 * `VerifikasiService::read`, `RencanaService::read/signers`,
 * `RaporService::listing/show`) menegakkan cakupan, lalu membatasi masukan
 * pada ID yang dikembalikan layanan tersebut. Keluaran layanan V4 dan REST
 * tidak berubah; kebutuhan tampilan baru (nama pelaku, kisi per kelas,
 * pratinjau penanda tangan) dibaca di sini.
 */
final class RaporTampilan
{
    public function __construct(private mysqli $db) {}

    /** @return array<int, array<string, mixed>> */
    private function rows(string $sql, string $types = '', array $args = []): array
    {
        $s = $this->db->prepare($sql);
        if (!$s) throw new V4Exception('Basis data tidak tersedia.', 503);
        if ($types !== '') $s->bind_param($types, ...$args);
        if (!$s->execute()) { $s->close(); throw new V4Exception('Data tidak dapat dibaca.', 503); }
        $r = $s->get_result(); $out = $r ? $r->fetch_all(MYSQLI_ASSOC) : []; $s->close();
        return $out;
    }

    /** @param array<int, int> $ids @return array{0:string,1:string,2:array<int,int>} */
    private static function in(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) $ids = [0];
        return [implode(',', array_fill(0, count($ids), '?')), str_repeat('i', count($ids)), $ids];
    }

    /**
     * Nama pelaku pengganti "pelaku #id" (nama akun, bukan nomor).
     *
     * @param array<int, int|string|null> $userIds
     * @return array<int, string>
     */
    public function namaPengguna(array $userIds): array
    {
        [$in, $t, $ids] = self::in(array_filter(array_map('intval', $userIds)));
        $out = [];
        foreach ($this->rows("SELECT id, COALESCE(NULLIF(TRIM(name), ''), username) AS nama FROM users WHERE id IN ($in)", $t, $ids) as $r) {
            $out[(int) $r['id']] = (string) $r['nama'];
        }
        return $out;
    }

    /**
     * Guru pengampu per penugasan guru mapel, termasuk status aktifnya
     * (R-1: guru nonaktif ditandai untuk diganti).
     *
     * @param array<int, int|string|null> $assignmentIds
     * @return array<int, array{guru_id:int, nama_guru:string, aktif:bool, mata_pelajaran_id:int}>
     */
    public function penugasanGuru(array $assignmentIds): array
    {
        [$in, $t, $ids] = self::in(array_filter(array_map('intval', $assignmentIds)));
        $out = [];
        foreach ($this->rows("SELECT a.id, a.guru_id, a.mata_pelajaran_id, g.nama_guru,
                (a.is_active = 1 AND a.tanggal_mulai <= CURDATE() AND (a.tanggal_selesai IS NULL OR a.tanggal_selesai >= CURDATE())
                 AND g.is_active = 1 AND g.archived_at IS NULL) AS aktif
            FROM guru_mapel_assignments a JOIN guru g ON g.id = a.guru_id WHERE a.id IN ($in)", $t, $ids) as $r) {
            $out[(int) $r['id']] = ['guru_id' => (int) $r['guru_id'], 'nama_guru' => (string) $r['nama_guru'],
                'aktif' => (bool) $r['aktif'], 'mata_pelajaran_id' => (int) $r['mata_pelajaran_id']];
        }
        return $out;
    }

    /**
     * Mapel rencana yang sudah punya nilai. Layanan menolak menyimpan ulang
     * rencana seperti itu, jadi formulir menampilkannya terkunci.
     *
     * @return array<int, bool> rencana_mapel_id => true
     */
    public function mapelBernilai(int $rencanaId): array
    {
        $out = [];
        foreach ($this->rows('SELECT DISTINCT rm.id FROM v4_rencana_mapel rm JOIN v4_nilai n ON n.rencana_mapel_id = rm.id WHERE rm.rencana_id = ?', 'i', [$rencanaId]) as $r) {
            $out[(int) $r['id']] = true;
        }
        return $out;
    }

    /**
     * Semester tepat sebelum `$tahunId` menurut urutan akademik (tahun awal,
     * lalu Ganjil sebelum Genap), tidak diarsipkan — untuk "Salin dari
     * semester lalu". Urutan ID tidak dipakai karena semester lama dapat
     * dimasukkan belakangan.
     */
    public function semesterSebelumnya(int $tahunId): ?array
    {
        $urut = static fn (array $r): int => (int) substr((string) $r['tahun'], 0, 4) * 2 + ($r['semester'] === 'Genap' ? 1 : 0);
        $semua = $this->rows('SELECT id, tahun, semester, status FROM tahun_ajaran WHERE archived_at IS NULL');
        $ini = null;
        foreach ($semua as $r) if ((int) $r['id'] === $tahunId) $ini = $urut($r);
        if ($ini === null) return null;
        $hasil = null;
        foreach ($semua as $r) {
            $k = $urut($r);
            if ($k < $ini && ($hasil === null || $k > $urut($hasil))) $hasil = $r;
        }
        return $hasil;
    }

    /**
     * Penanda tangan beserta aset terbaru (aktif bila ada) untuk kartu R-7
     * dan pilihan R-1. Nama berkas privat tidak ikut dikembalikan.
     *
     * @return array<int, array<string, mixed>>
     */
    public function penandaDenganAset(bool $termasukNonaktif): array
    {
        $rows = $this->rows('SELECT p.id, p.jabatan, p.nama, p.gelar, p.is_active,
                (SELECT a.id FROM v4_tanda_tangan_aset a WHERE a.penanda_tangan_id = p.id
                    ORDER BY (a.is_active = 1 AND (a.berlaku_mulai IS NULL OR a.berlaku_mulai <= CURDATE()) AND (a.berlaku_selesai IS NULL OR a.berlaku_selesai >= CURDATE())) DESC, a.id DESC LIMIT 1) AS aset_id
            FROM v4_penanda_tangan p' . ($termasukNonaktif ? '' : ' WHERE p.is_active = 1') . ' ORDER BY p.is_active DESC, p.jabatan, p.nama LIMIT 100');
        $aset = [];
        [$in, $t, $ids] = self::in(array_column($rows, 'aset_id'));
        foreach ($this->rows("SELECT id, is_active, created_at,
                (is_active = 1 AND (berlaku_mulai IS NULL OR berlaku_mulai <= CURDATE()) AND (berlaku_selesai IS NULL OR berlaku_selesai >= CURDATE())) AS berlaku
            FROM v4_tanda_tangan_aset WHERE id IN ($in)", $t, $ids) as $a) {
            $aset[(int) $a['id']] = $a;
        }
        foreach ($rows as &$r) {
            $a = $aset[(int) $r['aset_id']] ?? null;
            $r['aset_berlaku'] = $a !== null && (bool) $a['berlaku'];
            $r['aset_diunggah'] = $a['created_at'] ?? null;
        }
        unset($r);
        return $rows;
    }

    /** Satu aset tanda tangan untuk `portal/rapor_ttd.php` (setelah hak akses diperiksa). */
    public function aset(int $id): ?array
    {
        return $this->rows('SELECT a.id, a.private_path, a.sha256, a.mime_type,
                (a.is_active = 1 AND p.is_active = 1 AND (a.berlaku_mulai IS NULL OR a.berlaku_mulai <= CURDATE()) AND (a.berlaku_selesai IS NULL OR a.berlaku_selesai >= CURDATE())) AS berlaku
            FROM v4_tanda_tangan_aset a JOIN v4_penanda_tangan p ON p.id = a.penanda_tangan_id WHERE a.id = ?', 'i', [$id])[0] ?? null;
    }

    /**
     * Nama penanda tangan per ID (riwayat perubahan R-7).
     *
     * @param array<int, int|string|null> $ids
     * @return array<int, string>
     */
    public function namaPenanda(array $ids): array
    {
        [$in, $t, $list] = self::in(array_filter(array_map('intval', $ids)));
        $out = [];
        foreach ($this->rows("SELECT id, TRIM(CONCAT(nama, ' ', COALESCE(gelar, ''))) AS nama FROM v4_penanda_tangan WHERE id IN ($in)", $t, $list) as $r) {
            $out[(int) $r['id']] = (string) $r['nama'];
        }
        return $out;
    }

    /** @return array<int, array<string, mixed>> */
    public function riwayatPenanda(int $rencanaId): array
    {
        return $this->rows('SELECT version, snapshot_json, actor_user_id, alasan, created_at FROM v4_rencana_penanda_riwayat WHERE rencana_id = ? ORDER BY id DESC LIMIT 50', 'i', [$rencanaId]);
    }

    /**
     * Seluruh komponen nilai satu mapel untuk santri yang sudah diizinkan
     * layanan (kisi R-2/R-3).
     *
     * @param array<int, int> $santriIds
     * @return array<int, array<int, array<string, mixed>>> santri_id => baris v4_nilai
     */
    public function nilaiKelas(int $rencanaMapelId, array $santriIds): array
    {
        [$in, $t, $ids] = self::in($santriIds);
        $out = [];
        foreach ($this->rows("SELECT * FROM v4_nilai WHERE rencana_mapel_id = ? AND santri_id IN ($in) ORDER BY santri_id, komponen, nomor_harian", 'i' . $t, array_merge([$rencanaMapelId], $ids)) as $r) {
            $out[(int) $r['santri_id']][] = $r;
        }
        return $out;
    }

    /**
     * Sikap per santri pada semester (kisi R-4).
     *
     * @param array<int, int> $santriIds
     * @return array<int, array<string, mixed>> santri_id => baris v4_sikap
     */
    public function sikapKelas(int $tahunId, array $santriIds): array
    {
        [$in, $t, $ids] = self::in($santriIds);
        $out = [];
        foreach ($this->rows("SELECT * FROM v4_sikap WHERE tahun_ajaran_id = ? AND santri_id IN ($in)", 'i' . $t, array_merge([$tahunId], $ids)) as $r) {
            $out[(int) $r['santri_id']] = $r;
        }
        return $out;
    }

    /**
     * Status rapor dan versi terakhir per santri pada semester (R-6).
     *
     * @param array<int, int> $santriIds
     * @return array<int, array<string, mixed>>
     */
    public function raporKelas(int $tahunId, array $santriIds): array
    {
        [$in, $t, $ids] = self::in($santriIds);
        $out = [];
        foreach ($this->rows("SELECT r.id, r.santri_id, r.status, r.current_version,
                (SELECT v.diterbitkan_pada FROM v4_rapor_versi v WHERE v.rapor_id = r.id AND v.version = r.current_version) AS diterbitkan_pada,
                (SELECT COALESCE(MAX(o.id), 0) FROM v4_rapor_operasi o WHERE o.rapor_id = r.id) AS revision
            FROM v4_rapor r WHERE r.tahun_ajaran_id = ? AND r.santri_id IN ($in)", 'i' . $t, array_merge([$tahunId], $ids)) as $r) {
            $out[(int) $r['santri_id']] = $r;
        }
        return $out;
    }

    /**
     * Anak yang terhubung ke akun wali (chip R-8), memakai relasi yang sama
     * dengan `RaporService::listing()`.
     *
     * @return array<int, array{id:int, nama_santri:string}>
     */
    public function anakWali(int $userId): array
    {
        return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'nama_santri' => (string) $r['nama_santri']], $this->rows(
            'SELECT DISTINCT s.id, s.nama_santri FROM santri s JOIN santri_wali sw ON sw.santri_id = s.id AND sw.archived_at IS NULL
             JOIN wali w ON w.id = sw.wali_id AND w.is_active = 1 AND w.archived_at IS NULL JOIN users u ON u.wali_id = w.id AND u.is_active = 1
             WHERE u.id = ? ORDER BY s.nama_santri, s.id', 'i', [$userId]));
    }

    /**
     * Santri pemilik rapor (mengelompokkan daftar R-8 per anak). Masukan
     * hanya ID dari `RaporService::listing()` akun wali itu.
     *
     * @param array<int, int|string> $raporIds
     * @return array<int, int> rapor_id => santri_id
     */
    public function santriRapor(array $raporIds): array
    {
        [$in, $t, $ids] = self::in($raporIds);
        $out = [];
        foreach ($this->rows("SELECT id, santri_id FROM v4_rapor WHERE id IN ($in)", $t, $ids) as $r) {
            $out[(int) $r['id']] = (int) $r['santri_id'];
        }
        return $out;
    }
}
