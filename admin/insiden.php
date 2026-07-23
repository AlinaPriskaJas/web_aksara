<?php
// admin/insiden.php
session_start();
require_once "../config/koneksi.php";

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
                $upload_dir = "../uploads/insiden/";
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0755, true);
                }
                $filename = 'bukti_' . time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '', $_FILES['foto_bukti']['name']);
                if (move_uploaded_file($_FILES['foto_bukti']['tmp_name'], $upload_dir . $filename)) {
                    $foto_bukti = 'uploads/insiden/' . $filename;
                } else {
                    $flash = ['type' => 'danger', 'message' => 'Gagal mengunggah bukti foto/dokumen.'];
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
            <button type="button" class="btn-primary-custom" onclick="new bootstrap.Modal(document.getElementById('modalTambah')).show()">
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