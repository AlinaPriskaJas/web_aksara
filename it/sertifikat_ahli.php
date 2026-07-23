<?php
// ahlik3/sertifikat_ahli.php
require_once "../config/koneksi.php";

if (session_status() === PHP_SESSION_NONE)
    session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'ahli_k3') {
    header("Location: ../login.php");
    exit;
}

$page_title = "Sertifikat Kompetensi Ahli K3";
include "../includes/header.php";
include "../includes/sidebar.php";
include "../includes/topbar.php";

$current_user_id = $_SESSION['user_id'];
$success_msg = "";
$error_msg = "";

$tingkat_ahli_opsi = ['Ahli Utama', 'Ahli Spesialis', 'Ahli Eksternal', 'Helper & Teknisi'];
$bidang_keahlian_opsi = ['PTP', 'PAA', 'Elevator', 'Eskalator', 'PUBT', 'Instalasi Listrik', 'Angkur TKPK'];

// Handle tambah sertifikat baru
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nama_lengkap = trim($_POST['nama_lengkap'] ?? '');
    $tingkat_ahli = trim($_POST['tingkat_ahli'] ?? '');
    $bidang_keahlian_arr = $_POST['bidang_keahlian'] ?? [];
    if (!is_array($bidang_keahlian_arr))
        $bidang_keahlian_arr = [$bidang_keahlian_arr];
    $bidang_keahlian_arr = array_values(array_filter(array_map('trim', $bidang_keahlian_arr)));
    $nomor_sertifikat = trim($_POST['nomor_sertifikat'] ?? '');
    $tanggal_terbit = $_POST['tanggal_terbit'] ?? '';
    $tanggal_kedaluwarsa = $_POST['tanggal_kedaluwarsa'] ?? '';
    $file_sertifikat = "";

    if (
        empty($nama_lengkap) || empty($tingkat_ahli) || empty($bidang_keahlian_arr) ||
        empty($nomor_sertifikat) || empty($tanggal_terbit) || empty($tanggal_kedaluwarsa)
    ) {
        $error_msg = "Semua field wajib diisi (Bidang Keahlian minimal pilih 1), kecuali upload file sertifikat!";
    } elseif (!in_array($tingkat_ahli, $tingkat_ahli_opsi, true)) {
        $error_msg = "Tingkat ahli tidak valid!";
    } elseif (array_diff($bidang_keahlian_arr, $bidang_keahlian_opsi)) {
        $error_msg = "Bidang keahlian tidak valid!";
    } else {
        $bidang_keahlian = implode(', ', $bidang_keahlian_arr);
        // Handle optional file upload
        if (isset($_FILES['file_sertifikat']) && $_FILES['file_sertifikat']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['file_sertifikat'];
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['pdf', 'jpg', 'jpeg', 'png'])) {
                $dir = "../uploads/sertifikat/";
                if (!is_dir($dir))
                    mkdir($dir, 0777, true);
                $fname = "cert_" . time() . "_" . uniqid() . "." . $ext;
                if (move_uploaded_file($file['tmp_name'], $dir . $fname)) {
                    $file_sertifikat = "uploads/sertifikat/" . $fname;
                } else {
                    $error_msg = "Gagal mengunggah file sertifikat.";
                }
            } else {
                $error_msg = "Format file tidak didukung. Gunakan PDF, JPG, atau PNG.";
            }
        }

        if (empty($error_msg)) {
            try {
                $stmt = $conn->prepare("
                    INSERT INTO Sertifikat_Ahli
                        (user_id, nama_lengkap, tingkat_ahli, bidang_keahlian, nomor_sertifikat,
                         tanggal_terbit, tanggal_kedaluwarsa, file_sertifikat, status)
                    VALUES
                        (:user_id, :nama_lengkap, :tingkat_ahli, :bidang_keahlian, :nomor,
                         :terbit, :exp, :file, 'Aktif')
                ");
                $stmt->execute([
                    'user_id' => $current_user_id,
                    'nama_lengkap' => $nama_lengkap,
                    'tingkat_ahli' => $tingkat_ahli,
                    'bidang_keahlian' => $bidang_keahlian,
                    'nomor' => $nomor_sertifikat,
                    'terbit' => $tanggal_terbit,
                    'exp' => $tanggal_kedaluwarsa,
                    'file' => $file_sertifikat
                ]);
                $success_msg = "Sertifikat berhasil ditambahkan ke sistem!";
            } catch (PDOException $e) {
                if ($e->getCode() == 23000) {
                    $error_msg = "Nomor sertifikat sudah terdaftar di sistem!";
                } else {
                    $error_msg = "Gagal menyimpan sertifikat: " . $e->getMessage();
                }
            }
        }
    }
}

// Fetch sertifikat milik user ini
try {
    $stmt = $conn->prepare("SELECT * FROM v_sertifikat_ahli_status WHERE user_id = :user_id ORDER BY tanggal_kedaluwarsa ASC");
    $stmt->execute(['user_id' => $current_user_id]);
    $certs = $stmt->fetchAll();
} catch (PDOException $e) {
    // Fallback jika view belum ada
    try {
        $stmt2 = $conn->prepare("SELECT * FROM Sertifikat_Ahli WHERE user_id = :user_id ORDER BY tanggal_kedaluwarsa ASC");
        $stmt2->execute(['user_id' => $current_user_id]);
        $certs = $stmt2->fetchAll();
    } catch (PDOException $e2) {
        $certs = [];
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
            <h5 class="table-toolbar-title fw-bold">Data Sertifikat Lisensi Kompetensi Pribadi</h5>
            <div class="table-toolbar-actions">
                <div class="search-box-container">
                    <i class="bi bi-search"></i>
                    <input type="text" class="search-box" placeholder="Cari sertifikat..."
                        data-table-search="tabelSertifikatAhli" onkeyup="handleTableSearch('tabelSertifikatAhli')">
                </div>
                <button class="btn-primary-custom" onclick="openModal('modalSertifikat')">
                    <i class="bi bi-award me-1"></i>Tambah Sertifikat
                </button>
            </div>
        </div>

        <!-- Tabel Sertifikat -->
        <div class="table-responsive-custom">
            <table class="table-custom" id="tabelSertifikatAhli">
                <thead>
                    <tr>
                        <th>Nomor Sertifikat</th>
                        <th>Nama Lengkap</th>
                        <!--  <th>Tingkat Ahli</th> -->
                        <th>Bidang Keahlian</th>
                        <th>Tanggal Terbit</th>
                        <th>Masa Berlaku</th>
                        <th>Status</th>
                        <th>Unduh Berkas</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($certs) === 0): ?>
                        <tr>
                            <td colspan="8" class="text-center py-4 text-muted">
                                <i class="bi bi-award d-block mb-2" style="font-size:2rem;"></i>
                                Belum ada sertifikat terdaftar. Klik <strong>+ Tambah Sertifikat</strong> untuk
                                mendaftarkan.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($certs as $c): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($c['nomor_sertifikat']) ?></strong></td>
                                <td><?= htmlspecialchars($c['nama_lengkap']) ?></td>
                                <!-- <td><span class="badge bg-secondary"><?= htmlspecialchars($c['tingkat_ahli']) ?></span></td> -->
                                <td>
                                    <?php foreach (array_map('trim', explode(',', $c['bidang_keahlian'])) as $bk): ?>
                                        <span class="badge bg-secondary me-1 mb-1"><?= htmlspecialchars($bk) ?></span>
                                    <?php endforeach; ?>
                                </td>
                                <td><?= date('d-m-Y', strtotime($c['tanggal_terbit'])) ?></td>
                                <td><?= date('d-m-Y', strtotime($c['tanggal_kedaluwarsa'])) ?></td>
                                <td>
                                    <?php
                                    $badgeClass = "badge-success";
                                    $statusText = $c['status_realtime'] ?? ($c['status'] ?? 'Aktif');
                                    if (str_contains($statusText, 'Expired') || str_contains($statusText, 'Kritis'))
                                        $badgeClass = "badge-danger";
                                    if (str_contains($statusText, 'Peringatan'))
                                        $badgeClass = "badge-warning";
                                    ?>
                                    <span class="<?= $badgeClass ?>"><?= htmlspecialchars($statusText) ?></span>
                                </td>
                                <td>
                                    <?php if (!empty($c['file_sertifikat'])): ?>
                                        <a href="../<?= htmlspecialchars($c['file_sertifikat']) ?>" target="_blank"
                                            class="btn btn-outline-secondary btn-sm py-1"
                                            style="font-size:0.75rem; border-radius: 8px;">
                                            <i class="bi bi-file-earmark-pdf"></i> Unduh
                                        </a>
                                    <?php else: ?>
                                        <span class="text-muted">Tidak ada lampiran</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="pagination-custom" id="pagination-tabelSertifikatAhli"></div>
    </div>
</main>

<!-- ===== MODAL: Tambah Sertifikat ===== -->
<div id="modalSertifikat" class="arp-modal-overlay" onclick="closeModalOutside(event, 'modalSertifikat')">
    <div class="arp-modal-box">
        <div class="arp-modal-header">
            <div>
                <h6 class="fw-bold mb-0">Tambah Sertifikat Kompetensi</h6>
                <small class="text-muted">Daftarkan sertifikat lisensi K3 Anda agar terpantau sistem.</small>
            </div>
            <button class="arp-modal-close" onclick="closeModal('modalSertifikat')">&times;</button>
        </div>
        <div class="arp-modal-body">
            <form method="POST" action="sertifikat_ahli.php" enctype="multipart/form-data">
                <div class="mb-3">
                    <label class="form-label fw-semibold fs-7 mb-2">Nama Lengkap *</label>
                    <input type="text" name="nama_lengkap" class="form-control-custom"
                        placeholder="Nama lengkap sesuai sertifikat" required>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold fs-7 mb-2">Tingkat Ahli *</label>
                    <select name="tingkat_ahli" class="select-custom" required>
                        <option value="">-- Pilih Tingkat Ahli --</option>
                        <?php foreach ($tingkat_ahli_opsi as $opt): ?>
                            <option value="<?= htmlspecialchars($opt) ?>"><?= htmlspecialchars($opt) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold fs-7 mb-2">Bidang Keahlian * <small
                            class="text-muted fw-normal">(bisa pilih lebih dari satu)</small></label>
                    <div class="d-flex flex-wrap gap-2">
                        <?php foreach ($bidang_keahlian_opsi as $opt): ?>
                            <label class="form-control-custom d-flex align-items-center gap-2 mb-0"
                                style="width:auto; cursor:pointer;">
                                <input type="checkbox" name="bidang_keahlian[]" value="<?= htmlspecialchars($opt) ?>">
                                <?= htmlspecialchars($opt) ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold fs-7 mb-2">Nomor Sertifikat *</label>
                    <input type="text" name="nomor_sertifikat" class="form-control-custom"
                        placeholder="Contoh: KEMNAKER/K3U/2024/0001" required>
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <label class="form-label fw-semibold fs-7 mb-2">Tanggal Terbit *</label>
                        <input type="date" name="tanggal_terbit" class="form-control-custom" required>
                    </div>
                    <div class="col-6">
                        <label class="form-label fw-semibold fs-7 mb-2">Tanggal Kedaluwarsa *</label>
                        <input type="date" name="tanggal_kedaluwarsa" class="form-control-custom" required>
                    </div>
                </div>
                <div class="mb-4">
                    <label class="form-label fw-semibold fs-7 mb-2">Upload File Sertifikat</label>
                    <input type="file" name="file_sertifikat" class="form-control-custom" style="padding-top:8px;"
                        accept=".pdf,.jpg,.jpeg,.png">
                    <small class="text-muted d-block mt-1">Format: PDF, JPG, PNG</small>
                </div>
                <div class="d-flex gap-2">
                    <button type="button" class="btn-secondary-custom flex-grow-1"
                        onclick="closeModal('modalSertifikat')">Batal</button>
                    <button type="submit" class="btn-primary-custom flex-grow-1"><i class="bi bi-award me-1"></i> Simpan
                        Sertifikat</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        initTablePagination('tabelSertifikatAhli', 10);
    });
</script>

<?php if ($error_msg): ?>
    <script>document.addEventListener('DOMContentLoaded', () => openModal('modalSertifikat'));</script>
<?php endif; ?>

<?php include "../includes/footer.php"; ?>