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
 * dasar) dan Fase 2 (obat, rujukan, tindak lanjut, koreksi berversi, pembaca
 * internal). Dipakai halaman web `portal/kesehatan*.php` dan API
 * `/api/v1/v5/kesehatan/*` sehingga keputusan akses keduanya identik.
 *
 * Hak dihitung ulang dari basis data pada SETIAP panggilan publik
 * (`Capabilities::forget()` lebih dahulu), tidak dari sesi/token/menu:
 *
 *   - petugas    : capability `kesehatan.catat` bersumber penugasan milik akun
 *                  sendiri (akun aktif + peran pengurus + pengurus aktif +
 *                  penugasan kesehatan aktif) → baca seluruh + catat/koreksi;
 *   - admin      : capability yang sama bersumber pengawasan admin → HANYA
 *                  baca rincian, riwayat versi, dan riwayat akses. Admin tidak
 *                  pernah mengubah isi klinis (PRD V5 §5.1 [JANGAN DIUBAH]);
 *                  akun admin yang juga memegang penugasan (`keduanya`) tetap
 *                  diperlakukan sebagai admin untuk mutasi klinis;
 *   - pembimbing/: (Fase 2) penugasan pembimbing (peran pengurus) atau murobi
 *     murobi       (peran guru) aktif → HANYA baca kunjungan berstatus
 *                  Tercatat milik santri dalam cakupan, diperiksa per baris
 *                  (`KesehatanRepository::saringBinaan()`); tanpa riwayat
 *                  versi, riwayat akses, atau mutasi apa pun;
 *   - lainnya    : ditolak 403 (pengurus tanpa penugasan, guru non-murobi,
 *                  wali, akun nonaktif).
 *
 * Mutasi (catat dan koreksi) memeriksa ulang penugasan dengan kunci baca DI
 * DALAM transaksi. Koreksi memakai optimistic version: baris kunjungan
 * dikunci tulis, versi yang dibawa permintaan harus sama dengan versi
 * terbaru, lalu versi naik satu dan snapshot isi + rincian disimpan; koreksi
 * dari versi lama ditolak 409 tanpa menyentuh versi baru. Alasan wajib bila
 * kunjungan sudah Tercatat. Idempotensi per akun: kunci sama + isi sama →
 * hasil yang sama; kunci sama + isi berbeda → 409 tanpa perubahan data.
 *
 * Privasi: `audit_logs` dan `kesehatan_akses_log` hanya menerima ID, status,
 * versi, jumlah rincian, nama bagian yang berubah, sumber hak, dan kanal —
 * tidak pernah keluhan, catatan klinis, tindakan, obat, rujukan, tindak
 * lanjut, atau alasan. Pesan galat tidak memuat nilai masukan.
 */
final class KesehatanService
{
    public const SUMBER_PETUGAS = 'petugas';
    public const SUMBER_ADMIN = 'admin';
    public const SUMBER_ADMIN_PETUGAS = 'admin_petugas';
    public const SUMBER_PEMBIMBING = 'pembimbing';
    public const SUMBER_MUROBI = 'murobi';
    public const SUMBER_PEMBIMBING_MUROBI = 'pembimbing_murobi';

    public const CAKUPAN_SELURUH = 'seluruh';
    public const CAKUPAN_BINAAN = 'binaan';

    public const KANAL_WEB = 'web';
    public const KANAL_API = 'api';

    public const STATUS_DRAF = 'Draf';
    public const STATUS_TERCATAT = 'Tercatat';
    public const RUJUKAN_MENUNGGU = 'Menunggu hasil';
    public const RUJUKAN_DIKETAHUI = 'Hasil diketahui';
    public const TINDAK_LANJUT_TERBUKA = 'Terbuka';
    public const TINDAK_LANJUT_SELESAI = 'Selesai';

    private const OPERASI_BUAT = 'kunjungan.buat';
    private const OPERASI_KOREKSI = 'kunjungan.koreksi';
    private const OPERASI_HASIL_RUJUKAN = 'rujukan.hasil';
    private const BATAS_KELUHAN = 1000;
    private const BATAS_CATATAN = 4000;
    private const BATAS_TINDAKAN = 2000;
    private const BATAS_ALASAN = 1000;
    private const MIN_ALASAN = 5;
    private const BATAS_OBAT = 20;
    private const BATAS_RUJUKAN = 5;
    private const BATAS_TINDAK_LANJUT = 10;
    private const KOLOM_UTAMA = ['status', 'waktu_kunjungan', 'keluhan', 'catatan_klinis', 'tindakan'];
    private const BAGIAN_RINCIAN = ['obat', 'rujukan', 'tindak_lanjut'];

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
     * @return array{baca:bool, catat:bool, sumber:?string, cakupan:?string, riwayat_akses:bool, riwayat_versi:bool,
     *     binaan:array{pembimbing:bool, murobi:bool}}
     */
    public function akses(array $user): array
    {
        $this->siap();
        $userId = (int) ($user['id'] ?? 0);
        $binaan = ['pembimbing' => false, 'murobi' => false];
        if ($userId < 1) {
            return ['baca' => false, 'catat' => false, 'sumber' => null, 'cakupan' => null, 'riwayat_akses' => false, 'riwayat_versi' => false, 'binaan' => $binaan];
        }
        $this->capabilities->forget($userId);
        $sumber = match ($this->capabilities->featureSource($user, PenugasanJenis::CAP_KESEHATAN_CATAT)) {
            Capabilities::SUMBER_PENUGASAN => self::SUMBER_PETUGAS,
            Capabilities::SUMBER_ADMIN => self::SUMBER_ADMIN,
            Capabilities::SUMBER_KEDUANYA => self::SUMBER_ADMIN_PETUGAS,
            default => null,
        };
        $cakupan = $sumber === null ? null : self::CAKUPAN_SELURUH;
        if ($sumber === null) {
            // Pembaca binaan hanya dihitung bila akun tidak memegang hak baca seluruh santri.
            $binaan = $this->repo->binaanAktif($userId);
            $sumber = match (true) {
                $binaan['pembimbing'] && $binaan['murobi'] => self::SUMBER_PEMBIMBING_MUROBI,
                $binaan['pembimbing'] => self::SUMBER_PEMBIMBING,
                $binaan['murobi'] => self::SUMBER_MUROBI,
                default => null,
            };
            $cakupan = $sumber === null ? null : self::CAKUPAN_BINAAN;
        }
        $pengawas = in_array($sumber, [self::SUMBER_ADMIN, self::SUMBER_ADMIN_PETUGAS], true);

        return [
            'baca' => $sumber !== null,
            'catat' => $sumber === self::SUMBER_PETUGAS,
            'sumber' => $sumber,
            'cakupan' => $cakupan,
            'riwayat_akses' => $pengawas,
            'riwayat_versi' => $pengawas || $sumber === self::SUMBER_PETUGAS,
            'binaan' => $binaan,
        ];
    }

    /**
     * Daftar kunjungan tanpa isi klinis, dengan penyaring operasional dan
     * ringkasan dari kueri yang sama (hak per baris tidak berkurang).
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $input q, santri_id, status, dari, sampai, rujukan, tindak_lanjut, page
     * @return array{rows:array<int, array<string, mixed>>, total:int, page:int, per_page:int, akses:array<string, mixed>,
     *     ringkasan:array<string, int>, filter:array<string, mixed>}
     */
    public function daftar(array $user, array $input): array
    {
        $akses = $this->wajibBaca($user);
        $saring = $this->saring($akses, (int) $user['id']);
        $status = $akses['cakupan'] === self::CAKUPAN_SELURUH && is_string($input['status'] ?? null)
            && in_array($input['status'], [self::STATUS_DRAF, self::STATUS_TERCATAT], true) ? $input['status'] : '';
        $filter = [
            'q' => PageQuery::term($input['q'] ?? ''),
            'santri_id' => $this->idOpsional($input['santri_id'] ?? null),
            'status' => $status,
            'dari' => $this->tanggalSaring($input['dari'] ?? null),
            'sampai' => $this->tanggalSaring($input['sampai'] ?? null),
            'rujukan' => in_array($input['rujukan'] ?? null, ['ada', 'menunggu', 'diketahui', 'tanpa'], true) ? (string) $input['rujukan'] : '',
            'tindak_lanjut' => in_array($input['tindak_lanjut'] ?? null, ['terbuka', 'tanpa_terbuka'], true) ? (string) $input['tindak_lanjut'] : '',
        ];
        $hasil = $this->repo->daftar($filter, $this->halaman($input['page'] ?? 1), $saring);

        return [
            'rows' => array_map(fn (array $r): array => $this->ringkas($r) + ['penanda' => [
                'obat' => (int) $r['jumlah_obat'],
                'rujukan' => (int) $r['jumlah_rujukan'],
                'rujukan_menunggu' => (int) $r['rujukan_menunggu'],
                'tindak_lanjut' => (int) $r['jumlah_tindak_lanjut'],
                'tindak_lanjut_terbuka' => (int) $r['tindak_lanjut_terbuka'],
            ]], $hasil['rows']),
            'total' => (int) $hasil['total'],
            'page' => (int) $hasil['page'],
            'per_page' => (int) $hasil['perPage'],
            'akses' => $akses,
            'ringkasan' => $this->repo->ringkasan($filter, $saring),
            'filter' => $filter,
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
     * Mencatat satu kunjungan beserta obat, rujukan, dan tindak lanjutnya.
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $input idempotency_key, santri_id, waktu_kunjungan, keluhan, catatan_klinis, tindakan,
     *     status (Draf|Tercatat, bawaan Tercatat), obat[], rujukan[], tindak_lanjut[]
     * @return array{id:int, status:int, replayed:bool}
     */
    public function buat(array $user, array $input, string $kanal): array
    {
        $this->wajibCatat($user);
        $userId = (int) $user['id'];
        $data = $this->normalisasi($input);
        $data['rincian'] = $this->normalisasiRincian($input, $data['waktu_kunjungan'], false);
        $hash = hash('sha256', (string) json_encode([
            self::OPERASI_BUAT, $data['santri_id'], $data['waktu_kunjungan'], $data['status'],
            $data['keluhan'], $data['catatan_klinis'], $data['tindakan'], $data['rincian'],
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
            $this->repo->terapkanRincian($id, 1, $this->rencana($this->rincianKosong(), $data['rincian']), $userId);
            $this->repo->simpanRincianVersi($id, 1, $this->snapshot($id), $klaim['id'], $userId);
            $this->repo->selesaikanIdempotensi($klaim['id'], $id, 201);
            if (!$this->audit->log('kesehatan.kunjungan.dicatat', 'kesehatan_kunjungan', $id, null, [
                'kunjungan_id' => $id,
                'santri_id' => $data['santri_id'],
                'status' => $data['status'],
                'versi' => 1,
                'jumlah_rincian' => $this->jumlahRincian($data['rincian']),
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
     * Koreksi berversi: permintaan membawa versi yang dilihat petugas dan
     * keadaan LENGKAP kunjungan sesudah koreksi (isi utama + seluruh rincian
     * aktif; rincian lama dikenali dari `id`, rincian tanpa `id` = baru,
     * rincian lama yang tidak dikirim = dibatalkan pada versi baru).
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $input versi, alasan, idempotency_key, waktu_kunjungan, keluhan, catatan_klinis, tindakan,
     *     status (kosong = tetap), obat[], rujukan[], tindak_lanjut[]
     * @return array{id:int, versi:int, status:int, replayed:bool}
     */
    public function koreksi(array $user, int $id, array $input, string $kanal): array
    {
        $this->wajibCatat($user);
        $kunci = $this->kunci($input['idempotency_key'] ?? null);
        $versi = $this->versiDiminta($input['versi'] ?? null);
        $alasan = $this->teksOpsional($input['alasan'] ?? null, self::BATAS_ALASAN, 'Alasan koreksi');
        $waktu = $this->waktu($input['waktu_kunjungan'] ?? null);
        // Status kosong = pertahankan status saat ini (draf tidak tercatatkan diam-diam).
        $status = ($input['status'] ?? null) === null || ($input['status'] ?? null) === '' ? null : $this->status($input['status']);
        $baru = [
            'status' => $status,
            'waktu_kunjungan' => $waktu,
            'keluhan' => $this->teksWajib($input['keluhan'] ?? null, self::BATAS_KELUHAN, 'Keluhan'),
            'catatan_klinis' => $this->teksOpsional($input['catatan_klinis'] ?? null, self::BATAS_CATATAN, 'Catatan klinis'),
            'tindakan' => $this->teksOpsional($input['tindakan'] ?? null, self::BATAS_TINDAKAN, 'Tindakan'),
            'rincian' => $this->normalisasiRincian($input, $waktu, true),
        ];
        $hash = hash('sha256', (string) json_encode([self::OPERASI_KOREKSI, $id, $versi, $alasan, $baru], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $this->terapkan((int) $user['id'], $id, $versi, $alasan, $kunci, self::OPERASI_KOREKSI, $hash, $kanal,
            static fn (array $kini): array => ['status' => $baru['status'] ?? $kini['status']] + $baru);
    }

    /**
     * Mencatat hasil satu rujukan yang masih menunggu. Diperlakukan sebagai
     * koreksi berversi (alasan wajib bila kunjungan Tercatat).
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $input versi, alasan, idempotency_key, hasil, tanggal_hasil
     * @return array{id:int, versi:int, status:int, replayed:bool}
     */
    public function hasilRujukan(array $user, int $id, int $rujukanId, array $input, string $kanal): array
    {
        $this->wajibCatat($user);
        $kunci = $this->kunci($input['idempotency_key'] ?? null);
        $versi = $this->versiDiminta($input['versi'] ?? null);
        $alasan = $this->teksOpsional($input['alasan'] ?? null, self::BATAS_ALASAN, 'Alasan');
        $hasil = $this->teksWajib($input['hasil'] ?? null, 4000, 'Hasil rujukan');
        $tanggal = $this->tanggal($input['tanggal_hasil'] ?? null, 'Tanggal hasil') ?? date('Y-m-d');
        $hash = hash('sha256', (string) json_encode([self::OPERASI_HASIL_RUJUKAN, $id, $rujukanId, $versi, $alasan, $hasil, $tanggal], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $this->terapkan((int) $user['id'], $id, $versi, $alasan, $kunci, self::OPERASI_HASIL_RUJUKAN, $hash, $kanal,
            function (array $kini) use ($rujukanId, $hasil, $tanggal): array {
                foreach ($kini['rincian']['rujukan'] as $i => $r) {
                    if ($r['id'] !== $rujukanId) {
                        continue;
                    }
                    if ($r['status'] !== self::RUJUKAN_MENUNGGU) {
                        throw new KesehatanException('Hasil rujukan ini sudah tercatat. Gunakan koreksi untuk mengubahnya.', 409);
                    }
                    $kini['rincian']['rujukan'][$i] = array_replace($r, ['status' => self::RUJUKAN_DIKETAHUI, 'hasil' => $hasil, 'tanggal_hasil' => $tanggal]);
                    $this->validasiRujukan($kini['rincian']['rujukan'][$i], substr($kini['waktu_kunjungan'], 0, 10), 1);

                    return $kini;
                }
                throw new KesehatanException('Rujukan tidak ditemukan pada kunjungan ini.', 404);
            });
    }

    /**
     * Rincian satu kunjungan beserta isi klinis dan rinciannya. Setiap
     * pembacaan tercatat di `kesehatan_akses_log`; bila pencatatan gagal,
     * rincian tidak dibuka. Pembaca binaan hanya menemukan kunjungan Tercatat
     * santri dalam cakupannya; di luar cakupan dan tidak ada sama-sama 403.
     *
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function detail(array $user, int $id, string $kanal): array
    {
        $akses = $this->wajibBaca($user);
        $userId = (int) $user['id'];
        $row = $id > 0 ? $this->repo->kunjungan($id, $this->saring($akses, $userId)) : null;
        if ($row === null) {
            throw $akses['cakupan'] === self::CAKUPAN_BINAAN
                ? new KesehatanException('Kunjungan tidak ditemukan atau di luar cakupan binaan Anda.', 403)
                : new KesehatanException('Kunjungan tidak ditemukan.', 404);
        }
        $this->repo->catatAkses((int) $row['id'], (int) $row['santri_id'], $userId, $this->sumberBaris($akses, $userId, (int) $row['id']), 'baca_detail', $this->kanal($kanal));
        $rincian = $this->rincianDariBaris($this->repo->rincianAktif((int) $row['id']));

        return $this->ringkas($row) + [
            'keluhan' => (string) $row['keluhan'],
            'catatan_klinis' => $row['catatan_klinis'] === null ? null : (string) $row['catatan_klinis'],
            'tindakan' => $row['tindakan'] === null ? null : (string) $row['tindakan'],
            'diperbarui_pada' => (string) $row['updated_at'],
        ] + $rincian + ['akses' => $akses];
    }

    /**
     * Riwayat versi satu kunjungan, terbaru lebih dahulu, dengan perubahan
     * sebelum–sesudah tiap versi. Hanya petugas dan admin (pembaca binaan
     * tidak); setiap pembacaan tercatat di log akses.
     *
     * @param array<string, mixed> $user
     * @return array{kunjungan:array<string, mixed>, versi:array<int, array<string, mixed>>}
     */
    public function riwayatVersi(array $user, int $id, string $kanal): array
    {
        $akses = $this->wajibBaca($user);
        if (!$akses['riwayat_versi']) {
            throw new KesehatanException('Riwayat versi hanya untuk petugas kesehatan dan admin.', 403);
        }
        $row = $id > 0 ? $this->repo->kunjungan($id) : null;
        if ($row === null) {
            throw new KesehatanException('Kunjungan tidak ditemukan.', 404);
        }
        $this->repo->catatAkses((int) $row['id'], (int) $row['santri_id'], (int) $user['id'], (string) $akses['sumber'], 'baca_versi', $this->kanal($kanal));

        $hasil = [];
        $sebelum = null;
        foreach ($this->repo->versi($id) as $v) {
            $rincian = $v['rincian'] === null ? $this->rincianKosong() : json_decode((string) $v['rincian'], true, 32, JSON_THROW_ON_ERROR);
            $keadaan = [
                'status' => (string) $v['status'], 'waktu_kunjungan' => (string) $v['waktu_kunjungan'], 'keluhan' => (string) $v['keluhan'],
                'catatan_klinis' => $v['catatan_klinis'], 'tindakan' => $v['tindakan'],
                'rincian' => array_merge($this->rincianKosong(), is_array($rincian) ? $rincian : []),
            ];
            $hasil[] = [
                'versi' => (int) $v['versi'],
                'status' => $keadaan['status'],
                'alasan' => $v['alasan'] === null ? null : (string) $v['alasan'],
                'oleh' => ['id' => (int) $v['dibuat_oleh'], 'nama' => (string) $v['pembuat_nama']],
                'waktu' => (string) $v['dibuat_pada'],
                'perubahan' => $sebelum === null ? [] : $this->beda($sebelum, $keadaan),
            ];
            $sebelum = $keadaan;
        }

        return ['kunjungan' => $this->ringkas($row), 'versi' => array_reverse($hasil)];
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
    // Koreksi berversi
    // =======================================================================

    /**
     * Satu jalur untuk seluruh perubahan kunjungan tersimpan.
     *
     * Urutan di dalam transaksi: penugasan (kunci baca) → klaim idempotensi →
     * banding isi (409) → replay → kunci tulis kunjungan → banding versi (409)
     * → keadaan baru → validasi → UPDATE berpenjaga versi → snapshot versi +
     * rincian → audit metadata. Kegagalan di langkah mana pun membatalkan
     * seluruhnya (klaim ikut batal sehingga permintaan dapat diulang).
     *
     * @param callable(array<string, mixed>):array<string, mixed> $ubah
     * @return array{id:int, versi:int, status:int, replayed:bool}
     */
    private function terapkan(int $userId, int $id, int $versi, ?string $alasan, string $kunci, string $operasi, string $hash, string $kanal, callable $ubah): array
    {
        if ($id < 1) {
            throw new KesehatanException('Kunjungan tidak ditemukan.', 404);
        }

        return $this->repo->transaction(function () use ($userId, $id, $versi, $alasan, $kunci, $operasi, $hash, $kanal, $ubah): array {
            $penugasan = $this->repo->kunciPenugasanAktif($userId);
            if ($penugasan === null) {
                throw new KesehatanException('Penugasan kesehatan Anda sudah tidak aktif. Koreksi tidak disimpan.', 403);
            }
            $klaim = $this->repo->klaimIdempotensi($userId, $operasi, $kunci, $hash);
            if (!hash_equals($klaim['request_hash'], $hash)) {
                throw new KesehatanException('Kunci idempotensi sudah dipakai untuk perubahan lain. Tidak ada data yang diubah.', 409);
            }
            if ($klaim['kunjungan_id'] !== null) {
                return ['id' => $klaim['kunjungan_id'], 'versi' => (int) $this->repo->versiDariIdempotensi($klaim['id']), 'status' => 200, 'replayed' => true];
            }
            $baris = $this->repo->kunciKunjungan($id) ?? throw new KesehatanException('Kunjungan tidak ditemukan.', 404);
            if ((int) $baris['versi'] !== $versi) {
                throw new KesehatanException('Catatan ini sudah diubah menjadi versi ' . (int) $baris['versi'] . ' sejak Anda membukanya. Muat ulang lalu ulangi koreksi.', 409);
            }
            $kini = $this->keadaan($baris, $this->rincianDariBaris($this->repo->rincianAktif($id)));
            $baru = $ubah($kini);
            if ($kini['status'] === self::STATUS_TERCATAT && $baru['status'] === self::STATUS_DRAF) {
                throw new KesehatanException('Kunjungan yang sudah tercatat tidak dapat dikembalikan menjadi draf.');
            }
            if ($kini['status'] === self::STATUS_TERCATAT && ($alasan === null || mb_strlen($alasan) < self::MIN_ALASAN)) {
                throw new KesehatanException('Alasan koreksi wajib diisi (minimal ' . self::MIN_ALASAN . ' karakter) untuk kunjungan yang sudah tercatat.');
            }
            $rencana = $this->rencana($kini['rincian'], $baru['rincian']);
            $berubah = $this->bagianBerubah($kini, $baru);
            if ($berubah === []) {
                throw new KesehatanException('Tidak ada perubahan yang perlu disimpan.');
            }
            $versiBaru = $versi + 1;
            if (!$this->repo->perbaruiKunjungan($id, $versi, $versiBaru, $baru, $userId)) {
                throw new KesehatanException('Catatan ini sudah diubah permintaan lain. Muat ulang lalu ulangi koreksi.', 409);
            }
            $this->repo->simpanVersi($id, $versiBaru, $baru, $alasan, $userId);
            $this->repo->terapkanRincian($id, $versiBaru, $rencana, $userId);
            $this->repo->simpanRincianVersi($id, $versiBaru, $this->snapshot($id), $klaim['id'], $userId);
            $this->repo->selesaikanIdempotensi($klaim['id'], $id, 200);
            if (!$this->audit->log('kesehatan.kunjungan.dikoreksi', 'kesehatan_kunjungan', $id, [
                'versi' => $versi,
                'status' => $kini['status'],
            ], [
                'kunjungan_id' => $id,
                'santri_id' => (int) $baris['santri_id'],
                'versi' => $versiBaru,
                'status' => $baru['status'],
                'bagian_berubah' => $berubah,
                'jumlah_rincian' => $this->jumlahRincian($baru['rincian']),
                'operasi' => $operasi,
                'penugasan_id' => $penugasan['id'],
                'sumber' => self::SUMBER_PETUGAS,
                'kanal' => $kanal,
            ], $userId)) {
                throw new KesehatanException('Audit wajib tidak dapat disimpan. Koreksi tidak disimpan.', 503);
            }

            return ['id' => $id, 'versi' => $versiBaru, 'status' => 200, 'replayed' => false];
        });
    }

    /**
     * Rencana perubahan rincian: baris baru (tanpa id) → tambah, baris lama
     * yang isinya berbeda → ubah, baris lama yang tidak dikirim → batal.
     *
     * @param array<string, array<int, array<string, mixed>>> $kini
     * @param array<string, array<int, array<string, mixed>>> $baru
     * @return array<string, array<int, array{aksi:string, id:?int, data:array<string, mixed>}>>
     */
    private function rencana(array $kini, array $baru): array
    {
        $hasil = [];
        foreach (self::BAGIAN_RINCIAN as $bagian) {
            $lama = [];
            foreach ($kini[$bagian] as $r) {
                $lama[(int) $r['id']] = $r;
            }
            $hasil[$bagian] = [];
            $dilihat = [];
            foreach ($baru[$bagian] as $n => $r) {
                if ($r['id'] === null) {
                    $hasil[$bagian][] = ['aksi' => 'tambah', 'id' => null, 'data' => $r];
                    continue;
                }
                if (!isset($lama[$r['id']]) || isset($dilihat[$r['id']])) {
                    throw new KesehatanException($this->labelBagian($bagian) . ' ke-' . ($n + 1) . ' tidak ditemukan pada kunjungan ini.');
                }
                $dilihat[$r['id']] = true;
                if ($r !== $lama[$r['id']]) {
                    $hasil[$bagian][] = ['aksi' => 'ubah', 'id' => $r['id'], 'data' => $r];
                }
            }
            foreach ($lama as $lamaId => $r) {
                if (!isset($dilihat[$lamaId])) {
                    $hasil[$bagian][] = ['aksi' => 'batal', 'id' => $lamaId, 'data' => $r];
                }
            }
        }

        return $hasil;
    }

    /**
     * Kode bagian yang berbeda antara dua keadaan — hanya kode, untuk audit
     * metadata. Keluhan, catatan klinis, dan tindakan dilaporkan sebagai satu
     * kode `isi_klinis` agar nama kolom klinis pun tidak masuk log umum.
     *
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     * @return array<int, string>
     */
    private function bagianBerubah(array $a, array $b): array
    {
        $berubah = [];
        foreach (self::KOLOM_UTAMA as $kolom) {
            if ($a[$kolom] !== $b[$kolom]) {
                $berubah[] = in_array($kolom, ['keluhan', 'catatan_klinis', 'tindakan'], true) ? 'isi_klinis' : $kolom;
            }
        }
        $berubah = array_values(array_unique($berubah));
        foreach (self::BAGIAN_RINCIAN as $bagian) {
            if ($a['rincian'][$bagian] !== $b['rincian'][$bagian]) {
                $berubah[] = $bagian;
            }
        }

        return $berubah;
    }

    /**
     * Perubahan sebelum–sesudah antara dua versi berurutan.
     *
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     * @return array<int, array<string, mixed>>
     */
    private function beda(array $a, array $b): array
    {
        $hasil = [];
        foreach (self::KOLOM_UTAMA as $kolom) {
            if ($a[$kolom] !== $b[$kolom]) {
                $hasil[] = ['bagian' => 'kunjungan', 'id' => null, 'jenis' => 'ubah', 'kolom' => $kolom, 'sebelum' => $a[$kolom], 'sesudah' => $b[$kolom]];
            }
        }
        foreach (self::BAGIAN_RINCIAN as $bagian) {
            $lama = array_column($a['rincian'][$bagian], null, 'id');
            $baru = array_column($b['rincian'][$bagian], null, 'id');
            foreach ($baru as $rid => $r) {
                if (!isset($lama[$rid])) {
                    $hasil[] = ['bagian' => $bagian, 'id' => (int) $rid, 'jenis' => 'tambah', 'kolom' => null, 'sebelum' => null, 'sesudah' => $r];
                    continue;
                }
                foreach ($r as $kolom => $nilai) {
                    if ($kolom !== 'id' && ($lama[$rid][$kolom] ?? null) !== $nilai) {
                        $hasil[] = ['bagian' => $bagian, 'id' => (int) $rid, 'jenis' => 'ubah', 'kolom' => $kolom, 'sebelum' => $lama[$rid][$kolom] ?? null, 'sesudah' => $nilai];
                    }
                }
            }
            foreach ($lama as $rid => $r) {
                if (!isset($baru[$rid])) {
                    $hasil[] = ['bagian' => $bagian, 'id' => (int) $rid, 'jenis' => 'hapus', 'kolom' => null, 'sebelum' => $r, 'sesudah' => null];
                }
            }
        }

        return $hasil;
    }

    /**
     * Keadaan pembanding satu kunjungan (isi utama + rincian ternormalisasi).
     *
     * @param array<string, mixed> $baris
     * @param array<string, array<int, array<string, mixed>>> $rincian
     * @return array<string, mixed>
     */
    private function keadaan(array $baris, array $rincian): array
    {
        return [
            'status' => (string) $baris['status'],
            'waktu_kunjungan' => (string) $baris['waktu_kunjungan'],
            'keluhan' => (string) $baris['keluhan'],
            'catatan_klinis' => $baris['catatan_klinis'] === null ? null : (string) $baris['catatan_klinis'],
            'tindakan' => $baris['tindakan'] === null ? null : (string) $baris['tindakan'],
            'rincian' => $rincian,
        ];
    }

    /** @return array<string, array<int, array<string, mixed>>> snapshot rincian aktif (dengan id) */
    private function snapshot(int $kunjunganId): array
    {
        return $this->rincianDariBaris($this->repo->rincianAktif($kunjunganId));
    }

    /**
     * Baris basis data → bentuk ternormalisasi yang sama dengan masukan.
     *
     * @param array<string, array<int, array<string, mixed>>> $rows
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function rincianDariBaris(array $rows): array
    {
        $teks = static fn (mixed $v): ?string => $v === null ? null : (string) $v;

        return [
            'obat' => array_map(fn (array $r): array => [
                'id' => (int) $r['id'], 'nama_obat' => (string) $r['nama_obat'], 'jumlah' => $this->jumlah($r['jumlah'], 0),
                'satuan' => $teks($r['satuan']), 'petunjuk' => $teks($r['petunjuk']), 'waktu_pemberian' => (string) $r['waktu_pemberian'],
            ], $rows['obat']),
            'rujukan' => array_map(static fn (array $r): array => [
                'id' => (int) $r['id'], 'tujuan' => (string) $r['tujuan'], 'tanggal_rujukan' => (string) $r['tanggal_rujukan'],
                'alasan' => $teks($r['alasan']), 'status' => (string) $r['status'], 'hasil' => $teks($r['hasil']), 'tanggal_hasil' => $teks($r['tanggal_hasil']),
            ], $rows['rujukan']),
            'tindak_lanjut' => array_map(static fn (array $r): array => [
                'id' => (int) $r['id'], 'uraian' => (string) $r['uraian'], 'tanggal_target' => $teks($r['tanggal_target']),
                'status' => (string) $r['status'], 'tanggal_selesai' => $teks($r['tanggal_selesai']),
            ], $rows['tindak_lanjut']),
        ];
    }

    /** @return array{obat:array<int, mixed>, rujukan:array<int, mixed>, tindak_lanjut:array<int, mixed>} */
    private function rincianKosong(): array
    {
        return ['obat' => [], 'rujukan' => [], 'tindak_lanjut' => []];
    }

    /**
     * @param array<string, array<int, mixed>> $rincian
     * @return array{obat:int, rujukan:int, tindak_lanjut:int}
     */
    private function jumlahRincian(array $rincian): array
    {
        return ['obat' => count($rincian['obat']), 'rujukan' => count($rincian['rujukan']), 'tindak_lanjut' => count($rincian['tindak_lanjut'])];
    }

    // =======================================================================
    // Hak dan cakupan
    // =======================================================================

    private function siap(): void
    {
        if (!$this->repo->skemaSiap()) {
            throw new KesehatanException('Modul kesehatan belum dipasang pada basis data ini.', 503);
        }
    }

    /**
     * Penyaring baris pembaca binaan; null = seluruh kunjungan (petugas/admin).
     *
     * @param array<string, mixed> $akses
     * @return array{0:string, 1:array<int, int>}|null
     */
    private function saring(array $akses, int $userId): ?array
    {
        if ($akses['cakupan'] !== self::CAKUPAN_BINAAN) {
            return null;
        }

        return $this->repo->saringBinaan(array_keys(array_filter($akses['binaan'])), $userId);
    }

    /** Sumber hak yang membuka SATU baris (untuk log akses). */
    private function sumberBaris(array $akses, int $userId, int $kunjunganId): string
    {
        if ($akses['sumber'] !== self::SUMBER_PEMBIMBING_MUROBI) {
            return (string) $akses['sumber'];
        }

        return $this->repo->kunjungan($kunjunganId, $this->repo->saringBinaan([KesehatanRepository::BINAAN_PEMBIMBING], $userId)) !== null
            ? self::SUMBER_PEMBIMBING
            : self::SUMBER_MUROBI;
    }

    /** @return array<string, mixed> */
    private function wajibBaca(array $user): array
    {
        $akses = $this->akses($user);
        if (!$akses['baca']) {
            throw new KesehatanException('Catatan kesehatan hanya untuk petugas kesehatan berpenugasan aktif, admin, serta pembimbing/murobi santri terkait.', 403);
        }

        return $akses;
    }

    private function wajibCatat(array $user): void
    {
        $akses = $this->akses($user);
        if ($akses['catat']) {
            return;
        }
        throw new KesehatanException(match ($akses['cakupan']) {
            self::CAKUPAN_SELURUH => 'Admin hanya membaca catatan kesehatan dan tidak mengubah isi klinis.',
            self::CAKUPAN_BINAAN => 'Pembimbing dan murobi hanya membaca catatan kesehatan santri binaan.',
            default => 'Pencatatan kunjungan hanya untuk pengurus dengan penugasan kesehatan aktif.',
        }, 403);
    }

    // =======================================================================
    // Normalisasi dan validasi
    // =======================================================================

    /**
     * @param array<string, mixed> $input
     * @return array{idempotency_key:string, santri_id:int, waktu_kunjungan:string, status:string,
     *     keluhan:string, catatan_klinis:?string, tindakan:?string}
     */
    private function normalisasi(array $input): array
    {
        $kunci = $this->kunci($input['idempotency_key'] ?? null);
        $santri = $input['santri_id'] ?? null;
        if (!is_scalar($santri) || !preg_match('/^[1-9][0-9]{0,9}$/D', (string) $santri) || (int) $santri > 2147483647) {
            throw new KesehatanException('Santri wajib dipilih.');
        }

        return [
            'idempotency_key' => $kunci,
            'santri_id' => (int) $santri,
            'waktu_kunjungan' => $this->waktu($input['waktu_kunjungan'] ?? null),
            'status' => $this->status($input['status'] ?? null),
            'keluhan' => $this->teksWajib($input['keluhan'] ?? null, self::BATAS_KELUHAN, 'Keluhan'),
            'catatan_klinis' => $this->teksOpsional($input['catatan_klinis'] ?? null, self::BATAS_CATATAN, 'Catatan klinis'),
            'tindakan' => $this->teksOpsional($input['tindakan'] ?? null, self::BATAS_TINDAKAN, 'Tindakan'),
        ];
    }

    /**
     * Obat, rujukan, dan tindak lanjut dari masukan web (`obat[0][nama_obat]`)
     * maupun API (larik objek). Baris kosong diabaikan; baris lama yang
     * ditandai `hapus` tidak dikirim (= dibatalkan pada versi baru).
     *
     * @param array<string, mixed> $input
     * @return array{obat:array<int, array<string, mixed>>, rujukan:array<int, array<string, mixed>>, tindak_lanjut:array<int, array<string, mixed>>}
     */
    private function normalisasiRincian(array $input, string $waktuKunjungan, bool $bolehId): array
    {
        $tanggalKunjungan = substr($waktuKunjungan, 0, 10);
        $hasil = $this->rincianKosong();
        foreach (self::BAGIAN_RINCIAN as $bagian) {
            $baris = $input[$bagian] ?? [];
            if ($baris === '' || $baris === null) {
                $baris = [];
            }
            if (!is_array($baris)) {
                throw new KesehatanException('Isian ' . strtolower($this->labelBagian($bagian)) . ' tidak valid.');
            }
            $n = 0;
            foreach ($baris as $r) {
                $n++;
                if (!is_array($r)) {
                    throw new KesehatanException($this->labelBagian($bagian) . ' ke-' . $n . ' tidak valid.');
                }
                $id = $this->idOpsional($r['id'] ?? null);
                if ($id > 0 && !$bolehId) {
                    throw new KesehatanException($this->labelBagian($bagian) . ' baru tidak boleh membawa id.');
                }
                if ($id > 0 && $this->benar($r['hapus'] ?? null)) {
                    continue;
                }
                $kosong = match ($bagian) {
                    'obat' => $this->kosong($r, ['nama_obat', 'jumlah', 'satuan', 'petunjuk']),
                    'rujukan' => $this->kosong($r, ['tujuan', 'alasan', 'hasil']),
                    default => $this->kosong($r, ['uraian']),
                };
                if ($kosong && $id === 0) {
                    continue;
                }
                $hasil[$bagian][] = match ($bagian) {
                    'obat' => $this->obat($r, $id, $waktuKunjungan, $n),
                    'rujukan' => $this->rujukan($r, $id, $tanggalKunjungan, $n),
                    default => $this->tindakLanjut($r, $id, $tanggalKunjungan, $n),
                };
            }
        }
        foreach (['obat' => self::BATAS_OBAT, 'rujukan' => self::BATAS_RUJUKAN, 'tindak_lanjut' => self::BATAS_TINDAK_LANJUT] as $bagian => $batas) {
            if (count($hasil[$bagian]) > $batas) {
                throw new KesehatanException($this->labelBagian($bagian) . ' paling banyak ' . $batas . ' baris per kunjungan.');
            }
        }

        return $hasil;
    }

    /** @return array<string, mixed> */
    private function obat(array $r, int $id, string $waktuKunjungan, int $n): array
    {
        $label = 'Obat ke-' . $n;
        $waktu = $r['waktu_pemberian'] ?? null;

        return [
            'id' => $id > 0 ? $id : null,
            'nama_obat' => $this->teksWajib($r['nama_obat'] ?? null, 200, $label . ': nama obat'),
            'jumlah' => $this->jumlah($r['jumlah'] ?? null, $n),
            'satuan' => $this->teksOpsional($r['satuan'] ?? null, 40, $label . ': satuan'),
            'petunjuk' => $this->teksOpsional($r['petunjuk'] ?? null, 500, $label . ': petunjuk'),
            'waktu_pemberian' => $waktu === null || $waktu === '' ? $waktuKunjungan : $this->waktu($waktu, $label . ': waktu pemberian'),
        ];
    }

    /** @return array<string, mixed> */
    private function rujukan(array $r, int $id, string $tanggalKunjungan, int $n): array
    {
        $label = 'Rujukan ke-' . $n;
        $hasil = $this->teksOpsional($r['hasil'] ?? null, 4000, $label . ': hasil');
        $tanggalHasil = $this->tanggal($r['tanggal_hasil'] ?? null, $label . ': tanggal hasil');
        $rujukan = [
            'id' => $id > 0 ? $id : null,
            'tujuan' => $this->teksWajib($r['tujuan'] ?? null, 200, $label . ': tujuan'),
            'tanggal_rujukan' => $this->tanggal($r['tanggal_rujukan'] ?? null, $label . ': tanggal rujukan') ?? $tanggalKunjungan,
            'alasan' => $this->teksOpsional($r['alasan'] ?? null, 2000, $label . ': alasan'),
            'status' => $hasil === null ? self::RUJUKAN_MENUNGGU : self::RUJUKAN_DIKETAHUI,
            'hasil' => $hasil,
            'tanggal_hasil' => $hasil === null ? $tanggalHasil : ($tanggalHasil ?? date('Y-m-d')),
        ];
        $this->validasiRujukan($rujukan, $tanggalKunjungan, $n);

        return $rujukan;
    }

    /** @param array<string, mixed> $r */
    private function validasiRujukan(array $r, string $tanggalKunjungan, int $n): void
    {
        $label = 'Rujukan ke-' . $n;
        if ($r['tanggal_rujukan'] < $tanggalKunjungan || $r['tanggal_rujukan'] > date('Y-m-d', strtotime('+30 days'))) {
            throw new KesehatanException($label . ': tanggal rujukan tidak boleh sebelum kunjungan atau lebih dari 30 hari ke depan.');
        }
        if ($r['hasil'] === null && $r['tanggal_hasil'] !== null) {
            throw new KesehatanException($label . ': tanggal hasil hanya diisi bersama hasil rujukan.');
        }
        if ($r['hasil'] !== null && ($r['tanggal_hasil'] < $r['tanggal_rujukan'] || $r['tanggal_hasil'] > date('Y-m-d'))) {
            throw new KesehatanException($label . ': tanggal hasil tidak boleh sebelum tanggal rujukan atau di masa depan.');
        }
    }

    /** @return array<string, mixed> */
    private function tindakLanjut(array $r, int $id, string $tanggalKunjungan, int $n): array
    {
        $label = 'Tindak lanjut ke-' . $n;
        $status = $r['status'] ?? (($this->benar($r['selesai'] ?? null)) ? self::TINDAK_LANJUT_SELESAI : self::TINDAK_LANJUT_TERBUKA);
        if ($status === '' || $status === null) {
            $status = self::TINDAK_LANJUT_TERBUKA;
        }
        if (!in_array($status, [self::TINDAK_LANJUT_TERBUKA, self::TINDAK_LANJUT_SELESAI], true)) {
            throw new KesehatanException($label . ': status tidak valid.');
        }
        $target = $this->tanggal($r['tanggal_target'] ?? null, $label . ': tanggal target');
        $selesai = $this->tanggal($r['tanggal_selesai'] ?? null, $label . ': tanggal selesai');
        if ($status === self::TINDAK_LANJUT_SELESAI) {
            $selesai ??= date('Y-m-d');
        } elseif ($selesai !== null) {
            throw new KesehatanException($label . ': tanggal selesai hanya untuk tindak lanjut yang selesai.');
        }
        if ($target !== null && $target < $tanggalKunjungan) {
            throw new KesehatanException($label . ': tanggal target tidak boleh sebelum kunjungan.');
        }
        if ($selesai !== null && ($selesai < $tanggalKunjungan || $selesai > date('Y-m-d'))) {
            throw new KesehatanException($label . ': tanggal selesai tidak boleh sebelum kunjungan atau di masa depan.');
        }

        return [
            'id' => $id > 0 ? $id : null,
            'uraian' => $this->teksWajib($r['uraian'] ?? null, 1000, $label . ': uraian'),
            'tanggal_target' => $target,
            'status' => $status,
            'tanggal_selesai' => $selesai,
        ];
    }

    private function kunci(mixed $kunci): string
    {
        if (!is_string($kunci) || !preg_match('/^[A-Za-z0-9._:-]{8,100}$/D', $kunci)) {
            throw new KesehatanException('Kunci idempotensi wajib 8–100 karakter aman (huruf, angka, . _ : -).');
        }

        return $kunci;
    }

    private function versiDiminta(mixed $value): int
    {
        if (!is_scalar($value) || !preg_match('/^[1-9][0-9]{0,8}$/D', (string) $value)) {
            throw new KesehatanException('Versi catatan yang dikoreksi wajib disertakan.');
        }

        return (int) $value;
    }

    private function status(mixed $value): string
    {
        if ($value === null || $value === '') {
            return self::STATUS_TERCATAT;
        }
        if (!in_array($value, [self::STATUS_DRAF, self::STATUS_TERCATAT], true)) {
            throw new KesehatanException('Status kunjungan tidak valid.');
        }

        return $value;
    }

    private function waktu(mixed $value, string $label = 'Waktu kunjungan'): string
    {
        if (!is_string($value) || trim($value) === '') {
            throw new KesehatanException($label . ' wajib diisi.');
        }
        $raw = str_replace('T', ' ', trim($value));
        $format = strlen($raw) === 16 ? 'Y-m-d H:i' : 'Y-m-d H:i:s';
        $date = DateTimeImmutable::createFromFormat('!' . $format, $raw);
        $errors = DateTimeImmutable::getLastErrors();
        if (!$date || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) || $date->format($format) !== $raw) {
            throw new KesehatanException($label . ' tidak valid.');
        }
        if ($date->getTimestamp() > time() + 300) {
            throw new KesehatanException($label . ' tidak boleh di masa depan.');
        }
        if ($date->format('Y') < '2000') {
            throw new KesehatanException($label . ' tidak valid.');
        }

        return $date->format('Y-m-d H:i:s');
    }

    private function tanggal(mixed $value, string $label): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $date = is_string($value) ? DateTimeImmutable::createFromFormat('!Y-m-d', trim($value)) : false;
        if (!$date || $date->format('Y-m-d') !== trim((string) $value) || $date->format('Y') < '2000') {
            throw new KesehatanException($label . ' tidak valid.');
        }

        return $date->format('Y-m-d');
    }

    /** Tanggal penyaring daftar: tidak valid diabaikan, bukan galat. */
    private function tanggalSaring(mixed $value): string
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)) {
            return '';
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date && $date->format('Y-m-d') === $value ? $value : '';
    }

    /**
     * Jumlah obat: desimal positif, koma atau titik, maksimal dua angka di
     * belakang koma. Bentuk keluaran tanpa nol berlebih ("0.5", "2").
     */
    private function jumlah(mixed $value, int $n): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $raw = str_replace(',', '.', trim((string) (is_scalar($value) ? $value : '')));
        if (!preg_match('/^\d{1,6}(\.\d{1,2})?$/D', $raw) || (float) $raw <= 0) {
            throw new KesehatanException('Obat ke-' . $n . ': jumlah wajib angka positif (maksimal 2 angka desimal).');
        }
        return rtrim(rtrim(number_format((float) $raw, 2, '.', ''), '0'), '.');
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

    /** @param array<int, string> $kolom */
    private function kosong(array $r, array $kolom): bool
    {
        foreach ($kolom as $k) {
            if (is_string($r[$k] ?? null) ? trim($r[$k]) !== '' : ($r[$k] ?? null) !== null) {
                return false;
            }
        }

        return true;
    }

    private function benar(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 'on';
    }

    private function labelBagian(string $bagian): string
    {
        return match ($bagian) {
            'obat' => 'Obat',
            'rujukan' => 'Rujukan',
            default => 'Tindak lanjut',
        };
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
