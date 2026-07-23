<?php
session_start();
require_once "config/koneksi.php";

$error = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($email === '' || $password === '') {

        $error = "Email dan password wajib diisi.";

    } else {

        $stmt = $conn->prepare("SELECT * FROM Users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($password, $user['password'])) {

            // ========== SIMPAN SESSION ==========
            $_SESSION['login']        = true;
            $_SESSION['user_id']      = $user['id'];
            $_SESSION['nama_lengkap'] = $user['nama_lengkap'];
            $_SESSION['email']        = $user['email'];
            $_SESSION['role']         = $user['role'];
            $_SESSION['foto_profil']  = $user['foto_profil'] ?? null;
            // Kalau role client, ambil nama perusahaan untuk dipakai sebagai inisial avatar
            $_SESSION['nama_perusahaan'] = null;
            if ($user['role'] === 'client') {
                $klienStmt = $conn->prepare("SELECT nama_perusahaan FROM Data_Klien WHERE user_id = :uid LIMIT 1");
                $klienStmt->execute(['uid' => $user['id']]);
                $klienRow = $klienStmt->fetch();
                $_SESSION['nama_perusahaan'] = $klienRow['nama_perusahaan'] ?? null;
            } 

            // ========== REDIRECT BERDASARKAN ROLE ==========
            switch ($user['role']) {
                case 'admin':
                    header("Location: admin/dashboard.php");
                    exit;
                case 'it':
                    header("Location: it/dashboard.php");
                    exit;
                case 'client':
                    header("Location: client/dashboard.php");
                    exit;
                case 'ahli_k3':
                    header("Location: ahlik3/dashboard.php");
                    exit;
                case 'direksi':
                    header("Location: direksi/dashboard.php");
                    exit;
                default:
                    session_destroy();
                    $error = "Role tidak dikenali.";
                    break;
            }

        } elseif ($user) {
            $error = "Password salah.";
        } else {
            $error = "Email tidak ditemukan.";
        }
    }
}
?>
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

            <?php if ($error !== ""): ?>
                <div class="alert alert-danger py-2 px-3 mb-3" role="alert" style="font-size: 0.9rem;">
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <form action="login.php" method="POST">

                <div class="input-group-custom">
                    <span><i class="fa-solid fa-envelope"></i></span>
                    <input type="email" name="email" placeholder="Email ID" required
                        value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
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