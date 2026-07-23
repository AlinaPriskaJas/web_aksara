<?php
// ahlik3/jadwal.php
$page_title = "Jadwal Pemeriksaan Lapangan";
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

// Get the corresponding Sertifikat_Ahli ID for this user
try {
    $stmtAhli = $conn->prepare("SELECT id FROM Sertifikat_Ahli WHERE user_id = :user_id LIMIT 1");
    $stmtAhli->execute(['user_id' => $current_user_id]);
    $ahli_k3_id = $stmtAhli->fetchColumn() ?: 0;
} catch (PDOException $e) {
    $ahli_k3_id = 0;
}

// Fetch only this Ahli K3's schedules
$jadwals = [];
if ($ahli_k3_id > 0) {
    try {
        $stmtJadwal = $conn->prepare("
            SELECT jp.*, dk.nama_perusahaan, u.nama_lengkap AS nama_admin 
            FROM Jadwal_Pemeriksaan jp
            JOIN Data_Klien dk ON jp.klien_id = dk.id
            JOIN Users u ON jp.dijadwalkan_oleh = u.id
            WHERE jp.ahli_k3_id = :ahli_id
            ORDER BY jp.tanggal DESC, jp.jam_mulai DESC
        ");
        $stmtJadwal->execute(['ahli_id' => $ahli_k3_id]);
        $jadwals = $stmtJadwal->fetchAll();
    } catch (PDOException $e) {
        $jadwals = [];
    }
}
?>

<main class="main-content">
    <div class="row g-4">
        <div class="col-12">
            <div class="card-box">
                <div class="table-toolbar">
                    <h5 class="table-toolbar-title fw-bold">Daftar Jadwal Tugas Survey &amp; Pemeriksaan Lapangan</h5>
                    <div class="table-toolbar-actions">
                        <div class="search-box-container">
                            <i class="bi bi-search"></i>
                            <input type="text" class="search-box" placeholder="Cari jadwal..."
                                data-table-search="tabelJadwalAhli" onkeyup="handleTableSearch('tabelJadwalAhli')">
                        </div>
                    </div>
                </div>

                <div class="table-responsive-custom">
                    <table class="table-custom" id="tabelJadwalAhli">
                        <thead>
                            <tr>
                                <th>Tanggal &amp; Waktu</th>
                                <th>Perusahaan Klien</th>
                                <th>Lokasi Proyek</th>
                                <th>Status Tugas</th>
                                <th>Catatan Penugasan</th>
                                <th>Dijadwalkan Oleh</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($jadwals) === 0): ?>
                                <tr>
                                    <td colspan="6" class="text-center py-4 text-muted">Belum ada tugas jadwal inspeksi yang
                                        terdaftar untuk Anda.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($jadwals as $j): ?>
                                    <tr>
                                        <td>
                                            <div class="fw-bold"><?= date('d M Y', strtotime($j['tanggal'])) ?></div>
                                            <small class="text-secondary"><?= substr($j['jam_mulai'], 0, 5) ?> -
                                                <?= $j['jam_selesai'] ? substr($j['jam_selesai'], 0, 5) : 'Selesai' ?></small>
                                        </td>
                                        <td><strong><?= htmlspecialchars($j['nama_perusahaan']) ?></strong></td>
                                        <td><?= htmlspecialchars($j['lokasi'] ?: '-') ?></td>
                                        <td>
                                            <?php
                                            $badgeClass = 'badge-warning';
                                            if ($j['status'] === 'Selesai')
                                                $badgeClass = 'badge-success';
                                            if ($j['status'] === 'Dibatalkan')
                                                $badgeClass = 'badge-danger';
                                            ?>
                                            <span class="<?= $badgeClass ?>"><?= htmlspecialchars($j['status']) ?></span>
                                        </td>
                                        <td><?= htmlspecialchars($j['catatan'] ?: '-') ?></td>
                                        <td><?= htmlspecialchars($j['nama_admin']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div class="pagination-custom" id="pagination-tabelJadwalAhli"></div>
            </div>
        </div>
    </div>
</main>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        initTablePagination('tabelJadwalAhli', 10);
    });
</script>

<?php
include "../includes/footer.php";
?>