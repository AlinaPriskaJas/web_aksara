<?php
// ahlik3/dashboard.php — Dashboard Rekap Ahli K3
// Merangkum seluruh modul: Jadwal, Riwayat/Suket, Insiden, Sertifikat Ahli,
// Reimburse, Cuti, Absensi, Surat & Transportasi milik Ahli K3 yang login.
require_once "../config/koneksi.php";

if (session_status() === PHP_SESSION_NONE)
    session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'ahli_k3') {
    header("Location: ../login.php");
    exit;
}

$page_title = "Dashboard Keselamatan Kerja (K3)";
include "../includes/header.php";
include "../includes/sidebar.php";
include "../includes/topbar.php";

$current_user_id = $_SESSION['user_id'];
$today = date('Y-m-d');
$current_year = date('Y');

// ================= Profil & Ahli K3 ID =================
$nama_user = $_SESSION['nama_lengkap'] ?? 'Ahli K3';

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
    $stmtUser = $conn->prepare("SELECT * FROM Users WHERE id = :id LIMIT 1");
    $stmtUser->execute(['id' => $current_user_id]);
    $user = $stmtUser->fetch();
    if ($user)
        $nama_user = $user['nama_lengkap'];
} catch (PDOException $e) {
    $user = null;
}

try {
    $stmtAhli = $conn->prepare("SELECT id FROM Sertifikat_Ahli WHERE user_id = :user_id LIMIT 1");
    $stmtAhli->execute(['user_id' => $current_user_id]);
    $ahli_k3_id = $stmtAhli->fetchColumn() ?: 0;
} catch (PDOException $e) {
    $ahli_k3_id = 0;
}

// ================= Statistik Utama =================
try {
    $stmtCountJadwal = $conn->prepare("SELECT COUNT(*) FROM Jadwal_Pemeriksaan WHERE ahli_k3_id = :ahli_id AND status = 'Terjadwal'");
    $stmtCountJadwal->execute(['ahli_id' => $ahli_k3_id]);
    $countJadwal = $stmtCountJadwal->fetchColumn() ?: 0;

    $stmtCountSelesai = $conn->prepare("SELECT COUNT(*) FROM Suket_K3 WHERE ahli_k3_id = :ahli_id AND hasil_pemeriksaan IS NOT NULL");
    $stmtCountSelesai->execute(['ahli_id' => $ahli_k3_id]);
    $countSelesai = $stmtCountSelesai->fetchColumn() ?: 0;

    $stmtCountIncidents = $conn->prepare("SELECT COUNT(*) FROM Laporan_Insiden WHERE pelapor_id = :user_id");
    $stmtCountIncidents->execute(['user_id' => $current_user_id]);
    $countIncidents = $stmtCountIncidents->fetchColumn() ?: 0;
} catch (PDOException $e) {
    $countJadwal = 0;
    $countSelesai = 0;
    $countIncidents = 0;
}

// ================= Absensi Hari Ini =================
try {
    $stmtAbsen = $conn->prepare("SELECT * FROM Absensi WHERE user_id = :user_id AND tanggal = :today LIMIT 1");
    $stmtAbsen->execute(['user_id' => $current_user_id, 'today' => $today]);
    $absensi_hari_ini = $stmtAbsen->fetch();
} catch (PDOException $e) {
    $absensi_hari_ini = null;
}

// ================= Cuti (Saldo Tahun Berjalan) =================
try {
    $stmtCuti = $conn->prepare("SELECT * FROM Cuti_Saldo WHERE user_id = :user_id AND tahun = :tahun LIMIT 1");
    $stmtCuti->execute(['user_id' => $current_user_id, 'tahun' => $current_year]);
    $cuti_saldo = $stmtCuti->fetch();
    if (!$cuti_saldo)
        $cuti_saldo = ['jatah_tahunan' => 12, 'terpakai' => 0, 'sisa' => 12];

    $stmtCutiPending = $conn->prepare("SELECT COUNT(*) FROM Cuti WHERE user_id = :user_id AND status = 'Menunggu'");
    $stmtCutiPending->execute(['user_id' => $current_user_id]);
    $cutiPending = $stmtCutiPending->fetchColumn() ?: 0;
} catch (PDOException $e) {
    $cuti_saldo = ['jatah_tahunan' => 12, 'terpakai' => 0, 'sisa' => 12];
    $cutiPending = 0;
}

// ================= Reimburse =================
try {
    $stmtReimPending = $conn->prepare("SELECT COUNT(*), COALESCE(SUM(nominal),0) FROM Reimburse WHERE user_id = :user_id AND status = 'Menunggu'");
    $stmtReimPending->execute(['user_id' => $current_user_id]);
    [$reimPendingCount, $reimPendingTotal] = $stmtReimPending->fetch(PDO::FETCH_NUM);

    $stmtReimPaid = $conn->prepare("SELECT COALESCE(SUM(nominal),0) FROM Reimburse WHERE user_id = :user_id AND status = 'Dibayarkan' AND YEAR(tanggal_pengeluaran) = :tahun");
    $stmtReimPaid->execute(['user_id' => $current_user_id, 'tahun' => $current_year]);
    $reimPaidTotal = $stmtReimPaid->fetchColumn() ?: 0;
} catch (PDOException $e) {
    $reimPendingCount = 0;
    $reimPendingTotal = 0;
    $reimPaidTotal = 0;
}

// ================= Sertifikat Ahli (status kedaluwarsa) =================
$sertifikat_terdekat = null;
$sertifikat_total = 0;
$sertifikat_kritis = 0;
try {
    $stmtSertAll = $conn->prepare("SELECT * FROM Sertifikat_Ahli WHERE user_id = :user_id ORDER BY tanggal_kedaluwarsa ASC");
    $stmtSertAll->execute(['user_id' => $current_user_id]);
    $sertifikatList = $stmtSertAll->fetchAll();
    $sertifikat_total = count($sertifikatList);
    foreach ($sertifikatList as $s) {
        $sisaHari = (strtotime($s['tanggal_kedaluwarsa']) - strtotime($today)) / 86400;
        if ($sisaHari <= 60)
            $sertifikat_kritis++;
    }
    $sertifikat_terdekat = $sertifikatList[0] ?? null;
} catch (PDOException $e) {
    $sertifikatList = [];
}

// ================= Chart 1: Distribusi Hasil Pemeriksaan =================
$hasilLabels = ['Layak', 'Layak Bersyarat', 'Tidak Layak'];
$hasilData = [0, 0, 0];
if ($ahli_k3_id > 0) {
    try {
        $stmtHasil = $conn->prepare("SELECT hasil_pemeriksaan, COUNT(*) AS jml FROM Suket_K3 WHERE ahli_k3_id = :ahli_id AND hasil_pemeriksaan IS NOT NULL GROUP BY hasil_pemeriksaan");
        $stmtHasil->execute(['ahli_id' => $ahli_k3_id]);
        foreach ($stmtHasil->fetchAll() as $row) {
            $idx = array_search($row['hasil_pemeriksaan'], $hasilLabels);
            if ($idx !== false)
                $hasilData[$idx] = (int) $row['jml'];
        }
    } catch (PDOException $e) {
    }
}

// ================= Chart 2: Jadwal Pemeriksaan 6 Bulan Terakhir =================
$bulanLabels = [];
$bulanData = [];
for ($i = 5; $i >= 0; $i--) {
    $ym = date('Y-m', strtotime("-$i months"));
    $bulanLabels[] = date('M Y', strtotime("-$i months"));
    $bulanData[$ym] = 0;
}
if ($ahli_k3_id > 0) {
    try {
        $stmtBulan = $conn->prepare("SELECT DATE_FORMAT(tanggal, '%Y-%m') AS ym, COUNT(*) AS jml FROM Jadwal_Pemeriksaan WHERE ahli_k3_id = :ahli_id AND tanggal >= :start GROUP BY ym");
        $stmtBulan->execute(['ahli_id' => $ahli_k3_id, 'start' => date('Y-m-01', strtotime('-5 months'))]);
        foreach ($stmtBulan->fetchAll() as $row) {
            if (isset($bulanData[$row['ym']]))
                $bulanData[$row['ym']] = (int) $row['jml'];
        }
    } catch (PDOException $e) {
    }
}
$bulanDataValues = array_values($bulanData);

// ================= Jadwal Inspeksi Terdekat =================
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

// ================= Insiden Terbaru =================
$recentIncidents = [];
try {
    $stmtInc = $conn->prepare("
        SELECT li.*, dk.nama_perusahaan
        FROM Laporan_Insiden li
        JOIN Data_Klien dk ON li.klien_id = dk.id
        WHERE li.pelapor_id = :user_id
        ORDER BY li.waktu_kejadian DESC LIMIT 5
    ");
    $stmtInc->execute(['user_id' => $current_user_id]);
    $recentIncidents = $stmtInc->fetchAll();
} catch (PDOException $e) {
    $recentIncidents = [];
}

function severityBadge($sev)
{
    return match ($sev) {
        'Major' => 'badge-danger',
        'Medium' => 'badge-warning',
        default => 'badge-secondary',
    };
}
?>

<main class="main-content">

    <!-- Greeting -->
    <div class="mb-4">
        <h4 class="fw-bold mb-1"><?= htmlspecialchars($sapaan) ?>, <?= htmlspecialchars($nama_user) ?></h4>
        <p class="text-secondary mb-0">Rekap kondisi sistem & operasional perusahaan hari ini, <?= date('d M Y') ?></p>
    </div>

    <!-- Stat Cards Row 1 -->
    <div class="row g-4 mb-4">
        <div class="col-xl-3 col-md-6 col-12">
            <div class="stat-card">
                <div class="stat-card-info">
                    <span class="stat-card-title">Inspeksi Terjadwal</span>
                    <span class="stat-card-value"><?= $countJadwal ?> Tugas</span>
                </div>
                <div class="stat-card-icon"><i class="bi bi-calendar-event-fill"></i></div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6 col-12">
            <div class="stat-card">
                <div class="stat-card-info">
                    <span class="stat-card-title">Pemeriksaan Selesai</span>
                    <span class="stat-card-value text-success"><?= $countSelesai ?> Laporan</span>
                </div>
                <div class="stat-card-icon success"><i class="bi bi-shield-fill-check"></i></div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6 col-12">
            <div class="stat-card">
                <div class="stat-card-info">
                    <span class="stat-card-title">Laporan Insiden K3</span>
                    <span class="stat-card-value text-danger"><?= $countIncidents ?> Kejadian</span>
                </div>
                <div class="stat-card-icon danger"><i class="bi bi-exclamation-triangle-fill"></i></div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6 col-12">
            <div class="stat-card">
                <div class="stat-card-info">
                    <span class="stat-card-title">Sisa Cuti Tahunan</span>
                    <span class="stat-card-value"><?= $cuti_saldo['sisa'] ?> Hari</span>
                </div>
                <div class="stat-card-icon warning"><i class="bi bi-calendar-x-fill"></i></div>
            </div>
        </div>
    </div>

    <!-- Stat Cards Row 2 -->
    <div class="row g-4 mb-4">
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
                    <span class="stat-card-title">Reimburse Dibayarkan (<?= $current_year ?>)</span>
                    <span class="stat-card-value text-success">Rp <?= number_format($reimPaidTotal, 0, ',', '.') ?></span>
                </div>
                <div class="stat-card-icon success"><i class="bi bi-wallet-fill"></i></div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6 col-12">
            <div class="stat-card">
                <div class="stat-card-info">
                    <span class="stat-card-title">Sertifikat Dimiliki</span>
                    <span class="stat-card-value"><?= $sertifikat_total ?> Sertifikat</span>
                </div>
                <div class="stat-card-icon"><i class="bi bi-award-fill"></i></div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6 col-12">
            <div class="stat-card">
                <div class="stat-card-info">
                    <span class="stat-card-title">Sertifikat Perlu Perhatian</span>
                    <span class="stat-card-value <?= $sertifikat_kritis > 0 ? 'text-danger' : '' ?>"><?= $sertifikat_kritis ?> Item</span>
                </div>
                <div class="stat-card-icon <?= $sertifikat_kritis > 0 ? 'danger' : 'success' ?>">
                    <i class="bi bi-shield-exclamation"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Charts Row -->
    <div class="row g-4 mb-4">
        <div class="col-lg-5 col-12">
            <div class="card-box h-100">
                <h5 class="mb-3 fw-bold">Distribusi Hasil Pemeriksaan</h5>
                <?php if (array_sum($hasilData) === 0): ?>
                    <p class="text-muted text-center py-5 mb-0">Belum ada data hasil pemeriksaan.</p>
                <?php else: ?>
                    <div style="max-width: 260px; margin: 0 auto;">
                        <canvas id="chartHasil"></canvas>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <div class="col-lg-7 col-12">
            <div class="card-box h-100">
                <h5 class="mb-3 fw-bold">Tren Jadwal Pemeriksaan (6 Bulan Terakhir)</h5>
                <div style="height: 200px;">
                    <canvas id="chartJadwal"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Content Grid -->
    <div class="row g-4 mb-4">
        <!-- Jadwal Terdekat -->
        <div class="col-lg-8 col-12">
            <div class="card-box">
                <h5 class="mb-4 fw-bold">Jadwal Inspeksi Terdekat Anda</h5>
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
                <div class="mt-3 text-end">
                    <a href="jadwal.php" class="btn btn-outline-secondary btn-sm">Lihat Semua Jadwal <i class="bi bi-arrow-right"></i></a>
                </div>
            </div>

            <!-- Insiden Terbaru -->
            <div class="card-box mt-4">
                <h5 class="mb-4 fw-bold">Laporan Insiden Terbaru Anda</h5>
                <div class="table-responsive-custom">
                    <table class="table-custom">
                        <thead>
                            <tr>
                                <th>Tanggal</th>
                                <th>Perusahaan</th>
                                <th>Jenis Insiden</th>
                                <th>Severity</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($recentIncidents) === 0): ?>
                                <tr>
                                    <td colspan="5" class="text-center py-3 text-muted">Belum ada laporan insiden.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($recentIncidents as $inc): ?>
                                    <tr>
                                        <td><?= date('d M Y', strtotime($inc['waktu_kejadian'])) ?></td>
                                        <td><?= htmlspecialchars($inc['nama_perusahaan']) ?></td>
                                        <td><?= htmlspecialchars($inc['jenis_insiden']) ?></td>
                                        <td><span class="<?= severityBadge($inc['severity']) ?>"><?= htmlspecialchars($inc['severity']) ?></span></td>
                                        <td><span class="badge-secondary"><?= htmlspecialchars($inc['status_tindak_lanjut']) ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div class="mt-3 text-end">
                    <a href="insiden.php" class="btn btn-outline-secondary btn-sm">Lihat Semua Insiden <i class="bi bi-arrow-right"></i></a>
                </div>
            </div>
        </div>

        <!-- Sidebar Widgets -->
        <div class="col-lg-4 col-12">
            <div class="card-box">
                <h5 class="mb-4 fw-bold">Pintasan HSE (Quick Actions)</h5>
                <div class="d-grid gap-2">
                    <a href="absensi.php" class="btn-primary-custom w-100"><i class="bi bi-clock-history"></i> Absensi Hari Ini</a>
                    <a href="insiden.php" class="btn-danger-custom w-100"><i class="bi bi-cone-striped"></i> Laporkan Insiden K3</a>
                    <a href="reimburse.php" class="btn-secondary-custom w-100"><i class="bi bi-cash-coin"></i> Ajukan Reimbursement</a>
                    <a href="cuti.php" class="btn-secondary-custom w-100"><i class="bi bi-calendar-x"></i> Ajukan Cuti</a>
                    <a href="input_hasil.php" class="btn-secondary-custom w-100"><i class="bi bi-file-earmark-medical"></i> Input Hasil Pemeriksaan</a>
                </div>
            </div>

            <div class="card-box mt-4">
                <h5 class="mb-3 fw-bold">Sertifikat Terdekat Kedaluwarsa</h5>
                <?php if (!$sertifikat_terdekat): ?>
                    <p class="text-muted mb-0">Belum ada data sertifikat.</p>
                <?php else: ?>
                    <?php
                    $sisaHari = round((strtotime($sertifikat_terdekat['tanggal_kedaluwarsa']) - strtotime($today)) / 86400);
                    $badge = $sisaHari <= 30 ? 'badge-danger' : ($sisaHari <= 60 ? 'badge-warning' : 'badge-success');
                    ?>
                    <div class="border rounded p-3 bg-white">
                        <div class="d-flex align-items-center justify-content-between mb-2">
                            <span class="fw-bold fs-7"><?= htmlspecialchars($sertifikat_terdekat['bidang_keahlian']) ?></span>
                            <span class="<?= $badge ?>"><?= $sisaHari > 0 ? $sisaHari . ' hari lagi' : 'Kedaluwarsa' ?></span>
                        </div>
                        <span class="text-secondary fs-7 d-block mb-3">
                            No. <?= htmlspecialchars($sertifikat_terdekat['nomor_sertifikat']) ?> —
                            Berlaku s.d. <?= date('d-m-Y', strtotime($sertifikat_terdekat['tanggal_kedaluwarsa'])) ?>
                        </span>
                        <a href="sertifikat_ahli.php" class="btn-primary-custom w-100 d-block text-center" style="height:36px; line-height:36px; text-decoration:none;">
                            <i class="bi bi-award"></i> Kelola Sertifikat
                        </a>
                    </div>
                <?php endif; ?>
            </div>

            <div class="card-box mt-4">
                <h5 class="mb-3 fw-bold">Ringkasan Modul Lainnya</h5>
                <div class="d-flex flex-column gap-2">
                    <a href="rekomendasi.php" class="d-flex align-items-center justify-content-between text-decoration-none p-2 rounded" style="color: var(--text-primary); background:#f8fafc;">
                        <span><i class="bi bi-exclamation-triangle me-2 text-warning"></i>Rekomendasi Teknis</span>
                        <i class="bi bi-chevron-right text-muted"></i>
                    </a>
                    <a href="riwayat.php" class="d-flex align-items-center justify-content-between text-decoration-none p-2 rounded" style="color: var(--text-primary); background:#f8fafc;">
                        <span><i class="bi bi-journal-text me-2 text-primary"></i>Riwayat Pemeriksaan</span>
                        <i class="bi bi-chevron-right text-muted"></i>
                    </a>
                    <a href="upload.php" class="d-flex align-items-center justify-content-between text-decoration-none p-2 rounded" style="color: var(--text-primary); background:#f8fafc;">
                        <span><i class="bi bi-upload me-2 text-primary"></i>Upload Dokumentasi</span>
                        <i class="bi bi-chevron-right text-muted"></i>
                    </a>
                    <a href="surat.php" class="d-flex align-items-center justify-content-between text-decoration-none p-2 rounded" style="color: var(--text-primary); background:#f8fafc;">
                        <span><i class="bi bi-envelope me-2 text-primary"></i>Manajemen Surat</span>
                        <i class="bi bi-chevron-right text-muted"></i>
                    </a>
                    <a href="transportasi.php" class="d-flex align-items-center justify-content-between text-decoration-none p-2 rounded" style="color: var(--text-primary); background:#f8fafc;">
                        <span><i class="bi bi-truck me-2 text-primary"></i>Transportasi</span>
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
    <?php if (array_sum($hasilData) > 0): ?>
    const ctxHasil = document.getElementById('chartHasil');
    if (ctxHasil) {
        new Chart(ctxHasil, {
            type: 'doughnut',
            data: {
                labels: <?= json_encode($hasilLabels) ?>,
                datasets: [{
                    data: <?= json_encode($hasilData) ?>,
                    backgroundColor: ['#2ecc71', '#f1c40f', '#e74c3c'],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: { position: 'bottom', labels: { font: { family: 'Arial' } } }
                }
            }
        });
    }
    <?php endif; ?>

    const ctxJadwal = document.getElementById('chartJadwal');
    if (ctxJadwal) {
        new Chart(ctxJadwal, {
            type: 'bar',
            data: {
                labels: <?= json_encode($bulanLabels) ?>,
                datasets: [{
                    label: 'Jumlah Jadwal Pemeriksaan',
                    data: <?= json_encode($bulanDataValues) ?>,
                    backgroundColor: '#2C9A75',
                    borderRadius: 6,
                    maxBarThickness: 40
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    y: { beginAtZero: true, ticks: { stepSize: 1 } }
                }
            }
        });
    }
});
</script>

<?php
include "../includes/footer.php";
?>