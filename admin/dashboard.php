<?php
// admin/dashboard.php
session_start();

// Auth guard: pastikan sudah login dan role-nya memang admin
if (empty($_SESSION['login']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header("Location: ../login.php");
    exit;
}

require_once "../config/koneksi.php";

$page_title = "Dashboard Admin";

$user_id   = $_SESSION['user_id'];
$user_name = $_SESSION['nama_lengkap'] ?? 'Admin';

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

// Helper query aman: kembalikan default kalau tabel/kolom belum tersedia,
// supaya dashboard tidak error walau data belum lengkap
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

// Badge status generik dipakai di beberapa tabel/list
function badgeClass(?string $status): string
{
    $map = [
        'Menunggu Verifikasi' => 'badge-warning',
        'Menunggu'            => 'badge-warning',
        'Diverifikasi'        => 'badge-info',
        'Dijadwalkan'         => 'badge-info',
        'Disetujui'           => 'badge-success',
        'Selesai'             => 'badge-success',
        'Aktif'               => 'badge-success',
        'Ditolak'             => 'badge-danger',
        'Kritis-Expired'      => 'badge-danger',
        'Peringatan Awal'     => 'badge-warning',
    ];
    return $map[$status] ?? 'badge-secondary';
}

// ================== STAT CARDS ==================
$menunggu_approval  = safe_count($conn, "SELECT COUNT(*) FROM Approval WHERE status = 'Menunggu'");
$verifikasi_baru    = safe_count($conn, "SELECT COUNT(*) FROM Pengajuan_Pemeriksaan WHERE status = 'Menunggu Verifikasi'");
$sertifikat_perhatian = safe_count($conn, "SELECT COUNT(*) FROM v_sertifikat_ahli_status WHERE status_realtime IN ('Peringatan Awal','Kritis-Expired')");
$jadwal_minggu_ini  = safe_count($conn, "
    SELECT COUNT(*) FROM Jadwal_Pemeriksaan
    WHERE tanggal BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
      AND status IN ('Terjadwal','Reschedule')
");

// ================== RINGKASAN OPERASIONAL ==================
$total_klien_aktif = safe_count($conn, "SELECT COUNT(*) FROM Data_Klien WHERE status = 'Aktif'");
$suket_bulan_ini    = safe_count($conn, "
    SELECT COUNT(*) FROM Suket_K3
    WHERE MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE())
");
$insiden_bulan_ini  = safe_count($conn, "
    SELECT COUNT(*) FROM Laporan_Insiden
    WHERE MONTH(tanggal_kejadian) = MONTH(CURDATE()) AND YEAR(tanggal_kejadian) = YEAR(CURDATE())
");
$kendaraan_dipakai  = safe_count($conn, "SELECT COUNT(*) FROM Kendaraan WHERE status_kendaraan = 'Dipakai'");

// ================== AKTIVITAS TERBARU ==================
$aktivitas_terbaru = safe_query($conn, "
    SELECT al.aksi, al.modul, al.waktu_kejadian, u.nama_lengkap
    FROM Audit_Log al
    LEFT JOIN Users u ON u.id = al.user_id
    ORDER BY al.waktu_kejadian DESC
    LIMIT 6
");

// ================== PENGAJUAN PEMERIKSAAN TERBARU ==================
$pengajuan_terbaru = safe_query($conn, "
    SELECT pp.id, pp.klasifikasi_objek_k3, pp.jenis_pemeriksaan, pp.tanggal_diinginkan,
           pp.status, pp.created_at, dk.nama_perusahaan
    FROM Pengajuan_Pemeriksaan pp
    LEFT JOIN Data_Klien dk ON dk.id = pp.klien_id
    ORDER BY pp.created_at DESC
    LIMIT 6
");

// ================== SERTIFIKAT AHLI PERLU PERHATIAN ==================
$sertifikat_list = safe_query($conn, "
    SELECT nama_lengkap, bidang_keahlian, tanggal_kedaluwarsa, status_realtime
    FROM v_sertifikat_ahli_status
    WHERE status_realtime IN ('Peringatan Awal','Kritis-Expired')
    ORDER BY tanggal_kedaluwarsa ASC
    LIMIT 5
");

// ================== STOK GUDANG MENIPIS ==================
$stok_menipis = safe_query($conn, "
    SELECT nama_barang, satuan, stok_sistem, stok_minimum
    FROM Gudang_Stok
    WHERE stok_minimum IS NOT NULL AND stok_sistem <= stok_minimum
    ORDER BY (stok_sistem - stok_minimum) ASC
    LIMIT 5
");

// ================== JADWAL PEMERIKSAAN MINGGU INI ==================
$jadwal_list = safe_query($conn, "
    SELECT jp.tanggal, jp.jam_mulai, jp.lokasi, dk.nama_perusahaan, sa.nama_lengkap AS nama_ahli
    FROM Jadwal_Pemeriksaan jp
    LEFT JOIN Data_Klien dk ON dk.id = jp.klien_id
    LEFT JOIN Sertifikat_Ahli sa ON sa.id = jp.ahli_k3_id
    WHERE jp.tanggal BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
      AND jp.status IN ('Terjadwal','Reschedule')
    ORDER BY jp.tanggal ASC, jp.jam_mulai ASC
    LIMIT 5
");

// ================== GRAFIK: PENGAJUAN PEMERIKSAAN PER BULAN (TAHUN BERJALAN) ==================
$tahun_ini = date('Y');
$pengajuan_per_bulan = array_fill(1, 12, 0);
$rows = safe_query($conn, "
    SELECT MONTH(created_at) AS bln, COUNT(*) AS jumlah
    FROM Pengajuan_Pemeriksaan
    WHERE YEAR(created_at) = :tahun
    GROUP BY MONTH(created_at)
", [':tahun' => $tahun_ini]);
foreach ($rows as $row) {
    $pengajuan_per_bulan[(int) $row['bln']] = (int) $row['jumlah'];
}
$bulan_labels = ['Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agu','Sep','Okt','Nov','Des'];
$bulan_values = array_values($pengajuan_per_bulan);

// ================== DISTRIBUSI STATUS PENGAJUAN PEMERIKSAAN ==================
$status_rows = safe_query($conn, "
    SELECT status, COUNT(*) AS jumlah
    FROM Pengajuan_Pemeriksaan
    GROUP BY status
");
$status_summary = [
    'Menunggu Verifikasi' => 0,
    'Diverifikasi'        => 0,
    'Dijadwalkan'         => 0,
    'Selesai'             => 0,
    'Ditolak'             => 0,
];
foreach ($status_rows as $row) {
    if (isset($status_summary[$row['status']])) {
        $status_summary[$row['status']] = (int) $row['jumlah'];
    }
}

// ================== ANTRIAN APPROVAL PER JENIS (Menunggu) ==================
$approval_jenis = safe_query($conn, "
    SELECT jenis_pengajuan, COUNT(*) AS jumlah
    FROM Approval
    WHERE status = 'Menunggu'
    GROUP BY jenis_pengajuan
    ORDER BY jumlah DESC
");
$approval_jenis_total = array_sum(array_column($approval_jenis, 'jumlah')) ?: 1;

include "../includes/header.php";
include "../includes/sidebar.php";
include "../includes/topbar.php";
?>

<main class="main-content">

    <!-- Greeting -->
    <div class="mb-4">
        <h4 class="fw-bold mb-1"><?= htmlspecialchars($sapaan) ?>, <?= htmlspecialchars($user_name) ?></h4>
        <p class="text-secondary mb-0">Ringkasan operasional hari ini, <?= date('d M Y') ?></p>
    </div>

    <!-- Stat Cards -->
    <div class="row g-4 mb-4">
        <div class="col-xl-3 col-md-6 col-12">
            <a href="approval.php" class="text-decoration-none text-reset">
                <div class="stat-card">
                    <div class="stat-card-info">
                        <span class="stat-card-title">Menunggu Approval</span>
                        <span class="stat-card-value"><?= number_format($menunggu_approval, 0, ',', '.') ?></span>
                    </div>
                    <div class="stat-card-icon warning">
                        <i class="bi bi-check-circle-fill"></i>
                    </div>
                </div>
            </a>
        </div>

        <div class="col-xl-3 col-md-6 col-12">
            <a href="approval.php?tab=pemeriksaan" class="text-decoration-none text-reset">
                <div class="stat-card">
                    <div class="stat-card-info">
                        <span class="stat-card-title">Verifikasi Pengajuan Baru</span>
                        <span class="stat-card-value"><?= number_format($verifikasi_baru, 0, ',', '.') ?></span>
                    </div>
                    <div class="stat-card-icon">
                        <i class="bi bi-file-earmark-medical-fill"></i>
                    </div>
                </div>
            </a>
        </div>

        <div class="col-xl-3 col-md-6 col-12">
            <a href="sertifikat_ahli.php" class="text-decoration-none text-reset">
                <div class="stat-card">
                    <div class="stat-card-info">
                        <span class="stat-card-title">Sertifikat Perlu Perhatian</span>
                        <span class="stat-card-value"><?= number_format($sertifikat_perhatian, 0, ',', '.') ?></span>
                    </div>
                    <div class="stat-card-icon danger">
                        <i class="bi bi-award-fill"></i>
                    </div>
                </div>
            </a>
        </div>

        <div class="col-xl-3 col-md-6 col-12">
            <a href="jadwal.php" class="text-decoration-none text-reset">
                <div class="stat-card">
                    <div class="stat-card-info">
                        <span class="stat-card-title">Jadwal Minggu Ini</span>
                        <span class="stat-card-value"><?= number_format($jadwal_minggu_ini, 0, ',', '.') ?></span>
                    </div>
                    <div class="stat-card-icon success">
                        <i class="bi bi-calendar-event-fill"></i>
                    </div>
                </div>
            </a>
        </div>
    </div>

    <!-- Ringkasan Operasional + Aktivitas Terbaru -->
    <div class="row g-4 mb-4">
        <div class="col-lg-8 col-12">
            <div class="card-box h-100">
                <h5 class="fw-bold mb-4">Ringkasan Operasional</h5>
                <div class="row g-3 text-center">
                    <div class="col-6 col-md-3 border-end">
                        <i class="bi bi-people fs-3 text-success mb-2 d-block"></i>
                        <div class="fw-bold fs-5"><?= number_format($total_klien_aktif, 0, ',', '.') ?></div>
                        <span class="text-secondary fs-7">Klien Aktif</span>
                    </div>
                    <div class="col-6 col-md-3 border-end">
                        <i class="bi bi-file-earmark-medical fs-3 text-primary mb-2 d-block"></i>
                        <div class="fw-bold fs-5"><?= number_format($suket_bulan_ini, 0, ',', '.') ?></div>
                        <span class="text-secondary fs-7">Suket Bulan Ini</span>
                    </div>
                    <div class="col-6 col-md-3 border-end">
                        <i class="bi bi-exclamation-triangle fs-3 text-warning mb-2 d-block"></i>
                        <div class="fw-bold fs-5"><?= number_format($insiden_bulan_ini, 0, ',', '.') ?></div>
                        <span class="text-secondary fs-7">Insiden Bulan Ini</span>
                    </div>
                    <div class="col-6 col-md-3">
                        <i class="bi bi-truck fs-3 text-danger mb-2 d-block"></i>
                        <div class="fw-bold fs-5"><?= number_format($kendaraan_dipakai, 0, ',', '.') ?></div>
                        <span class="text-secondary fs-7">Kendaraan Dipakai</span>
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
                    <ul class="list-unstyled mb-0">
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
            </div>
        </div>
    </div>

    <!-- Pengajuan Pemeriksaan Terbaru + Panel Peringatan -->
    <div class="row g-4 mb-4">
        <div class="col-lg-8 col-12">
            <div class="card-box h-100">
                <div class="d-flex align-items-center justify-content-between mb-4">
                    <h5 class="mb-0 fw-bold">Pengajuan Pemeriksaan Terbaru</h5>
                    <a href="approval.php?tab=pemeriksaan" class="fs-7 fw-semibold text-decoration-none">Lihat semua &rarr;</a>
                </div>

                <div class="table-responsive-custom">
                    <table class="table-custom">
                        <thead>
                            <tr>
                                <th>Klien</th>
                                <th>Klasifikasi Objek</th>
                                <th>Jenis</th>
                                <th>Tgl Diinginkan</th>
                                <th>Status</th>
                                <th style="text-align:center;">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($pengajuan_terbaru)): ?>
                                <tr>
                                    <td colspan="6" class="text-center py-3 text-muted">Belum ada pengajuan pemeriksaan.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($pengajuan_terbaru as $p): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($p['nama_perusahaan'] ?? '-') ?></td>
                                        <td><?= htmlspecialchars($p['klasifikasi_objek_k3']) ?></td>
                                        <td><?= htmlspecialchars($p['jenis_pemeriksaan']) ?></td>
                                        <td><?= $p['tanggal_diinginkan'] ? date('d M Y', strtotime($p['tanggal_diinginkan'])) : '-' ?></td>
                                        <td><span class="<?= badgeClass($p['status']) ?>"><?= htmlspecialchars($p['status']) ?></span></td>
                                        <td style="text-align:center;">
                                            <?php if ($p['status'] === 'Menunggu Verifikasi'): ?>
                                                <a href="approval.php?tab=pemeriksaan" class="btn-primary-custom"
                                                    style="height:32px; padding:0 12px; font-size:0.8rem; text-decoration:none; display:inline-block; line-height:32px;">
                                                    Verifikasi
                                                </a>
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
        </div>

        <div class="col-lg-4 col-12">
            <div class="card-box mb-4">
                <div class="d-flex align-items-center justify-content-between mb-3">
                    <h5 class="mb-0 fw-bold">Sertifikat Perlu Perhatian</h5>
                    <a href="sertifikat_ahli.php" class="fs-7 fw-semibold text-decoration-none">Detail &rarr;</a>
                </div>
                <?php if (empty($sertifikat_list)): ?>
                    <p class="text-secondary fs-7 mb-0">Semua sertifikat ahli dalam kondisi aktif.</p>
                <?php else: ?>
                    <ul class="list-unstyled mb-0">
                        <?php foreach ($sertifikat_list as $s): ?>
                            <li class="d-flex justify-content-between align-items-center mb-3">
                                <div>
                                    <div class="fs-7 fw-semibold mb-0"><?= htmlspecialchars($s['nama_lengkap']) ?></div>
                                    <div class="fs-7 text-muted"><?= htmlspecialchars($s['bidang_keahlian']) ?> · exp <?= date('d M Y', strtotime($s['tanggal_kedaluwarsa'])) ?></div>
                                </div>
                                <span class="<?= badgeClass($s['status_realtime']) ?>"><?= htmlspecialchars($s['status_realtime']) ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>

            <div class="card-box">
                <div class="d-flex align-items-center justify-content-between mb-3">
                    <h5 class="mb-0 fw-bold">Stok Gudang Menipis</h5>
                    <a href="stock.php" class="fs-7 fw-semibold text-decoration-none">Detail &rarr;</a>
                </div>
                <?php if (empty($stok_menipis)): ?>
                    <p class="text-secondary fs-7 mb-0">Semua stok barang masih aman.</p>
                <?php else: ?>
                    <ul class="list-unstyled mb-0">
                        <?php foreach ($stok_menipis as $b): ?>
                            <li class="d-flex justify-content-between align-items-center mb-3">
                                <div>
                                    <div class="fs-7 fw-semibold mb-0"><?= htmlspecialchars($b['nama_barang']) ?></div>
                                    <div class="fs-7 text-muted">Min. <?= (int) $b['stok_minimum'] ?> <?= htmlspecialchars($b['satuan']) ?></div>
                                </div>
                                <span class="badge-danger"><?= (int) $b['stok_sistem'] ?> <?= htmlspecialchars($b['satuan']) ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Grafik + Approval per Jenis + Jadwal Minggu Ini -->
    <div class="row g-4">
        <div class="col-lg-5 col-12">
            <div class="card-box h-100">
                <h5 class="fw-bold mb-3">Tren Pengajuan Pemeriksaan (<?= $tahun_ini ?>)</h5>
                <canvas id="chartPengajuan" height="220"></canvas>
            </div>
        </div>

        <div class="col-lg-4 col-12">
            <div class="card-box h-100">
                <h5 class="fw-bold mb-3">Antrian Approval per Jenis</h5>
                <?php if (empty($approval_jenis)): ?>
                    <p class="text-secondary fs-7 mb-0">Tidak ada approval yang menunggu.</p>
                <?php else: ?>
                    <?php foreach ($approval_jenis as $aj): ?>
                        <?php $persen = round(((int) $aj['jumlah'] / $approval_jenis_total) * 100); ?>
                        <div class="mb-3">
                            <div class="d-flex justify-content-between mb-1">
                                <span class="fs-7 fw-semibold"><?= htmlspecialchars($aj['jenis_pengajuan']) ?></span>
                                <span class="fs-7 fw-bold"><?= (int) $aj['jumlah'] ?></span>
                            </div>
                            <div class="progress" style="height: 6px; border-radius: 4px;">
                                <div class="progress-bar bg-warning" role="progressbar" style="width: <?= $persen ?>%"></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
                <a href="approval.php" class="fs-7 fw-semibold text-decoration-none d-inline-block mt-2">Buka Approval Center &rarr;</a>
            </div>
        </div>

        <div class="col-lg-3 col-12">
            <div class="card-box h-100">
                <h5 class="fw-bold mb-3">Jadwal 7 Hari Ke Depan</h5>
                <?php if (empty($jadwal_list)): ?>
                    <p class="text-secondary fs-7 mb-0">Belum ada jadwal pemeriksaan minggu ini.</p>
                <?php else: ?>
                    <ul class="list-unstyled mb-0">
                        <?php foreach ($jadwal_list as $j): ?>
                            <li class="mb-3">
                                <div class="fs-7 fw-bold mb-0"><?= date('d M', strtotime($j['tanggal'])) ?> · <?= substr($j['jam_mulai'], 0, 5) ?></div>
                                <div class="fs-7 text-muted"><?= htmlspecialchars($j['nama_perusahaan'] ?? '-') ?></div>
                                <div class="fs-7 text-muted">Ahli: <?= htmlspecialchars($j['nama_ahli'] ?? '-') ?></div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <a href="jadwal.php" class="fs-7 fw-semibold text-decoration-none">Lihat Jadwal &rarr;</a>
            </div>
        </div>
    </div>

    <!-- Aksi Cepat -->
    <div class="row g-4 mt-1">
        <div class="col-12">
            <div class="card-box">
                <h5 class="fw-bold mb-4">Aksi Cepat</h5>
                <div class="row g-3">
                    <div class="col-6 col-md-3">
                        <a href="approval.php" class="btn-primary-custom w-100 d-block text-center text-decoration-none" style="height:44px; line-height:44px;">
                            <i class="bi bi-check-circle"></i> Approval
                        </a>
                    </div>
                    <div class="col-6 col-md-3">
                        <a href="data_klien.php" class="btn-secondary-custom w-100 d-block text-center text-decoration-none" style="height:44px; line-height:44px;">
                            <i class="bi bi-people"></i> Data Klien
                        </a>
                    </div>
                    <div class="col-6 col-md-3">
                        <a href="jadwal.php" class="btn-secondary-custom w-100 d-block text-center text-decoration-none" style="height:44px; line-height:44px;">
                            <i class="bi bi-calendar-event"></i> Jadwal Pemeriksaan
                        </a>
                    </div>
                    <div class="col-6 col-md-3">
                        <a href="sertifikat_ahli.php" class="btn-secondary-custom w-100 d-block text-center text-decoration-none" style="height:44px; line-height:44px;">
                            <i class="bi bi-award"></i> Sertifikat Ahli
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>

<!-- Chart.js CDN -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const pengajuanCtx = document.getElementById('chartPengajuan');
    if (pengajuanCtx) {
        new Chart(pengajuanCtx, {
            type: 'bar',
            data: {
                labels: <?= json_encode($bulan_labels) ?>,
                datasets: [{
                    label: 'Pengajuan Pemeriksaan',
                    data: <?= json_encode($bulan_values) ?>,
                    backgroundColor: '#2ecc71',
                    borderRadius: 4,
                    maxBarThickness: 26
                }]
            },
            options: {
                plugins: { legend: { display: false } },
                scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }
            }
        });
    }
});
</script>

<?php
include "../includes/footer.php";
?>