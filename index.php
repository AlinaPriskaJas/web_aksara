<!DOCTYPE html>
<html lang="id">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>PT Aksara Riksa Perdana</title>

    <!-- Bootstrap -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/css/bootstrap.min.css" rel="stylesheet">

    <!-- CSS -->
    <link rel="stylesheet" href="assets/css/style.css">

</head>

<body class="landing-page">

    <?php require "includes/navbar.php"; ?>

    <!-- Hero -->

    <section class="hero">

        <div class="container">

            <div class="row align-items-center">

                <div class="col-lg-6">

                    <div class="badge-custom">

                        Platform Digital Terintegrasi

                    </div>

                    <h1>

                        Satu Sistem Untuk Kelola Seluruh Proses K3

                    </h1>

                    <p>

                        Database klien, sertifikat digital,
                        jadwal pemeriksaan, approval,
                        hingga arsip perusahaan dalam satu platform.

                    </p>

                    <a href="login.php" class="btn btn-main">

                        Masuk ke Sistem

                    </a>

                    <a href="#" class="btn btn-second">

                        Lihat Fitur

                    </a>

                </div>

                <div class="col-lg-6">

                    <div class="preview">

                        <div class="preview-slides">
                            <?php
                                $folder = "assets/img/ahliK3/";
                                $foto = glob($folder . "*.{jpg,jpeg,png,webp,JPG,JPEG,PNG,WEBP}", GLOB_BRACE);
                                $i = 0;
                                foreach ($foto as $file):
                                    $activeClass = ($i === 0) ? "active" : "";
                            ?>
                                <div class="preview-slide <?= $activeClass ?>" style="background-image:url('<?= $file ?>')"></div>
                            <?php
                                    $i++;
                                endforeach;
                            ?>
                        </div>

                    </div>

                </div>

            </div>

        </div>

    </section>

    <!-- Statistik -->

    <section class="info-box">

        <div class="container-fluid">

            <div class="row">

                <div class="col-md-3 info-item">

                    <h2>5 Role</h2>

                    <p>Akses Sesuai Role</p>

                </div>

                <div class="col-md-3 info-item">

                    <h2>100%</h2>

                    <p>Digital</p>

                </div>

                <div class="col-md-3 info-item">

                    <h2>Realtime</h2>

                    <p>Monitoring</p>

                </div>

                <div class="col-md-3 info-item">

                    <h2>1 Platform</h2>

                    <p>Terintegrasi</p>

                </div>

            </div>

        </div>

    </section>

    <!-- ================= ROLE ================= -->

    <section class="role-section">

        <div class="container">

            <h5 class="role-subtitle">
                Akses sistem
            </h5>

            <h2 class="role-title">
                Masuk sesuai peran anda
            </h2>

            <p class="role-desc">
                Enam peran dengan akses dan kemampuan berbeda di dalam sistem.
            </p>

            <div class="row g-4 mt-2">

                <!-- Admin -->

                <div class="col-lg-4">

                    <div class="role-card">

                    <div class="role-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor" viewBox="0 0 16 16">
                            <path d="M8 0c-.69 0-1.843.265-2.928.56-1.11.3-2.229.655-2.887.87a1.54 1.54 0 0 0-1.044 1.262c-.596 4.477.787 7.795 2.465 9.99a11.777 11.777 0 0 0 2.517 2.453c.386.273.744.482 1.048.625.28.132.581.24.829.24s.548-.108.829-.24a7.159 7.159 0 0 0 1.048-.625 11.775 11.775 0 0 0 2.517-2.453c1.678-2.195 3.061-5.513 2.465-9.99a1.541 1.541 0 0 0-1.044-1.263 62.467 62.467 0 0 0-2.887-.87C9.843.266 8.69 0 8 0zm0 5a1.5 1.5 0 0 1 .5 2.915l.385 1.99a.5.5 0 0 1-.491.595h-.788a.5.5 0 0 1-.49-.595l.384-1.99A1.5 1.5 0 0 1 8 5z"/>
                        </svg>
                    </div>

                        <h4>Admin</h4>

                        <ul>
                            <li>Kelola data klien dan unit</li>
                            <li>Atur jadwal pemeriksaan</li>
                            <li>Terbitkan sertifikat digital</li>
                            <li>Kelola akun pengguna</li>
                        </ul>

                        <a href="login.php" class="btn-role">
                            Masuk sebagai Admin
                        </a>

                    </div>

                </div>

                <!-- Ahli K3 -->

                <div class="col-lg-4">

                    <div class="role-card">

                    <div class="role-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M11 3.05V5a1 1 0 0 0 2 0V3.05A8.001 8.001 0 0 1 20 11v1h1a1 1 0 0 1 1 1v1a1 1 0 0 1-1 1H2a1 1 0 0 1-1-1v-1a1 1 0 0 1 1-1h1v-1a8.001 8.001 0 0 1 8-7.95zM4 15h16v1a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2v-1z"/>
                        </svg>
                    </div>

                        <h4>Ahli K3</h4>

                        <ul>
                            <li>Lihat jadwal pemeriksaan</li>
                            <li>Input hasil pemeriksaan</li>
                            <li>Unggah dokumentasi</li>
                            <li>Ajukan rekomendasi teknis</li>
                        </ul>

                        <a href="login.php" class="btn-role">
                            Masuk sebagai Ahli K3
                        </a>

                    </div>

                </div>

                <!-- IT -->

                <div class="col-lg-4">

                    <div class="role-card">

                    <div class="role-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor" viewBox="0 0 16 16">
                            <path d="M13.5 3a.5.5 0 0 1 .5.5V11H2V3.5a.5.5 0 0 1 .5-.5h11zm-11-1A1.5 1.5 0 0 0 1 3.5V12h14V3.5A1.5 1.5 0 0 0 13.5 2h-11zM0 12.5h16a1.5 1.5 0 0 1-1.5 1.5h-13A1.5 1.5 0 0 1 0 12.5zM6.646 5.646a.5.5 0 1 1 .708.708L5.707 8l1.647 1.646a.5.5 0 0 1-.708.708l-2-2a.5.5 0 0 1 0-.708l2-2zm2.708 0a.5.5 0 1 0-.708.708L10.293 8 8.646 9.646a.5.5 0 0 0 .708.708l2-2a.5.5 0 0 0 0-.708l-2-2z"/>
                        </svg>
                    </div>

                        <h4>IT</h4>

                        <ul>
                            <li>Kelola infrastruktur</li>
                            <li>Backup database</li>
                            <li>Kelola akun</li>
                            <li>Pantau keamanan</li>
                        </ul>

                        <a href="login.php" class="btn-role">
                            Masuk sebagai IT
                        </a>

                    </div>

                </div>

            </div>

            <div class="row justify-content-center g-4 mt-2">

                <!-- Client -->

                <div class="col-lg-4">

                    <div class="role-card">

                    <div class="role-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor" viewBox="0 0 16 16">
                            <path d="M14.763.075A.5.5 0 0 1 15 .5v15a.5.5 0 0 1-.5.5h-4a.5.5 0 0 1-.5-.5v-2.5a2 2 0 1 0-4 0V15a.5.5 0 0 1-.5.5h-4a.5.5 0 0 1-.5-.5V7.5a.5.5 0 0 1 .342-.474L6 5.223V3.5a.5.5 0 0 1 .342-.474l8-2.75a.5.5 0 0 1 .421.799zM10 7a.5.5 0 0 0-.5.5v.01a.5.5 0 0 0 1 0V7.5A.5.5 0 0 0 10 7zm-3 0a.5.5 0 0 0-.5.5v.01a.5.5 0 0 0 1 0V7.5A.5.5 0 0 0 7 7z"/>
                        </svg>
                    </div>

                        <h4>Client</h4>

                        <ul>
                            <li>Ajukan pemeriksaan</li>
                            <li>Pantau status</li>
                            <li>Unduh sertifikat</li>
                            <li>Riwayat pemeriksaan</li>
                        </ul>

                        <a href="login.php" class="btn-role">
                            Masuk sebagai Client
                        </a>

                    </div>

                </div>

                <!-- Direksi -->

                <div class="col-lg-4">

                    <div class="role-card">

                    <div class="role-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor" viewBox="0 0 16 16">
                            <path d="M8 8a3 3 0 1 0 0-6 3 3 0 0 0 0 6zm2 1H6a4 4 0 0 0-4 4v1a1 1 0 0 0 1 1h10a1 1 0 0 0 1-1v-1a4 4 0 0 0-4-4zM8 4.5 9 6l-.5 3h-1L7 6l1-1.5z"/>
                        </svg>
                    </div>

                        <h4>Direksi</h4>

                        <ul>
                            <li>Laporan perusahaan</li>
                            <li>Statistik pemeriksaan</li>
                            <li>Approval</li>
                            <li>Ringkasan divisi</li>
                        </ul>

                        <a href="login.php" class="btn-role">
                            Masuk sebagai Direksi
                        </a>

                    </div>

                </div>

            </div>

        </div>

    </section>

    <footer>

        © 2026 PT Aksara Riksa Perdana

    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.bundle.min.js"></script>

    <script src="assets/js/script.js"></script>

</body>

</html>