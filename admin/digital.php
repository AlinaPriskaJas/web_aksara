<?php
// admin/digital.php -- Digital Sign / Arsip Dokumen Digital
session_start();

// Auth guard: pastikan sudah login dan role-nya memang admin
if (empty($_SESSION['login']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header("Location: ../login.php");
    exit;
}

require_once "../config/koneksi.php";
require_once "../includes/dokumen_helper.php";

$page_title = "Digital Sign - Arsip Dokumen Perusahaan";
$ROLE_AKTIF = 'admin'; // dipakai untuk membangun link balik ke halaman asal dokumen

// Kategori ENUM Dokumen_Digital.kategori
const KATEGORI_DOKUMEN = ['Suket K3', 'Sertifikat Ahli', 'Legal Perusahaan', 'Kontrak Klien', 'Laporan', 'Lainnya'];
const EXT_PRATINJAU = ['pdf', 'jpg', 'jpeg', 'png']; // format yang bisa dipratinjau & dicetak langsung via browser

// ================== FILTER ==================
$q               = trim($_GET['q'] ?? '');
$kategori_filter = $_GET['kategori'] ?? 'Semua';
$grup_filter     = $_GET['grup'] ?? 'Semua';

if (!in_array($kategori_filter, KATEGORI_DOKUMEN, true)) {
    $kategori_filter = 'Semua';
}

// ================== TAB DINAMIS BERDASARKAN DOKUMEN YANG BENAR-BENAR MASUK ==================
// Tab di halaman ini TIDAK di-hardcode lagi. Setiap kali modul baru (Surat, Sertifikat
// Ahli, Suket K3, dst) mengarsipkan dokumen lewat arp_arsipkan_dokumen(), nilai
// modul_sumber-nya otomatis dideteksi di sini dan langsung tampil sebagai tab baru.
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

if (!array_key_exists($grup_filter, $grup_map)) {
    $grup_filter = 'Semua';
}

// ================== STATISTIK ==================
$total_dokumen = 0;
$total_surat   = 0;
$total_suket   = 0;
$total_laporan = 0;
try {
    $total_dokumen = (int) $conn->query("SELECT COUNT(*) FROM Dokumen_Digital")->fetchColumn();

    $total_surat = (int) $conn->query("
        SELECT COUNT(*) FROM Dokumen_Digital
        WHERE modul_sumber IN ('Surat Keluar', 'Surat Masuk', 'Surat')
    ")->fetchColumn();

    $stmtSuket = $conn->prepare("SELECT COUNT(*) FROM Dokumen_Digital WHERE kategori = :k");
    $stmtSuket->execute([':k' => 'Suket K3']);
    $total_suket = (int) $stmtSuket->fetchColumn();

    $stmtLaporan = $conn->prepare("SELECT COUNT(*) FROM Dokumen_Digital WHERE kategori = :k");
    $stmtLaporan->execute([':k' => 'Laporan']);
    $total_laporan = (int) $stmtLaporan->fetchColumn();
} catch (PDOException $e) {
    // biarkan default 0 jika query gagal
}

// ================== DAFTAR DOKUMEN UNTUK DICETAK ==================
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
    if ($grup_filter !== 'Semua' && isset($grup_map[$grup_filter])) {
        $sql .= " AND dd.modul_sumber = :modul_sumber ";
        $params[':modul_sumber'] = $grup_map[$grup_filter];
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

function bisa_pratinjau(string $path): bool
{
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    return in_array($ext, EXT_PRATINJAU, true);
}

// File dari modul Surat/Sertifikat dkk sekarang berupa link Google Drive
// (bukan cuma path lokal), jadi jangan selalu ditempeli '../'.
function href_dokumen(string $path): string
{
    return (stripos($path, 'http') === 0) ? $path : '../' . $path;
}

include "../includes/header.php";
include "../includes/sidebar.php";
include "../includes/topbar.php";
?>

<main class="main-content">

    <!-- Ringkasan -->
    <div class="row g-4 mb-4">
        <div class="col-xl-3 col-md-6 col-12">
            <div class="stat-card">
                <div class="stat-card-info">
                    <span class="stat-card-title">Total Dokumen</span>
                    <span class="stat-card-value"><?= $total_dokumen ?></span>
                </div>
                <div class="stat-card-icon"><i class="bi bi-printer-fill"></i></div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6 col-12">
            <div class="stat-card">
                <div class="stat-card-info">
                    <span class="stat-card-title">Total Surat</span>
                    <span class="stat-card-value"><?= $total_surat ?></span>
                </div>
                <div class="stat-card-icon warning"><i class="bi bi-envelope-fill"></i></div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6 col-12">
            <div class="stat-card">
                <div class="stat-card-info">
                    <span class="stat-card-title">Total Suket K3</span>
                    <span class="stat-card-value"><?= $total_suket ?></span>
                </div>
                <div class="stat-card-icon success"><i class="bi bi-file-earmark-medical"></i></div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6 col-12">
            <div class="stat-card">
                <div class="stat-card-info">
                    <span class="stat-card-title">Total Laporan</span>
                    <span class="stat-card-value"><?= $total_laporan ?></span>
                </div>
                <div class="stat-card-icon"><i class="bi bi-clipboard-data-fill"></i></div>
            </div>
        </div>
    </div>

    <!-- Print Center -->
    <div class="card-box">
        <div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-3">
            <div>
                <h5 class="mb-1 fw-bold">Digital Center</h5>
                <p class="fs-7 text-muted mb-0">Cetak dokumen, laporan, sertifikat, dan surat perusahaan langsung dari Arsip Digital.</p>
            </div>
        </div>

        <!-- Tab Sumber Dokumen (otomatis mengikuti dokumen yang benar-benar masuk arsip) -->
        <div class="d-flex flex-wrap gap-2 mb-4">
            <?php
            $grup_list = array_merge(['Semua'], array_keys($grup_map));
            foreach ($grup_list as $g):
                $active = $grup_filter === $g;
                $urlParams = array_filter(['q' => $q, 'kategori' => $kategori_filter !== 'Semua' ? $kategori_filter : null, 'grup' => $g !== 'Semua' ? $g : null]);
                $iconGrup = $g === 'Semua' ? 'bi-grid-fill' : (arp_info_modul_dokumen($grup_map[$g])['icon'] ?? 'bi-file-earmark-fill');
                ?>
                <a href="digital.php?<?= htmlspecialchars(http_build_query($urlParams)) ?>"
                   class="<?= $active ? 'btn-primary-custom' : 'btn-secondary-custom' ?>"
                   style="height:36px; padding:0 16px; font-size:0.85rem; display:inline-flex; align-items:center; gap:6px;">
                    <i class="bi <?= $iconGrup ?>"></i> <?= htmlspecialchars($g) ?>
                </a>
            <?php endforeach; ?>
        </div>

        <form method="GET" class="row g-2 align-items-center mb-4">
            <input type="hidden" name="grup" value="<?= htmlspecialchars($grup_filter) ?>">
            <div class="col-lg-6 col-md-12">
                <div class="search-box-container" style="max-width:100%;">
                    <i class="bi bi-search"></i>
                    <input type="text" name="q" class="search-box" style="width:100%;" placeholder="Cari nama dokumen atau perusahaan..." value="<?= htmlspecialchars($q) ?>">
                </div>
            </div>
            <div class="col-lg-4 col-md-8 col-8">
                <select class="select-custom" name="kategori" onchange="this.form.submit()">
                    <option value="Semua" <?= $kategori_filter === 'Semua' ? 'selected' : '' ?>>Semua Kategori</option>
                    <?php foreach (KATEGORI_DOKUMEN as $k): ?>
                        <option value="<?= htmlspecialchars($k) ?>" <?= $kategori_filter === $k ? 'selected' : '' ?>><?= htmlspecialchars($k) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-lg-2 col-md-4 col-4">
                <button type="submit" class="btn-secondary-custom w-100"><i class="bi bi-funnel"></i> Filter</button>
            </div>
        </form>

        <div class="table-responsive-custom">
            <table class="table-custom">
                <thead>
                    <tr>
                        <th>No</th>
                        <th>Dokumen</th>
                        <th>Kategori</th>
                        <th>Sumber</th>
                        <th>Klien Terkait</th>
                        <th>Format</th>
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
                            <?php
                                $ext = strtoupper(pathinfo($d['file_path'], PATHINFO_EXTENSION));
                                $infoModul = arp_info_modul_dokumen($d['modul_sumber']);
                                $linkAsal = arp_link_sumber_dokumen($d['modul_sumber'], $d['ref_id'] ? (int) $d['ref_id'] : null, $ROLE_AKTIF);
                                $hrefDok = href_dokumen($d['file_path']);
                            ?>
                            <tr>
                                <td><?= $i + 1 ?></td>
                                <td>
                                    <div class="d-flex align-items-center gap-2">
                                        <i class="bi <?= icon_file_ext($d['file_path']) ?> fs-5" style="color: var(--primary);"></i>
                                        <div>
                                            <div class="fw-semibold"><?= htmlspecialchars($d['nama_dokumen']) ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td><span class="<?= badge_class_kategori($d['kategori']) ?>"><?= htmlspecialchars($d['kategori']) ?></span></td>
                                <td>
                                    <span class="fs-7 d-inline-flex align-items-center gap-1">
                                        <i class="bi <?= htmlspecialchars($infoModul['icon']) ?>"></i>
                                        <?= htmlspecialchars($infoModul['label']) ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if (!empty($d['nama_perusahaan'])): ?>
                                        <?= htmlspecialchars($d['nama_perusahaan']) ?>
                                        <div class="fs-7 text-muted"><?= htmlspecialchars($d['kode_klien']) ?></div>
                                    <?php else: ?>
                                        <span class="text-muted fs-7">-</span>
                                    <?php endif; ?>
                                </td>
                                <td><span class="badge-secondary"><?= htmlspecialchars($ext) ?></span></td>
                                <td><?= htmlspecialchars(date('d M Y', strtotime($d['created_at']))) ?></td>
                                <td style="text-align: center;">
                                    <div class="d-flex gap-2 justify-content-center">
                                        <a href="<?= htmlspecialchars($hrefDok) ?>" target="_blank" class="btn-secondary-custom" style="height:32px; padding:0 10px; font-size:0.8rem;" title="Lihat">
                                            <i class="bi bi-eye"></i>
                                        </a>
                                        <button type="button" class="btn-primary-custom" style="height:32px; padding:0 10px; font-size:0.8rem;" title="Cetak"
                                            onclick='openPrintModal(<?= json_encode([
                                                "nama_dokumen" => $d["nama_dokumen"],
                                                "kategori" => $d["kategori"],
                                                "file_path" => $hrefDok,
                                                "bisa_pratinjau" => bisa_pratinjau($d["file_path"]),
                                            ]) ?>)'>
                                            <i class="bi bi-printer"></i>
                                        </button>
                                        <a href="<?= htmlspecialchars($hrefDok) ?>" download class="btn-secondary-custom" style="height:32px; padding:0 10px; font-size:0.8rem;" title="Unduh">
                                            <i class="bi bi-download"></i>
                                        </a>
                                        <?php if ($linkAsal): ?>
                                            <a href="<?= htmlspecialchars($linkAsal) ?>" class="btn-secondary-custom" style="height:32px; padding:0 10px; font-size:0.8rem;" title="Buka Halaman Asal">
                                                <i class="bi bi-box-arrow-up-right"></i>
                                            </a>
                                        <?php endif; ?>
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

<!-- ===== MODAL: Pratinjau & Cetak Dokumen ===== -->
<div class="modal fade modal-custom" id="modalPrint" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h5 class="modal-title mb-0" id="printNamaDokumen">Cetak Dokumen</h5>
                    <span class="fs-7 text-muted" id="printKategoriDokumen">-</span>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div id="printPreviewWrapper" style="height:60vh; border:1px solid var(--border-color); border-radius:8px; overflow:hidden;">
                    <iframe id="printFrame" src="" style="width:100%; height:100%; border:0;"></iframe>
                </div>
                <div id="printFallback" class="alert alert-success-custom mb-0" style="display:none;">
                    <i class="bi bi-info-circle-fill fs-5"></i>
                    <div>
                        Pratinjau langsung tidak didukung untuk format file ini (Word/Excel). Unduh file terlebih dahulu,
                        lalu cetak melalui aplikasi Word/Excel/PDF Reader di perangkat Anda.
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <a href="#" id="printDownloadLink" download class="btn-secondary-custom">
                    <i class="bi bi-download me-1"></i> Unduh File
                </a>
                <button type="button" class="btn-primary-custom" id="printNowBtn" onclick="printCurrentDocument()">
                    <i class="bi bi-printer-fill me-1"></i> Cetak Sekarang
                </button>
            </div>
        </div>
    </div>
</div>

<script>
function openPrintModal(data) {
    document.getElementById('printNamaDokumen').textContent = data.nama_dokumen || 'Cetak Dokumen';
    document.getElementById('printKategoriDokumen').textContent = data.kategori || '-';
    document.getElementById('printDownloadLink').href = data.file_path;

    const frame = document.getElementById('printFrame');
    const wrapper = document.getElementById('printPreviewWrapper');
    const fallback = document.getElementById('printFallback');
    const printBtn = document.getElementById('printNowBtn');

    if (data.bisa_pratinjau) {
        frame.src = data.file_path;
        wrapper.style.display = 'block';
        fallback.style.display = 'none';
        printBtn.style.display = 'inline-flex';
    } else {
        frame.src = '';
        wrapper.style.display = 'none';
        fallback.style.display = 'flex';
        printBtn.style.display = 'none';
    }

    new bootstrap.Modal(document.getElementById('modalPrint')).show();
}

function printCurrentDocument() {
    const frame = document.getElementById('printFrame');
    try {
        frame.contentWindow.focus();
        frame.contentWindow.print();
    } catch (e) {
        // Jika gagal (mis. keterbatasan browser terhadap file PDF lintas asal), buka tab baru untuk mencetak manual
        window.open(frame.src, '_blank');
    }
}
</script>

<?php
include "../includes/footer.php";
?>