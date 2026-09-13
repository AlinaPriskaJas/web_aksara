<?php
// ahlik3/riwayat.php
$page_title = "Riwayat Pemeriksaan K3";
include "../includes/header.php";
include "../includes/sidebar.php";
include "../includes/topbar.php";
require_once "../config/koneksi.php";

// Authorization Check
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'ahli_k3') {
    header("Location: ../login.php");
    exit;
}

$current_user_id = $_SESSION['user_id'];

// Get Ahli K3 ID
try {
    $stmtAhli = $conn->prepare("SELECT id FROM Sertifikat_Ahli WHERE user_id = :user_id LIMIT 1");
    $stmtAhli->execute(['user_id' => $current_user_id]);
    $ahli_k3_id = $stmtAhli->fetchColumn() ?: 0;
} catch (PDOException $e) {
    $ahli_k3_id = 0;
}

// Fetch all historical inspections (including pending)
$riwayat = [];
if ($ahli_k3_id > 0) {
    try {
        $stmtHistory = $conn->prepare("
            SELECT s.*, dk.nama_perusahaan, o.nama_unit, o.merk, o.kapasitas 
            FROM Suket_K3 s
            JOIN Data_Klien dk ON s.klien_id = dk.id
            JOIN Objek_K3 o ON s.objek_id = o.id
            WHERE s.ahli_k3_id = :ahli_id
            ORDER BY s.tanggal_pemeriksaan DESC
        ");
        $stmtHistory->execute(['ahli_id' => $ahli_k3_id]);
        $riwayat = $stmtHistory->fetchAll();
    } catch (PDOException $e) {
        $riwayat = [];
    }
}
?>

<main class="main-content">
    <div class="row g-4">
        <div class="col-12">
            <div class="card-box">
                <div class="table-toolbar">
                    <h5 class="table-toolbar-title fw-bold">Riwayat Pengujian Alat &amp; Penerbitan Suket K3</h5>
                    <div class="table-toolbar-actions">
                        <div class="search-box-container">
                            <i class="bi bi-search"></i>
                            <input type="text" class="search-box" placeholder="Cari riwayat..."
                                data-table-search="tabelRiwayat" onkeyup="handleTableSearch('tabelRiwayat')">
                        </div>
                    </div>
                </div>

                <div class="table-responsive-custom">
                    <table class="table-custom" id="tabelRiwayat">
                        <thead>
                            <tr>
                                <th>Klien / Perusahaan</th>
                                <th>Nomor Laporan</th>
                                <th>Unit Objek</th>
                                <th>Spesifikasi / Kapasitas</th>
                                <th>Hasil Layak</th>
                                <th>Tanggal Inspeksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($riwayat) === 0): ?>
                                <tr>
                                    <td colspan="6" class="text-center py-4 text-muted">Belum ada riwayat pengerjaan pemeriksaan alat K3 yang terselesaikan.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($riwayat as $r): ?>
                                    <tr>
                                        <td><strong><?= htmlspecialchars($r['nama_perusahaan']) ?></strong></td>
                                        <td><?= htmlspecialchars($r['nomor_laporan']) ?></td>
                                        <td><?= htmlspecialchars($r['nama_unit']) ?></td>
                                        <td><?= htmlspecialchars($r['merk'] ?: '-') ?> / <?= htmlspecialchars($r['kapasitas'] ?: '-') ?></td>
                                        <td>
                                            <?php if ($r['hasil_pemeriksaan']): ?>
                                                <?php
                                                $badgeClass = "badge-success";
                                                if ($r['hasil_pemeriksaan'] === 'Tidak Layak') $badgeClass = "badge-danger";
                                                if ($r['hasil_pemeriksaan'] === 'Layak Bersyarat') $badgeClass = "badge-warning";
                                                ?>
                                                <span class="<?= $badgeClass ?>"><?= htmlspecialchars($r['hasil_pemeriksaan']) ?></span>
                                            <?php else: ?>
                                                <span class="text-muted italic">Belum diuji</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= $r['tanggal_pemeriksaan'] ? date('d-m-Y', strtotime($r['tanggal_pemeriksaan'])) : '-' ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div class="pagination-custom" id="pagination-tabelRiwayat"></div>
            </div>
        </div>
    </div>
</main>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        initTablePagination('tabelRiwayat', 10);
    });
</script>

<?php
include "../includes/footer.php";
?>
