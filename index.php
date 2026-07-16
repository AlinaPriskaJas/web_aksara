<?php
// index.php
$page_title = "Portal Internal - PT Aksara Riksa Perdana";

// Simple logout parameter mock
if (isset($_GET['logout'])) {
    $logout_message = "Anda telah berhasil logout dari sistem.";
}

include "includes/header.php";
?>

<style>
    .portal-container {
        display: flex;
        align-items: center;
        justify-content: center;
        min-height: 100vh;
        width: 100%;
        padding: 40px var(--padding);
        background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
    }
    .portal-card {
        background-color: #ffffff;
        border-radius: var(--radius);
        box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.3), 0 10px 10px -5px rgba(0, 0, 0, 0.2);
        max-width: 900px;
        width: 100%;
        padding: 40px;
        text-align: center;
    }
    .portal-logo {
        color: var(--primary);
        font-size: 3rem;
        margin-bottom: 10px;
    }
    .portal-title {
        font-size: 1.8rem;
        font-weight: bold;
        color: var(--text-primary);
        margin-bottom: 6px;
    }
    .portal-subtitle {
        font-size: 1rem;
        color: var(--text-secondary);
        margin-bottom: 40px;
    }
    .role-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
        gap: 20px;
        margin-bottom: 20px;
    }
    .role-card {
        background-color: var(--bg-body);
        border: 1px solid var(--border-color);
        border-radius: var(--radius);
        padding: 24px;
        text-decoration: none !important;
        transition: all var(--transition);
        display: flex;
        flex-direction: column;
        align-items: center;
        cursor: pointer;
    }
    .role-card:hover {
        transform: translateY(-5px);
        border-color: var(--primary);
        box-shadow: var(--shadow);
        background-color: #ffffff;
    }
    .role-icon {
        width: 60px;
        height: 60px;
        border-radius: var(--radius);
        background-color: rgba(27, 99, 196, 0.1);
        color: var(--primary);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.8rem;
        margin-bottom: 16px;
        transition: all var(--transition);
    }
    .role-card:hover .role-icon {
        background-color: var(--primary);
        color: #ffffff;
    }
    .role-name {
        font-size: 1.1rem;
        font-weight: bold;
        color: var(--text-primary);
        margin-bottom: 6px;
    }
    .role-desc {
        font-size: 0.8rem;
        color: var(--text-secondary);
        text-align: center;
    }
</style>

<div class="portal-container">
    <div class="portal-card">
        <!-- Logo -->
        <div class="portal-logo">
            <i class="bi bi-shield-fill-check"></i>
        </div>
        <h1 class="portal-title">PT Aksara Riksa Perdana</h1>
        <p class="portal-subtitle">Enterprise Internal Management System Portal</p>
        
        <?php if (isset($logout_message)): ?>
            <div class="alert alert-success-custom align-items-center justify-content-center mb-4">
                <i class="bi bi-check-circle-fill fs-5"></i>
                <div><?php echo htmlspecialchars($logout_message); ?></div>
            </div>
        <?php endif; ?>

        <div class="role-grid">
            <!-- Admin Role Card -->
            <a href="admin/dashboard.php" class="role-card">
                <div class="role-icon">
                    <i class="bi bi-person-workspace"></i>
                </div>
                <div class="role-name">Administrator Portal</div>
                <div class="role-desc">Persetujuan, persuratan, stok gudang, transportasi, & pengelolaan database internal.</div>
            </a>

            <!-- Direksi Role Card -->
            <a href="direksi/dashboard.php" class="role-card">
                <div class="role-icon">
                    <i class="bi bi-graph-up-arrow"></i>
                </div>
                <div class="role-name">Direksi Portal</div>
                <div class="role-desc">Monitoring operasional, peninjauan laporan eksekutif, dan dokumen digital direksi.</div>
            </a>

            <!-- IT Role Card -->
            <a href="it/dashboard.php" class="role-card">
                <div class="role-icon">
                    <i class="bi bi-laptop-fill"></i>
                </div>
                <div class="role-name">IT Administrator</div>
                <div class="role-desc">Pengelolaan pengguna, audit sistem keamanan, backup data, dan konfigurasi server.</div>
            </a>

            <!-- Ahli K3 Role Card -->
            <a href="ahlik3/dashboard.php" class="role-card">
                <div class="role-icon">
                    <i class="bi bi-heart-pulse-fill text-danger"></i>
                </div>
                <div class="role-name">Ahli K3 Portal</div>
                <div class="role-desc">Jadwal inspeksi keselamatan, upload temuan lapangan, dan input rekomendasi K3.</div>
            </a>

            <!-- Client Role Card -->
            <a href="client/dashboard.php" class="role-card">
                <div class="role-icon">
                    <i class="bi bi-building"></i>
                </div>
                <div class="role-name">Client Portal</div>
                <div class="role-desc">Pengajuan layanan K3, tracking status pengerjaan, dan pengunduhan sertifikat.</div>
            </a>
        </div>
    </div>
</div>

<?php
// Simple footer inclusion with minimal JS
?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
