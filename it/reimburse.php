<?php
// it/reimburse.php
require_once "../config/koneksi.php";
require_once "../includes/drive_helper.php";
require_once "../includes/functions.php";

if (session_status() === PHP_SESSION_NONE)
    session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'it') {
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

    if (isset($_POST['action']) && $_POST['action'] === 'submit') {
    $kodeReimburse = arp_muat_template_reimburse($conn);
    if (!$kodeReimburse) {
        $error_msg = "Template Reimbursement belum terhubung ke Jenis Surat. Hubungi Admin untuk menghubungkannya di menu Kelola Jenis Surat.";
    } else {
        $hasil = arp_proses_pengajuan_reimburse(
            $conn,
            $kodeReimburse,
            $current_user_id,
            $_POST['dinamis'] ?? [],
            $_POST['items'] ?? [],
            $_POST['no_urut_manual'] ?? null
        );
        if ($hasil['ok']) {
            $success_msg = $hasil['msg'];
        } else {
            $error_msg = $hasil['msg'];
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
                    $perihal = "Pencairan Reimburse - " . $reim['keterangan'];

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
    SELECT r.*, s.nomor AS nomor_surat_pengajuan, s.drive_link AS link_surat_drive, s.file_hasil AS link_surat_file
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

$kodeReimburse = arp_muat_template_reimburse($conn);
$fields_reimburse = ['fields' => [], 'table_fields' => [], 'blocks' => []];
$reimburse_template_belum_terhubung = !$kodeReimburse;

// ===== Hitung preview nomor urut surat reimburse (sama seperti di surat.php) =====
$preview_nomor_reimburse = '(otomatis saat disimpan)';
$counterPreviewReimburse = 1;
$bulanRomawiReimburse = '';
$tahunReimburse = (int) date('Y');
if ($kodeReimburse) {
    $bulanRomawiReimburse = ['I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X', 'XI', 'XII'][date('n') - 1];
    $counterDariKodeSurat = ((int) $kodeReimburse['tahun_counter'] === $tahunReimburse) ? (int) $kodeReimburse['counter'] : 0;

    $stmtMaxNomorReim = $conn->prepare("SELECT nomor FROM Surat WHERE kode_id = ? AND nomor LIKE ?");
    $stmtMaxNomorReim->execute([$kodeReimburse['id'], '%/' . $kodeReimburse['kode'] . '/ARP/%/' . $tahunReimburse]);
    $counterDariSurat = 0;
    foreach ($stmtMaxNomorReim->fetchAll(PDO::FETCH_COLUMN) as $nomorLama) {
        $angkaAwal = (int) strtok($nomorLama, '/');
        if ($angkaAwal > $counterDariSurat) {
            $counterDariSurat = $angkaAwal;
        }
    }
    $counterPreviewReimburse = max($counterDariKodeSurat, $counterDariSurat) + 1;
    $preview_nomor_reimburse = sprintf('%03d/%s/ARP/%s/%d', $counterPreviewReimburse, $kodeReimburse['kode'], $bulanRomawiReimburse, $tahunReimburse);
}

if ($kodeReimburse) {
    $fields_reimburse = muatFieldsTemplateLive($conn, $kodeReimburse);
}

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
            <button type="button" class="arp-tab-btn<?= $active_tab === 'tabPanelReimburseKaryawan' ? ' active' : '' ?>"
                data-tab-target="tabPanelReimburseKaryawan" onclick="switchTab('tabPanelReimburseKaryawan', this)">
                <i class="bi bi-people me-1"></i> Reimburse Karyawan
            </button>
        </div>

        <div class="row g-4">
            <!-- Card 1: Riwayat Pengajuan Reimbursement Anda -->
            <div class="col-12 arp-tab-panel" id="tabPanelReimburseSaya" <?= $active_tab === 'tabPanelReimburseSaya' ? '' : 'style="display:none;"' ?>>
                <div class="card-box mb-4">
                    <div class="table-toolbar">
                        <h5 class="table-toolbar-title fw-bold">Riwayat Pengajuan Reimbursment Anda</h5>
                        <div class="table-toolbar-actions">
                            <div class="search-box-container">
                                <i class="bi bi-search"></i>
                                <input type="text" class="search-box" placeholder="Cari reimburse..."
                                    data-table-search="tabelReimburseSaya"
                                    onkeyup="handleTableSearch('tabelReimburseSaya')">
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
                                    <th>No</th>
                                    <th>Tanggal Pengajuan</th>
                                    <th>Nominal</th>
                                    <th>Surat</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($my_reimbursements) === 0): ?>
                                    <tr>
                                        <td colspan="5" class="text-center py-4 text-muted">
                                            <i class="bi bi-receipt-cutoff d-block mb-2" style="font-size:2rem;"></i>
                                            Belum ada pengajuan reimbursement.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($my_reimbursements as $no => $r): ?>
                                        <tr>
                                            <td><?= $no + 1 ?></td>
                                            <td><?= date('d-m-Y', strtotime($r['tanggal_pengeluaran'])) ?></td>
                                            <td><strong>Rp <?= number_format($r['nominal'], 0, ',', '.') ?></strong></td>
                                            <td>
                                                <?php
                                                $linkSurat = $r['link_surat_drive'] ?: $r['link_surat_file'];
                                                if ($r['nomor_surat_pengajuan'] && $linkSurat):
                                                    $hrefSurat = hrefBerkas($linkSurat);
                                                ?>
                                                    <a href="<?= htmlspecialchars($hrefSurat) ?>" target="_blank"
                                                        class="btn btn-outline-secondary btn-sm py-1"
                                                        style="font-size:0.75rem; border-radius: 8px;">
                                                        <i class="bi bi-file-earmark-text"></i> <?= htmlspecialchars($r['nomor_surat_pengajuan']) ?>
                                                    </a>
                                                <?php elseif ($r['nomor_surat_pengajuan']): ?>
                                                    <span><?= htmlspecialchars($r['nomor_surat_pengajuan']) ?></span>
                                                <?php else: ?>
                                                    <span class="text-muted fst-italic">Menunggu Approval</span>
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
                                    <th>No</th>
                                    <th>Nama Pengajuan</th>
                                    <th>Tanggal Pengajuan</th>
                                    <th>Nominal</th>
                                    <th>Status</th>
                                    <th style="text-align: center;">Tindakan</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($reimbursements) === 0): ?>
                                    <tr>
                                        <td colspan="6" class="text-center py-3 text-muted">Belum ada pengajuan reimburse.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($reimbursements as $no => $r): ?>
                                        <tr>
                                            <td><?= $no + 1 ?></td>
                                            <td>
                                                <div class="fw-bold"><?= htmlspecialchars($r['nama_lengkap']) ?></div>
                                                <small class="text-secondary"><?= htmlspecialchars($r['email']) ?></small>
                                            </td>
                                            <td><?= date('d-m-Y', strtotime($r['tanggal_pengeluaran'])) ?></td>
                                            <td><strong>Rp <?= number_format($r['nominal'], 0, ',', '.') ?></strong></td>
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
            <?php if ($reimburse_template_belum_terhubung): ?>
                <div class="alert alert-danger-custom text-xs">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                    <div>Template surat Reimbursement belum terhubung ke Jenis Surat. Hubungi Admin.</div>
                </div>
            <?php else: ?>
            <form method="POST" action="reimburse.php" id="form-reimburse">
                <input type="hidden" name="action" value="submit">

                <div class="row g-3 mb-2">
                    <div class="col-12">
                        <label class="form-label fw-semibold fs-7 mb-2">No Urut Surat</label>
                        <div class="nomor-surat-group" style="display:flex; flex-wrap:nowrap; align-items:stretch; gap:0;">
                            <input type="text" name="no_urut_manual" id="input-no-urut-reimburse"
                                class="form-control-custom nomor-surat-input"
                                style="flex:0 0 90px; width:90px; min-width:90px;"
                                value="<?= htmlspecialchars(sprintf('%03d', $counterPreviewReimburse)) ?>"
                                placeholder="<?= htmlspecialchars(sprintf('%03d', $counterPreviewReimburse)) ?>">
                            <div class="form-control-custom field-readonly text-secondary nomor-surat-suffix"
                                style="flex:1 1 auto; white-space:nowrap; overflow-x:auto; font-size:0.85rem;">
                                /<?= htmlspecialchars($kodeReimburse['kode']) ?>/ARP/<?= htmlspecialchars($bulanRomawiReimburse) ?>/<?= htmlspecialchars((string) $tahunReimburse) ?>
                            </div>
                        </div>
                        <small class="text-secondary text-xs d-block mt-1">
                            Kosongkan untuk otomatis (nomor berikutnya: <b><?= htmlspecialchars($preview_nomor_reimburse) ?></b>).
                        </small>
                    </div>
                </div>

                <div class="row g-3 mb-2">
                    <?php foreach ($fields_reimburse['fields'] as $f): ?>
                        <?php $isTanggal = (bool) preg_match('/tanggal|tgl/i', $f['field']); ?>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold fs-7 mb-2"><?= htmlspecialchars($f['label']) ?></label>
                            <?php if ($isTanggal): ?>
                                <input type="date" name="dinamis[<?= htmlspecialchars($f['field']) ?>]"
                                    class="form-control-custom" value="<?= date('Y-m-d') ?>" required>
                            <?php else: ?>
                                <input type="text" name="dinamis[<?= htmlspecialchars($f['field']) ?>]"
                                    class="form-control-custom">
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>

                <label class="form-label fw-semibold fs-7 mb-2">Rincian Pengeluaran *</label>
                <div class="table-responsive-custom mb-2">
                    <table class="table-custom" id="tabel-item-reimburse">
                        <thead>
                            <tr>
                                <th>No</th>
                                <?php foreach ($fields_reimburse['table_fields'] as $kolom): ?>
                                    <th><?= htmlspecialchars($kolom['label']) ?></th>
                                <?php endforeach; ?>
                                <th style="text-align:right;">Sub Total</th>
                                <th style="width:36px;"></th>
                            </tr>
                        </thead>
                        <tbody id="tabel-item-reimburse-body">
                            <tr class="baris-item-reimburse" data-baris-index="0">
                                <td class="nomor-baris">1</td>
                                <?php foreach ($fields_reimburse['table_fields'] as $kolom): ?>
                                    <?php $isHarga = preg_match('/harga/i', $kolom['field']); ?>
                                    <td>
                                        <input type="text" name="items[0][<?= htmlspecialchars($kolom['field']) ?>]"
                                            data-kolom="<?= htmlspecialchars($kolom['field']) ?>"
                                            <?= $isHarga ? 'data-tipe="harga"' : '' ?>
                                            class="form-control-custom" required>
                                    </td>
                                <?php endforeach; ?>
                                <td class="subtotal-baris" style="text-align:right; font-family:monospace;">-</td>
                                <td style="text-align:center;">
                                    <button type="button" class="btn btn-outline-danger btn-sm tombol-hapus-baris-reimburse">
                                        <i class="bi bi-x-lg"></i>
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <button type="button" id="tombol-tambah-item-reimburse" class="btn btn-outline-primary btn-sm mb-3">
                    <i class="bi bi-plus-lg"></i> Tambah Baris
                </button>

                <div class="ringkasan-total-row total-bayar mb-4">
                    <span>Total Reimburse</span>
                    <span id="preview-total-reimburse" style="font-family:monospace;">Rp. 0</span>
                </div>

                <div class="d-flex gap-2">
                    <button type="button" class="btn-secondary-custom flex-grow-1"
                        onclick="closeModal('modalRemburse')">Batal</button>
                    <button type="submit" class="btn-primary-custom flex-grow-1">
                        <i class="bi bi-send me-1"></i> Kirim Pengajuan &amp; Buat Surat
                    </button>
                </div>
            </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        initTablePagination('tabelReimburseSaya', 10);
        initTablePagination('tabelReimburse', 10);
    });
</script>


<script>
(function () {
    var tbody = document.getElementById('tabel-item-reimburse-body');
    var tombolTambah = document.getElementById('tombol-tambah-item-reimburse');
    var elTotal = document.getElementById('preview-total-reimburse');
    if (!tbody || !tombolTambah) return;

    var kolomList = <?= json_encode(array_column($fields_reimburse['table_fields'], 'field')) ?>;
    var idx = 1;

    function parseAngkaJs(teks) {
        teks = String(teks || '').trim();
        var m = teks.match(/-?\d[\d.,]*/);
        if (!m) return null;
        var a = m[0];
        if (a.indexOf(',') !== -1 && a.indexOf('.') !== -1) a = a.replace(/\./g, '').replace(',', '.');
        else if (a.indexOf(',') !== -1) a = a.replace(',', '.');
        else { var b = a.split('.'); if (b.length > 1 && b[b.length - 1].length === 3) a = a.split('.').join(''); }
        var h = parseFloat(a);
        return isNaN(h) ? null : h;
    }
    function formatRupiahJs(a) {
        var n = Math.round(a);
        return 'Rp. ' + Math.abs(n).toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    }
    function formatRibuan(str) {
        var s = str.replace(/\D/g, '');
        return s === '' ? '' : s.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    }
    function hitungBaris(tr) {
        var qty = null, harga = null;
        tr.querySelectorAll('input[data-kolom]').forEach(function (inp) {
            var n = inp.getAttribute('data-kolom');
            if (qty === null && /qty|jumlah/i.test(n)) qty = parseAngkaJs(inp.value);
            if (harga === null && /harga/i.test(n)) harga = parseAngkaJs(inp.value);
        });
        var sub = tr.querySelector('.subtotal-baris');
        var nilai = (qty !== null && harga !== null) ? qty * harga : null;
        if (sub) sub.textContent = nilai !== null ? formatRupiahJs(nilai) : '-';
        return nilai;
    }
    function hitungTotal() {
        var total = 0;
        tbody.querySelectorAll('.baris-item-reimburse').forEach(function (tr) {
            var n = hitungBaris(tr);
            if (n !== null) total += n;
        });
        if (elTotal) elTotal.textContent = formatRupiahJs(total);
    }
    function pasangEvent(tr) {
        tr.querySelectorAll('input[data-tipe="harga"]').forEach(function (inp) {
            inp.addEventListener('input', function () {
                var pos = this.value.length - this.selectionStart;
                this.value = formatRibuan(this.value);
                var np = this.value.length - pos;
                this.setSelectionRange(np, np);
                hitungTotal();
            });
        });
        tr.querySelectorAll('input[data-kolom]').forEach(function (inp) {
            inp.addEventListener('input', hitungTotal);
        });
        var btn = tr.querySelector('.tombol-hapus-baris-reimburse');
        if (btn) btn.addEventListener('click', function () {
            if (tbody.querySelectorAll('.baris-item-reimburse').length > 1) {
                tr.remove();
            } else {
                tr.querySelectorAll('input').forEach(function (i) { i.value = ''; });
            }
            hitungTotal();
        });
    }
    tbody.querySelectorAll('.baris-item-reimburse').forEach(pasangEvent);

    tombolTambah.addEventListener('click', function () {
        var tr = document.createElement('tr');
        tr.className = 'baris-item-reimburse';
        tr.setAttribute('data-baris-index', idx);
        var html = '<td class="nomor-baris">' + (idx + 1) + '</td>';
        kolomList.forEach(function (k) {
            var isHarga = /harga/i.test(k);
            html += '<td><input type="text" name="items[' + idx + '][' + k + ']" data-kolom="' + k + '"' +
                (isHarga ? ' data-tipe="harga"' : '') + ' class="form-control-custom" required></td>';
        });
        html += '<td class="subtotal-baris" style="text-align:right; font-family:monospace;">-</td>';
        html += '<td style="text-align:center;"><button type="button" class="btn btn-outline-danger btn-sm tombol-hapus-baris-reimburse"><i class="bi bi-x-lg"></i></button></td>';
        tr.innerHTML = html;
        tbody.appendChild(tr);
        pasangEvent(tr);
        idx++;
    });
})();
</script>

<?php if ($error_msg): ?>
    <script>document.addEventListener('DOMContentLoaded', () => openModal('modalRemburse'));</script>
<?php endif; ?>

<?php include "../includes/footer.php"; ?>