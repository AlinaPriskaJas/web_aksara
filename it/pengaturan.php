<?php
// it/pengaturan.php
require_once "../config/koneksi.php";

if (session_status() === PHP_SESSION_NONE)
    session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'it') {
    header("Location: ../login.php");
    exit;
}

$page_title = "Pengaturan Sistem";
include "../includes/header.php";
include "../includes/sidebar.php";
include "../includes/topbar.php";

$success_msg = "";
$error_msg = "";
$active_tab = 'tabPanelKonfigurasi';

// Buka tab tertentu jika diakses lewat query string, misal pengaturan.php?tab=backup
if (isset($_GET['tab'])) {
    $tab_map = [
        'konfigurasi' => 'tabPanelKonfigurasi',
        'keamanan' => 'tabPanelKeamanan',
        'backup' => 'tabPanelBackup',
    ];
    if (isset($tab_map[$_GET['tab']])) {
        $active_tab = $tab_map[$_GET['tab']];
    }
}

// ================= KONFIGURASI SISTEM =================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'tambah') {
        $active_tab = 'tabPanelKonfigurasi';
        $kunci = trim($_POST['kunci']);
        $nilai = trim($_POST['nilai']);
        $keterangan = trim($_POST['keterangan']);

        if (empty($kunci) || $nilai === '') {
            $error_msg = "Kunci dan Nilai konfigurasi wajib diisi!";
        } else {
            try {
                $cek = $conn->prepare("SELECT id FROM Pengaturan WHERE kunci = :kunci");
                $cek->execute(['kunci' => $kunci]);
                if ($cek->fetch()) {
                    $error_msg = "Kunci konfigurasi \"$kunci\" sudah ada. Gunakan fitur edit.";
                } else {
                    $stmt = $conn->prepare("INSERT INTO Pengaturan (kunci, nilai, keterangan) VALUES (:kunci, :nilai, :ket)");
                    $stmt->execute(['kunci' => $kunci, 'nilai' => $nilai, 'ket' => $keterangan]);
                    $success_msg = "Konfigurasi \"$kunci\" berhasil ditambahkan.";
                }
            } catch (PDOException $e) {
                $error_msg = "Gagal menambahkan konfigurasi: " . $e->getMessage();
            }
        }
    } elseif (isset($_POST['action']) && $_POST['action'] === 'edit') {
        $active_tab = 'tabPanelKonfigurasi';
        $id = $_POST['id'];
        $nilai = trim($_POST['nilai']);
        $keterangan = trim($_POST['keterangan']);
        try {
            $stmt = $conn->prepare("UPDATE Pengaturan SET nilai = :nilai, keterangan = :ket WHERE id = :id");
            $stmt->execute(['nilai' => $nilai, 'ket' => $keterangan, 'id' => $id]);
            $success_msg = "Konfigurasi berhasil diperbarui.";
        } catch (PDOException $e) {
            $error_msg = "Gagal memperbarui konfigurasi: " . $e->getMessage();
        }
    } elseif (isset($_POST['action']) && $_POST['action'] === 'hapus') {
        $active_tab = 'tabPanelKonfigurasi';
        $id = $_POST['id'];
        try {
            $stmt = $conn->prepare("DELETE FROM Pengaturan WHERE id = :id");
            $stmt->execute(['id' => $id]);
            $success_msg = "Konfigurasi berhasil dihapus.";
        } catch (PDOException $e) {
            $error_msg = "Gagal menghapus konfigurasi: " . $e->getMessage();
        }

        // ================= KEAMANAN =================
    } elseif (isset($_POST['action']) && $_POST['action'] === 'simpan_kebijakan') {
        $active_tab = 'tabPanelKeamanan';
    }
    // (logika simpan kebijakan keamanan diproses di bawah setelah $security_keys didefinisikan)

    // ================= BACKUP & RESTORE =================
    if (isset($_POST['action']) && $_POST['action'] === 'backup') {
        $active_tab = 'tabPanelBackup';
    } elseif (isset($_POST['action']) && $_POST['action'] === 'hapus_backup') {
        $active_tab = 'tabPanelBackup';
    } elseif (isset($_POST['action']) && $_POST['action'] === 'restore') {
        $active_tab = 'tabPanelBackup';
    }
}

$pengaturan = $conn->query("SELECT * FROM Pengaturan ORDER BY kunci ASC")->fetchAll();

// ================= DATA KEAMANAN =================
// Kunci konfigurasi keamanan yang dikelola di tabel Pengaturan
$security_keys = [
    'session_timeout_menit' => ['label' => 'Session Timeout (menit)', 'default' => '30'],
    'panjang_password_minimum' => ['label' => 'Panjang Password Minimum', 'default' => '6'],
    'maksimal_percobaan_login' => ['label' => 'Maksimal Percobaan Login Gagal', 'default' => '5'],
    'wajib_ganti_password_hari' => ['label' => 'Wajib Ganti Password (hari)', 'default' => '90'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'simpan_kebijakan') {
    try {
        foreach ($security_keys as $key => $meta) {
            $nilai = trim($_POST[$key] ?? $meta['default']);
            $cek = $conn->prepare("SELECT id FROM Pengaturan WHERE kunci = :kunci");
            $cek->execute(['kunci' => $key]);
            if ($cek->fetch()) {
                $upd = $conn->prepare("UPDATE Pengaturan SET nilai = :nilai WHERE kunci = :kunci");
                $upd->execute(['nilai' => $nilai, 'kunci' => $key]);
            } else {
                $ins = $conn->prepare("INSERT INTO Pengaturan (kunci, nilai, keterangan) VALUES (:kunci, :nilai, :ket)");
                $ins->execute(['kunci' => $key, 'nilai' => $nilai, 'ket' => $meta['label']]);
            }
        }
        $success_msg = "Kebijakan keamanan berhasil diperbarui.";
    } catch (PDOException $e) {
        $error_msg = "Gagal menyimpan kebijakan keamanan: " . $e->getMessage();
    }
}

// Ambil nilai kebijakan saat ini
$current_policy = [];
foreach ($security_keys as $key => $meta) {
    $current_policy[$key] = $meta['default'];
}
try {
    $rows = $conn->query("SELECT kunci, nilai FROM Pengaturan WHERE kunci IN ('" . implode("','", array_keys($security_keys)) . "')")->fetchAll();
    foreach ($rows as $r) {
        $current_policy[$r['kunci']] = $r['nilai'];
    }
} catch (PDOException $e) {
}

// Statistik akun berhak akses tinggi
$totalAdminIt = $conn->query("SELECT COUNT(*) FROM Users WHERE role IN ('admin','it')")->fetchColumn() ?: 0;
$totalUserAll = $conn->query("SELECT COUNT(*) FROM Users")->fetchColumn() ?: 0;

// Aktivitas login terbaru (dari Audit_Log)
$loginLogs = [];
try {
    $loginLogs = $conn->query("
        SELECT al.*, u.nama_lengkap, u.role
        FROM Audit_Log al
        JOIN Users u ON al.user_id = u.id
        WHERE al.aksi LIKE '%Login%' OR al.modul = 'Auth'
        ORDER BY al.waktu_kejadian DESC
        LIMIT 100
    ")->fetchAll();
} catch (PDOException $e) {
    $loginLogs = [];
}

// Daftar akun dengan hak akses tinggi
$highPrivUsers = $conn->query("SELECT nama_lengkap, email, role, last_login FROM Users WHERE role IN ('admin','it') ORDER BY role ASC")->fetchAll();

// ================= DATA BACKUP =================
$backup_dir = "../backups/";
$log_file = $backup_dir . "backup_log.json";
if (!is_dir($backup_dir))
    mkdir($backup_dir, 0777, true);

function loadBackupLog($log_file)
{
    if (!file_exists($log_file))
        return [];
    $data = json_decode(file_get_contents($log_file), true);
    return is_array($data) ? $data : [];
}

function saveBackupLog($log_file, $data)
{
    file_put_contents($log_file, json_encode($data, JSON_PRETTY_PRINT));
}

function generateBackupSQL($conn, $dbName)
{
    $sql = "-- Backup Database $dbName\n-- Dibuat otomatis: " . date('Y-m-d H:i:s') . "\n\n";
    $sql .= "SET FOREIGN_KEY_CHECKS=0;\n\n";

    $tables = $conn->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tables as $table) {
        $createRow = $conn->query("SHOW CREATE TABLE `$table`")->fetch();
        $sql .= "DROP TABLE IF EXISTS `$table`;\n";
        $sql .= $createRow['Create Table'] . ";\n\n";

        $rows = $conn->query("SELECT * FROM `$table`")->fetchAll();
        foreach ($rows as $row) {
            $cols = array_map(fn($c) => "`$c`", array_keys($row));
            $vals = array_map(function ($v) use ($conn) {
                if ($v === null)
                    return "NULL";
                return $conn->quote($v);
            }, array_values($row));
            $sql .= "INSERT INTO `$table` (" . implode(',', $cols) . ") VALUES (" . implode(',', $vals) . ");\n";
        }
        $sql .= "\n";
    }
    $sql .= "SET FOREIGN_KEY_CHECKS=1;\n";
    return $sql;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // ===== Backup Sekarang =====
    if (isset($_POST['action']) && $_POST['action'] === 'backup') {
        try {
            $dbRow = $conn->query("SELECT DATABASE() AS db")->fetch();
            $sql = generateBackupSQL($conn, $dbRow['db']);
            $filename = "backup_" . date('Ymd_His') . ".sql";
            file_put_contents($backup_dir . $filename, $sql);

            $log = loadBackupLog($log_file);
            array_unshift($log, [
                'filename' => $filename,
                'ukuran' => filesize($backup_dir . $filename),
                'waktu' => date('Y-m-d H:i:s'),
                'dibuat_oleh' => $_SESSION['nama_lengkap'] ?? 'IT Support'
            ]);
            saveBackupLog($log_file, $log);

            $success_msg = "Backup database berhasil dibuat: $filename";
        } catch (Exception $e) {
            $error_msg = "Gagal membuat backup: " . $e->getMessage();
        }

        // ===== Hapus Backup =====
    } elseif (isset($_POST['action']) && $_POST['action'] === 'hapus_backup') {
        $filename = basename($_POST['filename']);
        $filepath = $backup_dir . $filename;
        if (file_exists($filepath)) {
            unlink($filepath);
            $log = loadBackupLog($log_file);
            $log = array_values(array_filter($log, fn($l) => $l['filename'] !== $filename));
            saveBackupLog($log_file, $log);
            $success_msg = "Berkas backup \"$filename\" berhasil dihapus.";
        } else {
            $error_msg = "Berkas backup tidak ditemukan.";
        }

        // ===== Restore Database =====
    } elseif (isset($_POST['action']) && $_POST['action'] === 'restore') {
        if (!isset($_FILES['restore_file']) || $_FILES['restore_file']['error'] !== UPLOAD_ERR_OK) {
            $error_msg = "Silakan unggah berkas .sql yang valid untuk melakukan restore.";
        } else {
            $ext = strtolower(pathinfo($_FILES['restore_file']['name'], PATHINFO_EXTENSION));
            if ($ext !== 'sql') {
                $error_msg = "Berkas restore harus berformat .sql";
            } else {
                try {
                    $sqlContent = file_get_contents($_FILES['restore_file']['tmp_name']);
                    $statements = array_filter(array_map('trim', explode(";\n", $sqlContent)));
                    $conn->beginTransaction();
                    foreach ($statements as $stmt) {
                        if (empty($stmt) || strpos($stmt, '--') === 0)
                            continue;
                        $conn->exec($stmt);
                    }
                    $conn->commit();
                    $success_msg = "Restore database berhasil dijalankan.";
                } catch (Exception $e) {
                    if ($conn->inTransaction())
                        $conn->rollBack();
                    $error_msg = "Gagal melakukan restore: " . $e->getMessage();
                }
            }
        }
    }
}

$backupList = loadBackupLog($log_file);

// Sinkronkan dengan file fisik yang benar-benar ada
$backupList = array_values(array_filter($backupList, fn($b) => file_exists($backup_dir . $b['filename'])));

$lastBackup = count($backupList) > 0 ? $backupList[0] : null;
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

    <div class="arp-tab-group">
        <div class="arp-tab-nav">
            <button type="button" class="arp-tab-btn<?= $active_tab === 'tabPanelKonfigurasi' ? ' active' : '' ?>"
                data-tab-target="tabPanelKonfigurasi" onclick="switchTab('tabPanelKonfigurasi', this)">
                <i class="bi bi-gear me-1"></i> Konfigurasi Sistem
            </button>
            <button type="button" class="arp-tab-btn<?= $active_tab === 'tabPanelKeamanan' ? ' active' : '' ?>"
                data-tab-target="tabPanelKeamanan" onclick="switchTab('tabPanelKeamanan', this)">
                <i class="bi bi-shield-lock me-1"></i> Keamanan
            </button>
            <button type="button" class="arp-tab-btn<?= $active_tab === 'tabPanelBackup' ? ' active' : '' ?>"
                data-tab-target="tabPanelBackup" onclick="switchTab('tabPanelBackup', this)">
                <i class="bi bi-cloud-arrow-up me-1"></i> Backup & Restore
            </button>
        </div>

        <div class="row g-4">
            <!-- ================= TAB: KONFIGURASI SISTEM ================= -->
            <div class="col-12 arp-tab-panel" id="tabPanelKonfigurasi"
                <?= $active_tab === 'tabPanelKonfigurasi' ? '' : 'style="display:none;"' ?>>
                <div class="card-box">
                    <div class="table-toolbar">
                        <h5 class="table-toolbar-title fw-bold">Konfigurasi Sistem</h5>
                        <div class="table-toolbar-actions">
                            <div class="search-box-container">
                                <i class="bi bi-search"></i>
                                <input type="text" class="search-box" placeholder="Cari konfigurasi..."
                                    data-table-search="tabelPengaturan" onkeyup="handleTableSearch('tabelPengaturan')">
                            </div>
                            <button class="btn-primary-custom" onclick="openModal('modalTambahKonfig')">
                                <i class="bi bi-plus-lg"></i>Tambah Konfigurasi
                            </button>
                        </div>
                    </div>

                    <div class="table-responsive-custom">
                        <table class="table-custom" id="tabelPengaturan">
                            <thead>
                                <tr>
                                    <th>Kunci</th>
                                    <th>Nilai</th>
                                    <th>Keterangan</th>
                                    <th style="text-align:center;">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($pengaturan) === 0): ?>
                                    <tr>
                                        <td colspan="4" class="text-center py-4 text-muted">
                                            <i class="bi bi-gear d-block mb-2" style="font-size:2rem;"></i>
                                            Belum ada konfigurasi sistem yang tersimpan.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($pengaturan as $p): ?>
                                        <tr>
                                            <td class="fw-bold"><?= htmlspecialchars($p['kunci']) ?></td>
                                            <td><?= htmlspecialchars($p['nilai']) ?></td>
                                            <td><?= htmlspecialchars($p['keterangan'] ?: '-') ?></td>
                                            <td style="text-align:center;">
                                                <button class="btn btn-outline-secondary btn-sm py-1" style="font-size:0.75rem;"
                                                    onclick='bukaEditKonfig(<?= json_encode($p) ?>)'>
                                                    <i class="bi bi-pencil-square"></i>
                                                </button>
                                                <form method="POST" action="pengaturan.php" style="display:inline-block;"
                                                    onsubmit="return confirm('Yakin ingin menghapus konfigurasi ini?');">
                                                    <input type="hidden" name="action" value="hapus">
                                                    <input type="hidden" name="id" value="<?= $p['id'] ?>">
                                                    <button type="submit" class="btn-danger-custom"
                                                        style="height:28px; padding:0 8px; font-size:0.75rem;">
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
                    <div class="pagination-custom" id="pagination-tabelPengaturan"></div>
                </div>
            </div>

            <!-- ================= TAB: KEAMANAN ================= -->
            <div class="col-12 arp-tab-panel" id="tabPanelKeamanan"
                <?= $active_tab === 'tabPanelKeamanan' ? '' : 'style="display:none;"' ?>>

                <div class="row g-4 mb-4">
                    <div class="col-md-4 col-12">
                        <div class="stat-card">
                            <div class="stat-card-info">
                                <span class="stat-card-title">Status Keamanan</span>
                                <span class="stat-card-value">Aman</span>
                            </div>
                            <div class="stat-card-icon success"><i class="bi bi-shield-fill-check"></i></div>
                        </div>
                    </div>
                    <div class="col-md-4 col-12">
                        <div class="stat-card">
                            <div class="stat-card-info">
                                <span class="stat-card-title">Akun Hak Akses Tinggi</span>
                                <span class="stat-card-value"><?= $totalAdminIt ?></span>
                            </div>
                            <div class="stat-card-icon warning"><i class="bi bi-person-fill-lock"></i></div>
                        </div>
                    </div>
                    <div class="col-md-4 col-12">
                        <div class="stat-card">
                            <div class="stat-card-info">
                                <span class="stat-card-title">Total Akun Terdaftar</span>
                                <span class="stat-card-value"><?= $totalUserAll ?></span>
                            </div>
                            <div class="stat-card-icon"><i class="bi bi-people-fill"></i></div>
                        </div>
                    </div>
                </div>

                <div class="row g-4 mb-4">
                    <!-- Kebijakan Keamanan -->
                    <div class="col-lg-5 col-12">
                        <div class="card-box">
                            <h5 class="mb-3 fw-bold">Kebijakan Keamanan Sistem</h5>
                            <form method="POST" action="pengaturan.php">
                                <input type="hidden" name="action" value="simpan_kebijakan">
                                <?php foreach ($security_keys as $key => $meta): ?>
                                    <div class="mb-3">
                                        <label class="form-label fw-semibold mb-2"><?= htmlspecialchars($meta['label']) ?></label>
                                        <input type="number" name="<?= $key ?>" class="form-control-custom"
                                            value="<?= htmlspecialchars($current_policy[$key]) ?>" min="1">
                                    </div>
                                <?php endforeach; ?>
                                <button type="submit" class="btn-primary-custom w-100">
                                    <i class="bi bi-shield-check"></i> Simpan Kebijakan
                                </button>
                            </form>
                        </div>
                    </div>

                    <!-- Akun Hak Akses Tinggi -->
                    <div class="col-lg-7 col-12">
                        <div class="card-box">
                            <h5 class="mb-3 fw-bold">Akun dengan Hak Akses Tinggi</h5>
                            <div class="table-responsive-custom">
                                <table class="table-custom">
                                    <thead>
                                        <tr>
                                            <th>Nama</th>
                                            <th>Email</th>
                                            <th>Role</th>
                                            <th>Login Terakhir</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (count($highPrivUsers) === 0): ?>
                                            <tr>
                                                <td colspan="4" class="text-center py-3 text-muted">Tidak ada data.</td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($highPrivUsers as $u): ?>
                                                <tr>
                                                    <td class="fw-bold"><?= htmlspecialchars($u['nama_lengkap']) ?></td>
                                                    <td><?= htmlspecialchars($u['email']) ?></td>
                                                    <td><span class="badge-warning" style="text-transform:capitalize;">
                                                            <?= htmlspecialchars($u['role']) ?>
                                                        </span></td>
                                                    <td><?= $u['last_login'] ? date('d-m-Y H:i', strtotime($u['last_login'])) : '-' ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Aktivitas Login Terbaru -->
                <div class="card-box">
                    <div class="table-toolbar">
                        <h5 class="table-toolbar-title fw-bold">Aktivitas Login & Autentikasi Terbaru</h5>
                        <div class="table-toolbar-actions">
                            <div class="search-box-container">
                                <i class="bi bi-search"></i>
                                <input type="text" class="search-box" placeholder="Cari aktivitas..." data-table-search="tabelLogin"
                                    onkeyup="handleTableSearch('tabelLogin')">
                            </div>
                        </div>
                    </div>
                    <div class="table-responsive-custom">
                        <table class="table-custom" id="tabelLogin">
                            <thead>
                                <tr>
                                    <th>Waktu</th>
                                    <th>Pengguna</th>
                                    <th>Role</th>
                                    <th>Aksi</th>
                                    <th>Detail</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($loginLogs) === 0): ?>
                                    <tr>
                                        <td colspan="5" class="text-center py-4 text-muted">
                                            <i class="bi bi-shield-slash d-block mb-2" style="font-size:2rem;"></i>
                                            Belum ada aktivitas login yang tercatat.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($loginLogs as $l): ?>
                                        <tr>
                                            <td><?= date('d-m-Y H:i:s', strtotime($l['waktu_kejadian'])) ?></td>
                                            <td><?= htmlspecialchars($l['nama_lengkap']) ?></td>
                                            <td style="text-transform:capitalize;"><?= htmlspecialchars($l['role']) ?></td>
                                            <td><?= htmlspecialchars($l['aksi']) ?></td>
                                            <td><?= htmlspecialchars($l['detail_perubahan'] ?: '-') ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="pagination-custom" id="pagination-tabelLogin"></div>
                </div>
            </div>

            <!-- ================= TAB: BACKUP & RESTORE ================= -->
            <div class="col-12 arp-tab-panel" id="tabPanelBackup"
                <?= $active_tab === 'tabPanelBackup' ? '' : 'style="display:none;"' ?>>

                <div class="row g-4 mb-4">
                    <div class="col-md-6 col-12">
                        <div class="stat-card">
                            <div class="stat-card-info">
                                <span class="stat-card-title">Total Berkas Backup</span>
                                <span class="stat-card-value"><?= count($backupList) ?></span>
                            </div>
                            <div class="stat-card-icon"><i class="bi bi-archive-fill"></i></div>
                        </div>
                    </div>
                    <div class="col-md-6 col-12">
                        <div class="stat-card">
                            <div class="stat-card-info">
                                <span class="stat-card-title">Backup Terakhir</span>
                                <span class="stat-card-value" style="font-size:1.1rem;">
                                    <?= $lastBackup ? date('d-m-Y H:i', strtotime($lastBackup['waktu'])) : 'Belum ada' ?>
                                </span>
                            </div>
                            <div class="stat-card-icon success"><i class="bi bi-clock-history"></i></div>
                        </div>
                    </div>
                </div>

                <div class="row g-4">
                    <div class="col-lg-8 col-12">
                        <div class="card-box">
                            <div class="table-toolbar">
                                <h5 class="table-toolbar-title fw-bold">Riwayat File Backup</h5>
                                <div class="table-toolbar-actions">
                                    <form method="POST" action="pengaturan.php" onsubmit="return confirm('Buat backup database sekarang?');">
                                        <input type="hidden" name="action" value="backup">
                                        <button type="submit" class="btn-primary-custom">
                                            <i class="bi bi-cloud-arrow-down-fill"></i> Backup Sekarang
                                        </button>
                                    </form>
                                </div>
                            </div>
                            <div class="table-responsive-custom">
                                <table class="table-custom" id="tabelBackup">
                                    <thead>
                                        <tr>
                                            <th>Nama File</th>
                                            <th>Ukuran</th>
                                            <th>Waktu</th>
                                            <th>Dibuat Oleh</th>
                                            <th style="text-align:center;">Aksi</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (count($backupList) === 0): ?>
                                            <tr>
                                                <td colspan="5" class="text-center py-4 text-muted">
                                                    <i class="bi bi-cloud-slash d-block mb-2" style="font-size:2rem;"></i>
                                                    Belum ada backup database.
                                                </td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($backupList as $b): ?>
                                                <tr>
                                                    <td class="fw-bold"><?= htmlspecialchars($b['filename']) ?></td>
                                                    <td><?= number_format($b['ukuran'] / 1024, 1) ?> KB</td>
                                                    <td><?= date('d-m-Y H:i', strtotime($b['waktu'])) ?></td>
                                                    <td><?= htmlspecialchars($b['dibuat_oleh']) ?></td>
                                                    <td style="text-align:center;">
                                                        <a href="../backups/<?= htmlspecialchars($b['filename']) ?>" download
                                                            class="btn btn-outline-secondary btn-sm py-1" style="font-size:0.75rem;">
                                                            <i class="bi bi-download"></i>
                                                        </a>
                                                        <form method="POST" action="pengaturan.php" style="display:inline-block;"
                                                            onsubmit="return confirm('Hapus berkas backup ini?');">
                                                            <input type="hidden" name="action" value="hapus_backup">
                                                            <input type="hidden" name="filename" value="<?= htmlspecialchars($b['filename']) ?>">
                                                            <button type="submit" class="btn-danger-custom"
                                                                style="height:28px; padding:0 8px; font-size:0.75rem;">
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
                            <div class="pagination-custom" id="pagination-tabelBackup"></div>
                        </div>
                    </div>

                    <div class="col-lg-4 col-12">
                        <div class="card-box">
                            <h5 class="mb-3 fw-bold">Pemulihan Database (Restore)</h5>
                            <div class="alert alert-danger-custom mb-3">
                                <i class="bi bi-exclamation-triangle-fill fs-5"></i>
                                <div>Restore akan menimpa data yang ada saat ini. Pastikan berkas .sql berasal dari sumber
                                    terpercaya.</div>
                            </div>
                            <form method="POST" action="pengaturan.php" enctype="multipart/form-data"
                                onsubmit="return confirm('Yakin ingin menjalankan restore? Tindakan ini tidak dapat dibatalkan.');">
                                <input type="hidden" name="action" value="restore">
                                <div class="mb-3">
                                    <label class="form-label fw-semibold mb-2">Unggah Berkas .sql *</label>
                                    <input type="file" name="restore_file" class="form-control-custom" style="padding-top:8px;"
                                        accept=".sql" required>
                                </div>
                                <button type="submit" class="btn-danger-custom w-100">
                                    <i class="bi bi-arrow-counterclockwise"></i> Jalankan Restore
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>

<!-- Modal Tambah Konfigurasi -->
<div class="arp-modal-overlay" id="modalTambahKonfig" onclick="closeModalOutside(event, 'modalTambahKonfig')">
    <div class="arp-modal-box" style="max-width:480px;">
        <div class="arp-modal-header">
            <div>
                <h5 class="fw-bold mb-0">Tambah Konfigurasi Sistem</h5>
                <small class="text-muted">Buat pasangan kunci-nilai konfigurasi baru</small>
            </div>
            <button class="arp-modal-close" onclick="closeModal('modalTambahKonfig')">&times;</button>
        </div>
        <div class="arp-modal-body">
            <form method="POST" action="pengaturan.php">
                <input type="hidden" name="action" value="tambah">
                <div class="mb-3">
                    <label class="form-label fw-semibold mb-2">Kunci *</label>
                    <input type="text" name="kunci" class="form-control-custom" placeholder="contoh: nama_perusahaan"
                        required>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold mb-2">Nilai *</label>
                    <input type="text" name="nilai" class="form-control-custom" required>
                </div>
                <div class="mb-4">
                    <label class="form-label fw-semibold mb-2">Keterangan</label>
                    <textarea name="keterangan" class="textarea-custom"
                        placeholder="Penjelasan singkat konfigurasi ini"></textarea>
                </div>
                <div class="d-flex gap-2 justify-content-end">
                    <button type="button" class="btn-secondary-custom"
                        onclick="closeModal('modalTambahKonfig')">Batal</button>
                    <button type="submit" class="btn-primary-custom">Simpan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Edit Konfigurasi -->
<div class="arp-modal-overlay" id="modalEditKonfig" onclick="closeModalOutside(event, 'modalEditKonfig')">
    <div class="arp-modal-box" style="max-width:480px;">
        <div class="arp-modal-header">
            <div>
                <h5 class="fw-bold mb-0">Edit Konfigurasi</h5>
                <small class="text-muted" id="editKonfigKunciLabel"></small>
            </div>
            <button class="arp-modal-close" onclick="closeModal('modalEditKonfig')">&times;</button>
        </div>
        <div class="arp-modal-body">
            <form method="POST" action="pengaturan.php">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="id" id="editKonfigId">
                <div class="mb-3">
                    <label class="form-label fw-semibold mb-2">Nilai *</label>
                    <input type="text" name="nilai" id="editKonfigNilai" class="form-control-custom" required>
                </div>
                <div class="mb-4">
                    <label class="form-label fw-semibold mb-2">Keterangan</label>
                    <textarea name="keterangan" id="editKonfigKeterangan" class="textarea-custom"></textarea>
                </div>
                <div class="d-flex gap-2 justify-content-end">
                    <button type="button" class="btn-secondary-custom"
                        onclick="closeModal('modalEditKonfig')">Batal</button>
                    <button type="submit" class="btn-primary-custom">Simpan Perubahan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        initTablePagination('tabelPengaturan', 10);
        initTablePagination('tabelLogin', 10);
        initTablePagination('tabelBackup', 10);
    });

    function bukaEditKonfig(p) {
        document.getElementById('editKonfigId').value = p.id;
        document.getElementById('editKonfigNilai').value = p.nilai;
        document.getElementById('editKonfigKeterangan').value = p.keterangan || '';
        document.getElementById('editKonfigKunciLabel').innerText = 'Kunci: ' + p.kunci;
        openModal('modalEditKonfig');
    }
</script>

<?php include "../includes/footer.php"; ?>
