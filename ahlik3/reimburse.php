<?php
// ahlik3/remburse.php
require_once "../config/koneksi.php";

if (session_status() === PHP_SESSION_NONE)
    session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'ahli_k3') {
    header("Location: ../login.php");
    exit;
}

$page_title = "Pengajuan Reimbursement";
include "../includes/header.php";
include "../includes/sidebar.php";
include "../includes/topbar.php";

$current_user_id = $_SESSION['user_id'];
$success_msg = "";
$error_msg = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tanggal_pengeluaran = $_POST['tanggal_pengeluaran'];
    $kategori = $_POST['kategori'];
    $nominal = floatval($_POST['nominal']);
    $keterangan = $_POST['keterangan'];

    if (empty($tanggal_pengeluaran) || empty($kategori) || $nominal <= 0 || !isset($_FILES['lampiran_bukti']) || $_FILES['lampiran_bukti']['error'] !== UPLOAD_ERR_OK) {
        $error_msg = "Isi seluruh data dengan benar dan unggah bukti struk/nota pengeluaran!";
    } else {
        $file = $_FILES['lampiran_bukti'];
        $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
        $allowed = ['jpg', 'jpeg', 'png', 'pdf'];

        if (!in_array(strtolower($ext), $allowed)) {
            $error_msg = "Format bukti tidak valid! Hanya diperbolehkan JPG, PNG, PDF.";
        } else {
            $target_dir = "../uploads/reimburse/";
            if (!is_dir($target_dir))
                mkdir($target_dir, 0777, true);

            $filename = "reimb_" . time() . "_" . uniqid() . "." . $ext;
            $db_path = "uploads/reimburse/" . $filename;
            $target_file = $target_dir . $filename;

            if (move_uploaded_file($file['tmp_name'], $target_file)) {
                try {
                    $stmt = $conn->prepare("INSERT INTO Reimburse (user_id, tanggal_pengeluaran, kategori, keterangan, nominal, lampiran_bukti, status) VALUES (:user_id, :tgl, :kategori, :ket, :nominal, :bukti, 'Menunggu')");
                    $stmt->execute([
                        'user_id' => $current_user_id,
                        'tgl' => $tanggal_pengeluaran,
                        'kategori' => $kategori,
                        'ket' => $keterangan,
                        'nominal' => $nominal,
                        'bukti' => $db_path
                    ]);
                    $success_msg = "Pengajuan reimbursement berhasil dikirim dan menunggu persetujuan Admin!";
                } catch (PDOException $e) {
                    $error_msg = "Gagal memproses penyimpanan database: " . $e->getMessage();
                }
            } else {
                $error_msg = "Gagal mengunggah berkas bukti pengeluaran.";
            }
        }
    }
}

$reimbursements = [];
try {
    $stmtReimb = $conn->prepare("
        SELECT r.*, s.nomor AS nomor_surat_pengajuan
        FROM Reimburse r
        LEFT JOIN surat s ON r.surat_id = s.id
        WHERE r.user_id = :user_id
        ORDER BY r.created_at DESC
    ");
    $stmtReimb->execute(['user_id' => $current_user_id]);
    $reimbursements = $stmtReimb->fetchAll();
} catch (PDOException $e) {
    $reimbursements = [];
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
            <h5 class="table-toolbar-title fw-bold">Riwayat Pengajuan Reimbursement Anda</h5>
            <div class="table-toolbar-actions">
                <div class="search-box-container">
                    <i class="bi bi-search"></i>
                    <input type="text" class="search-box" placeholder="Cari reimburse..."
                        data-table-search="tabelReimburseAhli" onkeyup="handleTableSearch('tabelReimburseAhli')">
                </div>
                <button class="btn-primary-custom" onclick="openModal('modalRemburse')">
                    <i class="bi bi-receipt me-1"></i>Ajukan Reimbursement
                </button>
            </div>
        </div>

        <!-- Tabel Riwayat Reimbursement -->
        <div class="table-responsive-custom">
            <table class="table-custom" id="tabelReimburseAhli">
                <thead>
                    <tr>
                        <th>Kategori</th>
                        <th>Tanggal</th>
                        <th>Nominal</th>
                        <th>Surat Generated</th>
                        <th>Status</th>
                        <th>Bukti</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($reimbursements) === 0): ?>
                        <tr>
                            <td colspan="6" class="text-center py-4 text-muted">
                                <i class="bi bi-receipt-cutoff d-block mb-2" style="font-size:2rem;"></i>
                                Belum ada pengajuan reimbursement.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($reimbursements as $r): ?>
                            <tr>
                                <td>
                                    <div class="fw-bold"><?= htmlspecialchars($r['kategori']) ?></div>
                                    <small class="text-secondary"><?= htmlspecialchars($r['keterangan'] ?: '-') ?></small>
                                </td>
                                <td><?= date('d-m-Y', strtotime($r['tanggal_pengeluaran'])) ?></td>
                                <td><strong>Rp <?= number_format($r['nominal'], 0, ',', '.') ?></strong></td>
                                <td><?= $r['nomor_surat_pengajuan'] ? htmlspecialchars($r['nomor_surat_pengajuan']) : '<span class="text-muted italic">Menunggu Approval</span>' ?>
                                </td>
                                <td>
                                    <?php
                                    $badgeClass = "badge-warning";
                                    if ($r['status'] === 'Disetujui')
                                        $badgeClass = "badge-primary";
                                    if ($r['status'] === 'Dibayarkan')
                                        $badgeClass = "badge-success";
                                    if ($r['status'] === 'Ditolak')
                                        $badgeClass = "badge-danger";
                                    ?>
                                    <span class="<?= $badgeClass ?>"><?= htmlspecialchars($r['status']) ?></span>
                                </td>
                                <td>
                                    <a href="../<?= htmlspecialchars($r['lampiran_bukti']) ?>" target="_blank"
                                        class="btn btn-outline-secondary btn-sm py-1"
                                        style="font-size:0.75rem; border-radius: 8px;">
                                        Lihat Nota
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="pagination-custom" id="pagination-tabelReimburseAhli"></div>
    </div>
</main>

<!-- ===== MODAL: Ajukan Reimbursement ===== -->
<div id="modalRemburse" class="arp-modal-overlay" onclick="closeModalOutside(event, 'modalRemburse')">
    <div class="arp-modal-box">
        <div class="arp-modal-header">
            <div>
                <h6 class="fw-bold mb-0">Ajukan Reimbursement Baru</h6>
                <small class="text-muted">Isi detail pengeluaran dan unggah bukti struk/nota.</small>
            </div>
            <button class="arp-modal-close" onclick="closeModal('modalRemburse')">&times;</button>
        </div>
        <div class="arp-modal-body">
            <form method="POST" action="remburse.php" enctype="multipart/form-data">
                <div class="mb-3">
                    <label class="form-label fw-semibold fs-7 mb-2">Tanggal Pengeluaran *</label>
                    <input type="date" name="tanggal_pengeluaran" class="form-control-custom" required>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold fs-7 mb-2">Kategori Pengeluaran *</label>
                    <select name="kategori" class="select-custom" required>
                        <option value="Operasional Lapangan">Operasional Lapangan (Alat / Bahan)</option>
                        <option value="Transportasi / Bensin">Transportasi / Bensin</option>
                        <option value="Konsumsi / Entertainment">Konsumsi / Makan Dinas</option>
                        <option value="Penginapan / Hotel">Akomodasi / Penginapan</option>
                        <option value="Lain-lain">Lain-lain</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold fs-7 mb-2">Nominal Pengeluaran *</label>
                    <input type="number" name="nominal" class="form-control-custom" placeholder="Contoh: 150000" min="1"
                        required>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold fs-7 mb-2">Unggah Bukti Struk/Nota *</label>
                    <input type="file" name="lampiran_bukti" class="form-control-custom" style="padding-top:8px;"
                        required>
                    <small class="text-muted d-block mt-1">Format: JPG, PNG, PDF</small>
                </div>
                <div class="mb-4">
                    <label class="form-label fw-semibold fs-7 mb-2">Keterangan Tambahan</label>
                    <textarea name="keterangan" class="textarea-custom"
                        placeholder="Contoh: Pembelian lem silikon unit K3 Karawang"></textarea>
                </div>
                <div class="d-flex gap-2">
                    <button type="button" class="btn-secondary-custom flex-grow-1"
                        onclick="closeModal('modalRemburse')">Batal</button>
                    <button type="submit" class="btn-primary-custom flex-grow-1"><i class="bi bi-send me-1"></i> Kirim
                        Pengajuan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        initTablePagination('tabelReimburseAhli', 10);
    });
</script>

<?php if ($error_msg): ?>
    <script>document.addEventListener('DOMContentLoaded', () => openModal('modalRemburse'));</script>
<?php endif; ?>

<?php include "../includes/footer.php"; ?>