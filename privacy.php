<?php
// Kebijakan Privasi — halaman publik, tanpa login.
// Dipakai sebagai URL kebijakan privasi pada Google Play Console dan
// App Store Connect. Diakses melalui https://alhasan.co.id/privacy
// (lihat aturan rewrite pada .htaccess) maupun /privacy.php secara langsung.

$page_title = 'Kebijakan Privasi — Pondok Pesantren Al Hasan Ciamis';
$tanggal_berlaku_id = '1 September 2026';
$tanggal_berlaku_en = '1 September 2026';
$kontak_email = 'alhasanpesantren@gmail.com';
$kontak_alamat = 'Jalan Jenderal Ahmad Yani No. 120, Ciamis, Jawa Barat 46213, Indonesia';

include 'header.php';
?>

<style>
.privacy-wrap { max-width: 900px; }
.privacy-wrap h1 { font-weight: 700; }
.privacy-wrap h2 { font-size: 1.35rem; font-weight: 600; margin-top: 2.25rem; scroll-margin-top: 90px; }
.privacy-wrap h3 { font-size: 1.05rem; font-weight: 600; margin-top: 1.5rem; }
.privacy-wrap p, .privacy-wrap li { line-height: 1.75; }
.privacy-wrap table { font-size: .94rem; }
.privacy-meta { font-size: .95rem; color: #6c757d; }
.privacy-callout { background: #f4f9f5; border-left: 4px solid #1B7A43; border-radius: .5rem; padding: 1.1rem 1.25rem; }
.privacy-toc a { text-decoration: none; }
.lang-switch .btn { border-radius: 2rem; }
</style>

<main class="container privacy-wrap my-5 pt-4">

  <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3">
    <div class="lang-switch btn-group" role="group" aria-label="Pilih bahasa">
      <button type="button" class="btn btn-success btn-sm px-3" id="btn-lang-id">Bahasa Indonesia</button>
      <button type="button" class="btn btn-outline-success btn-sm px-3" id="btn-lang-en">English</button>
    </div>
    <span class="privacy-meta">
      <span id="meta-lang-id">Berlaku sejak <?php echo $tanggal_berlaku_id; ?></span>
      <span id="meta-lang-en" style="display:none;">Effective <?php echo $tanggal_berlaku_en; ?></span>
    </span>
  </div>

  <!-- ================= BAHASA INDONESIA ================= -->
  <section id="lang-id" lang="id">

    <h1 class="mb-2">Kebijakan Privasi</h1>
    <p class="privacy-meta mb-4">
      Berlaku sejak <?php echo $tanggal_berlaku_id; ?> &middot; Terakhir diperbarui <?php echo $tanggal_berlaku_id; ?><br>
      Berlaku untuk aplikasi <strong>alhasanApps</strong> (Android &amp; iOS) dan situs <strong>alhasan.co.id</strong>.
    </p>

    <div class="privacy-callout mb-4">
      <strong>Ringkasan singkat.</strong>
      <ul class="mb-0 mt-2">
        <li>Kami <strong>tidak menampilkan iklan</strong> dan <strong>tidak menjual atau menyewakan</strong> data pribadi kepada siapa pun.</li>
        <li>Aplikasi <strong>tidak memuat SDK analitik, periklanan, atau pelacak pihak ketiga</strong>, dan tidak melacak Anda di aplikasi atau situs milik perusahaan lain.</li>
        <li>Aplikasi <strong>tidak mengakses lokasi, kamera, mikrofon, kontak, maupun galeri foto</strong> Anda.</li>
        <li>Akun aplikasi hanya diterbitkan pesantren untuk guru, pengurus, murobi, admin, dan orang tua/wali. Tidak ada pendaftaran mandiri.</li>
        <li>Seluruh lalu lintas data berjalan melalui koneksi terenkripsi HTTPS.</li>
      </ul>
    </div>

    <div class="privacy-toc mb-4">
      <strong>Daftar isi</strong>
      <ol class="mt-2">
        <li><a href="#id-1">Pengendali data dan kontak</a></li>
        <li><a href="#id-2">Ruang lingkup kebijakan</a></li>
        <li><a href="#id-3">Data pribadi yang kami kumpulkan</a></li>
        <li><a href="#id-4">Tujuan dan dasar hukum pemrosesan</a></li>
        <li><a href="#id-5">Izin perangkat yang diminta aplikasi</a></li>
        <li><a href="#id-6">Berbagi data dan pihak ketiga</a></li>
        <li><a href="#id-7">Masa penyimpanan data</a></li>
        <li><a href="#id-8">Keamanan data</a></li>
        <li><a href="#id-9">Hak Anda sebagai subjek data</a></li>
        <li><a href="#id-10">Penghapusan akun dan data</a></li>
        <li><a href="#id-11">Data santri dan anak di bawah umur</a></li>
        <li><a href="#id-12">Transfer data ke luar negeri</a></li>
        <li><a href="#id-13">Perubahan kebijakan</a></li>
        <li><a href="#id-14">Menghubungi kami</a></li>
      </ol>
    </div>

    <h2 id="id-1">1. Pengendali data dan kontak</h2>
    <p>
      Kebijakan ini diterbitkan oleh <strong>Pondok Pesantren Al Hasan Ciamis</strong> ("kami"), yang bertindak sebagai
      Pengendali Data Pribadi atas seluruh data yang diproses melalui aplikasi alhasanApps dan situs alhasan.co.id.
    </p>
    <ul>
      <li>Alamat: <?php echo $kontak_alamat; ?></li>
      <li>Surel untuk urusan privasi dan permintaan data: <a href="mailto:<?php echo $kontak_email; ?>"><?php echo $kontak_email; ?></a></li>
      <li>Jam layanan: Senin&ndash;Kamis, 08.00&ndash;16.00 WIB</li>
    </ul>

    <h2 id="id-2">2. Ruang lingkup kebijakan</h2>
    <p>Kebijakan ini mencakup:</p>
    <ul>
      <li><strong>alhasanApps</strong> &mdash; aplikasi mobile untuk Android dan iOS (paket <code>com.ilanmochamad.alhasanApps</code>) yang dipakai guru, pengurus, murobi, admin, dan orang tua/wali untuk melihat jadwal, mencatat kehadiran, mengelola perizinan santri, membaca laporan, dan menerima pemberitahuan.</li>
      <li><strong>alhasan.co.id</strong> &mdash; situs resmi pesantren, termasuk layanan Penerimaan Santri Baru (PSB) daring dan portal pendaftar.</li>
    </ul>
    <p>
      Akun aplikasi tidak dapat dibuat sendiri oleh pengguna. Akun diterbitkan dan dinonaktifkan oleh administrator pesantren.
    </p>

    <h2 id="id-3">3. Data pribadi yang kami kumpulkan</h2>

    <h3>3.1 Data akun dan sesi (aplikasi)</h3>
    <ul>
      <li>Nama pengguna dan kata sandi yang Anda masukkan saat masuk. Kata sandi dikirim melalui HTTPS untuk diverifikasi, <strong>tidak pernah disimpan di perangkat</strong>, dan disimpan di server hanya dalam bentuk hash.</li>
      <li>Nama lengkap, ID pengguna, peran (guru, pengurus, murobi, admin, orang tua/wali), serta NIP bagi guru.</li>
      <li>Token sesi. Token disimpan di penyimpanan aman sistem operasi (Keychain iOS / Keystore Android). Server hanya menyimpan sidik hash token, bukan tokennya. Token berlaku 30 hari dan dicabut saat Anda keluar.</li>
      <li>Nama perangkat yang dikirim saat masuk, untuk membedakan sesi antar perangkat.</li>
    </ul>

    <h3>3.2 Data pemberitahuan (push notification)</h3>
    <ul>
      <li>Token push perangkat dari layanan Expo. Di server, token disimpan terlindungi (terenkripsi) dan diindeks dengan sidik hash.</li>
      <li>Pengenal instalasi acak yang dibuat aplikasi. Pengenal ini <strong>bukan</strong> pengenal perangkat keras, bukan IMEI, bukan ID iklan, dan tidak dipakai untuk pelacakan. Fungsinya hanya agar token baru menggantikan token lama pada perangkat yang sama.</li>
      <li>Platform (Android/iOS), label perangkat, versi aplikasi, dan status sakelar push per perangkat.</li>
    </ul>

    <h3>3.3 Data operasional pesantren yang diproses melalui aplikasi</h3>
    <ul>
      <li>Jadwal pengajian, pertemuan, kelas, mata pelajaran, dan tahun ajaran.</li>
      <li>Kehadiran guru dan santri: status (Hadir, Terlambat, Izin, Sakit, Alpa), catatan, pencatat, serta waktu pencatatan dan koreksi.</li>
      <li>Perizinan santri: identitas santri, tanggal izin dan tanggal kembali, alasan, catatan pengurus, keputusan murobi/admin, dan riwayat perubahannya.</li>
      <li>Laporan kehadiran. Saat Anda mencetak laporan, berkas PDF dibuat di perangkat Anda dan hanya keluar dari perangkat bila Anda sendiri yang membagikannya.</li>
    </ul>
    <p class="privacy-meta">
      Data pada butir ini adalah catatan kelembagaan pesantren. Pengguna aplikasi mengaksesnya sesuai kewenangan perannya; seorang guru, misalnya, hanya melihat jadwal dan laporan miliknya sendiri.
    </p>

    <h3>3.4 Data pada situs alhasan.co.id</h3>
    <ul>
      <li><strong>Pendaftaran Santri Baru (PSB):</strong> nama lengkap, NIK, NISN, tempat dan tanggal lahir, jenis kelamin, alamat lengkap (jalan, desa, kecamatan, kabupaten, provinsi), nama ayah dan ibu, nomor HP wali, sekolah asal, dan jenjang tujuan.</li>
      <li><strong>Berkas unggahan pendaftar:</strong> kartu keluarga, KTP wali, akta kelahiran, ijazah, transkrip nilai, dan berkas prestasi dalam format PNG, JPG, atau PDF.</li>
      <li><strong>Cookie sesi</strong> untuk menjaga status masuk pendaftar dan administrator. Situs tidak memasang cookie periklanan.</li>
    </ul>

    <h3>3.5 Data teknis</h3>
    <p>
      Server web mencatat log teknis standar berupa alamat IP, waktu akses, alamat sumber daya yang diminta, kode status, dan jenis peramban atau aplikasi. Server juga mencatat percobaan masuk yang gagal untuk membatasi serangan tebak kata sandi. Log ini dipakai untuk keamanan dan penanganan gangguan, bukan untuk membuat profil pengguna.
    </p>

    <h3>3.6 Data yang <em>tidak</em> kami kumpulkan</h3>
    <p>
      Aplikasi tidak mengumpulkan lokasi presisi maupun perkiraan, tidak mengakses kamera, mikrofon, galeri foto, kontak, kalender, SMS, atau daftar aplikasi terpasang, tidak menggunakan pengenal iklan, dan tidak memuat SDK analitik atau periklanan pihak ketiga.
    </p>

    <h2 id="id-4">4. Tujuan dan dasar hukum pemrosesan</h2>
    <div class="table-responsive">
      <table class="table table-bordered align-middle">
        <thead class="table-light">
          <tr><th style="width:38%">Tujuan</th><th style="width:32%">Data yang dipakai</th><th>Dasar hukum (UU No. 27 Tahun 2022)</th></tr>
        </thead>
        <tbody>
          <tr><td>Autentikasi dan menjaga sesi</td><td>Akun, token sesi, nama perangkat</td><td>Pelaksanaan tugas dan hubungan kelembagaan dengan pengguna</td></tr>
          <tr><td>Pencatatan kehadiran dan jadwal</td><td>Data operasional pesantren</td><td>Kepentingan sah pesantren dalam menyelenggarakan pendidikan</td></tr>
          <tr><td>Pengelolaan perizinan santri</td><td>Identitas santri, tanggal, alasan, keputusan</td><td>Kepentingan sah dan pelaksanaan pengasuhan santri</td></tr>
          <tr><td>Pengiriman pemberitahuan</td><td>Token push, pengenal instalasi, platform</td><td>Persetujuan Anda melalui izin notifikasi perangkat</td></tr>
          <tr><td>Proses penerimaan santri baru</td><td>Data dan berkas pendaftar</td><td>Persetujuan pendaftar/wali dan langkah pra-perjanjian pendaftaran</td></tr>
          <tr><td>Keamanan sistem dan pencegahan penyalahgunaan</td><td>Log teknis, catatan percobaan masuk</td><td>Kewajiban hukum dan kepentingan sah menjaga keamanan</td></tr>
        </tbody>
      </table>
    </div>

    <h2 id="id-5">5. Izin perangkat yang diminta aplikasi</h2>
    <ul>
      <li><strong>Notifikasi</strong> &mdash; untuk mengirim pemberitahuan perizinan dan kegiatan. Izin diminta sekali; bila Anda menolak, aplikasi tetap berfungsi penuh kecuali pemberitahuan dorong. Anda dapat mencabutnya kapan saja lewat pengaturan perangkat, atau mematikan push per perangkat dari halaman <em>Profil &rarr; Perangkat &amp; push</em> di dalam aplikasi.</li>
      <li><strong>Akses internet</strong> &mdash; untuk berkomunikasi dengan server pesantren.</li>
    </ul>
    <p>Tidak ada izin sensitif lain yang diminta.</p>

    <h2 id="id-6">6. Berbagi data dan pihak ketiga</h2>
    <p>Kami tidak menjual, menyewakan, atau menukarkan data pribadi. Data hanya dibagikan kepada pihak berikut, sebatas yang diperlukan agar layanan berjalan:</p>
    <div class="table-responsive">
      <table class="table table-bordered align-middle">
        <thead class="table-light">
          <tr><th style="width:30%">Pihak</th><th style="width:38%">Data yang diterima</th><th>Peran</th></tr>
        </thead>
        <tbody>
          <tr><td>Expo (Expo Application Services, Amerika Serikat)</td><td>Token push perangkat dan isi ringkas pemberitahuan</td><td>Menyalurkan pemberitahuan ke perangkat</td></tr>
          <tr><td>Google Firebase Cloud Messaging</td><td>Token push dan isi pemberitahuan pada perangkat Android</td><td>Pengiriman pemberitahuan Android</td></tr>
          <tr><td>Apple Push Notification service</td><td>Token push dan isi pemberitahuan pada perangkat iOS</td><td>Pengiriman pemberitahuan iOS</td></tr>
          <tr><td>Penyedia hosting situs dan basis data</td><td>Seluruh data yang tersimpan di server</td><td>Prosesor data atas instruksi kami</td></tr>
          <tr><td>Penyedia gateway WhatsApp (opsional, nonaktif secara bawaan)</td><td>Nomor tujuan dan isi pesan pemberitahuan</td><td>Kanal pemberitahuan alternatif, hanya bila diaktifkan pesantren</td></tr>
          <tr><td>Penyedia pustaka tampilan situs (Bootstrap, Google Fonts, Font Awesome)</td><td>Alamat IP peramban saat memuat berkas tampilan</td><td>Pengiriman aset statis situs</td></tr>
        </tbody>
      </table>
    </div>
    <p>
      Kami juga dapat mengungkapkan data bila diwajibkan oleh hukum atau permintaan sah dari aparat yang berwenang.
    </p>

    <h2 id="id-7">7. Masa penyimpanan data</h2>
    <ul>
      <li><strong>Token sesi:</strong> maksimal 30 hari, dan langsung dicabut saat Anda keluar dari aplikasi.</li>
      <li><strong>Token perangkat push:</strong> disimpan selama perangkat terdaftar, dan dihapus saat Anda mencabut perangkat, mematikan push, keluar, atau menghapus aplikasi.</li>
      <li><strong>Kehadiran, perizinan, dan laporan:</strong> disimpan sebagai arsip akademik selama santri terdaftar dan paling lama 5 tahun setelah santri lulus atau keluar.</li>
      <li><strong>Data dan berkas pendaftar PSB:</strong> disimpan selama proses seleksi; bagi pendaftar yang tidak melanjutkan pendaftaran, data dihapus atau dianonimkan paling lambat 1 tahun setelah tahun ajaran yang bersangkutan berakhir.</li>
      <li><strong>Log teknis server:</strong> umumnya disimpan tidak lebih dari 12 bulan.</li>
    </ul>

    <h2 id="id-8">8. Keamanan data</h2>
    <ul>
      <li>Seluruh lalu lintas produksi memakai HTTPS.</li>
      <li>Kata sandi disimpan sebagai hash, bukan teks biasa.</li>
      <li>Token sesi hanya disimpan di server dalam bentuk hash HMAC-SHA-256, sehingga tidak dapat dibaca ulang dari basis data.</li>
      <li>Token push disimpan terenkripsi dengan kunci yang berada di lingkungan server, di luar basis data dan di luar paket aplikasi.</li>
      <li>Di perangkat, token sesi disimpan pada Keychain (iOS) atau Keystore (Android).</li>
      <li>Akses data dibatasi menurut peran dan kewenangan yang dihitung ulang di server pada setiap permintaan.</li>
      <li>Percobaan masuk dibatasi untuk mencegah serangan tebak kata sandi.</li>
    </ul>
    <p>
      Tidak ada sistem yang sepenuhnya kebal. Bila terjadi kebocoran data pribadi, kami akan memberi tahu subjek data dan lembaga berwenang sesuai ketentuan UU Pelindungan Data Pribadi.
    </p>

    <h2 id="id-9">9. Hak Anda sebagai subjek data</h2>
    <p>Berdasarkan Undang-Undang No. 27 Tahun 2022 tentang Pelindungan Data Pribadi, Anda berhak untuk:</p>
    <ul>
      <li>memperoleh informasi dan salinan data pribadi Anda yang kami proses;</li>
      <li>meminta perbaikan data yang keliru atau tidak lengkap;</li>
      <li>meminta penghapusan data pribadi Anda;</li>
      <li>menarik persetujuan, misalnya dengan mematikan pemberitahuan;</li>
      <li>membatasi atau menolak pemrosesan tertentu;</li>
      <li>mengajukan keberatan dan menyampaikan aduan.</li>
    </ul>
    <p>
      Kirim permintaan ke <a href="mailto:<?php echo $kontak_email; ?>"><?php echo $kontak_email; ?></a> dari alamat surel Anda, sertakan nama dan nama pengguna aplikasi. Kami menanggapi paling lambat <strong>14 hari kerja</strong>. Sebagian data akademik dapat kami pertahankan bila undang-undang atau kewajiban kearsipan pendidikan mengharuskannya; bila demikian, kami menjelaskan alasannya kepada Anda.
    </p>

    <h2 id="id-10">10. Penghapusan akun dan data</h2>
    <p>
      Akun alhasanApps diterbitkan oleh pesantren, sehingga penghapusan dilakukan melalui permintaan, bukan tombol mandiri di aplikasi. Caranya:
    </p>
    <ol>
      <li>Kirim surel ke <a href="mailto:<?php echo $kontak_email; ?>"><?php echo $kontak_email; ?></a> dengan subjek <strong>&ldquo;Penghapusan Akun alhasanApps&rdquo;</strong>.</li>
      <li>Cantumkan nama lengkap, nama pengguna, dan peran Anda.</li>
      <li>Kami memverifikasi permintaan melalui administrator pesantren, lalu memprosesnya paling lambat 14 hari kerja.</li>
    </ol>
    <p>Yang terjadi setelah permintaan disetujui:</p>
    <ul>
      <li><strong>Dihapus:</strong> akun dan kredensial, seluruh token sesi, serta seluruh pendaftaran perangkat push milik Anda.</li>
      <li><strong>Dipertahankan:</strong> catatan kehadiran, perizinan, dan laporan yang sudah menjadi arsip resmi pesantren, dengan masa simpan pada Bagian 7. Bila memungkinkan, catatan tersebut dilepaskan kaitannya dari identitas pribadi Anda.</li>
    </ul>
    <p>
      Anda juga dapat menghentikan pemrosesan sebagian tanpa menghapus akun: keluar dari aplikasi mencabut token sesi, dan mematikan push pada halaman <em>Profil &rarr; Perangkat &amp; push</em> mencabut pendaftaran perangkat.
    </p>

    <h2 id="id-11">11. Data santri dan anak di bawah umur</h2>
    <p>
      Aplikasi alhasanApps <strong>tidak ditujukan untuk digunakan oleh anak-anak</strong>. Akun hanya diterbitkan bagi guru, pengurus, murobi, admin, dan orang tua/wali yang telah dewasa.
    </p>
    <p>
      Aplikasi memang memproses data santri yang sebagian di antaranya berusia di bawah 18 tahun &mdash; nama, kelas, kamar, status kehadiran, dan perizinan &mdash; sebagai catatan kelembagaan pendidikan. Data tersebut diperoleh saat pendaftaran santri dengan persetujuan orang tua atau wali, hanya dapat dilihat oleh pengguna yang berwenang atas santri bersangkutan, tidak pernah dipakai untuk iklan atau profil komersial, dan tidak dibagikan kepada pihak ketiga di luar Bagian 6. Orang tua atau wali dapat menghubungi kami untuk mengakses, memperbaiki, atau mengajukan penghapusan data anaknya.
    </p>

    <h2 id="id-12">12. Transfer data ke luar negeri</h2>
    <p>
      Server utama dan basis data kami berada di Indonesia. Pengecualiannya adalah pengiriman pemberitahuan: token push dan isi ringkas pemberitahuan diteruskan melalui layanan Expo, Google (FCM), dan Apple (APNs) yang servernya dapat berada di luar Indonesia. Kami membatasi data pada butir tersebut seminimal mungkin dan tidak mengirimkan data kehadiran maupun berkas pendaftar ke layanan tersebut.
    </p>

    <h2 id="id-13">13. Perubahan kebijakan</h2>
    <p>
      Kebijakan ini dapat diperbarui bila fitur atau ketentuan hukum berubah. Versi terbaru selalu tersedia di
      <a href="https://alhasan.co.id/privacy">https://alhasan.co.id/privacy</a> dengan tanggal pembaruan di bagian atas halaman.
      Untuk perubahan yang signifikan, kami memberitahukannya melalui pemberitahuan di dalam aplikasi atau surel kepada administrator pesantren.
    </p>

    <h2 id="id-14">14. Menghubungi kami</h2>
    <p>
      Pondok Pesantren Al Hasan Ciamis<br>
      <?php echo $kontak_alamat; ?><br>
      Surel: <a href="mailto:<?php echo $kontak_email; ?>"><?php echo $kontak_email; ?></a>
    </p>
    <p class="privacy-meta">
      Bila Anda menilai penanganan data pribadi Anda tidak sesuai ketentuan, Anda berhak menyampaikan aduan kepada lembaga pelindungan data pribadi yang berwenang di Indonesia.
    </p>
  </section>

  <!-- ================= ENGLISH ================= -->
  <section id="lang-en" lang="en" style="display:none;">

    <h1 class="mb-2">Privacy Policy</h1>
    <p class="privacy-meta mb-4">
      Effective <?php echo $tanggal_berlaku_en; ?> &middot; Last updated <?php echo $tanggal_berlaku_en; ?><br>
      Applies to the <strong>alhasanApps</strong> mobile app (Android &amp; iOS) and the <strong>alhasan.co.id</strong> website.
    </p>

    <div class="privacy-callout mb-4">
      <strong>At a glance.</strong>
      <ul class="mb-0 mt-2">
        <li>We show <strong>no advertising</strong> and we <strong>never sell or rent</strong> personal data.</li>
        <li>The app contains <strong>no third-party analytics, advertising, or tracking SDKs</strong>, and does not track you across other companies' apps or websites.</li>
        <li>The app does <strong>not access your location, camera, microphone, contacts, or photo library</strong>.</li>
        <li>Accounts are issued by the pesantren to teachers, staff, murobi, administrators, and parents/guardians. There is no self-registration.</li>
        <li>All traffic runs over encrypted HTTPS connections.</li>
      </ul>
    </div>

    <h2 id="en-1">1. Data controller and contact</h2>
    <p>
      This policy is issued by <strong>Pondok Pesantren Al Hasan Ciamis</strong> ("we", "us"), the data controller for all personal data processed through the alhasanApps app and the alhasan.co.id website.
    </p>
    <ul>
      <li>Address: <?php echo $kontak_alamat; ?></li>
      <li>Privacy and data-request email: <a href="mailto:<?php echo $kontak_email; ?>"><?php echo $kontak_email; ?></a></li>
      <li>Office hours: Monday&ndash;Thursday, 08:00&ndash;16:00 (UTC+7)</li>
    </ul>

    <h2 id="en-2">2. Scope</h2>
    <ul>
      <li><strong>alhasanApps</strong> &mdash; the Android and iOS app (package <code>com.ilanmochamad.alhasanApps</code>) used by teachers, staff, murobi, administrators, and parents/guardians to view schedules, record attendance, manage student leave requests, read reports, and receive notifications.</li>
      <li><strong>alhasan.co.id</strong> &mdash; the official school website, including online new-student admission (PSB) and the applicant portal.</li>
    </ul>
    <p>App accounts cannot be created by users; they are issued and deactivated by school administrators.</p>

    <h2 id="en-3">3. Personal data we collect</h2>

    <h3>3.1 Account and session data (app)</h3>
    <ul>
      <li>The username and password you enter at sign-in. The password is sent over HTTPS for verification, is <strong>never stored on the device</strong>, and is stored on the server only as a hash.</li>
      <li>Full name, user ID, role (teacher, staff, murobi, administrator, parent/guardian), and teacher registration number where applicable.</li>
      <li>A session token, kept in the operating system's secure storage (iOS Keychain / Android Keystore). The server stores only a hash of the token, never the token itself. Tokens expire after 30 days and are revoked when you sign out.</li>
      <li>The device name sent at sign-in, used to distinguish sessions across devices.</li>
    </ul>

    <h3>3.2 Push notification data</h3>
    <ul>
      <li>An Expo push token for the device, stored encrypted on the server and indexed by a hash.</li>
      <li>A random installation identifier generated by the app. It is <strong>not</strong> a hardware identifier, IMEI, or advertising ID, and is not used for tracking; it only lets a refreshed token replace the old one for the same installation.</li>
      <li>Platform (Android/iOS), device label, app version, and the per-device push on/off setting.</li>
    </ul>

    <h3>3.3 School operational data processed in the app</h3>
    <ul>
      <li>Class schedules, meetings, classes, subjects, and academic years.</li>
      <li>Teacher and student attendance: status (present, late, excused, sick, absent), notes, who recorded it, and when it was recorded or corrected.</li>
      <li>Student leave requests: student identity, leave and return dates, reason, staff notes, murobi/administrator decisions, and the change history.</li>
      <li>Attendance reports. When you print a report, the PDF is generated on your device and leaves it only if you choose to share it.</li>
    </ul>

    <h3>3.4 Website data (alhasan.co.id)</h3>
    <ul>
      <li><strong>New-student admission (PSB):</strong> full name, national ID number (NIK), national student number (NISN), place and date of birth, sex, full address, parents' names, guardian's mobile number, previous school, and desired level of study.</li>
      <li><strong>Applicant document uploads:</strong> family card, guardian's ID card, birth certificate, diploma, transcript, and achievement records, as PNG, JPG, or PDF.</li>
      <li><strong>Session cookies</strong> to keep applicants and administrators signed in. The site sets no advertising cookies.</li>
    </ul>

    <h3>3.5 Technical data</h3>
    <p>
      The web server keeps standard technical logs: IP address, access time, requested resource, status code, and browser or app type. Failed sign-in attempts are recorded to throttle password-guessing attacks. These logs serve security and troubleshooting, not user profiling.
    </p>

    <h3>3.6 Data we do <em>not</em> collect</h3>
    <p>
      The app collects no precise or approximate location, and does not access the camera, microphone, photo library, contacts, calendar, SMS, or installed-app list. It uses no advertising identifier and embeds no third-party analytics or advertising SDK.
    </p>

    <h2 id="en-4">4. Purposes and legal bases</h2>
    <div class="table-responsive">
      <table class="table table-bordered align-middle">
        <thead class="table-light">
          <tr><th style="width:38%">Purpose</th><th style="width:32%">Data used</th><th>Legal basis (Law No. 27 of 2022)</th></tr>
        </thead>
        <tbody>
          <tr><td>Authentication and session management</td><td>Account, session token, device name</td><td>Performance of our institutional relationship with the user</td></tr>
          <tr><td>Attendance and schedule records</td><td>School operational data</td><td>Legitimate interest in delivering education</td></tr>
          <tr><td>Managing student leave requests</td><td>Student identity, dates, reasons, decisions</td><td>Legitimate interest and student care duties</td></tr>
          <tr><td>Delivering notifications</td><td>Push token, installation ID, platform</td><td>Your consent, given through the device notification permission</td></tr>
          <tr><td>New-student admission</td><td>Applicant data and documents</td><td>Consent of the applicant/guardian and pre-contractual steps</td></tr>
          <tr><td>System security and abuse prevention</td><td>Technical logs, sign-in attempt records</td><td>Legal obligation and legitimate security interest</td></tr>
        </tbody>
      </table>
    </div>

    <h2 id="en-5">5. Device permissions</h2>
    <ul>
      <li><strong>Notifications</strong> &mdash; to deliver leave-request and activity alerts. The permission is requested once; declining it leaves every other feature working. You can revoke it any time in device settings, or turn push off per device under <em>Profile &rarr; Devices &amp; push</em> in the app.</li>
      <li><strong>Internet access</strong> &mdash; to communicate with the school server.</li>
    </ul>
    <p>No other sensitive permission is requested.</p>

    <h2 id="en-6">6. Sharing and third parties</h2>
    <p>We do not sell, rent, or trade personal data. Data is shared only with the following parties, limited to what the service requires:</p>
    <div class="table-responsive">
      <table class="table table-bordered align-middle">
        <thead class="table-light">
          <tr><th style="width:30%">Party</th><th style="width:38%">Data received</th><th>Role</th></tr>
        </thead>
        <tbody>
          <tr><td>Expo (Expo Application Services, USA)</td><td>Device push token and short notification content</td><td>Routes notifications to devices</td></tr>
          <tr><td>Google Firebase Cloud Messaging</td><td>Push token and notification content on Android</td><td>Android notification delivery</td></tr>
          <tr><td>Apple Push Notification service</td><td>Push token and notification content on iOS</td><td>iOS notification delivery</td></tr>
          <tr><td>Website and database hosting provider</td><td>All data stored on the server</td><td>Processor acting on our instructions</td></tr>
          <tr><td>WhatsApp gateway provider (optional, off by default)</td><td>Destination number and notification message</td><td>Alternative notification channel, only if the school enables it</td></tr>
          <tr><td>Front-end asset providers (Bootstrap, Google Fonts, Font Awesome)</td><td>Browser IP address when loading assets</td><td>Delivery of static website assets</td></tr>
        </tbody>
      </table>
    </div>
    <p>We may also disclose data where required by law or by a lawful request from a competent authority.</p>

    <h2 id="en-7">7. Retention</h2>
    <ul>
      <li><strong>Session tokens:</strong> up to 30 days, revoked immediately when you sign out.</li>
      <li><strong>Push device tokens:</strong> kept while the device is registered; deleted when you revoke the device, turn push off, sign out, or uninstall the app.</li>
      <li><strong>Attendance, leave requests, and reports:</strong> kept as academic records while the student is enrolled and for up to 5 years after they graduate or leave.</li>
      <li><strong>Applicant data and documents:</strong> kept for the duration of the selection process; for applicants who do not proceed, data is deleted or anonymised within 1 year after the end of that academic year.</li>
      <li><strong>Server technical logs:</strong> normally no longer than 12 months.</li>
    </ul>

    <h2 id="en-8">8. Security</h2>
    <ul>
      <li>All production traffic uses HTTPS.</li>
      <li>Passwords are stored hashed, never in plain text.</li>
      <li>Session tokens are stored server-side only as HMAC-SHA-256 hashes and cannot be recovered from the database.</li>
      <li>Push tokens are stored encrypted with a key held in the server environment, outside the database and outside the app bundle.</li>
      <li>On the device, session tokens live in the iOS Keychain or Android Keystore.</li>
      <li>Access is restricted by role, with permissions recomputed server-side on every request.</li>
      <li>Sign-in attempts are rate-limited against password guessing.</li>
    </ul>
    <p>
      No system is perfectly secure. In the event of a personal data breach we will notify affected data subjects and the competent authority as required by Indonesian personal data protection law.
    </p>

    <h2 id="en-9">9. Your rights</h2>
    <p>Under Law No. 27 of 2022 on Personal Data Protection you may:</p>
    <ul>
      <li>obtain information about, and a copy of, the personal data we process about you;</li>
      <li>request correction of inaccurate or incomplete data;</li>
      <li>request deletion of your personal data;</li>
      <li>withdraw consent, for example by turning notifications off;</li>
      <li>restrict or object to certain processing;</li>
      <li>lodge a complaint.</li>
    </ul>
    <p>
      Send requests to <a href="mailto:<?php echo $kontak_email; ?>"><?php echo $kontak_email; ?></a> from your own email address, including your name and app username. We respond within <strong>14 working days</strong>. Some academic records may be retained where law or educational record-keeping duties require it; we will explain the reason if that applies.
    </p>

    <h2 id="en-10">10. Account and data deletion</h2>
    <p>alhasanApps accounts are issued by the school, so deletion happens by request rather than through an in-app button:</p>
    <ol>
      <li>Email <a href="mailto:<?php echo $kontak_email; ?>"><?php echo $kontak_email; ?></a> with the subject <strong>&ldquo;alhasanApps Account Deletion&rdquo;</strong>.</li>
      <li>Include your full name, username, and role.</li>
      <li>We verify the request with the school administrator and process it within 14 working days.</li>
    </ol>
    <p>Once approved:</p>
    <ul>
      <li><strong>Deleted:</strong> your account and credentials, all session tokens, and all push device registrations.</li>
      <li><strong>Retained:</strong> attendance, leave, and report records that form the school's official archive, for the periods in Section 7. Where feasible, those records are detached from your personal identity.</li>
    </ul>
    <p>
      You can also stop parts of the processing without deleting the account: signing out revokes the session token, and turning push off under <em>Profile &rarr; Devices &amp; push</em> revokes the device registration.
    </p>

    <h2 id="en-11">11. Children's data</h2>
    <p>
      alhasanApps is <strong>not directed to children</strong>. Accounts are issued only to adult teachers, staff, murobi, administrators, and parents/guardians.
    </p>
    <p>
      The app does process data about students, some of whom are under 18 &mdash; name, class, dormitory room, attendance status, and leave records &mdash; as institutional education records. That data is collected at student enrolment with parental or guardian consent, is visible only to users authorised for that student, is never used for advertising or commercial profiling, and is not shared beyond the parties in Section 6. Parents and guardians may contact us to access, correct, or request deletion of their child's data.
    </p>

    <h2 id="en-12">12. International transfers</h2>
    <p>
      Our main server and database are located in Indonesia. The exception is notification delivery: push tokens and short notification content pass through Expo, Google (FCM), and Apple (APNs), whose servers may be outside Indonesia. We keep that data minimal and never send attendance records or applicant documents to those services.
    </p>

    <h2 id="en-13">13. Changes to this policy</h2>
    <p>
      We may update this policy as features or legal requirements change. The current version is always at
      <a href="https://alhasan.co.id/privacy">https://alhasan.co.id/privacy</a>, with the update date shown at the top.
      Significant changes are announced through an in-app notice or by email to school administrators.
    </p>

    <h2 id="en-14">14. Contact us</h2>
    <p>
      Pondok Pesantren Al Hasan Ciamis<br>
      <?php echo $kontak_alamat; ?><br>
      Email: <a href="mailto:<?php echo $kontak_email; ?>"><?php echo $kontak_email; ?></a>
    </p>
  </section>

</main>

<script>
(function () {
  var id = document.getElementById('lang-id');
  var en = document.getElementById('lang-en');
  var btnId = document.getElementById('btn-lang-id');
  var btnEn = document.getElementById('btn-lang-en');

  function show(lang) {
    var toId = lang === 'id';
    id.style.display = toId ? '' : 'none';
    en.style.display = toId ? 'none' : '';
    document.getElementById('meta-lang-id').style.display = toId ? '' : 'none';
    document.getElementById('meta-lang-en').style.display = toId ? 'none' : '';
    btnId.className = 'btn btn-sm px-3 ' + (toId ? 'btn-success' : 'btn-outline-success');
    btnEn.className = 'btn btn-sm px-3 ' + (toId ? 'btn-outline-success' : 'btn-success');
    document.documentElement.lang = toId ? 'id' : 'en';
  }

  btnId.addEventListener('click', function () { show('id'); });
  btnEn.addEventListener('click', function () { show('en'); });

  // Bahasa peramban Inggris membuka versi Inggris; tautan #en-... juga.
  if (/^en/i.test(navigator.language || '') || /^#en-/.test(location.hash)) show('en');
})();
</script>

<?php include 'footer.php'; ?>
