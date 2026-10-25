<?php

declare(strict_types=1);

namespace App\Bsa;

use App\Audit\AuditLogger;
use App\Auth\Capabilities;
use App\Database\PageQuery;
use App\Penugasan\PenugasanJenis;
use DateTimeImmutable;

/**
 * Layanan keuangan santri/BSA — PRD V6 Fase 1 (fondasi BSA dan transaksi
 * minimum). Dipakai halaman web `portal/bsa*.php` dan API `/api/v1/v6/bsa/*`
 * sehingga keputusan akses keduanya identik.
 *
 * Hak dihitung ulang dari basis data pada SETIAP panggilan publik
 * (`Capabilities::forget()` lebih dahulu), tidak dari sesi/token/menu:
 *
 *   - petugas : capability `bsa.tabungan` bersumber penugasan BSA milik akun
 *               (akun aktif + peran pengurus + pengurus aktif + penugasan
 *               `bendahara_bulanan` aktif) → baca + mutasi, HANYA santri aktif
 *               dalam cakupan unit/jenjang penugasannya;
 *   - admin   : capability yang sama bersumber pengawasan admin → HANYA baca
 *               seluruh santri. Admin tidak pernah memutasi uang (PRD V6 §2);
 *               akun admin yang juga memegang penugasan BSA tetap diperlakukan
 *               sebagai admin;
 *   - wali    : akun orang tua aktif → HANYA baca anak dengan relasi
 *               `santri_wali` aktif;
 *   - lainnya : ditolak 403.
 * Santri di luar hak pembaca dijawab 404 (tidak membedakan "tidak ada" dari
 * "bukan hak Anda"), sehingga ID yang ditebak tidak membocorkan data.
 *
 * Mutasi memeriksa ulang penugasan dan cakupan DI DALAM transaksi dengan
 * urutan kunci tetap (lihat `BsaRepository`): penugasan yang diakhiri saat
 * sesi petugas masih terbuka menolak mutasi berikutnya, dan dua penarikan
 * bersamaan atas santri yang sama dijalankan berurutan sehingga saldo tidak
 * pernah negatif. Nominal rupiah bulat (int), tanpa float. Idempotensi per
 * akun: kunci sama + isi sama → hasil yang sama; kunci sama + isi berbeda
 * (termasuk nominal atau operasi lain) → 409 tanpa perubahan data.
 *
 * Audit: `audit_logs` hanya menerima ID, jenis, metode, status, periode, dan
 * kanal (`AuditLogger` menyaring awalan `bsa.`); aktor, waktu, nominal,
 * tujuan, dan referensi tersimpan di baris ledger/tagihan itu sendiri.
 */
final class BsaService
{
    public const SUMBER_PETUGAS = 'petugas';
    public const SUMBER_ADMIN = 'admin';
    public const SUMBER_ADMIN_PETUGAS = 'admin_petugas';
    public const SUMBER_WALI = 'wali';

    public const KANAL_WEB = 'web';
    public const KANAL_API = 'api';

    public const OP_SETORAN = 'tabungan.setoran';
    public const OP_PENARIKAN = 'tabungan.penarikan';
    public const OP_TAGIHAN = 'tagihan.terbit';

    /** Batas satu nominal (Rp1 miliar) — mencegah salah ketik berlipat, bukan aturan bisnis. */
    public const NOMINAL_MAKS = 1_000_000_000;
    public const KOMPONEN_MAKS = 10;

    public function __construct(
        private BsaRepository $repo,
        private Capabilities $capabilities,
        private AuditLogger $audit
    ) {
    }

    /**
     * Hak akun saat ini.
     *
     * @param array<string, mixed> $user
     * @return array{baca:bool, kelola:bool, sumber:?string, jenjang:?array<int, string>}
     *   `jenjang` hanya untuk petugas: null = seluruh unit, daftar = unit cakupan.
     */
    public function akses(array $user): array
    {
        $this->siap();
        $userId = (int) ($user['id'] ?? 0);
        $tanpa = ['baca' => false, 'kelola' => false, 'sumber' => null, 'jenjang' => null];
        if ($userId < 1) {
            return $tanpa;
        }
        $this->capabilities->forget($userId);
        $sumber = match ($this->capabilities->featureSource($user, PenugasanJenis::CAP_BSA_TABUNGAN)) {
            Capabilities::SUMBER_PENUGASAN => self::SUMBER_PETUGAS,
            Capabilities::SUMBER_ADMIN => self::SUMBER_ADMIN,
            Capabilities::SUMBER_KEDUANYA => self::SUMBER_ADMIN_PETUGAS,
            default => null,
        };
        if ($sumber === self::SUMBER_PETUGAS) {
            $penugasan = $this->repo->penugasanAktif($userId);
            if ($penugasan === []) {
                return $tanpa;
            }

            return ['baca' => true, 'kelola' => true, 'sumber' => $sumber, 'jenjang' => $this->cakupan($penugasan)];
        }
        if ($sumber !== null) {
            return ['baca' => true, 'kelola' => false, 'sumber' => $sumber, 'jenjang' => null];
        }
        if ($this->repo->waliAktif($userId)) {
            return ['baca' => true, 'kelola' => false, 'sumber' => self::SUMBER_WALI, 'jenjang' => null];
        }

        return $tanpa;
    }

    /**
     * Daftar santri yang boleh dibaca akun beserta saldo dan sisa tagihan.
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $input q, page
     * @return array{rows:array<int, array<string, mixed>>, total:int, page:int, per_page:int, akses:array<string, mixed>}
     */
    public function daftarSantri(array $user, array $input): array
    {
        $akses = $this->wajibBaca($user);
        $mode = match ($akses['sumber']) {
            self::SUMBER_PETUGAS => 'petugas',
            self::SUMBER_WALI => 'wali',
            default => 'admin',
        };
        $hasil = $this->repo->daftarSantri(
            ['mode' => $mode, 'user_id' => (int) $user['id'], 'jenjang' => $akses['jenjang']],
            PageQuery::term($input['q'] ?? ''),
            $this->halaman($input['page'] ?? 1)
        );

        return [
            'rows' => array_map(static fn (array $r): array => [
                'id' => (int) $r['id'],
                'nis' => (string) $r['nis'],
                'nama' => (string) $r['nama_santri'],
                'kelas' => $r['nama_kelas'] === null ? null : (string) $r['nama_kelas'],
                'unit' => $r['jenjang'] === null ? null : (string) $r['jenjang'],
                'saldo' => (int) $r['saldo'],
                'tagihan_terbuka' => (int) $r['tagihan_terbuka'],
                'sisa_tagihan' => (int) $r['sisa_tagihan'],
            ], $hasil['rows']),
            'total' => (int) $hasil['total'],
            'page' => (int) $hasil['page'],
            'per_page' => (int) $hasil['perPage'],
            'akses' => $akses,
        ];
    }

    /**
     * Rincian keuangan satu santri: saldo, tagihan, dan riwayat tabungan.
     *
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function santri(array $user, int $santriId, mixed $page = 1): array
    {
        $akses = $this->wajibBaca($user);
        $s = $this->santriTerbaca($user, $akses, $santriId);
        $riwayat = $this->repo->riwayatTabungan($santriId, $this->halaman($page));
        $wali = $akses['sumber'] === self::SUMBER_WALI;

        return [
            'santri' => $this->identitas($s),
            'saldo' => (int) $s['saldo'],
            'tagihan' => array_map(fn (array $t): array => $this->ringkasTagihan($t), $this->repo->tagihanSantri($santriId)),
            'tabungan' => [
                'rows' => array_map(fn (array $x): array => $this->ringkasTransaksi($x, $wali), $riwayat['rows']),
                'total' => (int) $riwayat['total'],
                'page' => (int) $riwayat['page'],
                'per_page' => (int) $riwayat['perPage'],
            ],
            'akses' => $akses,
        ];
    }

    /**
     * Saldo efektif satu santri.
     *
     * @param array<string, mixed> $user
     * @return array{santri_id:int, saldo:int}
     */
    public function saldo(array $user, int $santriId): array
    {
        $akses = $this->wajibBaca($user);
        $s = $this->santriTerbaca($user, $akses, $santriId);

        return ['santri_id' => (int) $s['id'], 'saldo' => (int) $s['saldo']];
    }

    /**
     * Rincian satu tagihan beserta snapshot komponennya.
     *
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function tagihan(array $user, int $id): array
    {
        $akses = $this->wajibBaca($user);
        $t = $id > 0 ? $this->repo->tagihan($id) : null;
        if ($t === null) {
            throw new BsaException('Tagihan tidak ditemukan.', 404);
        }
        try {
            $s = $this->santriTerbaca($user, $akses, (int) $t['santri_id']);
        } catch (BsaException) {
            throw new BsaException('Tagihan tidak ditemukan.', 404);
        }

        return $this->ringkasTagihan($t) + [
            'santri' => $this->identitas($s),
            'unit_saat_terbit' => $t['jenjang'] === null ? null : (string) $t['jenjang'],
            'catatan' => $t['catatan'] === null ? null : (string) $t['catatan'],
            'penerbit' => ['nama' => (string) $t['penerbit_nama']],
            'komponen' => array_map(static fn (array $k): array => [
                'urutan' => (int) $k['urutan'], 'nama' => (string) $k['nama'], 'nominal' => (int) $k['nominal'],
            ], $this->repo->komponen($id)),
            'akses' => $akses,
        ];
    }

    /**
     * Mencatat setoran tabungan (sah seketika; petugas pencatat menyetujui).
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $input idempotency_key, nominal, metode, referensi, catatan
     * @return array{id:int, status:int, replayed:bool, saldo_sesudah:int}
     */
    public function setoran(array $user, int $santriId, array $input, string $kanal): array
    {
        return $this->mutasiTabungan($user, $santriId, $input, $kanal, self::OP_SETORAN);
    }

    /**
     * Mencatat penarikan/belanja dari tabungan. Ditolak tanpa mutasi bila
     * nominal melebihi saldo efektif.
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $input idempotency_key, nominal, metode, tujuan, referensi, catatan
     * @return array{id:int, status:int, replayed:bool, saldo_sesudah:int}
     */
    public function penarikan(array $user, int $santriId, array $input, string $kanal): array
    {
        return $this->mutasiTabungan($user, $santriId, $input, $kanal, self::OP_PENARIKAN);
    }

    /**
     * Menerbitkan satu tagihan bulanan untuk satu santri dan satu periode.
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $input idempotency_key, santri_id, periode, jatuh_tempo, komponen[], catatan
     * @return array{id:int, status:int, replayed:bool}
     */
    public function terbitkanTagihan(array $user, array $input, string $kanal): array
    {
        $this->wajibKelola($user);
        $userId = (int) $user['id'];
        $data = $this->normalisasiTagihan($input);
        $hash = $this->hash([self::OP_TAGIHAN, $data['santri_id'], $data['periode'], $data['jatuh_tempo'], $data['komponen'], $data['catatan']]);

        return $this->repo->transaction(function () use ($userId, $data, $hash, $kanal): array {
            $penugasan = $this->penugasanTerkunci($userId);
            $klaim = $this->klaim($userId, $data['idempotency_key'], self::OP_TAGIHAN, $hash);
            // Replay tetap permintaan atas santri: cakupan dan status terkini
            // wajib sah sebelum hasil lama boleh dikembalikan.
            [$santri, $tugas] = $this->santriDalamCakupan($data['santri_id'], $penugasan);
            if ($klaim['hasil_id'] !== null) {
                return ['id' => $klaim['hasil_id'], 'status' => 200, 'replayed' => true];
            }
            $id = $this->repo->simpanTagihan($data, $santri['jenjang'], $userId, $tugas['pengurus_id'], $tugas['id']);
            $this->repo->selesaikanIdempotensi($klaim['id'], $id, 201);
            $this->wajibAudit('bsa.tagihan.terbit', 'bsa_tagihan', $id, [
                'tagihan_id' => $id, 'santri_id' => $data['santri_id'], 'periode' => $data['periode'], 'status' => 'Terbit', 'versi' => 1,
                'jumlah_komponen' => count($data['komponen']), 'penugasan_id' => $tugas['id'], 'sumber' => self::SUMBER_PETUGAS, 'kanal' => $this->kanal($kanal),
            ], $userId);

            return ['id' => $id, 'status' => 201, 'replayed' => false];
        });
    }

    // =======================================================================

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $input
     * @return array{id:int, status:int, replayed:bool, saldo_sesudah:int}
     */
    private function mutasiTabungan(array $user, int $santriId, array $input, string $kanal, string $operasi): array
    {
        $this->wajibKelola($user);
        $userId = (int) $user['id'];
        $keluar = $operasi === self::OP_PENARIKAN;
        $data = $this->normalisasiTabungan($input, $keluar);
        $hash = $this->hash([$operasi, $santriId, $data['nominal'], $data['metode'], $data['tujuan'], $data['referensi'], $data['catatan']]);

        // Pra-periksa di luar transaksi hanya untuk menyiapkan akun bersaldo nol
        // pada setoran pertama; keputusan sah tetap diambil ulang di dalam
        // transaksi. Santri di luar hak dijawab 404 tanpa membuat apa pun.
        if (!$keluar && $this->santriBolehDimutasi($userId, $santriId)) {
            $this->repo->pastikanAkun($santriId);
        }

        return $this->repo->transaction(function () use ($userId, $santriId, $data, $hash, $kanal, $operasi, $keluar): array {
            $penugasan = $this->penugasanTerkunci($userId);
            $klaim = $this->klaim($userId, $data['idempotency_key'], $operasi, $hash);
            [, $tugas] = $this->santriDalamCakupan($santriId, $penugasan);
            if ($klaim['hasil_id'] !== null) {
                $lama = $this->repo->transaksi($klaim['hasil_id']);

                return ['id' => $klaim['hasil_id'], 'status' => 200, 'replayed' => true, 'saldo_sesudah' => (int) ($lama['saldo_sesudah'] ?? 0)];
            }
            $akun = $this->repo->kunciAkun($santriId);
            $saldoLama = $akun['saldo'] ?? 0;
            if ($keluar && ($akun === null || $data['nominal'] > $saldoLama)) {
                throw new BsaException('Saldo tabungan tidak mencukupi. Penarikan tidak dicatat.', 422);
            }
            if ($akun === null) {
                throw new BsaException('Akun tabungan sedang disiapkan. Tidak ada data yang diubah; coba lagi.', 409);
            }
            $saldoBaru = $keluar ? $saldoLama - $data['nominal'] : $saldoLama + $data['nominal'];
            $data['jenis'] = $keluar ? 'Penarikan' : 'Setoran';
            $data['arah'] = $keluar ? 'Keluar' : 'Masuk';
            $id = $this->repo->catatTransaksi($santriId, $data, $saldoLama, $saldoBaru, $userId, $tugas['pengurus_id'], $tugas['id']);
            $this->repo->selesaikanIdempotensi($klaim['id'], $id, 201);
            $this->wajibAudit($keluar ? 'bsa.tabungan.penarikan' : 'bsa.tabungan.setoran', 'bsa_tabungan_transaksi', $id, [
                'transaksi_id' => $id, 'santri_id' => $santriId, 'jenis' => $data['jenis'], 'metode' => $data['metode'], 'status' => 'Sah',
                'penugasan_id' => $tugas['id'], 'sumber' => self::SUMBER_PETUGAS, 'kanal' => $this->kanal($kanal),
            ], $userId);

            return ['id' => $id, 'status' => 201, 'replayed' => false, 'saldo_sesudah' => $saldoBaru];
        });
    }

    private function siap(): void
    {
        if (!$this->repo->skemaSiap()) {
            throw new BsaException('Modul keuangan santri (BSA) belum dipasang pada basis data ini.', 503);
        }
    }

    /** @return array{baca:bool, kelola:bool, sumber:?string, jenjang:?array<int, string>} */
    private function wajibBaca(array $user): array
    {
        $akses = $this->akses($user);
        if (!$akses['baca']) {
            throw new BsaException('Keuangan santri hanya untuk petugas BSA berpenugasan aktif, admin, dan orang tua atas anaknya.', 403);
        }

        return $akses;
    }

    private function wajibKelola(array $user): void
    {
        $akses = $this->akses($user);
        if ($akses['kelola']) {
            return;
        }
        throw new BsaException(match ($akses['sumber']) {
            self::SUMBER_ADMIN, self::SUMBER_ADMIN_PETUGAS => 'Admin hanya membaca dan mengaudit keuangan santri, tidak mencatat transaksi.',
            self::SUMBER_WALI => 'Orang tua hanya melihat keuangan anaknya, tidak mencatat transaksi.',
            default => 'Pencatatan keuangan santri hanya untuk pengurus dengan penugasan BSA aktif.',
        }, 403);
    }

    /**
     * Santri yang boleh dibaca akun, atau 404.
     *
     * @param array{sumber:?string, jenjang:?array<int, string>} $akses
     * @return array<string, mixed>
     */
    private function santriTerbaca(array $user, array $akses, int $santriId): array
    {
        $s = $santriId > 0 ? $this->repo->santri($santriId) : null;
        $boleh = $s !== null && match ($akses['sumber']) {
            self::SUMBER_ADMIN, self::SUMBER_ADMIN_PETUGAS => true,
            self::SUMBER_WALI => $this->repo->anakWali((int) $user['id'], $santriId),
            self::SUMBER_PETUGAS => (int) $s['is_active'] === 1 && $s['archived_at'] === null && $this->unitTercakup($s['jenjang'], $akses['jenjang']),
            default => false,
        };
        if (!$boleh) {
            throw new BsaException('Santri tidak ditemukan.', 404);
        }

        return $s;
    }

    /** Pra-periksa tanpa kunci (di luar transaksi): santri aktif dalam cakupan penugasan akun. */
    private function santriBolehDimutasi(int $userId, int $santriId): bool
    {
        $penugasan = $this->repo->penugasanAktif($userId);
        $s = $santriId > 0 && $penugasan !== [] ? $this->repo->santri($santriId) : null;

        return $s !== null && (int) $s['is_active'] === 1 && $s['archived_at'] === null && $this->unitTercakup($s['jenjang'], $this->cakupan($penugasan));
    }

    /**
     * Penugasan BSA aktif, dikunci baca di dalam transaksi.
     *
     * @return array<int, array{id:int, pengurus_id:int, jenjang:?string}>
     */
    private function penugasanTerkunci(int $userId): array
    {
        $penugasan = $this->repo->penugasanAktif($userId, true);
        if ($penugasan === []) {
            throw new BsaException('Penugasan BSA Anda sudah tidak aktif. Transaksi tidak dicatat.', 403);
        }

        return $penugasan;
    }

    /**
     * Santri aktif (dikunci baca) yang termasuk cakupan salah satu penugasan.
     * Penugasan seluruh unit diutamakan; selain itu penugasan yang unitnya
     * sama dengan unit santri.
     *
     * @param array<int, array{id:int, pengurus_id:int, jenjang:?string}> $penugasan
     * @return array{0:array{id:int, jenjang:?string}, 1:array{id:int, pengurus_id:int, jenjang:?string}}
     */
    private function santriDalamCakupan(int $santriId, array $penugasan): array
    {
        $santri = $this->repo->santriTerkunci($santriId);
        if ($santri !== null) {
            foreach ($penugasan as $p) {
                if ($p['jenjang'] === null) {
                    return [$santri, $p];
                }
            }
            foreach ($penugasan as $p) {
                if ($santri['jenjang'] !== null && $this->samaUnit($p['jenjang'], $santri['jenjang'])) {
                    return [$santri, $p];
                }
            }
        }

        throw new BsaException('Santri tidak ditemukan.', 404);
    }

    /**
     * @param array<int, array{jenjang:?string}> $penugasan
     * @return array<int, string>|null null = seluruh unit
     */
    private function cakupan(array $penugasan): ?array
    {
        $unit = [];
        foreach ($penugasan as $p) {
            if ($p['jenjang'] === null) {
                return null;
            }
            $unit[mb_strtolower(trim($p['jenjang']))] = $p['jenjang'];
        }

        return array_values($unit);
    }

    /** @param array<int, string>|null $cakupan */
    private function unitTercakup(mixed $unitSantri, ?array $cakupan): bool
    {
        if ($cakupan === null) {
            return true;
        }
        if ($unitSantri === null || trim((string) $unitSantri) === '') {
            return false;
        }
        foreach ($cakupan as $unit) {
            if ($this->samaUnit($unit, (string) $unitSantri)) {
                return true;
            }
        }

        return false;
    }

    private function samaUnit(?string $a, string $b): bool
    {
        return $a !== null && mb_strtolower(trim($a)) === mb_strtolower(trim($b));
    }

    /** @return array{id:int, operasi:string, request_hash:string, hasil_id:?int} */
    private function klaim(int $userId, string $kunci, string $operasi, string $hash): array
    {
        $klaim = $this->repo->klaimIdempotensi($userId, $kunci, $operasi, $hash);
        if ($klaim['operasi'] !== $operasi || !hash_equals($klaim['request_hash'], $hash)) {
            throw new BsaException('Kunci idempotensi sudah dipakai untuk transaksi lain atau nominal berbeda. Tidak ada data yang diubah.', 409);
        }

        return $klaim;
    }

    /** @param array<string, mixed> $after */
    private function wajibAudit(string $action, string $entitas, int $id, array $after, int $userId): void
    {
        if (!$this->audit->log($action, $entitas, $id, null, $after, $userId)) {
            throw new BsaException('Audit wajib tidak dapat disimpan. Transaksi tidak dicatat.', 503);
        }
    }

    /** @param array<int, mixed> $isi */
    private function hash(array $isi): string
    {
        return hash('sha256', (string) json_encode($isi, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param array<string, mixed> $input
     * @return array{idempotency_key:string, nominal:int, metode:string, tujuan:?string, referensi:?string, catatan:?string}
     */
    private function normalisasiTabungan(array $input, bool $keluar): array
    {
        $metode = $input['metode'] ?? null;
        if (!in_array($metode, ['Tunai', 'Transfer'], true)) {
            throw new BsaException('Cara ' . ($keluar ? 'pengeluaran' : 'penerimaan') . ' wajib Tunai atau Transfer.');
        }
        $referensi = $this->teks($input['referensi'] ?? null, 100, 'Referensi');
        if ($metode === 'Transfer' && $referensi === null) {
            throw new BsaException('Referensi transfer wajib diisi agar dapat diperiksa petugas.');
        }
        $tujuan = $this->teks($input['tujuan'] ?? null, 255, 'Tujuan');
        if ($keluar && ($tujuan === null || mb_strlen($tujuan) < 3)) {
            throw new BsaException('Tujuan penarikan/belanja wajib diisi (minimal 3 karakter).');
        }

        return [
            'idempotency_key' => $this->kunci($input['idempotency_key'] ?? null),
            'nominal' => $this->nominal($input['nominal'] ?? null, 'Nominal'),
            'metode' => (string) $metode,
            'tujuan' => $keluar ? $tujuan : null,
            'referensi' => $referensi,
            'catatan' => $this->teks($input['catatan'] ?? null, 500, 'Catatan'),
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{idempotency_key:string, santri_id:int, periode:string, jatuh_tempo:string,
     *     komponen:array<int, array{nama:string, nominal:int}>, total:int, catatan:?string}
     */
    private function normalisasiTagihan(array $input): array
    {
        $periode = $input['periode'] ?? null;
        if (!is_string($periode) || !preg_match('/^(20[0-9]{2})-(0[1-9]|1[0-2])$/D', $periode)) {
            throw new BsaException('Periode wajib bulan kalender (format TTTT-BB).');
        }
        $jatuhTempo = $this->tanggal($input['jatuh_tempo'] ?? null, 'Jatuh tempo');
        if ($jatuhTempo < $periode . '-01') {
            throw new BsaException('Jatuh tempo tidak boleh sebelum awal periode tagihan.');
        }
        $mentah = $input['komponen'] ?? null;
        if (!is_array($mentah)) {
            throw new BsaException('Komponen tagihan wajib diisi.');
        }
        $komponen = [];
        $total = 0;
        foreach (array_values($mentah) as $i => $k) {
            if (!is_array($k)) {
                throw new BsaException('Komponen tagihan tidak valid.');
            }
            $nama = $this->teks($k['nama'] ?? null, 100, 'Nama komponen');
            $kosongNominal = ($k['nominal'] ?? '') === '' || ($k['nominal'] ?? null) === null;
            if ($nama === null && $kosongNominal) {
                continue; // baris formulir yang dibiarkan kosong
            }
            if ($nama === null) {
                throw new BsaException('Nama komponen ke-' . ($i + 1) . ' wajib diisi.');
            }
            $nominal = $this->nominal($k['nominal'] ?? null, 'Nominal komponen ke-' . ($i + 1));
            $komponen[] = ['nama' => $nama, 'nominal' => $nominal];
            $total += $nominal;
        }
        if ($komponen === []) {
            throw new BsaException('Tagihan wajib memuat paling sedikit satu komponen biaya.');
        }
        if (count($komponen) > self::KOMPONEN_MAKS) {
            throw new BsaException('Tagihan paling banyak memuat ' . self::KOMPONEN_MAKS . ' komponen.');
        }
        if ($total > self::NOMINAL_MAKS) {
            throw new BsaException('Total tagihan melebihi batas Rp1.000.000.000.');
        }

        return [
            'idempotency_key' => $this->kunci($input['idempotency_key'] ?? null),
            'santri_id' => $this->id($input['santri_id'] ?? null),
            'periode' => $periode,
            'jatuh_tempo' => $jatuhTempo,
            'komponen' => $komponen,
            'total' => $total,
            'catatan' => $this->teks($input['catatan'] ?? null, 500, 'Catatan'),
        ];
    }

    private function kunci(mixed $kunci): string
    {
        if (!is_string($kunci) || !preg_match('/^[A-Za-z0-9._:-]{8,100}$/D', $kunci)) {
            throw new BsaException('Kunci idempotensi wajib 8–100 karakter aman (huruf, angka, . _ : -).');
        }

        return $kunci;
    }

    /**
     * Rupiah bulat positif. Menerima bilangan bulat JSON atau teks angka
     * (titik pemisah ribuan boleh); pecahan, tanda, notasi ilmiah, dan float
     * ditolak — nominal tidak pernah melewati float.
     */
    private function nominal(mixed $value, string $label): int
    {
        $teks = '';
        if (is_int($value)) {
            $teks = (string) $value;
        } elseif (is_string($value) && preg_match('/^(?:[0-9]+|[0-9]{1,3}(?:\.[0-9]{3})+)$/D', trim($value)) === 1) {
            $teks = str_replace('.', '', trim($value));
        }
        if (!preg_match('/^[1-9][0-9]{0,9}$/D', $teks) || (int) $teks > self::NOMINAL_MAKS) {
            throw new BsaException($label . ' wajib rupiah bulat antara 1 dan 1.000.000.000.');
        }

        return (int) $teks;
    }

    private function tanggal(mixed $value, string $label): string
    {
        if (!is_string($value) || trim($value) === '') {
            throw new BsaException($label . ' wajib diisi.');
        }
        $raw = trim($value);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $raw);
        $errors = DateTimeImmutable::getLastErrors();
        if (!$date || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) || $date->format('Y-m-d') !== $raw || $raw < '2000-01-01' || $raw > '2099-12-31') {
            throw new BsaException($label . ' tidak valid.');
        }

        return $raw;
    }

    private function teks(mixed $value, int $maks, string $label): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8')) {
            throw new BsaException($label . ' wajib berupa teks.');
        }
        $teks = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
        if (mb_strlen($teks) > $maks) {
            throw new BsaException($label . ' maksimal ' . $maks . ' karakter.');
        }

        return $teks === '' ? null : $teks;
    }

    private function id(mixed $value): int
    {
        if (!is_scalar($value) || !preg_match('/^[1-9][0-9]{0,9}$/D', (string) $value) || (int) $value > 2147483647) {
            throw new BsaException('Santri wajib dipilih.');
        }

        return (int) $value;
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
     * @param array<string, mixed> $s
     * @return array<string, mixed>
     */
    private function identitas(array $s): array
    {
        return [
            'id' => (int) $s['id'],
            'nis' => (string) $s['nis'],
            'nama' => (string) $s['nama_santri'],
            'kelas' => $s['nama_kelas'] === null ? null : (string) $s['nama_kelas'],
            'unit' => $s['jenjang'] === null ? null : (string) $s['jenjang'],
            'aktif' => (int) $s['is_active'] === 1 && $s['archived_at'] === null,
        ];
    }

    /**
     * Status pembayaran turunan dari total dan pembayaran sah, bukan kolom bebas.
     *
     * @param array<string, mixed> $t
     * @return array<string, mixed>
     */
    private function ringkasTagihan(array $t): array
    {
        $total = (int) $t['total'];
        $terbayar = (int) $t['terbayar'];
        $sisa = $total - $terbayar;

        return [
            'id' => (int) $t['id'],
            'periode' => (string) $t['periode'],
            'jatuh_tempo' => (string) $t['jatuh_tempo'],
            'total' => $total,
            'terbayar' => $terbayar,
            'sisa' => $sisa,
            'status' => (string) $t['status'],
            'status_bayar' => (string) $t['status'] !== 'Terbit' ? (string) $t['status'] : ($sisa === 0 ? 'Lunas' : ($terbayar > 0 ? 'Sebagian' : 'Belum dibayar')),
            'versi' => (int) $t['versi'],
            'diterbitkan_pada' => (string) $t['created_at'],
        ];
    }

    /**
     * Referensi transfer dan catatan internal petugas tidak dikirim kepada
     * orang tua (PRD V6 §5.4); orang tua melihat jenis, nominal, tujuan,
     * saldo sesudah, dan waktu.
     *
     * @param array<string, mixed> $x
     * @return array<string, mixed>
     */
    private function ringkasTransaksi(array $x, bool $wali = false): array
    {
        $baris = [
            'id' => (int) $x['id'],
            'jenis' => (string) $x['jenis'],
            'arah' => (string) $x['arah'],
            'nominal' => (int) $x['nominal'],
            'saldo_sesudah' => $x['saldo_sesudah'] === null ? null : (int) $x['saldo_sesudah'],
            'status' => (string) $x['status'],
            'metode' => (string) $x['metode'],
            'tujuan' => $x['tujuan'] === null ? null : (string) $x['tujuan'],
            'referensi' => $x['referensi'] === null ? null : (string) $x['referensi'],
            'catatan' => $x['catatan'] === null ? null : (string) $x['catatan'],
            'petugas' => ['nama' => (string) $x['petugas_nama']],
            'waktu' => (string) $x['created_at'],
        ];
        if ($wali) {
            unset($baris['referensi'], $baris['catatan']);
        }

        return $baris;
    }
}
