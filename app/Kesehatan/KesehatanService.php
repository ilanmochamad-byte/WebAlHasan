<?php

declare(strict_types=1);

namespace App\Kesehatan;

use App\Audit\AuditLogger;
use App\Auth\Capabilities;
use App\Database\PageQuery;
use App\Penugasan\PenugasanJenis;
use DateTimeImmutable;

/**
 * Layanan kesehatan santri — PRD V5 Fase 1 (penugasan kesehatan dan kunjungan
 * dasar). Dipakai halaman web `portal/kesehatan*.php` dan API
 * `/api/v1/v5/kesehatan/*` sehingga keputusan akses keduanya identik.
 *
 * Hak dihitung ulang dari basis data pada SETIAP panggilan publik
 * (`Capabilities::forget()` lebih dahulu), tidak dari sesi/token/menu:
 *
 *   - petugas  : capability `kesehatan.catat` bersumber penugasan milik akun
 *                sendiri (akun aktif + peran pengurus + pengurus aktif +
 *                penugasan kesehatan aktif) → baca + catat;
 *   - admin    : capability yang sama bersumber pengawasan admin → HANYA
 *                baca rincian dan riwayat akses. Admin tidak pernah mengubah
 *                isi klinis (PRD V5 §5.1 [JANGAN DIUBAH]); akun admin yang juga
 *                memegang penugasan (`keduanya`) tetap diperlakukan sebagai
 *                admin untuk mutasi klinis;
 *   - lainnya  : ditolak 403 (pengurus tanpa penugasan, guru, wali, akun
 *                nonaktif). Pembaca pembimbing/murobi baru datang di Fase 2.
 *
 * Pencatatan memeriksa ulang penugasan dengan kunci baca DI DALAM transaksi,
 * sehingga penugasan yang diakhiri/dinonaktifkan saat sesi petugas masih
 * terbuka menolak tulisan berikutnya tanpa baris baru. Idempotensi per akun:
 * kunci sama + isi sama → kunjungan yang sama; kunci sama + isi berbeda →
 * 409 tanpa perubahan data.
 *
 * Privasi: `audit_logs` dan `kesehatan_akses_log` hanya menerima ID, status,
 * versi, sumber hak, dan kanal — tidak pernah keluhan, catatan klinis, atau
 * tindakan. Pesan galat tidak memuat nilai masukan.
 */
final class KesehatanService
{
    public const SUMBER_PETUGAS = 'petugas';
    public const SUMBER_ADMIN = 'admin';
    public const SUMBER_ADMIN_PETUGAS = 'admin_petugas';

    public const KANAL_WEB = 'web';
    public const KANAL_API = 'api';

    private const OPERASI_BUAT = 'kunjungan.buat';
    private const BATAS_KELUHAN = 1000;
    private const BATAS_CATATAN = 4000;
    private const BATAS_TINDAKAN = 2000;

    public function __construct(
        private KesehatanRepository $repo,
        private Capabilities $capabilities,
        private AuditLogger $audit
    ) {
    }

    /**
     * Hak akun saat ini.
     *
     * @param array<string, mixed> $user
     * @return array{baca:bool, catat:bool, sumber:?string, riwayat_akses:bool}
     */
    public function akses(array $user): array
    {
        $this->siap();
        $userId = (int) ($user['id'] ?? 0);
        if ($userId < 1) {
            return ['baca' => false, 'catat' => false, 'sumber' => null, 'riwayat_akses' => false];
        }
        $this->capabilities->forget($userId);
        $sumber = match ($this->capabilities->featureSource($user, PenugasanJenis::CAP_KESEHATAN_CATAT)) {
            Capabilities::SUMBER_PENUGASAN => self::SUMBER_PETUGAS,
            Capabilities::SUMBER_ADMIN => self::SUMBER_ADMIN,
            Capabilities::SUMBER_KEDUANYA => self::SUMBER_ADMIN_PETUGAS,
            default => null,
        };

        return [
            'baca' => $sumber !== null,
            'catat' => $sumber === self::SUMBER_PETUGAS,
            'sumber' => $sumber,
            'riwayat_akses' => in_array($sumber, [self::SUMBER_ADMIN, self::SUMBER_ADMIN_PETUGAS], true),
        ];
    }

    /**
     * Daftar kunjungan tanpa isi klinis.
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $input q, santri_id, status, page
     * @return array{rows:array<int, array<string, mixed>>, total:int, page:int, per_page:int, akses:array<string, mixed>}
     */
    public function daftar(array $user, array $input): array
    {
        $akses = $this->wajibBaca($user);
        $status = is_string($input['status'] ?? null) && in_array($input['status'], ['Draf', 'Tercatat'], true) ? $input['status'] : '';
        $hasil = $this->repo->daftar([
            'q' => PageQuery::term($input['q'] ?? ''),
            'santri_id' => $this->idOpsional($input['santri_id'] ?? null),
            'status' => $status,
        ], $this->halaman($input['page'] ?? 1));

        return [
            'rows' => array_map(fn (array $r): array => $this->ringkas($r), $hasil['rows']),
            'total' => (int) $hasil['total'],
            'page' => (int) $hasil['page'],
            'per_page' => (int) $hasil['perPage'],
            'akses' => $akses,
        ];
    }

    /**
     * Santri aktif yang dapat dipilih petugas.
     *
     * @param array<string, mixed> $user
     * @return array{rows:array<int, array{id:int, nis:string, nama:string}>, total:int}
     */
    public function santriPilihan(array $user, mixed $q): array
    {
        $this->wajibCatat($user);
        $hasil = $this->repo->santriPilihan(PageQuery::term($q));

        return [
            'rows' => array_map(static fn (array $s): array => ['id' => (int) $s['id'], 'nis' => (string) $s['nis'], 'nama' => (string) $s['nama_santri']], $hasil['rows']),
            'total' => (int) $hasil['total'],
        ];
    }

    /**
     * Mencatat satu kunjungan.
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $input idempotency_key, santri_id, waktu_kunjungan, keluhan, catatan_klinis, tindakan
     * @return array{id:int, status:int, replayed:bool}
     */
    public function buat(array $user, array $input, string $kanal): array
    {
        $this->wajibCatat($user);
        $userId = (int) $user['id'];
        $data = $this->normalisasi($input);
        $hash = hash('sha256', (string) json_encode([
            self::OPERASI_BUAT, $data['santri_id'], $data['waktu_kunjungan'], $data['status'],
            $data['keluhan'], $data['catatan_klinis'], $data['tindakan'],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $this->repo->transaction(function () use ($userId, $data, $hash, $kanal): array {
            $penugasan = $this->repo->kunciPenugasanAktif($userId);
            if ($penugasan === null) {
                throw new KesehatanException('Penugasan kesehatan Anda sudah tidak aktif. Kunjungan tidak disimpan.', 403);
            }
            $klaim = $this->repo->klaimIdempotensi($userId, self::OPERASI_BUAT, $data['idempotency_key'], $hash);
            if (!hash_equals($klaim['request_hash'], $hash)) {
                throw new KesehatanException('Kunci idempotensi sudah dipakai untuk isi kunjungan lain. Tidak ada data yang diubah.', 409);
            }
            if ($klaim['kunjungan_id'] !== null) {
                return ['id' => $klaim['kunjungan_id'], 'status' => 200, 'replayed' => true];
            }
            if ($this->repo->santriAktif($data['santri_id'], true) === null) {
                throw new KesehatanException('Santri tidak ditemukan atau sudah tidak aktif.', 422);
            }
            $id = $this->repo->simpanKunjungan($data, $userId, $penugasan['pengurus_id'], $penugasan['id']);
            $this->repo->selesaikanIdempotensi($klaim['id'], $id, 201);
            if (!$this->audit->log('kesehatan.kunjungan.dicatat', 'kesehatan_kunjungan', $id, null, [
                'kunjungan_id' => $id,
                'santri_id' => $data['santri_id'],
                'status' => $data['status'],
                'versi' => 1,
                'penugasan_id' => $penugasan['id'],
                'sumber' => self::SUMBER_PETUGAS,
                'kanal' => $kanal,
            ], $userId)) {
                throw new KesehatanException('Audit wajib tidak dapat disimpan. Kunjungan tidak disimpan.', 503);
            }

            return ['id' => $id, 'status' => 201, 'replayed' => false];
        });
    }

    /**
     * Rincian satu kunjungan beserta isi klinis. Setiap pembacaan tercatat di
     * `kesehatan_akses_log`; bila pencatatan gagal, rincian tidak dibuka.
     *
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function detail(array $user, int $id, string $kanal): array
    {
        $akses = $this->wajibBaca($user);
        $row = $id > 0 ? $this->repo->kunjungan($id) : null;
        if ($row === null) {
            throw new KesehatanException('Kunjungan tidak ditemukan.', 404);
        }
        $this->repo->catatAkses((int) $row['id'], (int) $row['santri_id'], (int) $user['id'], (string) $akses['sumber'], 'baca_detail', $this->kanal($kanal));

        return $this->ringkas($row) + [
            'keluhan' => (string) $row['keluhan'],
            'catatan_klinis' => $row['catatan_klinis'] === null ? null : (string) $row['catatan_klinis'],
            'tindakan' => $row['tindakan'] === null ? null : (string) $row['tindakan'],
            'diperbarui_pada' => (string) $row['updated_at'],
            'akses' => $akses,
        ];
    }

    /**
     * Riwayat akses rincian satu kunjungan — hanya admin (pengawasan).
     *
     * @param array<string, mixed> $user
     * @return array{rows:array<int, array<string, mixed>>, total:int, page:int, per_page:int}
     */
    public function riwayatAkses(array $user, int $id, mixed $page = 1): array
    {
        $akses = $this->wajibBaca($user);
        if (!$akses['riwayat_akses']) {
            throw new KesehatanException('Riwayat akses hanya untuk admin.', 403);
        }
        if ($id < 1 || $this->repo->kunjungan($id) === null) {
            throw new KesehatanException('Kunjungan tidak ditemukan.', 404);
        }
        $hasil = $this->repo->riwayatAkses($id, $this->halaman($page));

        return [
            'rows' => array_map(static fn (array $r): array => [
                'id' => (int) $r['id'],
                'pengguna' => ['id' => (int) $r['user_id'], 'nama' => (string) $r['pengguna_nama'], 'username' => (string) $r['username']],
                'sumber' => (string) $r['sumber'],
                'aksi' => (string) $r['aksi'],
                'kanal' => (string) $r['kanal'],
                'waktu' => (string) $r['created_at'],
            ], $hasil['rows']),
            'total' => (int) $hasil['total'],
            'page' => (int) $hasil['page'],
            'per_page' => (int) $hasil['perPage'],
        ];
    }

    // =======================================================================

    private function siap(): void
    {
        if (!$this->repo->skemaSiap()) {
            throw new KesehatanException('Modul kesehatan belum dipasang pada basis data ini.', 503);
        }
    }

    /** @return array{baca:bool, catat:bool, sumber:?string, riwayat_akses:bool} */
    private function wajibBaca(array $user): array
    {
        $akses = $this->akses($user);
        if (!$akses['baca']) {
            throw new KesehatanException('Catatan kesehatan hanya untuk petugas kesehatan berpenugasan aktif dan admin.', 403);
        }

        return $akses;
    }

    private function wajibCatat(array $user): void
    {
        $akses = $this->akses($user);
        if ($akses['catat']) {
            return;
        }
        throw new KesehatanException($akses['baca']
            ? 'Admin hanya membaca catatan kesehatan dan tidak mengubah isi klinis.'
            : 'Pencatatan kunjungan hanya untuk pengurus dengan penugasan kesehatan aktif.', 403);
    }

    /**
     * @param array<string, mixed> $input
     * @return array{idempotency_key:string, santri_id:int, waktu_kunjungan:string, status:string,
     *     keluhan:string, catatan_klinis:?string, tindakan:?string}
     */
    private function normalisasi(array $input): array
    {
        $kunci = $input['idempotency_key'] ?? null;
        if (!is_string($kunci) || !preg_match('/^[A-Za-z0-9._:-]{8,100}$/D', $kunci)) {
            throw new KesehatanException('Kunci idempotensi wajib 8–100 karakter aman (huruf, angka, . _ : -).');
        }
        $santri = $input['santri_id'] ?? null;
        if (!is_scalar($santri) || !preg_match('/^[1-9][0-9]{0,9}$/D', (string) $santri) || (int) $santri > 2147483647) {
            throw new KesehatanException('Santri wajib dipilih.');
        }

        return [
            'idempotency_key' => $kunci,
            'santri_id' => (int) $santri,
            'waktu_kunjungan' => $this->waktu($input['waktu_kunjungan'] ?? null),
            // Fase 1 hanya mencatat kunjungan berstatus Tercatat; alur Draf dan
            // penyuntingannya datang bersama koreksi berversi (Fase 2).
            'status' => 'Tercatat',
            'keluhan' => $this->teksWajib($input['keluhan'] ?? null, self::BATAS_KELUHAN, 'Keluhan'),
            'catatan_klinis' => $this->teksOpsional($input['catatan_klinis'] ?? null, self::BATAS_CATATAN, 'Catatan klinis'),
            'tindakan' => $this->teksOpsional($input['tindakan'] ?? null, self::BATAS_TINDAKAN, 'Tindakan'),
        ];
    }

    private function waktu(mixed $value): string
    {
        if (!is_string($value) || trim($value) === '') {
            throw new KesehatanException('Waktu kunjungan wajib diisi.');
        }
        $raw = str_replace('T', ' ', trim($value));
        $format = strlen($raw) === 16 ? 'Y-m-d H:i' : 'Y-m-d H:i:s';
        $date = DateTimeImmutable::createFromFormat('!' . $format, $raw);
        $errors = DateTimeImmutable::getLastErrors();
        if (!$date || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) || $date->format($format) !== $raw) {
            throw new KesehatanException('Waktu kunjungan tidak valid.');
        }
        if ($date->getTimestamp() > time() + 300) {
            throw new KesehatanException('Waktu kunjungan tidak boleh di masa depan.');
        }
        if ($date->format('Y') < '2000') {
            throw new KesehatanException('Waktu kunjungan tidak valid.');
        }

        return $date->format('Y-m-d H:i:s');
    }

    private function teksWajib(mixed $value, int $maks, string $label): string
    {
        $teks = $this->teksOpsional($value, $maks, $label);
        if ($teks === null) {
            throw new KesehatanException($label . ' wajib diisi.');
        }

        return $teks;
    }

    private function teksOpsional(mixed $value, int $maks, string $label): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8')) {
            throw new KesehatanException($label . ' wajib berupa teks.');
        }
        $teks = trim(str_replace(["\r\n", "\r"], "\n", $value));
        if (mb_strlen($teks) > $maks) {
            throw new KesehatanException($label . ' maksimal ' . $maks . ' karakter.');
        }

        return $teks === '' ? null : $teks;
    }

    private function idOpsional(mixed $value): int
    {
        return is_scalar($value) && preg_match('/^[1-9][0-9]{0,9}$/D', (string) $value) && (int) $value <= 2147483647 ? (int) $value : 0;
    }

    private function halaman(mixed $value): int
    {
        return is_scalar($value) && preg_match('/^[1-9][0-9]{0,6}$/D', (string) $value) ? (int) $value : 1;
    }

    private function kanal(string $kanal): string
    {
        return $kanal === self::KANAL_API ? self::KANAL_API : self::KANAL_WEB;
    }

    /**
     * Kolom yang aman untuk daftar (tanpa isi klinis).
     *
     * @param array<string, mixed> $r
     * @return array<string, mixed>
     */
    private function ringkas(array $r): array
    {
        return [
            'id' => (int) $r['id'],
            'santri' => ['id' => (int) $r['santri_id'], 'nis' => (string) $r['nis'], 'nama' => (string) $r['nama_santri']],
            'waktu_kunjungan' => (string) $r['waktu_kunjungan'],
            'dicatat_pada' => (string) $r['created_at'],
            'status' => (string) $r['status'],
            'versi' => (int) $r['versi'],
            'petugas' => ['nama' => (string) $r['petugas_nama']],
        ];
    }
}
