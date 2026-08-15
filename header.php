<?php
// Deteksi nama file halaman saat ini
$page_name = basename($_SERVER['PHP_SELF']);

// Logika: 
// Jika halamannya BUKAN 'index.php' DAN BUKAN kosong (root), 
// maka tambahkan class 'navbar-stay-white' dan dorong body ke bawah
if ($page_name != "index.php" && $page_name != "") {
    $class_nav = "navbar-stay-white shadow-sm"; 
    $body_style = "style='padding-top: 30px;'"; 
} else {
    $class_nav = "";
    $body_style = ""; 
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pondok Pesantren Al Hasan Ciamis</title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="style.css?v=<?php echo time(); ?>"> 
    <link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">
</head>
<body <?php echo $body_style; ?>>

<nav class="navbar navbar-expand-lg fixed-top <?php echo $class_nav; ?>">
    <div class="container">
        <a class="navbar-brand fw-bold" href="index.php">
        <img src="logo_alhasan.png" alt="Logo Pesantren" height="40" class="me-2"> PESANTREN AL HASAN CIAMIS
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav ms-auto align-items-center">
                <li class="nav-item"><a class="nav-link" href="index.php">Beranda</a></li>
                <li class="nav-item"><a class="nav-link" href="berita.php">Kabar Pesantren</a></li>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">Profil</a>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="#">Sejarah Pesantren</a></li>
                        <li><a class="dropdown-item" href="#">Visi dan Misi</a></li>
                        <li><a class="dropdown-item" href="#">Struktur Yayasan</a></li>
                        <li><a class="dropdown-item" href="#">Struktur Badan Koordinasi Pengurus Pesantren</a></li>
                        <li><a class="dropdown-item" href="#">Struktur Badan Pengurus Santri</a></li>
                        <li><a class="dropdown-item" href="#">Dewan Pengajar</a></li>
                        <li><a class="dropdown-item" href="jadwal_ngaji.php">Jadwal Pengajian</a></li>
                    </ul>
                </li>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">Unit Pendidikan</a>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="#">RA Terpadu Al Hasan</a></li>
                        <li><a class="dropdown-item" href="#">MI Daar el-Qolam</a></li>
                        <li><a class="dropdown-item" href="https://smpt.alhasan.co.id">SMP Terpadu Al Hasan</a></li>
                        <li><a class="dropdown-item" href="https://smkt.alhasan.co.id">SMK Terpadu Al hasan</a></li>
                    </ul>
                </li>
                <li class="nav-item"><a class="nav-link" href="galeri.php">Galeri</a></li>
                <li class="nav-item"><a class="nav-link" href="download.php">Download</a></li>
                
                <li class="nav-item">
                    <a class="btn btn-daftar ms-2 text-white" href="psb.php">PSB Online</a>
                </li>
            </ul>
        </div>
    </div>
</nav>