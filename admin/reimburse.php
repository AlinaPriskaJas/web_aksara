<?php
// admin/reimburse.php
require_once "../config/koneksi.php";
require_once "../includes/drive_helper.php";

if (session_status() === PHP_SESSION_NONE)
    session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../login.php");
    exit;
}

$page_title = "Kelola Reimbursement";
include "../includes/header.php";
include "../includes/sidebar.php";
include "../includes/topbar.php";

$current_user_id = $_SESSION['user_id'];
$success_msg = "";
$error_msg = "";
$active_tab = 'tabPanelReimburseSaya';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // ===== Aksi: Ajukan Reimburse Milik Sendiri =====
    if (isset($_POST['action']) && $_POST['action'] === 'submit') {
        $tanggal_pengeluaran = $_POST['tanggal_pengeluaran'];
        $kategori = $_POST['kategori'];
        $nominal = floatval($_POST['nominal']);
        $keterangan = $_POST['keterangan'];

        if (empty($tanggal_pengeluaran) || empty($kategori) || $nominal <= 0 || !isset($_FILES['lampiran_bukti']) || $_FILES['lampiran_bukti']['error'] !== UPLOAD_ERR_OK) {
            $error_msg = "Isi seluruh data dengan benar dan unggah bukti struk/nota pengeluaran!";
        } else {
            $file = $_FILES['lampiran_bukti'];
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg', 'jpeg', 'png', 'pdf'];

            if (!in_array($ext, $allowed)) {
                $error_msg = "Format bukti tidak valid! Hanya diperbolehkan JPG, PNG, PDF.";
            } else {
                $hasil_drive = arp_upload_ke_drive($file['tmp_name'], $file['name'], $file['type'], $current_user_id, 'Reimburse');

                if ($hasil_drive && !empty($hasil_drive['link'])) {
                    $db_path = $hasil_drive['link'];
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
                        $success_msg = "Pengajuan reimbursement berhasil dikirim!";
                        catatAudit(
                            $conn,
                            'Reimburse',
                            'Tambah',
                            "Mengajukan reimburse kategori {$kategori} sebesar Rp" . number_format($nominal, 0, ',', '.'),
                            null,
                            ['kategori' => $kategori, 'nominal' => $nominal, 'tanggal_pengeluaran' => $tanggal_pengeluaran]
                        );
                        $success_msg = "Pengajuan reimbursement berhasil dikirim!";
                    } catch (PDOException $e) {
                        $error_msg = "Gagal memproses penyimpanan database: " . $e->getMessage();
                    }
                } else {
                    $error_msg = "Gagal mengunggah berkas bukti pengeluaran.";
                }
            }
        }

        // ===== Aksi: Proses Pengajuan Karyawan (Setujui/Tolak/Bayarkan) =====
    } elseif (isset($_POST['action']) && $_POST['action'] === 'process') {
        $active_tab = 'tabPanelReimburseKaryawan';
        $reimburse_id = $_POST['reimburse_id'];
        $status = $_POST['status']; // Disetujui, Ditolak, Dibayarkan

        try {
            $conn->beginTransaction();

            $getReim = $conn->prepare("SELECT * FROM Reimburse WHERE id = :id");
            $getReim->execute(['id' => $reimburse_id]);
            $reim = $getReim->fetch();

            if ($reim) {
                $approval_id = $reim['approval_id'];
                if (!$approval_id) {
                    $appStmt = $conn->prepare("INSERT INTO Approval (jenis_pengajuan, ref_id, requester_id, approver_id, level, status, tgl_aksi) VALUES ('Reimburse', :ref_id, :requester, :approver, 1, :status, NOW())");
                    $appStatus = ($status === 'Dibayarkan') ? 'Disetujui' : $status;
                    $appStmt->execute([
                        'ref_id' => $reimburse_id,
                        'requester' => $reim['user_id'],
                        'approver' => $current_user_id,
                        'status' => $appStatus
                    ]);
                    $approval_id = $conn->lastInsertId();
                } else {
                    $appStatus = ($status === 'Dibayarkan') ? 'Disetujui' : $status;
                    $appStmt = $conn->prepare("UPDATE Approval SET status = :status, approver_id = :approver, tgl_aksi = NOW() WHERE id = :app_id");
                    $appStmt->execute(['status' => $appStatus, 'approver' => $current_user_id, 'app_id' => $approval_id]);
                }

                $surat_id = $reim['surat_id'];
                if (($status === 'Disetujui' || $status === 'Dibayarkan') && !$surat_id) {
                    $nomor_surat = "SR-REIMB/" . date('Ymd') . "/" . $reimburse_id;
                    $perihal = "Pencairan Reimburse Kategori " . $reim['kategori'];

                    $surStmt = $conn->prepare("INSERT INTO Surat (nomor, kode_id, perihal, status, arah, dibuat_oleh, tgl_dibuat, reimburse_id) VALUES (:nomor, 1, :perihal, 'Draft', 'Keluar', :dibuat_oleh, NOW(), :reimburse_id)");
                    $surStmt->execute([
                        'nomor' => $nomor_surat,
                        'perihal' => $perihal,
                        'dibuat_oleh' => $current_user_id,
                        'reimburse_id' => $reimburse_id
                    ]);
                    $surat_id = $conn->lastInsertId();
                }

                $updStmt = $conn->prepare("UPDATE Reimburse SET status = :status, approval_id = :approval_id, surat_id = :surat_id WHERE id = :id");
                $updStmt->execute([
                    'status' => $status,
                    'approval_id' => $approval_id,
                    'surat_id' => $surat_id,
                    'id' => $reimburse_id
                ]);

                $conn->commit();
                $success_msg = "Reimburse #" . $reimburse_id . " berhasil diperbarui ke status: " . $status;

                $conn->commit();
                catatAudit(
                    $conn,
                    'Reimburse',
                    'Proses',
                    "Mengubah status reimburse #{$reimburse_id} menjadi {$status}",
                    ['status' => $reim['status']],
                    ['status' => $status]
                );
                $success_msg = "Reimburse #" . $reimburse_id . " berhasil diperbarui ke status: " . $status;
            } else {
                $conn->rollBack();
                $error_msg = "Data pengajuan reimburse tidak ditemukan.";
            }
        } catch (PDOException $e) {
            $conn->rollBack();
            $error_msg = "Terjadi kesalahan database: " . $e->getMessage();
        }
    }
}

// Riwayat reimburse milik sendiri
$my_reimbursements = [];
try {
    $stmtMine = $conn->prepare("
        SELECT r.*, s.nomor AS nomor_surat_pengajuan
        FROM Reimburse r
        LEFT JOIN Surat s ON r.surat_id = s.id
        WHERE r.user_id = :user_id
        ORDER BY r.created_at DESC
    ");
    $stmtMine->execute(['user_id' => $current_user_id]);
    $my_reimbursements = $stmtMine->fetchAll();
} catch (PDOException $e) {
    $my_reimbursements = [];
}

// Seluruh pengajuan reimburse karyawan
$reimbursements = $conn->query("
    SELECT r.*, u.nama_lengkap, u.email 
    FROM Reimburse r
    JOIN Users u ON r.user_id = u.id
    ORDER BY r.created_at DESC
")->fetchAll();

// Total Financial Recap
$totalPaid = $conn->query("SELECT SUM(nominal) FROM Reimburse WHERE status = 'Dibayarkan'")->fetchColumn() ?: 0;
$totalPending = $conn->query("SELECT SUM(nominal) FROM Reimburse WHERE status = 'Menunggu'")->fetchColumn() ?: 0;
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

    <!-- Recap Cards -->
    <div class="row g-4 mb-4">
        <div class="col-md-6 col-12">
            <div class="stat-card">
                <div class="stat-card-info">
                    <span class="stat-card-title">Dana Dicairkan (Dibayarkan)</span>
                    <span class="stat-card-value">Rp <?= number_format($totalPaid, 0, ',', '.') ?></span>
                </div>
                <div class="stat-card-icon success">
                    <i class="bi bi-check-circle"></i>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-12">
            <div class="stat-card">
                <div class="stat-card-info">
                    <span class="stat-card-title">Total Antrean Pending</span>
                    <span class="stat-card-value">Rp <?= number_format($totalPending, 0, ',', '.') ?></span>
                </div>
                <div class="stat-card-icon warning">
                    <i class="bi bi-hourglass-split"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Tab Navigation -->
    <div class="arp-tab-group">
        <div class="arp-tab-nav">
            <button type="button" class="arp-tab-btn<?= $active_tab === 'tabPanelReimburseSaya' ? ' active' : '' ?>"
                data-tab-target="tabPanelReimburseSaya" onclick="switchTab('tabPanelReimburseSaya', this)">
                <i class="bi bi-receipt me-1"></i> Reimbursement Pribadi
            </button>
            <button type="button"
                class="arp-tab-btn<?= $active_tab === 'tabPanelReimburseKaryawan' ? ' active' : '' ?>"
                data-tab-target="tabPanelReimburseKaryawan" onclick="switchTab('tabPanelReimburseKaryawan', this)">
                <i class="bi bi-people me-1"></i> Reimburse Karyawan
            </button>
        </div>

    <div class="row g-4">
        <!-- Card 1: Riwayat Pengajuan Reimbursement Anda -->
        <div class="col-12 arp-tab-panel" id="tabPanelReimburseSaya"
            <?= $active_tab === 'tabPanelReimburseSaya' ? '' : 'style="display:none;"' ?>>
        <div class="card-box mb-4">
        <div class="table-toolbar">
            <h5 class="table-toolbar-title fw-bold">Riwayat Pengajuan Reimbursment Anda</h5>
            <div class="table-toolbar-actions">
                <div class="search-box-container">
                    <i class="bi bi-search"></i>
                    <input type="text" class="search-box" placeholder="Cari reimburse..."
                        data-table-search="tabelReimburseSaya" onkeyup="handleTableSearch('tabelReimburseSaya')">
                </div>
                <button class="btn-primary-custom" onclick="openModal('modalRemburse')">
                    <i class="bi bi-receipt me-1"></i>Ajukan Reimbursment
                </button>
            </div>
        </div>

        <div class="table-responsive-custom">
            <table class="table-custom" id="tabelReimburseSaya">
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
                    <?php if (count($my_reimbursements) === 0): ?>
                        <tr>
                            <td colspan="6" class="text-center py-4 text-muted">
                                <i class="bi bi-receipt-cutoff d-block mb-2" style="font-size:2rem;"></i>
                                Belum ada pengajuan reimbursement.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($my_reimbursements as $r): ?>
                            <tr>
                                <td>
                                    <div class="fw-bold"><?= htmlspecialchars($r['kategori']) ?></div>
                                    <small class="text-secondary"><?= htmlspecialchars($r['keterangan'] ?: '-') ?></small>
                                </td>
                                <td><?= date('d-m-Y', strtotime($r['tanggal_pengeluaran'])) ?></td>
                                <td><strong>Rp <?= number_format($r['nominal'], 0, ',', '.') ?></strong></td>
                                <td><?= $r['nomor_surat_pengajuan'] ? htmlspecialchars($r['nomor_surat_pengajuan']) : '<span class="text-muted fst-italic">Menunggu Approval</span>' ?>
                                </td>
                                <td>
                                    <?php
                                    $badgeClass = "badge-warning";
                                    if ($r['status'] === 'Disetujui')
                                        $badgeClass = "badge-success";
                                    if ($r['status'] === 'Dibayarkan')
                                        $badgeClass = "badge-success";
                                    if ($r['status'] === 'Ditolak')
                                        $badgeClass = "badge-danger";
                                    ?>
                                    <span class="<?= $badgeClass ?>"><?= htmlspecialchars($r['status']) ?></span>
                                </td>
                                <td>
                                    <?php $hrefBukti = str_starts_with($r['lampiran_bukti'], 'http') ? $r['lampiran_bukti'] : '../' . $r['lampiran_bukti']; ?>
                                    <a href="<?= htmlspecialchars($hrefBukti) ?>" target="_blank"
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
        <div class="pagination-custom" id="pagination-tabelReimburseSaya"></div>
        </div>
        </div>

        <!-- Card 2: Daftar Pengajuan Reimburse Karyawan -->
        <div class="col-12 arp-tab-panel" id="tabPanelReimburseKaryawan"
            <?= $active_tab === 'tabPanelReimburseKaryawan' ? '' : 'style="display:none;"' ?>>
            <div class="card-box">
                <div class="table-toolbar">
                    <h5 class="table-toolbar-title fw-bold">Daftar Pengajuan Reimburse Karyawan</h5>
                    <div class="table-toolbar-actions">
                        <div class="search-box-container">
                            <i class="bi bi-search"></i>
                            <input type="text" class="search-box" placeholder="Cari reimburse..."
                                data-table-search="tabelReimburse" onkeyup="handleTableSearch('tabelReimburse')">
                        </div>
                    </div>
                </div>

                <div class="table-responsive-custom">
                    <table class="table-custom" id="tabelReimburse">
                        <thead>
                            <tr>
                                <th>Karyawan</th>
                                <th>Tanggal Pengeluaran</th>
                                <th>Kategori</th>
                                <th>Nominal</th>
                                <th>Bukti</th>
                                <th>Status</th>
                                <th style="text-align: center;">Tindakan</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($reimbursements) === 0): ?>
                                <tr>
                                    <td colspan="7" class="text-center py-3 text-muted">Belum ada pengajuan reimburse.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($reimbursements as $r): ?>
                                    <tr>
                                        <td>
                                            <div class="fw-bold"><?= htmlspecialchars($r['nama_lengkap']) ?></div>
                                            <small class="text-secondary"><?= htmlspecialchars($r['email']) ?></small>
                                        </td>
                                        <td><?= date('d-m-Y', strtotime($r['tanggal_pengeluaran'])) ?></td>
                                        <td>
                                            <div class="fw-bold"><?= htmlspecialchars($r['kategori']) ?></div>
                                            <small
                                                class="text-secondary"><?= htmlspecialchars($r['keterangan'] ?: '-') ?></small>
                                        </td>
                                        <td><strong>Rp <?= number_format($r['nominal'], 0, ',', '.') ?></strong></td>
                                        <td>
                                            <?php if ($r['lampiran_bukti']): ?>
                                                <?php $hrefBukti2 = str_starts_with($r['lampiran_bukti'], 'http') ? $r['lampiran_bukti'] : '../' . $r['lampiran_bukti']; ?>
                                                <a href="<?= htmlspecialchars($hrefBukti2) ?>" target="_blank"
                                                    class="btn btn-outline-secondary btn-sm py-1"
                                                    style="font-size:0.75rem; border-radius: 8px;">
                                                    <i class="bi bi-file-earmark-image"></i> Lihat Bukti
                                                </a>
                                            <?php else: ?>
                                                <span class="text-muted">Tidak ada lampiran</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php
                                            $badgeClass = "badge-warning";
                                            if ($r['status'] === 'Disetujui')
                                                $badgeClass = "badge-success";
                                            if ($r['status'] === 'Dibayarkan')
                                                $badgeClass = "badge-success";
                                            if ($r['status'] === 'Ditolak')
                                                $badgeClass = "badge-danger";
                                            ?>
                                            <span class="<?= $badgeClass ?>"><?= htmlspecialchars($r['status']) ?></span>
                                        </td>
                                        <td style="text-align: center;">
                                            <?php if ($r['status'] === 'Menunggu'): ?>
                                                <form method="POST" action="reimburse.php" style="display:inline-block;">
                                                    <input type="hidden" name="action" value="process">
                                                    <input type="hidden" name="reimburse_id" value="<?= $r['id'] ?>">
                                                    <input type="hidden" name="status" value="Disetujui">
                                                    <button type="submit" class="btn-primary-custom"
                                                        style="height:28px; padding:0 8px; font-size:0.75rem;">Setujui</button>
                                                </form>
                                                <form method="POST" action="reimburse.php" style="display:inline-block;">
                                                    <input type="hidden" name="action" value="process">
                                                    <input type="hidden" name="reimburse_id" value="<?= $r['id'] ?>">
                                                    <input type="hidden" name="status" value="Ditolak">
                                                    <button type="submit" class="btn-danger-custom"
                                                        style="height:28px; padding:0 8px; font-size:0.75rem;">Tolak</button>
                                                </form>
                                            <?php elseif ($r['status'] === 'Disetujui'): ?>
                                                <form method="POST" action="reimburse.php" style="display:inline;">
                                                    <input type="hidden" name="action" value="process">
                                                    <input type="hidden" name="reimburse_id" value="<?= $r['id'] ?>">
                                                    <input type="hidden" name="status" value="Dibayarkan">
                                                    <button type="submit" class="btn-primary-custom"
                                                        style="height:28px; padding:0 8px; font-size:0.75rem; background-color: var(--success);">Bayarkan
                                                        &amp; Arsipkan</button>
                                                </form>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div class="pagination-custom" id="pagination-tabelReimburse"></div>
            </div>
        </div>
    </div>
    </div>
</main>

<!-- Modal: Ajukan Reimburse Sendiri -->
<div id="modalRemburse" class="arp-modal-overlay" onclick="closeModalOutside(event, 'modalRemburse')">
    <div class="arp-modal-box">
        <div class="arp-modal-header">
            <div>
                <h6 class="fw-bold mb-0">Ajukan Reimbursment Baru</h6>
                <small class="text-muted">Isi detail pengeluaran dan unggah bukti struk/nota.</small>
            </div>
            <button class="arp-modal-close" onclick="closeModal('modalRemburse')">&times;</button>
        </div>
        <div class="arp-modal-body">
            <form method="POST" action="reimburse.php" enctype="multipart/form-data">
                <input type="hidden" name="action" value="submit">
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
                    <div class="upload-dropzone" id="dropzoneBuktiReimburseAdmin">
                        <div class="upload-dropzone-icon"><i class="bi bi-cloud-arrow-up"></i></div>
                        <div>
                            <span class="fw-semibold" style="color: var(--primary);">Tarik &amp; lepas file di sini</span>
                            atau <span class="fw-semibold text-decoration-underline">Pilih File</span>
                        </div>
                        <span class="fs-7 text-muted">Format: JPG, PNG, PDF</span>
                        <input type="file" name="lampiran_bukti" id="inputBuktiReimburseAdmin" class="d-none"
                            accept=".jpg,.jpeg,.png,.pdf" required>
                        <div class="upload-dropzone-filelist" id="fileListBuktiReimburseAdmin"></div>
                    </div>
                </div>
                <div class="mb-4">
                    <label class="form-label fw-semibold fs-7 mb-2">Keterangan Tambahan</label>
                    <textarea name="keterangan" class="textarea-custom"
                        placeholder="Contoh: Pembelian ATK kantor"></textarea>
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
        initTablePagination('tabelReimburseSaya', 10);
        initTablePagination('tabelReimburse', 10);
    });
</script>

<?php if ($error_msg): ?>
    <script>document.addEventListener('DOMContentLoaded', () => openModal('modalRemburse'));</script>
<?php endif; ?>

<?php include "../includes/footer.php"; ?>