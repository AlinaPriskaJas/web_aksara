<?php
// ahliK3 /sertifikat_ahli.php
require_once "../config/koneksi.php";
require_once "../includes/drive_helper.php";

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

    if (isset($_POST['delete_id'])) {
        // ================= HANDLE HAPUS =================
        $delete_id = (int) $_POST['delete_id'];

        $cekStmt = $conn->prepare("SELECT * FROM Sertifikat_Ahli WHERE id = :id AND user_id = :uid");
        $cekStmt->execute(['id' => $delete_id, 'uid' => $current_user_id]);
        $existing = $cekStmt->fetch();

        if (!$existing) {
            $error_msg = "Anda tidak memiliki akses untuk menghapus sertifikat ini!";
        } else {
            try {
                $stmt = $conn->prepare("DELETE FROM Sertifikat_Ahli WHERE id = :id AND user_id = :uid");
                $stmt->execute(['id' => $delete_id, 'uid' => $current_user_id]);

                // Catatan: file_sertifikat sekarang berupa link Drive (bukan path lokal),
                // jadi @unlink ini otomatis jadi no-op aman untuk file yang sudah dipindah ke Drive.
                // File di Drive TIDAK ikut terhapus otomatis — hapus manual di Drive jika perlu.
                if (!empty($existing['file_sertifikat']) && !str_starts_with($existing['file_sertifikat'], 'http')) {
                    $filePath = "../" . $existing['file_sertifikat'];
                    if (is_file($filePath)) {
                        @unlink($filePath);
                    }
                }
                catatAudit($conn, 'Sertifikat Ahli', 'Hapus', "Menghapus sertifikat #{$delete_id} ({$existing['nomor_sertifikat']})", $existing, null);
                $success_msg = "Sertifikat berhasil dihapus!";
            } catch (PDOException $e) {
                $error_msg = "Gagal menghapus sertifikat: " . $e->getMessage();
            }
        }

    } elseif (isset($_POST['edit_id'])) {
        // ================= HANDLE EDIT =================
        $edit_id = (int) $_POST['edit_id'];
        $nama_lengkap = trim($_POST['nama_lengkap'] ?? '');
        $tingkat_ahli = trim($_POST['tingkat_ahli'] ?? '');
        $bidang_keahlian_arr = $_POST['bidang_keahlian'] ?? [];
        if (!is_array($bidang_keahlian_arr))
            $bidang_keahlian_arr = [$bidang_keahlian_arr];
        $bidang_keahlian_arr = array_values(array_filter(array_map('trim', $bidang_keahlian_arr)));
        $nomor_sertifikat = trim($_POST['nomor_sertifikat'] ?? '');
        $tanggal_terbit = $_POST['tanggal_terbit'] ?? '';
        $tanggal_kedaluwarsa = $_POST['tanggal_kedaluwarsa'] ?? '';

        $cekStmt = $conn->prepare("SELECT * FROM Sertifikat_Ahli WHERE id = :id AND user_id = :uid");
        $cekStmt->execute(['id' => $edit_id, 'uid' => $current_user_id]);
        $existing = $cekStmt->fetch();

        if (!$existing) {
            $error_msg = "Anda tidak memiliki akses untuk mengedit sertifikat ini!";
        } elseif (
            empty($nama_lengkap) || empty($tingkat_ahli) || empty($bidang_keahlian_arr) ||
            empty($nomor_sertifikat) || empty($tanggal_terbit) || empty($tanggal_kedaluwarsa)
        ) {
            $error_msg = "Semua field wajib diisi (Bidang Keahlian minimal pilih 1)!";
        } elseif (!in_array($tingkat_ahli, $tingkat_ahli_opsi, true)) {
            $error_msg = "Tingkat ahli tidak valid!";
        } elseif (array_diff($bidang_keahlian_arr, $bidang_keahlian_opsi)) {
            $error_msg = "Bidang keahlian tidak valid!";
        } else {
            $bidang_keahlian = implode(', ', $bidang_keahlian_arr);
            $file_sertifikat = $existing['file_sertifikat'];

            if (isset($_FILES['file_sertifikat']) && $_FILES['file_sertifikat']['error'] === UPLOAD_ERR_OK) {
                $file = $_FILES['file_sertifikat'];
                $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                if (in_array($ext, ['pdf', 'jpg', 'jpeg', 'png'])) {
                    $hasil_drive = arp_upload_ke_drive($file['tmp_name'], $file['name'], $file['type'], $current_user_id, 'Sertifikat_Ahli');
                    if ($hasil_drive && !empty($hasil_drive['link'])) {
                        $file_sertifikat = $hasil_drive['link'];
                    } else {
                        $error_msg = "Gagal mengunggah file sertifikat ke Drive.";
                    }
                } else {
                    $error_msg = "Format file tidak didukung. Gunakan PDF, JPG, atau PNG.";
                }
            }

            if (empty($error_msg)) {
                try {
                    $stmt = $conn->prepare("
                        UPDATE Sertifikat_Ahli SET
                            nama_lengkap = :nama_lengkap,
                            tingkat_ahli = :tingkat_ahli,
                            bidang_keahlian = :bidang_keahlian,
                            nomor_sertifikat = :nomor,
                            tanggal_terbit = :terbit,
                            tanggal_kedaluwarsa = :exp,
                            file_sertifikat = :file
                        WHERE id = :id AND user_id = :uid
                    ");
                    $stmt->execute([
                        'nama_lengkap' => $nama_lengkap,
                        'tingkat_ahli' => $tingkat_ahli,
                        'bidang_keahlian' => $bidang_keahlian,
                        'nomor' => $nomor_sertifikat,
                        'terbit' => $tanggal_terbit,
                        'exp' => $tanggal_kedaluwarsa,
                        'file' => $file_sertifikat,
                        'id' => $edit_id,
                        'uid' => $current_user_id
                    ]);
                    catatAudit(
                        $conn,
                        'Sertifikat Ahli',
                        'Ubah',
                        "Mengubah sertifikat #{$edit_id} ({$nomor_sertifikat})",
                        ['nomor_sertifikat' => $existing['nomor_sertifikat'], 'tingkat_ahli' => $existing['tingkat_ahli']],
                        ['nomor_sertifikat' => $nomor_sertifikat, 'tingkat_ahli' => $tingkat_ahli, 'bidang_keahlian' => $bidang_keahlian]
                    );
                    $success_msg = "Sertifikat berhasil diperbarui!";
                } catch (PDOException $e) {
                    if ($e->getCode() == 23000) {
                        $error_msg = "Nomor sertifikat sudah terdaftar di sistem!";
                    } else {
                        $error_msg = "Gagal memperbarui sertifikat: " . $e->getMessage();
                    }
                }
            }
        }

    } else {
        // ================= HANDLE TAMBAH =================
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
            if (isset($_FILES['file_sertifikat']) && $_FILES['file_sertifikat']['error'] === UPLOAD_ERR_OK) {
                $file = $_FILES['file_sertifikat'];
                $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                if (in_array($ext, ['pdf', 'jpg', 'jpeg', 'png'])) {
                    $hasil_drive = arp_upload_ke_drive($file['tmp_name'], $file['name'], $file['type'], $current_user_id, 'Sertifikat_Ahli');
                    if ($hasil_drive && !empty($hasil_drive['link'])) {
                        $file_sertifikat = $hasil_drive['link'];
                    } else {
                        $error_msg = "Gagal mengunggah file sertifikat ke Drive.";
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
                    catatAudit(
                        $conn,
                        'Sertifikat Ahli',
                        'Tambah',
                        "Menambahkan sertifikat {$nomor_sertifikat} ({$tingkat_ahli})",
                        null,
                        ['nomor_sertifikat' => $nomor_sertifikat, 'tingkat_ahli' => $tingkat_ahli, 'bidang_keahlian' => $bidang_keahlian]
                    );
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
}


// Fetch semua sertifikat (bukan hanya milik user login)
try {
    $stmt = $conn->query("SELECT * FROM v_sertifikat_ahli_status ORDER BY tanggal_kedaluwarsa ASC");
    $certs = $stmt->fetchAll();
} catch (PDOException $e) {
    // Fallback jika view belum ada
    try {
        $stmt2 = $conn->query("SELECT * FROM Sertifikat_Ahli ORDER BY tanggal_kedaluwarsa ASC");
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
            <h5 class="table-toolbar-title fw-bold">Daftar Sertifikat Ahli</h5>
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
                        <th class="col-aksi" style="text-align:center;">Aksi</th>
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
                                        <?php $hrefSert = str_starts_with($c['file_sertifikat'], 'http') ? $c['file_sertifikat'] : '../' . $c['file_sertifikat']; ?>
                                        <a href="<?= htmlspecialchars($hrefSert) ?>" target="_blank"
                                            class="btn btn-outline-secondary btn-sm py-1"
                                            style="font-size:0.75rem; border-radius: 8px;">
                                            <i class="bi bi-file-earmark-pdf"></i> Lihat
                                        </a>
                                    <?php else: ?>
                                        <span class="text-muted">Tidak ada lampiran</span>
                                    <?php endif; ?>
                                </td>
                                <td class="col-aksi text-center">
                                    <?php if ((int) $c['user_id'] === (int) $current_user_id): ?>
                                        <div class="table-actions">

                                            <!-- Edit -->
                                            <button type="button" class="btn btn-outline-primary btn-sm py-1"
                                                style="font-size:0.75rem;" title="Edit Sertifikat" onclick='bukaModalEdit(<?= json_encode([
                                                    "id" => $c["id"],
                                                    "nama_lengkap" => $c["nama_lengkap"],
                                                    "tingkat_ahli" => $c["tingkat_ahli"],
                                                    "bidang_keahlian" => array_map("trim", explode(",", $c["bidang_keahlian"])),
                                                    "nomor_sertifikat" => $c["nomor_sertifikat"],
                                                    "tanggal_terbit" => $c["tanggal_terbit"],
                                                    "tanggal_kedaluwarsa" => $c["tanggal_kedaluwarsa"]
                                                ]) ?>)'>
                                                <i class="bi bi-pencil-square"></i>
                                            </button>

                                            <!-- Hapus -->
                                            <form method="POST" action="sertifikat_ahli.php" class="d-inline"
                                                onsubmit="return confirm('Yakin ingin menghapus sertifikat <?= htmlspecialchars(addslashes($c['nomor_sertifikat'])) ?>? Tindakan ini tidak dapat dibatalkan.');">

                                                <input type="hidden" name="delete_id" value="<?= (int) $c['id'] ?>">

                                                <button type="submit" class="btn-danger-custom"
                                                    style="height:28px; padding:0 8px; font-size:0.75rem;" title="Hapus Sertifikat">
                                                    <i class="bi bi-trash-fill"></i>
                                                </button>
                                            </form>

                                        </div>

                                    <?php else: ?>
                                        <span class="text-secondary">-</span>
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
                    <div class="upload-dropzone" id="dzSertifikatBaru">
                        <div class="upload-dropzone-icon"><i class="bi bi-cloud-arrow-up"></i></div>
                        <div>
                            <span class="fw-semibold" style="color: var(--primary);">Tarik &amp; lepas file di sini</span>
                            atau <span class="fw-semibold text-decoration-underline">Pilih File</span>
                        </div>
                        <span class="fs-7 text-muted">Format: PDF, JPG, PNG</span>
                        <input type="file" name="file_sertifikat" id="inputSertifikatBaru" class="d-none" accept=".pdf,.jpg,.jpeg,.png">
                        <div class="upload-dropzone-filelist" id="fileListSertifikatBaru"></div>
                    </div>
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

<!-- ===== MODAL: Edit Sertifikat ===== -->
<div id="modalEditSertifikat" class="arp-modal-overlay" onclick="closeModalOutside(event, 'modalEditSertifikat')">
    <div class="arp-modal-box">
        <div class="arp-modal-header">
            <div>
                <h6 class="fw-bold mb-0">Edit Sertifikat Kompetensi</h6>
                <small class="text-muted">Perbarui data sertifikat milik Anda.</small>
            </div>
            <button class="arp-modal-close" onclick="closeModal('modalEditSertifikat')">&times;</button>
        </div>
        <div class="arp-modal-body">
            <form method="POST" action="sertifikat_ahli.php" enctype="multipart/form-data" id="formEditSertifikat">
                <input type="hidden" name="edit_id" id="edit_id">
                <div class="mb-3">
                    <label class="form-label fw-semibold fs-7 mb-2">Nama Lengkap *</label>
                    <input type="text" name="nama_lengkap" id="edit_nama_lengkap" class="form-control-custom" required>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold fs-7 mb-2">Tingkat Ahli *</label>
                    <select name="tingkat_ahli" id="edit_tingkat_ahli" class="select-custom" required>
                        <option value="">-- Pilih Tingkat Ahli --</option>
                        <?php foreach ($tingkat_ahli_opsi as $opt): ?>
                            <option value="<?= htmlspecialchars($opt) ?>"><?= htmlspecialchars($opt) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold fs-7 mb-2">Bidang Keahlian *</label>
                    <div class="d-flex flex-wrap gap-2" id="edit_bidang_keahlian_wrapper">
                        <?php foreach ($bidang_keahlian_opsi as $opt): ?>
                            <label class="form-control-custom d-flex align-items-center gap-2 mb-0"
                                style="width:auto; cursor:pointer;">
                                <input type="checkbox" class="edit-bidang-checkbox" name="bidang_keahlian[]"
                                    value="<?= htmlspecialchars($opt) ?>">
                                <?= htmlspecialchars($opt) ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold fs-7 mb-2">Nomor Sertifikat *</label>
                    <input type="text" name="nomor_sertifikat" id="edit_nomor_sertifikat" class="form-control-custom"
                        required>
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <label class="form-label fw-semibold fs-7 mb-2">Tanggal Terbit *</label>
                        <input type="date" name="tanggal_terbit" id="edit_tanggal_terbit" class="form-control-custom"
                            required>
                    </div>
                    <div class="col-6">
                        <label class="form-label fw-semibold fs-7 mb-2">Tanggal Kedaluwarsa *</label>
                        <input type="date" name="tanggal_kedaluwarsa" id="edit_tanggal_kedaluwarsa"
                            class="form-control-custom" required>
                    </div>
                </div>
                <div class="mb-4">
                    <label class="form-label fw-semibold fs-7 mb-2">Ganti File Sertifikat (opsional)</label>
                    <div class="upload-dropzone" id="dzSertifikatGanti">
                        <div class="upload-dropzone-icon"><i class="bi bi-cloud-arrow-up"></i></div>
                        <div>
                            <span class="fw-semibold" style="color: var(--primary);">Tarik &amp; lepas file di sini</span>
                            atau <span class="fw-semibold text-decoration-underline">Pilih File</span>
                        </div>
                        <span class="fs-7 text-muted">Kosongkan jika tidak ingin mengganti file</span>
                        <input type="file" name="file_sertifikat" id="inputSertifikatGanti" class="d-none" accept=".pdf,.jpg,.jpeg,.png">
                        <div class="upload-dropzone-filelist" id="fileListSertifikatGanti"></div>
                    </div>
                </div>
                <div class="d-flex gap-2">
                    <button type="button" class="btn-secondary-custom flex-grow-1"
                        onclick="closeModal('modalEditSertifikat')">Batal</button>
                    <button type="submit" class="btn-primary-custom flex-grow-1">
                        <i class="bi bi-save me-1"></i> Simpan Perubahan
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    function bukaModalEdit(data) {
        document.getElementById('edit_id').value = data.id;
        document.getElementById('edit_nama_lengkap').value = data.nama_lengkap;
        document.getElementById('edit_tingkat_ahli').value = data.tingkat_ahli;
        document.getElementById('edit_nomor_sertifikat').value = data.nomor_sertifikat;
        document.getElementById('edit_tanggal_terbit').value = data.tanggal_terbit;
        document.getElementById('edit_tanggal_kedaluwarsa').value = data.tanggal_kedaluwarsa;

        document.querySelectorAll('.edit-bidang-checkbox').forEach(cb => {
            cb.checked = data.bidang_keahlian.includes(cb.value);
        });

        openModal('modalEditSertifikat');
    }
</script>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        initTablePagination('tabelSertifikatAhli', 10);
    });
</script>

<?php if ($error_msg): ?>
    <script>document.addEventListener('DOMContentLoaded', () => openModal('modalSertifikat'));</script>
<?php endif; ?>

<?php include "../includes/footer.php"; ?>