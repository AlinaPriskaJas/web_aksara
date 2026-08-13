<?php
// admin/jadwal.php
$page_title = "Jadwal Pemeriksaan K3";
include "../includes/header.php";
include "../includes/sidebar.php";
include "../includes/topbar.php";
require_once "../config/koneksi.php";

// Authorization Check
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../login.php");
    exit;
}

$current_user_id = $_SESSION['user_id'];
$success_msg = "";
$error_msg = "";

// Handle Add/Edit Jadwal
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'save') {
        $ahli_k3_id = $_POST['ahli_k3_id'];
        $klien_id = $_POST['klien_id'];
        $tanggal = $_POST['tanggal'];
        $tanggal_selesai = $_POST['tanggal_selesai'] ?: null;
        $jam_mulai = $_POST['jam_mulai'];
        $jam_selesai = $_POST['jam_selesai'] ?: null;
        $lokasi = $_POST['lokasi'];
        $catatan = $_POST['catatan'];
        $tim_support_ids = isset($_POST['tim_support_ids']) ? array_slice(array_unique($_POST['tim_support_ids']), 0, 3) : [];
        $status = $_POST['status'] ?? 'Terjadwal';
        $kategori_objek_ids = isset($_POST['kategori_objek_ids']) ? $_POST['kategori_objek_ids'] : [];

        if (empty($ahli_k3_id) || empty($klien_id) || empty($tanggal) || empty($jam_mulai)) {
            $error_msg = "Semua kolom bertanda * wajib diisi!";
        } else {
            try {
                // Update data PIC klien jika diisi manual pada form jadwal
                // (hanya menimpa jika field yang dikirim tidak kosong, agar data lama tidak tertimpa kosong)
                $pic_nama_input = trim($_POST['pic_nama'] ?? '');
                $pic_kontak_input = trim($_POST['pic_kontak'] ?? '');

                if ($pic_nama_input !== '' || $pic_kontak_input !== '') {
                    $updKlienPic = $conn->prepare("
                UPDATE Data_Klien 
                SET pic_nama = COALESCE(NULLIF(:pic_nama, ''), pic_nama),
                    pic_whatsapp = COALESCE(NULLIF(:pic_kontak, ''), pic_whatsapp)
                WHERE id = :klien_id
            ");
                    $updKlienPic->execute([
                        'pic_nama' => $pic_nama_input,
                        'pic_kontak' => $pic_kontak_input,
                        'klien_id' => $klien_id
                    ]);
                }

                // Simpan daftar kategori objek K3 terpilih sebagai catatan tambahan terstruktur (comma separated ids -> nama)
                $kategori_label = [];
                if (!empty($kategori_objek_ids)) {
                    $inQuery = implode(',', array_fill(0, count($kategori_objek_ids), '?'));
                    $stmtKat = $conn->prepare("SELECT nama_kategori FROM kategori_objek_k3 WHERE id_kategori IN ($inQuery)");
                    $stmtKat->execute($kategori_objek_ids);
                    $kategori_label = array_column($stmtKat->fetchAll(), 'nama_kategori');
                }
                $unit_diinspeksi = implode(', ', $kategori_label);

                // Ambil nama tim support terpilih, simpan sebagai baris terstruktur di catatan
                $tim_label = [];
                if (!empty($tim_support_ids)) {
                    $inQueryTim = implode(',', array_fill(0, count($tim_support_ids), '?'));
                    $stmtTimNama = $conn->prepare("SELECT nama_lengkap FROM Users WHERE id IN ($inQueryTim)");
                    $stmtTimNama->execute($tim_support_ids);
                    $tim_label = array_column($stmtTimNama->fetchAll(), 'nama_lengkap');
                }
                if (!empty($tim_label)) {
                    $catatan = trim($catatan . "\n[Tim Support: " . implode(', ', $tim_label) . "]");
                }

                if (isset($_POST['id']) && !empty($_POST['id'])) {
                    // Edit / Update
                    $id = $_POST['id'];

                    // Ambil data LAMA (tanggal, jam, ahli_k3_id, tim_support_ids) SEBELUM di-update,
                    // dipakai untuk log reschedule DAN untuk mendeteksi siapa yang dicopot dari penugasan.
                    $checkStmt = $conn->prepare("SELECT tanggal, jam_mulai, ahli_k3_id, tim_support_ids FROM Jadwal_Pemeriksaan WHERE id = :id");
                    $checkStmt->execute(['id' => $id]);
                    $old = $checkStmt->fetch();

                    // Hitung daftar penerima LAMA (Lead Expert + Tim Support) SEBELUM data di-update
                    $penerimaLama = [];
                    if ($old) {
                        if (!empty($old['ahli_k3_id'])) {
                            $stmtLeadLama = $conn->prepare("SELECT user_id FROM Sertifikat_Ahli WHERE id = :ahli_id");
                            $stmtLeadLama->execute(['ahli_id' => $old['ahli_k3_id']]);
                            $leadLamaId = $stmtLeadLama->fetchColumn();
                            if ($leadLamaId) {
                                $penerimaLama[$leadLamaId] = true;
                            }
                        }
                        if (!empty($old['tim_support_ids'])) {
                            foreach (explode(',', $old['tim_support_ids']) as $tsIdLama) {
                                if ($tsIdLama !== '') {
                                    $penerimaLama[$tsIdLama] = true;
                                }
                            }
                        }
                    }

                    // Log reschedule if date or time changed
                    if ($old && ($old['tanggal'] !== $tanggal || $old['jam_mulai'] !== $jam_mulai)) {
                        $logStmt = $conn->prepare("INSERT INTO Jadwal_Reschedule_Log (jadwal_id, tanggal_lama, jam_lama, tanggal_baru, jam_baru, alasan, diubah_oleh) VALUES (:jadwal_id, :tgl_lama, :jam_lama, :tgl_baru, :jam_baru, :alasan, :user_id)");
                        $logStmt->execute([
                            'jadwal_id' => $id,
                            'tgl_lama' => $old['tanggal'],
                            'jam_lama' => $old['jam_mulai'],
                            'tgl_baru' => $tanggal,
                            'jam_baru' => $jam_mulai,
                            'alasan' => 'Penyesuaian jadwal operasional oleh Admin',
                            'user_id' => $current_user_id
                        ]);
                    }

                    $stmt = $conn->prepare("UPDATE Jadwal_Pemeriksaan SET ahli_k3_id = :ahli_k3_id, klien_id = :klien_id, tanggal = :tanggal, tanggal_selesai = :tanggal_selesai, jam_mulai = :jam_mulai, jam_selesai = :jam_selesai, lokasi = :lokasi, status = :status, catatan = :catatan, tim_support_ids = :tim_ids, kategori_objek_ids = :kat_ids WHERE id = :id");
                    $stmt->execute([
                        'ahli_k3_id' => $ahli_k3_id,
                        'klien_id' => $klien_id,
                        'tanggal' => $tanggal,
                        'tanggal_selesai' => $tanggal_selesai,
                        'jam_mulai' => $jam_mulai,
                        'jam_selesai' => $jam_selesai,
                        'lokasi' => $lokasi,
                        'status' => $status,
                        'catatan' => $catatan,
                        'tim_ids' => implode(',', $tim_support_ids),
                        'kat_ids' => implode(',', $kategori_objek_ids),
                        'id' => $id
                    ]);

                    // ================== NOTIFIKASI: JADWAL DIPERBARUI ==================
                    // Ambil nama klien langsung dari DB (jangan andalkan $klien_list, belum ter-load di titik ini)
                    $stmtNamaKlien = $conn->prepare("SELECT nama_perusahaan FROM Data_Klien WHERE id = :klien_id");
                    $stmtNamaKlien->execute(['klien_id' => $klien_id]);
                    $namaKlienNotif = $stmtNamaKlien->fetchColumn() ?: 'Klien';

                    $tglNotif = date('d/m/Y', strtotime($tanggal)) . ' ' . substr($jam_mulai, 0, 5);
                    $pesanNotif = ($old && ($old['tanggal'] !== $tanggal || $old['jam_mulai'] !== $jam_mulai))
                        ? "Jadwal pemeriksaan {$namaKlienNotif} diubah menjadi {$tglNotif}."
                        : "Detail jadwal pemeriksaan {$namaKlienNotif} pada {$tglNotif} telah diperbarui.";

                    // Hitung daftar penerima BARU (Lead Expert + Tim Support) setelah data diupdate
                    $penerimaJadwal = [];
                    $stmtLeadUser = $conn->prepare("SELECT user_id FROM Sertifikat_Ahli WHERE id = :ahli_id");
                    $stmtLeadUser->execute(['ahli_id' => $ahli_k3_id]);
                    $leadUserId = $stmtLeadUser->fetchColumn();
                    if ($leadUserId) {
                        $penerimaJadwal[$leadUserId] = true;
                    }
                    foreach ($tim_support_ids as $tsId) {
                        $penerimaJadwal[$tsId] = true;
                    }

                    // Pisahkan: yang baru masuk penugasan vs yang tetap ada dari sebelumnya
                    $penugasanBaru = array_diff_key($penerimaJadwal, $penerimaLama);
                    $tetapDitugaskan = array_intersect_key($penerimaJadwal, $penerimaLama);

                    // Kirim notif "Anda ditugaskan" ke yang baru pertama kali masuk penugasan
                    foreach (array_keys($penugasanBaru) as $userIdBaru) {
                        kirimNotifikasi(
                            $conn,
                            (int) $userIdBaru,
                            'Penugasan Jadwal Pemeriksaan',
                            "Anda ditugaskan pada pemeriksaan {$namaKlienNotif} pada {$tglNotif}" . ($lokasi ? " di {$lokasi}" : "") . ".",
                            'jadwal',
                            (int) $id
                        );
                    }

                    // Kirim notif "Jadwal Diperbarui" ke yang tetap ada di penugasan (lama & baru)
                    foreach (array_keys($tetapDitugaskan) as $userIdTetap) {
                        kirimNotifikasi(
                            $conn,
                            (int) $userIdTetap,
                            'Jadwal Pemeriksaan Diperbarui',
                            $pesanNotif,
                            'jadwal',
                            (int) $id
                        );
                    }

                    // ================== NOTIFIKASI: DICOPOT DARI PENUGASAN ==================
                    // Orang yang ADA di penugasan lama tapi TIDAK ADA lagi di penugasan baru
                    $dicopotDariTugas = array_diff_key($penerimaLama, $penerimaJadwal);
                    foreach (array_keys($dicopotDariTugas) as $userIdDicopot) {
                        kirimNotifikasi(
                            $conn,
                            (int) $userIdDicopot,
                            'Dicopot dari Penugasan Jadwal',
                            "Anda tidak lagi ditugaskan pada pemeriksaan {$namaKlienNotif} yang sebelumnya dijadwalkan pada {$tglNotif}.",
                            'jadwal',
                            (int) $id
                        );
                    }

                    // ================== NOTIFIKASI BROADCAST: SEMUA USER LAIN (info umum) ==================
                    // Semua user (semua role, kecuali client) diberi tahu ada perubahan jadwal,
                    // KECUALI yang sudah dapat salah satu notif spesifik di atas (agar tidak dobel).
                    $stmtSemuaUserEdit = $conn->prepare("SELECT id FROM Users WHERE role != 'client'");
                    $stmtSemuaUserEdit->execute();
                    foreach ($stmtSemuaUserEdit->fetchAll(PDO::FETCH_COLUMN) as $userIdBroadcast) {
                        if (
                            isset($penerimaJadwal[$userIdBroadcast]) ||
                            isset($dicopotDariTugas[$userIdBroadcast])
                        ) {
                            continue;
                        }
                        kirimNotifikasi(
                            $conn,
                            (int) $userIdBroadcast,
                            'Jadwal Pemeriksaan Diperbarui',
                            $pesanNotif,
                            'jadwal',
                            (int) $id
                        );
                    }

                    $success_msg = "Jadwal pemeriksaan berhasil diperbarui!";
                } else {
                    // Create New
                    // Catatan: form "Pilih Pengajuan K3" sudah dihapus dari UI, namun kolom
                    // pengajuan_id pada tabel Jadwal_Pemeriksaan masih NOT NULL (mengacu ke
                    // Pengajuan_Pemeriksaan). Untuk menjaga data tetap konsisten, sistem akan
                    // otomatis mencari pengajuan terbaru milik klien terkait; jika belum ada,
                    // sistem membuat baris pengajuan baru secara otomatis mewakili jadwal ini.
                    $findPengajuan = $conn->prepare("SELECT id FROM Pengajuan_Pemeriksaan WHERE klien_id = :klien_id AND status IN ('Menunggu Verifikasi', 'Diverifikasi') ORDER BY id DESC LIMIT 1");
                    $findPengajuan->execute(['klien_id' => $klien_id]);
                    $rowPengajuan = $findPengajuan->fetch();

                    if ($rowPengajuan) {
                        $pengajuan_id = $rowPengajuan['id'];
                    } else {
                        $autoPengajuan = $conn->prepare("INSERT INTO Pengajuan_Pemeriksaan (klien_id, diajukan_oleh, jenis_pemeriksaan, klasifikasi_objek_k3, deskripsi_kebutuhan, tanggal_diinginkan, status, diproses_oleh) VALUES (:klien_id, :user_id, 'Pemeriksaan Baru', 'Tempat Kerja di Ketinggian', :deskripsi, :tanggal, 'Diverifikasi', :user_id)");
                        $autoPengajuan->execute([
                            'klien_id' => $klien_id,
                            'user_id' => $current_user_id,
                            'deskripsi' => $unit_diinspeksi ? "Unit diinspeksi: $unit_diinspeksi" : 'Dibuat otomatis dari jadwal pemeriksaan.',
                            'tanggal' => $tanggal
                        ]);
                        $pengajuan_id = $conn->lastInsertId();
                    }

                    $stmt = $conn->prepare("INSERT INTO Jadwal_Pemeriksaan (pengajuan_id, ahli_k3_id, klien_id, tanggal, tanggal_selesai, jam_mulai, jam_selesai, lokasi, status, catatan, dijadwalkan_oleh, tim_support_ids, kategori_objek_ids) VALUES (:pengajuan_id, :ahli_k3_id, :klien_id, :tanggal, :tanggal_selesai, :jam_mulai, :jam_selesai, :lokasi, :status, :catatan, :user_id, :tim_ids, :kat_ids)");
                    $stmt->execute([
                        'pengajuan_id' => $pengajuan_id,
                        'ahli_k3_id' => $ahli_k3_id,
                        'klien_id' => $klien_id,
                        'tanggal' => $tanggal,
                        'tanggal_selesai' => $tanggal_selesai,
                        'jam_mulai' => $jam_mulai,
                        'jam_selesai' => $jam_selesai,
                        'lokasi' => $lokasi,
                        'status' => $status,
                        'catatan' => $catatan,
                        'user_id' => $current_user_id,
                        'tim_ids' => implode(',', $tim_support_ids),
                        'kat_ids' => implode(',', $kategori_objek_ids)
                    ]);

                    // Update Status Pengajuan_Pemeriksaan to 'Dijadwalkan'
                    $updPengajuan = $conn->prepare("UPDATE Pengajuan_Pemeriksaan SET status = 'Dijadwalkan' WHERE id = :pengajuan_id");
                    $updPengajuan->execute(['pengajuan_id' => $pengajuan_id]);

                    // ================== NOTIFIKASI: JADWAL BARU (Lead Expert + Tim Support) ==================
                    $jadwal_id_baru = $conn->lastInsertId(); // ambil SEBELUM query INSERT/UPDATE lain terjadi lagi

                    // Ambil nama klien langsung dari DB (jangan andalkan $klien_list, belum ter-load di titik ini)
                    $stmtNamaKlien = $conn->prepare("SELECT nama_perusahaan FROM Data_Klien WHERE id = :klien_id");
                    $stmtNamaKlien->execute(['klien_id' => $klien_id]);
                    $namaKlienNotif = $stmtNamaKlien->fetchColumn() ?: 'Klien';
                    $tglNotif = date('d/m/Y', strtotime($tanggal)) . ' ' . substr($jam_mulai, 0, 5);

                    // Kumpulkan penerima notif PENUGASAN: user_id Lead Expert (via Sertifikat_Ahli) + semua Tim Support
                    $penerimaJadwal = [];

                    $stmtLeadUser = $conn->prepare("SELECT user_id FROM Sertifikat_Ahli WHERE id = :ahli_id");
                    $stmtLeadUser->execute(['ahli_id' => $ahli_k3_id]);
                    $leadUserId = $stmtLeadUser->fetchColumn();
                    if ($leadUserId) {
                        $penerimaJadwal[$leadUserId] = true;
                    }

                    foreach ($tim_support_ids as $tsId) {
                        $penerimaJadwal[$tsId] = true;
                    }

                    // Kirim notif PENUGASAN ke Lead Expert + Tim Support
                    foreach (array_keys($penerimaJadwal) as $userIdNotif) {
                        kirimNotifikasi(
                            $conn,
                            (int) $userIdNotif,
                            'Penugasan Jadwal Pemeriksaan Baru',
                            "Anda ditugaskan pada pemeriksaan {$namaKlienNotif} pada {$tglNotif}" . ($lokasi ? " di {$lokasi}" : "") . ".",
                            'jadwal',
                            (int) $jadwal_id_baru
                        );
                    }

                    // ================== NOTIFIKASI BROADCAST: SEMUA USER (info umum) ==================
                    // Semua user (semua role, kecuali client) diberi tahu ada jadwal baru,
                    // KECUALI yang sudah dapat notif penugasan di atas (agar tidak dobel).
                    $stmtSemuaUser = $conn->prepare("SELECT id FROM Users WHERE role != 'client'");
                    $stmtSemuaUser->execute();
                    foreach ($stmtSemuaUser->fetchAll(PDO::FETCH_COLUMN) as $userIdBroadcast) {
                        if (isset($penerimaJadwal[$userIdBroadcast])) {
                            continue; // sudah dapat notif penugasan, skip biar tidak dobel
                        }
                        kirimNotifikasi(
                            $conn,
                            (int) $userIdBroadcast,
                            'Jadwal Pemeriksaan Baru',
                            "Ada jadwal pemeriksaan baru untuk {$namaKlienNotif} pada {$tglNotif}" . ($lokasi ? " di {$lokasi}" : "") . ".",
                            'jadwal',
                            (int) $jadwal_id_baru
                        );
                    }

                    $success_msg = "Jadwal pemeriksaan baru berhasil ditambahkan!";
                }
            } catch (PDOException $e) {
                $error_msg = "Gagal menyimpan data: " . $e->getMessage();
            }
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_POST['action']) && $_POST['action'] === 'save') {
            // ... (kode save yang sudah ada, tidak berubah)
        } elseif (isset($_POST['action']) && $_POST['action'] === 'delete') {
            $id = $_POST['id'] ?? null;
            if (!empty($id)) {
                try {
                    // Hapus log reschedule terkait dulu (agar tidak kena constraint FK)
                    $delLog = $conn->prepare("DELETE FROM Jadwal_Reschedule_Log WHERE jadwal_id = :id");
                    $delLog->execute(['id' => $id]);

                    $delJadwal = $conn->prepare("DELETE FROM Jadwal_Pemeriksaan WHERE id = :id");
                    $delJadwal->execute(['id' => $id]);

                    $success_msg = "Jadwal pemeriksaan berhasil dihapus!";
                } catch (PDOException $e) {
                    $error_msg = "Gagal menghapus data: " . $e->getMessage();
                }
            } else {
                $error_msg = "ID jadwal tidak valid.";
            }
        }
    }
}

// Get Lists for Forms
$klien_list = $conn->query("SELECT id, nama_perusahaan, pic_nama, pic_whatsapp FROM Data_Klien ORDER BY nama_perusahaan ASC")->fetchAll();
$ahli_list = $conn->query("SELECT id, nama_lengkap, tingkat_ahli, bidang_keahlian FROM Sertifikat_Ahli ORDER BY nama_lengkap ASC")->fetchAll();
$kategori_objek_list = $conn->query("SELECT id_kategori, kode_kategori, nama_kategori FROM kategori_objek_k3 ORDER BY nama_kategori ASC")->fetchAll();
$user_list = $conn->query("
    SELECT u.id, u.nama_lengkap, u.role, sa.tingkat_ahli, sa.bidang_keahlian
    FROM Users u
    LEFT JOIN Sertifikat_Ahli sa ON sa.user_id = u.id
    WHERE u.role != 'client'
    ORDER BY u.nama_lengkap ASC
")->fetchAll();

// Get Jadwal Data
$stmtJadwal = $conn->query("
    SELECT jp.*, dk.nama_perusahaan, sa.nama_lengkap AS nama_ahli, sa.tingkat_ahli, sa.bidang_keahlian, u.nama_lengkap AS nama_admin 
    FROM Jadwal_Pemeriksaan jp
    JOIN Data_Klien dk ON jp.klien_id = dk.id
    JOIN Sertifikat_Ahli sa ON jp.ahli_k3_id = sa.id
    JOIN Users u ON jp.dijadwalkan_oleh = u.id
    ORDER BY jp.tanggal DESC, jp.jam_mulai DESC
");
$jadwals = $stmtJadwal->fetchAll();
$today_str = date('Y-m-d');
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
        <!-- Kalender Jadwal Riksa -->
        <div class="col-lg-8">
            <div class="card-box">
                <div class="table-toolbar">
                    <h5 class="table-toolbar-title fw-bold">Kalender Jadwal Riksa</h5>
                    <div class="table-toolbar-actions">
                        <button type="button" class="btn-secondary-custom cal-nav-btn" onclick="changeMonth(-1)">
                            <i class="bi bi-chevron-left"></i>
                        </button>
                        <span id="calendarMonthLabel" class="fw-semibold"
                            style="min-width:150px; text-align:center; display:inline-block;"></span>
                        <button type="button" class="btn-secondary-custom cal-nav-btn" onclick="changeMonth(1)">
                            <i class="bi bi-chevron-right"></i>
                        </button>
                    </div>
                </div>

                <div class="calendar-weekdays">
                    <span>Sen</span><span>Sel</span><span>Rab</span><span>Kam</span><span>Jum</span><span>Sab</span><span>Min</span>
                </div>
                <div class="calendar-grid" id="calendarGrid"></div>

                <div class="calendar-legend">
                    <span class="legend-dot"></span> Ada jadwal riksa &mdash; nama klien ditampilkan langsung pada
                    tanggal
                </div>

                <div class="mt-3">
                    <button class="btn-primary-custom w-100" data-bs-toggle="modal" data-bs-target="#jadwalModal"
                        onclick="resetForm()">
                        <i class="bi bi-plus-lg"></i> Buat Jadwal Baru
                    </button>
                </div>
            </div>
        </div>

        <!-- Daftar Riksa Aktif -->
        <div class="col-lg-4">
            <div class="card-box">
                <div class="table-toolbar">
                    <h5 class="table-toolbar-title fw-bold">Daftar Riksa Aktif</h5>
                </div>
                <div class="riksa-subtitle" id="selectedDateLabel"></div>

                <div id="daftarRiksaAktif" class="daftar-riksa-list"></div>
            </div>
        </div>
    </div>
</main>

<!-- Modal Form -->
<div class="modal fade modal-custom" id="jadwalModal" tabindex="-1" aria-labelledby="jadwalModalLabel"
    aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <form method="POST" action="jadwal.php" id="formJadwal">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" id="form-id">
                <input type="hidden" name="klien_id" id="form-klien-id" required>

                <div class="modal-header">
                    <h5 class="modal-title" id="jadwalModalLabel">Kelola Jadwal Pemeriksaan</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">

                        <!-- Nama Perusahaan (Autocomplete) -->
                        <div class="col-md-4">
                            <label class="form-label fw-semibold fs-7 mb-1">Nama Perusahaan (Klien) <span
                                    class="text-danger">*</span></label>
                            <div class="arp-autocomplete-wrap" style="position:relative;">
                                <input type="text" id="form-klien-search" class="form-control-custom"
                                    placeholder="Ketik nama perusahaan..." autocomplete="off"
                                    oninput="searchKlien(this.value)" onfocus="searchKlien(this.value)">
                                <div id="klien-suggestion-box" class="arp-suggestion-box" style="display:none;"></div>
                            </div>
                        </div>

                        <!-- PIC Nama -->
                        <div class="col-md-4">
                            <label class="form-label fw-semibold fs-7 mb-1">Nama PIC</label>
                            <input type="text" name="pic_nama" id="form-pic-nama" class="form-control-custom"
                                placeholder="Otomatis dari data klien, atau isi manual">
                        </div>

                        <!-- PIC Kontak -->
                        <div class="col-md-4">
                            <label class="form-label fw-semibold fs-7 mb-1">Kontak PIC</label>
                            <input type="text" name="pic_kontak" id="form-pic-kontak" class="form-control-custom"
                                placeholder="Otomatis dari data klien, atau isi manual">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold fs-7 mb-1">Tanggal Mulai <span
                                    class="text-danger">*</span></label>
                            <input type="date" name="tanggal" id="form-tanggal" class="form-control-custom" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold fs-7 mb-1">Tanggal Selesai</label>
                            <input type="date" name="tanggal_selesai" id="form-tanggal-selesai"
                                class="form-control-custom">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold fs-7 mb-1">Jam Mulai <span
                                    class="text-danger">*</span></label>
                            <input type="time" name="jam_mulai" id="form-jam-mulai" class="form-control-custom"
                                required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold fs-7 mb-1">Jam Selesai</label>
                            <input type="time" name="jam_selesai" id="form-jam-selesai" class="form-control-custom">
                        </div>

                        <!-- Unit Alat yang Diinspeksi (Checklist) -->
                        <div class="col-12">
                            <label class="form-label fw-semibold fs-7 mb-1">Unit Alat yang Diinspeksi</label>
                            <div class="blok-box" style="max-height:180px; overflow-y:auto;">
                                <div class="row g-2">
                                    <?php foreach ($kategori_objek_list as $kat): ?>
                                        <div class="col-md-6">
                                            <div class="form-check">
                                                <input class="form-check-input kategori-objek-checkbox" type="checkbox"
                                                    name="kategori_objek_ids[]" value="<?= $kat['id_kategori'] ?>"
                                                    id="kat-<?= $kat['id_kategori'] ?>">
                                                <label class="form-check-label" for="kat-<?= $kat['id_kategori'] ?>">
                                                    <?= htmlspecialchars($kat['nama_kategori']) ?>
                                                </label>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>

                        <div class="col-12">
                            <label class="form-label fw-semibold fs-7 mb-1">Lead Expert (Ahli SKP) <span
                                    class="text-danger">*</span></label>
                            <select name="ahli_k3_id" id="form-ahli-id" class="select-custom" required>
                                <option value="">-- Pilih Ahli K3 --</option>
                                <?php foreach ($ahli_list as $a): ?>
                                    <option value="<?= $a['id'] ?>">
                                        <?= htmlspecialchars($a['nama_lengkap']) ?>
                                        (<?= htmlspecialchars($a['tingkat_ahli']) ?>) - SKP:
                                        <?= htmlspecialchars($a['bidang_keahlian']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-12">
                            <label class="form-label fw-semibold fs-7 mb-1">
                                Daftar Tim Support <span class="text-secondary fw-normal">(Rekomendasi 1-3 orang)</span>
                            </label>
                            <div class="blok-box" style="max-height:180px; overflow-y:auto;">
                                <div class="row g-2">
                                    <?php foreach ($user_list as $u): ?>
                                        <?php
                                        if ($u['role'] === 'ahli_k3' && !empty($u['tingkat_ahli'])) {
                                            $subLabel = htmlspecialchars($u['tingkat_ahli']) . ' - SKP: ' . htmlspecialchars($u['bidang_keahlian']);
                                        } else {
                                            $subLabel = htmlspecialchars(ucfirst($u['role']));
                                        }
                                        ?>
                                        <div class="col-md-6">
                                            <div class="form-check">
                                                <input class="form-check-input tim-support-checkbox" type="checkbox"
                                                    name="tim_support_ids[]" value="<?= $u['id'] ?>"
                                                    id="tim-<?= $u['id'] ?>" onchange="limitTimSupport(this)">
                                                <label class="form-check-label" for="tim-<?= $u['id'] ?>">
                                                    <div class="fw-semibold" style="font-size:0.85rem;">
                                                        <?= htmlspecialchars($u['nama_lengkap']) ?>
                                                    </div>
                                                    <div class="text-secondary" style="font-size:0.72rem;"><?= $subLabel ?>
                                                    </div>
                                                </label>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <small class="text-secondary" id="timSupportHint">Maksimal 3 orang dapat dipilih.</small>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold fs-7 mb-1">Status</label>
                            <select name="status" id="form-status" class="select-custom">
                                <option value="Terjadwal">Terjadwal</option>
                                <option value="Reschedule">Reschedule</option>
                                <option value="Berlangsung">Berlangsung</option>
                                <option value="Selesai">Selesai</option>
                                <option value="Dibatalkan">Dibatalkan</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold fs-7 mb-1">Lokasi Pemeriksaan/Survey</label>
                            <input type="text" name="lokasi" id="form-lokasi" class="form-control-custom"
                                placeholder="Nama Gedung, Lantai, atau Detail Alamat">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold fs-7 mb-1">Catatan Tambahan</label>
                            <textarea name="catatan" id="form-catatan" class="textarea-custom"
                                placeholder="Tuliskan instruksi atau memo khusus..."></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-secondary-custom" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn-primary-custom">Simpan Jadwal</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Form Hapus Jadwal (tersembunyi) -->
<form method="POST" action="jadwal.php" id="formHapusJadwal" style="display:none;">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="id" id="hapus-jadwal-id">
</form>

<script>
    // Data klien dari database dipakai untuk autocomplete (nama, pic, kontak)
    const klienData = <?= json_encode($klien_list) ?>;

    // Data seluruh jadwal dipakai untuk kalender & daftar riksa aktif (tanpa reload halaman)
    const jadwalData = <?= json_encode($jadwals) ?>;
    const todayStr = <?= json_encode($today_str) ?>;

    const namaBulan = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
    const namaHari = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];

    let calYear, calMonth, selectedDate;

    document.addEventListener('DOMContentLoaded', function () {
        initTablePagination('tabelJadwal', 10);

        const now = new Date();
        calYear = now.getFullYear();
        calMonth = now.getMonth(); // 0-based
        selectedDate = todayStr;

        renderCalendar();
        renderDaftarAktif(selectedDate);

        // Klik di luar box saran akan menutup daftar saran
        document.addEventListener('click', function (e) {
            const wrap = document.querySelector('.arp-autocomplete-wrap');
            const box = document.getElementById('klien-suggestion-box');
            if (wrap && !wrap.contains(e.target)) {
                box.style.display = 'none';
            }
        });
    });

    function pad2(n) { return n < 10 ? '0' + n : '' + n; }

    function getDateRange(start, end) {
        const dates = [];
        let cur = new Date(start + 'T00:00:00');
        const last = new Date((end || start) + 'T00:00:00');
        while (cur <= last) {
            dates.push(cur.getFullYear() + '-' + pad2(cur.getMonth() + 1) + '-' + pad2(cur.getDate()));
            cur.setDate(cur.getDate() + 1);
        }
        return dates;
    }

    function changeMonth(dir) {
        calMonth += dir;
        if (calMonth < 0) { calMonth = 11; calYear--; }
        if (calMonth > 11) { calMonth = 0; calYear++; }
        renderCalendar();
    }

    function renderCalendar() {
        const grid = document.getElementById('calendarGrid');
        const label = document.getElementById('calendarMonthLabel');
        label.textContent = namaBulan[calMonth] + ' ' + calYear;
        grid.innerHTML = '';

        // Kelompokkan jadwal berdasarkan tanggal, agar tiap tanggal bisa menampilkan nama klien pemeriksaannya
        const eventsByDate = {};
        jadwalData.forEach(j => {
            getDateRange(j.tanggal, j.tanggal_selesai).forEach(dateStr => {
                if (!eventsByDate[dateStr]) eventsByDate[dateStr] = [];
                eventsByDate[dateStr].push(j);
            });
        });

        const firstOfMonth = new Date(calYear, calMonth, 1);
        // Senin = 0 ... Minggu = 6
        let startOffset = (firstOfMonth.getDay() + 6) % 7;
        const daysInMonth = new Date(calYear, calMonth + 1, 0).getDate();
        const maxShow = 2;

        for (let i = 0; i < startOffset; i++) {
            const empty = document.createElement('div');
            empty.className = 'calendar-day empty';
            grid.appendChild(empty);
        }

        for (let d = 1; d <= daysInMonth; d++) {
            const dateStr = calYear + '-' + pad2(calMonth + 1) + '-' + pad2(d);
            const dayEvents = eventsByDate[dateStr] || [];

            const cell = document.createElement('div');
            cell.className = 'calendar-day';
            if (dateStr === todayStr) cell.classList.add('today');
            if (dateStr === selectedDate) cell.classList.add('selected');

            let eventsHtml = '';
            if (dayEvents.length > 0) {
                eventsHtml = '<div class="day-events">' +
                    dayEvents.slice(0, maxShow).map(ev => `<div class="day-event-item">${escapeHtml(ev.nama_perusahaan)}</div>`).join('') +
                    (dayEvents.length > maxShow ? `<div class="day-event-more">+${dayEvents.length - maxShow} lainnya</div>` : '') +
                    '</div>';
            }

            cell.innerHTML = `<div class="day-number">${d}</div>${eventsHtml}`;
            cell.onclick = () => selectDate(dateStr);
            grid.appendChild(cell);
        }
    }

    function selectDate(dateStr) {
        selectedDate = dateStr;
        renderCalendar();
        renderDaftarAktif(dateStr);
    }

    function renderDaftarAktif(dateStr) {
        const list = document.getElementById('daftarRiksaAktif');
        const label = document.getElementById('selectedDateLabel');

        const dObj = new Date(dateStr + 'T00:00:00');
        const formatted = dObj.getDate() + ' ' + namaBulan[dObj.getMonth()] + ' ' + dObj.getFullYear();
        label.textContent = 'Inspeksi untuk tanggal ' + formatted;

        const items = jadwalData
            .filter(j => getDateRange(j.tanggal, j.tanggal_selesai).includes(dateStr))
            .sort((a, b) => a.jam_mulai.localeCompare(b.jam_mulai));

        if (items.length === 0) {
            list.innerHTML = '<div class="riksa-empty"><i class="bi bi-calendar-x fs-3 d-block mb-2"></i>Tidak ada jadwal riksa pada tanggal ini.</div>';
            return;
        }

        list.innerHTML = items.map(j => {
            let badgeClass = 'badge-warning';
            if (j.status === 'Selesai') badgeClass = 'badge-success';
            if (j.status === 'Dibatalkan') badgeClass = 'badge-danger';
            const jamSelesai = j.jam_selesai ? j.jam_selesai.substring(0, 5) : 'Selesai';

            // Ambil nama tim support dari catatan (format: [Tim Support: A, B, C])
            let supportText = 'Unknown';
            if (j.catatan) {
                const match = j.catatan.match(/\[Tim Support:\s*(.+?)\]/);
                if (match && match[1].trim()) {
                    supportText = match[1].trim();
                }
            }
            const leadExpertText = j.nama_ahli ? escapeHtml(j.nama_ahli) + (j.tingkat_ahli ? ' (' + escapeHtml(j.tingkat_ahli) + ')' : '') : 'Unknown';

            return `
    <div class="riksa-card">
        <div class="riksa-jam">${j.jam_mulai.substring(0, 5)} - ${jamSelesai}</div>
        <div class="riksa-client">${escapeHtml(j.nama_perusahaan)}</div>
        <div class="riksa-ahli"><span class="riksa-label">Lead Expert</span><span class="riksa-value">${leadExpertText}</span></div>
        <div class="riksa-ahli"><span class="riksa-label">Support</span><span class="riksa-value">${escapeHtml(supportText)}</span></div>
        <div class="riksa-lokasi">${j.lokasi ? escapeHtml(j.lokasi) : '-'}</div>
        <div class="riksa-dibuat">Dibuat oleh: ${escapeHtml(j.nama_admin || '-')}</div>
        <div class="riksa-footer">
            <span class="${badgeClass}">${escapeHtml(j.status)}</span>
            <div style="display:flex; gap:6px;">
                <button class="btn-secondary-custom" style="height:32px; padding:0 12px; font-size:0.78rem; color:#ffffff; background-color:#dc2626; border-color:#dc2626;"
                    onclick='hapusJadwal(${j.id}, ${JSON.stringify(j.nama_perusahaan)})'>
                    Hapus
                </button>
                <button class="btn-primary-custom" style="height:32px; padding:0 12px; font-size:0.78rem;"
                    data-bs-toggle="modal" data-bs-target="#jadwalModal" onclick='editJadwal(${JSON.stringify(j)})'>
                    Edit
                </button>
            </div>
        </div>
    </div>
`;
        }).join('');
    }

    function searchKlien(keyword) {
        const box = document.getElementById('klien-suggestion-box');
        keyword = (keyword || '').trim().toLowerCase();

        if (keyword === '') {
            box.style.display = 'none';
            box.innerHTML = '';
            return;
        }

        const matches = klienData.filter(k => k.nama_perusahaan.toLowerCase().includes(keyword));

        if (matches.length === 0) {
            box.innerHTML = '<div class="arp-suggestion-empty">Tidak ada perusahaan ditemukan</div>';
            box.style.display = 'block';
            return;
        }

        box.innerHTML = matches.map(k => `
            <div class="arp-suggestion-item" onclick='pilihKlien(${JSON.stringify(k)})'>
                <div class="fw-semibold">${escapeHtml(k.nama_perusahaan)}</div>
                <small class="text-secondary">PIC: ${escapeHtml(k.pic_nama || '-')}</small>
            </div>
        `).join('');
        box.style.display = 'block';
    }

    function pilihKlien(klien) {
        document.getElementById('form-klien-search').value = klien.nama_perusahaan;
        document.getElementById('form-klien-id').value = klien.id;

        const picNamaInput = document.getElementById('form-pic-nama');
        const picKontakInput = document.getElementById('form-pic-kontak');

        // Isi otomatis jika data PIC tersedia di data klien
        picNamaInput.value = klien.pic_nama || '';
        picKontakInput.value = klien.pic_whatsapp || '';

        // Jika data PIC kosong pada klien ini, tandai supaya user tahu harus isi manual
        if (!klien.pic_nama) {
            picNamaInput.placeholder = 'PIC belum terdaftar, isi manual';
        } else {
            picNamaInput.placeholder = 'Otomatis dari data klien, atau isi manual';
        }

        if (!klien.pic_whatsapp) {
            picKontakInput.placeholder = 'Kontak belum terdaftar, isi manual';
        } else {
            picKontakInput.placeholder = 'Otomatis dari data klien, atau isi manual';
        }

        // Sembunyikan daftar saran setelah dipilih
        const box = document.getElementById('klien-suggestion-box');
        box.style.display = 'none';
        box.innerHTML = '';
    }

    function escapeHtml(str) {
        if (!str) return '';
        return str.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    }

    function resetForm() {
        document.getElementById('form-id').value = '';
        document.getElementById('form-klien-id').value = '';
        document.getElementById('form-klien-search').value = '';

        const picNamaInput = document.getElementById('form-pic-nama');
        const picKontakInput = document.getElementById('form-pic-kontak');
        picNamaInput.value = '';
        picKontakInput.value = '';
        picNamaInput.placeholder = 'Otomatis dari data klien, atau isi manual';
        picKontakInput.placeholder = 'Otomatis dari data klien, atau isi manual';

        document.getElementById('form-ahli-id').value = '';
        document.getElementById('form-tanggal').value = selectedDate || '';
        document.getElementById('form-tanggal-selesai').value = '';
        document.getElementById('form-jam-mulai').value = '';
        document.getElementById('form-jam-selesai').value = '';
        document.getElementById('form-status').value = 'Terjadwal';
        document.getElementById('form-lokasi').value = '';
        document.getElementById('form-catatan').value = '';
        document.querySelectorAll('.kategori-objek-checkbox').forEach(cb => cb.checked = false);
        document.querySelectorAll('.tim-support-checkbox').forEach(cb => cb.checked = false);
        document.getElementById('jadwalModalLabel').textContent = 'Buat Jadwal Baru';
    }

    function editJadwal(data) {
        document.getElementById('form-id').value = data.id;
        document.getElementById('form-klien-id').value = data.klien_id;

        const klien = klienData.find(k => k.id == data.klien_id);
        const picNamaInput = document.getElementById('form-pic-nama');
        const picKontakInput = document.getElementById('form-pic-kontak');

        if (klien) {
            document.getElementById('form-klien-search').value = klien.nama_perusahaan;
            picNamaInput.value = klien.pic_nama || '';
            picKontakInput.value = klien.pic_whatsapp || '';
        } else {
            document.getElementById('form-klien-search').value = data.nama_perusahaan || '';
            picNamaInput.value = '';
            picKontakInput.value = '';
        }

        document.getElementById('form-ahli-id').value = data.ahli_k3_id;
        document.getElementById('form-tanggal').value = data.tanggal;
        document.getElementById('form-tanggal-selesai').value = data.tanggal_selesai || '';
        document.getElementById('form-jam-mulai').value = data.jam_mulai;
        document.getElementById('form-jam-selesai').value = data.jam_selesai || '';
        document.getElementById('form-status').value = data.status;
        document.getElementById('form-lokasi').value = data.lokasi || '';
        document.getElementById('form-catatan').value = data.catatan || '';

        document.querySelectorAll('.kategori-objek-checkbox').forEach(cb => cb.checked = false);
        if (data.kategori_objek_ids) {
            String(data.kategori_objek_ids).split(',').filter(Boolean).forEach(id => {
                const cb = document.getElementById('kat-' + id);
                if (cb) cb.checked = true;
            });
        }

        document.querySelectorAll('.tim-support-checkbox').forEach(cb => cb.checked = false);
        if (data.tim_support_ids) {
            String(data.tim_support_ids).split(',').filter(Boolean).forEach(id => {
                const cb = document.getElementById('tim-' + id);
                if (cb) cb.checked = true;
            });
        }

        document.getElementById('jadwalModalLabel').textContent = 'Edit Jadwal Pemeriksaan';
    }

    function hapusJadwal(id, namaPerusahaan) {
        if (confirm('Yakin ingin menghapus jadwal riksa untuk "' + namaPerusahaan + '"? Tindakan ini tidak dapat dibatalkan.')) {
            document.getElementById('hapus-jadwal-id').value = id;
            document.getElementById('formHapusJadwal').submit();
        }
    }

    function limitTimSupport(checkbox) {
        const checked = document.querySelectorAll('.tim-support-checkbox:checked');
        const hint = document.getElementById('timSupportHint');
        if (checked.length > 3) {
            checkbox.checked = false;
            hint.textContent = 'Maksimal 3 orang dapat dipilih!';
            hint.classList.add('text-danger');
            hint.classList.remove('text-secondary');
        } else {
            hint.textContent = 'Maksimal 3 orang dapat dipilih.';
            hint.classList.remove('text-danger');
            hint.classList.add('text-secondary');
        }
    }
</script>

<?php
include "../includes/footer.php";
?>