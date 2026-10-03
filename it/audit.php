<?php
// app_aksara/it/audit.php
require_once "../config/koneksi.php";

if (session_status() === PHP_SESSION_NONE)
    session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'it') {
    header("Location: ../login.php");
    exit;
}

$page_title = "Audit Log";
include "../includes/header.php";
include "../includes/sidebar.php";
include "../includes/topbar.php";

// ================== DAFTAR ROLE (untuk dropdown filter) ==================
// Diambil langsung dari definisi ENUM kolom Users.role supaya selalu sinkron
// dengan struktur tabel, tidak perlu di-hardcode terpisah.
$daftar_role = [];
try {
    $colInfo = $conn->query("SHOW COLUMNS FROM Users LIKE 'role'")->fetch();
    if ($colInfo && preg_match("/^enum\((.*)\)$/i", $colInfo['Type'], $m)) {
        preg_match_all("/'([^']+)'/", $m[1], $mm);
        $daftar_role = $mm[1];
    }
} catch (PDOException $e) {
    $daftar_role = ['direksi', 'admin', 'it', 'client', 'ahli_k3'];
}

// ================== FILTER ==================
$filter_modul = isset($_GET['modul']) ? trim($_GET['modul']) : '';
$filter_role = isset($_GET['role']) ? trim($_GET['role']) : '';
$filter_dari = isset($_GET['dari']) ? trim($_GET['dari']) : '';
$filter_sampai = isset($_GET['sampai']) ? trim($_GET['sampai']) : '';

// Validasi format tanggal (YYYY-MM-DD) supaya tidak dipakai mentah-mentah di query
$tglValid = fn($t) => (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $t);
if ($filter_dari !== '' && !$tglValid($filter_dari))
    $filter_dari = '';
if ($filter_sampai !== '' && !$tglValid($filter_sampai))
    $filter_sampai = '';
if ($filter_role !== '' && !in_array($filter_role, $daftar_role, true))
    $filter_role = '';

// ================== QUERY LOG (dengan filter) ==================
// LEFT JOIN dipakai (bukan JOIN) supaya log tetap tampil walau user
// pembuatnya sudah dihapus dari tabel Users.
$sql = "
    SELECT al.*, u.nama_lengkap, u.role AS user_role
    FROM Audit_Log al
    LEFT JOIN Users u ON al.user_id = u.id
    WHERE 1=1
";
$params = [];

if ($filter_modul !== '') {
    $sql .= " AND al.modul = :modul";
    $params['modul'] = $filter_modul;
}
if ($filter_role !== '') {
    $sql .= " AND u.role = :role";
    $params['role'] = $filter_role;
}
if ($filter_dari !== '') {
    $sql .= " AND al.waktu_kejadian >= :dari";
    $params['dari'] = $filter_dari . ' 00:00:00';
}
if ($filter_sampai !== '') {
    $sql .= " AND al.waktu_kejadian <= :sampai";
    $params['sampai'] = $filter_sampai . ' 23:59:59';
}

$sql .= " ORDER BY al.waktu_kejadian DESC LIMIT 1000";

try {
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $logs = $stmt->fetchAll();
} catch (PDOException $e) {
    $logs = [];
}

// ================== STATISTIK (tidak terpengaruh filter) ==================
$totalLog = $conn->query("SELECT COUNT(*) FROM Audit_Log")->fetchColumn() ?: 0;
$logHariIni = $conn->query("SELECT COUNT(*) FROM Audit_Log WHERE DATE(waktu_kejadian) = CURDATE()")->fetchColumn() ?: 0;
$moduls = $conn->query("SELECT DISTINCT modul FROM Audit_Log WHERE modul IS NOT NULL AND modul <> '' ORDER BY modul ASC")->fetchAll(PDO::FETCH_COLUMN);

// Jumlah log yang sedang tampil setelah filter (buat info kecil di toolbar)
$totalTampil = count($logs);

// Data lengkap tiap log (termasuk data_sebelum/data_sesudah) diserialisasi
// ke JSON sekali saja untuk dipakai modal "Detail" di sisi client, supaya
// tidak perlu request tambahan per baris.
$logsForJs = [];
foreach ($logs as $l) {
    $logsForJs[$l['id']] = [
        'waktu' => date('d-m-Y H:i:s', strtotime($l['waktu_kejadian'])),
        'nama' => $l['nama_lengkap'] ?: 'Pengguna Terhapus',
        'role' => $l['user_role'] ? ucwords(str_replace('_', ' ', $l['user_role'])) : '-',
        'modul' => $l['modul'],
        'aksi' => $l['aksi'],
        'detail' => $l['detail_perubahan'] ?: '-',
        'sebelum' => $l['data_sebelum'],
        'sesudah' => $l['data_sesudah'],
    ];
}
?>

<main class="main-content">
    <!-- Recap Cards -->
    <div class="row g-4 mb-4">
        <div class="col-md-4 col-12">
            <div class="stat-card">
                <div class="stat-card-info">
                    <span class="stat-card-title">Total Aktivitas Tercatat</span>
                    <span class="stat-card-value"><?= number_format($totalLog, 0, ',', '.') ?></span>
                </div>
                <div class="stat-card-icon"><i class="bi bi-journal-text"></i></div>
            </div>
        </div>
        <div class="col-md-4 col-12">
            <div class="stat-card">
                <div class="stat-card-info">
                    <span class="stat-card-title">Aktivitas Hari Ini</span>
                    <span class="stat-card-value"><?= number_format($logHariIni, 0, ',', '.') ?></span>
                </div>
                <div class="stat-card-icon success"><i class="bi bi-activity"></i></div>
            </div>
        </div>
        <div class="col-md-4 col-12">
            <div class="stat-card">
                <div class="stat-card-info">
                    <span class="stat-card-title">Modul Termonitor</span>
                    <span class="stat-card-value"><?= count($moduls) ?></span>
                </div>
                <div class="stat-card-icon warning"><i class="bi bi-diagram-3-fill"></i></div>
            </div>
        </div>
    </div>

    <div class="card-box">
        <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
            <h5 class="fw-bold mb-0">Log Aktivitas Pengguna</h5>
            <div class="search-box-container" style="width:260px; max-width:100%;">
                <i class="bi bi-search"></i>
                <input type="text" class="search-box" style="width:100%;" placeholder="Cari log..."
                    data-table-search="tabelAudit" onkeyup="handleTableSearch('tabelAudit')">
            </div>
        </div>

        <!-- ===== Filter: Modul, Role, Rentang Tanggal ===== -->
        <form method="GET" action="audit.php" class="row g-3 align-items-end mb-4">
            <div class="col-lg-3 col-md-6 col-12">
                <label class="stat-card-title d-block" style="margin-bottom:6px;">Filter Modul</label>
                <select name="modul" class="select-custom" onchange="this.form.submit()">
                    <option value="">Semua</option>
                    <?php foreach ($moduls as $m): ?>
                        <option value="<?= htmlspecialchars($m) ?>" <?= $filter_modul === $m ? 'selected' : '' ?>>
                            <?= htmlspecialchars($m) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-lg-3 col-md-6 col-12">
                <label class="stat-card-title d-block" style="margin-bottom:6px;">Filter Role</label>
                <select name="role" class="select-custom" onchange="this.form.submit()">
                    <option value="">Semua</option>
                    <?php foreach ($daftar_role as $r): ?>
                        <option value="<?= htmlspecialchars($r) ?>" <?= $filter_role === $r ? 'selected' : '' ?>>
                            <?= htmlspecialchars(ucwords(str_replace('_', ' ', $r))) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-lg-3 col-md-6 col-12">
                <label class="stat-card-title d-block" style="margin-bottom:6px;">Mulai</label>
                <input type="date" name="dari" class="form-control-custom" value="<?= htmlspecialchars($filter_dari) ?>"
                    max="<?= htmlspecialchars($filter_sampai ?: date('Y-m-d')) ?>" onchange="this.form.submit()">
            </div>
            <div class="col-lg-3 col-md-6 col-12">
                <label class="stat-card-title d-block" style="margin-bottom:6px;">Sampai</label>
                <input type="date" name="sampai" class="form-control-custom"
                    value="<?= htmlspecialchars($filter_sampai) ?>" min="<?= htmlspecialchars($filter_dari) ?>"
                    max="<?= date('Y-m-d') ?>" onchange="this.form.submit()">
            </div>
        </form>

        <?php if ($filter_modul !== '' || $filter_role !== '' || $filter_dari !== '' || $filter_sampai !== ''): ?>
            <div class="mb-3">
                <span class="badge-secondary">
                    <i class="bi bi-info-circle me-1"></i>
                    Menampilkan <?= number_format($totalTampil, 0, ',', '.') ?> dari
                    <?= number_format($totalLog, 0, ',', '.') ?> total log sesuai filter
                </span>
            </div>
        <?php endif; ?>

        <div class="table-responsive-custom">
            <table class="table-custom" id="tabelAudit">
                <thead>
                    <tr>
                        <th>Waktu</th>
                        <th>Pengguna</th>
                        <th>Modul</th>
                        <th>Aksi</th>
                        <th>Detail Perubahan</th>
                        <th class="text-center">Aksi Log</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($logs) === 0): ?>
                        <tr>
                            <td colspan="6" class="text-center py-4 text-muted">
                                <i class="bi bi-journal-x d-block mb-2" style="font-size:2rem;"></i>
                                Belum ada aktivitas yang
                                tercatat<?= ($filter_modul !== '' || $filter_role !== '' || $filter_dari !== '' || $filter_sampai !== '') ? ' untuk filter yang dipilih.' : '.' ?>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($logs as $l): ?>
                            <?php
                            $aksiUpper = strtoupper((string) $l['aksi']);
                            $badgeAksi = 'badge-secondary';
                            if (str_contains($aksiUpper, 'HAPUS') || str_contains($aksiUpper, 'DELETE')) {
                                $badgeAksi = 'badge-danger';
                            } elseif (str_contains($aksiUpper, 'UBAH') || str_contains($aksiUpper, 'UPDATE') || str_contains($aksiUpper, 'EDIT')) {
                                $badgeAksi = 'badge-warning';
                            } elseif (str_contains($aksiUpper, 'TAMBAH') || str_contains($aksiUpper, 'CREATE') || str_contains($aksiUpper, 'INSERT') || str_contains($aksiUpper, 'LOGIN')) {
                                $badgeAksi = 'badge-success';
                            }
                            $adaDetailJson = !empty($l['data_sebelum']) || !empty($l['data_sesudah']);
                            ?>
                            <tr>
                                <td class="text-nowrap"><?= date('d-m-Y H:i:s', strtotime($l['waktu_kejadian'])) ?></td>
                                <td>
                                    <div class="fw-bold"><?= htmlspecialchars($l['nama_lengkap'] ?: 'Pengguna Terhapus') ?>
                                    </div>
                                    <small class="text-secondary" style="text-transform:capitalize;">
                                        <?= htmlspecialchars(str_replace('_', ' ', $l['user_role'] ?: '-')) ?>
                                    </small>
                                </td>
                                <td><span class="badge-warning"><?= htmlspecialchars($l['modul']) ?></span></td>
                                <td><span class="<?= $badgeAksi ?>"><?= htmlspecialchars($l['aksi']) ?></span></td>
                                <td style="max-width:320px;">
                                    <span class="text-truncate d-inline-block" style="max-width:320px; vertical-align:middle;"
                                        title="<?= htmlspecialchars($l['detail_perubahan'] ?: '-') ?>">
                                        <?= htmlspecialchars($l['detail_perubahan'] ?: '-') ?>
                                    </span>
                                </td>
                                <td class="text-center">
                                    <?php if ($adaDetailJson): ?>
                                        <button type="button" class="btn-icon-bukti"
                                            onclick="tampilkanDetailAudit(<?= (int) $l['id'] ?>)"
                                            title="Lihat detail data sebelum/sesudah">
                                            <i class="bi bi-eye"></i>
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
        <div class="pagination-custom" id="pagination-tabelAudit"></div>
    </div>
</main>

<!-- ===== MODAL: Detail Perubahan Log ===== -->
<div id="modalDetailAudit" class="arp-modal-overlay" onclick="closeModalOutside(event, 'modalDetailAudit')">
    <div class="arp-modal-box" style="max-width:640px;">
        <div class="arp-modal-header">
            <div>
                <h6 class="fw-bold mb-0">Detail Aktivitas Log</h6>
                <small class="text-muted" id="detailAuditSubtitle">-</small>
            </div>
            <button class="arp-modal-close" onclick="closeModal('modalDetailAudit')">&times;</button>
        </div>
        <div class="arp-modal-body">
            <div class="mb-3">
                <span class="stat-card-title d-block" style="margin-bottom:4px;">Keterangan</span>
                <div id="detailAuditKeterangan">-</div>
            </div>
            <div class="row g-3">
                <div class="col-md-6">
                    <span class="stat-card-title d-block" style="margin-bottom:6px;">Data Sebelum</span>
                    <pre id="detailAuditSebelum" class="p-3 rounded-3"
                        style="background:var(--bg-glass); border:1px solid var(--border-color); font-size:0.78rem; max-height:280px; overflow:auto; white-space:pre-wrap; word-break:break-word;">-</pre>
                </div>
                <div class="col-md-6">
                    <span class="stat-card-title d-block" style="margin-bottom:6px;">Data Sesudah</span>
                    <pre id="detailAuditSesudah" class="p-3 rounded-3"
                        style="background:var(--bg-glass); border:1px solid var(--border-color); font-size:0.78rem; max-height:280px; overflow:auto; white-space:pre-wrap; word-break:break-word;">-</pre>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        initTablePagination('tabelAudit', 15);
    });

    // Data log (termasuk data_sebelum/data_sesudah) untuk modal detail,
    // dikirim sekali dari PHP supaya tombol "Lihat Detail" tidak perlu request baru.
    const AUDIT_LOG_DATA = <?= json_encode($logsForJs, JSON_UNESCAPED_UNICODE) ?>;

    function formatJsonPretty(raw) {
        if (!raw) return '-';
        try {
            return JSON.stringify(JSON.parse(raw), null, 2);
        } catch (e) {
            return raw; // bukan JSON valid, tampilkan apa adanya
        }
    }

    function tampilkanDetailAudit(id) {
        const log = AUDIT_LOG_DATA[id];
        if (!log) return;

        document.getElementById('detailAuditSubtitle').textContent =
            `${log.nama} (${log.role}) — ${log.modul} — ${log.waktu}`;
        document.getElementById('detailAuditKeterangan').textContent = log.detail || '-';
        document.getElementById('detailAuditSebelum').textContent = formatJsonPretty(log.sebelum);
        document.getElementById('detailAuditSesudah').textContent = formatJsonPretty(log.sesudah);

        openModal('modalDetailAudit');
    }
</script>

<?php include "../includes/footer.php"; ?>