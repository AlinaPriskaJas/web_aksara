<?php
// direksi/insiden.php
$page_title = "Insiden Direksi";
include "../includes/header.php";
include "../includes/sidebar.php";
include "../includes/navbar.php";
?>

<main class="main-content">
    <div class="card-box">
        <h5 class="fw-bold mb-3"><?= htmlspecialchars($page_title) ?></h5>
        <div class="alert alert-success-custom mb-3">
            <i class="bi bi-info-circle-fill fs-5"></i>
            <div>
                <strong>Modul Aktif</strong><br>
                Halaman ini memuat master layout yang konsisten untuk <strong>PT Aksara Riksa Perdana</strong>.
            </div>
        </div>
        <p class="text-secondary mb-0">Silakan kembangkan fungsionalitas halaman ini di file <code>direksi/insiden.php</code> sesuai dengan kebutuhan modul.</p>
    </div>
</main>

<?php
include "../includes/footer.php";
?>
