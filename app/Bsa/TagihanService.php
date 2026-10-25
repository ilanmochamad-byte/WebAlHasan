<?php

declare(strict_types=1);

namespace App\Bsa;

use App\Database\PageQuery;
use DateTimeImmutable;

/**
 * Tagihan bulanan lengkap — PRD V6 Fase 2. Dipakai halaman web
 * `portal/bsa_*.php` dan API `/api/v1/v6/bsa/*` sehingga keputusan keduanya
 * identik.
 *
 * Hak memakai penjaga `BsaService` yang sama dengan Fase 1 dan dihitung ulang
 * dari basis data setiap panggilan:
 *   - petugas BSA : baca + mutasi santri AKTIF dalam cakupan unit
 *                   penugasannya; tarif hanya untuk unit dalam cakupannya;
 *   - admin       : hanya baca seluruh santri (termasuk tarif, penerbitan,
 *                   penyesuaian, tunggakan, pembayaran, kuitansi);
 *   - wali        : hanya baca tagihan, pembayaran sah/dibatalkan, dan
 *                   kuitansi anak dengan relasi `santri_wali` aktif — tanpa
 *                   referensi transfer, catatan internal, atau alasan;
 *   - lainnya     : 403. Data di luar hak dijawab 404.
 *
 * Aturan uang (PRD V6 §5.3): rupiah bulat tanpa float; satu tagihan per
 * santri per periode (kunci unik 027); komponen tarif dan penyesuaian santri
 * disalin sebagai snapshot saat terbit sehingga perubahan tarif sesudahnya
 * tidak mengubah tagihan atau kuitansi lama; revisi = baris penyesuaian
 * beralasan + versi baru (tidak ada UPDATE komponen atau DELETE); pembayaran
 * tidak boleh melebihi sisa (termasuk transfer yang menunggu verifikasi);
 * pembatalan pembayaran = entri penyeimbang beralasan, nominal tidak diubah.
 * Setiap mutasi idempoten per akun dan berjalan dalam satu transaksi dengan
 * urutan kunci tetap (lihat `TagihanRepository`). Tagihan tidak memotong
 * tabungan secara otomatis.
 *
 * Fase 3 (migrasi 029): pembayaran tagihan dari tabungan hanya melalui
 * tindakan eksplisit petugas (`bayarDariTabungan`) yang disetujui: debit
 * ledger `PembayaranTagihan` dan pembayaran bersumber `Tabungan` dicatat di
 * SATU transaksi basis data dengan referensi silang unik
 * (`bsa_pembayaran.tabungan_transaksi_id`), commit atau rollback bersama.
 * Membatalkan pembayaran itu mengembalikan saldo lewat baris `Pembalik`
 * beralasan di transaksi yang sama.
 */
final class TagihanService
{
    public const OP_TARIF = 'tarif.buat';
    public const OP_PENYESUAIAN = 'penyesuaian.buat';
    public const OP_PENYESUAIAN_CABUT = 'penyesuaian.cabut';
    public const OP_MASSAL = 'tagihan.massal';
    public const OP_REVISI = 'tagihan.revisi';
    public const OP_BATAL = 'tagihan.batal';
    public const OP_BAYAR = 'pembayaran.catat';
    public const OP_VERIFIKASI = 'pembayaran.verifikasi';
    public const OP_BAYAR_BATAL = 'pembayaran.batal';
    public const OP_BAYAR_TABUNGAN = 'pembayaran.tabungan';

    /** Penyesuaian aktif per santri dibatasi agar tagihan tetap terbaca. */
    public const PENYESUAIAN_AKTIF_MAKS = 5;
    /** Jatuh tempo penerbitan massal paling lambat tiga bulan sesudah awal periode. */
    public const JATUH_TEMPO_BULAN_MAKS = 3;

    public function __construct(
        private BsaService $bsa,
        private BsaRepository $bsaRepo,
        private TagihanRepository $repo,
        private TabunganRepository $tabungan
    ) {
    }

    /** Migrasi 028 terpasang; halaman memakai ini untuk menyembunyikan fitur Fase 2. */
    public function tersedia(): bool
    {
        return $this->repo->skemaSiap();
    }

    // =======================================================================
    // Tarif
    // =======================================================================

    /**
     * Unit yang boleh dikelola/dibaca akun dan seluruh tarifnya.
     *
     * @param array<string, mixed> $user
     * @return array{unit:array<int, string>, tarif:array<int, array<string, mixed>>, akses:array<string, mixed>}
     */
    public function daftarTarif(array $user): array
    {
        $akses = $this->wajibInternal($user);
        $unit = $this->unitAkses($akses);
        $rows = $this->repo->daftarTarif($akses['sumber'] === BsaService::SUMBER_PETUGAS ? $unit : null);
        $komponen = $this->repo->komponenTarif(array_map(static fn (array $r): int => (int) $r['id'], $rows));

        return [
            'unit' => $unit,
            'tarif' => array_map(static fn (array $r): array => [
                'id' => (int) $r['id'],
                'unit' => (string) $r['jenjang'],
                'berlaku_mulai' => (string) $r['berlaku_mulai'],
                'total' => (int) $r['total'],
                'status' => (string) $r['status'],
                'alasan' => $r['alasan'] === null ? null : (string) $r['alasan'],
                'diganti_oleh_id' => $r['diganti_oleh_id'] === null ? null : (int) $r['diganti_oleh_id'],
                'diganti_pada' => $r['diganti_pada'] === null ? null : (string) $r['diganti_pada'],
                'pembuat' => ['nama' => (string) $r['pembuat_nama']],
                'dibuat_pada' => (string) $r['created_at'],
                'komponen' => $komponen[(int) $r['id']] ?? [],
            ], $rows),
            'akses' => $akses,
        ];
    }

    /**
     * Menetapkan tarif unit mulai satu periode. Tarif berlaku pada periode
     * mulai yang sama diganti (beralasan); tarif periode sebelumnya tetap
     * berlaku untuk periode sebelumnya. Tagihan terbit tidak berubah.
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $input idempotency_key, unit, berlaku_mulai, komponen[], alasan
     * @return array{id:int, status:int, replayed:bool, menggantikan_id:?int}
     */
    public function buatTarif(array $user, array $input, string $kanal): array
    {
        $this->siap();
        $this->bsa->wajibKelola($user);
        $userId = (int) $user['id'];
        $unit = $this->unitKanonik($input['unit'] ?? null);
        $mulai = $this->bsa->periode($input['berlaku_mulai'] ?? null, 'Periode mulai berlaku');
        [$komponen, $total] = $this->komponen($input['komponen'] ?? null, 'Tarif');
        $alasan = $this->bsa->teks($input['alasan'] ?? null, 500, 'Alasan');
        $kunci = $this->bsa->kunci($input['idempotency_key'] ?? null);
        $hash = $this->bsa->hash([self::OP_TARIF, $unit, $mulai, $komponen, $alasan]);

        return $this->bsaRepo->transaction(function () use ($userId, $unit, $mulai, $komponen, $total, $alasan, $kunci, $hash, $kanal): array {
            $penugasan = $this->bsa->penugasanTerkunci($userId);
            $klaim = $this->bsa->klaim($userId, $kunci, self::OP_TARIF, $hash);
            $tugas = $this->penugasanUnit($penugasan, $unit);
            if ($tugas === null) {
                throw new BsaException('Unit ini di luar cakupan penugasan BSA Anda.', 404);
            }
            if ($klaim['hasil_id'] !== null) {
                return ['id' => $klaim['hasil_id'], 'status' => 200, 'replayed' => true, 'menggantikan_id' => null];
            }
            $lama = $this->repo->tarifBerlakuTerkunci($unit, $mulai);
            if (($lama !== null || $this->repo->adaTarifUnit($unit)) && ($alasan === null || mb_strlen($alasan) < 5)) {
                throw new BsaException('Perubahan tarif wajib disertai alasan (minimal 5 karakter).');
            }
            if ($lama !== null) {
                $this->repo->tandaiTarifDiganti($lama);
            }
            $id = $this->repo->simpanTarif(['jenjang' => $unit, 'berlaku_mulai' => $mulai, 'total' => $total, 'alasan' => $alasan, 'idempotency_key' => $kunci, 'komponen' => $komponen],
                $userId, $tugas['pengurus_id'], $tugas['id']);
            if ($lama !== null) {
                $this->repo->tautkanPengganti($lama, $id);
            }
            $this->bsaRepo->selesaikanIdempotensi($klaim['id'], $id, 201);
            $this->bsa->wajibAudit('bsa.tarif.buat', 'bsa_tarif', $id, [
                'tarif_id' => $id, 'jenjang' => $unit, 'berlaku_mulai' => $mulai, 'tarif_diganti_id' => $lama, 'jumlah_komponen' => count($komponen),
                'penugasan_id' => $tugas['id'], 'sumber' => BsaService::SUMBER_PETUGAS, 'kanal' => $this->bsa->kanal($kanal),
            ], $userId);

            return ['id' => $id, 'status' => 201, 'replayed' => false, 'menggantikan_id' => $lama];
        });
    }

    // =======================================================================
    // Penyesuaian per santri
    // =======================================================================

    /**
     * Penyesuaian satu santri (petugas dalam cakupan dan admin; wali tidak).
     *
     * @param array<string, mixed> $user
     * @return array{santri:array<string, mixed>, rows:array<int, array<string, mixed>>, akses:array<string, mixed>}
     */
    public function penyesuaianSantri(array $user, int $santriId): array
    {
        $akses = $this->wajibInternal($user);
        $s = $this->bsa->santriTerbaca($user, $akses, $santriId);

        return [
            'santri' => $this->bsa->identitas($s),
            'rows' => array_map(static fn (array $r): array => [
                'id' => (int) $r['id'],
                'jenis' => (string) $r['jenis'],
                'nama' => (string) $r['nama'],
                'nominal' => (int) $r['nominal'],
                'berlaku_mulai' => (string) $r['berlaku_mulai'],
                'berlaku_sampai' => $r['berlaku_sampai'] === null ? null : (string) $r['berlaku_sampai'],
                'alasan' => (string) $r['alasan'],
                'status' => (string) $r['status'],
                'pembuat' => ['nama' => (string) $r['pembuat_nama']],
                'dibuat_pada' => (string) $r['created_at'],
                'dicabut' => $r['status'] === 'Dicabut' ? ['oleh' => (string) $r['pencabut_nama'], 'pada' => (string) $r['dicabut_pada'], 'alasan' => (string) $r['alasan_cabut']] : null,
            ], $this->repo->penyesuaianSantri($santriId)),
            'akses' => $akses,
        ];
    }

    /**
     * Penyesuaian beralasan untuk satu santri: berlaku pada tagihan yang
     * terbit dari tarif mulai periode tersebut; tagihan terbit tidak berubah.
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $input idempotency_key, jenis, nama, nominal, berlaku_mulai, berlaku_sampai, alasan
     * @return array{id:int, status:int, replayed:bool}
     */
    public function buatPenyesuaian(array $user, int $santriId, array $input, string $kanal): array
    {
        $this->siap();
        $this->bsa->wajibKelola($user);
        $userId = (int) $user['id'];
        $jenis = $input['jenis'] ?? null;
        if (!in_array($jenis, ['Potongan', 'Tambahan'], true)) {
            throw new BsaException('Jenis penyesuaian wajib Potongan atau Tambahan.');
        }
        $nama = $this->bsa->teks($input['nama'] ?? null, 100, 'Keterangan penyesuaian');
        if ($nama === null || mb_strlen($nama) < 3) {
            throw new BsaException('Keterangan penyesuaian wajib diisi (minimal 3 karakter), mis. "Potongan syahriah".');
        }
        $mulai = $this->bsa->periode($input['berlaku_mulai'] ?? null, 'Berlaku mulai');
        $sampai = ($input['berlaku_sampai'] ?? '') === '' || ($input['berlaku_sampai'] ?? null) === null ? null : $this->bsa->periode($input['berlaku_sampai'], 'Berlaku sampai');
        if ($sampai !== null && $sampai < $mulai) {
            throw new BsaException('Periode "berlaku sampai" tidak boleh sebelum "berlaku mulai".');
        }
        $data = [
            'jenis' => (string) $jenis,
            'nama' => $nama,
            'nominal' => $this->bsa->nominal($input['nominal'] ?? null, 'Nominal penyesuaian'),
            'berlaku_mulai' => $mulai,
            'berlaku_sampai' => $sampai,
            'alasan' => $this->alasan($input['alasan'] ?? null),
            'idempotency_key' => $this->bsa->kunci($input['idempotency_key'] ?? null),
        ];
        $hash = $this->bsa->hash([self::OP_PENYESUAIAN, $santriId, $data['jenis'], $data['nama'], $data['nominal'], $mulai, $sampai, $data['alasan']]);

        return $this->bsaRepo->transaction(function () use ($userId, $santriId, $data, $hash, $kanal): array {
            $penugasan = $this->bsa->penugasanTerkunci($userId);
            $klaim = $this->bsa->klaim($userId, $data['idempotency_key'], self::OP_PENYESUAIAN, $hash);
            [, $tugas] = $this->bsa->santriDalamCakupan($santriId, $penugasan);
            if ($klaim['hasil_id'] !== null) {
                return ['id' => $klaim['hasil_id'], 'status' => 200, 'replayed' => true];
            }
            if ($this->repo->jumlahPenyesuaianAktif($santriId) >= self::PENYESUAIAN_AKTIF_MAKS) {
                throw new BsaException('Santri ini sudah memiliki ' . self::PENYESUAIAN_AKTIF_MAKS . ' penyesuaian aktif. Cabut salah satu lebih dahulu.');
            }
            $id = $this->repo->simpanPenyesuaian($santriId, $data, $userId, $tugas['pengurus_id'], $tugas['id']);
            $this->bsaRepo->selesaikanIdempotensi($klaim['id'], $id, 201);
            $this->bsa->wajibAudit('bsa.penyesuaian.buat', 'bsa_penyesuaian', $id, [
                'penyesuaian_id' => $id, 'santri_id' => $santriId, 'jenis' => $data['jenis'], 'berlaku_mulai' => $data['berlaku_mulai'], 'status' => 'Aktif',
                'penugasan_id' => $tugas['id'], 'sumber' => BsaService::SUMBER_PETUGAS, 'kanal' => $this->bsa->kanal($kanal),
            ], $userId);

            return ['id' => $id, 'status' => 201, 'replayed' => false];
        });
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $input idempotency_key, alasan
     * @return array{id:int, status:int, replayed:bool, santri_id:int}
     */
    public function cabutPenyesuaian(array $user, int $id, array $input, string $kanal): array
    {
        $this->siap();
        $this->bsa->wajibKelola($user);
        $userId = (int) $user['id'];
        $alasan = $this->alasan($input['alasan'] ?? null);
        $kunci = $this->bsa->kunci($input['idempotency_key'] ?? null);
        $hash = $this->bsa->hash([self::OP_PENYESUAIAN_CABUT, $id, $alasan]);
        $pemilik = $id > 0 ? $this->repo->penyesuaian($id) : null;
        if ($pemilik === null) {
            throw new BsaException('Penyesuaian tidak ditemukan.', 404);
        }

        return $this->bsaRepo->transaction(function () use ($userId, $id, $pemilik, $alasan, $kunci, $hash, $kanal): array {
            $penugasan = $this->bsa->penugasanTerkunci($userId);
            $klaim = $this->bsa->klaim($userId, $kunci, self::OP_PENYESUAIAN_CABUT, $hash);
            [, $tugas] = $this->bsa->santriDalamCakupan($pemilik['santri_id'], $penugasan);
            if ($klaim['hasil_id'] !== null) {
                return ['id' => $klaim['hasil_id'], 'status' => 200, 'replayed' => true, 'santri_id' => $pemilik['santri_id']];
            }
            $p = $this->repo->penyesuaian($id, true);
            if ($p === null || $p['santri_id'] !== $pemilik['santri_id']) {
                throw new BsaException('Penyesuaian tidak ditemukan.', 404);
            }
            if ($p['status'] !== 'Aktif') {
                throw new BsaException('Penyesuaian sudah dicabut. Tidak ada data yang diubah.', 409);
            }
            $this->repo->cabutPenyesuaian($id, $userId, $alasan);
            $this->bsaRepo->selesaikanIdempotensi($klaim['id'], $id, 200);
            $this->bsa->wajibAudit('bsa.penyesuaian.cabut', 'bsa_penyesuaian', $id, [
                'penyesuaian_id' => $id, 'santri_id' => $p['santri_id'], 'status' => 'Dicabut',
                'penugasan_id' => $tugas['id'], 'sumber' => BsaService::SUMBER_PETUGAS, 'kanal' => $this->bsa->kanal($kanal),
            ], $userId);

            return ['id' => $id, 'status' => 200, 'replayed' => false, 'santri_id' => $p['santri_id']];
        });
    }

    // =======================================================================
    // Pratinjau dan penerbitan massal
    // =======================================================================

    /**
     * Pratinjau penerbitan: hasil per santri sasaran tanpa menulis apa pun.
     * `hash` wajib dikirim kembali saat menerbitkan; bila data berubah di
     * antara pratinjau dan penerbitan, penerbitan ditolak 409.
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $input periode, jatuh_tempo, unit (opsional)
     * @return array<string, mixed>
     */
    public function pratinjau(array $user, array $input): array
    {
        $this->siap();
        $this->bsa->wajibKelola($user);
        $data = $this->normalisasiPenerbitan($input);
        $penugasan = $this->bsaRepo->penugasanAktif((int) $user['id']);
        $cakupan = $this->bsa->cakupan($penugasan);
        $this->wajibUnitDalamCakupan($data['unit'], $cakupan);
        $rencana = $this->rencana($data, $cakupan, null);
        // Alasan hanya dibawa rencana internal untuk snapshot penerbitan;
        // kontrak pratinjau web/API tetap seperti sebelumnya.
        foreach ($rencana['rows'] as &$r) {
            foreach ($r['penyesuaian'] as &$j) {
                unset($j['alasan']);
            }
            unset($j);
        }
        unset($r);

        return $rencana + ['periode' => $data['periode'], 'jatuh_tempo' => $data['jatuh_tempo'], 'unit' => $data['unit']];
    }

    /**
     * Menerbitkan tagihan seluruh santri sasaran yang belum punya tagihan
     * periode itu, dalam satu transaksi. Menjalankan ulang periode yang sama
     * tidak membuat tagihan kedua atau mengubah snapshot tagihan pertama.
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $input idempotency_key, periode, jatuh_tempo, unit, pratinjau_hash
     * @return array{id:int, status:int, replayed:bool, jumlah_terbit:int}
     */
    public function terbitkanMassal(array $user, array $input, string $kanal): array
    {
        $this->siap();
        $this->bsa->wajibKelola($user);
        $userId = (int) $user['id'];
        $data = $this->normalisasiPenerbitan($input);
        $hashPratinjau = $input['pratinjau_hash'] ?? null;
        if (!is_string($hashPratinjau) || !preg_match('/^[a-f0-9]{64}$/D', $hashPratinjau)) {
            throw new BsaException('Tinjau pratinjau penerbitan lebih dahulu.');
        }
        $kunci = $this->bsa->kunci($input['idempotency_key'] ?? null);
        $hash = $this->bsa->hash([self::OP_MASSAL, $data['periode'], $data['jatuh_tempo'], $data['unit'], $hashPratinjau]);

        return $this->bsaRepo->transaction(function () use ($userId, $data, $hashPratinjau, $kunci, $hash, $kanal): array {
            $penugasan = $this->bsa->penugasanTerkunci($userId);
            $klaim = $this->bsa->klaim($userId, $kunci, self::OP_MASSAL, $hash);
            $cakupan = $this->bsa->cakupan($penugasan);
            // Replay juga wajib masih dalam cakupan (pelajaran F-1 audit Fase 1).
            $this->wajibUnitDalamCakupan($data['unit'], $cakupan);
            if ($klaim['hasil_id'] !== null) {
                $lama = $this->repo->penerbitan($klaim['hasil_id']);

                return ['id' => $klaim['hasil_id'], 'status' => 200, 'replayed' => true, 'jumlah_terbit' => (int) ($lama['jumlah_terbit'] ?? 0)];
            }
            // Kunci baca santri sasaran (urut ID), lalu baca ulang sasaran dari
            // keadaan yang telah dikunci sebelum membandingkan dengan pratinjau.
            $this->repo->kunciSantri(array_column($this->repo->sasaranPenerbitan($cakupan, $data['unit']), 'id'));
            $rencana = $this->rencana($data, $cakupan, null);
            if (!hash_equals($rencana['hash'], $hashPratinjau)) {
                throw new BsaException('Data santri, tarif, penyesuaian, atau tagihan berubah sejak pratinjau. Tidak ada tagihan yang diterbitkan; tinjau ulang pratinjau.', 409);
            }
            if ($rencana['jumlah']['Terbit'] === 0) {
                throw new BsaException('Tidak ada tagihan baru untuk diterbitkan pada periode ini. Tidak ada data yang diubah.');
            }
            $jumlah = $rencana['jumlah'] + ['sasaran' => count($rencana['rows'])];
            $tugasUtama = $penugasan[0];
            foreach ($penugasan as $p) {
                if ($p['jenjang'] === null) {
                    $tugasUtama = $p;
                    break;
                }
            }
            $idBatch = $this->repo->simpanPenerbitan(['periode' => $data['periode'], 'jenjang' => $data['unit'], 'jatuh_tempo' => $data['jatuh_tempo'], 'idempotency_key' => $kunci],
                $jumlah, $rencana['hash'], $userId, $tugasUtama['pengurus_id'], $tugasUtama['id']);
            $komponenTarif = $this->repo->komponenTarif(array_values(array_filter(array_column($rencana['rows'], 'tarif_id'))));
            // Gunakan pembacaan yang SAMA dengan perhitungan total/hash.
            // Pada READ COMMITTED, penyesuaian dapat dicabut/disisipkan oleh
            // permintaan lain setelah rencana dihitung. Membaca ulang di sini
            // akan mencampur total lama dengan baris snapshot yang berbeda.
            foreach ($rencana['rows'] as $r) {
                $tagihanId = null;
                if ($r['hasil'] === 'Terbit') {
                    [, $tugas] = $this->bsa->santriDalamCakupan($r['santri_id'], $penugasan);
                    $tagihanId = $this->bsaRepo->simpanTagihan([
                        'santri_id' => $r['santri_id'], 'periode' => $data['periode'], 'jatuh_tempo' => $data['jatuh_tempo'], 'catatan' => null,
                        'idempotency_key' => 'massal:' . $idBatch . ':' . $r['santri_id'],
                        'komponen' => array_map(static fn (array $k): array => ['nama' => $k['nama'], 'nominal' => $k['nominal']], $komponenTarif[$r['tarif_id']] ?? []),
                        'total' => $r['total'],
                    ], $r['unit'], $userId, $tugas['pengurus_id'], $tugas['id']);
                    foreach ($r['penyesuaian'] as $j) {
                        $this->repo->simpanBarisPenyesuaian($tagihanId, 1, $j['jenis'], $j['nama'], $j['nominal'], 'Penyesuaian', $j['id'], $j['alasan'], $userId);
                    }
                    $this->bsa->wajibAudit('bsa.tagihan.terbit', 'bsa_tagihan', $tagihanId, [
                        'tagihan_id' => $tagihanId, 'santri_id' => $r['santri_id'], 'periode' => $data['periode'], 'status' => 'Terbit', 'versi' => 1,
                        'tarif_id' => $r['tarif_id'], 'penerbitan_id' => $idBatch, 'penugasan_id' => $tugas['id'], 'sumber' => BsaService::SUMBER_PETUGAS, 'kanal' => $this->bsa->kanal($kanal),
                    ], $userId);
                    $this->bsa->beritahuWali($r['santri_id'], BsaNotifikasi::EVENT_TAGIHAN, $tagihanId);
                }
                $this->repo->simpanHasilPenerbitan($idBatch, $r['santri_id'], $r['unit'], $r['hasil'], $tagihanId, $r['hasil'] === 'Terbit' ? $r['tarif_id'] : null, $r['keterangan']);
            }
            $this->bsaRepo->selesaikanIdempotensi($klaim['id'], $idBatch, 201);
            $this->bsa->wajibAudit('bsa.tagihan.massal', 'bsa_penerbitan', $idBatch, [
                'penerbitan_id' => $idBatch, 'periode' => $data['periode'], 'jenjang' => $data['unit'], 'jumlah_sasaran' => $jumlah['sasaran'],
                'jumlah_terbit' => $jumlah['Terbit'], 'jumlah_sudah_ada' => $jumlah['SudahAda'], 'jumlah_dilewati' => $jumlah['Dilewati'],
                'penugasan_id' => $tugasUtama['id'], 'sumber' => BsaService::SUMBER_PETUGAS, 'kanal' => $this->bsa->kanal($kanal),
            ], $userId);

            return ['id' => $idBatch, 'status' => 201, 'replayed' => false, 'jumlah_terbit' => $jumlah['Terbit']];
        });
    }

    /**
     * Rincian satu penerbitan. Petugas hanya melihat baris santri pada unit
     * dalam cakupannya.
     *
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function penerbitan(array $user, int $id): array
    {
        $akses = $this->wajibInternal($user);
        $b = $id > 0 ? $this->repo->penerbitan($id) : null;
        $cakupan = $akses['sumber'] === BsaService::SUMBER_PETUGAS ? $akses['jenjang'] : null;
        // Petugas unit: penerbitan unitnya, atau tanpa saringan unit buatannya sendiri.
        if ($b === null || ($cakupan !== null && ($b['jenjang'] === null ? (int) $b['dibuat_oleh'] !== (int) $user['id'] : !$this->bsa->unitTercakup($b['jenjang'], $cakupan)))) {
            throw new BsaException('Penerbitan tidak ditemukan.', 404);
        }
        // Unit pada hasil penerbitan adalah snapshot historis. Hak atas
        // identitas/uang santri tetap mengikuti penempatan dan status KINI,
        // sama dengan rincian tagihan, termasuk untuk petugas seluruh unit.
        $rows = $this->repo->hasilPenerbitan($id, $this->hak($user, $akses));

        return $this->ringkasPenerbitan($b) + [
            'rows' => array_map(static fn (array $r): array => [
                'santri' => ['id' => (int) $r['santri_id'], 'nis' => (string) $r['nis'], 'nama' => (string) $r['nama_santri']],
                'unit' => $r['jenjang'] === null ? null : (string) $r['jenjang'],
                'hasil' => (string) $r['hasil'],
                'tagihan_id' => $r['tagihan_id'] === null ? null : (int) $r['tagihan_id'],
                'total' => $r['total'] === null ? null : (int) $r['total'],
                'keterangan' => $r['keterangan'] === null ? null : (string) $r['keterangan'],
            ], $rows),
            'akses' => $akses,
        ];
    }

    /**
     * @param array<string, mixed> $user
     * @return array{rows:array<int, array<string, mixed>>, total:int, page:int, per_page:int}
     */
    public function daftarPenerbitan(array $user, mixed $page = 1): array
    {
        $akses = $this->wajibInternal($user);
        $unitPetugas = $akses['sumber'] === BsaService::SUMBER_PETUGAS ? $akses['jenjang'] : null;
        $hasil = $this->repo->daftarPenerbitan($unitPetugas, (int) $user['id'], $this->bsa->halaman($page));

        return ['rows' => array_map(fn (array $b): array => $this->ringkasPenerbitan($b), $hasil['rows']), 'total' => (int) $hasil['total'],
            'page' => (int) $hasil['page'], 'per_page' => (int) $hasil['perPage']];
    }

    // =======================================================================
    // Rincian, revisi, dan pembatalan tagihan
    // =======================================================================

    /**
     * Rincian tagihan: kontrak Fase 1 (`BsaService::tagihan`) ditambah baris
     * penyesuaian, pembayaran, dan riwayat versi. Wali melihat pembayaran sah
     * atau dibatalkan saja, tanpa referensi, catatan, dan alasan.
     *
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function rincianTagihan(array $user, int $id): array
    {
        $t = $this->bsa->tagihan($user, $id);
        if (!$this->repo->skemaSiap()) {
            return $t + ['penyesuaian' => [], 'pembayaran' => [], 'riwayat' => [], 'menunggu' => 0];
        }
        $wali = $t['akses']['sumber'] === BsaService::SUMBER_WALI;
        $bayar = $this->repo->pembayaranTagihan($id);
        if ($wali) {
            $bayar = array_values(array_filter($bayar, static fn (array $b): bool => $b['status'] !== 'Menunggu'));
        }
        $komponen = array_sum(array_column($t['komponen'], 'nominal'));

        return $t + [
            'jumlah_komponen' => $komponen,
            'penyesuaian' => array_map(static fn (array $r): array => array_filter([
                'versi' => (int) $r['versi'], 'jenis' => (string) $r['jenis'], 'nama' => (string) $r['nama'], 'nominal' => (int) $r['nominal'],
                'asal' => (string) $r['asal'], 'alasan' => $wali ? null : (string) $r['alasan'], 'dicatat_pada' => (string) $r['created_at'],
            ], static fn ($v): bool => $v !== null), $this->repo->barisPenyesuaian($id)),
            'pembayaran' => array_map(fn (array $b): array => $this->ringkasPembayaran($b, $wali), $bayar),
            'menunggu' => array_sum(array_map(static fn (array $b): int => $b['status'] === 'Menunggu' ? (int) $b['nominal'] : 0, $bayar)),
            'riwayat' => $wali ? [] : array_map(static fn (array $r): array => [
                'versi' => (int) $r['versi'], 'tindakan' => (string) $r['tindakan'], 'total' => (int) $r['total'], 'jatuh_tempo' => (string) $r['jatuh_tempo'],
                'alasan' => $r['alasan'] === null ? null : (string) $r['alasan'], 'aktor' => ['nama' => (string) $r['aktor_nama']], 'waktu' => (string) $r['created_at'],
            ], $this->repo->riwayatTagihan($id)),
        ];
    }

    /**
     * Revisi beralasan: menambah satu baris potongan/tambahan dan/atau
     * mengubah jatuh tempo, menaikkan versi (optimistic). Tidak menghapus
     * komponen; total baru wajib > 0 dan ≥ terbayar + transfer menunggu.
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $input idempotency_key, versi, jenis, nama, nominal, jatuh_tempo, alasan
     * @return array{id:int, status:int, replayed:bool, versi:int}
     */
    public function revisiTagihan(array $user, int $id, array $input, string $kanal): array
    {
        $this->siap();
        $this->bsa->wajibKelola($user);
        $userId = (int) $user['id'];
        $versi = $this->versi($input['versi'] ?? null);
        $alasan = $this->alasan($input['alasan'] ?? null);
        $kosong = static fn (string $k): bool => ($input[$k] ?? '') === '' || ($input[$k] ?? null) === null;
        $baris = null;
        if (!$kosong('nominal') || !$kosong('jenis')) {
            $jenis = $input['jenis'] ?? null;
            if (!in_array($jenis, ['Potongan', 'Tambahan'], true)) {
                throw new BsaException('Jenis revisi wajib Potongan atau Tambahan.');
            }
            $nama = $this->bsa->teks($input['nama'] ?? null, 100, 'Keterangan revisi') ?? ($jenis === 'Potongan' ? 'Koreksi potongan' : 'Koreksi tambahan');
            $baris = ['jenis' => (string) $jenis, 'nama' => $nama, 'nominal' => $this->bsa->nominal($input['nominal'] ?? null, 'Nominal revisi')];
        }
        $jatuhTempo = $kosong('jatuh_tempo') ? null : $this->bsa->tanggal($input['jatuh_tempo'], 'Jatuh tempo');
        if ($baris === null && $jatuhTempo === null) {
            throw new BsaException('Isi nominal revisi atau jatuh tempo baru.');
        }
        $kunci = $this->bsa->kunci($input['idempotency_key'] ?? null);
        $hash = $this->bsa->hash([self::OP_REVISI, $id, $versi, $baris, $jatuhTempo, $alasan]);

        return $this->mutasiTagihan($userId, $id, $kunci, self::OP_REVISI, $hash, function (array $t, array $tugas) use ($userId, $id, $versi, $baris, $jatuhTempo, $alasan, $kanal): array {
            $this->wajibVersi($t, $versi);
            $totalBaru = $t['total'] + ($baris === null ? 0 : ($baris['jenis'] === 'Tambahan' ? $baris['nominal'] : -$baris['nominal']));
            $jt = $jatuhTempo ?? $t['jatuh_tempo'];
            if ($jt < $t['periode'] . '-01') {
                throw new BsaException('Jatuh tempo tidak boleh sebelum awal periode tagihan.');
            }
            if ($totalBaru < 1) {
                throw new BsaException('Total tagihan sesudah revisi wajib lebih dari Rp0. Untuk menghapus kewajiban, batalkan tagihan.');
            }
            if ($totalBaru < $t['terbayar'] + $t['menunggu']) {
                throw new BsaException('Total tagihan sesudah revisi tidak boleh di bawah pembayaran yang sudah tercatat. Batalkan pembayaran lebih dahulu bila keliru.');
            }
            if ($totalBaru > BsaService::NOMINAL_MAKS) {
                throw new BsaException('Total tagihan melebihi batas Rp1.000.000.000.');
            }
            if ($baris === null && $jt === $t['jatuh_tempo']) {
                throw new BsaException('Revisi tidak mengubah apa pun.');
            }
            $this->repo->ubahTagihan($id, $t['versi'], $totalBaru, $jt, 'Terbit');
            $versiBaru = $t['versi'] + 1;
            if ($baris !== null) {
                $this->repo->simpanBarisPenyesuaian($id, $versiBaru, $baris['jenis'], $baris['nama'], $baris['nominal'], 'Revisi', null, $alasan, $userId);
            }
            $this->repo->catatRiwayat($id, $versiBaru, 'Revisi', $totalBaru, $jt, $alasan, $tugas['id'], $userId);
            $this->bsa->wajibAudit('bsa.tagihan.revisi', 'bsa_tagihan', $id, [
                'tagihan_id' => $id, 'santri_id' => $t['santri_id'], 'periode' => $t['periode'], 'status' => 'Terbit', 'versi' => $versiBaru,
                'jenis' => $baris['jenis'] ?? null, 'penugasan_id' => $tugas['id'], 'sumber' => BsaService::SUMBER_PETUGAS, 'kanal' => $this->bsa->kanal($kanal),
            ], $userId);

            return ['id' => $id, 'status' => 200, 'replayed' => false, 'versi' => $versiBaru];
        });
    }

    /**
     * Pembatalan tagihan beralasan; hanya bila belum ada pembayaran sah atau
     * menunggu. Baris tetap ada (status Dibatalkan, versi baru).
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $input idempotency_key, versi, alasan
     * @return array{id:int, status:int, replayed:bool, versi:int}
     */
    public function batalkanTagihan(array $user, int $id, array $input, string $kanal): array
    {
        $this->siap();
        $this->bsa->wajibKelola($user);
        $userId = (int) $user['id'];
        $versi = $this->versi($input['versi'] ?? null);
        $alasan = $this->alasan($input['alasan'] ?? null);
        $kunci = $this->bsa->kunci($input['idempotency_key'] ?? null);
        $hash = $this->bsa->hash([self::OP_BATAL, $id, $versi, $alasan]);

        return $this->mutasiTagihan($userId, $id, $kunci, self::OP_BATAL, $hash, function (array $t, array $tugas) use ($userId, $id, $versi, $alasan, $kanal): array {
            $this->wajibVersi($t, $versi);
            if ($t['terbayar'] > 0 || $t['menunggu'] > 0) {
                throw new BsaException('Tagihan yang sudah memiliki pembayaran tidak dapat dibatalkan. Batalkan pembayarannya lebih dahulu.');
            }
            $this->repo->ubahTagihan($id, $t['versi'], $t['total'], $t['jatuh_tempo'], 'Dibatalkan');
            $versiBaru = $t['versi'] + 1;
            $this->repo->catatRiwayat($id, $versiBaru, 'Dibatalkan', $t['total'], $t['jatuh_tempo'], $alasan, $tugas['id'], $userId);
            $this->bsa->wajibAudit('bsa.tagihan.batal', 'bsa_tagihan', $id, [
                'tagihan_id' => $id, 'santri_id' => $t['santri_id'], 'periode' => $t['periode'], 'status' => 'Dibatalkan', 'versi' => $versiBaru,
                'penugasan_id' => $tugas['id'], 'sumber' => BsaService::SUMBER_PETUGAS, 'kanal' => $this->bsa->kanal($kanal),
            ], $userId);

            return ['id' => $id, 'status' => 200, 'replayed' => false, 'versi' => $versiBaru];
        });
    }

    // =======================================================================
    // Pembayaran
    // =======================================================================

    /**
     * Mencatat pembayaran tunai/transfer. Tunai sah seketika (pencatat =
     * verifikator). Transfer sah seketika hanya bila petugas menyatakan sudah
     * memeriksa dana masuk; selain itu menunggu verifikasi dan belum mengurangi
     * sisa. Nominal tidak boleh melebihi sisa dikurangi transfer menunggu.
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $input idempotency_key, nominal, sumber, tanggal_transaksi, referensi, catatan, terverifikasi
     * @return array{id:int, status:int, replayed:bool, nomor_kuitansi:string, status_pembayaran:string, sisa:int}
     */
    public function catatPembayaran(array $user, int $tagihanId, array $input, string $kanal): array
    {
        $this->siap();
        $this->bsa->wajibKelola($user);
        $userId = (int) $user['id'];
        $sumber = $input['sumber'] ?? null;
        if (!in_array($sumber, ['Tunai', 'Transfer'], true)) {
            throw new BsaException('Cara pembayaran wajib Tunai atau Transfer.');
        }
        $referensi = $this->bsa->teks($input['referensi'] ?? null, 100, 'Referensi');
        if ($sumber === 'Transfer' && $referensi === null) {
            throw new BsaException('Referensi transfer wajib diisi agar dapat diperiksa petugas.');
        }
        $tanggal = $this->bsa->tanggal($input['tanggal_transaksi'] ?? null, 'Tanggal pembayaran');
        if ($tanggal > date('Y-m-d')) {
            throw new BsaException('Tanggal pembayaran tidak boleh di masa depan.');
        }
        $terverifikasi = $sumber === 'Tunai' || in_array($input['terverifikasi'] ?? null, [true, 1, '1', 'ya'], true);
        $data = [
            'nominal' => $this->bsa->nominal($input['nominal'] ?? null, 'Nominal pembayaran'),
            'sumber' => (string) $sumber,
            'tanggal_transaksi' => $tanggal,
            'referensi' => $referensi,
            'catatan' => $this->bsa->teks($input['catatan'] ?? null, 500, 'Catatan'),
            'idempotency_key' => $this->bsa->kunci($input['idempotency_key'] ?? null),
        ];
        $hash = $this->bsa->hash([self::OP_BAYAR, $tagihanId, $data['nominal'], $data['sumber'], $tanggal, $referensi, $data['catatan'], $terverifikasi]);

        return $this->mutasiTagihan($userId, $tagihanId, $data['idempotency_key'], self::OP_BAYAR, $hash,
            function (array $t, array $tugas) use ($userId, $tagihanId, $data, $terverifikasi, $kanal): array {
                if ($t['status'] !== 'Terbit') {
                    throw new BsaException('Tagihan sudah dibatalkan; pembayaran tidak dicatat.');
                }
                $sisa = $t['total'] - $t['terbayar'];
                if ($data['nominal'] > $sisa - $t['menunggu']) {
                    throw new BsaException($t['menunggu'] > 0
                        ? 'Nominal melebihi sisa tagihan sesudah transfer yang menunggu verifikasi. Pembayaran tidak dicatat.'
                        : 'Nominal melebihi sisa tagihan. Pembayaran tidak dicatat.');
                }
                $simpan = $this->repo->simpanPembayaran($tagihanId, $t['santri_id'], $data, $terverifikasi, $userId, $tugas['pengurus_id'], $tugas['id']);
                $sisaBaru = $sisa;
                if ($terverifikasi) {
                    $sisaBaru = $this->sahkan($t, $simpan['id'], $data['nominal']);
                }
                $this->bsa->wajibAudit('bsa.pembayaran.catat', 'bsa_pembayaran', $simpan['id'], [
                    'pembayaran_id' => $simpan['id'], 'tagihan_id' => $tagihanId, 'santri_id' => $t['santri_id'], 'metode' => $data['sumber'],
                    'status' => $terverifikasi ? 'Sah' : 'Menunggu', 'penugasan_id' => $tugas['id'], 'sumber' => BsaService::SUMBER_PETUGAS, 'kanal' => $this->bsa->kanal($kanal),
                ], $userId);
                if ($terverifikasi) {
                    $this->bsa->beritahuWali($t['santri_id'], BsaNotifikasi::EVENT_PEMBAYARAN, $simpan['id']);
                }

                return ['id' => $simpan['id'], 'status' => 201, 'replayed' => false, 'nomor_kuitansi' => $simpan['nomor'],
                    'status_pembayaran' => $terverifikasi ? 'Sah' : 'Menunggu', 'sisa' => $sisaBaru];
            },
            fn (int $hasilId): array => $this->hasilPembayaranLama($hasilId));
    }

    /**
     * Memverifikasi transfer yang menunggu: sah dan mengurangi sisa satu kali.
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $input idempotency_key
     * @return array{id:int, status:int, replayed:bool, nomor_kuitansi:string, status_pembayaran:string, sisa:int}
     */
    public function verifikasiPembayaran(array $user, int $id, array $input, string $kanal): array
    {
        $this->siap();
        $this->bsa->wajibKelola($user);
        $userId = (int) $user['id'];
        $kunci = $this->bsa->kunci($input['idempotency_key'] ?? null);
        $hash = $this->bsa->hash([self::OP_VERIFIKASI, $id]);
        $pemilik = $id > 0 ? $this->repo->pemilikPembayaran($id) : null;
        if ($pemilik === null) {
            throw new BsaException('Pembayaran tidak ditemukan.', 404);
        }

        return $this->mutasiTagihan($userId, $pemilik['tagihan_id'], $kunci, self::OP_VERIFIKASI, $hash, function (array $t, array $tugas) use ($userId, $id, $kanal): array {
            $p = $this->repo->pembayaranTerkunci($id);
            if ($p === null || $p['tagihan_id'] !== $t['id']) {
                throw new BsaException('Pembayaran tidak ditemukan.', 404);
            }
            if ($p['status'] !== 'Menunggu') {
                throw new BsaException($p['status'] === 'Sah' ? 'Pembayaran ini sudah terverifikasi.' : 'Pembayaran ini sudah ditolak/dibatalkan.', 409);
            }
            if ($t['status'] !== 'Terbit' || $p['nominal'] > $t['total'] - $t['terbayar']) {
                throw new BsaException('Nominal transfer melebihi sisa tagihan saat ini. Tolak transfer ini dan catat ulang dengan nominal yang benar.');
            }
            $this->repo->verifikasiPembayaran($id, $userId);
            $sisa = $this->sahkan($t, $id, $p['nominal']);
            $this->bsa->wajibAudit('bsa.pembayaran.verifikasi', 'bsa_pembayaran', $id, [
                'pembayaran_id' => $id, 'tagihan_id' => $t['id'], 'santri_id' => $t['santri_id'], 'metode' => $p['sumber'], 'status' => 'Sah',
                'penugasan_id' => $tugas['id'], 'sumber' => BsaService::SUMBER_PETUGAS, 'kanal' => $this->bsa->kanal($kanal),
            ], $userId);
            $this->bsa->beritahuWali($t['santri_id'], BsaNotifikasi::EVENT_PEMBAYARAN, $id);
            $lama = $this->repo->pembayaran($id);

            return ['id' => $id, 'status' => 200, 'replayed' => false, 'nomor_kuitansi' => (string) ($lama['nomor_kuitansi'] ?? ''), 'status_pembayaran' => 'Sah', 'sisa' => $sisa];
        }, fn (int $hasilId): array => $this->hasilPembayaranLama($hasilId));
    }

    /**
     * Membatalkan pembayaran sah (sisa kembali naik) atau menolak transfer
     * menunggu, beralasan. Nominal dan nomor kuitansi tidak diubah; entri
     * penyeimbang menyimpan aktor, waktu, dan alasan.
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $input idempotency_key, alasan
     * @return array{id:int, status:int, replayed:bool, nomor_kuitansi:string, status_pembayaran:string, sisa:int}
     */
    public function batalkanPembayaran(array $user, int $id, array $input, string $kanal): array
    {
        $this->siap();
        $this->bsa->wajibKelola($user);
        $userId = (int) $user['id'];
        $alasan = $this->alasan($input['alasan'] ?? null);
        $kunci = $this->bsa->kunci($input['idempotency_key'] ?? null);
        $hash = $this->bsa->hash([self::OP_BAYAR_BATAL, $id, $alasan]);
        $pemilik = $id > 0 ? $this->repo->pemilikPembayaran($id) : null;
        if ($pemilik === null) {
            throw new BsaException('Pembayaran tidak ditemukan.', 404);
        }

        return $this->mutasiTagihan($userId, $pemilik['tagihan_id'], $kunci, self::OP_BAYAR_BATAL, $hash, function (array $t, array $tugas) use ($userId, $id, $alasan, $kunci, $kanal): array {
            $p = $this->repo->pembayaranTerkunci($id);
            if ($p === null || $p['tagihan_id'] !== $t['id']) {
                throw new BsaException('Pembayaran tidak ditemukan.', 404);
            }
            if ($p['status'] === 'Dibatalkan') {
                throw new BsaException('Pembayaran ini sudah dibatalkan. Tidak ada data yang diubah.', 409);
            }
            $this->repo->tandaiPembayaranBatal($id, $p['status']);
            if ($p['status'] === 'Sah') {
                $this->repo->ubahTerbayar($t['id'], $t['terbayar'], $t['terbayar'] - $p['nominal']);
            }
            $this->repo->simpanPembatalan($id, $p['status'], $alasan, $userId, $tugas['pengurus_id'], $tugas['id']);
            // Fase 3: pembayaran dari tabungan yang dibatalkan mengembalikan
            // saldo di transaksi yang sama (kunci akun sesudah tagihan dan
            // pembayaran), sebagai baris Pembalik beralasan atas debitnya.
            $pengembalian = null;
            if ($p['sumber'] === 'Tabungan' && $p['status'] === 'Sah' && $p['tabungan_transaksi_id'] !== null) {
                $pengembalian = $this->kembalikanKeTabungan($t, $p, $kunci, $alasan, $userId, $tugas);
            }
            $this->bsa->wajibAudit('bsa.pembayaran.batal', 'bsa_pembayaran', $id, [
                'pembayaran_id' => $id, 'tagihan_id' => $t['id'], 'santri_id' => $t['santri_id'], 'metode' => $p['sumber'], 'status' => 'Dibatalkan',
                'jenis' => $p['status'] === 'Sah' ? 'Pembatalan' : 'Penolakan', 'penugasan_id' => $tugas['id'], 'sumber' => BsaService::SUMBER_PETUGAS, 'kanal' => $this->bsa->kanal($kanal),
                'transaksi_id' => $pengembalian['id'] ?? null,
            ], $userId);
            // Penolakan transfer menunggu tidak pernah terlihat orang tua; hanya pembatalan pembayaran sah.
            if ($p['status'] === 'Sah') {
                $this->bsa->beritahuWali($t['santri_id'], BsaNotifikasi::EVENT_PEMBAYARAN_BATAL, $id);
            }
            $lama = $this->repo->pembayaran($id);

            return ['id' => $id, 'status' => 200, 'replayed' => false, 'nomor_kuitansi' => (string) ($lama['nomor_kuitansi'] ?? ''), 'status_pembayaran' => 'Dibatalkan',
                'sisa' => $t['total'] - ($p['status'] === 'Sah' ? $t['terbayar'] - $p['nominal'] : $t['terbayar'])]
                + ($pengembalian === null ? [] : ['transaksi_id' => $pengembalian['id'], 'saldo' => $pengembalian['saldo']]);
        }, function (int $hasilId): array {
            $p = $this->repo->pembayaran($hasilId);

            return $this->hasilPembayaranLama($hasilId)
                + (($p['sumber'] ?? null) !== 'Tabungan' ? [] : [
                    'transaksi_id' => (int) $p['pengembalian_id'],
                    'saldo' => (int) ($this->bsaRepo->santri((int) $p['santri_id'])['saldo'] ?? 0),
                ]);
        });
    }

    /**
     * Membayar tagihan dari tabungan santri — tindakan EKSPLISIT petugas BSA
     * dengan persetujuan tercentang (PRD V6 §3.4, §5.3). Satu transaksi:
     * penugasan → klaim → santri + cakupan → tagihan (tulis) → akun tabungan
     * (tulis); verifikasi sisa tagihan (dikurangi transfer menunggu) dan
     * saldo; debit ledger `PembayaranTagihan`/`Internal` lalu pembayaran
     * `Tabungan` sah yang menunjuk debit itu, terbayar naik, kuitansi
     * snapshot. Gagal di titik mana pun → rollback keduanya. Kiriman ulang
     * kunci sama → hasil sama (satu debit, satu pembayaran). Bukan kas baru:
     * uangnya sudah diterima saat setoran.
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $input idempotency_key, nominal, setuju, catatan
     * @return array{id:int, status:int, replayed:bool, nomor_kuitansi:string, status_pembayaran:string, sisa:int, transaksi_id:?int, saldo:int}
     */
    public function bayarDariTabungan(array $user, int $tagihanId, array $input, string $kanal): array
    {
        $this->siap();
        if (!$this->tabungan->skemaSiap()) {
            throw new BsaException('Modul tabungan lengkap (PRD V6 Fase 3) belum dipasang pada basis data ini.', 503);
        }
        $this->bsa->wajibKelola($user);
        $userId = (int) $user['id'];
        if (!in_array($input['setuju'] ?? null, [true, 1, '1', 'ya'], true)) {
            throw new BsaException('Centang persetujuan bahwa tabungan santri dipotong untuk membayar tagihan ini.');
        }
        $nominal = $this->bsa->nominal($input['nominal'] ?? null, 'Nominal pembayaran');
        $catatan = $this->bsa->teks($input['catatan'] ?? null, 500, 'Catatan');
        $kunci = $this->bsa->kunci($input['idempotency_key'] ?? null);
        $hash = $this->bsa->hash([self::OP_BAYAR_TABUNGAN, $tagihanId, $nominal, $catatan]);

        return $this->mutasiTagihan($userId, $tagihanId, $kunci, self::OP_BAYAR_TABUNGAN, $hash,
            function (array $t, array $tugas) use ($userId, $tagihanId, $nominal, $catatan, $kunci, $kanal): array {
                if ($t['status'] !== 'Terbit') {
                    throw new BsaException('Tagihan sudah dibatalkan; pembayaran tidak dicatat.');
                }
                $sisa = $t['total'] - $t['terbayar'];
                if ($nominal > $sisa - $t['menunggu']) {
                    throw new BsaException($t['menunggu'] > 0
                        ? 'Nominal melebihi sisa tagihan sesudah transfer yang menunggu verifikasi. Tabungan tidak dipotong.'
                        : 'Nominal melebihi sisa tagihan. Tabungan tidak dipotong.');
                }
                $akun = $this->bsaRepo->kunciAkun($t['santri_id']);
                $saldo = $akun['saldo'] ?? 0;
                if ($nominal > $saldo) {
                    throw new BsaException('Saldo tabungan tidak mencukupi (saldo ' . $this->rupiah($saldo) . '). Tabungan tidak dipotong dan tagihan tidak berubah.', 422);
                }
                $debit = $this->bsaRepo->catatTransaksi($t['santri_id'], [
                    'jenis' => 'PembayaranTagihan', 'arah' => 'Keluar', 'nominal' => $nominal, 'metode' => 'Internal',
                    'tujuan' => 'Pembayaran tagihan ' . $t['periode'], 'referensi' => null, 'catatan' => $catatan, 'idempotency_key' => $kunci,
                ], $saldo, $saldo - $nominal, $userId, $tugas['pengurus_id'], $tugas['id']);
                $simpan = $this->repo->simpanPembayaran($tagihanId, $t['santri_id'], [
                    'nominal' => $nominal, 'sumber' => 'Tabungan', 'tanggal_transaksi' => date('Y-m-d'), 'referensi' => null,
                    'catatan' => $catatan, 'idempotency_key' => $kunci, 'tabungan_transaksi_id' => $debit,
                ], true, $userId, $tugas['pengurus_id'], $tugas['id']);
                $sisaBaru = $this->sahkan($t, $simpan['id'], $nominal);
                $this->bsa->wajibAudit('bsa.pembayaran.tabungan', 'bsa_pembayaran', $simpan['id'], [
                    'pembayaran_id' => $simpan['id'], 'transaksi_id' => $debit, 'tagihan_id' => $tagihanId, 'santri_id' => $t['santri_id'], 'metode' => 'Tabungan',
                    'status' => 'Sah', 'penugasan_id' => $tugas['id'], 'sumber' => BsaService::SUMBER_PETUGAS, 'kanal' => $this->bsa->kanal($kanal),
                ], $userId);
                // Satu pemberitahuan pembayaran mewakili debit tabungan pasangannya.
                $this->bsa->beritahuWali($t['santri_id'], BsaNotifikasi::EVENT_PEMBAYARAN, $simpan['id']);

                return ['id' => $simpan['id'], 'status' => 201, 'replayed' => false, 'nomor_kuitansi' => $simpan['nomor'], 'status_pembayaran' => 'Sah',
                    'sisa' => $sisaBaru, 'transaksi_id' => $debit, 'saldo' => $saldo - $nominal];
            },
            function (int $hasilId): array {
                $b = $this->repo->pembayaran($hasilId);

                return $this->hasilPembayaranLama($hasilId) + ['transaksi_id' => $b['tabungan_transaksi_id'] === null ? null : (int) $b['tabungan_transaksi_id'],
                    'saldo' => (int) ($this->bsaRepo->santri((int) ($b['santri_id'] ?? 0))['saldo'] ?? 0)];
            });
    }

    /**
     * Data kuitansi satu pembayaran. Wali: hanya pembayaran sah/dibatalkan
     * anaknya, tanpa referensi dan catatan.
     *
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function pembayaran(array $user, int $id): array
    {
        $this->siap();
        $akses = $this->bsa->wajibBaca($user);
        $b = $id > 0 ? $this->repo->pembayaran($id) : null;
        $wali = $akses['sumber'] === BsaService::SUMBER_WALI;
        if ($b === null || ($wali && $b['status'] === 'Menunggu')) {
            throw new BsaException('Pembayaran tidak ditemukan.', 404);
        }
        try {
            $s = $this->bsa->santriTerbaca($user, $akses, (int) $b['santri_id']);
        } catch (BsaException) {
            throw new BsaException('Pembayaran tidak ditemukan.', 404);
        }

        return $this->ringkasPembayaran($b, $wali) + ['santri' => $this->bsa->identitas($s), 'periode' => (string) $b['periode'], 'akses' => $akses];
    }

    /**
     * Riwayat pembayaran menurut hak (petugas cakupan, admin semua, wali anak).
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $input periode, status, sumber, q, page
     * @return array{rows:array<int, array<string, mixed>>, total:int, page:int, per_page:int, akses:array<string, mixed>}
     */
    public function daftarPembayaran(array $user, array $input): array
    {
        $this->siap();
        $akses = $this->bsa->wajibBaca($user);
        $wali = $akses['sumber'] === BsaService::SUMBER_WALI;
        $status = in_array($input['status'] ?? null, ['Menunggu', 'Sah', 'Dibatalkan'], true) ? (string) $input['status'] : null;
        if ($wali && $status === 'Menunggu') {
            $status = 'Sah';
        }
        $saring = [
            'periode' => $this->periodeOpsional($input['periode'] ?? null),
            'status' => $status,
            'sumber' => in_array($input['sumber'] ?? null, ['Tunai', 'Transfer', 'Tabungan'], true) ? (string) $input['sumber'] : null,
        ];
        $hasil = $this->repo->daftarPembayaran($this->hak($user, $akses), $saring, PageQuery::term($input['q'] ?? ''), $this->bsa->halaman($input['page'] ?? 1), $wali);
        $rows = array_values(array_filter($hasil['rows'], static fn (array $b): bool => !$wali || $b['status'] !== 'Menunggu'));

        return [
            'rows' => array_map(fn (array $b): array => $this->ringkasPembayaran($b, $wali) + ['santri' => ['id' => (int) $b['santri_id'], 'nis' => (string) $b['nis'], 'nama' => (string) $b['nama_santri']],
                'periode' => (string) $b['periode']], $rows),
            'total' => (int) $hasil['total'], 'page' => (int) $hasil['page'], 'per_page' => (int) $hasil['perPage'], 'saring' => $saring, 'akses' => $akses,
        ];
    }

    /**
     * Daftar tunggakan (tagihan Terbit bersisa) menurut hak.
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $input periode, unit, lewat, q, page
     * @return array<string, mixed>
     */
    public function tunggakan(array $user, array $input): array
    {
        $this->siap();
        $akses = $this->bsa->wajibBaca($user);
        $unit = null;
        if (($input['unit'] ?? '') !== '' && ($input['unit'] ?? null) !== null) {
            $unit = $this->unitKanonik($input['unit']);
        }
        $saring = ['periode' => $this->periodeOpsional($input['periode'] ?? null), 'unit' => $unit, 'lewat' => in_array($input['lewat'] ?? null, ['1', 1, true], true)];
        $hasil = $this->repo->tunggakan($this->hak($user, $akses), $saring, PageQuery::term($input['q'] ?? ''), $this->bsa->halaman($input['page'] ?? 1));
        $hariIni = date('Y-m-d');
        // Fase 4: jumlah transfer menunggu tidak dikirim kepada orang tua (sama dengan rincian tagihan).
        $wali = $akses['sumber'] === BsaService::SUMBER_WALI;

        return [
            'rows' => array_map(static fn (array $r): array => [
                'tagihan_id' => (int) $r['id'],
                'santri' => ['id' => (int) $r['santri_id'], 'nis' => (string) $r['nis'], 'nama' => (string) $r['nama_santri'],
                    'kelas' => $r['nama_kelas'] === null ? null : (string) $r['nama_kelas'], 'unit' => $r['jenjang'] === null ? null : (string) $r['jenjang']],
                'periode' => (string) $r['periode'],
                'jatuh_tempo' => (string) $r['jatuh_tempo'],
                'lewat_jatuh_tempo' => (string) $r['jatuh_tempo'] < $hariIni,
                'total' => (int) $r['total'],
                'terbayar' => (int) $r['terbayar'],
                'sisa' => (int) $r['sisa'],
                'menunggu_verifikasi' => $wali ? 0 : (int) $r['menunggu'],
            ], $hasil['rows']),
            'ringkasan' => $hasil['ringkasan'],
            'total' => (int) $hasil['total'], 'page' => (int) $hasil['page'], 'per_page' => (int) $hasil['perPage'],
            'saring' => $saring, 'unit' => $this->unitAkses($akses), 'akses' => $akses,
        ];
    }

    /**
     * Unit yang tampil sebagai pilihan: petugas = cakupannya, lainnya = semua.
     *
     * @param array{sumber:?string, jenjang:?array<int, string>} $akses
     * @return array<int, string>
     */
    public function unitAkses(array $akses): array
    {
        $semua = $this->repo->daftarUnit();
        if ($akses['sumber'] !== BsaService::SUMBER_PETUGAS || $akses['jenjang'] === null) {
            return $semua;
        }

        return array_values(array_filter($semua, fn (string $u): bool => $this->bsa->unitTercakup($u, $akses['jenjang'])));
    }

    // =======================================================================

    private function siap(): void
    {
        if (!$this->repo->skemaSiap()) {
            throw new BsaException('Modul tagihan bulanan lengkap (PRD V6 Fase 2) belum dipasang pada basis data ini.', 503);
        }
    }

    /**
     * Pembaca internal (petugas atau admin); wali dan lainnya 403.
     *
     * @return array{baca:bool, kelola:bool, sumber:?string, jenjang:?array<int, string>}
     */
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
     * Mutasi atas satu tagihan dengan urutan kunci tetap: penugasan → klaim
     * → santri pemilik (cakupan) → tagihan. Replay mengembalikan hasil lama
     * hanya sesudah cakupan dan status santri diperiksa ulang.
     *
     * @param callable(array, array): array $kerja
     * @param (callable(int): array)|null $replay
     * @return array<string, mixed>
     */
    private function mutasiTagihan(int $userId, int $tagihanId, string $kunci, string $operasi, string $hash, callable $kerja, ?callable $replay = null): array
    {
        $santriId = $tagihanId > 0 ? $this->repo->santriTagihan($tagihanId) : null;
        if ($santriId === null) {
            throw new BsaException('Tagihan tidak ditemukan.', 404);
        }

        return $this->bsaRepo->transaction(function () use ($userId, $tagihanId, $santriId, $kunci, $operasi, $hash, $kerja, $replay): array {
            $penugasan = $this->bsa->penugasanTerkunci($userId);
            $klaim = $this->bsa->klaim($userId, $kunci, $operasi, $hash);
            [, $tugas] = $this->bsa->santriDalamCakupan($santriId, $penugasan);
            if ($klaim['hasil_id'] !== null) {
                $lama = $replay === null ? ['id' => $klaim['hasil_id']] : $replay($klaim['hasil_id']);

                return $lama + ['id' => $klaim['hasil_id'], 'status' => 200, 'replayed' => true, 'versi' => (int) ($this->repo->tagihanTerkunci($tagihanId)['versi'] ?? 0)];
            }
            $t = $this->repo->tagihanTerkunci($tagihanId);
            if ($t === null || $t['santri_id'] !== $santriId) {
                throw new BsaException('Tagihan tidak ditemukan.', 404);
            }
            $hasil = $kerja($t, $tugas);
            $this->bsaRepo->selesaikanIdempotensi($klaim['id'], (int) $hasil['id'], (int) $hasil['status']);

            return $hasil;
        });
    }

    /**
     * Pembayaran menjadi sah: terbayar naik bersyarat dan snapshot kuitansi
     * disimpan sekali. Mengembalikan sisa sesudah pembayaran.
     *
     * @param array{id:int, total:int, terbayar:int, versi:int} $t
     */
    private function sahkan(array $t, int $pembayaranId, int $nominal): int
    {
        $baru = $t['terbayar'] + $nominal;
        $this->repo->ubahTerbayar($t['id'], $t['terbayar'], $baru);
        $this->repo->simpanKuitansi($pembayaranId, $t['versi'], $t['total'], $t['terbayar'], $t['total'] - $baru);

        return $t['total'] - $baru;
    }

    /**
     * Pengembalian saldo atas pembayaran dari tabungan yang dibatalkan: baris
     * `Pembalik`/`Masuk`/`Internal` beralasan atas debit asal, di transaksi
     * pembatalan yang sama. Kunci unik `pembalik_dari_id` menjamin satu
     * pengembalian per debit.
     *
     * @param array{id:int, periode:string, santri_id:int} $t
     * @param array{nominal:int, tabungan_transaksi_id:?int} $p
     * @param array{id:int, pengurus_id:int} $tugas
     * @return array{id:int, saldo:int}
     */
    private function kembalikanKeTabungan(array $t, array $p, string $kunci, string $alasan, int $userId, array $tugas): array
    {
        $akun = $this->bsaRepo->kunciAkun($t['santri_id']);
        if ($akun === null) {
            throw new BsaException('Akun tabungan santri tidak ditemukan. Tidak ada data yang diubah.', 409);
        }
        $baru = $akun['saldo'] + $p['nominal'];
        try {
            $id = $this->bsaRepo->catatTransaksi($t['santri_id'], [
                'jenis' => 'Pembalik', 'arah' => 'Masuk', 'nominal' => $p['nominal'], 'metode' => 'Internal',
                'tujuan' => 'Pengembalian pembayaran tagihan ' . $t['periode'], 'referensi' => null, 'catatan' => null,
                'pembalik_dari_id' => $p['tabungan_transaksi_id'], 'alasan' => $alasan, 'idempotency_key' => $kunci,
            ], $akun['saldo'], $baru, $userId, $tugas['pengurus_id'], $tugas['id']);
        } catch (BsaException $exception) {
            if ($exception->errno === 1062) {
                throw new BsaException('Debit tabungan ini sudah dikembalikan. Tidak ada data yang diubah.', 409);
            }
            throw $exception;
        }

        return ['id' => $id, 'saldo' => $baru];
    }

    private function rupiah(int $n): string
    {
        return 'Rp' . number_format($n, 0, ',', '.');
    }

    /** @return array<string, mixed> */
    private function hasilPembayaranLama(int $id): array
    {
        $b = $this->repo->pembayaran($id);

        // Replay mengembalikan status pembayaran dan sisa tagihan saat ini.
        // Transfer menunggu belum punya kuitansi; kuitansi pembayaran yang
        // dibatalkan menyimpan sisa historis sebelum pembatalan, bukan sisa
        // kini. Snapshot cetak tetap dibaca terpisah dan tidak diubah.
        return ['nomor_kuitansi' => (string) ($b['nomor_kuitansi'] ?? ''), 'status_pembayaran' => (string) ($b['status'] ?? ''), 'sisa' => (int) ($b['sisa_kini'] ?? 0)];
    }

    /**
     * Rencana penerbitan deterministik (urut ID santri) beserta hash.
     *
     * @param array{periode:string, jatuh_tempo:string, unit:?string} $data
     * @param array<int, string>|null $cakupan
     * @param array<int, array<string, mixed>>|null $sasaran
     * @return array{rows:array<int, array<string, mixed>>, jumlah:array{Terbit:int, SudahAda:int, Dilewati:int}, total_terbit:int, hash:string}
     */
    private function rencana(array $data, ?array $cakupan, ?array $sasaran): array
    {
        $sasaran ??= $this->repo->sasaranPenerbitan($cakupan, $data['unit']);
        $ids = array_column($sasaran, 'id');
        $tarif = $this->repo->tarifEfektif($data['periode']);
        $ada = $this->repo->tagihanPeriode($ids, $data['periode']);
        $berlaku = $this->repo->penyesuaianBerlaku($ids, $data['periode']);
        $rows = [];
        $jumlah = ['Terbit' => 0, 'SudahAda' => 0, 'Dilewati' => 0];
        $totalTerbit = 0;
        foreach ($sasaran as $s) {
            $r = ['santri_id' => $s['id'], 'nis' => $s['nis'], 'nama' => $s['nama'], 'kelas' => $s['kelas'], 'unit' => $s['jenjang'],
                'hasil' => 'Dilewati', 'keterangan' => null, 'tarif_id' => null, 'tagihan_id' => null, 'total' => null, 'penyesuaian' => []];
            $t = $s['jenjang'] === null ? null : ($tarif[mb_strtolower($s['jenjang'])] ?? null);
            if (isset($ada[$s['id']])) {
                $r['hasil'] = 'SudahAda';
                $r['tagihan_id'] = $ada[$s['id']]['id'];
                $r['keterangan'] = $ada[$s['id']]['status'] === 'Dibatalkan' ? 'Tagihan periode ini sudah ada (dibatalkan)' : 'Tagihan periode ini sudah ada';
            } elseif ($s['jenjang'] === null) {
                $r['keterangan'] = 'Belum ditempatkan di kelas';
            } elseif ($t === null) {
                $r['keterangan'] = 'Belum ada tarif unit untuk periode ini';
            } else {
                $total = $t['total'];
                foreach ($berlaku[$s['id']] ?? [] as $j) {
                    $total += $j['jenis'] === 'Tambahan' ? $j['nominal'] : -$j['nominal'];
                    $r['penyesuaian'][] = ['id' => $j['id'], 'jenis' => $j['jenis'], 'nama' => $j['nama'], 'nominal' => $j['nominal'], 'alasan' => $j['alasan']];
                }
                $r['tarif_id'] = $t['id'];
                if ($total < 1) {
                    $r['keterangan'] = 'Dibebaskan penuh oleh penyesuaian';
                } elseif ($total > BsaService::NOMINAL_MAKS) {
                    $r['keterangan'] = 'Total melebihi batas Rp1.000.000.000';
                } else {
                    $r['hasil'] = 'Terbit';
                    $r['total'] = $total;
                    $totalTerbit += $total;
                }
            }
            $jumlah[$r['hasil']]++;
            $rows[] = $r;
        }
        $sidik = array_map(static fn (array $r): array => [$r['santri_id'], $r['hasil'], $r['unit'], $r['tarif_id'], $r['tagihan_id'], $r['total'], array_column($r['penyesuaian'], 'id')], $rows);
        $hash = hash('sha256', (string) json_encode([$data['periode'], $data['jatuh_tempo'], $data['unit'], $cakupan, $sidik], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return ['rows' => $rows, 'jumlah' => $jumlah, 'total_terbit' => $totalTerbit, 'hash' => $hash];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{periode:string, jatuh_tempo:string, unit:?string}
     */
    private function normalisasiPenerbitan(array $input): array
    {
        $periode = $this->bsa->periode($input['periode'] ?? null);
        $jatuhTempo = $this->bsa->tanggal($input['jatuh_tempo'] ?? null, 'Jatuh tempo');
        $awal = new DateTimeImmutable($periode . '-01');
        if ($jatuhTempo < $awal->format('Y-m-d') || $jatuhTempo > $awal->modify('+' . self::JATUH_TEMPO_BULAN_MAKS . ' months')->format('Y-m-d')) {
            throw new BsaException('Jatuh tempo wajib antara awal periode dan ' . self::JATUH_TEMPO_BULAN_MAKS . ' bulan sesudahnya.');
        }
        $unit = ($input['unit'] ?? '') === '' || ($input['unit'] ?? null) === null ? null : $this->unitKanonik($input['unit']);

        return ['periode' => $periode, 'jatuh_tempo' => $jatuhTempo, 'unit' => $unit];
    }

    /**
     * Saringan unit wajib dalam cakupan; tanpa saringan = seluruh unit dalam
     * cakupan petugas (sasaran tetap dibatasi cakupan oleh repository).
     *
     * @param array<int, string>|null $cakupan
     */
    private function wajibUnitDalamCakupan(?string $unit, ?array $cakupan): void
    {
        if ($unit !== null && !$this->bsa->unitTercakup($unit, $cakupan)) {
            throw new BsaException('Unit ini di luar cakupan penugasan BSA Anda.', 404);
        }
    }

    /** Nama unit kanonik dari kelas aktif; unit tidak dikenal ditolak. */
    private function unitKanonik(mixed $value): string
    {
        if (is_string($value) && trim($value) !== '') {
            foreach ($this->repo->daftarUnit() as $u) {
                if (mb_strtolower($u) === mb_strtolower(trim($value))) {
                    return $u;
                }
            }
        }
        throw new BsaException('Unit/jenjang wajib dipilih dari unit kelas yang aktif.');
    }

    /**
     * Penugasan yang mencakup unit (seluruh unit diutamakan).
     *
     * @param array<int, array{id:int, pengurus_id:int, jenjang:?string}> $penugasan
     * @return array{id:int, pengurus_id:int, jenjang:?string}|null
     */
    private function penugasanUnit(array $penugasan, string $unit): ?array
    {
        foreach ($penugasan as $p) {
            if ($p['jenjang'] === null) {
                return $p;
            }
        }
        foreach ($penugasan as $p) {
            if ($this->bsa->unitTercakup($unit, [$p['jenjang']])) {
                return $p;
            }
        }

        return null;
    }

    /**
     * @return array{0:array<int, array{nama:string, nominal:int}>, 1:int}
     */
    private function komponen(mixed $mentah, string $label): array
    {
        if (!is_array($mentah)) {
            throw new BsaException('Komponen ' . strtolower($label) . ' wajib diisi.');
        }
        $komponen = [];
        $total = 0;
        foreach (array_values($mentah) as $i => $k) {
            if (!is_array($k)) {
                throw new BsaException('Komponen tidak valid.');
            }
            $nama = $this->bsa->teks($k['nama'] ?? null, 100, 'Nama komponen');
            $kosongNominal = ($k['nominal'] ?? '') === '' || ($k['nominal'] ?? null) === null;
            if ($nama === null && $kosongNominal) {
                continue;
            }
            if ($nama === null) {
                throw new BsaException('Nama komponen ke-' . ($i + 1) . ' wajib diisi.');
            }
            $nominal = $this->bsa->nominal($k['nominal'] ?? null, 'Nominal komponen ke-' . ($i + 1));
            $komponen[] = ['nama' => $nama, 'nominal' => $nominal];
            $total += $nominal;
        }
        if ($komponen === []) {
            throw new BsaException($label . ' wajib memuat paling sedikit satu komponen biaya.');
        }
        if (count($komponen) > BsaService::KOMPONEN_MAKS) {
            throw new BsaException($label . ' paling banyak memuat ' . BsaService::KOMPONEN_MAKS . ' komponen.');
        }
        if ($total > BsaService::NOMINAL_MAKS) {
            throw new BsaException('Total ' . strtolower($label) . ' melebihi batas Rp1.000.000.000.');
        }

        return [$komponen, $total];
    }

    private function alasan(mixed $value): string
    {
        $alasan = $this->bsa->teks($value, 500, 'Alasan');
        if ($alasan === null || mb_strlen($alasan) < 5) {
            throw new BsaException('Alasan wajib diisi (minimal 5 karakter).');
        }

        return $alasan;
    }

    private function versi(mixed $value): int
    {
        if (!is_scalar($value) || !preg_match('/^[1-9][0-9]{0,8}$/D', (string) $value)) {
            throw new BsaException('Versi tagihan tidak valid; muat ulang halaman.');
        }

        return (int) $value;
    }

    /** @param array{versi:int} $t */
    private function wajibVersi(array $t, int $versi): void
    {
        if ($t['versi'] !== $versi) {
            throw new BsaException('Tagihan sudah berubah (versi ' . $t['versi'] . '). Tidak ada data yang diubah; muat ulang lalu tinjau sebelum mengulang.', 409);
        }
    }

    private function periodeOpsional(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^(20[0-9]{2})-(0[1-9]|1[0-2])$/D', $value) ? $value : null;
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

    /**
     * @param array<string, mixed> $b
     * @return array<string, mixed>
     */
    private function ringkasPenerbitan(array $b): array
    {
        return [
            'id' => (int) $b['id'],
            'periode' => (string) $b['periode'],
            'unit' => $b['jenjang'] === null ? null : (string) $b['jenjang'],
            'jatuh_tempo' => (string) $b['jatuh_tempo'],
            'jumlah' => ['sasaran' => (int) $b['jumlah_sasaran'], 'terbit' => (int) $b['jumlah_terbit'], 'sudah_ada' => (int) $b['jumlah_sudah_ada'], 'dilewati' => (int) $b['jumlah_dilewati']],
            'pembuat' => ['nama' => (string) $b['pembuat_nama']],
            'waktu' => (string) $b['created_at'],
        ];
    }

    /**
     * Referensi transfer, catatan internal, dan alasan pembatalan tidak
     * dikirim kepada orang tua (PRD V6 §5.4).
     *
     * @param array<string, mixed> $b
     * @return array<string, mixed>
     */
    private function ringkasPembayaran(array $b, bool $wali): array
    {
        $baris = [
            'id' => (int) $b['id'],
            'tagihan_id' => (int) $b['tagihan_id'],
            'nomor_kuitansi' => (string) $b['nomor_kuitansi'],
            'nominal' => (int) $b['nominal'],
            'sumber' => (string) $b['sumber'],
            'status' => (string) $b['status'],
            'tanggal_transaksi' => (string) $b['tanggal_transaksi'],
            'referensi' => $b['referensi'] === null ? null : (string) $b['referensi'],
            'catatan' => $b['catatan'] === null ? null : (string) $b['catatan'],
            'pencatat' => ['nama' => (string) $b['pencatat_nama']],
            'verifikasi' => $b['diverifikasi_pada'] === null ? null : ['oleh' => (string) $b['verifikator_nama'], 'pada' => (string) $b['diverifikasi_pada']],
            'kuitansi' => $b['tagihan_versi'] === null ? null : [
                'tagihan_versi' => (int) $b['tagihan_versi'], 'total_tagihan' => (int) $b['total_tagihan'],
                'terbayar_sebelum' => (int) $b['terbayar_sebelum'], 'sisa_sesudah' => (int) $b['sisa_sesudah'],
            ],
            'pembatalan' => $b['batal_jenis'] === null ? null : ['jenis' => (string) $b['batal_jenis'], 'alasan' => (string) $b['batal_alasan'],
                'oleh' => (string) $b['pembatal_nama'], 'pada' => (string) $b['batal_pada']],
            'dicatat_pada' => (string) $b['created_at'],
            // Fase 3: referensi silang ke debit tabungan (dan pengembaliannya).
            'tabungan' => ($b['tabungan_transaksi_id'] ?? null) === null ? null : [
                'transaksi_id' => (int) $b['tabungan_transaksi_id'],
                'nomor_kuitansi' => BsaService::nomorKuitansiTabungan((int) $b['tabungan_transaksi_id'], (string) $b['debit_dibuat']),
                'pengembalian_id' => $b['pengembalian_id'] === null ? null : (int) $b['pengembalian_id'],
            ],
        ];
        if ($wali) {
            unset($baris['referensi'], $baris['catatan']);
            if ($baris['pembatalan'] !== null) {
                $baris['pembatalan'] = ['jenis' => $baris['pembatalan']['jenis'], 'pada' => $baris['pembatalan']['pada']];
            }
        }

        return $baris;
    }
}
