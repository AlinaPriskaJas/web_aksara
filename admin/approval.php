<?php
// admin/approval.php
session_start();
require_once "../config/koneksi.php";

// TODO: ganti dengan user_id dari sesi login sebenarnya (proses_login.php belum tersambung penuh).
$admin_id = $_SESSION['user_id'] ?? 1;

$page_title = "Approval Pengajuan Pemeriksaan";
$flash = null;

// ================== TAB AKTIF (Pemeriksaan / Surat) ==================
$tab_aktif = $_GET['tab'] ?? 'pemeriksaan';
if (!in_array($tab_aktif, ['pemeriksaan', 'surat'], true)) {
    $tab_aktif = 'pemeriksaan';
}

// ================== PROSES SETUJUI / TOLAK PENGAJUAN ==================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['proses_approval'])) {
    $pengajuan_id = (int) ($_POST['pengajuan_id'] ?? 0);
    $decision     = $_POST['decision'] ?? ''; // 'approve' | 'reject'
    $catatan      = trim($_POST['catatan'] ?? '');

    $status_map = [
        'approve' => ['pengajuan' => 'Diverifikasi', 'approval' => 'Disetujui'],
        'reject'  => ['pengajuan' => 'Ditolak',      'approval' => 'Ditolak'],
    ];

    if ($pengajuan_id <= 0 || !isset($status_map[$decision])) {
        $flash = ['type' => 'danger', 'message' => 'Permintaan tidak valid.'];
    } elseif ($decision === 'reject' && $catatan === '') {
        $flash = ['type' => 'danger', 'message' => 'Catatan wajib diisi saat menolak pengajuan.'];
    } else {
        try {
            $conn->beginTransaction();

            $cek = $conn->prepare("SELECT diajukan_oleh, status FROM Pengajuan_Pemeriksaan WHERE id = :id FOR UPDATE");
            $cek->execute([':id' => $pengajuan_id]);
            $row = $cek->fetch();

            if (!$row) {
                throw new RuntimeException("Data pengajuan tidak ditemukan.");
            }
            if ($row['status'] !== 'Menunggu Verifikasi') {
                throw new RuntimeException("Pengajuan ini sudah diproses sebelumnya (" . $row['status'] . ").");
            }

            $status_pengajuan = $status_map[$decision]['pengajuan'];
            $status_approval  = $status_map[$decision]['approval'];

            $update = $conn->prepare("
                UPDATE Pengajuan_Pemeriksaan
                SET status = :status, catatan_admin = :catatan, diproses_oleh = :admin_id
                WHERE id = :id
            ");
            $update->execute([
                ':status'   => $status_pengajuan,
                ':catatan'  => $catatan !== '' ? $catatan : null,
                ':admin_id' => $admin_id,
                ':id'       => $pengajuan_id,
            ]);

            $cekApproval = $conn->prepare("
                SELECT id FROM Approval
                WHERE jenis_pengajuan = 'Pengajuan Pemeriksaan' AND ref_id = :ref_id AND status = 'Menunggu'
                ORDER BY id DESC LIMIT 1
            ");
            $cekApproval->execute([':ref_id' => $pengajuan_id]);
            $approvalRow = $cekApproval->fetch();

            if ($approvalRow) {
                $updApproval = $conn->prepare("
                    UPDATE Approval
                    SET status = :status, approver_id = :approver_id, catatan = :catatan, tgl_aksi = NOW()
                    WHERE id = :id
                ");
                $updApproval->execute([
                    ':status'      => $status_approval,
                    ':approver_id' => $admin_id,
                    ':catatan'     => $catatan !== '' ? $catatan : null,
                    ':id'          => $approvalRow['id'],
                ]);
            } else {
                $insertApproval = $conn->prepare("
                    INSERT INTO Approval (jenis_pengajuan, ref_id, requester_id, approver_id, level, status, catatan, tgl_aksi)
                    VALUES ('Pengajuan Pemeriksaan', :ref_id, :requester_id, :approver_id, 1, :status, :catatan, NOW())
                ");
                $insertApproval->execute([
                    ':ref_id'       => $pengajuan_id,
                    ':requester_id' => $row['diajukan_oleh'],
                    ':approver_id'  => $admin_id,
                    ':status'       => $status_approval,
                    ':catatan'      => $catatan !== '' ? $catatan : null,
                ]);
            }

            // Beri tahu client (yang mengajukan) hasil verifikasinya lewat Notifikasi
            try {
                $stmtNotifClient = $conn->prepare("
                    INSERT INTO Notifikasi (user_id, judul, pesan, modul_terkait, ref_id, sudah_dibaca)
                    VALUES (:user_id, :judul, :pesan, 'Pengajuan_Pemeriksaan', :ref_id, 0)
                ");
                $stmtNotifClient->execute([
                    ':user_id' => $row['diajukan_oleh'],
                    ':judul'   => $decision === 'approve' ? 'Pengajuan Disetujui' : 'Pengajuan Ditolak',
                    ':pesan'   => $decision === 'approve'
                        ? 'Pengajuan pemeriksaan Anda telah diverifikasi dan disetujui Admin.'
                        : 'Pengajuan pemeriksaan Anda ditolak Admin. Catatan: ' . ($catatan !== '' ? $catatan : '-'),
                    ':ref_id'  => $pengajuan_id,
                ]);
            } catch (PDOException $e) {
                // Jangan gagalkan proses approval hanya karena notifikasi gagal dibuat
            }

            $conn->commit();

            $flash = [
                'type' => 'success',
                'message' => $decision === 'approve'
                    ? 'Pengajuan pemeriksaan berhasil disetujui.'
                    : 'Pengajuan pemeriksaan berhasil ditolak.',
            ];
        } catch (Exception $e) {
            $conn->rollBack();
            $flash = ['type' => 'danger', 'message' => 'Gagal memproses: ' . $e->getMessage()];
        }
    }

    $_SESSION['approval_flash'] = $flash;
    $redirect_status = $_GET['status'] ?? '';
    header("Location: approval.php" . ($redirect_status !== '' ? '?status=' . urlencode($redirect_status) : ''));
    exit;
}

// ================== PROSES UBAH TANGGAL DIINGINKAN ==================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ubah_tanggal'])) {
    $pengajuan_id = (int) ($_POST['pengajuan_id'] ?? 0);
    $tanggal_baru = trim($_POST['tanggal_baru'] ?? '');

    if ($pengajuan_id <= 0 || $tanggal_baru === '') {
        $flash = ['type' => 'danger', 'message' => 'Tanggal baru wajib diisi.'];
    } else {
        try {
            $stmt = $conn->prepare("
                UPDATE Pengajuan_Pemeriksaan
                SET tanggal_diinginkan = :tanggal
                WHERE id = :id
            ");
            $stmt->execute([
                ':tanggal' => $tanggal_baru,
                ':id'      => $pengajuan_id,
            ]);
            $flash = ['type' => 'success', 'message' => 'Tanggal diinginkan berhasil diperbarui.'];
        } catch (PDOException $e) {
            $flash = ['type' => 'danger', 'message' => 'Gagal memperbarui tanggal: ' . $e->getMessage()];
        }
    }

    $_SESSION['approval_flash'] = $flash;
    $redirect_status = $_GET['status'] ?? '';
    header("Location: approval.php" . ($redirect_status !== '' ? '?status=' . urlencode($redirect_status) : ''));
    exit;
}

// ================== PROSES SETUJUI / TOLAK SURAT ==================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['proses_approval_surat'])) {
    $surat_id = (int) ($_POST['surat_id'] ?? 0);
    $decision = $_POST['decision'] ?? ''; // 'approve' | 'reject'
    $catatan  = trim($_POST['catatan'] ?? '');

    $status_map_surat = [
        'approve' => 'Disetujui',
        'reject'  => 'Ditolak',
    ];

    if ($surat_id <= 0 || !isset($status_map_surat[$decision])) {
        $flash = ['type' => 'danger', 'message' => 'Permintaan tidak valid.'];
    } elseif ($decision === 'reject' && $catatan === '') {
        $flash = ['type' => 'danger', 'message' => 'Catatan wajib diisi saat menolak surat.'];
    } else {
        try {
            $conn->beginTransaction();

            $cekSurat = $conn->prepare("SELECT dibuat_oleh, status, nomor, perihal FROM surat WHERE id = :id FOR UPDATE");
            $cekSurat->execute([':id' => $surat_id]);
            $rowSurat = $cekSurat->fetch();

            if (!$rowSurat) {
                throw new RuntimeException("Data surat tidak ditemukan.");
            }
            if ($rowSurat['status'] !== 'Diajukan') {
                throw new RuntimeException("Surat ini sudah diproses sebelumnya (" . $rowSurat['status'] . ").");
            }

            $status_surat_baru = $status_map_surat[$decision];

            $updSurat = $conn->prepare("UPDATE surat SET status = :status WHERE id = :id");
            $updSurat->execute([
                ':status' => $status_surat_baru,
                ':id'     => $surat_id,
            ]);

            $cekApprovalSurat = $conn->prepare("
                SELECT id FROM Approval
                WHERE jenis_pengajuan = 'Surat' AND ref_id = :ref_id AND status = 'Menunggu'
                ORDER BY id DESC LIMIT 1
            ");
            $cekApprovalSurat->execute([':ref_id' => $surat_id]);
            $approvalRowSurat = $cekApprovalSurat->fetch();

            if ($approvalRowSurat) {
                $updApprovalSurat = $conn->prepare("
                    UPDATE Approval
                    SET status = :status, approver_id = :approver_id, catatan = :catatan, tgl_aksi = NOW()
                    WHERE id = :id
                ");
                $updApprovalSurat->execute([
                    ':status'      => $status_surat_baru,
                    ':approver_id' => $admin_id,
                    ':catatan'     => $catatan !== '' ? $catatan : null,
                    ':id'          => $approvalRowSurat['id'],
                ]);
            } else {
                $insertApprovalSurat = $conn->prepare("
                    INSERT INTO Approval (jenis_pengajuan, ref_id, requester_id, approver_id, level, status, catatan, tgl_aksi)
                    VALUES ('Surat', :ref_id, :requester_id, :approver_id, 1, :status, :catatan, NOW())
                ");
                $insertApprovalSurat->execute([
                    ':ref_id'       => $surat_id,
                    ':requester_id' => $rowSurat['dibuat_oleh'],
                    ':approver_id'  => $admin_id,
                    ':status'       => $status_surat_baru,
                    ':catatan'      => $catatan !== '' ? $catatan : null,
                ]);
            }

            try {
                $stmtNotifSurat = $conn->prepare("
                    INSERT INTO Notifikasi (user_id, judul, pesan, modul_terkait, ref_id, sudah_dibaca)
                    VALUES (:user_id, :judul, :pesan, 'Surat', :ref_id, 0)
                ");
                $stmtNotifSurat->execute([
                    ':user_id' => $rowSurat['dibuat_oleh'],
                    ':judul'   => $decision === 'approve' ? 'Surat Disetujui' : 'Surat Ditolak',
                    ':pesan'   => $decision === 'approve'
                        ? 'Surat "' . $rowSurat['perihal'] . '" telah disetujui Admin.'
                        : 'Surat "' . $rowSurat['perihal'] . '" ditolak Admin. Catatan: ' . ($catatan !== '' ? $catatan : '-'),
                    ':ref_id'  => $surat_id,
                ]);
            } catch (PDOException $e) {
                // Jangan gagalkan proses approval hanya karena notifikasi gagal dibuat
            }

            $conn->commit();

            $flash = [
                'type' => 'success',
                'message' => $decision === 'approve'
                    ? 'Surat berhasil disetujui.'
                    : 'Surat berhasil ditolak.',
            ];
        } catch (Exception $e) {
            $conn->rollBack();
            $flash = ['type' => 'danger', 'message' => 'Gagal memproses: ' . $e->getMessage()];
        }
    }

    $_SESSION['approval_flash'] = $flash;
    $redirect_status_surat = $_GET['status_surat'] ?? '';
    header("Location: approval.php?tab=surat" . ($redirect_status_surat !== '' ? '&status_surat=' . urlencode($redirect_status_surat) : ''));
    exit;
}

$flash = $_SESSION['approval_flash'] ?? $flash;
unset($_SESSION['approval_flash']);

// ================== FILTER STATUS ==================
$status_filter = $_GET['status'] ?? 'Menunggu Verifikasi';
$valid_statuses = ['Menunggu Verifikasi', 'Diverifikasi', 'Dijadwalkan', 'Ditolak', 'Selesai', 'Semua'];
if (!in_array($status_filter, $valid_statuses, true)) {
    $status_filter = 'Menunggu Verifikasi';
}

$counts = [
    'Menunggu Verifikasi' => 0,
    'Diverifikasi'        => 0,
    'Ditolak'             => 0,
    'Selesai'             => 0,
];
try {
    $stmtCount = $conn->query("SELECT status, COUNT(*) AS jumlah FROM Pengajuan_Pemeriksaan GROUP BY status");
    foreach ($stmtCount->fetchAll() as $c) {
        if (isset($counts[$c['status']])) {
            $counts[$c['status']] = (int) $c['jumlah'];
        }
    }
} catch (PDOException $e) {
    // biarkan default 0 jika query gagal
}

// ================== AMBIL DAFTAR PENGAJUAN (harus dijalankan DULUAN) ==================
$daftar_pengajuan = [];
try {
    $sql = "
        SELECT pp.*, dk.nama_perusahaan AS nama_perusahaan_klien, dk.kode_klien, u.nama_lengkap AS nama_pengaju
        FROM Pengajuan_Pemeriksaan pp
        LEFT JOIN Data_Klien dk ON pp.klien_id = dk.id
        LEFT JOIN Users u ON pp.diajukan_oleh = u.id
    ";
    $params = [];
    if ($status_filter !== 'Semua') {
        $sql .= " WHERE pp.status = :status ";
        $params[':status'] = $status_filter;
    }
    $sql .= " ORDER BY pp.created_at DESC";

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $daftar_pengajuan = $stmt->fetchAll();
} catch (PDOException $e) {
    $daftar_pengajuan = [];
}

// ================== AMBIL SEMUA UNIT PER PENGAJUAN (1 pengajuan bisa banyak unit) ==================
$unit_per_pengajuan = [];
if (!empty($daftar_pengajuan)) {
    try {
        $ids = array_column($daftar_pengajuan, 'id');
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmtUnit = $conn->prepare("
            SELECT pu.pengajuan_id, pu.nama_unit, pu.jenis_pemeriksaan, k.nama_kategori AS bidang
            FROM Pengajuan_Pemeriksaan_Unit pu
            LEFT JOIN jenis_objek_k3 j ON j.id_jenis = pu.id_jenis
            LEFT JOIN kategori_objek_k3 k ON k.id_kategori = j.id_kategori
            WHERE pu.pengajuan_id IN ($placeholders)
            ORDER BY pu.pengajuan_id ASC, pu.urutan ASC, pu.id ASC
        ");
        $stmtUnit->execute($ids);
        foreach ($stmtUnit->fetchAll() as $u) {
            $unit_per_pengajuan[$u['pengajuan_id']][] = [
                'bidang' => $u['bidang'],
                'unit'   => $u['nama_unit'],
                'jenis'  => $u['jenis_pemeriksaan'],
            ];
        }
    } catch (PDOException $e) {
        $unit_per_pengajuan = [];
    }
}

function ambil_unit_pengajuan(array $p, array $unit_per_pengajuan): array
{
    if (!empty($unit_per_pengajuan[$p['id']])) {
        return $unit_per_pengajuan[$p['id']];
    }
    return [[
        'bidang' => $p['klasifikasi_objek_k3'] ?? null,
        'unit'   => $p['jenis_objek'] ?? null,
        'jenis'  => $p['jenis_pemeriksaan'] ?? null,
    ]];
}

function kelompokkan_unit_per_bidang(array $unit_list): array
{
    $grup = [];
    foreach ($unit_list as $u) {
        $namaBidang = (!empty($u['bidang'])) ? $u['bidang'] : 'Belum Ada Bidang';
        if (!isset($grup[$namaBidang])) {
            $grup[$namaBidang] = [];
        }
        $grup[$namaBidang][] = [
            'unit'  => $u['unit'] ?? '-',
            'jenis' => $u['jenis'] ?? null,
        ];
    }
    return $grup;
}

function singkat_jenis_pemeriksaan(?string $jenis): string
{
    $map = [
        'Pemeriksaan Baru'    => 'Baru',
        'Pemeriksaan Berkala' => 'Berkala',
        'Pemeriksaan Ulang'   => 'Ulang',
        'Pemeriksaan Khusus'  => 'Khusus',
    ];
    return $map[$jenis] ?? ($jenis ?? '');
}

function badge_class_status(string $status): string
{
    switch ($status) {
        case 'Menunggu Verifikasi':
            return 'badge-warning';
        case 'Diverifikasi':
        case 'Dijadwalkan':
            return 'badge-info';
        case 'Selesai':
            return 'badge-success';
        case 'Ditolak':
            return 'badge-danger';
        default:
            return 'badge-secondary';
    }
}

// ================== FILTER STATUS SURAT ==================
$status_filter_surat = $_GET['status_surat'] ?? 'Diajukan';
$valid_statuses_surat = ['Diajukan', 'Disetujui', 'Ditolak', 'Draft', 'Semua'];
if (!in_array($status_filter_surat, $valid_statuses_surat, true)) {
    $status_filter_surat = 'Diajukan';
}

$counts_surat = [
    'Diajukan'  => 0,
    'Disetujui' => 0,
    'Ditolak'   => 0,
    'Draft'     => 0,
];
try {
    $stmtCountSurat = $conn->query("SELECT status, COUNT(*) AS jumlah FROM surat GROUP BY status");
    foreach ($stmtCountSurat->fetchAll() as $c) {
        if (isset($counts_surat[$c['status']])) {
            $counts_surat[$c['status']] = (int) $c['jumlah'];
        }
    }
} catch (PDOException $e) {
    // biarkan default 0 jika query gagal
}

// ================== AMBIL DAFTAR SURAT ==================
$daftar_surat = [];
try {
    $sqlSurat = "
        SELECT s.*, u.nama_lengkap AS nama_pembuat, ks.nama AS nama_jenis_surat
        FROM surat s
        LEFT JOIN Users u ON s.dibuat_oleh = u.id
        LEFT JOIN kode_surat ks ON s.kode_id = ks.id
    ";
    $paramsSurat = [];
    if ($status_filter_surat !== 'Semua') {
        $sqlSurat .= " WHERE s.status = :status ";
        $paramsSurat[':status'] = $status_filter_surat;
    }
    $sqlSurat .= " ORDER BY s.created_at DESC";

    $stmtSurat = $conn->prepare($sqlSurat);
    $stmtSurat->execute($paramsSurat);
    $daftar_surat = $stmtSurat->fetchAll();
} catch (PDOException $e) {
    $daftar_surat = [];
}

function badge_class_status_surat(string $status): string
{
    switch ($status) {
        case 'Diajukan':
            return 'badge-warning';
        case 'Disetujui':
            return 'badge-success';
        case 'Ditolak':
            return 'badge-danger';
        case 'Draft':
        default:
            return 'badge-secondary';
    }
}

include "../includes/header.php";
include "../includes/sidebar.php";
include "../includes/topbar.php";
?>

<style>
.table-custom {
    table-layout: fixed;
    width: 100%;
}
.table-custom colgroup col.col-no       { width: 44px; }
.table-custom colgroup col.col-tanggal  { width: 110px; }
.table-custom colgroup col.col-perusahaan { width: 190px; }
.table-custom colgroup col.col-pengaju  { width: 130px; }
.table-custom colgroup col.col-unit     { width: 240px; }
.table-custom colgroup col.col-jenis    { width: 140px; }
/* Kolom Tgl. Diinginkan dilebarkan supaya muat tanggal + tombol ubah tanggal berdampingan */
.table-custom colgroup col.col-tgldiinginkan { width: 170px; }
.table-custom colgroup col.col-status   { width: 110px; }
.table-custom colgroup col.col-aksi     { width: 210px; }

.table-custom th,
.table-custom td {
    vertical-align: top;
    padding: 12px 14px;
    word-wrap: break-word;
    overflow-wrap: break-word;
}
.table-custom td.align-middle {
    vertical-align: middle;
}

.cell-perusahaan .nama-utama {
    font-weight: 600;
    color: #1e293b;
    line-height: 1.3;
}
.cell-perusahaan .sub-info {
    font-size: 0.74rem;
    color: #94a3b8;
    line-height: 1.4;
    margin-top: 2px;
}

.bidang-group {
    background: #f8fafc;
    border: 1px solid #eef1f5;
    border-radius: 8px;
    padding: 8px 10px;
}
.bidang-group + .bidang-group {
    margin-top: 6px;
}
.bidang-group-title {
    font-weight: 700;
    font-size: 0.72rem;
    text-transform: uppercase;
    letter-spacing: 0.02em;
    color: #64748b;
    margin-bottom: 5px;
}
.bidang-group-list {
    margin: 0;
    padding: 0;
    list-style: none;
}
.bidang-group-list li {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    font-size: 0.8rem;
    color: #334155;
    padding: 2px 0;
}
.bidang-group-list li:not(:last-child) {
    border-bottom: 1px dashed #e2e8f0;
}
.bidang-group-list .unit-name {
    flex: 1;
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.unit-count-note {
    font-size: 0.72rem;
    color: #94a3b8;
    margin-top: 6px;
}

.badge-jenis-unit {
    flex-shrink: 0;
    display: inline-block;
    font-size: 0.66rem;
    font-weight: 700;
    padding: 2px 8px;
    border-radius: 999px;
    white-space: nowrap;
}
.badge-jenis-unit.jenis-baru    { background: #eef2ff; color: #4338ca; }
.badge-jenis-unit.jenis-berkala { background: #ecfdf5; color: #047857; }
.badge-jenis-unit.jenis-ulang   { background: #fff7ed; color: #c2410c; }
.badge-jenis-unit.jenis-khusus  { background: #fdf2f8; color: #be185d; }
.badge-jenis-unit.jenis-lainnya { background: #f1f5f9; color: #475569; }

.jenis-ringkasan {
    display: flex;
    flex-wrap: wrap;
    gap: 4px;
}

/* ---- Sel Tgl. Diinginkan: tanggal + tombol ubah berdampingan ---- */
.cell-tgl-diinginkan {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 6px;
}
.cell-tgl-diinginkan .tgl-text {
    white-space: nowrap;
}
.btn-ubah-tanggal {
    flex-shrink: 0;
    width: 28px;
    height: 28px;
    border-radius: 6px;
    border: 1px solid #e2e8f0;
    background: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    color: #64748b;
    font-size: 0.78rem;
    transition: all .15s ease;
}
.btn-ubah-tanggal:hover {
    background: #eef2ff;
    border-color: #c7d2fe;
    color: #4338ca;
}

.aksi-wrapper {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}
.aksi-wrapper .btn-primary-custom,
.aksi-wrapper .btn-secondary-custom {
    white-space: nowrap;
}

.table-custom tbody tr:nth-child(even) {
    background: #fafbfc;
}
.table-custom tbody tr:hover {
    background: #f1f5f9;
}

/* ---- Tab navigasi Approval (Pemeriksaan / Surat) ---- */
.nav-tabs-custom {
    display: flex;
    gap: 4px;
    border-bottom: 1px solid #e2e8f0;
    list-style: none;
    padding: 0;
    margin: 0 0 1.5rem 0;
}
.nav-tabs-custom .nav-link {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 10px 18px;
    font-size: 0.88rem;
    font-weight: 600;
    color: #64748b;
    text-decoration: none;
    border-bottom: 2px solid transparent;
    border-radius: 8px 8px 0 0;
    transition: all .15s ease;
}
.nav-tabs-custom .nav-link:hover {
    color: #4338ca;
    background: #f8fafc;
}
.nav-tabs-custom .nav-link.active {
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

    <ul class="nav nav-tabs-custom mb-4">
        <li class="nav-item">
            <a class="nav-link <?= $tab_aktif === 'pemeriksaan' ? 'active' : '' ?>" href="approval.php?tab=pemeriksaan">
                <i class="bi bi-clipboard2-check"></i> Pengajuan Pemeriksaan
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $tab_aktif === 'surat' ? 'active' : '' ?>" href="approval.php?tab=surat">
                <i class="bi bi-envelope-paper"></i> Surat
            </a>
        </li>
    </ul>

    <?php if ($tab_aktif === 'pemeriksaan'): ?>

    <div class="row g-4 mb-4">
        <div class="col-xl-3 col-md-6 col-12">
            <div class="stat-card">
                <div class="stat-card-info">
                    <span class="stat-card-title">Menunggu Verifikasi</span>
                    <span class="stat-card-value"><?= $counts['Menunggu Verifikasi'] ?></span>
                </div>
                <div class="stat-card-icon warning"><i class="bi bi-hourglass-split"></i></div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6 col-12">
            <div class="stat-card">
                <div class="stat-card-info">
                    <span class="stat-card-title">Diverifikasi</span>
                    <span class="stat-card-value"><?= $counts['Diverifikasi'] ?></span>
                </div>
                <div class="stat-card-icon"><i class="bi bi-check2-square"></i></div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6 col-12">
            <div class="stat-card">
                <div class="stat-card-info">
                    <span class="stat-card-title">Ditolak</span>
                    <span class="stat-card-value"><?= $counts['Ditolak'] ?></span>
                </div>
                <div class="stat-card-icon danger"><i class="bi bi-x-circle"></i></div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6 col-12">
            <div class="stat-card">
                <div class="stat-card-info">
                    <span class="stat-card-title">Selesai</span>
                    <span class="stat-card-value"><?= $counts['Selesai'] ?></span>
                </div>
                <div class="stat-card-icon success"><i class="bi bi-patch-check-fill"></i></div>
            </div>
        </div>
    </div>

    <div class="card-box">
        <div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-3">
            <h5 class="mb-0 fw-bold">Daftar Pengajuan Pemeriksaan</h5>
            <form method="GET" class="d-flex gap-2">
                <select class="select-custom" name="status" style="width: 220px;" onchange="this.form.submit()">
                    <?php foreach (['Menunggu Verifikasi', 'Diverifikasi', 'Dijadwalkan', 'Ditolak', 'Selesai', 'Semua'] as $opt): ?>
                        <option value="<?= htmlspecialchars($opt) ?>" <?= $status_filter === $opt ? 'selected' : '' ?>>
                            <?= $opt === 'Semua' ? 'Semua Status' : htmlspecialchars($opt) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>

        <div class="table-responsive-custom">
            <table class="table-custom">
                <colgroup>
                    <col class="col-no">
                    <col class="col-tanggal">
                    <col class="col-perusahaan">
                    <col class="col-pengaju">
                    <col class="col-unit">
                    <col class="col-jenis">
                    <col class="col-tgldiinginkan">
                    <col class="col-status">
                    <col class="col-aksi">
                </colgroup>
                <thead>
                    <tr>
                        <th>No</th>
                        <th>Tgl. Ajuan</th>
                        <th>Nama Perusahaan</th>
                        <th>Diajukan Oleh</th>
                        <th>Bidang &amp; Unit yang Diperiksa</th>
                        <th>Jenis Pemeriksaan</th>
                        <th>Tgl. Diinginkan</th>
                        <th>Status</th>
                        <th style="text-align: center;">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($daftar_pengajuan)): ?>
                        <tr>
                            <td colspan="9" class="text-center text-muted py-4">Tidak ada data pengajuan untuk status ini.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($daftar_pengajuan as $i => $p): ?>
                            <?php
                                $unit_list    = ambil_unit_pengajuan($p, $unit_per_pengajuan);
                                $grup_bidang  = kelompokkan_unit_per_bidang($unit_list);
                                $jenis_unique = array_values(array_unique(array_filter(array_column($unit_list, 'jenis'))));
                                if (empty($jenis_unique) && !empty($p['jenis_pemeriksaan'])) {
                                    $jenis_unique = [$p['jenis_pemeriksaan']];
                                }

                                $kelas_jenis = function (?string $jenis): string {
                                    $map = [
                                        'Pemeriksaan Baru'    => 'jenis-baru',
                                        'Pemeriksaan Berkala' => 'jenis-berkala',
                                        'Pemeriksaan Ulang'   => 'jenis-ulang',
                                        'Pemeriksaan Khusus'  => 'jenis-khusus',
                                    ];
                                    return $map[$jenis] ?? 'jenis-lainnya';
                                };
                            ?>
                            <tr>
                                <td class="align-middle"><?= $i + 1 ?></td>
                                <td class="align-middle"><?= htmlspecialchars(date('d M Y', strtotime($p['created_at']))) ?></td>
                                <td class="cell-perusahaan align-middle">
                                    <div class="nama-utama"><?= htmlspecialchars($p['nama_perusahaan'] ?? '-') ?></div>
                                    <?php if (!empty($p['nama_perusahaan_klien']) && $p['nama_perusahaan_klien'] !== $p['nama_perusahaan']): ?>
                                        <div class="sub-info">Data Klien: <?= htmlspecialchars($p['nama_perusahaan_klien']) ?></div>
                                    <?php endif; ?>
                                    <?php if (!empty($p['kode_klien'])): ?>
                                        <div class="sub-info"><?= htmlspecialchars($p['kode_klien']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="align-middle"><?= htmlspecialchars($p['nama_pengaju'] ?? '-') ?></td>
                                <td>
                                    <?php foreach ($grup_bidang as $namaBidang => $daftarUnit): ?>
                                        <div class="bidang-group">
                                            <div class="bidang-group-title"><?= htmlspecialchars($namaBidang) ?></div>
                                            <ul class="bidang-group-list">
                                                <?php foreach ($daftarUnit as $u): ?>
                                                    <li>
                                                        <span class="unit-name" title="<?= htmlspecialchars($u['unit']) ?>"><?= htmlspecialchars($u['unit']) ?></span>
                                                        <?php if (!empty($u['jenis'])): ?>
                                                            <span class="badge-jenis-unit <?= $kelas_jenis($u['jenis']) ?>">
                                                                <?= htmlspecialchars(singkat_jenis_pemeriksaan($u['jenis'])) ?>
                                                            </span>
                                                        <?php endif; ?>
                                                    </li>
                                                <?php endforeach; ?>
                                            </ul>
                                        </div>
                                    <?php endforeach; ?>
                                    <?php if (count($unit_list) > 1): ?>
                                        <div class="unit-count-note"><?= count($unit_list) ?> unit diajukan</div>
                                    <?php endif; ?>
                                </td>
                                <td class="align-middle">
                                    <?php if (empty($jenis_unique)): ?>
                                        <span class="text-muted fs-7">-</span>
                                    <?php else: ?>
                                        <div class="jenis-ringkasan">
                                            <?php foreach ($jenis_unique as $jn): ?>
                                                <span class="badge-jenis-unit <?= $kelas_jenis($jn) ?>">
                                                    <?= htmlspecialchars(singkat_jenis_pemeriksaan($jn)) ?>
                                                </span>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td class="align-middle">
                                    <div class="cell-tgl-diinginkan">
                                        <span class="tgl-text"><?= $p['tanggal_diinginkan'] ? htmlspecialchars(date('d M Y', strtotime($p['tanggal_diinginkan']))) : '-' ?></span>
                                        <button type="button" class="btn-ubah-tanggal" title="Ubah Tanggal Diinginkan"
                                            onclick="openUbahTanggalModal(<?= (int) $p['id'] ?>, '<?= htmlspecialchars($p['tanggal_diinginkan'] ?? '') ?>', '<?= htmlspecialchars(addslashes($p['nama_perusahaan'] ?? '-')) ?>')">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                    </div>
                                </td>
                                <td class="align-middle"><span class="<?= badge_class_status($p['status']) ?>"><?= htmlspecialchars($p['status']) ?></span></td>
                                <td class="align-middle" style="text-align: center;">
                                    <?php if ($p['status'] === 'Menunggu Verifikasi'): ?>
                                        <div class="aksi-wrapper">
                                            <button type="button" class="btn-primary-custom" style="height:32px; padding:0 12px; font-size:0.8rem;"
                                                onclick="openApprovalModal(<?= (int) $p['id'] ?>, 'approve', '<?= htmlspecialchars(addslashes($p['nama_perusahaan'] ?? '-')) ?>')">
                                                <i class="bi bi-check-lg"></i> Setujui
                                            </button>
                                            <button type="button" class="btn-secondary-custom" style="height:32px; padding:0 12px; font-size:0.8rem; color: var(--danger); border-color: var(--danger);"
                                                onclick="openApprovalModal(<?= (int) $p['id'] ?>, 'reject', '<?= htmlspecialchars(addslashes($p['nama_perusahaan'] ?? '-')) ?>')">
                                                <i class="bi bi-x-lg"></i> Tolak
                                            </button>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-muted fs-7">
                                            <?= !empty($p['catatan_admin']) ? htmlspecialchars($p['catatan_admin']) : '-' ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php elseif ($tab_aktif === 'surat'): ?>

    <div class="row g-4 mb-4">
        <div class="col-xl-3 col-md-6 col-12">
            <div class="stat-card">
                <div class="stat-card-info">
                    <span class="stat-card-title">Diajukan</span>
                    <span class="stat-card-value"><?= $counts_surat['Diajukan'] ?></span>
                </div>
                <div class="stat-card-icon warning"><i class="bi bi-hourglass-split"></i></div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6 col-12">
            <div class="stat-card">
                <div class="stat-card-info">
                    <span class="stat-card-title">Disetujui</span>
                    <span class="stat-card-value"><?= $counts_surat['Disetujui'] ?></span>
                </div>
                <div class="stat-card-icon"><i class="bi bi-check2-square"></i></div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6 col-12">
            <div class="stat-card">
                <div class="stat-card-info">
                    <span class="stat-card-title">Ditolak</span>
                    <span class="stat-card-value"><?= $counts_surat['Ditolak'] ?></span>
                </div>
                <div class="stat-card-icon danger"><i class="bi bi-x-circle"></i></div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6 col-12">
            <div class="stat-card">
                <div class="stat-card-info">
                    <span class="stat-card-title">Draft</span>
                    <span class="stat-card-value"><?= $counts_surat['Draft'] ?></span>
                </div>
                <div class="stat-card-icon"><i class="bi bi-file-earmark-text"></i></div>
            </div>
        </div>
    </div>

    <div class="card-box">
        <div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-3">
            <h5 class="mb-0 fw-bold">Daftar Persetujuan Surat</h5>
            <form method="GET" class="d-flex gap-2">
                <input type="hidden" name="tab" value="surat">
                <select class="select-custom" name="status_surat" style="width: 220px;" onchange="this.form.submit()">
                    <?php foreach (['Diajukan', 'Disetujui', 'Ditolak', 'Draft', 'Semua'] as $opt): ?>
                        <option value="<?= htmlspecialchars($opt) ?>" <?= $status_filter_surat === $opt ? 'selected' : '' ?>>
                            <?= $opt === 'Semua' ? 'Semua Status' : htmlspecialchars($opt) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>

        <div class="table-responsive-custom">
            <table class="table-custom">
                <colgroup>
                    <col style="width: 44px;">
                    <col style="width: 110px;">
                    <col style="width: 140px;">
                    <col style="width: 220px;">
                    <col style="width: 150px;">
                    <col style="width: 130px;">
                    <col style="width: 110px;">
                    <col style="width: 210px;">
                </colgroup>
                <thead>
                    <tr>
                        <th>No</th>
                        <th>Tgl. Dibuat</th>
                        <th>Nomor Surat</th>
                        <th>Perihal</th>
                        <th>Jenis Surat</th>
                        <th>Dibuat Oleh</th>
                        <th>Status</th>
                        <th style="text-align: center;">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($daftar_surat)): ?>
                        <tr>
                            <td colspan="8" class="text-center text-muted py-4">Tidak ada data surat untuk status ini.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($daftar_surat as $i => $s): ?>
                            <tr>
                                <td class="align-middle"><?= $i + 1 ?></td>
                                <td class="align-middle"><?= htmlspecialchars(date('d M Y', strtotime($s['tgl_dibuat'] ?? $s['created_at']))) ?></td>
                                <td class="align-middle"><?= htmlspecialchars($s['nomor'] ?? '-') ?></td>
                                <td class="align-middle"><?= htmlspecialchars($s['perihal'] ?? '-') ?></td>
                                <td class="align-middle"><?= htmlspecialchars($s['nama_jenis_surat'] ?? ($s['jenis_surat'] ?? '-')) ?></td>
                                <td class="align-middle"><?= htmlspecialchars($s['nama_pembuat'] ?? '-') ?></td>
                                <td class="align-middle"><span class="<?= badge_class_status_surat($s['status']) ?>"><?= htmlspecialchars($s['status']) ?></span></td>
                                <td class="align-middle" style="text-align: center;">
                                    <?php if ($s['status'] === 'Diajukan'): ?>
                                        <div class="aksi-wrapper">
                                            <button type="button" class="btn-primary-custom" style="height:32px; padding:0 12px; font-size:0.8rem;"
                                                onclick="openApprovalModalSurat(<?= (int) $s['id'] ?>, 'approve', '<?= htmlspecialchars(addslashes($s['perihal'] ?? '-')) ?>')">
                                                <i class="bi bi-check-lg"></i> Setujui
                                            </button>
                                            <button type="button" class="btn-secondary-custom" style="height:32px; padding:0 12px; font-size:0.8rem; color: var(--danger); border-color: var(--danger);"
                                                onclick="openApprovalModalSurat(<?= (int) $s['id'] ?>, 'reject', '<?= htmlspecialchars(addslashes($s['perihal'] ?? '-')) ?>')">
                                                <i class="bi bi-x-lg"></i> Tolak
                                            </button>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-muted fs-7">-</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php endif; ?>
</main>

<div class="modal fade modal-custom" id="modalApproval" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form action="approval.php<?= $status_filter !== 'Menunggu Verifikasi' ? '?status=' . urlencode($status_filter) : '' ?>" method="POST">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalApprovalTitle">Setujui Pengajuan</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-3">Perusahaan: <strong id="modalApprovalNamaKlien">-</strong></p>

                    <input type="hidden" name="pengajuan_id" id="modalApprovalPengajuanId" value="">
                    <input type="hidden" name="decision" id="modalApprovalDecision" value="">

                    <label class="form-label fw-semibold fs-7 mb-2" id="modalApprovalCatatanLabel">Catatan (opsional)</label>
                    <textarea class="textarea-custom" name="catatan" id="modalApprovalCatatan" placeholder="Tulis catatan untuk klien/tim internal..."></textarea>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-secondary-custom" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" name="proses_approval" class="btn-primary-custom" id="modalApprovalSubmit">Setujui</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Ubah Tanggal Diinginkan -->
<div class="modal fade modal-custom" id="modalUbahTanggal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form action="approval.php<?= $status_filter !== 'Menunggu Verifikasi' ? '?status=' . urlencode($status_filter) : '' ?>" method="POST">
                <div class="modal-header">
                    <h5 class="modal-title">Ubah Tanggal Diinginkan</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-3">Perusahaan: <strong id="modalUbahTanggalNamaKlien">-</strong></p>

                    <input type="hidden" name="pengajuan_id" id="modalUbahTanggalPengajuanId" value="">

                    <label class="form-label fw-semibold fs-7 mb-2">Tanggal Diinginkan Baru</label>
                    <div class="date-input-wrapper">
                        <i class="bi bi-calendar-week"></i>
                        <input type="date" class="form-control-custom" name="tanggal_baru" id="modalUbahTanggalInput" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-secondary-custom" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" name="ubah_tanggal" class="btn-primary-custom">Simpan Tanggal</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Approval Surat -->
<div class="modal fade modal-custom" id="modalApprovalSurat" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form action="approval.php?tab=surat<?= $status_filter_surat !== 'Diajukan' ? '&status_surat=' . urlencode($status_filter_surat) : '' ?>" method="POST">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalApprovalSuratTitle">Setujui Surat</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-3">Perihal: <strong id="modalApprovalSuratPerihal">-</strong></p>

                    <input type="hidden" name="surat_id" id="modalApprovalSuratId" value="">
                    <input type="hidden" name="decision" id="modalApprovalSuratDecision" value="">

                    <label class="form-label fw-semibold fs-7 mb-2" id="modalApprovalSuratCatatanLabel">Catatan (opsional)</label>
                    <textarea class="textarea-custom" name="catatan" id="modalApprovalSuratCatatan" placeholder="Tulis catatan untuk pembuat surat..."></textarea>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-secondary-custom" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" name="proses_approval_surat" class="btn-primary-custom" id="modalApprovalSuratSubmit">Setujui</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function openApprovalModal(pengajuanId, decision, namaKlien) {
    document.getElementById('modalApprovalPengajuanId').value = pengajuanId;
    document.getElementById('modalApprovalDecision').value = decision;
    document.getElementById('modalApprovalNamaKlien').textContent = namaKlien;

    const title = document.getElementById('modalApprovalTitle');
    const submitBtn = document.getElementById('modalApprovalSubmit');
    const catatanLabel = document.getElementById('modalApprovalCatatanLabel');
    const catatanInput = document.getElementById('modalApprovalCatatan');
    catatanInput.value = '';

    if (decision === 'approve') {
        title.textContent = 'Setujui Pengajuan';
        submitBtn.textContent = 'Setujui';
        catatanLabel.textContent = 'Catatan (opsional)';
        catatanInput.required = false;
    } else {
        title.textContent = 'Tolak Pengajuan';
        submitBtn.textContent = 'Tolak Pengajuan';
        catatanLabel.textContent = 'Alasan Penolakan (wajib diisi)';
        catatanInput.required = true;
    }

    const modal = new bootstrap.Modal(document.getElementById('modalApproval'));
    modal.show();
}

function openUbahTanggalModal(pengajuanId, tanggalSekarang, namaKlien) {
    document.getElementById('modalUbahTanggalPengajuanId').value = pengajuanId;
    document.getElementById('modalUbahTanggalInput').value = tanggalSekarang;
    document.getElementById('modalUbahTanggalNamaKlien').textContent = namaKlien;

    const modal = new bootstrap.Modal(document.getElementById('modalUbahTanggal'));
    modal.show();
}

function openApprovalModalSurat(suratId, decision, perihal) {
    document.getElementById('modalApprovalSuratId').value = suratId;
    document.getElementById('modalApprovalSuratDecision').value = decision;
    document.getElementById('modalApprovalSuratPerihal').textContent = perihal;

    const title = document.getElementById('modalApprovalSuratTitle');
    const submitBtn = document.getElementById('modalApprovalSuratSubmit');
    const catatanLabel = document.getElementById('modalApprovalSuratCatatanLabel');
    const catatanInput = document.getElementById('modalApprovalSuratCatatan');
    catatanInput.value = '';

    if (decision === 'approve') {
        title.textContent = 'Setujui Surat';
        submitBtn.textContent = 'Setujui';
        catatanLabel.textContent = 'Catatan (opsional)';
        catatanInput.required = false;
    } else {
        title.textContent = 'Tolak Surat';
        submitBtn.textContent = 'Tolak Surat';
        catatanLabel.textContent = 'Alasan Penolakan (wajib diisi)';
        catatanInput.required = true;
    }

    const modal = new bootstrap.Modal(document.getElementById('modalApprovalSurat'));
    modal.show();
}
</script>

<?php
include "../includes/footer.php";
?>