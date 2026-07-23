<?php
// client/suket.php
session_start();
require_once "../config/koneksi.php";

$page_title = "Suket K3";

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

// ================== PENCARIAN & PAGINATION ==================
$keyword     = trim($_GET['q'] ?? '');
$per_page    = 5;
$page_now    = max(1, (int) ($_GET['page'] ?? 1));
$offset      = ($page_now - 1) * $per_page;

// ================== STATUS REALTIME (mengikuti pola v_sertifikat_ahli_status) ==================
// Aktif        : masih lebih dari 30 hari sebelum expiry
// Peringatan Awal : sisa <= 30 hari
// Kritis-Expired  : sudah lewat tanggal_expiry
$status_case_sql = "
    CASE
        WHEN sk.tanggal_expiry IS NULL THEN 'Belum Terbit'
        WHEN sk.tanggal_expiry < CURDATE() THEN 'Kritis-Expired'
        WHEN sk.tanggal_expiry <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN 'Peringatan Awal'
        ELSE 'Aktif'
    END
";

// ================== STAT CARD (dihitung terpisah, tidak kena filter search/pagination) ==================
$total_dokumen   = 0;
$total_aktif     = 0;
$total_peringatan = 0;

if ($klien_id) {
    try {
        $stmt = $conn->prepare("SELECT COUNT(*) FROM Suket_K3 WHERE klien_id = :klien_id");
        $stmt->execute([':klien_id' => $klien_id]);
        $total_dokumen = (int) $stmt->fetchColumn();

        $stmt = $conn->prepare("
            SELECT $status_case_sql AS status_realtime, COUNT(*) AS jumlah
            FROM Suket_K3 sk
            WHERE sk.klien_id = :klien_id
            GROUP BY status_realtime
        ");
        $stmt->execute([':klien_id' => $klien_id]);
        foreach ($stmt->fetchAll() as $row) {
            if ($row['status_realtime'] === 'Aktif') $total_aktif = (int) $row['jumlah'];
            if ($row['status_realtime'] === 'Peringatan Awal') $total_peringatan = (int) $row['jumlah'];
        }
    } catch (PDOException $e) {
        // biarkan tetap 0 jika gagal
    }
}

// ================== DAFTAR SUKET K3 (dengan search + pagination) ==================
$daftar_suket = [];
$total_data   = 0;

if ($klien_id) {
    try {
        $where = "WHERE sk.klien_id = :klien_id";
        $params = [':klien_id' => $klien_id];

        if ($keyword !== '') {
            $where .= " AND sk.nomor_laporan LIKE :kw";
            $params[':kw'] = '%' . $keyword . '%';
        }

        // Hitung total data untuk pagination
        $stmt = $conn->prepare("SELECT COUNT(*) FROM Suket_K3 sk $where");
        $stmt->execute($params);
        $total_data = (int) $stmt->fetchColumn();

        // Ambil data halaman aktif, join ke Objek_K3 & jenis_objek_k3 untuk nama objek
        $stmt = $conn->prepare("
            SELECT sk.id, sk.nomor_laporan, sk.tanggal_pemeriksaan, sk.tanggal_expiry,
                   sk.file_sertifikat_pdf,
                   COALESCE(ok.nama_unit, jok.nama_objek, '-') AS nama_objek,
                   $status_case_sql AS status_realtime
            FROM Suket_K3 sk
            LEFT JOIN Objek_K3 ok ON ok.id = sk.objek_id
            LEFT JOIN jenis_objek_k3 jok ON jok.id_jenis = ok.id_jenis
            $where
            ORDER BY sk.created_at DESC
            LIMIT :limit OFFSET :offset
        ");
        foreach ($params as $key => $val) {
            $stmt->bindValue($key, $val);
        }
        $stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $daftar_suket = $stmt->fetchAll();
    } catch (PDOException $e) {
        $daftar_suket = [];
    }
}

$total_halaman = max(1, (int) ceil($total_data / $per_page));

// Mapping status ke badge class
$badge_map = [
    'Aktif'            => 'badge-success',
    'Peringatan Awal'  => 'badge-warning',
    'Kritis-Expired'   => 'badge-danger',
    'Belum Terbit'     => 'badge-secondary',
];

include "../includes/header.php";
include "../includes/sidebar.php";
include "../includes/topbar.php";
?>

<main class="main-content">
    <div class="row g-4 mb-4">
        <div class="col-xl-4 col-md-6 col-12">
            <div class="stat-card">
                <div class="stat-card-info">
                    <span class="stat-card-title">Total Dokumen</span>
                    <span class="stat-card-value"><?= number_format($total_dokumen, 0, ',', '.') ?></span>
                </div>
                <div class="stat-card-icon"><i class="bi bi-folder2-open"></i></div>
            </div>
        </div>
        <div class="col-xl-4 col-md-6 col-12">
            <div class="stat-card">
                <div class="stat-card-info">
                    <span class="stat-card-title">Aktif</span>
                    <span class="stat-card-value"><?= number_format($total_aktif, 0, ',', '.') ?></span>
                </div>
                <div class="stat-card-icon success"><i class="bi bi-patch-check-fill"></i></div>
            </div>
        </div>
        <div class="col-xl-4 col-md-6 col-12">
            <div class="stat-card">
                <div class="stat-card-info">
                    <span class="stat-card-title">Akan Kedaluwarsa</span>
                    <span class="stat-card-value"><?= number_format($total_peringatan, 0, ',', '.') ?></span>
                </div>
                <div class="stat-card-icon warning"><i class="bi bi-exclamation-triangle-fill"></i></div>
            </div>
        </div>
    </div>

    <div class="card-box">
        <div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-3">
            <h5 class="mb-0 fw-bold">Surat Keterangan (Suket) K3</h5>
            <form method="GET" class="search-box-container" style="width: 220px;">
                <i class="bi bi-search"></i>
                <input type="text" name="q" class="search-box" placeholder="Cari nomor laporan..."
                    value="<?= htmlspecialchars($keyword) ?>">
            </form>
        </div>

        <?php if (!$klien_id): ?>
            <div class="alert alert-danger-custom mb-0">
                <i class="bi bi-exclamation-triangle-fill fs-5"></i>
                <div>Akun Anda belum terhubung dengan data perusahaan klien. Hubungi Admin.</div>
            </div>
        <?php elseif (empty($daftar_suket)): ?>
            <p class="text-muted fs-7 mb-0">
                <?= $keyword !== '' ? 'Tidak ada Suket K3 yang cocok dengan pencarian.' : 'Belum ada Suket K3 yang tersedia.' ?>
            </p>
        <?php else: ?>
            <div class="table-responsive-custom">
                <table class="table-custom">
                    <thead>
                        <tr>
                            <th>No</th>
                            <th>Nomor Laporan</th>
                            <th>Objek K3</th>
                            <th>Tanggal Terbit</th>
                            <th>Tanggal Kedaluwarsa</th>
                            <th>Status</th>
                            <th style="text-align: center;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($daftar_suket as $i => $row): ?>
                            <tr>
                                <td><?= $offset + $i + 1 ?></td>
                                <td><?= htmlspecialchars($row['nomor_laporan']) ?></td>
                                <td><?= htmlspecialchars($row['nama_objek']) ?></td>
                                <td><?= $row['tanggal_pemeriksaan'] ? date('d F Y', strtotime($row['tanggal_pemeriksaan'])) : '-' ?></td>
                                <td><?= $row['tanggal_expiry'] ? date('d F Y', strtotime($row['tanggal_expiry'])) : '-' ?></td>
                                <td>
                                    <span class="<?= $badge_map[$row['status_realtime']] ?? 'badge-secondary' ?>">
                                        <?= htmlspecialchars($row['status_realtime']) ?>
                                    </span>
                                </td>
                                <td style="text-align: center;">
                                    <?php if (!empty($row['file_sertifikat_pdf'])): ?>
                                        <a href="../<?= htmlspecialchars($row['file_sertifikat_pdf']) ?>" target="_blank"
                                            class="btn-primary-custom" style="height:32px; padding:0 12px; font-size:0.8rem;">
                                            <i class="bi bi-download"></i> PDF
                                        </a>
                                    <?php else: ?>
                                        <span class="text-muted fs-7">Belum tersedia</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination Sungguhan (LIMIT/OFFSET) -->
            <div class="pagination-custom">
                <span class="text-muted fs-7">
                    Menampilkan <?= $offset + 1 ?>-<?= min($offset + $per_page, $total_data) ?> dari <?= $total_data ?> data
                </span>
                <ul class="pagination-pages">
                    <li class="pagination-item <?= $page_now <= 1 ? 'disabled' : '' ?>">
                        <?php if ($page_now > 1): ?>
                            <a href="?page=<?= $page_now - 1 ?>&q=<?= urlencode($keyword) ?>"><i class="bi bi-chevron-left"></i></a>
                        <?php else: ?>
                            <span><i class="bi bi-chevron-left"></i></span>
                        <?php endif; ?>
                    </li>

                    <?php for ($p = 1; $p <= $total_halaman; $p++): ?>
                        <li class="pagination-item <?= $p === $page_now ? 'active' : '' ?>">
                            <?php if ($p === $page_now): ?>
                                <span><?= $p ?></span>
                            <?php else: ?>
                                <a href="?page=<?= $p ?>&q=<?= urlencode($keyword) ?>"><?= $p ?></a>
                            <?php endif; ?>
                        </li>
                    <?php endfor; ?>

                    <li class="pagination-item <?= $page_now >= $total_halaman ? 'disabled' : '' ?>">
                        <?php if ($page_now < $total_halaman): ?>
                            <a href="?page=<?= $page_now + 1 ?>&q=<?= urlencode($keyword) ?>"><i class="bi bi-chevron-right"></i></a>
                        <?php else: ?>
                            <span><i class="bi bi-chevron-right"></i></span>
                        <?php endif; ?>
                    </li>
                </ul>
            </div>
        <?php endif; ?>
    </div>
</main>

<?php
include "../includes/footer.php";
?>