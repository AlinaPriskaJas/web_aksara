<?php
// client/profile.php
require_once "../config/koneksi.php";
require_once "../includes/client_helper.php";

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

// Apakah data perusahaan sudah lengkap diisi oleh client sendiri?
// Selama belum lengkap, client wajib melengkapinya dulu di halaman ini
// sebelum admin bisa memverifikasi/mengaktifkan akunnya.
$klien_lengkap = arp_klien_lengkap($data_klien);

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
    } elseif ($action === 'edit_klien') {
        $nama_perusahaan = trim($_POST['nama_perusahaan'] ?? '');
        $alamat          = trim($_POST['alamat'] ?? '');
        $pic_nama        = trim($_POST['pic_nama'] ?? '');
        $jabatan_pic     = trim($_POST['jabatan_pic'] ?? '');
        $pic_whatsapp    = trim($_POST['pic_whatsapp'] ?? '');
        $pic_email       = trim($_POST['pic_email'] ?? '');

        // Semua kolom wajib diisi LENGKAP oleh client sendiri, supaya admin
        // tidak perlu (dan tidak berisiko salah) mengetik ulang data perusahaan.
        if ($nama_perusahaan === '' || $alamat === '' || $pic_nama === '' || $jabatan_pic === '' || $pic_whatsapp === '' || $pic_email === '') {
            $error_msg = "Semua kolom data perusahaan wajib diisi lengkap sebelum disimpan!";
        } elseif (!filter_var($pic_email, FILTER_VALIDATE_EMAIL)) {
            $error_msg = "Format email PIC tidak valid!";
        } else {
            // Kalau akun ini (mis. akun lama) belum punya baris Data_Klien sama
            // sekali, buatkan dulu secara otomatis lalu tautkan ke akun ini -
            // client mengisi datanya sendiri, admin tidak perlu turun tangan.
            if (!$data_klien) {
                $klien_id_baru = arp_buat_data_klien_kosong($conn, $current_user_id);
                if ($klien_id_baru) {
                    $stmtKlien->execute(['uid' => $current_user_id]);
                    $data_klien = $stmtKlien->fetch();
                }
            }

            if (!$data_klien) {
                $error_msg = "Gagal menyiapkan data perusahaan. Silakan coba lagi atau hubungi admin.";
            } else {
                try {
                    $upd = $conn->prepare("
                        UPDATE Data_Klien
                        SET nama_perusahaan = :nama_perusahaan, alamat = :alamat,
                            pic_nama = :pic_nama, jabatan_pic = :jabatan_pic,
                            pic_whatsapp = :pic_whatsapp, pic_email = :pic_email
                        WHERE user_id = :uid
                    ");
                    $upd->execute([
                        'nama_perusahaan' => $nama_perusahaan,
                        'alamat'          => $alamat,
                        'pic_nama'        => $pic_nama,
                        'jabatan_pic'     => $jabatan_pic,
                        'pic_whatsapp'    => $pic_whatsapp,
                        'pic_email'       => $pic_email,
                        'uid'             => $current_user_id,
                    ]);
                    $success_msg = "Data perusahaan berhasil disimpan! Admin akan memverifikasi dan mengaktifkan akun Anda.";
                    $stmtKlien->execute(['uid' => $current_user_id]);
                    $data_klien = $stmtKlien->fetch();
                    $nama_untuk_avatar = $data_klien['nama_perusahaan'] ?? ($user['nama_lengkap'] ?? 'Klien');
                    $klien_lengkap = arp_klien_lengkap($data_klien);
                } catch (PDOException $e) {
                    $error_msg = "Gagal menyimpan perubahan: " . $e->getMessage();
                }
            }
        }
    } elseif ($action === 'ganti_password') {
        $password_lama       = $_POST['password_lama'] ?? '';
        $password_baru       = $_POST['password_baru'] ?? '';
        $password_konfirmasi = $_POST['password_konfirmasi'] ?? '';

        if (empty($password_lama) || empty($password_baru) || empty($password_konfirmasi)) {
            $error_msg = "Semua kolom kata sandi wajib diisi!";
        } elseif (strlen($password_baru) < 6) {
            $error_msg = "Kata sandi baru minimal 6 karakter!";
        } elseif ($password_baru !== $password_konfirmasi) {
            $error_msg = "Konfirmasi kata sandi baru tidak cocok!";
        } elseif (!$user || !password_verify($password_lama, $user['password'])) {
            $error_msg = "Kata sandi lama salah!";
        } else {
            try {
                $hash = password_hash($password_baru, PASSWORD_BCRYPT);
                $upd = $conn->prepare("UPDATE Users SET password = :pwd WHERE id = :id");
                $upd->execute(['pwd' => $hash, 'id' => $current_user_id]);
                $success_msg = "Kata sandi berhasil diperbarui!";
                $stmtUser->execute(['id' => $current_user_id]);
                $user = $stmtUser->fetch();
            } catch (PDOException $e) {
                $error_msg = "Gagal memperbarui kata sandi: " . $e->getMessage();
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

    <?php if (!$klien_lengkap): ?>
        <div class="alert alert-danger-custom align-items-center mb-4">
            <i class="bi bi-exclamation-triangle-fill fs-5"></i>
            <div>
                <strong>Lengkapi Data Perusahaan Anda Terlebih Dahulu.</strong>
                Sebelum akun dapat diaktifkan dan digunakan sepenuhnya, mohon isi seluruh data perusahaan
                (Nama Perusahaan, Alamat, dan data PIC) pada form di bawah ini dengan benar.
                Data ini diisi langsung oleh Anda agar tidak terjadi kesalahan input oleh Admin.
            </div>
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
                <h5 class="mb-1 fw-bold"><?= htmlspecialchars(($data_klien['nama_perusahaan'] ?? '') !== '' ? $data_klien['nama_perusahaan'] : 'Belum Diisi') ?></h5>
                <?php if ($klien_lengkap): ?>
                    <span class="badge-success"><?= htmlspecialchars($data_klien['status'] ?? '-') ?></span>
                <?php else: ?>
                    <span class="badge-danger">Data Belum Lengkap</span>
                <?php endif; ?>
            </div>
        </div>

        <div class="col-lg-8 col-12">
            <div class="card-box">
                <div class="d-flex align-items-center justify-content-between mb-4">
                    <h5 class="fw-bold mb-0">Data Perusahaan</h5>
                </div>

                <form action="profile.php" method="POST">
                    <input type="hidden" name="action" value="edit_klien">
                    <div class="row g-4">
                        <div class="col-md-6 col-12">
                            <label class="form-label fw-semibold fs-7 mb-2">Kode Klien</label>
                            <input type="text" class="form-control-custom" value="<?= htmlspecialchars($data_klien['kode_klien'] ?? '') ?>" readonly>
                        </div>
                        <div class="col-md-6 col-12">
                            <label class="form-label fw-semibold fs-7 mb-2">Nama Perusahaan <span class="text-danger">*</span></label>
                            <input type="text" class="form-control-custom" name="nama_perusahaan" required
                                placeholder="Contoh: PT Sejahtera Abadi"
                                value="<?= htmlspecialchars($data_klien['nama_perusahaan'] ?? '') ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold fs-7 mb-2">Alamat <span class="text-danger">*</span></label>
                            <textarea class="textarea-custom" name="alamat" required
                                placeholder="Alamat lengkap perusahaan"><?= htmlspecialchars($data_klien['alamat'] ?? '') ?></textarea>
                        </div>
                        <div class="col-md-6 col-12">
                            <label class="form-label fw-semibold fs-7 mb-2">Nama PIC <span class="text-danger">*</span></label>
                            <input type="text" class="form-control-custom" name="pic_nama" required
                                placeholder="Nama penanggung jawab / kontak"
                                value="<?= htmlspecialchars($data_klien['pic_nama'] ?? '') ?>">
                        </div>
                        <div class="col-md-6 col-12">
                            <label class="form-label fw-semibold fs-7 mb-2">Jabatan PIC <span class="text-danger">*</span></label>
                            <input type="text" class="form-control-custom" name="jabatan_pic" required
                                placeholder="Contoh: HRD Manager"
                                value="<?= htmlspecialchars($data_klien['jabatan_pic'] ?? '') ?>">
                        </div>
                        <div class="col-md-6 col-12">
                            <label class="form-label fw-semibold fs-7 mb-2">No. WhatsApp PIC <span class="text-danger">*</span></label>
                            <input type="text" class="form-control-custom" name="pic_whatsapp" required
                                placeholder="Contoh: 08123456789"
                                value="<?= htmlspecialchars($data_klien['pic_whatsapp'] ?? '') ?>">
                        </div>
                        <div class="col-md-6 col-12">
                            <label class="form-label fw-semibold fs-7 mb-2">Email PIC <span class="text-danger">*</span></label>
                            <input type="email" class="form-control-custom" name="pic_email" required
                                placeholder="email@perusahaan.com"
                                value="<?= htmlspecialchars($data_klien['pic_email'] ?? '') ?>">
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
                <form action="profile.php" method="POST">
                    <input type="hidden" name="action" value="ganti_password">
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