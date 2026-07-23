<?php
// direksi/insiden.php
session_start();
require_once "../config/koneksi.php";

$page_title = "Laporan Insiden K3";

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

$total_baru        = safe_count($conn, "SELECT COUNT(*) FROM Laporan_Insiden WHERE status = 'Baru'");
$total_investigasi = safe_count($conn, "SELECT COUNT(*) FROM Laporan_Insiden WHERE status = 'Investigasi'");
$total_tindaklanjut = safe_count($conn, "SELECT COUNT(*) FROM Laporan_Insiden WHERE status = 'Tindak Lanjut'");
$total_selesai      = safe_count($conn, "SELECT COUNT(*) FROM Laporan_Insiden WHERE status = 'Selesai'");

$status_filter = $_GET['status'] ?? 'Semua';
$valid_statuses = ['Baru', 'Investigasi', 'Tindak Lanjut', 'Selesai', 'Semua'];
if (!in_array($status_filter, $valid_statuses, true)) {
    $status_filter = 'Semua';
}

$daftar_insiden = [];
try {
    $sql = "
        SELECT li.*, dk.nama_perusahaan, u1.nama_lengkap AS nama_pelapor, u2.nama_lengkap AS nama_penanggung
        FROM Laporan_Insiden li
        LEFT JOIN Data_Klien dk ON li.klien_id = dk.id
        LEFT JOIN Users u1 ON li.dilaporkan_oleh = u1.id
        LEFT JOIN Users u2 ON li.ditangani_oleh = u2.id
    ";
    $params = [];
    if ($status_filter !== 'Semua') {
        $sql .= " WHERE li.status = :status ";
        $params[':status'] = $status_filter;
    }
    $sql .= " ORDER BY li.tanggal_kejadian DESC";

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $daftar_insiden = $stmt->fetchAll();
} catch (PDOException $e) {
    $daftar_insiden = [];
}

function badge_status_insiden(string $status): string
{
    switch ($status) {
        case 'Baru':          return 'badge-danger';
        case 'Investigasi':   return 'badge-warning';
        case 'Tindak Lanjut': return 'badge-info';
        case 'Selesai':       return 'badge-success';
        default:              return 'badge-secondary';
    }
}
function badge_keparahan(string $tingkat): string
{
    switch ($tingkat) {
        case 'Ringan': return 'badge-success';
        case 'Sedang': return 'badge-warning';
        case 'Berat':  return 'badge-danger';
        case 'Fatal':  return 'badge-danger';
        default:       return 'badge-secondary';
    }
}

include "../includes/header.php";
include "../includes/sidebar.php";
include "../includes/topbar.php";
?>

<main class="main-content">
    <div class="row g-4 mb-4">
        <div class="col-xl-3 col-md-6 col-12">
            <div class="stat-card">
                <div class="stat-card-info">
                    <span class="stat-card-title">Baru</span>
                    <span class="stat-card-value"><?= $total_baru ?></span>
                </div>
                <div class="stat-card-icon danger"><i class="bi bi-exclamation-triangle-fill"></i></div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6 col-12">
            <div class="stat-card">
                <div class="stat-card-info">
                    <span class="stat-card-title">Investigasi</span>
                    <span class="stat-card-value"><?= $total_investigasi ?></span>
                </div>
                <div class="stat-card-icon warning"><i class="bi bi-search"></i></div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6 col-12">
            <div class="stat-card">
                <div class="stat-card-info">
                    <span class="stat-card-title">Tindak Lanjut</span>
                    <span class="stat-card-value"><?= $total_tindaklanjut ?></span>
                </div>
                <div class="stat-card-icon"><i class="bi bi-clipboard2-check"></i></div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6 col-12">
            <div class="stat-card">
                <div class="stat-card-info">
                    <span class="stat-card-title">Selesai</span>
                    <span class="stat-card-value"><?= $total_selesai ?></span>
                </div>
                <div class="stat-card-icon success"><i class="bi bi-check2-square"></i></div>
            </div>
        </div>
    </div>

    <div class="card-box">
        <div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-3">
            <h5 class="mb-0 fw-bold">Daftar Laporan Insiden K3</h5>
            <form method="GET" class="d-flex gap-2">
                <select class="select-custom" name="status" style="width: 200px;" onchange="this.form.submit()">
                    <?php foreach ($valid_statuses as $opt): ?>
                        <option value="<?= htmlspecialchars($opt) ?>" <?= $status_filter === $opt ? 'selected' : '' ?>>
                            <?= $opt === 'Semua' ? 'Semua Status' : htmlspecialchars($opt) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>

        <div class="table-responsive-custom">
            <table class="table-custom">
                <thead>
                    <tr>
                        <th>No</th>
                        <th>Kode</th>
                        <th>Tanggal</th>
                        <th>Judul / Klien</th>
                        <th>Kategori</th>
                        <th>Keparahan</th>
                        <th>Penanggung Jawab</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($daftar_insiden)): ?>
                        <tr><td colspan="8" class="text-center text-muted py-4">Tidak ada data insiden untuk status ini.</td></tr>
                    <?php else: ?>
                        <?php foreach ($daftar_insiden as $i => $li): ?>
                            <tr>
                                <td><?= $i + 1 ?></td>
                                <td><?= htmlspecialchars($li['kode_insiden']) ?></td>
                                <td><?= htmlspecialchars(date('d M Y', strtotime($li['tanggal_kejadian']))) ?></td>
                                <td>
                                    <?= htmlspecialchars($li['judul_insiden']) ?>
                                    <div class="fs-7 text-muted"><?= htmlspecialchars($li['nama_perusahaan'] ?? $li['lokasi']) ?></div>
                                </td>
                                <td class="fs-7"><?= htmlspecialchars($li['kategori_insiden']) ?></td>
                                <td><span class="<?= badge_keparahan($li['tingkat_keparahan']) ?>"><?= htmlspecialchars($li['tingkat_keparahan']) ?></span></td>
                                <td class="fs-7"><?= htmlspecialchars($li['nama_penanggung'] ?? 'Belum ditugaskan') ?></td>
                                <td><span class="<?= badge_status_insiden($li['status']) ?>"><?= htmlspecialchars($li['status']) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>

<?php
include "../includes/footer.php";
?>
