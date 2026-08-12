<?php
// client/riwayat.php
session_start();
require_once "../config/koneksi.php";

$page_title = "Riwayat Pemeriksaan";

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

// ================== PENCARIAN, FILTER HASIL & PAGINATION ==================
$keyword  = trim($_GET['q'] ?? '');
$hasil_filter = $_GET['hasil'] ?? '';
if (!in_array($hasil_filter, ['Layak', 'Layak Bersyarat', 'Tidak Layak'], true)) {
    $hasil_filter = '';
}

$per_page = 5;
$page_now = max(1, (int) ($_GET['page'] ?? 1));
$offset   = ($page_now - 1) * $per_page;

// ================== DAFTAR RIWAYAT PEMERIKSAAN (dengan search + filter + pagination) ==================
$daftar_riwayat = [];
$total_data     = 0;

if ($klien_id) {
    try {
        $where  = "WHERE lp.klien_id = :klien_id AND lp.hasil_pemeriksaan IS NOT NULL";
        $params = [':klien_id' => $klien_id];

        if ($hasil_filter !== '') {
            $where .= " AND lp.hasil_pemeriksaan = :hasil";
            $params[':hasil'] = $hasil_filter;
        }

        if ($keyword !== '') {
            $where .= " AND (lp.nomor_laporan LIKE :kw OR COALESCE(ok.nama_unit, jok.nama_objek, '') LIKE :kw)";
            $params[':kw'] = '%' . $keyword . '%';
        }

        // Hitung total data untuk pagination
        $stmt = $conn->prepare("
            SELECT COUNT(*)
            FROM Laporan_Pemeriksaan lp
            LEFT JOIN Objek_K3 ok ON ok.id = lp.objek_id
            LEFT JOIN jenis_objek_k3 jok ON jok.id_jenis = ok.id_jenis
            $where
        ");
        $stmt->execute($params);
        $total_data = (int) $stmt->fetchColumn();

        // Ambil data halaman aktif, join ke Objek_K3 & jenis_objek_k3 untuk nama objek
        $stmt = $conn->prepare("
            SELECT lp.id, lp.nomor_laporan, lp.jenis_pemeriksaan, lp.tanggal_pemeriksaan, lp.hasil_pemeriksaan,
                   COALESCE(ok.nama_unit, jok.nama_objek, '-') AS nama_objek
            FROM Laporan_Pemeriksaan lp
            LEFT JOIN Objek_K3 ok ON ok.id = lp.objek_id
            LEFT JOIN jenis_objek_k3 jok ON jok.id_jenis = ok.id_jenis
            $where
            ORDER BY lp.tanggal_pemeriksaan DESC, lp.created_at DESC
            LIMIT :limit OFFSET :offset
        ");
        foreach ($params as $key => $val) {
            $stmt->bindValue($key, $val);
        }
        $stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $daftar_riwayat = $stmt->fetchAll();
    } catch (PDOException $e) {
        $daftar_riwayat = [];
    }
}

$total_halaman = max(1, (int) ceil($total_data / $per_page));

// Mapping hasil pemeriksaan ke badge class
$badge_map = [
    'Layak'           => 'badge-success',
    'Layak Bersyarat' => 'badge-warning',
    'Tidak Layak'     => 'badge-danger',
];

include "../includes/header.php";
include "../includes/sidebar.php";
include "../includes/topbar.php";
?>

<main class="main-content">
    <div class="card-box">
        <form method="GET" class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-3">
            <h5 class="mb-0 fw-bold">Riwayat Pemeriksaan Objek K3</h5>
            <div class="d-flex gap-2">
                <div class="search-box-container" style="width: 220px;">
                    <i class="bi bi-search"></i>
                    <input type="text" name="q" class="search-box" placeholder="Cari riwayat..."
                        value="<?= htmlspecialchars($keyword) ?>">
                </div>
                <select name="hasil" class="select-custom" style="width: 180px;" onchange="this.form.submit()">
                    <option value="">Semua Hasil</option>
                    <option value="Layak" <?= $hasil_filter === 'Layak' ? 'selected' : '' ?>>Layak</option>
                    <option value="Layak Bersyarat" <?= $hasil_filter === 'Layak Bersyarat' ? 'selected' : '' ?>>Layak Bersyarat</option>
                    <option value="Tidak Layak" <?= $hasil_filter === 'Tidak Layak' ? 'selected' : '' ?>>Tidak Layak</option>
                </select>
            </div>
        </form>

        <?php if (!$klien_id): ?>
            <div class="alert alert-danger-custom mb-0">
                <i class="bi bi-exclamation-triangle-fill fs-5"></i>
                <div>Akun Anda belum terhubung dengan data perusahaan klien. Lengkapi terlebih dahulu data Anda pada Profile. Lalu hubungi Admin untuk menautkan akun.</div>
            </div>
        <?php elseif (empty($daftar_riwayat)): ?>
            <p class="text-muted fs-7 mb-0">
                <?= ($keyword !== '' || $hasil_filter !== '') ? 'Tidak ada riwayat pemeriksaan yang cocok.' : 'Belum ada riwayat pemeriksaan yang tersedia.' ?>
            </p>
        <?php else: ?>
            <div class="table-responsive-custom">
                <table class="table-custom">
                    <thead>
                        <tr>
                            <th>No</th>
                            <th>Objek K3</th>
                            <th>Jenis Pemeriksaan</th>
                            <th>Tanggal Pemeriksaan</th>
                            <th>No. Laporan</th>
                            <th>Hasil</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($daftar_riwayat as $i => $r): ?>
                            <tr>
                                <td><?= $offset + $i + 1 ?></td>
                                <td><?= htmlspecialchars($r['nama_objek']) ?></td>
                                <td><?= htmlspecialchars($r['jenis_pemeriksaan']) ?></td>
                                <td><?= $r['tanggal_pemeriksaan'] ? date('d F Y', strtotime($r['tanggal_pemeriksaan'])) : '-' ?></td>
                                <td><?= htmlspecialchars($r['nomor_laporan']) ?></td>
                                <td>
                                    <span class="<?= $badge_map[$r['hasil_pemeriksaan']] ?? 'badge-secondary' ?>">
                                        <?= htmlspecialchars($r['hasil_pemeriksaan']) ?>
                                    </span>
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
                            <a href="?page=<?= $page_now - 1 ?>&q=<?= urlencode($keyword) ?>&hasil=<?= urlencode($hasil_filter) ?>"><i class="bi bi-chevron-left"></i></a>
                        <?php else: ?>
                            <span><i class="bi bi-chevron-left"></i></span>
                        <?php endif; ?>
                    </li>

                    <?php for ($p = 1; $p <= $total_halaman; $p++): ?>
                        <li class="pagination-item <?= $p === $page_now ? 'active' : '' ?>">
                            <?php if ($p === $page_now): ?>
                                <span><?= $p ?></span>
                            <?php else: ?>
                                <a href="?page=<?= $p ?>&q=<?= urlencode($keyword) ?>&hasil=<?= urlencode($hasil_filter) ?>"><?= $p ?></a>
                            <?php endif; ?>
                        </li>
                    <?php endfor; ?>

                    <li class="pagination-item <?= $page_now >= $total_halaman ? 'disabled' : '' ?>">
                        <?php if ($page_now < $total_halaman): ?>
                            <a href="?page=<?= $page_now + 1 ?>&q=<?= urlencode($keyword) ?>&hasil=<?= urlencode($hasil_filter) ?>"><i class="bi bi-chevron-right"></i></a>
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