<?php

declare(strict_types=1);

namespace App\Bsa;

use App\Database\PageQuery;

/**
 * Tabungan lengkap — PRD V6 Fase 3. Dipakai halaman web `portal/bsa_*.php`
 * dan API `/api/v1/v6/bsa/*` sehingga keputusan keduanya identik.
 *
 * Hak memakai penjaga `BsaService` yang sama dengan Fase 1–2, dihitung ulang
 * dari basis data setiap panggilan: petugas BSA memutasi santri AKTIF dalam
 * cakupan unitnya; admin (termasuk admin yang juga petugas) dan orang tua
 * hanya membaca; orang tua hanya anak dengan relasi `santri_wali` aktif,
 * tanpa referensi transfer, catatan internal, alasan koreksi, atau setoran
 * yang belum diverifikasi. Di luar hak → 404.
 *
 * Aturan uang (PRD V6 §5.3):
 *   - Saldo efektif = akun cerminan ledger 027 yang HANYA-TAMBAH; setiap baris
 *     ledger sudah sah dan saldo sesudahnya tidak pernah diubah.
 *   - Setoran tunai sah seketika (pencatat = penyetuju). Setoran transfer sah
 *     seketika hanya bila petugas menyatakan sudah memeriksa dana masuk;
 *     selain itu tercatat MENUNGGU di `bsa_setoran_transfer` (029) dan belum
 *     dihitung saldo sampai diverifikasi (ledger disisipkan saat itu) atau
 *     ditolak beralasan.
 *   - Penarikan/belanja: tujuan wajib, ditolak bila melebihi saldo, pencatat
 *     boleh langsung menyetujui (PRD §5.1).
 *   - Koreksi = baris `Pembalik` beralasan atas transaksi asal (kunci unik:
 *     satu pembalik per transaksi), tidak pernah UPDATE/DELETE. Membalik
 *     setoran yang uangnya sudah terpakai ditolak (saldo tidak boleh
 *     negatif). Debit pembayaran tagihan dikoreksi dengan membatalkan
 *     pembayarannya (`TagihanService::batalkanPembayaran`) agar debit dan
 *     pembayaran selalu berpasangan.
 *   - Pembayaran tagihan dari tabungan: `TagihanService::bayarDariTabungan`.
 *   - Rekonsiliasi harian memisahkan kas tunai/transfer yang diverifikasi dari
 *     perpindahan internal tabungan → tagihan, sehingga satu rupiah tidak
 *     dihitung dua kali sebagai penerimaan kas.
 */
final class TabunganService
{
    public const OP_SETORAN_MENUNGGU = 'tabungan.setoran';
    public const OP_VERIFIKASI = 'tabungan.setoran.verifikasi';
    public const OP_TOLAK = 'tabungan.setoran.tolak';
    public const OP_KOREKSI = 'tabungan.koreksi';

    public function __construct(
        private BsaService $bsa,
        private BsaRepository $bsaRepo,
        private TabunganRepository $repo
    ) {
    }

    /** Migrasi 029 terpasang; halaman memakai ini untuk menyembunyikan fitur Fase 3. */
    public function tersedia(): bool
    {
        return $this->bsaRepo->skemaSiap() && $this->repo->skemaSiap();
    }

    // =======================================================================
    // Setoran, penarikan, belanja
    // =======================================================================

    /**
     * Setoran tabungan. Tunai, atau transfer yang dinyatakan sudah diperiksa,
     * sah seketika lewat `BsaService::setoran` (ledger + saldo). Transfer
     * tanpa pernyataan itu dicatat menunggu verifikasi dan tidak mengubah
     * saldo (status HTTP 202).
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $input idempotency_key, nominal, metode, referensi, catatan, terverifikasi
     * @return array<string, mixed>
     */
    public function setoran(array $user, int $santriId, array $input, string $kanal): array
    {
        if (!$this->tersedia()) {
            return $this->bsa->setoran($user, $santriId, $input, $kanal);
        }
        $metode = $input['metode'] ?? null;
        $terverifikasi = in_array($input['terverifikasi'] ?? null, [true, 1, '1', 'ya'], true);
        if ($metode !== 'Transfer' || $terverifikasi) {
            $h = $this->bsa->setoran($user, $santriId, $input, $kanal);

            return $h + ['status_setoran' => 'Sah', 'transaksi_id' => $h['id'], 'setoran_transfer_id' => null, 'nomor_kuitansi' => $this->nomor($h['id'])];
        }

        return $this->setoranMenunggu($user, $santriId, $input, $kanal);
    }

    /**
     * Penarikan atau belanja dari tabungan (`jenis` Penarikan|Belanja).
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $input idempotency_key, jenis, nominal, metode, tujuan, referensi, catatan
     * @return array<string, mixed>
     */
    public function penarikan(array $user, int $santriId, array $input, string $kanal): array
    {
        $jenis = ($input['jenis'] ?? '') === '' || ($input['jenis'] ?? null) === null ? 'Penarikan' : $input['jenis'];
        if (!in_array($jenis, ['Penarikan', 'Belanja'], true)) {
            throw new BsaException('Jenis pengeluaran wajib Penarikan atau Belanja.');
        }
        if (!$this->tersedia()) {
            if ($jenis === 'Belanja') {
                $this->siap();
            }

            return $this->bsa->penarikan($user, $santriId, $input, $kanal);
        }
        $h = $jenis === 'Belanja' ? $this->bsa->belanja($user, $santriId, $input, $kanal) : $this->bsa->penarikan($user, $santriId, $input, $kanal);

        return $h + ['jenis' => $jenis, 'transaksi_id' => $h['id'], 'nomor_kuitansi' => $this->nomor($h['id'])];
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function setoranMenunggu(array $user, int $santriId, array $input, string $kanal): array
    {
        $this->bsa->wajibKelola($user);
        $userId = (int) $user['id'];
        $referensi = $this->bsa->teks($input['referensi'] ?? null, 100, 'Referensi');
        if ($referensi === null) {
            throw new BsaException('Referensi transfer wajib diisi agar dapat diperiksa petugas.');
        }
        $data = [
            'idempotency_key' => $this->bsa->kunci($input['idempotency_key'] ?? null),
            'nominal' => $this->bsa->nominal($input['nominal'] ?? null, 'Nominal'),
            'referensi' => $referensi,
            'catatan' => $this->bsa->teks($input['catatan'] ?? null, 500, 'Catatan'),
        ];
        // Hash berbeda dari setoran sah (penanda 'menunggu'): kunci yang sama
        // tidak dapat dipakai sekali sebagai setoran sah dan sekali menunggu.
        $hash = $this->bsa->hash([BsaService::OP_SETORAN, $santriId, $data['nominal'], 'Transfer', null, $referensi, $data['catatan'], 'menunggu']);

        return $this->bsaRepo->transaction(function () use ($userId, $santriId, $data, $hash, $kanal): array {
            $penugasan = $this->bsa->penugasanTerkunci($userId);
            $klaim = $this->bsa->klaim($userId, $data['idempotency_key'], self::OP_SETORAN_MENUNGGU, $hash);
            [, $tugas] = $this->bsa->santriDalamCakupan($santriId, $penugasan);
            if ($klaim['hasil_id'] !== null) {
                return $this->hasilSetoranTransfer($klaim['hasil_id'], 200, true);
            }
            $id = $this->repo->simpanSetoranTransfer($santriId, $data, $userId, $tugas['pengurus_id'], $tugas['id']);
            $this->bsaRepo->selesaikanIdempotensi($klaim['id'], $id, 202);
            $this->bsa->wajibAudit('bsa.tabungan.setoran', 'bsa_setoran_transfer', $id, [
                'setoran_transfer_id' => $id, 'santri_id' => $santriId, 'jenis' => 'Setoran', 'metode' => 'Transfer', 'status' => 'Menunggu',
                'penugasan_id' => $tugas['id'], 'sumber' => BsaService::SUMBER_PETUGAS, 'kanal' => $this->bsa->kanal($kanal),
            ], $userId);

            return $this->hasilSetoranTransfer($id, 202, false);
        });
    }

    /**
     * Memverifikasi setoran transfer yang menunggu: dana dinyatakan sudah
     * diperiksa masuk; ledger `Setoran/Transfer` sah disisipkan saat ini
     * (pencatat = penerima semula, penyetuju = pemeriksa) dan saldo naik
     * satu kali.
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $input idempotency_key
     * @return array<string, mixed>
     */
    public function verifikasiSetoran(array $user, int $id, array $input, string $kanal): array
    {
        return $this->putusSetoran($user, $id, $input, $kanal, true);
    }

    /**
     * Menolak setoran transfer yang menunggu (dana tidak ditemukan/keliru),
     * beralasan. Saldo tidak berubah.
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $input idempotency_key, alasan
     * @return array<string, mixed>
     */
    public function tolakSetoran(array $user, int $id, array $input, string $kanal): array
    {
        return $this->putusSetoran($user, $id, $input, $kanal, false);
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function putusSetoran(array $user, int $id, array $input, string $kanal, bool $sahkan): array
    {
        $this->siap();
        $this->bsa->wajibKelola($user);
        $userId = (int) $user['id'];
        $alasan = $sahkan ? null : $this->alasan($input['alasan'] ?? null);
        $kunci = $this->bsa->kunci($input['idempotency_key'] ?? null);
        $operasi = $sahkan ? self::OP_VERIFIKASI : self::OP_TOLAK;
        $hash = $this->bsa->hash([$operasi, $id, $alasan]);
        $pemilik = $id > 0 ? $this->repo->setoranTransfer($id) : null;
        if ($pemilik === null) {
            throw new BsaException('Setoran transfer tidak ditemukan.', 404);
        }
        $santriId = (int) $pemilik['santri_id'];
        // Akun bersaldo nol disiapkan di luar transaksi (seperti setoran Fase 1)
        // hanya bila santri terbaca petugas; keputusan sah diambil ulang di dalam.
        if ($sahkan) {
            $this->bsa->santriTerbaca($user, $this->bsa->akses($user), $santriId);
            $this->bsaRepo->pastikanAkun($santriId);
        }

        return $this->bsaRepo->transaction(function () use ($userId, $id, $santriId, $alasan, $kunci, $operasi, $hash, $kanal, $sahkan): array {
            $penugasan = $this->bsa->penugasanTerkunci($userId);
            $klaim = $this->bsa->klaim($userId, $kunci, $operasi, $hash);
            [, $tugas] = $this->bsa->santriDalamCakupan($santriId, $penugasan);
            if ($klaim['hasil_id'] !== null) {
                return $this->hasilSetoranTransfer($klaim['hasil_id'], 200, true);
            }
            $p = $this->repo->setoranTransfer($id, true);
            if ($p === null || $p['santri_id'] !== $santriId) {
                throw new BsaException('Setoran transfer tidak ditemukan.', 404);
            }
            if ($p['status'] !== 'Menunggu') {
                throw new BsaException($p['status'] === 'Sah' ? 'Setoran transfer ini sudah diverifikasi.' : 'Setoran transfer ini sudah ditolak.', 409);
            }
            $transaksiId = null;
            if ($sahkan) {
                $akun = $this->bsaRepo->kunciAkun($santriId);
                if ($akun === null) {
                    throw new BsaException('Akun tabungan sedang disiapkan. Tidak ada data yang diubah; coba lagi.', 409);
                }
                $transaksiId = $this->bsaRepo->catatTransaksi($santriId, [
                    'jenis' => 'Setoran', 'arah' => 'Masuk', 'nominal' => $p['nominal'], 'metode' => 'Transfer', 'tujuan' => null,
                    'referensi' => $p['referensi'], 'catatan' => $p['catatan'],
                    // '#' tidak termasuk karakter kunci idempotensi pengguna, sehingga
                    // tidak pernah bertabrakan dengan kunci yang dikirim klien.
                    'idempotency_key' => 'transfer#' . $id,
                ], $akun['saldo'], $akun['saldo'] + $p['nominal'], $p['dicatat_oleh'], $p['pengurus_id'], $p['penugasan_id'], $userId);
                $this->repo->sahkanSetoranTransfer($id, $transaksiId, $userId);
            } else {
                $this->repo->tolakSetoranTransfer($id, $userId, (string) $alasan);
            }
            $this->bsaRepo->selesaikanIdempotensi($klaim['id'], $id, 200);
            $this->bsa->wajibAudit($sahkan ? 'bsa.tabungan.verifikasi' : 'bsa.tabungan.tolak', 'bsa_setoran_transfer', $id, [
                'setoran_transfer_id' => $id, 'transaksi_id' => $transaksiId, 'santri_id' => $santriId, 'jenis' => 'Setoran', 'metode' => 'Transfer',
                'status' => $sahkan ? 'Sah' : 'Ditolak', 'penugasan_id' => $tugas['id'], 'sumber' => BsaService::SUMBER_PETUGAS, 'kanal' => $this->bsa->kanal($kanal),
            ], $userId);
            // Antrean menunggu/ditolak tidak terlihat orang tua; hanya baris ledger sah diberitahukan.
            if ($transaksiId !== null) {
                $this->bsa->beritahuWali($santriId, BsaNotifikasi::EVENT_TABUNGAN, $transaksiId);
            }

            return $this->hasilSetoranTransfer($id, 200, false);
        });
    }

    /**
     * Koreksi transaksi tabungan sah dengan baris `Pembalik` beralasan:
     * setoran → keluar, penarikan/belanja → masuk, nominal sama, metode sama.
     * Transaksi asal tidak diubah. Ditolak bila sudah dikoreksi, bila asal
     * sendiri pembalik, bila debit pembayaran tagihan (gunakan pembatalan
     * pembayaran), atau bila membalik setoran membuat saldo negatif.
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $input idempotency_key, alasan
     * @return array<string, mixed>
     */
    public function koreksi(array $user, int $transaksiId, array $input, string $kanal): array
    {
        $this->siap();
        $this->bsa->wajibKelola($user);
        $userId = (int) $user['id'];
        $alasan = $this->alasan($input['alasan'] ?? null);
        $kunci = $this->bsa->kunci($input['idempotency_key'] ?? null);
        $hash = $this->bsa->hash([self::OP_KOREKSI, $transaksiId, $alasan]);
        $asal = $transaksiId > 0 ? $this->bsaRepo->transaksi($transaksiId) : null;
        if ($asal === null) {
            throw new BsaException('Transaksi tidak ditemukan.', 404);
        }
        $santriId = (int) $asal['santri_id'];

        return $this->bsaRepo->transaction(function () use ($userId, $transaksiId, $santriId, $alasan, $kunci, $hash, $kanal): array {
            $penugasan = $this->bsa->penugasanTerkunci($userId);
            $klaim = $this->bsa->klaim($userId, $kunci, self::OP_KOREKSI, $hash);
            [, $tugas] = $this->bsa->santriDalamCakupan($santriId, $penugasan);
            if ($klaim['hasil_id'] !== null) {
                $lama = $this->bsaRepo->transaksi($klaim['hasil_id']);

                return ['id' => $klaim['hasil_id'], 'status' => 200, 'replayed' => true, 'pembalik_dari_id' => $transaksiId,
                    'saldo_sesudah' => (int) ($lama['saldo_sesudah'] ?? 0), 'saldo' => $this->saldoKini($santriId), 'nomor_kuitansi' => $this->nomor($klaim['hasil_id'])];
            }
            $akun = $this->bsaRepo->kunciAkun($santriId);
            $x = $this->bsaRepo->transaksi($transaksiId);
            if ($akun === null || $x === null || (int) $x['santri_id'] !== $santriId || $x['status'] !== 'Sah') {
                throw new BsaException('Transaksi tidak ditemukan.', 404);
            }
            if ($x['jenis'] === 'Pembalik') {
                throw new BsaException('Transaksi pembalik tidak dapat dibalik lagi. Catat transaksi baru bila perlu.');
            }
            if ($x['jenis'] === 'PembayaranTagihan') {
                throw new BsaException('Debit pembayaran tagihan dikoreksi dengan membatalkan pembayaran ' . ($x['bayar_kuitansi'] ?? '') . ' pada rincian tagihan; saldo kembali di transaksi yang sama.');
            }
            if ($x['dikoreksi_oleh_id'] !== null) {
                throw new BsaException('Transaksi ini sudah dikoreksi. Tidak ada data yang diubah.', 409);
            }
            $nominal = (int) $x['nominal'];
            $arah = $x['arah'] === 'Masuk' ? 'Keluar' : 'Masuk';
            $saldoBaru = $arah === 'Masuk' ? $akun['saldo'] + $nominal : $akun['saldo'] - $nominal;
            if ($saldoBaru < 0) {
                throw new BsaException('Saldo tabungan tidak cukup untuk membalik setoran ini (saldo ' . $this->rupiah($akun['saldo'])
                    . '). Dana sudah dipakai untuk penarikan, belanja, atau pembayaran tagihan; koreksi transaksi pemakai lebih dahulu.', 422);
            }
            $nomorAsal = BsaService::nomorKuitansiTabungan((int) $x['id'], (string) $x['created_at']);
            try {
                $id = $this->bsaRepo->catatTransaksi($santriId, [
                    'jenis' => 'Pembalik', 'arah' => $arah, 'nominal' => $nominal, 'metode' => (string) $x['metode'],
                    'tujuan' => 'Koreksi ' . strtolower((string) $x['jenis']) . ' ' . $nomorAsal, 'referensi' => null, 'catatan' => null,
                    'pembalik_dari_id' => $transaksiId, 'alasan' => $alasan, 'idempotency_key' => $kunci,
                ], $akun['saldo'], $saldoBaru, $userId, $tugas['pengurus_id'], $tugas['id']);
            } catch (BsaException $exception) {
                if ($exception->errno === 1062) {
                    throw new BsaException('Transaksi ini sudah dikoreksi. Tidak ada data yang diubah.', 409);
                }
                throw $exception;
            }
            $this->bsaRepo->selesaikanIdempotensi($klaim['id'], $id, 201);
            $this->bsa->wajibAudit('bsa.tabungan.koreksi', 'bsa_tabungan_transaksi', $id, [
                'transaksi_id' => $id, 'pembalik_dari_id' => $transaksiId, 'santri_id' => $santriId, 'jenis' => 'Pembalik', 'metode' => (string) $x['metode'],
                'status' => 'Sah', 'penugasan_id' => $tugas['id'], 'sumber' => BsaService::SUMBER_PETUGAS, 'kanal' => $this->bsa->kanal($kanal),
            ], $userId);
            $this->bsa->beritahuWali($santriId, BsaNotifikasi::EVENT_TABUNGAN, $id);

            return ['id' => $id, 'status' => 201, 'replayed' => false, 'pembalik_dari_id' => $transaksiId, 'saldo_sesudah' => $saldoBaru, 'saldo' => $saldoBaru,
                'nomor_kuitansi' => $this->nomor($id)];
        });
    }

    // =======================================================================
    // Bacaan
    // =======================================================================

    /**
     * Rincian keuangan santri (kontrak `BsaService::santri`) ditambah setoran
     * transfer menunggu/diputus untuk petugas dan admin. Orang tua tidak
     * melihat setoran yang belum diverifikasi.
     *
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function santri(array $user, int $santriId, mixed $page = 1): array
    {
        $d = $this->bsa->santri($user, $santriId, $page);
        $internal = $d['akses']['sumber'] !== BsaService::SUMBER_WALI;
        $rows = $internal && $this->tersedia() ? $this->repo->setoranTransferSantri($santriId) : [];
        $d['setoran_transfer'] = array_map(fn (array $r): array => $this->ringkasSetoran($r), $rows);
        $d['saldo_menunggu'] = $internal && $this->tersedia() ? $this->repo->nominalMenungguSantri($santriId) : 0;

        return $d;
    }

    /**
     * Satu transaksi tabungan beserta data kuitansinya (saldo sebelum/sesudah,
     * referensi silang). Hak sama dengan rincian santri; orang tua tanpa
     * referensi, catatan, dan alasan.
     *
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function transaksi(array $user, int $id): array
    {
        $this->siap();
        $akses = $this->bsa->wajibBaca($user);
        $x = $id > 0 ? $this->bsaRepo->transaksi($id) : null;
        if ($x === null || $x['status'] !== 'Sah') {
            throw new BsaException('Transaksi tidak ditemukan.', 404);
        }
        try {
            $s = $this->bsa->santriTerbaca($user, $akses, (int) $x['santri_id']);
        } catch (BsaException) {
            throw new BsaException('Transaksi tidak ditemukan.', 404);
        }
        $baris = $this->bsa->ringkasTransaksi($x, $akses['sumber'] === BsaService::SUMBER_WALI);
        $saldoSesudah = (int) $x['saldo_sesudah'];

        return $baris + [
            'saldo_sebelum' => $x['arah'] === 'Masuk' ? $saldoSesudah - (int) $x['nominal'] : $saldoSesudah + (int) $x['nominal'],
            'disetujui_pada' => (string) $x['disetujui_pada'],
            'santri' => $this->bsa->identitas($s),
            'akses' => $akses,
        ];
    }

    /**
     * Mutasi tabungan lintas santri (petugas: cakupannya; admin: semua) dan
     * setoran transfer menunggu. Orang tua memakai rincian anak.
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $input jenis, metode, tanggal, q, page
     * @return array<string, mixed>
     */
    public function daftarMutasi(array $user, array $input): array
    {
        $akses = $this->wajibInternal($user);
        $saring = [
            'jenis' => in_array($input['jenis'] ?? null, ['Setoran', 'Penarikan', 'Belanja', 'PembayaranTagihan', 'Pembalik'], true) ? (string) $input['jenis'] : null,
            'metode' => in_array($input['metode'] ?? null, ['Tunai', 'Transfer', 'Internal'], true) ? (string) $input['metode'] : null,
            'tanggal' => $this->tanggalOpsional($input['tanggal'] ?? null),
        ];
        $hasil = $this->repo->daftarMutasi($this->hak($user, $akses), $saring, PageQuery::term($input['q'] ?? ''), $this->bsa->halaman($input['page'] ?? 1));

        return [
            'rows' => array_map(static fn (array $x): array => [
                'id' => (int) $x['id'],
                'santri' => ['id' => (int) $x['santri_id'], 'nis' => (string) $x['nis'], 'nama' => (string) $x['nama_santri']],
                'jenis' => (string) $x['jenis'], 'arah' => (string) $x['arah'], 'nominal' => (int) $x['nominal'], 'saldo_sesudah' => (int) $x['saldo_sesudah'],
                'metode' => (string) $x['metode'], 'tujuan' => $x['tujuan'] === null ? null : (string) $x['tujuan'],
                'referensi' => $x['referensi'] === null ? null : (string) $x['referensi'],
                'pembalik_dari_id' => $x['pembalik_dari_id'] === null ? null : (int) $x['pembalik_dari_id'],
                'dikoreksi_oleh_id' => $x['dikoreksi_oleh_id'] === null ? null : (int) $x['dikoreksi_oleh_id'],
                'petugas' => ['nama' => (string) $x['petugas_nama']], 'waktu' => (string) $x['created_at'],
                'nomor_kuitansi' => BsaService::nomorKuitansiTabungan((int) $x['id'], (string) $x['created_at']),
            ], $hasil['rows']),
            'total' => (int) $hasil['total'], 'page' => (int) $hasil['page'], 'per_page' => (int) $hasil['perPage'], 'saring' => $saring, 'akses' => $akses,
        ];
    }

    /**
     * Setoran transfer menurut status (bawaan: menunggu verifikasi).
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $input status, q, page
     * @return array<string, mixed>
     */
    public function daftarSetoranTransfer(array $user, array $input): array
    {
        $akses = $this->wajibInternal($user);
        $status = ($input['status'] ?? 'Menunggu') === 'semua' ? null
            : (in_array($input['status'] ?? 'Menunggu', ['Menunggu', 'Sah', 'Ditolak'], true) ? (string) ($input['status'] ?? 'Menunggu') : 'Menunggu');
        $hasil = $this->repo->daftarSetoranTransfer($this->hak($user, $akses), $status, PageQuery::term($input['q'] ?? ''), $this->bsa->halaman($input['page'] ?? 1));

        return [
            'rows' => array_map(fn (array $r): array => $this->ringkasSetoran($r) + ['santri' => ['id' => (int) $r['santri_id'], 'nis' => (string) $r['nis'], 'nama' => (string) $r['nama_santri']]], $hasil['rows']),
            'total' => (int) $hasil['total'], 'page' => (int) $hasil['page'], 'per_page' => (int) $hasil['perPage'], 'status' => $status ?? 'semua', 'akses' => $akses,
        ];
    }

    /**
     * Rekonsiliasi harian BSA (petugas: santri dalam cakupannya kini; admin:
     * semua). Kas = uang nyata tunai/transfer yang SUDAH diverifikasi:
     * setoran tabungan dan pembayaran tagihan tunai/transfer (masuk),
     * penarikan/belanja (keluar), serta koreksi. Pembayaran tagihan dari
     * tabungan dan pengembaliannya adalah PERPINDAHAN INTERNAL: tidak
     * menambah penerimaan kas karena uangnya sudah dihitung saat setoran.
     * Transfer yang menunggu verifikasi ditampilkan terpisah, tidak dihitung.
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $input tanggal (TTTT-BB-HH; bawaan hari ini)
     * @return array<string, mixed>
     */
    public function rekonsiliasi(array $user, array $input): array
    {
        $akses = $this->wajibInternal($user);
        $hariIni = date('Y-m-d');
        $tanggal = $this->tanggalOpsional($input['tanggal'] ?? null) ?? $hariIni;
        if ($tanggal > $hariIni) {
            throw new BsaException('Tanggal rekonsiliasi tidak boleh di masa depan.');
        }
        $m = $this->repo->rekonsiliasi($this->hak($user, $akses), $tanggal, $tanggal === $hariIni);
        $nol = static fn (array $kunci): array => array_fill_keys($kunci, 0);
        $tab = ['setoran' => $nol(['Tunai', 'Transfer']), 'penarikan' => $nol(['Tunai', 'Transfer']), 'belanja' => $nol(['Tunai', 'Transfer']),
            'koreksi_masuk' => $nol(['Tunai', 'Transfer']), 'koreksi_keluar' => $nol(['Tunai', 'Transfer']), 'debit_tagihan' => 0, 'pengembalian_tagihan' => 0];
        foreach ($m['ledger'] as $r) {
            $n = (int) $r['nominal'];
            $cara = (string) $r['metode'];
            match (true) {
                $r['jenis'] === 'Setoran' => $tab['setoran'][$cara] += $n,
                $r['jenis'] === 'Penarikan' => $tab['penarikan'][$cara] += $n,
                $r['jenis'] === 'Belanja' => $tab['belanja'][$cara] += $n,
                $r['jenis'] === 'PembayaranTagihan' => $tab['debit_tagihan'] += $n,
                $r['jenis'] === 'Pembalik' && $cara === 'Internal' => $tab['pengembalian_tagihan'] += $n,
                $r['jenis'] === 'Pembalik' && $r['arah'] === 'Masuk' => $tab['koreksi_masuk'][$cara] += $n,
                default => $tab['koreksi_keluar'][$cara] += $n,
            };
        }
        $bayar = $nol(['Tunai', 'Transfer', 'Tabungan']);
        $batal = $nol(['Tunai', 'Transfer', 'Tabungan']);
        foreach ($m['bayar'] as $r) {
            $bayar[(string) $r['sumber']] += (int) $r['nominal'];
        }
        foreach ($m['batal'] as $r) {
            $batal[(string) $r['sumber']] += (int) $r['nominal'];
        }
        $kas = [];
        foreach (['Tunai', 'Transfer'] as $cara) {
            $masuk = $tab['setoran'][$cara] + $bayar[$cara] + $tab['koreksi_masuk'][$cara];
            $keluar = $tab['penarikan'][$cara] + $tab['belanja'][$cara] + $tab['koreksi_keluar'][$cara] + $batal[$cara];
            $kas[$cara] = ['setoran' => $tab['setoran'][$cara], 'pembayaran_tagihan' => $bayar[$cara], 'koreksi_masuk' => $tab['koreksi_masuk'][$cara], 'masuk' => $masuk,
                'penarikan_belanja' => $tab['penarikan'][$cara] + $tab['belanja'][$cara], 'koreksi_keluar' => $tab['koreksi_keluar'][$cara] + $batal[$cara],
                'keluar' => $keluar, 'bersih' => $masuk - $keluar];
        }
        $mutasi = 0;
        foreach ($m['ledger'] as $r) {
            $mutasi += $r['arah'] === 'Masuk' ? (int) $r['nominal'] : -(int) $r['nominal'];
        }

        return [
            'tanggal' => $tanggal,
            'kas' => $kas,
            // Penerimaan kas baru = uang nyata yang masuk hari itu dari luar
            // (setoran + pembayaran tagihan tunai/transfer terverifikasi).
            'penerimaan_kas_baru' => $kas['Tunai']['setoran'] + $kas['Tunai']['pembayaran_tagihan'] + $kas['Transfer']['setoran'] + $kas['Transfer']['pembayaran_tagihan'],
            'tabungan' => $tab,
            'tagihan' => ['pembayaran' => $bayar, 'pembatalan' => $batal, 'pembayaran_total' => array_sum($bayar)],
            'internal' => [
                'debit_tabungan' => $tab['debit_tagihan'], 'pembayaran_dari_tabungan' => $bayar['Tabungan'], 'selisih' => $tab['debit_tagihan'] - $bayar['Tabungan'],
                'pengembalian_ke_tabungan' => $tab['pengembalian_tagihan'], 'pembatalan_pembayaran_tabungan' => $batal['Tabungan'],
                'selisih_pengembalian' => $tab['pengembalian_tagihan'] - $batal['Tabungan'],
            ],
            'saldo' => [
                'awal' => $m['saldo_awal'], 'mutasi_bersih' => $mutasi, 'akhir' => $m['saldo_akhir'], 'selisih' => $m['saldo_awal'] + $mutasi - $m['saldo_akhir'],
                'akun' => $tanggal === $hariIni ? $m['saldo_akun'] : null, 'selisih_akun' => $tanggal === $hariIni ? $m['saldo_akun'] - $m['saldo_akhir'] : null,
            ],
            'menunggu' => ['setoran_transfer' => $m['menunggu_setoran'], 'pembayaran_transfer' => $m['menunggu_bayar']],
            'rows' => array_map(static fn (array $r): array => [
                'asal' => (string) $r['asal'], 'id' => (int) $r['id'], 'waktu' => (string) $r['waktu'], 'jenis' => (string) $r['jenis'], 'arah' => (string) $r['arah'],
                'cara' => (string) $r['cara'], 'nominal' => (int) $r['nominal'], 'rujukan_id' => $r['rujukan_id'] === null ? null : (int) $r['rujukan_id'],
                'nomor_kuitansi' => $r['asal'] === 'tabungan' ? BsaService::nomorKuitansiTabungan((int) $r['id'], (string) $r['dibuat']) : (string) $r['nomor_kuitansi'],
                'internal' => in_array($r['cara'], ['Internal', 'Tabungan'], true),
                'santri' => ['id' => (int) $r['santri_id'], 'nis' => (string) $r['nis'], 'nama' => (string) $r['nama_santri']],
            ], $m['rows']),
            'akses' => $akses,
        ];
    }

    // =======================================================================

    private function siap(): void
    {
        if (!$this->tersedia()) {
            throw new BsaException('Modul tabungan lengkap (PRD V6 Fase 3) belum dipasang pada basis data ini.', 503);
        }
    }

    /** @return array{baca:bool, kelola:bool, sumber:?string, jenjang:?array<int, string>} */
    private function wajibInternal(array $user): array
    {
        $this->siap();
        $akses = $this->bsa->wajibBaca($user);
        if ($akses['sumber'] === BsaService::SUMBER_WALI) {
            throw new BsaException('Bagian ini hanya untuk petugas BSA dan admin.', 403);
        }

        return $akses;
    }

    /**
     * @param array{sumber:?string, jenjang:?array<int, string>} $akses
     * @return array{mode:string, user_id:int, jenjang:?array<int, string>}
     */
    private function hak(array $user, array $akses): array
    {
        return [
            'mode' => match ($akses['sumber']) {
                BsaService::SUMBER_PETUGAS => 'petugas',
                BsaService::SUMBER_WALI => 'wali',
                default => 'admin',
            },
            'user_id' => (int) $user['id'],
            'jenjang' => $akses['jenjang'],
        ];
    }

    /** Hasil setoran transfer (baru atau replay) dengan keadaan saat ini. */
    private function hasilSetoranTransfer(int $id, int $status, bool $replayed): array
    {
        $p = $this->repo->setoranTransfer($id) ?? [];
        $transaksiId = ($p['transaksi_id'] ?? null) === null ? null : (int) $p['transaksi_id'];

        return [
            'id' => $id, 'status' => $status, 'replayed' => $replayed, 'status_setoran' => (string) ($p['status'] ?? ''),
            'setoran_transfer_id' => $id, 'transaksi_id' => $transaksiId,
            'nomor_kuitansi' => $transaksiId === null ? null : BsaService::nomorKuitansiTabungan($transaksiId, (string) $p['transaksi_dibuat']),
            'saldo_sesudah' => $this->saldoKini((int) ($p['santri_id'] ?? 0)),
        ];
    }

    /** @param array<string, mixed> $r */
    private function ringkasSetoran(array $r): array
    {
        $transaksiId = $r['transaksi_id'] === null ? null : (int) $r['transaksi_id'];

        return [
            'id' => (int) $r['id'], 'nominal' => (int) $r['nominal'], 'referensi' => (string) $r['referensi'],
            'catatan' => $r['catatan'] === null ? null : (string) $r['catatan'], 'status' => (string) $r['status'],
            'transaksi_id' => $transaksiId,
            'nomor_kuitansi' => $transaksiId === null ? null : BsaService::nomorKuitansiTabungan($transaksiId, (string) $r['transaksi_dibuat']),
            'pencatat' => ['nama' => (string) $r['pencatat_nama']], 'dicatat_pada' => (string) $r['created_at'],
            'diputus' => $r['diputus_pada'] === null ? null : ['oleh' => (string) $r['pemutus_nama'], 'pada' => (string) $r['diputus_pada']],
            'alasan_tolak' => $r['alasan_tolak'] === null ? null : (string) $r['alasan_tolak'],
        ];
    }

    private function saldoKini(int $santriId): int
    {
        return (int) ($this->bsaRepo->santri($santriId)['saldo'] ?? 0);
    }

    private function nomor(int $transaksiId): ?string
    {
        $x = $this->bsaRepo->transaksi($transaksiId);

        return $x === null ? null : BsaService::nomorKuitansiTabungan($transaksiId, (string) $x['created_at']);
    }

    private function alasan(mixed $value): string
    {
        $alasan = $this->bsa->teks($value, 500, 'Alasan');
        if ($alasan === null || mb_strlen($alasan) < 5) {
            throw new BsaException('Alasan wajib diisi (minimal 5 karakter).');
        }

        return $alasan;
    }

    private function tanggalOpsional(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        return $this->bsa->tanggal($value, 'Tanggal');
    }

    private function rupiah(int $n): string
    {
        return 'Rp' . number_format($n, 0, ',', '.');
    }
}
