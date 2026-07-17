<!DOCTYPE html>
<html lang="id">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Masuk ke Sistem | PT Aksara Riksa Perdana</title>

    <!-- Bootstrap -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/css/bootstrap.min.css" rel="stylesheet">

    <!-- Font Awesome -->
    <link rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">

    <!-- CSS -->
    <link rel="stylesheet" href="assets/css/style.css?v=7">

</head>

<body class="login-page">

    <div class="login-bg-blobs">
        <span class="b1"></span>
        <span class="b2"></span>
        <span class="b3"></span>
    </div>

    <div class="login-wrapper">

        <div class="login-brand">
            
            <h4>PT Aksara Riksa Perdana</h4>
            <p>Masuk ke ARP Digital</p>
        </div>

        <div class="login-avatar">
            <svg xmlns="http://www.w3.org/2000/svg" width="38" height="38" fill="currentColor" viewBox="0 0 16 16">
                <path d="M8 8a3 3 0 1 0 0-6 3 3 0 0 0 0 6zm2 1H6a4 4 0 0 0-4 4v1a1 1 0 0 0 1 1h10a1 1 0 0 0 1-1v-1a4 4 0 0 0-4-4z"/>
            </svg>
        </div>

        <div class="login-card">

            <form action="proses_login.php" method="POST">

                <div class="input-group-custom">
                    <span><i class="fa-solid fa-envelope"></i></span>
                    <input type="email" name="email" placeholder="Email ID" required>
                </div>

                <div class="input-group-custom">
                    <span><i class="fa-solid fa-lock"></i></span>
                    <input type="password" name="password" placeholder="Password" required>
                </div>

                <div class="login-options">
                    <label>
                        <input type="checkbox" name="remember">
                        Ingat saya
                    </label>
                    <a href="lupa_password.php">Lupa Password?</a>
                </div>

                <button type="submit" class="btn-login-submit">
                    MASUK
                </button>

            </form>

        </div>

        <a href="index.php" class="login-back">
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="currentColor" viewBox="0 0 16 16" class="login-back-icon">
                <path fill-rule="evenodd" d="M15 8a.5.5 0 0 0-.5-.5H2.707l3.147-3.146a.5.5 0 1 0-.708-.708l-4 4a.5.5 0 0 0 0 .708l4 4a.5.5 0 0 0 .708-.708L2.707 8.5H14.5A.5.5 0 0 0 15 8z"/>
            </svg>
            Kembali ke Beranda
        </a>

        <div class="login-register">
            <span>Jika belum punya akun, </span><a href="registrasi.php">daftar di sini</a>
        </div>

    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.bundle.min.js"></script>

</body>

</html>