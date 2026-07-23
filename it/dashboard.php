<?php
// ahlik3/dashboard.php
$page_title = "Dashboard Keselamatan Kerja (K3)";
include "../includes/header.php";
include "../includes/sidebar.php";
include "../includes/topbar.php";
require_once "../config/koneksi.php";

// Authorization Check
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'ahli_k3') {
    header("Location: ../login.php");
    exit;
}

$current_user_id = $_SESSION['user_id'];

// Get Ahli K3 ID
try {
    $stmtAhli = $conn->prepare("SELECT id FROM Sertifikat_Ahli WHERE user_id = :user_id LIMIT 1");
    $stmtAhli->execute(['user_id' => $current_user_id]);
    $ahli_k3_id = $stmtAhli->fetchColumn() ?: 0;
} catch (PDOException $e) {
    $ahli_k3_id = 0;
}

// Queries for statistics
try {
    // Scheduled tasks
    $stmtCountJadwal = $conn->prepare("SELECT COUNT(*) FROM Jadwal_Pemeriksaan WHERE ahli_k3_id = :ahli_id AND status = 'Terjadwal'");
    $stmtCountJadwal->execute(['ahli_id' => $ahli_k3_id]);
    $countJadwal = $stmtCountJadwal->fetchColumn() ?: 0;

    // Completed inspections
    $stmtCountSelesai = $conn->prepare("SELECT COUNT(*) FROM Suket_K3 WHERE ahli_k3_id = :ahli_id AND hasil_pemeriksaan IS NOT NULL");
    $stmtCountSelesai->execute(['ahli_id' => $ahli_k3_id]);
    $countSelesai = $stmtCountSelesai->fetchColumn() ?: 0;

    // Incidents reported by this user
    $stmtCountIncidents = $conn->prepare("SELECT COUNT(*) FROM Laporan_Insiden WHERE pelapor_id = :user_id");
    $stmtCountIncidents->execute(['user_id' => $current_user_id]);
    $countIncidents = $stmtCountIncidents->fetchColumn() ?: 0;
} catch (PDOException $e) {
    $countJadwal = 0;
    $countSelesai = 0;
    $countIncidents = 0;
}

// Fetch upcoming inspections
$upcoming = [];
if ($ahli_k3_id > 0) {
    try {
        $stmtUpcoming = $conn->prepare("
            SELECT jp.*, dk.nama_perusahaan 
            FROM Jadwal_Pemeriksaan jp
            JOIN Data_Klien dk ON jp.klien_id = dk.id
            WHERE jp.ahli_k3_id = :ahli_id AND jp.status = 'Terjadwal'
            ORDER BY jp.tanggal ASC LIMIT 5
        ");
        $stmtUpcoming->execute(['ahli_id' => $ahli_k3_id]);
        $upcoming = $stmtUpcoming->fetchAll();
    } catch (PDOException $e) {
        $upcoming = [];
    }
}
?>

<main class="main-content">
    <!-- Stat Cards Section -->
    <div class="row g-4 mb-4">
        <div class="col-xl-4 col-md-6 col-12">
            <div class="stat-card">
                <div class="stat-card-info">
                    <span class="stat-card-title">Inspeksi Terjadwal</span>
                    <span class="stat-card-value"><?= $countJadwal ?> Tugas</span>
                </div>
                <div class="stat-card-icon">
                    <i class="bi bi-calendar-event-fill"></i>
                </div>
            </div>
        </div>

        <div class="col-xl-4 col-md-6 col-12">
            <div class="stat-card">
                <div class="stat-card-info">
                    <span class="stat-card-title">Pemeriksaan Selesai</span>
                    <span class="stat-card-value text-success"><?= $countSelesai ?> Laporan</span>
                </div>
                <div class="stat-card-icon success">
                    <i class="bi bi-shield-fill-check"></i>
                </div>
            </div>
        </div>

        <div class="col-xl-4 col-md-6 col-12">
            <div class="stat-card">
                <div class="stat-card-info">
                    <span class="stat-card-title">Laporan Insiden K3</span>
                    <span class="stat-card-value text-danger"><?= $countIncidents ?> Kejadian</span>
                </div>
                <div class="stat-card-icon danger">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Content Grid -->
    <div class="row g-4">
        <!-- Tables & Activity -->
        <div class="col-lg-8 col-12">
            <div class="card-box">
                <h5 class="mb-4 fw-bold">Jadwal Inspeksi Terdekat Anda (Dashboard Tugas MVP)</h5>
                
                <div class="table-responsive-custom">
                    <table class="table-custom">
                        <thead>
                            <tr>
                                <th>Tanggal Pelaksanaan</th>
                                <th>Nama Perusahaan</th>
                                <th>Lokasi Proyek</th>
                                <th>Status</th>
                                <th style="text-align: center;">Tindakan</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($upcoming) === 0): ?>
                                <tr>
                                    <td colspan="5" class="text-center py-3 text-muted">Belum ada jadwal inspeksi terdekat.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($upcoming as $up): ?>
                                    <tr>
                                        <td><strong><?= date('d M Y', strtotime($up['tanggal'])) ?></strong></td>
                                        <td><?= htmlspecialchars($up['nama_perusahaan']) ?></td>
                                        <td><?= htmlspecialchars($up['lokasi'] ?: '-') ?></td>
                                        <td><span class="badge-warning"><?= htmlspecialchars($up['status']) ?></span></td>
                                        <td style="text-align: center;">
                                            <a href="input_hasil.php" class="btn-primary-custom" style="height:32px; padding: 0 12px; font-size:0.8rem;">Mulai Uji</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Sidebar Widgets -->
        <div class="col-lg-4 col-12">
            <div class="card-box">
                <h5 class="mb-4 fw-bold">Pintasan HSE (Quick Actions)</h5>
                <div class="d-grid gap-2">
                    <a href="absensi.php" class="btn-primary-custom w-100">
                        <i class="bi bi-clock-history"></i> Absensi Hari Ini
                    </a>
                    <a href="insiden.php" class="btn-danger-custom w-100">
                        <i class="bi bi-cone-striped"></i> Laporkan Insiden K3
                    </a>
                    <a href="remburse.php" class="btn-secondary-custom w-100">
                        <i class="bi bi-cash-coin"></i> Ajukan Reimbursement
                    </a>
                </div>
            </div>
        </div>
    </div>
</main>

<?php
include "../includes/footer.php";
?>
