<?php
// admin/absensi.php
require_once "../config/koneksi.php";
require_once "../includes/drive_helper.php";

if (session_status() === PHP_SESSION_NONE)
    session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'ahli_k3') {
    header("Location: ../login.php");
    exit;
}

$page_title = "Absensi Kehadiran";
include "../includes/header.php";
include "../includes/sidebar.php";
include "../includes/topbar.php";

$current_user_id = $_SESSION['user_id'];
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
        $catatan_aktivitas = $_POST['catatan_aktivitas'];
        $jam_masuk = date('H:i:s');
        $bukti_foto = "";

        if (empty($status_kehadiran) || empty($lokasi_masuk)) {
            $error_msg = "Status Kehadiran dan Lokasi Masuk wajib diisi!";
        } elseif (!isset($_FILES['bukti_foto']) || $_FILES['bukti_foto']['error'] !== UPLOAD_ERR_OK) {
            $error_msg = "Bukti Foto Selfie/Lokasi wajib diunggah!";
        } else {
            $file = $_FILES['bukti_foto'];
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) {
                $error_msg = "Format foto tidak didukung. Gunakan JPG, PNG, atau WEBP.";
            } else {
                $hasil_drive = arp_upload_ke_drive($file['tmp_name'], $file['name'], $file['type'], $current_user_id, 'Absensi');
                if ($hasil_drive && !empty($hasil_drive['link'])) {
                    $bukti_foto = $hasil_drive['link'];
                } else {
                    $error_msg = "Gagal mengunggah bukti foto ke Drive.";
                }
            }
        }

        if (empty($error_msg)) {
            try {
                $stmt = $conn->prepare("
                    INSERT INTO Absensi (user_id, tanggal, jam_masuk, lokasi_masuk, status_kehadiran, bukti_foto, catatan_aktivitas)
                    VALUES (:user_id, :tanggal, :jam_masuk, :lokasi_masuk, :status, :bukti, :catatan)
                ");
                $stmt->execute([
                    'user_id' => $current_user_id,
                    'tanggal' => $today,
                    'jam_masuk' => $jam_masuk,
                    'lokasi_masuk' => $lokasi_masuk,
                    'status' => $status_kehadiran,
                    'bukti' => $bukti_foto,
                    'catatan' => $catatan_aktivitas
                ]);
                catatAudit(
                    $conn,
                    'Absensi',
                    'Check-in',
                    "Absen masuk jam {$jam_masuk} status {$status_kehadiran} di {$lokasi_masuk}",
                    null,
                    ['status_kehadiran' => $status_kehadiran, 'lokasi_masuk' => $lokasi_masuk, 'jam_masuk' => $jam_masuk]
                );
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
        $jam_pulang = date('H:i:s');

        if (empty($lokasi_pulang)) {
            $error_msg = "Lokasi Pulang wajib diisi!";
        } else {
            try {
                $stmt = $conn->prepare("
                    UPDATE Absensi 
                    SET jam_pulang = :jam_pulang, lokasi_pulang = :lokasi_pulang 
                    WHERE user_id = :user_id AND tanggal = :today
                ");
                $stmt->execute([
                    'jam_pulang' => $jam_pulang,
                    'lokasi_pulang' => $lokasi_pulang,
                    'user_id' => $current_user_id,
                    'today' => $today
                ]);
                catatAudit(
                    $conn,
                    'Absensi',
                    'Check-out',
                    "Absen pulang jam {$jam_pulang} di {$lokasi_pulang}",
                    null,
                    ['jam_pulang' => $jam_pulang, 'lokasi_pulang' => $lokasi_pulang]
                );
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
                            <button class="btn-primary-custom" onclick="openModal('modalAbsenCheckin')">
                                <i class="bi bi-box-arrow-in-right me-1"></i>Absen Hari Ini
                            </button>
                        <?php elseif ($absen_status === 'checkin'): ?>
                            <button class="btn-danger-custom" onclick="openModal('modalAbsenCheckout')">
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
                                        <td><?= htmlspecialchars($log['lokasi_masuk']) ?></td>
                                        <td class="text-center">
                                            <?php if (!empty($log['bukti_foto']) && $log['bukti_foto'] !== 'input_manual_admin'): ?>
                                                <?php $hrefBukti = str_starts_with($log['bukti_foto'], 'http') ? $log['bukti_foto'] : '../' . $log['bukti_foto']; ?>
                                                <button type="button" class="btn-icon-bukti"
                                                    onclick="tampilkanBuktiFoto('<?= htmlspecialchars($hrefBukti) ?>')"
                                                    title="Lihat Bukti Foto">
                                                    <i class="bi bi-image"></i>
                                                </button>
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
                    <label class="form-label fw-semibold fs-7 mb-2">Lokasi Masuk *</label>
                    <input type="text" name="lokasi_masuk" class="form-control-custom"
                        placeholder="Contoh: Kantor Pusat / Project Site A" required>
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
                        <div>
                            <span class="upload-dropzone-link"
                                onclick="event.stopPropagation(); document.getElementById('inputGaleriCheckin').click();">
                                <i class="bi bi-image-fill me-1"></i>atau pilih dari galeri
                            </span>
                        </div>
                    </div>
                    <input type="file" id="inputGaleriCheckin" accept="image/*" class="d-none"
                        onchange="fotoDariGaleri(this)">
                    <input type="file" name="bukti_foto" id="buktiFotoCheckin" class="d-none" required>
                    <div id="previewFotoCheckin" class="mt-2" style="display:none;">
                        <img id="imgPreviewCheckin" src=""
                            style="max-width:100%; border-radius:10px; border:1px solid var(--border-color);">
                        <button type="button" class="btn btn-sm btn-outline-danger mt-2 d-block"
                            onclick="hapusFotoCheckin()"><i class="bi bi-x-circle me-1"></i>Hapus & Ambil
                            Ulang</button>
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
                <div>Tidak dapat mengakses kamera. Pastikan izin kamera diaktifkan pada browser, atau gunakan tombol
                    <strong>Pilih File</strong> sebagai alternatif.
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
                    <label class="form-label fw-semibold fs-7 mb-2">Lokasi Pulang *</label>
                    <input type="text" name="lokasi_pulang" class="form-control-custom"
                        placeholder="Masukkan lokasi saat absen pulang" required>
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

<script>
    document.addEventListener('DOMContentLoaded', function () {
        initTablePagination('tabelAbsensiSaya', 10);
        initTablePagination('tabelAbsensi', 10);
    });

    let streamKameraCheckin = null;

    async function bukaKameraSelfie() {
        openModal('modalKameraCheckin');
        const video = document.getElementById('videoKameraCheckin');
        const errBox = document.getElementById('errorKameraCheckin');
        errBox.style.display = 'none';
        try {
            streamKameraCheckin = await navigator.mediaDevices.getUserMedia({
                video: { facingMode: 'user' },
                audio: false
            });
            video.srcObject = streamKameraCheckin;
        } catch (err) {
            errBox.style.display = 'flex';
        }
    }

    function tutupKameraSelfie() {
        closeModal('modalKameraCheckin');
        if (streamKameraCheckin) {
            streamKameraCheckin.getTracks().forEach(t => t.stop());
            streamKameraCheckin = null;
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

    function fotoDariGaleri(input) {
        if (input.files && input.files[0]) {
            setFotoCheckin(input.files[0]);
        }
    }

    function setFotoCheckin(file) {
        const dt = new DataTransfer();
        dt.items.add(file);
        document.getElementById('buktiFotoCheckin').files = dt.files;
        document.getElementById('imgPreviewCheckin').src = URL.createObjectURL(file);
        document.getElementById('previewFotoCheckin').style.display = 'block';
        document.getElementById('dropzoneCheckin').style.display = 'none';
    }

    function hapusFotoCheckin() {
        document.getElementById('buktiFotoCheckin').value = '';
        document.getElementById('inputGaleriCheckin').value = '';
        document.getElementById('previewFotoCheckin').style.display = 'none';
        document.getElementById('dropzoneCheckin').style.display = 'block';
    }
</script>

<?php if ($error_msg): ?>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            <?php if ($absen_status === 'checkin'): ?>
                openModal('modalAbsenCheckout');
            <?php else: ?>
                openModal('modalAbsenCheckin');
            <?php endif; ?>
        });
    </script>
<?php endif; ?>

<?php include "../includes/footer.php"; ?>