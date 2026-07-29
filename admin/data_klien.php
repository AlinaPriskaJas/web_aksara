<?php
// admin/data_klien.php
session_start();
require_once "../config/koneksi.php";

// TODO: ganti dengan user_id dari sesi login sebenarnya (proses_login.php belum tersambung penuh).
$admin_id = $_SESSION['user_id'] ?? 1;

$page_title = "Data Klien";
$flash = null;

// ================== PROSES: TAUTKAN KE DATA_KLIEN YANG SUDAH ADA ==================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['aksi']) && $_POST['aksi'] === 'tautkan_existing') {
    $user_id  = (int) ($_POST['user_id'] ?? 0);
    $klien_id = (int) ($_POST['klien_id'] ?? 0);

    if (!$user_id || !$klien_id) {
        $flash = ['type' => 'danger', 'message' => 'Pilih perusahaan yang ingin ditautkan.'];
    } else {
        try {
            // Guard: hanya tautkan kalau baris Data_Klien itu memang belum ditautkan ke user manapun
            $stmt = $conn->prepare("UPDATE Data_Klien SET user_id = :user_id WHERE id = :klien_id AND user_id IS NULL");
            $stmt->execute([':user_id' => $user_id, ':klien_id' => $klien_id]);

            if ($stmt->rowCount() > 0) {
                $flash = ['type' => 'success', 'message' => 'Akun client berhasil ditautkan ke data perusahaan.'];

                // Beri tahu client bahwa akunnya sudah ditautkan ke data perusahaan
                try {
                    $stmtNamaKlien = $conn->prepare("SELECT nama_perusahaan FROM Data_Klien WHERE id = :id");
                    $stmtNamaKlien->execute([':id' => $klien_id]);
                    $namaPerusahaanKlien = $stmtNamaKlien->fetchColumn() ?: '-';

                    $stmtNotifTautkan = $conn->prepare("
                        INSERT INTO Notifikasi (user_id, judul, pesan, modul_terkait, ref_id, sudah_dibaca)
                        VALUES (:user_id, :judul, :pesan, 'Data_Klien', :ref_id, 0)
                    ");
                    $stmtNotifTautkan->execute([
                        ':user_id' => $user_id,
                        ':judul'   => 'Akun Berhasil Ditautkan',
                        ':pesan'   => 'Akun Anda telah ditautkan ke data perusahaan "' . $namaPerusahaanKlien . '" oleh Admin.',
                        ':ref_id'  => $klien_id,
                    ]);
                } catch (PDOException $e) {
                    // Jangan gagalkan proses tautan hanya karena notifikasi gagal dibuat
                }
            } else {
                $flash = ['type' => 'danger', 'message' => 'Data perusahaan tersebut sudah ditautkan ke akun lain. Pilih perusahaan lain atau buat baru.'];
            }
        } catch (PDOException $e) {
            $flash = ['type' => 'danger', 'message' => 'Gagal menautkan: ' . $e->getMessage()];
        }
    }
    $_SESSION['data_klien_flash'] = $flash;
    header("Location: data_klien.php");
    exit;
}

// ================== PROSES: BUAT DATA_KLIEN BARU SEKALIGUS TAUTKAN ==================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['aksi']) && $_POST['aksi'] === 'tautkan_baru') {
    $user_id         = (int) ($_POST['user_id'] ?? 0);
    $nama_perusahaan = trim($_POST['nama_perusahaan'] ?? '');
    $alamat          = trim($_POST['alamat'] ?? '');
    $pic_nama        = trim($_POST['pic_nama'] ?? '');
    $pic_whatsapp    = trim($_POST['pic_whatsapp'] ?? '');
    $pic_email       = trim($_POST['pic_email'] ?? '');

    if (!$user_id || $nama_perusahaan === '') {
        $flash = ['type' => 'danger', 'message' => 'Nama perusahaan wajib diisi.'];
    } else {
        try {
            $conn->beginTransaction();

            // Generate kode_klien otomatis: KLN-0001, KLN-0002, dst.
            $stmt = $conn->query("SELECT COUNT(*) FROM Data_Klien");
            $urutan = (int) $stmt->fetchColumn() + 1;
            $kode_klien = 'KLN-' . str_pad((string) $urutan, 4, '0', STR_PAD_LEFT);

            // Pastikan kode_klien belum terpakai (jaga-jaga kalau ada penghapusan data sebelumnya)
            $cekKode = $conn->prepare("SELECT COUNT(*) FROM Data_Klien WHERE kode_klien = :kode");
            $cekKode->execute([':kode' => $kode_klien]);
            while ((int) $cekKode->fetchColumn() > 0) {
                $urutan++;
                $kode_klien = 'KLN-' . str_pad((string) $urutan, 4, '0', STR_PAD_LEFT);
                $cekKode->execute([':kode' => $kode_klien]);
            }

            $stmt = $conn->prepare("
                INSERT INTO Data_Klien (kode_klien, nama_perusahaan, alamat, status, pic_nama, pic_whatsapp, pic_email, user_id)
                VALUES (:kode_klien, :nama_perusahaan, :alamat, 'Aktif', :pic_nama, :pic_whatsapp, :pic_email, :user_id)
            ");
            $stmt->execute([
                ':kode_klien'      => $kode_klien,
                ':nama_perusahaan' => $nama_perusahaan,
                ':alamat'          => $alamat,
                ':pic_nama'        => $pic_nama,
                ':pic_whatsapp'    => $pic_whatsapp,
                ':pic_email'       => $pic_email,
                ':user_id'         => $user_id,
            ]);
            $klien_id_baru = (int) $conn->lastInsertId();

            $conn->commit();
            $flash = ['type' => 'success', 'message' => "Data perusahaan baru ($kode_klien) berhasil dibuat dan ditautkan ke akun client."];

            // Beri tahu client bahwa akunnya sudah ditautkan ke data perusahaan baru
            try {
                $stmtNotifTautkanBaru = $conn->prepare("
                    INSERT INTO Notifikasi (user_id, judul, pesan, modul_terkait, ref_id, sudah_dibaca)
                    VALUES (:user_id, :judul, :pesan, 'Data_Klien', :ref_id, 0)
                ");
                $stmtNotifTautkanBaru->execute([
                    ':user_id' => $user_id,
                    ':judul'   => 'Akun Berhasil Ditautkan',
                    ':pesan'   => 'Akun Anda telah ditautkan ke data perusahaan "' . $nama_perusahaan . '" (' . $kode_klien . ') oleh Admin.',
                    ':ref_id'  => $klien_id_baru,
                ]);
            } catch (PDOException $e) {
                // Jangan gagalkan proses tautan hanya karena notifikasi gagal dibuat
            }
        } catch (PDOException $e) {
            $conn->rollBack();
            $flash = ['type' => 'danger', 'message' => 'Gagal membuat data perusahaan: ' . $e->getMessage()];
        }
    }
    $_SESSION['data_klien_flash'] = $flash;
    header("Location: data_klien.php");
    exit;
}

// ================== PROSES: EDIT DATA PERUSAHAAN YANG SUDAH DITAUTKAN ==================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['aksi']) && $_POST['aksi'] === 'edit_klien') {
    $klien_id        = (int) ($_POST['klien_id'] ?? 0);
    $nama_perusahaan = trim($_POST['nama_perusahaan'] ?? '');
    $alamat          = trim($_POST['alamat'] ?? '');
    $status          = $_POST['status'] ?? 'Aktif';
    $pic_nama        = trim($_POST['pic_nama'] ?? '');
    $pic_whatsapp    = trim($_POST['pic_whatsapp'] ?? '');
    $pic_email       = trim($_POST['pic_email'] ?? '');

    if (!$klien_id || $nama_perusahaan === '' || !in_array($status, ['Aktif', 'Non-aktif'], true)) {
        $flash = ['type' => 'danger', 'message' => 'Data tidak valid.'];
    } else {
        try {
            $stmt = $conn->prepare("
                UPDATE Data_Klien
                SET nama_perusahaan = :nama_perusahaan, alamat = :alamat, status = :status,
                    pic_nama = :pic_nama, pic_whatsapp = :pic_whatsapp, pic_email = :pic_email
                WHERE id = :id
            ");
            $stmt->execute([
                ':nama_perusahaan' => $nama_perusahaan,
                ':alamat'          => $alamat,
                ':status'          => $status,
                ':pic_nama'        => $pic_nama,
                ':pic_whatsapp'    => $pic_whatsapp,
                ':pic_email'       => $pic_email,
                ':id'              => $klien_id,
            ]);
            $flash = ['type' => 'success', 'message' => 'Data perusahaan berhasil diperbarui.'];
        } catch (PDOException $e) {
            $flash = ['type' => 'danger', 'message' => 'Gagal memperbarui data: ' . $e->getMessage()];
        }
    }
    $_SESSION['data_klien_flash'] = $flash;
    header("Location: data_klien.php");
    exit;
}

// ================== PROSES: IMPORT DATA KLIEN DARI FILE (.xlsx / .csv) ==================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['aksi']) && $_POST['aksi'] === 'import_klien') {
    require_once "../includes/functions.php";

    $total = 0; $berhasil = 0; $gagal = 0; $duplikat = 0;
    $daftarError = [];
    $namaFileAsli = $_FILES['file_import']['name'] ?? '-';

    if (!isset($_FILES['file_import']) || $_FILES['file_import']['error'] !== UPLOAD_ERR_OK) {
        $flash = ['type' => 'danger', 'message' => 'File import wajib dipilih.'];
    } else {
        $ext = strtolower(pathinfo($_FILES['file_import']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['xlsx', 'csv'], true)) {
            $flash = ['type' => 'danger', 'message' => 'Format file tidak didukung. Gunakan .xlsx atau .csv.'];
        } else {
            try {
                $baris = bacaBarisSpreadsheet($_FILES['file_import']['tmp_name'], $ext);
                if (empty($baris)) {
                    throw new RuntimeException('File kosong / tidak ada data.');
                }

                // ---- Baca baris header (baris pertama) & petakan hanya kolom yang dikenali ----
                $headerRow = array_shift($baris);
                $petaHeader = petaHeaderImportKlien();
                $kolomTerpakai = []; // ['A' => 'nama_perusahaan', 'C' => 'pic_nama', ...]
                foreach ($headerRow as $kolomHuruf => $teksHeader) {
                    $key = normalisasiHeaderKlien((string) $teksHeader);
                    if (isset($petaHeader[$key])) {
                        $kolomTerpakai[$kolomHuruf] = $petaHeader[$key];
                    }
                    // Header yang tidak dikenali sengaja dilewati (tidak dipetakan)
                }

                if (!in_array('nama_perusahaan', $kolomTerpakai, true)) {
                    throw new RuntimeException('Kolom "NAMA PERUSAHAAN" tidak ditemukan di file. Pastikan header sesuai template.');
                }

                $total = count($baris);
                $conn->beginTransaction();

                $urutan = (int) $conn->query("SELECT COUNT(*) FROM Data_Klien")->fetchColumn();
                $cekDuplikat = $conn->prepare("SELECT id FROM Data_Klien WHERE LOWER(nama_perusahaan) = LOWER(:nama) LIMIT 1");
                $cekKode     = $conn->prepare("SELECT COUNT(*) FROM Data_Klien WHERE kode_klien = :kode");
                $insertKlien = $conn->prepare("
                    INSERT INTO Data_Klien (kode_klien, nama_perusahaan, status, pic_nama, jabatan_pic, pic_whatsapp, pic_email)
                    VALUES (:kode_klien, :nama_perusahaan, :status, :pic_nama, :jabatan_pic, :pic_whatsapp, :pic_email)
                ");

                foreach ($baris as $i => $r) {
                    $baris_ke = $i + 2; // +2: baris 1 = header

                    $data = ['nama_perusahaan' => '', 'pic_nama' => '', 'jabatan_pic' => '', 'pic_whatsapp' => '', 'pic_email' => '', 'status' => ''];
                    foreach ($kolomTerpakai as $kolomHuruf => $field) {
                        $data[$field] = trim((string) ($r[$kolomHuruf] ?? ''));
                    }

                    // Lewati baris kosong total (tidak dihitung sama sekali)
                    if ($data['nama_perusahaan'] === '' && $data['pic_nama'] === '' && $data['pic_email'] === '') {
                        $total--;
                        continue;
                    }

                    if ($data['nama_perusahaan'] === '') {
                        $gagal++;
                        $daftarError[] = "Baris $baris_ke: Nama Perusahaan kosong.";
                        continue;
                    }

                    $cekDuplikat->execute([':nama' => $data['nama_perusahaan']]);
                    if ($cekDuplikat->fetch()) {
                        $duplikat++;
                        $daftarError[] = "Baris $baris_ke: \"{$data['nama_perusahaan']}\" sudah ada di Data Klien (dilewati).";
                        continue;
                    }

                    $statusNormal = strtolower($data['status']);
                    $status = (strpos($statusNormal, 'non') !== false || strpos($statusNormal, 'tidak') !== false)
                        ? 'Non-aktif' : 'Aktif';

                    do {
                        $urutan++;
                        $kode_klien = 'KLN-' . str_pad((string) $urutan, 4, '0', STR_PAD_LEFT);
                        $cekKode->execute([':kode' => $kode_klien]);
                    } while ((int) $cekKode->fetchColumn() > 0);

                    $insertKlien->execute([
                        ':kode_klien'      => $kode_klien,
                        ':nama_perusahaan' => $data['nama_perusahaan'],
                        ':status'          => $status,
                        ':pic_nama'        => $data['pic_nama'],
                        ':jabatan_pic'     => $data['jabatan_pic'],
                        ':pic_whatsapp'    => $data['pic_whatsapp'],
                        ':pic_email'       => $data['pic_email'],
                    ]);
                    $berhasil++;
                }

                $conn->commit();

                $conn->prepare("
                    INSERT INTO Import_Log (nama_file, total_baris, berhasil, gagal, duplikat, detail_error, diupload_oleh)
                    VALUES (:nama_file, :total, :berhasil, :gagal, :duplikat, :detail, :user_id)
                ")->execute([
                    ':nama_file' => $namaFileAsli,
                    ':total'     => $total,
                    ':berhasil'  => $berhasil,
                    ':gagal'     => $gagal,
                    ':duplikat'  => $duplikat,
                    ':detail'    => implode("\n", $daftarError) ?: null,
                    ':user_id'   => $admin_id,
                ]);

                $flash = [
                    'type'    => $berhasil > 0 ? 'success' : 'danger',
                    'message' => "Import selesai: {$berhasil} berhasil, {$duplikat} duplikat dilewati, {$gagal} gagal dari {$total} baris data.",
                ];
            } catch (\Throwable $e) {
                if ($conn->inTransaction()) {
                    $conn->rollBack();
                }
                $flash = ['type' => 'danger', 'message' => 'Gagal import: ' . $e->getMessage()];
            }
        }
    }

    $_SESSION['data_klien_flash'] = $flash;
    header("Location: data_klien.php?tab=daftar");
    exit;
}

$flash = $_SESSION['data_klien_flash'] ?? $flash;
unset($_SESSION['data_klien_flash']);

// ================== DAFTAR SEMUA AKUN CLIENT (Users role='client') + STATUS TAUTAN ==================
$daftar_client = [];
try {
    $stmt = $conn->query("
        SELECT u.id AS user_id, u.nama_lengkap, u.email, u.created_at AS tgl_registrasi,
               dk.id AS klien_id, dk.kode_klien, dk.nama_perusahaan, dk.alamat, dk.status,
               dk.pic_nama, dk.pic_whatsapp, dk.pic_email
        FROM Users u
        LEFT JOIN Data_Klien dk ON dk.user_id = u.id
        WHERE u.role = 'client'
        ORDER BY u.created_at DESC
    ");
    $daftar_client = $stmt->fetchAll();
} catch (PDOException $e) {
    $daftar_client = [];
}

$total_client   = count($daftar_client);
$sudah_tertaut  = 0;
$belum_tertaut  = 0;
foreach ($daftar_client as $c) {
    if ($c['klien_id']) {
        $sudah_tertaut++;
    } else {
        $belum_tertaut++;
    }
}

// ================== DAFTAR DATA_KLIEN YANG BELUM PUNYA user_id (untuk opsi "tautkan ke existing") ==================
$klien_belum_tertaut = [];
try {
    $stmt = $conn->query("SELECT id, kode_klien, nama_perusahaan FROM Data_Klien WHERE user_id IS NULL ORDER BY nama_perusahaan ASC");
    $klien_belum_tertaut = $stmt->fetchAll();
} catch (PDOException $e) {
    $klien_belum_tertaut = [];
}

// ================== DAFTAR SEMUA DATA_KLIEN (Tab "Daftar Client") ==================
$semua_klien = [];
try {
    $stmt = $conn->query("
        SELECT id, kode_klien, nama_perusahaan, pic_nama, jabatan_pic, pic_whatsapp, pic_email, status
        FROM Data_Klien
        ORDER BY nama_perusahaan ASC
    ");
    $semua_klien = $stmt->fetchAll();
} catch (PDOException $e) {
    $semua_klien = [];
}

$active_tab_klien = (($_GET['tab'] ?? '') === 'daftar') ? 'tabPanelDaftarKlien' : 'tabPanelAkunKlien';

include "../includes/header.php";
include "../includes/sidebar.php";
include "../includes/topbar.php";
?>

<style>
/* ---- Sub-tab di dalam form (Data Perusahaan / Data PIC) ---- */
.subtab-nav {
    display: flex;
    gap: 4px;
    border-bottom: 1px solid #e2e8f0;
    list-style: none;
    padding: 0;
    margin: 0 0 1rem 0;
}
.subtab-nav .subtab-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 16px;
    font-size: 0.82rem;
    font-weight: 600;
    color: #64748b;
    background: transparent;
    border: none;
    border-bottom: 2px solid transparent;
    border-radius: 8px 8px 0 0;
    cursor: pointer;
    transition: all .15s ease;
}
.subtab-nav .subtab-btn:hover {
    color: #4338ca;
    background: #f8fafc;
}
.subtab-nav .subtab-btn.active {
    color: #4338ca;
    border-bottom-color: #4338ca;
    background: #eef2ff;
}

/* ---- Tab utama modal Tautkan: "Pilih Perusahaan yang Sudah Ada" / "Buat Perusahaan Baru" ---- */
.maintab-nav {
    display: flex;
    gap: 4px;
    border-bottom: 2px solid #e2e8f0;
    list-style: none;
    padding: 0;
    margin: 0 0 1.25rem 0;
}
.maintab-nav .nav-item {
    flex: 1;
}
.maintab-nav .nav-link {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    width: 100%;
    padding: 10px 14px;
    font-size: 0.85rem;
    font-weight: 600;
    color: #64748b;
    background: transparent;
    border: none;
    border-bottom: 2px solid transparent;
    margin-bottom: -2px;
    border-radius: 8px 8px 0 0;
    cursor: pointer;
    transition: all .15s ease;
    text-align: center;
}
.maintab-nav .nav-link:hover {
    color: #4338ca;
    background: #f8fafc;
}
.maintab-nav .nav-link.active {
    color: #4338ca;
    border-bottom-color: #4338ca;
    background: #eef2ff;
}
</style>

<main class="main-content">

    <?php if ($flash): ?>
        <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?>-custom mb-3">
            <i class="bi <?= $flash['type'] === 'success' ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill' ?> fs-5"></i>
            <div><?= htmlspecialchars($flash['message']) ?></div>
        </div>
    <?php endif; ?>

    <!-- Ringkasan -->
    <div class="row g-4 mb-4">
        <div class="col-xl-4 col-md-6 col-12">
            <div class="stat-card">
                <div class="stat-card-info">
                    <span class="stat-card-title">Total Client Terdaftar</span>
                    <span class="stat-card-value"><?= $total_client ?></span>
                </div>
                <div class="stat-card-icon"><i class="bi bi-people-fill"></i></div>
            </div>
        </div>
        <div class="col-xl-4 col-md-6 col-12">
            <div class="stat-card">
                <div class="stat-card-info">
                    <span class="stat-card-title">Sudah Ditautkan</span>
                    <span class="stat-card-value"><?= $sudah_tertaut ?></span>
                </div>
                <div class="stat-card-icon success"><i class="bi bi-link-45deg"></i></div>
            </div>
        </div>
        <div class="col-xl-4 col-md-6 col-12">
            <div class="stat-card">
                <div class="stat-card-info">
                    <span class="stat-card-title">Belum Ditautkan</span>
                    <span class="stat-card-value"><?= $belum_tertaut ?></span>
                </div>
                <div class="stat-card-icon warning"><i class="bi bi-exclamation-triangle-fill"></i></div>
            </div>
        </div>
    </div>

    <!-- Tab Navigation -->
    <div class="arp-tab-group">
        <div class="arp-tab-nav">
            <button type="button" class="arp-tab-btn<?= $active_tab_klien === 'tabPanelAkunKlien' ? ' active' : '' ?>"
                data-tab-target="tabPanelAkunKlien" onclick="switchTab('tabPanelAkunKlien', this)">
                <i class="bi bi-people-fill me-1"></i> Akun Client
            </button>
            <button type="button" class="arp-tab-btn<?= $active_tab_klien === 'tabPanelDaftarKlien' ? ' active' : '' ?>"
                data-tab-target="tabPanelDaftarKlien" onclick="switchTab('tabPanelDaftarKlien', this)">
                <i class="bi bi-building me-1"></i> Daftar Client
            </button>
        </div>

        <div class="row g-4">
            <!-- Panel 1: Akun Client Terdaftar -->
            <div class="col-12 arp-tab-panel" id="tabPanelAkunKlien" <?= $active_tab_klien === 'tabPanelAkunKlien' ? '' : 'style="display:none;"' ?>>
                <div class="card-box">
                    <div class="table-toolbar">
                        <h5 class="table-toolbar-title fw-bold">Akun Client Terdaftar</h5>
                    </div>

                    <div class="table-responsive-custom">
                        <table class="table-custom">
                            <thead>
                                <tr>
                                    <th>No</th>
                                    <th>Nama Akun</th>
                                    <th>Email Login</th>
                                    <th>Tgl. Registrasi</th>
                                    <th>Kode Klien</th>
                                    <th>Nama Perusahaan</th>
                                    <th>Status Tautan</th>
                                    <th style="text-align: center;">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($daftar_client)): ?>
                                    <tr>
                                        <td colspan="8" class="text-center text-muted py-4">Belum ada akun client yang registrasi.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($daftar_client as $i => $c): ?>
                                        <tr>
                                            <td><?= $i + 1 ?></td>
                                            <td><?= htmlspecialchars($c['nama_lengkap']) ?></td>
                                            <td><?= htmlspecialchars($c['email']) ?></td>
                                            <td><?= date('d M Y', strtotime($c['tgl_registrasi'])) ?></td>
                                            <td>
                                                <?php if ($c['klien_id']): ?>
                                                    <?= htmlspecialchars($c['kode_klien']) ?>
                                                <?php else: ?>
                                                    <span class="text-muted fs-7">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($c['klien_id']): ?>
                                                    <?= htmlspecialchars($c['nama_perusahaan']) ?>
                                                <?php else: ?>
                                                    <span class="text-muted fs-7">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($c['klien_id']): ?>
                                                    <span class="badge-success">Sudah Ditautkan</span>
                                                <?php else: ?>
                                                    <span class="badge-warning">Belum Ditautkan</span>
                                                <?php endif; ?>
                                            </td>
                                            <td style="text-align: center;">
                                                <?php if ($c['klien_id']): ?>
                                                    <button type="button" class="btn-secondary-custom" style="height:32px; padding:0 12px; font-size:0.8rem;"
                                                        onclick='openEditModal(<?= json_encode([
                                                            "klien_id" => $c["klien_id"],
                                                            "nama_perusahaan" => $c["nama_perusahaan"],
                                                            "alamat" => $c["alamat"],
                                                            "status" => $c["status"],
                                                            "pic_nama" => $c["pic_nama"],
                                                            "pic_whatsapp" => $c["pic_whatsapp"],
                                                            "pic_email" => $c["pic_email"],
                                                        ]) ?>)'>
                                                        <i class="bi bi-pencil-square"></i> Edit
                                                    </button>
                                                <?php else: ?>
                                                    <button type="button" class="btn-primary-custom" style="height:32px; padding:0 12px; font-size:0.8rem;"
                                                        onclick="openTautkanModal(<?= (int) $c['user_id'] ?>, '<?= htmlspecialchars(addslashes($c['nama_lengkap'])) ?>')">
                                                        <i class="bi bi-link-45deg"></i> Tautkan
                                                    </button>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Panel 2: Daftar Client (baru, dengan Import Data Klien) -->
            <div class="col-12 arp-tab-panel" id="tabPanelDaftarKlien" <?= $active_tab_klien === 'tabPanelDaftarKlien' ? '' : 'style="display:none;"' ?>>
                <div class="card-box">
                    <div class="table-toolbar">
                        <h5 class="table-toolbar-title fw-bold">Daftar Data Klien</h5>
                        <div class="table-toolbar-actions">
                            <div class="search-box-container">
                                <i class="bi bi-search"></i>
                                <input type="text" class="search-box" placeholder="Cari perusahaan..."
                                    data-table-search="tabelDaftarKlien" onkeyup="handleTableSearch('tabelDaftarKlien')">
                            </div>
                            <button type="button" class="btn-primary-custom"
                                onclick="new bootstrap.Modal(document.getElementById('modalImportKlien')).show()">
                                <i class="bi bi-file-earmark-arrow-up"></i> Import Data Klien
                            </button>
                        </div>
                    </div>
                    <div class="table-responsive-custom">
                        <table class="table-custom" id="tabelDaftarKlien">
                            <thead>
                                <tr>
                                    <th>Kode Klien</th>
                                    <th>Nama Perusahaan</th>
                                    <th>Nama PIC</th>
                                    <th>Jabatan</th>
                                    <th>No. HP/WhatsApp</th>
                                    <th>Email</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($semua_klien)): ?>
                                    <tr>
                                        <td colspan="7" class="text-center text-muted py-4">Belum ada data klien.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($semua_klien as $k): ?>
                                        <tr>
                                            <td><strong><?= htmlspecialchars($k['kode_klien']) ?></strong></td>
                                            <td><?= htmlspecialchars($k['nama_perusahaan']) ?></td>
                                            <td><?= htmlspecialchars($k['pic_nama'] ?: '-') ?></td>
                                            <td><?= htmlspecialchars($k['jabatan_pic'] ?: '-') ?></td>
                                            <td><?= htmlspecialchars($k['pic_whatsapp'] ?: '-') ?></td>
                                            <td><?= htmlspecialchars($k['pic_email'] ?: '-') ?></td>
                                            <td>
                                                <?php if ($k['status'] === 'Aktif'): ?>
                                                    <span class="badge-success">Aktif</span>
                                                <?php else: ?>
                                                    <span class="badge-danger">Non-aktif</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="pagination-custom" id="pagination-tabelDaftarKlien"></div>
                </div>
            </div>
        </div>
    </div>
</main>

<!-- ===== MODAL: Tautkan Akun Client ===== -->
<div class="modal fade modal-custom" id="modalTautkan" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Tautkan Akun Client: <span id="tautkanNamaAkun">-</span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <ul class="maintab-nav" id="tabTautkan">
                    <li class="nav-item">
                        <button class="nav-link active" type="button" onclick="gantiTabTautkan('existing')" id="btnTabExisting">
                            <i class="bi bi-search"></i> Pilih Perusahaan yang Sudah Ada
                        </button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link" type="button" onclick="gantiTabTautkan('baru')" id="btnTabBaru">
                            <i class="bi bi-plus-lg"></i> Buat Perusahaan Baru
                        </button>
                    </li>
                </ul>

                <!-- Tab: Tautkan ke Data_Klien existing -->
                <form action="data_klien.php" method="POST" id="formTautkanExisting">
                    <input type="hidden" name="aksi" value="tautkan_existing">
                    <input type="hidden" name="user_id" id="tautkanExistingUserId" value="">

                    <label class="form-label fw-semibold fs-7 mb-2">Pilih Perusahaan</label>
                    <select class="select-custom mb-3" name="klien_id" required>
                        <option value="">-- Pilih Perusahaan --</option>
                        <?php foreach ($klien_belum_tertaut as $k): ?>
                            <option value="<?= (int) $k['id'] ?>">
                                <?= htmlspecialchars($k['nama_perusahaan']) ?> (<?= htmlspecialchars($k['kode_klien']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (empty($klien_belum_tertaut)): ?>
                        <p class="text-muted fs-7">Tidak ada data perusahaan yang belum ditautkan. Gunakan tab "Buat Perusahaan Baru".</p>
                    <?php endif; ?>

                    <div class="d-flex gap-2 mt-3">
                        <button type="button" class="btn-secondary-custom flex-grow-1" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" class="btn-primary-custom flex-grow-1" <?= empty($klien_belum_tertaut) ? 'disabled' : '' ?>>
                            <i class="bi bi-link-45deg me-1"></i> Tautkan
                        </button>
                    </div>
                </form>

                <!-- Tab: Buat Data_Klien baru -->
                <form action="data_klien.php" method="POST" id="formTautkanBaru" style="display:none;">
                    <input type="hidden" name="aksi" value="tautkan_baru">
                    <input type="hidden" name="user_id" id="tautkanBaruUserId" value="">

                    <ul class="subtab-nav">
                        <li>
                            <button type="button" class="subtab-btn active" data-subtab-group="baru" data-subtab="perusahaan" onclick="gantiSubTab('baru', 'perusahaan')">
                                <i class="bi bi-building"></i> Data Perusahaan
                            </button>
                        </li>
                        <li>
                            <button type="button" class="subtab-btn" data-subtab-group="baru" data-subtab="pic" onclick="gantiSubTab('baru', 'pic')">
                                <i class="bi bi-person-vcard"></i> Data PIC
                            </button>
                        </li>
                    </ul>

                    <div data-subtab-group="baru" data-subtab-panel="perusahaan" class="subtab-panel">
                        <div class="mb-3">
                            <label class="form-label fw-semibold fs-7 mb-2">Nama Perusahaan *</label>
                            <input type="text" name="nama_perusahaan" class="form-control-custom" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold fs-7 mb-2">Alamat</label>
                            <textarea class="textarea-custom" name="alamat"></textarea>
                        </div>
                    </div>

                    <div data-subtab-group="baru" data-subtab-panel="pic" class="subtab-panel" style="display:none;">
                        <div class="mb-3">
                            <label class="form-label fw-semibold fs-7 mb-2">Nama PIC</label>
                            <input type="text" name="pic_nama" class="form-control-custom">
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold fs-7 mb-2">WhatsApp PIC</label>
                            <input type="text" name="pic_whatsapp" class="form-control-custom">
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold fs-7 mb-2">Email PIC</label>
                            <input type="email" name="pic_email" class="form-control-custom">
                        </div>
                    </div>

                    <div class="d-flex gap-2 mt-3">
                        <button type="button" class="btn-secondary-custom flex-grow-1" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" class="btn-primary-custom flex-grow-1">
                            <i class="bi bi-plus-lg me-1"></i> Buat &amp; Tautkan
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- ===== MODAL: Edit Data Perusahaan ===== -->
<div class="modal fade modal-custom" id="modalEditKlien" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form action="data_klien.php" method="POST">
                <input type="hidden" name="aksi" value="edit_klien">
                <input type="hidden" name="klien_id" id="editKlienId" value="">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Data Perusahaan</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <ul class="subtab-nav">
                        <li>
                            <button type="button" class="subtab-btn active" data-subtab-group="edit" data-subtab="perusahaan" onclick="gantiSubTab('edit', 'perusahaan')">
                                <i class="bi bi-building"></i> Data Perusahaan
                            </button>
                        </li>
                        <li>
                            <button type="button" class="subtab-btn" data-subtab-group="edit" data-subtab="pic" onclick="gantiSubTab('edit', 'pic')">
                                <i class="bi bi-person-vcard"></i> Data PIC
                            </button>
                        </li>
                    </ul>

                    <div data-subtab-group="edit" data-subtab-panel="perusahaan" class="subtab-panel">
                        <div class="mb-3">
                            <label class="form-label fw-semibold fs-7 mb-2">Nama Perusahaan *</label>
                            <input type="text" name="nama_perusahaan" id="editNamaPerusahaan" class="form-control-custom" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold fs-7 mb-2">Alamat</label>
                            <textarea class="textarea-custom" name="alamat" id="editAlamat"></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold fs-7 mb-2">Status</label>
                            <select class="select-custom" name="status" id="editStatus">
                                <option value="Aktif">Aktif</option>
                                <option value="Non-aktif">Non-aktif</option>
                            </select>
                        </div>
                    </div>

                    <div data-subtab-group="edit" data-subtab-panel="pic" class="subtab-panel" style="display:none;">
                        <div class="mb-3">
                            <label class="form-label fw-semibold fs-7 mb-2">Nama PIC</label>
                            <input type="text" name="pic_nama" id="editPicNama" class="form-control-custom">
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold fs-7 mb-2">WhatsApp PIC</label>
                            <input type="text" name="pic_whatsapp" id="editPicWhatsapp" class="form-control-custom">
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold fs-7 mb-2">Email PIC</label>
                            <input type="email" name="pic_email" id="editPicEmail" class="form-control-custom">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-secondary-custom" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn-primary-custom">Simpan Perubahan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ===== MODAL: Import Data Klien ===== -->
<div class="modal fade modal-custom" id="modalImportKlien" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form action="data_klien.php" method="POST" enctype="multipart/form-data">
                <input type="hidden" name="aksi" value="import_klien">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-file-earmark-arrow-up me-2"></i>Import Data Klien</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-success-custom text-xs mb-3">
                        <i class="bi bi-info-circle-fill"></i>
                        <div>
                            File harus punya baris header berikut (urutan bebas):<br>
                            <strong>NAMA PERUSAHAAN, Nama PIC, Jabatan, No. HP/WhatsApp, Email, Status Client</strong><br>
                            Kolom di luar daftar ini otomatis diabaikan. Format file: <strong>.xlsx</strong> atau <strong>.csv</strong>.
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold fs-7 mb-2">Pilih File *</label>
                        <input type="file" name="file_import" class="form-control-custom" accept=".xlsx,.csv" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-secondary-custom" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn-primary-custom">
                        <i class="bi bi-upload me-1"></i> Import Sekarang
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function openTautkanModal(userId, namaAkun) {
    document.getElementById('tautkanNamaAkun').textContent = namaAkun;
    document.getElementById('tautkanExistingUserId').value = userId;
    document.getElementById('tautkanBaruUserId').value = userId;
    gantiTabTautkan('existing');
    gantiSubTab('baru', 'perusahaan');
    new bootstrap.Modal(document.getElementById('modalTautkan')).show();
}

function gantiTabTautkan(tab) {
    const btnExisting = document.getElementById('btnTabExisting');
    const btnBaru = document.getElementById('btnTabBaru');
    const formExisting = document.getElementById('formTautkanExisting');
    const formBaru = document.getElementById('formTautkanBaru');

    if (tab === 'existing') {
        btnExisting.classList.add('active');
        btnBaru.classList.remove('active');
        formExisting.style.display = 'block';
        formBaru.style.display = 'none';
    } else {
        btnBaru.classList.add('active');
        btnExisting.classList.remove('active');
        formBaru.style.display = 'block';
        formExisting.style.display = 'none';
    }
}

// Sub-tab generik: dipakai untuk memisahkan "Data Perusahaan" & "Data PIC" di dalam form
function gantiSubTab(group, tab) {
    document.querySelectorAll('.subtab-btn[data-subtab-group="' + group + '"]').forEach(function (btn) {
        btn.classList.toggle('active', btn.dataset.subtab === tab);
    });
    document.querySelectorAll('.subtab-panel[data-subtab-group="' + group + '"]').forEach(function (panel) {
        panel.style.display = (panel.dataset.subtabPanel === tab) ? 'block' : 'none';
    });
}

function openEditModal(data) {
    document.getElementById('editKlienId').value = data.klien_id;
    document.getElementById('editNamaPerusahaan').value = data.nama_perusahaan || '';
    document.getElementById('editAlamat').value = data.alamat || '';
    document.getElementById('editStatus').value = data.status || 'Aktif';
    document.getElementById('editPicNama').value = data.pic_nama || '';
    document.getElementById('editPicWhatsapp').value = data.pic_whatsapp || '';
    document.getElementById('editPicEmail').value = data.pic_email || '';
    gantiSubTab('edit', 'perusahaan');
    new bootstrap.Modal(document.getElementById('modalEditKlien')).show();
}
</script>

<?php
include "../includes/footer.php";
?>