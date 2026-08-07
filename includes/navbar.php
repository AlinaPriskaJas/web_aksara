<?php $current_page = basename($_SERVER['PHP_SELF']); ?>
<!-- Topbar -->
<div class="topbar">

    <div class="container">

        <div class="row">

            <div class="col-md-6">

                <i class="fa-solid fa-location-dot"></i>
                Bandung, Jawa Barat

            </div>

            <div class="col-md-6 text-end">

                <i class="fa-solid fa-envelope"></i>
                info@arp.co.id

                &nbsp;

                <i class="fa-solid fa-phone"></i>
                (022) 000-0000

            </div>

        </div>

    </div>

</div>


<!-- Navbar -->

<nav class="navbar navbar-expand-lg">

    <div class="container">

        <a class="navbar-brand d-flex align-items-center" href="index.">

                    <img src="assets/img/logo.png"
                        alt="Logo PT Aksara Riksa Perdana"
                        class="logo-img">

                    <div class="ms-3">

                        <div class="brand-title">
                            Aksara Riksa Perdana
                        </div>

                        <div class="brand-sub">
                            Pemeriksaan & Pengujian K3
                        </div>

                    </div>

        </a>


        <button
            class="navbar-toggler"
            type="button"
            data-bs-toggle="collapse"
            data-bs-target="#menu">

            <span class="navbar-toggler-icon"></span>

        </button>


        <div class="collapse navbar-collapse" id="menu">

            <ul class="navbar-nav ms-auto align-items-center">

                <li class="nav-item">
               
                <a class="nav-link <?php echo ($current_page == 'index.php') ? 'active' : ''; ?>" href="index.php">
                    Beranda
                </a>

                </li>

                <li class="nav-item">

                <a class="nav-link <?php echo ($current_page == 'tentang.php') ? 'active' : ''; ?>" href="tentang.php">
                     Tentang
                </a>

                </li>

              
                <li class="nav-item">

                <a class="nav-link <?php echo ($current_page == 'kontak.php') ? 'active' : ''; ?>" href="kontak.php">
                        Kontak
                </a>

                </li>

                <li class="nav-item ms-2">

                    <a href="login.php" class="btn btn-login">

                        Masuk

                    </a>

                </li>

            </ul>

        </div>

    </div>

</nav>