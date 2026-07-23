<?php
// direksi/dashboard.php
session_start();

// Auth guard: pastikan sudah login dan role-nya memang direksi
if (empty($_SESSION['login']) || ($_SESSION['role'] ?? '') !== 'direksi') {
    header("Location: ../login.php");
    exit;
}

require_once "../config/koneksi.php";

$page_title = "Dashboard Direksi";

$user_id   = $_SESSION['user_id'];
$user_name = $_SESSION['nama_lengkap'];

// Jam untuk sapaan (pagi/siang/sore/malam)
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

// Helper query aman: kembalikan 0 jika tabel/kolom belum tersedia, agar dashboard tidak error
function safe_count(PDO $conn, string $sql, array $params = []): int
{
    try {
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    } catch (PDOException $e) {
        return 0;
    }
}

function safe_query(PDO $conn, string $sql, array $params = []): array
{
    try {
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
}

// ================== STAT CARDS ==================
$total_klien       = safe_count($conn, "SELECT COUNT(*) FROM Data_Klien WHERE status = 'Aktif'");
$sertifikat_aktif  = safe_count($conn, "SELECT COUNT(*) FROM v_sertifikat_ahli_status WHERE status_realtime = 'Aktif'");
$menunggu_approval = safe_count($conn, "SELECT COUNT(*) FROM Approval WHERE status = 'Menunggu'");
$audit_inspeksi    = safe_count($conn, "SELECT COUNT(*) FROM Jadwal_Pemeriksaan WHERE status IN ('Terjadwal','Berlangsung')");

// ================== RINGKASAN OPERASIONAL ==================
$total_pemeriksaan = safe_count($conn, "SELECT COUNT(*) FROM Suket_K3");
$laporan_dibuat     = safe_count($conn, "SELECT COUNT(*) FROM Suket_K3 WHERE file_sertifikat_pdf IS NOT NULL AND file_sertifikat_pdf <> ''");
$total_rekomendasi  = safe_count($conn, "SELECT COUNT(*) FROM Suket_K3 WHERE rekomendasi_teknis IS NOT NULL AND rekomendasi_teknis <> ''");
$temuan_audit       = safe_count($conn, "SELECT COUNT(*) FROM Laporan_Insiden");

// ================== AKTIVITAS TERBARU (Audit_Log) ==================
$aktivitas_terbaru = safe_query($conn, "
    SELECT al.aksi, al.modul, al.waktu_kejadian, u.nama_lengkap
    FROM Audit_Log al
    LEFT JOIN Users u ON u.id = al.user_id
    ORDER BY al.waktu_kejadian DESC
    LIMIT 6
");

// ================== GRAFIK KPI UTAMA (Pemeriksaan selesai per bulan, tahun berjalan) ==================
$tahun_ini = date('Y');
$kpi_per_bulan = array_fill(1, 12, 0);
$kpi_rows = safe_query($conn, "
    SELECT MONTH(tanggal_pemeriksaan) AS bln, COUNT(*) AS jumlah
    FROM Suket_K3
    WHERE tanggal_pemeriksaan IS NOT NULL
      AND YEAR(tanggal_pemeriksaan) = :tahun
    GROUP BY MONTH(tanggal_pemeriksaan)
", [':tahun' => $tahun_ini]);
foreach ($kpi_rows as $row) {
    $kpi_per_bulan[(int) $row['bln']] = (int) $row['jumlah'];
}
$kpi_labels = ['Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agu','Sep','Okt','Nov','Des'];
$kpi_values = array_values($kpi_per_bulan);

// ================== STATUS APPROVAL (donut) ==================
$approval_rows = safe_query($conn, "
    SELECT status, COUNT(*) AS jumlah
    FROM Approval
    GROUP BY status
");
$approval_summary = ['Disetujui' => 0, 'Menunggu' => 0, 'Ditolak' => 0];
foreach ($approval_rows as $row) {
    if (isset($approval_summary[$row['status']])) {
        $approval_summary[$row['status']] = (int) $row['jumlah'];
    }
}
$approval_total = array_sum($approval_summary);

// ================== PENGAJUAN CUTI (tahun berjalan) ==================
$cuti_rows = safe_query($conn, "
    SELECT status, COUNT(*) AS jumlah
    FROM Cuti
    WHERE YEAR(created_at) = :tahun
    GROUP BY status
", [':tahun' => $tahun_ini]);
$cuti_summary = ['Disetujui' => 0, 'Menunggu' => 0, 'Ditolak' => 0];
foreach ($cuti_rows as $row) {
    if (isset($cuti_summary[$row['status']])) {
        $cuti_summary[$row['status']] = (int) $row['jumlah'];
    }
}

include "../includes/header.php";
include "../includes/sidebar.php";
include "../includes/topbar.php";
?>

<main class="main-content">

    <!-- Greeting -->
        <div class="mb-4">
            <h4 class="fw-bold mb-1">
                <?= htmlspecialchars($sapaan) ?>,
                <?= htmlspecialchars($user_name) ?>
            </h4>
            <p class="text-secondary mb-0">
                Ringkasan kondisi perusahaan secara real time
            </p>
        </div>

    <!-- Stat Cards Section -->
    <div class="row g-4 mb-4">
        <div class="col-xl-3 col-md-6 col-12">
            <div class="stat-card">
                <div class="stat-card-info">
                    <span class="stat-card-title">Total Klien</span>
                    <span class="stat-card-value"><?= number_format($total_klien, 0, ',', '.') ?></span>
                </div>
                <div class="stat-card-icon success">
                    <i class="bi bi-people-fill"></i>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6 col-12">
            <div class="stat-card">
                <div class="stat-card-info">
                    <span class="stat-card-title">Sertifikat Aktif</span>
                    <span class="stat-card-value"><?= number_format($sertifikat_aktif, 0, ',', '.') ?></span>
                </div>
                <div class="stat-card-icon warning">
                    <i class="bi bi-award-fill"></i>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6 col-12">
            <div class="stat-card">
                <div class="stat-card-info">
                    <span class="stat-card-title">Menunggu Approval</span>
                    <span class="stat-card-value"><?= number_format($menunggu_approval, 0, ',', '.') ?></span>
                </div>
                <div class="stat-card-icon danger">
                    <i class="bi bi-file-earmark-check-fill"></i>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6 col-12">
            <div class="stat-card">
                <div class="stat-card-info">
                    <span class="stat-card-title">Audit &amp; Inspeksi</span>
                    <span class="stat-card-value"><?= number_format($audit_inspeksi, 0, ',', '.') ?></span>
                </div>
                <div class="stat-card-icon">
                    <i class="bi bi-clipboard2-check-fill"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Ringkasan Operasional + Aktivitas Terbaru -->
    <div class="row g-4 mb-4">
        <div class="col-lg-8 col-12">
            <div class="card-box h-100">
                <h5 class="fw-bold mb-4">Ringkasan Operasional</h5>
                <div class="row g-3 text-center">
                    <div class="col-6 col-md-3 border-end">
                        <i class="bi bi-clipboard2-pulse fs-3 text-success mb-2 d-block"></i>
                        <div class="fw-bold fs-5"><?= number_format($total_pemeriksaan, 0, ',', '.') ?></div>
                        <span class="text-secondary fs-7">Pemeriksaan</span>
                    </div>
                    <div class="col-6 col-md-3 border-end">
                        <i class="bi bi-file-earmark-text fs-3 text-primary mb-2 d-block"></i>
                        <div class="fw-bold fs-5"><?= number_format($laporan_dibuat, 0, ',', '.') ?></div>
                        <span class="text-secondary fs-7">Laporan Dibuat</span>
                    </div>
                    <div class="col-6 col-md-3 border-end">
                        <i class="bi bi-journal-check fs-3 text-warning mb-2 d-block"></i>
                        <div class="fw-bold fs-5"><?= number_format($total_rekomendasi, 0, ',', '.') ?></div>
                        <span class="text-secondary fs-7">Rekomendasi</span>
                    </div>
                    <div class="col-6 col-md-3">
                        <i class="bi bi-person-badge fs-3 text-danger mb-2 d-block"></i>
                        <div class="fw-bold fs-5"><?= number_format($temuan_audit, 0, ',', '.') ?></div>
                        <span class="text-secondary fs-7">Temuan Audit</span>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-4 col-12">
            <div class="card-box h-100">
                <h5 class="fw-bold mb-3">Aktivitas Terbaru</h5>
                <?php if (empty($aktivitas_terbaru)): ?>
                    <p class="text-secondary fs-7 mb-0">Belum ada aktivitas tercatat.</p>
                <?php else: ?>
                    <ul class="list-unstyled mb-3">
                        <?php foreach ($aktivitas_terbaru as $log): ?>
                            <li class="d-flex gap-2 mb-3">
                                <i class="bi bi-dot fs-4 text-success"></i>
                                <div>
                                    <div class="fs-7 fw-semibold mb-0">
                                        <?= htmlspecialchars($log['aksi']) ?> — <?= htmlspecialchars($log['modul']) ?>
                                    </div>
                                    <div class="fs-7 text-muted">
                                        <?= htmlspecialchars($log['nama_lengkap'] ?? 'Sistem') ?> ·
                                        <?= date('d M Y H:i', strtotime($log['waktu_kejadian'])) ?>
                                    </div>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <a href="approval.php" class="fs-7 fw-semibold text-decoration-none">Lihat semua aktivitas &rarr;</a>
            </div>
        </div>
    </div>

    <!-- Grafik KPI + Status Approval + Pengajuan Cuti -->
    <div class="row g-4">
        <div class="col-lg-5 col-12">
            <div class="card-box h-100">
                <h5 class="fw-bold mb-3">Grafik KPI Utama</h5>
                <canvas id="chartKpiUtama" height="220"></canvas>
            </div>
        </div>

        <div class="col-lg-4 col-12">
            <div class="card-box h-100">
                <h5 class="fw-bold mb-3">Status Approval</h5>
                <div class="d-flex align-items-center gap-4">
                    <div style="width:150px; height:150px;">
                        <canvas id="chartStatusApproval"></canvas>
                    </div>
                    <ul class="list-unstyled mb-0 fs-7">
                        <li class="d-flex align-items-center gap-2 mb-2">
                            <span class="d-inline-block rounded-circle" style="width:10px;height:10px;background:var(--success);"></span>
                            Disetujui
                            <span class="fw-bold ms-auto"><?= $approval_summary['Disetujui'] ?></span>
                        </li>
                        <li class="d-flex align-items-center gap-2 mb-2">
                            <span class="d-inline-block rounded-circle" style="width:10px;height:10px;background:var(--warning);"></span>
                            Menunggu
                            <span class="fw-bold ms-auto"><?= $approval_summary['Menunggu'] ?></span>
                        </li>
                        <li class="d-flex align-items-center gap-2">
                            <span class="d-inline-block rounded-circle" style="width:10px;height:10px;background:var(--danger);"></span>
                            Ditolak
                            <span class="fw-bold ms-auto"><?= $approval_summary['Ditolak'] ?></span>
                        </li>
                    </ul>
                </div>
                <a href="approval.php" class="fs-7 fw-semibold text-decoration-none d-inline-block mt-3">Lihat di Approval Center &rarr;</a>
            </div>
        </div>

        <div class="col-lg-3 col-12">
            <div class="card-box h-100">
                <h5 class="fw-bold mb-3">Pengajuan Cuti</h5>
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="fs-7 text-secondary">Disetujui</span>
                    <span class="fw-bold badge-success"><?= $cuti_summary['Disetujui'] ?></span>
                </div>
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="fs-7 text-secondary">Menunggu</span>
                    <span class="fw-bold badge-warning"><?= $cuti_summary['Menunggu'] ?></span>
                </div>
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <span class="fs-7 text-secondary">Ditolak</span>
                    <span class="fw-bold badge-danger"><?= $cuti_summary['Ditolak'] ?></span>
                </div>
                <a href="cuti.php" class="fs-7 fw-semibold text-decoration-none">Kelola Data Cuti &rarr;</a>
            </div>
        </div>
    </div>
</main>

<!-- Chart.js CDN (khusus halaman ini) -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    // Grafik KPI Utama (Bar)
    const kpiCtx = document.getElementById('chartKpiUtama');
    if (kpiCtx) {
        new Chart(kpiCtx, {
            type: 'bar',
            data: {
                labels: <?= json_encode($kpi_labels) ?>,
                datasets: [{
                    label: 'Pemeriksaan Selesai',
                    data: <?= json_encode($kpi_values) ?>,
                    backgroundColor: '#2ecc71',
                    borderRadius: 4,
                    maxBarThickness: 28
                }]
            },
            options: {
                plugins: { legend: { display: false } },
                scales: {
                    y: { beginAtZero: true, ticks: { precision: 0 } }
                }
            }
        });
    }

    // Status Approval (Donut)
    const approvalCtx = document.getElementById('chartStatusApproval');
    if (approvalCtx) {
        new Chart(approvalCtx, {
            type: 'doughnut',
            data: {
                labels: ['Disetujui', 'Menunggu', 'Ditolak'],
                datasets: [{
                    data: [
                        <?= $approval_summary['Disetujui'] ?>,
                        <?= $approval_summary['Menunggu'] ?>,
                        <?= $approval_summary['Ditolak'] ?>
                    ],
                    backgroundColor: ['#2ecc71', '#f1c40f', '#e74c3c'],
                    borderWidth: 0
                }]
            },
            options: {
                cutout: '70%',
                plugins: { legend: { display: false } }
            }
        });
    }
});
</script>

<?php
include "../includes/footer.php";
?>