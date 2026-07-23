<?php
// it/dashboard.php
require_once "../config/koneksi.php";

if (session_status() === PHP_SESSION_NONE)
    session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'it') {
    header("Location: ../login.php");
    exit;
}

$page_title = "Dashboard IT Support";
include "../includes/header.php";
include "../includes/sidebar.php";
include "../includes/topbar.php";

$totalUsers = $conn->query("SELECT COUNT(*) FROM Users")->fetchColumn() ?: 0;

// Estimasi ukuran database (storage terpakai)
try {
    $dbRow = $conn->query("SELECT DATABASE() AS db")->fetch();
    $sizeRow = $conn->prepare("SELECT ROUND(SUM(DATA_LENGTH + INDEX_LENGTH) / 1024 / 1024, 2) AS size_mb FROM information_schema.TABLES WHERE TABLE_SCHEMA = :db");
    $sizeRow->execute(['db' => $dbRow['db']]);
    $dbSizeMB = $sizeRow->fetch()['size_mb'] ?: 0;
} catch (PDOException $e) {
    $dbSizeMB = 0;
}

// Aktivitas terbaru
$recentLogs = [];
try {
    $recentLogs = $conn->query("
        SELECT al.*, u.nama_lengkap, u.role
        FROM Audit_Log al
        JOIN Users u ON al.user_id = u.id
        ORDER BY al.waktu_kejadian DESC
        LIMIT 6
    ")->fetchAll();
} catch (PDOException $e) {
    $recentLogs = [];
}

// Status backup terakhir
$backup_dir = "../backups/";
$log_file = $backup_dir . "backup_log.json";
$lastBackup = null;
if (file_exists($log_file)) {
    $backupData = json_decode(file_get_contents($log_file), true);
    if (is_array($backupData) && count($backupData) > 0) {
        $lastBackup = $backupData[0];
    }
}
?>

<main class="main-content">
    <!-- Stat Cards Section -->
    <div class="row g-4 mb-4">
        <div class="col-xl-3 col-md-6 col-12">
            <div class="stat-card">
                <div class="stat-card-info">
                    <span class="stat-card-title">Server Uptime</span>
                    <span class="stat-card-value">99.98%</span>
                </div>
                <div class="stat-card-icon success">
                    <i class="bi bi-hdd-network-fill"></i>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6 col-12">
            <div class="stat-card">
                <div class="stat-card-info">
                    <span class="stat-card-title">Total Users</span>
                    <span class="stat-card-value"><?= number_format($totalUsers, 0, ',', '.') ?></span>
                </div>
                <div class="stat-card-icon">
                    <i class="bi bi-people-fill"></i>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6 col-12">
            <div class="stat-card">
                <div class="stat-card-info">
                    <span class="stat-card-title">Ukuran Database</span>
                    <span class="stat-card-value"><?= number_format($dbSizeMB, 1, ',', '.') ?> MB</span>
                </div>
                <div class="stat-card-icon warning">
                    <i class="bi bi-server"></i>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6 col-12">
            <div class="stat-card">
                <div class="stat-card-info">
                    <span class="stat-card-title">Ancaman Keamanan</span>
                    <span class="stat-card-value">0</span>
                </div>
                <div class="stat-card-icon danger">
                    <i class="bi bi-shield-fill-check"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Content Grid -->
    <div class="row g-4">
        <!-- Tables & Activity -->
        <div class="col-lg-8 col-12">
            <div class="card-box">
                <h5 class="mb-4 fw-bold">Log Aktivitas Sistem Terbaru</h5>

                <div class="table-responsive-custom">
                    <table class="table-custom">
                        <thead>
                            <tr>
                                <th>No</th>
                                <th>Pengguna</th>
                                <th>Modul</th>
                                <th>Tindakan</th>
                                <th>Waktu</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($recentLogs) === 0): ?>
                                <tr>
                                    <td colspan="5" class="text-center py-3 text-muted">Belum ada aktivitas tercatat.</td>
                                </tr>
                            <?php else: ?>
                                <?php $no = 1;
                                foreach ($recentLogs as $l): ?>
                                    <tr>
                                        <td><?= $no++ ?></td>
                                        <td><?= htmlspecialchars($l['nama_lengkap']) ?>
                                            (<?= htmlspecialchars(ucfirst($l['role'])) ?>)</td>
                                        <td><?= htmlspecialchars($l['modul']) ?></td>
                                        <td><?= htmlspecialchars($l['aksi']) ?></td>
                                        <td><?= date('H:i', strtotime($l['waktu_kejadian'])) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div class="mt-3 text-end">
                    <a href="audit.php" class="btn btn-outline-secondary btn-sm">Lihat Semua Log <i
                            class="bi bi-arrow-right"></i></a>
                </div>
            </div>
        </div>

        <!-- Sidebar Widgets -->
        <div class="col-lg-4 col-12">
            <div class="card-box">
                <h5 class="mb-4 fw-bold">Backup Database</h5>

                <div class="border rounded p-3 mb-3 bg-white">
                    <div class="d-flex align-items-center justify-content-between mb-2">
                        <span class="fw-bold fs-7">Backup Terakhir</span>
                        <span class="<?= $lastBackup ? 'badge-success' : 'badge-warning' ?>">
                            <?= $lastBackup ? 'Selesai' : 'Belum Ada' ?>
                        </span>
                    </div>
                    <span class="text-secondary fs-7 d-block mb-3">
                        <?= $lastBackup ? 'Terakhir: ' . date('d-m-Y H:i', strtotime($lastBackup['waktu'])) . ' WIB' : 'Belum pernah melakukan backup database.' ?>
                    </span>
                    <a href="pengaturan.php?tab=backup" class="btn-primary-custom w-100 d-block text-center"
                        style="height:36px; line-height:36px; text-decoration:none;">
                        <i class="bi bi-cloud-arrow-down-fill"></i> Kelola Backup
                    </a>
                </div>
            </div>
        </div>
    </div>
</main>

<?php
include "../includes/footer.php";
?>
