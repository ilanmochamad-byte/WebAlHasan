<?php

declare(strict_types=1);

namespace App\Bsa;

use App\Database\PageQuery;
use DateTimeImmutable;

/**
 * Laporan keuangan santri/BSA — PRD V6 Fase 5 (persyaratan 1–2). Satu layanan
 * dipakai halaman `portal/bsa_laporan.php`, ekspor `portal/bsa_laporan_ekspor.php`,
 * API `GET /api/v1/v6/bsa/laporan[/ekspor]`, dan layar aplikasi, sehingga
 * angka dan hak ketiganya identik.
 *
 * Hak dihitung ulang setiap panggilan lewat `BsaService::wajibBaca()`:
 *   - petugas BSA: santri AKTIF dalam cakupan unit penugasannya;
 *   - admin (termasuk admin yang juga petugas): seluruh santri, hanya baca,
 *     ditambah daftar santri nonaktif yang masih bersaldo/bertunggakan (R-10);
 *   - orang tua: hanya anak dengan relasi `santri_wali` aktif — tanpa kas,
 *     perpindahan internal, daftar koreksi beralasan, transfer menunggu,
 *     maupun pemeriksaan selisih internal;
 *   - lainnya 403; santri di luar hak 404.
 *
 * Isi laporan per periode (bulan dari..sampai, paling panjang 12 bulan, tidak
 * di masa depan) dan per santri:
 *   - TAGIHAN menurut periode tagihan: terbit, dibatalkan, terbayar menurut
 *     sumber, sisa, lunas, lewat jatuh tempo;
 *   - TABUNGAN menurut waktu transaksi sah: saldo awal, setoran, penarikan,
 *     belanja, pembayaran tagihan dari tabungan, koreksi, pengembalian, saldo
 *     akhir;
 *   - KAS (internal): uang nyata tunai/transfer masuk/keluar. Pembayaran
 *     tagihan dari tabungan dan pengembaliannya adalah PERPINDAHAN INTERNAL
 *     dan tidak pernah menambah penerimaan kas baru (rumus sama dengan
 *     rekonsiliasi harian Fase 3);
 *   - PEMBATALAN dan koreksi beserta aktor, waktu, alasan, dan asal;
 *   - SELISIH rekonsiliasi yang wajib Rp0 (tagihan = Σ pembayaran sah,
 *     saldo awal + mutasi = akhir, debit tabungan = pembayaran dari tabungan,
 *     pengembalian = pembatalan pembayaran tabungan, akun = ledger per santri).
 * Seluruh angka dibaca dalam satu snapshot konsisten. Laporan tidak
 * memutasi data; ekspor CSV dicatat di `audit_logs` tanpa nominal.
 */
final class LaporanService
{
    public const EKSPOR = ['santri', 'transaksi', 'koreksi'];
    public const KOREKSI_TAMPIL = 200;
    public const RENTANG_MAKS_BULAN = 12;

    public function __construct(
        private BsaService $bsa,
        private TagihanService $tagihan,
        private TabunganService $tabungan,
        private LaporanRepository $repo
    ) {
    }

    /** Laporan memerlukan tagihan DAN tabungan lengkap (027–029): keduanya dirilis bersama. */
    public function tersedia(): bool
    {
        return $this->tagihan->tersedia() && $this->tabungan->tersedia();
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $input dari, sampai (TTTT-BB), santri, unit, q, page
     * @return array<string, mixed>
     */
    public function laporan(array $user, array $input): array
    {
        [$akses, $hak, $r, $f, $santri] = $this->persiapan($user, $input);
        $wali = $hak['mode'] === 'wali';
        $admin = in_array($akses['sumber'], [BsaService::SUMBER_ADMIN, BsaService::SUMBER_ADMIN_PETUGAS], true);
        $q = $wali ? '' : PageQuery::term($input['q'] ?? '');
        $page = $this->bsa->halaman($input['page'] ?? 1);

        $m = $this->repo->snapshot(fn (): array => [
            'tagihan' => $this->repo->tagihan($hak, $f, $r['dari'], $r['sampai'], $r['hari_ini']),
            'bayar_tagihan' => $this->repo->pembayaranTagihan($hak, $f, $r['dari'], $r['sampai']),
            'tagihan_bulanan' => $this->repo->tagihanBulanan($hak, $f, $r['dari'], $r['sampai']),
            'ledger' => $this->repo->ledgerBulanan($hak, $f, $r['mulai'], $r['akhir']),
            'bayar' => $this->repo->bayarBulanan($hak, $f, $r['mulai'], $r['akhir']),
            'batal' => $this->repo->batalBulanan($hak, $f, $r['mulai'], $r['akhir']),
            'saldo_awal' => $this->repo->saldoSebelum($hak, $f, $r['mulai']),
            'saldo_akhir' => $this->repo->saldoSebelum($hak, $f, $r['akhir']),
            'menunggu' => $wali ? null : $this->repo->menunggu($hak, $f, $r['dari'], $r['sampai']),
            'koreksi_lain' => $this->repo->koreksiLain($hak, $f, $r['mulai'], $r['akhir']),
            'koreksi' => $wali ? [] : $this->repo->koreksiDaftar($hak, $f, $r['mulai'], $r['akhir'], self::KOREKSI_TAMPIL + 1),
            'per_santri' => $this->repo->perSantri($hak, $f, $r['dari'], $r['sampai'], $r['mulai'], $r['akhir'], $q, $page),
            'nonaktif' => $admin && $f['unit'] === null ? $this->repo->nonaktifBersaldo($f['santri_id']) : null,
        ]);

        $tab = $this->tabunganMenurutJenis($m['ledger']);
        $bayar = $this->menurutSumber($m['bayar']);
        $batal = $this->menurutSumber($m['batal']);
        $kas = $this->kas($tab, $bayar, $batal);
        $mutasi = 0;
        foreach ($m['ledger'] as $x) {
            $mutasi += $x['arah'] === 'Masuk' ? $x['nominal'] : -$x['nominal'];
        }
        $bayarSah = ['Tunai' => 0, 'Transfer' => 0, 'Tabungan' => 0];
        $bayarBatal = ['Tunai' => 0, 'Transfer' => 0, 'Tabungan' => 0];
        foreach ($m['bayar_tagihan'] as $b) {
            if ($b['status'] === 'Sah') {
                $bayarSah[$b['sumber']] = ($bayarSah[$b['sumber']] ?? 0) + $b['nominal'];
            } elseif ($b['status'] === 'Dibatalkan') {
                $bayarBatal[$b['sumber']] = ($bayarBatal[$b['sumber']] ?? 0) + $b['nominal'];
            }
        }
        $t = $m['tagihan'];
        $kl = $m['koreksi_lain'];
        $ps = $m['per_santri'];
        $internal = [
            'debit_tabungan' => $tab['PembayaranTagihan']['Internal'] ?? 0,
            'pembayaran_dari_tabungan' => $bayar['Tabungan'],
            'pengembalian_ke_tabungan' => $tab['Pengembalian'],
            'pembatalan_pembayaran_tabungan' => $batal['Tabungan'],
        ];
        $internal['selisih'] = $internal['debit_tabungan'] - $internal['pembayaran_dari_tabungan'];
        $internal['selisih_pengembalian'] = $internal['pengembalian_ke_tabungan'] - $internal['pembatalan_pembayaran_tabungan'];
        $selisih = [
            'tagihan_terbayar' => $t['terbayar'] - array_sum($bayarSah),
            'tagihan_baris' => $t['selisih'],
            'saldo' => $m['saldo_awal'] + $mutasi - $m['saldo_akhir'],
            'internal' => $internal['selisih'],
            'pengembalian' => $internal['selisih_pengembalian'],
            'santri_saldo' => $ps['jumlah']['santri_selisih_saldo'] ?? 0,
            'santri_akun' => $ps['jumlah']['santri_selisih_akun'] ?? 0,
        ];
        $selisih['nol'] = array_filter($selisih, static fn (int $n): bool => $n !== 0) === [];

        $hasil = [
            'rentang' => ['dari' => $r['dari'], 'sampai' => $r['sampai'], 'tanggal_awal' => substr($r['mulai'], 0, 10), 'tanggal_akhir' => $r['tanggal_akhir'],
                'hari_ini' => $r['hari_ini'], 'bulan' => $r['bulan']],
            'saring' => ['santri_id' => $f['santri_id'], 'unit' => $f['unit'], 'q' => $q],
            'santri' => $santri === null ? null : $this->bsa->identitas($santri),
            'tagihan' => [
                'terbit' => ['jumlah' => $t['terbit_jumlah'], 'nominal' => $t['terbit_nominal']],
                'dibatalkan' => ['jumlah' => $t['batal_jumlah'], 'nominal' => $t['batal_nominal']],
                'terbayar' => $t['terbayar'],
                'sisa' => $t['terbit_nominal'] - $t['terbayar'],
                'lunas' => $t['lunas'],
                'belum_lunas' => $t['belum_lunas'],
                'lewat_jatuh_tempo' => ['jumlah' => $t['lewat_jumlah'], 'sisa' => $t['lewat_sisa']],
                'santri' => $t['santri'],
                'pembayaran_sah' => $bayarSah,
                'pembayaran_dibatalkan' => $bayarBatal,
                'menunggu_verifikasi' => $m['menunggu']['pembayaran'] ?? null,
            ],
            'tabungan' => [
                'saldo_awal' => $m['saldo_awal'],
                'setoran' => $this->caraKas($tab['Setoran']),
                'penarikan' => $this->caraKas($tab['Penarikan']),
                'belanja' => $this->caraKas($tab['Belanja']),
                'pembayaran_tagihan' => $internal['debit_tabungan'],
                'koreksi_masuk' => $this->caraKas($tab['KoreksiMasuk']),
                'koreksi_keluar' => $this->caraKas($tab['KoreksiKeluar']),
                'pengembalian' => $tab['Pengembalian'],
                'mutasi_bersih' => $mutasi,
                'saldo_akhir' => $m['saldo_akhir'],
                'setoran_menunggu' => $m['menunggu']['setoran'] ?? null,
            ],
            'kas' => $wali ? null : $kas,
            'penerimaan_kas_baru' => $wali ? null : $kas['Tunai']['setoran'] + $kas['Tunai']['pembayaran_tagihan'] + $kas['Transfer']['setoran'] + $kas['Transfer']['pembayaran_tagihan'],
            'internal' => $wali ? null : $internal,
            'pembatalan' => [
                'pembayaran' => $batal,
                'koreksi_tabungan' => ['masuk' => array_sum($this->caraKas($tab['KoreksiMasuk'])), 'keluar' => array_sum($this->caraKas($tab['KoreksiKeluar'])),
                    'jumlah' => $tab['jumlah_koreksi']],
                'tagihan_dibatalkan' => ['jumlah' => $kl['tagihan_batal_jumlah'], 'nominal' => $kl['tagihan_batal_nominal']],
                'tagihan_direvisi' => $kl['tagihan_revisi_jumlah'],
                'penolakan_transfer_pembayaran' => $wali ? null : ['jumlah' => $kl['penolakan_bayar_jumlah'], 'nominal' => $kl['penolakan_bayar_nominal']],
                'setoran_transfer_ditolak' => $wali ? null : ['jumlah' => $kl['setoran_ditolak_jumlah'], 'nominal' => $kl['setoran_ditolak_nominal']],
            ],
            'koreksi' => $wali ? null : [
                'rows' => array_map(fn (array $k): array => $this->ringkasKoreksi($k), array_slice($m['koreksi'], 0, self::KOREKSI_TAMPIL)),
                'terpotong' => count($m['koreksi']) > self::KOREKSI_TAMPIL,
            ],
            'per_bulan' => $this->perBulan($r['bulan'], $m),
            'per_santri' => [
                'rows' => array_map(fn (array $s): array => $this->ringkasSantri($s, $wali), $ps['rows']),
                'total' => (int) $ps['total'], 'page' => (int) $ps['page'], 'per_page' => (int) $ps['perPage'],
                'jumlah' => $wali ? array_diff_key($ps['jumlah'], array_flip(['selisih_saldo', 'selisih_akun', 'santri_selisih_saldo', 'santri_selisih_akun'])) : $ps['jumlah'],
            ],
            'nonaktif' => $m['nonaktif'] === null ? null : [
                'jumlah' => count($m['nonaktif']),
                'saldo' => array_sum(array_column($m['nonaktif'], 'saldo')),
                'sisa' => array_sum(array_column($m['nonaktif'], 'sisa')),
                'rows' => array_map(static fn (array $s): array => [
                    'santri' => ['id' => (int) $s['id'], 'nis' => (string) $s['nis'], 'nama' => (string) $s['nama_santri'], 'aktif' => false],
                    'saldo' => $s['saldo'], 'sisa' => $s['sisa'],
                ], array_slice($m['nonaktif'], 0, 100)),
            ],
            'selisih' => $wali ? null : $selisih,
            'akses' => $akses,
        ];

        return $hasil;
    }

    /**
     * Data ekspor CSV menurut hak yang sama dengan laporan. Ekspor yang
     * melebihi `LaporanRepository::EKSPOR_MAKS` baris ditolak (bukan dipotong).
     * Setiap ekspor dicatat di `audit_logs` (`bsa.laporan.ekspor`: jenis,
     * periode, sumber hak, kanal, santri bila disaring — tanpa nominal).
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $input jenis (santri|transaksi|koreksi), dari, sampai, santri, unit
     * @return array{nama_berkas:string, kolom:array<int, string>, baris:array<int, array<int, int|string>>}
     */
    public function ekspor(array $user, array $input, string $kanal): array
    {
        $jenis = in_array($input['jenis'] ?? null, self::EKSPOR, true) ? (string) $input['jenis'] : null;
        if ($jenis === null) {
            throw new BsaException('Jenis ekspor tidak dikenal (santri, transaksi, atau koreksi).');
        }
        [$akses, $hak, $r, $f] = $this->persiapan($user, $input);
        $wali = $hak['mode'] === 'wali';
        if ($wali && $jenis === 'koreksi') {
            throw new BsaException('Ekspor koreksi beralasan hanya untuk petugas BSA dan admin.', 403);
        }
        $rows = $this->repo->snapshot(fn (): array => match ($jenis) {
            'santri' => $this->repo->perSantriSemua($hak, $f, $r['dari'], $r['sampai'], $r['mulai'], $r['akhir']),
            'transaksi' => $this->repo->transaksi($hak, $f, $r['mulai'], $r['akhir']),
            default => $this->repo->koreksiDaftar($hak, $f, $r['mulai'], $r['akhir'], LaporanRepository::EKSPOR_MAKS + 1),
        });
        if (count($rows) > LaporanRepository::EKSPOR_MAKS) {
            throw new BsaException('Ekspor melebihi ' . number_format(LaporanRepository::EKSPOR_MAKS, 0, ',', '.') . ' baris. Persempit periode atau saring unit/santri.');
        }
        $this->bsa->wajibAudit('bsa.laporan.ekspor', 'bsa_laporan', $f['santri_id'] ?? 0, array_filter([
            'jenis' => $jenis, 'periode' => $r['dari'] . '/' . $r['sampai'], 'sumber' => $akses['sumber'], 'kanal' => $this->bsa->kanal($kanal),
            'santri_id' => $f['santri_id'], 'jenjang' => $f['unit'],
        ], static fn (mixed $v): bool => $v !== null), (int) $user['id']);

        [$kolom, $baris] = match ($jenis) {
            'santri' => $this->csvSantri($rows, $wali),
            'transaksi' => $this->csvTransaksi($rows),
            default => $this->csvKoreksi($rows),
        };

        return ['nama_berkas' => 'keuangan-' . $jenis . '-' . $r['dari'] . ($r['sampai'] === $r['dari'] ? '' : '_' . $r['sampai']) . '.csv', 'kolom' => $kolom, 'baris' => $baris];
    }

    /**
     * Menulis CSV UTF-8 (BOM untuk Excel). Sel teks yang dapat ditafsirkan
     * sebagai formula diberi awalan kutip; angka ditulis apa adanya.
     *
     * @param resource $out
     * @param array<int, string> $kolom
     * @param array<int, array<int, int|string>> $baris
     */
    public static function tulisCsv($out, array $kolom, array $baris): void
    {
        $sel = static fn (mixed $v): string => is_int($v) ? (string) $v
            : (preg_match('/^[\x00-\x20]*[=+@-]/u', (string) $v) === 1 || preg_match('/^[\t\r\n]/', (string) $v) === 1 ? "'" . $v : (string) $v);
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, array_map($sel, $kolom), ',', '"', '');
        foreach ($baris as $b) {
            fputcsv($out, array_map($sel, $b), ',', '"', '');
        }
    }

    // =======================================================================

    /**
     * @return array{0:array<string, mixed>, 1:array{mode:string, user_id:int, jenjang:?array<int, string>}, 2:array<string, mixed>, 3:array{santri_id:?int, unit:?string}, 4:?array<string, mixed>}
     */
    private function persiapan(array $user, array $input): array
    {
        if (!$this->tersedia()) {
            throw new BsaException('Laporan keuangan memerlukan modul tagihan dan tabungan lengkap (migrasi 027–029) yang belum dipasang pada basis data ini.', 503);
        }
        $akses = $this->bsa->wajibBaca($user);
        $hak = [
            'mode' => match ($akses['sumber']) {
                BsaService::SUMBER_PETUGAS => 'petugas',
                BsaService::SUMBER_WALI => 'wali',
                default => 'admin',
            },
            'user_id' => (int) $user['id'],
            'jenjang' => $akses['jenjang'],
        ];
        $r = $this->rentang($input);
        $santri = null;
        $santriId = null;
        if (($input['santri'] ?? '') !== '' && ($input['santri'] ?? null) !== null) {
            $santriId = $this->bsa->id($input['santri']);
            $santri = $this->bsa->santriTerbaca($user, $akses, $santriId);
        }
        $unit = null;
        if ($hak['mode'] !== 'wali' && is_string($input['unit'] ?? null) && trim((string) $input['unit']) !== '') {
            $unit = $this->unit((string) $input['unit'], $akses);
        }

        return [$akses, $hak, $r, ['santri_id' => $santriId, 'unit' => $unit], $santri];
    }

    /** @return array<string, mixed> */
    private function rentang(array $input): array
    {
        $kini = date('Y-m');
        $sampai = $this->bulan($input['sampai'] ?? null, 'Periode sampai') ?? $kini;
        $dari = $this->bulan($input['dari'] ?? null, 'Periode dari') ?? $sampai;
        if ($dari > $sampai) {
            throw new BsaException('Periode "dari" tidak boleh sesudah periode "sampai".');
        }
        if ($sampai > $kini) {
            throw new BsaException('Periode laporan tidak boleh di masa depan.');
        }
        $mulai = new DateTimeImmutable($dari . '-01');
        $akhir = (new DateTimeImmutable($sampai . '-01'))->modify('+1 month');
        $bulan = [];
        for ($b = $mulai; $b < $akhir; $b = $b->modify('+1 month')) {
            $bulan[] = $b->format('Y-m');
        }
        if (count($bulan) > self::RENTANG_MAKS_BULAN) {
            throw new BsaException('Rentang laporan paling panjang ' . self::RENTANG_MAKS_BULAN . ' bulan.');
        }

        return [
            'dari' => $dari, 'sampai' => $sampai, 'bulan' => $bulan, 'hari_ini' => date('Y-m-d'),
            'mulai' => $mulai->format('Y-m-d 00:00:00'), 'akhir' => $akhir->format('Y-m-d 00:00:00'),
            'tanggal_akhir' => $akhir->modify('-1 day')->format('Y-m-d'),
        ];
    }

    private function bulan(mixed $value, string $label): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) || preg_match('/^(20[0-9]{2})-(0[1-9]|1[0-2])$/D', $value) !== 1) {
            throw new BsaException($label . ' tidak sah (format TTTT-BB).');
        }

        return $value;
    }

    /** Unit kanonik dari kelas aktif; unit di luar cakupan petugas 404. */
    private function unit(string $value, array $akses): string
    {
        foreach ($this->tagihan->unitAkses(['sumber' => BsaService::SUMBER_ADMIN, 'jenjang' => null]) as $u) {
            if (mb_strtolower($u) === mb_strtolower(trim($value))) {
                if (!in_array($u, $this->tagihan->unitAkses($akses), true)) {
                    throw new BsaException('Unit ini di luar cakupan penugasan BSA Anda.', 404);
                }

                return $u;
            }
        }
        throw new BsaException('Unit/jenjang tidak dikenal.');
    }

    /**
     * Ledger sah dikelompokkan menurut makna, bukan arah: setoran/penarikan/
     * belanja per cara, debit tagihan, koreksi masuk/keluar (pembalik
     * Tunai/Transfer), dan pengembalian (pembalik Internal).
     *
     * @param array<int, array{jenis:string, arah:string, metode:string, nominal:int, jumlah:int}> $ledger
     * @return array<string, mixed>
     */
    private function tabunganMenurutJenis(array $ledger): array
    {
        $tab = ['Setoran' => [], 'Penarikan' => [], 'Belanja' => [], 'PembayaranTagihan' => [], 'KoreksiMasuk' => [], 'KoreksiKeluar' => [], 'Pengembalian' => 0, 'jumlah_koreksi' => 0];
        foreach ($ledger as $x) {
            $cara = (string) $x['metode'];
            if ($x['jenis'] === 'Pembalik') {
                if ($cara === 'Internal') {
                    $tab['Pengembalian'] += $x['nominal'];
                    continue;
                }
                $kunci = $x['arah'] === 'Masuk' ? 'KoreksiMasuk' : 'KoreksiKeluar';
                $tab['jumlah_koreksi'] += $x['jumlah'];
            } else {
                $kunci = (string) $x['jenis'];
            }
            $tab[$kunci][$cara] = ($tab[$kunci][$cara] ?? 0) + $x['nominal'];
        }

        return $tab;
    }

    /** @return array{Tunai:int, Transfer:int, Tabungan:int} */
    private function menurutSumber(array $rows): array
    {
        $hasil = ['Tunai' => 0, 'Transfer' => 0, 'Tabungan' => 0];
        foreach ($rows as $b) {
            $hasil[(string) $b['sumber']] = ($hasil[(string) $b['sumber']] ?? 0) + $b['nominal'];
        }

        return $hasil;
    }

    /** @return array{Tunai:int, Transfer:int} */
    private function caraKas(array $perCara): array
    {
        return ['Tunai' => (int) ($perCara['Tunai'] ?? 0), 'Transfer' => (int) ($perCara['Transfer'] ?? 0)];
    }

    /**
     * Kas nyata per cara — rumus sama dengan rekonsiliasi harian Fase 3.
     *
     * @return array<string, array<string, int>>
     */
    private function kas(array $tab, array $bayar, array $batal): array
    {
        $kas = [];
        foreach (['Tunai', 'Transfer'] as $cara) {
            $setoran = (int) ($tab['Setoran'][$cara] ?? 0);
            $koreksiMasuk = (int) ($tab['KoreksiMasuk'][$cara] ?? 0);
            $tarikBelanja = (int) ($tab['Penarikan'][$cara] ?? 0) + (int) ($tab['Belanja'][$cara] ?? 0);
            $koreksiKeluar = (int) ($tab['KoreksiKeluar'][$cara] ?? 0) + $batal[$cara];
            $masuk = $setoran + $bayar[$cara] + $koreksiMasuk;
            $keluar = $tarikBelanja + $koreksiKeluar;
            $kas[$cara] = ['setoran' => $setoran, 'pembayaran_tagihan' => $bayar[$cara], 'koreksi_masuk' => $koreksiMasuk, 'masuk' => $masuk,
                'penarikan_belanja' => $tarikBelanja, 'koreksi_keluar' => $koreksiKeluar, 'keluar' => $keluar, 'bersih' => $masuk - $keluar];
        }

        return $kas;
    }

    /**
     * Satu baris per bulan rentang: tagihan menurut periode, arus menurut
     * waktu transaksi. Jumlah seluruh baris = angka agregat laporan.
     *
     * @param array<int, string> $bulan
     * @return array<int, array<string, mixed>>
     */
    private function perBulan(array $bulan, array $m): array
    {
        $baris = [];
        foreach ($bulan as $b) {
            $baris[$b] = ['periode' => $b, 'tagihan_jumlah' => 0, 'tagihan_terbit' => 0, 'terbayar' => 0, 'sisa' => 0, 'setoran' => 0, 'penarikan_belanja' => 0,
                'bayar_kas' => 0, 'bayar_tabungan' => 0, 'pembatalan' => 0, 'kas_baru' => 0];
        }
        foreach ($m['tagihan_bulanan'] as $t) {
            if (isset($baris[$t['bulan']])) {
                $baris[$t['bulan']]['tagihan_jumlah'] = $t['jumlah'];
                $baris[$t['bulan']]['tagihan_terbit'] = $t['terbit'];
                $baris[$t['bulan']]['terbayar'] = $t['terbayar'];
                $baris[$t['bulan']]['sisa'] = $t['terbit'] - $t['terbayar'];
            }
        }
        foreach ($m['ledger'] as $x) {
            if (!isset($baris[$x['bulan']])) {
                continue;
            }
            if ($x['jenis'] === 'Setoran') {
                $baris[$x['bulan']]['setoran'] += $x['nominal'];
                $baris[$x['bulan']]['kas_baru'] += in_array($x['metode'], ['Tunai', 'Transfer'], true) ? $x['nominal'] : 0;
            } elseif (in_array($x['jenis'], ['Penarikan', 'Belanja'], true)) {
                $baris[$x['bulan']]['penarikan_belanja'] += $x['nominal'];
            }
        }
        foreach ($m['bayar'] as $b) {
            if (!isset($baris[$b['bulan']])) {
                continue;
            }
            if ($b['sumber'] === 'Tabungan') {
                $baris[$b['bulan']]['bayar_tabungan'] += $b['nominal'];
            } else {
                $baris[$b['bulan']]['bayar_kas'] += $b['nominal'];
                $baris[$b['bulan']]['kas_baru'] += $b['nominal'];
            }
        }
        foreach ($m['batal'] as $b) {
            if (isset($baris[$b['bulan']])) {
                $baris[$b['bulan']]['pembatalan'] += $b['nominal'];
            }
        }

        return array_values($baris);
    }

    /** @return array<string, mixed> */
    private function ringkasSantri(array $s, bool $wali): array
    {
        $baris = ['santri' => $this->bsa->identitas($s)];
        foreach (LaporanRepository::KOLOM_SANTRI as $k) {
            $baris[$k] = $s[$k];
        }
        if ($wali) {
            unset($baris['selisih_saldo'], $baris['selisih_akun']);
        }

        return $baris;
    }

    /** @return array<string, mixed> */
    private function ringkasKoreksi(array $k): array
    {
        return [
            'asal' => (string) $k['asal'],
            'id' => (int) $k['id'],
            'waktu' => (string) $k['waktu'],
            'jenis' => (string) $k['jenis'],
            'cara' => $k['cara'] === null ? null : (string) $k['cara'],
            'nominal' => (int) $k['nominal'],
            'aktor' => $k['aktor'] === null ? null : (string) $k['aktor'],
            'alasan' => $k['alasan'] === null ? null : (string) $k['alasan'],
            'rujukan' => $this->rujukanKoreksi($k),
            'santri' => ['id' => (int) $k['santri_id'], 'nis' => (string) $k['nis'], 'nama' => (string) $k['nama_santri']],
        ];
    }

    /** @return array{id:int, jenis:string, label:string} */
    private function rujukanKoreksi(array $k): array
    {
        $id = (int) $k['asal_id'];

        return ['id' => $id, 'jenis' => (string) $k['asal_jenis'], 'label' => match ($k['asal']) {
            'tabungan' => BsaService::nomorKuitansiTabungan($id, (string) $k['asal_dibuat']),
            'pembayaran' => (string) $k['asal_nomor'],
            'setoran' => 'Setoran transfer #' . $id,
            default => 'Tagihan #' . $id . ' (' . (string) $k['periode'] . ')',
        }];
    }

    /** @return array{0:array<int, string>, 1:array<int, array<int, int|string>>} */
    private function csvSantri(array $rows, bool $wali): array
    {
        $kolom = ['NIS', 'Nama santri', 'Kelas', 'Unit', 'Status', 'Jumlah tagihan', 'Tagihan terbit', 'Terbayar', 'Sisa periode', 'Sisa seluruh tunggakan',
            'Bayar tunai/transfer', 'Pembayaran dibatalkan', 'Saldo awal', 'Setoran', 'Penarikan', 'Belanja', 'Bayar tagihan dari tabungan', 'Koreksi masuk',
            'Koreksi keluar', 'Pengembalian ke tabungan', 'Saldo akhir', 'Saldo kini'];
        if (!$wali) {
            array_push($kolom, 'Selisih saldo', 'Selisih akun');
        }
        $baris = array_map(static function (array $s) use ($wali): array {
            $b = [(string) $s['nis'], (string) $s['nama_santri'], (string) ($s['nama_kelas'] ?? ''), (string) ($s['jenjang'] ?? ''),
                (int) $s['is_active'] === 1 && $s['archived_at'] === null ? 'Aktif' : 'Nonaktif'];
            foreach (['tagihan_jumlah', 'tagihan', 'terbayar', 'sisa', 'sisa_semua', 'bayar_kas', 'batal_bayar', 'saldo_awal', 'setoran', 'penarikan', 'belanja',
                'bayar_tabungan', 'koreksi_masuk', 'koreksi_keluar', 'pengembalian', 'saldo_akhir', 'saldo_akun'] as $k) {
                $b[] = (int) $s[$k];
            }
            if (!$wali) {
                $b[] = (int) $s['selisih_saldo'];
                $b[] = (int) $s['selisih_akun'];
            }

            return $b;
        }, $rows);

        return [$kolom, $baris];
    }

    /**
     * Rincian kejadian uang. Referensi transfer, catatan, dan alasan tidak
     * ikut ekspor (PRD V6 §5.4); nomor kuitansi menjadi rujukan pemeriksaan.
     *
     * @return array{0:array<int, string>, 1:array<int, array<int, int|string>>}
     */
    private function csvTransaksi(array $rows): array
    {
        $kolom = ['Waktu', 'Nomor kuitansi', 'NIS', 'Nama santri', 'Asal', 'Jenis', 'Cara', 'Arah', 'Nominal', 'Kas baru', 'Perpindahan internal', 'Periode tagihan', 'Rujukan'];
        $baris = array_map(static function (array $x): array {
            $tabungan = $x['asal'] === 'tabungan';
            $kasBaru = ($tabungan && $x['jenis'] === 'Setoran' || $x['asal'] === 'pembayaran') && in_array($x['cara'], ['Tunai', 'Transfer'], true);
            $rujukan = $x['rujukan_id'] === null ? '' : ($x['rujukan_dibuat'] !== null
                ? BsaService::nomorKuitansiTabungan((int) $x['rujukan_id'], (string) $x['rujukan_dibuat']) : '');

            return [
                (string) $x['waktu'],
                $tabungan ? BsaService::nomorKuitansiTabungan((int) $x['id'], (string) $x['dibuat']) : (string) $x['nomor_kuitansi'],
                (string) $x['nis'], (string) $x['nama_santri'],
                match ($x['asal']) { 'tabungan' => 'Tabungan', 'pembayaran' => 'Pembayaran tagihan', default => 'Pembatalan pembayaran' },
                (string) $x['jenis'], (string) $x['cara'], (string) $x['arah'], (int) $x['nominal'],
                $kasBaru ? 'Ya' : 'Tidak', in_array($x['cara'], ['Internal', 'Tabungan'], true) ? 'Ya' : 'Tidak',
                (string) ($x['periode'] ?? ''), $rujukan,
            ];
        }, $rows);

        return [$kolom, $baris];
    }

    /** @return array{0:array<int, string>, 1:array<int, array<int, int|string>>} */
    private function csvKoreksi(array $rows): array
    {
        $kolom = ['Waktu', 'Jenis', 'NIS', 'Nama santri', 'Cara', 'Nominal', 'Aktor', 'Alasan', 'Asal'];
        $baris = array_map(function (array $k): array {
            $r = $this->ringkasKoreksi($k);

            return [$r['waktu'], $r['jenis'], $r['santri']['nis'], $r['santri']['nama'], (string) ($r['cara'] ?? ''), $r['nominal'], (string) ($r['aktor'] ?? ''),
                (string) ($r['alasan'] ?? ''), $r['rujukan']['label']];
        }, $rows);

        return [$kolom, $baris];
    }
}
