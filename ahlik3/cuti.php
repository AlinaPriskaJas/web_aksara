<?php
// ahli_k3/cuti.php

// Pastikan perhitungan tanggal/bulan (untuk akrual saldo cuti) selalu
// mengikuti waktu Indonesia, bukan timezone default server (mis. UTC).
date_default_timezone_set('Asia/Jakarta');

require_once "../config/koneksi.php";
require_once "../includes/drive_helper.php";
require_once "../includes/cuti_surat_helper.php";

if (session_status() === PHP_SESSION_NONE)
    session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'ahli_k3') {
    header("Location: ../login.php");
    exit;
}

$page_title = "Pengajuan Cuti";
include "../includes/header.php";
include "../includes/sidebar.php";
include "../includes/topbar.php";

$current_user_id = $_SESSION['user_id'];
$success_msg = "";
$error_msg = "";
$current_year = (int) date('Y');
$current_month = (int) date('n'); // 1-12, dipakai untuk akrual bulanan

/**
 * Ambil nama lengkap user yang sedang login (dipakai di kolom "Nama").
 */
function get_current_user_name($conn, $user_id)
{
    try {
        $stmt = $conn->prepare("SELECT nama_lengkap FROM Users WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $user_id]);
        $row = $stmt->fetch();
        return $row ? $row['nama_lengkap'] : '-';
    } catch (PDOException $e) {
        return '-';
    }
}
$current_user_name = get_current_user_name($conn, $current_user_id);

/**
 * Ambil tanggal user mendaftar (Users.created_at) -- dipakai sebagai titik
 * awal akrual saldo Cuti Tahunan (lihat ensure_saldo_cuti()), bukan dihitung
 * dari Januari. Fallback ke waktu sekarang kalau baris user tidak ketemu
 * (mis. data korup) supaya akrual tetap dimulai dari suatu titik yang wajar.
 */
function get_user_created_at($conn, $user_id)
{
    try {
        $stmt = $conn->prepare("SELECT created_at FROM Users WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $user_id]);
        $row = $stmt->fetch();
        return ($row && !empty($row['created_at'])) ? $row['created_at'] : date('Y-m-d H:i:s');
    } catch (PDOException $e) {
        return date('Y-m-d H:i:s');
    }
}
$current_user_created_at = get_user_created_at($conn, $current_user_id);

/**
 * Pastikan baris Cuti_Saldo untuk (user, tahun) ada, dan jatah_tahunan
 * terakru otomatis +1 setiap tanggal 1 bulan berjalan -- TAPI dimulai dari
 * bulan user MENDAFTAR (Users.created_at), bukan selalu dari Januari:
 *   - Kalau daftar tepat tanggal 1 suatu bulan, bulan itu langsung dihitung
 *     (jatah = 1 di bulan itu juga).
 *   - Kalau daftar SETELAH tanggal 1 (mis. tanggal 8), bulan pendaftaran itu
 *     belum dapat jatah sama sekali -- akrual baru mulai di tanggal 1 bulan
 *     BERIKUTNYA.
 * Untuk tahun-tahun setelah tahun pendaftaran, akrual kembali normal dari
 * Januari (karyawan sudah terdaftar penuh setahun). Untuk tahun sebelum user
 * terdaftar, jatah 0. Untuk tahun yang sudah lewat (dan user sudah terdaftar
 * saat itu), jatah penuh 12. Nilai selalu disinkronkan ulang (bisa naik/turun)
 * supaya data lama yang tidak akurat ikut terkoreksi.
 */
function ensure_saldo_cuti($conn, $user_id, $tahun, $bulanSekarang, $tahunSekarang, $tanggalDaftar)
{
    $waktuDaftar = strtotime($tanggalDaftar) ?: time();
    $tahunDaftar = (int) date('Y', $waktuDaftar);
    $bulanDaftar = (int) date('n', $waktuDaftar);
    $hariDaftar = (int) date('j', $waktuDaftar);

    if ($tahun < $tahunDaftar) {
        // Tahun sebelum user ini terdaftar -> belum ada jatah sama sekali.
        $targetJatah = 0;
    } elseif ($tahun < $tahunSekarang) {
        $targetJatah = 12;
    } elseif ($tahun > $tahunSekarang) {
        $targetJatah = 0;
    } elseif ($tahun === $tahunDaftar) {
        // Tahun pendaftaran: akrual dihitung mulai dari BULAN mendaftar.
        $bonusBulanDaftar = ($hariDaftar <= 1) ? 1 : 0;
        $targetJatah = max(0, min(12, ($bulanSekarang - $bulanDaftar) + $bonusBulanDaftar));
    } else {
        // Tahun berjalan, tapi bukan tahun pendaftaran -> akrual normal dari Januari.
        $targetJatah = max(0, min(12, $bulanSekarang));
    }

    $stmtBal = $conn->prepare("SELECT * FROM Cuti_Saldo WHERE user_id = :user_id AND tahun = :tahun LIMIT 1");
    $stmtBal->execute(['user_id' => $user_id, 'tahun' => $tahun]);
    $balance = $stmtBal->fetch();

    if (!$balance) {
        $initStmt = $conn->prepare("INSERT INTO Cuti_Saldo (user_id, tahun, jatah_tahunan, terpakai) VALUES (:user_id, :tahun, :jatah, 0)");
        $initStmt->execute(['user_id' => $user_id, 'tahun' => $tahun, 'jatah' => $targetJatah]);
        $stmtBal->execute(['user_id' => $user_id, 'tahun' => $tahun]);
        $balance = $stmtBal->fetch();
    } elseif ((int) $balance['jatah_tahunan'] !== $targetJatah) {
        $updStmt = $conn->prepare("UPDATE Cuti_Saldo SET jatah_tahunan = :jatah WHERE id = :id");
        $updStmt->execute(['jatah' => $targetJatah, 'id' => $balance['id']]);
        $stmtBal->execute(['user_id' => $user_id, 'tahun' => $tahun]);
        $balance = $stmtBal->fetch();
    }

    return $balance;
}

function badge_class_for($status)
{
    if ($status === 'Disetujui')
        return 'badge-success';
    if ($status === 'Ditolak')
        return 'badge-danger';
    if ($status === 'Dibatalkan')
        return 'badge-secondary';
    return 'badge-warning';
}
function tab_from_jenis($jenis_cuti)
{
    $map = ['Cuti Tahunan' => 'tahunan', 'Cuti Khusus' => 'khusus', 'Izin Sakit' => 'sakit'];
    return $map[$jenis_cuti] ?? 'tahunan';
}

// Fetch / init saldo cuti tahunan (dengan akrual bulanan)
try {
    $balance = ensure_saldo_cuti($conn, $current_user_id, $current_year, $current_month, $current_year, $current_user_created_at);
} catch (PDOException $e) {
    $balance = ['jatah_tahunan' => $current_month, 'terpakai' => 0, 'sisa' => $current_month];
}

// Tab aktif
$valid_tabs = ['tahunan', 'khusus', 'sakit', 'saldo'];
$active_tab = $_GET['tab'] ?? 'tahunan';
if (!in_array($active_tab, $valid_tabs))
    $active_tab = 'tahunan';

$highlight_id = isset($_GET['highlight']) ? (int) $_GET['highlight'] : null;

$jenisMap = [
    'tahunan' => 'Cuti Tahunan',
    'khusus' => 'Cuti Khusus',
    'sakit' => 'Izin Sakit',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'batal_cuti') {

    $cuti_id = intval($_POST['cuti_id'] ?? 0);
    try {
        $stmtCheck = $conn->prepare("SELECT * FROM Cuti WHERE id = :id AND user_id = :user_id LIMIT 1");
        $stmtCheck->execute(['id' => $cuti_id, 'user_id' => $current_user_id]);
        $cutiRow = $stmtCheck->fetch();

        if (!$cutiRow) {
            $error_msg = "Data pengajuan cuti tidak ditemukan.";
        } elseif ($cutiRow['status'] !== 'Menunggu') {
            $error_msg = "Hanya pengajuan berstatus 'Menunggu' yang dapat dibatalkan.";
            $active_tab = tab_from_jenis($cutiRow['jenis_cuti']);
        } else {
            $conn->beginTransaction();
            $upd = $conn->prepare("UPDATE Cuti SET status = 'Dibatalkan' WHERE id = :id");
            $upd->execute(['id' => $cuti_id]);
            if (!empty($cutiRow['approval_id'])) {
                $updApp = $conn->prepare("UPDATE Approval SET status = 'Dibatalkan' WHERE id = :app_id");
                $updApp->execute(['app_id' => $cutiRow['approval_id']]);
            }
            $conn->commit();
            catatAudit(
                $conn,
                'Cuti',
                'Batalkan',
                "Membatalkan pengajuan cuti #{$cuti_id} (" . $cutiRow['jenis_cuti'] . ")",
                ['status' => $cutiRow['status']],
                ['status' => 'Dibatalkan']
            );
            $success_msg = "Pengajuan " . htmlspecialchars($cutiRow['jenis_cuti']) . " berhasil dibatalkan.";
            $active_tab = tab_from_jenis($cutiRow['jenis_cuti']);
        }
    } catch (PDOException $e) {
        if ($conn->inTransaction())
            $conn->rollBack();
        $error_msg = "Gagal membatalkan pengajuan: " . $e->getMessage();
    }

} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit_cuti') {

    $cuti_id = intval($_POST['cuti_id'] ?? 0);
    $form_type = $_POST['form_type'] ?? '';

    try {
        $stmtCheck = $conn->prepare("SELECT * FROM Cuti WHERE id = :id AND user_id = :user_id LIMIT 1");
        $stmtCheck->execute(['id' => $cuti_id, 'user_id' => $current_user_id]);
        $cutiRow = $stmtCheck->fetch();

        if (!$cutiRow) {
            $error_msg = "Data pengajuan cuti tidak ditemukan.";
        } elseif ($cutiRow['status'] !== 'Menunggu') {
            $error_msg = "Hanya pengajuan berstatus 'Menunggu' yang dapat diubah tanggalnya.";
            $active_tab = tab_from_jenis($cutiRow['jenis_cuti']);
        } elseif (!isset($jenisMap[$form_type])) {
            $error_msg = "Form tidak valid.";
        } else {
            $active_tab = $form_type;
            $alasan = trim($_POST['alasan'] ?? '');
            $duration = 0;
            $tgl_mulai = '';
            $tgl_selesai = '';

            // Field tambahan khusus Cuti Tahunan (dipakai untuk mencetak Surat Cuti)
            $jabatan_karyawan = null;
            $divisi_karyawan = null;
            $sub_divisi_karyawan = null;
            $alamat_cuti = null;
            $nama_penerima = null;
            $nama_mengetahui = null;
            $tanggal_serah_terima = null;
            $nama_penerima_tugas = null;
            $jabatan_penerima_tugas = null;
            $sub_divisi_penerima_tugas = null;
            $divisi_penerima_tugas = null;
            $serah_terima_items = [];

            if ($form_type === 'tahunan') {
                $jabatan_karyawan = trim($_POST['jabatan_karyawan'] ?? '') ?: null;
                $divisi_karyawan = trim($_POST['divisi_karyawan'] ?? '') ?: null;
                $sub_divisi_karyawan = trim($_POST['sub_divisi_karyawan'] ?? '') ?: null;
                $alamat_cuti = trim($_POST['alamat_cuti'] ?? '') ?: null;
                $nama_penerima = trim($_POST['nama_penerima'] ?? '') ?: null;
                $nama_mengetahui = trim($_POST['nama_mengetahui'] ?? '') ?: null;
                $tanggal_serah_terima = trim($_POST['tanggal_serah_terima'] ?? '') ?: null;
                $nama_penerima_tugas = trim($_POST['nama_penerima_tugas'] ?? '') ?: null;
                $jabatan_penerima_tugas = trim($_POST['jabatan_penerima_tugas'] ?? '') ?: null;
                $sub_divisi_penerima_tugas = trim($_POST['sub_divisi_penerima_tugas'] ?? '') ?: null;
                $divisi_penerima_tugas = trim($_POST['divisi_penerima_tugas'] ?? '') ?: null;

                $item_deskripsi_list = $_POST['item_deskripsi'] ?? [];
                $item_status_list = $_POST['item_status'] ?? [];
                foreach ($item_deskripsi_list as $idx_item => $deskripsi_item) {
                    $deskripsi_item = trim($deskripsi_item);
                    if ($deskripsi_item === '')
                        continue;
                    $serah_terima_items[] = [
                        'deskripsi' => $deskripsi_item,
                        'status' => trim($item_status_list[$idx_item] ?? ''),
                    ];
                }
            }

            if ($form_type === 'sakit') {
                $tgl_mulai = $_POST['tgl_sakit'] ?? '';
                $total_hari = intval($_POST['total_hari'] ?? 0);

                if (empty($tgl_mulai) || $total_hari < 1) {
                    $error_msg = "Tanggal Sakit dan Total Hari Sakit wajib diisi dengan benar!";
                } else {
                    $startDt = new DateTime($tgl_mulai);
                    $endDt = clone $startDt;
                    $endDt->modify('+' . ($total_hari - 1) . ' days');
                    $tgl_selesai = $endDt->format('Y-m-d');
                    $duration = $total_hari;
                }
            } else {
                $tgl_mulai = $_POST['tgl_mulai'] ?? '';
                $tgl_selesai = $_POST['tgl_selesai'] ?? '';

                if (empty($tgl_mulai) || empty($tgl_selesai)) {
                    $error_msg = "Tanggal Mulai dan Tanggal Selesai wajib diisi!";
                } else {
                    $start = new DateTime($tgl_mulai);
                    $end = new DateTime($tgl_selesai);
                    $diff = $start->diff($end)->format("%r%a");
                    $duration = intval($diff) + 1;
                    if ($duration <= 0) {
                        $error_msg = "Tanggal Selesai harus sesudah atau sama dengan Tanggal Mulai!";
                    }
                }
            }

            // Untuk cuti tahunan: saldo yang tersedia = sisa saat ini + durasi lama (dikembalikan dulu)
            if (empty($error_msg) && $form_type === 'tahunan') {
                $saldoTersedia = $balance['sisa'] + (int) $cutiRow['total_durasi'];
                if ($duration > $saldoTersedia) {
                    $error_msg = "Saldo cuti tahunan tidak mencukupi! Saldo tersedia untuk perubahan ini: " . $saldoTersedia . " hari.";
                }
            }

            if (empty($error_msg)) {
                $lampiran = $cutiRow['lampiran']; // pertahankan dokumen lama kecuali diganti

                if (isset($_FILES['lampiran']) && $_FILES['lampiran']['error'] === UPLOAD_ERR_OK) {
                    $file = $_FILES['lampiran'];
                    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                    if (in_array($ext, ['pdf', 'jpg', 'jpeg', 'png'])) {
                        $hasil_drive = arp_upload_ke_drive($file['tmp_name'], $file['name'], $file['type'], $current_user_id, 'Cuti');
                        if ($hasil_drive && !empty($hasil_drive['link'])) {
                            $lampiran = $hasil_drive['link'];
                        } else {
                            $error_msg = "Gagal mengunggah dokumen pendukung ke Drive: " . arp_drive_last_error();
                        }
                    } else {
                        $error_msg = "Format dokumen tidak didukung. Gunakan PDF, JPG, atau PNG.";
                    }
                }

                if (empty($error_msg)) {
                    $updCuti = $conn->prepare("UPDATE Cuti SET tgl_mulai = :start, tgl_selesai = :end, total_durasi = :duration, alasan = :alasan, lampiran = :lampiran, jabatan_karyawan = :jabatan_karyawan, divisi_karyawan = :divisi_karyawan, sub_divisi_karyawan = :sub_divisi_karyawan, alamat_cuti = :alamat_cuti, nama_penerima = :nama_penerima, nama_mengetahui = :nama_mengetahui, tanggal_serah_terima = :tanggal_serah_terima, nama_penerima_tugas = :nama_penerima_tugas, jabatan_penerima_tugas = :jabatan_penerima_tugas, sub_divisi_penerima_tugas = :sub_divisi_penerima_tugas, divisi_penerima_tugas = :divisi_penerima_tugas WHERE id = :id AND user_id = :user_id");
                    $updCuti->execute([
                        'start' => $tgl_mulai,
                        'end' => $tgl_selesai,
                        'duration' => $duration,
                        'alasan' => $alasan,
                        'lampiran' => $lampiran,
                        'jabatan_karyawan' => $jabatan_karyawan,
                        'divisi_karyawan' => $divisi_karyawan,
                        'sub_divisi_karyawan' => $sub_divisi_karyawan,
                        'alamat_cuti' => $alamat_cuti,
                        'nama_penerima' => $nama_penerima,
                        'nama_mengetahui' => $nama_mengetahui,
                        'tanggal_serah_terima' => $tanggal_serah_terima,
                        'nama_penerima_tugas' => $nama_penerima_tugas,
                        'jabatan_penerima_tugas' => $jabatan_penerima_tugas,
                        'sub_divisi_penerima_tugas' => $sub_divisi_penerima_tugas,
                        'divisi_penerima_tugas' => $divisi_penerima_tugas,
                        'id' => $cuti_id,
                        'user_id' => $current_user_id
                    ]);

                    if ($form_type === 'tahunan') {
                        $delItem = $conn->prepare("DELETE FROM Cuti_Serah_Terima WHERE cuti_id = :cuti_id");
                        $delItem->execute(['cuti_id' => $cuti_id]);

                        if (!empty($serah_terima_items)) {
                            $stmtItem = $conn->prepare("INSERT INTO Cuti_Serah_Terima (cuti_id, urutan, deskripsi, status) VALUES (:cuti_id, :urutan, :deskripsi, :status)");
                            foreach ($serah_terima_items as $urutan_item => $item) {
                                $stmtItem->execute([
                                    'cuti_id' => $cuti_id,
                                    'urutan' => $urutan_item + 1,
                                    'deskripsi' => $item['deskripsi'],
                                    'status' => $item['status'],
                                ]);
                            }
                        }
                    }

                    $success_msg = "Pengajuan " . $jenisMap[$form_type] . " berhasil diperbarui.";
                }
            }
        }
    } catch (PDOException $e) {
        $error_msg = "Gagal memperbarui pengajuan cuti: " . $e->getMessage();
    }

} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form_type = $_POST['form_type'] ?? '';
    if (isset($jenisMap[$form_type])) {
        $active_tab = $form_type;
    }

    if (!isset($jenisMap[$form_type])) {
        $error_msg = "Form tidak valid.";
    } else {
        $jenis_cuti = $jenisMap[$form_type];
        $alasan = trim($_POST['alasan'] ?? '');
        $duration = 0;
        $tgl_mulai = '';
        $tgl_selesai = '';

        // Field tambahan khusus Cuti Tahunan (dipakai untuk mencetak Surat Cuti)
        $jabatan_karyawan = null;
        $divisi_karyawan = null;
        $sub_divisi_karyawan = null;
        $alamat_cuti = null;
        $nama_penerima = null;
        $nama_mengetahui = null;
        $tanggal_serah_terima = null;
        $nama_penerima_tugas = null;
        $jabatan_penerima_tugas = null;
        $sub_divisi_penerima_tugas = null;
        $divisi_penerima_tugas = null;
        $serah_terima_items = [];

        if ($form_type === 'tahunan') {
            $jabatan_karyawan = trim($_POST['jabatan_karyawan'] ?? '') ?: null;
            $divisi_karyawan = trim($_POST['divisi_karyawan'] ?? '') ?: null;
            $sub_divisi_karyawan = trim($_POST['sub_divisi_karyawan'] ?? '') ?: null;
            $alamat_cuti = trim($_POST['alamat_cuti'] ?? '') ?: null;
            $nama_penerima = trim($_POST['nama_penerima'] ?? '') ?: null;
            $nama_mengetahui = trim($_POST['nama_mengetahui'] ?? '') ?: null;
            $tanggal_serah_terima = trim($_POST['tanggal_serah_terima'] ?? '') ?: null;
            $nama_penerima_tugas = trim($_POST['nama_penerima_tugas'] ?? '') ?: null;
            $jabatan_penerima_tugas = trim($_POST['jabatan_penerima_tugas'] ?? '') ?: null;
            $sub_divisi_penerima_tugas = trim($_POST['sub_divisi_penerima_tugas'] ?? '') ?: null;
            $divisi_penerima_tugas = trim($_POST['divisi_penerima_tugas'] ?? '') ?: null;

            $item_deskripsi_list = $_POST['item_deskripsi'] ?? [];
            $item_status_list = $_POST['item_status'] ?? [];
            foreach ($item_deskripsi_list as $idx_item => $deskripsi_item) {
                $deskripsi_item = trim($deskripsi_item);
                if ($deskripsi_item === '')
                    continue;
                $serah_terima_items[] = [
                    'deskripsi' => $deskripsi_item,
                    'status' => trim($item_status_list[$idx_item] ?? ''),
                ];
            }
        }

        if ($form_type === 'sakit') {
            $tgl_mulai = $_POST['tgl_sakit'] ?? '';
            $total_hari = intval($_POST['total_hari'] ?? 0);

            if (empty($tgl_mulai) || $total_hari < 1) {
                $error_msg = "Tanggal Sakit dan Total Hari Sakit wajib diisi dengan benar!";
            } else {
                $startDt = new DateTime($tgl_mulai);
                $endDt = clone $startDt;
                $endDt->modify('+' . ($total_hari - 1) . ' days');
                $tgl_selesai = $endDt->format('Y-m-d');
                $duration = $total_hari;
            }
        } else {
            $tgl_mulai = $_POST['tgl_mulai'] ?? '';
            $tgl_selesai = $_POST['tgl_selesai'] ?? '';

            if (empty($tgl_mulai) || empty($tgl_selesai)) {
                $error_msg = "Tanggal Mulai dan Tanggal Selesai wajib diisi!";
            } else {
                $start = new DateTime($tgl_mulai);
                $end = new DateTime($tgl_selesai);
                $diff = $start->diff($end)->format("%r%a");
                $duration = intval($diff) + 1;
                if ($duration <= 0) {
                    $error_msg = "Tanggal Selesai harus sesudah atau sama dengan Tanggal Mulai!";
                }
            }
        }

        if (empty($error_msg) && $form_type === 'tahunan' && $duration > $balance['sisa']) {
            $error_msg = "Saldo cuti tahunan tidak mencukupi! Sisa saldo: " . $balance['sisa'] . " hari.";
        }

        if (empty($error_msg)) {
            // Handle optional dokumen pendukung upload
            $lampiran = "";
            if (isset($_FILES['lampiran']) && $_FILES['lampiran']['error'] === UPLOAD_ERR_OK) {
                $file = $_FILES['lampiran'];
                $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                if (in_array($ext, ['pdf', 'jpg', 'jpeg', 'png'])) {
                    $hasil_drive = arp_upload_ke_drive($file['tmp_name'], $file['name'], $file['type'], $current_user_id, 'Cuti');
                    if ($hasil_drive && !empty($hasil_drive['link'])) {
                        $lampiran = $hasil_drive['link'];
                    } else {
                        $error_msg = "Gagal mengunggah dokumen pendukung ke Drive: " . arp_drive_last_error();
                    }
                } else {
                    $error_msg = "Format dokumen tidak didukung. Gunakan PDF, JPG, atau PNG.";
                }
            }

            if (empty($error_msg)) {
                try {
                    $conn->beginTransaction();

                    $stmtApp = $conn->prepare("INSERT INTO Approval (jenis_pengajuan, ref_id, requester_id, level, status) VALUES ('Cuti', 0, :requester, 1, 'Menunggu')");
                    $stmtApp->execute(['requester' => $current_user_id]);
                    $approval_id = $conn->lastInsertId();

                    $stmtCuti = $conn->prepare("INSERT INTO Cuti (user_id, jenis_cuti, tgl_mulai, tgl_selesai, total_durasi, alasan, lampiran, status, approval_id, jabatan_karyawan, divisi_karyawan, sub_divisi_karyawan, alamat_cuti, nama_penerima, nama_mengetahui, tanggal_serah_terima, nama_penerima_tugas, jabatan_penerima_tugas, sub_divisi_penerima_tugas, divisi_penerima_tugas) VALUES (:user_id, :jenis, :start, :end, :duration, :alasan, :lampiran, 'Menunggu', :app_id, :jabatan_karyawan, :divisi_karyawan, :sub_divisi_karyawan, :alamat_cuti, :nama_penerima, :nama_mengetahui, :tanggal_serah_terima, :nama_penerima_tugas, :jabatan_penerima_tugas, :sub_divisi_penerima_tugas, :divisi_penerima_tugas)");
                    $stmtCuti->execute([
                        'user_id' => $current_user_id,
                        'jenis' => $jenis_cuti,
                        'start' => $tgl_mulai,
                        'end' => $tgl_selesai,
                        'duration' => $duration,
                        'alasan' => $alasan,
                        'lampiran' => $lampiran,
                        'app_id' => $approval_id,
                        'jabatan_karyawan' => $jabatan_karyawan,
                        'divisi_karyawan' => $divisi_karyawan,
                        'sub_divisi_karyawan' => $sub_divisi_karyawan,
                        'alamat_cuti' => $alamat_cuti,
                        'nama_penerima' => $nama_penerima,
                        'nama_mengetahui' => $nama_mengetahui,
                        'tanggal_serah_terima' => $tanggal_serah_terima,
                        'nama_penerima_tugas' => $nama_penerima_tugas,
                        'jabatan_penerima_tugas' => $jabatan_penerima_tugas,
                        'sub_divisi_penerima_tugas' => $sub_divisi_penerima_tugas,
                        'divisi_penerima_tugas' => $divisi_penerima_tugas,
                    ]);

                    $cuti_id = $conn->lastInsertId();
                    $updApp = $conn->prepare("UPDATE Approval SET ref_id = :cuti_id WHERE id = :app_id");
                    $updApp->execute(['cuti_id' => $cuti_id, 'app_id' => $approval_id]);

                    if ($form_type === 'tahunan' && !empty($serah_terima_items)) {
                        $stmtItem = $conn->prepare("INSERT INTO Cuti_Serah_Terima (cuti_id, urutan, deskripsi, status) VALUES (:cuti_id, :urutan, :deskripsi, :status)");
                        foreach ($serah_terima_items as $urutan_item => $item) {
                            $stmtItem->execute([
                                'cuti_id' => $cuti_id,
                                'urutan' => $urutan_item + 1,
                                'deskripsi' => $item['deskripsi'],
                                'status' => $item['status'],
                            ]);
                        }
                    }

                    // ================== NOTIFIKASI KE DIREKSI (APPROVER) ==================
                    $stmtDireksi = $conn->prepare("SELECT id FROM Users WHERE role = 'direksi'");
                    $stmtDireksi->execute();
                    foreach ($stmtDireksi->fetchAll(PDO::FETCH_COLUMN) as $direksi_id_notif) {
                        kirimNotifikasi(
                            $conn,
                            (int) $direksi_id_notif,
                            'Pengajuan Cuti Baru',
                            "{$current_user_name} mengajukan {$jenis_cuti} ({$tgl_mulai} s/d {$tgl_selesai}).",
                            'cuti',
                            (int) $cuti_id
                        );
                    }

                    // ================== EMAIL KE DIREKSI (APPROVER) ==================
                    $emailDireksi = getEmailByRole($conn, 'direksi');
                    if (!empty($emailDireksi)) {
                        $bodyEmail = templateEmailNotifikasi(
                            'Pengajuan Cuti Baru',
                            "{$current_user_name} mengajukan cuti dan menunggu persetujuan Anda.",
                            [
                                'Jenis Cuti' => $jenis_cuti,
                                'Tanggal Mulai' => $tgl_mulai,
                                'Tanggal Selesai' => $tgl_selesai,
                                'Durasi' => $duration . ' hari',
                                'Alasan' => $alasan ?: '-',
                            ],
                        );

                        kirimEmail(
                            $emailDireksi,
                            'Pengajuan Cuti Baru dari ' . $current_user_name,
                            $bodyEmail
                        );
                    }

                    $conn->commit();

                    // ================== DRAFT SURAT CUTI KE DRIVE ==================
                    // Khusus Cuti Tahunan: buat draft Surat Cuti & Pengalihan Tugas dan
                    // langsung unggah ke Google Drive begitu diajukan, supaya direksi bisa
                    // membuka filenya langsung dari Drive saat meninjau pengajuan (bukan
                    // cuma pratinjau on-the-fly). Best-effort: dijalankan SESUDAH commit,
                    // jadi kalau gagal (mis. Drive lagi bermasalah), pengajuan cuti yang
                    // sudah tersimpan TETAP tidak terpengaruh -- direksi akan melihat
                    // pratinjau on-the-fly seperti biasa sampai draft berhasil diunggah
                    // (mis. lewat tombol "Cetak Surat Cuti" yang punya self-heal).
                    if ($jenis_cuti === 'Cuti Tahunan') {
                        arp_generate_dan_unggah_surat_cuti_draft($conn, (int) $cuti_id);
                    }

                    catatAudit(
                        $conn,
                        'Cuti',
                        'Tambah',
                        "Mengajukan {$jenis_cuti} ({$tgl_mulai} s/d {$tgl_selesai})",
                        null,
                        [
                            'jenis_cuti' => $jenis_cuti,
                            'tgl_mulai' => $tgl_mulai,
                            'tgl_selesai' => $tgl_selesai,
                            'total_durasi' => $duration,
                        ]
                    );
                    $success_msg = "Pengajuan " . $jenis_cuti . " berhasil dikirim! Menunggu persetujuan Direksi.";

                    $balance = ensure_saldo_cuti($conn, $current_user_id, $current_year, $current_month, $current_year, $current_user_created_at);
                } catch (PDOException $e) {
                    $conn->rollBack();
                    $error_msg = "Gagal memproses pengajuan cuti: " . $e->getMessage();
                }
            }
        }
    }
}

// Riwayat per kategori
function fetch_leaves($conn, $user_id, $jenis)
{
    try {
        $stmt = $conn->prepare("SELECT * FROM Cuti WHERE user_id = :user_id AND jenis_cuti = :jenis ORDER BY created_at DESC");
        $stmt->execute(['user_id' => $user_id, 'jenis' => $jenis]);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
}
$leavesTahunan = fetch_leaves($conn, $current_user_id, 'Cuti Tahunan');
$leavesKhusus = fetch_leaves($conn, $current_user_id, 'Cuti Khusus');
$leavesSakit = fetch_leaves($conn, $current_user_id, 'Izin Sakit');

// Lampirkan daftar "Uraian Tugas" (Serah Terima Tugas) ke tiap baris Cuti Tahunan,
// dipakai untuk mengisi ulang form Ubah & untuk mencetak Surat Cuti.
try {
    $stmtItemAll = $conn->prepare("SELECT * FROM Cuti_Serah_Terima WHERE cuti_id = :cuti_id ORDER BY urutan ASC");
    foreach ($leavesTahunan as &$leaveTahunanRow) {
        $stmtItemAll->execute(['cuti_id' => $leaveTahunanRow['id']]);
        $leaveTahunanRow['items'] = $stmtItemAll->fetchAll();
    }
    unset($leaveTahunanRow);
} catch (PDOException $e) {
    // Tabel Cuti_Serah_Terima belum ada (migration belum dijalankan) -> abaikan, biarkan 'items' kosong
    foreach ($leavesTahunan as &$leaveTahunanRow) {
        $leaveTahunanRow['items'] = [];
    }
    unset($leaveTahunanRow);
}
// BARU: lampirkan link Surat Cuti (dari tabel Surat, bukan kolom di Cuti)
foreach ($leavesTahunan as &$leaveTahunanRow) {
    $leaveTahunanRow['surat_cuti_link'] = arp_ambil_link_surat_cuti($conn, (int) $leaveTahunanRow['id']);
}
unset($leaveTahunanRow);

// Data untuk tab Saldo Cuti
function sum_durasi($conn, $user_id, $jenis, $tahun)
{
    try {
        $stmt = $conn->prepare("SELECT COALESCE(SUM(total_durasi),0) AS total FROM Cuti WHERE user_id = :user_id AND jenis_cuti = :jenis AND YEAR(tgl_mulai) = :tahun AND status = 'Disetujui'");
        $stmt->execute(['user_id' => $user_id, 'jenis' => $jenis, 'tahun' => $tahun]);
        $row = $stmt->fetch();
        return $row ? (int) $row['total'] : 0;
    } catch (PDOException $e) {
        return 0;
    }
}
$dipakaiKhusus = sum_durasi($conn, $current_user_id, 'Cuti Khusus', $current_year);
$dipakaiSakit = sum_durasi($conn, $current_user_id, 'Izin Sakit', $current_year);
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

    <!-- Balance Summary Cards -->
    <div class="row g-4 mb-4">
        <div class="col-xl-4 col-md-6 col-12">
            <div class="stat-card">
                <div class="stat-card-info">
                    <span class="stat-card-title">Jatah Cuti Tahunan (s.d. bulan ini)</span>
                    <span class="stat-card-value"><?= (int) $balance['jatah_tahunan'] ?> Hari</span>
                </div>
                <div class="stat-card-icon">
                    <i class="bi bi-calendar-check-fill"></i>
                </div>
            </div>
        </div>
        <div class="col-xl-4 col-md-6 col-12">
            <div class="stat-card">
                <div class="stat-card-info">
                    <span class="stat-card-title">Cuti Terpakai</span>
                    <span class="stat-card-value text-danger"><?= (int) $balance['terpakai'] ?> Hari</span>
                </div>
                <div class="stat-card-icon danger">
                    <i class="bi bi-calendar-minus-fill"></i>
                </div>
            </div>
        </div>
        <div class="col-xl-4 col-md-6 col-12">
            <div class="stat-card">
                <div class="stat-card-info">
                    <span class="stat-card-title">Sisa Saldo Cuti Tahun <?= $current_year ?></span>
                    <span class="stat-card-value text-success"><?= (int) $balance['sisa'] ?> Hari</span>
                </div>
                <div class="stat-card-icon success">
                    <i class="bi bi-calendar-plus-fill"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Tab Navigation -->
    <div class="arp-tab-group">
        <div class="arp-tab-nav">
            <button type="button" class="arp-tab-btn<?= $active_tab === 'tahunan' ? ' active' : '' ?>"
                data-tab-target="tabPanelTahunan" onclick="switchTab('tabPanelTahunan', this)">
                <i class="bi bi-calendar-check me-1"></i> Cuti Tahunan
            </button>
            <button type="button" class="arp-tab-btn<?= $active_tab === 'khusus' ? ' active' : '' ?>"
                data-tab-target="tabPanelKhusus" onclick="switchTab('tabPanelKhusus', this)">
                <i class="bi bi-journal-text me-1"></i> Cuti Khusus
            </button>
            <button type="button" class="arp-tab-btn<?= $active_tab === 'sakit' ? ' active' : '' ?>"
                data-tab-target="tabPanelSakit" onclick="switchTab('tabPanelSakit', this)">
                <i class="bi bi-thermometer-half me-1"></i> Izin Sakit
            </button>
            <button type="button" class="arp-tab-btn<?= $active_tab === 'saldo' ? ' active' : '' ?>"
                data-tab-target="tabPanelSaldo" onclick="switchTab('tabPanelSaldo', this)">
                <i class="bi bi-wallet2 me-1"></i> Saldo Cuti
            </button>
        </div>

        <div class="row g-4">
            <!-- ===================== TAB: CUTI TAHUNAN ===================== -->
            <div class="col-12 arp-tab-panel" id="tabPanelTahunan" <?= $active_tab === 'tahunan' ? '' : 'style="display:none;"' ?>>
                <div class="card-box">
                    <div class="table-toolbar">
                        <h5 class="table-toolbar-title fw-bold">Riwayat Pengajuan Cuti Tahunan Anda</h5>
                        <div class="table-toolbar-actions">
                            <div class="search-box-container">
                                <i class="bi bi-search"></i>
                                <input type="text" class="search-box" placeholder="Cari cuti..."
                                    data-table-search="tabelCutiTahunan"
                                    onkeyup="handleTableSearch('tabelCutiTahunan')">
                            </div>
                            <button class="btn-primary-custom" onclick="openModal('modalCutiTahunan')">
                                <i class="bi bi-calendar-plus me-1"></i>Ajukan Cuti Tahunan
                            </button>
                        </div>
                    </div>
                    <div class="table-responsive-custom">
                        <table class="table-custom" id="tabelCutiTahunan">
                            <thead>
                                <tr>
                                    <th>No</th>
                                    <th>Nama</th>
                                    <th>Jenis Cuti</th>
                                    <th>Tanggal Mulai</th>
                                    <th>Tanggal Selesai</th>
                                    <th>Durasi</th>
                                    <th>Status</th>
                                    <th>Keterangan</th>
                                    <th>Dokumen</th>
                                    <th>Surat Cuti</th>
                                    <th>Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($leavesTahunan) === 0): ?>
                                    <tr>
                                        <td colspan="11" class="text-center py-4 text-muted">
                                            <i class="bi bi-calendar-event d-block mb-2" style="font-size:2rem;"></i>
                                            Belum ada riwayat pengajuan cuti tahunan.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($leavesTahunan as $i => $l): ?>
                                        <tr id="cuti-row-<?= (int) $l['id'] ?>"
                                            class="<?= $highlight_id === (int) $l['id'] ? 'table-warning' : '' ?>">
                                            <td><?= $i + 1 ?></td>
                                            <td><?= htmlspecialchars($current_user_name) ?></td>
                                            <td><strong><?= htmlspecialchars($l['jenis_cuti']) ?></strong></td>
                                            <td><?= date('d-m-Y', strtotime($l['tgl_mulai'])) ?></td>
                                            <td><?= date('d-m-Y', strtotime($l['tgl_selesai'])) ?></td>
                                            <td><strong><?= $l['total_durasi'] ?> Hari</strong></td>
                                            <td><span
                                                    class="<?= badge_class_for($l['status']) ?>"><?= htmlspecialchars($l['status']) ?></span>
                                            </td>
                                            <td class="col-keterangan"><?= htmlspecialchars($l['alasan'] ?: '-') ?></td>
                                            <td>
                                                <?php if (!empty($l['lampiran'])): ?>
                                                    <?php $hrefLampiran = str_starts_with($l['lampiran'], 'http') ? $l['lampiran'] : '../' . $l['lampiran']; ?>
                                                    <a href="<?= htmlspecialchars($hrefLampiran) ?>" target="_blank"
                                                        class="btn btn-outline-secondary btn-sm py-1"
                                                        style="font-size:0.75rem; border-radius: 8px;">
                                                        <i class="bi bi-file-earmark-arrow-down"></i> Lihat
                                                    </a>
                                                <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if (!empty($l['surat_cuti_link'])): ?>
                                                    <a href="<?= htmlspecialchars($l['surat_cuti_link']) ?>" target="_blank"
                                                        class="btn btn-outline-success btn-sm py-1"
                                                        style="font-size:0.75rem; border-radius: 8px;">
                                                        <i class="bi bi-file-earmark-text"></i> Lihat
                                                    </a>
                                                <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="d-flex gap-1 flex-wrap">
                                                    <?php if ($l['status'] === 'Menunggu'): ?>
                                                        <button type="button" class="btn btn-outline-primary btn-sm py-1"
                                                            style="font-size:0.75rem; border-radius:8px;" onclick='openEditCuti("Tahunan", <?= json_encode([
                                                                "id" => $l["id"],
                                                                "tgl_mulai" => $l["tgl_mulai"],
                                                                "tgl_selesai" => $l["tgl_selesai"],
                                                                "alasan" => $l["alasan"],
                                                                "jabatan_karyawan" => $l["jabatan_karyawan"],
                                                                "divisi_karyawan" => $l["divisi_karyawan"],
                                                                "sub_divisi_karyawan" => $l["sub_divisi_karyawan"],
                                                                "alamat_cuti" => $l["alamat_cuti"],
                                                                "nama_penerima" => $l["nama_penerima"],
                                                                "nama_mengetahui" => $l["nama_mengetahui"],
                                                                "tanggal_serah_terima" => $l["tanggal_serah_terima"],
                                                                "nama_penerima_tugas" => $l["nama_penerima_tugas"],
                                                                "jabatan_penerima_tugas" => $l["jabatan_penerima_tugas"],
                                                                "sub_divisi_penerima_tugas" => $l["sub_divisi_penerima_tugas"],
                                                                "divisi_penerima_tugas" => $l["divisi_penerima_tugas"],
                                                                "items" => $l["items"],
                                                            ], JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                                                            <i class="bi bi-pencil-square"></i> Ubah
                                                        </button>
                                                        <form method="POST" action="cuti.php?tab=tahunan" class="d-inline"
                                                            onsubmit="return confirm('Batalkan pengajuan cuti ini?');">
                                                            <input type="hidden" name="action" value="batal_cuti">
                                                            <input type="hidden" name="cuti_id" value="<?= (int) $l['id'] ?>">
                                                            <button type="submit" class="btn btn-outline-danger btn-sm py-1"
                                                                style="font-size:0.75rem; border-radius:8px;">
                                                                <i class="bi bi-x-circle"></i> Batalkan
                                                            </button>
                                                        </form>
                                                    <?php endif; ?>
                                                    <?php if ($l['status'] === 'Disetujui'): ?>
                                                        <a href="../includes/generate_cuti_surat.php?id=<?= (int) $l['id'] ?>"
                                                            target="_blank" class="btn btn-outline-success btn-sm py-1"
                                                            style="font-size:0.75rem; border-radius:8px;">
                                                            <i class="bi bi-printer"></i> Cetak Surat Cuti
                                                        </a>
                                                    <?php endif; ?>
                                                    <?php if ($l['status'] !== 'Menunggu' && $l['status'] !== 'Disetujui'): ?>
                                                        <span class="text-muted">-</span>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="pagination-custom" id="pagination-tabelCutiTahunan"></div>
                </div>
            </div>

            <!-- ===================== TAB: CUTI KHUSUS ===================== -->
            <div class="col-12 arp-tab-panel" id="tabPanelKhusus" <?= $active_tab === 'khusus' ? '' : 'style="display:none;"' ?>>
                <div class="card-box">
                    <div class="table-toolbar">
                        <h5 class="table-toolbar-title fw-bold">Riwayat Pengajuan Cuti Khusus Anda</h5>
                        <div class="table-toolbar-actions">
                            <div class="search-box-container">
                                <i class="bi bi-search"></i>
                                <input type="text" class="search-box" placeholder="Cari cuti..."
                                    data-table-search="tabelCutiKhusus" onkeyup="handleTableSearch('tabelCutiKhusus')">
                            </div>
                            <button class="btn-primary-custom" onclick="openModal('modalCutiKhusus')">
                                <i class="bi bi-calendar-plus me-1"></i>Ajukan Cuti Khusus
                            </button>
                        </div>
                    </div>
                    <div class="table-responsive-custom">
                        <table class="table-custom" id="tabelCutiKhusus">
                            <thead>
                                <tr>
                                    <th>No</th>
                                    <th>Nama</th>
                                    <th>Jenis Cuti</th>
                                    <th>Tanggal Mulai</th>
                                    <th>Tanggal Selesai</th>
                                    <th>Durasi</th>
                                    <th>Status</th>
                                    <th>Keterangan</th>
                                    <th>Dokumen</th>
                                    <th>Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($leavesKhusus) === 0): ?>
                                    <tr>
                                        <td colspan="10" class="text-center py-4 text-muted">
                                            <i class="bi bi-calendar-event d-block mb-2" style="font-size:2rem;"></i>
                                            Belum ada riwayat pengajuan cuti khusus.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($leavesKhusus as $i => $l): ?>
                                        <tr id="cuti-row-<?= (int) $l['id'] ?>"
                                            class="<?= $highlight_id === (int) $l['id'] ? 'table-warning' : '' ?>">
                                            <td><?= $i + 1 ?></td>
                                            <td><?= htmlspecialchars($current_user_name) ?></td>
                                            <td><strong><?= htmlspecialchars($l['jenis_cuti']) ?></strong></td>
                                            <td><?= date('d-m-Y', strtotime($l['tgl_mulai'])) ?></td>
                                            <td><?= date('d-m-Y', strtotime($l['tgl_selesai'])) ?></td>
                                            <td><strong><?= $l['total_durasi'] ?> Hari</strong></td>
                                            <td><span
                                                    class="<?= badge_class_for($l['status']) ?>"><?= htmlspecialchars($l['status']) ?></span>
                                            </td>
                                            <td class="col-keterangan"><?= htmlspecialchars($l['alasan'] ?: '-') ?></td>
                                            <td>
                                                <?php if (!empty($l['lampiran'])): ?>
                                                    <?php $hrefLampiran = str_starts_with($l['lampiran'], 'http') ? $l['lampiran'] : '../' . $l['lampiran']; ?>
                                                    <a href="<?= htmlspecialchars($hrefLampiran) ?>" target="_blank"
                                                        class="btn btn-outline-secondary btn-sm py-1"
                                                        style="font-size:0.75rem; border-radius: 8px;">
                                                        <i class="bi bi-file-earmark-arrow-down"></i> Lihat
                                                    </a>
                                                <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($l['status'] === 'Menunggu'): ?>
                                                    <div class="d-flex gap-1">
                                                        <button type="button" class="btn btn-outline-primary btn-sm py-1"
                                                            style="font-size:0.75rem; border-radius:8px;" onclick='openEditCuti("Khusus", <?= json_encode([
                                                                "id" => $l["id"],
                                                                "tgl_mulai" => $l["tgl_mulai"],
                                                                "tgl_selesai" => $l["tgl_selesai"],
                                                                "alasan" => $l["alasan"],
                                                            ], JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                                                            <i class="bi bi-pencil-square"></i> Ubah
                                                        </button>
                                                        <form method="POST" action="cuti.php?tab=khusus" class="d-inline"
                                                            onsubmit="return confirm('Batalkan pengajuan cuti ini?');">
                                                            <input type="hidden" name="action" value="batal_cuti">
                                                            <input type="hidden" name="cuti_id" value="<?= (int) $l['id'] ?>">
                                                            <button type="submit" class="btn btn-outline-danger btn-sm py-1"
                                                                style="font-size:0.75rem; border-radius:8px;">
                                                                <i class="bi bi-x-circle"></i> Batalkan
                                                            </button>
                                                        </form>
                                                    </div>
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
                    <div class="pagination-custom" id="pagination-tabelCutiKhusus"></div>
                </div>
            </div>

            <!-- ===================== TAB: IZIN SAKIT ===================== -->
            <div class="col-12 arp-tab-panel" id="tabPanelSakit" <?= $active_tab === 'sakit' ? '' : 'style="display:none;"' ?>>
                <div class="card-box">
                    <div class="table-toolbar">
                        <h5 class="table-toolbar-title fw-bold">Riwayat Pengajuan Izin Sakit Anda</h5>
                        <div class="table-toolbar-actions">
                            <div class="search-box-container">
                                <i class="bi bi-search"></i>
                                <input type="text" class="search-box" placeholder="Cari izin sakit..."
                                    data-table-search="tabelIzinSakit" onkeyup="handleTableSearch('tabelIzinSakit')">
                            </div>
                            <button class="btn-primary-custom" onclick="openModal('modalIzinSakit')">
                                <i class="bi bi-calendar-plus me-1"></i>Ajukan Izin Sakit
                            </button>
                        </div>
                    </div>
                    <div class="table-responsive-custom">
                        <table class="table-custom" id="tabelIzinSakit">
                            <thead>
                                <tr>
                                    <th>No</th>
                                    <th>Nama</th>
                                    <th>Jenis Cuti</th>
                                    <th>Tanggal</th>
                                    <th>Durasi</th>
                                    <th>Status</th>
                                    <th>Keterangan</th>
                                    <th>Dokumen</th>
                                    <th>Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($leavesSakit) === 0): ?>
                                    <tr>
                                        <td colspan="9" class="text-center py-4 text-muted">
                                            <i class="bi bi-calendar-event d-block mb-2" style="font-size:2rem;"></i>
                                            Belum ada riwayat pengajuan izin sakit.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($leavesSakit as $i => $l): ?>
                                        <tr id="cuti-row-<?= (int) $l['id'] ?>"
                                            class="<?= $highlight_id === (int) $l['id'] ? 'table-warning' : '' ?>">
                                            <td><?= $i + 1 ?></td>
                                            <td><?= htmlspecialchars($current_user_name) ?></td>
                                            <td><strong><?= htmlspecialchars($l['jenis_cuti']) ?></strong></td>
                                            <td><?= date('d-m-Y', strtotime($l['tgl_mulai'])) ?></td>
                                            <td><strong><?= $l['total_durasi'] ?> Hari</strong></td>
                                            <td><span
                                                    class="<?= badge_class_for($l['status']) ?>"><?= htmlspecialchars($l['status']) ?></span>
                                            </td>
                                            <td class="col-keterangan"><?= htmlspecialchars($l['alasan'] ?: '-') ?></td>
                                            <td>
                                                <?php if (!empty($l['lampiran'])): ?>
                                                    <?php $hrefLampiran = str_starts_with($l['lampiran'], 'http') ? $l['lampiran'] : '../' . $l['lampiran']; ?>
                                                    <a href="<?= htmlspecialchars($hrefLampiran) ?>" target="_blank"
                                                        class="btn btn-outline-secondary btn-sm py-1"
                                                        style="font-size:0.75rem; border-radius: 8px;">
                                                        <i class="bi bi-file-earmark-arrow-down"></i> Lihat
                                                    </a>
                                                <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($l['status'] === 'Menunggu'): ?>
                                                    <div class="d-flex gap-1">
                                                        <button type="button" class="btn btn-outline-primary btn-sm py-1"
                                                            style="font-size:0.75rem; border-radius:8px;" onclick='openEditCuti("Sakit", <?= json_encode([
                                                                "id" => $l["id"],
                                                                "tgl_mulai" => $l["tgl_mulai"],
                                                                "total_hari" => $l["total_durasi"],
                                                                "alasan" => $l["alasan"],
                                                            ], JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                                                            <i class="bi bi-pencil-square"></i> Ubah
                                                        </button>
                                                        <form method="POST" action="cuti.php?tab=sakit" class="d-inline"
                                                            onsubmit="return confirm('Batalkan pengajuan izin sakit ini?');">
                                                            <input type="hidden" name="action" value="batal_cuti">
                                                            <input type="hidden" name="cuti_id" value="<?= (int) $l['id'] ?>">
                                                            <button type="submit" class="btn btn-outline-danger btn-sm py-1"
                                                                style="font-size:0.75rem; border-radius:8px;">
                                                                <i class="bi bi-x-circle"></i> Batalkan
                                                            </button>
                                                        </form>
                                                    </div>
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
                    <div class="pagination-custom" id="pagination-tabelIzinSakit"></div>
                </div>
            </div>

            <!-- ===================== TAB: SALDO CUTI ===================== -->
            <div class="col-12 arp-tab-panel" id="tabPanelSaldo" <?= $active_tab === 'saldo' ? '' : 'style="display:none;"' ?>>
                <div class="card-box">
                    <div class="table-toolbar">
                        <h5 class="table-toolbar-title fw-bold">Saldo Cuti Anda</h5>
                    </div>
                    <div class="table-responsive-custom">
                        <table class="table-custom" id="tabelSaldoCuti">
                            <thead>
                                <tr>
                                    <th>No</th>
                                    <th>Jenis Cuti</th>
                                    <th>Tahun</th>
                                    <th>Hak</th>
                                    <th>Dipakai</th>
                                    <th>Sisa Saldo</th>
                                    <th>Masa Kadaluarsa</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td>1</td>
                                    <td><strong>Cuti Tahunan</strong></td>
                                    <td><?= $current_year ?></td>
                                    <td><?= (int) $balance['jatah_tahunan'] ?> Hari</td>
                                    <td class="text-danger"><?= (int) $balance['terpakai'] ?> Hari</td>
                                    <td class="text-success"><strong><?= (int) $balance['sisa'] ?> Hari</strong></td>
                                    <td>31 Desember <?= $current_year ?></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="pagination-custom" id="pagination-tabelSaldoCuti"></div>
                </div>
            </div>
            <!-- /col-12 arp-tab-panel tabPanelSaldo -->
        </div>
        <!-- /row g-4 -->
    </div>
    <!-- /arp-tab-group -->
</main>

<!-- ===== MODAL: Ajukan Cuti Tahunan ===== -->
<div id="modalCutiTahunan" class="arp-modal-overlay" onclick="closeModalOutside(event, 'modalCutiTahunan')">
    <div class="arp-modal-box" style="max-width: 720px;">
        <div class="arp-modal-header">
            <div>
                <h6 class="fw-bold mb-0">Ajukan Cuti Tahunan</h6>
                <small class="text-muted">Sisa saldo cuti tahunan Anda: <strong><?= (int) $balance['sisa'] ?>
                        hari</strong></small>
            </div>
            <button class="arp-modal-close" onclick="closeModal('modalCutiTahunan')">&times;</button>
        </div>
        <div class="arp-modal-body">
            <form method="POST" action="cuti.php?tab=tahunan" enctype="multipart/form-data">
                <input type="hidden" name="form_type" value="tahunan">
                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <label class="form-label fw-semibold fs-7 mb-2">Tanggal Mulai *</label>
                        <input type="date" name="tgl_mulai" id="tahunanTglMulai" class="form-control-custom"
                            onchange="hitungDurasi('tahunan')" required>
                    </div>
                    <div class="col-6">
                        <label class="form-label fw-semibold fs-7 mb-2">Tanggal Selesai *</label>
                        <input type="date" name="tgl_selesai" id="tahunanTglSelesai" class="form-control-custom"
                            onchange="hitungDurasi('tahunan')" required>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold fs-7 mb-2">Total Durasi</label>
                    <input type="text" id="tahunanTotalDurasi" class="form-control-custom" value="0 Hari" readonly
                        disabled style="background: var(--bg-glass); font-weight:600;">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold fs-7 mb-2">Keterangan</label>
                    <textarea name="alasan" class="textarea-custom"
                        placeholder="Tuliskan keterangan detail keperluan Anda..."></textarea>
                </div>
                <div class="mb-4">
                    <label class="form-label fw-semibold fs-7 mb-2">Unggah Dokumen Pendukung</label>
                    <div class="upload-dropzone" id="dzCutiTahunanNew">
                        <div class="upload-dropzone-icon"><i class="bi bi-cloud-arrow-up"></i></div>
                        <div>
                            <span class="fw-semibold" style="color: var(--primary);">Tarik &amp; lepas file di sini</span>
                            atau <span class="fw-semibold text-decoration-underline">Pilih File</span>
                        </div>
                        <span class="fs-7 text-muted">Format: PDF, JPG, PNG (Opsional)</span>
                        <input type="file" name="lampiran" id="inputCutiTahunanNew" class="d-none" accept=".pdf,.jpg,.jpeg,.png">
                        <div class="upload-dropzone-filelist" id="fileListCutiTahunanNew"></div>
                    </div>
                </div>

                <hr class="my-3">
                <p class="fw-semibold fs-7 mb-3">Data untuk Surat Cuti &amp; Pengalihan Tugas <span class="text-muted fw-normal">(diisi supaya bisa dicetak jadi surat)</span></p>
                <div class="row g-2 mb-3">
                    <div class="col-4">
                        <label class="form-label fw-semibold fs-7 mb-2">Jabatan</label>
                        <input type="text" name="jabatan_karyawan" id="tahunanJabatan" class="form-control-custom">
                    </div>
                    <div class="col-4">
                        <label class="form-label fw-semibold fs-7 mb-2">Divisi</label>
                        <input type="text" name="divisi_karyawan" id="tahunanDivisi" class="form-control-custom">
                    </div>
                    <div class="col-4">
                        <label class="form-label fw-semibold fs-7 mb-2">Sub Divisi</label>
                        <input type="text" name="sub_divisi_karyawan" id="tahunanSubDivisi" class="form-control-custom">
                    </div>
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <label class="form-label fw-semibold fs-7 mb-2">Surat Ditujukan Kepada Yth</label>
                        <input type="text" name="nama_penerima" id="tahunanNamaPenerima" class="form-control-custom"
                            placeholder="mis. Direksi PT. Aksara Riksa Perdana">
                    </div>
                    <div class="col-6">
                        <label class="form-label fw-semibold fs-7 mb-2">Alamat Selama Cuti</label>
                        <input type="text" name="alamat_cuti" id="tahunanAlamatCuti" class="form-control-custom">
                    </div>
                </div>

                <p class="fw-semibold fs-7 mb-3 mt-3">Serah Terima Tugas</p>
                <div class="row g-2 mb-3">
                    <div class="col-4">
                        <label class="form-label fw-semibold fs-7 mb-2">Tanggal Serah Terima</label>
                        <input type="date" name="tanggal_serah_terima" id="tahunanTglSerahTerima" class="form-control-custom">
                    </div>
                    <div class="col-4">
                        <label class="form-label fw-semibold fs-7 mb-2">Nama Penerima Tugas</label>
                        <input type="text" name="nama_penerima_tugas" id="tahunanNamaPenerimaTugas" class="form-control-custom">
                    </div>
                    <div class="col-4">
                        <label class="form-label fw-semibold fs-7 mb-2">Jabatan Penerima Tugas</label>
                        <input type="text" name="jabatan_penerima_tugas" id="tahunanJabatanPenerimaTugas" class="form-control-custom">
                    </div>
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <label class="form-label fw-semibold fs-7 mb-2">Sub Divisi Penerima Tugas</label>
                        <input type="text" name="sub_divisi_penerima_tugas" id="tahunanSubDivisiPenerimaTugas" class="form-control-custom">
                    </div>
                    <div class="col-6">
                        <label class="form-label fw-semibold fs-7 mb-2">Divisi Penerima Tugas</label>
                        <input type="text" name="divisi_penerima_tugas" id="tahunanDivisiPenerimaTugas" class="form-control-custom">
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold fs-7 mb-2">Uraian Tugas yang Diserahkan</label>
                    <table class="table table-sm align-middle mb-2" style="font-size:0.8rem;">
                        <thead>
                            <tr>
                                <th style="width:45%;">Uraian Tugas</th>
                                <th style="width:40%;">Status</th>
                                <th style="width:15%;"></th>
                            </tr>
                        </thead>
                        <tbody id="tahunanTugasBody"></tbody>
                    </table>
                    <button type="button" class="btn-secondary-custom" style="font-size:0.75rem;"
                        onclick="tambahBarisTugas('tahunanTugasBody')"><i class="bi bi-plus-lg me-1"></i> Tambah Baris</button>
                </div>
                <div class="mb-4">
                    <label class="form-label fw-semibold fs-7 mb-2">Mengetahui (Atasan / HRD)</label>
                    <input type="text" name="nama_mengetahui" id="tahunanNamaMengetahui" class="form-control-custom">
                </div>

                <div class="d-flex gap-2">
                    <button type="button" class="btn-secondary-custom flex-grow-1"
                        onclick="closeModal('modalCutiTahunan')">Batal</button>
                    <button type="submit" class="btn-primary-custom flex-grow-1"><i class="bi bi-send me-1"></i> Kirim
                        Pengajuan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ===== MODAL: Ajukan Cuti Khusus ===== -->
<div id="modalCutiKhusus" class="arp-modal-overlay" onclick="closeModalOutside(event, 'modalCutiKhusus')">
    <div class="arp-modal-box">
        <div class="arp-modal-header">
            <div>
                <h6 class="fw-bold mb-0">Ajukan Cuti Khusus</h6>
            </div>
            <button class="arp-modal-close" onclick="closeModal('modalCutiKhusus')">&times;</button>
        </div>
        <div class="arp-modal-body">
            <form method="POST" action="cuti.php?tab=khusus" enctype="multipart/form-data">
                <input type="hidden" name="form_type" value="khusus">
                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <label class="form-label fw-semibold fs-7 mb-2">Tanggal Mulai *</label>
                        <input type="date" name="tgl_mulai" id="khususTglMulai" class="form-control-custom"
                            onchange="hitungDurasi('khusus')" required>
                    </div>
                    <div class="col-6">
                        <label class="form-label fw-semibold fs-7 mb-2">Tanggal Selesai *</label>
                        <input type="date" name="tgl_selesai" id="khususTglSelesai" class="form-control-custom"
                            onchange="hitungDurasi('khusus')" required>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold fs-7 mb-2">Total Durasi</label>
                    <input type="text" id="khususTotalDurasi" class="form-control-custom" value="0 Hari" readonly
                        disabled style="background: var(--bg-glass); font-weight:600;">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold fs-7 mb-2">Keterangan</label>
                    <textarea name="alasan" class="textarea-custom"
                        placeholder="Tuliskan keterangan detail keperluan Anda..."></textarea>
                </div>
                <div class="mb-4">
                    <label class="form-label fw-semibold fs-7 mb-2">Unggah Dokumen Pendukung</label>
                    <div class="upload-dropzone" id="dzCutiKhususNew">
                        <div class="upload-dropzone-icon"><i class="bi bi-cloud-arrow-up"></i></div>
                        <div>
                            <span class="fw-semibold" style="color: var(--primary);">Tarik &amp; lepas file di sini</span>
                            atau <span class="fw-semibold text-decoration-underline">Pilih File</span>
                        </div>
                        <span class="fs-7 text-muted">Format: PDF, JPG, PNG (Opsional)</span>
                        <input type="file" name="lampiran" id="inputCutiKhususNew" class="d-none" accept=".pdf,.jpg,.jpeg,.png">
                        <div class="upload-dropzone-filelist" id="fileListCutiKhususNew"></div>
                    </div>
                </div>
                <div class="d-flex gap-2">
                    <button type="button" class="btn-secondary-custom flex-grow-1"
                        onclick="closeModal('modalCutiKhusus')">Batal</button>
                    <button type="submit" class="btn-primary-custom flex-grow-1"><i class="bi bi-send me-1"></i> Kirim
                        Pengajuan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ===== MODAL: Ajukan Izin Sakit ===== -->
<div id="modalIzinSakit" class="arp-modal-overlay" onclick="closeModalOutside(event, 'modalIzinSakit')">
    <div class="arp-modal-box">
        <div class="arp-modal-header">
            <div>
                <h6 class="fw-bold mb-0">Ajukan Izin Sakit</h6>
            </div>
            <button class="arp-modal-close" onclick="closeModal('modalIzinSakit')">&times;</button>
        </div>
        <div class="arp-modal-body">
            <form method="POST" action="cuti.php?tab=sakit" enctype="multipart/form-data">
                <input type="hidden" name="form_type" value="sakit">
                <div class="mb-3">
                    <label class="form-label fw-semibold fs-7 mb-2">Tanggal Sakit *</label>
                    <input type="date" name="tgl_sakit" class="form-control-custom" required>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold fs-7 mb-2">Total Hari Sakit *</label>
                    <input type="number" name="total_hari" class="form-control-custom" min="1" value="1" required>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold fs-7 mb-2">Keterangan</label>
                    <textarea name="alasan" class="textarea-custom"
                        placeholder="Tuliskan keterangan sakit Anda..."></textarea>
                </div>
                <div class="mb-4">
                    <label class="form-label fw-semibold fs-7 mb-2">Unggah Dokumen Pendukung</label>
                    <div class="upload-dropzone" id="dzCutiSakitNew">
                        <div class="upload-dropzone-icon"><i class="bi bi-cloud-arrow-up"></i></div>
                        <div>
                            <span class="fw-semibold" style="color: var(--primary);">Tarik &amp; lepas file di sini</span>
                            atau <span class="fw-semibold text-decoration-underline">Pilih File</span>
                        </div>
                        <span class="fs-7 text-muted">Format: PDF, JPG, PNG (Opsional)</span>
                        <input type="file" name="lampiran" id="inputCutiSakitNew" class="d-none" accept=".pdf,.jpg,.jpeg,.png">
                        <div class="upload-dropzone-filelist" id="fileListCutiSakitNew"></div>
                    </div>
                </div>
                <div class="d-flex gap-2">
                    <button type="button" class="btn-secondary-custom flex-grow-1"
                        onclick="closeModal('modalIzinSakit')">Batal</button>
                    <button type="submit" class="btn-primary-custom flex-grow-1"><i class="bi bi-send me-1"></i> Kirim
                        Pengajuan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ===== MODAL: Ubah Cuti Tahunan ===== -->
<div id="modalEditTahunan" class="arp-modal-overlay" onclick="closeModalOutside(event, 'modalEditTahunan')">
    <div class="arp-modal-box" style="max-width: 720px;">
        <div class="arp-modal-header">
            <div>
                <h6 class="fw-bold mb-0">Ubah Pengajuan Cuti Tahunan</h6>
                <small class="text-muted">Hanya pengajuan berstatus Menunggu yang dapat diubah.</small>
            </div>
            <button class="arp-modal-close" onclick="closeModal('modalEditTahunan')">&times;</button>
        </div>
        <div class="arp-modal-body">
            <form method="POST" action="cuti.php?tab=tahunan" enctype="multipart/form-data">
                <input type="hidden" name="action" value="edit_cuti">
                <input type="hidden" name="form_type" value="tahunan">
                <input type="hidden" name="cuti_id" id="editTahunanCutiId">
                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <label class="form-label fw-semibold fs-7 mb-2">Tanggal Mulai *</label>
                        <input type="date" name="tgl_mulai" id="editTahunanTglMulai" class="form-control-custom"
                            onchange="hitungDurasi('editTahunan')" required>
                    </div>
                    <div class="col-6">
                        <label class="form-label fw-semibold fs-7 mb-2">Tanggal Selesai *</label>
                        <input type="date" name="tgl_selesai" id="editTahunanTglSelesai" class="form-control-custom"
                            onchange="hitungDurasi('editTahunan')" required>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold fs-7 mb-2">Total Durasi</label>
                    <input type="text" id="editTahunanTotalDurasi" class="form-control-custom" value="0 Hari" readonly
                        disabled style="background: var(--bg-glass); font-weight:600;">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold fs-7 mb-2">Keterangan</label>
                    <textarea name="alasan" id="editTahunanAlasan" class="textarea-custom"></textarea>
                </div>
                <div class="mb-4">
                    <label class="form-label fw-semibold fs-7 mb-2">Ganti Dokumen Pendukung</label>
                    <div class="upload-dropzone" id="dzCutiTahunanEdit">
                        <div class="upload-dropzone-icon"><i class="bi bi-cloud-arrow-up"></i></div>
                        <div>
                            <span class="fw-semibold" style="color: var(--primary);">Tarik &amp; lepas file di sini</span>
                            atau <span class="fw-semibold text-decoration-underline">Pilih File</span>
                        </div>
                        <span class="fs-7 text-muted">Format: PDF, JPG, PNG (Opsional)</span>
                        <input type="file" name="lampiran" id="inputCutiTahunanEdit" class="d-none" accept=".pdf,.jpg,.jpeg,.png">
                        <div class="upload-dropzone-filelist" id="fileListCutiTahunanEdit"></div>
                    </div>
                </div>

                <hr class="my-3">
                <p class="fw-semibold fs-7 mb-3">Data untuk Surat Cuti &amp; Pengalihan Tugas <span class="text-muted fw-normal">(diisi supaya bisa dicetak jadi surat)</span></p>
                <div class="row g-2 mb-3">
                    <div class="col-4">
                        <label class="form-label fw-semibold fs-7 mb-2">Jabatan</label>
                        <input type="text" name="jabatan_karyawan" id="editTahunanJabatan" class="form-control-custom">
                    </div>
                    <div class="col-4">
                        <label class="form-label fw-semibold fs-7 mb-2">Divisi</label>
                        <input type="text" name="divisi_karyawan" id="editTahunanDivisi" class="form-control-custom">
                    </div>
                    <div class="col-4">
                        <label class="form-label fw-semibold fs-7 mb-2">Sub Divisi</label>
                        <input type="text" name="sub_divisi_karyawan" id="editTahunanSubDivisi" class="form-control-custom">
                    </div>
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <label class="form-label fw-semibold fs-7 mb-2">Surat Ditujukan Kepada Yth</label>
                        <input type="text" name="nama_penerima" id="editTahunanNamaPenerima" class="form-control-custom">
                    </div>
                    <div class="col-6">
                        <label class="form-label fw-semibold fs-7 mb-2">Alamat Selama Cuti</label>
                        <input type="text" name="alamat_cuti" id="editTahunanAlamatCuti" class="form-control-custom">
                    </div>
                </div>

                <p class="fw-semibold fs-7 mb-3 mt-3">Serah Terima Tugas</p>
                <div class="row g-2 mb-3">
                    <div class="col-4">
                        <label class="form-label fw-semibold fs-7 mb-2">Tanggal Serah Terima</label>
                        <input type="date" name="tanggal_serah_terima" id="editTahunanTglSerahTerima" class="form-control-custom">
                    </div>
                    <div class="col-4">
                        <label class="form-label fw-semibold fs-7 mb-2">Nama Penerima Tugas</label>
                        <input type="text" name="nama_penerima_tugas" id="editTahunanNamaPenerimaTugas" class="form-control-custom">
                    </div>
                    <div class="col-4">
                        <label class="form-label fw-semibold fs-7 mb-2">Jabatan Penerima Tugas</label>
                        <input type="text" name="jabatan_penerima_tugas" id="editTahunanJabatanPenerimaTugas" class="form-control-custom">
                    </div>
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <label class="form-label fw-semibold fs-7 mb-2">Sub Divisi Penerima Tugas</label>
                        <input type="text" name="sub_divisi_penerima_tugas" id="editTahunanSubDivisiPenerimaTugas" class="form-control-custom">
                    </div>
                    <div class="col-6">
                        <label class="form-label fw-semibold fs-7 mb-2">Divisi Penerima Tugas</label>
                        <input type="text" name="divisi_penerima_tugas" id="editTahunanDivisiPenerimaTugas" class="form-control-custom">
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold fs-7 mb-2">Uraian Tugas yang Diserahkan</label>
                    <table class="table table-sm align-middle mb-2" style="font-size:0.8rem;">
                        <thead>
                            <tr>
                                <th style="width:45%;">Uraian Tugas</th>
                                <th style="width:40%;">Status</th>
                                <th style="width:15%;"></th>
                            </tr>
                        </thead>
                        <tbody id="editTahunanTugasBody"></tbody>
                    </table>
                    <button type="button" class="btn-secondary-custom" style="font-size:0.75rem;"
                        onclick="tambahBarisTugas('editTahunanTugasBody')"><i class="bi bi-plus-lg me-1"></i> Tambah Baris</button>
                </div>
                <div class="mb-4">
                    <label class="form-label fw-semibold fs-7 mb-2">Mengetahui (Atasan / HRD)</label>
                    <input type="text" name="nama_mengetahui" id="editTahunanNamaMengetahui" class="form-control-custom">
                </div>

                <div class="d-flex gap-2">
                    <button type="button" class="btn-secondary-custom flex-grow-1"
                        onclick="closeModal('modalEditTahunan')">Batal</button>
                    <button type="submit" class="btn-primary-custom flex-grow-1"><i class="bi bi-save me-1"></i> Simpan
                        Perubahan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ===== MODAL: Ubah Cuti Khusus ===== -->
<div id="modalEditKhusus" class="arp-modal-overlay" onclick="closeModalOutside(event, 'modalEditKhusus')">
    <div class="arp-modal-box">
        <div class="arp-modal-header">
            <div>
                <h6 class="fw-bold mb-0">Ubah Pengajuan Cuti Khusus</h6>
                <small class="text-muted">Hanya pengajuan berstatus Menunggu yang dapat diubah.</small>
            </div>
            <button class="arp-modal-close" onclick="closeModal('modalEditKhusus')">&times;</button>
        </div>
        <div class="arp-modal-body">
            <form method="POST" action="cuti.php?tab=khusus" enctype="multipart/form-data">
                <input type="hidden" name="action" value="edit_cuti">
                <input type="hidden" name="form_type" value="khusus">
                <input type="hidden" name="cuti_id" id="editKhususCutiId">
                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <label class="form-label fw-semibold fs-7 mb-2">Tanggal Mulai *</label>
                        <input type="date" name="tgl_mulai" id="editKhususTglMulai" class="form-control-custom"
                            onchange="hitungDurasi('editKhusus')" required>
                    </div>
                    <div class="col-6">
                        <label class="form-label fw-semibold fs-7 mb-2">Tanggal Selesai *</label>
                        <input type="date" name="tgl_selesai" id="editKhususTglSelesai" class="form-control-custom"
                            onchange="hitungDurasi('editKhusus')" required>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold fs-7 mb-2">Total Durasi</label>
                    <input type="text" id="editKhususTotalDurasi" class="form-control-custom" value="0 Hari" readonly
                        disabled style="background: var(--bg-glass); font-weight:600;">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold fs-7 mb-2">Keterangan</label>
                    <textarea name="alasan" id="editKhususAlasan" class="textarea-custom"></textarea>
                </div>
                <div class="mb-4">
                    <label class="form-label fw-semibold fs-7 mb-2">Ganti Dokumen Pendukung</label>
                    <div class="upload-dropzone" id="dzCutiKhususEdit">
                        <div class="upload-dropzone-icon"><i class="bi bi-cloud-arrow-up"></i></div>
                        <div>
                            <span class="fw-semibold" style="color: var(--primary);">Tarik &amp; lepas file di sini</span>
                            atau <span class="fw-semibold text-decoration-underline">Pilih File</span>
                        </div>
                        <span class="fs-7 text-muted">Format: PDF, JPG, PNG (Opsional)</span>
                        <input type="file" name="lampiran" id="inputCutiKhususEdit" class="d-none" accept=".pdf,.jpg,.jpeg,.png">
                        <div class="upload-dropzone-filelist" id="fileListCutiKhususEdit"></div>
                    </div>
                </div>
                <div class="d-flex gap-2">
                    <button type="button" class="btn-secondary-custom flex-grow-1"
                        onclick="closeModal('modalEditKhusus')">Batal</button>
                    <button type="submit" class="btn-primary-custom flex-grow-1"><i class="bi bi-save me-1"></i> Simpan
                        Perubahan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ===== MODAL: Ubah Izin Sakit ===== -->
<div id="modalEditSakit" class="arp-modal-overlay" onclick="closeModalOutside(event, 'modalEditSakit')">
    <div class="arp-modal-box">
        <div class="arp-modal-header">
            <div>
                <h6 class="fw-bold mb-0">Ubah Pengajuan Izin Sakit</h6>
                <small class="text-muted">Hanya pengajuan berstatus Menunggu yang dapat diubah.</small>
            </div>
            <button class="arp-modal-close" onclick="closeModal('modalEditSakit')">&times;</button>
        </div>
        <div class="arp-modal-body">
            <form method="POST" action="cuti.php?tab=sakit" enctype="multipart/form-data">
                <input type="hidden" name="action" value="edit_cuti">
                <input type="hidden" name="form_type" value="sakit">
                <input type="hidden" name="cuti_id" id="editSakitCutiId">
                <div class="mb-3">
                    <label class="form-label fw-semibold fs-7 mb-2">Tanggal Sakit *</label>
                    <input type="date" name="tgl_sakit" id="editSakitTglSakit" class="form-control-custom" required>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold fs-7 mb-2">Total Hari Sakit *</label>
                    <input type="number" name="total_hari" id="editSakitTotalHari" class="form-control-custom" min="1"
                        required>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold fs-7 mb-2">Keterangan</label>
                    <textarea name="alasan" id="editSakitAlasan" class="textarea-custom"></textarea>
                </div>
                <div class="mb-4">
                    <label class="form-label fw-semibold fs-7 mb-2">Ganti Dokumen Pendukung</label>
                    <div class="upload-dropzone" id="dzCutiSakitEdit">
                        <div class="upload-dropzone-icon"><i class="bi bi-cloud-arrow-up"></i></div>
                        <div>
                            <span class="fw-semibold" style="color: var(--primary);">Tarik &amp; lepas file di sini</span>
                            atau <span class="fw-semibold text-decoration-underline">Pilih File</span>
                        </div>
                        <span class="fs-7 text-muted">Format: PDF, JPG, PNG (Opsional)</span>
                        <input type="file" name="lampiran" id="inputCutiSakitEdit" class="d-none" accept=".pdf,.jpg,.jpeg,.png">
                        <div class="upload-dropzone-filelist" id="fileListCutiSakitEdit"></div>
                    </div>
                </div>
                <div class="d-flex gap-2">
                    <button type="button" class="btn-secondary-custom flex-grow-1"
                        onclick="closeModal('modalEditSakit')">Batal</button>
                    <button type="submit" class="btn-primary-custom flex-grow-1"><i class="bi bi-save me-1"></i> Simpan
                        Perubahan</button>
                </div>
            </form>
        </div>
    </div>
</div>


<script>
    document.addEventListener('DOMContentLoaded', function () {
        initTablePagination('tabelCutiTahunan', 10);
        initTablePagination('tabelCutiKhusus', 10);
        initTablePagination('tabelIzinSakit', 10);
        initTablePagination('tabelSaldoCuti', 10);
        <?php if ($error_msg && in_array($active_tab, ['tahunan', 'khusus', 'sakit'])): ?>
            openModal('modal<?= ucfirst($active_tab === 'sakit' ? 'IzinSakit' : 'Cuti' . ucfirst($active_tab)) ?>');
        <?php endif; ?>
    });

    function hitungDurasi(prefix) {
        const mulaiEl = document.getElementById(prefix + 'TglMulai');
        const selesaiEl = document.getElementById(prefix + 'TglSelesai');
        const outputEl = document.getElementById(prefix + 'TotalDurasi');
        if (!mulaiEl.value || !selesaiEl.value) {
            outputEl.value = '0 Hari';
            return;
        }
        const mulai = new Date(mulaiEl.value);
        const selesai = new Date(selesaiEl.value);
        const selisihHari = Math.round((selesai - mulai) / (1000 * 60 * 60 * 24)) + 1;
        outputEl.value = (selisihHari > 0 ? selisihHari : 0) + ' Hari';
    }
    function tambahBarisTugas(tbodyId, deskripsi, status) {
        const tbody = document.getElementById(tbodyId);
        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td><input type="text" name="item_deskripsi[]" class="form-control-custom form-control-sm" value="${(deskripsi || '').replace(/"/g, '&quot;')}"></td>
            <td><input type="text" name="item_status[]" class="form-control-custom form-control-sm" value="${(status || '').replace(/"/g, '&quot;')}" placeholder="mis. Selesai / Dilanjutkan"></td>
            <td><button type="button" class="btn btn-outline-danger btn-sm py-1" style="font-size:0.7rem;" onclick="this.closest('tr').remove()"><i class="bi bi-trash"></i></button></td>
        `;
        tbody.appendChild(tr);
    }

    function openEditCuti(prefix, data) {
        document.getElementById('edit' + prefix + 'CutiId').value = data.id;
        document.getElementById('edit' + prefix + 'Alasan').value = data.alasan || '';

        if (prefix === 'Sakit') {
            document.getElementById('editSakitTglSakit').value = data.tgl_mulai;
            document.getElementById('editSakitTotalHari').value = data.total_hari;
        } else {
            document.getElementById('edit' + prefix + 'TglMulai').value = data.tgl_mulai;
            document.getElementById('edit' + prefix + 'TglSelesai').value = data.tgl_selesai;
            hitungDurasi('edit' + prefix);
        }

        if (prefix === 'Tahunan') {
            document.getElementById('editTahunanJabatan').value = data.jabatan_karyawan || '';
            document.getElementById('editTahunanDivisi').value = data.divisi_karyawan || '';
            document.getElementById('editTahunanSubDivisi').value = data.sub_divisi_karyawan || '';
            document.getElementById('editTahunanNamaPenerima').value = data.nama_penerima || '';
            document.getElementById('editTahunanAlamatCuti').value = data.alamat_cuti || '';
            document.getElementById('editTahunanTglSerahTerima').value = data.tanggal_serah_terima || '';
            document.getElementById('editTahunanNamaPenerimaTugas').value = data.nama_penerima_tugas || '';
            document.getElementById('editTahunanJabatanPenerimaTugas').value = data.jabatan_penerima_tugas || '';
            document.getElementById('editTahunanSubDivisiPenerimaTugas').value = data.sub_divisi_penerima_tugas || '';
            document.getElementById('editTahunanDivisiPenerimaTugas').value = data.divisi_penerima_tugas || '';
            document.getElementById('editTahunanNamaMengetahui').value = data.nama_mengetahui || '';

            const tbody = document.getElementById('editTahunanTugasBody');
            tbody.innerHTML = '';
            (data.items || []).forEach(function (item) {
                tambahBarisTugas('editTahunanTugasBody', item.deskripsi, item.status);
            });
        }

        openModal('modalEdit' + prefix);
    }
</script>

<?php if ($highlight_id): ?>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const row = document.getElementById('cuti-row-<?= $highlight_id ?>');
            if (row) row.scrollIntoView({ behavior: 'smooth', block: 'center' });
        });
    </script>
<?php endif; ?>

<?php include "../includes/footer.php"; ?>