<?php

declare(strict_types=1);

namespace App\Report;

/**
 * Tata letak cetak bersama untuk laporan V1 dan V2.
 *
 * ===========================================================================
 * MENGAPA KELAS INI ADA
 * ===========================================================================
 * Sebelumnya nomor halaman mengandalkan CSS:
 *
 *     @page { @bottom-center { content: "Halaman " counter(page) } }
 *     .page-footer:after { content: "Halaman " counter(page) }
 *
 * Keduanya TIDAK bekerja pada mesin cetak peramban:
 *
 *  1. Kotak margin `@page { @bottom-center }` hanya didukung mesin Paged Media
 *     (Prince, WeasyPrint, Paged.js). Chrome, Safari, dan WebKit `expo-print`
 *     mengabaikannya sepenuhnya — jadi ia tidak pernah mencetak apa pun.
 *  2. `counter(page)` di dalam elemen `position: fixed` BUKAN penghitung
 *     konteks halaman. WebKit/Safari mengevaluasinya menjadi **0**, sehingga
 *     footer tercetak "Halaman 0". Chromium pun tidak konsisten.
 *  3. `position: fixed; bottom: -11mm` menaruh footer DI LUAR kotak halaman,
 *     sehingga mesin cetak membuat satu halaman hantu berisi footer saja.
 *
 * Karena tidak ada satu pun mekanisme CSS yang dapat diandalkan lintas mesin,
 * penomoran halaman dipindahkan ke SERVER: dokumen dipecah menjadi "lembar"
 * dengan tinggi yang dianggarkan, dan setiap lembar membawa teksnya sendiri
 * — "Halaman i dari n" — sebagai teks biasa. Nilainya dihitung PHP, sehingga
 * identik pada Chrome, Safari, `expo-print`, dan mesin apa pun berikutnya, dan
 * dapat diuji tanpa peramban.
 *
 * ===========================================================================
 * ANGGARAN TINGGI: SATU LEMBAR HARUS MUAT PADA KEDUA ORIENTASI
 * ===========================================================================
 * Orientasi kertas tidak dapat dipaksakan (Safari mengabaikan `@page size`),
 * jadi satu lembar harus muat pada A4 **lanskap maupun potret**. Keduanya
 * dianggarkan TERPISAH, masing-masing dengan lebar, tinggi, dan ukuran huruf
 * orientasinya sendiri:
 *
 *   - LANSKAP: lebar area 277 mm, tinggi 176 mm, huruf 8 pt.
 *   - POTRET : lebar area 190 mm, tinggi 263 mm, huruf 7,2 pt (lihat aturan
 *              `@media print and (orientation:portrait)` di bawah).
 *
 * Sebuah baris ditambahkan ke lembar berjalan hanya bila ia masih muat pada
 * KEDUA anggaran itu. Dengan demikian lembar yang sama dijamin utuh pada
 * kedua orientasi, tanpa harus menggabungkan "lebar tersempit" dan "tinggi
 * terpendek" menjadi satu perkiraan tunggal — cara lama yang benar, tetapi
 * membuat halaman lanskap hanya terisi separuh.
 *
 * Yang tidak berubah: kalau perkiraan meleset, yang gagal adalah PENGUJIAN,
 * bukan nomor halaman pengguna.
 *
 * Perkiraan ini diverifikasi terhadap PDF sungguhan oleh
 * `tests/v2_phase5_cetak_pdf.php`: jumlah halaman fisik PDF wajib sama dengan
 * jumlah lembar yang dihitung di sini. Bila konstanta di bawah meleset,
 * pengujian itu gagal — bukan diam-diam menghasilkan nomor halaman salah.
 */
final class PrintLayout
{
    /** Tinggi area cetak yang aman untuk A4 lanskap (210 mm − margin − cadangan). */
    public const TINGGI_AREA_MM = 176.0;

    /** Sinonim eksplisit dari `TINGGI_AREA_MM`, dipakai saat orientasi disebutkan. */
    public const TINGGI_AREA_LANSKAP_MM = 176.0;

    /** Tinggi area cetak A4 potret (297 mm − margin − cadangan). */
    public const TINGGI_AREA_POTRET_MM = 263.0;

    /** Lebar area cetak tersempit (A4 potret 210 mm − margin). */
    public const LEBAR_AREA_MM = 192.0;

    /** Lebar area cetak A4 potret. */
    public const LEBAR_AREA_POTRET_MM = 190.0;

    /** Lebar area cetak A4 lanskap (297 mm − margin). */
    public const LEBAR_AREA_LANSKAP_MM = 277.0;

    /**
     * Rasio ukuran huruf potret terhadap lanskap.
     *
     * CSS mengecilkan huruf menjadi 7,2 pt pada orientasi potret (8 pt × 0,9),
     * sehingga kapasitas karakter per kolom ikut bertambah. Angka ini WAJIB
     * sejalan dengan aturan `@media (orientation:portrait)` pada `cssDasar()`;
     * bila salah satunya berubah tanpa yang lain, pengujian PDF akan gagal.
     */
    public const SKALA_FONT_POTRET = 0.9;

    /**
     * Pengali tinggi kepala untuk orientasi potret.
     *
     * Kolom meta dan chip ringkasan lebih banyak membungkus pada halaman yang
     * lebih sempit, sehingga blok kepala tumbuh. Dilebihkan dengan sengaja;
     * anggaran tinggi potret memang jauh lebih longgar.
     */
    public const KEPALA_FAKTOR_POTRET = 1.6;

    /** Tinggi satu baris teks tabel pada 8pt, termasuk jarak antarbaris. */
    public const TINGGI_BARIS_MM = 3.5;

    /** Padding atas+bawah satu sel tabel. */
    public const PADDING_SEL_MM = 2.6;

    /**
     * Padding kiri+kanan satu sel tabel.
     *
     * WAJIB dikurangkan dari lebar kolom sebelum menghitung kapasitas
     * karakter. Melupakannya membuat kolom sempit tampak lebih lapang daripada
     * kenyataannya — persis penyebab header "Durasi" tercetak "Duras i".
     */
    public const PADDING_HORIZONTAL_MM = 2.2;

    /**
     * Lebar rata-rata satu karakter pada 8pt Arial.
     *
     * Sengaja dilebihkan dari rata-rata huruf kecil (±1,45 mm) karena isi
     * laporan banyak memakai HURUF KAPITAL (nama santri, nama kamar) yang
     * lebih lebar. Melebihkan hanya membuat perkiraan tinggi lebih aman;
     * meremehkannya membuat lembar meluber dan nomor halaman salah.
     */
    public const LEBAR_KARAKTER_MM = 1.70;

    /** Tinggi blok kepala lengkap (identitas + meta + ringkasan) pada lembar pertama. */
    public const TINGGI_KEPALA_PERTAMA_MM = 74.0;

    /** Tinggi blok kepala ringkas pada lembar kedua dan seterusnya. */
    public const TINGGI_KEPALA_LANJUTAN_MM = 18.0;

    /** Tinggi baris judul tabel (thead) yang diulang pada setiap lembar. */
    public const TINGGI_THEAD_MM = 9.0;

    /** Tinggi footer nomor halaman. */
    public const TINGGI_FOOTER_MM = 10.0;

    /**
     * Sekurang-kurangnya satu baris data selalu dimuat per lembar.
     *
     * Tanpa ini, satu baris yang sangat tinggi (alasan izin yang panjang sekali)
     * akan membuat perulangan pemecahan tidak pernah maju dan menghasilkan
     * lembar kosong tanpa henti.
     */
    public const MINIMAL_BARIS_PER_LEMBAR = 1;

    /**
     * Memperkirakan jumlah baris teks yang dibutuhkan satu sel.
     *
     * Pemisah `<br>` dihitung sebagai baris baru yang pasti. Sisanya dihitung
     * dari jumlah karakter dibagi kapasitas kolom, DIBULATKAN KE ATAS, karena
     * meremehkan tinggi jauh lebih berbahaya daripada melebihkannya: yang
     * pertama membuat lembar meluber dan nomor halaman salah.
     */
    public static function barisTeks(string $teks, float $lebarKolomMm, ?float $lebarKarakterMm = null): int
    {
        $lebarIsi = max(2.0, $lebarKolomMm - self::PADDING_HORIZONTAL_MM);
        $kapasitas = max(1, (int) floor($lebarIsi / ($lebarKarakterMm ?? self::LEBAR_KARAKTER_MM)));
        $total = 0;
        foreach (preg_split('/\R|<br\s*\/?>/i', $teks) ?: [''] as $penggal) {
            $panjang = mb_strlen(trim(strip_tags($penggal)));
            $total += max(1, (int) ceil($panjang / $kapasitas));
        }

        return max(1, $total);
    }

    /**
     * Tinggi satu baris tabel dalam mm, dari jumlah baris teks tertinggi
     * di antara seluruh selnya.
     */
    public static function tinggiBaris(int $barisTeksTerbanyak): float
    {
        return ($barisTeksTerbanyak * self::TINGGI_BARIS_MM) + self::PADDING_SEL_MM;
    }

    /**
     * Anggaran tinggi tabel yang tersedia pada satu lembar.
     *
     * Tinggi kepala dapat ditimpa karena laporan V1 (absensi) memakai blok
     * kepala yang lebih ringkas daripada laporan perizinan V2. Nilai bawaan
     * mempertahankan perilaku laporan perizinan.
     */
    public static function anggaranLembar(
        bool $lembarPertama,
        ?float $kepalaPertamaMm = null,
        ?float $kepalaLanjutanMm = null
    ): float {
        $kepala = $lembarPertama
            ? ($kepalaPertamaMm ?? self::TINGGI_KEPALA_PERTAMA_MM)
            : ($kepalaLanjutanMm ?? self::TINGGI_KEPALA_LANJUTAN_MM);

        return self::TINGGI_AREA_MM - $kepala - self::TINGGI_THEAD_MM - self::TINGGI_FOOTER_MM;
    }

    /**
     * Memecah tinggi baris menjadi lembar-lembar.
     *
     * @param array<int, float> $tinggiBaris tinggi tiap baris data, dalam mm
     * @return array<int, array<int, int>> daftar lembar, tiap lembar berisi
     *                                     indeks baris yang dimuatnya
     */
    public static function pecahLembar(
        array $tinggiBaris,
        ?float $kepalaPertamaMm = null,
        ?float $kepalaLanjutanMm = null
    ): array {
        if ($tinggiBaris === []) {
            // Dokumen tanpa baris tetap menghasilkan SATU lembar, supaya
            // laporan kosong tetap memiliki kepala, footer, dan "Halaman 1
            // dari 1" — bukan dokumen tanpa halaman sama sekali.
            return [[]];
        }

        $lembar = [];
        $sekarang = [];
        $terpakai = 0.0;
        $anggaran = self::anggaranLembar(true, $kepalaPertamaMm, $kepalaLanjutanMm);

        foreach ($tinggiBaris as $indeks => $tinggi) {
            $muat = $terpakai + $tinggi <= $anggaran;
            if (!$muat && count($sekarang) >= self::MINIMAL_BARIS_PER_LEMBAR) {
                $lembar[] = $sekarang;
                $sekarang = [];
                $terpakai = 0.0;
                $anggaran = self::anggaranLembar(false, $kepalaPertamaMm, $kepalaLanjutanMm);
            }
            $sekarang[] = $indeks;
            $terpakai += $tinggi;
        }
        if ($sekarang !== []) {
            $lembar[] = $sekarang;
        }

        return $lembar;
    }

    /**
     * Tinggi satu baris tabel pada satu orientasi tertentu.
     *
     * @param array<int, string> $sel teks tiap kolom pada baris ini
     * @param array<int, float|int> $lebarKolomPersen lebar kolom dalam persen
     */
    public static function tinggiBarisOrientasi(
        array $sel,
        array $lebarKolomPersen,
        float $lebarAreaMm,
        float $lebarKarakterMm
    ): float {
        $terbanyak = 1;
        foreach ($sel as $kolom => $teks) {
            $persen = (float) ($lebarKolomPersen[$kolom] ?? 100 / max(1, count($sel)));
            $terbanyak = max(
                $terbanyak,
                self::barisTeks($teks, $lebarAreaMm * ($persen / 100), $lebarKarakterMm)
            );
        }

        return self::tinggiBaris($terbanyak);
    }

    /**
     * Memecah baris tabel menjadi lembar yang muat pada KEDUA orientasi.
     *
     * Inilah jalur yang dipakai kedua laporan. Sebuah baris hanya ditambahkan
     * ke lembar berjalan bila tinggi kumulatifnya masih muat pada anggaran
     * lanskap DAN anggaran potret sekaligus, sehingga satu berkas HTML yang
     * sama menghasilkan jumlah halaman fisik yang sama pada orientasi mana pun
     * — dan karena itu nomor halamannya selalu cocok.
     *
     * @param array<int, array<int, string>> $barisSel teks sel per baris
     * @param array<int, float|int> $lebarKolomPersen lebar kolom dalam persen
     * @return array<int, array<int, int>> daftar lembar berisi indeks baris
     */
    public static function pecahLembarKolom(
        array $barisSel,
        array $lebarKolomPersen,
        ?float $kepalaPertamaMm = null,
        ?float $kepalaLanjutanMm = null
    ): array {
        if ($barisSel === []) {
            return [[]];
        }

        $kepalaPertama = $kepalaPertamaMm ?? self::TINGGI_KEPALA_PERTAMA_MM;
        $kepalaLanjutan = $kepalaLanjutanMm ?? self::TINGGI_KEPALA_LANJUTAN_MM;

        $tinggiLanskap = [];
        $tinggiPotret = [];
        foreach ($barisSel as $sel) {
            $tinggiLanskap[] = self::tinggiBarisOrientasi(
                $sel,
                $lebarKolomPersen,
                self::LEBAR_AREA_LANSKAP_MM,
                self::LEBAR_KARAKTER_MM
            );
            $tinggiPotret[] = self::tinggiBarisOrientasi(
                $sel,
                $lebarKolomPersen,
                self::LEBAR_AREA_POTRET_MM,
                self::LEBAR_KARAKTER_MM * self::SKALA_FONT_POTRET
            );
        }

        $anggaran = static function (bool $pertama) use ($kepalaPertama, $kepalaLanjutan): array {
            $kepala = $pertama ? $kepalaPertama : $kepalaLanjutan;

            return [
                self::TINGGI_AREA_LANSKAP_MM - $kepala - self::TINGGI_THEAD_MM - self::TINGGI_FOOTER_MM,
                self::TINGGI_AREA_POTRET_MM - ($kepala * self::KEPALA_FAKTOR_POTRET)
                    - self::TINGGI_THEAD_MM - self::TINGGI_FOOTER_MM,
            ];
        };

        $lembar = [];
        $sekarang = [];
        $pakaiLanskap = 0.0;
        $pakaiPotret = 0.0;
        [$batasLanskap, $batasPotret] = $anggaran(true);

        foreach ($barisSel as $indeks => $_sel) {
            $muat = ($pakaiLanskap + $tinggiLanskap[$indeks] <= $batasLanskap)
                && ($pakaiPotret + $tinggiPotret[$indeks] <= $batasPotret);
            if (!$muat && count($sekarang) >= self::MINIMAL_BARIS_PER_LEMBAR) {
                $lembar[] = $sekarang;
                $sekarang = [];
                $pakaiLanskap = 0.0;
                $pakaiPotret = 0.0;
                [$batasLanskap, $batasPotret] = $anggaran(false);
            }
            $sekarang[] = $indeks;
            $pakaiLanskap += $tinggiLanskap[$indeks];
            $pakaiPotret += $tinggiPotret[$indeks];
        }
        if ($sekarang !== []) {
            $lembar[] = $sekarang;
        }

        return $lembar;
    }

    /**
     * Footer nomor halaman — teks biasa, dihitung server.
     *
     * TIDAK memakai `counter(page)`. Inilah satu-satunya sumber nomor halaman
     * pada dokumen, sehingga tidak mungkin muncul "Halaman 0" dan tidak mungkin
     * ada dua penomoran yang saling bertentangan.
     */
    public static function footerHalaman(int $halaman, int $total, string $catatan = ''): string
    {
        $teks = 'Halaman ' . $halaman . ' dari ' . $total;

        return '<div class="lembar-footer">'
            . ($catatan === '' ? '' : '<span class="lembar-catatan">' . self::e($catatan) . '</span>')
            . '<span class="lembar-nomor">' . self::e($teks) . '</span>'
            . '</div>';
    }

    /**
     * Petunjuk orientasi yang tampil di layar, tersembunyi saat mencetak.
     *
     * Safari dan dialog cetak macOS mengabaikan `@page { size: A4 landscape }`,
     * sehingga pengguna perlu diberi tahu secara eksplisit. Halaman tetap
     * terbaca bila petunjuk ini diabaikan — ia mempercantik, bukan menyelamatkan.
     */
    public static function petunjukOrientasi(): string
    {
        return '<div class="petunjuk-cetak" role="note">'
            . '<strong>Tip cetak:</strong> pilih orientasi <strong>Lanskap (Landscape)</strong> '
            . 'dan ukuran <strong>A4</strong> pada dialog cetak agar seluruh kolom tampil paling lega. '
            . 'Sebagian peramban (misalnya Safari) mengabaikan pengaturan orientasi dari halaman, '
            . 'sehingga orientasi perlu dipilih sendiri. Hasil potret tetap terbaca dan nomor '
            . 'halamannya tetap benar.'
            . '</div>';
    }

    /**
     * CSS cetak bersama.
     *
     * Perbedaan penting dari versi sebelumnya:
     *
     *  - `overflow-wrap: break-word` (bukan `anywhere`). `anywhere` mengizinkan
     *    mesin memotong di TENGAH kata pada posisi mana pun demi mempersempit
     *    kolom — itulah yang menghasilkan "N o.", "Sumb er", dan "Disetuju i"
     *    pada PDF produksi. `break-word` hanya memotong kata yang benar-benar
     *    lebih lebar daripada kolomnya.
     *  - `word-break: normal` ditegaskan agar tidak ada aturan lain yang
     *    menghidupkan pemotongan agresif.
     *  - tidak ada `counter(page)` dan tidak ada elemen `position: fixed`.
     *  - `@page { size: A4 landscape }` DIPERTAHANKAN karena Chrome
     *    menghormatinya; ia menjadi peningkatan, bukan syarat kebenaran.
     */
    public static function cssDasar(): string
    {
        return '@page{size:A4 landscape;margin:12mm 10mm}'
            . '*{box-sizing:border-box}'
            . 'body{font-family:Arial,Helvetica,sans-serif;color:#17231c;font-size:8pt;margin:0;padding:10px;'
            . 'overflow-wrap:break-word;word-break:normal}'
            . '.report-nav{margin-bottom:10px}'
            . '.petunjuk-cetak{border:1px solid #9dbfa8;background:#eef6f0;border-radius:6px;'
            . 'padding:8px 10px;margin-bottom:12px;font-size:9pt;line-height:1.45}'
            . 'h1{font-size:16pt;margin:0 0 2px}h2{font-size:11pt;margin:0 0 3px}'
            . '.identity{border-bottom:2px solid #176b3a;padding-bottom:6px;margin-bottom:6px}'
            . '.meta{display:grid;grid-template-columns:1fr 1fr;gap:2px 16px;margin-bottom:7px}'
            . '.summary{display:flex;flex-wrap:wrap;gap:5px;margin:6px 0 8px}'
            . '.summary span{border:1px solid #ccd8cf;border-radius:5px;padding:3px 6px}'
            . '.muted{color:#59665e;font-size:7pt}'
            // Token yang tidak boleh pecah sama sekali (tanggal YYYY-MM-DD).
            // Tanpa ini mesin cetak memecahnya pada tanda hubung menjadi
            // "2026-08-" / "25", yang membingungkan pada laporan tanggal.
            . '.utuh{white-space:nowrap}'
            . '.peringatan{border:1px solid #d0a34e;background:#fdf6e6;padding:6px 8px;border-radius:5px;margin:0 0 8px}'
            // Tabel: kolom tetap, tetapi pemotongan kata hanya sebagai upaya terakhir.
            . 'table{width:100%;border-collapse:collapse;table-layout:fixed}'
            . 'th,td{border:1px solid #bac8bd;padding:3px 4px;vertical-align:top;'
            . 'overflow-wrap:break-word;word-break:normal;hyphens:none}'
            // Judul kolom TIDAK BOLEH dipotong di tengah kata dalam keadaan
            // apa pun: lebar kolom sudah dipilih agar setiap kata judul muat.
            // `overflow-wrap:normal` membuat mesin cetak membungkus hanya pada
            // spasi, sehingga "Durasi" tidak pernah menjadi "Duras i".
            . 'th{background:#e8f2ea;text-align:left;font-size:7.5pt;overflow-wrap:normal}'
            // Satu baris data tidak boleh terbelah antarhalaman (kriteria 8).
            . 'tr,td,th{break-inside:avoid;page-break-inside:avoid}'
            . '.empty{text-align:center;padding:18px}'
            . '.tanda-tangan{margin-top:10px;font-size:7.5pt;color:#59665e}'
            // Lembar = satu halaman fisik. Pemisahnya eksplisit, bukan hasil
            // tebakan mesin cetak, sehingga nomor halaman selalu cocok.
            . '.lembar{break-after:page;page-break-after:always}'
            . '.lembar:last-of-type{break-after:auto;page-break-after:auto}'
            . '.lembar-footer{display:flex;justify-content:space-between;align-items:baseline;gap:10px;'
            . 'margin-top:8px;padding-top:5px;border-top:1px solid #ccd8cf;font-size:7.5pt;color:#555}'
            . '.lembar-nomor{white-space:nowrap;font-weight:700}'
            . '.lembar-catatan{color:#59665e}'
            . '.lanjutan{display:flex;justify-content:space-between;align-items:baseline;gap:10px;'
            . 'border-bottom:1px solid #ccd8cf;padding-bottom:5px;margin-bottom:7px}'
            . '.lanjutan strong{font-size:10pt}'
            . '@media screen{.lembar{border:1px solid #dde5e0;border-radius:8px;padding:14px;margin-bottom:16px}}'
            . '@media print{.report-nav,.petunjuk-cetak{display:none!important}'
            . 'body{print-color-adjust:exact;-webkit-print-color-adjust:exact;padding:0}'
            // Pada potret kolom menyempit; huruf sedikit dikecilkan agar tetap
            // terbaca. Arah perubahan ini AMAN terhadap anggaran tinggi: huruf
            // lebih kecil berarti baris lebih sedikit, tidak pernah lebih banyak.
            . '@media (orientation:portrait){body{font-size:7.2pt}th{font-size:6.8pt}'
            . 'h1{font-size:14pt}h2{font-size:10pt}}'
            . '}';
    }

    public static function e(mixed $value): string
    {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
