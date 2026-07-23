<?php
// ahlik3/profile.php
require_once "../config/koneksi.php";

if (session_status() === PHP_SESSION_NONE)
    session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'it') {
    header("Location: ../login.php");
    exit;
}

$current_user_id = $_SESSION['user_id'];
$success_msg = "";
$error_msg = "";

// Fetch user profile
try {
    $stmt = $conn->prepare("SELECT * FROM Users WHERE id = :id LIMIT 1");
    $stmt->execute(['id' => $current_user_id]);
    $user = $stmt->fetch();
} catch (PDOException $e) {
    $user = null;
}

// Handle POST: edit profil, upload/hapus foto, atau ganti password
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'edit_profil') {
        $nama_lengkap = trim($_POST['nama_lengkap']);
        if (empty($nama_lengkap)) {
            $error_msg = "Nama lengkap tidak boleh kosong!";
        } elseif (strlen($nama_lengkap) < 3) {
            $error_msg = "Nama minimal 3 karakter!";
        } else {
            try {
                $upd = $conn->prepare("UPDATE Users SET nama_lengkap = :nama WHERE id = :id");
                $upd->execute(['nama' => $nama_lengkap, 'id' => $current_user_id]);
                $success_msg = "Profil berhasil diperbarui!";
                $_SESSION['nama_lengkap'] = $nama_lengkap;
                // Refresh
                $stmt->execute(['id' => $current_user_id]);
                $user = $stmt->fetch();
            } catch (PDOException $e) {
                $error_msg = "Gagal memperbarui profil: " . $e->getMessage();
            }
        }
    } elseif ($action === 'upload_foto') {
        if (!isset($_FILES['foto_profil']) || $_FILES['foto_profil']['error'] !== UPLOAD_ERR_OK) {
            $error_msg = "Gagal mengunggah foto. Silakan coba lagi.";
        } else {
            $file = $_FILES['foto_profil'];
            $allowed_ext = ['jpg', 'jpeg', 'png', 'webp'];
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $max_size = 2 * 1024 * 1024; // 2MB

            if (!in_array($ext, $allowed_ext)) {
                $error_msg = "Format foto tidak didukung. Gunakan JPG, PNG, atau WEBP.";
            } elseif ($file['size'] > $max_size) {
                $error_msg = "Ukuran foto maksimal 2MB.";
            } else {
                $upload_dir = "../uploads/profil/";
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0755, true);
                }
                $filename = $current_user_id . "_" . time() . "." . $ext;
                $target_path = $upload_dir . $filename;

                if (move_uploaded_file($file['tmp_name'], $target_path)) {
                    if (!empty($user['foto_profil']) && is_file("../" . $user['foto_profil'])) {
                        @unlink("../" . $user['foto_profil']);
                    }
                    $db_path = "uploads/profil/" . $filename;
                    try {
                        $upd = $conn->prepare("UPDATE Users SET foto_profil = :foto WHERE id = :id");
                        $upd->execute(['foto' => $db_path, 'id' => $current_user_id]);
                        $_SESSION['foto_profil'] = $db_path;
                        $success_msg = "Foto profil berhasil diperbarui!";
                        $stmt->execute(['id' => $current_user_id]);
                        $user = $stmt->fetch();
                    } catch (PDOException $e) {
                        $error_msg = "Gagal menyimpan foto ke database.";
                    }
                } else {
                    $error_msg = "Gagal menyimpan file foto di server.";
                }
            }
        }
    } elseif ($action === 'hapus_foto') {
        if (!empty($user['foto_profil'])) {
            if (is_file("../" . $user['foto_profil'])) {
                @unlink("../" . $user['foto_profil']);
            }
            try {
                $upd = $conn->prepare("UPDATE Users SET foto_profil = NULL WHERE id = :id");
                $upd->execute(['id' => $current_user_id]);
                $_SESSION['foto_profil'] = null;
                $success_msg = "Foto profil dihapus. Avatar kembali ke inisial nama.";
                $stmt->execute(['id' => $current_user_id]);
                $user = $stmt->fetch();
            } catch (PDOException $e) {
                $error_msg = "Gagal menghapus foto profil.";
            }
        }
    } elseif ($action === 'ganti_password') {
        $password_lama = $_POST['password_lama'];
        $password_baru = $_POST['password_baru'];
        $konfirmasi = $_POST['konfirmasi_baru'];

        if (empty($password_lama) || empty($password_baru)) {
            $error_msg = "Kedua kolom kata sandi wajib diisi!";
        } elseif (strlen($password_baru) < 6) {
            $error_msg = "Kata sandi baru minimal 6 karakter!";
        } elseif ($password_baru !== $konfirmasi) {
            $error_msg = "Konfirmasi kata sandi baru tidak cocok!";
        } elseif (!password_verify($password_lama, $user['password'])) {
            $error_msg = "Kata sandi lama salah!";
        } else {
            try {
                $hash = password_hash($password_baru, PASSWORD_BCRYPT);
                $upd = $conn->prepare("UPDATE Users SET password = :pwd WHERE id = :id");
                $upd->execute(['pwd' => $hash, 'id' => $current_user_id]);
                $success_msg = "Kata sandi berhasil diperbarui!";
                $stmt->execute(['id' => $current_user_id]);
                $user = $stmt->fetch();
            } catch (PDOException $e) {
                $error_msg = "Gagal memperbarui database: " . $e->getMessage();
            }
        }
    }
}

// Decode modal untuk auto-open saat error
$open_modal_on_error = '';
if ($error_msg && isset($_POST['action'])) {
    if ($_POST['action'] === 'edit_profil') {
        $open_modal_on_error = 'modalEditProfil';
    } elseif ($_POST['action'] === 'ganti_password') {
        $open_modal_on_error = 'modalGantiPassword';
    }
}

// ⬇️ Header/sidebar/topbar BARU di-include DI SINI,
//    setelah $_SESSION di atas sudah pasti ter-update duluan
$page_title = "Profil & Pengaturan Akun";
include "../includes/header.php";
include "../includes/sidebar.php";
include "../includes/topbar.php";
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

    <!-- Page Header & Action Bar -->
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
        <div class="d-flex gap-2">
            <button class="btn-primary-custom" onclick="openModal('modalEditProfil')">
                <i class="bi bi-pencil-square me-1"></i> Edit Profil
            </button>
            <button class="btn-secondary-custom" onclick="openModal('modalGantiPassword')">
                <i class="bi bi-shield-lock me-1"></i> Kata Sandi
            </button>
        </div>
    </div>

    <div class="row g-4">
        <!-- Kartu Profil (Tampilan Utama) -->
        <div class="col-lg-5 col-12">
            <div class="card-box text-center">
                <div class="profile-avatar-wrap">
                    <?= arp_avatar_html($user['nama_lengkap'], $user['foto_profil'] ?? null, '../', 110) ?>
                    <label class="avatar-camera-btn" for="inputFotoProfil" title="Ganti foto profil">
                        <i class="bi bi-camera-fill"></i>
                    </label>
                </div>
                <form id="formUploadFoto" method="POST" action="profile.php" enctype="multipart/form-data" class="d-none">
                    <input type="hidden" name="action" value="upload_foto">
                    <input type="file" id="inputFotoProfil" name="foto_profil" accept=".jpg,.jpeg,.png,.webp"
                        onchange="document.getElementById('formUploadFoto').submit();">
                </form>
                <?php if (!empty($user['foto_profil'])): ?>
                    <form method="POST" action="profile.php" class="mb-2"
                        onsubmit="return confirm('Hapus foto profil dan kembali ke avatar inisial?');">
                        <input type="hidden" name="action" value="hapus_foto">
                        <button type="submit" class="avatar-remove-link">Hapus Foto</button>
                    </form>
                <?php endif; ?>
                <h5 class="mb-1 fw-bold"><?= htmlspecialchars($user['nama_lengkap']) ?></h5>
                <span class="badge bg-secondary mb-3 d-block" style="width:fit-content; margin:auto;">Peran:
                    <?= htmlspecialchars($user['role']) ?></span>

                <div class="text-start mb-3 border-bottom pb-3">
                    <label class="text-muted" style="font-size:0.75rem; display:block;">Email Karyawan</label>
                    <span class="fw-semibold"><?= htmlspecialchars($user['email']) ?></span>
                </div>
                <div class="text-start mb-3 border-bottom pb-3">
                    <label class="text-muted" style="font-size:0.75rem; display:block;">Tanggal Bergabung</label>
                    <span><?= date('d-m-Y H:i', strtotime($user['created_at'])) ?> WIB</span>
                </div>
                <div class="text-start mb-4">
                    <label class="text-muted" style="font-size:0.75rem; display:block;">Terakhir Login</label>
                    <span><?= $user['last_login'] ? date('d-m-Y H:i', strtotime($user['last_login'])) . ' WIB' : '-' ?></span>
                </div>

            </div>
        </div>

        <!-- Panel Kanan: Info Akun Detail -->
        <div class="col-lg-7 col-12">
            <div class="card-box h-100">
                <div class="d-flex align-items-center justify-content-between mb-4">
                    <div>
                        <h5 class="fw-bold mb-1">Data Profil Akun </h5>
                    </div>
                </div>

                <div class="row g-3">
                    <div class="col-12">
                        <div class="p-3 rounded-3"
                            style="background: var(--bg-glass); border: 1px solid var(--border-color);">
                            <div class="d-flex align-items-center gap-3">
                                <div class="stat-card-icon"
                                    style="width:44px; height:44px; min-width:44px; font-size:1.1rem;">
                                    <i class="bi bi-person-fill"></i>
                                </div>
                                <div>
                                    <div class="text-muted" style="font-size:0.75rem;">Nama Lengkap</div>
                                    <div class="fw-bold"><?= htmlspecialchars($user['nama_lengkap']) ?></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-12">
                        <div class="p-3 rounded-3"
                            style="background: var(--bg-glass); border: 1px solid var(--border-color);">
                            <div class="d-flex align-items-center gap-3">
                                <div class="stat-card-icon"
                                    style="width:44px; height:44px; min-width:44px; font-size:1.1rem;">
                                    <i class="bi bi-envelope-fill"></i>
                                </div>
                                <div>
                                    <div class="text-muted" style="font-size:0.75rem;">Email Karyawan</div>
                                    <div class="fw-bold"><?= htmlspecialchars($user['email']) ?></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-12">
                        <div class="p-3 rounded-3"
                            style="background: var(--bg-glass); border: 1px solid var(--border-color);">
                            <div class="d-flex align-items-center gap-3">
                                <div class="stat-card-icon"
                                    style="width:44px; height:44px; min-width:44px; font-size:1.1rem;">
                                    <i class="bi bi-shield-fill-check"></i>
                                </div>
                                <div>
                                    <div class="text-muted" style="font-size:0.75rem;">Peran Akun</div>
                                    <div class="fw-bold"><?= htmlspecialchars($user['role']) ?></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-12">
                        <div class="p-3 rounded-3"
                            style="background: var(--bg-glass); border: 1px solid var(--border-color);">
                            <div class="d-flex align-items-center gap-3">
                                <div class="stat-card-icon success"
                                    style="width:44px; height:44px; min-width:44px; font-size:1.1rem;">
                                    <i class="bi bi-clock-history"></i>
                                </div>
                                <div>
                                    <div class="text-muted" style="font-size:0.75rem;">Login Terakhir</div>
                                    <div class="fw-bold">
                                        <?= $user['last_login'] ? date('d-m-Y H:i', strtotime($user['last_login'])) . ' WIB' : 'Belum ada data' ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>

<!-- ===== MODAL: Edit Profil ===== -->
<div id="modalEditProfil" class="arp-modal-overlay" onclick="closeModalOutside(event, 'modalEditProfil')">
    <div class="arp-modal-box">
        <div class="arp-modal-header">
            <div>
                <h6 class="fw-bold mb-0">Edit Profil</h6>
                <small class="text-muted">Perbarui informasi profil akun Anda.</small>
            </div>
            <button class="arp-modal-close" onclick="closeModal('modalEditProfil')">&times;</button>
        </div>
        <div class="arp-modal-body">
            <form method="POST" action="profile.php">
                <input type="hidden" name="action" value="edit_profil">
                <div class="mb-3">
                    <label class="form-label fw-semibold fs-7 mb-2">Nama Lengkap *</label>
                    <input type="text" name="nama_lengkap" class="form-control-custom"
                        value="<?= htmlspecialchars($user['nama_lengkap']) ?>" required>
                </div>
                <div class="mb-4">
                    <label class="form-label fw-semibold fs-7 mb-2">Email Karyawan</label>
                    <input type="email" class="form-control-custom" value="<?= htmlspecialchars($user['email']) ?>"
                        disabled style="opacity:0.6; cursor:not-allowed;">
                    <small class="text-muted d-block mt-1">Email tidak dapat diubah sendiri. Hubungi Admin.</small>
                </div>
                <div class="d-flex gap-2">
                    <button type="button" class="btn-secondary-custom flex-grow-1"
                        onclick="closeModal('modalEditProfil')">Batal</button>
                    <button type="submit" class="btn-primary-custom flex-grow-1"><i class="bi bi-save me-1"></i> Simpan
                        Perubahan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ===== MODAL: Ganti Kata Sandi ===== -->
<div id="modalGantiPassword" class="arp-modal-overlay" onclick="closeModalOutside(event, 'modalGantiPassword')">
    <div class="arp-modal-box">
        <div class="arp-modal-header">
            <div>
                <h6 class="fw-bold mb-0">Perbarui Kata Sandi</h6>
                <small class="text-muted">Pastikan kata sandi baru minimal 6 karakter.</small>
            </div>
            <button class="arp-modal-close" onclick="closeModal('modalGantiPassword')">&times;</button>
        </div>
        <div class="arp-modal-body">
            <form method="POST" action="profile.php">
                <input type="hidden" name="action" value="ganti_password">
                <div class="mb-3">
                    <label class="form-label fw-semibold fs-7 mb-2">Kata Sandi Lama *</label>
                    <input type="password" name="password_lama" class="form-control-custom" required
                        placeholder="Masukkan kata sandi saat ini">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold fs-7 mb-2">Kata Sandi Baru *</label>
                    <input type="password" name="password_baru" class="form-control-custom" required
                        placeholder="Kata sandi baru (min. 6 karakter)">
                </div>
                <div class="mb-4">
                    <label class="form-label fw-semibold fs-7 mb-2">Konfirmasi Kata Sandi Baru *</label>
                    <input type="password" name="konfirmasi_baru" class="form-control-custom" required
                        placeholder="Ulangi kata sandi baru">
                </div>
                <div class="d-flex gap-2">
                    <button type="button" class="btn-secondary-custom flex-grow-1"
                        onclick="closeModal('modalGantiPassword')">Batal</button>
                    <button type="submit" class="btn-primary-custom flex-grow-1"><i class="bi bi-shield-lock me-1"></i>
                        Simpan Kata Sandi</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// ===== Modal Handler (openModal, closeModal, closeModalOutside) =====
function openModal(id) {
    const modal = document.getElementById(id);
    if (modal) {
        modal.classList.add('show');
        document.body.style.overflow = 'hidden'; // cegah scroll di belakang modal
    }
}

function closeModal(id) {
    const modal = document.getElementById(id);
    if (modal) {
        modal.classList.remove('show');
        document.body.style.overflow = '';
    }
}

function closeModalOutside(event, id) {
    // hanya tutup kalau yang diklik adalah overlay-nya sendiri, bukan isi modal
    if (event.target.id === id) {
        closeModal(id);
    }
}

<?php if ($open_modal_on_error): ?>
document.addEventListener('DOMContentLoaded', () => openModal('<?= $open_modal_on_error ?>'));
<?php endif; ?>
</script>

<?php include "../includes/footer.php"; ?>