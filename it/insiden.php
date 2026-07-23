<?php
// ahlik3/insiden.php
require_once "../config/koneksi.php";

if (session_status() === PHP_SESSION_NONE)
    session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'ahli_k3') {
    header("Location: ../login.php");
    exit;
}

$page_title = "Laporan Insiden K3";
include "../includes/header.php";
include "../includes/sidebar.php";
include "../includes/topbar.php";

$current_user_id = $_SESSION['user_id'];
$success_msg = "";
$error_msg = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $klien_id = $_POST['klien_id'];
    $waktu_kejadian = $_POST['waktu_kejadian'];
    $lokasi_spesifik = $_POST['lokasi_spesifik'];
    $jenis_insiden = $_POST['jenis_insiden'];
    $severity = $_POST['severity'];
    $kronologi = $_POST['kronologi'];
    $tindakan = $_POST['tindakan_korektif'];

    if (empty($klien_id) || empty($waktu_kejadian) || empty($jenis_insiden) || empty($kronologi)) {
        $error_msg = "Klien, Waktu Kejadian, Jenis Insiden, dan Kronologi Kejadian wajib diisi!";
    } else {
        try {
            $stmt = $conn->prepare("
                INSERT INTO Laporan_Insiden (klien_id, pelapor_id, waktu_kejadian, lokasi_spesifik, jenis_insiden, severity, kronologi, tindakan_korektif, lampiran_foto, status_tindak_lanjut)
                VALUES (:klien_id, :pelapor_id, :waktu, :lokasi, :jenis, :severity, :kronologi, :tindakan, '', 'Baru')
            ");
            $stmt->execute([
                'klien_id' => $klien_id,
                'pelapor_id' => $current_user_id,
                'waktu' => $waktu_kejadian,
                'lokasi' => $lokasi_spesifik,
                'jenis' => $jenis_insiden,
                'severity' => $severity,
                'kronologi' => $kronologi,
                'tindakan' => $tindakan,
            ]);
            $success_msg = "Laporan insiden K3 berhasil direkam dan diproses oleh tim HSE!";
        } catch (PDOException $e) {
            $error_msg = "Gagal menyimpan laporan insiden: " . $e->getMessage();
        }
    }
}

$klien_list = [];
try {
    $klien_list = $conn->query("SELECT id, nama_perusahaan FROM Data_Klien ORDER BY nama_perusahaan ASC")->fetchAll();
} catch (PDOException $e) {
    $klien_list = [];
}

$incidents = [];
try {
    $stmtInc = $conn->prepare("
        SELECT li.*, dk.nama_perusahaan 
        FROM Laporan_Insiden li
        JOIN Data_Klien dk ON li.klien_id = dk.id
        WHERE li.pelapor_id = :pelapor_id
        ORDER BY li.waktu_kejadian DESC
    ");
    $stmtInc->execute(['pelapor_id' => $current_user_id]);
    $incidents = $stmtInc->fetchAll();
} catch (PDOException $e) {
    $incidents = [];
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
            <h5 class="table-toolbar-title fw-bold">Riwayat Laporan Insiden K3 Anda</h5>
            <div class="table-toolbar-actions">
                <div class="search-box-container">
                    <i class="bi bi-search"></i>
                    <input type="text" class="search-box" placeholder="Cari insiden..." data-table-search="tabelInsiden"
                        onkeyup="handleTableSearch('tabelInsiden')">
                </div>
                <button class="btn-primary-custom" onclick="openModal('modalInsiden')">
                    <i class="bi bi-cone-striped me-1"></i>Laporan Insiden
                </button>
            </div>
        </div>

        <!-- Tabel Riwayat Insiden -->
        <div class="table-responsive-custom">
            <table class="table-custom" id="tabelInsiden">
                <thead>
                    <tr>
                        <th>Klien / Lokasi</th>
                        <th>Insiden / Severity</th>
                        <th>Tanggal</th>
                        <th>Tindak Lanjut</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($incidents) === 0): ?>
                        <tr>
                            <td colspan="4" class="text-center py-4 text-muted">
                                <i class="bi bi-shield-check d-block mb-2" style="font-size:2rem;"></i>
                                Belum pernah melaporkan kejadian insiden.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($incidents as $inc): ?>
                            <tr>
                                <td>
                                    <div class="fw-bold"><?= htmlspecialchars($inc['nama_perusahaan']) ?></div>
                                    <small
                                        class="text-secondary"><?= htmlspecialchars($inc['lokasi_spesifik'] ?: '-') ?></small>
                                </td>
                                <td>
                                    <div class="fw-bold"><?= htmlspecialchars($inc['jenis_insiden']) ?></div>
                                    <span class="badge bg-danger" style="font-size:0.75rem;">Severity:
                                        <?= htmlspecialchars($inc['severity']) ?></span>
                                </td>
                                <td><?= date('d-m-Y H:i', strtotime($inc['waktu_kejadian'])) ?></td>
                                <td>
                                    <?php
                                    $badgeClass = "badge-warning";
                                    if ($inc['status_tindak_lanjut'] === 'Selesai')
                                        $badgeClass = "badge-success";
                                    ?>
                                    <span
                                        class="<?= $badgeClass ?>"><?= htmlspecialchars($inc['status_tindak_lanjut']) ?></span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="pagination-custom" id="pagination-tabelInsiden"></div>
    </div>
</main>

<!-- ===== MODAL: Laporan Insiden ===== -->
<div id="modalInsiden" class="arp-modal-overlay" onclick="closeModalOutside(event, 'modalInsiden')">
    <div class="arp-modal-box">
        <div class="arp-modal-header">
            <div>
                <h6 class="fw-bold mb-0">Laporkan Insiden K3 Baru</h6>
                <small class="text-muted">Isi form ini untuk melaporkan kejadian bahaya atau kecelakaan kerja.</small>
            </div>
            <button class="arp-modal-close" onclick="closeModal('modalInsiden')">&times;</button>
        </div>
        <div class="arp-modal-body">
            <form method="POST" action="insiden.php">
                <div class="mb-3">
                    <label class="form-label fw-semibold fs-7 mb-2">Perusahaan / Klien Terkait *</label>
                    <select name="klien_id" class="select-custom" required>
                        <option value="">-- Pilih Klien --</option>
                        <?php foreach ($klien_list as $k): ?>
                            <option value="<?= $k['id'] ?>"><?= htmlspecialchars($k['nama_perusahaan']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold fs-7 mb-2">Waktu Kejadian *</label>
                    <input type="datetime-local" name="waktu_kejadian" class="form-control-custom" required>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold fs-7 mb-2">Lokasi Spesifik Kejadian</label>
                    <input type="text" name="lokasi_spesifik" class="form-control-custom"
                        placeholder="Contoh: Gedung A Lantai Dasar, Area Produksi">
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <label class="form-label fw-semibold fs-7 mb-2">Jenis Insiden *</label>
                        <select name="jenis_insiden" class="select-custom" required>
                            <option value="Unsafe Action">Unsafe Action</option>
                            <option value="Unsafe Condition">Unsafe Condition</option>
                            <option value="Near-Miss">Near-Miss</option>
                            <option value="Minor Accident">Minor Accident</option>
                            <option value="Major Accident">Major Accident</option>
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label fw-semibold fs-7 mb-2">Severity *</label>
                        <select name="severity" class="select-custom" required>
                            <option value="Low">Low</option>
                            <option value="Medium">Medium</option>
                            <option value="Major">Major</option>
                        </select>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold fs-7 mb-2">Kronologi Kejadian *</label>
                    <textarea name="kronologi" class="textarea-custom" required
                        placeholder="Deskripsikan kronologi kecelakaan/kondisi bahaya secara lengkap..."></textarea>
                </div>
                <div class="mb-4">
                    <label class="form-label fw-semibold fs-7 mb-2">Tindakan Korektif Sementara</label>
                    <textarea name="tindakan_korektif" class="textarea-custom"
                        placeholder="Tindakan darurat yang telah diupayakan..."></textarea>
                </div>
                <div class="d-flex gap-2">
                    <button type="button" class="btn-secondary-custom flex-grow-1"
                        onclick="closeModal('modalInsiden')">Batal</button>
                    <button type="submit" class="btn-primary-custom flex-grow-1"><i class="bi bi-send me-1"></i> Kirim
                        Laporan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        initTablePagination('tabelInsiden', 10);
    });
</script>

<?php if ($error_msg): ?>
    <script>document.addEventListener('DOMContentLoaded', () => openModal('modalInsiden'));</script>
<?php endif; ?>

<?php include "../includes/footer.php"; ?>