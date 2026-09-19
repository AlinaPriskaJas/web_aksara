<?php
// it/digital.php
require_once "../config/koneksi.php";
require_once "../includes/drive_helper.php";
require_once "../includes/dokumen_helper.php";

if (session_status() === PHP_SESSION_NONE)
    session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'it') {
    header("Location: ../login.php");
    exit;
}

$page_title = "Digital Sign - Dokumen Digital Perusahaan";
$ROLE_AKTIF = 'it'; // dipakai untuk membangun link balik ke halaman asal dokumen
include "../includes/header.php";
include "../includes/sidebar.php";
include "../includes/topbar.php";

// File dari modul Surat/Sertifikat dkk sekarang berupa link Google Drive
// (bukan cuma path lokal), jadi jangan selalu ditempeli '../'.
function href_dokumen(string $path): string
{
    return (stripos($path, 'http') === 0) ? $path : '../' . $path;
}

$current_user_id = $_SESSION['user_id'];
$success_msg = "";
$error_msg = "";
$kategori_list = ['Suket K3', 'Sertifikat Ahli', 'Legal Perusahaan', 'Kontrak Klien', 'Laporan', 'Lainnya'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'upload') {
        $nama_dokumen = trim($_POST['nama_dokumen']);
        $kategori = $_POST['kategori'];
        $visibilitas = $_POST['visibilitas'];
        $klien_id = !empty($_POST['klien_id']) ? intval($_POST['klien_id']) : null;

        if (empty($nama_dokumen) || !in_array($kategori, $kategori_list) || !isset($_FILES['file_path']) || $_FILES['file_path']['error'] !== UPLOAD_ERR_OK) {
            $error_msg = "Nama dokumen, kategori, dan berkas wajib diisi!";
        } else {
            $file = $_FILES['file_path'];
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $allowed = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx', 'xls', 'xlsx'];

            if (!in_array($ext, $allowed)) {
                $error_msg = "Format berkas tidak didukung.";
            } else {
                $hasil_drive = arp_upload_ke_drive($file['tmp_name'], $file['name'], $file['type'], 0, 'Dokumen_Digital');
                if ($hasil_drive && !empty($hasil_drive['link'])) {
                    try {
                        $stmt = $conn->prepare("INSERT INTO Dokumen_Digital (nama_dokumen, kategori, file_path, modul_sumber, klien_id, visibilitas, diupload_oleh) VALUES (:nama, :kategori, :path, 'IT Repository', :klien_id, :visibilitas, :user_id)");
                        $stmt->execute([
                            'nama' => $nama_dokumen,
                            'kategori' => $kategori,
                            'path' => $hasil_drive['link'],
                            'klien_id' => $klien_id,
                            'visibilitas' => $visibilitas,
                            'user_id' => $current_user_id
                        ]);
                        $success_msg = "Dokumen \"$nama_dokumen\" berhasil diunggah ke repository.";
                    } catch (PDOException $e) {
                        $error_msg = "Gagal menyimpan data dokumen: " . $e->getMessage();
                    }
                } else {
                    $error_msg = "Gagal mengunggah berkas ke Drive.";
                }
            }
        }
    } elseif (isset($_POST['action']) && $_POST['action'] === 'hapus') {
        $doc_id = $_POST['doc_id'];
        try {
            $stmt = $conn->prepare("DELETE FROM Dokumen_Digital WHERE id = :id");
            $stmt->execute(['id' => $doc_id]);
            $success_msg = "Dokumen berhasil dihapus dari repository.";
        } catch (PDOException $e) {
            $error_msg = "Gagal menghapus dokumen: " . $e->getMessage();
        }
    }
}

// Tab sumber dokumen (otomatis mengikuti dokumen yang benar-benar masuk arsip)
$modul_tersedia = [];
try {
    $stmtModul = $conn->query("SELECT DISTINCT modul_sumber FROM Dokumen_Digital WHERE modul_sumber IS NOT NULL AND modul_sumber <> ''");
    $modul_tersedia = $stmtModul->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    $modul_tersedia = [];
}
$grup_map = []; // 'Label Tab' => modul_sumber asli di DB
foreach ($modul_tersedia as $modulSumber) {
    $info = arp_info_modul_dokumen($modulSumber);
    $grup_map[$info['label']] = $modulSumber;
}
ksort($grup_map);

$filter_grup = isset($_GET['grup']) ? trim($_GET['grup']) : '';
if (!array_key_exists($filter_grup, $grup_map)) {
    $filter_grup = '';
}

// Filter
$filter_kategori = isset($_GET['kategori']) ? trim($_GET['kategori']) : '';
$sql = "SELECT d.*, u.nama_lengkap, k.nama_perusahaan FROM Dokumen_Digital d 
        JOIN Users u ON d.diupload_oleh = u.id 
        LEFT JOIN Data_Klien k ON d.klien_id = k.id
        WHERE 1=1";
$params = [];
if (!empty($filter_kategori)) {
    $sql .= " AND d.kategori = :kategori";
    $params['kategori'] = $filter_kategori;
}
if ($filter_grup !== '') {
    $sql .= " AND d.modul_sumber = :modul_sumber";
    $params['modul_sumber'] = $grup_map[$filter_grup];
}
$sql .= " ORDER BY d.created_at DESC";

try {
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $dokumen = $stmt->fetchAll();
} catch (PDOException $e) {
    $dokumen = [];
}

$totalDok = $conn->query("SELECT COUNT(*) FROM Dokumen_Digital")->fetchColumn() ?: 0;

// Total per jenis dokumen, dipakai untuk card ringkasan di atas.
$totalSurat = $conn->query("
    SELECT COUNT(*) FROM Dokumen_Digital
    WHERE modul_sumber IN ('Surat Keluar', 'Surat Masuk', 'Surat')
")->fetchColumn() ?: 0;
$totalSuket = $conn->query("SELECT COUNT(*) FROM Dokumen_Digital WHERE kategori = 'Suket K3'")->fetchColumn() ?: 0;
$totalLaporan = $conn->query("SELECT COUNT(*) FROM Dokumen_Digital WHERE kategori = 'Laporan'")->fetchColumn() ?: 0;

$klienList = $conn->query("SELECT id, nama_perusahaan FROM Data_Klien ORDER BY nama_perusahaan ASC")->fetchAll();
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

    <div class="row g-4 mb-4">
        <div class="col-xl-3 col-md-6 col-12">
            <div class="stat-card">
                <div class="stat-card-info">
                    <span class="stat-card-title">Total Dokumen Tersimpan</span>
                    <span class="stat-card-value"><?= $totalDok ?></span>
                </div>
                <div class="stat-card-icon"><i class="bi bi-hdd-network-fill"></i></div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6 col-12">
            <div class="stat-card">
                <div class="stat-card-info">
                    <span class="stat-card-title">Total Surat</span>
                    <span class="stat-card-value"><?= $totalSurat ?></span>
                </div>
                <div class="stat-card-icon warning"><i class="bi bi-envelope-fill"></i></div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6 col-12">
            <div class="stat-card">
                <div class="stat-card-info">
                    <span class="stat-card-title">Total Suket K3</span>
                    <span class="stat-card-value"><?= $totalSuket ?></span>
                </div>
                <div class="stat-card-icon success"><i class="bi bi-file-earmark-medical"></i></div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6 col-12">
            <div class="stat-card">
                <div class="stat-card-info">
                    <span class="stat-card-title">Total Laporan</span>
                    <span class="stat-card-value"><?= $totalLaporan ?></span>
                </div>
                <div class="stat-card-icon"><i class="bi bi-clipboard-data-fill"></i></div>
            </div>
        </div>
    </div>

    <div class="card-box">
        <!-- Tab Sumber Dokumen (otomatis mengikuti dokumen yang benar-benar masuk arsip) -->
        <div class="d-flex flex-wrap gap-2 mb-3">
            <?php
            $tabUrlParams = array_filter(['kategori' => $filter_kategori ?: null]);
            $semuaActive = $filter_grup === '';
            ?>
            <a href="digital.php?<?= htmlspecialchars(http_build_query($tabUrlParams)) ?>"
               class="<?= $semuaActive ? 'btn-primary-custom' : 'btn-secondary-custom' ?>"
               style="height:36px; padding:0 16px; font-size:0.85rem; display:inline-flex; align-items:center; gap:6px;">
                <i class="bi bi-grid-fill"></i> Semua
            </a>
            <?php foreach ($grup_map as $labelTab => $modulSumberAsli):
                $active = $filter_grup === $labelTab;
                $iconGrup = arp_info_modul_dokumen($modulSumberAsli)['icon'];
                $paramsTab = array_merge($tabUrlParams, ['grup' => $labelTab]);
                ?>
                <a href="digital.php?<?= htmlspecialchars(http_build_query($paramsTab)) ?>"
                   class="<?= $active ? 'btn-primary-custom' : 'btn-secondary-custom' ?>"
                   style="height:36px; padding:0 16px; font-size:0.85rem; display:inline-flex; align-items:center; gap:6px;">
                    <i class="bi <?= htmlspecialchars($iconGrup) ?>"></i> <?= htmlspecialchars($labelTab) ?>
                </a>
            <?php endforeach; ?>
        </div>

        <div class="table-toolbar">
            <h5 class="table-toolbar-title fw-bold">Repository Dokumen Perusahaan</h5>
            <div class="table-toolbar-actions">
                <form method="GET" action="digital.php">
                    <input type="hidden" name="grup" value="<?= htmlspecialchars($filter_grup) ?>">
                    <select name="kategori" class="select-custom" onchange="this.form.submit()" style="min-width:180px;">
                        <option value="">Semua Kategori</option>
                        <?php foreach ($kategori_list as $k): ?>
                            <option value="<?= htmlspecialchars($k) ?>" <?= $filter_kategori === $k ? 'selected' : '' ?>>
                                <?= htmlspecialchars($k) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>
                <div class="search-box-container">
                    <i class="bi bi-search"></i>
                    <input type="text" class="search-box" placeholder="Cari dokumen..." data-table-search="tabelDigital"
                        onkeyup="handleTableSearch('tabelDigital')">
                </div>
                <button class="btn-primary-custom" onclick="openModal('modalUploadDok')">
                    <i class="bi bi-upload"></i>Unggah Dokumen
                </button>
            </div>
        </div>

        <div class="table-responsive-custom">
            <table class="table-custom" id="tabelDigital">
                <thead>
                    <tr>
                        <th>Nama Dokumen</th>
                        <th>Kategori</th>
                        <th>Sumber</th>
                        <th>Klien Terkait</th>
                        <th>Visibilitas</th>
                        <th>Diunggah Oleh</th>
                        <th>Tanggal</th>
                        <th style="text-align:center;">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($dokumen) === 0): ?>
                        <tr>
                            <td colspan="8" class="text-center py-4 text-muted">
                                <i class="bi bi-folder-x d-block mb-2" style="font-size:2rem;"></i>
                                Belum ada dokumen tersimpan.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($dokumen as $d): ?>
                            <?php
                                $hrefDok = href_dokumen($d['file_path']);
                                $infoModul = arp_info_modul_dokumen($d['modul_sumber']);
                                $linkAsal = arp_link_sumber_dokumen($d['modul_sumber'], $d['ref_id'] ? (int) $d['ref_id'] : null, $ROLE_AKTIF);
                            ?>
                            <tr>
                                <td class="fw-bold"><?= htmlspecialchars($d['nama_dokumen']) ?></td>
                                <td><span class="badge-warning"><?= htmlspecialchars($d['kategori']) ?></span></td>
                                <td>
                                    <span class="fs-7 d-inline-flex align-items-center gap-1">
                                        <i class="bi <?= htmlspecialchars($infoModul['icon']) ?>"></i>
                                        <?= htmlspecialchars($infoModul['label']) ?>
                                    </span>
                                </td>
                                <td><?= $d['nama_perusahaan'] ? htmlspecialchars($d['nama_perusahaan']) : '-' ?></td>
                                <td>
                                    <?php
                                    $visBadge = 'badge-warning';
                                    if ($d['visibilitas'] === 'Publik')
                                        $visBadge = 'badge-success';
                                    if ($d['visibilitas'] === 'Client')
                                        $visBadge = 'badge-danger';
                                    ?>
                                    <span class="<?= $visBadge ?>"><?= htmlspecialchars($d['visibilitas']) ?></span>
                                </td>
                                <td><?= htmlspecialchars($d['nama_lengkap']) ?></td>
                                <td><?= date('d-m-Y', strtotime($d['created_at'])) ?></td>
                                <td style="text-align:center;">
                                    <a href="<?= htmlspecialchars($hrefDok) ?>" target="_blank"
                                        class="btn btn-outline-secondary btn-sm py-1" style="font-size:0.75rem;" title="Lihat">
                                        <i class="bi bi-eye-fill"></i>
                                    </a>
                                    <?php if ($linkAsal): ?>
                                        <a href="<?= htmlspecialchars($linkAsal) ?>"
                                            class="btn btn-outline-secondary btn-sm py-1" style="font-size:0.75rem;" title="Buka Halaman Asal">
                                            <i class="bi bi-box-arrow-up-right"></i>
                                        </a>
                                    <?php endif; ?>
                                    <form method="POST" action="digital.php" style="display:inline-block;"
                                        onsubmit="return confirm('Yakin ingin menghapus dokumen ini?');">
                                        <input type="hidden" name="action" value="hapus">
                                        <input type="hidden" name="doc_id" value="<?= $d['id'] ?>">
                                        <button type="submit" class="btn-danger-custom"
                                            style="height:28px; padding:0 8px; font-size:0.75rem;" title="Hapus">
                                            <i class="bi bi-trash-fill"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="pagination-custom" id="pagination-tabelDigital"></div>
    </div>
</main>

<!-- Modal Upload Dokumen -->
<div class="arp-modal-overlay" id="modalUploadDok" onclick="closeModalOutside(event, 'modalUploadDok')">
    <div class="arp-modal-box" style="max-width:500px;">
        <div class="arp-modal-header">
            <div>
                <h5 class="fw-bold mb-0">Unggah Dokumen Digital</h5>
                <small class="text-muted">Simpan dokumen ke repository server</small>
            </div>
            <button class="arp-modal-close" onclick="closeModal('modalUploadDok')">&times;</button>
        </div>
        <div class="arp-modal-body">
            <form method="POST" action="digital.php" enctype="multipart/form-data">
                <input type="hidden" name="action" value="upload">
                <div class="mb-3">
                    <label class="form-label fw-semibold mb-2">Nama Dokumen *</label>
                    <input type="text" name="nama_dokumen" class="form-control-custom" required>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold mb-2">Kategori *</label>
                    <select name="kategori" class="select-custom" required>
                        <?php foreach ($kategori_list as $k): ?>
                            <option value="<?= htmlspecialchars($k) ?>"><?= htmlspecialchars($k) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold mb-2">Klien Terkait (Opsional)</label>
                    <select name="klien_id" class="select-custom">
                        <option value="">-- Tidak Terkait Klien --</option>
                        <?php foreach ($klienList as $k): ?>
                            <option value="<?= $k['id'] ?>"><?= htmlspecialchars($k['nama_perusahaan']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold mb-2">Visibilitas *</label>
                    <select name="visibilitas" class="select-custom" required>
                        <option value="Internal">Internal</option>
                        <option value="Client">Client</option>
                        <option value="Publik">Publik</option>
                    </select>
                </div>
                <div class="mb-4">
                    <label class="form-label fw-semibold mb-2">Berkas *</label>
                    <div class="upload-dropzone" id="dropzoneFilePathIt">
                        <div class="upload-dropzone-icon"><i class="bi bi-cloud-arrow-up"></i></div>
                        <div>
                            <span class="fw-semibold" style="color: var(--primary);">Tarik &amp; lepas file di sini</span>
                            atau <span class="fw-semibold text-decoration-underline">Pilih File</span>
                        </div>
                        <span class="fs-7 text-muted">Format: PDF, JPG, PNG, DOC, XLS</span>
                        <input type="file" name="file_path" id="inputFilePathIt" class="d-none"
                            accept=".pdf,.jpg,.jpeg,.png,.doc,.docx,.xls,.xlsx" required>
                        <div class="upload-dropzone-filelist" id="fileListFilePathIt"></div>
                    </div>
                </div>
                <div class="d-flex gap-2 justify-content-end">
                    <button type="button" class="btn-secondary-custom"
                        onclick="closeModal('modalUploadDok')">Batal</button>
                    <button type="submit" class="btn-primary-custom">Unggah Dokumen</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        initTablePagination('tabelDigital', 10);
    });
</script>

<?php if ($error_msg): ?>
    <script>document.addEventListener('DOMContentLoaded', () => openModal('modalUploadDok'));</script>
<?php endif; ?>

<?php include "../includes/footer.php"; ?>