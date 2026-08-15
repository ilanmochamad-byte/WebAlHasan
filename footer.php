<footer class="text-white text-center text-lg-start">
    <div class="container p-4">
        <div class="row mt-4">
            <div class="col-lg-4 col-md-12 mb-4 mb-md-0">
                <h5 class="text-uppercase mb-4">PP Al Hasan Ciamis</h5>
                <p>
                    Mencetak generasi yang berilmu amaliah, beramal ilmiah, dan berakhlakul karimah berlandaskan Ahlussunnah wal Jamaah.
                </p>
            </div>
            <div class="col-lg-4 col-md-6 mb-4 mb-md-0">
                <h5 class="text-uppercase mb-4">Kontak Kami</h5>
                <ul class="fa-ul" style="margin-left: 1.65em;">
                    <li class="mb-3">
                        <span class="fa-li"><i class="fas fa-home"></i></span><span class="ms-2">Jalan Jenderal Ahmad Yani No. 120 Ciamis Jawa Barat 46213</span>
                    </li>
                    <li class="mb-3">
                        <span class="fa-li"><i class="fas fa-envelope"></i></span><span class="ms-2">alhasanpesantren@gmail.com</span>
                    </li>
                    <li class="mb-3">
                        <span class="fa-li"><i class="fas fa-phone"></i></span><span class="ms-2">+62 812 3456 7890</span>
                    </li>
                </ul>
            </div>
            <div class="col-lg-4 col-md-6 mb-4 mb-md-0">
                <h5 class="text-uppercase mb-4">Jam Operasional</h5>
                <table class="table text-center text-white">
                    <tbody class="fw-normal">
                        <tr>
                            <td>Senin - Kamis:</td>
                            <td>08.00 - 16.00</td>
                        </tr>
                        <tr>
                            <td>Jumat - Minggu:</td>
                            <td>Libur / Kegiatan Pesantren</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="text-center p-3" style="background-color: rgba(0, 0, 0, 0.2);">
        © <?php echo date("Y"); ?> Copyright: PP Al Hasan Ciamis
    </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>
<script>
    // Inisialisasi Animasi AOS
    AOS.init();

    // Script untuk Navbar berubah warna saat di-scroll
    const navEl = document.querySelector('.navbar');
    window.addEventListener('scroll', () => {
        if (window.scrollY >= 56) {
            navEl.classList.add('scrolled');
        } else if (window.scrollY < 56) {
            navEl.classList.remove('scrolled');
        }
    });
</script>
</body>
</html>