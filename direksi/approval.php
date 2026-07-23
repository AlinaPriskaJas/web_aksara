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
    'Cuti'      => ['table' => 'Cuti',                'status_col' => 'status'],
    'Reimburse' => ['table' => 'Reimburse',            'status_col' => 'status'],
    'Kendaraan' => ['table' => 'Peminjaman_Kendaraan', 'status_col' => 'status_peminjaman'],
];

// ================== PROSES SETUJUI / TOLAK ==================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['proses_approval'])) {
    $approval_id = (int) ($_POST['approval_id'] ?? 0);
    $decision    = $_POST['decision'] ?? ''; // 'approve' | 'reject'
    $catatan     = trim($_POST['catatan'] ?? '');

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
            // -------------------------------------------------------------------
            // Ini bagian yang sebelumnya HILANG. UPDATE Cuti di atas hanya
            // mengubah Cuti.status, tapi tidak pernah menyentuh Cuti_Saldo,
            // sehingga kartu "Cuti Terpakai" / "Sisa Saldo" di cuti.php tidak
            // pernah berubah walau pengajuan sudah Disetujui.
            // ===================================================================
            if ($row['jenis_pengajuan'] === 'Cuti' && $status_baru === 'Disetujui') {
                $stmtCuti = $conn->prepare("SELECT user_id, jenis_cuti, tgl_mulai, total_durasi FROM Cuti WHERE id = :id");
                $stmtCuti->execute([':id' => $row['ref_id']]);
                $cutiRow = $stmtCuti->fetch();

                // Hanya "Cuti Tahunan" yang memotong jatah tahunan. Jenis lain
                // (Cuti Sakit, Cuti Alasan Penting, Izin Melahirkan/Pendampingan)
                // tidak mengurangi saldo — sama seperti validasi saat pengajuan
                // di cuti.php (lihat pengecekan $jenis_cuti === 'Cuti Tahunan').
                if ($cutiRow && $cutiRow['jenis_cuti'] === 'Cuti Tahunan') {
                    $tahunCuti = date('Y', strtotime($cutiRow['tgl_mulai']));

                    // Pastikan baris saldo tahun tsb sudah ada sebelum ditambah
                    $stmtEnsure = $conn->prepare("
                        INSERT INTO Cuti_Saldo (user_id, tahun, jatah_tahunan, terpakai)
                        VALUES (:user_id, :tahun, 12, 0)
                        ON DUPLICATE KEY UPDATE user_id = user_id
                    ");
                    $stmtEnsure->execute([
                        ':user_id' => $cutiRow['user_id'],
                        ':tahun'   => $tahunCuti,
                    ]);

                    $updSaldo = $conn->prepare("
                        UPDATE Cuti_Saldo
                        SET terpakai = terpakai + :durasi
                        WHERE user_id = :user_id AND tahun = :tahun
                    ");
                    $updSaldo->execute([
                        ':durasi'  => $cutiRow['total_durasi'],
                        ':user_id' => $cutiRow['user_id'],
                        ':tahun'   => $tahunCuti,
                    ]);
                }
            }

            $updApproval = $conn->prepare("
                UPDATE Approval
                SET status = :status, approver_id = :approver_id, catatan = :catatan, tgl_aksi = NOW()
                WHERE id = :id
            ");
            $updApproval->execute([
                ':status'      => $status_baru,
                ':approver_id' => $direksi_id,
                ':catatan'     => $catatan !== '' ? $catatan : null,
                ':id'          => $approval_id,
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
    header("Location: approval.php" . ($redirect_status !== '' ? '?status=' . urlencode($redirect_status) : ''));
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

// ================== STAT CARDS ==================
$in_clause = "'" . implode("','", JENIS_APPROVAL) . "'";
$total_menunggu = safe_count($conn, "SELECT COUNT(*) FROM Approval WHERE jenis_pengajuan IN ($in_clause) AND status = 'Menunggu'");
$total_disetujui = safe_count($conn, "SELECT COUNT(*) FROM Approval WHERE jenis_pengajuan IN ($in_clause) AND status = 'Disetujui'");
$total_ditolak = safe_count($conn, "SELECT COUNT(*) FROM Approval WHERE jenis_pengajuan IN ($in_clause) AND status = 'Ditolak'");
$total_semua = $total_menunggu + $total_disetujui + $total_ditolak;

// ================== FILTER STATUS ==================
$status_filter = $_GET['status'] ?? 'Menunggu';
$valid_statuses = ['Menunggu', 'Disetujui', 'Ditolak', 'Semua'];
if (!in_array($status_filter, $valid_statuses, true)) {
    $status_filter = 'Menunggu';
}

$daftar_approval = [];
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

// Ambil detail ringkas tiap ref (nominal reimburse, tanggal cuti, tujuan kendaraan, dsb.)
function ambil_detail_ref(PDO $conn, string $jenis, int $ref_id): string
{
    try {
        switch ($jenis) {
            case 'Cuti':
                $s = $conn->prepare("SELECT jenis_cuti, tgl_mulai, tgl_selesai, total_durasi FROM Cuti WHERE id = :id");
                $s->execute([':id' => $ref_id]);
                $d = $s->fetch();
                if (!$d) return '-';
                return htmlspecialchars($d['jenis_cuti']) . ' &middot; ' .
                    date('d M Y', strtotime($d['tgl_mulai'])) . ' - ' . date('d M Y', strtotime($d['tgl_selesai'])) .
                    ' (' . (int) $d['total_durasi'] . ' hari)';
            case 'Reimburse':
                $s = $conn->prepare("SELECT kategori, nominal, tanggal_pengeluaran FROM Reimburse WHERE id = :id");
                $s->execute([':id' => $ref_id]);
                $d = $s->fetch();
                if (!$d) return '-';
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
                if (!$d) return '-';
                return htmlspecialchars(($d['nama_kendaraan'] ?? '-') . ' (' . ($d['plat_nomor'] ?? '-') . ')') .
                    ' &middot; ke ' . htmlspecialchars($d['tujuan_lokasi'] ?? '-') .
                    ' &middot; ' . date('d M Y', strtotime($d['tgl_mulai'])) . ' - ' . date('d M Y', strtotime($d['tgl_selesai']));
        }
    } catch (PDOException $e) {
        return '-';
    }
    return '-';
}

function badge_class_status(string $status): string
{
    switch ($status) {
        case 'Menunggu':  return 'badge-warning';
        case 'Disetujui': return 'badge-success';
        case 'Ditolak':   return 'badge-danger';
        default:          return 'badge-secondary';
    }
}

include "../includes/header.php";
include "../includes/sidebar.php";
include "../includes/topbar.php";
?>

<main class="main-content">

    <?php if ($flash): ?>
        <div class="alert alert-<?= $flash['type'] === 'success' ? 'success-custom' : 'danger-custom' ?> mb-4">
            <i class="bi <?= $flash['type'] === 'success' ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill' ?> fs-5"></i>
            <div><?= htmlspecialchars($flash['message']) ?></div>
        </div>
    <?php endif; ?>

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
                <p class="text-secondary fs-7 mb-0">Persetujuan akhir untuk Cuti, Reimburse, dan Peminjaman Kendaraan.</p>
            </div>
            <form method="GET" class="d-flex gap-2">
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
                        <th>Status</th>
                        <th style="text-align:center;">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($daftar_approval)): ?>
                        <tr><td colspan="7" class="text-center text-muted py-4">Tidak ada data untuk status ini.</td></tr>
                    <?php else: ?>
                        <?php foreach ($daftar_approval as $i => $a): ?>
                            <tr>
                                <td><?= $i + 1 ?></td>
                                <td><?= htmlspecialchars(date('d M Y', strtotime($a['created_at']))) ?></td>
                                <td><?= htmlspecialchars($a['jenis_pengajuan']) ?></td>
                                <td>
                                    <?= htmlspecialchars($a['nama_pemohon'] ?? '-') ?>
                                    <div class="fs-7 text-muted"><?= htmlspecialchars(ucfirst($a['role_pemohon'] ?? '-')) ?></div>
                                </td>
                                <td class="fs-7"><?= ambil_detail_ref($conn, $a['jenis_pengajuan'], (int) $a['ref_id']) ?></td>
                                <td><span class="<?= badge_class_status($a['status']) ?>"><?= htmlspecialchars($a['status']) ?></span></td>
                                <td style="text-align:center;">
                                    <?php if ($a['status'] === 'Menunggu'): ?>
                                        <div class="d-flex gap-2 justify-content-center">
                                            <button type="button" class="btn-primary-custom" style="height:32px; padding:0 12px; font-size:0.8rem;"
                                                onclick="openApprovalModal(<?= (int) $a['id'] ?>, 'approve')">
                                                <i class="bi bi-check-lg"></i> Setujui
                                            </button>
                                            <button type="button" class="btn-secondary-custom" style="height:32px; padding:0 12px; font-size:0.8rem; color: var(--danger); border-color: var(--danger);"
                                                onclick="openApprovalModal(<?= (int) $a['id'] ?>, 'reject')">
                                                <i class="bi bi-x-lg"></i> Tolak
                                            </button>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-muted fs-7"><?= !empty($a['catatan']) ? htmlspecialchars($a['catatan']) : '-' ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>

<div class="modal fade modal-custom" id="modalApproval" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form action="approval.php<?= $status_filter !== 'Menunggu' ? '?status=' . urlencode($status_filter) : '' ?>" method="POST">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalApprovalTitle">Setujui Pengajuan</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="approval_id" id="modalApprovalId" value="">
                    <input type="hidden" name="decision" id="modalApprovalDecision" value="">

                    <label class="form-label fw-semibold fs-7 mb-2" id="modalApprovalCatatanLabel">Catatan (opsional)</label>
                    <textarea class="textarea-custom" name="catatan" id="modalApprovalCatatan" placeholder="Tulis catatan..."></textarea>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-secondary-custom" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" name="proses_approval" class="btn-primary-custom" id="modalApprovalSubmit">Setujui</button>
                </div>
            </form>
        </div>
    </div>
</div>

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
</script>

<?php
include "../includes/footer.php";
?>