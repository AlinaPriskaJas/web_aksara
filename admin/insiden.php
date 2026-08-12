<?php
// admin/insiden.php
session_start();
require_once "../config/koneksi.php";
require_once "../includes/drive_helper.php";

// TODO: ganti dengan user_id dari sesi login sebenarnya (proses_login.php belum tersambung penuh).
$admin_id = $_SESSION['user_id'] ?? 1;

$page_title = "Dashboard Insiden - Rekap Insiden K3";
$flash = null;

const KATEGORI_INSIDEN   = ['Kecelakaan Kerja', 'Nyaris Celaka (Near Miss)', 'Kebakaran', 'Kerusakan Alat', 'Pencemaran Lingkungan', 'Lainnya'];
const TINGKAT_KEPARAHAN  = ['Ringan', 'Sedang', 'Berat', 'Fatal'];
const STATUS_INSIDEN     = ['Baru', 'Investigasi', 'Tindak Lanjut', 'Selesai'];
const EXT_BUKTI_DIIZINKAN = ['jpg', 'jpeg', 'png', 'pdf'];
const MAX_UKURAN_BUKTI    = 5 * 1024 * 1024; // 5 MB

function buat_kode_insiden(PDO $conn): string
{
    $tanggal = date('Ymd');
    $stmt = $conn->prepare("SELECT COUNT(*) FROM Laporan_Insiden WHERE DATE(created_at) = CURDATE()");
    $stmt->execute();
    $urutan = ((int) $stmt->fetchColumn()) + 1;
    return 'INC-' . $tanggal . '-' . str_pad((string) $urutan, 4, '0', STR_PAD_LEFT);
}

// ================== PROSES: TAMBAH INSIDEN (INPUT MANUAL ADMIN) ==================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['aksi']) && $_POST['aksi'] === 'tambah') {
    $judul_insiden      = trim($_POST['judul_insiden'] ?? '');
    $klien_id           = (int) ($_POST['klien_id'] ?? 0);
    $lokasi             = trim($_POST['lokasi'] ?? '');
    $kategori_insiden   = $_POST['kategori_insiden'] ?? '';
    $tingkat_keparahan  = $_POST['tingkat_keparahan'] ?? '';
    $tanggal_kejadian   = $_POST['tanggal_kejadian'] ?? '';
    $deskripsi          = trim($_POST['deskripsi'] ?? '');
    $tindakan_awal      = trim($_POST['tindakan_awal'] ?? '');

    if ($judul_insiden === '' || $lokasi === '' || $deskripsi === '' ||
        !in_array($kategori_insiden, KATEGORI_INSIDEN, true) ||
        !in_array($tingkat_keparahan, TINGKAT_KEPARAHAN, true) ||
        $tanggal_kejadian === '') {
        $flash = ['type' => 'danger', 'message' => 'Judul, lokasi, kategori, tingkat keparahan, tanggal kejadian, dan deskripsi wajib diisi.'];
    } else {
        $foto_bukti = null;
        $upload_gagal = false;

        if (isset($_FILES['foto_bukti']) && $_FILES['foto_bukti']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['foto_bukti']['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, EXT_BUKTI_DIIZINKAN, true)) {
                $flash = ['type' => 'danger', 'message' => 'Format bukti harus JPG, PNG, atau PDF.'];
                $upload_gagal = true;
            } elseif ($_FILES['foto_bukti']['size'] > MAX_UKURAN_BUKTI) {
                $flash = ['type' => 'danger', 'message' => 'Ukuran file bukti maksimal 5 MB.'];
                $upload_gagal = true;
            } else {
                $hasil_drive = arp_upload_ke_drive($_FILES['foto_bukti']['tmp_name'], $_FILES['foto_bukti']['name'], $_FILES['foto_bukti']['type'], 0, 'Insiden');
                if ($hasil_drive && !empty($hasil_drive['link'])) {
                    $foto_bukti = $hasil_drive['link'];
                } else {
                    $flash = ['type' => 'danger', 'message' => 'Gagal mengunggah bukti foto/dokumen ke Drive.'];
                    $upload_gagal = true;
                }
            }
        }

        if (!$upload_gagal) {
            try {
                $kode_insiden = buat_kode_insiden($conn);
                $stmt = $conn->prepare("
                    INSERT INTO Laporan_Insiden
                        (kode_insiden, judul_insiden, klien_id, lokasi, kategori_insiden, tingkat_keparahan,
                         status, tanggal_kejadian, deskripsi, tindakan_awal, foto_bukti, dilaporkan_oleh)
                    VALUES
                        (:kode_insiden, :judul_insiden, :klien_id, :lokasi, :kategori_insiden, :tingkat_keparahan,
                         'Baru', :tanggal_kejadian, :deskripsi, :tindakan_awal, :foto_bukti, :dilaporkan_oleh)
                ");
                $stmt->execute([
                    ':kode_insiden'      => $kode_insiden,
                    ':judul_insiden'     => $judul_insiden,
                    ':klien_id'          => $klien_id > 0 ? $klien_id : null,
                    ':lokasi'            => $lokasi,
                    ':kategori_insiden'  => $kategori_insiden,
                    ':tingkat_keparahan' => $tingkat_keparahan,
                    ':tanggal_kejadian'  => $tanggal_kejadian,
                    ':deskripsi'         => $deskripsi,
                    ':tindakan_awal'     => $tindakan_awal !== '' ? $tindakan_awal : null,
                    ':foto_bukti'        => $foto_bukti,
                    ':dilaporkan_oleh'   => $admin_id,
                ]);
                $flash = ['type' => 'success', 'message' => "Insiden berhasil dicatat dengan kode $kode_insiden."];
            } catch (PDOException $e) {
                if ($foto_bukti && file_exists("../" . $foto_bukti)) {
                    @unlink("../" . $foto_bukti);
                }
                $flash = ['type' => 'danger', 'message' => 'Gagal menyimpan laporan insiden: ' . $e->getMessage()];
            }
        }
    }
    $_SESSION['insiden_flash'] = $flash;
    header("Location: insiden.php" . (!empty($_GET) ? '?' . http_build_query($_GET) : ''));
    exit;
}

// ================== PROSES: UPDATE STATUS / TINDAK LANJUT ==================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['aksi']) && $_POST['aksi'] === 'update_status') {
    $id                     = (int) ($_POST['id'] ?? 0);
    $status                 = $_POST['status'] ?? '';
    $catatan_tindak_lanjut  = trim($_POST['catatan_tindak_lanjut'] ?? '');

    if (!$id || !in_array($status, STATUS_INSIDEN, true)) {
        $flash = ['type' => 'danger', 'message' => 'Data status tidak valid.'];
    } else {
        try {
            $stmt = $conn->prepare("
                UPDATE Laporan_Insiden
                SET status = :status, catatan_tindak_lanjut = :catatan, ditangani_oleh = :ditangani_oleh
                WHERE id = :id
            ");
            $stmt->execute([
                ':status'         => $status,
                ':catatan'        => $catatan_tindak_lanjut !== '' ? $catatan_tindak_lanjut : null,
                ':ditangani_oleh' => $admin_id,
                ':id'             => $id,
            ]);
            $flash = ['type' => 'success', 'message' => 'Status insiden berhasil diperbarui.'];
        } catch (PDOException $e) {
            $flash = ['type' => 'danger', 'message' => 'Gagal memperbarui status: ' . $e->getMessage()];
        }
    }
    $_SESSION['insiden_flash'] = $flash;
    header("Location: insiden.php" . (!empty($_GET) ? '?' . http_build_query($_GET) : ''));
    exit;
}

// ================== PROSES: HAPUS INSIDEN ==================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['aksi']) && $_POST['aksi'] === 'hapus') {
    $id = (int) ($_POST['id'] ?? 0);
    if ($id) {
        try {
            $cek = $conn->prepare("SELECT foto_bukti FROM Laporan_Insiden WHERE id = :id");
            $cek->execute([':id' => $id]);
            $row = $cek->fetch();

            if ($row) {
                $stmt = $conn->prepare("DELETE FROM Laporan_Insiden WHERE id = :id");
                $stmt->execute([':id' => $id]);

                if (!empty($row['foto_bukti']) && file_exists("../" . $row['foto_bukti'])) {
                    @unlink("../" . $row['foto_bukti']);
                }
                $flash = ['type' => 'success', 'message' => 'Laporan insiden berhasil dihapus.'];
            } else {
                $flash = ['type' => 'danger', 'message' => 'Insiden tidak ditemukan.'];
            }
        } catch (PDOException $e) {
            $flash = ['type' => 'danger', 'message' => 'Gagal menghapus insiden: ' . $e->getMessage()];
        }
    }
    $_SESSION['insiden_flash'] = $flash;
    header("Location: insiden.php" . (!empty($_GET) ? '?' . http_build_query($_GET) : ''));
    exit;
}

$flash = $_SESSION['insiden_flash'] ?? $flash;
unset($_SESSION['insiden_flash']);

// ================== FILTER ==================
$q                 = trim($_GET['q'] ?? '');
$kategori_filter   = $_GET['kategori'] ?? 'Semua';
$keparahan_filter  = $_GET['keparahan'] ?? 'Semua';
$status_filter     = $_GET['status'] ?? 'Semua';

if (!in_array($kategori_filter, KATEGORI_INSIDEN, true))   $kategori_filter = 'Semua';
if (!in_array($keparahan_filter, TINGKAT_KEPARAHAN, true)) $keparahan_filter = 'Semua';
if (!in_array($status_filter, STATUS_INSIDEN, true))       $status_filter = 'Semua';

// ================== DAFTAR KLIEN (untuk dropdown) ==================
$daftar_klien = [];
try {
    $stmt = $conn->query("SELECT id, kode_klien, nama_perusahaan FROM Data_Klien ORDER BY nama_perusahaan ASC");
    $daftar_klien = $stmt->fetchAll();
} catch (PDOException $e) {
    $daftar_klien = [];
}

// ================== STATISTIK ==================
$total_insiden       = 0;
$insiden_bulan_ini   = 0;
$insiden_belum_selesai = 0;
$insiden_berat_fatal = 0;
$distribusi_status    = array_fill_keys(STATUS_INSIDEN, 0);
$distribusi_keparahan = array_fill_keys(TINGKAT_KEPARAHAN, 0);

try {
    $total_insiden = (int) $conn->query("SELECT COUNT(*) FROM Laporan_Insiden")->fetchColumn();

    $stmtBulan = $conn->query("SELECT COUNT(*) FROM Laporan_Insiden WHERE MONTH(tanggal_kejadian) = MONTH(CURDATE()) AND YEAR(tanggal_kejadian) = YEAR(CURDATE())");
    $insiden_bulan_ini = (int) $stmtBulan->fetchColumn();

    $stmtBelum = $conn->query("SELECT COUNT(*) FROM Laporan_Insiden WHERE status != 'Selesai'");
    $insiden_belum_selesai = (int) $stmtBelum->fetchColumn();

    $stmtBerat = $conn->query("SELECT COUNT(*) FROM Laporan_Insiden WHERE tingkat_keparahan IN ('Berat', 'Fatal')");
    $insiden_berat_fatal = (int) $stmtBerat->fetchColumn();

    $stmtStatus = $conn->query("SELECT status, COUNT(*) AS jumlah FROM Laporan_Insiden GROUP BY status");
    foreach ($stmtStatus->fetchAll() as $row) {
        if (isset($distribusi_status[$row['status']])) {
            $distribusi_status[$row['status']] = (int) $row['jumlah'];
        }
    }

    $stmtKeparahan = $conn->query("SELECT tingkat_keparahan, COUNT(*) AS jumlah FROM Laporan_Insiden GROUP BY tingkat_keparahan");
    foreach ($stmtKeparahan->fetchAll() as $row) {
        if (isset($distribusi_keparahan[$row['tingkat_keparahan']])) {
            $distribusi_keparahan[$row['tingkat_keparahan']] = (int) $row['jumlah'];
        }
    }
} catch (PDOException $e) {
    // biarkan default 0 jika query gagal / tabel belum tersedia
}

// ================== DAFTAR INSIDEN ==================
$daftar_insiden = [];
try {
    $sql = "
        SELECT li.*, dk.nama_perusahaan, dk.kode_klien,
               up.nama_lengkap AS nama_pelapor, ut.nama_lengkap AS nama_penanganan
        FROM Laporan_Insiden li
        LEFT JOIN Data_Klien dk ON li.klien_id = dk.id
        LEFT JOIN Users up ON li.dilaporkan_oleh = up.id
        LEFT JOIN Users ut ON li.ditangani_oleh = ut.id
        WHERE 1=1
    ";
    $params = [];

    if ($q !== '') {
        $sql .= " AND (li.judul_insiden LIKE :q OR li.kode_insiden LIKE :q OR li.lokasi LIKE :q OR dk.nama_perusahaan LIKE :q) ";
        $params[':q'] = '%' . $q . '%';
    }
    if ($kategori_filter !== 'Semua') {
        $sql .= " AND li.kategori_insiden = :kategori ";
        $params[':kategori'] = $kategori_filter;
    }
    if ($keparahan_filter !== 'Semua') {
        $sql .= " AND li.tingkat_keparahan = :keparahan ";
        $params[':keparahan'] = $keparahan_filter;
    }
    if ($status_filter !== 'Semua') {
        $sql .= " AND li.status = :status ";
        $params[':status'] = $status_filter;
    }
    $sql .= " ORDER BY li.tanggal_kejadian DESC, li.id DESC";

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $daftar_insiden = $stmt->fetchAll();
} catch (PDOException $e) {
    $daftar_insiden = [];
}

function badge_class_keparahan(string $keparahan): string
{
    switch ($keparahan) {
        case 'Ringan':
            return 'badge-success';
        case 'Sedang':
            return 'badge-warning';
        case 'Berat':
        case 'Fatal':
            return 'badge-danger';
        default:
            return 'badge-secondary';
    }
}

function badge_class_status(string $status): string
{
    switch ($status) {
        case 'Baru':
            return 'badge-secondary';
        case 'Investigasi':
            return 'badge-warning';
        case 'Tindak Lanjut':
            return 'badge-info';
        case 'Selesai':
            return 'badge-success';
        default:
            return 'badge-secondary';
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
                    <span class="stat-card-title">Total Insiden</span>
                    <span class="stat-card-value"><?= $total_insiden ?></span>
                </div>
                <div class="stat-card-icon"><i class="bi bi-clipboard2-pulse-fill"></i></div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6 col-12">
            <div class="stat-card">
                <div class="stat-card-info">
                    <span class="stat-card-title">Insiden Bulan Ini</span>
                    <span class="stat-card-value"><?= $insiden_bulan_ini ?></span>
                </div>
                <div class="stat-card-icon warning"><i class="bi bi-calendar-event-fill"></i></div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6 col-12">
            <div class="stat-card">
                <div class="stat-card-info">
                    <span class="stat-card-title">Belum Selesai</span>
                    <span class="stat-card-value"><?= $insiden_belum_selesai ?></span>
                </div>
                <div class="stat-card-icon warning"><i class="bi bi-hourglass-split"></i></div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6 col-12">
            <div class="stat-card">
                <div class="stat-card-info">
                    <span class="stat-card-title">Berat &amp; Fatal</span>
                    <span class="stat-card-value"><?= $insiden_berat_fatal ?></span>
                </div>
                <div class="stat-card-icon danger"><i class="bi bi-exclamation-octagon-fill"></i></div>
            </div>
        </div>
    </div>

    <!-- Dashboard Distribusi -->
    <div class="row g-4 mb-4">
        <div class="col-lg-6 col-12">
            <div class="card-box h-100">
                <h6 class="fw-bold mb-3">Distribusi Status Penanganan</h6>
                <?php foreach (STATUS_INSIDEN as $st):
                    $jumlah  = $distribusi_status[$st];
                    $persen  = $total_insiden > 0 ? round(($jumlah / $total_insiden) * 100) : 0;
                    $warna   = ['Baru' => 'var(--secondary)', 'Investigasi' => 'var(--warning)', 'Tindak Lanjut' => 'var(--primary)', 'Selesai' => 'var(--success)'][$st];
                    ?>
                    <div class="mb-3">
                        <div class="d-flex justify-content-between fs-7 mb-1">
                            <span class="fw-semibold"><?= htmlspecialchars($st) ?></span>
                            <span class="text-muted"><?= $jumlah ?> insiden (<?= $persen ?>%)</span>
                        </div>
                        <div style="height:8px; background:var(--bg-body); border-radius:4px; overflow:hidden;">
                            <div style="height:100%; width:<?= $persen ?>%; background:<?= $warna ?>; border-radius:4px;"></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="col-lg-6 col-12">
            <div class="card-box h-100">
                <h6 class="fw-bold mb-3">Distribusi Tingkat Keparahan</h6>
                <?php foreach (TINGKAT_KEPARAHAN as $kp):
                    $jumlah = $distribusi_keparahan[$kp];
                    $persen = $total_insiden > 0 ? round(($jumlah / $total_insiden) * 100) : 0;
                    $warna  = ['Ringan' => 'var(--success)', 'Sedang' => 'var(--warning)', 'Berat' => 'var(--danger)', 'Fatal' => 'var(--danger-hover)'][$kp];
                    ?>
                    <div class="mb-3">
                        <div class="d-flex justify-content-between fs-7 mb-1">
                            <span class="fw-semibold"><?= htmlspecialchars($kp) ?></span>
                            <span class="text-muted"><?= $jumlah ?> insiden (<?= $persen ?>%)</span>
                        </div>
                        <div style="height:8px; background:var(--bg-body); border-radius:4px; overflow:hidden;">
                            <div style="height:100%; width:<?= $persen ?>%; background:<?= $warna ?>; border-radius:4px;"></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Rekap Laporan Insiden -->
    <div class="card-box">
        <div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-3">
            <div>
                <h5 class="mb-1 fw-bold">Rekap Laporan Insiden K3</h5>
                <p class="fs-7 text-muted mb-0">Pantau seluruh insiden K3 di lapangan untuk keperluan evaluasi manajemen.</p>
            </div>
            <button type="button" class="btn-primary-custom" onclick="openModal('modalTambah')">
                <i class="bi bi-plus-circle-fill me-1"></i> Catat Insiden
            </button>
        </div>

        <form method="GET" class="row g-2 align-items-center mb-4">
            <div class="col-lg-4 col-md-12">
                <div class="search-box-container" style="max-width:100%;">
                    <i class="bi bi-search"></i>
                    <input type="text" name="q" class="search-box" style="width:100%;" placeholder="Cari kode, judul, lokasi, atau klien..." value="<?= htmlspecialchars($q) ?>">
                </div>
            </div>
            <div class="col-lg-3 col-md-4 col-6">
                <select class="select-custom" name="kategori" onchange="this.form.submit()">
                    <option value="Semua" <?= $kategori_filter === 'Semua' ? 'selected' : '' ?>>Semua Kategori</option>
                    <?php foreach (KATEGORI_INSIDEN as $k): ?>
                        <option value="<?= htmlspecialchars($k) ?>" <?= $kategori_filter === $k ? 'selected' : '' ?>><?= htmlspecialchars($k) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-lg-2 col-md-4 col-6">
                <select class="select-custom" name="keparahan" onchange="this.form.submit()">
                    <option value="Semua" <?= $keparahan_filter === 'Semua' ? 'selected' : '' ?>>Semua Keparahan</option>
                    <?php foreach (TINGKAT_KEPARAHAN as $kp): ?>
                        <option value="<?= htmlspecialchars($kp) ?>" <?= $keparahan_filter === $kp ? 'selected' : '' ?>><?= htmlspecialchars($kp) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-lg-2 col-md-4 col-6">
                <select class="select-custom" name="status" onchange="this.form.submit()">
                    <option value="Semua" <?= $status_filter === 'Semua' ? 'selected' : '' ?>>Semua Status</option>
                    <?php foreach (STATUS_INSIDEN as $st): ?>
                        <option value="<?= htmlspecialchars($st) ?>" <?= $status_filter === $st ? 'selected' : '' ?>><?= htmlspecialchars($st) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-lg-1 col-md-12 col-6">
                <button type="submit" class="btn-secondary-custom w-100"><i class="bi bi-funnel"></i></button>
            </div>
        </form>

        <div class="table-responsive-custom">
            <table class="table-custom">
                <thead>
                    <tr>
                        <th>No</th>
                        <th>Kode / Insiden</th>
                        <th>Kategori</th>
                        <th>Keparahan</th>
                        <th>Klien / Lokasi</th>
                        <th>Tgl. Kejadian</th>
                        <th>Status</th>
                        <th style="text-align: center;">Aksi</th>
                        </tr>
                </thead>
                <tbody>
                    <?php if (count($daftar_insiden) === 0): ?>
                        <tr>
                            <td colspan="8" class="text-center py-4 text-muted">
                                <i class="bi bi-shield-check d-block mb-2" style="font-size:2rem;"></i>
                                Belum ada laporan insiden yang tercatat.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($daftar_insiden as $i => $ins): ?>
                            <tr>
                                <td><?= $i + 1 ?></td>
                                <td>
                                    <div class="fw-bold"><?= htmlspecialchars($ins['kode_insiden']) ?></div>
                                    <small class="text-secondary"><?= htmlspecialchars($ins['judul_insiden']) ?></small>
                                </td>
                                <td><?= htmlspecialchars($ins['kategori_insiden']) ?></td>
                                <td><span class="<?= badge_class_keparahan($ins['tingkat_keparahan']) ?>"><?= htmlspecialchars($ins['tingkat_keparahan']) ?></span></td>
                                <td>
                                    <div class="fw-semibold"><?= htmlspecialchars($ins['nama_perusahaan'] ?? '-') ?></div>
                                    <small class="text-secondary"><?= htmlspecialchars($ins['lokasi']) ?></small>
                                </td>
                                <td><?= date('d-m-Y', strtotime($ins['tanggal_kejadian'])) ?></td>
                                <td><span class="<?= badge_class_status($ins['status']) ?>"><?= htmlspecialchars($ins['status']) ?></span></td>
                                <td style="text-align: center;">
                                    <div class="d-flex gap-1 justify-content-center">
                                        <button type="button" class="btn-secondary-custom" style="height:32px; padding:0 12px; font-size:0.8rem;"
                                            onclick='bukaModalDetail(<?= json_encode([
                                                "id" => $ins["id"],
                                                "kode_insiden" => $ins["kode_insiden"],
                                                "judul_insiden" => $ins["judul_insiden"],
                                                "nama_perusahaan" => $ins["nama_perusahaan"] ?? "-",
                                                "lokasi" => $ins["lokasi"],
                                                "kategori_insiden" => $ins["kategori_insiden"],
                                                "tingkat_keparahan" => $ins["tingkat_keparahan"],
                                                "tanggal_kejadian" => date("d-m-Y", strtotime($ins["tanggal_kejadian"])),
                                                "deskripsi" => $ins["deskripsi"],
                                                "tindakan_awal" => $ins["tindakan_awal"] ?? "",
                                                "catatan_tindak_lanjut" => $ins["catatan_tindak_lanjut"] ?? "",
                                                "status" => $ins["status"],
                                                "foto_bukti" => $ins["foto_bukti"] ?? "",
                                                "nama_pelapor" => $ins["nama_pelapor"] ?? "-",
                                                "nama_penanganan" => $ins["nama_penanganan"] ?? "-",
                                            ], JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                                            <i class="bi bi-eye"></i> Detail
                                        </button>
                                        <form method="POST" action="insiden.php" class="d-inline"
                                            onsubmit="return confirm('Hapus laporan insiden \'<?= htmlspecialchars(addslashes($ins['kode_insiden'])) ?>\'? Tindakan ini tidak bisa dibatalkan.');">
                                            <input type="hidden" name="aksi" value="hapus">
                                            <input type="hidden" name="id" value="<?= (int) $ins['id'] ?>">
                                            <button type="submit" class="btn-danger-custom" style="height:32px; padding:0 12px; font-size:0.8rem;">
                                                <i class="bi bi-trash-fill"></i>
                                            </button>
                                        </form>
                                    </div>
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

<!-- ===== MODAL: Catat Insiden Baru ===== -->
<div id="modalTambah" class="arp-modal-overlay" onclick="closeModalOutside(event, 'modalTambah')">
    <div class="arp-modal-box">
        <div class="arp-modal-header">
            <div>
                <h6 class="fw-bold mb-0">Catat Insiden K3 Baru</h6>
                <small class="text-muted">Input manual laporan insiden yang diterima admin.</small>
            </div>
            <button class="arp-modal-close" onclick="closeModal('modalTambah')">&times;</button>
        </div>
        <div class="arp-modal-body">
            <form method="POST" action="insiden.php" enctype="multipart/form-data">
                <input type="hidden" name="aksi" value="tambah">
                <div class="mb-3">
                    <label class="form-label fw-semibold fs-7 mb-2">Judul Insiden *</label>
                    <input type="text" name="judul_insiden" class="form-control-custom" required
                        placeholder="Contoh: Terpeleset di area produksi">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold fs-7 mb-2">Klien Terkait</label>
                    <select name="klien_id" class="select-custom">
                        <option value="0">-- Internal / Tanpa Klien --</option>
                        <?php foreach ($daftar_klien as $k): ?>
                            <option value="<?= $k['id'] ?>"><?= htmlspecialchars($k['nama_perusahaan']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold fs-7 mb-2">Lokasi Kejadian *</label>
                    <input type="text" name="lokasi" class="form-control-custom" required
                        placeholder="Contoh: Gudang B, Site Klien XYZ">
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <label class="form-label fw-semibold fs-7 mb-2">Kategori Insiden *</label>
                        <select name="kategori_insiden" class="select-custom" required>
                            <option value="">-- Pilih --</option>
                            <?php foreach (KATEGORI_INSIDEN as $k): ?>
                                <option value="<?= htmlspecialchars($k) ?>"><?= htmlspecialchars($k) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label fw-semibold fs-7 mb-2">Tingkat Keparahan *</label>
                        <select name="tingkat_keparahan" class="select-custom" required>
                            <option value="">-- Pilih --</option>
                            <?php foreach (TINGKAT_KEPARAHAN as $kp): ?>
                                <option value="<?= htmlspecialchars($kp) ?>"><?= htmlspecialchars($kp) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold fs-7 mb-2">Tanggal Kejadian *</label>
                    <input type="date" name="tanggal_kejadian" class="form-control-custom" required max="<?= date('Y-m-d') ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold fs-7 mb-2">Deskripsi Kejadian *</label>
                    <textarea name="deskripsi" class="textarea-custom" required
                        placeholder="Jelaskan kronologi kejadian secara lengkap..."></textarea>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold fs-7 mb-2">Tindakan Awal</label>
                    <textarea name="tindakan_awal" class="textarea-custom"
                        placeholder="Tindakan darurat yang sudah dilakukan (opsional)..."></textarea>
                </div>
                <div class="mb-4">
                    <label class="form-label fw-semibold fs-7 mb-2">Bukti Foto / Dokumen</label>
                    <input type="file" name="foto_bukti" class="form-control-custom" accept=".jpg,.jpeg,.png,.pdf">
                    <small class="text-muted">Format JPG, PNG, atau PDF. Maksimal 5 MB.</small>
                </div>
                <div class="d-flex gap-2">
                    <button type="button" class="btn-secondary-custom flex-grow-1" onclick="closeModal('modalTambah')">Batal</button>
                    <button type="submit" class="btn-primary-custom flex-grow-1"><i class="bi bi-send me-1"></i> Simpan Laporan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ===== MODAL: Detail & Update Status Insiden ===== -->
<div id="modalDetail" class="arp-modal-overlay" onclick="closeModalOutside(event, 'modalDetail')">
    <div class="arp-modal-box">
        <div class="arp-modal-header">
            <div>
                <h6 class="fw-bold mb-0" id="detailKode">-</h6>
                <small class="text-muted" id="detailJudul">-</small>
            </div>
            <button class="arp-modal-close" onclick="closeModal('modalDetail')">&times;</button>
        </div>
        <div class="arp-modal-body">
            <div class="row g-3 mb-3">
                <div class="col-6">
                    <div class="fs-7 text-muted mb-1">Klien / Lokasi</div>
                    <div class="fw-semibold" id="detailKlienLokasi">-</div>
                </div>
                <div class="col-6">
                    <div class="fs-7 text-muted mb-1">Tanggal Kejadian</div>
                    <div class="fw-semibold" id="detailTanggal">-</div>
                </div>
                <div class="col-6">
                    <div class="fs-7 text-muted mb-1">Kategori</div>
                    <div class="fw-semibold" id="detailKategori">-</div>
                </div>
                <div class="col-6">
                    <div class="fs-7 text-muted mb-1">Tingkat Keparahan</div>
                    <div class="fw-semibold" id="detailKeparahan">-</div>
                </div>
                <div class="col-6">
                    <div class="fs-7 text-muted mb-1">Dilaporkan Oleh</div>
                    <div class="fw-semibold" id="detailPelapor">-</div>
                </div>
                <div class="col-6">
                    <div class="fs-7 text-muted mb-1">Ditangani Oleh</div>
                    <div class="fw-semibold" id="detailPenanganan">-</div>
                </div>
            </div>
            <div class="mb-3">
                <div class="fs-7 text-muted mb-1">Deskripsi Kejadian</div>
                <div id="detailDeskripsi">-</div>
            </div>
            <div class="mb-3" id="detailTindakanWrap">
                <div class="fs-7 text-muted mb-1">Tindakan Awal</div>
                <div id="detailTindakan">-</div>
            </div>
            <div class="mb-3" id="detailFotoWrap" style="display:none;">
                <div class="fs-7 text-muted mb-1">Bukti Foto / Dokumen</div>
                <a href="#" id="detailFotoLink" target="_blank" class="btn-secondary-custom" style="display:inline-flex; width:auto;">
                    <i class="bi bi-paperclip me-1"></i> Lihat Lampiran
                </a>
            </div>

            <hr class="my-3">

            <form method="POST" action="insiden.php">
                <input type="hidden" name="aksi" value="update_status">
                <input type="hidden" name="id" id="updateId" value="">
                <div class="mb-3">
                    <label class="form-label fw-semibold fs-7 mb-2">Status Penanganan</label>
                    <select name="status" id="updateStatus" class="select-custom">
                        <?php foreach (STATUS_INSIDEN as $st): ?>
                            <option value="<?= htmlspecialchars($st) ?>"><?= htmlspecialchars($st) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-4">
                    <label class="form-label fw-semibold fs-7 mb-2">Catatan Tindak Lanjut</label>
                    <textarea name="catatan_tindak_lanjut" id="updateCatatan" class="textarea-custom"
                        placeholder="Catatan investigasi / tindak lanjut yang dilakukan..."></textarea>
                </div>
                <div class="d-flex gap-2">
                    <button type="button" class="btn-secondary-custom flex-grow-1" onclick="closeModal('modalDetail')">Tutup</button>
                    <button type="submit" class="btn-primary-custom flex-grow-1"><i class="bi bi-check2-circle me-1"></i> Perbarui Status</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        initTablePagination('tabelInsiden', 10);
    });

    function bukaModalDetail(data) {
        document.getElementById('detailKode').textContent = data.kode_insiden;
        document.getElementById('detailJudul').textContent = data.judul_insiden;
        document.getElementById('detailKlienLokasi').textContent = data.nama_perusahaan + ' - ' + data.lokasi;
        document.getElementById('detailTanggal').textContent = data.tanggal_kejadian;
        document.getElementById('detailKategori').textContent = data.kategori_insiden;
        document.getElementById('detailKeparahan').textContent = data.tingkat_keparahan;
        document.getElementById('detailPelapor').textContent = data.nama_pelapor;
        document.getElementById('detailPenanganan').textContent = data.nama_penanganan;
        document.getElementById('detailDeskripsi').textContent = data.deskripsi;

        const tindakanWrap = document.getElementById('detailTindakanWrap');
        if (data.tindakan_awal) {
            document.getElementById('detailTindakan').textContent = data.tindakan_awal;
            tindakanWrap.style.display = '';
        } else {
            tindakanWrap.style.display = 'none';
        }

        const fotoWrap = document.getElementById('detailFotoWrap');
        if (data.foto_bukti) {
            document.getElementById('detailFotoLink').href = data.foto_bukti.startsWith('http') ? data.foto_bukti : '../' + data.foto_bukti;
            fotoWrap.style.display = '';
        } else {
            fotoWrap.style.display = 'none';
        }

        document.getElementById('updateId').value = data.id;
        document.getElementById('updateStatus').value = data.status;
        document.getElementById('updateCatatan').value = data.catatan_tindak_lanjut || '';

        openModal('modalDetail');
    }
</script>

<?php include "../includes/footer.php"; ?>