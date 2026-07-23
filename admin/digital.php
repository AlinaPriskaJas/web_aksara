<?php
// admin/digital.php
session_start();
require_once "../config/koneksi.php";

// TODO: ganti dengan user_id dari sesi login sebenarnya (proses_login.php belum tersambung penuh).
$admin_id = $_SESSION['user_id'] ?? 1;

$page_title = "Digital Sign - Arsip Digital Perusahaan";
$flash = null;

const KATEGORI_DOKUMEN = ['Suket K3', 'Sertifikat Ahli', 'Legal Perusahaan', 'Kontrak Klien', 'Laporan', 'Lainnya'];
const VISIBILITAS_DOKUMEN = ['Internal', 'Client', 'Publik'];
const EXT_DIIZINKAN = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'jpg', 'jpeg', 'png'];
const MAX_UKURAN_FILE = 10 * 1024 * 1024; // 10 MB

function slug_nama(string $text): string
{
    $text = strtolower(trim($text));
    $text = preg_replace('/[^a-z0-9]+/', '-', $text);
    return trim($text, '-') ?: 'dokumen';
}

// ================== PROSES: UPLOAD DOKUMEN BARU ==================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['aksi']) && $_POST['aksi'] === 'upload') {
    $nama_dokumen = trim($_POST['nama_dokumen'] ?? '');
    $kategori     = $_POST['kategori'] ?? '';
    $klien_id     = (int) ($_POST['klien_id'] ?? 0);
    $visibilitas  = $_POST['visibilitas'] ?? 'Internal';

    if ($nama_dokumen === '' || !in_array($kategori, KATEGORI_DOKUMEN, true) || !in_array($visibilitas, VISIBILITAS_DOKUMEN, true)) {
        $flash = ['type' => 'danger', 'message' => 'Nama dokumen, kategori, dan visibilitas wajib diisi dengan benar.'];
    } elseif (!isset($_FILES['file_dokumen']) || $_FILES['file_dokumen']['error'] !== UPLOAD_ERR_OK) {
        $flash = ['type' => 'danger', 'message' => 'File dokumen wajib diunggah.'];
    } else {
        $ext = strtolower(pathinfo($_FILES['file_dokumen']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, EXT_DIIZINKAN, true)) {
            $flash = ['type' => 'danger', 'message' => 'Format file harus PDF, Word, Excel, JPG, atau PNG.'];
        } elseif ($_FILES['file_dokumen']['size'] > MAX_UKURAN_FILE) {
            $flash = ['type' => 'danger', 'message' => 'Ukuran file maksimal 10 MB.'];
        } else {
            $upload_dir = "../uploads/dokumen_digital/";
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            $filename = 'dok_' . slug_nama($nama_dokumen) . '_' . time() . '.' . $ext;

            if (move_uploaded_file($_FILES['file_dokumen']['tmp_name'], $upload_dir . $filename)) {
                $file_path = 'uploads/dokumen_digital/' . $filename;
                try {
                    $stmt = $conn->prepare("
                        INSERT INTO Dokumen_Digital (nama_dokumen, kategori, file_path, modul_sumber, ref_id, klien_id, visibilitas, diupload_oleh)
                        VALUES (:nama_dokumen, :kategori, :file_path, 'Manual - Digital Sign', NULL, :klien_id, :visibilitas, :diupload_oleh)
                    ");
                    $stmt->execute([
                        ':nama_dokumen' => $nama_dokumen,
                        ':kategori'     => $kategori,
                        ':file_path'    => $file_path,
                        ':klien_id'     => $klien_id > 0 ? $klien_id : null,
                        ':visibilitas'  => $visibilitas,
                        ':diupload_oleh' => $admin_id,
                    ]);
                    $flash = ['type' => 'success', 'message' => 'Dokumen berhasil diunggah ke Arsip Digital.'];
                } catch (PDOException $e) {
                    @unlink($upload_dir . $filename);
                    $flash = ['type' => 'danger', 'message' => 'Gagal menyimpan data dokumen: ' . $e->getMessage()];
                }
            } else {
                $flash = ['type' => 'danger', 'message' => 'Gagal mengunggah file, silakan coba lagi.'];
            }
        }
    }
    $_SESSION['digital_flash'] = $flash;
    header("Location: digital.php" . (!empty($_GET) ? '?' . http_build_query($_GET) : ''));
    exit;
}

// ================== PROSES: EDIT DOKUMEN ==================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['aksi']) && $_POST['aksi'] === 'edit') {
    $id           = (int) ($_POST['id'] ?? 0);
    $nama_dokumen = trim($_POST['nama_dokumen'] ?? '');
    $kategori     = $_POST['kategori'] ?? '';
    $klien_id     = (int) ($_POST['klien_id'] ?? 0);
    $visibilitas  = $_POST['visibilitas'] ?? 'Internal';

    if (!$id || $nama_dokumen === '' || !in_array($kategori, KATEGORI_DOKUMEN, true) || !in_array($visibilitas, VISIBILITAS_DOKUMEN, true)) {
        $flash = ['type' => 'danger', 'message' => 'Data tidak valid.'];
    } else {
        try {
            $cek = $conn->prepare("SELECT file_path FROM Dokumen_Digital WHERE id = :id");
            $cek->execute([':id' => $id]);
            $existing = $cek->fetch();

            if (!$existing) {
                throw new RuntimeException("Dokumen tidak ditemukan.");
            }

            $file_path = $existing['file_path'];
            $file_lama = null;

            // Ganti file jika admin mengunggah file baru
            if (isset($_FILES['file_dokumen']) && $_FILES['file_dokumen']['error'] === UPLOAD_ERR_OK) {
                $ext = strtolower(pathinfo($_FILES['file_dokumen']['name'], PATHINFO_EXTENSION));
                if (!in_array($ext, EXT_DIIZINKAN, true)) {
                    throw new RuntimeException("Format file harus PDF, Word, Excel, JPG, atau PNG.");
                }
                if ($_FILES['file_dokumen']['size'] > MAX_UKURAN_FILE) {
                    throw new RuntimeException("Ukuran file maksimal 10 MB.");
                }
                $upload_dir = "../uploads/dokumen_digital/";
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0755, true);
                }
                $filename = 'dok_' . slug_nama($nama_dokumen) . '_' . time() . '.' . $ext;
                if (!move_uploaded_file($_FILES['file_dokumen']['tmp_name'], $upload_dir . $filename)) {
                    throw new RuntimeException("Gagal mengunggah file baru.");
                }
                $file_lama = $file_path;
                $file_path = 'uploads/dokumen_digital/' . $filename;
            }

            $stmt = $conn->prepare("
                UPDATE Dokumen_Digital
                SET nama_dokumen = :nama_dokumen, kategori = :kategori, klien_id = :klien_id,
                    visibilitas = :visibilitas, file_path = :file_path
                WHERE id = :id
            ");
            $stmt->execute([
                ':nama_dokumen' => $nama_dokumen,
                ':kategori'     => $kategori,
                ':klien_id'     => $klien_id > 0 ? $klien_id : null,
                ':visibilitas'  => $visibilitas,
                ':file_path'    => $file_path,
                ':id'           => $id,
            ]);

            if ($file_lama && file_exists("../" . $file_lama)) {
                @unlink("../" . $file_lama);
            }

            $flash = ['type' => 'success', 'message' => 'Dokumen berhasil diperbarui.'];
        } catch (Exception $e) {
            $flash = ['type' => 'danger', 'message' => 'Gagal memperbarui dokumen: ' . $e->getMessage()];
        }
    }
    $_SESSION['digital_flash'] = $flash;
    header("Location: digital.php" . (!empty($_GET) ? '?' . http_build_query($_GET) : ''));
    exit;
}

// ================== PROSES: HAPUS DOKUMEN ==================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['aksi']) && $_POST['aksi'] === 'hapus') {
    $id = (int) ($_POST['id'] ?? 0);
    if ($id) {
        try {
            $cek = $conn->prepare("SELECT file_path FROM Dokumen_Digital WHERE id = :id");
            $cek->execute([':id' => $id]);
            $row = $cek->fetch();

            if ($row) {
                $stmt = $conn->prepare("DELETE FROM Dokumen_Digital WHERE id = :id");
                $stmt->execute([':id' => $id]);

                if (!empty($row['file_path']) && file_exists("../" . $row['file_path'])) {
                    @unlink("../" . $row['file_path']);
                }
                $flash = ['type' => 'success', 'message' => 'Dokumen berhasil dihapus dari arsip.'];
            } else {
                $flash = ['type' => 'danger', 'message' => 'Dokumen tidak ditemukan.'];
            }
        } catch (PDOException $e) {
            $flash = ['type' => 'danger', 'message' => 'Gagal menghapus dokumen: ' . $e->getMessage()];
        }
    }
    $_SESSION['digital_flash'] = $flash;
    header("Location: digital.php" . (!empty($_GET) ? '?' . http_build_query($_GET) : ''));
    exit;
}

$flash = $_SESSION['digital_flash'] ?? $flash;
unset($_SESSION['digital_flash']);

// ================== FILTER ==================
$q               = trim($_GET['q'] ?? '');
$kategori_filter = $_GET['kategori'] ?? 'Semua';
$visibilitas_filter = $_GET['visibilitas'] ?? 'Semua';

if (!in_array($kategori_filter, KATEGORI_DOKUMEN, true)) {
    $kategori_filter = 'Semua';
}
if (!in_array($visibilitas_filter, VISIBILITAS_DOKUMEN, true)) {
    $visibilitas_filter = 'Semua';
}

// ================== DAFTAR KLIEN (untuk dropdown) ==================
$daftar_klien = [];
try {
    $stmt = $conn->query("SELECT id, kode_klien, nama_perusahaan FROM Data_Klien ORDER BY nama_perusahaan ASC");
    $daftar_klien = $stmt->fetchAll();
} catch (PDOException $e) {
    $daftar_klien = [];
}

// ================== STATISTIK ==================
$total_dokumen     = 0;
$dokumen_bulan_ini  = 0;
$terhubung_klien    = 0;
$visibilitas_publik = 0;
try {
    $total_dokumen = (int) $conn->query("SELECT COUNT(*) FROM Dokumen_Digital")->fetchColumn();

    $stmtBulan = $conn->query("SELECT COUNT(*) FROM Dokumen_Digital WHERE MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE())");
    $dokumen_bulan_ini = (int) $stmtBulan->fetchColumn();

    $terhubung_klien = (int) $conn->query("SELECT COUNT(*) FROM Dokumen_Digital WHERE klien_id IS NOT NULL")->fetchColumn();

    $visibilitas_publik = (int) $conn->query("SELECT COUNT(*) FROM Dokumen_Digital WHERE visibilitas = 'Publik'")->fetchColumn();
} catch (PDOException $e) {
    // biarkan default 0 jika query gagal
}

// ================== DAFTAR DOKUMEN ==================
$daftar_dokumen = [];
try {
    $sql = "
        SELECT dd.*, dk.nama_perusahaan, dk.kode_klien, u.nama_lengkap AS nama_pengunggah
        FROM Dokumen_Digital dd
        LEFT JOIN Data_Klien dk ON dd.klien_id = dk.id
        LEFT JOIN Users u ON dd.diupload_oleh = u.id
        WHERE 1=1
    ";
    $params = [];

    if ($q !== '') {
        $sql .= " AND (dd.nama_dokumen LIKE :q OR dk.nama_perusahaan LIKE :q) ";
        $params[':q'] = '%' . $q . '%';
    }
    if ($kategori_filter !== 'Semua') {
        $sql .= " AND dd.kategori = :kategori ";
        $params[':kategori'] = $kategori_filter;
    }
    if ($visibilitas_filter !== 'Semua') {
        $sql .= " AND dd.visibilitas = :visibilitas ";
        $params[':visibilitas'] = $visibilitas_filter;
    }
    $sql .= " ORDER BY dd.created_at DESC";

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $daftar_dokumen = $stmt->fetchAll();
} catch (PDOException $e) {
    $daftar_dokumen = [];
}

function badge_class_kategori(string $kategori): string
{
    switch ($kategori) {
        case 'Suket K3':
            return 'badge-success';
        case 'Sertifikat Ahli':
            return 'badge-info';
        case 'Legal Perusahaan':
            return 'badge-warning';
        case 'Kontrak Klien':
            return 'badge-secondary';
        case 'Laporan':
            return 'badge-danger';
        default:
            return 'badge-secondary';
    }
}

function badge_class_visibilitas(string $visibilitas): string
{
    switch ($visibilitas) {
        case 'Client':
            return 'badge-info';
        case 'Publik':
            return 'badge-warning';
        default:
            return 'badge-secondary';
    }
}

function icon_file_ext(string $path): string
{
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    switch ($ext) {
        case 'pdf':
            return 'bi-file-earmark-pdf-fill';
        case 'doc':
        case 'docx':
            return 'bi-file-earmark-word-fill';
        case 'xls':
        case 'xlsx':
            return 'bi-file-earmark-excel-fill';
        case 'jpg':
        case 'jpeg':
        case 'png':
            return 'bi-file-earmark-image-fill';
        default:
            return 'bi-file-earmark-fill';
    }
}

include "../includes/header.php";
include "../includes/sidebar.php";
include "../includes/topbar.php";
?>

<main class="main-content">

    <?php if ($flash): ?>
        <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?>-custom mb-3">
            <i class="bi <?= $flash['type'] === 'success' ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill' ?> fs-5"></i>
            <div><?= htmlspecialchars($flash['message']) ?></div>
        </div>
    <?php endif; ?>

    <!-- Ringkasan -->
    <div class="row g-4 mb-4">
        <div class="col-xl-3 col-md-6 col-12">
            <div class="stat-card">
                <div class="stat-card-info">
                    <span class="stat-card-title">Total Dokumen</span>
                    <span class="stat-card-value"><?= $total_dokumen ?></span>
                </div>
                <div class="stat-card-icon"><i class="bi bi-folder2-open"></i></div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6 col-12">
            <div class="stat-card">
                <div class="stat-card-info">
                    <span class="stat-card-title">Diunggah Bulan Ini</span>
                    <span class="stat-card-value"><?= $dokumen_bulan_ini ?></span>
                </div>
                <div class="stat-card-icon"><i class="bi bi-cloud-arrow-up-fill"></i></div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6 col-12">
            <div class="stat-card">
                <div class="stat-card-info">
                    <span class="stat-card-title">Terhubung ke Klien</span>
                    <span class="stat-card-value"><?= $terhubung_klien ?></span>
                </div>
                <div class="stat-card-icon success"><i class="bi bi-building-check"></i></div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6 col-12">
            <div class="stat-card">
                <div class="stat-card-info">
                    <span class="stat-card-title">Visibilitas Publik</span>
                    <span class="stat-card-value"><?= $visibilitas_publik ?></span>
                </div>
                <div class="stat-card-icon warning"><i class="bi bi-globe2"></i></div>
            </div>
        </div>
    </div>

    <!-- Arsip Digital -->
    <div class="card-box">
        <div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-3">
            <h5 class="mb-0 fw-bold">Arsip Dokumen Digital Perusahaan</h5>
            <button type="button" class="btn-primary-custom" onclick="new bootstrap.Modal(document.getElementById('modalUpload')).show()">
                <i class="bi bi-cloud-arrow-up-fill me-1"></i> Unggah Dokumen
            </button>
        </div>

        <form method="GET" class="row g-2 align-items-center mb-4">
            <div class="col-lg-5 col-md-12">
                <div class="search-box-container" style="max-width:100%;">
                    <i class="bi bi-search"></i>
                    <input type="text" name="q" class="search-box" style="width:100%;" placeholder="Cari nama dokumen atau perusahaan..." value="<?= htmlspecialchars($q) ?>">
                </div>
            </div>
            <div class="col-lg-3 col-md-6 col-8">
                <select class="select-custom" name="kategori" onchange="this.form.submit()">
                    <option value="Semua" <?= $kategori_filter === 'Semua' ? 'selected' : '' ?>>Semua Kategori</option>
                    <?php foreach (KATEGORI_DOKUMEN as $k): ?>
                        <option value="<?= htmlspecialchars($k) ?>" <?= $kategori_filter === $k ? 'selected' : '' ?>><?= htmlspecialchars($k) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-lg-3 col-md-6 col-8">
                <select class="select-custom" name="visibilitas" onchange="this.form.submit()">
                    <option value="Semua" <?= $visibilitas_filter === 'Semua' ? 'selected' : '' ?>>Semua Visibilitas</option>
                    <?php foreach (VISIBILITAS_DOKUMEN as $v): ?>
                        <option value="<?= htmlspecialchars($v) ?>" <?= $visibilitas_filter === $v ? 'selected' : '' ?>><?= htmlspecialchars($v) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-lg-1 col-md-12 col-4">
                <button type="submit" class="btn-secondary-custom w-100"><i class="bi bi-funnel"></i></button>
            </div>
        </form>

        <div class="table-responsive-custom">
            <table class="table-custom">
                <thead>
                    <tr>
                        <th>No</th>
                        <th>Dokumen</th>
                        <th>Kategori</th>
                        <th>Klien Terkait</th>
                        <th>Visibilitas</th>
                        <th>Diunggah Oleh</th>
                        <th>Tgl. Unggah</th>
                        <th style="text-align: center;">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($daftar_dokumen)): ?>
                        <tr>
                            <td colspan="8" class="text-center text-muted py-4">Belum ada dokumen yang cocok dengan filter ini.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($daftar_dokumen as $i => $d): ?>
                            <tr>
                                <td><?= $i + 1 ?></td>
                                <td>
                                    <div class="d-flex align-items-center gap-2">
                                        <i class="bi <?= icon_file_ext($d['file_path']) ?> fs-5" style="color: var(--primary);"></i>
                                        <div>
                                            <div class="fw-semibold"><?= htmlspecialchars($d['nama_dokumen']) ?></div>
                                            <div class="fs-7 text-muted"><?= htmlspecialchars($d['modul_sumber'] ?? '-') ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td><span class="<?= badge_class_kategori($d['kategori']) ?>"><?= htmlspecialchars($d['kategori']) ?></span></td>
                                <td>
                                    <?php if (!empty($d['nama_perusahaan'])): ?>
                                        <?= htmlspecialchars($d['nama_perusahaan']) ?>
                                        <div class="fs-7 text-muted"><?= htmlspecialchars($d['kode_klien']) ?></div>
                                    <?php else: ?>
                                        <span class="text-muted fs-7">-</span>
                                    <?php endif; ?>
                                </td>
                                <td><span class="<?= badge_class_visibilitas($d['visibilitas']) ?>"><?= htmlspecialchars($d['visibilitas']) ?></span></td>
                                <td><?= htmlspecialchars($d['nama_pengunggah'] ?? '-') ?></td>
                                <td><?= htmlspecialchars(date('d M Y', strtotime($d['created_at']))) ?></td>
                                <td style="text-align: center;">
                                    <div class="d-flex gap-2 justify-content-center">
                                        <button type="button" class="btn-secondary-custom" style="height:32px; padding:0 10px; font-size:0.8rem;" title="Lihat Dokumen"
                                            onclick='openPreviewModal(<?= json_encode([
                                                "nama_dokumen" => $d["nama_dokumen"],
                                                "file_path"    => $d["file_path"],
                                            ]) ?>)'>
                                            <i class="bi bi-eye-fill"></i>
                                        </button>
                                        <a href="../<?= htmlspecialchars($d['file_path']) ?>" target="_blank" download class="btn-secondary-custom" style="height:32px; padding:0 10px; font-size:0.8rem;" title="Unduh">
                                            <i class="bi bi-download"></i>
                                        </a>
                                        <button type="button" class="btn-secondary-custom" style="height:32px; padding:0 10px; font-size:0.8rem;" title="Edit"
                                            onclick='openEditModal(<?= json_encode([
                                                "id" => $d["id"],
                                                "nama_dokumen" => $d["nama_dokumen"],
                                                "kategori" => $d["kategori"],
                                                "klien_id" => $d["klien_id"],
                                                "visibilitas" => $d["visibilitas"],
                                                "file_path" => $d["file_path"],
                                            ]) ?>)'>
                                            <i class="bi bi-pencil-square"></i>
                                        </button>
                                        <button type="button" class="btn-secondary-custom" style="height:32px; padding:0 10px; font-size:0.8rem; color: var(--danger); border-color: var(--danger);" title="Hapus"
                                            onclick="openHapusModal(<?= (int) $d['id'] ?>, '<?= htmlspecialchars(addslashes($d['nama_dokumen'])) ?>')">
                                            <i class="bi bi-trash3"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>

<!-- ===== MODAL: Unggah Dokumen Baru ===== -->
<div class="modal fade modal-custom" id="modalUpload" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form action="digital.php<?= !empty($_GET) ? '?' . htmlspecialchars(http_build_query($_GET)) : '' ?>" method="POST" enctype="multipart/form-data">
                <input type="hidden" name="aksi" value="upload">
                <div class="modal-header">
                    <h5 class="modal-title">Unggah Dokumen ke Arsip Digital</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold fs-7 mb-2">Nama Dokumen *</label>
                        <input type="text" name="nama_dokumen" class="form-control-custom" placeholder="Contoh: Akta Pendirian Perusahaan" required>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold fs-7 mb-2">Kategori *</label>
                            <select class="select-custom" name="kategori" required>
                                <option value="">-- Pilih Kategori --</option>
                                <?php foreach (KATEGORI_DOKUMEN as $k): ?>
                                    <option value="<?= htmlspecialchars($k) ?>"><?= htmlspecialchars($k) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold fs-7 mb-2">Visibilitas *</label>
                            <select class="select-custom" name="visibilitas" required>
                                <?php foreach (VISIBILITAS_DOKUMEN as $v): ?>
                                    <option value="<?= htmlspecialchars($v) ?>" <?= $v === 'Internal' ? 'selected' : '' ?>><?= htmlspecialchars($v) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold fs-7 mb-2">Klien Terkait (opsional)</label>
                        <select class="select-custom" name="klien_id">
                            <option value="">-- Tidak Terhubung ke Klien --</option>
                            <?php foreach ($daftar_klien as $k): ?>
                                <option value="<?= (int) $k['id'] ?>"><?= htmlspecialchars($k['nama_perusahaan']) ?> (<?= htmlspecialchars($k['kode_klien']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-1">
                        <label class="form-label fw-semibold fs-7 mb-2">File Dokumen *</label>
                        <div class="upload-dropzone" id="uploadDropzoneNew">
                            <div class="upload-dropzone-icon"><i class="bi bi-cloud-arrow-up"></i></div>
                            <div>
                                <span class="fw-semibold" style="color: var(--primary);">Drag &amp; drop file di sini</span>
                                atau <span class="fw-semibold text-decoration-underline">Pilih File</span>
                            </div>
                            <span class="fs-7 text-muted">Format: PDF, Word, Excel, JPG, PNG (Max 10 MB)</span>
                            <input type="file" name="file_dokumen" id="fileUploadNew" class="d-none" accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png" required>
                            <div class="upload-dropzone-filelist" id="fileListNew"></div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-secondary-custom" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn-primary-custom"><i class="bi bi-cloud-arrow-up-fill me-1"></i> Unggah</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ===== MODAL: Edit Dokumen ===== -->
<div class="modal fade modal-custom" id="modalEdit" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form action="digital.php<?= !empty($_GET) ? '?' . htmlspecialchars(http_build_query($_GET)) : '' ?>" method="POST" enctype="multipart/form-data">
                <input type="hidden" name="aksi" value="edit">
                <input type="hidden" name="id" id="editId" value="">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Dokumen</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold fs-7 mb-2">Nama Dokumen *</label>
                        <input type="text" name="nama_dokumen" id="editNamaDokumen" class="form-control-custom" required>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold fs-7 mb-2">Kategori *</label>
                            <select class="select-custom" name="kategori" id="editKategori" required>
                                <?php foreach (KATEGORI_DOKUMEN as $k): ?>
                                    <option value="<?= htmlspecialchars($k) ?>"><?= htmlspecialchars($k) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold fs-7 mb-2">Visibilitas *</label>
                            <select class="select-custom" name="visibilitas" id="editVisibilitas" required>
                                <?php foreach (VISIBILITAS_DOKUMEN as $v): ?>
                                    <option value="<?= htmlspecialchars($v) ?>"><?= htmlspecialchars($v) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold fs-7 mb-2">Klien Terkait (opsional)</label>
                        <select class="select-custom" name="klien_id" id="editKlienId">
                            <option value="">-- Tidak Terhubung ke Klien --</option>
                            <?php foreach ($daftar_klien as $k): ?>
                                <option value="<?= (int) $k['id'] ?>"><?= htmlspecialchars($k['nama_perusahaan']) ?> (<?= htmlspecialchars($k['kode_klien']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-1">
                        <label class="form-label fw-semibold fs-7 mb-2">Ganti File (opsional)</label>
                        <div class="upload-dropzone" id="uploadDropzoneEdit">
                            <div class="upload-dropzone-icon"><i class="bi bi-cloud-arrow-up"></i></div>
                            <div>
                                <span class="fw-semibold" style="color: var(--primary);">Drag &amp; drop file di sini</span>
                                atau <span class="fw-semibold text-decoration-underline">Pilih File Baru</span>
                            </div>
                            <span class="fs-7 text-muted">Kosongkan jika tidak ingin mengganti file. Max 10 MB.</span>
                            <input type="file" name="file_dokumen" id="fileUploadEdit" class="d-none" accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png">
                            <div class="upload-dropzone-filelist" id="fileListEdit"></div>
                        </div>
                        <p class="fs-7 text-muted mt-2 mb-0">File saat ini: <a href="#" id="editFileLamaLink" target="_blank">Lihat file</a></p>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-secondary-custom" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn-primary-custom">Simpan Perubahan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ===== MODAL: Lihat Dokumen ===== -->
<div class="modal fade modal-custom" id="modalPreview" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="previewNamaDokumen">Lihat Dokumen</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-center" id="previewBody" style="min-height:200px;">
                <!-- konten preview di-render lewat JS -->
            </div>
            <div class="modal-footer">
                <a href="#" id="previewDownloadLink" target="_blank" download class="btn-secondary-custom">
                    <i class="bi bi-download me-1"></i> Unduh File
                </a>
                <button type="button" class="btn-primary-custom" data-bs-dismiss="modal">Tutup</button>
            </div>
        </div>
    </div>
</div>

<!-- ===== MODAL: Konfirmasi Hapus ===== -->
<div class="modal fade modal-custom" id="modalHapus" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form action="digital.php<?= !empty($_GET) ? '?' . htmlspecialchars(http_build_query($_GET)) : '' ?>" method="POST">
                <input type="hidden" name="aksi" value="hapus">
                <input type="hidden" name="id" id="hapusId" value="">
                <div class="modal-header">
                    <h5 class="modal-title">Hapus Dokumen</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-danger-custom mb-0">
                        <i class="bi bi-exclamation-triangle-fill fs-5"></i>
                        <div>
                            Yakin ingin menghapus dokumen <strong id="hapusNamaDokumen">-</strong> dari Arsip Digital?
                            File yang sudah dihapus tidak dapat dikembalikan.
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-secondary-custom" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn-primary-custom" style="background-color: var(--danger); border-color: var(--danger);">
                        <i class="bi bi-trash3 me-1"></i> Ya, Hapus
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function setupDropzone(dropzoneId, inputId, fileListId) {
    const dropzone = document.getElementById(dropzoneId);
    const fileInput = document.getElementById(inputId);
    const fileList = document.getElementById(fileListId);
    if (!dropzone || !fileInput) return;

    dropzone.addEventListener('click', function () { fileInput.click(); });

    ['dragenter', 'dragover'].forEach(function (evt) {
        dropzone.addEventListener(evt, function (e) {
            e.preventDefault(); e.stopPropagation();
            dropzone.classList.add('dragover');
        });
    });
    ['dragleave', 'drop'].forEach(function (evt) {
        dropzone.addEventListener(evt, function (e) {
            e.preventDefault(); e.stopPropagation();
            dropzone.classList.remove('dragover');
        });
    });
    dropzone.addEventListener('drop', function (e) {
        if (e.dataTransfer.files.length) {
            fileInput.files = e.dataTransfer.files;
            renderList();
        }
    });
    fileInput.addEventListener('change', renderList);

    function renderList() {
        if (!fileList) return;
        fileList.innerHTML = '';
        Array.from(fileInput.files).forEach(function (file) {
            const item = document.createElement('div');
            item.className = 'file-item';
            item.innerHTML = '<span><i class="bi bi-paperclip me-2"></i>' + file.name + '</span>' +
                '<span class="text-muted">' + (file.size / 1024).toFixed(0) + ' KB</span>';
            fileList.appendChild(item);
        });
    }
}
setupDropzone('uploadDropzoneNew', 'fileUploadNew', 'fileListNew');
setupDropzone('uploadDropzoneEdit', 'fileUploadEdit', 'fileListEdit');

function openEditModal(data) {
    document.getElementById('editId').value = data.id;
    document.getElementById('editNamaDokumen').value = data.nama_dokumen || '';
    document.getElementById('editKategori').value = data.kategori || '';
    document.getElementById('editVisibilitas').value = data.visibilitas || 'Internal';
    document.getElementById('editKlienId').value = data.klien_id || '';
    document.getElementById('editFileLamaLink').href = '../' + data.file_path;
    document.getElementById('fileListEdit').innerHTML = '';
    new bootstrap.Modal(document.getElementById('modalEdit')).show();
}

function openPreviewModal(data) {
    const filePath = '../' + data.file_path;
    const ext = (data.file_path.split('.').pop() || '').toLowerCase();
    const body = document.getElementById('previewBody');
    const imageExt = ['jpg', 'jpeg', 'png'];

    document.getElementById('previewNamaDokumen').textContent = data.nama_dokumen || 'Lihat Dokumen';
    document.getElementById('previewDownloadLink').href = filePath;
    body.innerHTML = '';

    if (imageExt.includes(ext)) {
        const img = document.createElement('img');
        img.src = filePath;
        img.style.maxWidth = '100%';
        img.style.maxHeight = '70vh';
        img.alt = data.nama_dokumen || 'Preview dokumen';
        img.onerror = function () {
            body.innerHTML =
                '<div class="alert alert-danger-custom mb-0 text-start">' +
                '<i class="bi bi-exclamation-triangle-fill fs-5"></i>' +
                '<div>File gambar ini tidak dapat ditampilkan. Kemungkinan file rusak, kosong, atau lokasi file di server tidak sesuai dengan data di database. Silakan cek langsung di server atau minta dokumen diunggah ulang.</div>' +
                '</div>';
        };
        body.appendChild(img);
    } else if (ext === 'pdf') {
        body.innerHTML = '<iframe src="' + filePath + '" style="width:100%; height:70vh; border:0;"></iframe>';
    } else {
        body.innerHTML =
            '<div class="alert alert-secondary mb-0 text-start">' +
            '<i class="bi bi-info-circle-fill fs-5"></i>' +
            '<div>Preview langsung belum didukung untuk format file ini (' + ext.toUpperCase() + '). Silakan gunakan tombol "Unduh File" di bawah untuk membukanya.</div>' +
            '</div>';
    }

    new bootstrap.Modal(document.getElementById('modalPreview')).show();
}

function openHapusModal(id, namaDokumen) {
    document.getElementById('hapusId').value = id;
    document.getElementById('hapusNamaDokumen').textContent = namaDokumen;
    new bootstrap.Modal(document.getElementById('modalHapus')).show();
}
</script>

<?php
include "../includes/footer.php";
?>