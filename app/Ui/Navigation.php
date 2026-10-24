<?php

declare(strict_types=1);

namespace App\Ui;

use App\Auth\Capabilities;
use App\Penugasan\PenugasanJenis;

/**
 * Peta navigasi Sistem Al Hasan per peran (redesain website Tahap 2).
 *
 * Peta menu mengikuti keputusan Human Developer 22 Oktober 2026 (A–K):
 * admin 17 menu dalam 7 grup; guru & murobi; pengurus/pembimbing; Bagian
 * Pendidikan; orang tua. Menu disusun menurut PERAN AKTIF (`Peran`), dan di
 * dalam peran itu hanya menu yang menjadi hak nyata akun yang ditampilkan.
 *
 * Kelas ini SENGAJA tidak memuat guard apa pun. Ia hanya menjawab pertanyaan
 * "menu apa yang pantas ditampilkan kepada akun ini". Menyembunyikan menu
 * BUKAN kontrol akses: setiap halaman tujuan tetap memeriksa hak dan
 * cakupannya sendiri di server (`Authorization`, `PortalGuard`, layanan).
 *
 * Setiap menu memuat daftar halaman miliknya (`pages`) dan tab modulnya
 * (`tabs`). Alamat lama yang tidak lagi menjadi butir menu tetap dapat dibuka
 * dan ditempatkan di bawah menu barunya lewat daftar halaman itu.
 *
 * Struktur keluaran `forUser()`:
 *   [ ['label' => 'Grup'|'', 'items' => [ ['key','label','url','icon','badge',
 *      'badge_label','pages','tabs','short'] ] ] ]
 */
final class Navigation
{
    /**
     * Butir bilah bawah HP per peran (cetak biru, peta menu): jumlah menu
     * utama dan kandidatnya menurut prioritas. Beranda selalu pertama;
     * Notifikasi dan Menu ditambahkan di ujung.
     *
     * @var array<string, array{0:int, 1:array<int, string>}>
     */
    private const BOTTOM_BAR = [
        Peran::ADMIN => [2, ['izin', 'santri']],
        Peran::GURU => [2, ['pengajian', 'nilai', 'antrean', 'sikap_binaan']],
        Peran::PENGURUS => [2, ['izin', 'sikap', 'pembinaan', 'kesehatan']],
        Peran::PENDIDIKAN => [1, ['rapor', 'pengganti', 'absensi']],
        Peran::ORANG_TUA => [3, ['izin_anak', 'rapor_wali', 'info']],
    ];

    /** @var array<string, array<string, mixed>> */
    private static array $cache = [];

    /**
     * Grup menu yang tampil untuk akun pada peran aktifnya.
     *
     * @param array<string, mixed> $user
     * @param array<int, string> $capabilities
     * @param array<string, array<string, mixed>> $v3Capabilities
     * @return array<int, array{label:string, items:array<int, array<string, mixed>>}>
     */
    public static function forUser(array $user, array $capabilities, ?int $unreadCount = null, array $v3Capabilities = [], ?string $peran = null): array
    {
        return self::build($user, $capabilities, $unreadCount, $v3Capabilities, $peran)['groups'];
    }

    /**
     * Peta lengkap: grup tampil, butir tersembunyi (notifikasi, kata sandi),
     * dan peran yang dipakai.
     *
     * @param array<string, mixed> $user
     * @param array<int, string> $capabilities
     * @param array<string, array<string, mixed>> $v3Capabilities
     * @return array{peran:?string, groups:array<int, array{label:string, items:array<int, array<string, mixed>>}>, extras:array<int, array<string, mixed>>}
     */
    public static function build(array $user, array $capabilities, ?int $unreadCount = null, array $v3Capabilities = [], ?string $peran = null): array
    {
        $peran ??= Peran::active($user);
        $cacheKey = (int) ($user['id'] ?? 0) . '|' . ($peran ?? '-') . '|' . ($unreadCount ?? 'x');
        if (isset(self::$cache[$cacheKey])) {
            return self::$cache[$cacheKey];
        }

        $badge = $unreadCount !== null && $unreadCount > 0 ? ($unreadCount > 99 ? '99+' : (string) $unreadCount) : null;
        $badgeLabel = $badge !== null ? $unreadCount . ' notifikasi belum dibaca' : null;
        $notifikasi = self::item('notifikasi', 'Notifikasi', '/portal/notifikasi.php', 'fa-bell', ['notifikasi.php'], [], 'Notifikasi');
        $notifikasi['badge'] = $badge;
        $notifikasi['badge_label'] = $badgeLabel;
        $password = self::item('akun.password', 'Ganti kata sandi', '/admin/ubah_password.php', 'fa-key', ['ubah_password.php']);

        $ctx = new NavigationContext($user, $capabilities, $v3Capabilities);
        $groups = match ($peran) {
            Peran::ADMIN => self::admin($ctx),
            Peran::GURU => self::guru($ctx),
            Peran::PENGURUS => self::pengurus($ctx),
            Peran::PENDIDIKAN => self::pendidikan($ctx),
            Peran::ORANG_TUA => self::orangTua($ctx, $notifikasi),
            default => [['label' => '', 'items' => [self::beranda()]]],
        };

        // Grup kosong (mis. pengurus bukan pembimbing tanpa penugasan) dibuang.
        $groups = array_values(array_filter($groups, static fn (array $group): bool => $group['items'] !== []));

        $extras = [$password];
        if (self::find($groups, 'notifikasi') === null) {
            $extras[] = $notifikasi;
        }

        return self::$cache[$cacheKey] = ['peran' => $peran, 'groups' => $groups, 'extras' => $extras];
    }

    /** @return array<int, array{label:string, items:array<int, array<string, mixed>>}> */
    private static function admin(NavigationContext $c): array
    {
        $beranda = self::beranda([
            self::tab('Tugas saya', '/portal/index.php', ['index.php']),
            self::tab('Ringkasan administrasi', '/admin/admin_dashboard.php', ['admin_dashboard.php']),
        ]);

        return [
            ['label' => '', 'items' => [$beranda]],
            ['label' => 'Kegiatan santri', 'items' => array_values(array_filter([
                self::perizinan(),
                self::pengajian(),
                self::pembinaan(true),
                // PRD V5: admin membaca dan mengaudit catatan kesehatan (tanpa mutasi klinis).
                $c->kesehatan(false) ? self::kesehatan() : null,
            ]))],
            ['label' => 'Akademik', 'items' => array_filter([self::penilaian($c, true)])],
            ['label' => 'Data pesantren', 'items' => [
                self::item('santri', 'Data santri', '/admin/admin_master_santri.php', 'fa-address-book', [], [
                    self::tab('Santri', '/admin/admin_master_santri.php', ['admin_master_santri.php', 'export_santri.php', 'proses_import_santri.php', 'hapus_siswa.php']),
                    self::tab('Penempatan', '/admin/admin_penempatan_santri.php', ['admin_penempatan_santri.php', 'admin_santri.php']),
                    self::tab('Orang tua/wali', '/admin/admin_wali.php', ['admin_wali.php', 'admin_wali_rekonsiliasi.php']),
                    self::tab('Kelulusan & alumni', '/admin/admin_alumni.php', ['admin_alumni.php', 'admin_kelulusan_santri.php', 'proses_mutasi_alumni.php']),
                    self::tab('Rekap jumlah', '/admin/admin_rekap_santri.php', ['admin_rekap_santri.php']),
                ], 'Santri'),
                self::item('asatidz', 'Asatidz & pengurus', '/admin/admin_guru.php', 'fa-chalkboard-user', [], [
                    self::tab('Asatidz', '/admin/admin_guru.php', ['admin_guru.php']),
                    self::tab('Pengurus', '/admin/admin_pengurus.php', ['admin_pengurus.php']),
                ], 'Asatidz'),
                self::item('kelas', 'Kelas & kobong', '/admin/admin_kelas.php', 'fa-school', [], [
                    self::tab('Kelas', '/admin/admin_kelas.php', ['admin_kelas.php']),
                    self::tab('Kamar/kobong', '/admin/admin_kamar.php', ['admin_kamar.php']),
                ], 'Kelas'),
            ]],
            ['label' => 'PSB', 'items' => [
                self::item('psb.pendaftar', 'Pendaftar', '/admin/admin_data.php', 'fa-user-plus', ['admin_data.php', 'proses_psb_admin.php', 'export_psb.php', 'proses_import_psb.php']),
                self::item('psb.pembayaran', 'Pembayaran', '/admin/admin_pembayaran_psb.php', 'fa-money-bill', ['admin_pembayaran_psb.php', 'export_pembayaran.php']),
                self::item('psb.rekap', 'Rekap keuangan', '/admin/admin_rekap_keuangan.php', 'fa-chart-area', ['admin_rekap_keuangan.php'], [], 'Rekap'),
            ]],
            ['label' => 'Website', 'items' => [
                self::item('konten', 'Konten website', '/admin/admin_berita.php', 'fa-newspaper', [], [
                    self::tab('Berita', '/admin/admin_berita.php', ['admin_berita.php', 'tambah_berita.php', 'edit_berita.php', 'proses_berita.php']),
                    self::tab('Galeri', '/admin/admin_galeri.php', ['admin_galeri.php']),
                    self::tab('Unduhan', '/admin/admin_download.php', ['admin_download.php']),
                ], 'Website'),
            ]],
            ['label' => 'Pengaturan', 'items' => [
                self::item('tahun', 'Tahun ajaran', '/admin/admin_tahun.php', 'fa-calendar-days', ['admin_tahun.php']),
                self::item('penugasan', 'Penugasan', '/admin/admin_penugasan.php', 'fa-id-badge', ['admin_penugasan.php', 'admin_murobi.php', 'admin_pembimbing.php']),
                self::item('akun', 'Akun & hak akses', '/admin/admin_akun.php', 'fa-user-lock', ['admin_akun.php', 'admin_akun_perizinan.php'], [], 'Akun'),
                self::item('kanal', 'Kanal notifikasi', '/admin/admin_notifikasi.php', 'fa-tower-broadcast', ['admin_notifikasi.php'], [], 'Kanal'),
                self::item('arsip', 'Data sistem lama', '/admin/admin_pelanggaran.php', 'fa-box-archive', ['admin_pelanggaran.php'], [], 'Arsip'),
            ]],
        ];
    }

    /** @return array<int, array{label:string, items:array<int, array<string, mixed>>}> */
    private static function guru(NavigationContext $c): array
    {
        $mengajar = [];
        if ($c->showPengajian()) {
            $mengajar[] = self::pengajian();
        }
        if ($c->own(PenugasanJenis::CAP_NILAI_INPUT)) {
            $mengajar[] = self::item('nilai', 'Nilai semester', '/portal/nilai_semester.php', 'fa-pen', ['nilai_semester.php'], [], 'Nilai');
        }

        $murobi = [];
        if ($c->izin(Capabilities::MUROBI)) {
            $murobi[] = self::item('antrean', 'Antrean izin', '/portal/izin_antrean.php', 'fa-inbox', [], [
                self::tab('Menunggu keputusan', '/portal/izin_antrean.php', ['izin_antrean.php']),
                self::tab('Riwayat', '/portal/izin.php', ['izin.php', 'izin_detail.php', 'izin_ringkasan.php', 'izin_aksi.php']),
                self::tab('Laporan', '/portal/laporan.php', ['laporan.php', 'laporan_cetak.php', 'laporan_csv.php']),
            ], 'Antrean');
        }
        if ($c->own(PenugasanJenis::CAP_SIKAP_MUROBI)) {
            $murobi[] = self::item('sikap_binaan', 'Sikap binaan', '/portal/sikap_semester.php?mode=murobi', 'fa-heart', ['sikap_semester.php?mode=murobi'], [], 'Sikap');
        }
        if ($c->kesehatanBinaan('murobi')) {
            // PRD V5 Fase 2: murobi membaca catatan kesehatan santri binaannya (hanya baca).
            $murobi[] = self::kesehatan();
        }
        if (isset($c->v3['v3.binaan.baca'])) {
            // V-6 (Tahap 5): murobi juga membaca laporan pembinaan binaannya.
            $murobi[] = self::item('pembinaan_binaan', 'Pembinaan binaan', '/portal/v3_pelanggaran.php', 'fa-scale-balanced', [], [
                self::tab('Pelanggaran', '/portal/v3_pelanggaran.php', ['v3_pelanggaran.php', 'v3_pelanggaran_detail.php', 'v3_lampiran.php']),
                self::tab('Konseling', '/portal/v3_konseling.php', ['v3_konseling.php', 'v3_konseling_detail.php', 'v3_konseling_cetak.php']),
                self::tab('Laporan', '/portal/v3_laporan.php', ['v3_laporan.php']),
            ], 'Pembinaan');
        }

        return [
            ['label' => '', 'items' => [self::beranda()]],
            ['label' => 'Mengajar', 'items' => $mengajar],
            ['label' => 'Murobi', 'items' => $murobi],
        ];
    }

    /** @return array<int, array{label:string, items:array<int, array<string, mixed>>}> */
    private static function pengurus(NavigationContext $c): array
    {
        $items = [];
        if ($c->izin(Capabilities::PENGURUS)) {
            $items[] = self::perizinan();
        }
        // Keputusan J: Sikap semester dan Pembinaan hanya bagi pengurus yang
        // memegang penugasan pembimbing aktif.
        if ($c->own(PenugasanJenis::CAP_SIKAP_INPUT)) {
            $items[] = self::item('sikap', 'Sikap semester', '/portal/sikap_semester.php', 'fa-heart', ['sikap_semester.php'], [], 'Binaan');
        }
        if (isset($c->v3['v3.pelanggaran.kelola'])) {
            $items[] = self::pembinaan(false);
        }

        // PRD V5: petugas kesehatan adalah pengurus dengan penugasan kesehatan aktif.
        $klinik = $c->kesehatan(true) ? [self::kesehatan()] : [];
        if ($klinik === [] && $c->kesehatanBinaan('pembimbing')) {
            // PRD V5 Fase 2: pembimbing membaca catatan kesehatan santri binaannya (hanya baca).
            $items[] = self::kesehatan();
        }

        return [
            ['label' => '', 'items' => [self::beranda()]],
            ['label' => 'Santri binaan', 'items' => $items],
            ['label' => 'Klinik', 'items' => $klinik],
        ];
    }

    /** @return array<int, array{label:string, items:array<int, array<string, mixed>>}> */
    private static function pendidikan(NavigationContext $c): array
    {
        $akademik = array_filter([self::penilaian($c, false)]);
        if ($c->own(PenugasanJenis::CAP_NILAI_PENGGANTI)) {
            $akademik[] = self::item('pengganti', 'Nilai pengganti', '/portal/nilai_semester.php?pengganti=1', 'fa-pen-to-square', ['nilai_semester.php?pengganti=1'], [], 'Pengganti');
        }

        // Keputusan H (Tahap 4): laporan kehadiran seluruh jadwal, hanya-baca.
        // Hak bacanya ditegakkan `admin/_laporan_guard.php` dan cakupan
        // laporan (`ReportFilter::forUser`), bukan oleh menu ini.
        $laporan = [];
        if ($c->own(PenugasanJenis::CAP_RAPOR_VERIFIKASI)) {
            $laporan[] = self::item('absensi', 'Laporan absensi pengajian', '/admin/admin_laporan_absensi.php', 'fa-clipboard-check',
                ['admin_laporan_absensi.php', 'laporan_absensi_detail.php', 'laporan_absensi_cetak.php', 'export_laporan_absensi.php'], [], 'Absensi');
        }

        return [
            ['label' => '', 'items' => [self::beranda()]],
            ['label' => 'Akademik', 'items' => array_values($akademik)],
            ['label' => 'Laporan', 'items' => $laporan],
        ];
    }

    /**
     * @param array<string, mixed> $notifikasi
     * @return array<int, array{label:string, items:array<int, array<string, mixed>>}>
     */
    private static function orangTua(NavigationContext $c, array $notifikasi): array
    {
        $items = [self::beranda()];
        if ($c->izin(Capabilities::ORANG_TUA)) {
            // I-7 (keputusan O): tab Aktif dan Riwayat. Ringkasan dan laporan
            // lama tetap dapat dibuka dan ditempatkan di bawah menu ini.
            $items[] = self::item('izin_anak', 'Izin anak', '/portal/izin.php', 'fa-door-open',
                ['izin_ringkasan.php', 'laporan.php', 'laporan_cetak.php', 'laporan_csv.php', 'izin_antrean.php'], [
                self::tab('Aktif', '/portal/izin.php', ['izin.php', 'izin_detail.php']),
                self::tab('Riwayat', '/portal/izin.php?tab=riwayat', ['izin.php?tab=riwayat']),
            ], 'Izin');
        }
        if ($c->raporWali()) {
            $items[] = self::item('rapor_wali', 'Rapor', '/portal/rapor_wali.php', 'fa-file-lines', ['rapor_wali.php', 'rapor_pdf.php'], [], 'Rapor');
        }
        if (isset($c->v3['v3.publikasi.baca'])) {
            // Keputusan F: orang tua melihat "Informasi pembinaan".
            // V-7 (Tahap 5): tab Belum dibaca · Semua; rekap cetak/CSV tetap di bawah menu ini.
            $items[] = self::item('info', 'Informasi pembinaan', '/portal/v3_publikasi.php', 'fa-envelope-open-text', ['v3_laporan.php'], [
                self::tab('Belum dibaca', '/portal/v3_publikasi.php?baca=belum', ['v3_publikasi.php?baca=belum']),
                self::tab('Semua', '/portal/v3_publikasi.php', ['v3_publikasi.php']),
            ], 'Info');
        }
        if ($c->kesehatanWali()) {
            // PRD V5 Fase 3: ringkasan kesehatan yang diterbitkan petugas klinik
            // untuk anak dengan relasi wali aktif (hak dicek ulang di layanan).
            $items[] = self::item('kesehatan_wali', 'Kesehatan anak', '/portal/kesehatan_wali.php', 'fa-kit-medical', ['kesehatan_wali.php'], [], 'Kesehatan');
        }
        // Keputusan K: notifikasi orang tua juga tampil sebagai menu.
        $items[] = $notifikasi;

        return [['label' => '', 'items' => $items]];
    }

    /**
     * @param array<int, array<string, mixed>> $tabs
     * @return array<string, mixed>
     */
    private static function beranda(array $tabs = []): array
    {
        return self::item('beranda', 'Beranda', '/portal/index.php', 'fa-house', ['index.php'], $tabs, 'Beranda');
    }

    /** @return array<string, mixed> */
    private static function perizinan(): array
    {
        return self::item('izin', 'Perizinan', '/portal/izin_ringkasan.php', 'fa-door-open', ['admin_izin.php'], [
            self::tab('Ringkasan', '/portal/izin_ringkasan.php', ['izin_ringkasan.php']),
            self::tab('Pengajuan', '/portal/izin.php', ['izin.php', 'izin_detail.php', 'izin_buat.php', 'izin_aksi.php']),
            self::tab('Antrean', '/portal/izin_antrean.php', ['izin_antrean.php']),
            self::tab('Laporan', '/portal/laporan.php', ['laporan.php', 'laporan_cetak.php', 'laporan_csv.php']),
        ], 'Perizinan');
    }

    /** @return array<string, mixed> */
    private static function kesehatan(): array
    {
        return self::item('kesehatan', 'Kesehatan', '/portal/kesehatan.php', 'fa-kit-medical',
            ['kesehatan.php', 'kesehatan_buat.php', 'kesehatan_detail.php', 'kesehatan_koreksi.php', 'kesehatan_hasil_rujukan.php', 'kesehatan_riwayat.php', 'kesehatan_ringkasan.php'], [], 'Kesehatan');
    }

    /** @return array<string, mixed> */
    private static function pengajian(): array
    {
        // Tab J-1 s.d. J-5 (cetak biru): Jadwal · Pertemuan · Kehadiran.
        return self::item('pengajian', 'Pengajian', '/admin/admin_pengajian.php', 'fa-book-open', [], [
            self::tab('Jadwal', '/admin/admin_pengajian.php', ['admin_pengajian.php', 'admin_jadwal_ngaji.php']),
            self::tab('Pertemuan', '/admin/admin_pengajian.php?tab=pertemuan', ['admin_pengajian.php?tab=pertemuan', 'pertemuan_pengajian.php']),
            self::tab('Kehadiran', '/admin/admin_laporan_absensi.php', ['admin_laporan_absensi.php', 'laporan_absensi_detail.php', 'laporan_absensi_cetak.php', 'export_laporan_absensi.php']),
        ], 'Pengajian');
    }

    /** @return array<string, mixed> */
    private static function pembinaan(bool $katalog): array
    {
        $tabs = [
            self::tab('Pelanggaran', '/portal/v3_pelanggaran.php', ['v3_pelanggaran.php', 'v3_pelanggaran_detail.php', 'v3_lampiran.php']),
            self::tab('Konseling', '/portal/v3_konseling.php', ['v3_konseling.php', 'v3_konseling_detail.php', 'v3_konseling_cetak.php']),
            // Keputusan E/F: staf menyebutnya "Publikasi ke orang tua".
            self::tab('Publikasi ke orang tua', '/portal/v3_publikasi_kelola.php', ['v3_publikasi_kelola.php', 'v3_publikasi.php']),
            self::tab('Laporan', '/portal/v3_laporan.php', ['v3_laporan.php']),
        ];
        if ($katalog) {
            $tabs[] = self::tab('Katalog & poin', '/admin/admin_v3_katalog.php', ['admin_v3_katalog.php']);
        }

        return self::item('pembinaan', 'Pembinaan', '/portal/v3_pelanggaran.php', 'fa-scale-balanced', [], $tabs, 'Pembinaan');
    }

    /**
     * Menu "Penilaian & rapor": tab sesuai hak akun. Admin juga melihat tab
     * pengawasan (nilai, sikap, penanda tangan, mata pelajaran) agar tidak ada
     * fungsi lama yang kehilangan jalan masuk.
     *
     * @return array<string, mixed>|null
     */
    private static function penilaian(NavigationContext $c, bool $admin): ?array
    {
        $tabs = [];
        if ($c->feature(PenugasanJenis::CAP_RAPOR_RENCANA)) {
            $tabs[] = self::tab('Rencana', '/portal/rencana_nilai.php', ['rencana_nilai.php']);
        }
        if ($c->feature(PenugasanJenis::CAP_RAPOR_VERIFIKASI)) {
            $tabs[] = self::tab('Kelengkapan', '/portal/verifikasi_semester.php', ['verifikasi_semester.php']);
        }
        if ($c->feature(PenugasanJenis::CAP_RAPOR_FINALISASI)) {
            $tabs[] = self::tab('Penerbitan', '/portal/rapor_semester.php', ['rapor_semester.php', 'rapor_pdf.php']);
        }
        if ($admin) {
            $tabs[] = self::tab('Penanda tangan', '/portal/rapor_penanda.php', ['rapor_penanda.php']);
            if ($c->feature(PenugasanJenis::CAP_NILAI_INPUT)) {
                $tabs[] = self::tab('Nilai semester', '/portal/nilai_semester.php', ['nilai_semester.php']);
            }
            if ($c->feature(PenugasanJenis::CAP_NILAI_PENGGANTI)) {
                $tabs[] = self::tab('Nilai pengganti', '/portal/nilai_semester.php?pengganti=1', ['nilai_semester.php?pengganti=1']);
            }
            if ($c->feature(PenugasanJenis::CAP_SIKAP_INPUT)) {
                $tabs[] = self::tab('Sikap semester', '/portal/sikap_semester.php', ['sikap_semester.php']);
            }
            // M-5 (Tahap 7): halaman sendiri; alamat tab lama dialihkan ke sini.
            $tabs[] = self::tab('Mata pelajaran', '/admin/admin_mata_pelajaran.php', ['admin_mata_pelajaran.php', 'admin_penugasan.php?jenis=mata_pelajaran']);
        }
        if ($tabs === []) {
            return null;
        }

        return self::item('rapor', 'Penilaian & rapor', self::path($tabs[0]['url']), 'fa-file-signature', [], $tabs, 'Rapor');
    }

    /**
     * Butir bilah bawah HP: Beranda, menu utama peran, lalu
     * Notifikasi (dan Menu, yang membuka laci, bila masih ada tempat).
     *
     * @param array<int, array{label:string, items:array<int, array<string, mixed>>}> $groups
     * @param array<int, array<string, mixed>> $extras
     * @return array<int, array<string, mixed>>
     */
    public static function bottomBar(array $groups, string $active, ?string $peran = null, array $extras = []): array
    {
        $beranda = self::find($groups, 'beranda');
        if ($beranda === null) {
            return [];
        }
        $bar = [$beranda];
        [$batas, $kandidat] = self::BOTTOM_BAR[$peran ?? ''] ?? [0, []];
        foreach ($kandidat as $key) {
            $item = self::find($groups, $key);
            if ($item !== null && count($bar) <= $batas) {
                $bar[] = $item;
            }
        }
        $notifikasi = self::find($groups, 'notifikasi') ?? self::findIn($extras, 'notifikasi');
        if ($notifikasi !== null) {
            $bar[] = $notifikasi;
        }
        foreach ($bar as &$item) {
            $item['short'] ??= $item['label'];
            $item['current'] = $item['key'] === $active;
        }
        unset($item);
        // Laci tetap dapat dibuka dari ≡ di topbar; butir "Menu" ditambahkan
        // bila bilah bawah masih punya tempat (paling banyak lima butir).
        if (count($bar) < 5 && $peran !== Peran::ORANG_TUA) {
            $bar[] = ['key' => '_menu', 'short' => 'Menu', 'label' => 'Menu', 'icon' => 'fa-bars', 'url' => '#ah-sidebar', 'current' => false, 'badge' => null, 'badge_label' => null];
        }

        return $bar;
    }

    /**
     * Menu dan tab yang boleh dibuka akun, untuk pencarian menu di peramban
     * (keputusan AQ). Tidak memuat data santri atau nomor izin.
     *
     * @param array<int, array{label:string, items:array<int, array<string, mixed>>}> $groups
     * @param array<int, array<string, mixed>> $extras
     * @return array<int, array{label:string, context:string, url:string}>
     */
    public static function searchIndex(array $groups, array $extras = []): array
    {
        $index = [];
        $seen = [];
        $push = static function (string $label, string $context, string $url) use (&$index, &$seen): void {
            $id = $label . '|' . $url;
            if (isset($seen[$id])) {
                return;
            }
            $seen[$id] = true;
            $index[] = ['label' => $label, 'context' => $context, 'url' => $url];
        };
        foreach ($groups as $group) {
            foreach ($group['items'] as $item) {
                $push($item['label'], $group['label'], $item['url']);
                foreach ($item['tabs'] as $tab) {
                    if ($tab['label'] !== $item['label']) {
                        $push($tab['label'], $item['label'], $tab['url']);
                    }
                }
            }
        }
        foreach ($extras as $item) {
            $push($item['label'], 'Akun', $item['url']);
        }

        return $index;
    }

    /**
     * Menu dan tab tempat sebuah halaman berada.
     *
     * Aturan halaman berupa nama skrip, opsional dengan parameter wajib
     * (`sikap_semester.php?mode=murobi`). Aturan dengan parameter lebih banyak
     * yang cocok menang, sehingga sub-halaman berparameter tidak tertukar
     * dengan halaman induknya.
     *
     * @param array<int, array{label:string, items:array<int, array<string, mixed>>}> $groups
     * @param array<int, array<string, mixed>> $extras
     * @param array<string, mixed>|null $query
     * @return array{key:string, tab:?int}|null
     */
    public static function locate(array $groups, array $extras = [], ?string $script = null, ?array $query = null): ?array
    {
        $script ??= basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        $query ??= $_GET;
        $best = null;
        $bestScore = -1;
        $consider = static function (array $item) use ($script, $query, &$best, &$bestScore): void {
            foreach ($item['pages'] as $rule) {
                $score = self::match($rule, $script, $query);
                if ($score > $bestScore) {
                    $bestScore = $score;
                    $best = ['key' => $item['key'], 'tab' => null];
                }
            }
            foreach ($item['tabs'] as $index => $tab) {
                foreach ($tab['pages'] as $rule) {
                    $score = self::match($rule, $script, $query);
                    // Halaman yang sekaligus menjadi tab menu induknya (mis.
                    // Beranda › Tugas saya) menyorot tab itu juga.
                    $samaInduk = $best !== null && $best['key'] === $item['key'] && $best['tab'] === null;
                    if ($score > $bestScore || ($score >= 0 && $score === $bestScore && $samaInduk)) {
                        $bestScore = $score;
                        $best = ['key' => $item['key'], 'tab' => $index];
                    }
                }
            }
        };
        foreach ($groups as $group) {
            foreach ($group['items'] as $item) {
                $consider($item);
            }
        }
        foreach ($extras as $item) {
            $consider($item);
        }

        return $best;
    }

    /**
     * @param array<int, array{label:string, items:array<int, array<string, mixed>>}> $groups
     * @return array<string, mixed>|null
     */
    public static function find(array $groups, string $key): ?array
    {
        foreach ($groups as $group) {
            $found = self::findIn($group['items'], $key);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<string, mixed>|null
     */
    private static function findIn(array $items, string $key): ?array
    {
        foreach ($items as $item) {
            if (($item['key'] ?? null) === $key) {
                return $item;
            }
        }

        return null;
    }

    /**
     * Skor kecocokan aturan halaman: -1 tidak cocok, 0 cocok nama skrip,
     * 1+ cocok dengan sejumlah parameter.
     *
     * @param array<string, mixed> $query
     */
    private static function match(string $rule, string $script, array $query): int
    {
        $parts = explode('?', $rule, 2);
        if ($parts[0] !== $script) {
            return -1;
        }
        if (!isset($parts[1])) {
            return 0;
        }
        parse_str($parts[1], $required);
        foreach ($required as $name => $value) {
            if (!isset($query[$name]) || (string) $query[$name] !== (string) $value) {
                return -1;
            }
        }

        return count($required);
    }

    private static function path(string $url): string
    {
        $base = rtrim(app_url(''), '/');

        return $base !== '' && str_starts_with($url, $base) ? substr($url, strlen($base)) : $url;
    }

    /**
     * @param array<int, string> $pages
     * @return array{label:string, url:string, pages:array<int, string>}
     */
    private static function tab(string $label, string $path, array $pages): array
    {
        return ['label' => $label, 'url' => app_url($path), 'pages' => $pages];
    }

    /**
     * @param array<int, string> $pages
     * @param array<int, array{label:string, url:string, pages:array<int, string>}> $tabs
     * @return array<string, mixed>
     */
    private static function item(string $key, string $label, string $path, string $icon, array $pages, array $tabs = [], ?string $short = null): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'url' => app_url($path),
            'icon' => $icon,
            'badge' => null,
            'badge_label' => null,
            'pages' => $pages,
            'tabs' => $tabs,
            'short' => $short ?? $label,
        ];
    }
}
