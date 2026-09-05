<?php
// client/dashboard.php
session_start();

// Auth guard: pastikan sudah login dan role-nya memang client
if (empty($_SESSION['login']) || ($_SESSION['role'] ?? '') !== 'client') {
    header("Location: ../login.php");
    exit;
}

require_once "../config/koneksi.php";

$page_title = "Dashboard Client";

$user_id = $_SESSION['user_id'];

// ================== AMBIL DATA KLIEN YANG SEDANG LOGIN ==================
$nama_perusahaan = "Perusahaan Anda";
$klien_id = null;
try {
    $stmt = $conn->prepare("SELECT id, nama_perusahaan FROM Data_Klien WHERE user_id = :user_id LIMIT 1");
    $stmt->execute([':user_id' => $user_id]);
    $klien = $stmt->fetch();
    if ($klien) {
        $klien_id = (int) $klien['id'];
        $nama_perusahaan = $klien['nama_perusahaan'];
    }
} catch (PDOException $e) {
    // Biarkan default jika query gagal
}

// ================== HELPER QUERY AMAN ==================
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
$pemeriksaan_berjalan = 0;
$menunggu_approval    = 0;
$dokumen_digital      = 0;
$total_pemeriksaan    = 0;

if ($klien_id) {
    $pemeriksaan_berjalan = safe_count($conn, "
        SELECT COUNT(*) FROM Pengajuan_Pemeriksaan
        WHERE klien_id = :klien_id AND status IN ('Diverifikasi','Dijadwalkan')
    ", [':klien_id' => $klien_id]);

    $menunggu_approval = safe_count($conn, "
        SELECT COUNT(*) FROM Pengajuan_Pemeriksaan
        WHERE klien_id = :klien_id AND status = 'Menunggu Verifikasi'
    ", [':klien_id' => $klien_id]);

    $dokumen_digital = safe_count($conn, "
        SELECT COUNT(*) FROM Dokumen_Digital WHERE klien_id = :klien_id
    ", [':klien_id' => $klien_id]);

    $total_pemeriksaan = safe_count($conn, "
        SELECT COUNT(*) FROM Suket_K3 WHERE klien_id = :klien_id
    ", [':klien_id' => $klien_id]);
}

// ================== PEMERIKSAAN TERDEKAT (Jadwal_Pemeriksaan) ==================
$pemeriksaan_terdekat = null;
if ($klien_id) {
    $rows = safe_query($conn, "
        SELECT jp.tanggal, jp.jam_mulai, jp.lokasi, pp.jenis_objek
        FROM Jadwal_Pemeriksaan jp
        LEFT JOIN Pengajuan_Pemeriksaan pp ON pp.id = jp.pengajuan_id
        WHERE jp.klien_id = :klien_id
          AND jp.tanggal >= CURDATE()
          AND jp.status IN ('Terjadwal','Berlangsung')
        ORDER BY jp.tanggal ASC, jp.jam_mulai ASC
        LIMIT 1
    ", [':klien_id' => $klien_id]);
    $pemeriksaan_terdekat = $rows[0] ?? null;
}

// ================== STATUS PENGAJUAN (ringkasan per status) ==================
$status_summary = [
    'Menunggu Verifikasi' => 0,
    'Dijadwalkan'         => 0,
    'Diverifikasi'        => 0,
    'Selesai'             => 0,
];
if ($klien_id) {
    $rows = safe_query($conn, "
        SELECT status, COUNT(*) AS jumlah
        FROM Pengajuan_Pemeriksaan
        WHERE klien_id = :klien_id
        GROUP BY status
    ", [':klien_id' => $klien_id]);
    foreach ($rows as $row) {
        if (isset($status_summary[$row['status']])) {
            $status_summary[$row['status']] = (int) $row['jumlah'];
        }
    }
}

// ================== DOKUMEN DIGITAL TERBARU (dari Suket_K3) ==================
$dokumen_terbaru = [];
if ($klien_id) {
    $dokumen_terbaru = safe_query($conn, "
        SELECT sk.nomor_laporan AS nama_dokumen,
               sk.file_sertifikat_pdf AS file_path,
               COALESCE(sk.tanggal_pemeriksaan, sk.created_at) AS created_at
        FROM Suket_K3 sk
        WHERE sk.klien_id = :klien_id
          AND sk.file_sertifikat_pdf IS NOT NULL
          AND sk.file_sertifikat_pdf <> ''
        ORDER BY sk.created_at DESC
        LIMIT 3
    ", [':klien_id' => $klien_id]);
}

include "../includes/header.php";
include "../includes/sidebar.php";
include "../includes/topbar.php";
?>

<main class="main-content">
    <!-- Welcome Banner -->
    <div class="mb-4">
        <p class="text-muted mb-1">Selamat datang,</p>
        <h4 class="fw-bold mb-1"><?= htmlspecialchars($nama_perusahaan) ?></h4>
        <p class="text-muted mb-0">Berikut ringkasan layanan pemeriksaan K3 perusahaan Anda.</p>
    </div>

    <?php if (!$klien_id): ?>
        <div class="alert alert-danger-custom mb-4">
            <i class="bi bi-exclamation-triangle-fill fs-5"></i>
            <div>Akun Anda belum terhubung dengan data perusahaan klien. Lengkapi terlebih dahulu data Anda pada Profile.Lalu hubungi Admin untuk menautkan akun.</div>
        </div>
    <?php endif; ?>

    <!-- Stat Cards Section -->
    <div class="row g-4 mb-4">
        <div class="col-xl-3 col-md-6 col-12">
            <div class="stat-card">
                <div class="stat-card-info">
                    <span class="stat-card-title">Pemeriksaan Berjalan</span>
                    <span class="stat-card-value"><?= number_format($pemeriksaan_berjalan, 0, ',', '.') ?></span>
                </div>
                <div class="stat-card-icon success">
                    <i class="bi bi-file-earmark-check-fill"></i>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6 col-12">
            <div class="stat-card">
                <div class="stat-card-info">
                    <span class="stat-card-title">Menunggu Approval</span>
                    <span class="stat-card-value"><?= number_format($menunggu_approval, 0, ',', '.') ?></span>
                </div>
                <div class="stat-card-icon warning">
                    <i class="bi bi-hourglass-split"></i>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6 col-12">
            <div class="stat-card">
                <div class="stat-card-info">
                    <span class="stat-card-title">Dokumen Digital</span>
                    <span class="stat-card-value"><?= number_format($dokumen_digital, 0, ',', '.') ?></span>
                </div>
                <div class="stat-card-icon">
                    <i class="bi bi-folder2-open"></i>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6 col-12">
            <div class="stat-card">
                <div class="stat-card-info">
                    <span class="stat-card-title">Total Pemeriksaan</span>
                    <span class="stat-card-value"><?= number_format($total_pemeriksaan, 0, ',', '.') ?></span>
                </div>
                <div class="stat-card-icon success">
                    <i class="bi bi-clipboard2-data-fill"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Middle Row: Upcoming Inspection & Status Summary -->
    <div class="row g-4 mb-4">
        <div class="col-lg-6 col-12">
            <div class="card-box h-100">
                <h5 class="fw-bold mb-4">Pemeriksaan Terdekat</h5>
                <?php if ($pemeriksaan_terdekat): ?>
                    <div class="d-flex align-items-center gap-3">
                        <div class="illustration-box">
                            <i class="bi bi-clipboard2-check"></i>
                        </div>
                        <div class="flex-grow-1">
                            <div class="fw-bold mb-1"><?= htmlspecialchars($pemeriksaan_terdekat['jenis_objek'] ?? 'Objek K3') ?></div>
                            <div class="text-muted fs-7 mb-1">
                                <i class="bi bi-calendar-event me-1"></i>
                                <?= date('d F Y', strtotime($pemeriksaan_terdekat['tanggal'])) ?> .
                                <?= date('H.i', strtotime($pemeriksaan_terdekat['jam_mulai'])) ?> WIB
                            </div>
                            <div class="text-muted fs-7">
                                <i class="bi bi-geo-alt me-1"></i> Lokasi : <?= htmlspecialchars($pemeriksaan_terdekat['lokasi'] ?? '-') ?>
                            </div>
                        </div>
                    </div>
                    <a href="status.php" class="btn-primary-custom mt-4">
                        <i class="bi bi-eye"></i> Lihat Detail
                    </a>
                <?php else: ?>
                    <p class="text-muted fs-7 mb-0">Belum ada jadwal pemeriksaan terdekat.</p>
                <?php endif; ?>
            </div>
        </div>

        <div class="col-lg-6 col-12">
            <div class="card-box h-100">
                <h5 class="fw-bold mb-4">Status Pengajuan</h5>
                <div class="status-list">
                    <div class="status-list-item">
                        <span class="status-label"><span class="status-dot dot-warning"></span> Menunggu Approval</span>
                        <span class="status-value"><?= $status_summary['Menunggu Verifikasi'] ?></span>
                    </div>
                    <div class="status-list-item">
                        <span class="status-label"><span class="status-dot dot-primary"></span> Dijadwalkan</span>
                        <span class="status-value"><?= $status_summary['Dijadwalkan'] ?></span>
                    </div>
                    <div class="status-list-item">
                        <span class="status-label"><span class="status-dot dot-info"></span> Diverifikasi</span>
                        <span class="status-value"><?= $status_summary['Diverifikasi'] ?></span>
                    </div>
                    <div class="status-list-item">
                        <span class="status-label"><span class="status-dot dot-success"></span> Selesai</span>
                        <span class="status-value"><?= $status_summary['Selesai'] ?></span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Latest Digital Documents -->
    <div class="card-box">
        <div class="d-flex align-items-center justify-content-between mb-3">
            <h5 class="fw-bold mb-0">Dokumen Digital Terbaru</h5>
            <a href="suket.php" class="text-decoration-none fs-7 fw-semibold" style="color: var(--primary);">Lihat Semua</a>
        </div>

        <?php if (empty($dokumen_terbaru)): ?>
            <p class="text-muted fs-7 mb-0">Belum ada dokumen digital yang tersedia.</p>
        <?php else: ?>
            <?php foreach ($dokumen_terbaru as $dok): ?>
                <div class="doc-list-item">
                    <div class="d-flex align-items-center gap-3">
                        <div class="doc-list-icon"><i class="bi bi-file-earmark-pdf-fill"></i></div>
                        <div class="doc-list-info">
                            <div class="doc-list-title"><?= htmlspecialchars($dok['nama_dokumen']) ?></div>
                            <div class="doc-list-subtitle">Terbit <?= date('d F Y', strtotime($dok['created_at'])) ?></div>
                        </div>
                    </div>
                    <a href="../<?= htmlspecialchars($dok['file_path']) ?>" class="btn-primary-custom"
                        style="height:34px; padding: 0 14px; font-size:0.8rem;" download>Download</a>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</main>

<?php
include "../includes/footer.php";
?>