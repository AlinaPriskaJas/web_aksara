<?php
// client/status.php
session_start();
require_once "../config/koneksi.php";

$page_title = "Status Pengajuan";

// TODO: ganti dengan user_id dari sesi login sebenarnya setelah proses_login.php terhubung penuh.
$user_id = $_SESSION['user_id'] ?? 1;

// ================== AMBIL KLIEN_ID DARI USER YANG LOGIN ==================
$klien_id = null;
try {
    $stmt = $conn->prepare("SELECT id FROM Data_Klien WHERE user_id = :user_id LIMIT 1");
    $stmt->execute([':user_id' => $user_id]);
    $klien = $stmt->fetch();
    if ($klien) {
        $klien_id = (int) $klien['id'];
    }
} catch (PDOException $e) {
    $klien_id = null;
}

// ================== PENCARIAN (opsional, dari search box) ==================
$keyword = trim($_GET['q'] ?? '');

// ================== AMBIL SEMUA PENGAJUAN + STATUS JADWAL TERBARU ==================
$daftar_pengajuan = [];
if ($klien_id) {
    try {
        $sql = "
            SELECT pp.id, pp.klasifikasi_objek_k3, pp.jenis_objek, pp.jenis_pemeriksaan,
                   pp.tanggal_diinginkan, pp.status, pp.created_at,
                   (SELECT jp.status
                    FROM Jadwal_Pemeriksaan jp
                    WHERE jp.pengajuan_id = pp.id
                    ORDER BY jp.created_at DESC
                    LIMIT 1) AS status_jadwal
            FROM Pengajuan_Pemeriksaan pp
            WHERE pp.klien_id = :klien_id
        ";
        $params = [':klien_id' => $klien_id];

        if ($keyword !== '') {
            $sql .= " AND (pp.klasifikasi_objek_k3 LIKE :kw OR pp.jenis_objek LIKE :kw OR pp.jenis_pemeriksaan LIKE :kw)";
            $params[':kw'] = '%' . $keyword . '%';
        }

        $sql .= " ORDER BY pp.created_at DESC";

        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        $daftar_pengajuan = $stmt->fetchAll();
    } catch (PDOException $e) {
        $daftar_pengajuan = [];
    }
}

// ================== HITUNG STATUS DISPLAY TIAP BARIS + RINGKASAN STAT CARD ==================
$stat = [
    'menunggu_approval' => 0,
    'dijadwalkan'       => 0,
    'sedang_diperiksa'  => 0,
    'ditolak'           => 0,
];

foreach ($daftar_pengajuan as &$row) {
    switch ($row['status']) {
        case 'Menunggu Verifikasi':
            $row['status_display'] = 'Menunggu Approval';
            $row['status_badge']   = 'badge-warning';
            $stat['menunggu_approval']++;
            break;

        case 'Diverifikasi':
            $row['status_display'] = 'Diverifikasi';
            $row['status_badge']   = 'badge-info';
            break;

        case 'Dijadwalkan':
            if ($row['status_jadwal'] === 'Berlangsung') {
                $row['status_display'] = 'Sedang Diperiksa';
                $row['status_badge']   = 'badge-success';
                $stat['sedang_diperiksa']++;
            } else {
                $row['status_display'] = 'Dijadwalkan';
                $row['status_badge']   = 'badge-info';
                $stat['dijadwalkan']++;
            }
            break;

        case 'Ditolak':
            $row['status_display'] = 'Ditolak';
            $row['status_badge']   = 'badge-danger';
            $stat['ditolak']++;
            break;

        case 'Selesai':
            $row['status_display'] = 'Selesai';
            $row['status_badge']   = 'badge-success';
            break;

        default:
            $row['status_display'] = $row['status'];
            $row['status_badge']   = 'badge-secondary';
    }
}
unset($row); // putus referensi foreach di atas

include "../includes/header.php";
include "../includes/sidebar.php";
include "../includes/topbar.php";
?>

<main class="main-content">
    <!-- Ringkasan Status -->
    <div class="row g-4 mb-4">
        <div class="col-xl-3 col-md-6 col-12">
            <div class="stat-card">
                <div class="stat-card-info">
                    <span class="stat-card-title">Menunggu Approval</span>
                    <span class="stat-card-value"><?= $stat['menunggu_approval'] ?></span>
                </div>
                <div class="stat-card-icon warning"><i class="bi bi-hourglass-split"></i></div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6 col-12">
            <div class="stat-card">
                <div class="stat-card-info">
                    <span class="stat-card-title">Dijadwalkan</span>
                    <span class="stat-card-value"><?= $stat['dijadwalkan'] ?></span>
                </div>
                <div class="stat-card-icon"><i class="bi bi-calendar-event"></i></div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6 col-12">
            <div class="stat-card">
                <div class="stat-card-info">
                    <span class="stat-card-title">Sedang Diperiksa</span>
                    <span class="stat-card-value"><?= $stat['sedang_diperiksa'] ?></span>
                </div>
                <div class="stat-card-icon success"><i class="bi bi-clipboard2-pulse"></i></div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6 col-12">
            <div class="stat-card">
                <div class="stat-card-info">
                    <span class="stat-card-title">Ditolak</span>
                    <span class="stat-card-value"><?= $stat['ditolak'] ?></span>
                </div>
                <div class="stat-card-icon danger"><i class="bi bi-x-circle"></i></div>
            </div>
        </div>
    </div>

    <!-- Table Status Pengajuan -->
    <div class="card-box">
        <div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-3">
            <h5 class="mb-0 fw-bold">Status Pengajuan Pemeriksaan</h5>
            <div class="d-flex gap-2">
                <form method="GET" class="search-box-container" style="width: 220px;">
                    <i class="bi bi-search"></i>
                    <input type="text" name="q" class="search-box" placeholder="Cari pengajuan..."
                        value="<?= htmlspecialchars($keyword) ?>">
                </form>
                <a href="pengajuan.php" class="btn-primary-custom">
                    <i class="bi bi-plus-lg"></i> Ajukan Baru
                </a>
            </div>
        </div>

        <?php if (!$klien_id): ?>
            <div class="alert alert-danger-custom mb-0">
                <i class="bi bi-exclamation-triangle-fill fs-5"></i>
                <div>Akun Anda belum terhubung dengan data perusahaan klien. Lengkapi terlebih dahulu data Anda pada Profile.Lalu hubungi Admin untuk menautkan akun.</div>
            </div>
        <?php elseif (empty($daftar_pengajuan)): ?>
            <p class="text-muted fs-7 mb-0">
                <?= $keyword !== '' ? 'Tidak ada pengajuan yang cocok dengan pencarian.' : 'Belum ada pengajuan pemeriksaan.' ?>
            </p>
        <?php else: ?>
            <div class="table-responsive-custom">
                <table class="table-custom">
                    <thead>
                        <tr>
                            <th>No</th>
                            <th>Bidang Objek K3</th>
                            <th>Jenis Objek K3</th>
                            <th>Jenis Pemeriksaan</th>
                            <th>Tanggal Diajukan</th>
                            <th>Status</th>
                            <th style="text-align: center;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($daftar_pengajuan as $i => $row): ?>
                            <tr>
                                <td><?= $i + 1 ?></td>
                                <td><?= htmlspecialchars($row['klasifikasi_objek_k3']) ?></td>
                                <td><?= htmlspecialchars($row['jenis_objek'] ?? '-') ?></td>
                                <td><?= htmlspecialchars($row['jenis_pemeriksaan']) ?></td>
                                <td><?= date('d F Y', strtotime($row['created_at'])) ?></td>
                                <td><span class="<?= $row['status_badge'] ?>"><?= htmlspecialchars($row['status_display']) ?></span></td>
                                <td style="text-align: center;">
                                    <?php if ($row['status'] !== 'Menunggu Verifikasi'): ?>
                                        <a href="riwayat.php?id=<?= (int) $row['id'] ?>" class="btn-primary-custom"
                                            style="height:32px; padding:0 12px; font-size:0.8rem;">
                                            <i class="bi bi-eye"></i> Detail
                                        </a>
                                    <?php else: ?>
                                        <span class="text-muted fs-7">-</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="pagination-custom">
                <span class="text-muted fs-7">Menampilkan <?= count($daftar_pengajuan) ?> dari <?= count($daftar_pengajuan) ?> data</span>
            </div>
        <?php endif; ?>
    </div>
</main>

<?php
include "../includes/footer.php";
?>