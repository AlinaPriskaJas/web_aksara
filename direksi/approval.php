<?php
// direksi/approval.php
session_start();
require_once "../config/koneksi.php";

// TODO: ganti dengan user_id dari sesi login sebenarnya setelah proses_login.php terhubung penuh.
$direksi_id = $_SESSION['user_id'] ?? 1;

$page_title = "Approval Center";
$flash = null;

// Modul yang statusnya cocok dengan skema Approval (Menunggu/Disetujui/Ditolak)
const JENIS_APPROVAL = ['Cuti', 'Reimburse', 'Kendaraan'];

$tabel_map = [
    'Cuti' => ['table' => 'Cuti', 'status_col' => 'status'],
    'Reimburse' => ['table' => 'Reimburse', 'status_col' => 'status'],
    'Kendaraan' => ['table' => 'Peminjaman_Kendaraan', 'status_col' => 'status_peminjaman'],
];

// ================== TAB AKTIF ==================
$tab_aktif = $_GET['tab'] ?? 'umum';
if (!in_array($tab_aktif, ['umum', 'surat'], true)) {
    $tab_aktif = 'umum';
}

// ================== PROSES SETUJUI / TOLAK (Cuti/Reimburse/Kendaraan) ==================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['proses_approval'])) {
    $approval_id = (int) ($_POST['approval_id'] ?? 0);
    $decision = $_POST['decision'] ?? ''; // 'approve' | 'reject'
    $catatan = trim($_POST['catatan'] ?? '');

    $status_baru = $decision === 'approve' ? 'Disetujui' : ($decision === 'reject' ? 'Ditolak' : '');

    if ($approval_id <= 0 || $status_baru === '') {
        $flash = ['type' => 'danger', 'message' => 'Permintaan tidak valid.'];
    } elseif ($decision === 'reject' && $catatan === '') {
        $flash = ['type' => 'danger', 'message' => 'Catatan wajib diisi saat menolak pengajuan.'];
    } else {
        try {
            $conn->beginTransaction();

            $cek = $conn->prepare("SELECT * FROM Approval WHERE id = :id FOR UPDATE");
            $cek->execute([':id' => $approval_id]);
            $row = $cek->fetch();

            if (!$row) {
                throw new RuntimeException("Data approval tidak ditemukan.");
            }
            if ($row['status'] !== 'Menunggu') {
                throw new RuntimeException("Pengajuan ini sudah diproses sebelumnya (" . $row['status'] . ").");
            }
            if (!isset($tabel_map[$row['jenis_pengajuan']])) {
                throw new RuntimeException("Jenis pengajuan tidak didukung di Approval Center ini.");
            }

            $target = $tabel_map[$row['jenis_pengajuan']];

            $updRef = $conn->prepare("UPDATE {$target['table']} SET {$target['status_col']} = :status WHERE id = :id");
            $updRef->execute([':status' => $status_baru, ':id' => $row['ref_id']]);

            // ===================================================================
            // CASCADE KHUSUS: Cuti Tahunan yang Disetujui -> potong Cuti_Saldo
            // ===================================================================
            if ($row['jenis_pengajuan'] === 'Cuti' && $status_baru === 'Disetujui') {
                $stmtCuti = $conn->prepare("SELECT user_id, jenis_cuti, tgl_mulai, total_durasi FROM Cuti WHERE id = :id");
                $stmtCuti->execute([':id' => $row['ref_id']]);
                $cutiRow = $stmtCuti->fetch();

                if ($cutiRow && $cutiRow['jenis_cuti'] === 'Cuti Tahunan') {
                    $tahunCuti = date('Y', strtotime($cutiRow['tgl_mulai']));

                    $stmtEnsure = $conn->prepare("
                        INSERT INTO Cuti_Saldo (user_id, tahun, jatah_tahunan, terpakai)
                        VALUES (:user_id, :tahun, 12, 0)
                        ON DUPLICATE KEY UPDATE user_id = user_id
                    ");
                    $stmtEnsure->execute([
                        ':user_id' => $cutiRow['user_id'],
                        ':tahun' => $tahunCuti,
                    ]);

                    $updSaldo = $conn->prepare("
                        UPDATE Cuti_Saldo
                        SET terpakai = terpakai + :durasi
                        WHERE user_id = :user_id AND tahun = :tahun
                    ");
                    $updSaldo->execute([
                        ':durasi' => $cutiRow['total_durasi'],
                        ':user_id' => $cutiRow['user_id'],
                        ':tahun' => $tahunCuti,
                    ]);
                }
            }

            $updApproval = $conn->prepare("
                UPDATE Approval
                SET status = :status, approver_id = :approver_id, catatan = :catatan, tgl_aksi = NOW()
                WHERE id = :id
            ");
            $updApproval->execute([
                ':status' => $status_baru,
                ':approver_id' => $direksi_id,
                ':catatan' => $catatan !== '' ? $catatan : null,
                ':id' => $approval_id,
            ]);

            $conn->commit();
            $flash = [
                'type' => 'success',
                'message' => $decision === 'approve' ? 'Pengajuan berhasil disetujui.' : 'Pengajuan berhasil ditolak.',
            ];
        } catch (Exception $e) {
            $conn->rollBack();
            $flash = ['type' => 'danger', 'message' => 'Gagal memproses: ' . $e->getMessage()];
        }
    }

    $_SESSION['approval_flash'] = $flash;
    $redirect_status = $_GET['status'] ?? '';
    header("Location: approval.php?tab=umum" . ($redirect_status !== '' ? '&status=' . urlencode($redirect_status) : ''));
    exit;
}

// ================== PROSES SETUJUI / TOLAK SURAT ==================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['proses_approval_surat'])) {
    $surat_id = (int) ($_POST['surat_id'] ?? 0);
    $decision = $_POST['decision'] ?? ''; // 'approve' | 'reject'
    $catatan = trim($_POST['catatan'] ?? '');

    $status_map_surat = [
        'approve' => 'Disetujui',
        'reject' => 'Ditolak',
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
            // Status pending yang sesungguhnya dipakai admin/surat.php saat surat diajukan
            // adalah 'Menunggu Persetujuan' (bukan 'Diajukan').
            if ($rowSurat['status'] !== 'Menunggu Persetujuan') {
                throw new RuntimeException("Surat ini sudah diproses sebelumnya (" . $rowSurat['status'] . ").");
            }

            $status_surat_baru = $status_map_surat[$decision];

            $updSurat = $conn->prepare("UPDATE surat SET status = :status WHERE id = :id");
            $updSurat->execute([
                ':status' => $status_surat_baru,
                ':id' => $surat_id,
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
                    ':status' => $status_surat_baru,
                    ':approver_id' => $direksi_id,
                    ':catatan' => $catatan !== '' ? $catatan : null,
                    ':id' => $approvalRowSurat['id'],
                ]);
            } else {
                $insertApprovalSurat = $conn->prepare("
                    INSERT INTO Approval (jenis_pengajuan, ref_id, requester_id, approver_id, level, status, catatan, tgl_aksi)
                    VALUES ('Surat', :ref_id, :requester_id, :approver_id, 1, :status, :catatan, NOW())
                ");
                $insertApprovalSurat->execute([
                    ':ref_id' => $surat_id,
                    ':requester_id' => $rowSurat['dibuat_oleh'],
                    ':approver_id' => $direksi_id,
                    ':status' => $status_surat_baru,
                    ':catatan' => $catatan !== '' ? $catatan : null,
                ]);
            }

            $conn->commit();
            $flash = [
                'type' => 'success',
                'message' => $decision === 'approve' ? 'Surat berhasil disetujui.' : 'Surat berhasil ditolak.',
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

function safe_count(PDO $conn, string $sql, array $params = []): int
{
    try {
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    } catch (PDOException $e) {
        return 0;
    }
}

// ================== TAB UMUM: STAT CARDS + DATA ==================
$in_clause = "'" . implode("','", JENIS_APPROVAL) . "'";
$total_menunggu = safe_count($conn, "SELECT COUNT(*) FROM Approval WHERE jenis_pengajuan IN ($in_clause) AND status = 'Menunggu'");
$total_disetujui = safe_count($conn, "SELECT COUNT(*) FROM Approval WHERE jenis_pengajuan IN ($in_clause) AND status = 'Disetujui'");
$total_ditolak = safe_count($conn, "SELECT COUNT(*) FROM Approval WHERE jenis_pengajuan IN ($in_clause) AND status = 'Ditolak'");
$total_semua = $total_menunggu + $total_disetujui + $total_ditolak;

$status_filter = $_GET['status'] ?? 'Menunggu';
$valid_statuses = ['Menunggu', 'Disetujui', 'Ditolak', 'Semua'];
if (!in_array($status_filter, $valid_statuses, true)) {
    $status_filter = 'Menunggu';
}

$daftar_approval = [];
if ($tab_aktif === 'umum') {
    try {
        $sql = "
            SELECT a.*, u.nama_lengkap AS nama_pemohon, u.role AS role_pemohon
            FROM Approval a
            LEFT JOIN Users u ON a.requester_id = u.id
            WHERE a.jenis_pengajuan IN ($in_clause)
        ";
        $params = [];
        if ($status_filter !== 'Semua') {
            $sql .= " AND a.status = :status ";
            $params[':status'] = $status_filter;
        }
        $sql .= " ORDER BY a.created_at DESC";

        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        $daftar_approval = $stmt->fetchAll();
    } catch (PDOException $e) {
        $daftar_approval = [];
    }
}

// Ambil detail ringkas tiap ref (nominal reimburse, tanggal cuti, tujuan kendaraan, dsb.)
// Detail ringkas (TANPA alasan/keterangan — dipisah ke kolom sendiri)
function ambil_detail_ref(PDO $conn, string $jenis, int $ref_id): string
{
    try {
        switch ($jenis) {
            case 'Cuti':
                $s = $conn->prepare("SELECT jenis_cuti, tgl_mulai, tgl_selesai, total_durasi FROM Cuti WHERE id = :id");
                $s->execute([':id' => $ref_id]);
                $d = $s->fetch();
                if (!$d)
                    return '-';
                $label_jenis_cuti = $d['jenis_cuti'] === 'Izin Sakit' ? 'Cuti Sakit' : $d['jenis_cuti'];
                return htmlspecialchars($label_jenis_cuti) . ' &middot; ' .
                    date('d M Y', strtotime($d['tgl_mulai'])) . ' - ' . date('d M Y', strtotime($d['tgl_selesai'])) .
                    ' (' . (int) $d['total_durasi'] . ' hari)';
            case 'Reimburse':
                $s = $conn->prepare("SELECT kategori, nominal, tanggal_pengeluaran FROM Reimburse WHERE id = :id");
                $s->execute([':id' => $ref_id]);
                $d = $s->fetch();
                if (!$d)
                    return '-';
                return htmlspecialchars($d['kategori']) . ' &middot; Rp ' . number_format((float) $d['nominal'], 0, ',', '.') .
                    ' &middot; ' . date('d M Y', strtotime($d['tanggal_pengeluaran']));
            case 'Kendaraan':
                $s = $conn->prepare("
                    SELECT pk.tujuan_lokasi, pk.tgl_mulai, pk.tgl_selesai, k.nama_kendaraan, k.plat_nomor
                    FROM Peminjaman_Kendaraan pk LEFT JOIN Kendaraan k ON pk.kendaraan_id = k.id
                    WHERE pk.id = :id
                ");
                $s->execute([':id' => $ref_id]);
                $d = $s->fetch();
                if (!$d)
                    return '-';
                return htmlspecialchars(($d['nama_kendaraan'] ?? '-') . ' (' . ($d['plat_nomor'] ?? '-') . ')') .
                    ' &middot; ke ' . htmlspecialchars($d['tujuan_lokasi'] ?? '-') .
                    ' &middot; ' . date('d M Y', strtotime($d['tgl_mulai'])) . ' - ' . date('d M Y', strtotime($d['tgl_selesai']));
        }
    } catch (PDOException $e) {
        return '-';
    }
    return '-';
}

// Keterangan/alasan asli dari pemohon — ditampilkan di kolom terpisah
function ambil_keterangan_ref(PDO $conn, string $jenis, int $ref_id): string
{
    try {
        switch ($jenis) {
            case 'Cuti':
                $s = $conn->prepare("SELECT alasan FROM Cuti WHERE id = :id");
                $s->execute([':id' => $ref_id]);
                $v = $s->fetchColumn();
                return $v !== false && $v !== null && $v !== '' ? (string) $v : '-';
            case 'Reimburse':
                $s = $conn->prepare("SELECT keterangan FROM Reimburse WHERE id = :id");
                $s->execute([':id' => $ref_id]);
                $v = $s->fetchColumn();
                return $v !== false && $v !== null && $v !== '' ? (string) $v : '-';
            case 'Kendaraan':
                $s = $conn->prepare("SELECT keperluan_dinas FROM Peminjaman_Kendaraan WHERE id = :id");
                $s->execute([':id' => $ref_id]);
                $v = $s->fetchColumn();
                return $v !== false && $v !== null && $v !== '' ? (string) $v : '-';
        }
    } catch (PDOException $e) {
        return '-';
    }
    return '-';
}

// Lampiran/berkas bukti pengajuan (dipakai kolom "Aksi" -> tombol File).
// Kendaraan tidak punya berkas pendukung, jadi otomatis null.
function ambil_file_ref(PDO $conn, string $jenis, int $ref_id): ?string
{
    try {
        switch ($jenis) {
            case 'Cuti':
                $s = $conn->prepare("SELECT lampiran FROM Cuti WHERE id = :id");
                $s->execute([':id' => $ref_id]);
                $v = $s->fetchColumn();
                return $v !== false && $v !== null && $v !== '' ? (string) $v : null;
            case 'Reimburse':
                $s = $conn->prepare("SELECT lampiran_bukti FROM Reimburse WHERE id = :id");
                $s->execute([':id' => $ref_id]);
                $v = $s->fetchColumn();
                return $v !== false && $v !== null && $v !== '' ? (string) $v : null;
        }
    } catch (PDOException $e) {
        return null;
    }
    return null;
}

// Untuk pengajuan jenis "Cuti", kolom Jenis di tabel Approval Center cuma
// nunjukin "Cuti" secara umum (sesuai ENUM Approval.jenis_pengajuan) —
// nggak kelihatan apakah itu Cuti Tahunan, Cuti Khusus, atau Cuti Sakit
// seperti yang dibedakan di halaman Pengajuan Cuti. Fungsi ini ambil
// jenis_cuti spesifiknya dari tabel Cuti supaya direksi langsung tahu.
function label_jenis_pengajuan(PDO $conn, string $jenis, int $ref_id): string
{
    if ($jenis !== 'Cuti') {
        return $jenis;
    }
    try {
        $s = $conn->prepare("SELECT jenis_cuti FROM Cuti WHERE id = :id");
        $s->execute([':id' => $ref_id]);
        $d = $s->fetch();
        if ($d && !empty($d['jenis_cuti'])) {
            // Normalisasi label lama 'Izin Sakit' (masih dipakai form pengajuan)
            // supaya tampil konsisten dengan ENUM terbaru 'Cuti Sakit'.
            return $d['jenis_cuti'] === 'Izin Sakit' ? 'Cuti Sakit' : $d['jenis_cuti'];
        }
    } catch (PDOException $e) {
    }
    return $jenis; // fallback generik "Cuti" kalau data Cuti-nya nggak ketemu
}

function badge_class_jenis_cuti(string $label): string
{
    switch ($label) {
        case 'Cuti Tahunan':
            return 'badge-info';
        case 'Cuti Khusus':
            return 'badge-secondary';
        case 'Cuti Sakit':
            return 'badge-danger';
        default:
            return 'badge-secondary';
    }
}

function badge_class_status(string $status): string
{
    switch ($status) {
        case 'Menunggu':
            return 'badge-warning';
        case 'Disetujui':
            return 'badge-success';
        case 'Ditolak':
            return 'badge-danger';
        default:
            return 'badge-secondary';
    }
}

// ================== TAB SURAT: STAT CARDS + DATA ==================
$status_filter_surat = $_GET['status_surat'] ?? 'Menunggu Persetujuan';
$valid_statuses_surat = ['Menunggu Persetujuan', 'Disetujui', 'Ditolak', 'Draft', 'Semua'];
if (!in_array($status_filter_surat, $valid_statuses_surat, true)) {
    $status_filter_surat = 'Menunggu Persetujuan';
}

$counts_surat = [
    'Menunggu Persetujuan' => 0,
    'Disetujui' => 0,
    'Ditolak' => 0,
    'Draft' => 0,
];
try {
    $stmtCountSurat = $conn->query("SELECT status, COUNT(*) AS jumlah FROM surat WHERE arah = 'Keluar' GROUP BY status");
    foreach ($stmtCountSurat->fetchAll() as $c) {
        if (isset($counts_surat[$c['status']])) {
            $counts_surat[$c['status']] = (int) $c['jumlah'];
        }
    }
} catch (PDOException $e) {
}

$daftar_surat = [];
if ($tab_aktif === 'surat') {
    try {
        $sqlSurat = "
            SELECT s.*, u.nama_lengkap AS nama_pembuat, u.role AS role_pembuat, ks.nama AS nama_jenis_surat
            FROM surat s
            LEFT JOIN Users u ON s.dibuat_oleh = u.id
            LEFT JOIN kode_surat ks ON s.kode_id = ks.id
            WHERE s.arah = 'Keluar'
        ";
        $paramsSurat = [];
        if ($status_filter_surat !== 'Semua') {
            $sqlSurat .= " AND s.status = :status ";
            $paramsSurat[':status'] = $status_filter_surat;
        }
        $sqlSurat .= " ORDER BY s.created_at DESC";

        $stmtSurat = $conn->prepare($sqlSurat);
        $stmtSurat->execute($paramsSurat);
        $daftar_surat = $stmtSurat->fetchAll();
    } catch (PDOException $e) {
        $daftar_surat = [];
    }
}

function badge_class_status_surat(string $status): string
{
    switch ($status) {
        case 'Menunggu Persetujuan':
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

<main class="main-content">

    <?php if ($flash): ?>
        <div class="alert alert-<?= $flash['type'] === 'success' ? 'success-custom' : 'danger-custom' ?> mb-4">
            <i
                class="bi <?= $flash['type'] === 'success' ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill' ?> fs-5"></i>
            <div><?= htmlspecialchars($flash['message']) ?></div>
        </div>
    <?php endif; ?>

    <!-- Tab Navigasi -->
    <div class="arp-tab-group">
        <div class="arp-tab-nav">
            <a href="approval.php?tab=umum" class="arp-tab-btn<?= $tab_aktif === 'umum' ? ' active' : '' ?>">
                <i class="bi bi-collection me-1"></i> Pengajuan Umum
            </a>
            <a href="approval.php?tab=surat" class="arp-tab-btn<?= $tab_aktif === 'surat' ? ' active' : '' ?>">
                <i class="bi bi-envelope me-1"></i> Approval Surat
                <?php if ($counts_surat['Menunggu Persetujuan'] > 0): ?>
                    <span class="badge-warning ms-1"><?= $counts_surat['Menunggu Persetujuan'] ?></span>
                <?php endif; ?>
            </a>
        </div>
    </div>

    <?php if ($tab_aktif === 'umum'): ?>
        <div class="col-12 arp-tab-panel" id="tabPanelUmum">
            <div class="row g-4 mb-4">
                <div class="col-xl-3 col-md-6 col-12">
                    <div class="stat-card">
                        <div class="stat-card-info">
                            <span class="stat-card-title">Menunggu Approval</span>
                            <span class="stat-card-value"><?= $total_menunggu ?></span>
                        </div>
                        <div class="stat-card-icon warning"><i class="bi bi-hourglass-split"></i></div>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6 col-12">
                    <div class="stat-card">
                        <div class="stat-card-info">
                            <span class="stat-card-title">Disetujui</span>
                            <span class="stat-card-value"><?= $total_disetujui ?></span>
                        </div>
                        <div class="stat-card-icon success"><i class="bi bi-check2-square"></i></div>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6 col-12">
                    <div class="stat-card">
                        <div class="stat-card-info">
                            <span class="stat-card-title">Ditolak</span>
                            <span class="stat-card-value"><?= $total_ditolak ?></span>
                        </div>
                        <div class="stat-card-icon danger"><i class="bi bi-x-circle"></i></div>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6 col-12">
                    <div class="stat-card">
                        <div class="stat-card-info">
                            <span class="stat-card-title">Total Pengajuan</span>
                            <span class="stat-card-value"><?= $total_semua ?></span>
                        </div>
                        <div class="stat-card-icon"><i class="bi bi-collection"></i></div>
                    </div>
                </div>
            </div>

            <div class="card-box">
                <div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-3">
                    <div>
                        <h5 class="mb-1 fw-bold">Approval Center</h5>
                        <p class="text-secondary fs-7 mb-0">Persetujuan akhir untuk Cuti, Reimburse, dan Peminjaman
                            Kendaraan.
                        </p>
                    </div>
                    <form method="GET" class="d-flex gap-2">
                        <input type="hidden" name="tab" value="umum">
                        <select class="select-custom" name="status" style="width: 200px;" onchange="this.form.submit()">
                            <?php foreach ($valid_statuses as $opt): ?>
                                <option value="<?= htmlspecialchars($opt) ?>" <?= $status_filter === $opt ? 'selected' : '' ?>>
                                    <?= $opt === 'Semua' ? 'Semua Status' : htmlspecialchars($opt) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                </div>

                <div class="table-responsive-custom">
                    <table class="table-custom">
                        <thead>
                            <tr>
                                <th>No</th>
                                <th>Tanggal</th>
                                <th>Jenis</th>
                                <th>Pemohon</th>
                                <th>Detail</th>
                                <th>Keterangan</th>
                                <th>Status</th>
                                <th style="text-align:center;">Tindakan</th>
                                <th style="text-align:center;">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($daftar_approval)): ?>
                                <tr>
                                    <td colspan="9" class="text-center text-muted py-4">Tidak ada data untuk status ini.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($daftar_approval as $i => $a): ?>
                                    <tr>
                                        <td><?= $i + 1 ?></td>
                                        <td><?= htmlspecialchars(date('d M Y', strtotime($a['created_at']))) ?></td>
                                        <td>
                                            <?php if ($a['jenis_pengajuan'] === 'Cuti'):
                                                $label_cuti = label_jenis_pengajuan($conn, $a['jenis_pengajuan'], (int) $a['ref_id']);
                                            ?>
                                                <span class="<?= badge_class_jenis_cuti($label_cuti) ?> fs-7"><?= htmlspecialchars($label_cuti) ?></span>
                                            <?php else: ?>
                                                <?= htmlspecialchars($a['jenis_pengajuan']) ?>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <strong><?= htmlspecialchars($a['nama_pemohon'] ?? '-') ?></strong>
                                            <br><small class="text-secondary"><?= htmlspecialchars(ucfirst($a['role_pemohon'] ?? '-')) ?></small>
                                            </div>
                                        </td>
                                        <td class="fs-7"><?= ambil_detail_ref($conn, $a['jenis_pengajuan'], (int) $a['ref_id']) ?>
                                        </td>
                                        <td class="fs-7 col-keterangan"><?= htmlspecialchars(ambil_keterangan_ref($conn, $a['jenis_pengajuan'], (int) $a['ref_id'])) ?>
                                        </td>
                                        <td><span
                                                class="<?= badge_class_status($a['status']) ?>"><?= htmlspecialchars($a['status']) ?></span>
                                        </td>
                                        <td style="text-align:center;">
                                            <?php if ($a['status'] === 'Menunggu'): ?>
                                                <div class="d-flex gap-2 justify-content-center">
                                                    <button type="button" class="btn-primary-custom"
                                                        style="height:32px; padding:0 12px; font-size:0.8rem;"
                                                        onclick="openApprovalModal(<?= (int) $a['id'] ?>, 'approve')">
                                                        <i class="bi bi-check-lg"></i> Setujui
                                                    </button>
                                                    <button type="button" class="btn-secondary-custom"
                                                        style="height:32px; padding:0 12px; font-size:0.8rem; color: var(--danger); border-color: var(--danger);"
                                                        onclick="openApprovalModal(<?= (int) $a['id'] ?>, 'reject')">
                                                        <i class="bi bi-x-lg"></i> Tolak
                                                    </button>
                                                </div>
                                            <?php else: ?>
                                                <span
                                                    class="text-muted fs-7"><?= !empty($a['catatan']) ? htmlspecialchars($a['catatan']) : '-' ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="text-align:center;">
                                            <?php $fileRefUmum = ambil_file_ref($conn, $a['jenis_pengajuan'], (int) $a['ref_id']); ?>
                                            <?php if ($fileRefUmum):
                                                $hrefFileUmum = str_starts_with($fileRefUmum, 'http') ? $fileRefUmum : '../' . $fileRefUmum;
                                            ?>
                                                <button type="button" class="btn-icon-bukti"
                                                    onclick="openFileModal('<?= htmlspecialchars($hrefFileUmum, ENT_QUOTES) ?>', '<?= htmlspecialchars(addslashes($a['jenis_pengajuan']), ENT_QUOTES) ?>')"
                                                    title="Lihat File">
                                                    <i class="bi bi-paperclip"></i>
                                                </button>
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
        </div>

    <?php elseif ($tab_aktif === 'surat'): ?>

        <div class="row g-4 mb-4">
            <div class="col-xl-3 col-md-6 col-12">
                <div class="stat-card">
                    <div class="stat-card-info">
                        <span class="stat-card-title">Menunggu Persetujuan</span>
                        <span class="stat-card-value"><?= $counts_surat['Menunggu Persetujuan'] ?></span>
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
                    <div class="stat-card-icon success"><i class="bi bi-check2-square"></i></div>
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
                <div>
                    <h5 class="mb-1 fw-bold">Approval Surat Keluar</h5>
                    <p class="text-secondary fs-7 mb-0">Persetujuan akhir untuk surat keluar sebelum dikirim.</p>
                </div>
                <form method="GET" class="d-flex gap-2">
                    <input type="hidden" name="tab" value="surat">
                    <select class="select-custom" name="status_surat" style="width: 220px;" onchange="this.form.submit()">
                        <?php foreach ($valid_statuses_surat as $opt): ?>
                            <option value="<?= htmlspecialchars($opt) ?>" <?= $status_filter_surat === $opt ? 'selected' : '' ?>>
                                <?= $opt === 'Semua' ? 'Semua Status' : htmlspecialchars($opt) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>
            </div>

            <div class="table-responsive-custom">
                <table class="table-custom">
                    <thead>
                        <tr>
                            <th>No</th>
                            <th>Tgl. Dibuat</th>
                            <th>Nomor Surat</th>
                            <th>Perihal</th>
                            <th>Jenis Surat</th>
                            <th>Dibuat Oleh</th>
                            <th>Status</th>
                            <th style="text-align: center;">Tindakan</th>
                            <th style="text-align: center;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($daftar_surat)): ?>
                            <tr>
                                <td colspan="9" class="text-center text-muted py-4">Tidak ada data surat untuk status ini.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($daftar_surat as $i => $s): ?>
                                <tr>
                                    <td class="align-middle"><?= $i + 1 ?></td>
                                    <td class="align-middle">
                                        <?= htmlspecialchars(date('d M Y', strtotime($s['tgl_dibuat'] ?? $s['created_at']))) ?>
                                    </td>
                                    <td class="align-middle"><?= htmlspecialchars($s['nomor'] ?? '-') ?></td>
                                    <td class="align-middle"><?= htmlspecialchars($s['perihal'] ?? '-') ?></td>
                                    <td class="align-middle">
                                        <?= htmlspecialchars($s['nama_jenis_surat'] ?? ($s['jenis_surat'] ?? '-')) ?>
                                    </td>
                                    <td class="align-middle">
                                        <strong><?= htmlspecialchars($s['nama_pembuat'] ?? '-') ?></strong>
                                        <br><small
                                            class="text-secondary"><?= htmlspecialchars(ucfirst($s['role_pembuat'] ?? '-')) ?></small>
                                    </td>
                                    <td class="align-middle"><span
                                            class="<?= badge_class_status_surat($s['status']) ?>"><?= htmlspecialchars($s['status']) ?></span>
                                    </td>
                                    <td class="align-middle" style="text-align: center;">
                                        <?php if ($s['status'] === 'Menunggu Persetujuan'): ?>
                                            <div class="d-flex gap-2 justify-content-center">
                                                <button type="button" class="btn-primary-custom"
                                                    style="height:32px; padding:0 12px; font-size:0.8rem;"
                                                    onclick="openApprovalModalSurat(<?= (int) $s['id'] ?>, 'approve', '<?= htmlspecialchars(addslashes($s['perihal'] ?? '-')) ?>')">
                                                    <i class="bi bi-check-lg"></i> Setujui
                                                </button>
                                                <button type="button" class="btn-secondary-custom"
                                                    style="height:32px; padding:0 12px; font-size:0.8rem; color: var(--danger); border-color: var(--danger);"
                                                    onclick="openApprovalModalSurat(<?= (int) $s['id'] ?>, 'reject', '<?= htmlspecialchars(addslashes($s['perihal'] ?? '-')) ?>')">
                                                    <i class="bi bi-x-lg"></i> Tolak
                                                </button>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-muted fs-7">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="align-middle" style="text-align: center;">
                                        <?php if (!empty($s['file_hasil'])):
                                            $hrefFileSurat = str_starts_with($s['file_hasil'], 'http') ? $s['file_hasil'] : '../' . $s['file_hasil'];
                                        ?>
                                            <button type="button" class="btn-icon-bukti"
                                                onclick="openFileModal('<?= htmlspecialchars($hrefFileSurat, ENT_QUOTES) ?>', '<?= htmlspecialchars(addslashes($s['nomor'] ?? $s['perihal'] ?? 'Surat'), ENT_QUOTES) ?>')"
                                                title="Lihat File">
                                                <i class="bi bi-paperclip"></i>
                                            </button>
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

<!-- Modal Approval Umum -->
<div class="modal fade modal-custom" id="modalApproval" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form
                action="approval.php?tab=umum<?= $status_filter !== 'Menunggu' ? '&status=' . urlencode($status_filter) : '' ?>"
                method="POST">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalApprovalTitle">Setujui Pengajuan</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="approval_id" id="modalApprovalId" value="">
                    <input type="hidden" name="decision" id="modalApprovalDecision" value="">

                    <label class="form-label fw-semibold fs-7 mb-2" id="modalApprovalCatatanLabel">Catatan
                        (opsional)</label>
                    <textarea class="textarea-custom" name="catatan" id="modalApprovalCatatan"
                        placeholder="Tulis catatan..."></textarea>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-secondary-custom" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" name="proses_approval" class="btn-primary-custom"
                        id="modalApprovalSubmit">Setujui</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Approval Surat -->
<div class="modal fade modal-custom" id="modalApprovalSurat" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form
                action="approval.php?tab=surat<?= $status_filter_surat !== 'Menunggu Persetujuan' ? '&status_surat=' . urlencode($status_filter_surat) : '' ?>"
                method="POST">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalApprovalSuratTitle">Setujui Surat</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-3">Perihal: <strong id="modalApprovalSuratPerihal">-</strong></p>

                    <input type="hidden" name="surat_id" id="modalApprovalSuratId" value="">
                    <input type="hidden" name="decision" id="modalApprovalSuratDecision" value="">

                    <label class="form-label fw-semibold fs-7 mb-2" id="modalApprovalSuratCatatanLabel">Catatan
                        (opsional)</label>
                    <textarea class="textarea-custom" name="catatan" id="modalApprovalSuratCatatan"
                        placeholder="Tulis catatan untuk pembuat surat..."></textarea>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-secondary-custom" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" name="proses_approval_surat" class="btn-primary-custom"
                        id="modalApprovalSuratSubmit">Setujui</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Lihat File -->
<div class="modal fade modal-custom" id="modalLihatFile" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modalLihatFileTitle">Lampiran</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="modalLihatFileBody" style="min-height: 200px;">
                <!-- diisi via JS -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-primary-custom" id="modalLihatFilePrint" style="display:none;">
                    <i class="bi bi-printer"></i> Cetak
                </button>
                <a href="#" id="modalLihatFileDownload" target="_blank" class="btn-secondary-custom">
                    <i class="bi bi-box-arrow-up-right"></i> Buka di Tab Baru
                </a>
                <button type="button" class="btn-secondary-custom" data-bs-dismiss="modal">Tutup</button>
            </div>
        </div>
    </div>
</div>

<script>
    function openFileModal(fileUrl, label) {
        const body = document.getElementById('modalLihatFileBody');
        const title = document.getElementById('modalLihatFileTitle');
        const downloadBtn = document.getElementById('modalLihatFileDownload');
        const printBtn = document.getElementById('modalLihatFilePrint');

        title.textContent = 'Lampiran' + (label ? ' - ' + label : '');
        downloadBtn.href = fileUrl;
        printBtn.onclick = null;
        printBtn.style.display = 'none';

        const ext = fileUrl.split('?')[0].split('.').pop().toLowerCase();
        const gambarExt = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];

        if (gambarExt.includes(ext)) {
            body.innerHTML = `<img id="modalLihatFileImg" src="${fileUrl}" alt="Lampiran" style="max-width:100%; height:auto; display:block; margin:0 auto;">`;
            printBtn.style.display = 'inline-flex';
            printBtn.onclick = function () { cetakGambarLampiran(fileUrl); };
        } else if (ext === 'pdf') {
            body.innerHTML = `<iframe id="modalLihatFileFrame" src="${fileUrl}" style="width:100%; height:70vh; border:0;"></iframe>`;
            printBtn.style.display = 'inline-flex';
            printBtn.onclick = function () { cetakPdfLampiran(); };
        } else {
            body.innerHTML = `
                <div class="text-center py-4">
                    <i class="bi bi-file-earmark-text" style="font-size:2.5rem;"></i>
                    <p class="text-secondary mt-2 mb-0">Pratinjau tidak tersedia untuk tipe berkas ini (mis. Word).<br>Silakan gunakan tombol "Buka di Tab Baru", lalu cetak dari aplikasi/pembacanya (mis. Word, Google Docs).</p>
                </div>`;
        }

        const modal = new bootstrap.Modal(document.getElementById('modalLihatFile'));
        modal.show();
    }

    function cetakPdfLampiran() {
        const frame = document.getElementById('modalLihatFileFrame');
        if (frame && frame.contentWindow) {
            try {
                frame.contentWindow.focus();
                frame.contentWindow.print();
            } catch (e) {
                // Fallback kalau viewer PDF bawaan browser memblokir akses contentWindow
                window.open(frame.src, '_blank');
            }
        }
    }

    function cetakGambarLampiran(fileUrl) {
        const jendelaCetak = window.open('', '_blank', 'width=800,height=600');
        if (!jendelaCetak) return;
        jendelaCetak.document.write(`
            <html>
            <head><title>Cetak Lampiran</title></head>
            <body style="margin:0; display:flex; justify-content:center; align-items:center;">
                <img src="${fileUrl}" style="max-width:100%;" onload="window.print();">
            </body>
            </html>
        `);
        jendelaCetak.document.close();
    }
</script>

<script>
    function openApprovalModal(approvalId, decision) {
        document.getElementById('modalApprovalId').value = approvalId;
        document.getElementById('modalApprovalDecision').value = decision;

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