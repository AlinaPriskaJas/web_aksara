<?php
// ahlik3/rekomendasi.php
$page_title = "Rekomendasi Teknis K3";
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

// Fetch all Sukets under this Ahli to review and provide recommendations
$sukets = [];
if ($ahli_k3_id > 0) {
    try {
        $stmtSuket = $conn->prepare("
            SELECT s.id, s.nomor_laporan, s.rekomendasi_teknis, s.hasil_pemeriksaan, s.tanggal_pemeriksaan, dk.nama_perusahaan, o.nama_unit 
            FROM Suket_K3 s
            JOIN Data_Klien dk ON s.klien_id = dk.id
            JOIN Objek_K3 o ON s.objek_id = o.id
            WHERE s.ahli_k3_id = :ahli_id AND s.hasil_pemeriksaan IS NOT NULL
            ORDER BY s.tanggal_pemeriksaan DESC
        ");
        $stmtSuket->execute(['ahli_id' => $ahli_k3_id]);
        $sukets = $stmtSuket->fetchAll();
    } catch (PDOException $e) {
        $sukets = [];
    }
}
?>

<main class="main-content">
    <div class="row g-4">
        <div class="col-12">
            <div class="card-box">
                <div class="table-toolbar">
                    <h5 class="table-toolbar-title fw-bold">Riwayat Rekomendasi Teknis K3 yang Diberikan</h5>
                    <div class="table-toolbar-actions">
                        <div class="search-box-container">
                            <i class="bi bi-search"></i>
                            <input type="text" class="search-box" placeholder="Cari rekomendasi..."
                                data-table-search="tabelRekomendasi" onkeyup="handleTableSearch('tabelRekomendasi')">
                        </div>
                    </div>
                </div>

                <div class="table-responsive-custom">
                    <table class="table-custom" id="tabelRekomendasi">
                        <thead>
                            <tr>
                                <th>Unit Objek &amp; Klien</th>
                                <th>No Suket / Laporan</th>
                                <th>Kelayakan</th>
                                <th>Tanggal Periksa</th>
                                <th>Rekomendasi Teknis Keselamatan Kerja</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($sukets) === 0): ?>
                                <tr>
                                    <td colspan="5" class="text-center py-3 text-muted">Belum ada data pemeriksaan dengan
                                        hasil terdaftar. Silakan input hasil terlebih dahulu.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($sukets as $s): ?>
                                    <tr>
                                        <td>
                                            <div class="fw-bold"><?= htmlspecialchars($s['nama_unit']) ?></div>
                                            <small class="text-secondary"><?= htmlspecialchars($s['nama_perusahaan']) ?></small>
                                        </td>
                                        <td><?= htmlspecialchars($s['nomor_laporan']) ?></td>
                                        <td>
                                            <?php
                                            $badgeClass = "badge-success";
                                            if ($s['hasil_pemeriksaan'] === 'Tidak Layak')
                                                $badgeClass = "badge-danger";
                                            if ($s['hasil_pemeriksaan'] === 'Layak Bersyarat')
                                                $badgeClass = "badge-warning";
                                            ?>
                                            <span
                                                class="<?= $badgeClass ?>"><?= htmlspecialchars($s['hasil_pemeriksaan']) ?></span>
                                        </td>
                                        <td><?= date('d-m-Y', strtotime($s['tanggal_pemeriksaan'])) ?></td>
                                        <td>
                                            <div class="bg-light p-3 rounded"
                                                style="font-size:0.85rem; line-height: 1.5; color: var(--text-primary); border-left: 3px solid var(--primary);">
                                                <?= nl2br(htmlspecialchars($s['rekomendasi_teknis'] ?: 'Tidak ada rekomendasi khusus.')) ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div class="pagination-custom" id="pagination-tabelRekomendasi"></div>
            </div>
        </div>
    </div>
</main>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        initTablePagination('tabelRekomendasi', 10);
    });
</script>

<?php
include "../includes/footer.php";
?>