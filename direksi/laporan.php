<?php
// direksi/laporan.php
session_start();

// Auth guard: pastikan sudah login dan role-nya memang direksi
if (empty($_SESSION['login']) || ($_SESSION['role'] ?? '') !== 'direksi') {
    header("Location: ../login.php");
    exit;
}

require_once "../config/koneksi.php";

$page_title = "Laporan Eksekutif";

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
function safe_sum(PDO $conn, string $sql, array $params = []): float
{
    try {
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        return (float) $stmt->fetchColumn();
    } catch (PDOException $e) {
        return 0.0;
    }
}

$tahun = (int) ($_GET['tahun'] ?? date('Y'));
if ($tahun < 2000 || $tahun > 2100) {
    $tahun = (int) date('Y');
}
$tahun_tersedia = range((int) date('Y'), (int) date('Y') - 4);

// ================== RINGKASAN TAHUN BERJALAN ==================
$total_suket_tahun   = safe_count($conn, "SELECT COUNT(*) FROM Suket_K3 WHERE YEAR(created_at) = :t", [':t' => $tahun]);
$total_klien_aktif   = safe_count($conn, "SELECT COUNT(*) FROM Data_Klien WHERE status = 'Aktif'");
$total_insiden_tahun = safe_count($conn, "SELECT COUNT(*) FROM Laporan_Insiden WHERE YEAR(created_at) = :t", [':t' => $tahun]);
$total_reimburse_rp  = safe_sum($conn, "SELECT COALESCE(SUM(nominal),0) FROM Reimburse WHERE status IN ('Disetujui','Dibayarkan') AND YEAR(tanggal_pengeluaran) = :t", [':t' => $tahun]);

// ================== SUKET SELESAI PER BULAN ==================
$per_bulan = array_fill(1, 12, 0);
$rows = safe_query($conn, "
    SELECT MONTH(tanggal_pemeriksaan) AS bln, COUNT(*) AS jumlah
    FROM Suket_K3
    WHERE tanggal_pemeriksaan IS NOT NULL AND YEAR(tanggal_pemeriksaan) = :t
    GROUP BY MONTH(tanggal_pemeriksaan)
", [':t' => $tahun]);
foreach ($rows as $r) {
    $per_bulan[(int) $r['bln']] = (int) $r['jumlah'];
}
$label_bulan = ['Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agu','Sep','Okt','Nov','Des'];

// ================== HASIL PEMERIKSAAN (Layak/Tidak Layak/Bersyarat) ==================
$hasil_rows = safe_query($conn, "
    SELECT hasil_pemeriksaan, COUNT(*) AS jumlah
    FROM Suket_K3
    WHERE hasil_pemeriksaan IS NOT NULL AND YEAR(tanggal_pemeriksaan) = :t
    GROUP BY hasil_pemeriksaan
", [':t' => $tahun]);
$hasil_summary = ['Layak' => 0, 'Tidak Layak' => 0, 'Layak Bersyarat' => 0];
foreach ($hasil_rows as $r) {
    if (isset($hasil_summary[$r['hasil_pemeriksaan']])) {
        $hasil_summary[$r['hasil_pemeriksaan']] = (int) $r['jumlah'];
    }
}

// ================== INSIDEN PER TINGKAT KEPARAHAN ==================
$insiden_rows = safe_query($conn, "
    SELECT tingkat_keparahan, COUNT(*) AS jumlah
    FROM Laporan_Insiden
    WHERE YEAR(created_at) = :t
    GROUP BY tingkat_keparahan
", [':t' => $tahun]);
$insiden_summary = ['Ringan' => 0, 'Sedang' => 0, 'Berat' => 0, 'Fatal' => 0];
foreach ($insiden_rows as $r) {
    if (isset($insiden_summary[$r['tingkat_keparahan']])) {
        $insiden_summary[$r['tingkat_keparahan']] = (int) $r['jumlah'];
    }
}

// ================== TOP KLIEN (jumlah pemeriksaan) ==================
$top_klien = safe_query($conn, "
    SELECT dk.nama_perusahaan, COUNT(*) AS jumlah
    FROM Suket_K3 sk
    LEFT JOIN Data_Klien dk ON sk.klien_id = dk.id
    WHERE YEAR(sk.created_at) = :t
    GROUP BY sk.klien_id
    ORDER BY jumlah DESC
    LIMIT 5
", [':t' => $tahun]);

include "../includes/header.php";
include "../includes/sidebar.php";
include "../includes/topbar.php";
?>

<main class="main-content">
    <div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-3">
        <div>
            <h4 class="fw-bold mb-1">Laporan Eksekutif</h4>
            <p class="text-secondary mb-0">Ringkasan kinerja perusahaan berdasarkan data operasional</p>
        </div>
        <form method="GET" class="d-flex gap-2">
            <select class="select-custom" name="tahun" style="width: 140px;" onchange="this.form.submit()">
                <?php foreach ($tahun_tersedia as $ty): ?>
                    <option value="<?= $ty ?>" <?= $tahun === $ty ? 'selected' : '' ?>>Tahun <?= $ty ?></option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>

    <div class="row g-4 mb-4">
        <div class="col-xl-3 col-md-6 col-12">
            <div class="stat-card">
                <div class="stat-card-info">
                    <span class="stat-card-title">Suket Terbit (<?= $tahun ?>)</span>
                    <span class="stat-card-value"><?= number_format($total_suket_tahun, 0, ',', '.') ?></span>
                </div>
                <div class="stat-card-icon success"><i class="bi bi-file-earmark-medical"></i></div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6 col-12">
            <div class="stat-card">
                <div class="stat-card-info">
                    <span class="stat-card-title">Client Aktif</span>
                    <span class="stat-card-value"><?= number_format($total_klien_aktif, 0, ',', '.') ?></span>
                </div>
                <div class="stat-card-icon"><i class="bi bi-people-fill"></i></div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6 col-12">
            <div class="stat-card">
                <div class="stat-card-info">
                    <span class="stat-card-title">Insiden (<?= $tahun ?>)</span>
                    <span class="stat-card-value"><?= number_format($total_insiden_tahun, 0, ',', '.') ?></span>
                </div>
                <div class="stat-card-icon danger"><i class="bi bi-exclamation-triangle-fill"></i></div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6 col-12">
            <div class="stat-card">
                <div class="stat-card-info">
                    <span class="stat-card-title">Reimburse Disetujui</span>
                    <span class="stat-card-value" style="font-size:1.1rem;">Rp <?= number_format($total_reimburse_rp, 0, ',', '.') ?></span>
                </div>
                <div class="stat-card-icon warning"><i class="bi bi-cash-coin"></i></div>
            </div>
        </div>
    </div>

    <div class="row g-4 mb-4">
        <div class="col-lg-7 col-12">
            <div class="card-box h-100">
                <h5 class="fw-bold mb-3">Suket K3 Terbit per Bulan di Tahun <?= $tahun ?></h5>
                <div class="chart-wrap" style="position:relative; height:260px;">
                    <canvas id="chartSuketBulan"></canvas>
                </div>
            </div>
        </div>
        <div class="col-lg-5 col-12">
            <div class="card-box h-100">
                <h5 class="fw-bold mb-3">Hasil Pemeriksaan</h5>
                <div class="chart-wrap" style="position:relative; height:260px;">
                    <canvas id="chartHasil"></canvas>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-6 col-12">
            <div class="card-box h-100">
                <h5 class="fw-bold mb-3">Insiden per Tingkat Keparahan</h5>
                <div class="chart-wrap" style="position:relative; height:240px;">
                    <canvas id="chartInsiden"></canvas>
                </div>
            </div>
        </div>
        <div class="col-lg-6 col-12">
            <div class="card-box h-100">
                <h5 class="fw-bold mb-3">5 Client dengan Aktivitas Tertinggi</h5>
                <?php if (empty($top_klien)): ?>
                    <p class="text-secondary fs-7 mb-0">Belum ada data pemeriksaan pada tahun ini.</p>
                <?php else: ?>
                    <div class="table-responsive-custom">
                        <table class="table-custom">
                            <thead><tr><th>Perusahaan</th><th>Jumlah Pemeriksaan</th></tr></thead>
                            <tbody>
                                <?php foreach ($top_klien as $k): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($k['nama_perusahaan'] ?? '-') ?></td>
                                        <td><?= (int) $k['jumlah'] ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    new Chart(document.getElementById('chartSuketBulan'), {
        type: 'line',
        data: {
            labels: <?= json_encode($label_bulan) ?>,
            datasets: [{
                label: 'Suket Terbit',
                data: <?= json_encode(array_values($per_bulan)) ?>,
                borderColor: '#2ecc71',
                backgroundColor: 'rgba(46,204,113,0.15)',
                tension: 0.35,
                fill: true
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }
        }
    });

    new Chart(document.getElementById('chartHasil'), {
        type: 'doughnut',
        data: {
            labels: ['Layak', 'Tidak Layak', 'Layak Bersyarat'],
            datasets: [{
                data: [<?= $hasil_summary['Layak'] ?>, <?= $hasil_summary['Tidak Layak'] ?>, <?= $hasil_summary['Layak Bersyarat'] ?>],
                backgroundColor: ['#2ecc71', '#e74c3c', '#f1c40f'],
                borderWidth: 0
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '65%',
            plugins: { legend: { position: 'bottom', labels: { boxWidth: 12, padding: 12 } } }
        }
    });

    new Chart(document.getElementById('chartInsiden'), {
        type: 'bar',
        data: {
            labels: ['Ringan', 'Sedang', 'Berat', 'Fatal'],
            datasets: [{
                data: [<?= $insiden_summary['Ringan'] ?>, <?= $insiden_summary['Sedang'] ?>, <?= $insiden_summary['Berat'] ?>, <?= $insiden_summary['Fatal'] ?>],
                backgroundColor: ['#2ecc71', '#f1c40f', '#e67e22', '#e74c3c'],
                borderRadius: 4,
                maxBarThickness: 40
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }
        }
    });
});
</script>

<?php
include "../includes/footer.php";
?>