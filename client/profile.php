<?php
// client/profile.php
require_once "../config/koneksi.php";

if (session_status() === PHP_SESSION_NONE)
    session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'client') {
    header("Location: ../login.php");
    exit;
}

$page_title = "Profile Perusahaan";
$current_user_id = $_SESSION['user_id'];
$success_msg = "";
$error_msg = "";

// Ambil data user (untuk foto & password)
try {
    $stmtUser = $conn->prepare("SELECT * FROM Users WHERE id = :id LIMIT 1");
    $stmtUser->execute(['id' => $current_user_id]);
    $user = $stmtUser->fetch();
} catch (PDOException $e) {
    $user = null;
}

// Ambil data klien (perusahaan)
try {
    $stmtKlien = $conn->prepare("SELECT * FROM Data_Klien WHERE user_id = :uid LIMIT 1");
    $stmtKlien->execute(['uid' => $current_user_id]);
    $data_klien = $stmtKlien->fetch();
} catch (PDOException $e) {
    $data_klien = null;
}

// Nama yang dipakai untuk inisial avatar = nama perusahaan, bukan nama PIC
$nama_untuk_avatar = $data_klien['nama_perusahaan'] ?? ($user['nama_lengkap'] ?? 'Klien');

// Handle POST: upload/hapus foto akun
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'upload_foto') {
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
                        $stmtUser->execute(['id' => $current_user_id]);
                        $user = $stmtUser->fetch();
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
                $success_msg = "Foto profil dihapus. Avatar kembali ke inisial nama perusahaan.";
                $stmtUser->execute(['id' => $current_user_id]);
                $user = $stmtUser->fetch();
            } catch (PDOException $e) {
                $error_msg = "Gagal menghapus foto profil.";
            }
        }
    }
}

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

    <div class="row g-4">
        <!-- Kartu Avatar Perusahaan -->
        <div class="col-lg-4 col-12">
            <div class="card-box text-center">
                <div class="profile-avatar-wrap">
                    <?= arp_avatar_html($nama_untuk_avatar, $user['foto_profil'] ?? null, '../', 110) ?>
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
                        onsubmit="return confirm('Hapus foto profil dan kembali ke avatar inisial perusahaan?');">
                        <input type="hidden" name="action" value="hapus_foto">
                        <button type="submit" class="avatar-remove-link">Hapus Foto</button>
                    </form>
                <?php endif; ?>
                <h5 class="mb-1 fw-bold"><?= htmlspecialchars($data_klien['nama_perusahaan'] ?? '-') ?></h5>
                <span class="badge-success"><?= htmlspecialchars($data_klien['status'] ?? '-') ?></span>
            </div>
        </div>

        <div class="col-lg-8 col-12">
            <div class="card-box">
                <div class="d-flex align-items-center justify-content-between mb-4">
                    <h5 class="fw-bold mb-0">Data Perusahaan</h5>
                </div>

                <form action="profile_proses.php" method="POST">
                    <div class="row g-4">
                        <div class="col-md-6 col-12">
                            <label class="form-label fw-semibold fs-7 mb-2">Kode Klien</label>
                            <input type="text" class="form-control-custom" value="<?= htmlspecialchars($data_klien['kode_klien'] ?? '') ?>" readonly>
                        </div>
                        <div class="col-md-6 col-12">
                            <label class="form-label fw-semibold fs-7 mb-2">Nama Perusahaan</label>
                            <input type="text" class="form-control-custom" name="nama_perusahaan" value="<?= htmlspecialchars($data_klien['nama_perusahaan'] ?? '') ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold fs-7 mb-2">Alamat</label>
                            <textarea class="textarea-custom" name="alamat"><?= htmlspecialchars($data_klien['alamat'] ?? '') ?></textarea>
                        </div>
                        <div class="col-md-6 col-12">
                            <label class="form-label fw-semibold fs-7 mb-2">Nama PIC</label>
                            <input type="text" class="form-control-custom" name="pic_nama" value="<?= htmlspecialchars($data_klien['pic_nama'] ?? '') ?>">
                        </div>
                        <div class="col-md-6 col-12">
                            <label class="form-label fw-semibold fs-7 mb-2">No. WhatsApp PIC</label>
                            <input type="text" class="form-control-custom" name="pic_whatsapp" value="<?= htmlspecialchars($data_klien['pic_whatsapp'] ?? '') ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold fs-7 mb-2">Email PIC</label>
                            <input type="email" class="form-control-custom" name="pic_email" value="<?= htmlspecialchars($data_klien['pic_email'] ?? '') ?>">
                        </div>
                        <div class="col-12 d-flex justify-content-end gap-3 mt-2">
                            <button type="reset" class="btn-secondary-custom">Reset</button>
                            <button type="submit" class="btn-primary-custom">Simpan Perubahan</button>
                        </div>
                    </div>
                </form>
            </div>

            <div class="card-box mt-4">
                <h5 class="fw-bold mb-4">Ubah Kata Sandi</h5>
                <form action="ubah_password.php" method="POST">
                    <div class="row g-3">
                        <div class="col-md-4 col-12">
                            <label class="form-label fw-semibold fs-7 mb-2">Kata Sandi Lama</label>
                            <input type="password" class="form-control-custom" name="password_lama" placeholder="••••••••">
                        </div>
                        <div class="col-md-4 col-12">
                            <label class="form-label fw-semibold fs-7 mb-2">Kata Sandi Baru</label>
                            <input type="password" class="form-control-custom" name="password_baru" placeholder="••••••••">
                        </div>
                        <div class="col-md-4 col-12">
                            <label class="form-label fw-semibold fs-7 mb-2">Konfirmasi Kata Sandi</label>
                            <input type="password" class="form-control-custom" name="password_konfirmasi" placeholder="••••••••">
                        </div>
                        <div class="col-12 text-end">
                            <button type="submit" class="btn-primary-custom">Perbarui Kata Sandi</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</main>

<?php
include "../includes/footer.php";
?>