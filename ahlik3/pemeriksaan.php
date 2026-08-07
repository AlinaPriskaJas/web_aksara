<?php
// ahlik3/pemeriksaan.php
require_once "../config/koneksi.php";

if (session_status() === PHP_SESSION_NONE)
    session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'ahli_k3') {
    header("Location: ../login.php");
    exit;
}

$page_title = "Input Hasil Pemeriksaan";
include "../includes/header.php";
include "../includes/sidebar.php";
include "../includes/topbar.php";

$current_user_id = $_SESSION['user_id'];
$success_msg = "";
$error_msg = "";

try {
    $stmtAhli = $conn->prepare("SELECT id FROM Sertifikat_Ahli WHERE user_id = :user_id LIMIT 1");
    $stmtAhli->execute(['user_id' => $current_user_id]);
    $ahli_k3_id = $stmtAhli->fetchColumn() ?: 0;
} catch (PDOException $e) {
    $ahli_k3_id = 0;
}

// ================== PROSES: INPUT HASIL PEMERIKSAAN (form lama) ==================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_action'] ?? '') === 'input_hasil') {
    $laporan_id = $_POST['suket_id']; // tetap pakai nama field ini di form, isinya id laporan
    $hasil_pemeriksaan = $_POST['hasil_pemeriksaan'];
    $rekomendasi_teknis = $_POST['rekomendasi_teknis'];
    $tanggal_pemeriksaan = $_POST['tanggal_pemeriksaan'];
    $tanggal_expiry = $_POST['tanggal_expiry'] ?: null;

    if (empty($laporan_id) || empty($hasil_pemeriksaan) || empty($tanggal_pemeriksaan)) {
        $error_msg = "Laporan, Hasil Kelayakan, dan Tanggal Pemeriksaan wajib diisi!";
    } else {
        try {
            $stmt = $conn->prepare("
                UPDATE Laporan_Pemeriksaan 
                SET hasil_pemeriksaan = :hasil, 
                    rekomendasi_teknis = :rekomendasi, 
                    tanggal_pemeriksaan = :tgl_periksa,
                    tanggal_expiry = :tgl_expiry,
                    status = 'Menunggu Suket'
                WHERE id = :id AND ahli_k3_id = :ahli_id
            ");
            $stmt->execute([
                'hasil' => $hasil_pemeriksaan,
                'rekomendasi' => $rekomendasi_teknis,
                'tgl_periksa' => $tanggal_pemeriksaan,
                'tgl_expiry' => $tanggal_expiry,
                'id' => $laporan_id,
                'ahli_id' => $ahli_k3_id
            ]);
            $success_msg = "Hasil pemeriksaan berhasil disimpan!";
        } catch (PDOException $e) {
            $error_msg = "Gagal menyimpan hasil: " . $e->getMessage();
        }
    }
}

// ================== PROSES: UPLOAD LAPORAN PEMERIKSAAN (form baru) ==================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_action'] ?? '') === 'upload_laporan') {
    $nomor_laporan = trim($_POST['nomor_laporan'] ?? '');
    $klien_id = (int) ($_POST['klien_id'] ?? 0);
    $nama_perusahaan_input = trim($_POST['nama_perusahaan'] ?? '');
    $id_kategori = (int) ($_POST['id_kategori'] ?? 0);
    $id_jenis = (int) ($_POST['id_jenis'] ?? 0);
    $unit_objek_input = trim($_POST['unit_objek'] ?? '');
    $tanggal_buat = $_POST['tanggal_buat'] ?? '';
    $jenis_pemeriksaan_short = $_POST['jenis_pemeriksaan'] ?? '';

    $jenisMap = [
        'Berkala' => 'Pemeriksaan Berkala',
        'Baru' => 'Pemeriksaan Baru',
    ];

    if (
        $nomor_laporan === '' || $klien_id <= 0 || $id_jenis <= 0 || $unit_objek_input === '' ||
        $tanggal_buat === '' || !isset($jenisMap[$jenis_pemeriksaan_short])
    ) {
        $error_msg = "Nomor Surat, Nama Perusahaan, Bidang Objek, Unit Objek, Tanggal Dibuat, dan Jenis Pemeriksaan wajib diisi dengan benar!";
    } elseif (!isset($_FILES['file_laporan']) || $_FILES['file_laporan']['error'] !== UPLOAD_ERR_OK) {
        $error_msg = "Silakan lampirkan file Laporan Pemeriksaan yang valid!";
    } else {
        $file = $_FILES['file_laporan'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png'];

        if (!in_array($ext, $allowed)) {
            $error_msg = "Format file tidak didukung! Hanya PDF, DOC, DOCX, JPG, PNG.";
        } else {
            try {
                // Pastikan klien yang dipilih valid (hasil autocomplete)
                $stmtKlien = $conn->prepare("SELECT id, nama_perusahaan FROM Data_Klien WHERE id = :id LIMIT 1");
                $stmtKlien->execute(['id' => $klien_id]);
                $klien = $stmtKlien->fetch();

                // Pastikan jenis objek yang dipilih valid (hasil autocomplete)
                $stmtJenis = $conn->prepare("SELECT id_jenis, id_kategori, nama_objek FROM jenis_objek_k3 WHERE id_jenis = :id LIMIT 1");
                $stmtJenis->execute(['id' => $id_jenis]);
                $jenisObjek = $stmtJenis->fetch();

                if (!$klien) {
                    $error_msg = "Nama perusahaan tidak ditemukan di Data Klien. Silakan pilih dari daftar saran.";
                } elseif (!$jenisObjek) {
                    $error_msg = "Unit Objek tidak ditemukan. Silakan pilih dari daftar saran yang muncul.";
                } else {
                    // Cari Objek_K3 milik klien ini dengan jenis yang sama & nama unit yang sama,
                    // jika belum ada maka buat baru
                    $stmtCariObjek = $conn->prepare("
                        SELECT id FROM Objek_K3 
                        WHERE id_client = :id_client AND id_jenis = :id_jenis AND nama_unit = :nama_unit 
                        LIMIT 1
                    ");
                    $stmtCariObjek->execute([
                        'id_client' => $klien_id,
                        'id_jenis' => $id_jenis,
                        'nama_unit' => $unit_objek_input
                    ]);
                    $objek_id = $stmtCariObjek->fetchColumn();

                    if (!$objek_id) {
                        $stmtBuatObjek = $conn->prepare("
                            INSERT INTO Objek_K3 (id_client, id_jenis, nama_unit) 
                            VALUES (:id_client, :id_jenis, :nama_unit)
                        ");
                        $stmtBuatObjek->execute([
                            'id_client' => $klien_id,
                            'id_jenis' => $id_jenis,
                            'nama_unit' => $unit_objek_input
                        ]);
                        $objek_id = $conn->lastInsertId();
                    }

                    // Upload file ke folder penyimpanan
                    $target_dir = "../uploads/laporan_pemeriksaan/";
                    if (!is_dir($target_dir)) {
                        mkdir($target_dir, 0777, true);
                    }
                    $filename = "laporan_" . time() . "_" . uniqid() . "." . $ext;
                    $db_path = "uploads/laporan_pemeriksaan/" . $filename;
                    $target_file = $target_dir . $filename;

                    if (move_uploaded_file($file['tmp_name'], $target_file)) {
                        $stmtInsert = $conn->prepare("
                            INSERT INTO Laporan_Pemeriksaan 
                                (klien_id, objek_id, nomor_laporan, jenis_pemeriksaan, tanggal_buat, tanggal_pemeriksaan, ahli_k3_id, file_laporan) 
                            VALUES 
                                (:klien_id, :objek_id, :nomor_laporan, :jenis_pemeriksaan, :tanggal_buat, :tanggal_buat, :ahli_id, :file_path)
                        ");
                        $stmtInsert->execute([
                            'klien_id' => $klien_id,
                            'objek_id' => $objek_id,
                            'nomor_laporan' => $nomor_laporan,
                            'jenis_pemeriksaan' => $jenisMap[$jenis_pemeriksaan_short],
                            'tanggal_buat' => $tanggal_buat,
                            'ahli_id' => $ahli_k3_id,
                            'file_path' => $db_path
                        ]);
                        $success_msg = "Laporan Pemeriksaan berhasil diunggah!";
                    } else {
                        $error_msg = "Gagal mengunggah file ke server.";
                    }
                }
            } catch (PDOException $e) {
                if ($e->getCode() == 23000) {
                    $error_msg = "Nomor Laporan tersebut sudah terdaftar. Gunakan nomor lain.";
                } else {
                    $error_msg = "Gagal menyimpan laporan: " . $e->getMessage();
                }
            }
        }
    }
}

// ================== DATA UNTUK TABEL & FORM ==================
$laporans = [];
if ($ahli_k3_id > 0) {
    try {
        $stmtLaporan = $conn->prepare("
            SELECT lp.*, dk.nama_perusahaan, o.nama_unit, o.serial_number, jo.nama_objek AS nama_alat
            FROM Laporan_Pemeriksaan lp
            JOIN Data_Klien dk ON lp.klien_id = dk.id
            JOIN Objek_K3 o ON lp.objek_id = o.id
            LEFT JOIN jenis_objek_k3 jo ON o.id_jenis = jo.id_jenis
            WHERE lp.ahli_k3_id = :ahli_id
            ORDER BY lp.created_at DESC
        ");
        $stmtLaporan->execute(['ahli_id' => $ahli_k3_id]);
        $laporans = $stmtLaporan->fetchAll();
    } catch (PDOException $e) {
        $laporans = [];
    }
}

// Data untuk autocomplete: Nama Perusahaan (Data Klien)
$klien_list = [];
try {
    $klien_list = $conn->query("SELECT id, nama_perusahaan FROM Data_Klien ORDER BY nama_perusahaan ASC")->fetchAll();
} catch (PDOException $e) {
    $klien_list = [];
}

// Data untuk autocomplete: Bidang Objek (kategori_objek_k3)
$kategori_list = [];
try {
    $kategori_list = $conn->query("SELECT id_kategori, nama_kategori FROM kategori_objek_k3 ORDER BY nama_kategori ASC")->fetchAll();
} catch (PDOException $e) {
    $kategori_list = [];
}

// Data untuk autocomplete: Unit Objek (jenis_objek_k3), difilter di JS sesuai Bidang Objek yang dipilih
$jenis_list = [];
try {
    $jenis_list = $conn->query("SELECT id_jenis, id_kategori, nama_objek FROM jenis_objek_k3 ORDER BY nama_objek ASC")->fetchAll();
} catch (PDOException $e) {
    $jenis_list = [];
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
            <h5 class="table-toolbar-title fw-bold">Daftar Laporan Pemeriksaan</h5>
            <div class="table-toolbar-actions">
                <div class="search-box-container">
                    <i class="bi bi-search"></i>
                    <input type="text" class="search-box" placeholder="Cari laporan..."
                        data-table-search="tabelInputHasil" onkeyup="handleTableSearch('tabelInputHasil')">
                </div>
                <button class="btn-primary-custom" onclick="openModal('modalUploadLaporan')">
                    <i class="bi bi-file-earmark-medical me-1"></i>Upload Laporan Pemeriksaan
                </button>
                <button class="btn-primary-custom" onclick="openModal('modalInputHasil')">
                    <i class="bi bi-file-earmark-medical me-1"></i>Buat Laporan Pemeriksaan
                </button>
            </div>
        </div>

        <!-- Tabel Daftar Laporan Pemeriksaan -->
        <div class="table-responsive-custom">
            <table class="table-custom" id="tabelInputHasil">
                <thead>
                    <tr>
                        <th>No. Laporan Pemeriksaan</th>
                        <th>Nama Perusahaan</th>
                        <th>Unit Objek</th>
                        <th>Alat</th>
                        <th>Tanggal Buat</th>
                        <th>Jenis Pemeriksaan</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($laporans) === 0): ?>
                        <tr>
                            <td colspan="7" class="text-center py-4 text-muted">
                                <i class="bi bi-clipboard-x d-block mb-2" style="font-size:2rem;"></i>
                                Belum ada penugasan atau laporan pemeriksaan.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($laporans as $s): ?>
                            <tr>
                                <td><?= htmlspecialchars($s['nomor_laporan']) ?></td>
                                <td><?= htmlspecialchars($s['nama_perusahaan']) ?></td>
                                <td><?= htmlspecialchars($s['nama_unit'] ?: '-') ?></td>
                                <td><?= htmlspecialchars($s['nama_alat'] ?: '-') ?></td>
                                <td><?= $s['created_at'] ? date('d-m-Y', strtotime($s['created_at'])) : '-' ?></td>
                                <td><?= htmlspecialchars($s['jenis_pemeriksaan']) ?></td>
                                <td>
                                    <div class="d-flex gap-1">
                                        <?php if (!empty($s['file_laporan'])): ?>
                                            <a href="../<?= htmlspecialchars($s['file_laporan']) ?>" target="_blank"
                                                class="btn btn-outline-secondary btn-sm py-1"
                                                style="font-size:0.75rem; border-radius: 8px;" title="Lihat File">
                                                <i class="bi bi-file-earmark-text"></i>
                                            </a>
                                        <?php endif; ?>
                                        <button type="button" class="btn btn-outline-secondary btn-sm py-1"
                                            style="font-size:0.75rem; border-radius: 8px;" title="Input Hasil"
                                            onclick="bukaModalHasil(<?= (int) $s['id'] ?>)">
                                            <i class="bi bi-pencil-square"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="pagination-custom" id="pagination-tabelInputHasil"></div>
    </div>
</main>

<!-- ===== MODAL: Upload Laporan Pemeriksaan ===== -->
<div id="modalUploadLaporan" class="arp-modal-overlay" onclick="closeModalOutside(event, 'modalUploadLaporan')">
    <div class="arp-modal-box">
        <div class="arp-modal-header">
            <div>
                <h6 class="fw-bold mb-0">Upload Laporan Pemeriksaan</h6>
                <small class="text-muted">Unggah berkas laporan hasil pemeriksaan K3.</small>
            </div>
            <button class="arp-modal-close" onclick="closeModal('modalUploadLaporan')">&times;</button>
        </div>
        <div class="arp-modal-body">
            <form method="POST" action="pemeriksaan.php" enctype="multipart/form-data" id="formUploadLaporan">
                <input type="hidden" name="form_action" value="upload_laporan">
                <input type="hidden" name="klien_id" id="upload-klien-id" required>
                <input type="hidden" name="id_kategori" id="upload-id-kategori">
                <input type="hidden" name="id_jenis" id="upload-id-jenis" required>

                <div class="mb-3">
                    <label class="form-label fw-semibold fs-7 mb-2">Nomor Surat *</label>
                    <input type="text" name="nomor_laporan" class="form-control-custom"
                        placeholder="Contoh: 566/K3-LAP/VIII/2026" required>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold fs-7 mb-2">Nama Perusahaan *</label>
                    <div class="arp-autocomplete-wrap" style="position:relative;">
                        <input type="text" id="upload-nama-perusahaan" class="form-control-custom"
                            placeholder="Ketik nama perusahaan..." autocomplete="off"
                            oninput="searchKlienUpload(this.value)" onfocus="searchKlienUpload(this.value)" required>
                        <div id="klien-upload-suggestion-box" class="arp-suggestion-box" style="display:none;"></div>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold fs-7 mb-2">Bidang Objek *</label>
                    <div class="arp-autocomplete-wrap" style="position:relative;">
                        <input type="text" id="upload-bidang-objek" class="form-control-custom"
                            placeholder="Ketik bidang objek..." autocomplete="off"
                            oninput="searchKategoriUpload(this.value)" onfocus="searchKategoriUpload(this.value)"
                            required>
                        <div id="kategori-upload-suggestion-box" class="arp-suggestion-box" style="display:none;">
                        </div>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold fs-7 mb-2">Unit Objek *</label>
                    <div class="arp-autocomplete-wrap" style="position:relative;">
                        <input type="text" name="unit_objek" id="upload-unit-objek" class="form-control-custom"
                            placeholder="Pilih Bidang Objek terlebih dahulu..." autocomplete="off" disabled
                            oninput="searchJenisUpload(this.value)" onfocus="searchJenisUpload(this.value)" required>
                        <div id="jenis-upload-suggestion-box" class="arp-suggestion-box" style="display:none;"></div>
                    </div>
                    <small class="text-muted d-block mt-1">Pilihan Unit Objek menyesuaikan Bidang Objek yang dipilih di
                        atas.</small>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold fs-7 mb-2">Tanggal Dibuat *</label>
                    <input type="date" name="tanggal_buat" class="form-control-custom" required>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold fs-7 mb-2">Jenis Pemeriksaan *</label>
                    <select name="jenis_pemeriksaan" class="select-custom" required>
                        <option value="">-- Pilih Jenis Pemeriksaan --</option>
                        <option value="Berkala">Berkala</option>
                        <option value="Baru">Baru</option>
                    </select>
                </div>

                <div class="mb-4">
                    <label class="form-label fw-semibold fs-7 mb-2">Upload File Laporan *</label>
                    <input type="file" name="file_laporan" class="form-control-custom" style="padding-top:8px;"
                        required>
                    <small class="text-muted d-block mt-1">Ekstensi yang diizinkan: PDF, DOC, DOCX, JPG, PNG</small>
                </div>

                <div class="d-flex gap-2">
                    <button type="button" class="btn-secondary-custom flex-grow-1"
                        onclick="closeModal('modalUploadLaporan')">Batal</button>
                    <button type="submit" class="btn-primary-custom flex-grow-1"><i class="bi bi-upload me-1"></i>
                        Unggah</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ===== MODAL: Input Hasil ===== -->
<div id="modalInputHasil" class="arp-modal-overlay" onclick="closeModalOutside(event, 'modalInputHasil')">
    <div class="arp-modal-box">
        <div class="arp-modal-header">
            <div>
                <h6 class="fw-bold mb-0">Input Hasil Pengujian &amp; Penilaian K3</h6>
                <small class="text-muted">Masukkan hasil kelayakan dan rekomendasi teknis pemeriksaan.</small>
            </div>
            <button class="arp-modal-close" onclick="closeModal('modalInputHasil')">&times;</button>
        </div>
        <div class="arp-modal-body">
            <form method="POST" action="pemeriksaan.php">
                <input type="hidden" name="form_action" value="input_hasil">
                <div class="mb-3">
                    <label class="form-label fw-semibold fs-7 mb-2">Pilih Unit Pengujian Suket *</label>
                    <select name="suket_id" id="input-hasil-suket-id" class="select-custom" required>
                        <option value="">-- Pilih Laporan Penugasan --</option>
                        <?php foreach ($laporans as $s): ?>
                            <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['nama_perusahaan']) ?> -
                                <?= htmlspecialchars($s['nama_unit']) ?> (<?= htmlspecialchars($s['nomor_laporan']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold fs-7 mb-2">Tanggal Pemeriksaan *</label>
                    <input type="date" name="tanggal_pemeriksaan" class="form-control-custom" required>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold fs-7 mb-2">Masa Berlaku Kedaluwarsa</label>
                    <input type="date" name="tanggal_expiry" class="form-control-custom">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold fs-7 mb-2">Hasil Kelayakan K3 *</label>
                    <select name="hasil_pemeriksaan" class="select-custom" required>
                        <option value="Layak">Layak</option>
                        <option value="Layak Bersyarat">Layak Bersyarat</option>
                        <option value="Tidak Layak">Tidak Layak</option>
                    </select>
                </div>
                <div class="mb-4">
                    <label class="form-label fw-semibold fs-7 mb-2">Rekomendasi Teknis Temuan Lapangan</label>
                    <textarea name="rekomendasi_teknis" class="textarea-custom"
                        placeholder="Tuliskan temuan perbaikan..."></textarea>
                </div>
                <div class="d-flex gap-2">
                    <button type="button" class="btn-secondary-custom flex-grow-1"
                        onclick="closeModal('modalInputHasil')">Batal</button>
                    <button type="submit" class="btn-primary-custom flex-grow-1"><i class="bi bi-send me-1"></i> Kirim &
                        Rekam</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        initTablePagination('tabelInputHasil', 10);

        // Tutup semua kotak saran autocomplete jika klik di luar area saran
        document.addEventListener('click', function (e) {
            document.querySelectorAll('.arp-autocomplete-wrap').forEach(function (wrap) {
                if (!wrap.contains(e.target)) {
                    const box = wrap.querySelector('.arp-suggestion-box');
                    if (box) box.style.display = 'none';
                }
            });
        });
    });

    function bukaModalHasil(suketId) {
        document.getElementById('input-hasil-suket-id').value = suketId;
        openModal('modalInputHasil');
    }

    // ============== DATA UNTUK AUTOCOMPLETE ==============
    const klienDataUpload = <?= json_encode($klien_list) ?>;
    const kategoriDataUpload = <?= json_encode($kategori_list) ?>;
    const jenisDataUpload = <?= json_encode($jenis_list) ?>;

    // ---------- Autocomplete: Nama Perusahaan ----------
    function searchKlienUpload(keyword) {
        const box = document.getElementById('klien-upload-suggestion-box');
        keyword = (keyword || '').trim().toLowerCase();
        document.getElementById('upload-klien-id').value = '';

        if (keyword === '') {
            box.style.display = 'none';
            box.innerHTML = '';
            return;
        }

        const matches = klienDataUpload.filter(k => k.nama_perusahaan.toLowerCase().includes(keyword));

        if (matches.length === 0) {
            box.innerHTML = '<div class="arp-suggestion-empty">Tidak ada perusahaan ditemukan</div>';
            box.style.display = 'block';
            return;
        }

        box.innerHTML = matches.map(k => `
            <div class="arp-suggestion-item" onclick='pilihKlienUpload(${JSON.stringify(k)})'>
                <div class="fw-semibold">${escapeHtml(k.nama_perusahaan)}</div>
            </div>
        `).join('');
        box.style.display = 'block';
    }

    function pilihKlienUpload(klien) {
        document.getElementById('upload-nama-perusahaan').value = klien.nama_perusahaan;
        document.getElementById('upload-klien-id').value = klien.id;
        document.getElementById('klien-upload-suggestion-box').style.display = 'none';
    }

    // ---------- Autocomplete: Bidang Objek (kategori_objek_k3) ----------
    function searchKategoriUpload(keyword) {
        const box = document.getElementById('kategori-upload-suggestion-box');
        keyword = (keyword || '').trim().toLowerCase();
        document.getElementById('upload-id-kategori').value = '';

        if (keyword === '') {
            box.style.display = 'none';
            box.innerHTML = '';
            return;
        }

        const matches = kategoriDataUpload.filter(k => k.nama_kategori.toLowerCase().includes(keyword));

        if (matches.length === 0) {
            box.innerHTML = '<div class="arp-suggestion-empty">Tidak ada bidang objek ditemukan</div>';
            box.style.display = 'block';
            return;
        }

        box.innerHTML = matches.map(k => `
            <div class="arp-suggestion-item" onclick='pilihKategoriUpload(${JSON.stringify(k)})'>
                <div class="fw-semibold">${escapeHtml(k.nama_kategori)}</div>
            </div>
        `).join('');
        box.style.display = 'block';
    }

    function pilihKategoriUpload(kategori) {
        document.getElementById('upload-bidang-objek').value = kategori.nama_kategori;
        document.getElementById('upload-id-kategori').value = kategori.id_kategori;
        document.getElementById('kategori-upload-suggestion-box').style.display = 'none';

        // Reset & aktifkan Unit Objek setiap kali Bidang Objek berubah
        const unitInput = document.getElementById('upload-unit-objek');
        unitInput.value = '';
        unitInput.disabled = false;
        unitInput.placeholder = 'Ketik unit objek...';
        document.getElementById('upload-id-jenis').value = '';
        document.getElementById('jenis-upload-suggestion-box').style.display = 'none';
        unitInput.focus();
    }

    // ---------- Autocomplete: Unit Objek (jenis_objek_k3), difilter sesuai Bidang Objek ----------
    function searchJenisUpload(keyword) {
        const box = document.getElementById('jenis-upload-suggestion-box');
        const idKategori = document.getElementById('upload-id-kategori').value;
        keyword = (keyword || '').trim().toLowerCase();
        document.getElementById('upload-id-jenis').value = '';

        if (!idKategori) {
            box.style.display = 'none';
            box.innerHTML = '';
            return;
        }

        const matches = jenisDataUpload.filter(j =>
            String(j.id_kategori) === String(idKategori) &&
            j.nama_objek.toLowerCase().includes(keyword)
        );

        if (matches.length === 0) {
            box.innerHTML = '<div class="arp-suggestion-empty">Tidak ada unit objek ditemukan untuk bidang ini</div>';
            box.style.display = 'block';
            return;
        }

        box.innerHTML = matches.map(j => `
            <div class="arp-suggestion-item" onclick='pilihJenisUpload(${JSON.stringify(j)})'>
                <div class="fw-semibold">${escapeHtml(j.nama_objek)}</div>
            </div>
        `).join('');
        box.style.display = 'block';
    }

    function pilihJenisUpload(jenis) {
        document.getElementById('upload-unit-objek').value = jenis.nama_objek;
        document.getElementById('upload-id-jenis').value = jenis.id_jenis;
        document.getElementById('jenis-upload-suggestion-box').style.display = 'none';
    }

    function escapeHtml(str) {
        if (!str) return '';
        return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    }
</script>

<?php if ($error_msg): ?>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            <?php if (($_POST['form_action'] ?? '') === 'upload_laporan'): ?>
                openModal('modalUploadLaporan');
            <?php else: ?>
                openModal('modalInputHasil');
            <?php endif; ?>
        });
    </script>
<?php endif; ?>

<?php include "../includes/footer.php"; ?>