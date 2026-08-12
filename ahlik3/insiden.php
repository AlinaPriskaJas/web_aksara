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

// Kategori & tingkat keparahan HARUS sinkron dengan ENUM di tabel Laporan_Insiden
$kategori_options = ['Kecelakaan Kerja', 'Nyaris Celaka (Near Miss)', 'Kebakaran', 'Kerusakan Alat', 'Pencemaran Lingkungan', 'Lainnya'];
$tingkat_options = ['Ringan', 'Sedang', 'Berat', 'Fatal'];

function generateKodeInsiden(): string
{
    return 'INS-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -5));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $insiden_id = $_POST['insiden_id'] ?? '';
    $judul_insiden = trim($_POST['judul_insiden'] ?? '');
    $klien_id = $_POST['klien_id'] ?? '';
    $tanggal_kejadian = $_POST['tanggal_kejadian'] ?? '';
    $lokasi = trim($_POST['lokasi'] ?? '');
    $kategori_insiden = $_POST['kategori_insiden'] ?? '';
    $tingkat_keparahan = $_POST['tingkat_keparahan'] ?? '';
    $deskripsi = trim($_POST['deskripsi'] ?? '');
    $tindakan_awal = trim($_POST['tindakan_awal'] ?? '');
    $existing_foto_bukti = trim($_POST['existing_foto_bukti'] ?? '');

    $isEdit = !empty($insiden_id);

    if (
        empty($judul_insiden) || empty($klien_id) || empty($tanggal_kejadian) || empty($lokasi)
        || empty($kategori_insiden) || empty($tingkat_keparahan) || empty($deskripsi)
    ) {
        $error_msg = "Judul, Klien, Tanggal Kejadian, Lokasi, Kategori, Tingkat Keparahan, dan Deskripsi wajib diisi!";
    } elseif (!in_array($kategori_insiden, $kategori_options, true)) {
        $error_msg = "Kategori insiden tidak valid.";
    } elseif (!in_array($tingkat_keparahan, $tingkat_options, true)) {
        $error_msg = "Tingkat keparahan tidak valid.";
    } else {
        // Validasi klien_id memang ada di database (diisi lewat autocomplete)
        $klienValid = false;
        try {
            $cekKlien = $conn->prepare("SELECT id FROM Data_Klien WHERE id = :id");
            $cekKlien->execute(['id' => $klien_id]);
            $klienValid = (bool) $cekKlien->fetch();
        } catch (PDOException $e) {
            $klienValid = false;
        }

        if (!$klienValid) {
            $error_msg = "Klien yang dipilih tidak valid. Silakan pilih dari daftar saran yang muncul.";
        } else {
            // ==== Proses upload foto/dokumen bukti ====
            $lampiran_files = [];
            $adaFileBaru = !empty($_FILES['foto_bukti']) && !empty($_FILES['foto_bukti']['name'][0]);

            if ($adaFileBaru) {
                $upload_dir = "../uploads/insiden/";
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0755, true);
                }

                $allowed_ext = ['jpg', 'jpeg', 'png', 'pdf'];
                $max_size = 5 * 1024 * 1024; // 5MB per file

                foreach ($_FILES['foto_bukti']['name'] as $idx => $filename) {
                    if ($filename === '') continue;
                    if ($_FILES['foto_bukti']['error'][$idx] !== UPLOAD_ERR_OK) {
                        $error_msg = "Gagal mengunggah file: " . htmlspecialchars($filename);
                        continue;
                    }

                    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                    if (!in_array($ext, $allowed_ext)) {
                        $error_msg = "Format file tidak didukung (hanya JPG, PNG, PDF): " . htmlspecialchars($filename);
                        continue;
                    }
                    if ($_FILES['foto_bukti']['size'][$idx] > $max_size) {
                        $error_msg = "Ukuran file melebihi 5MB: " . htmlspecialchars($filename);
                        continue;
                    }

                    $safe_name = uniqid('insiden_') . '_' . preg_replace('/[^A-Za-z0-9_\-\.]/', '_', $filename);
                    $target_path = $upload_dir . $safe_name;

                    if (move_uploaded_file($_FILES['foto_bukti']['tmp_name'][$idx], $target_path)) {
                        $lampiran_files[] = $safe_name;
                    } else {
                        $error_msg = "Gagal menyimpan file: " . htmlspecialchars($filename);
                    }
                }
            }

            // Kolom foto_bukti VARCHAR(255) -> gabungkan nama file dgn koma, batasi total panjang
            if ($adaFileBaru && count($lampiran_files) > 0) {
                $foto_bukti_str = implode(',', $lampiran_files);
            } else {
                // Tidak ada file baru -> pertahankan file lama (mode edit)
                $foto_bukti_str = $existing_foto_bukti;
            }
            if (strlen($foto_bukti_str) > 255) {
                $error_msg = "Total nama file bukti terlalu panjang, kurangi jumlah file yang diunggah.";
            }

            if ($error_msg === "") {
                try {
                    if ($isEdit) {
                        // ==== UPDATE: hanya boleh mengedit laporan miliknya sendiri & masih berstatus 'Baru' ====
                        $stmt = $conn->prepare("
                            UPDATE Laporan_Insiden
                            SET judul_insiden = :judul,
                                klien_id = :klien_id,
                                lokasi = :lokasi,
                                kategori_insiden = :kategori,
                                tingkat_keparahan = :tingkat,
                                tanggal_kejadian = :tanggal,
                                deskripsi = :deskripsi,
                                tindakan_awal = :tindakan,
                                foto_bukti = :foto
                            WHERE id = :id AND dilaporkan_oleh = :pelapor AND status = 'Baru'
                        ");
                        $stmt->execute([
                            'judul' => $judul_insiden,
                            'klien_id' => $klien_id,
                            'lokasi' => $lokasi,
                            'kategori' => $kategori_insiden,
                            'tingkat' => $tingkat_keparahan,
                            'tanggal' => $tanggal_kejadian,
                            'deskripsi' => $deskripsi,
                            'tindakan' => $tindakan_awal,
                            'foto' => $foto_bukti_str,
                            'id' => $insiden_id,
                            'pelapor' => $current_user_id,
                        ]);

                        if ($stmt->rowCount() > 0) {
                            $success_msg = "Laporan insiden K3 berhasil diperbarui!";
                        } else {
                            $error_msg = "Laporan tidak dapat diedit (mungkin sudah diproses tim HSE atau bukan milik Anda).";
                        }
                    } else {
                        // ==== INSERT baru ====
                        $kode_insiden = generateKodeInsiden();
                        $stmt = $conn->prepare("
                            INSERT INTO Laporan_Insiden
                                (kode_insiden, judul_insiden, klien_id, lokasi, kategori_insiden, tingkat_keparahan, status, tanggal_kejadian, deskripsi, tindakan_awal, foto_bukti, dilaporkan_oleh)
                            VALUES
                                (:kode, :judul, :klien_id, :lokasi, :kategori, :tingkat, 'Baru', :tanggal, :deskripsi, :tindakan, :foto, :pelapor)
                        ");
                        $stmt->execute([
                            'kode' => $kode_insiden,
                            'judul' => $judul_insiden,
                            'klien_id' => $klien_id,
                            'lokasi' => $lokasi,
                            'kategori' => $kategori_insiden,
                            'tingkat' => $tingkat_keparahan,
                            'tanggal' => $tanggal_kejadian,
                            'deskripsi' => $deskripsi,
                            'tindakan' => $tindakan_awal,
                            'foto' => $foto_bukti_str,
                            'pelapor' => $current_user_id,
                        ]);
                        catatAudit(
                            $conn,
                            'Insiden',
                            'Tambah',
                            "Melaporkan insiden K3: {$judul_insiden} di {$lokasi}",
                            null,
                            ['kode_insiden' => $kode_insiden, 'kategori_insiden' => $kategori_insiden, 'tingkat_keparahan' => $tingkat_keparahan]
                        );
                        $success_msg = "Laporan insiden K3 (" . htmlspecialchars($kode_insiden) . ") berhasil direkam dan diproses oleh tim HSE!";
                    }
                } catch (PDOException $e) {
                    $error_msg = "Gagal menyimpan laporan insiden: " . $e->getMessage();
                }
            }
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
        LEFT JOIN Data_Klien dk ON li.klien_id = dk.id
        WHERE li.dilaporkan_oleh = :pelapor_id
        ORDER BY li.tanggal_kejadian DESC, li.created_at DESC
    ");
    $stmtInc->execute(['pelapor_id' => $current_user_id]);
    $incidents = $stmtInc->fetchAll();
} catch (PDOException $e) {
    $incidents = [];
}
?>

<main class="main-content">
    <?php if ($success_msg): ?>
        <div class="alert-success-custom align-items-center">
            <i class="bi bi-check-circle-fill fs-5"></i>
            <div><?= htmlspecialchars($success_msg) ?></div>
        </div>
    <?php endif; ?>
    <?php if ($error_msg): ?>
        <div class="alert-danger-custom align-items-center">
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
                <button class="btn-primary-custom" onclick="resetFormToCreateMode(); openModal('modalInsiden');">
                    <i class="bi bi-cone-striped me-1"></i>Laporan Insiden
                </button>
            </div>
        </div>

        <!-- Tabel Riwayat Insiden -->
        <div class="table-responsive-custom">
            <table class="table-custom" id="tabelInsiden">
                <thead>
                    <tr>
                        <th>Kode / Judul</th>
                        <th>Klien / Lokasi</th>
                        <th>Kategori / Tingkat</th>
                        <th>Tanggal</th>
                        <th>Status</th>
                        <th class="col-aksi">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($incidents) === 0): ?>
                        <tr>
                            <td colspan="6" class="text-center py-4 text-muted">
                                <i class="bi bi-shield-check d-block mb-2" style="font-size:2rem;"></i>
                                Belum pernah melaporkan kejadian insiden.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($incidents as $inc): ?>
                            <tr>
                                <td>
                                    <div class="fw-bold"><?= htmlspecialchars($inc['judul_insiden']) ?></div>
                                    <small class="text-secondary"><?= htmlspecialchars($inc['kode_insiden']) ?></small>
                                </td>
                                <td>
                                    <div class="fw-bold"><?= htmlspecialchars($inc['nama_perusahaan'] ?? '-') ?></div>
                                    <small class="text-secondary"><?= htmlspecialchars($inc['lokasi'] ?: '-') ?></small>
                                </td>
                                <td>
                                    <div class="fw-bold"><?= htmlspecialchars($inc['kategori_insiden']) ?></div>
                                    <span class="badge-danger"><?= htmlspecialchars($inc['tingkat_keparahan']) ?></span>
                                </td>
                                <td><?= date('d-m-Y', strtotime($inc['tanggal_kejadian'])) ?></td>
                                <td>
                                    <?php
                                    $badgeClass = "badge-warning";
                                    if ($inc['status'] === 'Selesai')
                                        $badgeClass = "badge-success";
                                    ?>
                                    <span class="<?= $badgeClass ?>"><?= htmlspecialchars($inc['status']) ?></span>
                                </td>
                                <td class="col-aksi">
                                    <?php if ($inc['status'] === 'Baru'): ?>
                                        <div class="table-actions">
                                            <button type="button" class="btn-icon-bukti" title="Edit laporan"
                                                onclick="openEditModal(<?= (int) $inc['id'] ?>)">
                                                <i class="bi bi-pencil-square"></i>
                                            </button>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-muted text-xs">Sedang diproses</span>
                                    <?php endif; ?>
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

<!-- ===== MODAL: Laporan Insiden (Create / Edit) ===== -->
<div id="modalInsiden" class="arp-modal-overlay" onclick="closeModalOutside(event, 'modalInsiden')">
    <div class="arp-modal-box">
        <div class="arp-modal-header">
            <div>
                <h6 class="fw-bold mb-0" id="modalInsidenTitle">Laporkan Insiden K3 Baru</h6>
                <small class="text-muted" id="modalInsidenSubtitle">Isi form ini untuk melaporkan kejadian bahaya atau
                    kecelakaan kerja.</small>
            </div>
            <button class="arp-modal-close" onclick="closeModal('modalInsiden')">&times;</button>
        </div>
        <div class="arp-modal-body">
            <form method="POST" action="insiden.php" enctype="multipart/form-data" id="formInsiden">
                <input type="hidden" name="insiden_id" id="insidenIdInput" value="">
                <input type="hidden" name="existing_foto_bukti" id="existingFotoBuktiInput" value="">

                <div class="mb-3">
                    <label class="form-label fw-semibold fs-7 mb-2">Judul Insiden *</label>
                    <input type="text" name="judul_insiden" id="judulInsidenInput" class="form-control-custom"
                        placeholder="Contoh: Pekerja Terpeleset di Area Produksi" required>
                </div>

                <!-- Autocomplete klien: pakai pola .search-box-container + .arp-suggestion-box/-item/-empty
                     yang sudah tersedia global (dipakai juga di komponen "Buat Surat"), bukan CSS baru. -->
                <div class="mb-3 search-box-container">
                    <label class="form-label fw-semibold fs-7 mb-2">Perusahaan / Klien Terkait *</label>
                    <input type="text" id="klienSearchInput" class="form-control-custom"
                        placeholder="Ketik nama perusahaan..." autocomplete="off">
                    <input type="hidden" name="klien_id" id="klienIdInput">
                    <div id="klienDropdown" class="arp-suggestion-box" style="display:none;"></div>
                    <small class="text-danger" id="klienErrorMsg" style="display:none;">Pilih klien dari daftar
                        saran yang muncul.</small>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold fs-7 mb-2">Tanggal Kejadian *</label>
                    <input type="date" name="tanggal_kejadian" id="tanggalKejadianInput" class="form-control-custom"
                        required>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold fs-7 mb-2">Lokasi Kejadian *</label>
                    <input type="text" name="lokasi" id="lokasiInput" class="form-control-custom"
                        placeholder="Contoh: Gedung A Lantai Dasar, Area Produksi" required>
                </div>

                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <label class="form-label fw-semibold fs-7 mb-2">Kategori Insiden *</label>
                        <select name="kategori_insiden" id="kategoriInsidenInput" class="select-custom" required>
                            <?php foreach ($kategori_options as $opt): ?>
                                <option value="<?= htmlspecialchars($opt) ?>"><?= htmlspecialchars($opt) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label fw-semibold fs-7 mb-2">Tingkat Keparahan *</label>
                        <select name="tingkat_keparahan" id="tingkatKeparahanInput" class="select-custom" required>
                            <?php foreach ($tingkat_options as $opt): ?>
                                <option value="<?= htmlspecialchars($opt) ?>"><?= htmlspecialchars($opt) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold fs-7 mb-2">Deskripsi / Kronologi Kejadian *</label>
                    <textarea name="deskripsi" id="deskripsiInput" class="textarea-custom" required
                        placeholder="Deskripsikan kronologi kecelakaan/kondisi bahaya secara lengkap..."></textarea>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold fs-7 mb-2">Tindakan Awal</label>
                    <textarea name="tindakan_awal" id="tindakanAwalInput" class="textarea-custom"
                        placeholder="Tindakan darurat yang telah diupayakan..."></textarea>
                </div>

                <div class="mb-4">
                    <label class="form-label fw-semibold fs-7 mb-2">Upload Foto/Dokumen Bukti</label>
                    <input type="file" name="foto_bukti[]" class="form-control-custom" multiple
                        accept="image/jpeg,image/png,application/pdf">
                    <small class="text-muted d-block mt-1">Bisa unggah lebih dari satu file (JPG, PNG, atau PDF),
                        maksimal 5MB per file.</small>
                    <!-- Kotak catatan file lama: pakai .blok-box + .text-xs yang sudah ada (dipakai di "Buat Surat"),
                         bukan bikin kelas baru. Disembunyikan lewat inline style, di-toggle oleh JS. -->
                    <div class="blok-box text-xs mt-2" id="existingFotoNote" style="display:none;"></div>
                </div>

                <div class="d-flex gap-2">
                    <button type="button" class="btn-secondary-custom flex-grow-1"
                        onclick="closeModal('modalInsiden')">Batal</button>
                    <button type="submit" class="btn-primary-custom flex-grow-1" id="submitInsidenBtn">
                        <i class="bi bi-send me-1"></i> Kirim Laporan
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    // Data lengkap semua laporan milik user ini, dipakai untuk mengisi form saat mode Edit
    const incidentsData = <?= json_encode($incidents, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const klienData = <?= json_encode($klien_list, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

    document.addEventListener('DOMContentLoaded', function () {
        initTablePagination('tabelInsiden', 10);

        const searchInput = document.getElementById('klienSearchInput');
        const hiddenInput = document.getElementById('klienIdInput');
        const dropdown = document.getElementById('klienDropdown');
        const klienErrorMsg = document.getElementById('klienErrorMsg');
        const form = document.getElementById('formInsiden');

        function escapeHtml(str) {
            const div = document.createElement('div');
            div.textContent = str;
            return div.innerHTML;
        }

        function highlightMatch(name, query) {
            const idx = name.toLowerCase().indexOf(query.toLowerCase());
            if (idx === -1) return escapeHtml(name);
            const before = escapeHtml(name.substring(0, idx));
            const match = escapeHtml(name.substring(idx, idx + query.length));
            const after = escapeHtml(name.substring(idx + query.length));
            return `${before}<strong>${match}</strong>${after}`;
        }

        function renderDropdown(list, query) {
            if (list.length === 0) {
                dropdown.innerHTML = '<div class="arp-suggestion-empty">Klien tidak ditemukan</div>';
            } else {
                dropdown.innerHTML = list.map(k => `
                    <div class="arp-suggestion-item" data-id="${k.id}" data-name="${escapeHtml(k.nama_perusahaan)}">
                        ${highlightMatch(k.nama_perusahaan, query)}
                    </div>
                `).join('');
            }
            dropdown.style.display = 'block';
        }

        searchInput.addEventListener('input', function () {
            hiddenInput.value = '';
            klienErrorMsg.style.display = 'none';
            const q = this.value.trim();
            if (q === '') {
                dropdown.style.display = 'none';
                return;
            }
            const filtered = klienData.filter(k => k.nama_perusahaan.toLowerCase().includes(q.toLowerCase()));
            renderDropdown(filtered, q);
        });

        searchInput.addEventListener('focus', function () {
            const q = this.value.trim();
            if (q !== '') {
                const filtered = klienData.filter(k => k.nama_perusahaan.toLowerCase().includes(q.toLowerCase()));
                renderDropdown(filtered, q);
            }
        });

        dropdown.addEventListener('click', function (e) {
            const item = e.target.closest('.arp-suggestion-item');
            if (!item) return;
            searchInput.value = item.dataset.name;
            hiddenInput.value = item.dataset.id;
            dropdown.style.display = 'none';
            klienErrorMsg.style.display = 'none';
        });

        document.addEventListener('click', function (e) {
            if (!e.target.closest('#klienSearchInput') && !e.target.closest('#klienDropdown')) {
                dropdown.style.display = 'none';
            }
        });

        form.addEventListener('submit', function (e) {
            if (!hiddenInput.value) {
                e.preventDefault();
                klienErrorMsg.style.display = 'block';
                searchInput.focus();
            }
        });
    });

    function resetFormToCreateMode() {
        document.getElementById('formInsiden').reset();
        document.getElementById('insidenIdInput').value = '';
        document.getElementById('existingFotoBuktiInput').value = '';
        document.getElementById('klienIdInput').value = '';
        document.getElementById('klienSearchInput').value = '';
        document.getElementById('existingFotoNote').style.display = 'none';
        document.getElementById('modalInsidenTitle').textContent = 'Laporkan Insiden K3 Baru';
        document.getElementById('modalInsidenSubtitle').textContent = 'Isi form ini untuk melaporkan kejadian bahaya atau kecelakaan kerja.';
        document.getElementById('submitInsidenBtn').innerHTML = '<i class="bi bi-send me-1"></i> Kirim Laporan';
    }

    function openEditModal(id) {
        const data = incidentsData.find(d => parseInt(d.id) === id);
        if (!data) return;

        document.getElementById('formInsiden').reset();
        document.getElementById('insidenIdInput').value = data.id;
        document.getElementById('judulInsidenInput').value = data.judul_insiden || '';
        document.getElementById('klienSearchInput').value = data.nama_perusahaan || '';
        document.getElementById('klienIdInput').value = data.klien_id || '';
        document.getElementById('tanggalKejadianInput').value = data.tanggal_kejadian || '';
        document.getElementById('lokasiInput').value = data.lokasi || '';
        document.getElementById('kategoriInsidenInput').value = data.kategori_insiden || '';
        document.getElementById('tingkatKeparahanInput').value = data.tingkat_keparahan || '';
        document.getElementById('deskripsiInput').value = data.deskripsi || '';
        document.getElementById('tindakanAwalInput').value = data.tindakan_awal || '';
        document.getElementById('existingFotoBuktiInput').value = data.foto_bukti || '';

        const note = document.getElementById('existingFotoNote');
        if (data.foto_bukti) {
            note.style.display = 'block';
            note.innerHTML = '<i class="bi bi-paperclip me-1"></i>File saat ini: ' + data.foto_bukti.split(',').join(', ') + '<br>Unggah file baru untuk menggantinya, atau biarkan kosong untuk mempertahankan file lama.';
        } else {
            note.style.display = 'none';
        }

        document.getElementById('modalInsidenTitle').textContent = 'Edit Laporan Insiden K3';
        document.getElementById('modalInsidenSubtitle').textContent = 'Perbarui detail laporan insiden ' + (data.kode_insiden || '') + '.';
        document.getElementById('submitInsidenBtn').innerHTML = '<i class="bi bi-save me-1"></i> Simpan Perubahan';

        openModal('modalInsiden');
    }
</script>

<?php if ($error_msg): ?>
    <script>document.addEventListener('DOMContentLoaded', () => openModal('modalInsiden'));</script>
<?php endif; ?>

<?php include "../includes/footer.php"; ?>