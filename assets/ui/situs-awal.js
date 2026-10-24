// Redesain Tahap 8: tandai bahwa JavaScript aktif sebelum halaman digambar,
// agar tampilan tanpa JavaScript (semua langkah/bagian terbuka) tidak berkedip.
document.documentElement.className = document.documentElement.className.replace('s-nojs', 's-js');
