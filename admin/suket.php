<?php
// admin/suket.php
$page_title = "Surat Keterangan K3";
require_once "../config/koneksi.php";
require_once "../includes/drive_helper.php";
require_once "../includes/dokumen_helper.php";
include "../includes/header.php";
include "../includes/sidebar.php";
include "../includes/topbar.php";

// Authorization Check
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../login.php");
    exit;
}

$current_user_id = (int) $_SESSION['user_id'];
$success_msg = "";
$error_msg = "";

// Ekstensi file suket yang diizinkan untuk diunggah
$EKSTENSI_SUKET_DIIZINKAN = ['pdf', 'jpg', 'jpeg', 'png'];

// Handle Upload / Edit Suket (upload dokumen Suket K3 yang SUDAH JADI, bukan generate baru)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'save') {
        $id             = isset($_POST['id']) && $_POST['id'] !== '' ? (int) $_POST['id'] : null;
        $klien_id       = $_POST['klien_id'] ?? '';
        $objek_id       = $_POST['objek_id'] ?: null; // opsional -- suket boleh diunggah tanpa unit objek spesifik
        $ahli_k3_id     = $_POST['ahli_k3_id'] ?? '';
        $nomor_suket    = trim($_POST['nomor_suket'] ?? '');
        $nomor_laporan  = trim($_POST['nomor_laporan'] ?? '');
        $jenis_pemeriksaan   = $_POST['jenis_pemeriksaan'] ?? 'Pemeriksaan Berkala';
        $tanggal_pemeriksaan = $_POST['tanggal_pemeriksaan'] ?: null;
        $tanggal_expiry      = $_POST['tanggal_expiry'] ?: null;

        // Ambil data existing dulu kalau ini mode edit (perlu untuk audit log & file lama)
        $existing = null;
        if ($id) {
            $cek = $conn->prepare("SELECT * FROM Suket_K3 WHERE id = :id");
            $cek->execute(['id' => $id]);
            $existing = $cek->fetch();
        }

        if (
            empty($klien_id) || empty($ahli_k3_id) ||
            empty($nomor_suket) || empty($nomor_laporan) || empty($tanggal_pemeriksaan)
        ) {
            $error_msg = "Kolom Nomor Suket, Nomor Laporan, Perusahaan Klien, Ahli K3, dan Tanggal Pemeriksaan wajib diisi!";
        } elseif (!$id && (!isset($_FILES['file_suket']) || $_FILES['file_suket']['error'] === UPLOAD_ERR_NO_FILE)) {
            $error_msg = "File Suket K3 (hasil cetak/PDF yang sudah jadi) wajib diunggah!";
        } else {
            $file_sertifikat_pdf = $existing['file_sertifikat_pdf'] ?? null;
            $drive_file_id        = null;

            // Proses upload file (opsional saat edit, wajib saat tambah baru)
            if (isset($_FILES['file_suket']) && $_FILES['file_suket']['error'] === UPLOAD_ERR_OK) {
                $file = $_FILES['file_suket'];
                $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

                if (!in_array($ext, $EKSTENSI_SUKET_DIIZINKAN, true)) {
                    $error_msg = "Format file tidak didukung. Gunakan PDF, JPG, atau PNG.";
                } else {
                    $hasil_drive = arp_upload_ke_drive($file['tmp_name'], $file['name'], $file['type'], 0, 'Suket_K3');
                    if ($hasil_drive && !empty($hasil_drive['link'])) {
                        $file_sertifikat_pdf = $hasil_drive['link'];
                        $drive_file_id = $hasil_drive['file_id'] ?? null;
                    } else {
                        $error_msg = "Gagal mengunggah file Suket ke Drive: " . arp_drive_last_error();
                    }
                }
            } elseif (isset($_FILES['file_suket']) && $_FILES['file_suket']['error'] !== UPLOAD_ERR_NO_FILE) {
                $error_msg = "Gagal mengunggah file Suket (kode error: " . $_FILES['file_suket']['error'] . ").";
            }

            if (empty($error_msg)) {
                try {
                    if ($id) {
                        // ================= UPDATE (edit data / ganti file) =================
                        $stmt = $conn->prepare("
                            UPDATE Suket_K3 SET
                                klien_id = :klien_id,
                                objek_id = :objek_id,
                                ahli_k3_id = :ahli_k3_id,
                                nomor_suket = :nomor_suket,
                                nomor_laporan = :nomor_laporan,
                                jenis_pemeriksaan = :jenis_pemeriksaan,
                                tanggal_pemeriksaan = :tanggal_pemeriksaan,
                                tanggal_expiry = :tanggal_expiry,
                                file_sertifikat_pdf = :file_sertifikat_pdf
                            WHERE id = :id
                        ");
                        $stmt->execute([
                            'klien_id' => $klien_id,
                            'objek_id' => $objek_id,
                            'ahli_k3_id' => $ahli_k3_id,
                            'nomor_suket' => $nomor_suket,
                            'nomor_laporan' => $nomor_laporan,
                            'jenis_pemeriksaan' => $jenis_pemeriksaan,
                            'tanggal_pemeriksaan' => $tanggal_pemeriksaan,
                            'tanggal_expiry' => $tanggal_expiry,
                            'file_sertifikat_pdf' => $file_sertifikat_pdf,
                            'id' => $id
                        ]);

                        catatAudit(
                            $conn,
                            'Suket K3',
                            'Ubah',
                            "Memperbarui Suket K3 #{$id} ({$nomor_suket})",
                            $existing,
                            ['nomor_suket' => $nomor_suket, 'nomor_laporan' => $nomor_laporan]
                        );

                        $new_suket_id = $id;
                        $success_msg = "Suket K3 berhasil diperbarui!";
                    } else {
                        // ================= INSERT (upload suket baru yang sudah jadi) =================
                        $stmt = $conn->prepare("
                            INSERT INTO Suket_K3
                                (klien_id, objek_id, ahli_k3_id, nomor_suket, nomor_laporan, jenis_pemeriksaan, tanggal_pemeriksaan, tanggal_expiry, file_sertifikat_pdf)
                            VALUES
                                (:klien_id, :objek_id, :ahli_k3_id, :nomor_suket, :nomor_laporan, :jenis_pemeriksaan, :tanggal_pemeriksaan, :tanggal_expiry, :file_sertifikat_pdf)
                        ");
                        $stmt->execute([
                            'klien_id' => $klien_id,
                            'objek_id' => $objek_id,
                            'ahli_k3_id' => $ahli_k3_id,
                            'nomor_suket' => $nomor_suket,
                            'nomor_laporan' => $nomor_laporan,
                            'jenis_pemeriksaan' => $jenis_pemeriksaan,
                            'tanggal_pemeriksaan' => $tanggal_pemeriksaan,
                            'tanggal_expiry' => $tanggal_expiry,
                            'file_sertifikat_pdf' => $file_sertifikat_pdf
                        ]);

                        $new_suket_id = (int) $conn->lastInsertId();

                        catatAudit(
                            $conn,
                            'Suket K3',
                            'Tambah',
                            "Mengunggah Suket K3 baru #{$new_suket_id} ({$nomor_suket})"
                        );

                        $success_msg = "Suket K3 berhasil diunggah!";
                    }

                    // Arsipkan/perbarui salinannya di Dokumen Digital (drive arsip Suket K3)
                    if (!empty($file_sertifikat_pdf)) {
                        $namaKlienStmt = $conn->prepare("SELECT nama_perusahaan FROM Data_Klien WHERE id = :id");
                        $namaKlienStmt->execute(['id' => $klien_id]);
                        $namaKlien = $namaKlienStmt->fetchColumn() ?: '';

                        arp_arsipkan_dokumen($conn, [
                            'nama_dokumen'  => trim($nomor_suket . ' - ' . $namaKlien),
                            'kategori'      => 'Suket K3',
                            'file_path'     => $file_sertifikat_pdf,
                            'drive_file_id' => $drive_file_id,
                            'drive_link'    => $file_sertifikat_pdf,
                            'modul_sumber'  => 'Suket K3',
                            'ref_id'        => $new_suket_id,
                            'klien_id'      => $klien_id,
                            'visibilitas'   => 'Client',
                            'diupload_oleh' => $current_user_id,
                        ]);
                    }
                } catch (PDOException $e) {
                    if ($e->getCode() == 23000) {
                        $error_msg = "Nomor Suket atau Nomor Laporan sudah terdaftar di sistem!";
                    } else {
                        $error_msg = "Gagal memproses Suket: " . $e->getMessage();
                    }
                }
            }
        }
    }
}

$highlight_id = isset($_GET['highlight']) ? (int) $_GET['highlight'] : null;

// Fetch lists untuk form (autocomplete)
$klien_list = $conn->query("SELECT id, nama_perusahaan, alamat FROM Data_Klien ORDER BY nama_perusahaan ASC")->fetchAll();

$objek_list = $conn->query("
    SELECT o.id, o.nama_unit, o.serial_number, dk.nama_perusahaan
    FROM Objek_K3 o
    JOIN Data_Klien dk ON o.id_client = dk.id
    ORDER BY dk.nama_perusahaan ASC
")->fetchAll();

$ahli_list = $conn->query("SELECT id, nama_lengkap, tingkat_ahli, bidang_keahlian, nomor_sertifikat FROM Sertifikat_Ahli ORDER BY nama_lengkap ASC")->fetchAll();

// Fetch Suket listings
$sukets = $conn->query("
    SELECT s.*, dk.nama_perusahaan, dk.alamat AS alamat_klien, o.nama_unit,
           sa.nama_lengkap AS nama_ahli, sa.bidang_keahlian AS bidang_ahli, sa.nomor_sertifikat AS no_skp
    FROM Suket_K3 s
    JOIN Data_Klien dk ON s.klien_id = dk.id
    LEFT JOIN Objek_K3 o ON s.objek_id = o.id
    JOIN Sertifikat_Ahli sa ON s.ahli_k3_id = sa.id
    ORDER BY s.created_at DESC
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

    <div class="row g-4">
        <div class="col-12">
            <div class="card-box">
                <div class="table-toolbar">
                    <h5 class="table-toolbar-title fw-bold">Arsip Surat Keterangan K3 (Suket)</h5>
                    <div class="table-toolbar-actions">
                        <div class="search-box-container">
                            <i class="bi bi-search"></i>
                            <input type="text" class="search-box" placeholder="Cari suket..."
                                data-table-search="tabelSuket" onkeyup="handleTableSearch('tabelSuket')">
                        </div>
                        <button class="btn-primary-custom" data-bs-toggle="modal" data-bs-target="#suketModal"
                            onclick="resetForm()">
                            <i class="bi bi-upload"></i> Upload Suket K3
                        </button>
                    </div>
                </div>

                <div class="table-responsive-custom">
                    <table class="table-custom" id="tabelSuket">
                        <thead>
                            <tr>
                                <th>No Suket</th>
                                <th>No Laporan</th>
                                <th>Nama Klien</th>
                                <th>Unit Objek</th>
                                <th>Ahli K3 / No SKP</th>
                                <th>Tgl Pemeriksaan</th>
                                <th>Tgl Expiry</th>
                                <th style="text-align: center;">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($sukets) === 0): ?>
                                <tr>
                                    <td colspan="8" class="text-center py-3 text-muted">Belum ada Suket K3 yang diunggah.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($sukets as $s): ?>
                                    <?php
                                    $rowClass = ($highlight_id && (int) $s['id'] === $highlight_id) ? 'table-warning' : '';
                                    $fileLink = $s['file_sertifikat_pdf'] ?? '';
                                    $fileHref = $fileLink ? (str_starts_with($fileLink, 'http') ? $fileLink : '../' . $fileLink) : '';
                                    ?>
                                    <tr id="suket-row-<?= (int) $s['id'] ?>" class="<?= $rowClass ?>">
                                        <td><strong><?= htmlspecialchars($s['nomor_suket'] ?: '-') ?></strong></td>
                                        <td><?= htmlspecialchars($s['nomor_laporan']) ?></td>
                                        <td><?= htmlspecialchars($s['nama_perusahaan']) ?></td>
                                        <td><?= htmlspecialchars($s['nama_unit'] ?: '-') ?></td>
                                        <td>
                                            <?= htmlspecialchars($s['nama_ahli']) ?>
                                            <small class="d-block text-muted"><?= htmlspecialchars($s['bidang_ahli']) ?> &middot; SKP:
                                                <?= htmlspecialchars($s['no_skp'] ?: '-') ?></small>
                                        </td>
                                        <td><?= $s['tanggal_pemeriksaan'] ? date('d-m-Y', strtotime($s['tanggal_pemeriksaan'])) : '-' ?>
                                        </td>
                                        <td><?= $s['tanggal_expiry'] ? date('d-m-Y', strtotime($s['tanggal_expiry'])) : '-' ?>
                                        </td>
                                        <td style="text-align: center; white-space: nowrap;">
                                            <?php if ($fileHref): ?>
                                                <a href="<?= htmlspecialchars($fileHref) ?>" target="_blank" class="btn-secondary-custom"
                                                    style="height:32px; padding: 0 10px; font-size:0.8rem;" title="Lihat File">
                                                    <i class="bi bi-file-earmark-pdf"></i>
                                                </a>
                                            <?php endif; ?>
                                            <button class="btn-primary-custom"
                                                style="height:32px; padding: 0 12px; font-size:0.8rem;" data-bs-toggle="modal"
                                                data-bs-target="#suketModal" onclick='editSuket(<?= json_encode($s) ?>)'>
                                                Edit
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div class="pagination-custom" id="pagination-tabelSuket"></div>
            </div>
        </div>
    </div>
</main>

<!-- Modal Form -->
<div class="modal fade modal-custom" id="suketModal" tabindex="-1" aria-labelledby="suketModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <form method="POST" action="suket.php" enctype="multipart/form-data" id="suketForm">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" id="form-id">

                <div class="modal-header">
                    <h5 class="modal-title" id="suketModalLabel">Upload Suket K3</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted fs-7 mb-3">
                        Unggah dokumen Suket K3 yang <strong>sudah selesai dibuat/dicetak</strong>. File akan otomatis
                        tersimpan ke Drive &amp; muncul di arsip Dokumen Digital kategori "Suket K3".
                    </p>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold fs-7 mb-1">Nomor Suket *</label>
                            <input type="text" name="nomor_suket" id="form-nomor-suket" class="form-control-custom"
                                placeholder="Contoh: SK3/ARP-01/2026" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold fs-7 mb-1">Nomor Laporan *</label>
                            <input type="text" name="nomor_laporan" id="form-nomor-laporan" class="form-control-custom"
                                placeholder="Nomor laporan hasil pemeriksaan" required>
                        </div>

                        <!-- Perusahaan Klien (autocomplete) -->
                        <div class="col-md-6 autocomplete-wrapper">
                            <label class="form-label fw-semibold fs-7 mb-1">Perusahaan Klien *</label>
                            <input type="text" id="form-klien-search" class="form-control-custom"
                                placeholder="Ketik nama perusahaan..." autocomplete="off">
                            <input type="hidden" name="klien_id" id="form-klien-id">
                            <div class="autocomplete-list" id="form-klien-list"></div>
                            <small class="d-block text-muted mt-1" id="form-klien-alamat">Alamat: -</small>
                        </div>

                        <!-- Objek K3 Unit (autocomplete, opsional) -->
                        <div class="col-md-6 autocomplete-wrapper">
                            <label class="form-label fw-semibold fs-7 mb-1">Objek K3 Unit <span class="text-muted fw-normal">(opsional)</span></label>
                            <input type="text" id="form-objek-search" class="form-control-custom"
                                placeholder="Ketik nama unit / SN / klien... (boleh dikosongkan)" autocomplete="off">
                            <input type="hidden" name="objek_id" id="form-objek-id">
                            <div class="autocomplete-list" id="form-objek-list"></div>
                        </div>

                        <!-- Ahli K3 Pelaksana (autocomplete) -->
                        <div class="col-md-6 autocomplete-wrapper">
                            <label class="form-label fw-semibold fs-7 mb-1">Ahli K3 Pelaksana *</label>
                            <input type="text" id="form-ahli-search" class="form-control-custom"
                                placeholder="Ketik nama ahli K3..." autocomplete="off">
                            <input type="hidden" name="ahli_k3_id" id="form-ahli-id">
                            <div class="autocomplete-list" id="form-ahli-list"></div>
                            <small class="d-block text-muted mt-1" id="form-ahli-info">Bidang: - &middot; No SKP: -</small>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold fs-7 mb-1">Jenis Pemeriksaan</label>
                            <select name="jenis_pemeriksaan" id="form-jenis-pemeriksaan" class="select-custom">
                                <option value="Pemeriksaan Baru">Pemeriksaan Baru</option>
                                <option value="Pemeriksaan Berkala">Pemeriksaan Berkala</option>
                                <option value="Pemeriksaan Ulang">Pemeriksaan Ulang</option>
                                <option value="Pemeriksaan Khusus">Pemeriksaan Khusus</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold fs-7 mb-1">Tanggal Pemeriksaan *</label>
                            <input type="date" name="tanggal_pemeriksaan" id="form-tgl-pemeriksaan"
                                class="form-control-custom" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold fs-7 mb-1">Tanggal Kedaluwarsa</label>
                            <input type="date" name="tanggal_expiry" id="form-tgl-expiry" class="form-control-custom">
                        </div>

                        <div class="col-12">
                            <label class="form-label fw-semibold fs-7 mb-1">File Suket K3 (PDF / JPG / PNG) <span
                                    id="form-file-required-mark">*</span></label>
                            <input type="file" name="file_suket" id="form-file-suket" class="form-control-custom"
                                accept=".pdf,.jpg,.jpeg,.png">
                            <small class="d-block text-muted mt-1" id="form-file-existing"></small>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-secondary-custom" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn-primary-custom">Simpan Suket</button>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
    .autocomplete-wrapper {
        position: relative;
    }

    .autocomplete-list {
        display: none;
        position: absolute;
        top: 100%;
        left: 0;
        right: 0;
        z-index: 2000;
        max-height: 220px;
        overflow-y: auto;
        background: #fff;
        border: 1px solid #d9dde3;
        border-radius: 8px;
        box-shadow: 0 8px 20px rgba(0, 0, 0, 0.12);
        margin-top: 4px;
    }

    .autocomplete-list.show {
        display: block;
    }

    .autocomplete-item {
        padding: 8px 12px;
        font-size: 0.85rem;
        cursor: pointer;
        border-bottom: 1px solid #f1f2f4;
    }

    .autocomplete-item:last-child {
        border-bottom: none;
    }

    .autocomplete-item:hover,
    .autocomplete-item.active {
        background: #f0f4ff;
    }

    .autocomplete-item small {
        display: block;
        color: #8a8f98;
        font-size: 0.75rem;
    }

    .autocomplete-empty {
        padding: 8px 12px;
        font-size: 0.8rem;
        color: #9aa0a8;
    }
</style>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        initTablePagination('tabelSuket', 10);
    });

    // ===== Data untuk autocomplete =====
    const klienDataAll = <?= json_encode($klien_list) ?>;
    const objekDataAll = <?= json_encode($objek_list) ?>;
    const ahliDataAll = <?= json_encode($ahli_list) ?>;

    /**
     * Membuat komponen autocomplete generik.
     */
    function setupAutocomplete({ searchId, hiddenId, listId, data, matchFn, labelFn, subLabelFn, onSelect }) {
        const searchEl = document.getElementById(searchId);
        const hiddenEl = document.getElementById(hiddenId);
        const listEl = document.getElementById(listId);

        function renderList(query) {
            const q = (query || '').trim().toLowerCase();
            const filtered = data.filter(item => matchFn(item, q));

            listEl.innerHTML = '';

            if (filtered.length === 0) {
                const empty = document.createElement('div');
                empty.className = 'autocomplete-empty';
                empty.textContent = 'Tidak ditemukan';
                listEl.appendChild(empty);
            } else {
                filtered.slice(0, 50).forEach(item => {
                    const row = document.createElement('div');
                    row.className = 'autocomplete-item';
                    const sub = subLabelFn ? subLabelFn(item) : '';
                    row.innerHTML = labelFn(item) + (sub ? `<small>${sub}</small>` : '');
                    row.addEventListener('mousedown', function (e) {
                        e.preventDefault();
                        searchEl.value = labelFn(item).replace(/<[^>]*>/g, '');
                        hiddenEl.value = item.id;
                        listEl.classList.remove('show');
                        if (onSelect) onSelect(item);
                    });
                    listEl.appendChild(row);
                });
            }

            listEl.classList.add('show');
        }

        searchEl.addEventListener('focus', function () {
            renderList(searchEl.value);
        });

        searchEl.addEventListener('input', function () {
            hiddenEl.value = '';
            renderList(searchEl.value);
        });

        searchEl.addEventListener('blur', function () {
            setTimeout(() => listEl.classList.remove('show'), 150);
        });

        searchEl._setValue = function (text, id) {
            searchEl.value = text || '';
            hiddenEl.value = id || '';
        };
    }

    // --- Klien ---
    setupAutocomplete({
        searchId: 'form-klien-search',
        hiddenId: 'form-klien-id',
        listId: 'form-klien-list',
        data: klienDataAll,
        matchFn: (item, q) => !q || item.nama_perusahaan.toLowerCase().includes(q),
        labelFn: (item) => item.nama_perusahaan,
        subLabelFn: (item) => item.alamat || '',
        onSelect: (item) => {
            document.getElementById('form-klien-alamat').textContent = 'Alamat: ' + (item.alamat || '-');
        }
    });

    // --- Objek K3 ---
    setupAutocomplete({
        searchId: 'form-objek-search',
        hiddenId: 'form-objek-id',
        listId: 'form-objek-list',
        data: objekDataAll,
        matchFn: (item, q) => !q ||
            item.nama_unit.toLowerCase().includes(q) ||
            item.nama_perusahaan.toLowerCase().includes(q) ||
            (item.serial_number || '').toLowerCase().includes(q),
        labelFn: (item) => `${item.nama_perusahaan} - ${item.nama_unit}`,
        subLabelFn: (item) => `SN: ${item.serial_number || '-'}`
    });

    // --- Ahli K3 ---
    setupAutocomplete({
        searchId: 'form-ahli-search',
        hiddenId: 'form-ahli-id',
        listId: 'form-ahli-list',
        data: ahliDataAll,
        matchFn: (item, q) => !q ||
            item.nama_lengkap.toLowerCase().includes(q) ||
            (item.bidang_keahlian || '').toLowerCase().includes(q) ||
            (item.nomor_sertifikat || '').toLowerCase().includes(q),
        labelFn: (item) => item.nama_lengkap,
        subLabelFn: (item) => `${item.tingkat_ahli} - ${item.bidang_keahlian} (SKP: ${item.nomor_sertifikat || '-'})`,
        onSelect: (item) => {
            document.getElementById('form-ahli-info').textContent =
                'Bidang: ' + (item.bidang_keahlian || '-') + ' \u00b7 No SKP: ' + (item.nomor_sertifikat || '-');
        }
    });

    function resetForm() {
        document.getElementById('suketForm').reset();
        document.getElementById('form-id').value = '';

        document.getElementById('form-nomor-suket').value = '';
        document.getElementById('form-nomor-laporan').value = '';

        document.getElementById('form-klien-search').value = '';
        document.getElementById('form-klien-id').value = '';
        document.getElementById('form-klien-alamat').textContent = 'Alamat: -';

        document.getElementById('form-objek-search').value = '';
        document.getElementById('form-objek-id').value = '';

        document.getElementById('form-ahli-search').value = '';
        document.getElementById('form-ahli-id').value = '';
        document.getElementById('form-ahli-info').textContent = 'Bidang: - \u00b7 No SKP: -';

        document.getElementById('form-jenis-pemeriksaan').value = 'Pemeriksaan Berkala';
        document.getElementById('form-tgl-pemeriksaan').value = '';
        document.getElementById('form-tgl-expiry').value = '';

        document.getElementById('form-file-suket').value = '';
        document.getElementById('form-file-suket').setAttribute('required', 'required');
        document.getElementById('form-file-required-mark').style.display = 'inline';
        document.getElementById('form-file-existing').textContent = '';

        document.getElementById('suketModalLabel').textContent = 'Upload Suket K3';
    }

    function editSuket(data) {
        document.getElementById('form-id').value = data.id;

        document.getElementById('form-nomor-suket').value = data.nomor_suket || '';
        document.getElementById('form-nomor-laporan').value = data.nomor_laporan || '';

        const klien = klienDataAll.find(k => String(k.id) === String(data.klien_id));
        document.getElementById('form-klien-search').value = klien ? klien.nama_perusahaan : (data.nama_perusahaan || '');
        document.getElementById('form-klien-id').value = data.klien_id;
        document.getElementById('form-klien-alamat').textContent = 'Alamat: ' + (data.alamat_klien || (klien ? klien.alamat : '') || '-');

        const objek = objekDataAll.find(o => String(o.id) === String(data.objek_id));
        document.getElementById('form-objek-search').value = objek ? `${objek.nama_perusahaan} - ${objek.nama_unit}` : (data.nama_unit || '');
        document.getElementById('form-objek-id').value = data.objek_id;

        const ahli = ahliDataAll.find(a => String(a.id) === String(data.ahli_k3_id));
        document.getElementById('form-ahli-search').value = ahli ? ahli.nama_lengkap : (data.nama_ahli || '');
        document.getElementById('form-ahli-id').value = data.ahli_k3_id;
        document.getElementById('form-ahli-info').textContent =
            'Bidang: ' + (data.bidang_ahli || (ahli ? ahli.bidang_keahlian : '') || '-') +
            ' \u00b7 No SKP: ' + (data.no_skp || (ahli ? ahli.nomor_sertifikat : '') || '-');

        document.getElementById('form-jenis-pemeriksaan').value = data.jenis_pemeriksaan || 'Pemeriksaan Berkala';
        document.getElementById('form-tgl-pemeriksaan').value = data.tanggal_pemeriksaan || '';
        document.getElementById('form-tgl-expiry').value = data.tanggal_expiry || '';

        // Saat edit, file tidak wajib diunggah ulang -- hanya kalau mau ganti file lama.
        document.getElementById('form-file-suket').value = '';
        document.getElementById('form-file-suket').removeAttribute('required');
        document.getElementById('form-file-required-mark').style.display = 'none';
        document.getElementById('form-file-existing').textContent = data.file_sertifikat_pdf
            ? 'File saat ini sudah tersimpan. Unggah file baru di sini hanya jika ingin menggantinya.'
            : 'Belum ada file tersimpan untuk suket ini.';

        document.getElementById('suketModalLabel').textContent = 'Edit Suket K3';
    }

    // Validasi manual sebelum submit, karena field wajib berupa hidden input
    document.getElementById('suketForm').addEventListener('submit', function (e) {
        const requiredHidden = [
            { id: 'form-klien-id', label: 'Perusahaan Klien' },
            { id: 'form-ahli-id', label: 'Ahli K3 Pelaksana' }
        ];
        for (const f of requiredHidden) {
            if (!document.getElementById(f.id).value) {
                e.preventDefault();
                alert(`Silakan pilih "${f.label}" dari daftar hasil pencarian sebelum menyimpan.`);
                return;
            }
        }
    });
</script>

<?php if ($highlight_id): ?>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const row = document.getElementById('suket-row-<?= $highlight_id ?>');
            if (row) row.scrollIntoView({ behavior: 'smooth', block: 'center' });
        });
    </script>
<?php endif; ?>

<?php
include "../includes/footer.php";
?>
