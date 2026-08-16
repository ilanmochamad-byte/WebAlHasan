-- phpMyAdmin SQL Dump
-- version 5.2.3
-- https://www.phpmyadmin.net/
--
-- Host: localhost:3306
-- Generation Time: Aug 16, 2026 at 08:25 PM
-- Server version: 10.6.24-MariaDB-cll-lve
-- PHP Version: 8.4.24

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `k1807225_webalhasan`
--

-- --------------------------------------------------------

--
-- Table structure for table `alumni`
--

CREATE TABLE `alumni` (
  `id` int(11) NOT NULL,
  `nis` varchar(20) NOT NULL,
  `nama_santri` varchar(100) NOT NULL,
  `jenis_kelamin` enum('L','P') NOT NULL,
  `tempat_lahir` varchar(50) NOT NULL,
  `tgl_lahir` date NOT NULL,
  `alamat` text NOT NULL,
  `desa` varchar(50) NOT NULL,
  `kecamatan` varchar(50) NOT NULL,
  `kab_kota` varchar(50) NOT NULL,
  `provinsi` varchar(50) NOT NULL,
  `nama_ayah` varchar(100) NOT NULL,
  `no_hp_ayah` varchar(20) DEFAULT NULL,
  `nama_ibu` varchar(100) NOT NULL,
  `no_hp_ibu` varchar(20) DEFAULT NULL,
  `asal_sekolah` varchar(100) NOT NULL,
  `unit_terakhir` varchar(50) NOT NULL,
  `tahun_angkatan` varchar(10) NOT NULL,
  `tingkat` enum('Ibtida','Tsanawi') NOT NULL,
  `status_keluar` enum('Lulus','Pindah','Berhenti') NOT NULL,
  `tgl_keluar` date NOT NULL,
  `foto` varchar(255) DEFAULT 'default.jpg'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `berita`
--

CREATE TABLE `berita` (
  `id` int(11) NOT NULL,
  `judul` varchar(255) NOT NULL,
  `isi_berita` text NOT NULL,
  `gambar` varchar(255) NOT NULL,
  `tanggal` date DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `berita`
--

INSERT INTO `berita` (`id`, `judul`, `isi_berita`, `gambar`, `tanggal`) VALUES
(1, 'Quran Kemenag Android Tembus Satu Juta Pengguna di Akhir 2025', '<p>Jakarta (Kemenag) --- Aplikasi Quran Kemenag versi Android menutup tahun ini dengan sebuah capaian. Hingga akhir Desember 2025, aplikasi yang dikembangkan Lajnah Pentashihan Mushaf Al-Qurâ€™an (LPMQ) Kemenag ini telah digunakan lebih dari satu juta pengguna di seluruh Indonesia.</p><p>Kepala LPMQ Kementerian Agama, &nbsp;Abdul Aziz Sidqi, menyebut capaian tersebut sebagai karunia Allah Swt. sekaligus amanah besar bagi pengelola layanan Al-Qurâ€™an digital Kemenag. â€œAlhamdulillah, ini adalah niâ€˜mat Allah di penghujung tahun dan bukti kepercayaan umat kepada Quran Kemenag,â€ ujarnya di Jakarta, Rabu (31/12/2025). </p><p>Ia menjelaskan bahwa kepercayaan umat tersebut harus dijawab dengan pengembangan fitur yang terus disesuaikan dengan kebutuhan pengguna. Sejumlah fitur baru telah dihadirkan, di antaranya mushaf qirÄâ€™Ät, terjemah Al-Qurâ€™an dalam berbagai bahasa daerah, kalender hijriah bulanan, serta deteksi lokasi otomatis berbasis GPS untuk mendukung layanan keislaman yang lebih akurat dan personal.</p><p>â€œQuran Kemenag tidak hanya kami jaga dari sisi ketepatan mushaf dan terjemahan, tetapi juga terus kami kembangkan agar semakin relevan dengan kebutuhan umat di era digital,â€ tegasnya.</p><p>Kepala Badan Moderasi Beragama dan Pengembangan Sumber Daya Manusia (BMBPSDM) Kemenag, Muhammad Ali Ramdhani, menilai pencapaian satu juta pengguna sebagai indikator keberhasilan transformasi digital Kementerian Agama.</p><p>â€œKepercayaan satu juta pengguna menunjukkan bahwa umat membutuhkan Al-Qurâ€™an digital yang resmi, kredibel, dan adaptif terhadap perkembangan teknologi,â€ katanya.</p><p>Menurutnya, kehadiran fitur-fitur baru tersebut memperkuat peran Quran Kemenag tidak hanya sebagai aplikasi baca, tetapi juga sebagai media pembelajaran Al-Qurâ€™an yang inklusif dan mencerahkan.</p><p>Menutup 2025, capaian ini menjadi momentum refleksi dan syukur atas niâ€˜mat Allah Swt. Ke depan, Kementerian Agama melalui LPMQ berkomitmen untuk terus meningkatkan khidmat kepada Al-Qurâ€™an melalui inovasi berkelanjutan, agar Quran Kemenag senantiasa menjadi pilihan utama umat dalam berinteraksi dengan Kitab Suci. </p><p>Sumber: <a href=\"https://kemenag.go.id/nasional/quran-kemenag-android-tembus-satu-juta-pengguna-di-akhir-2025-HG2GZ\" target=\"_blank\">Berita Nasional KEMENAG RI</a></p>', '664094660_01KDTH296M7M3325KDH1BS6Y8B.jpeg', '2026-01-01'),
(2, 'Literasi Al-Qurâ€™an akan Jadi Syarat Rekrutmen dan Karir Guru PAI', '<p>Jakarta (Kemenag) --- Direktur Jenderal Pendidikan Islam (Dirjen Pendis) Kementerian Agama (Kemenag) Amin Suyitno menyampaikan literasi Al-Qurâ€™an akan menjadi syarat rekrutmen, sertifikasi, dan pengembangan karir guru Pendidikan Agama Islam (PAI). Penegasan ini disampaikan Amin Suyitno usai merilis hasil Asesmen Pendidikan Agama Islam (PAI) 2025, di Jakarta.</p><p>Ia menambahkan bahwa kompetensi membaca Al-Qurâ€™an akan terintegrasi langsung dalam seluruh siklus pengelolaan guru. â€œKe depan, penguatan kompetensi membaca Al-Qurâ€™an harus menjadi bagian integral dari rekrutmen, sertifikasi, hingga penilaian kinerja guru PAI,â€ tegas Dirjen Pendis Amin Suyitno, Selasa (30/12/2025). </p><p>Kebijakan ini didasarkan atas hasil asesmen terhadap 160.143 guru PAI SD/SDLB. Penilaian yang dilakukan melalui aplikasi SIAGA Kementerian Agama menunjukkan bahwa 58,26 persen masih berada pada kategori pratama atau dasar dalam membaca Al-Qurâ€™an.</p><p>Sebanyak 30,4 persen berada pada kategori madya dan 11,3 persen telah mencapai kategori mahir. Data ini dikumpulkan melalui metode triangulasi oleh Lembaga Taá¸¥sin dan Taá¸¥fÄ«áº“ Al-Qurâ€™an (LTTQ) Universitas PTIQ Jakarta dengan tingkat kepercayaan tinggi pada agregat nasional dan daerah.</p><p>Rata-rata Indeks Membaca Al-Qurâ€™an guru PAI SD/SDLB tercatat 57,17. Analisis indikator menunjukkan bahwa pemahaman hukum bacaan tajwid menjadi aspek yang paling membutuhkan penguatan.</p><p>Dirjen Pendis Amin Suyitno, menegaskan bahwa temuan ini menjadi landasan kuat reformasi sistem kepegawaian guru PAI. â€œGuru PAI adalah ujung tombak pendidikan keagamaan di sekolah. Ketika lebih dari separuh guru PAI SD belum fasih membaca Al-Qurâ€™an, ini menjadi tantangan serius yang harus dijawab dengan kebijakan yang sistematis dan berkelanjutan,â€ ujar Suyitno.</p><p>Direktur Pendidikan Agama Islam, M. Munir, menilai bahwa arah kebijakan ini tepat karena menyentuh akar mutu pembelajaran.\r\nâ€œData ini sangat jelas menunjukkan bahwa persoalan utama bukan hanya pada aspek pedagogik, tetapi pada kompetensi dasar guru PAI itu sendiri, khususnya kemampuan membaca Al-Qurâ€™an secara tartil dan sesuai kaidah tajwid,â€ kata Munir.</p><p>â€œJika guru masih terbata-bata atau belum memahami tajwid dengan baik, maka proses transfer literasi Al-Qurâ€™an kepada siswa akan ikut terdampak. Ini menjelaskan mengapa kemampuan membaca Al-Qurâ€™an siswa SD juga masih didominasi kategori dasar,â€ lanjutnya.</p><p>Melalui kebijakan ini, Kemenag akan mereorientasi sertifikasi guru PAI, menyusun standar kompetensi berbasis literasi Al-Qurâ€™an, memperkuat kemitraan dengan pesantren dan PTKI, serta membangun sistem evaluasi berkala melalui asesmen nasional sebagai mekanisme kendali mutu berkelanjutan.</p><p>Sumber: <a href=\"https://kemenag.go.id/nasional/literasi-al-quran-akan-jadi-syarat-rekrutmen-dan-karir-guru-pai-iNM5U\" target=\"_blank\">Berita Nasional KEMENAG RI</a></p>', '303918756_01KDQXVG03K0QVV62FG62MWF0F.jpg', '2026-01-01'),
(3, 'Riset Kemenag, Gen Z Lebih Toleran dari Milenial dan Baby Boomers', '<p>Jakarta (Kemenag) --- Direktorat Jenderal Bimbingan Masyarakat Islam Kementerian Agama bekerja sama dengan Alvara Strategic Research merilis Survei Indeks Kualitas Kehidupan Beragama Umat Islam Tahun 2025. Hasil survei menunjukkan dinamika positif kehidupan beragama, terutama di kalangan generasi muda.</p><p><span style=\"font-size: var(--bs-body-font-size); text-align: var(--bs-body-text-align);\">Survei tersebut mencatat tingkat toleransi beragama Generasi Z (Gen Z) di atas generasi Milenial dan Baby Boomers. Gen C juga masuk kelompok tertinggi dalam kemampuan membaca Al-Quran dibandingkan generasi lainnya. Temuan ini dinilai menjadi sinyal optimis bagi masa depan kehidupan beragama di Indonesia.</span></p><p><span style=\"font-size: var(--bs-body-font-size); text-align: var(--bs-body-text-align);\">Direktur Urusan Agama Islam dan Bina Syariah Kementerian Agama, Arsad Hidayat, menyebut hasil survei ini sebagai capaian yang menggembirakan dan patut dijadikan rujukan kebijakan.</span></p><p><span style=\"font-size: var(--bs-body-font-size); text-align: var(--bs-body-text-align);\">â€œHasilnya cukup menggembirakan dan memberikan optimisme. Laporan ini idealnya menjadi acuan bagi para pengambil kebijakan untuk merumuskan langkah strategis dalam meningkatkan kualitas kehidupan beragama di tanah air,â€ ujar Arsad di Jakarta, Rabu (31/12/2025).</span></p><p><span style=\"font-size: var(--bs-body-font-size); text-align: var(--bs-body-text-align);\">Menurutnya, survei ini sejalan dengan visi Asta Cita Pemerintah, khususnya dalam penguatan sumber daya manusia dan kerukunan sosial. \"Fokus utamanya adalah membangun sumber daya manusia yang unggul sekaligus memperkokoh kerukunan dan cinta kemanusiaan sebagai fondasi stabilitas nasional,â€ katanya.</span></p><p><span style=\"font-size: var(--bs-body-font-size); text-align: var(--bs-body-text-align);\">Ia menekankan pentingnya menjaga capaian positif generasi muda, terutama dalam aspek toleransi dan literasi keagamaan. â€œPenguatan aspek yang sudah baik, seperti toleransi dan literasi kitab suci pada generasi muda, harus terus dikawal agar menjadi karakter permanen bangsa,â€ tegas Arsad.</span></p><p><span style=\"font-size: var(--bs-body-font-size); text-align: var(--bs-body-text-align);\">Peneliti Alvara Strategic Research, Lilik Purwandi, mengatakan, Gen Z memiliki peran strategis sebagai motor penggerak menuju Indonesia Emas 2045. Survei ini, menurutnya, mencatat sejumlah indikator positif yang memperkuat optimisme tersebut.</span></p><p><span style=\"font-size: var(--bs-body-font-size); text-align: var(--bs-body-text-align);\">Pertama, literasi Al-Quran, terutama kemampuan membaca Al-Quran di kalangan Gen Z menunjukkan hasil positif, bahkan indeksnya tertinggi jika dibandingkan generasi lainnya. Indeks kemampuan membaca Al-Qurâ€™an dengan tartil pada Gen Z tercatat sebesar 56,29, lebih tinggi dibandingkan Milenial (54,06), Generasi X (53,97), dan Baby Boomers (50,95).\r\n</span></p><p><span style=\"font-size: var(--bs-body-font-size); text-align: var(--bs-body-text-align);\">\r\nKedua, Gen Z mencatat skor tertinggi dalam salah satu indikator toleransi beragama. Pada indikator sikap tidak membubarkan kegiatan keagamaan aliran atau organisasi keagamaan lain, Gen Z meraih indeks 80,03, melampaui Milenial (78,77), Generasi X (78,97), dan Baby Boomers (78,81).</span></p><p><span style=\"font-size: var(--bs-body-font-size); text-align: var(--bs-body-text-align);\">Secara keseluruhan, indeks pengamalan toleransi Gen Z berada pada angka 79,65, hanya terpaut tipis dari Generasi X yang mencatat 79,67, dan lebih tinggi dibandingkan Milenial (79,07) dan Baby Boomers (78, 63). Indikator toleransi mencakup penerimaan terhadap pelaksanaan ibadah agama lain, sikap tidak mencela, tidak melakukan persekusi, serta tidak menyebarkan ujaran kebencian.</span></p><p><span style=\"font-size: var(--bs-body-font-size); text-align: var(--bs-body-text-align);\">â€œData ini menunjukkan Gen Z memiliki kedewasaan sikap yang luar biasa dalam menghargai perbedaan. Mereka adalah generasi yang paling menolak praktik persekusi atau pembubaran kegiatan keagamaan pihak lain,â€ ujar Lilik.</span></p><p><span style=\"font-size: var(--bs-body-font-size); text-align: var(--bs-body-text-align);\">Ketiga, survei juga mencatat potensi positif kehidupan beragama di wilayah perkotaan yang didominasi populasi muda. Meski indeks dimensi ibadah masyarakat urban (78,38) sedikit lebih rendah dibandingkan perdesaan (79,37), pemahaman keagamaan yang kuat dinilai menjadi modal penting bagi pengembangan spiritual ke depan.\r\n\r\nâ€œGen Z dan Milenial adalah pilar masa depan.</span></p><p><span style=\"font-size: var(--bs-body-font-size); text-align: var(--bs-body-text-align);\">Walaupun terdapat tantangan dalam pengamalan ibadah harian, modal intelektual melalui pemahaman Al-Qurâ€™an dan sikap toleransi yang matang merupakan aset besar bagi kohesi sosial,â€ kata Lilik.</span></p><p><span style=\"font-size: var(--bs-body-font-size); text-align: var(--bs-body-text-align);\">Survei ini menggunakan pendekatan kuantitatif dengan cakupan nasional, melibatkan 1.208 responden Muslim di 34 provinsi. Metode yang digunakan adalah multistage random sampling melalui wawancara tatap muka langsung. Margin of error tercatat 2,89 persen dengan tingkat kepercayaan 95 persen.</span></p><p><span style=\"font-size: var(--bs-body-font-size); text-align: var(--bs-body-text-align);\">Secara nasional, Indeks Kualitas Kehidupan Beragama Umat Islam Tahun 2025 berada pada angka 78,80 dan masuk kategori tinggi. Dimensi Akhlak menjadi aspek dengan skor tertinggi, yakni 81,88.</span></p><p><span style=\"font-size: var(--bs-body-font-size); text-align: var(--bs-body-text-align);\">Hasil survei ini diharapkan dapat menjadi dasar bagi perumusan program pembinaan keagamaan yang lebih relevan dengan karakter Gen Z dan Milenial, sehingga penguatan toleransi dan literasi keagamaan dapat berjalan seiring dengan peningkatan pengamalan ibadah secara berkelanjutan.\r\n\r\n(Mwr/Mr)</span></p><p><span style=\"font-size: var(--bs-body-font-size); text-align: var(--bs-body-text-align);\">Sumber: </span><a href=\"https://kemenag.go.id/nasional/riset-kemenag-gen-z-lebih-toleran-dari-milenial-dan-baby-boomers-XXSu2\" target=\"_blank\">Berita Nasional KEMENAG RI</a></p>', '140323509_01KDSS2E17YZ14CY2SCNYK7564.jpeg', '2026-01-01');

-- --------------------------------------------------------

--
-- Table structure for table `download`
--

CREATE TABLE `download` (
  `id` int(11) NOT NULL,
  `judul` varchar(255) NOT NULL,
  `nama_file` varchar(255) NOT NULL,
  `tanggal` date DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `download`
--

INSERT INTO `download` (`id`, `judul`, `nama_file`, `tanggal`) VALUES
(2, 'Buku Panduan Santri 2026', '1395998881_BUKU PANDUAN SANTRI BARU.pdf', '2026-07-02');

-- --------------------------------------------------------

--
-- Table structure for table `galeri`
--

CREATE TABLE `galeri` (
  `id` int(11) NOT NULL,
  `judul` varchar(255) NOT NULL,
  `keterangan` text DEFAULT NULL,
  `gambar` varchar(255) NOT NULL,
  `tanggal` date DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `galeri`
--

INSERT INTO `galeri` (`id`, `judul`, `keterangan`, `gambar`, `tanggal`) VALUES
(1, 'Libur Semester Ganjil 2025/2026 SMK Terpadu Al Hasan', 'Terhitung tanggal 25 Desember 2025 - 10 Januari 2026, kegiatan Belajar Mengajar di Pondok Pesantren Al Hasan diistirahatkan.', '175716945_Libur Semester Ganjil.jpg', '2026-01-02');

-- --------------------------------------------------------

--
-- Table structure for table `guru`
--

CREATE TABLE `guru` (
  `id` int(11) NOT NULL,
  `nip` varchar(30) DEFAULT NULL,
  `nama_guru` varchar(100) NOT NULL,
  `no_hp` varchar(15) DEFAULT NULL,
  `status` enum('Guru','Pembimbing','Keduanya') NOT NULL DEFAULT 'Guru'
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `guru`
--

INSERT INTO `guru` (`id`, `nip`, `nama_guru`, `no_hp`, `status`) VALUES
(2, '2627006', 'ILAN MOCHAMAD FAUZAN', '082119021212', 'Guru'),
(3, '2627016', 'WAWAN GUSMAWAN, S.Kom.', '085316351442', 'Guru'),
(4, '2627007', 'H. NIZAR MUHAMMAD FASYA, S.Pd.I., M.Pd.', '085223322338', 'Guru'),
(5, '2627008', 'H. SOPI AHMAD MUSTOFA, S.Pd.I., M.Pd.', '', 'Guru'),
(6, '2627009', 'H. ZAMZAM MANARUL HIDAYAT, S.Ag.', '', 'Guru'),
(7, '2627001', 'KH. MOCHAMAD SYARIF HIDAYAT', '081221889030', 'Guru'),
(8, '2627002', 'KH. DIDIN NAJMUDIN, S.Pd.', '081222453399', 'Guru'),
(9, '2627004', 'KH. FUAD SOLAHUDIN', '085223121889', 'Guru'),
(10, '2627003', 'Drs. KH. YUYUN RAHAYU', '085223999401', 'Guru'),
(11, '2627005', 'KH. DADANG HOLIDIN', '085223693746', 'Guru'),
(12, '2627019', 'H. MUHAMMAD HASBY ASHSHIDDIQY', '', 'Guru'),
(13, '2627010', 'H. DZIKRI ILHAMI AHMAD FAUZI, S.Ag., M.Pd.', '', 'Guru'),
(14, '2627017', 'BEBEN ROMDONI, S.P.', '', 'Guru'),
(15, '2627015', 'YUSNA HENDARSIH', '', 'Guru'),
(16, '2627024', 'TIA SITI LUTFIAH, S.Pd.', '', 'Guru'),
(17, '2627018', 'BAHIJ MUHAMMAD AULIA', '', 'Guru'),
(18, '2627023', 'SYIFA UMIYATU SYAHIDA, S.IP.', '', 'Guru'),
(19, '2627022', 'LAYLA HILMIYA FAUZIYAH, S.Sy.', '', 'Guru'),
(20, '2627020', 'ASEP SUNANDAR, S.Pd.', '', 'Guru'),
(21, '2627013', 'ALIT LATIFATUL FUADAH, S.Pd.', '', 'Guru'),
(22, '2627012', 'RADEN ANISA NURUS SA\'IDAH, S.Pd.', '', 'Guru'),
(23, '2627014', 'TIKA TAQIYATUZ ZAHRA, S.Pd.', '', 'Guru'),
(24, '2627021', 'RENI NURAENI, S.H.', '', 'Guru'),
(25, '2627025', 'SUHENDAR HERDIANA,  S.Hum.', '', 'Guru'),
(26, '2627011', 'Hj. NENG MAHDA ANNIDA, Lc.', '', 'Guru');

-- --------------------------------------------------------

--
-- Table structure for table `jadwal_ngaji`
--

CREATE TABLE `jadwal_ngaji` (
  `id` int(11) NOT NULL,
  `id_tahun` int(11) NOT NULL,
  `waktu_sholat` enum('Ba''da Shubuh','Ba''da Ashar','Ba''da Magrib','Ba''da Isya') NOT NULL,
  `jam` varchar(50) NOT NULL,
  `id_kelas` int(11) NOT NULL,
  `fan_ilmu` varchar(100) NOT NULL,
  `nama_kitab` varchar(100) NOT NULL,
  `id_guru` int(11) NOT NULL,
  `tempat` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `jadwal_ngaji`
--

INSERT INTO `jadwal_ngaji` (`id`, `id_tahun`, `waktu_sholat`, `jam`, `id_kelas`, `fan_ilmu`, `nama_kitab`, `id_guru`, `tempat`) VALUES
(1, 4, 'Ba\'da Shubuh', '05.00 - 06.00 WIB', 3, 'Fiqih', 'Riyadlul Badiah', 2, 'Aula Arraudlah'),
(2, 4, 'Ba\'da Shubuh', '05.00 - 06.00 WIB', 2, 'Fiqih', 'Fiqih Rancang & Sholat Fardlu', 15, 'Abu Bakar Atas'),
(3, 4, 'Ba\'da Shubuh', '05.00 - 06.00 WIB', 2, 'Al Qur\'an', 'Tahfidz Juz \'Amma', 16, 'Abu Bakar Atas'),
(4, 4, 'Ba\'da Shubuh', '05.00 - 06.00 WIB', 10, 'Fiqih', 'Fiqih Rancang & Sholat Fardlu', 17, 'Masjid Lantai 1'),
(5, 4, 'Ba\'da Shubuh', '05.00 - 06.00 WIB', 10, 'Al Qur\'an', 'Tahfidz Juz \'Amma', 6, 'Masjid Lantai 1');

-- --------------------------------------------------------

--
-- Table structure for table `kamar`
--

CREATE TABLE `kamar` (
  `id` int(11) NOT NULL,
  `nama_kamar` varchar(50) NOT NULL,
  `kapasitas` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `kamar`
--

INSERT INTO `kamar` (`id`, `nama_kamar`, `kapasitas`) VALUES
(1, 'ABU BAKAR', 20),
(2, 'USMAN BIN AFFAN', 20),
(4, 'UMAR BIN KHATAB ', 20),
(5, 'ALI BIN ABI THALIB ', 20),
(6, 'SALMAN AL FARISI ', 20),
(7, 'ZAID BIN TSABIT ', 20),
(9, 'KHOLID BIN WALID ', 20),
(11, 'BILAL BIN RABAH ', 35),
(12, 'ABDURAHMAN BIN AUF ', 35),
(13, 'ZUBAIR BIN AWWAM ', 35),
(14, 'THOLHAH ', 35),
(17, 'ROBIAH AL ADAWIYAH', 28),
(19, 'SAYYIDAH KHODIJAH', 18),
(20, 'SAYYIDAH AISYAH', 15),
(21, 'SAYYIDAH AMINAH', 12),
(22, 'FATIMAH AZZAHRA', 20),
(24, 'SAYYIDAH HAFSHOH', 20),
(25, 'SAUDAH AL AMIRIYAH', 18),
(26, 'UMMU SALAMAH', 18),
(27, 'UMMU HABIBAH', 18),
(29, 'SOFIAH BINTI HUYAY', 20),
(30, 'MAEMUNAH BINTI AL HARITS', 20),
(31, 'RAIHANAH BINTI ZAID', 20),
(32, 'JUWAIRIYAH BINTI AL HARITS', 20),
(33, 'ZAINAB BINTI JAHSY', 20),
(34, 'SAAD BIN ABI WAQAS', 35),
(35, 'MARIAH AL QIBTHI ', 10),
(36, 'UMAR BIN KHATTAB', 20),
(37, 'HAMZAH BIN ABDUL MUTHOLIB', 15);

-- --------------------------------------------------------

--
-- Table structure for table `kelas`
--

CREATE TABLE `kelas` (
  `id` int(11) NOT NULL,
  `nama_kelas` varchar(50) NOT NULL,
  `jenjang` varchar(20) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `kelas`
--

INSERT INTO `kelas` (`id`, `nama_kelas`, `jenjang`) VALUES
(2, '1 IBTIDA PI', 'SMP/MTs/Sederajat'),
(3, '1 TSANAWI', 'SMA/SMK/MA/Sederajat'),
(5, '3 IBTIDA PA', 'SMP/MTs/Sederajat'),
(8, '2 TSANAWI ', 'SMA/SMK/MA/Sederajat'),
(9, '3 TSANAWI ', 'SMA/SMK/MA/Sederajat'),
(10, '1 IBTIDA PA', 'SMP/MTs/Sederajat'),
(11, '2 IBTIDA PA', 'SMP/MTs/Sederajat'),
(13, '2 IBTIDA PI', 'SMP/MTs/Sederajat'),
(14, '3 IBTIDA PI', 'SMP/MTs/Sederajat'),
(15, '1 IBTIDA TAHFIDZ', 'SMP/MTs/Sederajat'),
(16, '2 IBTIDA TAHDFIDZ', 'SMP/MTs/Sederajat'),
(17, '3 IBTIDA TAHFIDZ', 'SMP/MTs/Sederajat'),
(19, '1 TSANAWI TAHFIDZ', 'SMA/SMK/MA/Sederajat'),
(20, '2 TSANAWI TAHFIDZ', 'SMA/SMK/MA/Sederajat'),
(21, '3 TSANAWI TAHFIDZ', 'SMA/SMK/MA/Sederajat');

-- --------------------------------------------------------

--
-- Table structure for table `mapel`
--

CREATE TABLE `mapel` (
  `id` int(11) NOT NULL,
  `nama_mapel` varchar(100) NOT NULL,
  `kategori` enum('Umum','Agama','Pesantren') NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `mengajar`
--

CREATE TABLE `mengajar` (
  `id` int(11) NOT NULL,
  `id_guru` int(11) NOT NULL,
  `id_mapel` int(11) NOT NULL,
  `id_kelas` int(11) NOT NULL,
  `id_tahun` int(11) NOT NULL,
  `hari` varchar(20) DEFAULT NULL,
  `jam_mulai` time DEFAULT NULL,
  `jam_selesai` time DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `pelanggaran`
--

CREATE TABLE `pelanggaran` (
  `id` int(11) NOT NULL,
  `id_santri` int(11) NOT NULL,
  `tgl_pelanggaran` date NOT NULL,
  `jenis_pelanggaran` varchar(255) NOT NULL,
  `poin` int(11) NOT NULL,
  `hukuman` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `pembimbing_kamar`
--

CREATE TABLE `pembimbing_kamar` (
  `id` int(11) NOT NULL,
  `id_guru` int(11) NOT NULL,
  `id_kamar` int(11) NOT NULL,
  `id_tahun` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `perizinan`
--

CREATE TABLE `perizinan` (
  `id` int(11) NOT NULL,
  `id_santri` int(11) NOT NULL,
  `tgl_izin` date NOT NULL,
  `tgl_kembali` date NOT NULL,
  `alasan` text NOT NULL,
  `status` enum('Pending','Disetujui','Ditolak') NOT NULL DEFAULT 'Pending'
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `plotting_kamar`
--

CREATE TABLE `plotting_kamar` (
  `id` int(11) NOT NULL,
  `id_santri` int(11) NOT NULL,
  `id_kamar` int(11) NOT NULL,
  `id_tahun` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `plotting_kamar`
--

INSERT INTO `plotting_kamar` (`id`, `id_santri`, `id_kamar`, `id_tahun`) VALUES
(1, 311, 14, 4),
(2, 32, 22, 4),
(3, 145, 20, 4),
(4, 1, 13, 4),
(5, 2, 13, 4),
(6, 110, 12, 4),
(7, 188, 4, 4),
(8, 312, 14, 4),
(9, 189, 5, 4),
(10, 313, 14, 4),
(11, 111, 12, 4),
(12, 190, 5, 4),
(13, 33, 27, 4),
(14, 146, 17, 4),
(15, 79, 24, 4),
(16, 112, 36, 4),
(17, 113, 34, 4),
(18, 60, 11, 4),
(19, 327, 22, 4),
(20, 328, 24, 4),
(21, 61, 1, 4),
(22, 187, 5, 4),
(23, 35, 24, 4),
(24, 34, 20, 4),
(25, 4, 13, 4),
(26, 253, 7, 4),
(27, 191, 5, 4),
(28, 114, 34, 4),
(29, 36, 19, 4),
(30, 147, 19, 4),
(31, 255, 7, 4),
(32, 81, 32, 4),
(33, 329, 22, 4),
(35, 192, 2, 4),
(36, 3, 37, 4),
(37, 254, 37, 4),
(38, 256, 37, 4),
(40, 285, 30, 4),
(41, 314, 14, 4),
(42, 330, 22, 4),
(43, 193, 2, 4),
(44, 315, 14, 4),
(45, 148, 17, 4),
(46, 149, 19, 4),
(47, 284, 33, 4),
(48, 229, 33, 4),
(49, 257, 37, 4),
(50, 194, 36, 4),
(51, 316, 14, 4),
(53, 195, 2, 4),
(54, 196, 36, 4),
(55, 197, 2, 4),
(56, 258, 9, 4),
(57, 62, 11, 4),
(58, 355, 14, 4),
(59, 259, 37, 4),
(60, 115, 34, 4),
(61, 116, 12, 4),
(62, 198, 36, 4),
(63, 289, 33, 4),
(64, 80, 21, 4),
(65, 199, 2, 4),
(66, 317, 14, 4),
(67, 260, 37, 4),
(68, 200, 2, 4),
(69, 261, 9, 4),
(70, 262, 9, 4),
(71, 263, 7, 4),
(72, 201, 5, 4),
(73, 117, 12, 4),
(74, 331, 22, 4),
(75, 150, 20, 4),
(76, 228, 25, 4),
(77, 227, 26, 4),
(78, 151, 19, 4),
(79, 230, 25, 4),
(80, 152, 19, 4),
(81, 37, 24, 4),
(82, 231, 25, 4),
(83, 286, 30, 4),
(84, 287, 29, 4),
(85, 38, 22, 4),
(86, 288, 30, 4),
(87, 39, 22, 4),
(88, 40, 29, 4),
(89, 232, 25, 4),
(90, 82, 20, 4),
(91, 83, 26, 4),
(92, 41, 25, 4),
(93, 84, 21, 4),
(94, 233, 27, 4),
(95, 85, 21, 4),
(96, 42, 17, 4),
(97, 86, 19, 4),
(98, 153, 17, 4),
(99, 290, 30, 4),
(100, 87, 21, 4),
(101, 43, 30, 4),
(102, 118, 34, 4),
(103, 6, 2, 4),
(104, 318, 14, 4),
(105, 291, 33, 4),
(106, 264, 9, 4),
(107, 293, 30, 4),
(108, 65, 6, 4),
(109, 154, 17, 4),
(110, 119, 34, 4),
(111, 7, 13, 4),
(112, 202, 36, 4),
(113, 319, 14, 4),
(114, 45, 32, 4),
(115, 203, 2, 4),
(116, 64, 11, 4),
(117, 63, 11, 4),
(118, 66, 6, 4),
(119, 120, 12, 4),
(120, 155, 20, 4),
(121, 156, 17, 4),
(123, 294, 30, 4),
(124, 234, 27, 4),
(125, 235, 27, 4),
(126, 204, 2, 4),
(127, 292, 33, 4),
(128, 295, 33, 4),
(129, 8, 13, 4),
(130, 121, 34, 4),
(131, 205, 5, 4),
(132, 296, 33, 4),
(133, 265, 7, 4),
(134, 88, 29, 4),
(135, 332, 22, 4),
(136, 333, 22, 4),
(137, 90, 22, 4),
(138, 89, 24, 4),
(139, 266, 7, 4),
(140, 157, 19, 4),
(141, 334, 32, 4),
(142, 356, 14, 4),
(143, 9, 13, 4),
(144, 267, 7, 4),
(145, 122, 12, 4),
(146, 206, 5, 4),
(147, 123, 12, 4),
(148, 92, 30, 4),
(149, 93, 17, 4),
(150, 320, 14, 4),
(151, 357, 14, 4),
(152, 10, 13, 4),
(153, 11, 13, 4),
(154, 158, 19, 4),
(155, 186, 20, 4),
(156, 159, 20, 4),
(157, 12, 13, 4),
(158, 13, 13, 4),
(159, 207, 2, 4),
(160, 160, 17, 4),
(161, 208, 36, 4),
(162, 335, 24, 4),
(163, 94, 27, 4),
(164, 297, 29, 4),
(165, 298, 33, 4),
(166, 209, 2, 4),
(167, 336, 32, 4),
(168, 14, 13, 4),
(169, 299, 33, 4),
(170, 95, 25, 4),
(171, 210, 2, 4),
(172, 236, 25, 4),
(173, 96, 27, 4),
(174, 97, 19, 4),
(175, 337, 24, 4),
(176, 361, 24, 4),
(177, 225, 2, 4),
(178, 226, 5, 4),
(179, 31, 13, 4),
(180, 347, 24, 4),
(181, 251, 25, 4),
(182, 252, 26, 4),
(183, 326, 14, 4),
(184, 144, 12, 4),
(185, 78, 1, 4),
(186, 250, 27, 4),
(187, 268, 9, 4),
(188, 269, 9, 4),
(189, 67, 11, 4),
(190, 91, 35, 4),
(191, 338, 22, 4),
(192, 161, 17, 4),
(193, 270, 9, 4),
(194, 237, 25, 4),
(195, 238, 26, 4),
(196, 162, 17, 4),
(197, 271, 7, 4),
(198, 272, 9, 4),
(199, 239, 26, 4),
(200, 273, 7, 4),
(201, 211, 5, 4),
(202, 124, 12, 4),
(203, 212, 5, 4),
(204, 213, 5, 4),
(205, 274, 9, 4),
(206, 276, 9, 4),
(207, 125, 34, 4),
(208, 15, 13, 4),
(209, 214, 2, 4),
(210, 275, 7, 4),
(211, 68, 11, 4),
(212, 277, 7, 4),
(213, 126, 34, 4),
(214, 44, 30, 4),
(215, 69, 11, 4),
(216, 16, 13, 4),
(217, 17, 34, 4),
(218, 278, 9, 4),
(219, 358, 14, 4),
(220, 127, 4, 4),
(221, 215, 5, 4),
(222, 128, 34, 4),
(223, 216, 2, 4),
(224, 133, 12, 4),
(225, 279, 7, 4),
(226, 220, 5, 4),
(227, 219, 5, 4),
(228, 218, 2, 4),
(229, 132, 34, 4),
(230, 22, 5, 4),
(231, 131, 12, 4),
(232, 129, 34, 4),
(233, 21, 13, 4),
(234, 217, 5, 4),
(235, 130, 12, 4),
(236, 19, 13, 4),
(237, 20, 36, 4),
(238, 23, 13, 4),
(239, 321, 14, 4),
(240, 24, 13, 4),
(241, 280, 9, 4),
(242, 134, 34, 4),
(243, 71, 6, 4),
(244, 136, 12, 4),
(245, 349, 32, 4),
(246, 165, 17, 4),
(247, 240, 26, 4),
(248, 339, 24, 4),
(249, 303, 33, 4),
(250, 46, 26, 4),
(251, 359, 14, 4),
(252, 70, 11, 4),
(253, 322, 14, 4),
(254, 222, 5, 4),
(255, 348, 32, 4),
(256, 302, 30, 4),
(257, 300, 29, 4),
(258, 301, 30, 4),
(259, 164, 17, 4),
(260, 163, 17, 4),
(261, 360, 14, 4),
(262, 135, 34, 4),
(263, 166, 20, 4),
(264, 72, 11, 4),
(265, 137, 12, 4),
(266, 77, 6, 4),
(267, 51, 20, 4),
(268, 99, 30, 4),
(269, 305, 33, 4),
(270, 50, 27, 4),
(271, 172, 20, 4),
(272, 171, 20, 4),
(273, 73, 1, 4),
(274, 98, 35, 4),
(275, 170, 17, 4),
(276, 304, 33, 4),
(277, 48, 17, 4),
(278, 169, 17, 4),
(279, 168, 20, 4),
(280, 49, 17, 4),
(281, 47, 25, 4),
(282, 340, 32, 4),
(283, 241, 27, 4),
(284, 167, 17, 4),
(285, 350, 22, 4),
(286, 341, 22, 4),
(287, 242, 27, 4),
(288, 26, 13, 4),
(289, 138, 36, 4),
(290, 223, 5, 4),
(291, 139, 34, 4),
(292, 306, 29, 4),
(293, 52, 22, 4),
(294, 53, 26, 4),
(295, 54, 19, 4),
(296, 283, 37, 4),
(297, 324, 14, 4),
(298, 101, 21, 4),
(299, 76, 1, 4),
(300, 100, 21, 4),
(301, 29, 13, 4),
(302, 141, 12, 4),
(303, 281, 7, 4),
(304, 323, 14, 4),
(305, 75, 6, 4),
(306, 28, 13, 4),
(307, 27, 13, 4),
(308, 224, 2, 4),
(309, 140, 4, 4),
(310, 74, 11, 4),
(311, 307, 29, 4),
(312, 243, 25, 4),
(313, 343, 24, 4),
(314, 248, 25, 4),
(315, 247, 25, 4),
(316, 182, 20, 4),
(317, 246, 27, 4),
(318, 181, 17, 4),
(319, 103, 29, 4),
(320, 142, 12, 4),
(321, 325, 13, 4),
(322, 342, 32, 4),
(323, 180, 19, 4),
(324, 179, 17, 4),
(325, 178, 19, 4),
(326, 245, 27, 4),
(327, 310, 30, 4),
(328, 177, 17, 4),
(329, 176, 19, 4),
(330, 175, 17, 4),
(331, 309, 29, 4),
(332, 308, 29, 4),
(333, 244, 27, 4),
(334, 102, 21, 4),
(335, 174, 19, 4),
(336, 173, 17, 4),
(337, 55, 33, 4),
(338, 183, 19, 4),
(339, 249, 26, 4),
(340, 104, 17, 4),
(341, 344, 24, 4),
(342, 184, 20, 4),
(343, 109, 33, 4),
(344, 108, 32, 4),
(345, 107, 33, 4),
(346, 106, 25, 4),
(347, 185, 17, 4),
(348, 59, 33, 4),
(349, 354, 32, 4),
(350, 353, 24, 4),
(351, 56, 30, 4),
(352, 143, 12, 4),
(353, 30, 9, 4),
(354, 351, 32, 4),
(355, 352, 24, 4),
(356, 282, 9, 4),
(357, 105, 20, 4),
(358, 345, 22, 4),
(359, 346, 24, 4),
(360, 58, 29, 4),
(361, 57, 29, 4),
(362, 18, 14, 4);

-- --------------------------------------------------------

--
-- Table structure for table `plotting_kelas`
--

CREATE TABLE `plotting_kelas` (
  `id` int(11) NOT NULL,
  `id_santri` int(11) NOT NULL,
  `id_kelas` int(11) NOT NULL,
  `id_tahun` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `plotting_kelas`
--

INSERT INTO `plotting_kelas` (`id`, `id_santri`, `id_kelas`, `id_tahun`) VALUES
(1, 146, 17, 4),
(2, 112, 17, 4),
(3, 148, 17, 4),
(4, 152, 17, 4),
(5, 154, 17, 4),
(6, 159, 17, 4),
(7, 161, 17, 4),
(8, 127, 17, 4),
(9, 164, 17, 4),
(10, 166, 17, 4),
(11, 167, 17, 4),
(12, 168, 17, 4),
(13, 170, 17, 4),
(14, 138, 17, 4),
(15, 140, 17, 4),
(16, 177, 17, 4),
(17, 178, 17, 4),
(18, 180, 17, 4),
(19, 188, 16, 4),
(20, 229, 16, 4),
(21, 194, 16, 4),
(23, 232, 16, 4),
(31, 110, 5, 4),
(32, 111, 5, 4),
(33, 113, 5, 4),
(34, 114, 5, 4),
(35, 115, 5, 4),
(36, 116, 5, 4),
(37, 117, 5, 4),
(38, 118, 5, 4),
(39, 119, 5, 4),
(40, 120, 5, 4),
(41, 121, 5, 4),
(42, 122, 5, 4),
(43, 123, 5, 4),
(44, 124, 5, 4),
(45, 125, 5, 4),
(46, 126, 5, 4),
(47, 128, 5, 4),
(48, 129, 5, 4),
(49, 130, 5, 4),
(50, 131, 5, 4),
(51, 132, 5, 4),
(52, 133, 5, 4),
(53, 134, 5, 4),
(54, 135, 5, 4),
(55, 136, 5, 4),
(56, 137, 5, 4),
(57, 139, 5, 4),
(58, 141, 5, 4),
(59, 142, 5, 4),
(60, 143, 5, 4),
(61, 144, 5, 4),
(62, 187, 11, 4),
(63, 189, 11, 4),
(64, 190, 11, 4),
(65, 191, 11, 4),
(66, 192, 11, 4),
(67, 193, 11, 4),
(68, 195, 11, 4),
(71, 199, 11, 4),
(72, 200, 11, 4),
(73, 201, 11, 4),
(75, 203, 11, 4),
(76, 204, 11, 4),
(77, 205, 11, 4),
(78, 207, 11, 4),
(80, 210, 11, 4),
(81, 212, 11, 4),
(82, 214, 11, 4),
(83, 216, 11, 4),
(84, 217, 11, 4),
(85, 218, 11, 4),
(86, 219, 11, 4),
(87, 220, 11, 4),
(89, 222, 11, 4),
(90, 223, 11, 4),
(91, 224, 11, 4),
(92, 225, 11, 4),
(93, 226, 11, 4),
(94, 145, 14, 4),
(95, 147, 14, 4),
(96, 149, 14, 4),
(97, 150, 14, 4),
(98, 151, 14, 4),
(99, 153, 14, 4),
(100, 155, 14, 4),
(101, 156, 14, 4),
(102, 157, 14, 4),
(103, 158, 14, 4),
(104, 186, 14, 4),
(105, 160, 14, 4),
(106, 162, 14, 4),
(107, 163, 14, 4),
(108, 165, 14, 4),
(109, 169, 14, 4),
(110, 171, 14, 4),
(111, 172, 14, 4),
(112, 173, 14, 4),
(113, 174, 14, 4),
(114, 175, 14, 4),
(115, 176, 14, 4),
(116, 179, 14, 4),
(117, 181, 14, 4),
(118, 182, 14, 4),
(119, 183, 14, 4),
(120, 184, 14, 4),
(121, 185, 14, 4),
(123, 228, 13, 4),
(124, 230, 13, 4),
(125, 231, 13, 4),
(127, 234, 13, 4),
(128, 235, 13, 4),
(129, 236, 13, 4),
(130, 237, 13, 4),
(131, 238, 13, 4),
(132, 239, 13, 4),
(133, 240, 13, 4),
(134, 241, 13, 4),
(137, 244, 13, 4),
(138, 245, 13, 4),
(139, 246, 13, 4),
(140, 247, 13, 4),
(141, 248, 13, 4),
(142, 250, 13, 4),
(143, 252, 13, 4),
(145, 1, 8, 4),
(146, 2, 8, 4),
(147, 3, 8, 4),
(149, 79, 9, 4),
(150, 253, 10, 4),
(152, 254, 10, 4),
(153, 256, 10, 4),
(154, 258, 10, 4),
(155, 260, 10, 4),
(156, 261, 10, 4),
(157, 263, 10, 4),
(158, 264, 10, 4),
(160, 266, 10, 4),
(161, 311, 19, 4),
(162, 355, 19, 4),
(163, 332, 19, 4),
(164, 334, 19, 4),
(165, 356, 19, 4),
(166, 357, 19, 4),
(167, 358, 19, 4),
(168, 359, 19, 4),
(169, 360, 19, 4),
(170, 348, 19, 4),
(171, 349, 19, 4),
(172, 350, 19, 4),
(173, 351, 19, 4),
(174, 352, 19, 4),
(175, 353, 19, 4),
(176, 354, 19, 4),
(177, 312, 3, 4),
(178, 313, 3, 4),
(179, 327, 3, 4),
(181, 329, 3, 4),
(182, 314, 3, 4),
(183, 330, 3, 4),
(184, 315, 3, 4),
(185, 316, 3, 4),
(186, 317, 3, 4),
(187, 331, 3, 4),
(188, 318, 3, 4),
(189, 319, 3, 4),
(190, 333, 3, 4),
(191, 320, 3, 4),
(192, 335, 3, 4),
(193, 336, 3, 4),
(194, 361, 3, 4),
(195, 337, 3, 4),
(196, 338, 3, 4),
(197, 321, 3, 4),
(198, 322, 3, 4),
(199, 339, 3, 4),
(200, 340, 3, 4),
(201, 341, 3, 4),
(202, 323, 3, 4),
(203, 324, 3, 4),
(204, 342, 3, 4),
(205, 325, 3, 4),
(206, 343, 3, 4),
(207, 344, 3, 4),
(208, 345, 3, 4),
(209, 346, 3, 4),
(210, 347, 3, 4),
(211, 326, 3, 4),
(212, 196, 16, 4),
(213, 197, 11, 4),
(214, 206, 11, 4),
(215, 209, 11, 4),
(216, 211, 11, 4),
(217, 213, 11, 4),
(218, 215, 11, 4),
(220, 198, 16, 4),
(221, 233, 16, 4),
(222, 202, 16, 4),
(223, 208, 16, 4),
(224, 242, 16, 4),
(225, 243, 16, 4),
(227, 249, 13, 4),
(228, 251, 13, 4),
(229, 227, 16, 4),
(230, 32, 20, 4),
(231, 33, 20, 4),
(232, 34, 20, 4),
(233, 35, 20, 4),
(234, 38, 20, 4),
(235, 41, 20, 4),
(236, 42, 20, 4),
(237, 44, 20, 4),
(238, 46, 20, 4),
(239, 50, 20, 4),
(240, 54, 20, 4),
(241, 56, 20, 4),
(242, 88, 21, 4),
(243, 89, 21, 4),
(244, 96, 21, 4),
(245, 67, 21, 4),
(246, 68, 21, 4),
(247, 99, 21, 4),
(248, 103, 21, 4),
(251, 80, 9, 4),
(252, 81, 9, 4),
(253, 62, 9, 4),
(254, 63, 9, 4),
(255, 82, 9, 4),
(256, 83, 9, 4),
(257, 84, 9, 4),
(258, 85, 9, 4),
(259, 86, 9, 4),
(260, 87, 9, 4),
(261, 64, 9, 4),
(262, 65, 9, 4),
(263, 66, 9, 4),
(264, 90, 9, 4),
(265, 91, 9, 4),
(266, 92, 9, 4),
(267, 93, 9, 4),
(268, 94, 9, 4),
(269, 95, 9, 4),
(270, 97, 9, 4),
(271, 69, 9, 4),
(272, 70, 9, 4),
(273, 71, 9, 4),
(274, 72, 9, 4),
(275, 98, 9, 4),
(276, 73, 9, 4),
(277, 74, 9, 4),
(278, 75, 9, 4),
(279, 100, 9, 4),
(280, 76, 9, 4),
(281, 101, 9, 4),
(282, 102, 9, 4),
(283, 104, 9, 4),
(284, 105, 9, 4),
(285, 77, 9, 4),
(286, 106, 9, 4),
(287, 107, 9, 4),
(288, 108, 9, 4),
(289, 109, 9, 4),
(290, 78, 9, 4),
(291, 4, 8, 4),
(292, 36, 8, 4),
(293, 5, 8, 4),
(294, 37, 8, 4),
(295, 39, 8, 4),
(296, 40, 8, 4),
(297, 43, 8, 4),
(298, 6, 8, 4),
(299, 7, 8, 4),
(300, 45, 8, 4),
(301, 8, 8, 4),
(302, 9, 8, 4),
(303, 10, 8, 4),
(304, 11, 8, 4),
(305, 12, 8, 4),
(306, 13, 8, 4),
(307, 14, 8, 4),
(308, 15, 8, 4),
(309, 16, 8, 4),
(310, 17, 8, 4),
(311, 18, 8, 4),
(312, 19, 8, 4),
(313, 20, 8, 4),
(314, 21, 8, 4),
(315, 22, 8, 4),
(316, 23, 8, 4),
(317, 24, 8, 4),
(318, 47, 8, 4),
(319, 48, 8, 4),
(320, 49, 8, 4),
(321, 25, 8, 4),
(322, 51, 8, 4),
(323, 26, 8, 4),
(324, 52, 8, 4),
(325, 53, 8, 4),
(326, 27, 8, 4),
(327, 28, 8, 4),
(328, 29, 8, 4),
(329, 55, 8, 4),
(330, 57, 8, 4),
(331, 58, 8, 4),
(332, 59, 8, 4),
(333, 30, 8, 4),
(334, 31, 8, 4),
(335, 285, 15, 4),
(336, 295, 15, 4),
(337, 296, 15, 4),
(338, 298, 15, 4),
(339, 299, 15, 4),
(340, 301, 15, 4),
(341, 60, 9, 4),
(342, 61, 9, 4),
(343, 328, 3, 4),
(344, 255, 10, 4),
(345, 257, 10, 4),
(346, 259, 10, 4),
(347, 286, 2, 4),
(348, 287, 2, 4),
(349, 288, 2, 4),
(350, 289, 2, 4),
(351, 262, 10, 4),
(352, 290, 2, 4),
(353, 291, 2, 4),
(354, 293, 2, 4),
(355, 292, 2, 4),
(356, 265, 10, 4),
(357, 267, 10, 4),
(358, 297, 2, 4),
(359, 268, 10, 4),
(360, 269, 10, 4),
(361, 270, 10, 4),
(363, 273, 10, 4),
(364, 274, 10, 4),
(365, 276, 10, 4),
(366, 275, 10, 4),
(367, 277, 10, 4),
(368, 278, 10, 4),
(369, 279, 10, 4),
(370, 303, 2, 4),
(371, 302, 2, 4),
(372, 300, 2, 4),
(373, 305, 2, 4),
(374, 304, 2, 4),
(375, 283, 10, 4),
(376, 281, 10, 4),
(377, 306, 2, 4),
(378, 307, 2, 4),
(379, 310, 2, 4),
(380, 308, 2, 4),
(381, 309, 2, 4),
(382, 282, 10, 4);

-- --------------------------------------------------------

--
-- Table structure for table `psb_pembayaran`
--

CREATE TABLE `psb_pembayaran` (
  `id` int(11) NOT NULL,
  `no_pendaftaran` varchar(20) NOT NULL,
  `kategori_biaya` varchar(100) NOT NULL,
  `syahriyyah` int(11) NOT NULL,
  `infaq` int(11) NOT NULL,
  `seragam_psas` int(11) NOT NULL DEFAULT 0,
  `seragam_pramuka` int(11) NOT NULL DEFAULT 0,
  `rincian_wajib` text NOT NULL,
  `total_wajib` int(11) NOT NULL,
  `total_keseluruhan` int(11) NOT NULL,
  `metode_pembayaran` enum('Cash','Transfer') NOT NULL DEFAULT 'Cash',
  `status_pembayaran` enum('Belum Lunas','Lunas') NOT NULL DEFAULT 'Belum Lunas',
  `waktu_lunas` datetime DEFAULT NULL,
  `tanggal` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `psb_pembayaran`
--

INSERT INTO `psb_pembayaran` (`id`, `no_pendaftaran`, `kategori_biaya`, `syahriyyah`, `infaq`, `seragam_psas`, `seragam_pramuka`, `rincian_wajib`, `total_wajib`, `total_keseluruhan`, `metode_pembayaran`, `status_pembayaran`, `waktu_lunas`, `tanggal`) VALUES
(9, 'PSB202604685', 'SMP Terpadu Al Hasan', 800000, 0, 190000, 215000, '{\"Formulir Pendaftaran\":150000,\"Administrasi Santri Baru\":250000,\"Kitab\":230000,\"Laundry (20 Kg)\":100000,\"Biaya Ujian Tahunan\":250000,\"Seragam Yayasan\":165000,\"Batik SMP\":85000,\"Seragam Olahraga\":155000,\"JAS Almamater\":250000,\"Seragam Muslim\":150000,\"Sabuk PSAS & Pramuka\":35000,\"Dasi\":17500}', 1837500, 3042500, 'Transfer', 'Lunas', '2026-07-10 09:15:12', '2026-07-10 09:11:58'),
(10, 'PSB202604762', 'SMP Terpadu Al Hasan', 800000, 1000000, 225000, 230000, '{\"Formulir Pendaftaran\":150000,\"Administrasi Santri Baru\":250000,\"Lemari\":600000,\"Kitab\":230000,\"Laundry (20 Kg)\":100000,\"Biaya Ujian Tahunan\":250000,\"Seragam Yayasan\":170000,\"Batik SMP\":85000,\"Seragam Olahraga\":160000,\"JAS Almamater\":250000,\"Seragam Muslimah\":150000,\"Sabuk PSAS & Pramuka\":35000,\"Kerudung\":100000}', 2530000, 4785000, 'Cash', 'Lunas', '2026-07-10 13:34:15', '2026-07-10 09:16:41'),
(13, 'PSB20262640', 'Tsanawi Non-SMK (Baru)', 800000, 1000000, 0, 0, '{\"Formulir Pendaftaran\":150000,\"Administrasi Santri Baru\":250000,\"Lemari\":600000,\"Kitab\":270000,\"Biaya Ujian Tahunan\":150000,\"JAS Almamater\":250000,\"Seragam Muslimah\":150000}', 1820000, 3620000, 'Cash', 'Lunas', '2026-07-10 10:44:34', '2026-07-10 10:03:21'),
(14, 'PSB20262682', 'Tsanawi Non-SMK (Baru)', 800000, 1000000, 0, 0, '{\"Formulir Pendaftaran\":150000,\"Administrasi Santri Baru\":250000,\"Kitab\":270000,\"Laundry (20 Kg)\":100000,\"Biaya Ujian Tahunan\":150000,\"JAS Almamater\":250000,\"Seragam Muslimah\":150000}', 1320000, 3120000, 'Cash', 'Lunas', '2026-07-10 10:20:27', '2026-07-10 10:13:34'),
(15, 'PSB202603113', 'SMP Terpadu Al Hasan', 800000, 1000000, 190000, 215000, '{\"Formulir Pendaftaran\":150000,\"Administrasi Santri Baru\":250000,\"Lemari\":600000,\"Kitab\":230000,\"Laundry (20 Kg)\":100000,\"Biaya Ujian Tahunan\":250000,\"Seragam Yayasan\":165000,\"Batik SMP\":85000,\"Seragam Olahraga\":155000,\"JAS Almamater\":250000,\"Seragam Muslim\":150000,\"Sabuk PSAS & Pramuka\":35000,\"Dasi\":17500}', 2437500, 4642500, 'Cash', 'Lunas', '2026-07-10 10:24:55', '2026-07-10 10:17:10'),
(16, 'PSB20263668', 'SMK Terpadu Al Hasan (Baru)', 500000, 0, 230000, 230000, '{\"Formulir Pendaftaran\":150000,\"Administrasi Santri Baru\":250000,\"Kitab\":270000,\"Biaya Ujian Tahunan\":250000,\"Seragam PDH SMK\":231000,\"Seragam Olahraga\":190000,\"JAS Almamater\":250000,\"Seragam Muslimah\":150000}', 1741000, 2701000, 'Cash', 'Lunas', '2026-07-10 10:44:18', '2026-07-10 10:26:33'),
(17, 'PSB202605671', 'SMP Terpadu Al Hasan', 800000, 0, 225000, 230000, '{\"Kitab\":230000,\"Laundry (20 Kg)\":100000,\"Seragam Yayasan\":170000,\"Batik SMP\":85000,\"Seragam Olahraga\":160000,\"JAS Almamater\":250000,\"Seragam Muslimah\":150000,\"Sabuk PSAS & Pramuka\":35000,\"Kerudung\":100000}', 1280000, 2535000, 'Cash', 'Lunas', '2026-07-10 10:54:08', '2026-07-10 10:32:11'),
(18, 'PSB20263285', 'SMK Terpadu Al Hasan (Alumni)', 600000, 0, 200000, 200000, '{\"Administrasi Santri Baru\":250000,\"Kitab\":200000,\"Laundry\":100000,\"Biaya Ujian Tahunan\":250000,\"Seragam PDH SMK\":231000,\"Seragam Olahraga\":190000}', 1221000, 2221000, 'Cash', 'Lunas', '2026-07-10 10:48:30', '2026-07-10 10:39:21'),
(19, 'PSB20268632', 'Tsanawi Non-SMK (Baru)', 800000, 1000000, 0, 0, '{\"Formulir Pendaftaran\":150000,\"Administrasi Santri Baru\":250000,\"Kitab\":270000,\"Laundry (20 Kg)\":100000,\"Biaya Ujian Tahunan\":150000,\"JAS Almamater\":250000,\"Seragam Muslimah\":150000}', 1320000, 3120000, 'Cash', 'Lunas', '2026-07-10 11:11:43', '2026-07-10 11:02:17'),
(20, 'PSB202605358', 'SMP Terpadu Al Hasan', 800000, 1000000, 190000, 215000, '{\"Formulir Pendaftaran\":150000,\"Administrasi Santri Baru\":250000,\"Lemari\":600000,\"Kitab\":230000,\"Laundry (20 Kg)\":100000,\"Biaya Ujian Tahunan\":250000,\"Seragam Yayasan\":165000,\"Batik SMP\":85000,\"Seragam Olahraga\":155000,\"JAS Almamater\":250000,\"Seragam Muslim\":150000,\"Sabuk PSAS & Pramuka\":35000,\"Dasi\":17500}', 2437500, 4642500, 'Cash', 'Lunas', '2026-07-10 11:32:02', '2026-07-10 11:12:04'),
(21, 'PSB202603234', 'SMP Terpadu Al Hasan', 800000, 1000000, 225000, 230000, '{\"Formulir Pendaftaran\":150000,\"Administrasi Santri Baru\":250000,\"Lemari\":600000,\"Laundry (20 Kg)\":100000,\"Biaya Ujian Tahunan\":250000,\"Seragam Yayasan\":170000,\"Batik SMP\":85000,\"Seragam Olahraga\":160000,\"JAS Almamater\":250000,\"Seragam Muslimah\":150000,\"Sabuk PSAS & Pramuka\":35000,\"Kerudung\":100000}', 2300000, 4555000, 'Transfer', 'Lunas', '2026-07-10 14:15:05', '2026-07-10 13:38:07'),
(22, 'PSB20264834', 'Tsanawi Non-SMK (Baru)', 800000, 1000000, 0, 0, '{\"Formulir Pendaftaran\":150000,\"Administrasi Santri Baru\":250000,\"Kitab\":270000,\"Laundry (20 Kg)\":100000,\"Biaya Ujian Tahunan\":150000,\"JAS Almamater\":250000,\"Seragam Muslimah\":150000}', 1320000, 3120000, 'Cash', 'Lunas', '2026-07-10 14:21:13', '2026-07-10 13:51:59'),
(23, 'PSB202600314', 'SMP Terpadu Al Hasan', 800000, 1000000, 225000, 230000, '{\"Administrasi Santri Baru\":250000,\"Kitab\":230000,\"Laundry (20 Kg)\":100000,\"Biaya Ujian Tahunan\":250000,\"Seragam Yayasan\":170000,\"Batik SMP\":85000,\"Seragam Olahraga\":160000,\"JAS Almamater\":250000,\"Seragam Muslimah\":150000,\"Sabuk PSAS & Pramuka\":35000,\"Kerudung\":100000}', 1780000, 4035000, 'Transfer', 'Lunas', '2026-07-10 14:33:59', '2026-07-10 14:07:47'),
(24, 'PSB202602864', 'SMP Terpadu Al Hasan', 800000, 0, 190000, 215000, '{\"Formulir Pendaftaran\":150000,\"Administrasi Santri Baru\":250000,\"Lemari\":600000,\"Seragam Yayasan\":165000,\"Batik SMP\":85000,\"Seragam Olahraga\":155000,\"JAS Almamater\":250000,\"Seragam Muslim\":150000,\"Sabuk PSAS & Pramuka\":35000,\"Dasi\":17500}', 1857500, 3062500, 'Cash', 'Lunas', '2026-07-10 14:42:01', '2026-07-10 14:21:18'),
(25, 'PSB202600212', 'SMP Terpadu Al Hasan', 800000, 1000000, 0, 0, '{\"Formulir Pendaftaran\":150000,\"Administrasi Santri Baru\":250000,\"Kitab\":230000,\"Laundry (20 Kg)\":100000,\"Biaya Ujian Tahunan\":250000,\"Seragam Yayasan\":165000,\"Seragam Olahraga\":155000,\"JAS Almamater\":250000,\"Seragam Muslim\":150000}', 1700000, 3500000, 'Transfer', 'Lunas', '2026-07-10 15:37:46', '2026-07-10 15:23:40'),
(32, 'PSB-2026-8375', 'Tsanawi Non-SMK (Baru)', 800000, 0, 0, 0, '{\"Formulir Pendaftaran\":150000,\"Administrasi Santri Baru\":250000,\"Lemari\":600000,\"Laundry (20 Kg)\":100000,\"Biaya Ujian Tahunan\":150000,\"JAS Almamater\":250000,\"Seragam Muslim\":150000}', 1650000, 2450000, 'Cash', 'Lunas', '2026-07-10 15:52:51', '2026-07-10 15:37:58'),
(33, 'PSB202603543', 'SMP Terpadu Al Hasan', 800000, 1000000, 190000, 215000, '{\"Formulir Pendaftaran\":150000,\"Administrasi Santri Baru\":250000,\"Lemari\":600000,\"Kitab\":230000,\"Laundry (20 Kg)\":100000,\"Biaya Ujian Tahunan\":250000,\"Seragam Yayasan\":165000,\"Batik SMP\":85000,\"Seragam Olahraga\":155000,\"JAS Almamater\":250000,\"Seragam Muslim\":150000,\"Sabuk PSAS & Pramuka\":35000,\"Dasi\":17500}', 2437500, 4642500, 'Transfer', 'Lunas', '2026-07-10 16:06:16', '2026-07-10 15:42:02'),
(34, 'PSB202600971', 'SMP Terpadu Al Hasan', 800000, 1000000, 190000, 215000, '{\"Formulir Pendaftaran\":150000,\"Administrasi Santri Baru\":250000,\"Lemari\":600000,\"Kitab\":230000,\"Laundry (20 Kg)\":100000,\"Biaya Ujian Tahunan\":250000,\"Seragam Yayasan\":165000,\"Batik SMP\":85000,\"Seragam Olahraga\":155000,\"JAS Almamater\":250000,\"Seragam Muslim\":150000,\"Sabuk PSAS & Pramuka\":35000,\"Dasi\":17500}', 2437500, 4642500, 'Cash', 'Lunas', '2026-07-10 15:58:27', '2026-07-10 15:44:53'),
(35, 'PSB202605145', 'SMP Terpadu Al Hasan', 800000, 0, 225000, 230000, '{\"Formulir Pendaftaran\":150000,\"Administrasi Santri Baru\":250000,\"Kitab\":230000,\"Laundry (20 Kg)\":100000,\"Seragam Yayasan\":170000,\"Batik SMP\":85000,\"Seragam Olahraga\":160000,\"JAS Almamater\":250000,\"Seragam Muslimah\":150000,\"Sabuk PSAS & Pramuka\":35000,\"Kerudung\":100000}', 1680000, 2935000, 'Cash', 'Lunas', '2026-07-10 16:16:42', '2026-07-10 15:52:41'),
(36, 'PSB202601022', 'SMP Terpadu Al Hasan', 800000, 1000000, 225000, 230000, '{\"Formulir Pendaftaran\":150000,\"Administrasi Santri Baru\":250000,\"Lemari\":600000,\"Kitab\":230000,\"Laundry (20 Kg)\":100000,\"Biaya Ujian Tahunan\":250000,\"Seragam Yayasan\":170000,\"Batik SMP\":85000,\"Seragam Olahraga\":160000,\"JAS Almamater\":250000,\"Seragam Muslimah\":150000,\"Sabuk PSAS & Pramuka\":35000,\"Kerudung\":100000}', 2530000, 4785000, 'Cash', 'Lunas', '2026-07-10 16:36:47', '2026-07-10 15:55:22'),
(38, 'PSB20267548', 'Tsanawi Non-SMK (Baru)', 800000, 1000000, 0, 0, '{\"Formulir Pendaftaran\":150000,\"Administrasi Santri Baru\":250000,\"Lemari\":600000,\"Kitab\":270000,\"Laundry (20 Kg)\":100000,\"Biaya Ujian Tahunan\":150000,\"JAS Almamater\":250000,\"Seragam Muslim\":150000}', 1920000, 3720000, 'Transfer', 'Lunas', '2026-07-10 17:10:13', '2026-07-10 16:20:15'),
(39, 'PSB202600694', 'SMP Terpadu Al Hasan', 800000, 0, 0, 0, '{\"Formulir Pendaftaran\":150000,\"Administrasi Santri Baru\":250000,\"Kitab\":230000,\"Laundry (20 Kg)\":100000,\"Biaya Ujian Tahunan\":250000,\"Seragam Yayasan\":170000,\"Batik SMP\":85000,\"Seragam Olahraga\":160000,\"JAS Almamater\":250000,\"Seragam Muslimah\":150000,\"Sabuk PSAS & Pramuka\":35000,\"Kerudung\":100000}', 1930000, 2730000, 'Cash', 'Lunas', '2026-07-10 16:46:15', '2026-07-10 16:24:50'),
(40, 'PSB202602269', 'SMP Terpadu Al Hasan', 800000, 1000000, 225000, 230000, '{\"Formulir Pendaftaran\":150000,\"Administrasi Santri Baru\":250000,\"Kitab\":230000,\"Laundry (20 Kg)\":100000,\"Biaya Ujian Tahunan\":250000,\"Seragam Yayasan\":170000,\"Batik SMP\":85000,\"Seragam Olahraga\":160000,\"JAS Almamater\":250000,\"Seragam Muslimah\":150000,\"Sabuk PSAS & Pramuka\":35000,\"Kerudung\":100000}', 1930000, 4185000, 'Cash', 'Lunas', '2026-07-10 17:19:25', '2026-07-10 16:38:59'),
(42, 'PSB202600587', 'SMP Terpadu Al Hasan', 800000, 0, 225000, 230000, '{\"Formulir Pendaftaran\":150000,\"Administrasi Santri Baru\":250000,\"Lemari\":600000,\"Kitab\":230000,\"Laundry (20 Kg)\":100000,\"Biaya Ujian Tahunan\":250000,\"Seragam Yayasan\":170000,\"Batik SMP\":85000,\"Seragam Olahraga\":160000,\"JAS Almamater\":250000,\"Seragam Muslimah\":150000,\"Sabuk PSAS & Pramuka\":35000,\"Kerudung\":100000}', 2530000, 3785000, 'Cash', 'Lunas', '2026-07-10 17:07:07', '2026-07-10 17:03:04'),
(43, 'PSB20265285', 'SMK Terpadu Al Hasan (Baru)', 800000, 1000000, 200000, 200000, '{\"Formulir Pendaftaran\":150000,\"Administrasi Santri Baru\":250000,\"Lemari\":600000,\"Kitab\":270000,\"Laundry (20 Kg)\":100000,\"Biaya Ujian Tahunan\":250000,\"Seragam PDH SMK\":231000,\"Seragam Olahraga\":190000,\"JAS Almamater\":250000,\"Seragam Muslim\":150000}', 2441000, 4641000, 'Transfer', 'Belum Lunas', NULL, '2026-07-10 20:46:43'),
(44, 'PSB20267631', 'Tsanawi Non-SMK (Alumni)', 0, 0, 0, 0, '{\"Administrasi Santri Baru\":250000}', 250000, 250000, 'Cash', 'Lunas', '2026-07-11 08:40:17', '2026-07-11 08:35:11'),
(46, 'PSB202603430', 'SMP Terpadu Al Hasan', 800000, 1000000, 0, 0, '{\"Formulir Pendaftaran\":150000,\"Administrasi Santri Baru\":250000,\"Kitab\":230000,\"Laundry (20 Kg)\":100000,\"Biaya Ujian Tahunan\":250000,\"Seragam Olahraga\":160000,\"JAS Almamater\":250000,\"Seragam Muslimah\":150000,\"Sabuk PSAS & Pramuka\":35000}', 1575000, 3375000, 'Cash', 'Lunas', '2026-07-12 23:32:19', '2026-07-11 09:25:35'),
(47, 'PSB202603824', 'SMP Terpadu Al Hasan', 800000, 1000000, 190000, 215000, '{\"Formulir Pendaftaran\":150000,\"Administrasi Santri Baru\":250000,\"Kitab\":230000,\"Laundry (20 Kg)\":100000,\"Biaya Ujian Tahunan\":250000,\"Seragam Yayasan\":165000,\"Batik SMP\":85000,\"Seragam Olahraga\":155000,\"JAS Almamater\":250000,\"Seragam Muslim\":150000,\"Sabuk PSAS & Pramuka\":35000,\"Dasi\":17500}', 1837500, 4042500, 'Cash', 'Lunas', '2026-07-11 10:05:42', '2026-07-11 09:32:59'),
(48, 'PSB202605834', 'SMP Terpadu Al Hasan', 800000, 1000000, 190000, 215000, '{\"Administrasi Santri Baru\":250000,\"Lemari\":600000,\"Kitab\":230000,\"Laundry (20 Kg)\":100000,\"Biaya Ujian Tahunan\":250000,\"Seragam Yayasan\":165000,\"Batik SMP\":85000,\"Seragam Olahraga\":155000,\"JAS Almamater\":250000,\"Seragam Muslim\":150000,\"Sabuk PSAS & Pramuka\":35000,\"Dasi\":17500}', 2287500, 4492500, 'Transfer', 'Lunas', '2026-07-11 10:10:20', '2026-07-11 09:56:37'),
(50, 'PSB20269533', 'Tsanawi Non-SMK (Alumni)', 600000, 0, 0, 0, '{\"Administrasi Santri Baru\":250000,\"Biaya Ujian Tahunan\":150000}', 400000, 1000000, 'Cash', 'Lunas', '2026-07-11 10:26:37', '2026-07-11 10:07:50'),
(51, 'PSB20265778', 'SMK Terpadu Al Hasan (Baru)', 800000, 1000000, 230000, 230000, '{\"Formulir Pendaftaran\":150000,\"Administrasi Santri Baru\":250000,\"Kitab\":270000,\"Biaya Ujian Tahunan\":250000,\"Seragam PDH SMK\":231000,\"Seragam Olahraga\":190000,\"JAS Almamater\":250000,\"Seragam Muslimah\":150000}', 1741000, 4001000, 'Cash', 'Lunas', '2026-07-11 10:23:29', '2026-07-11 10:09:47'),
(52, 'PSB202602473', 'SMP Terpadu Al Hasan', 800000, 1000000, 225000, 230000, '{\"Formulir Pendaftaran\":150000,\"Administrasi Santri Baru\":250000,\"Lemari\":600000,\"Kitab\":230000,\"Biaya Ujian Tahunan\":250000,\"Seragam Yayasan\":170000,\"Batik SMP\":85000,\"Seragam Olahraga\":160000,\"JAS Almamater\":250000,\"Seragam Muslimah\":150000,\"Sabuk PSAS & Pramuka\":35000,\"Kerudung\":100000}', 2430000, 4685000, 'Cash', 'Lunas', '2026-07-11 10:17:32', '2026-07-11 10:12:09'),
(53, 'PSB202604145', 'SMP Terpadu Al Hasan', 800000, 1000000, 190000, 215000, '{\"Formulir Pendaftaran\":150000,\"Administrasi Santri Baru\":250000,\"Lemari\":600000,\"Kitab\":230000,\"Laundry (20 Kg)\":100000,\"Biaya Ujian Tahunan\":250000,\"Seragam Yayasan\":170000,\"Batik SMP\":85000,\"Seragam Olahraga\":160000,\"JAS Almamater\":250000,\"Seragam Muslimah\":150000,\"Sabuk PSAS & Pramuka\":35000,\"Kerudung\":100000}', 2530000, 4735000, 'Cash', 'Lunas', '2026-07-11 10:34:46', '2026-07-11 10:27:24'),
(54, 'PSB20261227', 'Tsanawi Non-SMK (Alumni)', 600000, 0, 0, 0, '{\"Administrasi Santri Baru\":250000,\"Kitab\":200000,\"Biaya Ujian Tahunan\":150000}', 600000, 1200000, 'Transfer', 'Lunas', '2026-07-11 10:42:47', '2026-07-11 10:33:35'),
(55, 'PSB202601969', 'SMP Terpadu Al Hasan', 800000, 1000000, 225000, 230000, '{\"Formulir Pendaftaran\":150000,\"Administrasi Santri Baru\":250000,\"Lemari\":600000,\"Kitab\":230000,\"Laundry (20 Kg)\":100000,\"Biaya Ujian Tahunan\":250000,\"Seragam Yayasan\":170000,\"Batik SMP\":85000,\"Seragam Olahraga\":160000,\"JAS Almamater\":250000,\"Seragam Muslimah\":150000,\"Sabuk PSAS & Pramuka\":35000,\"Kerudung\":100000}', 2530000, 4785000, 'Transfer', 'Lunas', '2026-07-11 10:47:02', '2026-07-11 10:42:45'),
(56, 'PSB202604430', 'SMP Terpadu Al Hasan', 800000, 1000000, 0, 0, '{\"Formulir Pendaftaran\":150000,\"Administrasi Santri Baru\":250000,\"Kitab\":230000,\"Biaya Ujian Tahunan\":250000,\"Seragam Yayasan\":170000,\"Batik SMP\":85000,\"Seragam Olahraga\":160000,\"JAS Almamater\":250000,\"Seragam Muslimah\":150000,\"Sabuk PSAS & Pramuka\":35000,\"Kerudung\":100000}', 1830000, 3630000, 'Transfer', 'Lunas', '2026-07-11 11:12:55', '2026-07-11 11:00:27'),
(57, 'PSB20263490', 'Tsanawi Non-SMK (Alumni)', 0, 0, 0, 0, '{\"Administrasi Santri Baru\":250000}', 250000, 250000, 'Cash', 'Lunas', '2026-07-11 11:24:19', '2026-07-11 11:07:15'),
(58, 'PSB20268772', 'Tsanawi Non-SMK (Baru)', 800000, 0, 0, 0, '{\"Lemari\":600000,\"Kitab\":270000,\"Laundry (20 Kg)\":100000}', 970000, 1770000, 'Cash', 'Lunas', '2026-07-11 11:43:50', '2026-07-11 11:15:04'),
(59, 'PSB202600487', 'SMP Terpadu Al Hasan', 800000, 1000000, 225000, 230000, '{\"Formulir Pendaftaran\":150000,\"Administrasi Santri Baru\":250000,\"Kitab\":230000,\"Laundry (20 Kg)\":100000,\"Biaya Ujian Tahunan\":250000,\"Seragam Yayasan\":170000,\"Batik SMP\":85000,\"Seragam Olahraga\":160000,\"JAS Almamater\":250000,\"Seragam Muslimah\":150000,\"Sabuk PSAS & Pramuka\":35000,\"Kerudung\":100000}', 1930000, 4185000, 'Transfer', 'Lunas', '2026-07-11 11:48:59', '2026-07-11 11:18:44'),
(60, 'PSB20263118', 'Tsanawi Non-SMK (Baru)', 800000, 1000000, 0, 0, '{\"Formulir Pendaftaran\":150000,\"Administrasi Santri Baru\":250000,\"Lemari\":600000,\"Kitab\":270000,\"Laundry (20 Kg)\":100000,\"Biaya Ujian Tahunan\":150000,\"JAS Almamater\":250000,\"Seragam Muslim\":150000}', 1920000, 3720000, 'Cash', 'Lunas', '2026-07-11 11:27:25', '2026-07-11 11:23:10'),
(61, 'PSB202602394', 'SMP Terpadu Al Hasan', 800000, 0, 225000, 230000, '{\"Administrasi Santri Baru\":250000,\"Lemari\":600000,\"Kitab\":230000,\"Laundry (20 Kg)\":100000,\"Seragam Yayasan\":170000,\"Batik SMP\":85000,\"Seragam Olahraga\":160000,\"JAS Almamater\":250000,\"Seragam Muslimah\":150000,\"Sabuk PSAS & Pramuka\":35000,\"Kerudung\":100000}', 2130000, 3385000, 'Transfer', 'Lunas', '2026-07-11 11:34:47', '2026-07-11 11:31:16'),
(62, 'PSB202605927', 'SMP Terpadu Al Hasan', 0, 0, 190000, 215000, '{\"Formulir Pendaftaran\":150000,\"Administrasi Santri Baru\":250000,\"Seragam Yayasan\":165000,\"Batik SMP\":85000,\"Seragam Olahraga\":155000,\"JAS Almamater\":250000,\"Seragam Muslim\":150000,\"Sabuk PSAS & Pramuka\":35000,\"Dasi\":17500}', 1257500, 1662500, 'Cash', 'Lunas', '2026-07-11 11:39:19', '2026-07-11 11:33:37'),
(63, 'PSB202604045', 'SMP Terpadu Al Hasan', 800000, 1000000, 190000, 215000, '{\"Formulir Pendaftaran\":150000,\"Administrasi Santri Baru\":250000,\"Lemari\":600000,\"Kitab\":230000,\"Laundry (20 Kg)\":100000,\"Biaya Ujian Tahunan\":250000,\"Seragam Yayasan\":165000,\"Batik SMP\":85000,\"Seragam Olahraga\":155000,\"JAS Almamater\":250000,\"Seragam Muslim\":150000,\"Sabuk PSAS & Pramuka\":35000,\"Dasi\":17500}', 2437500, 4642500, 'Transfer', 'Lunas', '2026-07-11 12:03:55', '2026-07-11 11:35:18'),
(64, 'PSB20265175', 'Tsanawi Non-SMK (Baru)', 400000, 0, 0, 0, '{\"Administrasi Santri Baru\":250000,\"Biaya Ujian Tahunan\":150000}', 400000, 800000, 'Cash', 'Lunas', '2026-07-11 11:54:11', '2026-07-11 11:46:29'),
(65, 'PSB20261994', 'Tsanawi Non-SMK (Baru)', 800000, 0, 0, 0, '{\"Formulir Pendaftaran\":150000,\"Administrasi Santri Baru\":250000,\"Kitab\":270000,\"Seragam Muslimah\":150000}', 820000, 1620000, 'Cash', 'Lunas', '2026-07-11 13:11:15', '2026-07-11 11:58:00'),
(66, 'PSB20269064', 'Tsanawi Non-SMK (Alumni)', 600000, 0, 0, 0, '{\"Administrasi Santri Baru\":250000,\"Kitab\":200000,\"Biaya Ujian Tahunan\":150000}', 600000, 1200000, 'Transfer', 'Lunas', '2026-07-11 13:01:28', '2026-07-11 12:14:13'),
(67, 'PSB20266331', 'Tsanawi Non-SMK (Baru)', 800000, 1000000, 0, 0, '{\"Administrasi Santri Baru\":250000,\"Kitab\":270000,\"Biaya Ujian Tahunan\":150000,\"JAS Almamater\":250000,\"Seragam Muslimah\":150000}', 1070000, 2870000, 'Cash', 'Lunas', '2026-07-11 13:18:54', '2026-07-11 13:14:47'),
(68, 'PSB202603334', 'SMP Terpadu Al Hasan', 800000, 1000000, 190000, 215000, '{\"Formulir Pendaftaran\":150000,\"Administrasi Santri Baru\":250000,\"Lemari\":600000,\"Kitab\":230000,\"Laundry (20 Kg)\":100000,\"Biaya Ujian Tahunan\":250000,\"Seragam Yayasan\":165000,\"Seragam Olahraga\":155000,\"JAS Almamater\":250000,\"Seragam Muslim\":150000,\"Sabuk PSAS & Pramuka\":35000,\"Dasi\":17500}', 2352500, 4557500, 'Cash', 'Lunas', '2026-07-11 13:23:49', '2026-07-11 13:19:37'),
(69, 'PSB202601589', 'SMP Terpadu Al Hasan', 800000, 0, 190000, 215000, '{\"Formulir Pendaftaran\":150000,\"Administrasi Santri Baru\":250000,\"Lemari\":600000,\"Kitab\":230000,\"Laundry (20 Kg)\":100000,\"Biaya Ujian Tahunan\":250000,\"Seragam Yayasan\":165000,\"Seragam Olahraga\":155000,\"JAS Almamater\":250000,\"Seragam Muslim\":150000,\"Sabuk PSAS & Pramuka\":35000,\"Dasi\":17500}', 2352500, 3557500, 'Cash', 'Lunas', '2026-07-11 13:31:39', '2026-07-11 13:26:10'),
(70, 'PSB202604874', 'SMP Terpadu Al Hasan', 800000, 1000000, 225000, 230000, '{\"Formulir Pendaftaran\":150000,\"Administrasi Santri Baru\":250000,\"Kitab\":230000,\"Biaya Ujian Tahunan\":250000,\"Seragam Yayasan\":170000,\"Batik SMP\":85000,\"Seragam Olahraga\":160000,\"JAS Almamater\":250000,\"Seragam Muslimah\":150000,\"Sabuk PSAS & Pramuka\":35000,\"Kerudung\":100000}', 1830000, 4085000, 'Transfer', 'Lunas', '2026-07-11 13:40:52', '2026-07-11 13:37:21'),
(71, 'PSB20265815', 'Tsanawi Non-SMK (Alumni)', 0, 0, 0, 0, '{\"Administrasi Santri Baru\":250000,\"Kitab\":200000,\"Biaya Ujian Tahunan\":150000}', 600000, 600000, 'Cash', 'Lunas', '2026-07-11 13:47:17', '2026-07-11 13:44:27'),
(72, 'PSB20267299', 'Tsanawi Non-SMK (Baru)', 800000, 1000000, 0, 0, '{\"Formulir Pendaftaran\":150000,\"Administrasi Santri Baru\":250000,\"Kitab\":270000,\"Biaya Ujian Tahunan\":150000,\"JAS Almamater\":250000,\"Seragam Muslim\":150000}', 1220000, 3020000, 'Cash', 'Lunas', '2026-07-11 14:00:48', '2026-07-11 13:47:18'),
(73, 'PSB20263473', 'Tsanawi Non-SMK (Alumni)', 600000, 0, 0, 0, '{\"Administrasi Santri Baru\":250000}', 250000, 850000, 'Cash', 'Lunas', '2026-07-11 14:34:28', '2026-07-11 13:59:40'),
(74, 'PSB202605419', 'SMP Terpadu Al Hasan', 800000, 1000000, 225000, 230000, '{\"Formulir Pendaftaran\":150000,\"Administrasi Santri Baru\":250000,\"Lemari\":600000,\"Kitab\":230000,\"Biaya Ujian Tahunan\":250000,\"Seragam Yayasan\":170000,\"Batik SMP\":85000,\"Seragam Olahraga\":160000,\"JAS Almamater\":250000,\"Seragam Muslimah\":150000,\"Sabuk PSAS & Pramuka\":35000,\"Kerudung\":100000}', 2430000, 4685000, 'Cash', 'Lunas', '2026-07-11 14:06:45', '2026-07-11 14:03:20'),
(75, 'PSB20263011', 'Tsanawi Non-SMK (Alumni)', 0, 0, 0, 0, '{\"Administrasi Santri Baru\":250000,\"Kitab\":200000,\"Biaya Ujian Tahunan\":150000}', 600000, 600000, 'Cash', 'Lunas', '2026-07-11 14:10:15', '2026-07-11 14:07:22'),
(76, 'PSB20269966', 'Tsanawi Non-SMK (Alumni)', 0, 0, 0, 0, '{\"Administrasi Santri Baru\":250000,\"Kitab\":200000,\"Biaya Ujian Tahunan\":150000}', 600000, 600000, 'Cash', 'Lunas', '2026-07-11 14:13:44', '2026-07-11 14:10:31'),
(77, 'PSB202603791', 'SMP Terpadu Al Hasan', 600000, 0, 0, 0, '{\"Formulir Pendaftaran\":150000,\"Administrasi Santri Baru\":250000,\"Biaya Ujian Tahunan\":250000,\"Batik SMP\":85000,\"JAS Almamater\":250000,\"Seragam Muslimah\":150000}', 1135000, 1735000, 'Cash', 'Lunas', '2026-07-11 14:31:16', '2026-07-11 14:21:31'),
(78, 'PSB20264358', 'Tsanawi Non-SMK (Alumni)', 400000, 0, 0, 0, '{\"Administrasi Santri Baru\":250000,\"Biaya Ujian Tahunan\":150000}', 400000, 800000, 'Cash', 'Lunas', '2026-07-11 14:31:09', '2026-07-11 14:23:37'),
(79, 'PSB20269074', 'Tsanawi Non-SMK (Alumni)', 0, 0, 0, 0, '{\"Administrasi Santri Baru\":250000}', 250000, 250000, 'Cash', 'Lunas', '2026-07-11 14:37:52', '2026-07-11 14:29:31'),
(80, 'PSB20266506', 'Tsanawi Non-SMK (Alumni)', 600000, 0, 0, 0, '{}', 0, 600000, 'Cash', 'Lunas', '2026-07-11 14:40:36', '2026-07-11 14:35:27'),
(81, 'PSB20269374', 'SMK Terpadu Al Hasan (Alumni)', 600000, 0, 200000, 200000, '{\"Administrasi Santri Baru\":250000,\"Kitab\":200000,\"Laundry\":100000,\"Biaya Ujian Tahunan\":250000,\"Seragam PDH SMK\":231000,\"Seragam Olahraga\":190000}', 1221000, 2221000, 'Transfer', 'Lunas', '2026-07-11 14:52:32', '2026-07-11 14:47:35'),
(82, 'PSB20264083', 'Tsanawi Non-SMK (Alumni)', 0, 0, 0, 0, '{\"Administrasi Santri Baru\":250000,\"Biaya Ujian Tahunan\":150000}', 400000, 400000, 'Cash', 'Lunas', '2026-07-11 14:55:56', '2026-07-11 14:53:58'),
(83, 'PSB202601431', 'SMP Terpadu Al Hasan', 800000, 1000000, 225000, 230000, '{\"Formulir Pendaftaran\":150000,\"Administrasi Santri Baru\":250000,\"Laundry (20 Kg)\":100000,\"Biaya Ujian Tahunan\":250000,\"Seragam Yayasan\":170000,\"Batik SMP\":85000,\"Seragam Olahraga\":160000,\"JAS Almamater\":250000,\"Seragam Muslimah\":150000,\"Sabuk PSAS & Pramuka\":35000,\"Kerudung\":100000}', 1700000, 3955000, 'Cash', 'Lunas', '2026-07-11 15:08:34', '2026-07-11 15:05:04'),
(84, 'PSB202605255', 'SMP Terpadu Al Hasan', 800000, 1000000, 0, 215000, '{\"Formulir Pendaftaran\":150000,\"Administrasi Santri Baru\":250000,\"Kitab\":230000,\"Biaya Ujian Tahunan\":250000,\"Seragam Yayasan\":165000,\"Batik SMP\":85000,\"Seragam Olahraga\":155000,\"JAS Almamater\":250000,\"Seragam Muslim\":150000}', 1685000, 3700000, 'Cash', 'Lunas', '2026-07-11 15:18:30', '2026-07-11 15:11:31'),
(85, 'PSB20267776', 'Tsanawi Non-SMK (Baru)', 800000, 0, 0, 0, '{\"Formulir Pendaftaran\":150000,\"Administrasi Santri Baru\":250000,\"Lemari\":600000,\"Kitab\":270000,\"Laundry (20 Kg)\":100000}', 1370000, 2170000, 'Cash', 'Lunas', '2026-07-11 15:25:00', '2026-07-11 15:15:00'),
(87, 'PSB20266289', 'Tsanawi Non-SMK (Alumni)', 0, 0, 0, 0, '{\"Administrasi Santri Baru\":250000,\"Kitab\":200000,\"Biaya Ujian Tahunan\":150000}', 600000, 600000, 'Cash', 'Lunas', '2026-07-11 15:27:51', '2026-07-11 15:20:19'),
(88, 'PSB202602598', 'SMP Terpadu Al Hasan', 800000, 0, 0, 0, '{}', 0, 800000, 'Cash', 'Lunas', '2026-07-11 15:35:15', '2026-07-11 15:27:25'),
(89, 'PSB202602662', 'SMP Terpadu Al Hasan', 800000, 1000000, 225000, 230000, '{\"Formulir Pendaftaran\":150000,\"Administrasi Santri Baru\":250000,\"Biaya Ujian Tahunan\":250000,\"Seragam Yayasan\":170000,\"Batik SMP\":85000,\"Seragam Olahraga\":160000,\"JAS Almamater\":250000,\"Seragam Muslimah\":150000,\"Sabuk PSAS & Pramuka\":35000,\"Kerudung\":100000}', 1600000, 3855000, 'Transfer', 'Lunas', '2026-07-11 15:40:43', '2026-07-11 15:31:54'),
(90, 'PSB20265969', 'Tsanawi Non-SMK (Alumni)', 0, 0, 0, 0, '{\"Administrasi Santri Baru\":250000,\"Kitab\":200000,\"Biaya Ujian Tahunan\":150000}', 600000, 600000, 'Cash', 'Lunas', '2026-07-11 15:44:59', '2026-07-11 15:42:05'),
(91, 'PSB20268215', 'Tsanawi Non-SMK (Baru)', 800000, 1000000, 0, 0, '{\"Formulir Pendaftaran\":150000,\"Administrasi Santri Baru\":250000,\"Lemari\":600000,\"Kitab\":270000,\"Laundry (20 Kg)\":100000,\"Biaya Ujian Tahunan\":150000,\"JAS Almamater\":250000,\"Seragam Muslimah\":150000}', 1920000, 3720000, 'Cash', 'Lunas', '2026-07-11 15:48:19', '2026-07-11 15:44:47'),
(92, 'PSB20263847', 'Tsanawi Non-SMK (Alumni)', 0, 0, 0, 0, '{\"Administrasi Santri Baru\":250000,\"Kitab\":200000,\"Biaya Ujian Tahunan\":150000}', 600000, 600000, 'Cash', 'Lunas', '2026-07-11 15:56:47', '2026-07-11 15:50:34'),
(93, 'PSB202602938', 'SMP Terpadu Al Hasan', 800000, 500000, 225000, 230000, '{\"Formulir Pendaftaran\":150000,\"Administrasi Santri Baru\":250000,\"Kitab\":230000,\"Laundry (20 Kg)\":100000,\"Biaya Ujian Tahunan\":250000,\"Seragam Yayasan\":170000,\"Batik SMP\":85000,\"Seragam Olahraga\":160000,\"JAS Almamater\":250000,\"Seragam Muslimah\":150000,\"Sabuk PSAS & Pramuka\":35000,\"Kerudung\":100000}', 1930000, 3685000, 'Cash', 'Lunas', '2026-07-11 16:20:35', '2026-07-11 15:56:31'),
(94, 'PSB202600756', 'SMP Terpadu Al Hasan', 0, 0, 190000, 215000, '{\"Administrasi Santri Baru\":250000,\"Lemari\":600000,\"Kitab\":230000,\"Seragam Yayasan\":165000,\"Batik SMP\":85000,\"Seragam Olahraga\":155000,\"Sabuk PSAS & Pramuka\":35000,\"Dasi\":17500}', 1537500, 1942500, 'Cash', 'Lunas', '2026-07-11 16:13:25', '2026-07-11 16:09:43'),
(95, 'PSB202603976', 'SMP Terpadu Al Hasan', 800000, 1000000, 190000, 215000, '{\"Formulir Pendaftaran\":150000,\"Administrasi Santri Baru\":250000,\"Lemari\":600000,\"Kitab\":230000,\"Laundry (20 Kg)\":100000,\"Biaya Ujian Tahunan\":250000,\"Seragam Yayasan\":165000,\"Batik SMP\":85000,\"Seragam Olahraga\":155000,\"JAS Almamater\":250000,\"Seragam Muslim\":150000,\"Sabuk PSAS & Pramuka\":35000,\"Dasi\":17500}', 2437500, 4642500, 'Cash', 'Lunas', '2026-07-11 16:27:31', '2026-07-11 16:13:02'),
(96, 'PSB202605585', 'SMP Terpadu Al Hasan', 0, 0, 0, 0, '{\"Administrasi Santri Baru\":250000,\"Lemari\":600000,\"Kitab\":230000,\"Laundry (20 Kg)\":100000,\"Seragam Yayasan\":165000}', 1345000, 1345000, 'Cash', 'Lunas', '2026-07-11 16:32:34', '2026-07-11 16:21:53'),
(97, 'PSB20269928', 'Tsanawi Non-SMK (Baru)', 800000, 1000000, 0, 0, '{\"Formulir Pendaftaran\":150000,\"Administrasi Santri Baru\":250000,\"Biaya Ujian Tahunan\":150000,\"JAS Almamater\":250000,\"Seragam Muslimah\":150000}', 950000, 2750000, 'Transfer', 'Lunas', '2026-07-11 16:59:54', '2026-07-11 16:27:06'),
(99, 'PSB20264856', 'Tsanawi Non-SMK (Alumni)', 600000, 0, 0, 0, '{\"Administrasi Santri Baru\":250000,\"Kitab\":200000,\"Laundry\":100000,\"Biaya Ujian Tahunan\":150000}', 700000, 1300000, 'Cash', 'Lunas', '2026-07-12 13:27:53', '2026-07-12 10:54:20'),
(100, 'PSB20262199', 'Tsanawi Non-SMK (Baru)', 800000, 1000000, 0, 0, '{\"Formulir Pendaftaran\":150000,\"Administrasi Santri Baru\":250000,\"Lemari\":600000,\"Kitab\":270000,\"Biaya Ujian Tahunan\":150000,\"JAS Almamater\":250000,\"Seragam Muslimah\":150000}', 1820000, 3620000, 'Cash', 'Lunas', '2026-07-12 13:32:26', '2026-07-12 13:30:06'),
(101, 'PSB20267362', 'Tsanawi Non-SMK (Alumni)', 0, 0, 0, 0, '{\"Administrasi Santri Baru\":250000,\"Kitab\":200000,\"Biaya Ujian Tahunan\":150000}', 600000, 600000, 'Cash', 'Lunas', '2026-07-12 14:13:21', '2026-07-12 14:12:19'),
(102, 'PSB20269787', 'Tsanawi Non-SMK (Alumni)', 0, 0, 0, 0, '{\"Administrasi Santri Baru\":250000,\"Biaya Ujian Tahunan\":150000}', 400000, 400000, 'Cash', 'Lunas', '2026-07-12 23:32:12', '2026-07-12 14:40:37'),
(103, 'PSB20265157', 'Tsanawi Non-SMK (Alumni)', 600000, 0, 0, 0, '{\"Administrasi Santri Baru\":250000,\"Biaya Ujian Tahunan\":150000}', 400000, 1000000, 'Cash', 'Lunas', '2026-07-12 15:59:24', '2026-07-12 15:52:53'),
(104, 'PSB20264293', 'Tsanawi Non-SMK (Alumni)', 0, 0, 0, 0, '{\"Administrasi Santri Baru\":250000,\"Kitab\":200000,\"Biaya Ujian Tahunan\":150000}', 600000, 600000, 'Cash', 'Lunas', '2026-07-12 15:58:15', '2026-07-12 15:56:26'),
(105, 'PSB202601810', 'SMP Terpadu Al Hasan', 800000, 1000000, 225000, 230000, '{\"Formulir Pendaftaran\":150000,\"Administrasi Santri Baru\":250000,\"Lemari\":600000,\"Laundry (20 Kg)\":100000,\"Biaya Ujian Tahunan\":250000,\"Seragam Yayasan\":170000,\"Batik SMP\":85000,\"Seragam Olahraga\":160000,\"JAS Almamater\":250000,\"Seragam Muslimah\":150000,\"Sabuk PSAS & Pramuka\":35000,\"Kerudung\":100000}', 2300000, 4555000, 'Cash', 'Lunas', '2026-07-12 23:32:25', '2026-07-12 20:38:38'),
(106, 'PSB202601133', 'SMP Terpadu Al Hasan', 800000, 1000000, 190000, 215000, '{\"Formulir Pendaftaran\":150000,\"Administrasi Santri Baru\":250000,\"Lemari\":600000,\"Kitab\":230000,\"Laundry (20 Kg)\":100000,\"Biaya Ujian Tahunan\":250000,\"Seragam Yayasan\":165000,\"Batik SMP\":85000,\"Seragam Olahraga\":155000,\"JAS Almamater\":250000,\"Seragam Muslim\":150000,\"Sabuk PSAS & Pramuka\":35000,\"Dasi\":17500}', 2437500, 4642500, 'Cash', 'Lunas', '2026-07-12 23:31:05', '2026-07-12 21:55:24'),
(107, 'PSB202600816', 'SMP Terpadu Al Hasan', 800000, 1000000, 190000, 215000, '{\"Administrasi Santri Baru\":250000,\"Lemari\":600000,\"Kitab\":230000,\"Laundry (20 Kg)\":100000,\"Biaya Ujian Tahunan\":250000,\"Seragam Yayasan\":165000,\"Batik SMP\":85000,\"Seragam Olahraga\":155000,\"JAS Almamater\":250000,\"Seragam Muslim\":150000,\"Sabuk PSAS & Pramuka\":35000,\"Dasi\":17500}', 2287500, 4492500, 'Cash', 'Lunas', '2026-07-12 23:30:52', '2026-07-12 21:59:39'),
(108, 'PSB202603647', 'SMP Terpadu Al Hasan', 800000, 1000000, 190000, 215000, '{\"Administrasi Santri Baru\":250000,\"Lemari\":600000,\"Kitab\":230000,\"Laundry (20 Kg)\":100000,\"Biaya Ujian Tahunan\":250000,\"Seragam Yayasan\":165000,\"Batik SMP\":85000,\"Seragam Olahraga\":155000,\"JAS Almamater\":250000,\"Seragam Muslim\":150000,\"Sabuk PSAS & Pramuka\":35000,\"Dasi\":17500}', 2287500, 4492500, 'Cash', 'Lunas', '2026-07-12 23:30:39', '2026-07-12 22:01:23'),
(109, 'PSB202601645', 'SMP Terpadu Al Hasan', 800000, 1000000, 225000, 230000, '{\"Formulir Pendaftaran\":150000,\"Administrasi Santri Baru\":250000,\"Lemari\":600000,\"Biaya Ujian Tahunan\":250000,\"Seragam Yayasan\":170000,\"Batik SMP\":85000,\"Seragam Olahraga\":160000,\"JAS Almamater\":250000,\"Seragam Muslimah\":150000,\"Sabuk PSAS & Pramuka\":35000,\"Kerudung\":100000}', 2200000, 4455000, 'Cash', 'Lunas', '2026-07-12 23:30:27', '2026-07-12 22:04:42'),
(110, 'PSB20264796', 'Tsanawi Non-SMK (Alumni)', 600000, 0, 0, 0, '{\"Administrasi Santri Baru\":250000,\"Kitab\":200000,\"Biaya Ujian Tahunan\":150000}', 600000, 1200000, 'Cash', 'Lunas', '2026-07-12 23:30:11', '2026-07-12 22:08:53'),
(111, 'PSB202601238', 'SMP Terpadu Al Hasan', 800000, 1000000, 190000, 215000, '{\"Administrasi Santri Baru\":250000,\"Lemari\":600000,\"Laundry (20 Kg)\":100000,\"Biaya Ujian Tahunan\":250000,\"Seragam Yayasan\":165000,\"Batik SMP\":85000,\"Seragam Olahraga\":155000,\"JAS Almamater\":250000,\"Seragam Muslim\":150000,\"Sabuk PSAS & Pramuka\":35000,\"Dasi\":17500}', 2057500, 4262500, 'Cash', 'Lunas', '2026-07-12 23:29:59', '2026-07-12 22:11:45'),
(112, 'PSB202603057', 'SMP Terpadu Al Hasan', 800000, 1000000, 225000, 230000, '{\"Formulir Pendaftaran\":150000,\"Administrasi Santri Baru\":250000,\"Lemari\":600000,\"Kitab\":230000,\"Laundry (20 Kg)\":100000,\"Biaya Ujian Tahunan\":250000,\"Seragam Yayasan\":170000,\"Batik SMP\":85000,\"Seragam Olahraga\":160000,\"JAS Almamater\":250000,\"Seragam Muslimah\":150000,\"Sabuk PSAS & Pramuka\":35000,\"Kerudung\":100000}', 2530000, 4785000, 'Cash', 'Lunas', '2026-07-12 23:29:39', '2026-07-12 22:13:27'),
(113, 'PSB20262095', 'Tsanawi Non-SMK (Baru)', 800000, 0, 0, 0, '{\"Formulir Pendaftaran\":150000,\"Administrasi Santri Baru\":250000,\"Lemari\":600000}', 1000000, 1800000, 'Cash', 'Lunas', '2026-07-12 23:29:26', '2026-07-12 22:15:37'),
(114, 'PSB202605056', 'SMP Terpadu Al Hasan', 300000, 0, 225000, 0, '{\"Formulir Pendaftaran\":150000,\"Administrasi Santri Baru\":250000,\"Biaya Ujian Tahunan\":250000,\"Seragam Yayasan\":170000,\"Batik SMP\":85000,\"Seragam Olahraga\":160000,\"JAS Almamater\":250000,\"Seragam Muslimah\":150000,\"Sabuk PSAS & Pramuka\":35000,\"Kerudung\":100000}', 1600000, 2125000, 'Cash', 'Lunas', '2026-07-12 23:29:14', '2026-07-12 22:22:49'),
(115, 'PSB20266312', 'SMK Terpadu Al Hasan (Alumni)', 0, 0, 0, 0, '{\"Kitab\":200000}', 200000, 200000, 'Cash', 'Lunas', '2026-07-12 23:28:58', '2026-07-12 22:25:01'),
(116, 'PSB202604559', 'SMP Terpadu Al Hasan', 800000, 1000000, 190000, 215000, '{\"Formulir Pendaftaran\":150000,\"Administrasi Santri Baru\":250000,\"Lemari\":600000,\"Kitab\":230000,\"Biaya Ujian Tahunan\":250000,\"Seragam Yayasan\":165000,\"Batik SMP\":85000,\"Seragam Olahraga\":155000,\"JAS Almamater\":250000,\"Seragam Muslim\":150000,\"Sabuk PSAS & Pramuka\":35000,\"Dasi\":17500}', 2337500, 4542500, 'Cash', 'Lunas', '2026-07-12 23:28:47', '2026-07-12 22:30:35'),
(117, 'PSB202602719', 'SMP Terpadu Al Hasan', 800000, 0, 0, 0, '{\"Formulir Pendaftaran\":150000,\"Administrasi Santri Baru\":250000,\"Lemari\":600000,\"Laundry (20 Kg)\":100000}', 1100000, 1900000, 'Cash', 'Lunas', '2026-07-12 23:28:25', '2026-07-12 22:34:24'),
(118, 'PSB202601358', 'SMP Terpadu Al Hasan', 800000, 1000000, 220000, 0, '{\"Formulir Pendaftaran\":150000,\"Administrasi Santri Baru\":250000,\"Lemari\":600000,\"Kitab\":230000,\"Laundry (20 Kg)\":100000,\"Biaya Ujian Tahunan\":250000,\"Seragam Yayasan\":165000,\"Batik SMP\":85000,\"Seragam Olahraga\":155000,\"JAS Almamater\":250000,\"Seragam Muslim\":150000,\"Sabuk PSAS & Pramuka\":35000,\"Dasi\":17500}', 2437500, 4457500, 'Cash', 'Lunas', '2026-07-12 23:28:14', '2026-07-12 22:43:47'),
(119, 'PSB20269687', 'Tsanawi Non-SMK (Alumni)', 600000, 0, 0, 0, '{\"Administrasi Santri Baru\":250000,\"Kitab\":200000,\"Biaya Ujian Tahunan\":150000}', 600000, 1200000, 'Cash', 'Lunas', '2026-07-12 23:28:00', '2026-07-12 22:48:39'),
(120, 'PSB202604944', 'SMP Terpadu Al Hasan', 0, 0, 0, 0, '{\"Formulir Pendaftaran\":150000,\"Administrasi Santri Baru\":250000}', 400000, 400000, 'Cash', 'Lunas', '2026-07-12 23:27:48', '2026-07-12 22:50:54'),
(121, 'PSB202604385', 'SMP Terpadu Al Hasan', 800000, 1000000, 190000, 215000, '{\"Formulir Pendaftaran\":150000,\"Administrasi Santri Baru\":250000,\"Kitab\":230000,\"Laundry (20 Kg)\":100000,\"Biaya Ujian Tahunan\":250000,\"Seragam Yayasan\":165000,\"Batik SMP\":85000,\"Seragam Olahraga\":155000,\"JAS Almamater\":250000,\"Seragam Muslim\":150000,\"Sabuk PSAS & Pramuka\":35000,\"Dasi\":17500}', 1837500, 4042500, 'Cash', 'Lunas', '2026-07-12 23:31:25', '2026-07-12 22:54:34'),
(122, 'PSB202605739', 'SMP Terpadu Al Hasan', 300000, 1000000, 215000, 0, '{\"Formulir Pendaftaran\":150000,\"Kitab\":230000,\"Biaya Ujian Tahunan\":250000,\"Seragam Yayasan\":165000,\"Batik SMP\":85000,\"Seragam Olahraga\":155000,\"JAS Almamater\":250000,\"Seragam Muslim\":150000,\"Sabuk PSAS & Pramuka\":35000,\"Dasi\":17500}', 1487500, 3002500, 'Cash', 'Lunas', '2026-07-12 23:32:32', '2026-07-12 23:20:08'),
(123, 'PSB20267460', 'SMK Terpadu Al Hasan (Baru)', 800000, 280000, 0, 0, '{\"Formulir Pendaftaran\":150000,\"Administrasi Santri Baru\":250000,\"Lemari\":600000,\"Seragam Muslimah\":150000}', 1150000, 2230000, 'Cash', 'Lunas', '2026-07-12 23:31:40', '2026-07-12 23:26:36'),
(124, 'PSB202601786', 'SMP Terpadu Al Hasan', 800000, 1000000, 190000, 215000, '{\"Formulir Pendaftaran\":150000,\"Administrasi Santri Baru\":250000,\"Kitab\":230000,\"Laundry (20 Kg)\":100000,\"Biaya Ujian Tahunan\":250000,\"Seragam Yayasan\":165000,\"Batik SMP\":85000,\"Seragam Olahraga\":155000,\"JAS Almamater\":250000,\"Seragam Muslim\":150000,\"Sabuk PSAS & Pramuka\":35000,\"Dasi\":17500}', 1837500, 4042500, 'Transfer', 'Lunas', '2026-07-14 08:38:48', '2026-07-13 16:06:30'),
(125, 'PSB202602154', 'SMP Terpadu Al Hasan', 800000, 1000000, 190000, 215000, '{\"Formulir Pendaftaran\":150000,\"Administrasi Santri Baru\":250000,\"Lemari\":600000,\"Kitab\":230000,\"Laundry (20 Kg)\":100000,\"Biaya Ujian Tahunan\":250000,\"Seragam Yayasan\":165000,\"Batik SMP\":85000,\"Seragam Olahraga\":155000,\"JAS Almamater\":250000,\"Seragam Muslim\":150000,\"Sabuk PSAS & Pramuka\":35000,\"Dasi\":17500}', 2437500, 4642500, 'Cash', 'Lunas', '2026-07-14 08:38:27', '2026-07-14 08:38:02');

-- --------------------------------------------------------

--
-- Table structure for table `psb_pendaftar`
--

CREATE TABLE `psb_pendaftar` (
  `id` int(11) NOT NULL,
  `no_pendaftaran` varchar(20) NOT NULL,
  `tgl_daftar` datetime DEFAULT current_timestamp(),
  `nama_lengkap` varchar(100) NOT NULL,
  `nisn` varchar(20) NOT NULL,
  `nik` varchar(20) NOT NULL,
  `tempat_lahir` varchar(50) NOT NULL,
  `tgl_lahir` date NOT NULL,
  `jenis_kelamin` enum('L','P') NOT NULL,
  `alamat_jalan` text NOT NULL,
  `desa` varchar(50) NOT NULL,
  `kecamatan` varchar(50) NOT NULL,
  `kab_kota` varchar(50) NOT NULL,
  `provinsi` varchar(50) NOT NULL,
  `nama_ayah` varchar(100) NOT NULL,
  `nama_ibu` varchar(100) NOT NULL,
  `no_hp_wali` varchar(15) NOT NULL,
  `sekolah_asal` varchar(100) NOT NULL,
  `jenjang_tujuan` varchar(50) NOT NULL,
  `status` enum('Baru','Diterima','Cadangan','Ditolak','Dimigrasi') NOT NULL DEFAULT 'Baru',
  `file_ktp` varchar(255) DEFAULT NULL,
  `file_kk` varchar(255) DEFAULT NULL,
  `file_akta` varchar(255) DEFAULT NULL,
  `file_ijazah` varchar(255) DEFAULT NULL,
  `file_nilai` varchar(255) DEFAULT NULL,
  `file_prestasi` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `psb_pendaftar`
--

INSERT INTO `psb_pendaftar` (`id`, `no_pendaftaran`, `tgl_daftar`, `nama_lengkap`, `nisn`, `nik`, `tempat_lahir`, `tgl_lahir`, `jenis_kelamin`, `alamat_jalan`, `desa`, `kecamatan`, `kab_kota`, `provinsi`, `nama_ayah`, `nama_ibu`, `no_hp_wali`, `sekolah_asal`, `jenjang_tujuan`, `status`, `file_ktp`, `file_kk`, `file_akta`, `file_ijazah`, `file_nilai`, `file_prestasi`) VALUES
(105, 'PSB202602013', '2026-07-09 21:45:57', 'FAJRI MUHAMAD NUR AMALUDIN', '0138971178', '', 'CIAMIS', '1970-01-01', 'L', 'KARANGPAWITAN RT 5 RW 3', 'KARANGPAWITAN', 'KAWALI', 'CIAMIS', 'JAWA BARAT', 'ARIS RUDIANTO', 'IKEU KARTIKA', '081320901128', 'SDN 1 KARANGPAWITAN', 'SMP Terpadu Al Hasan', 'Baru', NULL, NULL, NULL, NULL, NULL, NULL),
(151, 'PSB20269374', '2026-07-10 09:31:18', 'IMAM AHMAD AR-RASYID', '3111346828', '3174082007111003', 'JAKARTA', '2011-07-20', 'L', 'Jl. PLOT III/46 Dusun Duren Tiga', 'Duren Tiga', 'Pancoran', 'Jakarta Selatan', 'DKI Jakarta', 'Abdul Aziz Razak, S.Ag.', 'Eulis Jubaedah', '', 'SMP Terpadu Al Hasan', 'SMK Terpadu Al Hasan', 'Dimigrasi', NULL, NULL, NULL, NULL, NULL, NULL),
(152, 'PSB20262095', '2026-07-10 09:32:02', 'SINAR NURUL MAULIDA', 'TMP2607103099', '3207095702110001', 'Ciamis', '2011-02-17', 'P', 'Citeureup', 'Citeureup', 'Kawali', 'Ciamis', 'Jawa barat', 'Ayi Atma ', 'Dadah Saadah ', '082127804709', 'SMPN 2 Kawali', 'MAN 2 Ciamis', 'Dimigrasi', NULL, NULL, NULL, NULL, NULL, NULL),
(153, 'PSB20264796', '2026-07-10 09:37:28', 'KIKI AURELIA YANUAR ', 'TMP2607103450', '3207085001110002', 'Ciamis', '2011-01-10', 'P', 'Maparah 2 ', 'Maparah ', 'Panjalu', 'Ciamis', 'Jawa barat', 'Aso Solihin ', 'Imas ', '', 'SMP Terpadu Al Hasan ', 'MAN 2 Ciamis', 'Dimigrasi', NULL, NULL, NULL, NULL, NULL, NULL),
(154, 'PSB20269687', '2026-07-10 09:44:32', 'SITI EVA MUJARIFAH', 'TMP2607102216', '3218106401110001', 'Banjar', '2011-01-24', 'P', 'Cibeureum', 'Sidamulih', 'Sidamulih', 'Pangandaran ', 'Jawa barat', 'Uu Suherman ', 'Edeh Harlina ', '085223650684', 'SMP Terpadu Al Hasan ', 'MAN 2 Ciamis', 'Dimigrasi', NULL, NULL, NULL, NULL, NULL, NULL),
(156, 'PSB20263668', '2026-07-10 09:49:26', 'NIDA LABUDA SITI SHOLIHAH', '0101547980', '320622690610001', 'Tasikmalaya', '2010-06-29', 'P', 'Kp. Cibaregbeg RT 021/005', 'Pasirbatang', 'Manonjaya', 'Tasikmalaya', 'Jawa Barat', 'Usup Supriadi', 'Dede Cica Fadilah', '', 'SMP Negeri 1 Manonjaya', 'SMK Terpadu Al Hasan', 'Dimigrasi', NULL, NULL, NULL, NULL, NULL, NULL),
(157, 'PSB20265025', '2026-07-10 09:52:59', 'NIDA SAMROTUL FUADAH', 'TMP2607108157', '3207035910100003', 'CIAMIS', '2010-10-19', 'P', 'WARUNGJATI RT 018/007', 'CIJEUNGJING', 'CIJEUNGJING', 'CIAMIS', 'JAWA BARAT', 'WAHYU ZATNIKA', 'SOPIATIN', '087895045923', 'SMPN 1 CIJEUNGJING', 'SMK Terpadu Al Hasan', 'Dimigrasi', NULL, NULL, NULL, NULL, NULL, NULL),
(158, 'PSB20268632', '2026-07-10 09:55:37', 'LIYANA AINUN UNSA ', '0111542972', '3329015101110004', 'Brebes ', '2011-01-11', 'P', 'Banjarsari Rt 04 Rw 02', 'Banjaran ', 'Salem', 'Brebes ', 'Jawa Tengah ', 'Tanto ', 'Wiwin Winarti', '085601158236', 'MTS Assalam Salem ', 'MAN 2 Ciamis', 'Dimigrasi', NULL, NULL, NULL, NULL, NULL, NULL),
(159, 'PSB20263285', '2026-07-10 10:00:08', 'MUHAMMAD RIZQI MAULANA', 'TMP2607102838', '3207020803100001', 'CIAMIS', '2010-03-08', 'L', 'KALAPANUNGGAL RT 030/012', 'SINDANGSARI', 'CIKONENG', 'CIAMIS', 'JAWA BARAT', 'KUSMAN ABDUL ROJAK', 'EPI FARIDA', '085323040409', 'SMP TERPADU AL HASAN', 'SMK Terpadu Al Hasan', 'Dimigrasi', NULL, NULL, NULL, NULL, NULL, NULL),
(160, 'PSB20262640', '2026-07-10 10:01:56', 'SAVA MARWATI', 'TMP2607107079', '3207186110100006', 'Ciamis', '2010-09-22', 'P', '', '', '', '', '', '', '', '081222102401', 'MTs Al Amin', 'MAN 2 Ciamis', 'Dimigrasi', NULL, NULL, NULL, NULL, NULL, NULL),
(161, 'PSB20262682', '2026-07-10 10:12:20', 'NATHFA ZEIDA FIRLYZIA', 'TMP2607108962', '3329017006110001', 'Brebes', '2011-06-30', 'P', '', '', '', '', '', '', '', '082328638536', 'SMP NEGERI 1 SALEM', 'MAN 2 Ciamis', 'Dimigrasi', NULL, NULL, NULL, NULL, NULL, NULL),
(162, 'PSB20263538', '2026-07-10 10:13:39', 'FAZRY SAEPULOH', '0109044911', '3208182012100001', 'Kuningan', '2010-12-20', 'L', 'Lingkungan Cilame', 'Cigadung', 'Cigugur', 'Kuningan', 'Jawa Barat', 'Aris Wahidin', 'Aminah', '0831674041181', 'SMP Terpadu Al Hasan', 'SMK Terpadu Al Hasan', 'Dimigrasi', NULL, NULL, NULL, NULL, NULL, NULL),
(163, 'PSB20267460', '2026-07-10 10:17:05', 'SYIFA OCTAVIA', '3122445239', '3207147010120002', 'Ciamis', '2012-10-30', 'P', 'Desa Rt/Rw 004/006', 'Margajaya', 'Sukadana', 'Ciamis', 'Jawa Barat', 'Dedi Rosadi', 'Yati Suryati', '085178523371', 'MTSS Margajaya', 'SMK Terpadu Al Hasan', 'Dimigrasi', NULL, NULL, NULL, NULL, NULL, NULL),
(164, 'PSB20262103', '2026-07-10 10:21:21', 'SHOFA NURBAITILLAH', '3106580963', '3208105712100002', 'Kuningan', '2010-12-17', 'P', 'Manis I RT 014 RW 007', 'Ciawilor', 'Ciawigebang', 'Kuningan', 'Jawa Barat', '-', 'Hj. Fatimah', '081548119218', 'SMP Terpadu Al Hasan', 'SMK Terpadu Al Hasan', 'Dimigrasi', NULL, NULL, NULL, NULL, NULL, NULL),
(165, 'PSB20265507', '2026-07-10 10:24:39', 'SA\'DAN AHMAD YAZID', '0102246324', '3207152206100002', 'Ciamis', '2010-06-22', 'L', 'Linkungan Cibitung Hilir', 'Kertasari', 'Ciamis', 'Ciamis', 'Jawa Barat', 'Umar Nawawi', 'Yati Nurhayati', '087874532544', 'SMP Terpadu Al Hasan', 'SMK Terpadu Al Hasan', 'Dimigrasi', NULL, NULL, NULL, NULL, NULL, NULL),
(166, 'PSB20266312', '2026-07-10 10:30:40', 'ADNAN FAJRI', '3105509685', '3207051008100002', 'Ciamis', '2010-08-10', 'L', 'Sukasari RT 018 RW 009', 'Janggala', 'Cidolog', 'Ciamis', 'Jawa Barat', 'Donald Okta Sut Herlan', 'Ade Sumarni', '082119094197', 'SMP Terpadu Al Hasan', 'SMK Terpadu Al Hasan', 'Dimigrasi', NULL, NULL, NULL, NULL, NULL, NULL),
(167, 'PSB-2026-8375', '2026-07-10 12:50:20', 'HIDAYAT MUJIBUL AZMI', '3104547394', '3279042611100001', 'Banjar', '2010-10-26', 'L', 'Dusun Citangkolo RT. 05/RW.01', 'KUJANGSARI', 'LANGENSARI', 'KOTA BANJAR', 'JAWA BARAT', 'Momon Hermawan', 'Habibah', '087826411457', 'MTsN 1 Banjar', 'MAN 2 Ciamis', 'Dimigrasi', 'file_ktp_167_1783664056.jpg', 'file_kk_167_1783664056.jpg', 'file_akta_167_1783664056.jpg', 'file_ijazah_167_1783664056.jpg', 'file_nilai_167_1783664057.jpg', NULL),
(168, 'PSB20264834', '2026-07-10 13:15:04', 'NAZIA ULHAQ', '0117550072', '3329015107110002', 'Brebes', '2011-07-11', 'P', 'Jl.Tegal Jati Dusun.Banjaran RT/RW 05/02', 'Banjaran', 'Banjaran', 'Brebes ', 'Jawa Barat', 'Cahyo', 'Cucum Sismiati', '085740021157', 'MTS Assalam Salem', 'MAN 2 Ciamis', 'Dimigrasi', NULL, NULL, NULL, NULL, NULL, NULL),
(170, 'PSB20261994', '2026-07-10 13:53:09', 'ALIFA NURUL IZATI PUTRI RIDWAN', '3104448746', '3207271309070414', 'Ciamis', '2010-07-31', 'P', 'Dsn.Karang Anyar', 'Cigugur', 'Cigugur', 'Pangandaran', 'Jawa Barat', 'Asep Ridwan Malik', 'Uli Purwanti', '081214051127', 'MTS YBH Cimindi', 'MAN 2 Ciamis', 'Dimigrasi', NULL, NULL, NULL, NULL, NULL, NULL),
(171, 'PSB20268185', '2026-07-10 13:57:58', 'ASEP HIDAYAT', '0109242847', '3207131908100003', 'Ciamis', '2010-08-19', 'L', 'Dsn.Jamursi RT 09 Rw 08', 'Sukajaya', 'Rajadesa', 'Ciamis', 'Jawa Barat', 'Rickin Martin', 'Asri Asriani', '082216301486', 'SMP Terpadu Al Muawwanah', 'MAN 2 Ciamis', 'Baru', NULL, NULL, NULL, NULL, NULL, NULL),
(179, 'PSB20267548', '2026-07-10 15:13:44', 'MUHAMMAD ZIDANI SYAKIR ', '3103186717', '3204383009100002', 'Ciamis', '2010-09-30', 'L', 'Dsn.Cikalagen, RT/RW 006/003', 'Tanjungsari', 'Sadananya', 'Ciamis', 'Jawa Barat', 'Mumu Muhaemin', 'Enung Nurhayati', '087824602987', 'MTS Al Huda Sadananya', 'MAN 2 Ciamis', 'Dimigrasi', NULL, NULL, NULL, NULL, NULL, NULL),
(184, 'PSB20267631', '2026-07-11 08:30:32', 'DANDI NAZRIL AKBAR', 'TMP2607116519', '3207010101110001', 'Ciamis', '2011-01-01', 'L', 'W.R Wetan', 'Imbanagara', 'Ciamis', 'Ciamis', 'Jawa Barat', 'Feriyanto', 'Eni Kartini', '085321131776', 'SMP Terpadu Al Hasan ', 'MAN 2 Ciamis', 'Dimigrasi', NULL, NULL, NULL, NULL, NULL, NULL),
(185, 'PSB20265778', '2026-07-11 09:06:20', 'AI ANNURUL A\'INI', '0118221953', '3207305906110001', 'CIAMIS', '2011-06-19', 'P', 'MULYAJAYA RT 003/007', 'CISAGA', 'CISAGA', 'CIAMIS', 'JAWA BARAT', 'MAMAT HIDAYAT', 'ETI NURHAYATI', '085720576994', 'MTS S AL IGNA CISAGA', 'SMK Terpadu Al Hasan', 'Dimigrasi', NULL, NULL, NULL, NULL, NULL, NULL),
(186, 'PSB20269533', '2026-07-11 09:55:53', 'ADE KHOIRUL FIRMANSYAH', 'TMP2607119225', '1804231312100001', 'Lampung Barat', '2010-12-13', 'L', 'Dsn.Karangtengah, RT/RW 018/003', 'Sukamulya', 'Purwadadi', 'Ciamis ', 'Jawa Barat', 'Johansyah', 'Alrita Hindarsih', '085785460257', 'SMP Terpadu Al Hasan ', 'SMAN 1 Ciamis', 'Dimigrasi', NULL, NULL, NULL, NULL, NULL, NULL),
(187, 'PSB20263490', '2026-07-11 10:14:16', 'NAFISA ASYRI ARAFAH', 'TMP2607114956', '3206076711090002', 'Tasikmalaya', '2009-11-27', 'P', 'Kp. Balongbongan, RT/RW 004/004', 'Cibanteng', 'Parungponteng', 'Tasikmalaya', 'Jawa Barat', 'Agus Mustabat', 'Enoh Nurhasanah', '085324988000', 'SMP Terpadu Al Hasan ', 'MAN 2 Ciamis', 'Dimigrasi', NULL, NULL, NULL, NULL, NULL, NULL),
(188, 'PSB20263118', '2026-07-11 10:18:29', 'ZIDAN RIZKI RAMADHAN', 'TMP2607117226', '', 'Ciamis', '2010-08-19', 'L', 'Sukaharja', 'Sukaharja', 'Rajadesa', 'Ciamis', 'Jawa Barat', 'Wahyu Hidayat', 'Cicih Wiarsih', '081222195953', 'SMPN 2 Rajadesa', 'MAN 2 Ciamis', 'Dimigrasi', NULL, NULL, NULL, NULL, NULL, NULL),
(189, 'PSB20261227', '2026-07-11 10:26:23', 'RIKZA ABDUL AZIZ', 'TMP2607115118', '3207040106100001', 'Ciamis', '2010-08-01', 'L', 'Dsn.Sukanguncal, RT/RW 001/006', 'Tanjungsari', 'Sadananya', 'Ciamis', 'Jawa Barat', 'Aep Saepuloh', 'Ulpah Yatipah', '082118496252', 'SMP Terpadu Al Hasan ', 'MAN 2 Ciamis', 'Dimigrasi', NULL, NULL, NULL, NULL, NULL, NULL),
(190, 'PSB20268772', '2026-07-11 10:34:24', 'AFGHAN FIRMANSYAH', '0105232679', '3207090304000001', 'Ciamis', '2010-02-12', 'L', 'Kp. Sukamaju, RT/RW 24/11', 'Talagasari', 'Kawali', 'Ciamis', 'Jawa Barat', 'Taupik Rahman', 'Ai Masruroh', '082116136062', 'SMPN 1 Kawali', 'MAN 2 Ciamis', 'Dimigrasi', NULL, NULL, NULL, NULL, NULL, NULL),
(191, 'PSB20266331', '2026-07-11 10:57:19', 'LIYANA PILZA JAUHAR', '0103556378', '327906509100001', 'Banjar', '2010-09-25', 'P', 'Jl. Tentara Pelajar 711, Dsn. Girimulya, RT/RW 001/013,', 'Binangun', 'Pataruman', 'Banjar', 'Jawa Barat', 'Undang Nana', 'Ikah', '085223454654', 'SMP IT Al-Fawwaz', 'MAN 2 Ciamis', 'Dimigrasi', NULL, NULL, NULL, NULL, NULL, NULL),
(192, 'PSB20265175', '2026-07-11 11:13:36', 'HAYATINNUFUS AL BADAR', '0116411160', '3207045304110001', 'Ciamis', '2011-04-13', 'P', 'Dsn. Desa ', 'Werasari', 'Sadananya', 'Ciamis', 'Jawa Barat', 'Endar Darussalim', 'Ebah Saebah', '081313361865', 'SMP Terpadu Al Hasan ', 'MAN 2 Ciamis', 'Dimigrasi', NULL, NULL, NULL, NULL, NULL, NULL),
(193, 'PSB20265742', '2026-07-11 11:21:03', 'HANANIA FITRIANI', 'TMP2607111461', '3207034709100003', 'Ciamis', '2010-09-07', 'P', 'Dsn. Kersikan, RT/RW 025/010', 'Handapherang', 'Cijeungjing', 'Ciamis', 'Jawa Barat', 'Maman Bin Toharik', 'Sylvianti', '085724984579', 'SMP Terpadu Al Hasan ', 'SMAN 1 Ciamis', 'Dimigrasi', NULL, NULL, NULL, NULL, NULL, NULL),
(195, 'PSB20269064', '2026-07-11 11:48:28', 'FAIZ AZKA MUBAROK', 'TMP2607116159', '3207250307100002', 'Ciamis', '2010-07-03', 'L', 'Dsn. Cidawung, RT/RW 01/09', 'Margacinta', 'Cijulang', 'Pangandaran ', 'Jawa Barat', 'Suryatno', 'Yayah Haryati', '081313730231', 'SMP Terpadu Al Hasan ', 'MAN 2 Ciamis', 'Dimigrasi', NULL, NULL, NULL, NULL, NULL, NULL),
(196, 'PSB20267299', '2026-07-11 11:55:12', 'SAYYID MALIK ABRORI', '0107500041', '320719200810004', 'Ciamis', '2010-08-20', 'L', 'Dsn. Karangsari', 'Bangunsari', 'Pamarican', 'Ciamis', 'Jawa Barat', 'Ade Abdurrahman', 'Enung Nurhasanah', '082119825660', 'MTSN 7 Ciamis', 'MAN 2 Ciamis', 'Dimigrasi', NULL, NULL, NULL, NULL, NULL, NULL),
(197, 'PSB20265815', '2026-07-11 13:23:27', 'HANIA RAHMA IQNI', 'TMP2607113276', '', 'Ciamis', '2010-09-23', 'P', 'Rancapetir', 'Rancapetir', 'Ciamis', 'Ciamis', 'Jawa Barat', 'Muhammad Iqbal Rifa\'i', 'Nining Wahdaningsih', '089526026248', 'SMP Terpadu Al Hasan ', 'MAN 2 Ciamis', 'Dimigrasi', NULL, NULL, NULL, NULL, NULL, NULL),
(198, 'PSB20263473', '2026-07-11 13:29:37', 'MUHAMMAD HAFIDL NIJAR MUTTAQIN', 'TMP2607113709', '3207111612100001', 'Ciamis', '2010-12-16', 'L', 'Dsn. Sukasari', 'Sukawening', 'Cipaku', 'Ciamis', 'Jawa Barat', 'Iin Abdul Aziz', 'Solihat Aripatul Janah', '081282414037', 'SMP Terpadu Al Hasan ', 'MAN 2 Ciamis', 'Dimigrasi', NULL, NULL, NULL, NULL, NULL, NULL),
(199, 'PSB20264358', '2026-07-11 13:33:07', 'SYAHLA SHABIRA HELMI', 'TMP2607114682', '3207015811100003', 'Ciamis', '2010-11-18', 'P', 'Lingkungan Kedung Panjang, RT/RW 001/002', 'Maleber', 'Ciamis', 'Ciamis', 'Jawa Barat', 'Mochamad Helmi', 'Siti Rochimah', '081573453378', 'SMP Terpadu Al Hasan ', 'SMAN 2 Ciamis', 'Dimigrasi', NULL, NULL, NULL, NULL, NULL, NULL),
(200, 'PSB20269966', '2026-07-11 13:37:35', 'NAJMA ZHAFIRAH NURSALIMAH', 'TMP2607114864', '3207114706110003', 'Ciamis', '2011-06-07', 'P', 'Dsn. Sukadana, RT/RW 004/006', 'Sukawening', 'Cipaku', 'Ciamis', 'Jawa Barat', 'Maman', 'Mamay Nurkomala', '085316981050', 'SMP Terpadu Al Hasan ', 'SMAN 2 Ciamis', 'Dimigrasi', NULL, NULL, NULL, NULL, NULL, NULL),
(201, 'PSB20263011', '2026-07-11 13:43:30', 'SITI MARYAM NURLATIFAH TIRA SETIAWAN', 'TMP2607112120', '3206225906110004', 'Manonjaya', '2011-06-19', 'P', 'Kp. Pair Panjang, Dsn. Pasirpanjang, RT/RW 002/001', 'Kalimanggis', 'Manonjaya', 'Tasikmalaya', 'Jawa Barat', 'Wawan Setiawan', 'Eti Rohaeti', '085161982077', 'SMP Terpadu Al Hasan ', 'MAN 2 Ciamis', 'Dimigrasi', NULL, NULL, NULL, NULL, NULL, NULL),
(202, 'PSB20269074', '2026-07-11 14:06:17', 'KEYLA KANAZWA AZZAHRA', 'TMP2607117998', '3207016104100003', 'Majalengka', '2010-04-21', 'P', 'Dsn. Cimanggu', 'Linggasari', 'Ciamis', 'Ciamis', 'Jawa Barat', 'Yayan Robayani', 'Iis Suryani', '085943295201', 'SMP Terpadu Al Hasan ', 'SMAN 2 Ciamis', 'Dimigrasi', NULL, NULL, NULL, NULL, NULL, NULL),
(206, 'PSB20267776', '2026-07-11 14:19:10', 'ZAHIRA MAULIDATUL HASANAH', '0114199718', '3207325403110001', 'Ciamis', '2011-03-14', 'P', 'Dsn. Ragapulu', 'Jelat', 'Baregbeg', 'Ciamis', 'Jawa Barat', 'Aep Saepulloh', 'Enah Nurhasanah', '087858986018', 'MTS Babakan', 'MAN 2 Ciamis', 'Dimigrasi', NULL, NULL, NULL, NULL, NULL, NULL),
(207, 'PSB20266506', '2026-07-11 14:22:27', 'ARIP RAHMAN HAKIM', '0109848988', '3207041609100001', 'Ciamis', '2010-09-16', 'L', 'Dsn. Sukawening', 'Tanjungsari', 'Sadananya', 'Ciamis', 'Jawa Barat', 'Idi', 'Idah', '081214110526', 'SMP Terpadu Al Hasan ', 'SMKN 2 Ciamis', 'Dimigrasi', NULL, NULL, NULL, NULL, NULL, NULL),
(208, 'PSB20264083', '2026-07-11 14:36:19', 'AZHAR ZAIDAN', 'TMP2607118882', '3279013001110002', 'Banjar', '2011-01-30', 'L', 'Lingkungan Sumanding wetan RT/RW 02/23', 'Mekarsari', 'Banjar', 'Banjar', 'Jawa Barat', 'Asep Harjana', 'Nurnianingsih', '085285030021', 'SMP Terpadu Al Hasan ', 'MAN 2 Ciamis', 'Dimigrasi', NULL, NULL, NULL, NULL, NULL, NULL),
(209, 'PSB20266289', '2026-07-11 14:44:02', 'ALVI KHOIRUL AKMAL', 'TMP2607117647', '3207040812100001', 'Ciamis', '2010-12-08', 'L', 'Kp. Ngenol, RT/RW 010/005', 'Puspamukti', 'Cigalontang', 'Tasikmalaya', 'Jawa Barat', '', 'Teti Nurhayati', '082315446524', 'SMP Terpadu Al Hasan ', 'SMAN 1 Ciamis', 'Dimigrasi', NULL, NULL, NULL, NULL, NULL, NULL),
(210, 'PSB20268215', '2026-07-11 15:07:44', 'AISHA AKILA RAMADANI', '3108970694', '3671124109100006', 'Ciamis', '2010-09-01', 'P', 'Dsn. Wetan, RT/RW 019/005', 'Jatinagara', 'Jatinagara', 'Ciamis', 'Jawa Barat', 'Didik Yusuf Daryono', 'Enung Nuraeni', '081227367455', 'MTS Terpadu Riyadul Hidayah Al Munawwaroh', 'MAN 2 Ciamis', 'Dimigrasi', NULL, NULL, NULL, NULL, NULL, NULL),
(211, 'PSB20265969', '2026-07-11 15:13:16', 'FADLA NAJMIATUL JANNAH', 'TMP2607117483', '3207086106110001', 'Ciamis', '2011-06-21', 'P', 'Dsn. Desa Kaler, RT/RW 001/001', 'Cihaurbeuti', 'Cihaurbeuti', 'Ciamis', 'Jawa Barat', 'Yayat Sudrajat', 'Pujianti', '', 'SMP Terpadu Al Hasan ', 'MAN 2 Ciamis', 'Dimigrasi', NULL, NULL, NULL, NULL, NULL, NULL),
(212, 'PSB20263847', '2026-07-11 15:17:21', 'ALYA ZULFA MUTA\'AMIMAH', 'TMP2607113260', '', 'Ciamis', '2009-11-23', 'P', 'Dsn. Sirnarasa, RT/RW 04/02', 'Beber', 'Cimaragas', 'Ciamis', 'Jawa Barat', 'Irfan', 'Didah Mardiah', '082217805660', 'SMP Terpadu Al Hasan ', 'MAN 2 Ciamis', 'Dimigrasi', NULL, NULL, NULL, NULL, NULL, NULL),
(213, 'PSB20269928', '2026-07-11 15:33:49', 'SYIFA ALMAIRA FITRIA', 'TMP2607118885', '3207014903110001', 'Ciamis', '2011-03-09', 'P', 'Lingkungan Desa, RT/RW 003/003', 'Kertasari', 'Ciamis', 'Ciamis', 'Jawa Barat', 'Indrawan Fitrayana', 'Wulan Desryna', '085723603070', 'MTS Muhammadiyah Rancah', 'MAN 2 Ciamis', 'Dimigrasi', NULL, NULL, NULL, NULL, NULL, NULL),
(214, 'PSB20264856', '2026-07-12 09:54:09', 'ANDIKA RAMDHAN', '0108352557', '3207101908100001', 'Ciamis', '2010-08-19', 'L', 'Dsn. Bojongnangka, RT/RW 015/006', 'Karangpaningal', 'Panawangan', 'Ciamis', 'Jawa Barat', 'Nana Sudiana', 'Herna Rahmawati', '081313585580', 'SMP Terpadu Al Hasan ', 'MAN 2 Ciamis', 'Dimigrasi', NULL, NULL, NULL, NULL, NULL, NULL),
(215, 'PSB20262199', '2026-07-12 12:57:00', 'SILVIA DWI JULIANTI', 'TMP2607125475', '320705510710001', 'Ciamis', '2010-07-11', 'P', 'Dsn. Sukamantri, RT/RW 005/002', 'Sukasari', 'Cidolog', 'Ciamis', 'Jawa Barat', 'Riyanto', 'Ati Suhayati', '085223836703', 'SMP IT Al Fawwaz ', 'MAN 2 Ciamis', 'Dimigrasi', NULL, NULL, NULL, NULL, NULL, NULL),
(216, 'PSB20267362', '2026-07-12 14:01:46', 'ILHAM MUHAMAD FAUJAN ', 'TMP2607123248', '3207110504100002', 'Ciamis', '2010-04-05', 'L', 'Dsn. Desa Rt 04 Rw 03', 'Jalatrang ', 'Cipaku', 'Ciamis', 'Jawa Barat', 'Kosim ', 'Munawaroh ', '085223147692', 'SMP Terpadu Al Hasan ', 'MAN 2 Ciamis', 'Dimigrasi', NULL, NULL, NULL, NULL, NULL, NULL),
(217, 'PSB20269787', '2026-07-12 14:12:56', 'MUHAMMAD YASSIR IRZAQI', 'TMP2607128463', '3207331907100001', 'Ciamis', '2010-07-19', 'L', 'Dsn. Caringin Rt 38 Rw 13', 'Cibeureum ', 'Sukamantri ', 'Ciamis ', 'Sukamantri ', 'Dede Heris ', 'Laelatussolihah ', '085222329407', 'SMP Terpadu Al Hasan ', 'SMAN 1 Ciamis', 'Dimigrasi', NULL, NULL, NULL, NULL, NULL, NULL),
(220, 'PSB20265157', '2026-07-12 15:43:52', 'NABIL MUHAMMAD ZAKI', 'TMP2607129453', '', 'Ciamis', '2011-05-07', 'L', 'Jl. Cokroaminoto, RT/RW 01/25', 'Ciamis', 'Ciamis', 'Ciamis', 'Jawa Barat', 'Loso', 'Suminem', '085700405208', 'SMP Terpadu Al Hasan ', 'MAN 2 Ciamis', 'Dimigrasi', NULL, NULL, NULL, NULL, NULL, NULL),
(221, 'PSB20264293', '2026-07-12 15:45:58', 'MARSHA CAHYA AL KHAIRA', 'TMP2607122275', '', 'Cilacap', '2011-03-06', 'P', 'Dsn. Tambangan, RT/RW 05/01', 'Wringinharjo', 'Gandrungmango', 'Cilacap', 'Jawa Tengah ', 'Raslan', 'Eni Nuraeni', '082242903013', 'SMP Terpadu Al Hasan ', 'MAN 2 Ciamis', 'Dimigrasi', NULL, NULL, NULL, NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `santri`
--

CREATE TABLE `santri` (
  `id` int(11) NOT NULL,
  `nis` varchar(20) NOT NULL,
  `nama_santri` varchar(100) NOT NULL,
  `jenis_kelamin` enum('L','P') NOT NULL,
  `tempat_lahir` varchar(50) NOT NULL,
  `tgl_lahir` date NOT NULL,
  `alamat` text NOT NULL,
  `desa` varchar(50) NOT NULL,
  `kecamatan` varchar(50) NOT NULL,
  `kab_kota` varchar(50) NOT NULL,
  `provinsi` varchar(50) NOT NULL,
  `nama_ayah` varchar(100) NOT NULL,
  `no_hp_ayah` varchar(20) DEFAULT NULL,
  `nama_ibu` varchar(100) NOT NULL,
  `no_hp_ibu` varchar(20) DEFAULT NULL,
  `asal_sekolah` varchar(100) NOT NULL,
  `sekolah_saat_ini` varchar(50) NOT NULL,
  `foto` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `santri`
--

INSERT INTO `santri` (`id`, `nis`, `nama_santri`, `jenis_kelamin`, `tempat_lahir`, `tgl_lahir`, `alamat`, `desa`, `kecamatan`, `kab_kota`, `provinsi`, `nama_ayah`, `no_hp_ayah`, `nama_ibu`, `no_hp_ibu`, `asal_sekolah`, `sekolah_saat_ini`, `foto`) VALUES
(1, '2502001', 'ADIT CANDRA NUGRAHA', 'L', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', '', 'default.jpg'),
(2, '2502002', 'ADITIA SURYADINATA', 'L', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', '', 'default.jpg'),
(3, '2502003', 'ADRIAN MUHAMMAD ALFARIZI', 'L', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', '', 'default.jpg'),
(4, '2502004', 'AJMI UL HUSNA', 'L', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', '', 'default.jpg'),
(5, '2502005', 'ASEP FIRMAN', 'L', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', '', 'default.jpg'),
(6, '2502006', 'FAIZ ABDUL MUJIB', 'L', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', '', 'default.jpg'),
(7, '2502007', 'FAUZI UBAIDILLAH', 'L', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', '', 'default.jpg'),
(8, '2502008', 'GIRI NUGRAHA PUTRA', 'L', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', '', 'default.jpg'),
(9, '2502009', 'HILALUNAJA ASSYA\'BANI', 'L', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', '', 'default.jpg'),
(10, '2502010', 'IQBAL ALVIS ALVARO', 'L', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', '', 'default.jpg'),
(11, '2502011', 'IRFAN WAHYUDIN LENAMAH', 'L', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', '', 'default.jpg'),
(12, '2502012', 'JUNAEDIN SETTE', 'L', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', '', 'default.jpg'),
(13, '2502013', 'KAFFA MUAMMAD ALFAREZEL', 'L', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', '', 'default.jpg'),
(14, '2502014', 'KUMARA RAKHA P F', 'L', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', '', 'default.jpg'),
(15, '2502015', 'MUHAMMAD AFNAN JANNATIN', 'L', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', '', 'default.jpg'),
(16, '2502016', 'MUHAMMAD FAUZAN KAMIL M', 'L', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', '', 'default.jpg'),
(17, '2502017', 'MUHAMMAD GEMILANG DWI SAMSUDIN', 'L', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', '', 'default.jpg'),
(18, '2502018', 'MUHAMMAD HUSAIN', 'L', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', '', 'default.jpg'),
(19, '2502019', 'MUHAMMAD JIDAN FAUZI RAMADHAN', 'L', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', '', 'default.jpg'),
(20, '2502020', 'MUHAMMAD KHAERUN NAJIB', 'L', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', '', 'default.jpg'),
(21, '2502021', 'MUHAMMAD NAZAR FAUZI', 'L', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', '', 'default.jpg'),
(22, '2502022', 'MUHAMMAD RAFA FAUZAN KAMIL', 'L', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', '', 'default.jpg'),
(23, '2502023', 'MUHAMMAD RIVAL NUR\'AZMI', 'L', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', '', 'default.jpg'),
(24, '2502024', 'MUHAMMAD SAEPULLOH', 'L', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', '', 'default.jpg'),
(25, '2502025', 'RAFA RAHMATUL ARIEF', 'L', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', '', 'default.jpg'),
(26, '2502026', 'RAIHAN', 'L', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', '', 'default.jpg'),
(27, '2502027', 'RIFHAN ZAIN MUHAROM', 'L', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', '', 'default.jpg'),
(28, '2502028', 'RIFKI ADAM NUGRAHA', 'L', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', '', 'default.jpg'),
(29, '2502029', 'RIZQO HABIBI FADLY', 'L', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', '', 'default.jpg'),
(30, '2502030', 'WIRA ADI SAPUTRA', 'L', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', '', 'default.jpg'),
(31, '2502031', 'ZEA KAAFIN HAULI', 'L', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', '', 'default.jpg'),
(32, '2502032', 'ADE PIKA NURUL FITRIYAH', 'P', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', '', 'default.jpg'),
(33, '2502033', 'AGHNI FAHIRA SYAKILA', 'P', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', '', 'default.jpg'),
(34, '2502034', 'AISYAH AHZA', 'P', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', '', 'default.jpg'),
(35, '2502035', 'AISYAH NURHIDAYAH', 'P', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', '', 'default.jpg'),
(36, '2502036', 'ALMA KHAIRUN NISA', 'P', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', '', 'default.jpg'),
(37, '2502037', 'AULIA EFANA', 'P', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', '', 'default.jpg'),
(38, '2502038', 'AZMI NURY SAFFANAH', 'P', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', '', 'default.jpg'),
(39, '2502039', 'BELA NABILA SALMA', 'P', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', '', 'default.jpg'),
(40, '2502040', 'CHINTA MELIZA APRILIA', 'P', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', '', 'default.jpg'),
(41, '2502041', 'DELIA SIFA MAHARANI', 'P', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', '', 'default.jpg'),
(42, '2502042', 'DILA NURFADILAH', 'P', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', '', 'default.jpg'),
(43, '2502043', 'EMI WIDISULISIO LENAMAH', 'P', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', '', 'default.jpg'),
(44, '2502044', 'FEBRIANTI HIJRA SYAIRAINI MAULIDA', 'P', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', '', 'default.jpg'),
(45, '2502045', 'FERA WILDIYANTI', 'P', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', '', 'default.jpg'),
(46, '2502049', 'NAILA AMALIA', 'P', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', '', 'default.jpg'),
(47, '2502051', 'NAZIA AINNURRAFIQ', 'P', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', '', 'default.jpg'),
(48, '2502052', 'NISRINA ALMAHIRA', 'P', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', '', 'default.jpg'),
(49, '2502053', 'NUR ANDINI', 'P', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', '', 'default.jpg'),
(50, '2502054', 'RAFIQAH ZUKFATURROHMAH', 'P', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', '', 'default.jpg'),
(51, '2502055', 'RAHMALIA DWI OKTAVIANI', 'P', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', '', 'default.jpg'),
(52, '2502056', 'RAISHA SETYA PRATIWI', 'P', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', '', 'default.jpg'),
(53, '2502057', 'RANTHI SILVIA', 'P', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', '', 'default.jpg'),
(54, '2502058', 'SAFA ALIFAH RULLIYANAHA', 'P', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', '', 'default.jpg'),
(55, '2502059', 'SAFA SADIYA FAUZIYYAH', 'P', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', '', 'default.jpg'),
(56, '2502060', 'SYAMROTUL ASYIFA', 'P', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', '', 'default.jpg'),
(57, '2502061', 'SYIFA AULIA\'U RAHMAH', 'P', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', '', 'default.jpg'),
(58, '2502062', 'SYIFA FAUJIAH', 'P', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', '', 'default.jpg'),
(59, '2502063', 'TASYA NURWITA AINI', 'P', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', '', 'default.jpg'),
(60, '2402001', 'AHMAD RAFQI MAULANA', 'L', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', '', 'default.jpg'),
(61, '2402002', 'AIDIL RAHMADIAN FARHANI', 'L', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', '', 'default.jpg'),
(62, '2402003', 'AUFA RAIHAN SHENDRA', 'L', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', '', 'default.jpg'),
(63, '2402004', 'DAFFI ADRIANSYAH', 'L', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', '', 'default.jpg'),
(64, '2402005', 'FADIL IRFAN RIFANI', 'L', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', '', 'default.jpg'),
(65, '2402006', 'FAKHRUL M. MIHARJA', 'L', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', '', 'default.jpg'),
(66, '2402007', 'FIKRI HIDAYAT', 'L', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', '', 'default.jpg'),
(67, '2402008', 'M. HIRZAN AL FATHONI', 'L', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', '', 'default.jpg'),
(68, '2402009', 'MUHAMMAD EZA', 'L', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', '', 'default.jpg'),
(69, '2402010', 'MUHAMMAD FAIZ', 'L', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', '', 'default.jpg'),
(70, '2402011', 'MUHAMMAD ZIDANE PRATAMA', 'L', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', '', 'default.jpg'),
(71, '2402012', 'NAUFAL ZAKY NAFIS', 'L', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', '', 'default.jpg'),
(72, '2402013', 'NAZHIF RAJENDRA WARAPRADA', 'L', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', '', 'default.jpg'),
(73, '2402014', 'PAUJANUDIN', 'L', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', '', 'default.jpg'),
(74, '2402015', 'RIFA ALFAUZI', 'L', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', '', 'default.jpg'),
(75, '2402016', 'RIFKI MAULANA', 'L', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', '', 'default.jpg'),
(76, '2402017', 'ROBHI LUCKY LAFARO', 'L', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', '', 'default.jpg'),
(77, '2402018', 'SYAM RAFI IZZULHAQ', 'L', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', '', 'default.jpg'),
(78, '2402019', 'ZIYAN URFA FUADI', 'L', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', '', 'default.jpg'),
(79, '2402020', 'AGNIA SRI MULYANI', 'P', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', '', 'default.jpg'),
(80, '2402021', 'ALIFIA HAFIZAH SOINBALA', 'P', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', '', 'default.jpg'),
(81, '2402022', 'ALMA FAUZIAH', 'P', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', '', 'default.jpg'),
(82, '2402023', 'DEA NEYSA SALSABILA', 'P', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', '', 'default.jpg'),
(83, '2402024', 'DELA NOVITA', 'P', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', '', 'default.jpg'),
(84, '2402025', 'DEWI AISYAH', 'P', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', '', 'default.jpg'),
(85, '2402026', 'DIDAH HINDUN HAMIDAH', 'P', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', '', 'default.jpg'),
(86, '2402027', 'DINDA SYIFA FAUZIAH', 'P', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', '', 'default.jpg'),
(87, '2402028', 'ELA MARSELA', 'P', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', '', 'default.jpg'),
(88, '2402029', 'HANA KHALIDAH  HERMANA', 'P', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', '', 'default.jpg'),
(89, '2402030', 'HANUN ALENA', 'P', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', '', 'default.jpg'),
(90, '2402031', 'HARISMA NIZAMI', 'P', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', '', 'default.jpg'),
(91, '2402032', 'HIKNI AULIA', 'P', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', '', 'default.jpg'),
(92, '2402033', 'IKRIMA AULIA', 'P', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', '', 'default.jpg'),
(93, '2402034', 'ILQYA KARIMA DINAN', 'P', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', '', 'default.jpg'),
(94, '2402035', 'KEYSA DWI SAFITRI', 'P', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', '', 'default.jpg'),
(95, '2402036', 'LARAS SEKAR KINANTI', 'P', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', '', 'default.jpg'),
(96, '2402037', 'LIDYA NAFISAH YULIANI', 'P', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', '', 'default.jpg'),
(97, '2402038', 'LIVIA ZIA HEMANIKA', 'P', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', '', 'default.jpg'),
(98, '2402039', 'ONG TIYEN MAEMUNAH', 'P', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', '', 'default.jpg'),
(99, '2402040', 'RAHMA AZIZAH', 'P', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', '', 'default.jpg'),
(100, '2402041', 'RIZZA SALSABILA', 'P', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', '', 'default.jpg'),
(101, '2402042', 'ROHMATUN NISA', 'P', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', '', 'default.jpg'),
(102, '2402043', 'SALMA AISHA MANENTI', 'P', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', '', 'default.jpg'),
(103, '2402044', 'SELLA AULIA RAHMA', 'P', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', '', 'default.jpg'),
(104, '2402045', 'SILFANITA NURHAFIZHAH', 'P', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', '', 'default.jpg'),
(105, '2402046', 'SINTA LESTARI', 'P', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', '', 'default.jpg'),
(106, '2402047', 'VINA NURUL HIDAYAH', 'P', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', '', 'default.jpg'),
(107, '2402048', 'WARDA SNAE', 'P', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', '', 'default.jpg'),
(108, '2402049', 'WIDIYANI', 'P', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', '', 'default.jpg'),
(109, '2402050', 'WINARTI ANGGRAINI', 'P', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', '', 'default.jpg'),
(110, '2401001', 'ADITYA FATHUROHMAN', 'L', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', 'SMP Terpadu Al Hasan', 'default.jpg'),
(111, '2401002', 'AFRIZAL DENNIS LOVIAN', 'L', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', 'SMP Terpadu Al Hasan', 'default.jpg'),
(112, '2401003', 'AGUS BRYAN SAPUTRA', 'L', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', 'SMP Terpadu Al Hasan', 'default.jpg'),
(113, '2401004', 'AHMAD AR-RAFFI HERDIANA', 'L', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', 'SMP Terpadu Al Hasan', 'default.jpg'),
(114, '2401005', 'AL HAFIZ ZULKIFLI SOINBALA', 'L', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', 'SMP Terpadu Al Hasan', 'default.jpg'),
(115, '2401006', 'AZMI ASYAM OKTAVIAN', 'L', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', 'SMP Terpadu Al Hasan', 'default.jpg'),
(116, '2401007', 'AZRIEL ALWI AL HADAD', 'L', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', 'SMP Terpadu Al Hasan', 'default.jpg'),
(117, '2401008', 'FADIL MAULA YAHSYA', 'L', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', 'SMP Terpadu Al Hasan', 'default.jpg'),
(118, '2401009', 'FAHMI BARONSAH BAHRI', 'L', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', 'SMP Terpadu Al Hasan', 'default.jpg'),
(119, '2401010', 'FAUZAN NUGRAHA PRIATAMA', 'L', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', 'SMP Terpadu Al Hasan', 'default.jpg'),
(120, '2401011', 'FIRLY MAULANA AL GHIFFARI', 'L', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', 'SMP Terpadu Al Hasan', 'default.jpg'),
(121, '2401012', 'HABIB LUQMAN AWWALI MANSHUR', 'L', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', 'SMP Terpadu Al Hasan', 'default.jpg'),
(122, '2401013', 'HYKAL ARYA PUTRA', 'L', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', 'SMP Terpadu Al Hasan', 'default.jpg'),
(123, '2401014', 'IKMALUL HAMDI RAMADHAN', 'L', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', 'SMP Terpadu Al Hasan', 'default.jpg'),
(124, '2401015', 'MUHAMAD RIZKI PRATAÎœÎ‘', 'L', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', 'SMP Terpadu Al Hasan', 'default.jpg'),
(125, '2401016', 'MUHAMMAD ADZFAR ALFAHRIY', 'L', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', 'SMP Terpadu Al Hasan', 'default.jpg'),
(126, '2401017', 'MUHAMMAD AGHNA PATURROHMAN', 'L', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', 'SMP Terpadu Al Hasan', 'default.jpg'),
(127, '2401018', 'MUHAMMAD HAFIDZ ARSYAD', 'L', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', 'SMP Terpadu Al Hasan', 'default.jpg'),
(128, '2401019', 'MUHAMMAD IQBAL ABDILLAH', 'L', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', 'SMP Terpadu Al Hasan', 'default.jpg'),
(129, '2401020', 'MUHAMMAD JABBAR AL KATIRI', 'L', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', 'SMP Terpadu Al Hasan', 'default.jpg'),
(130, '2401021', 'MUHAMMAD LUTFI AL MUBAROQ', 'L', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', 'SMP Terpadu Al Hasan', 'default.jpg'),
(131, '2401022', 'MUHAMMAD RAFA FAIQ ALFARIZY', 'L', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', 'SMP Terpadu Al Hasan', 'default.jpg'),
(132, '2401023', 'MUHAMMAD RAHES SURYA PAMUNGKAS', 'L', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', 'SMP Terpadu Al Hasan', 'default.jpg'),
(133, '2401024', 'MUHAMMAD REVAL HERMAWAN', 'L', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', 'SMP Terpadu Al Hasan', 'default.jpg'),
(134, '2401025', 'MUHAMMAD WAHDAN KAMIL', 'L', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', 'SMP Terpadu Al Hasan', 'default.jpg'),
(135, '2401026', 'NABIL SAYYIDIL ADHA', 'L', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', 'SMP Terpadu Al Hasan', 'default.jpg'),
(136, '2401027', 'NAUFAL GHIFAR', 'L', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', 'SMP Terpadu Al Hasan', 'default.jpg'),
(137, '2401028', 'RAHMAT MAULANA', 'L', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', 'SMP Terpadu Al Hasan', 'default.jpg'),
(138, '2401029', 'RAIHAN LUTHFI NADHIR ALFAEYZA', 'L', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', 'SMP Terpadu Al Hasan', 'default.jpg'),
(139, '2401030', 'RANGGA PRATAMA SUDIRMAN', 'L', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', 'SMP Terpadu Al Hasan', 'default.jpg'),
(140, '2401031', 'RIFA HAREL FADILLAH', 'L', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', 'SMP Terpadu Al Hasan', 'default.jpg'),
(141, '2401032', 'RIZKY SYA\'BAN JULIANA', 'L', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', 'SMP Terpadu Al Hasan', 'default.jpg'),
(142, '2401033', 'SEFTIAN ANUGRAH', 'L', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', 'SMP Terpadu Al Hasan', 'default.jpg'),
(143, '2401034', 'SYAHRUL FITRA RAMADHAN', 'L', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', 'SMP Terpadu Al Hasan', 'default.jpg'),
(144, '2401035', 'ZIDNI \'AFIF AFANDI', 'L', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', 'SMP Terpadu Al Hasan', 'default.jpg'),
(145, '2401036', 'ADISTRI PUTRI', 'P', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', 'SMP Terpadu Al Hasan', 'default.jpg'),
(146, '2401037', 'AGNI FATHIRA', 'P', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', 'SMP Terpadu Al Hasan', 'default.jpg'),
(147, '2401038', 'AMEERA SUKMA TRIANA', 'P', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', 'SMP Terpadu Al Hasan', 'default.jpg'),
(148, '2401039', 'ANGGUN CAHYA PUTRI', 'P', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', 'SMP Terpadu Al Hasan', 'default.jpg'),
(149, '2401040', 'ANITA NUR RAHAYU', 'P', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', 'SMP Terpadu Al Hasan', 'default.jpg'),
(150, '2401041', 'AQILA REGIFA AULYA', 'P', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', 'SMP Terpadu Al Hasan', 'default.jpg'),
(151, '2401042', 'ASIFAH LENAMA', 'P', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', 'SMP Terpadu Al Hasan', 'default.jpg'),
(152, '2401043', 'ASYIFA', 'P', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', 'SMP Terpadu Al Hasan', 'default.jpg'),
(153, '2401044', 'DWI REFI AULIA', 'P', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', 'SMP Terpadu Al Hasan', 'default.jpg'),
(154, '2401045', 'FATHIYA AZZAHRA', 'P', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', 'SMP Terpadu Al Hasan', 'default.jpg'),
(155, '2401046', 'FIRMA PUSPITA', 'P', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', 'SMP Terpadu Al Hasan', 'default.jpg'),
(156, '2401047', 'FITRI AULIA', 'P', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', 'SMP Terpadu Al Hasan', 'default.jpg'),
(157, '2401048', 'HASNA DHIA SILMI YUSUF', 'P', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', 'SMP Terpadu Al Hasan', 'default.jpg'),
(158, '2401049', 'IRNA LESTARI RUSTI BAHTIAR', 'P', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', 'SMP Terpadu Al Hasan', 'default.jpg'),
(159, '2401050', 'JELITA BUNGA YOHANA', 'P', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', 'SMP Terpadu Al Hasan', 'default.jpg'),
(160, '2401051', 'KAMILA IKSANI SAKAN', 'P', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', 'SMP Terpadu Al Hasan', 'default.jpg'),
(161, '2401052', 'MARWAH MAULIDA KHOIRUNNISA', 'P', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', 'SMP Terpadu Al Hasan', 'default.jpg'),
(162, '2401053', 'MEYSHA SHALSABILLA FAUZIAH', 'P', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', 'SMP Terpadu Al Hasan', 'default.jpg'),
(163, '2401054', 'MUTHIA GALUH KAPUTREN', 'P', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', 'SMP Terpadu Al Hasan', 'default.jpg'),
(164, '2401055', 'NADHIRA KHAIRUNNISA AMALIA', 'P', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', 'SMP Terpadu Al Hasan', 'default.jpg'),
(165, '2401056', 'NAJWA HIKMATUROHÐœÐÐ', 'P', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', 'SMP Terpadu Al Hasan', 'default.jpg'),
(166, '2401057', 'NAURA HASNA ANNIDA', 'P', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', 'SMP Terpadu Al Hasan', 'default.jpg'),
(167, '2401058', 'NI\'MA DAIYATUL FUADAH', 'P', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', 'SMP Terpadu Al Hasan', 'default.jpg'),
(168, '2401059', 'NILLAH', 'P', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', 'SMP Terpadu Al Hasan', 'default.jpg'),
(169, '2401060', 'NUR SHOBAH AZIZIAH', 'P', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', 'SMP Terpadu Al Hasan', 'default.jpg'),
(170, '2401061', 'NURUL WILDANI', 'P', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', 'SMP Terpadu Al Hasan', 'default.jpg'),
(171, '2401062', 'PUPU MASYFUAH', 'P', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', 'SMP Terpadu Al Hasan', 'default.jpg'),
(172, '2401063', 'PUTRI CAHYA RAMDANI', 'P', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', 'SMP Terpadu Al Hasan', 'default.jpg'),
(173, '2401064', 'SAFIRA YASMIN MALLIKA', 'P', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', 'SMP Terpadu Al Hasan', 'default.jpg'),
(174, '2401065', 'SALISA SALSABILA MARWAH', 'P', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', 'SMP Terpadu Al Hasan', 'default.jpg'),
(175, '2401066', 'SALSABILA ASHOFA', 'P', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', 'SMP Terpadu Al Hasan', 'default.jpg'),
(176, '2401067', 'SALSABILA PUTRI', 'P', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', 'SMP Terpadu Al Hasan', 'default.jpg'),
(177, '2401068', 'SALUL NURHAN SALA', 'P', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', 'SMP Terpadu Al Hasan', 'default.jpg'),
(178, '2401069', 'SARAH AZZAHRA ROMDON', 'P', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', 'SMP Terpadu Al Hasan', 'default.jpg'),
(179, '2401070', 'SASKIA KHANSA HERMAWAN', 'P', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', 'SMP Terpadu Al Hasan', 'default.jpg'),
(180, '2401071', 'SASKIA NUR ARIFIN', 'P', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', 'SMP Terpadu Al Hasan', 'default.jpg'),
(181, '2401072', 'SERUNY ARIJ ABBIYAH', 'P', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', 'SMP Terpadu Al Hasan', 'default.jpg'),
(182, '2401073', 'SHAQILA SHAQIF SUNGKAR', 'P', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', 'SMP Terpadu Al Hasan', 'default.jpg'),
(183, '2401074', 'SIDNY AULIA ZARKASIH', 'P', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', 'SMP Terpadu Al Hasan', 'default.jpg'),
(184, '2401075', 'SILVIA PUTRI ISNIAWATI', 'P', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', 'SMP Terpadu Al Hasan', 'default.jpg'),
(185, '2401076', 'TIAS NURHIDAYATI', 'P', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', 'SMP Terpadu Al Hasan', 'default.jpg'),
(186, '2401077', 'ÎšANZA AUFA MALIHAH', 'P', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', 'SMP Terpadu Al Hasan', 'default.jpg'),
(187, '2501001', 'ABDI BALDAN HIDAYATULOH', 'L', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', 'SMP Terpadu Al Hasan', 'default.jpg'),
(188, '2501002', 'ADITYA SAPUTRA', 'L', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', 'SMP Terpadu Al Hasan', 'default.jpg'),
(189, '2501003', 'ADZILDAN AZZURAHMAN', 'L', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', 'SMP Terpadu Al Hasan', 'default.jpg'),
(190, '2501004', 'AGAM ABDILAH PRATAMA', 'L', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', 'SMP Terpadu Al Hasan', 'default.jpg'),
(191, '2501005', 'AKMA MAULANA PUTRA', 'L', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', 'SMP Terpadu Al Hasan', 'default.jpg'),
(192, '2501006', 'ALQY MUBARAK', 'L', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', 'SMP Terpadu Al Hasan', 'default.jpg'),
(193, '2501007', 'ALVIAN TRI OKTAVIANA', 'L', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', 'SMP Terpadu Al Hasan', 'default.jpg'),
(194, '2501008', 'ARI NUGRAHA', 'L', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', 'SMP Terpadu Al Hasan', 'default.jpg'),
(195, '2501009', 'ARRAFIAN OKTAVIANA ARIF', 'L', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', 'SMP Terpadu Al Hasan', 'default.jpg'),
(196, '2501010', 'ARYA YUDHA', 'L', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', 'SMP Terpadu Al Hasan', 'default.jpg'),
(197, '2501011', 'AUFA ALRAIS', 'L', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', 'SMP Terpadu Al Hasan', 'default.jpg'),
(198, '2501012', 'BINTANG SUMYAR HENDRIANTO', 'L', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', 'SMP Terpadu Al Hasan', 'default.jpg'),
(199, '2501013', 'DAFA NURFATAN FAUZANI', 'L', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', 'SMP Terpadu Al Hasan', 'default.jpg'),
(200, '2501014', 'DIKA PRATAMA', 'L', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', 'SMP Terpadu Al Hasan', 'default.jpg'),
(201, '2501015', 'EKA AMIRUL HAKIM', 'L', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', 'SMP Terpadu Al Hasan', 'default.jpg'),
(202, '2501016', 'FAWAZ AHMAD FAUZI', 'L', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', 'SMP Terpadu Al Hasan', 'default.jpg'),
(203, '2501017', 'FICHELL JULIAN PURNAMA', 'L', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', 'SMP Terpadu Al Hasan', 'default.jpg'),
(204, '2501018', 'GALUH MUCHAMAD SOLICHIN', 'L', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', 'SMP Terpadu Al Hasan', 'default.jpg'),
(205, '2501019', 'HAFIYER EKA PUTRA SOLIHIN', 'L', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', 'SMP Terpadu Al Hasan', 'default.jpg'),
(206, '2501020', 'ICHSAN FATAHILLAH', 'L', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', 'SMP Terpadu Al Hasan', 'default.jpg'),
(207, '2501021', 'KAINDRA NURDAFFA EMYR N ZULKARNAEN', 'L', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', 'SMP Terpadu Al Hasan', 'default.jpg'),
(208, '2501022', 'KAYSAN HAZIQU EL AAZKIA', 'L', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', 'SMP Terpadu Al Hasan', 'default.jpg'),
(209, '2501023', 'KHOIRUL ALIF KURNIAWAN', 'L', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', 'SMP Terpadu Al Hasan', 'default.jpg'),
(210, '2501024', 'LELAKIKU CLEODORA WARDHANA', 'L', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', 'SMP Terpadu Al Hasan', 'default.jpg'),
(211, '2501025', 'MUHAMAD NADZIR ISLAMUL ANWAR', 'L', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', 'SMP Terpadu Al Hasan', 'default.jpg'),
(212, '2501026', 'MUHAMAD SURYA PRANATHA', 'L', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', 'SMP Terpadu Al Hasan', 'default.jpg'),
(213, '2501027', 'MUHAMAD VIKRI HAQ', 'L', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', 'SMP Terpadu Al Hasan', 'default.jpg'),
(214, '2501028', 'MUHAMMAD ARKAAN MAULANA', 'L', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', 'SMP Terpadu Al Hasan', 'default.jpg'),
(215, '2501029', 'MUHAMMAD HAFIZ BURHANUDIN', 'L', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', 'SMP Terpadu Al Hasan', 'default.jpg'),
(216, '2501030', 'MUHAMMAD IRFAN FADHILAH', 'L', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', 'SMP Terpadu Al Hasan', 'default.jpg'),
(217, '2501031', 'MUHAMMAD NADZHIR ISLAMUL A', 'L', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', 'SMP Terpadu Al Hasan', 'default.jpg'),
(218, '2501032', 'MUHAMMAD RAHMAN FADILAH', 'L', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', 'SMP Terpadu Al Hasan', 'default.jpg'),
(219, '2501033', 'MUHAMMAD RAIHAN AL KHALIFI DZIKRI', 'L', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', 'SMP Terpadu Al Hasan', 'default.jpg'),
(220, '2501034', 'MUHAMMAD REHAN YULIYADI', 'L', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', 'SMP Terpadu Al Hasan', 'default.jpg'),
(222, '2501035', 'MUHIBAN ANNAFIS AWALUDIN', 'L', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', 'SMP Terpadu Al Hasan', 'default.jpg'),
(223, '2501036', 'RAKA ADITYA NUGRAHA', 'L', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', 'SMP Terpadu Al Hasan', 'default.jpg'),
(224, '2501037', 'RIFFAT ZIDANIS', 'L', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', 'SMP Terpadu Al Hasan', 'default.jpg'),
(225, '2501038', 'WIRDA MAULANA SOPARI', 'L', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', 'SMP Terpadu Al Hasan', 'default.jpg'),
(226, '2501039', 'YUSUF', 'L', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', 'SMP Terpadu Al Hasan', 'default.jpg'),
(227, '2501040', 'ALFI WARDA FARIDAH', 'P', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', 'SMP Terpadu Al Hasan', 'default.jpg'),
(228, '2501041', 'ALLIFA MAULIDA HAYATUNUFUS', 'P', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', 'SMP Terpadu Al Hasan', 'default.jpg'),
(229, '2501042', 'AQILA HANA ZIA Z.N', 'P', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', 'SMP Terpadu Al Hasan', 'default.jpg'),
(230, '2501043', 'ASYA NIKITA REVALDIENA', 'P', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', 'SMP Terpadu Al Hasan', 'default.jpg'),
(231, '2501044', 'AWA ABIDAH', 'P', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', 'SMP Terpadu Al Hasan', 'default.jpg'),
(232, '2501045', 'DEA KAZALEA SYAIMA A', 'P', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', 'SMP Terpadu Al Hasan', 'default.jpg'),
(233, '2501046', 'DIASVIKA AZMI NIDYA', 'P', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', 'SMP Terpadu Al Hasan', 'default.jpg'),
(234, '2501047', 'FUZI NUR FAUZIAH', 'P', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', 'SMP Terpadu Al Hasan', 'default.jpg'),
(235, '2501048', 'GAISA NADIRA ZAROTUNNISA', 'P', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', 'SMP Terpadu Al Hasan', 'default.jpg'),
(236, '2501049', 'LIA HABIBAH HABIBATURROHMAH', 'P', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', 'SMP Terpadu Al Hasan', 'default.jpg'),
(237, '2501050', 'MAYANG ERI ANJANY', 'P', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', 'SMP Terpadu Al Hasan', 'default.jpg'),
(238, '2501051', 'MEISYA PURWADIJAYA', 'P', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', 'SMP Terpadu Al Hasan', 'default.jpg'),
(239, '2501052', 'MOUGHIA CITA LAKSANYA', 'P', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', 'SMP Terpadu Al Hasan', 'default.jpg'),
(240, '2501053', 'NAJWA FAJRIYAH QUROTA\'AYUN TABUN', 'P', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', 'SMP Terpadu Al Hasan', 'default.jpg'),
(241, '2501054', 'NELY NUR FAUZIYAH', 'P', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', 'SMP Terpadu Al Hasan', 'default.jpg'),
(242, '2501055', 'NIKEN SAVANA ZIVILIA', 'P', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', 'SMP Terpadu Al Hasan', 'default.jpg'),
(243, '2501056', 'REGINA DWI OKTAPIA', 'P', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', 'SMP Terpadu Al Hasan', 'default.jpg'),
(244, '2501057', 'SALMA DINA AMALIYAH', 'P', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', 'SMP Terpadu Al Hasan', 'default.jpg'),
(245, '2501058', 'SANDRI APRILIA LATIF', 'P', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', 'SMP Terpadu Al Hasan', 'default.jpg'),
(246, '2501059', 'SHAFA SALSABILA RAIQAH', 'P', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', 'SMP Terpadu Al Hasan', 'default.jpg'),
(247, '2501060', 'SHAYNA AZKAYLA BILQIS', 'P', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', 'SMP Terpadu Al Hasan', 'default.jpg'),
(248, '2501061', 'SHOFA AZKIA NUR', 'P', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', 'SMP Terpadu Al Hasan', 'default.jpg'),
(249, '2501062', 'SIFA AINUNNISA RAMADHANI', 'P', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', 'SMP Terpadu Al Hasan', 'default.jpg'),
(250, '2501063', 'YUSRI ALTHOFUNNISA', 'P', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', 'SMP Terpadu Al Hasan', 'default.jpg'),
(251, '2501064', 'ZAHRA HIJRIY MAULIDA', 'P', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', 'SMP Terpadu Al Hasan', 'default.jpg'),
(252, '2501065', 'ZALFA PUTRI NAJILLA', 'P', '', '2026-07-17', '', '', '', '', '', '', '', '', '', '', 'SMP Terpadu Al Hasan', 'default.jpg'),
(253, '2601001', 'AKASYAH NAUFAL DARY ABIYYU', 'L', 'GARUT', '1970-01-01', 'NENGKLOK, PERUM GRAHA ARTHA BLOK A NO 69 RT 3 RW 9', 'PAJATEN', 'SIDAMULIH', 'PANGANDARAN', 'JAWA BARAT', 'YADI RAMDANI', '083827492003', 'DEVIA SUSANTI', '083827492003', 'SDN 1 PAJATEN', 'SMP Terpadu Al Hasan', 'default.jpg'),
(254, '2601002', 'ALDY AHMAD SANTANA', 'L', 'CIAMIS', '1970-01-01', 'SUKANAGARA RT 8 RW 2', 'SUKANAGARA', 'LAKBOK', 'CIAMIS', 'JAWA BARAT', 'HADI SUYITNO', '081935599413', 'WINARNI', '081935599413', 'SDN 3 SUKANAGARA', 'SMP Terpadu Al Hasan', 'default.jpg'),
(255, '2601003', 'ALFIAN BADRITAMAM', 'L', 'CIAMIS', '2013-09-10', 'CIKUYA RT 3 RW 1', 'LANGKAPSARI', 'BANJARANYAR', 'CIAMIS', 'JAWA BARAT', 'HOPID TOHIDI', '082130586515', 'DEDE MARSILAH', '082130586515', 'MI CIKASO', 'SMP Terpadu Al Hasan', 'default.jpg'),
(256, '2601004', 'ALVARO ZHIAN KASYAFANI', 'L', 'BANDUNG', '1970-01-01', 'Gg.H.KURDI II/IV NO.361/201A RT 4 RW 1', 'KARASAK', 'ASTANAANYAR', 'BANDUNG', 'JAWA BARAT', 'INFIRODIAN MUHAMMAD', '081221701700', 'YEYEN SURYANI', '081221701700', 'MI ALMUMININ CIAMIS', 'SMP Terpadu Al Hasan', 'default.jpg'),
(257, '2601005', 'ARGANTA YUDA', 'L', 'CIMAHI', '1970-01-01', 'DUSUN LANGENSARI RT 9 RW 4', 'PATAKAHARJA', 'RANCAH', 'Ciamis', 'JAWA BARAT', 'EDI SUTARDI', '082130993369', 'IRA MUTIA SUTARDI', '082130993369', 'SDN 2 PATAKAHARJA', 'SMP Terpadu Al Hasan', 'default.jpg'),
(258, '2601006', 'AUFA NURFAUZAN', 'L', 'CIAMIS', '1970-01-01', 'PUNCAK ASIH RT 1 RW 16', 'CISADAP', 'CIAMIS', 'CIAMIS', 'JAWA BARAT', 'JUMADI', '0895346152413', '', '0895346152413', 'SDN 1 CISADAP', 'SMP Terpadu Al Hasan', 'default.jpg'),
(259, '2601007', 'AZKA AKILA NAJMUMILAH', 'L', 'CILACAP', '1970-01-01', 'KALEANYAR RT 1 RW 11', 'RAWAAPU', 'PATIMUAN', 'CILACAP', 'JAWA BARAT', 'AGUS PERMANA', '081273623668', 'NIA NUR INAYAH', '081273623668', 'SDN 03 RAWAAPU', 'SMP Terpadu Al Hasan', 'default.jpg'),
(260, '2601008', 'DANISH ABHAR SYAHPUTR', 'L', 'CIAMIS', '1970-01-01', 'JAMBAN RT 15 RW 3', 'SIDAHARJA', 'LAKBOK', 'CIAMIS', 'JAWA BARAT', '', '081223030262', '', '081223030262', 'SDN 2 SIDAHARJA', 'SMP Terpadu Al Hasan', 'default.jpg'),
(261, '2601009', 'DILFA FAQIH ARASY', 'L', 'CIAMIS', '1970-01-01', 'SUKANAGARA RT 8 RW 2', 'SUKANAGARA', 'LAKBOK', 'CIAMIS', 'JAWA BARAT', 'YUSUF TAUHIDI', '082128266866', 'PRIASTI ROSLIANA', '082128266866', 'SDN 3 SUKANAGARA', 'SMP Terpadu Al Hasan', 'default.jpg'),
(262, '2601010', 'DINAR MOHAMMED ASSEGAF', 'L', 'CIAMIS', '2014-08-01', 'MARGALUYU RT 4 RW 8', 'MARGALUYU', 'PATARUMAN', 'KOTA BANJAR', 'JAWA BARAT', 'PUDJI SUHARTOYO', '087749991699', 'ELIN NURJANNAH', '087749991699', 'MI AL-AZHAR BANJAR', 'SMP Terpadu Al Hasan', 'default.jpg'),
(263, '2601011', 'EGIE AQILA ADITYA', 'L', 'CIAMIS', '1970-01-01', 'MADASARI RT 36 RW 12', 'MASAWAH', 'CIMERAK', 'PANGANDARAN', 'JAWA BARAT', 'DEDI SUPRIADI', '083875085882', 'AI NURWAHIDAH', '083875085882', 'SDN 3 MASAWAH', 'SMP Terpadu Al Hasan', 'default.jpg'),
(264, '2601012', 'FARHAN ARSYAD NURDIANSYAH', 'L', 'CIAMIS', '1970-01-01', 'PERUM CITRA KEBUN MAS BLOK R7/28 RT 54 RW 15', 'BANGLE', 'MAJALAYA', 'KARAWANG', 'JAWA BARAT', 'AAN NURDIANSYAH', '085846997335', 'DEDE NURLIAH', '085846997335', 'SDN 2 BANGUNSARI', 'SMP Terpadu Al Hasan', 'default.jpg'),
(265, '2601013', 'HAIKAL MUMTAZ NASRULLAH', 'L', 'TASIKMALAYA', '1970-01-01', 'RIMPAK GEDE RT 4 RW 4', 'SUKAMULYA', 'BAREGBEG', 'CIAMIS', 'JAWA BARAT', 'ENAN NASRULOH', '085223451884', 'IMAS MASRUROH', '085223451884', 'MIN  9 CIAMIS', 'SMP Terpadu Al Hasan', 'default.jpg'),
(266, '2601014', 'HASBI MULTAZAM AL-BUKHORI', 'L', 'CIAMIS', '1970-01-01', 'JL.Ir H DJUANDA RT 3 RW 10', 'LINGGASARI', 'CIAMIS', 'CIAMIS', 'JAWA BARAT', 'BUKHORI MUSLIM', '081320475226', 'IKAH MUTAMASIKAH', '081320475226', 'MIN 9 CIAMIS', 'SMP Terpadu Al Hasan', 'default.jpg'),
(267, '2601015', 'HUDDAN NABAWI', 'L', 'TEGAL', '1970-01-01', 'CIBEREUM RT 27 RW 7', 'CIBADAK', 'BANJARSARI', 'CIAMIS', 'JAWA BARAT', 'ENGKUS KUSNADI', '083854559855', 'ANITA MARDIANA RISQI', '083854559855', 'SDN 3 CIBADAK', 'SMP Terpadu Al Hasan', 'default.jpg'),
(268, '2601016', 'M.IHSAN AL-HUSAENI', 'L', 'CIAMIS', '1970-01-01', 'SILUMAN BARU RT 34 RW 16', 'PURWAHARJA', 'PURWAHARJA', 'BANJAR', 'JAWA BARAT', 'ABUB MAHBUB', '085320994909', 'LILIH SOLIHAH', '085320994909', 'MIS PURWAHARJA 1', 'SMP Terpadu Al Hasan', 'default.jpg'),
(269, '2601017', 'MARVIN ERI SYAHPUTRA', 'L', 'CIAMIS', '1970-01-01', 'JATIBARANG RT 19 RW 5', 'SINDANGANGIN', 'LAKBOK', 'CIAMIS', 'JAWA BARAT', 'RENO', '082125296925', 'ELISA AMELIA', '082125296925', 'SDN 2 SINDANGANGIN', 'SMP Terpadu Al Hasan', 'default.jpg'),
(270, '2601018', 'MAULANA IFHDIL HANAFI', 'L', 'CIAMIS', '2013-10-05', 'BUGEL RT 3 RW 2', 'KERTAYASA', 'CIJULANG', 'PANGANDARAN', 'JAWA BARAT', 'ATANG GUNAWAN', '082219363054', 'ANING YANINGSIH', '082219363054', '', 'SMP Terpadu Al Hasan', 'default.jpg'),
(271, '2601019', 'MOHAMAD ROBI ASSATIRI', 'L', 'BREBES', '1970-01-01', 'MALANDANG RT 4 RW 3', 'PABUARAN', 'SALEM', 'BREBES', 'JAWA BARAT', 'CARKA', '085293729505', 'DARSINI', '085293729505', 'SDN 2 PABUARAN', 'SMP Terpadu Al Hasan', 'default.jpg'),
(272, '2601020', 'MOHAMMAD ABIDZAR ALGHIFARI', 'L', 'CIAMIS', '2013-06-06', 'CIPETEUY RT 8 RW 3', 'JAYARAKSA', 'CIMARAGAS', 'CIAMIS', 'JAWA BARAT', 'HANHAN RIDWANULOH', '085223304292', 'NURHOPIPAH', '085223304292', 'SDN 4 BEBER', 'SMP Terpadu Al Hasan', 'default.jpg'),
(273, '2601021', 'MUGNI WALI MUHAMMAD', 'L', 'BREBES', '2014-08-02', 'NYEGOG RT 1 RW 2', 'BENTAR', 'SALEM', 'BREBES', 'JAWA BARAT', 'ABDUL KARIM', '085848654106', 'TURKINI', '085848654106', 'SDN 3 BENTAR', 'SMP Terpadu Al Hasan', 'default.jpg'),
(274, '2601022', 'MUHAMAD ZIDAN ALFARIZKY', 'L', 'CIAMIS', '2013-01-04', 'CIPOROAN RT 16 RW 4', 'SIDAHARJA', 'PAMARICAN', 'CIAMIS', 'JAWA BARAT', 'HENDRA', '082320668584', 'WINA SHOLIHAH', '082320668584', 'MI SIDAHARJA', 'SMP Terpadu Al Hasan', 'default.jpg'),
(275, '2601023', 'MUHAMMAD ARSAKHA VIRENDRA', 'L', 'BREBES', '2013-08-12', 'SALEM RT 2 RW 6', 'SALEM', 'SALEM', 'BREBES', 'JAWA TENGAH', 'SUPRIYANTO', '085228131425', 'FINA SALSABIL', '085228131425', 'SDN 3 SALEM', 'SMP Terpadu Al Hasan', 'default.jpg'),
(276, '2601024', 'MUHAMMAD ABRISAM ADHISTYA SHIDIEQ', 'L', 'MADINAH', '1970-01-01', 'DUSUN KARANGKENDAL RT 1 RW 7', 'PUSAKANAGARA', 'BAREGBEG', 'Ciamis', 'JAWA BARAT', 'MUHAMMAD HASBY ASIDIQY', '', 'HINHIN HINDANI', '', 'SD MODEL AULADY', 'SMP Terpadu Al Hasan', 'default.jpg'),
(277, '2601025', 'MUHAMMAD FADHLAN HIDAYAT', 'L', 'CIAMIS', '2013-04-11', 'JL.SADANG NO 41 RT 4 RW 9', 'MARGAHAYU TENGAH', 'MARGAHAYU', 'BANDUNG', 'JAWA BARAT', 'DAYAT HIDAYAT', '082115544846', 'NELI HERLINA', '082115544846', 'SDN CIBOLERANG', 'SMP Terpadu Al Hasan', 'default.jpg'),
(278, '2601026', 'MUHAMMAD HABIBY AL-IDRUS', 'L', 'CIAMIS', '2013-08-08', 'MULYABAKTI RT 4 RW 4', 'JAYARAKSA', 'CIMARAGAS', 'CIAMIS', 'JAWA BARAT', 'ANGGUN NUGRAHA', '082138939990', 'SOPIYANTI', '082138939990', 'SDN 4 BEBER', 'SMP Terpadu Al Hasan', 'default.jpg'),
(279, '2601027', 'MUHAMMAD RIFQI SYA\'BANI', 'L', 'CIAMIS', '1970-01-01', 'CIMANGGU RT 4 RW 9', 'JALATRANG', 'CIPAKU', 'CIAMIS', 'JAWA BARAT', 'IDI KUSNADI', '083827004306', 'ENOK YANI', '083827004306', 'SDN 1 JALATRANG', 'SMP Terpadu Al Hasan', 'default.jpg'),
(280, '2601028', 'MUHAMMAD SATYA KUSUMA', 'L', 'MATARAM', '1970-01-01', 'JL. CEMPAKA WARNA RT 5 RW 4', 'CEMPAKA PUTIH TIMUR', 'CEMPAKA PUTIH', 'JAKARTA PUSAT', 'JAKARTA', 'RADIHTYA HARDIAN KUSUMA', '081380428532', 'IKA SARTIKA SARI', '081380428532', '', 'SMP Terpadu Al Hasan', 'default.jpg'),
(281, '2601029', 'RIZAL FADRIL MUNAWAR', 'L', 'BANJAR', '2012-06-12', 'DUSUN BUNIRASA RT 2 RW 1', 'CIDOLOG', 'CIDOLOG', 'Ciamis', 'JAWA BARAT', 'JAKA AGUS SALEH (ALM)', '85321578099', 'SRI MULYANI', '85321578099', 'MIS JANGGALA 2', 'SMP Terpadu Al Hasan', 'default.jpg'),
(282, '2601030', 'SURYA NUGRAHA', 'L', 'CIAMIS', '2014-02-01', 'INDRAJAYA RT 3 RW 3', 'INDRAJAYA', 'SALEM', 'BREBES', 'JAWA TENGAH', 'TRISYONO', '085859525426', 'NURHASANAH', '085859525426', 'SDN INDRAJAYA 2', 'SMP Terpadu Al Hasan', 'default.jpg'),
(283, '2601031', 'SAEFUL NAZZA', 'L', 'KUNINGAN', '1970-01-01', 'CIPEDANG RT 4 RW 15', 'SUKAMULYA', 'BAREGBEG', 'CIAMIS', 'JAWA BARAT', 'CECE SUDRAJAT', '082126830800', 'TITIN KURNETIN', '082126830800', 'SDN 4 SUKAMULYA', 'SMP Terpadu Al Hasan', 'default.jpg'),
(284, '2601032', 'ALIFIYA PURWANTO', 'P', 'CIAMIS', '2014-01-08', 'SUKAMULYA RT 15 RW 7', 'KERTABUMI', 'CIJEUNGJING', 'CIAMIS', 'JAWA BARAT', 'APUNG PURWANTO', '0821196576229', 'TOTIH HARYATI', '0821196576229', 'SDN 2 CIMARI', 'SMP Terpadu Al Hasan', 'default.jpg'),
(285, '2601033', 'ALIYA FITRIANI', 'P', 'CIAMIS', '2013-09-08', 'MEDANGLAYANG RT 1 RW 1', 'MEDANGLAYANG', 'PANUMBANGAN', 'CIAMIS', 'JAWA BARAT', 'DERI HERDIAWAN', '082130586515', 'NONOK BADRIYAH', '082130586515', 'SDN 4 MEDANGLAYANG', 'SMP Terpadu Al Hasan', 'default.jpg'),
(286, '2601034', 'AZKHAIRA DARLA CALLISTA', 'P', 'CIAMIS', '1970-01-01', 'ANGSANA RT 25 RW 6', 'NEGLASARI', 'PAMARICAN', 'CIAMIS', 'JAWA BARAT', 'AHMAD DAENURI', '082127512693', 'RISKA DIANA', '082127512693', 'SDN 1 PAMARICAN', 'SMP Terpadu Al Hasan', 'default.jpg'),
(287, '2601035', 'AZKY AULIYA HELMY', 'P', 'CIAMIS', '2014-04-06', 'MEKARSARI RT 20 RW 5', 'CIBADAK', 'BANJARSARI', 'CIAMIS', 'JAWA BARAT', 'IRFAN HILMI', '085222672595', 'AISAH', '085222672595', 'SDN 3 CIBADAK', 'SMP Terpadu Al Hasan', 'default.jpg'),
(288, '2601036', 'AZQIYA SIFA LUTFIYAH', 'P', 'CIAMIS', '2013-01-10', 'JL. KESADARAN 3 NO 30 RT 3 RW 1', 'CIPINANG MUARA', 'JATI NEGARA', '', 'JAWA BARAT', 'USIN USTRIANI', '', 'SRI IRMAWATI', '', 'MI AL MUNAWAR', 'SMP Terpadu Al Hasan', 'default.jpg'),
(289, '2601037', 'BINAR CAHAYA ZIFANA', 'P', 'CIAMIS', '2014-03-04', 'INDRAJAYA RT 3 RW 2', 'INDRAJAYA', 'SALEM', 'BREBES', 'JAWA BARAT', 'HAMDAN', '081326639409', 'ONAH', '081326639409', 'SDN 4 INDRAJAYA', 'SMP Terpadu Al Hasan', 'default.jpg'),
(290, '2601038', 'DZAKIYYA FITRIA WARDATULULA', 'P', 'CIAMIS', '2013-08-08', 'KARANGPUCUNG RT 12 RW 15', 'CIJEUNGJING', 'CIJEUNGJING', 'CIAMIS', 'JAWA BARAT', 'DANI FITRIA', '081224827421', 'UMI KULSUM', '081224827421', 'MIS PUI KERTAHARJA', 'SMP Terpadu Al Hasan', 'default.jpg'),
(291, '2601039', 'FAJRAINA NADA NADIFA', 'P', 'BREBES', '1970-01-01', 'TEMBONGRAJA RT 5 RW 2', 'TEMBONGRAJA', 'SALEM', 'BREBES', 'JAWA BARAT', 'ACENG WALUYO', '08562610560', 'DWI KARTIKA WIDIASTUTI', '08562610560', 'SDN TEMBONGRAJA 01', 'SMP Terpadu Al Hasan', 'default.jpg'),
(292, '2601040', 'FARISHA FITRIA', 'P', 'CIAMIS', '1970-01-01', 'ARSALA BANJARANGSANA RT 1 RW 1', 'BANJARANGSANA', 'PANUMBANGAN', 'CIAMIS', 'JAWA BARAT', 'DEDE PUDOLA SAEFUL ROHMAN', '08999805299', 'IRMA RAHMAWATI', '08999805299', 'SDN 4 BANJARANGSANA', 'SMP Terpadu Al Hasan', 'default.jpg'),
(293, '2601041', 'FATHI MUHIMMATUL HASANAH', 'P', 'PANGANDARAN', '2013-04-08', 'KERSARATU RT 34 RW 8', 'SINDANGJAYA', 'MANGUNJAYA', 'PANGANDARAN', 'JAWA BARAT', 'KHOLIQ AL AZALI', '087878546889', 'ANI SURYANI', '087878546889', 'MIS CIRAUPAN', 'SMP Terpadu Al Hasan', 'default.jpg'),
(294, '2601042', 'FREYA MAYDINA NURFAIZA', 'P', 'CIAMIS', '1970-01-01', 'KARANGANYAR RT 25 RW 4', 'SUKAMULYA', 'PURWADADI', 'CIAMIS', 'JAWA BARAT', 'ANDI GUSYANDI', '081221784499', 'NINA NURAMALIYAH', '081221784499', 'SDN 2 SUKAMULYA', 'SMP Terpadu Al Hasan', 'default.jpg'),
(295, '2601043', 'GINA SAMROTUL FUADAH', 'P', 'CIAMIS', '1970-01-01', 'LINGKUNGAN. CIBITUNG HILIR RT  RW', 'KERTASARI', 'CIAMIS', 'CIAMIS', 'JAWA BARAT', 'UMAR NAWAWI', '087874532544', 'YATI NURHAYATI', '087874532544', 'SDN 3 KERTASARI', 'SMP Terpadu Al Hasan', 'default.jpg'),
(296, '2601044', 'HAFIZA ALMAIRA HELMI', 'P', 'CIAMIS', '1970-01-01', 'KEDUNG PANJANG RT 1 RW 2', 'MALEBER', 'MALEBER', 'CIAMIS', 'JAWA BARAT', 'MOCHAMAD HELMI', '081573453378', 'SITI ROCHIMAH', '081573453378', 'MI ANDALAN CIAMIS', 'SMP Terpadu Al Hasan', 'default.jpg'),
(297, '2601045', 'KHANA DAFINA NURALIFA', 'P', 'CIAMIS', '1970-01-01', 'KARANGANYAR RT 3 RW 20', 'RANCAH', 'RANCAH', 'CIAMIS', 'JAWA BARAT', 'DADIH', '081220346507', 'AI DEDAH SITI NURBAEDAH', '081220346507', 'MIN 7 CIAMIS', 'SMP Terpadu Al Hasan', 'default.jpg'),
(298, '2601046', 'KHANZA ZAHIRA HERMANSYAH', 'P', 'BANJAR', '1970-01-01', 'KARANGPUCUNG RT 34 RW 11', 'BALOKANG', 'BANJAR', 'BANJAR', 'JAWA BARAT', 'YUS HERMANSYAH', '081323802646', 'ARMAWANTI', '081323802646', 'MIN 3 BANJAR', 'SMP Terpadu Al Hasan', 'default.jpg'),
(299, '2601047', 'LAILA SALSABILA', 'P', 'CIAMIS', '1970-01-01', 'MARGAMULYA  RT 2 RW 3', 'KIARAPAYUNG', 'RANCAH', 'CIAMIS', 'JAWA BARAT', 'DARUSMAN FIRDAUS', '081298847032', 'AI IPAH LATIPAH', '081298847032', 'SDN 3 KIARAPAYUNG', 'SMP Terpadu Al Hasan', 'default.jpg'),
(300, '2601048', 'MUMTAZAH YAZMEENA DEWI', 'P', 'CIAMIS', '1970-01-01', 'KARANGANYAR RT 5 RW 21', 'RANCAH', 'RANCAH', 'CIAMIS', 'JAWA BARAT', 'DAYAT HIDAYAT', '082118638621', 'DEWI LISMAWATI', '082118638621', 'MIN 7 CIAMIS', 'SMP Terpadu Al Hasan', 'default.jpg'),
(301, '2601049', 'NADIVA SAKINA BAHARI', 'P', 'CIAMIS', '2013-01-05', 'PASSUNGSARI RT 30 RW 3', 'SIDARAHAYU', 'PURWADADI', 'CIAMIS', 'JAWA BARAT', '', '0886966945315', 'MIDIYANI', '0886966945315', 'MI CIKAWUNG LAKBOK', 'SMP Terpadu Al Hasan', 'default.jpg'),
(302, '2601050', 'NAILA ADHWA KHAIRANI', 'P', 'CIAMIS', '1970-01-01', 'Jl.RE.MARTADINATA NO.7/09 RT 1 RW 1', 'CIAMIS', 'CIAMIS', 'CIAMIS', 'JAWA BARAT', 'ARI SYARIFUDIN', '081323384222', 'AI IRAWATI', '081323384222', 'MI ANDALAN CIAMIS', 'SMP Terpadu Al Hasan', 'default.jpg'),
(303, '2601051', 'NAILA MUTIARA', 'P', '', '2026-07-09', '', '', '', '', 'JAWA BARAT', '', '', '', '', '', 'SMP Terpadu Al Hasan', 'default.jpg'),
(304, '2601052', 'NOVA NURFADILAH', 'P', 'TASIKMALAYA', '1970-01-01', 'JL.CILOLOHAN  RT 5 RW 8', 'KAHURIPAN', 'TAWANG', 'TASIKMALAYA', 'JAWA BARAT', 'KOSASIH', '082126628058', 'NETI IRAWATI', '082126628058', 'SDN CILOLOHAN', 'SMP Terpadu Al Hasan', 'default.jpg'),
(305, '2601053', 'RAHAYU SAHDA SITI NURFAUZIAH', 'P', 'CIAMIS', '1970-01-01', 'DESA RT 2 RW 2', 'SUKAMAJU', 'BAREGBEG', 'CIAMIS', 'JAWA BARAT', 'EMAN SULAEMAN', '085324777107', 'RENI NURAENI', '085324777107', 'MI ANDALAN CIAMIS', 'SMP Terpadu Al Hasan', 'default.jpg'),
(306, '2601054', 'RAISA NUR RIZQI', 'P', 'CIAMIS', '1970-01-01', 'CIDAHU RT 7 RW 8', 'CIOMAS', 'PANJALU', 'CIAMIS', 'JAWA BARAT', 'RIZKI FAUZI', '082317344520', 'PIPIH USWATUN HASANAH', '082317344520', 'SDN KANCAH', 'SMP Terpadu Al Hasan', 'default.jpg'),
(307, '2601055', 'REVA LISTIANI', 'P', 'CIAMIS', '1970-01-01', 'JAMBAN RT 18 RW 3', 'SIDAHARJA', 'LAKBOK', 'CIAMIS', 'JAWA BARAT', 'DASIKIN', '082219757805', 'SUNIAWATI', '082219757805', 'SDN 2 SIDAHARJA', 'SMP Terpadu Al Hasan', 'default.jpg'),
(308, '2601056', 'SALSABIL AL MUBAROK DAYARI', 'P', 'CIAMIS', '1970-01-01', 'CILILITAN RT 7 RW 4', 'BAHARA', 'PANJALU', 'CIAMIS', 'JAWA BARAT', 'YAYAN DAYARI', '081221794720', 'DIAN RAHMAWATI', '081221794720', 'SDN 1 BAHARA', 'SMP Terpadu Al Hasan', 'default.jpg'),
(309, '2601057', 'SALSABILA NUR\'AENI', 'P', 'CIAMIS', '1970-01-01', 'CISINGKAH RT 6 RW 2', 'SUKAMANAH', 'SINDANGKASIH', '', 'JAWA BARAT', 'WAHYU RUSTANDI', '', 'NUR SITI NURJANAH', '', 'SDN 3 SUKAMANAH', 'SMP Terpadu Al Hasan', 'default.jpg'),
(310, '2601058', 'SALWA NUR ASIAH', 'P', 'CIAMIS', '2013-04-09', 'CIOMAS RT 1 RW 1', '', 'PANAJALU', 'CIAMIS', 'JAWA BARAT', 'IRPAN JULKARNAEN', '082218607630', 'SITI SUMIATI', '082218607630', 'SDN 1 CIAMIS', 'SMP Terpadu Al Hasan', 'default.jpg'),
(311, '2602001', 'ADE KHOIRUL FIRMANSYAH', 'L', 'Lampung Barat', '2010-12-13', 'Dsn.Karangtengah, RT/RW 018/003', 'Sukamulya', 'Purwadadi', 'Ciamis ', 'Jawa Barat', 'Johansyah', '085785460257', 'Alrita Hindarsih', '085785460257', 'SMP Terpadu Al Hasan ', 'SMAN 1 Ciamis', 'default.jpg'),
(312, '2602002', 'ADNAN FAJRI', 'L', 'Ciamis', '2010-08-10', 'Sukasari RT 018 RW 009', 'Janggala', 'Cidolog', 'Ciamis', 'Jawa Barat', 'Donald Okta Sut Herlan', '082119094197', 'Ade Sumarni', '082119094197', 'SMP Terpadu Al Hasan', 'SMK Terpadu Al Hasan', 'default.jpg'),
(313, '2602003', 'AFGHAN FIRMANSYAH', 'L', 'Ciamis', '2010-02-12', 'Kp. Sukamaju, RT/RW 24/11', 'Talagasari', 'Kawali', 'Ciamis', 'Jawa Barat', 'Taupik Rahman', '082116136062', 'Ai Masruroh', '082116136062', 'SMPN 1 Kawali', 'MAN 2 Ciamis', 'default.jpg'),
(314, '2602004', 'ALVI KHOIRUL AKMAL', 'L', 'Ciamis', '2010-12-08', 'Kp. Ngenol, RT/RW 010/005', 'Puspamukti', 'Cigalontang', 'Tasikmalaya', 'Jawa Barat', '', '082315446524', 'Teti Nurhayati', '082315446524', 'SMP Terpadu Al Hasan ', 'SMAN 1 Ciamis', 'default.jpg'),
(315, '2602005', 'ANDIKA RAMDHAN', 'L', 'Ciamis', '2010-08-19', 'Dsn. Bojongnangka, RT/RW 015/006', 'Karangpaningal', 'Panawangan', 'Ciamis', 'Jawa Barat', 'Nana Sudiana', '081313585580', 'Herna Rahmawati', '081313585580', 'SMP Terpadu Al Hasan ', 'MAN 2 Ciamis', 'default.jpg');
INSERT INTO `santri` (`id`, `nis`, `nama_santri`, `jenis_kelamin`, `tempat_lahir`, `tgl_lahir`, `alamat`, `desa`, `kecamatan`, `kab_kota`, `provinsi`, `nama_ayah`, `no_hp_ayah`, `nama_ibu`, `no_hp_ibu`, `asal_sekolah`, `sekolah_saat_ini`, `foto`) VALUES
(316, '2602006', 'ARIP RAHMAN HAKIM', 'L', 'Ciamis', '2010-09-16', 'Dsn. Sukawening', 'Tanjungsari', 'Sadananya', 'Ciamis', 'Jawa Barat', 'Idi', '081214110526', 'Idah', '081214110526', 'SMP Terpadu Al Hasan ', 'SMKN 2 Ciamis', 'default.jpg'),
(317, '2602008', 'DANDI NAZRIL AKBAR', 'L', 'Ciamis', '2011-01-01', 'W.R Wetan', 'Imbanagara', 'Ciamis', 'Ciamis', 'Jawa Barat', 'Feriyanto', '085321131776', 'Eni Kartini', '085321131776', 'SMP Terpadu Al Hasan ', 'MAN 2 Ciamis', 'default.jpg'),
(318, '2602009', 'FAIZ AZKA MUBAROK', 'L', 'Ciamis', '2010-07-03', 'Dsn. Cidawung, RT/RW 01/09', 'Margacinta', 'Cijulang', 'Pangandaran ', 'Jawa Barat', 'Suryatno', '081313730231', 'Yayah Haryati', '081313730231', 'SMP Terpadu Al Hasan ', 'MAN 2 Ciamis', 'default.jpg'),
(319, '2602010', 'FAZRY SAEPULOH', 'L', 'Kuningan', '2010-12-20', 'Lingkungan Cilame', 'Cigadung', 'Cigugur', 'Kuningan', 'Jawa Barat', 'Aris Wahidin', '0831674041181', 'Aminah', '0831674041181', 'SMP Terpadu Al Hasan', 'SMK Terpadu Al Hasan', 'default.jpg'),
(320, '2602012', 'ILHAM MUHAMAD FAUJAN ', 'L', 'Ciamis', '2010-04-05', 'Dsn. Desa Rt 04 Rw 03', 'Jalatrang ', 'Cipaku', 'Ciamis', 'Jawa Barat', 'Kosim ', '085223147692', 'Munawaroh ', '085223147692', 'SMP Terpadu Al Hasan ', 'MAN 2 Ciamis', 'default.jpg'),
(321, '2602015', 'MUHAMMAD RIZQI MAULANA', 'L', 'CIAMIS', '2010-03-08', 'KALAPANUNGGAL RT 030/012', 'SINDANGSARI', 'CIKONENG', 'CIAMIS', 'JAWA BARAT', 'KUSMAN ABDUL ROJAK', '085323040409', 'EPI FARIDA', '085323040409', 'SMP TERPADU AL HASAN', 'SMK Terpadu Al Hasan', 'default.jpg'),
(322, '2602017', 'MUHAMMAD ZIDANI SYAKIR ', 'L', 'Ciamis', '2010-09-30', 'Dsn.Cikalagen, RT/RW 006/003', 'Tanjungsari', 'Sadananya', 'Ciamis', 'Jawa Barat', 'Mumu Muhaemin', '087824602987', 'Enung Nurhayati', '087824602987', 'MTS Al Huda Sadananya', 'MAN 2 Ciamis', 'default.jpg'),
(323, '2602019', 'RIKZA ABDUL AZIZ', 'L', 'Ciamis', '2010-08-01', 'Dsn.Sukanguncal, RT/RW 001/006', 'Tanjungsari', 'Sadananya', 'Ciamis', 'Jawa Barat', 'Aep Saepuloh', '082118496252', 'Ulpah Yatipah', '082118496252', 'SMP Terpadu Al Hasan ', 'MAN 2 Ciamis', 'default.jpg'),
(324, '2602020', 'SA\'DAN AHMAD YAZID', 'L', 'Ciamis', '2010-06-22', 'Linkungan Cibitung Hilir', 'Kertasari', 'Ciamis', 'Ciamis', 'Jawa Barat', 'Umar Nawawi', '087874532544', 'Yati Nurhayati', '087874532544', 'SMP Terpadu Al Hasan', 'SMK Terpadu Al Hasan', 'default.jpg'),
(325, '2602021', 'SAYYID MALIK ABRORI', 'L', 'Ciamis', '2010-08-20', 'Dsn. Karangsari', 'Bangunsari', 'Pamarican', 'Ciamis', 'Jawa Barat', 'Ade Abdurrahman', '082119825660', 'Enung Nurhasanah', '082119825660', 'MTSN 7 Ciamis', 'MAN 2 Ciamis', 'default.jpg'),
(326, '2602022', 'ZIDAN RIZKI RAMADHAN', 'L', 'Ciamis', '2010-08-19', 'Sukaharja', 'Sukaharja', 'Rajadesa', 'Ciamis', 'Jawa Barat', 'Wahyu Hidayat', '081222195953', 'Cicih Wiarsih', '081222195953', 'SMPN 2 Rajadesa', 'MAN 2 Ciamis', 'default.jpg'),
(327, '2602023', 'AI ANNURUL A\'INI', 'P', 'CIAMIS', '2011-06-19', 'MULYAJAYA RT 003/007', 'CISAGA', 'CISAGA', 'CIAMIS', 'JAWA BARAT', 'MAMAT HIDAYAT', '085720576994', 'ETI NURHAYATI', '085720576994', 'MTS S AL IGNA CISAGA', 'SMK Terpadu Al Hasan', 'default.jpg'),
(328, '2602024', 'AISHA AKILA RAMADANI', 'P', 'Ciamis', '2010-09-01', 'Dsn. Wetan, RT/RW 019/005', 'Jatinagara', 'Jatinagara', 'Ciamis', 'Jawa Barat', 'Didik Yusuf Daryono', '081227367455', 'Enung Nuraeni', '081227367455', 'MTS Terpadu Riyadul Hidayah Al Munawwaroh', 'MAN 2 Ciamis', 'default.jpg'),
(329, '2602025', 'ALIFA NURUL IZATI PUTRI RIDWAN', 'P', 'Ciamis', '2010-07-31', 'Dsn.Karang Anyar', 'Cigugur', 'Cigugur', 'Pangandaran', 'Jawa Barat', 'Asep Ridwan Malik', '081214051127', 'Uli Purwanti', '081214051127', 'MTS YBH Cimindi', 'MAN 2 Ciamis', 'default.jpg'),
(330, '2602026', 'ALYA ZULFA MUTA\'AMIMAH', 'P', 'Ciamis', '2009-11-23', 'Dsn. Sirnarasa, RT/RW 04/02', 'Beber', 'Cimaragas', 'Ciamis', 'Jawa Barat', 'Irfan', '082217805660', 'Didah Mardiah', '082217805660', 'SMP Terpadu Al Hasan ', 'MAN 2 Ciamis', 'default.jpg'),
(331, '2602027', 'FADLA NAJMIATUL JANNAH', 'P', 'Ciamis', '2011-06-21', 'Dsn. Desa Kaler, RT/RW 001/001', 'Cihaurbeuti', 'Cihaurbeuti', 'Ciamis', 'Jawa Barat', 'Yayat Sudrajat', '', 'Pujianti', '', 'SMP Terpadu Al Hasan ', 'MAN 2 Ciamis', 'default.jpg'),
(332, '2602028', 'HANANIA FITRIANI', 'P', 'Ciamis', '2010-09-07', 'Dsn. Kersikan, RT/RW 025/010', 'Handapherang', 'Cijeungjing', 'Ciamis', 'Jawa Barat', 'Maman Bin Toharik', '085724984579', 'Sylvianti', '085724984579', 'SMP Terpadu Al Hasan ', 'SMAN 1 Ciamis', 'default.jpg'),
(333, '2602029', 'HANIA RAHMA IQNI', 'P', 'Ciamis', '2010-09-23', 'Rancapetir', 'Rancapetir', 'Ciamis', 'Ciamis', 'Jawa Barat', 'Muhammad Iqbal Rifa\'i', '089526026248', 'Nining Wahdaningsih', '089526026248', 'SMP Terpadu Al Hasan ', 'MAN 2 Ciamis', 'default.jpg'),
(334, '2602030', 'HAYATINNUFUS AL BADAR', 'P', 'Ciamis', '2011-04-13', 'Dsn. Desa ', 'Werasari', 'Sadananya', 'Ciamis', 'Jawa Barat', 'Endar Darussalim', '081313361865', 'Ebah Saebah', '081313361865', 'SMP Terpadu Al Hasan ', 'MAN 2 Ciamis', 'default.jpg'),
(335, '2602031', 'KEYLA KANAZWA AZZAHRA', 'P', 'Majalengka', '2010-04-21', 'Dsn. Cimanggu', 'Linggasari', 'Ciamis', 'Ciamis', 'Jawa Barat', 'Yayan Robayani', '085943295201', 'Iis Suryani', '085943295201', 'SMP Terpadu Al Hasan ', 'SMAN 2 Ciamis', 'default.jpg'),
(336, '2602032', 'KIKI AURELIA YANUAR ', 'P', 'Ciamis', '2011-01-10', 'Maparah 2 ', 'Maparah ', 'Panjalu', 'Ciamis', 'Jawa barat', 'Aso Solihin ', '', 'Imas ', '', 'SMP Terpadu Al Hasan ', 'MAN 2 Ciamis', 'default.jpg'),
(337, '2602034', 'LIYANA PILZA JAUHAR', 'P', 'Banjar', '2010-09-25', 'Jl. Tentara Pelajar 711, Dsn. Girimulya, RT/RW 001/013,', 'Binangun', 'Pataruman', 'Banjar', 'Jawa Barat', 'Undang Nana', '085223454654', 'Ikah', '085223454654', 'SMP IT Al-Fawwaz', 'MAN 2 Ciamis', 'default.jpg'),
(338, '2602035', 'MARSHA CAHYA AL KHAIRA', 'P', 'Cilacap', '2011-03-06', 'Dsn. Tambangan, RT/RW 05/01', 'Wringinharjo', 'Gandrungmango', 'Cilacap', 'Jawa Tengah ', 'Raslan', '082242903013', 'Eni Nuraeni', '082242903013', 'SMP Terpadu Al Hasan ', 'MAN 2 Ciamis', 'default.jpg'),
(339, '2602037', 'NAJMA ZHAFIRAH NURSALIMAH', 'P', 'Ciamis', '2011-06-07', 'Dsn. Sukadana, RT/RW 004/006', 'Sukawening', 'Cipaku', 'Ciamis', 'Jawa Barat', 'Maman', '085316981050', 'Mamay Nurkomala', '085316981050', 'SMP Terpadu Al Hasan ', 'SMAN 2 Ciamis', 'default.jpg'),
(340, '2602039', 'NAZIA ULHAQ', 'P', 'Brebes', '2011-07-11', 'Jl.Tegal Jati Dusun.Banjaran RT/RW 05/02', 'Banjaran', 'Banjaran', 'Brebes ', 'Jawa Barat', 'Cahyo', '085740021157', 'Cucum Sismiati', '085740021157', 'MTS Assalam Salem', 'MAN 2 Ciamis', 'default.jpg'),
(341, '2602041', 'NIDA SAMROTUL FUADAH', 'P', 'CIAMIS', '2010-10-19', 'WARUNGJATI RT 018/007', 'CIJEUNGJING', 'CIJEUNGJING', 'CIAMIS', 'JAWA BARAT', 'WAHYU ZATNIKA', '087895045923', 'SOPIATIN', '087895045923', 'SMPN 1 CIJEUNGJING', 'SMK Terpadu Al Hasan', 'default.jpg'),
(342, '2602042', 'SAVA MARWATI', 'P', 'Ciamis', '2010-09-22', '', '', '', '', '', '', '081222102401', '', '081222102401', 'MTs Al Amin', 'MAN 2 Ciamis', 'default.jpg'),
(343, '2602043', 'SHOFA NURBAITILLAH', 'P', 'Kuningan', '2010-12-17', 'Manis I RT 014 RW 007', 'Ciawilor', 'Ciawigebang', 'Kuningan', 'Jawa Barat', '-', '081548119218', 'Hj. Fatimah', '081548119218', 'SMP Terpadu Al Hasan', 'SMK Terpadu Al Hasan', 'default.jpg'),
(344, '2602044', 'SILVIA DWI JULIANTI', 'P', 'Ciamis', '2010-07-11', 'Dsn. Sukamantri, RT/RW 005/002', 'Sukasari', 'Cidolog', 'Ciamis', 'Jawa Barat', 'Riyanto', '085223836703', 'Ati Suhayati', '085223836703', 'SMP IT Al Fawwaz ', 'MAN 2 Ciamis', 'default.jpg'),
(345, '2602046', 'SITI EVA MUJARIFAH', 'P', 'Banjar', '2011-01-24', 'Cibeureum', 'Sidamulih', 'Sidamulih', 'Pangandaran ', 'Jawa barat', 'Uu Suherman ', '085223650684', 'Edeh Harlina ', '085223650684', 'SMP Terpadu Al Hasan ', 'MAN 2 Ciamis', 'default.jpg'),
(346, '2602047', 'SITI MARYAM NURLATIFAH TIRA SETIAWAN', 'P', 'Manonjaya', '2011-06-19', 'Kp. Pair Panjang, Dsn. Pasirpanjang, RT/RW 002/001', 'Kalimanggis', 'Manonjaya', 'Tasikmalaya', 'Jawa Barat', 'Wawan Setiawan', '085161982077', 'Eti Rohaeti', '085161982077', 'SMP Terpadu Al Hasan ', 'MAN 2 Ciamis', 'default.jpg'),
(347, '2602051', 'ZAHIRA MAULIDATUL HASANAH', 'P', 'Ciamis', '2011-03-14', 'Dsn. Ragapulu', 'Jelat', 'Baregbeg', 'Ciamis', 'Jawa Barat', 'Aep Saepulloh', '087858986018', 'Enah Nurhasanah', '087858986018', 'MTS Babakan', 'MAN 2 Ciamis', 'default.jpg'),
(348, '2602036', 'NAFISA ASYRI ARAFAH', 'P', 'Tasikmalaya', '2009-11-27', 'Kp. Balongbongan, RT/RW 004/004', 'Cibanteng', 'Parungponteng', 'Tasikmalaya', 'Jawa Barat', 'Agus Mustabat', '085324988000', 'Enoh Nurhasanah', '085324988000', 'SMP Terpadu Al Hasan ', 'MAN 2 Ciamis', 'default.jpg'),
(349, '2602038', 'NATHFA ZEIDA FIRLYZIA', 'P', 'Brebes', '2011-06-30', '', '', '', '', '', '', '082328638536', '', '082328638536', 'SMP NEGERI 1 SALEM', 'MAN 2 Ciamis', 'default.jpg'),
(350, '2602040', 'NIDA LABUDA SITI SHOLIHAH', 'P', 'Tasikmalaya', '2010-06-29', 'Kp. Cibaregbeg RT 021/005', 'Pasirbatang', 'Manonjaya', 'Tasikmalaya', 'Jawa Barat', 'Usup Supriadi', '', 'Dede Cica Fadilah', '', 'SMP Negeri 1 Manonjaya', 'SMK Terpadu Al Hasan', 'default.jpg'),
(351, '2602045', 'SINAR NURUL MAULIDA', 'P', 'Ciamis', '2011-02-17', 'Citeureup', 'Citeureup', 'Kawali', 'Ciamis', 'Jawa barat', 'Ayi Atma ', '082127804709', 'Dadah Saadah ', '082127804709', 'SMPN 2 Kawali', 'MAN 2 Ciamis', 'default.jpg'),
(352, '2602048', 'SYAHLA SHABIRA HELMI', 'P', 'Ciamis', '2010-11-18', 'Lingkungan Kedung Panjang, RT/RW 001/002', 'Maleber', 'Ciamis', 'Ciamis', 'Jawa Barat', 'Mochamad Helmi', '081573453378', 'Siti Rochimah', '081573453378', 'SMP Terpadu Al Hasan ', 'SMAN 2 Ciamis', 'default.jpg'),
(353, '2602049', 'SYIFA ALMAIRA FITRIA', 'P', 'Ciamis', '2011-03-09', 'Lingkungan Desa, RT/RW 003/003', 'Kertasari', 'Ciamis', 'Ciamis', 'Jawa Barat', 'Indrawan Fitrayana', '085723603070', 'Wulan Desryna', '085723603070', 'MTS Muhammadiyah Rancah', 'MAN 2 Ciamis', 'default.jpg'),
(354, '2602050', 'SYIFA OCTAVIA', 'P', 'Ciamis', '2012-10-30', 'Desa Rt/Rw 004/006', 'Margajaya', 'Sukadana', 'Ciamis', 'Jawa Barat', 'Dedi Rosadi', '085178523371', 'Yati Suryati', '085178523371', 'MTSS Margajaya', 'SMK Terpadu Al Hasan', 'default.jpg'),
(355, '2602007', 'AZHAR ZAIDAN', 'L', 'Banjar', '2011-01-30', 'Lingkungan Sumanding wetan RT/RW 02/23', 'Mekarsari', 'Banjar', 'Banjar', 'Jawa Barat', 'Asep Harjana', '085285030021', 'Nurnianingsih', '085285030021', 'SMP Terpadu Al Hasan ', 'MAN 2 Ciamis', 'default.jpg'),
(356, '2602011', 'HIDAYAT MUJIBUL AZMI', 'L', 'Banjar', '2010-10-26', 'Dusun Citangkolo RT. 05/RW.01', 'KUJANGSARI', 'LANGENSARI', 'KOTA BANJAR', 'JAWA BARAT', 'Momon Hermawan', '087826411457', 'Habibah', '087826411457', 'MTsN 1 Banjar', 'MAN 2 Ciamis', 'default.jpg'),
(357, '2602013', 'IMAM AHMAD AR-RASYID', 'L', 'JAKARTA', '2011-07-20', 'Jl. PLOT III/46 Dusun Duren Tiga', 'Duren Tiga', 'Pancoran', 'Jakarta Selatan', 'DKI Jakarta', 'Abdul Aziz Razak, S.Ag.', '', 'Eulis Jubaedah', '', 'SMP Terpadu Al Hasan', 'SMK Terpadu Al Hasan', 'default.jpg'),
(358, '2602014', 'MUHAMMAD HAFIDL NIJAR MUTTAQIN', 'L', 'Ciamis', '2010-12-16', 'Dsn. Sukasari', 'Sukawening', 'Cipaku', 'Ciamis', 'Jawa Barat', 'Iin Abdul Aziz', '081282414037', 'Solihat Aripatul Janah', '081282414037', 'SMP Terpadu Al Hasan ', 'MAN 2 Ciamis', 'default.jpg'),
(359, '2602016', 'MUHAMMAD YASSIR IRZAQI', 'L', 'Ciamis', '2010-07-19', 'Dsn. Caringin Rt 38 Rw 13', 'Cibeureum ', 'Sukamantri ', 'Ciamis ', 'Sukamantri ', 'Dede Heris ', '085222329407', 'Laelatussolihah ', '085222329407', 'SMP Terpadu Al Hasan ', 'SMAN 1 Ciamis', 'default.jpg'),
(360, '2602018', 'NABIL MUHAMMAD ZAKI', 'L', 'Ciamis', '2011-05-07', 'Jl. Cokroaminoto, RT/RW 01/25', 'Ciamis', 'Ciamis', 'Ciamis', 'Jawa Barat', 'Loso', '085700405208', 'Suminem', '085700405208', 'SMP Terpadu Al Hasan ', 'MAN 2 Ciamis', 'default.jpg'),
(361, '2602033', 'LIYANA AINUN UNSA ', 'P', 'Brebes ', '2011-01-11', 'Banjarsari Rt 04 Rw 02', 'Banjaran ', 'Salem', 'Brebes ', 'Jawa Tengah ', 'Tanto ', '085601158236', 'Wiwin Winarti', '085601158236', 'MTS Assalam Salem ', 'MAN 2 Ciamis', 'default.jpg');

-- --------------------------------------------------------

--
-- Table structure for table `tahun_ajaran`
--

CREATE TABLE `tahun_ajaran` (
  `id` int(11) NOT NULL,
  `tahun` varchar(20) NOT NULL,
  `semester` enum('Ganjil','Genap') NOT NULL,
  `status` enum('Aktif','Non-Aktif') NOT NULL DEFAULT 'Non-Aktif'
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `tahun_ajaran`
--

INSERT INTO `tahun_ajaran` (`id`, `tahun`, `semester`, `status`) VALUES
(2, '2025/2026', 'Genap', 'Non-Aktif'),
(3, '2025/2026', 'Ganjil', 'Non-Aktif'),
(4, '2026/2027', 'Ganjil', 'Aktif'),
(5, '2026/2027', 'Genap', 'Non-Aktif');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(100) NOT NULL,
  `username` varchar(50) NOT NULL,
  `email` varchar(191) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `guru_id` bigint(20) UNSIGNED DEFAULT NULL,
  `santri_id` bigint(20) UNSIGNED DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `last_login_at` timestamp NULL DEFAULT NULL,
  `remember_token` varchar(100) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `name`, `username`, `email`, `phone`, `password`, `guru_id`, `santri_id`, `is_active`, `last_login_at`, `remember_token`, `created_at`, `updated_at`) VALUES
(1, 'Administrator', 'admin', 'admin@alhasan.co.id', NULL, '$2y$12$sDIvib5EfaB7WBVolBrz4.wt5nqfL9qmDc3HRBPJb7glYhPJgdMYC', NULL, NULL, 1, NULL, NULL, '2026-08-15 07:42:02', '2026-08-15 07:42:02');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `alumni`
--
ALTER TABLE `alumni`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `nis` (`nis`);

--
-- Indexes for table `berita`
--
ALTER TABLE `berita`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `download`
--
ALTER TABLE `download`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `galeri`
--
ALTER TABLE `galeri`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `guru`
--
ALTER TABLE `guru`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `jadwal_ngaji`
--
ALTER TABLE `jadwal_ngaji`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `kamar`
--
ALTER TABLE `kamar`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `kelas`
--
ALTER TABLE `kelas`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `mapel`
--
ALTER TABLE `mapel`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `mengajar`
--
ALTER TABLE `mengajar`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `pelanggaran`
--
ALTER TABLE `pelanggaran`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `pembimbing_kamar`
--
ALTER TABLE `pembimbing_kamar`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `perizinan`
--
ALTER TABLE `perizinan`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `plotting_kamar`
--
ALTER TABLE `plotting_kamar`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `plotting_kelas`
--
ALTER TABLE `plotting_kelas`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `psb_pembayaran`
--
ALTER TABLE `psb_pembayaran`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `no_pendaftaran` (`no_pendaftaran`);

--
-- Indexes for table `psb_pendaftar`
--
ALTER TABLE `psb_pendaftar`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `no_pendaftaran` (`no_pendaftaran`),
  ADD UNIQUE KEY `nisn` (`nisn`);

--
-- Indexes for table `santri`
--
ALTER TABLE `santri`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `nis` (`nis`);

--
-- Indexes for table `tahun_ajaran`
--
ALTER TABLE `tahun_ajaran`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `users_username_unique` (`username`),
  ADD UNIQUE KEY `users_email_unique` (`email`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `alumni`
--
ALTER TABLE `alumni`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `berita`
--
ALTER TABLE `berita`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `download`
--
ALTER TABLE `download`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `galeri`
--
ALTER TABLE `galeri`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `guru`
--
ALTER TABLE `guru`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=27;

--
-- AUTO_INCREMENT for table `jadwal_ngaji`
--
ALTER TABLE `jadwal_ngaji`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `kamar`
--
ALTER TABLE `kamar`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=38;

--
-- AUTO_INCREMENT for table `kelas`
--
ALTER TABLE `kelas`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=22;

--
-- AUTO_INCREMENT for table `mapel`
--
ALTER TABLE `mapel`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `mengajar`
--
ALTER TABLE `mengajar`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `pelanggaran`
--
ALTER TABLE `pelanggaran`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `pembimbing_kamar`
--
ALTER TABLE `pembimbing_kamar`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `perizinan`
--
ALTER TABLE `perizinan`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `plotting_kamar`
--
ALTER TABLE `plotting_kamar`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=363;

--
-- AUTO_INCREMENT for table `plotting_kelas`
--
ALTER TABLE `plotting_kelas`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=383;

--
-- AUTO_INCREMENT for table `psb_pembayaran`
--
ALTER TABLE `psb_pembayaran`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=128;

--
-- AUTO_INCREMENT for table `psb_pendaftar`
--
ALTER TABLE `psb_pendaftar`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=223;

--
-- AUTO_INCREMENT for table `santri`
--
ALTER TABLE `santri`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=362;

--
-- AUTO_INCREMENT for table `tahun_ajaran`
--
ALTER TABLE `tahun_ajaran`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
