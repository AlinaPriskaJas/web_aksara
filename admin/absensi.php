<?php
// admin/absensi.php
require_once "../config/koneksi.php";
require_once "../includes/drive_helper.php";

if (session_status() === PHP_SESSION_NONE)
    session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../login.php");
    exit;
}

$page_title = "Absensi Kehadiran";
include "../includes/header.php";
include "../includes/sidebar.php";
include "../includes/topbar.php";

$current_user_id = $_SESSION['user_id'];

// Ambil nama lengkap user yang sedang login, untuk keperluan notifikasi
$stmtNamaUser = $conn->prepare("SELECT nama_lengkap FROM Users WHERE id = :id");
$stmtNamaUser->execute(['id' => $current_user_id]);
$current_user_name = $stmtNamaUser->fetchColumn() ?: 'User';

$success_msg = "";
$error_msg = "";
$today = date('Y-m-d');

// Cek apakah admin sudah absen masuk hari ini
try {
    $stmtCheck = $conn->prepare("SELECT * FROM Absensi WHERE user_id = :user_id AND tanggal = :today LIMIT 1");
    $stmtCheck->execute(['user_id' => $current_user_id, 'today' => $today]);
    $attendance_today = $stmtCheck->fetch();
} catch (PDOException $e) {
    $attendance_today = null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Absen Masuk (Check-in)
    if (isset($_POST['action']) && $_POST['action'] === 'checkin') {
        $status_kehadiran = $_POST['status_kehadiran'];
        $lokasi_masuk = $_POST['lokasi_masuk'];
        $latitude_masuk = $_POST['latitude_masuk'] ?? '';
        $longitude_masuk = $_POST['longitude_masuk'] ?? '';
        $catatan_aktivitas = $_POST['catatan_aktivitas'];
        $jam_masuk = date('H:i:s');
        $bukti_foto = "";
        $drive_file_id = "";
        $drive_link = "";

        if (empty($status_kehadiran) || empty($lokasi_masuk)) {
            $error_msg = "Status Kehadiran dan Lokasi Masuk wajib diisi!";
        } elseif ($latitude_masuk === '' || $longitude_masuk === '') {
            $error_msg = "Titik GPS belum terdeteksi. Aktifkan izin lokasi pada browser, lalu tekan tombol muat ulang lokasi sebelum absen.";
        } elseif (!isset($_FILES['bukti_foto']) || $_FILES['bukti_foto']['error'] !== UPLOAD_ERR_OK) {
            $error_msg = "Bukti Foto Selfie/Lokasi wajib diunggah!";
        } else {
            $file = $_FILES['bukti_foto'];
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) {
                $error_msg = "Format foto tidak didukung. Gunakan JPG, PNG, atau WEBP.";
            } else {
                $nama_file_drive = arp_nama_file_absensi($current_user_name, $ext);
                $hasil_drive = arp_upload_ke_drive($file['tmp_name'], $nama_file_drive, $file['type'], $current_user_id, 'Absensi');
                if ($hasil_drive && !empty($hasil_drive['link'])) {
                    $bukti_foto = $hasil_drive['link'];
                    $drive_file_id = $hasil_drive['file_id'] ?? '';
                    $drive_link = $hasil_drive['link'];

                    if (empty($hasil_drive['sharing_ok'])) {
                        error_log('Peringatan: foto absensi user_id=' . $current_user_id . ' ter-upload (file_id=' . $drive_file_id . ') tapi sharing GAGAL: ' . ($hasil_drive['sharing_error'] ?? ''));
                    }
                } else {
                    $error_msg = "Gagal mengunggah bukti foto ke Drive: " . arp_drive_last_error();
                }
            }
        }

        if (empty($error_msg)) {
            try {
                $stmt = $conn->prepare("
    INSERT INTO Absensi (user_id, tanggal, jam_masuk, lokasi_masuk, latitude_masuk, longitude_masuk, status_kehadiran, bukti_foto, drive_file_id, drive_link, catatan_aktivitas)
    VALUES (:user_id, :tanggal, :jam_masuk, :lokasi_masuk, :latitude_masuk, :longitude_masuk, :status, :bukti, :drive_file_id, :drive_link, :catatan)
");
                $stmt->execute([
                    'user_id' => $current_user_id,
                    'tanggal' => $today,
                    'jam_masuk' => $jam_masuk,
                    'lokasi_masuk' => $lokasi_masuk,
                    'latitude_masuk' => $latitude_masuk,
                    'longitude_masuk' => $longitude_masuk,
                    'status' => $status_kehadiran,
                    'bukti' => $bukti_foto,
                    'drive_file_id' => $drive_file_id,
                    'drive_link' => $drive_link,
                    'catatan' => $catatan_aktivitas
                ]);

                $absensi_id_baru = $conn->lastInsertId();
                $stmtDireksiAbsen = $conn->prepare("SELECT id FROM Users WHERE role = 'direksi'");
                $stmtDireksiAbsen->execute();
                foreach ($stmtDireksiAbsen->fetchAll(PDO::FETCH_COLUMN) as $direksi_id_notif) {
                    kirimNotifikasi(
                        $conn,
                        (int) $direksi_id_notif,
                        'Absensi Masuk Hari Ini',
                        "{$current_user_name} mencatat kehadiran: {$status_kehadiran} pada {$today}.",
                        'absensi',
                        (int) $absensi_id_baru
                    );
                }
                $emailDireksiAbsen = getEmailByRole($conn, 'direksi');
                if (!empty($emailDireksiAbsen)) {
                    $bodyAbsen = templateEmailNotifikasi(
                        'Absensi Masuk Hari Ini',
                        "{$current_user_name} mencatat kehadiran.",
                        ['Status Kehadiran' => $status_kehadiran, 'Tanggal' => $today],
                        $base_url . 'admin/absensi.php'
                    );
                    kirimEmail($emailDireksiAbsen, 'Absensi Masuk: ' . $current_user_name, $bodyAbsen);
                }
                $success_msg = "Absen Masuk Berhasil! Selamat bekerja.";
                $stmtCheck->execute(['user_id' => $current_user_id, 'today' => $today]);
                $attendance_today = $stmtCheck->fetch();
            } catch (PDOException $e) {
                $error_msg = "Gagal melakukan absensi: " . $e->getMessage();
            }
        }
    }

    // Absen Pulang (Check-out)
    if (isset($_POST['action']) && $_POST['action'] === 'checkout') {
        $lokasi_pulang = $_POST['lokasi_pulang'];
        $latitude_pulang = $_POST['latitude_pulang'] ?? '';
        $longitude_pulang = $_POST['longitude_pulang'] ?? '';
        $jam_pulang = date('H:i:s');

        if (empty($lokasi_pulang)) {
            $error_msg = "Lokasi Pulang wajib diisi!";
        } elseif ($latitude_pulang === '' || $longitude_pulang === '') {
            $error_msg = "Titik GPS belum terdeteksi. Aktifkan izin lokasi pada browser, lalu tekan tombol muat ulang lokasi sebelum absen pulang.";
        } else {
            try {
                $stmt = $conn->prepare("
                    UPDATE Absensi 
                    SET jam_pulang = :jam_pulang, lokasi_pulang = :lokasi_pulang,
                        latitude_pulang = :latitude_pulang, longitude_pulang = :longitude_pulang
                    WHERE user_id = :user_id AND tanggal = :today
                ");
                $stmt->execute([
                    'jam_pulang' => $jam_pulang,
                    'lokasi_pulang' => $lokasi_pulang,
                    'latitude_pulang' => $latitude_pulang,
                    'longitude_pulang' => $longitude_pulang,
                    'user_id' => $current_user_id,
                    'today' => $today
                ]);
                $success_msg = "Absen Pulang Berhasil! Sampai jumpa besok.";
                $stmtCheck->execute(['user_id' => $current_user_id, 'today' => $today]);
                $attendance_today = $stmtCheck->fetch();
            } catch (PDOException $e) {
                $error_msg = "Gagal melakukan absen pulang: " . $e->getMessage();
            }
        }
    }

}

// Riwayat absensi pribadi admin
$my_logs = [];
try {
    $stmtLogs = $conn->prepare("SELECT * FROM Absensi WHERE user_id = :user_id ORDER BY tanggal DESC LIMIT 15");
    $stmtLogs->execute(['user_id' => $current_user_id]);
    $my_logs = $stmtLogs->fetchAll();
} catch (PDOException $e) {
    $my_logs = [];
}

// Tentukan status absensi hari ini untuk label tombol
$absen_status = 'belum'; // belum, checkin, selesai
if ($attendance_today) {
    $absen_status = $attendance_today['jam_pulang'] ? 'selesai' : 'checkin';
}

// Rekap absensi seluruh karyawan
try {
    $stmtAll = $conn->query("
        SELECT a.*, u.nama_lengkap, u.role
        FROM Absensi a
        JOIN Users u ON a.user_id = u.id
        ORDER BY a.tanggal DESC, a.jam_masuk DESC
    ");
    $attendances = $stmtAll->fetchAll();
} catch (PDOException $e) {
    $attendances = [];
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

    <div class="row g-4">
        <!-- Card 1: Riwayat Absensi Pribadi Saya -->
        <div class="col-12">
            <div class="card-box">
                <div class="table-toolbar">
                    <h5 class="table-toolbar-title fw-bold">Riwayat Absensi Pribadi Saya</h5>
                    <div class="table-toolbar-actions">
                        <div class="search-box-container">
                            <i class="bi bi-search"></i>
                            <input type="text" class="search-box" placeholder="Cari absensi..."
                                data-table-search="tabelAbsensiSaya" onkeyup="handleTableSearch('tabelAbsensiSaya')">
                        </div>
                        <?php if ($absen_status === 'belum'): ?>
                            <button class="btn-primary-custom"
                                onclick="openModal('modalAbsenCheckin'); ambilLokasiCheckin();">
                                <i class="bi bi-box-arrow-in-right me-1"></i>Absen Hari Ini
                            </button>
                        <?php elseif ($absen_status === 'checkin'): ?>
                            <button class="btn-danger-custom"
                                onclick="openModal('modalAbsenCheckout'); ambilLokasiCheckout();">
                                <i class="bi bi-box-arrow-left me-1"></i> Absen Pulang
                            </button>
                        <?php else: ?>
                            <span class="badge-success" style="padding: 10px 18px; font-size: 0.85rem;">
                                <i class="bi bi-shield-check me-1"></i> Absensi Hari Ini Selesai
                            </span>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Ringkasan status hari ini -->
                <?php if ($attendance_today): ?>
                    <div class="d-flex gap-3 mb-4 flex-wrap">
                        <div class="p-3 rounded-3 text-center flex-grow-1"
                            style="background: var(--bg-glass); border: 1px solid var(--border-color); min-width:130px;">
                            <div class="text-muted" style="font-size:0.75rem;">Jam Masuk</div>
                            <div class="fw-bold" style="font-size:1.1rem;">
                                <?= htmlspecialchars(substr($attendance_today['jam_masuk'], 0, 5)) ?> WIB
                            </div>
                        </div>
                        <div class="p-3 rounded-3 text-center flex-grow-1"
                            style="background: var(--bg-glass); border: 1px solid var(--border-color); min-width:130px;">
                            <div class="text-muted" style="font-size:0.75rem;">Jam Pulang</div>
                            <div class="fw-bold" style="font-size:1.1rem;">
                                <?= $attendance_today['jam_pulang'] ? htmlspecialchars(substr($attendance_today['jam_pulang'], 0, 5)) . ' WIB' : '<span class="text-muted">—</span>' ?>
                            </div>
                        </div>
                        <div class="p-3 rounded-3 text-center flex-grow-1"
                            style="background: var(--bg-glass); border: 1px solid var(--border-color); min-width:130px;">
                            <div class="text-muted" style="font-size:0.75rem;">Status</div>
                            <div class="fw-bold" style="font-size:0.85rem;">
                                <?= htmlspecialchars($attendance_today['status_kehadiran']) ?>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="table-responsive-custom">
                    <table class="table-custom" id="tabelAbsensiSaya">
                        <thead>
                            <tr>
                                <th>Tanggal</th>
                                <th>Jam Masuk</th>
                                <th>Jam Pulang</th>
                                <th>Status Kehadiran</th>
                                <th>Lokasi Masuk</th>
                                <th class="text-center">Bukti</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($my_logs) === 0): ?>
                                <tr>
                                    <td colspan="6" class="text-center py-4 text-muted">
                                        <i class="bi bi-calendar-x d-block mb-2" style="font-size:2rem;"></i>
                                        Belum ada riwayat absensi.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($my_logs as $log): ?>
                                    <tr>
                                        <td><strong><?= date('d-m-Y', strtotime($log['tanggal'])) ?></strong></td>
                                        <td><?= htmlspecialchars(substr($log['jam_masuk'], 0, 5)) ?> WIB</td>
                                        <td><?= $log['jam_pulang'] ? htmlspecialchars(substr($log['jam_pulang'], 0, 5)) . ' WIB' : '<span class="text-muted">Belum Checkout</span>' ?>
                                        </td>
                                        <td>
                                            <?php
                                            $badgeClass = "badge-success";
                                            if (strpos($log['status_kehadiran'], 'WFH') !== false)
                                                $badgeClass = "badge-warning";
                                            if (strpos($log['status_kehadiran'], 'Dinas') !== false)
                                                $badgeClass = "badge-primary";
                                            if (strpos($log['status_kehadiran'], 'Izin') !== false || strpos($log['status_kehadiran'], 'Sakit') !== false)
                                                $badgeClass = "badge-danger";
                                            ?>
                                            <span
                                                class="<?= $badgeClass ?>"><?= htmlspecialchars($log['status_kehadiran']) ?></span>
                                        </td>
                                        <td>
                                            <?= htmlspecialchars($log['lokasi_masuk']) ?>
                                            <?php if (!empty($log['latitude_masuk']) && !empty($log['longitude_masuk'])): ?>
                                                <br><a
                                                    href="https://www.google.com/maps?q=<?= urlencode($log['latitude_masuk'] . ',' . $log['longitude_masuk']) ?>"
                                                    target="_blank" class="fs-7"><i class="bi bi-geo-alt-fill me-1"></i>Lihat di
                                                    Peta</a>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center">
                                            <?php if (!empty($log['drive_file_id'])): ?>
                                                <?php
                                                $url_preview_foto = 'https://drive.google.com/thumbnail?id=' . urlencode($log['drive_file_id']) . '&sz=w1000';
                                                $url_drive_asli = $log['drive_link'] ?? '';
                                                ?>
                                                <button type="button" class="btn-icon-bukti"
                                                    onclick="tampilkanBuktiFoto('<?= htmlspecialchars($url_preview_foto) ?>', '<?= htmlspecialchars($url_drive_asli) ?>')"
                                                    title="Lihat Bukti Foto">
                                                    <i class="bi bi-image"></i>
                                                </button>
                                            <?php elseif (!empty($log['drive_link'])): ?>
                                                <a href="<?= htmlspecialchars($log['drive_link']) ?>" target="_blank"
                                                    class="btn-icon-bukti" title="Buka di Drive">
                                                    <i class="bi bi-image"></i>
                                                </a>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div class="pagination-custom" id="pagination-tabelAbsensiSaya"></div>
            </div>
        </div>
    </div>
</main>

<!-- ===== MODAL: Absen Check-in ===== -->
<div id="modalAbsenCheckin" class="arp-modal-overlay" onclick="closeModalOutside(event, 'modalAbsenCheckin')">
    <div class="arp-modal-box">
        <div class="arp-modal-header">
            <div>
                <h6 class="fw-bold mb-0">Absensi Kehadiran Hari Ini</h6>
                <small class="text-muted">Rekam kehadiran masuk Anda sekarang — <?= date('d-m-Y') ?></small>
            </div>
            <button class="arp-modal-close" onclick="closeModal('modalAbsenCheckin')">&times;</button>
        </div>
        <div class="arp-modal-body">
            <form method="POST" action="absensi.php" enctype="multipart/form-data">
                <input type="hidden" name="action" value="checkin">
                <div class="mb-3">
                    <label class="form-label fw-semibold fs-7 mb-2">Status Kehadiran *</label>
                    <select name="status_kehadiran" class="select-custom" required>
                        <option value="WFO - Kantor Utama">WFO - Kantor Utama</option>
                        <option value="Dinas Luar / Survey Site">Dinas Luar / Survey Site</option>
                        <option value="WFH / Kerja Remote">WFH / Kerja Remote</option>
                        <option value="Sakit / Izin (Setengah Hari)">Sakit / Izin (Setengah Hari)</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold fs-7 mb-2">Lokasi Masuk (GPS Otomatis) *</label>
                    <div class="d-flex gap-2">
                        <input type="text" name="lokasi_masuk" id="lokasiMasukInput" class="form-control-custom"
                            placeholder="Mendeteksi lokasi..." required readonly>
                        <button type="button" class="btn-secondary-custom" style="white-space:nowrap;"
                            onclick="ambilLokasiCheckin()" title="Muat Ulang Lokasi">
                            <i class="bi bi-arrow-clockwise"></i>
                        </button>
                    </div>
                    <input type="hidden" name="latitude_masuk" id="latitudeMasukInput">
                    <input type="hidden" name="longitude_masuk" id="longitudeMasukInput">
                    <small id="statusLokasiCheckin" class="text-muted d-block mt-1">
                        <span class="spinner-border spinner-border-sm me-1"></span>Meminta izin lokasi ke browser...
                    </small>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold fs-7 mb-2">Bukti Foto Selfie/Lokasi *</label>
                    <div class="upload-dropzone" id="dropzoneCheckin" onclick="bukaKameraSelfie()">
                        <div class="upload-dropzone-icon"><i class="bi bi-camera-fill"></i></div>
                        <div>
                            <span class="fw-semibold" style="color: var(--primary);">Tekan untuk ambil foto
                                selfie</span>
                        </div>
                        <span class="fs-7 text-muted">Kamera akan terbuka otomatis</span>
                    </div>
                    <input type="file" name="bukti_foto" id="buktiFotoCheckin" class="d-none" required>
                    <div id="previewFotoCheckin" class="mt-2" style="display:none;">
                        <img id="imgPreviewCheckin" src=""
                            style="max-width:100%; border-radius:10px; border:1px solid var(--border-color);">
                    </div>
                </div>
                <div class="mb-4">
                    <label class="form-label fw-semibold fs-7 mb-2">Catatan Aktivitas</label>
                    <textarea name="catatan_aktivitas" class="textarea-custom"
                        placeholder="Rencana pengerjaan / tugas hari ini..."></textarea>
                </div>
                <div class="d-flex gap-2">
                    <button type="button" class="btn-secondary-custom flex-grow-1"
                        onclick="closeModal('modalAbsenCheckin')">Batal</button>
                    <button type="submit" class="btn-primary-custom flex-grow-1"><i
                            class="bi bi-box-arrow-in-right me-1"></i> Absen Masuk</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ===== MODAL: Kamera Selfie (untuk Bukti Foto Absen Masuk) ===== -->
<div id="modalKameraCheckin" class="arp-modal-overlay" onclick="closeModalOutside(event, 'modalKameraCheckin')">
    <div class="arp-modal-box" style="max-width:420px;">
        <div class="arp-modal-header">
            <div>
                <h6 class="fw-bold mb-0">Ambil Foto Selfie</h6>
                <small class="text-muted">Posisikan wajah Anda di tengah frame.</small>
            </div>
            <button class="arp-modal-close" onclick="tutupKameraSelfie()">&times;</button>
        </div>
        <div class="arp-modal-body text-center">
            <video id="videoKameraCheckin" autoplay playsinline muted
                style="width:100%; border-radius:10px; background:#000; transform:scaleX(-1);"></video>
            <canvas id="canvasKameraCheckin" class="d-none"></canvas>
            <div id="errorKameraCheckin" class="alert alert-danger-custom mt-3" style="display:none;">
                <i class="bi bi-exclamation-triangle-fill"></i>
                <div>Tidak dapat mengakses kamera. Pastikan izin kamera diaktifkan pada browser untuk dapat
                    melakukan absensi.
                </div>
            </div>
            <button type="button" class="btn-primary-custom w-100 mt-3" onclick="jepretFotoCheckin()">
                <i class="bi bi-camera-fill me-1"></i> Jepret Foto
            </button>
        </div>
    </div>
</div>

<!-- ===== MODAL: Absen Check-out ===== -->
<div id="modalAbsenCheckout" class="arp-modal-overlay" onclick="closeModalOutside(event, 'modalAbsenCheckout')">
    <div class="arp-modal-box">
        <div class="arp-modal-header">
            <div>
                <h6 class="fw-bold mb-0">Absen Pulang (Check-out)</h6>
                <small class="text-muted">Konfirmasi kehadiran pulang Anda hari ini.</small>
            </div>
            <button class="arp-modal-close" onclick="closeModal('modalAbsenCheckout')">&times;</button>
        </div>
        <div class="arp-modal-body">
            <?php if ($attendance_today): ?>
                <div class="alert alert-success-custom mb-4" style="display:flex; gap:12px; align-items:center;">
                    <i class="bi bi-check-circle-fill fs-5"></i>
                    <div>
                        <strong>Anda sudah Check-in</strong><br>
                        Jam Masuk: <?= htmlspecialchars(substr($attendance_today['jam_masuk'], 0, 5)) ?> WIB
                    </div>
                </div>
            <?php endif; ?>
            <form method="POST" action="absensi.php">
                <input type="hidden" name="action" value="checkout">
                <div class="mb-4">
                    <label class="form-label fw-semibold fs-7 mb-2">Lokasi Pulang (GPS Otomatis) *</label>
                    <div class="d-flex gap-2">
                        <input type="text" name="lokasi_pulang" id="lokasiPulangInput" class="form-control-custom"
                            placeholder="Mendeteksi lokasi..." required readonly>
                        <button type="button" class="btn-secondary-custom" style="white-space:nowrap;"
                            onclick="ambilLokasiCheckout()" title="Muat Ulang Lokasi">
                            <i class="bi bi-arrow-clockwise"></i>
                        </button>
                    </div>
                    <input type="hidden" name="latitude_pulang" id="latitudePulangInput">
                    <input type="hidden" name="longitude_pulang" id="longitudePulangInput">
                    <small id="statusLokasiCheckout" class="text-muted d-block mt-1">
                        <span class="spinner-border spinner-border-sm me-1"></span>Meminta izin lokasi ke browser...
                    </small>
                </div>
                <div class="d-flex gap-2">
                    <button type="button" class="btn-secondary-custom flex-grow-1"
                        onclick="closeModal('modalAbsenCheckout')">Batal</button>
                    <button type="submit" class="btn-danger-custom flex-grow-1"><i
                            class="bi bi-box-arrow-left me-1"></i> Absen Pulang</button>
                </div>
            </form>
        </div>
    </div>
</div>


<!-- ===== Overlay loading: muncul di TENGAH halaman saat form absen dikirim, bukan cuma di tab atas ===== -->
<div id="arpUploadOverlay" class="arp-upload-overlay">
    <div class="arp-upload-overlay-box">
        <div class="spinner-border" role="status" style="color: var(--primary);"></div>
        <p class="fw-semibold mb-1 mt-3">Mengunggah data &amp; foto...</p>
        <small class="text-muted">Mohon tunggu sebentar, jangan tutup atau refresh halaman ini.</small>
    </div>
</div>
<style>
    .arp-upload-overlay {
        display: none;
        position: fixed;
        inset: 0;
        background: rgba(15, 23, 42, 0.55);
        z-index: 99999;
        align-items: center;
        justify-content: center;
    }

    .arp-upload-overlay.arp-show {
        display: flex;
    }

    .arp-upload-overlay-box {
        background: #fff;
        border-radius: 14px;
        padding: 28px 32px;
        text-align: center;
        max-width: 300px;
        box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
    }
</style>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        initTablePagination('tabelAbsensiSaya', 10);
        initTablePagination('tabelAbsensi', 10);

        // Tampilkan overlay loading di tengah halaman saat form check-in/check-out
        // dikirim, supaya user tahu prosesnya masih berjalan (bukan macet) -
        // terutama saat mengunggah foto yang makan waktu beberapa detik.
        document.querySelectorAll('#modalAbsenCheckin form, #modalAbsenCheckout form').forEach(function (form) {
            form.addEventListener('submit', function () {
                if (!form.checkValidity()) return; // biarkan validasi HTML5 bawaan jalan dulu

                var overlay = document.getElementById('arpUploadOverlay');
                overlay.classList.add('arp-show');

                var tombol = form.querySelector('button[type="submit"]');
                if (tombol) {
                    tombol.disabled = true;
                    tombol.dataset.teksAsli = tombol.innerHTML;
                    tombol.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Mengunggah...';
                }
            });
        });
    });

    let streamKameraCheckin = null;

    // Hentikan & lepas semua track kamera yang sedang aktif (kalau ada).
    function hentikanStreamKameraCheckin() {
        if (streamKameraCheckin) {
            streamKameraCheckin.getTracks().forEach(t => t.stop());
            streamKameraCheckin = null;
        }
    }

    function tundaSebentar(ms) {
        return new Promise(resolve => setTimeout(resolve, ms));
    }

    // Minta 1x stream kamera & pasang ke video. Balikin true kalau video
    // beneran dapat frame nyata (videoWidth > 0), false kalau kamera "nyala"
    // (getUserMedia sukses, gak ada error) tapi gambarnya tetap hitam --
    // ini bug umum di sebagian HP/browser saat kamera fisik baru saja
    // dilepas dari sesi sebelumnya dan diminta lagi terlalu cepat.
    async function mulaiStreamKameraCheckin(video) {
        streamKameraCheckin = await navigator.mediaDevices.getUserMedia({
            video: { facingMode: 'user' },
            audio: false
        });
        video.srcObject = streamKameraCheckin;
        try {
            await video.play();
        } catch (playErr) {
            // Diamkan: kalau browser sudah autoplay sendiri, play() di sini
            // kadang ditolak (AbortError) padahal videonya tetap jalan.
        }
        // Kasih waktu sebentar buat video benar-benar mulai decode frame,
        // baru dicek apakah videoWidth sudah keisi (artinya ada gambar nyata).
        await tundaSebentar(800);
        return video.videoWidth > 0;
    }

    async function bukaKameraSelfie() {
        openModal('modalKameraCheckin');
        const video = document.getElementById('videoKameraCheckin');
        const errBox = document.getElementById('errorKameraCheckin');
        const errText = errBox.querySelector('div');
        errBox.style.display = 'none';

        // Penting: pastikan stream/track LAMA (dari sesi jepret sebelumnya)
        // sudah dilepas & video.srcObject dikosongkan dulu sebelum minta stream
        // baru.
        hentikanStreamKameraCheckin();
        video.srcObject = null;

        // Kamera cuma bisa diakses di "secure context" (HTTPS atau localhost).
        // Kalau halaman dibuka lewat http://IP-address, navigator.mediaDevices
        // akan undefined sehingga kamera tidak akan pernah menyala.
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            errBox.style.display = 'flex';
            if (errText) {
                errText.textContent = (location.protocol !== 'https:' && location.hostname !== 'localhost' && location.hostname !== '127.0.0.1')
                    ? 'Kamera diblokir browser karena halaman ini dibuka lewat HTTP, bukan HTTPS. Akses situs lewat HTTPS agar kamera bisa dipakai.'
                    : 'Kamera tidak didukung di browser ini.';
            }
            return;
        }

        // Beri jeda sebentar dulu supaya kamera fisik benar-benar release
        // sebelum diminta lagi -- ini bagian penting untuk kasus "kamera
        // nyala tapi layarnya hitam" saat dibuka ulang.
        await tundaSebentar(300);

        try {
            let dapatFrame = await mulaiStreamKameraCheckin(video);

            if (!dapatFrame) {
                // Percobaan pertama gak dapat frame nyata (layar hitam).
                // Lepas total, kasih jeda lebih lama, lalu coba sekali lagi.
                hentikanStreamKameraCheckin();
                video.srcObject = null;
                await tundaSebentar(600);
                dapatFrame = await mulaiStreamKameraCheckin(video);
            }

            if (!dapatFrame) {
                errBox.style.display = 'flex';
                if (errText) {
                    errText.textContent = 'Kamera menyala tapi gambar tidak muncul. Tekan tombol X, tunggu 2-3 detik, lalu tekan kamera lagi.';
                }
            }
        } catch (err) {
            errBox.style.display = 'flex';
            if (errText) {
                let pesan = 'Tidak dapat mengakses kamera. Pastikan izin kamera diaktifkan pada browser untuk dapat melakukan absensi.';
                if (err.name === 'NotAllowedError' || err.name === 'PermissionDeniedError') {
                    pesan = 'Izin kamera ditolak. Buka pengaturan situs di browser (ikon gembok di address bar), izinkan akses Kamera, lalu coba lagi.';
                } else if (err.name === 'NotFoundError' || err.name === 'DevicesNotFoundError') {
                    pesan = 'Kamera tidak ditemukan pada perangkat ini.';
                } else if (err.name === 'NotReadableError' || err.name === 'TrackStartError') {
                    pesan = 'Kamera sedang dipakai aplikasi atau tab lain. Tutup aplikasi/tab lain yang memakai kamera, lalu coba lagi.';
                } else if (err.name === 'SecurityError') {
                    pesan = 'Akses kamera diblokir karena halaman tidak diakses lewat HTTPS.';
                }
                errText.textContent = pesan;
            }
        }
    }

    function tutupKameraSelfie() {
        closeModal('modalKameraCheckin');
        hentikanStreamKameraCheckin();
        const video = document.getElementById('videoKameraCheckin');
        if (video) {
            video.srcObject = null;
        }
    }

    function jepretFotoCheckin() {
        const video = document.getElementById('videoKameraCheckin');
        if (!video.videoWidth) return; // kamera belum siap
        const canvas = document.getElementById('canvasKameraCheckin');
        canvas.width = video.videoWidth;
        canvas.height = video.videoHeight;
        const ctx = canvas.getContext('2d');
        // Cermin balik hasil jepretan biar tidak terbalik seperti preview
        ctx.translate(canvas.width, 0);
        ctx.scale(-1, 1);
        ctx.drawImage(video, 0, 0);
        canvas.toBlob(blob => {
            const file = new File([blob], 'selfie_checkin_' + Date.now() + '.jpg', { type: 'image/jpeg' });
            setFotoCheckin(file);
            tutupKameraSelfie();
        }, 'image/jpeg', 0.9);
    }

    function setFotoCheckin(file) {
        const dt = new DataTransfer();
        dt.items.add(file);
        document.getElementById('buktiFotoCheckin').files = dt.files;
        document.getElementById('imgPreviewCheckin').src = URL.createObjectURL(file);
        document.getElementById('previewFotoCheckin').style.display = 'block';
        document.getElementById('dropzoneCheckin').style.display = 'none';
    }

    // ================== LOKASI GPS OTOMATIS (Absen Masuk & Pulang) ==================
    // Alur: klik "Absen Masuk/Pulang" -> browser minta izin lokasi -> ambil GPS
    // (Latitude & Longitude) -> koordinat disimpan di input hidden -> koordinat
    // diubah menjadi alamat (reverse geocoding) lewat Nominatim OpenStreetMap ->
    // alamat ditampilkan di kolom Lokasi -> baru form absensi bisa disimpan.
    function ambilLokasiCheckin() {
        ambilLokasiGPS('lokasiMasukInput', 'latitudeMasukInput', 'longitudeMasukInput', 'statusLokasiCheckin');
    }

    function ambilLokasiCheckout() {
        ambilLokasiGPS('lokasiPulangInput', 'latitudePulangInput', 'longitudePulangInput', 'statusLokasiCheckout');
    }

    function ambilLokasiGPS(idInput, idLat, idLng, idStatus) {
        const inputLokasi = document.getElementById(idInput);
        const inputLat = document.getElementById(idLat);
        const inputLng = document.getElementById(idLng);
        const statusBox = document.getElementById(idStatus);
        if (!inputLokasi || !inputLat || !inputLng) return;

        inputLokasi.value = '';
        inputLat.value = '';
        inputLng.value = '';
        if (statusBox) {
            statusBox.classList.remove('text-danger');
            statusBox.classList.add('text-muted');
            statusBox.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Meminta izin lokasi ke browser...';
        }

        if (!navigator.geolocation) {
            if (statusBox) {
                statusBox.classList.remove('text-muted');
                statusBox.classList.add('text-danger');
                statusBox.textContent = 'Browser tidak mendukung GPS. Silakan isi lokasi secara manual.';
            }
            inputLokasi.removeAttribute('readonly');
            inputLokasi.placeholder = 'Masukkan lokasi secara manual';
            return;
        }

        navigator.geolocation.getCurrentPosition(async function (posisi) {
            const lat = posisi.coords.latitude;
            const lng = posisi.coords.longitude;
            inputLat.value = lat;
            inputLng.value = lng;

            if (statusBox) {
                statusBox.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Titik GPS didapat, mengubah ke alamat...';
            }

            try {
                const resp = await fetch(
                    `https://nominatim.openstreetmap.org/reverse?format=json&lat=${lat}&lon=${lng}&zoom=18&addressdetails=1`,
                    { headers: { 'Accept-Language': 'id' } }
                );
                const data = await resp.json();
                const alamat = (data && data.display_name) ? data.display_name : `${lat.toFixed(6)}, ${lng.toFixed(6)}`;
                inputLokasi.value = alamat;
                if (statusBox) {
                    statusBox.classList.remove('text-danger');
                    statusBox.innerHTML = '<i class="bi bi-geo-alt-fill me-1"></i>Lokasi GPS berhasil dideteksi.';
                }
            } catch (e) {
                // Kalau reverse-geocoding gagal (mis. tidak ada internet ke layanan peta),
                // tetap simpan koordinat mentahnya supaya absensi tidak terhambat.
                inputLokasi.value = `${lat.toFixed(6)}, ${lng.toFixed(6)}`;
                if (statusBox) {
                    statusBox.innerHTML = '<i class="bi bi-geo-alt-fill me-1"></i>Koordinat GPS didapat (alamat tidak dapat dimuat).';
                }
            }
        }, function (err) {
            let pesan = 'Gagal mengambil lokasi. ';
            if (err.code === err.PERMISSION_DENIED) {
                pesan += 'Izin lokasi ditolak. Aktifkan izin lokasi pada browser lalu tekan tombol muat ulang lokasi.';
            } else if (err.code === err.TIMEOUT) {
                pesan += 'Waktu permintaan lokasi habis. Coba lagi.';
            } else {
                pesan += 'Pastikan GPS/lokasi perangkat aktif, lalu coba lagi.';
            }
            if (statusBox) {
                statusBox.classList.remove('text-muted');
                statusBox.classList.add('text-danger');
                statusBox.textContent = pesan;
            }
            // Fallback: izinkan isi manual kalau GPS benar-benar tidak bisa diakses.
            inputLokasi.removeAttribute('readonly');
            inputLokasi.placeholder = 'GPS gagal, isi lokasi manual';
        }, {
            enableHighAccuracy: true,
            timeout: 15000,
            maximumAge: 0
        });
    }
</script>

<?php if ($error_msg): ?>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            <?php if ($absen_status === 'checkin'): ?>
                openModal('modalAbsenCheckout');
                ambilLokasiCheckout();
            <?php else: ?>
                openModal('modalAbsenCheckin');
                ambilLokasiCheckin();
            <?php endif; ?>
        });
    </script>
<?php endif; ?>

<!-- ===== MODAL: Preview Bukti Foto Absensi ===== -->
<div id="modalBuktiFoto" class="arp-modal-overlay" onclick="closeModalOutside(event, 'modalBuktiFoto')">
    <div class="arp-modal-box" style="max-width:480px;">
        <div class="arp-modal-header">
            <h6 class="fw-bold mb-0">Bukti Foto Absensi</h6>
            <button class="arp-modal-close" onclick="closeModal('modalBuktiFoto')">&times;</button>
        </div>
        <div class="arp-modal-body text-center">
            <img id="imgBuktiFotoModal" src="" alt="Bukti Foto Absensi" referrerpolicy="no-referrer"
                style="max-width:100%; max-height:70vh; border-radius:10px; border:1px solid var(--border-color); display:block; margin:0 auto;">

            <!-- Ditampilkan hanya kalau gambar gagal dimuat (mis. sharing belum aktif / masih propagasi) -->
            <div id="errorBuktiFotoModal" style="display:none;" class="alert alert-danger-custom mt-3 text-start">
                <i class="bi bi-exclamation-triangle-fill"></i>
                <div>
                    Foto tidak dapat ditampilkan langsung di sini (kemungkinan izin akses foto di Drive belum aktif
                    atau masih diproses). Silakan buka langsung lewat tombol di bawah.
                </div>
            </div>
            <a id="linkBukaDiDrive" href="#" target="_blank" class="btn-secondary-custom mt-3 d-inline-block"
                style="display:none;">
                <i class="bi bi-box-arrow-up-right me-1"></i> Buka di Google Drive
            </a>
        </div>
    </div>
</div>

<script>
    /**
     * Tampilkan modal preview bukti foto absensi.
     * @param {string} urlThumbnail  URL thumbnail Drive (dari drive_file_id) untuk ditampilkan langsung.
     * @param {string} urlDriveAsli  Opsional: link Drive asli (drive_link) untuk fallback "Buka di Drive"
     *                               kalau thumbnail gagal dimuat.
     */
    function tampilkanBuktiFoto(urlThumbnail, urlDriveAsli) {
        const img = document.getElementById('imgBuktiFotoModal');
        const errBox = document.getElementById('errorBuktiFotoModal');
        const linkDrive = document.getElementById('linkBukaDiDrive');

        // Reset state tiap kali modal dibuka
        errBox.style.display = 'none';
        img.style.display = 'block';
        linkDrive.style.display = 'none';

        img.onerror = function () {
            img.onerror = null;
            img.style.display = 'none';
            errBox.style.display = 'flex';
            if (urlDriveAsli) {
                linkDrive.href = urlDriveAsli;
                linkDrive.style.display = 'inline-block';
            }
        };

        img.src = urlThumbnail;
        openModal('modalBuktiFoto');
    }
</script>

<?php include "../includes/footer.php"; ?>