<?php
// direksi/digital.php
session_start();
require_once "../config/koneksi.php";

$page_title = "Dokumen Digital";

const KATEGORI_DOKUMEN = ['Suket K3', 'Sertifikat Ahli', 'Legal Perusahaan', 'Kontrak Klien', 'Laporan', 'Lainnya'];

function safe_count(PDO $conn, string $sql, array $params = []): int
{
    try {
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    } catch (PDOException $e) {
        return 0;
    }
}

$total_dokumen = safe_count($conn, "SELECT COUNT(*) FROM Dokumen_Digital");
$total_suket   = safe_count($conn, "SELECT COUNT(*) FROM Dokumen_Digital WHERE kategori = 'Suket K3'");
$total_legal   = safe_count($conn, "SELECT COUNT(*) FROM Dokumen_Digital WHERE kategori = 'Legal Perusahaan'");
$total_kontrak = safe_count($conn, "SELECT COUNT(*) FROM Dokumen_Digital WHERE kategori = 'Kontrak Klien'");

$kategori_filter = $_GET['kategori'] ?? 'Semua';
$valid_kategori = array_merge(['Semua'], KATEGORI_DOKUMEN);
if (!in_array($kategori_filter, $valid_kategori, true)) {
    $kategori_filter = 'Semua';
}

$keyword = trim($_GET['q'] ?? '');

$daftar_dokumen = [];
try {
    $sql = "
        SELECT dd.*, dk.nama_perusahaan, u.nama_lengkap AS nama_pengunggah
        FROM Dokumen_Digital dd
        LEFT JOIN Data_Klien dk ON dd.klien_id = dk.id
        LEFT JOIN Users u ON dd.diupload_oleh = u.id
        WHERE 1=1
    ";
    $params = [];
    if ($kategori_filter !== 'Semua') {
        $sql .= " AND dd.kategori = :kategori ";
        $params[':kategori'] = $kategori_filter;
    }
    if ($keyword !== '') {
        $sql .= " AND dd.nama_dokumen LIKE :kw ";
        $params[':kw'] = '%' . $keyword . '%';
    }
    $sql .= " ORDER BY dd.created_at DESC LIMIT 200";

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $daftar_dokumen = $stmt->fetchAll();
} catch (PDOException $e) {
    $daftar_dokumen = [];
}

function badge_visibilitas(string $v): string
{
    switch ($v) {
        case 'Internal': return 'badge-secondary';
        case 'Client':   return 'badge-info';
        case 'Publik':   return 'badge-success';
        default:         return 'badge-secondary';
    }
}

include "../includes/header.php";
include "../includes/sidebar.php";
include "../includes/topbar.php";
?>

<main class="main-content">
    <div class="row g-4 mb-4">
        <div class="col-xl-3 col-md-6 col-12">
            <div class="stat-card">
                <div class="stat-card-info">
                    <span class="stat-card-title">Total Dokumen</span>
                    <span class="stat-card-value"><?= $total_dokumen ?></span>
                </div>
                <div class="stat-card-icon"><i class="bi bi-file-earmark-text-fill"></i></div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6 col-12">
            <div class="stat-card">
                <div class="stat-card-info">
                    <span class="stat-card-title">Suket K3</span>
                    <span class="stat-card-value"><?= $total_suket ?></span>
                </div>
                <div class="stat-card-icon success"><i class="bi bi-file-earmark-medical"></i></div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6 col-12">
            <div class="stat-card">
                <div class="stat-card-info">
                    <span class="stat-card-title">Legal Perusahaan</span>
                    <span class="stat-card-value"><?= $total_legal ?></span>
                </div>
                <div class="stat-card-icon warning"><i class="bi bi-bank"></i></div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6 col-12">
            <div class="stat-card">
                <div class="stat-card-info">
                    <span class="stat-card-title">Kontrak Client</span>
                    <span class="stat-card-value"><?= $total_kontrak ?></span>
                </div>
                <div class="stat-card-icon"><i class="bi bi-file-earmark-ruled"></i></div>
            </div>
        </div>
    </div>

    <div class="card-box">
        <div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-3">
            <h5 class="mb-0 fw-bold">Arsip Dokumen Digital</h5>
            <form method="GET" class="d-flex gap-2 flex-wrap">
                <input type="text" class="form-control-custom" name="q" placeholder="Cari nama dokumen..." value="<?= htmlspecialchars($keyword) ?>" style="width:220px;">
                <select class="select-custom" name="kategori" style="width: 200px;" onchange="this.form.submit()">
                    <?php foreach ($valid_kategori as $opt): ?>
                        <option value="<?= htmlspecialchars($opt) ?>" <?= $kategori_filter === $opt ? 'selected' : '' ?>>
                            <?= $opt === 'Semua' ? 'Semua Kategori' : htmlspecialchars($opt) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn-primary-custom" style="height:38px;"><i class="bi bi-search"></i></button>
            </form>
        </div>

        <div class="table-responsive-custom">
            <table class="table-custom">
                <thead>
                    <tr>
                        <th>No</th>
                        <th>Nama Dokumen</th>
                        <th>Kategori</th>
                        <th>Client</th>
                        <th>Visibilitas</th>
                        <th>Diunggah Oleh</th>
                        <th>Tanggal</th>
                        <th style="text-align:center;">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($daftar_dokumen)): ?>
                        <tr><td colspan="8" class="text-center text-muted py-4">Tidak ada dokumen ditemukan.</td></tr>
                    <?php else: ?>
                        <?php foreach ($daftar_dokumen as $i => $d): ?>
                            <tr>
                                <td><?= $i + 1 ?></td>
                                <td><?= htmlspecialchars($d['nama_dokumen']) ?></td>
                                <td class="fs-7"><?= htmlspecialchars($d['kategori']) ?></td>
                                <td class="fs-7"><?= htmlspecialchars($d['nama_perusahaan'] ?? '-') ?></td>
                                <td><span class="<?= badge_visibilitas($d['visibilitas']) ?>"><?= htmlspecialchars($d['visibilitas']) ?></span></td>
                                <td class="fs-7"><?= htmlspecialchars($d['nama_pengunggah'] ?? '-') ?></td>
                                <td class="fs-7"><?= htmlspecialchars(date('d M Y', strtotime($d['created_at']))) ?></td>
                                <td style="text-align:center;">
                                    <a href="<?= $base_url . htmlspecialchars($d['file_path']) ?>" target="_blank" class="btn-secondary-custom" style="height:32px; padding:0 12px; font-size:0.8rem;">
                                        <i class="bi bi-eye"></i> Lihat
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>

<?php
include "../includes/footer.php";
?>
