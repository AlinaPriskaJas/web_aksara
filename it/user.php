<?php
// it/user.php
require_once "../config/koneksi.php";

if (session_status() === PHP_SESSION_NONE)
    session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'it') {
    header("Location: ../login.php");
    exit;
}

$page_title = "User Management";
include "../includes/header.php";
include "../includes/sidebar.php";
include "../includes/topbar.php";

$current_user_id = $_SESSION['user_id'];
$success_msg = "";
$error_msg = "";
$valid_roles = ['direksi', 'admin', 'it', 'client', 'ahli_k3'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // ===== Tambah User Baru =====
    if (isset($_POST['action']) && $_POST['action'] === 'tambah') {
        $nama_lengkap = trim($_POST['nama_lengkap']);
        $email = trim($_POST['email']);
        $role = $_POST['role'];
        $password = $_POST['password'];

        if (empty($nama_lengkap) || empty($email) || empty($password) || !in_array($role, $valid_roles)) {
            $error_msg = "Semua field wajib diisi dengan benar!";
        } else {
            try {
                $cek = $conn->prepare("SELECT id FROM Users WHERE email = :email");
                $cek->execute(['email' => $email]);
                if ($cek->fetch()) {
                    $error_msg = "Email sudah terdaftar, gunakan email lain.";
                } else {
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $conn->prepare("INSERT INTO Users (nama_lengkap, email, password, role) VALUES (:nama, :email, :pass, :role)");
                    $stmt->execute([
                        'nama' => $nama_lengkap,
                        'email' => $email,
                        'pass' => $hash,
                        'role' => $role
                    ]);
                    $success_msg = "Akun pengguna \"$nama_lengkap\" berhasil dibuat.";
                }
            } catch (PDOException $e) {
                $error_msg = "Gagal menambahkan pengguna: " . $e->getMessage();
            }
        }

        // ===== Edit User =====
    } elseif (isset($_POST['action']) && $_POST['action'] === 'edit') {
        $user_id = $_POST['user_id'];
        $nama_lengkap = trim($_POST['nama_lengkap']);
        $email = trim($_POST['email']);
        $role = $_POST['role'];

        if (empty($nama_lengkap) || empty($email) || !in_array($role, $valid_roles)) {
            $error_msg = "Semua field wajib diisi dengan benar!";
        } else {
            try {
                $stmt = $conn->prepare("UPDATE Users SET nama_lengkap = :nama, email = :email, role = :role WHERE id = :id");
                $stmt->execute([
                    'nama' => $nama_lengkap,
                    'email' => $email,
                    'role' => $role,
                    'id' => $user_id
                ]);
                $success_msg = "Data pengguna berhasil diperbarui.";
            } catch (PDOException $e) {
                $error_msg = "Gagal memperbarui pengguna: " . $e->getMessage();
            }
        }

        // ===== Reset Password =====
    } elseif (isset($_POST['action']) && $_POST['action'] === 'reset_password') {
        $user_id = $_POST['user_id'];
        $password_baru = $_POST['password_baru'];

        if (empty($password_baru) || strlen($password_baru) < 6) {
            $error_msg = "Password baru minimal 6 karakter!";
        } else {
            try {
                $hash = password_hash($password_baru, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("UPDATE Users SET password = :pass WHERE id = :id");
                $stmt->execute(['pass' => $hash, 'id' => $user_id]);
                $success_msg = "Password pengguna berhasil direset.";
            } catch (PDOException $e) {
                $error_msg = "Gagal mereset password: " . $e->getMessage();
            }
        }

        // ===== Hapus User =====
    } elseif (isset($_POST['action']) && $_POST['action'] === 'hapus') {
        $user_id = $_POST['user_id'];
        if ($user_id == $current_user_id) {
            $error_msg = "Anda tidak dapat menghapus akun Anda sendiri.";
        } else {
            try {
                $stmt = $conn->prepare("DELETE FROM Users WHERE id = :id");
                $stmt->execute(['id' => $user_id]);
                $success_msg = "Akun pengguna berhasil dihapus.";
            } catch (PDOException $e) {
                $error_msg = "Gagal menghapus pengguna. Kemungkinan data masih terhubung dengan data lain.";
            }
        }
    }
}

// Ambil daftar user
$users = $conn->query("SELECT * FROM Users ORDER BY created_at DESC")->fetchAll();
$totalUsers = count($users);
$totalByRole = [];
foreach ($valid_roles as $r) {
    $totalByRole[$r] = 0;
}
foreach ($users as $u) {
    $totalByRole[$u['role']] = ($totalByRole[$u['role']] ?? 0) + 1;
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

    <!-- Recap Cards -->
    <div class="row g-4 mb-4">
        <div class="col-xl-3 col-md-6 col-12">
            <div class="stat-card">
                <div class="stat-card-info">
                    <span class="stat-card-title">Total Akun Pengguna</span>
                    <span class="stat-card-value"><?= $totalUsers ?></span>
                </div>
                <div class="stat-card-icon"><i class="bi bi-people-fill"></i></div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6 col-12">
            <div class="stat-card">
                <div class="stat-card-info">
                    <span class="stat-card-title">Ahli K3</span>
                    <span class="stat-card-value"><?= $totalByRole['ahli_k3'] ?></span>
                </div>
                <div class="stat-card-icon success"><i class="bi bi-award-fill"></i></div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6 col-12">
            <div class="stat-card">
                <div class="stat-card-info">
                    <span class="stat-card-title">Admin & IT</span>
                    <span class="stat-card-value"><?= $totalByRole['admin'] + $totalByRole['it'] ?></span>
                </div>
                <div class="stat-card-icon warning"><i class="bi bi-person-gear"></i></div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6 col-12">
            <div class="stat-card">
                <div class="stat-card-info">
                    <span class="stat-card-title">Client</span>
                    <span class="stat-card-value"><?= $totalByRole['client'] ?></span>
                </div>
                <div class="stat-card-icon danger"><i class="bi bi-building"></i></div>
            </div>
        </div>
    </div>

    <!-- Data Table -->
    <div class="card-box">
        <div class="table-toolbar">
            <h5 class="table-toolbar-title fw-bold">Data User Sistem</h5>
            <div class="table-toolbar-actions">
                <div class="search-box-container">
                    <i class="bi bi-search"></i>
                    <input type="text" class="search-box" placeholder="Cari user..." data-table-search="tabelUser"
                        onkeyup="handleTableSearch('tabelUser')">
                </div>
                <button class="btn-primary-custom" onclick="openModal('modalTambahUser')">
                    <i class="bi bi-person-plus-fill"></i>Tambah User
                </button>
            </div>
        </div>

        <div class="table-responsive-custom">
            <table class="table-custom" id="tabelUser">
                <thead>
                    <tr>
                        <th>Nama Lengkap</th>
                        <th>Email</th>
                        <th>Role</th>
                        <th>Login Terakhir</th>
                        <th>Terdaftar</th>
                        <th style="text-align:center;">Tindakan</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($users) === 0): ?>
                        <tr>
                            <td colspan="6" class="text-center py-3 text-muted">Belum ada data pengguna.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($users as $u): ?>
                            <tr>
                                <td class="fw-bold"><?= htmlspecialchars($u['nama_lengkap']) ?></td>
                                <td><?= htmlspecialchars($u['email']) ?></td>
                                <td><span class="badge-warning" style="text-transform:capitalize;">
                                        <?= htmlspecialchars(str_replace('_', ' ', $u['role'])) ?>
                                    </span>
                                </td>
                                <td><?= $u['last_login'] ? date('d-m-Y H:i', strtotime($u['last_login'])) : '-' ?></td>
                                <td><?= date('d-m-Y', strtotime($u['created_at'])) ?></td>
                                <td style="text-align:center;">
                                    <button class="btn btn-outline-secondary btn-sm py-1" style="font-size:0.75rem;"
                                        onclick='bukaEditUser(<?= json_encode($u) ?>)'>
                                        <i class="bi bi-pencil-square"></i>
                                    </button>
                                    <button class="btn btn-outline-warning btn-sm py-1" style="font-size:0.75rem;"
                                        onclick="bukaResetPassword(<?= $u['id'] ?>, '<?= htmlspecialchars($u['nama_lengkap'], ENT_QUOTES) ?>')">
                                        <i class="bi bi-key-fill"></i>
                                    </button>
                                    <form method="POST" action="user.php" style="display:inline-block;"
                                        onsubmit="return confirm('Yakin ingin menghapus akun ini?');">
                                        <input type="hidden" name="action" value="hapus">
                                        <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
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
        <div class="pagination-custom" id="pagination-tabelUser"></div>
    </div>
</main>

<!-- Modal Tambah User -->
<div class="arp-modal-overlay" id="modalTambahUser" onclick="closeModalOutside(event, 'modalTambahUser')">
    <div class="arp-modal-box" style="max-width:500px;">
        <div class="arp-modal-header">
            <div>
                <h5 class="fw-bold mb-0">Tambah User Baru</h5>
                <small class="text-muted">Buat akun pengguna baru untuk sistem</small>
            </div>
            <button class="arp-modal-close" onclick="closeModal('modalTambahUser')">&times;</button>
        </div>
        <div class="arp-modal-body">
            <form method="POST" action="user.php">
                <input type="hidden" name="action" value="tambah">
                <div class="mb-3">
                    <label class="form-label fw-semibold mb-2">Nama Lengkap *</label>
                    <input type="text" name="nama_lengkap" class="form-control-custom" required>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold mb-2">Email *</label>
                    <input type="email" name="email" class="form-control-custom" required>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold mb-2">Role *</label>
                    <select name="role" class="select-custom" required>
                        <option value="admin">Admin</option>
                        <option value="it">IT</option>
                        <option value="direksi">Direksi</option>
                        <option value="ahli_k3">Ahli K3</option>
                        <option value="client">Client</option>
                    </select>
                </div>
                <div class="mb-4">
                    <label class="form-label fw-semibold mb-2">Password Awal *</label>
                    <input type="password" name="password" class="form-control-custom" minlength="6" required>
                </div>
                <div class="d-flex gap-2 justify-content-end">
                    <button type="button" class="btn-secondary-custom"
                        onclick="closeModal('modalTambahUser')">Batal</button>
                    <button type="submit" class="btn-primary-custom">Simpan User</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Edit User -->
<div class="arp-modal-overlay" id="modalEditUser" onclick="closeModalOutside(event, 'modalEditUser')">
    <div class="arp-modal-box" style="max-width:500px;">
        <div class="arp-modal-header">
            <div>
                <h5 class="fw-bold mb-0">Edit Data User</h5>
                <small class="text-muted">Perbarui informasi akun pengguna</small>
            </div>
            <button class="arp-modal-close" onclick="closeModal('modalEditUser')">&times;</button>
        </div>
        <div class="arp-modal-body">
            <form method="POST" action="user.php">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="user_id" id="editUserId">
                <div class="mb-3">
                    <label class="form-label fw-semibold mb-2">Nama Lengkap *</label>
                    <input type="text" name="nama_lengkap" id="editNamaLengkap" class="form-control-custom" required>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold mb-2">Email *</label>
                    <input type="email" name="email" id="editEmail" class="form-control-custom" required>
                </div>
                <div class="mb-4">
                    <label class="form-label fw-semibold mb-2">Role *</label>
                    <select name="role" id="editRole" class="select-custom" required>
                        <option value="admin">Admin</option>
                        <option value="it">IT</option>
                        <option value="direksi">Direksi</option>
                        <option value="ahli_k3">Ahli K3</option>
                        <option value="client">Client</option>
                    </select>
                </div>
                <div class="d-flex gap-2 justify-content-end">
                    <button type="button" class="btn-secondary-custom"
                        onclick="closeModal('modalEditUser')">Batal</button>
                    <button type="submit" class="btn-primary-custom">Simpan Perubahan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Reset Password -->
<div class="arp-modal-overlay" id="modalResetPassword" onclick="closeModalOutside(event, 'modalResetPassword')">
    <div class="arp-modal-box" style="max-width:450px;">
        <div class="arp-modal-header">
            <div>
                <h5 class="fw-bold mb-0">Reset Password</h5>
                <small class="text-muted" id="resetPasswordNamaUser"></small>
            </div>
            <button class="arp-modal-close" onclick="closeModal('modalResetPassword')">&times;</button>
        </div>
        <div class="arp-modal-body">
            <form method="POST" action="user.php">
                <input type="hidden" name="action" value="reset_password">
                <input type="hidden" name="user_id" id="resetUserId">
                <div class="mb-4">
                    <label class="form-label fw-semibold mb-2">Password Baru *</label>
                    <input type="password" name="password_baru" class="form-control-custom" minlength="6" required>
                    <small class="text-muted d-block mt-1">Minimal 6 karakter.</small>
                </div>
                <div class="d-flex gap-2 justify-content-end">
                    <button type="button" class="btn-secondary-custom"
                        onclick="closeModal('modalResetPassword')">Batal</button>
                    <button type="submit" class="btn-primary-custom">Reset Password</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        initTablePagination('tabelUser', 10);
    });

    function bukaEditUser(user) {
        document.getElementById('editUserId').value = user.id;
        document.getElementById('editNamaLengkap').value = user.nama_lengkap;
        document.getElementById('editEmail').value = user.email;
        document.getElementById('editRole').value = user.role;
        openModal('modalEditUser');
    }

    function bukaResetPassword(id, nama) {
        document.getElementById('resetUserId').value = id;
        document.getElementById('resetPasswordNamaUser').innerText = 'Akun: ' + nama;
        openModal('modalResetPassword');
    }
</script>

<?php include "../includes/footer.php"; ?>