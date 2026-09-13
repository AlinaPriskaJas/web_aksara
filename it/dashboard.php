<?php
// it/dashboard.php — Dashboard Rekap IT Support
// Merangkum seluruh modul sistem: User Management, Audit Log, Backup/Database,
// Stock Gudang, Transportasi, Dokumen Digital, Surat, Reimburse & Cuti karyawan.
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

$nama_user = $_SESSION['nama_lengkap'] ?? 'IT Support';

// Sapaan otomatis sesuai jam
$jam = (int) date('H');
if ($jam < 11) {
    $sapaan = 'Selamat pagi';
} elseif ($jam < 15) {
    $sapaan = 'Selamat siang';
} elseif ($jam < 19) {
    $sapaan = 'Selamat sore';
} else {
    $sapaan = 'Selamat malam';
}

try {
    $stmtUser = $conn->prepare("SELECT nama_lengkap FROM Users WHERE id = :id LIMIT 1");
    $stmtUser->execute(['id' => $_SESSION['user_id']]);
    $u = $stmtUser->fetch();
    if ($u)
        $nama_user = $u['nama_lengkap'];
} catch (PDOException $e) {
}

// ================= Users =================
$totalUsers = $conn->query("SELECT COUNT(*) FROM Users")->fetchColumn() ?: 0;
$usersByRole = ['direksi' => 0, 'admin' => 0, 'it' => 0, 'ahli_k3' => 0, 'client' => 0];
try {
    $stmtRole = $conn->query("SELECT role, COUNT(*) AS jml FROM Users GROUP BY role");
    foreach ($stmtRole->fetchAll() as $row) {
        $usersByRole[$row['role']] = (int) $row['jml'];
    }
} catch (PDOException $e) {
}

// ================= Ukuran Database =================
try {
    $dbRow = $conn->query("SELECT DATABASE() AS db")->fetch();
    $sizeRow = $conn->prepare("SELECT ROUND(SUM(DATA_LENGTH + INDEX_LENGTH) / 1024 / 1024, 2) AS size_mb FROM information_schema.TABLES WHERE TABLE_SCHEMA = :db");
    $sizeRow->execute(['db' => $dbRow['db']]);
    $dbSizeMB = $sizeRow->fetch()['size_mb'] ?: 0;
} catch (PDOException $e) {
    $dbSizeMB = 0;
}

// ================= Audit Log =================
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
$logHariIni = 0;
try {
    $logHariIni = $conn->query("SELECT COUNT(*) FROM Audit_Log WHERE DATE(waktu_kejadian) = CURDATE()")->fetchColumn() ?: 0;
} catch (PDOException $e) {
}

// Chart: aktivitas log 7 hari terakhir
$logLabels = [];
$logCounts = [];
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $logLabels[] = date('d M', strtotime("-$i days"));
    $logCounts[$d] = 0;
}
try {
    $stmtLog7 = $conn->prepare("SELECT DATE(waktu_kejadian) AS d, COUNT(*) AS jml FROM Audit_Log WHERE waktu_kejadian >= :start GROUP BY d");
    $stmtLog7->execute(['start' => date('Y-m-d 00:00:00', strtotime('-6 days'))]);
    foreach ($stmtLog7->fetchAll() as $row) {
        if (isset($logCounts[$row['d']]))
            $logCounts[$row['d']] = (int) $row['jml'];
    }
} catch (PDOException $e) {
}
$logCountsValues = array_values($logCounts);

// ================= Backup Terakhir =================
$backup_dir = "../backups/";
$log_file = $backup_dir . "backup_log.json";
$lastBackup = null;
if (file_exists($log_file)) {
    $backupData = json_decode(file_get_contents($log_file), true);
    if (is_array($backupData) && count($backupData) > 0) {
        $lastBackup = $backupData[0];
    }
}

// ================= Stock Gudang (Stok Menipis) =================
$lowStock = [];
$totalBarang = 0;
try {
    $totalBarang = $conn->query("SELECT COUNT(*) FROM Gudang_Stok")->fetchColumn() ?: 0;
    $stmtLow = $conn->query("
        SELECT * FROM Gudang_Stok
        WHERE stok_minimum IS NOT NULL AND stok_sistem <= stok_minimum
        ORDER BY stok_sistem ASC LIMIT 5
    ");
    $lowStock = $stmtLow->fetchAll();
} catch (PDOException $e) {
}
$lowStockCount = count($lowStock);
try {
    $lowStockCount = $conn->query("SELECT COUNT(*) FROM Gudang_Stok WHERE stok_minimum IS NOT NULL AND stok_sistem <= stok_minimum")->fetchColumn() ?: 0;
} catch (PDOException $e) {
}

// ================= Transportasi =================
$vehicleStatus = ['Tersedia' => 0, 'Dipakai' => 0, 'Maintenance' => 0];
try {
    $stmtVeh = $conn->query("SELECT status_kendaraan, COUNT(*) AS jml FROM Kendaraan GROUP BY status_kendaraan");
    foreach ($stmtVeh->fetchAll() as $row) {
        $vehicleStatus[$row['status_kendaraan']] = (int) $row['jml'];
    }
} catch (PDOException $e) {
}
$totalKendaraan = array_sum($vehicleStatus);

// ================= Dokumen Digital =================
$totalDok = 0;
try {
    $totalDok = $conn->query("SELECT COUNT(*) FROM Dokumen_Digital")->fetchColumn() ?: 0;
} catch (PDOException $e) {
}

// ================= Reimburse (Perusahaan) =================
$reimPendingCount = 0;
$reimPendingTotal = 0;
try {
    $r = $conn->query("SELECT COUNT(*), COALESCE(SUM(nominal),0) FROM Reimburse WHERE status = 'Menunggu'")->fetch(PDO::FETCH_NUM);
    [$reimPendingCount, $reimPendingTotal] = $r;
} catch (PDOException $e) {
}

// ================= Cuti (Perusahaan) =================
$cutiPendingCount = 0;
try {
    $cutiPendingCount = $conn->query("SELECT COUNT(*) FROM Cuti WHERE status = 'Menunggu'")->fetchColumn() ?: 0;
} catch (PDOException $e) {
}

// ================= Surat (Bulan Ini) =================
$suratMasukBulan = 0;
$suratKeluarBulan = 0;
try {
    $suratMasukBulan = $conn->query("SELECT COUNT(*) FROM Surat WHERE arah = 'Masuk' AND MONTH(tgl_dibuat) = MONTH(CURDATE()) AND YEAR(tgl_dibuat) = YEAR(CURDATE())")->fetchColumn() ?: 0;
    $suratKeluarBulan = $conn->query("SELECT COUNT(*) FROM Surat WHERE arah = 'Keluar' AND MONTH(tgl_dibuat) = MONTH(CURDATE()) AND YEAR(tgl_dibuat) = YEAR(CURDATE())")->fetchColumn() ?: 0;
} catch (PDOException $e) {
}

// ================== JADWAL PEMERIKSAAN 7 HARI (IT: lihat semua, tandai jika ditugaskan) ==================
$jadwalMingguIT = [];
try {
    $stmtJadwalIT = $conn->prepare("
        SELECT jp.tanggal, jp.jam_mulai, jp.lokasi, jp.status, dk.nama_perusahaan, sa.nama_lengkap AS nama_ahli,
               jp.tim_support_ids, sa.user_id AS ahli_user_id
        FROM Jadwal_Pemeriksaan jp
        LEFT JOIN Data_Klien dk ON dk.id = jp.klien_id
        LEFT JOIN Sertifikat_Ahli sa ON sa.id = jp.ahli_k3_id
        WHERE jp.tanggal BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
          AND jp.status IN ('Terjadwal','Reschedule')
        ORDER BY jp.tanggal ASC, jp.jam_mulai ASC
        LIMIT 7
    ");
    $stmtJadwalIT->execute();
    $jadwalMingguIT = $stmtJadwalIT->fetchAll();
} catch (PDOException $e) {
    $jadwalMingguIT = [];
}

foreach ($jadwalMingguIT as &$jit) {
    $jit['ditugaskan_ke_saya'] = false;
    if (!empty($jit['ahli_user_id']) && (int) $jit['ahli_user_id'] === (int) $_SESSION['user_id']) {
        $jit['ditugaskan_ke_saya'] = true;
    }
    if (!$jit['ditugaskan_ke_saya'] && !empty($jit['tim_support_ids'])) {
        $tsIds = array_map('trim', explode(',', $jit['tim_support_ids']));
        if (in_array((string) $_SESSION['user_id'], $tsIds, true)) {
            $jit['ditugaskan_ke_saya'] = true;
        }
    }
}
unset($jit);
?>

<main class="main-content">

    <div class="mb-4">
        <h4 class="fw-bold mb-1"><?= htmlspecialchars($sapaan) ?>, <?= htmlspecialchars($nama_user) ?></h4>
        <p class="text-secondary mb-0">Rekap kondisi sistem & operasional perusahaan hari ini, <?= date('d M Y') ?></p>
    </div>

    <!-- Stat Cards Row 1 -->
    <div class="row g-4 mb-4">
        <div class="col-xl-3 col-md-6 col-12">
            <div class="stat-card">
                <div class="stat-card-info">
                    <span class="stat-card-title">Total Users</span>
                    <span class="stat-card-value"><?= number_format($totalUsers, 0, ',', '.') ?></span>
                </div>
                <div class="stat-card-icon"><i class="bi bi-people-fill"></i></div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6 col-12">
            <div class="stat-card">
                <div class="stat-card-info">
                    <span class="stat-card-title">Ukuran Database</span>
                    <span class="stat-card-value"><?= number_format($dbSizeMB, 1, ',', '.') ?> MB</span>
                </div>
                <div class="stat-card-icon warning"><i class="bi bi-server"></i></div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6 col-12">
            <div class="stat-card">
                <div class="stat-card-info">
                    <span class="stat-card-title">Aktivitas Log Hari Ini</span>
                    <span class="stat-card-value"><?= $logHariIni ?> Entri</span>
                </div>
                <div class="stat-card-icon"><i class="bi bi-clock-history"></i></div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6 col-12">
            <div class="stat-card">
                <div class="stat-card-info">
                    <span class="stat-card-title">Status Backup</span>
                    <span class="stat-card-value <?= $lastBackup ? 'text-success' : 'text-danger' ?>">
                        <?= $lastBackup ? 'Aman' : 'Belum Ada' ?>
                    </span>
                </div>
                <div class="stat-card-icon <?= $lastBackup ? 'success' : 'danger' ?>"><i
                        class="bi bi-cloud-arrow-down-fill"></i></div>
            </div>
        </div>
    </div>

    <!-- Stat Cards Row 2 -->
    <div class="row g-4 mb-4">
        <div class="col-xl-3 col-md-6 col-12">
            <div class="stat-card">
                <div class="stat-card-info">
                    <span class="stat-card-title">Stok Menipis</span>
                    <span class="stat-card-value <?= $lowStockCount > 0 ? 'text-danger' : '' ?>"><?= $lowStockCount ?>
                        Barang</span>
                </div>
                <div class="stat-card-icon <?= $lowStockCount > 0 ? 'danger' : 'success' ?>"><i
                        class="bi bi-box-seam"></i></div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6 col-12">
            <div class="stat-card">
                <div class="stat-card-info">
                    <span class="stat-card-title">Dokumen Digital</span>
                    <span class="stat-card-value"><?= number_format($totalDok, 0, ',', '.') ?> Berkas</span>
                </div>
                <div class="stat-card-icon"><i class="bi bi-file-earmark-pdf-fill"></i></div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6 col-12">
            <div class="stat-card">
                <div class="stat-card-info">
                    <span class="stat-card-title">Reimburse Menunggu</span>
                    <span class="stat-card-value"><?= $reimPendingCount ?> Pengajuan</span>
                </div>
                <div class="stat-card-icon warning"><i class="bi bi-cash-coin"></i></div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6 col-12">
            <div class="stat-card">
                <div class="stat-card-info">
                    <span class="stat-card-title">Cuti Menunggu Approval</span>
                    <span class="stat-card-value"><?= $cutiPendingCount ?> Pengajuan</span>
                </div>
                <div class="stat-card-icon warning"><i class="bi bi-calendar-x-fill"></i></div>
            </div>
        </div>
    </div>

    <!-- Charts Row -->
    <div class="row g-4 mb-4">
        <div class="col-lg-5 col-12">
            <div class="card-box h-100">
                <h5 class="mb-3 fw-bold">Distribusi User per Role</h5>
                <div style="max-width: 260px; margin: 0 auto;">
                    <canvas id="chartUsers"></canvas>
                </div>
            </div>
        </div>
        <div class="col-lg-7 col-12">
            <div class="card-box h-100">
                <h5 class="mb-3 fw-bold">Aktivitas Sistem (7 Hari Terakhir)</h5>
                <div style="height: 200px;">
                    <canvas id="chartAktivitas"></canvas>
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

            <!-- Jadwal Pemeriksaan Minggu Ini (card baru, gaya tabel lebar) -->
            <div class="card-box mt-4">
                <div class="d-flex align-items-center justify-content-between mb-4">
                    <h5 class="mb-0 fw-bold">Jadwal Pemeriksaan Minggu Ini</h5>
                    <span class="fs-7 text-secondary">7 hari ke depan</span>
                </div>
                <div class="table-responsive-custom">
                    <table class="table-custom">
                        <thead>
                            <tr>
                                <th>Tanggal</th>
                                <th>Jam</th>
                                <th>Perusahaan</th>
                                <th>Lokasi</th>
                                <th>Ahli K3</th>
                                <th>Status</th>
                                <th style="text-align:center;">Keterlibatan</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($jadwalMingguIT)): ?>
                                <tr>
                                    <td colspan="7" class="text-center py-3 text-muted">Belum ada jadwal pemeriksaan
                                        minggu ini.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($jadwalMingguIT as $jit): ?>
                                    <tr>
                                        <td><?= date('d M Y', strtotime($jit['tanggal'])) ?></td>
                                        <td><?= substr($jit['jam_mulai'], 0, 5) ?></td>
                                        <td><?= htmlspecialchars($jit['nama_perusahaan'] ?? '-') ?></td>
                                        <td><?= htmlspecialchars($jit['lokasi'] ?: '-') ?></td>
                                        <td><?= htmlspecialchars($jit['nama_ahli'] ?? '-') ?></td>
                                        <td>
                                            <span
                                                class="<?= $jit['status'] === 'Reschedule' ? 'badge-warning' : 'badge-info' ?>">
                                                <?= htmlspecialchars($jit['status']) ?>
                                            </span>
                                        </td>
                                        <td style="text-align:center;">
                                            <?php if ($jit['ditugaskan_ke_saya']): ?>
                                                <span class="badge-info">Saya</span>
                                            <?php else: ?>
                                                <span class="text-muted fs-7">-</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Stok Menipis -->
            <div class="card-box mt-4">
                <h5 class="mb-4 fw-bold">Peringatan Stok Menipis</h5>
                <div class="table-responsive-custom">
                    <table class="table-custom">
                        <thead>
                            <tr>
                                <th>Kode</th>
                                <th>Nama Barang</th>
                                <th>Stok Sistem</th>
                                <th>Stok Minimum</th>
                                <th>Lokasi Rak</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($lowStock) === 0): ?>
                                <tr>
                                    <td colspan="5" class="text-center py-3 text-muted">Semua stok gudang dalam kondisi
                                        aman.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($lowStock as $item): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($item['kode_barang']) ?></td>
                                        <td><?= htmlspecialchars($item['nama_barang']) ?></td>
                                        <td><span class="badge-danger"><?= $item['stok_sistem'] ?>
                                                <?= htmlspecialchars($item['satuan']) ?></span></td>
                                        <td><?= $item['stok_minimum'] ?>         <?= htmlspecialchars($item['satuan']) ?></td>
                                        <td><?= htmlspecialchars($item['lokasi_rak'] ?: '-') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div class="mt-3 text-end">
                    <a href="stock.php" class="btn btn-outline-secondary btn-sm">Kelola Stok Gudang <i
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

            <div class="card-box mt-4">
                <h5 class="mb-3 fw-bold">Status Transportasi</h5>
                <div class="d-flex flex-column gap-2">
                    <div class="d-flex align-items-center justify-content-between">
                        <span class="text-secondary fs-7"><i class="bi bi-check-circle text-success me-1"></i>
                            Tersedia</span>
                        <span class="fw-bold"><?= $vehicleStatus['Tersedia'] ?> / <?= $totalKendaraan ?></span>
                    </div>
                    <div class="d-flex align-items-center justify-content-between">
                        <span class="text-secondary fs-7"><i class="bi bi-truck text-warning me-1"></i> Dipakai</span>
                        <span class="fw-bold"><?= $vehicleStatus['Dipakai'] ?> / <?= $totalKendaraan ?></span>
                    </div>
                    <div class="d-flex align-items-center justify-content-between">
                        <span class="text-secondary fs-7"><i class="bi bi-tools text-danger me-1"></i>
                            Maintenance</span>
                        <span class="fw-bold"><?= $vehicleStatus['Maintenance'] ?> / <?= $totalKendaraan ?></span>
                    </div>
                </div>
                <a href="transportasi.php" class="btn btn-outline-secondary btn-sm w-100 mt-3">Kelola Transportasi</a>
            </div>

            <div class="card-box mt-4">
                <h5 class="mb-3 fw-bold">Persuratan Bulan Ini</h5>
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="text-secondary fs-7"><i class="bi bi-envelope-arrow-down text-primary me-1"></i> Surat
                        Masuk</span>
                    <span class="fw-bold"><?= $suratMasukBulan ?></span>
                </div>
                <div class="d-flex align-items-center justify-content-between">
                    <span class="text-secondary fs-7"><i class="bi bi-envelope-arrow-up text-primary me-1"></i> Surat
                        Keluar</span>
                    <span class="fw-bold"><?= $suratKeluarBulan ?></span>
                </div>
                <a href="surat.php" class="btn btn-outline-secondary btn-sm w-100 mt-3">Kelola Surat</a>
            </div>

            <div class="card-box mt-4">
                <h5 class="mb-3 fw-bold">Pintasan Modul</h5>
                <div class="d-flex flex-column gap-2">
                    <a href="user.php"
                        class="d-flex align-items-center justify-content-between text-decoration-none p-2 rounded"
                        style="color: var(--text-primary); background:#f8fafc;">
                        <span><i class="bi bi-people me-2 text-primary"></i>User Management</span>
                        <i class="bi bi-chevron-right text-muted"></i>
                    </a>
                    <a href="digital.php"
                        class="d-flex align-items-center justify-content-between text-decoration-none p-2 rounded"
                        style="color: var(--text-primary); background:#f8fafc;">
                        <span><i class="bi bi-hdd-network me-2 text-primary"></i>Digital Assets</span>
                        <i class="bi bi-chevron-right text-muted"></i>
                    </a>
                    <a href="reimburse.php"
                        class="d-flex align-items-center justify-content-between text-decoration-none p-2 rounded"
                        style="color: var(--text-primary); background:#f8fafc;">
                        <span><i class="bi bi-cash-coin me-2 text-primary"></i>Reimburse</span>
                        <i class="bi bi-chevron-right text-muted"></i>
                    </a>
                    <a href="cuti.php"
                        class="d-flex align-items-center justify-content-between text-decoration-none p-2 rounded"
                        style="color: var(--text-primary); background:#f8fafc;">
                        <span><i class="bi bi-calendar-x me-2 text-primary"></i>Cuti</span>
                        <i class="bi bi-chevron-right text-muted"></i>
                    </a>
                    <a href="pengaturan.php"
                        class="d-flex align-items-center justify-content-between text-decoration-none p-2 rounded"
                        style="color: var(--text-primary); background:#f8fafc;">
                        <span><i class="bi bi-gear me-2 text-primary"></i>Pengaturan Sistem</span>
                        <i class="bi bi-chevron-right text-muted"></i>
                    </a>
                </div>
            </div>
        </div>
    </div>
</main>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        const ctxUsers = document.getElementById('chartUsers');
        if (ctxUsers) {
            new Chart(ctxUsers, {
                type: 'doughnut',
                data: {
                    labels: ['Direksi', 'Admin', 'IT', 'Ahli K3', 'Client'],
                    datasets: [{
                        data: [
                            <?= $usersByRole['direksi'] ?>,
                            <?= $usersByRole['admin'] ?>,
                            <?= $usersByRole['it'] ?>,
                            <?= $usersByRole['ahli_k3'] ?>,
                            <?= $usersByRole['client'] ?>
                        ],
                        backgroundColor: ['#1e4620', '#2C9A75', '#3498db', '#f1c40f', '#94a3b8'],
                        borderWidth: 0
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    plugins: { legend: { position: 'bottom', labels: { font: { family: 'Arial' } } } }
                }
            });
        }

        const ctxAktivitas = document.getElementById('chartAktivitas');
        if (ctxAktivitas) {
            new Chart(ctxAktivitas, {
                type: 'line',
                data: {
                    labels: <?= json_encode($logLabels) ?>,
                    datasets: [{
                        label: 'Jumlah Aktivitas',
                        data: <?= json_encode($logCountsValues) ?>,
                        borderColor: '#2C9A75',
                        backgroundColor: 'rgba(44,154,117,0.15)',
                        fill: true,
                        tension: 0.35,
                        pointRadius: 4,
                        pointBackgroundColor: '#2C9A75'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } }
                }
            });
        }
    });
</script>

<?php
include "../includes/footer.php";
?>