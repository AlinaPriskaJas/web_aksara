<?php
// ahlik3/upload.php
require_once "../config/koneksi.php";

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'ahli_k3') {
    header("Location: ../login.php");
    exit;
}

$page_title = "Upload Laporan & Dokumentasi";
include "../includes/header.php";
include "../includes/sidebar.php";
include "../includes/topbar.php";

$current_user_id = $_SESSION['user_id'];
$success_msg = "";
$error_msg = "";

// Get Ahli K3 ID
try {
    $stmtAhli = $conn->prepare("SELECT id FROM Sertifikat_Ahli WHERE user_id = :user_id LIMIT 1");
    $stmtAhli->execute(['user_id' => $current_user_id]);
    $ahli_k3_id = $stmtAhli->fetchColumn() ?: 0;
} catch (PDOException $e) {
    $ahli_k3_id = 0;
}

// Handle File Upload
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $suket_id = $_POST['suket_id'];
    $jenis = $_POST['jenis'];
    $keterangan = $_POST['keterangan'];

    if (empty($suket_id) || !isset($_FILES['file_dokumentasi']) || $_FILES['file_dokumentasi']['error'] !== UPLOAD_ERR_OK) {
        $error_msg = "Pilih unit suket dan lampirkan file dokumentasi yang valid!";
    } else {
        $file = $_FILES['file_dokumentasi'];
        $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
        $allowed = ['jpg', 'jpeg', 'png', 'pdf', 'doc', 'docx'];

        if (!in_array(strtolower($ext), $allowed)) {
            $error_msg = "Format file tidak didukung! Hanya JPG, PNG, PDF, DOC.";
        } else {
            $target_dir = "../uploads/dokumentasi/";
            if (!is_dir($target_dir))
                mkdir($target_dir, 0777, true);

            $filename = "dok_" . time() . "_" . uniqid() . "." . $ext;
            $db_path = "uploads/dokumentasi/" . $filename;
            $target_file = $target_dir . $filename;

            if (move_uploaded_file($file['tmp_name'], $target_file)) {
                try {
                    $stmt = $conn->prepare("INSERT INTO Suket_Dokumentasi (suket_id, jenis, file_path, keterangan, diupload_oleh) VALUES (:suket_id, :jenis, :file_path, :keterangan, :uploader)");
                    $stmt->execute([
                        'suket_id' => $suket_id,
                        'jenis' => $jenis,
                        'file_path' => $db_path,
                        'keterangan' => $keterangan,
                        'uploader' => $current_user_id
                    ]);
                    $success_msg = "Dokumentasi hasil pemeriksaan berhasil diunggah!";
                } catch (PDOException $e) {
                    $error_msg = "Gagal mencatat data ke database: " . $e->getMessage();
                }
            } else {
                $error_msg = "Gagal mengunggah file ke server.";
            }
        }
    }
}

// Fetch Suket list & dokumentasi
$sukets = [];
$dokumentasi = [];
if ($ahli_k3_id > 0) {
    try {
        $stmtSuket = $conn->prepare("
            SELECT s.*, dk.nama_perusahaan, o.nama_unit 
            FROM Suket_K3 s
            JOIN Data_Klien dk ON s.klien_id = dk.id
            JOIN Objek_K3 o ON s.objek_id = o.id
            WHERE s.ahli_k3_id = :ahli_id
            ORDER BY s.tanggal_jadwal DESC
        ");
        $stmtSuket->execute(['ahli_id' => $ahli_k3_id]);
        $sukets = $stmtSuket->fetchAll();

        $stmtDok = $conn->prepare("
            SELECT sd.*, s.nomor_laporan, o.nama_unit 
            FROM Suket_Dokumentasi sd
            JOIN Suket_K3 s ON sd.suket_id = s.id
            JOIN Objek_K3 o ON s.objek_id = o.id
            WHERE sd.diupload_oleh = :uploader
            ORDER BY sd.id DESC
        ");
        $stmtDok->execute(['uploader' => $current_user_id]);
        $dokumentasi = $stmtDok->fetchAll();
    } catch (PDOException $e) {
        $sukets = [];
        $dokumentasi = [];
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
        <div class="alert alert-danger-custom align-items-center" id="errorAlertUpload">
            <i class="bi bi-exclamation-triangle-fill"></i>
            <div><?= htmlspecialchars($error_msg) ?></div>
        </div>
    <?php endif; ?>

    <!-- Content Card -->
    <div class="card-box">
        <div class="table-toolbar">
            <h5 class="table-toolbar-title fw-bold">Daftar Berkas &amp; Foto yang Anda Unggah</h5>
            <div class="table-toolbar-actions">
                <div class="search-box-container">
                    <i class="bi bi-search"></i>
                    <input type="text" class="search-box" placeholder="Cari berkas..." data-table-search="tabelUpload"
                        onkeyup="handleTableSearch('tabelUpload')">
                </div>
                <button class="btn-primary-custom" onclick="openModal('modalUpload')">
                    <i class="bi bi-upload me-1"></i>Upload Laporan
                </button>
            </div>
        </div>

        <!-- Tabel Daftar Berkas -->
        <div class="table-responsive-custom">
            <table class="table-custom" id="tabelUpload">
                <thead>
                    <tr>
                        <th>Unit / No Suket</th>
                        <th>Jenis Dokumen</th>
                        <th>Keterangan</th>
                        <th>Unduh / Tautan</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($dokumentasi) === 0): ?>
                        <tr>
                            <td colspan="4" class="text-center py-4 text-muted">
                                <i class="bi bi-folder-x d-block mb-2" style="font-size:2rem;"></i>
                                Belum ada file dokumentasi yang diunggah.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($dokumentasi as $d): ?>
                            <tr>
                                <td>
                                    <div class="fw-bold"><?= htmlspecialchars($d['nama_unit']) ?></div>
                                    <small class="text-secondary"><?= htmlspecialchars($d['nomor_laporan']) ?></small>
                                </td>
                                <td>
                                    <?php
                                    $badgeClass = "badge-success";
                                    if ($d['jenis'] === 'Foto Lapangan')
                                        $badgeClass = "badge-primary";
                                    if ($d['jenis'] === 'Sertifikat PDF')
                                        $badgeClass = "badge-warning";
                                    ?>
                                    <span class="<?= $badgeClass ?>"><?= htmlspecialchars($d['jenis']) ?></span>
                                </td>
                                <td><?= htmlspecialchars($d['keterangan'] ?: '-') ?></td>
                                <td>
                                    <a href="../<?= htmlspecialchars($d['file_path']) ?>" target="_blank"
                                        class="btn btn-outline-secondary btn-sm py-1"
                                        style="font-size:0.75rem; border-radius: 8px;">
                                        <i class="bi bi-download"></i> Buka File
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="pagination-custom" id="pagination-tabelUpload"></div>
    </div>
</main>

<!-- ===== MODAL: Upload Laporan ===== -->
<div id="modalUpload" class="arp-modal-overlay" onclick="closeModalOutside(event, 'modalUpload')">
    <div class="arp-modal-box">
        <div class="arp-modal-header">
            <div>
                <h6 class="fw-bold mb-0">Unggah Laporan &amp; Foto Lapangan</h6>
                <small class="text-muted">Lampirkan file dokumentasi untuk penugasan suket K3 Anda.</small>
            </div>
            <button class="arp-modal-close" onclick="closeModal('modalUpload')">&times;</button>
        </div>
        <div class="arp-modal-body">
            <form method="POST" action="upload.php" enctype="multipart/form-data">
                <div class="mb-3">
                    <label class="form-label fw-semibold fs-7 mb-2">Pilih Tugas Suket K3 *</label>
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
                    <label class="form-label fw-semibold fs-7 mb-2">Jenis Lampiran *</label>
                    <select name="jenis" class="select-custom" required>
                        <option value="Foto Lapangan">Foto Lapangan (Visual Temuan)</option>
                        <option value="Dokumen Pendukung">Dokumen Pendukung (Draft/Arsip)</option>
                        <option value="Sertifikat PDF">Sertifikat / Laporan Final (PDF)</option>
                        <option value="Lainnya">Lainnya</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold fs-7 mb-2">Pilih File *</label>
                    <input type="file" name="file_dokumentasi" class="form-control-custom" style="padding-top:8px;"
                        required>
                    <small class="text-muted d-block mt-1">Ekstensi yang diizinkan: JPG, PNG, PDF, DOC, DOCX</small>
                </div>
                <div class="mb-4">
                    <label class="form-label fw-semibold fs-7 mb-2">Deskripsi / Keterangan</label>
                    <textarea name="keterangan" class="textarea-custom"
                        placeholder="Contoh: Kondisi APAR di koridor utama berkarat"></textarea>
                </div>
                <div class="d-flex gap-2">
                    <button type="button" class="btn-secondary-custom flex-grow-1"
                        onclick="closeModal('modalUpload')">Batal</button>
                    <button type="submit" class="btn-primary-custom flex-grow-1"><i class="bi bi-upload me-1"></i>
                        Unggah</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        initTablePagination('tabelUpload', 10);
    });
</script>

<?php if ($error_msg): ?>
    <script>document.addEventListener('DOMContentLoaded', () => openModal('modalUpload'));</script>
<?php endif; ?>

<?php include "../includes/footer.php"; ?>