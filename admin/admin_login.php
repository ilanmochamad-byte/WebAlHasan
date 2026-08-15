<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login Admin - PP Al Hasan</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background-color: #f0f2f5; height: 100vh; display: flex; align-items: center; justify-content: center; }
        .login-card { width: 100%; max-width: 400px; padding: 40px; border-radius: 15px; background: white; box-shadow: 0 10px 25px rgba(0,0,0,0.1); }
        .btn-login { background-color: #0f5132; color: white; width: 100%; padding: 10px; border-radius: 8px; font-weight: bold; }
        .btn-login:hover { background-color: #0a3d25; color: white; }
    </style>
</head>
<body>
    <div class="login-card">
        <div class="text-center mb-4">
            <h4 class="fw-bold text-success">Admin Panel</h4>
            <p class="text-muted">Website Pesantren Al Hasan Ciamis</p>
        </div>
        
        <?php if(isset($_GET['pesan']) && $_GET['pesan'] == 'gagal'){ ?>
            <div class="alert alert-danger text-center small">Username / Password Salah!</div>
        <?php } ?>

        <form action="cek_login.php" method="POST">
            <div class="mb-3">
                <label class="form-label">Username</label>
                <input type="text" name="username" class="form-control" required autofocus>
            </div>
            <div class="mb-3">
                <label class="form-label">Password</label>
                <input type="password" name="password" class="form-control" required>
            </div>
            <button type="submit" class="btn btn-login">MASUK</button>
            <div class="text-center mt-3">
                <a href="index.php" class="text-decoration-none small text-muted">← Kembali ke Website Utama</a>
            </div>
        </form>
    </div>
</body>
</html>