<?php

declare(strict_types=1);

namespace App\Penugasan;

use InvalidArgumentException;

/**
 * Katalog jenis penugasan fungsional (fondasi PRD V3–V6, keputusan pengguna
 * 7 September 2026).
 *
 * Kelas ini murni definisi — tanpa basis data, tanpa sesi — dan menjadi SATU
 * sumber kebenaran bagi resolver capability (`App\Auth\Capabilities`),
 * layanan mutasi (`PenugasanService`), halaman admin, pengujian, dan
 * dokumentasi:
 *
 *   - tabel dan kolom subjek/cakupan tiap jenis;
 *   - role dasar yang WAJIB dimiliki akun agar penugasan menghasilkan
 *     capability (murobi/guru mapel → `guru`; selebihnya → `pengurus`);
 *   - capability yang dihasilkan;
 *   - apakah tahun ajaran penugasan wajib berstatus Aktif (PSB tidak:
 *     penerimaan berjalan sebelum tahun ajarannya aktif).
 *
 * PENTING: tidak ada satu pun jenis di bawah yang menjadi role login. Semua
 * adalah penugasan yang menghasilkan capability, dan capability itu baru
 * efektif bila akun aktif + role dasar + relasi master + masa berlaku + tahun
 * ajaran + cakupan seluruhnya sah pada pemeriksaan server.
 */
final class PenugasanJenis
{
    public const MUROBI = 'murobi';
    public const PEMBIMBING = 'pembimbing';
    public const GURU_MAPEL = 'guru_mapel';
    public const PENDIDIKAN = 'pendidikan';
    public const BENDAHARA_BULANAN = 'bendahara_bulanan';
    public const PANITIA_PSB = 'panitia_psb';
    public const BENDAHARA_PSB = 'bendahara_psb';

    /** @var array<int, string> Urutan tampil pada pusat penugasan. */
    public const ALL = [
        self::MUROBI,
        self::PEMBIMBING,
        self::GURU_MAPEL,
        self::PENDIDIKAN,
        self::BENDAHARA_BULANAN,
        self::PANITIA_PSB,
        self::BENDAHARA_PSB,
    ];

    /** Bentuk cakupan: target kamar/kelas (tabel lama). */
    public const CAKUPAN_TARGET = 'target';
    /** Bentuk cakupan: mata pelajaran + kelas. */
    public const CAKUPAN_MAPEL_KELAS = 'mapel_kelas';
    /** Bentuk cakupan: jenjang/unit opsional (NULL = seluruh unit). */
    public const CAKUPAN_JENJANG = 'jenjang';
    /** Bentuk cakupan: gelombang PSB opsional (NULL = seluruh gelombang). */
    public const CAKUPAN_GELOMBANG = 'gelombang';

    // --- Capability fondasi. Nama disengaja berpola `<modul>.<tindakan>` -----
    // Kesiapan V3 (konseling & pelanggaran memakai cakupan binaan murobi/pembimbing).
    public const CAP_MUROBI_BINAAN = 'murobi.binaan';
    public const CAP_PEMBIMBING_BINAAN = 'pembimbing.binaan';
    // Kesiapan V4.
    public const CAP_NILAI_INPUT = 'nilai.input';
    public const CAP_NILAI_LIHAT_SENDIRI = 'nilai.lihat_sendiri';
    public const CAP_NILAI_KOREKSI_SENDIRI = 'nilai.koreksi_sendiri';
    public const CAP_RAPOR_VERIFIKASI = 'rapor.verifikasi';
    public const CAP_RAPOR_FINALISASI = 'rapor.finalisasi';
    public const CAP_RAPOR_BUKA_KOREKSI = 'rapor.buka_koreksi';
    public const CAP_RAPOR_CETAK = 'rapor.cetak';
    // Kesiapan V5.
    public const CAP_PEMBIAYAAN_BULANAN_TAGIHAN = 'pembiayaan_bulanan.tagihan';
    public const CAP_PEMBIAYAAN_BULANAN_PEMBAYARAN = 'pembiayaan_bulanan.pembayaran';
    public const CAP_PEMBIAYAAN_BULANAN_VERIFIKASI = 'pembiayaan_bulanan.verifikasi';
    public const CAP_PEMBIAYAAN_BULANAN_LAPORAN = 'pembiayaan_bulanan.laporan';
    // Kesiapan V6.
    public const CAP_PSB_PENDAFTAR = 'psb.pendaftar';
    public const CAP_PSB_VERIFIKASI = 'psb.verifikasi';
    public const CAP_PSB_SELEKSI = 'psb.seleksi';
    public const CAP_PSB_PENERIMAAN = 'psb.penerimaan';
    public const CAP_PSB_KEUANGAN_TAGIHAN = 'psb_keuangan.tagihan';
    public const CAP_PSB_KEUANGAN_PEMBAYARAN = 'psb_keuangan.pembayaran';
    public const CAP_PSB_KEUANGAN_VERIFIKASI = 'psb_keuangan.verifikasi';
    public const CAP_PSB_KEUANGAN_LAPORAN = 'psb_keuangan.laporan';

    /**
     * @var array<string, array{
     *     label:string,
     *     label_subjek:string,
     *     tabel:string,
     *     entitas:string,
     *     subjek:'guru'|'pengurus',
     *     subjek_kolom:string,
     *     role_dasar:string,
     *     cakupan:string,
     *     wajib_tahun_aktif:bool,
     *     lama:bool,
     *     capabilities:array<int, string>,
     *     prd:string,
     *     keterangan:string
     * }>
     */
    private const DEFINISI = [
        self::MUROBI => [
            'label' => 'Murobi',
            'label_subjek' => 'Guru',
            'tabel' => 'murobi_assignments',
            'entitas' => 'murobi_assignment',
            'subjek' => 'guru',
            'subjek_kolom' => 'guru_id',
            'role_dasar' => 'guru',
            'cakupan' => self::CAKUPAN_TARGET,
            'wajib_tahun_aktif' => true,
            'lama' => true,
            'capabilities' => [self::CAP_MUROBI_BINAAN],
            'prd' => 'V2 (berjalan) · V3',
            'keterangan' => 'Guru dengan penugasan murobi aktif. Capability keputusan perizinan V2 (`murobi`) tetap dihitung seperti sebelumnya; `murobi.binaan` menyiapkan cakupan kamar/kelas untuk konseling dan pelanggaran (V3).',
        ],
        self::PEMBIMBING => [
            'label' => 'Pembimbing',
            'label_subjek' => 'Pengurus',
            'tabel' => 'pembimbing_assignments',
            'entitas' => 'pembimbing_assignment',
            'subjek' => 'pengurus',
            'subjek_kolom' => 'pengurus_id',
            'role_dasar' => 'pengurus',
            'cakupan' => self::CAKUPAN_TARGET,
            'wajib_tahun_aktif' => true,
            'lama' => true,
            'capabilities' => [self::CAP_PEMBIMBING_BINAAN],
            'prd' => 'V2 (berjalan) · V3',
            'keterangan' => 'Pengurus dengan penugasan pembimbing aktif. Cakupan pengajuan izin V2 tetap dihitung seperti sebelumnya; `pembimbing.binaan` menyiapkan cakupan kamar/kelas untuk konseling dan pelanggaran (V3).',
        ],
        self::GURU_MAPEL => [
            'label' => 'Guru Mata Pelajaran',
            'label_subjek' => 'Guru',
            'tabel' => 'guru_mapel_assignments',
            'entitas' => 'guru_mapel_assignment',
            'subjek' => 'guru',
            'subjek_kolom' => 'guru_id',
            'role_dasar' => 'guru',
            'cakupan' => self::CAKUPAN_MAPEL_KELAS,
            'wajib_tahun_aktif' => true,
            'lama' => false,
            'capabilities' => [self::CAP_NILAI_INPUT, self::CAP_NILAI_LIHAT_SENDIRI, self::CAP_NILAI_KOREKSI_SENDIRI],
            'prd' => 'V4',
            'keterangan' => 'Guru pengampu satu mata pelajaran pada satu kelas untuk satu tahun ajaran/semester. Capability hanya menunjukkan kesiapan akses; formulir dan tabel nilai belum ada.',
        ],
        self::PENDIDIKAN => [
            'label' => 'Bagian Pendidikan',
            'label_subjek' => 'Pengurus',
            'tabel' => 'pendidikan_assignments',
            'entitas' => 'pendidikan_assignment',
            'subjek' => 'pengurus',
            'subjek_kolom' => 'pengurus_id',
            'role_dasar' => 'pengurus',
            'cakupan' => self::CAKUPAN_JENJANG,
            'wajib_tahun_aktif' => true,
            'lama' => false,
            'capabilities' => [self::CAP_RAPOR_VERIFIKASI, self::CAP_RAPOR_FINALISASI, self::CAP_RAPOR_BUKA_KOREKSI, self::CAP_RAPOR_CETAK],
            'prd' => 'V4',
            'keterangan' => 'Pengurus yang diangkat sebagai Bagian Pendidikan, opsional dibatasi satu unit/jenjang. Halaman rapor belum ada.',
        ],
        self::BENDAHARA_BULANAN => [
            'label' => 'Bendahara Pembiayaan Bulanan',
            'label_subjek' => 'Pengurus',
            'tabel' => 'bendahara_bulanan_assignments',
            'entitas' => 'bendahara_bulanan_assignment',
            'subjek' => 'pengurus',
            'subjek_kolom' => 'pengurus_id',
            'role_dasar' => 'pengurus',
            'cakupan' => self::CAKUPAN_JENJANG,
            'wajib_tahun_aktif' => true,
            'lama' => false,
            'capabilities' => [self::CAP_PEMBIAYAAN_BULANAN_TAGIHAN, self::CAP_PEMBIAYAAN_BULANAN_PEMBAYARAN, self::CAP_PEMBIAYAAN_BULANAN_VERIFIKASI, self::CAP_PEMBIAYAAN_BULANAN_LAPORAN],
            'prd' => 'V5',
            'keterangan' => 'Pengurus yang diangkat sebagai bendahara pembiayaan bulanan, opsional dibatasi satu unit/jenjang. Tagihan, pembayaran, kuitansi, dan laporan keuangan belum ada.',
        ],
        self::PANITIA_PSB => [
            'label' => 'Panitia PSB',
            'label_subjek' => 'Pengurus',
            'tabel' => 'panitia_psb_assignments',
            'entitas' => 'panitia_psb_assignment',
            'subjek' => 'pengurus',
            'subjek_kolom' => 'pengurus_id',
            'role_dasar' => 'pengurus',
            'cakupan' => self::CAKUPAN_GELOMBANG,
            'wajib_tahun_aktif' => false,
            'lama' => false,
            'capabilities' => [self::CAP_PSB_PENDAFTAR, self::CAP_PSB_VERIFIKASI, self::CAP_PSB_SELEKSI, self::CAP_PSB_PENERIMAAN],
            'prd' => 'V6',
            'keterangan' => 'Pengurus panitia penerimaan santri baru untuk satu tahun ajaran penerimaan, opsional satu gelombang. TIDAK memberi akses keuangan PSB.',
        ],
        self::BENDAHARA_PSB => [
            'label' => 'Bendahara PSB',
            'label_subjek' => 'Pengurus',
            'tabel' => 'bendahara_psb_assignments',
            'entitas' => 'bendahara_psb_assignment',
            'subjek' => 'pengurus',
            'subjek_kolom' => 'pengurus_id',
            'role_dasar' => 'pengurus',
            'cakupan' => self::CAKUPAN_GELOMBANG,
            'wajib_tahun_aktif' => false,
            'lama' => false,
            'capabilities' => [self::CAP_PSB_KEUANGAN_TAGIHAN, self::CAP_PSB_KEUANGAN_PEMBAYARAN, self::CAP_PSB_KEUANGAN_VERIFIKASI, self::CAP_PSB_KEUANGAN_LAPORAN],
            'prd' => 'V6',
            'keterangan' => 'Pengurus bendahara PSB, terpisah dari panitia. TIDAK memberi akses proses pendaftaran/seleksi PSB.',
        ],
    ];

    /** @var array<string, string> Label manusiawi tiap capability (untuk UI dan dokumentasi). */
    private const LABEL_CAPABILITY = [
        self::CAP_MUROBI_BINAAN => 'Binaan murobi (kamar/kelas)',
        self::CAP_PEMBIMBING_BINAAN => 'Binaan pembimbing (kamar/kelas)',
        self::CAP_NILAI_INPUT => 'Input nilai',
        self::CAP_NILAI_LIHAT_SENDIRI => 'Lihat nilai sendiri',
        self::CAP_NILAI_KOREKSI_SENDIRI => 'Koreksi nilai sendiri',
        self::CAP_RAPOR_VERIFIKASI => 'Verifikasi rapor',
        self::CAP_RAPOR_FINALISASI => 'Finalisasi rapor',
        self::CAP_RAPOR_BUKA_KOREKSI => 'Buka koreksi rapor',
        self::CAP_RAPOR_CETAK => 'Cetak rapor',
        self::CAP_PEMBIAYAAN_BULANAN_TAGIHAN => 'Tagihan bulanan',
        self::CAP_PEMBIAYAAN_BULANAN_PEMBAYARAN => 'Pembayaran bulanan',
        self::CAP_PEMBIAYAAN_BULANAN_VERIFIKASI => 'Verifikasi pembayaran bulanan',
        self::CAP_PEMBIAYAAN_BULANAN_LAPORAN => 'Laporan pembiayaan bulanan',
        self::CAP_PSB_PENDAFTAR => 'Data pendaftar PSB',
        self::CAP_PSB_VERIFIKASI => 'Verifikasi berkas PSB',
        self::CAP_PSB_SELEKSI => 'Seleksi PSB',
        self::CAP_PSB_PENERIMAAN => 'Penerimaan PSB',
        self::CAP_PSB_KEUANGAN_TAGIHAN => 'Tagihan PSB',
        self::CAP_PSB_KEUANGAN_PEMBAYARAN => 'Pembayaran PSB',
        self::CAP_PSB_KEUANGAN_VERIFIKASI => 'Verifikasi pembayaran PSB',
        self::CAP_PSB_KEUANGAN_LAPORAN => 'Laporan keuangan PSB',
    ];

    /**
     * @return array{
     *     label:string, label_subjek:string, tabel:string, entitas:string,
     *     subjek:string, subjek_kolom:string, role_dasar:string, cakupan:string,
     *     wajib_tahun_aktif:bool, lama:bool, capabilities:array<int, string>,
     *     prd:string, keterangan:string
     * }
     */
    public static function definisi(string $jenis): array
    {
        if (!isset(self::DEFINISI[$jenis])) {
            throw new InvalidArgumentException('Jenis penugasan tidak dikenal.');
        }

        return self::DEFINISI[$jenis];
    }

    public static function valid(string $jenis): bool
    {
        return isset(self::DEFINISI[$jenis]);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function semua(): array
    {
        return self::DEFINISI;
    }

    /**
     * Seluruh capability fondasi, dalam urutan jenis.
     *
     * @return array<int, string>
     */
    public static function semuaCapability(): array
    {
        $daftar = [];
        foreach (self::DEFINISI as $definisi) {
            foreach ($definisi['capabilities'] as $capability) {
                $daftar[$capability] = true;
            }
        }

        return array_keys($daftar);
    }

    /**
     * Jenis penugasan yang menghasilkan sebuah capability (bisa lebih dari satu
     * pada masa depan; saat ini tepat satu).
     *
     * @return array<int, string>
     */
    public static function jenisUntukCapability(string $capability): array
    {
        $hasil = [];
        foreach (self::DEFINISI as $jenis => $definisi) {
            if (in_array($capability, $definisi['capabilities'], true)) {
                $hasil[] = $jenis;
            }
        }

        return $hasil;
    }

    public static function labelCapability(string $capability): string
    {
        return self::LABEL_CAPABILITY[$capability] ?? $capability;
    }
}
