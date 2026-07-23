<?php
// it/cuti.php
require_once "../config/koneksi.php";

if (session_status() === PHP_SESSION_NONE)
    session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'it') {
    header("Location: ../login.php");
    exit;
}

$page_title = "Pengajuan Cuti";
include "../includes/header.php";
include "../includes/sidebar.php";
include "../includes/topbar.php";

$current_user_id = $_SESSION['user_id'];
$success_msg = "";
$error_msg = "";
$current_year = date('Y');
$active_tab = 'tabPanelCutiSaya';

// Fetch or Initialize Leave Balance
try {
    $stmtBal = $conn->prepare("SELECT * FROM Cuti_Saldo WHERE user_id = :user_id AND tahun = :tahun LIMIT 1");
    $stmtBal->execute(['user_id' => $current_user_id, 'tahun' => $current_year]);
    $balance = $stmtBal->fetch();

    if (!$balance) {
        $initStmt = $conn->prepare("INSERT INTO Cuti_Saldo (user_id, tahun, jatah_tahunan, terpakai) VALUES (:user_id, :tahun, 12, 0)");
        $initStmt->execute(['user_id' => $current_user_id, 'tahun' => $current_year]);
        $stmtBal->execute(['user_id' => $current_user_id, 'tahun' => $current_year]);
        $balance = $stmtBal->fetch();
    }
} catch (PDOException $e) {
    $balance = ['jatah_tahunan' => 12, 'terpakai' => 0, 'sisa' => 12];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // ===== Aksi: Ajukan Cuti Sendiri =====
    if (isset($_POST['action']) && $_POST['action'] === 'submit') {
        $jenis_cuti = $_POST['jenis_cuti'];
        $tgl_mulai = $_POST['tgl_mulai'];
        $tgl_selesai = $_POST['tgl_selesai'];
        $alasan = $_POST['alasan'];

        if (empty($jenis_cuti) || empty($tgl_mulai) || empty($tgl_selesai)) {
            $error_msg = "Jenis Cuti, Tanggal Mulai, dan Tanggal Selesai wajib diisi!";
        } else {
            $start = new DateTime($tgl_mulai);
            $end = new DateTime($tgl_selesai);
            $diff = $start->diff($end)->format("%r%a");
            $duration = intval($diff) + 1;

            if ($duration <= 0) {
                $error_msg = "Tanggal Selesai harus sesudah atau sama dengan Tanggal Mulai!";
            } elseif ($jenis_cuti === 'Cuti Tahunan' && $duration > $balance['sisa']) {
                $error_msg = "Saldo cuti tahunan tidak mencukupi! Sisa saldo: " . $balance['sisa'] . " hari.";
            } else {
                $lampiran = "";
                if (isset($_FILES['lampiran']) && $_FILES['lampiran']['error'] === UPLOAD_ERR_OK) {
                    $file = $_FILES['lampiran'];
                    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                    if (in_array($ext, ['pdf', 'jpg', 'jpeg', 'png'])) {
                        $dir = "../uploads/cuti/";
                        if (!is_dir($dir))
                            mkdir($dir, 0777, true);
                        $fname = "cuti_" . $current_user_id . "_" . time() . "_" . uniqid() . "." . $ext;
                        if (move_uploaded_file($file['tmp_name'], $dir . $fname)) {
                            $lampiran = "uploads/cuti/" . $fname;
                        } else {
                            $error_msg = "Gagal mengunggah dokumen pendukung.";
                        }
                    } else {
                        $error_msg = "Format dokumen tidak didukung. Gunakan PDF, JPG, atau PNG.";
                    }
                }

                if (empty($error_msg)) {
                    try {
                        $conn->beginTransaction();

                        $stmtApp = $conn->prepare("INSERT INTO Approval (jenis_pengajuan, ref_id, requester_id, level, status) VALUES ('Cuti', 0, :requester, 1, 'Menunggu')");
                        $stmtApp->execute(['requester' => $current_user_id]);
                        $approval_id = $conn->lastInsertId();

                        $stmtCuti = $conn->prepare("INSERT INTO Cuti (user_id, jenis_cuti, tgl_mulai, tgl_selesai, total_durasi, alasan, lampiran, status, approval_id) VALUES (:user_id, :jenis, :start, :end, :duration, :alasan, :lampiran, 'Menunggu', :app_id)");
                        $stmtCuti->execute([
                            'user_id' => $current_user_id,
                            'jenis' => $jenis_cuti,
                            'start' => $tgl_mulai,
                            'end' => $tgl_selesai,
                            'duration' => $duration,
                            'alasan' => $alasan,
                            'lampiran' => $lampiran,
                            'app_id' => $approval_id
                        ]);

                        $cuti_id = $conn->lastInsertId();
                        $updApp = $conn->prepare("UPDATE Approval SET ref_id = :cuti_id WHERE id = :app_id");
                        $updApp->execute(['cuti_id' => $cuti_id, 'app_id' => $approval_id]);

                        $conn->commit();
                        $success_msg = "Pengajuan cuti berhasil dikirim! Menunggu persetujuan.";
                        $stmtBal->execute(['user_id' => $current_user_id, 'tahun' => $current_year]);
                        $balance = $stmtBal->fetch();
                    } catch (PDOException $e) {
                        $conn->rollBack();
                        $error_msg = "Gagal memproses pengajuan cuti: " . $e->getMessage();
                    }
                }
            }
        }

        // ===== Aksi: Proses Pengajuan Cuti Karyawan (Setujui/Tolak) =====
    } elseif (isset($_POST['action']) && $_POST['action'] === 'process') {
        $active_tab = 'tabPanelCutiKaryawan';
        $cuti_id = $_POST['cuti_id'];
        $status = $_POST['status']; // Disetujui, Ditolak

        try {
            $conn->beginTransaction();

            $getCuti = $conn->prepare("SELECT * FROM Cuti WHERE id = :id");
            $getCuti->execute(['id' => $cuti_id]);
            $c = $getCuti->fetch();

            if ($c) {
                if ($c['approval_id']) {
                    $appStmt = $conn->prepare("UPDATE Approval SET status = :status, approver_id = :approver, tgl_aksi = NOW() WHERE id = :app_id");
                    $appStmt->execute(['status' => $status, 'approver' => $current_user_id, 'app_id' => $c['approval_id']]);
                }

                $updStmt = $conn->prepare("UPDATE Cuti SET status = :status WHERE id = :id");
                $updStmt->execute(['status' => $status, 'id' => $cuti_id]);

                // Update saldo cuti jika disetujui dan Cuti Tahunan
                if ($status === 'Disetujui' && $c['jenis_cuti'] === 'Cuti Tahunan') {
                    $tahunCuti = date('Y', strtotime($c['tgl_mulai']));
                    $updSaldo = $conn->prepare("UPDATE Cuti_Saldo SET terpakai = terpakai + :durasi WHERE user_id = :user_id AND tahun = :tahun");
                    $updSaldo->execute(['durasi' => $c['total_durasi'], 'user_id' => $c['user_id'], 'tahun' => $tahunCuti]);
                }

                $conn->commit();
                $success_msg = "Pengajuan cuti #" . $cuti_id . " berhasil diperbarui ke status: " . $status;
            } else {
                $conn->rollBack();
                $error_msg = "Data pengajuan cuti tidak ditemukan.";
            }
        } catch (PDOException $e) {
            $conn->rollBack();
            $error_msg = "Terjadi kesalahan database: " . $e->getMessage();
        }
    }
}

// Riwayat cuti milik sendiri
$leaves = [];
try {
    $stmtHistory = $conn->prepare("SELECT * FROM Cuti WHERE user_id = :user_id ORDER BY created_at DESC");
    $stmtHistory->execute(['user_id' => $current_user_id]);
    $leaves = $stmtHistory->fetchAll();
} catch (PDOException $e) {
    $leaves = [];
}

// Seluruh pengajuan cuti karyawan
$all_leaves = [];
try {
    $stmtAll = $conn->query("
        SELECT c.*, u.nama_lengkap, u.email
        FROM Cuti c
        JOIN Users u ON c.user_id = u.id
        ORDER BY c.created_at DESC
    ");
    $all_leaves = $stmtAll->fetchAll();
} catch (PDOException $e) {
    $all_leaves = [];
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

    <!-- Balance Summary Cards -->
    <div class="row g-4 mb-4">
        <div class="col-xl-4 col-md-6 col-12">
            <div class="stat-card">
                <div class="stat-card-info">
                    <span class="stat-card-title">Jatah Cuti Tahunan</span>
                    <span class="stat-card-value"><?= $balance['jatah_tahunan'] ?> Hari</span>
                </div>
                <div class="stat-card-icon">
                    <i class="bi bi-calendar-check-fill"></i>
                </div>
            </div>
        </div>
        <div class="col-xl-4 col-md-6 col-12">
            <div class="stat-card">
                <div class="stat-card-info">
                    <span class="stat-card-title">Cuti Terpakai</span>
                    <span class="stat-card-value text-danger"><?= $balance['terpakai'] ?> Hari</span>
                </div>
                <div class="stat-card-icon danger">
                    <i class="bi bi-calendar-minus-fill"></i>
                </div>
            </div>
        </div>
        <div class="col-xl-4 col-md-6 col-12">
            <div class="stat-card">
                <div class="stat-card-info">
                    <span class="stat-card-title">Sisa Saldo Cuti</span>
                    <span class="stat-card-value text-success"><?= $balance['sisa'] ?> Hari</span>
                </div>
                <div class="stat-card-icon success">
                    <i class="bi bi-calendar-plus-fill"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Tab Navigation -->
    <div class="arp-tab-group">
        <div class="arp-tab-nav">
            <button type="button" class="arp-tab-btn<?= $active_tab === 'tabPanelCutiSaya' ? ' active' : '' ?>"
                data-tab-target="tabPanelCutiSaya" onclick="switchTab('tabPanelCutiSaya', this)">
                <i class="bi bi-calendar-check me-1"></i> Cuti Pribadi
            </button>
            <button type="button" class="arp-tab-btn<?= $active_tab === 'tabPanelCutiKaryawan' ? ' active' : '' ?>"
                data-tab-target="tabPanelCutiKaryawan" onclick="switchTab('tabPanelCutiKaryawan', this)">
                <i class="bi bi-people me-1"></i> Cuti Karyawan
            </button>
        </div>

    <div class="row g-4">
        <!-- Card 1: Riwayat Pengajuan Cuti Anda -->
        <div class="col-12 arp-tab-panel" id="tabPanelCutiSaya"
            <?= $active_tab === 'tabPanelCutiSaya' ? '' : 'style="display:none;"' ?>>
        <div class="card-box mb-4">
        <div class="table-toolbar">
            <h5 class="table-toolbar-title fw-bold">Riwayat Pengajuan Cuti Anda</h5>
            <div class="table-toolbar-actions">
                <div class="search-box-container">
                    <i class="bi bi-search"></i>
                    <input type="text" class="search-box" placeholder="Cari cuti..." data-table-search="tabelCuti"
                        onkeyup="handleTableSearch('tabelCuti')">
                </div>
                <button class="btn-primary-custom" onclick="openModal('modalCuti')">
                    <i class="bi bi-calendar-plus me-1"></i>Ajukan Cuti
                </button>
            </div>
        </div>

        <div class="table-responsive-custom">
            <table class="table-custom" id="tabelCuti">
                <thead>
                    <tr>
                        <th>Jenis Cuti</th>
                        <th>Mulai</th>
                        <th>Selesai</th>
                        <th>Durasi</th>
                        <th>Status</th>
                        <th>Alasan</th>
                        <th>Dokumen</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($leaves) === 0): ?>
                        <tr>
                            <td colspan="7" class="text-center py-4 text-muted">
                                <i class="bi bi-calendar-event d-block mb-2" style="font-size:2rem;"></i>
                                Belum ada riwayat pengajuan cuti.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($leaves as $l): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($l['jenis_cuti']) ?></strong></td>
                                <td><?= date('d-m-Y', strtotime($l['tgl_mulai'])) ?></td>
                                <td><?= date('d-m-Y', strtotime($l['tgl_selesai'])) ?></td>
                                <td><strong><?= $l['total_durasi'] ?> Hari</strong></td>
                                <td>
                                    <?php
                                    $badgeClass = "badge-warning";
                                    if ($l['status'] === 'Disetujui')
                                        $badgeClass = "badge-success";
                                    if ($l['status'] === 'Ditolak')
                                        $badgeClass = "badge-danger";
                                    ?>
                                    <span class="<?= $badgeClass ?>"><?= htmlspecialchars($l['status']) ?></span>
                                </td>
                                <td><?= htmlspecialchars($l['alasan'] ?: '-') ?></td>
                                <td>
                                    <?php if (!empty($l['lampiran'])): ?>
                                        <a href="../<?= htmlspecialchars($l['lampiran']) ?>" target="_blank"
                                            class="btn btn-outline-secondary btn-sm py-1"
                                            style="font-size:0.75rem; border-radius: 8px;">
                                            <i class="bi bi-file-earmark-arrow-down"></i> Lihat
                                        </a>
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
        <div class="pagination-custom" id="pagination-tabelCuti"></div>
        </div>
        </div>

        <!-- Card 2: Data Riwayat Pengajuan Cuti Karyawan -->
        <div class="col-12 arp-tab-panel" id="tabPanelCutiKaryawan"
            <?= $active_tab === 'tabPanelCutiKaryawan' ? '' : 'style="display:none;"' ?>>
            <div class="card-box">
                <div class="table-toolbar">
                    <h5 class="table-toolbar-title fw-bold">Data Riwayat Pengajuan Cuti Karyawan</h5>
                    <div class="table-toolbar-actions">
                        <div class="search-box-container">
                            <i class="bi bi-search"></i>
                            <input type="text" class="search-box" placeholder="Cari karyawan..."
                                data-table-search="tabelCutiKaryawan" onkeyup="handleTableSearch('tabelCutiKaryawan')">
                        </div>
                    </div>
                </div>

                <div class="table-responsive-custom">
                    <table class="table-custom" id="tabelCutiKaryawan">
                        <thead>
                            <tr>
                                <th>Karyawan</th>
                                <th>Jenis Cuti</th>
                                <th>Mulai</th>
                                <th>Selesai</th>
                                <th>Durasi</th>
                                <th>Status</th>
                                <th style="text-align:center;">Tindakan</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($all_leaves) === 0): ?>
                                <tr>
                                    <td colspan="7" class="text-center py-3 text-muted">Belum ada pengajuan cuti karyawan.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($all_leaves as $l): ?>
                                    <tr>
                                        <td>
                                            <div class="fw-bold"><?= htmlspecialchars($l['nama_lengkap']) ?></div>
                                            <small class="text-secondary"><?= htmlspecialchars($l['email']) ?></small>
                                        </td>
                                        <td><?= htmlspecialchars($l['jenis_cuti']) ?></td>
                                        <td><?= date('d-m-Y', strtotime($l['tgl_mulai'])) ?></td>
                                        <td><?= date('d-m-Y', strtotime($l['tgl_selesai'])) ?></td>
                                        <td><strong><?= $l['total_durasi'] ?> Hari</strong></td>
                                        <td>
                                            <?php
                                            $badgeClass = "badge-warning";
                                            if ($l['status'] === 'Disetujui')
                                                $badgeClass = "badge-success";
                                            if ($l['status'] === 'Ditolak')
                                                $badgeClass = "badge-danger";
                                            ?>
                                            <span class="<?= $badgeClass ?>"><?= htmlspecialchars($l['status']) ?></span>
                                        </td>
                                        <td style="text-align:center;">
                                            <?php if ($l['status'] === 'Menunggu'): ?>
                                                <form method="POST" action="cuti.php" style="display:inline-block;">
                                                    <input type="hidden" name="action" value="process">
                                                    <input type="hidden" name="cuti_id" value="<?= $l['id'] ?>">
                                                    <input type="hidden" name="status" value="Disetujui">
                                                    <button type="submit" class="btn-primary-custom"
                                                        style="height:28px; padding:0 8px; font-size:0.75rem;">Setujui</button>
                                                </form>
                                                <form method="POST" action="cuti.php" style="display:inline-block;">
                                                    <input type="hidden" name="action" value="process">
                                                    <input type="hidden" name="cuti_id" value="<?= $l['id'] ?>">
                                                    <input type="hidden" name="status" value="Ditolak">
                                                    <button type="submit" class="btn-danger-custom"
                                                        style="height:28px; padding:0 8px; font-size:0.75rem;">Tolak</button>
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
                <div class="pagination-custom" id="pagination-tabelCutiKaryawan"></div>
            </div>
        </div>
    </div>
    </div>
</main>

<!-- ===== MODAL: Ajukan Cuti ===== -->
<div id="modalCuti" class="arp-modal-overlay" onclick="closeModalOutside(event, 'modalCuti')">
    <div class="arp-modal-box">
        <div class="arp-modal-header">
            <div>
                <h6 class="fw-bold mb-0">Ajukan Permohonan Cuti</h6>
                <small class="text-muted">Sisa saldo cuti tahunan Anda: <strong><?= $balance['sisa'] ?>
                        hari</strong></small>
            </div>
            <button class="arp-modal-close" onclick="closeModal('modalCuti')">&times;</button>
        </div>
        <div class="arp-modal-body">
            <form method="POST" action="cuti.php" enctype="multipart/form-data">
                <input type="hidden" name="action" value="submit">
                <div class="mb-3">
                    <label class="form-label fw-semibold fs-7 mb-2">Jenis Cuti *</label>
                    <select name="jenis_cuti" class="select-custom" required>
                        <option value="Cuti Tahunan">Cuti Tahunan</option>
                        <option value="Cuti Sakit">Cuti Sakit</option>
                        <option value="Cuti Alasan Penting">Cuti Alasan Penting</option>
                        <option value="Izin Melahirkan / Pendampingan">Izin Melahirkan / Pendampingan</option>
                    </select>
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <label class="form-label fw-semibold fs-7 mb-2">Tanggal Mulai *</label>
                        <input type="date" name="tgl_mulai" id="cutiTglMulai" class="form-control-custom"
                            onchange="hitungDurasiCuti()" required>
                    </div>
                    <div class="col-6">
                        <label class="form-label fw-semibold fs-7 mb-2">Tanggal Selesai *</label>
                        <input type="date" name="tgl_selesai" id="cutiTglSelesai" class="form-control-custom"
                            onchange="hitungDurasiCuti()" required>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold fs-7 mb-2">Total Durasi</label>
                    <input type="text" id="cutiTotalDurasi" class="form-control-custom" value="0 Hari" readonly
                        disabled style="background: var(--bg-glass); font-weight:600;">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold fs-7 mb-2">Alasan Permohonan</label>
                    <textarea name="alasan" class="textarea-custom"
                        placeholder="Tuliskan keterangan detail keperluan Anda..."></textarea>
                </div>
                <div class="mb-4">
                    <label class="form-label fw-semibold fs-7 mb-2">Unggah Dokumen Pendukung</label>
                    <input type="file" name="lampiran" class="form-control-custom" style="padding-top:8px;"
                        accept=".pdf,.jpg,.jpeg,.png">
                    <small class="text-muted d-block mt-1">Opsional. Format: PDF, JPG, PNG (contoh: surat sakit,
                        undangan, dsb).</small>
                </div>
                <div class="d-flex gap-2">
                    <button type="button" class="btn-secondary-custom flex-grow-1"
                        onclick="closeModal('modalCuti')">Batal</button>
                    <button type="submit" class="btn-primary-custom flex-grow-1"><i class="bi bi-send me-1"></i> Kirim
                        Pengajuan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        initTablePagination('tabelCuti', 10);
        initTablePagination('tabelCutiKaryawan', 10);
    });

    function hitungDurasiCuti() {
        const mulaiEl = document.getElementById('cutiTglMulai');
        const selesaiEl = document.getElementById('cutiTglSelesai');
        const outputEl = document.getElementById('cutiTotalDurasi');
        if (!mulaiEl.value || !selesaiEl.value) {
            outputEl.value = '0 Hari';
            return;
        }
        const mulai = new Date(mulaiEl.value);
        const selesai = new Date(selesaiEl.value);
        const selisihHari = Math.round((selesai - mulai) / (1000 * 60 * 60 * 24)) + 1;
        outputEl.value = (selisihHari > 0 ? selisihHari : 0) + ' Hari';
    }
</script>

<?php if ($error_msg): ?>
    <script>document.addEventListener('DOMContentLoaded', () => openModal('modalCuti'));</script>
<?php endif; ?>

<?php include "../includes/footer.php"; ?>