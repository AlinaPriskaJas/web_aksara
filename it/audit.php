<?php
// it/audit.php
require_once "../config/koneksi.php";

if (session_status() === PHP_SESSION_NONE)
    session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'it') {
    header("Location: ../login.php");
    exit;
}

$page_title = "Audit Log";
include "../includes/header.php";
include "../includes/sidebar.php";
include "../includes/topbar.php";

// Filter modul
$filter_modul = isset($_GET['modul']) ? trim($_GET['modul']) : '';

$sql = "SELECT al.*, u.nama_lengkap, u.role FROM Audit_Log al JOIN Users u ON al.user_id = u.id";
$params = [];
if (!empty($filter_modul)) {
    $sql .= " WHERE al.modul = :modul";
    $params['modul'] = $filter_modul;
}
$sql .= " ORDER BY al.waktu_kejadian DESC LIMIT 500";

try {
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $logs = $stmt->fetchAll();
} catch (PDOException $e) {
    $logs = [];
}

$totalLog = $conn->query("SELECT COUNT(*) FROM Audit_Log")->fetchColumn() ?: 0;
$logHariIni = $conn->query("SELECT COUNT(*) FROM Audit_Log WHERE DATE(waktu_kejadian) = CURDATE()")->fetchColumn() ?: 0;
$moduls = $conn->query("SELECT DISTINCT modul FROM Audit_Log ORDER BY modul ASC")->fetchAll(PDO::FETCH_COLUMN);
?>

<main class="main-content">
    <!-- Recap Cards -->
    <div class="row g-4 mb-4">
        <div class="col-md-4 col-12">
            <div class="stat-card">
                <div class="stat-card-info">
                    <span class="stat-card-title">Total Aktivitas Tercatat</span>
                    <span class="stat-card-value"><?= number_format($totalLog, 0, ',', '.') ?></span>
                </div>
                <div class="stat-card-icon"><i class="bi bi-journal-text"></i></div>
            </div>
        </div>
        <div class="col-md-4 col-12">
            <div class="stat-card">
                <div class="stat-card-info">
                    <span class="stat-card-title">Aktivitas Hari Ini</span>
                    <span class="stat-card-value"><?= number_format($logHariIni, 0, ',', '.') ?></span>
                </div>
                <div class="stat-card-icon success"><i class="bi bi-activity"></i></div>
            </div>
        </div>
        <div class="col-md-4 col-12">
            <div class="stat-card">
                <div class="stat-card-info">
                    <span class="stat-card-title">Modul Termonitor</span>
                    <span class="stat-card-value"><?= count($moduls) ?></span>
                </div>
                <div class="stat-card-icon warning"><i class="bi bi-diagram-3-fill"></i></div>
            </div>
        </div>
    </div>

    <div class="card-box">
        <div class="table-toolbar">
            <h5 class="table-toolbar-title fw-bold">Log Aktivitas Pengguna</h5>
            <div class="table-toolbar-actions">
                <form method="GET" action="audit.php" class="d-flex gap-2">
                    <select name="modul" class="select-custom" onchange="this.form.submit()" style="min-width:180px;">
                        <option value="">Semua Modul</option>
                        <?php foreach ($moduls as $m): ?>
                            <option value="<?= htmlspecialchars($m) ?>" <?= $filter_modul === $m ? 'selected' : '' ?>>
                                <?= htmlspecialchars($m) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>
                <div class="search-box-container">
                    <i class="bi bi-search"></i>
                    <input type="text" class="search-box" placeholder="Cari log..." data-table-search="tabelAudit"
                        onkeyup="handleTableSearch('tabelAudit')">
                </div>
            </div>
        </div>

        <div class="table-responsive-custom">
            <table class="table-custom" id="tabelAudit">
                <thead>
                    <tr>
                        <th>Waktu</th>
                        <th>Pengguna</th>
                        <th>Modul</th>
                        <th>Aksi</th>
                        <th>Detail Perubahan</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($logs) === 0): ?>
                        <tr>
                            <td colspan="5" class="text-center py-4 text-muted">
                                <i class="bi bi-journal-x d-block mb-2" style="font-size:2rem;"></i>
                                Belum ada aktivitas yang tercatat.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($logs as $l): ?>
                            <tr>
                                <td><?= date('d-m-Y H:i:s', strtotime($l['waktu_kejadian'])) ?></td>
                                <td>
                                    <div class="fw-bold"><?= htmlspecialchars($l['nama_lengkap']) ?></div>
                                    <small class="text-secondary" style="text-transform:capitalize;">
                                        <?= htmlspecialchars(str_replace('_', ' ', $l['role'])) ?>
                                    </small>
                                </td>
                                <td><span class="badge-warning"><?= htmlspecialchars($l['modul']) ?></span></td>
                                <td><?= htmlspecialchars($l['aksi']) ?></td>
                                <td><?= htmlspecialchars($l['detail_perubahan'] ?: '-') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="pagination-custom" id="pagination-tabelAudit"></div>
    </div>
</main>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        initTablePagination('tabelAudit', 15);
    });
</script>

<?php include "../includes/footer.php"; ?>
