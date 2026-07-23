<?php
session_start();
require_once "config/koneksi.php";

$error = "";

// Simpan input lama supaya form tidak kosong ulang kalau validasi gagal
$old_role         = $_POST['role'] ?? '';
$old_nama_lengkap = $_POST['nama_lengkap'] ?? '';
$old_email        = $_POST['email'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $role                = trim($_POST['role'] ?? '');
    $nama_lengkap        = trim($_POST['nama_lengkap'] ?? '');
    $email               = trim($_POST['email'] ?? '');
    $password            = $_POST['password'] ?? '';
    $konfirmasi_password = $_POST['konfirmasi_password'] ?? '';

    $roles_valid = ['admin', 'it', 'client', 'ahli_k3', 'direksi'];

    if ($role === '' || $nama_lengkap === '' || $email === '' || $password === '' || $konfirmasi_password === '') {

        $error = "Semua field wajib diisi.";

    } elseif (!in_array($role, $roles_valid, true)) {

        $error = "Peran akun tidak valid.";

    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {

        $error = "Format email tidak valid.";

    } elseif ($password !== $konfirmasi_password) {

        $error = "Konfirmasi password tidak sama.";

    } else {

        // ========== CEK EMAIL ==========
        $stmt = $conn->prepare("SELECT id FROM Users WHERE email = ?");
        $stmt->execute([$email]);

        if ($stmt->rowCount() > 0) {

            $error = "Email sudah terdaftar.";

        } else {

            // ========== INSERT DATABASE ==========
            $password_hash = password_hash($password, PASSWORD_DEFAULT);

            try {

                $stmt = $conn->prepare("
                    INSERT INTO Users
                    (nama_lengkap, email, password, role)
                    VALUES (?, ?, ?, ?)
                ");

                $stmt->execute([
                    $nama_lengkap,
                    $email,
                    $password_hash,
                    $role
                ]);

                header("Location: login.php?registrasi=sukses");
                exit;

            } catch (PDOException $e) {
                $error = "Registrasi gagal, silakan coba lagi.";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registrasi Akun | PT Aksara Riksa Perdana</title>
    <!-- Bootstrap -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- CSS -->
    <link rel="stylesheet" href="assets/css/style.css?v=8">
</head>
<body class="login-page registration-page">
    <div class="login-bg-blobs">
        <span class="b1"></span>
        <span class="b2"></span>
        <span class="b3"></span>
    </div>
    <div class="login-wrapper registration-wrapper">

        <div class="login-brand">
            <h4>Daftar ke ARP Digital</h4>
            <p>Pilih peran, lalu lengkapi data akun Anda</p>
        </div>

        <div class="login-avatar registration-avatar">
            <svg xmlns="http://www.w3.org/2000/svg" width="38" height="38" fill="currentColor" viewBox="0 0 16 16">
                <path d="M5.338 1.59a61.44 61.44 0 0 0-2.837.856.481.481 0 0 0-.328.39c-.554 4.157.726 7.19 2.253 9.188a10.725 10.725 0 0 0 2.287 2.233c.346.244.652.42.893.533.12.057.218.095.293.118a.55.55 0 0 0 .101.025.615.615 0 0 0 .1-.025c.076-.023.174-.061.294-.118.24-.113.547-.29.893-.533a10.726 10.726 0 0 0 2.287-2.233c1.527-1.997 2.807-5.031 2.253-9.188a.48.48 0 0 0-.328-.39c-.651-.213-1.75-.56-2.837-.855C9.552 1.29 8.531 1.067 8 1.067c-.53 0-1.552.223-2.662.524zM5.072.56C6.157.265 7.31 0 8 0s1.843.265 2.928.56c1.11.3 2.229.655 2.887.87a1.54 1.54 0 0 1 1.044 1.262c.596 4.477-.787 7.795-2.465 9.99a11.775 11.775 0 0 1-2.517 2.453c-.386.273-.744.482-1.048.625-.28.132-.581.24-.829.24s-.548-.108-.829-.24a7.159 7.159 0 0 1-1.048-.625 11.777 11.777 0 0 1-2.517-2.453C1.928 10.487.545 7.169 1.141 2.692A1.54 1.54 0 0 1 2.185 1.43c.658-.215 1.777-.57 2.887-.87z"/>
            </svg>
        </div>

        <div class="login-card">

            <?php if ($error !== ""): ?>
                <div class="alert alert-danger py-2 px-3 mb-3" role="alert" style="font-size: 0.9rem;">
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <form action="registrasi.php" method="POST">

          <!-- PERAN AKUN -->
                <div class="form-field-group">
                    <label class="input-label">Peran Akun</label>

                    <div class="input-group-custom">

                        <span>
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16"
                                fill="currentColor" viewBox="0 0 16 16">
                                <path d="M8 8a3 3 0 1 0 0-6 3 3 0 0 0 0 6zm2 1H6a4 4 0 0 0-4
                                4v1a1 1 0 0 0 1 1h10a1 1 0 0 0
                                1-1v-1a4 4 0 0 0-4-4z"/>
                            </svg>
                        </span>

                        <select name="role" required>
                            <option value="" disabled <?= $old_role === '' ? 'selected' : '' ?>>Pilih Peran Akun</option>
                            <option value="admin" <?= $old_role === 'admin' ? 'selected' : '' ?>>Admin</option>
                            <option value="it" <?= $old_role === 'it' ? 'selected' : '' ?>>IT</option>
                            <option value="client" <?= $old_role === 'client' ? 'selected' : '' ?>>Client</option>
                            <option value="ahli_k3" <?= $old_role === 'ahli_k3' ? 'selected' : '' ?>>Ahli K3</option>
                            <option value="direksi" <?= $old_role === 'direksi' ? 'selected' : '' ?>>Direksi</option>
                        </select>

                    </div>
                </div>

                <!-- NAMA LENGKAP -->
                <div class="form-field-group">
                    <label class="input-label">Nama Lengkap</label>
                    <div class="input-group-custom">
                        <span>
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
                                <path d="M8 8a3 3 0 1 0 0-6 3 3 0 0 0 0 6zm2 1H6a4 4 0 0 0-4 4v1a1 1 0 0 0 1 1h10a1 1 0 0 0 1-1v-1a4 4 0 0 0-4-4z"/>
                            </svg>
                        </span>
                        <input type="text" name="nama_lengkap" placeholder="Masukkan nama lengkap" required
                            value="<?= htmlspecialchars($old_nama_lengkap) ?>">
                    </div>
                </div>

                <!-- EMAIL KARYAWAN -->
                <div class="form-field-group">
                    <label class="input-label">Email Karyawan</label>
                    <div class="input-group-custom">
                        <span>
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
                                <path d="M0 4a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2V4zm2-1a1 1 0 0 0-1 1v.217l7 4.2 7-4.2V4a1 1 0 0 0-1-1H2zm13 2.383-4.708 2.825L15 11.105V5.383zm-.034 6.876-5.64-3.471L8 9.583l-1.326-.795-5.64 3.47A1 1 0 0 0 2 13h12a1 1 0 0 0 .966-.741zM1 11.105l4.708-2.897L1 5.383v5.722z"/>
                            </svg>
                        </span>
                        <input type="email" name="email" placeholder="Masukkan email karyawan" required
                            value="<?= htmlspecialchars($old_email) ?>">
                    </div>
                </div>

                <!-- KATA SANDI -->
                <div class="form-field-group">
                    <label class="input-label">Kata Sandi</label>
                    <div class="input-group-custom password-group">
                        <span>
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
                                <path d="M8 1a2 2 0 0 1 2 2v4H6V3a2 2 0 0 1 2-2zm3 6V3a3 3 0 0 0-6 0v4a2 2 0 0 0-2 2v5a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2z"/>
                            </svg>
                        </span>
                        <input type="password" name="password" id="password" placeholder="Buat kata sandi baru" required>
                        <button type="button" class="password-toggle" onclick="togglePassword('password', this)">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16" class="eye-icon">
                                <path d="M16 8s-3-5.5-8-5.5S0 8 0 8s3 5.5 8 5.5S16 8 16 8zM1.173 8a13.133 13.133 0 0 1 1.66-2.043C4.12 4.668 5.88 3.5 8 3.5c2.12 0 3.879 1.168 5.168 2.457A13.133 13.133 0 0 1 14.828 8c-.058.087-.122.183-.195.288-.335.48-.83 1.12-1.465 1.755C11.879 11.332 10.119 12.5 8 12.5c-2.12 0-3.879-1.168-5.168-2.457A13.134 13.134 0 0 1 1.172 8z"/>
                                <path d="M8 5.5a2.5 2.5 0 1 0 0 5 2.5 2.5 0 0 0 0-5zM4.5 8a3.5 3.5 0 1 1 7 0 3.5 3.5 0 0 1-7 0z"/>
                            </svg>
                        </button>
                    </div>
                </div>

                <!-- KONFIRMASI SANDI -->
                <div class="form-field-group">
                    <label class="input-label">Konfirmasi Sandi</label>
                    <div class="input-group-custom password-group">
                        <span>
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
                                <path d="M8 1a2 2 0 0 1 2 2v4H6V3a2 2 0 0 1 2-2zm3 6V3a3 3 0 0 0-6 0v4a2 2 0 0 0-2 2v5a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2z"/>
                            </svg>
                        </span>
                        <input type="password" name="konfirmasi_password" id="konfirmasi_password" placeholder="Ulangi kata sandi baru" required>
                        <button type="button" class="password-toggle" onclick="togglePassword('konfirmasi_password', this)">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16" class="eye-icon">
                                <path d="M16 8s-3-5.5-8-5.5S0 8 0 8s3 5.5 8 5.5S16 8 16 8zM1.173 8a13.133 13.133 0 0 1 1.66-2.043C4.12 4.668 5.88 3.5 8 3.5c2.12 0 3.879 1.168 5.168 2.457A13.133 13.133 0 0 1 14.828 8c-.058.087-.122.183-.195.288-.335.48-.83 1.12-1.465 1.755C11.879 11.332 10.119 12.5 8 12.5c-2.12 0-3.879-1.168-5.168-2.457A13.134 13.134 0 0 1 1.172 8z"/>
                                <path d="M8 5.5a2.5 2.5 0 1 0 0 5 2.5 2.5 0 0 0 0-5zM4.5 8a3.5 3.5 0 1 1 7 0 3.5 3.5 0 0 1-7 0z"/>
                            </svg>
                        </button>
                    </div>
                </div>

                <button type="submit" class="btn-login-submit">
                    DAFTAR SEKARANG
                </button>

                <div class="login-register">
                    <span>Sudah punya akun?</span>
                    <a href="login.php">Masuk di sini</a>
                </div>

            </form>
        </div>

        <a href="index.php" class="login-back">
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="currentColor" viewBox="0 0 16 16" class="login-back-icon">
                <path fill-rule="evenodd" d="M15 8a.5.5 0 0 0-.5-.5H2.707l3.147-3.146a.5.5 0 1 0-.708-.708l-4 4a.5.5 0 0 0 0 .708l4 4a.5.5 0 0 0 .708-.708L2.707 8.5H14.5A.5.5 0 0 0 15 8z"/>
            </svg>
            Kembali ke Beranda
        </a>

    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function togglePassword(inputId, button) {
            const input = document.getElementById(inputId);
            const icon = button.querySelector('.eye-icon');
            if (input.type === 'password') {
                input.type = 'text';
                icon.innerHTML = '<path d="M13.359 11.238C15.06 9.72 16 8 16 8s-3-5.5-8-5.5a7.028 7.028 0 0 0-2.79.588l.77.771A5.944 5.944 0 0 1 8 3.5c2.12 0 3.879 1.168 5.168 2.457A13.134 13.134 0 0 1 14.828 8c-.058.087-.122.183-.195.288-.335.48-.83 1.12-1.465 1.755-.165.165-.337.328-.517.486l.708.709z"/><path d="M11.297 9.176a3.5 3.5 0 0 0-4.474-4.474l.823.823a2.5 2.5 0 0 1 2.829 2.829l.822.822zm-2.943 1.299.822.822a3.5 3.5 0 0 1-4.474-4.474l.823.823a2.5 2.5 0 0 0 2.829 2.829z"/><path d="M3.35 5.47c-.18.16-.353.322-.518.487A13.134 13.134 0 0 0 1.172 8l.195.288c.335.48.83 1.12 1.465 1.755C4.121 11.332 5.881 12.5 8 12.5c.716 0 1.39-.133 2.02-.36l.77.772A7.029 7.029 0 0 1 8 13.5C3 13.5 0 8 0 8s.939-1.721 2.641-3.238l.708.709zm10.296 8.884-12-12 .708-.708 12 12-.708.708z"/>';
            } else {
                input.type = 'password';
                icon.innerHTML = '<path d="M16 8s-3-5.5-8-5.5S0 8 0 8s3 5.5 8 5.5S16 8 16 8zM1.173 8a13.133 13.133 0 0 1 1.66-2.043C4.12 4.668 5.88 3.5 8 3.5c2.12 0 3.879 1.168 5.168 2.457A13.133 13.133 0 0 1 14.828 8c-.058.087-.122.183-.195.288-.335.48-.83 1.12-1.465 1.755C11.879 11.332 10.119 12.5 8 12.5c-2.12 0-3.879-1.168-5.168-2.457A13.134 13.134 0 0 1 1.172 8z"/><path d="M8 5.5a2.5 2.5 0 1 0 0 5 2.5 2.5 0 0 0 0-5zM4.5 8a3.5 3.5 0 1 1 7 0 3.5 3.5 0 0 1-7 0z"/>';
            }
        }
    </script>
</body>
</html>