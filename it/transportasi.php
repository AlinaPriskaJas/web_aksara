<?php
// admin/transportasi.php
$page_title = "Manajemen Transportasi";
include "../includes/header.php";
include "../includes/sidebar.php";
include "../includes/topbar.php";
require_once "../config/koneksi.php";

// Authorization Check
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'it') {
    header("Location: ../login.php");
    exit;
}

$success_msg = "";
$error_msg = "";
$active_tab = 'tabPanelKendaraan';

// Handle Add/Edit/Status Update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'save_vehicle') {
        $nama_kendaraan = $_POST['nama_kendaraan'];
        $plat_nomor = $_POST['plat_nomor'];
        $jenis = $_POST['jenis'];
        $status_kendaraan = $_POST['status_kendaraan'];

        if (empty($nama_kendaraan) || empty($plat_nomor)) {
            $error_msg = "Nama kendaraan dan plat nomor wajib diisi!";
        } else {
            try {
                $stmt = $conn->prepare("INSERT INTO Kendaraan (nama_kendaraan, plat_nomor, jenis, status_kendaraan) VALUES (:nama, :plat, :jenis, :status) ON DUPLICATE KEY UPDATE nama_kendaraan = :nama, jenis = :jenis, status_kendaraan = :status");
                $stmt->execute([
                    'nama' => $nama_kendaraan,
                    'plat' => $plat_nomor,
                    'jenis' => $jenis,
                    'status' => $status_kendaraan
                ]);
                $success_msg = "Data kendaraan berhasil disimpan!";
            } catch (PDOException $e) {
                $error_msg = "Gagal menyimpan kendaraan: " . $e->getMessage();
            }
        }
    } elseif (isset($_POST['action']) && $_POST['action'] === 'approve_loan') {
        $active_tab = 'tabPanelPeminjaman';
        $loan_id = $_POST['loan_id'];
        $status = $_POST['status'];

        try {
            $stmt = $conn->prepare("UPDATE Peminjaman_Kendaraan SET status_peminjaman = :status WHERE id = :id");
            $stmt->execute(['status' => $status, 'id' => $loan_id]);

            // If approved or berlangsung, change vehicle status accordingly
            if ($status === 'Disetujui' || $status === 'Berlangsung') {
                $getVeh = $conn->prepare("SELECT kendaraan_id FROM Peminjaman_Kendaraan WHERE id = :id");
                $getVeh->execute(['id' => $loan_id]);
                $vehId = $getVeh->fetchColumn();

                $stmtVeh = $conn->prepare("UPDATE Kendaraan SET status_kendaraan = 'Dipakai' WHERE id = :veh_id");
                $stmtVeh->execute(['veh_id' => $vehId]);
            } elseif ($status === 'Selesai') {
                $getVeh = $conn->prepare("SELECT kendaraan_id FROM Peminjaman_Kendaraan WHERE id = :id");
                $getVeh->execute(['id' => $loan_id]);
                $vehId = $getVeh->fetchColumn();

                $stmtVeh = $conn->prepare("UPDATE Kendaraan SET status_kendaraan = 'Tersedia' WHERE id = :veh_id");
                $stmtVeh->execute(['veh_id' => $vehId]);
            }
            $success_msg = "Persetujuan peminjaman diperbarui!";
        } catch (PDOException $e) {
            $error_msg = "Gagal memperbarui persetujuan: " . $e->getMessage();
        }
    }
}

// Fetch Vehicles
$vehicles = $conn->query("SELECT * FROM Kendaraan ORDER BY nama_kendaraan ASC")->fetchAll();

// Fetch Loans
$loans = $conn->query("
    SELECT pk.*, k.nama_kendaraan, k.plat_nomor, u.nama_lengkap 
    FROM Peminjaman_Kendaraan pk
    JOIN Kendaraan k ON pk.kendaraan_id = k.id
    JOIN Users u ON pk.user_id = u.id
    ORDER BY pk.tgl_mulai DESC
")->fetchAll();
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

    <!-- Tab Navigation -->
    <div class="arp-tab-group">
        <div class="arp-tab-nav">
            <button type="button" class="arp-tab-btn<?= $active_tab === 'tabPanelKendaraan' ? ' active' : '' ?>"
                data-tab-target="tabPanelKendaraan" onclick="switchTab('tabPanelKendaraan', this)">
                <i class="bi bi-truck me-1"></i> Daftar Kendaraan
            </button>
            <button type="button" class="arp-tab-btn<?= $active_tab === 'tabPanelPeminjaman' ? ' active' : '' ?>"
                data-tab-target="tabPanelPeminjaman" onclick="switchTab('tabPanelPeminjaman', this)">
                <i class="bi bi-journal-text me-1"></i> Pengajuan Peminjaman
            </button>
        </div>

    <div class="row g-4">
        <!-- Daftar Kendaraan -->
        <div class="col-12 arp-tab-panel" id="tabPanelKendaraan" <?= $active_tab === 'tabPanelKendaraan' ? '' : 'style="display:none;"' ?>>
            <div class="card-box">
                <div class="table-toolbar">
                    <h5 class="table-toolbar-title fw-bold">Daftar Kendaraan Operasional</h5>
                    <div class="table-toolbar-actions">
                        <div class="search-box-container">
                            <i class="bi bi-search"></i>
                            <input type="text" class="search-box" placeholder="Cari kendaraan..."
                                data-table-search="tabelKendaraan" onkeyup="handleTableSearch('tabelKendaraan')">
                        </div>
                        <button class="btn-primary-custom" onclick="openModal('modalKendaraan')">
                            <i class="bi bi-plus-lg"></i>Tambah / Update Kendaraan
                        </button>
                    </div>
                </div>
                <div class="table-responsive-custom">
                    <table class="table-custom" id="tabelKendaraan">
                        <thead>
                            <tr>
                                <th>Nama Kendaraan</th>
                                <th>Plat Nomor</th>
                                <th>Jenis</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($vehicles) === 0): ?>
                                <tr>
                                    <td colspan="4" class="text-center py-4 text-muted">Belum ada armada terdaftar.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($vehicles as $v): ?>
                                    <tr>
                                        <td><strong><?= htmlspecialchars($v['nama_kendaraan']) ?></strong></td>
                                        <td><span class="badge bg-secondary"><?= htmlspecialchars($v['plat_nomor']) ?></span>
                                        </td>
                                        <td><?= htmlspecialchars($v['jenis'] ?: '-') ?></td>
                                        <td>
                                            <?php
                                            $badgeClass = "badge-success";
                                            if ($v['status_kendaraan'] === 'Dipakai')
                                                $badgeClass = "badge-warning";
                                            if ($v['status_kendaraan'] === 'Maintenance')
                                                $badgeClass = "badge-danger";
                                            ?>
                                            <span
                                                class="<?= $badgeClass ?>"><?= htmlspecialchars($v['status_kendaraan']) ?></span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div class="pagination-custom" id="pagination-tabelKendaraan"></div>
            </div>
        </div>

        <!-- Pengajuan & Peminjaman -->
        <div class="col-12 arp-tab-panel" id="tabPanelPeminjaman" <?= $active_tab === 'tabPanelPeminjaman' ? '' : 'style="display:none;"' ?>>
            <div class="card-box">
                <div class="table-toolbar">
                    <h5 class="table-toolbar-title fw-bold">Daftar Pengajuan Peminjaman Kendaraan</h5>
                    <div class="table-toolbar-actions">
                        <div class="search-box-container">
                            <i class="bi bi-search"></i>
                            <input type="text" class="search-box" placeholder="Cari pengajuan..."
                                data-table-search="tabelPeminjaman" onkeyup="handleTableSearch('tabelPeminjaman')">
                        </div>
                        <button class="btn-secondary-custom" onclick="openModal('modalPeminjaman')">
                            <i class="bi bi-plus-lg"></i>Pengajuan Peminjaman
                        </button>
                    </div>
                </div>
                <div class="table-responsive-custom">
                    <table class="table-custom" id="tabelPeminjaman">
                        <thead>
                            <tr>
                                <th>Peminjam</th>
                                <th>Kendaraan</th>
                                <th>Durasi</th>
                                <th>Tujuan / Keperluan</th>
                                <th>Status</th>
                                <th style="text-align: center;">Tindakan</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($loans) === 0): ?>
                                <tr>
                                    <td colspan="6" class="text-center py-4 text-muted">Belum ada riwayat peminjaman.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($loans as $l): ?>
                                    <tr>
                                        <td><strong><?= htmlspecialchars($l['nama_lengkap']) ?></strong></td>
                                        <td><?= htmlspecialchars($l['nama_kendaraan']) ?>
                                            (<?= htmlspecialchars($l['plat_nomor']) ?>)</td>
                                        <td><?= date('d-m-Y', strtotime($l['tgl_mulai'])) ?> s/d
                                            <?= date('d-m-Y', strtotime($l['tgl_selesai'])) ?></td>
                                        <td>
                                            <div class="fw-bold"><?= htmlspecialchars($l['tujuan_lokasi']) ?></div>
                                            <small class="text-secondary"><?= htmlspecialchars($l['keperluan_dinas']) ?></small>
                                        </td>
                                        <td>
                                            <?php
                                            $badgeClass = "badge-warning";
                                            if ($l['status_peminjaman'] === 'Disetujui' || $l['status_peminjaman'] === 'Selesai')
                                                $badgeClass = "badge-success";
                                            if ($l['status_peminjaman'] === 'Ditolak')
                                                $badgeClass = "badge-danger";
                                            ?>
                                            <span
                                                class="<?= $badgeClass ?>"><?= htmlspecialchars($l['status_peminjaman']) ?></span>
                                        </td>
                                        <td style="text-align: center;">
                                            <?php if ($l['status_peminjaman'] === 'Diajukan'): ?>
                                                <form method="POST" action="transportasi.php" style="display:inline-block;">
                                                    <input type="hidden" name="action" value="approve_loan">
                                                    <input type="hidden" name="loan_id" value="<?= $l['id'] ?>">
                                                    <input type="hidden" name="status" value="Disetujui">
                                                    <button type="submit" class="btn-primary-custom"
                                                        style="height:28px; padding:0 8px; font-size:0.75rem; background-color:var(--success);">Setujui</button>
                                                </form>
                                                <form method="POST" action="transportasi.php" style="display:inline-block;">
                                                    <input type="hidden" name="action" value="approve_loan">
                                                    <input type="hidden" name="loan_id" value="<?= $l['id'] ?>">
                                                    <input type="hidden" name="status" value="Ditolak">
                                                    <button type="submit" class="btn-danger-custom"
                                                        style="height:28px; padding:0 8px; font-size:0.75rem;">Tolak</button>
                                                </form>
                                            <?php elseif ($l['status_peminjaman'] === 'Disetujui'): ?>
                                                <form method="POST" action="transportasi.php" style="display:inline;">
                                                    <input type="hidden" name="action" value="approve_loan">
                                                    <input type="hidden" name="loan_id" value="<?= $l['id'] ?>">
                                                    <input type="hidden" name="status" value="Selesai">
                                                    <button type="submit" class="btn-primary-custom"
                                                        style="height:28px; padding:0 8px; font-size:0.75rem;">Selesaikan</button>
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
                <div class="pagination-custom" id="pagination-tabelPeminjaman"></div>
            </div>
        </div>
    </div>
    </div>

    <!-- Modal: Tambah / Update Kendaraan -->
    <div class="arp-modal-overlay" id="modalKendaraan" onclick="closeModalOutside(event, 'modalKendaraan')">
        <div class="arp-modal-box" style="max-width:500px;">
            <div class="arp-modal-header">
                <div>
                    <h5 class="fw-bold mb-0">Tambah / Update Kendaraan</h5>
                    <small class="text-muted">Daftarkan atau perbarui data armada kendaraan</small>
                </div>
                <button class="arp-modal-close" onclick="closeModal('modalKendaraan')">&times;</button>
            </div>
            <div class="arp-modal-body">
                <form method="POST" action="transportasi.php">
                    <input type="hidden" name="action" value="save_vehicle">
                    <div class="mb-3">
                        <label class="form-label fw-semibold mb-2">Nama Kendaraan *</label>
                        <input type="text" name="nama_kendaraan" class="form-control-custom"
                            placeholder="Contoh: Toyota Avanza Silver" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold mb-2">Plat Nomor *</label>
                        <input type="text" name="plat_nomor" class="form-control-custom"
                            placeholder="Contoh: D 1234 ABC" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold mb-2">Jenis</label>
                        <input type="text" name="jenis" class="form-control-custom"
                            placeholder="Contoh: Mobil MPV / Motor">
                    </div>
                    <div class="mb-4">
                        <label class="form-label fw-semibold mb-2">Status Kendaraan</label>
                        <select name="status_kendaraan" class="select-custom">
                            <option value="Tersedia">Tersedia</option>
                            <option value="Dipakai">Dipakai</option>
                            <option value="Maintenance">Maintenance</option>
                        </select>
                    </div>
                    <div class="d-flex gap-2 justify-content-end">
                        <button type="button" class="btn-secondary-custom"
                            onclick="closeModal('modalKendaraan')">Batal</button>
                        <button type="submit" class="btn-primary-custom">Simpan Kendaraan</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal: Pengajuan Peminjaman Kendaraan -->
    <div class="arp-modal-overlay" id="modalPeminjaman" onclick="closeModalOutside(event, 'modalPeminjaman')">
        <div class="arp-modal-box" style="max-width:520px;">
            <div class="arp-modal-header">
                <div>
                    <h5 class="fw-bold mb-0">Pengajuan Peminjaman Kendaraan</h5>
                    <small class="text-muted">Isi form untuk mengajukan peminjaman kendaraan operasional</small>
                </div>
                <button class="arp-modal-close" onclick="closeModal('modalPeminjaman')">&times;</button>
            </div>
            <div class="arp-modal-body">
                <form method="POST" action="transportasi.php">
                    <input type="hidden" name="action" value="request_loan">
                    <div class="mb-3">
                        <label class="form-label fw-semibold mb-2">Kendaraan *</label>
                        <select name="kendaraan_id" class="select-custom" required>
                            <option value="">-- Pilih Kendaraan --</option>
                            <?php foreach ($vehicles as $v):
                                if ($v['status_kendaraan'] === 'Tersedia'): ?>
                                    <option value="<?= $v['id'] ?>"><?= htmlspecialchars($v['nama_kendaraan']) ?>
                                        (<?= htmlspecialchars($v['plat_nomor']) ?>)</option>
                                <?php endif; endforeach; ?>
                        </select>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-6">
                            <label class="form-label fw-semibold mb-2">Tanggal Mulai *</label>
                            <input type="date" name="tgl_mulai" class="form-control-custom" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-semibold mb-2">Tanggal Selesai *</label>
                            <input type="date" name="tgl_selesai" class="form-control-custom" required>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold mb-2">Tujuan Lokasi *</label>
                        <input type="text" name="tujuan_lokasi" class="form-control-custom"
                            placeholder="Contoh: Cikarang, Bekasi" required>
                    </div>
                    <div class="mb-4">
                        <label class="form-label fw-semibold mb-2">Keperluan Dinas</label>
                        <textarea name="keperluan_dinas" class="textarea-custom"
                            placeholder="Jelaskan tujuan penggunaan kendaraan"></textarea>
                    </div>
                    <div class="d-flex gap-2 justify-content-end">
                        <button type="button" class="btn-secondary-custom"
                            onclick="closeModal('modalPeminjaman')">Batal</button>
                        <button type="submit" class="btn-primary-custom">Ajukan Peminjaman</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</main>


<script>
    document.addEventListener('DOMContentLoaded', function () {
        initTablePagination('tabelKendaraan', 10);
        initTablePagination('tabelPeminjaman', 10);
    });
</script>

<?php
include "../includes/footer.php";
?>