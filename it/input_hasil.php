<?php
// ahlik3/input_hasil.php
require_once "../config/koneksi.php";

if (session_status() === PHP_SESSION_NONE)
    session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'ahli_k3') {
    header("Location: ../login.php");
    exit;
}

$page_title = "Input Hasil Pemeriksaan";
include "../includes/header.php";
include "../includes/sidebar.php";
include "../includes/topbar.php";

$current_user_id = $_SESSION['user_id'];
$success_msg = "";
$error_msg = "";

try {
    $stmtAhli = $conn->prepare("SELECT id FROM Sertifikat_Ahli WHERE user_id = :user_id LIMIT 1");
    $stmtAhli->execute(['user_id' => $current_user_id]);
    $ahli_k3_id = $stmtAhli->fetchColumn() ?: 0;
} catch (PDOException $e) {
    $ahli_k3_id = 0;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $suket_id = $_POST['suket_id'];
    $hasil_pemeriksaan = $_POST['hasil_pemeriksaan'];
    $rekomendasi_teknis = $_POST['rekomendasi_teknis'];
    $tanggal_pemeriksaan = $_POST['tanggal_pemeriksaan'];
    $tanggal_expiry = $_POST['tanggal_expiry'] ?: null;

    if (empty($suket_id) || empty($hasil_pemeriksaan) || empty($tanggal_pemeriksaan)) {
        $error_msg = "Suket, Hasil Kelayakan, dan Tanggal Pemeriksaan wajib diisi!";
    } else {
        try {
            $stmt = $conn->prepare("
                UPDATE Suket_K3 
                SET hasil_pemeriksaan = :hasil, 
                    rekomendasi_teknis = :rekomendasi, 
                    tanggal_pemeriksaan = :tgl_periksa,
                    tanggal_expiry = :tgl_expiry 
                WHERE id = :id AND ahli_k3_id = :ahli_id
            ");
            $stmt->execute([
                'hasil' => $hasil_pemeriksaan,
                'rekomendasi' => $rekomendasi_teknis,
                'tgl_periksa' => $tanggal_pemeriksaan,
                'tgl_expiry' => $tanggal_expiry,
                'id' => $suket_id,
                'ahli_id' => $ahli_k3_id
            ]);
            $success_msg = "Hasil pemeriksaan berhasil disimpan!";
        } catch (PDOException $e) {
            $error_msg = "Gagal menyimpan hasil: " . $e->getMessage();
        }
    }
}

$sukets = [];
if ($ahli_k3_id > 0) {
    try {
        $stmtSuket = $conn->prepare("
            SELECT s.*, dk.nama_perusahaan, o.nama_unit, o.serial_number 
            FROM Suket_K3 s
            JOIN Data_Klien dk ON s.klien_id = dk.id
            JOIN Objek_K3 o ON s.objek_id = o.id
            WHERE s.ahli_k3_id = :ahli_id
            ORDER BY s.tanggal_jadwal DESC
        ");
        $stmtSuket->execute(['ahli_id' => $ahli_k3_id]);
        $sukets = $stmtSuket->fetchAll();
    } catch (PDOException $e) {
        $sukets = [];
    }
}
?>

<main class="main-content">
    <?php if ($success_msg): ?>
        <div class="alert alert-success-custom align-items-center">
            <i class="bi bi-check-circle-fill fs-5"></i>
            <div><?= htmlspecialchars($success_msg) ?></div>
        </div>
    <?php endif; ?>
    <?php if ($error_msg): ?>
        <div class="alert alert-danger-custom align-items-center">
            <i class="bi bi-exclamation-triangle-fill"></i>
            <div><?= htmlspecialchars($error_msg) ?></div>
        </div>
    <?php endif; ?>

    <!-- Content Card -->
    <div class="card-box">
        <div class="table-toolbar">
            <h5 class="table-toolbar-title fw-bold">Riwayat Input Hasil Pemeriksaan Anda</h5>
            <div class="table-toolbar-actions">
                <div class="search-box-container">
                    <i class="bi bi-search"></i>
                    <input type="text" class="search-box" placeholder="Cari hasil..." data-table-search="tabelInputHasil"
                        onkeyup="handleTableSearch('tabelInputHasil')">
                </div>
                <button class="btn-primary-custom" onclick="openModal('modalInputHasil')">
                    <i class="bi bi-file-earmark-medical me-1"></i>Input Hasil
                </button>
            </div>
        </div>

        <!-- Tabel Riwayat -->
        <div class="table-responsive-custom">
            <table class="table-custom" id="tabelInputHasil">
                <thead>
                    <tr>
                        <th>Unit Objek</th>
                        <th>No Suket</th>
                        <th>Hasil</th>
                        <th>Tgl Periksa</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($sukets) === 0): ?>
                        <tr>
                            <td colspan="5" class="text-center py-4 text-muted">
                                <i class="bi bi-clipboard-x d-block mb-2" style="font-size:2rem;"></i>
                                Belum ada penugasan atau riwayat pemeriksaan.
                            </td>
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
                                    <?php if ($s['hasil_pemeriksaan']): ?>
                                        <?php
                                        $badgeClass = "badge-success";
                                        if ($s['hasil_pemeriksaan'] === 'Tidak Layak')
                                            $badgeClass = "badge-danger";
                                        if ($s['hasil_pemeriksaan'] === 'Layak Bersyarat')
                                            $badgeClass = "badge-warning";
                                        ?>
                                        <span class="<?= $badgeClass ?>"><?= htmlspecialchars($s['hasil_pemeriksaan']) ?></span>
                                    <?php else: ?>
                                        <span class="text-muted italic">Belum diinput</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= $s['tanggal_pemeriksaan'] ? date('d-m-Y', strtotime($s['tanggal_pemeriksaan'])) : '-' ?>
                                </td>
                                <td>
                                    <?php if ($s['hasil_pemeriksaan']): ?>
                                        <span class="badge-success">Selesai</span>
                                    <?php else: ?>
                                        <span class="badge-warning">Pending</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="pagination-custom" id="pagination-tabelInputHasil"></div>
    </div>
</main>

<!-- ===== MODAL: Input Hasil ===== -->
<div id="modalInputHasil" class="arp-modal-overlay" onclick="closeModalOutside(event, 'modalInputHasil')">
    <div class="arp-modal-box">
        <div class="arp-modal-header">
            <div>
                <h6 class="fw-bold mb-0">Input Hasil Pengujian &amp; Penilaian K3</h6>
                <small class="text-muted">Masukkan hasil kelayakan dan rekomendasi teknis pemeriksaan.</small>
            </div>
            <button class="arp-modal-close" onclick="closeModal('modalInputHasil')">&times;</button>
        </div>
        <div class="arp-modal-body">
            <form method="POST" action="input_hasil.php">
                <div class="mb-3">
                    <label class="form-label fw-semibold fs-7 mb-2">Pilih Unit Pengujian Suket *</label>
                    <select name="suket_id" class="select-custom" required>
                        <option value="">-- Pilih Suket Penugasan --</option>
                        <?php foreach ($sukets as $s): ?>
                            <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['nama_perusahaan']) ?> -
                                <?= htmlspecialchars($s['nama_unit']) ?> (<?= htmlspecialchars($s['nomor_laporan']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold fs-7 mb-2">Tanggal Pemeriksaan *</label>
                    <input type="date" name="tanggal_pemeriksaan" class="form-control-custom" required>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold fs-7 mb-2">Masa Berlaku Kedaluwarsa</label>
                    <input type="date" name="tanggal_expiry" class="form-control-custom">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold fs-7 mb-2">Hasil Kelayakan K3 *</label>
                    <select name="hasil_pemeriksaan" class="select-custom" required>
                        <option value="Layak">Layak</option>
                        <option value="Layak Bersyarat">Layak Bersyarat</option>
                        <option value="Tidak Layak">Tidak Layak</option>
                    </select>
                </div>
                <div class="mb-4">
                    <label class="form-label fw-semibold fs-7 mb-2">Rekomendasi Teknis Temuan Lapangan</label>
                    <textarea name="rekomendasi_teknis" class="textarea-custom"
                        placeholder="Tuliskan temuan perbaikan..."></textarea>
                </div>
                <div class="d-flex gap-2">
                    <button type="button" class="btn-secondary-custom flex-grow-1"
                        onclick="closeModal('modalInputHasil')">Batal</button>
                    <button type="submit" class="btn-primary-custom flex-grow-1"><i class="bi bi-send me-1"></i> Kirim &
                        Rekam</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        initTablePagination('tabelInputHasil', 10);
    });
</script>

<?php if ($error_msg): ?>
    <script>document.addEventListener('DOMContentLoaded', () => openModal('modalInputHasil'));</script>
<?php endif; ?>

<?php include "../includes/footer.php"; ?>