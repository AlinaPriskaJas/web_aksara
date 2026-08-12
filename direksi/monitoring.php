<?php
// direksi/monitoring.php
session_start();
require_once "../config/koneksi.php";

$page_title = "Monitoring Operasional";

// ================== TAB MONITORING ==================
// Setiap rekapan (absensi, jadwal, cuti, kendaraan, stok, sertifikat)
// ditampilkan sebagai tab terpisah supaya halaman lebih rapi & mudah dipantau.
$tab_list_monitoring = ['absensi', 'jadwal', 'cuti', 'kendaraan', 'stok', 'sertifikat'];
$tab_monitoring = $_GET['tab_monitoring'] ?? 'absensi';
if (!in_array($tab_monitoring, $tab_list_monitoring, true)) {
    $tab_monitoring = 'absensi';
}

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
function safe_query(PDO $conn, string $sql, array $params = []): array
{
    try {
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
}

// ================== STAT CARDS ==================
$hadir_hari_ini    = safe_count($conn, "SELECT COUNT(*) FROM Absensi WHERE tanggal = CURDATE()");
$total_karyawan    = safe_count($conn, "SELECT COUNT(*) FROM Users WHERE role NOT IN ('client')");
$jadwal_berjalan   = safe_count($conn, "SELECT COUNT(*) FROM Jadwal_Pemeriksaan WHERE status IN ('Terjadwal','Berlangsung') AND tanggal >= CURDATE()");
$kendaraan_dipakai = safe_count($conn, "SELECT COUNT(*) FROM Kendaraan WHERE status_kendaraan = 'Dipakai'");

// ================== REKAP ABSENSI HARIAN (SELURUH KARYAWAN) ==================
// Filter tanggal (default hari ini), bisa dipilih tanggal lain lewat query string ?tgl=YYYY-MM-DD
$tgl_rekap = isset($_GET['tgl']) && $_GET['tgl'] !== '' ? $_GET['tgl'] : date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tgl_rekap)) {
    $tgl_rekap = date('Y-m-d');
}

$absensi_hari_ini = safe_query($conn, "
    SELECT a.*, u.nama_lengkap, u.role
    FROM Absensi a LEFT JOIN Users u ON a.user_id = u.id
    WHERE a.tanggal = :tgl
    ORDER BY a.jam_masuk DESC
", ['tgl' => $tgl_rekap]);

// ================== JADWAL PEMERIKSAAN TERDEKAT ==================
$jadwal_terdekat = safe_query($conn, "
    SELECT j.tanggal, j.jam_mulai, j.lokasi, j.status, dk.nama_perusahaan, sa.nama_lengkap AS nama_ahli
    FROM Jadwal_Pemeriksaan j
    LEFT JOIN Data_Klien dk ON j.klien_id = dk.id
    LEFT JOIN Sertifikat_Ahli sa ON j.ahli_k3_id = sa.id
    WHERE j.tanggal >= CURDATE() AND j.status IN ('Terjadwal','Reschedule','Berlangsung')
    ORDER BY j.tanggal ASC, j.jam_mulai ASC
    LIMIT 8
");

// ================== KENDARAAN OPERASIONAL ==================
$daftar_kendaraan = safe_query($conn, "SELECT nama_kendaraan, plat_nomor, status_kendaraan FROM Kendaraan ORDER BY status_kendaraan DESC, nama_kendaraan ASC");

// ================== STOK GUDANG MENIPIS ==================
$stok_menipis = safe_query($conn, "
    SELECT nama_barang, stok_sistem, stok_minimum, satuan
    FROM Gudang_Stok
    WHERE stok_minimum IS NOT NULL AND stok_sistem <= stok_minimum
    ORDER BY stok_sistem ASC
    LIMIT 6
");

// ================== SERTIFIKAT AHLI SEGERA EXPIRE ==================
$sertifikat_kritis = safe_query($conn, "
    SELECT nama_lengkap, bidang_keahlian, tanggal_kedaluwarsa, status_realtime
    FROM v_sertifikat_ahli_status
    WHERE status_realtime IN ('Peringatan Awal','Kritis-Expired')
    ORDER BY tanggal_kedaluwarsa ASC
    LIMIT 6
");

// ================== SISA CUTI SELURUH KARYAWAN ==================
// Direksi perlu memantau sisa saldo cuti tahunan semua karyawan (bukan cuma
// miliknya sendiri). Tahun bisa dipilih lewat query string ?tahun_cuti=YYYY,
// default tahun berjalan. Pakai LEFT JOIN + COALESCE supaya karyawan yang
// belum punya baris Cuti_Saldo (belum pernah ambil cuti tahun ini) tetap
// tampil dengan jatah default 12 hari / 0 terpakai, tanpa perlu INSERT dulu.
$tahun_sekarang_cuti = (int) date('Y');
$bulan_sekarang_cuti = (int) date('n'); // 1-12, dipakai untuk akrual bulanan

$tahun_cuti = isset($_GET['tahun_cuti']) ? (int) $_GET['tahun_cuti'] : $tahun_sekarang_cuti;
if ($tahun_cuti < 2020 || $tahun_cuti > 2100) {
    $tahun_cuti = $tahun_sekarang_cuti;
}

// Jatah cuti tahunan mengikuti akrual bulanan, sama persis dengan logika di
// halaman Pengajuan Cuti (ensure_saldo_cuti): TIDAK langsung 12 hari di awal
// tahun, tapi bertambah +1 hari otomatis setiap tanggal 1 bulan berjalan
// (Jan = 1 hari, Jul = 7 hari, dst, maksimal 12). Tahun yang sudah lewat
// dianggap jatah penuh 12, tahun yang akan datang belum mulai akrual (0).
if ($tahun_cuti < $tahun_sekarang_cuti) {
    $jatah_tahunan_akrual = 12;
} elseif ($tahun_cuti > $tahun_sekarang_cuti) {
    $jatah_tahunan_akrual = 0;
} else {
    $jatah_tahunan_akrual = max(0, min(12, $bulan_sekarang_cuti));
}

$sisa_cuti_karyawan_raw = safe_query($conn, "
    SELECT
        u.id, u.nama_lengkap, u.role,
        COALESCE(cs.terpakai, 0) AS terpakai
    FROM Users u
    LEFT JOIN Cuti_Saldo cs ON cs.user_id = u.id AND cs.tahun = :tahun
    WHERE u.role NOT IN ('client')
    ORDER BY u.nama_lengkap ASC
", ['tahun' => $tahun_cuti]);

$sisa_cuti_karyawan = array_map(function ($row) use ($jatah_tahunan_akrual) {
    $row['jatah_tahunan'] = $jatah_tahunan_akrual;
    $row['sisa'] = $jatah_tahunan_akrual - (int) $row['terpakai'];
    return $row;
}, $sisa_cuti_karyawan_raw);

// Daftar tahun untuk dropdown pilihan (tahun berjalan +/- beberapa tahun)
$opsi_tahun_cuti = range($tahun_sekarang_cuti + 1, $tahun_sekarang_cuti - 3);

// ================== REKAP CUTI KHUSUS & CUTI SAKIT ==================
// Dua jenis ini TIDAK punya jatah/saldo tahunan seperti Cuti Tahunan
// (langsung diajukan sesuai kebutuhan), jadi direkap terpisah: total hari
// & jumlah pengajuan yang sudah Disetujui per karyawan untuk tahun terpilih.
// Catatan: field jenis_cuti untuk izin sakit di form pengajuan (semua role)
// tersimpan sebagai 'Izin Sakit', karena itu dicek juga di sini supaya rekap
// tetap akurat walau labelnya beda dengan ENUM 'Cuti Sakit'.
$rekap_cuti_khusus_sakit = safe_query($conn, "
    SELECT
        u.id, u.nama_lengkap, u.role,
        COALESCE(SUM(CASE WHEN c.jenis_cuti = 'Cuti Khusus' AND c.status = 'Disetujui' THEN c.total_durasi ELSE 0 END), 0) AS khusus_hari,
        COALESCE(SUM(CASE WHEN c.jenis_cuti = 'Cuti Khusus' AND c.status = 'Disetujui' THEN 1 ELSE 0 END), 0) AS khusus_jumlah,
        COALESCE(SUM(CASE WHEN c.jenis_cuti IN ('Cuti Sakit', 'Izin Sakit') AND c.status = 'Disetujui' THEN c.total_durasi ELSE 0 END), 0) AS sakit_hari,
        COALESCE(SUM(CASE WHEN c.jenis_cuti IN ('Cuti Sakit', 'Izin Sakit') AND c.status = 'Disetujui' THEN 1 ELSE 0 END), 0) AS sakit_jumlah
    FROM Users u
    LEFT JOIN Cuti c ON c.user_id = u.id AND YEAR(c.tgl_mulai) = :tahun
    WHERE u.role NOT IN ('client')
    GROUP BY u.id, u.nama_lengkap, u.role
    ORDER BY u.nama_lengkap ASC
", ['tahun' => $tahun_cuti]);

function badge_kehadiran(string $status): string
{
    switch ($status) {
        case 'WFO - Kantor Utama':          return 'badge-success';
        case 'Dinas Luar / Survey Site':     return 'badge-info';
        case 'WFH / Kerja Remote':           return 'badge-warning';
        default:                             return 'badge-secondary';
    }
}
function badge_kendaraan(string $status): string
{
    switch ($status) {
        case 'Tersedia':    return 'badge-success';
        case 'Dipakai':     return 'badge-warning';
        case 'Maintenance': return 'badge-danger';
        default:            return 'badge-secondary';
    }
}
// Sisa cuti tipis (<=2 hari) = merah (kritis), <=5 hari = kuning (perlu diawasi), selebihnya hijau (aman)
function badge_sisa_cuti(int $sisa): string
{
    if ($sisa <= 2) return 'badge-danger';
    if ($sisa <= 5) return 'badge-warning';
    return 'badge-success';
}

include "../includes/header.php";
include "../includes/sidebar.php";
include "../includes/topbar.php";
?>

<main class="main-content">
    <div class="mb-4 d-flex justify-content-between align-items-start flex-wrap gap-3">
        <div>
            <h4 class="fw-bold mb-1">Monitoring Operasional</h4>
            <p class="text-secondary mb-0">Pantauan kondisi lapangan &amp; sumber daya perusahaan secara real time</p>
        </div>
        <button type="button" class="btn-primary-custom" onclick="bukaModalRekapBulanan()">
            <i class="bi bi-file-earmark-pdf me-1"></i> Rekap Bulanan
        </button>
    </div>

    <div class="row g-4 mb-4">
        <div class="col-xl-3 col-md-6 col-12">
            <div class="stat-card">
                <div class="stat-card-info">
                    <span class="stat-card-title">Hadir Hari Ini</span>
                    <span class="stat-card-value"><?= $hadir_hari_ini ?> / <?= $total_karyawan ?></span>
                </div>
                <div class="stat-card-icon success"><i class="bi bi-person-check-fill"></i></div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6 col-12">
            <div class="stat-card">
                <div class="stat-card-info">
                    <span class="stat-card-title">Jadwal Berjalan</span>
                    <span class="stat-card-value"><?= $jadwal_berjalan ?></span>
                </div>
                <div class="stat-card-icon"><i class="bi bi-calendar-event"></i></div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6 col-12">
            <div class="stat-card">
                <div class="stat-card-info">
                    <span class="stat-card-title">Kendaraan Dipakai</span>
                    <span class="stat-card-value"><?= $kendaraan_dipakai ?></span>
                </div>
                <div class="stat-card-icon warning"><i class="bi bi-truck"></i></div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6 col-12">
            <div class="stat-card">
                <div class="stat-card-info">
                    <span class="stat-card-title">Stok Menipis</span>
                    <span class="stat-card-value"><?= count($stok_menipis) ?></span>
                </div>
                <div class="stat-card-icon danger"><i class="bi bi-box-seam"></i></div>
            </div>
        </div>
    </div>

    <!-- Tab Navigasi -->
    <div class="arp-tab-group">
        <div class="arp-tab-nav">
            <a href="monitoring.php?tab_monitoring=absensi&tgl=<?= urlencode($tgl_rekap) ?>"
                class="arp-tab-btn<?= $tab_monitoring === 'absensi' ? ' active' : '' ?>">
                <i class="bi bi-calendar-check me-1"></i> Rekap Absensi
            </a>
            <a href="monitoring.php?tab_monitoring=jadwal"
                class="arp-tab-btn<?= $tab_monitoring === 'jadwal' ? ' active' : '' ?>">
                <i class="bi bi-calendar-event me-1"></i> Jadwal Pemeriksaan
            </a>
            <a href="monitoring.php?tab_monitoring=cuti&tahun_cuti=<?= urlencode((string) $tahun_cuti) ?>"
                class="arp-tab-btn<?= $tab_monitoring === 'cuti' ? ' active' : '' ?>">
                <i class="bi bi-calendar-week me-1"></i> Sisa Cuti Karyawan
            </a>
            <a href="monitoring.php?tab_monitoring=kendaraan"
                class="arp-tab-btn<?= $tab_monitoring === 'kendaraan' ? ' active' : '' ?>">
                <i class="bi bi-truck me-1"></i> Status Kendaraan
            </a>
            <a href="monitoring.php?tab_monitoring=stok"
                class="arp-tab-btn<?= $tab_monitoring === 'stok' ? ' active' : '' ?>">
                <i class="bi bi-box-seam me-1"></i> Stok Gudang Menipis
                <?php if (count($stok_menipis) > 0): ?>
                    <span class="badge-danger ms-1"><?= count($stok_menipis) ?></span>
                <?php endif; ?>
            </a>
            <a href="monitoring.php?tab_monitoring=sertifikat"
                class="arp-tab-btn<?= $tab_monitoring === 'sertifikat' ? ' active' : '' ?>">
                <i class="bi bi-patch-check me-1"></i> Sertifikat Ahli
                <?php if (count($sertifikat_kritis) > 0): ?>
                    <span class="badge-warning ms-1"><?= count($sertifikat_kritis) ?></span>
                <?php endif; ?>
            </a>
        </div>
    </div>

    <?php if ($tab_monitoring === 'absensi'): ?>
    <div class="row g-4 mb-4">
        <div class="col-12 arp-tab-panel" id="panelAbsensi">
            <div class="card-box">
                <div class="table-toolbar">
                    <h5 class="table-toolbar-title fw-bold">Rekap Absensi Harian (Seluruh Karyawan)</h5>
                    <div class="table-toolbar-actions">
                        <form method="GET" action="monitoring.php" class="d-flex align-items-center gap-2">
                            <input type="hidden" name="tab_monitoring" value="absensi">
                            <input type="date" name="tgl" class="form-control-custom" value="<?= htmlspecialchars($tgl_rekap) ?>" onchange="this.form.submit()">
                        </form>
                        <div class="search-box-container">
                            <i class="bi bi-search"></i>
                            <input type="text" class="search-box" placeholder="Cari nama karyawan..."
                                data-table-search="tabelRekapAbsensi" onkeyup="handleTableSearch('tabelRekapAbsensi')">
                        </div>
                    </div>
                </div>

                <p class="text-secondary fs-7 mb-3">
                    Menampilkan absensi tanggal <strong><?= date('d-m-Y', strtotime($tgl_rekap)) ?></strong>
                    (<?= count($absensi_hari_ini) ?> dari <?= $total_karyawan ?> karyawan)
                </p>

                <?php if (empty($absensi_hari_ini)): ?>
                    <p class="text-secondary fs-7 mb-0">Belum ada data absensi pada tanggal ini.</p>
                <?php else: ?>
                    <div class="table-responsive-custom">
                        <table class="table-custom" id="tabelRekapAbsensi">
                            <thead>
                                <tr>
                                    <th>Nama</th>
                                    <th>Status</th>
                                    <th>Jam Masuk</th>
                                    <th>Jam Pulang</th>
                                    <th>Status</th>
                                    <th>Lokasi Masuk</th>
                                    <th class="text-center">Bukti</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($absensi_hari_ini as $a): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($a['nama_lengkap'] ?? '-') ?></td>
                                        <td><?= htmlspecialchars($a['role'] ?? '-') ?></td>
                                        <td><?= htmlspecialchars(substr($a['jam_masuk'], 0, 5)) ?> WIB</td>
                                        <td><?= $a['jam_pulang'] ? htmlspecialchars(substr($a['jam_pulang'], 0, 5)) . ' WIB' : '<span class="text-muted">Belum Checkout</span>' ?></td>
                                        <td><span class="<?= badge_kehadiran($a['status_kehadiran']) ?> fs-7"><?= htmlspecialchars($a['status_kehadiran']) ?></span></td>
                                        <td><?= htmlspecialchars($a['lokasi_masuk'] ?? '-') ?></td>
                                        <td class="text-center">
                                            <?php if (!empty($a['bukti_foto']) && $a['bukti_foto'] !== 'input_manual_admin'): ?>
                                                <button type="button" class="btn-icon-bukti"
                                                    onclick="tampilkanBuktiFoto('../<?= htmlspecialchars($a['bukti_foto']) ?>')"
                                                    title="Lihat Bukti Foto">
                                                    <i class="bi bi-image"></i>
                                                </button>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="pagination-custom" id="pagination-tabelRekapAbsensi"></div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($tab_monitoring === 'jadwal'): ?>
    <div class="row g-4 mb-4">
        <div class="col-12 arp-tab-panel" id="panelJadwal">
            <div class="card-box">
                <h5 class="fw-bold mb-3">Jadwal Pemeriksaan Terdekat</h5>
                <?php if (empty($jadwal_terdekat)): ?>
                    <p class="text-secondary fs-7 mb-0">Tidak ada jadwal pemeriksaan mendatang.</p>
                <?php else: ?>
                    <ul class="list-unstyled mb-0">
                        <?php foreach ($jadwal_terdekat as $j): ?>
                            <li class="d-flex justify-content-between align-items-start mb-3 pb-2" style="border-bottom:1px solid var(--border-color, #eee);">
                                <div>
                                    <div class="fw-semibold fs-7"><?= htmlspecialchars($j['nama_perusahaan'] ?? '-') ?></div>
                                    <div class="fs-7 text-muted"><?= htmlspecialchars($j['lokasi'] ?? '-') ?> &middot; <?= htmlspecialchars($j['nama_ahli'] ?? 'Belum ditugaskan') ?></div>
                                </div>
                                <div class="text-end fs-7">
                                    <div class="fw-semibold"><?= date('d M', strtotime($j['tanggal'])) ?></div>
                                    <div class="text-muted"><?= substr($j['jam_mulai'], 0, 5) ?></div>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($tab_monitoring === 'cuti'): ?>
    <div class="row g-4 mb-4">
        <div class="col-12 arp-tab-panel" id="panelCuti">
            <div class="card-box">
                <div class="table-toolbar">
                    <h5 class="table-toolbar-title fw-bold">Sisa Cuti Karyawan</h5>
                    <div class="table-toolbar-actions">
                        <form method="GET" action="monitoring.php" class="d-flex align-items-center gap-2">
                            <input type="hidden" name="tab_monitoring" value="cuti">
                            <select name="tahun_cuti" class="select-custom" onchange="this.form.submit()">
                                <?php foreach ($opsi_tahun_cuti as $th): ?>
                                    <option value="<?= $th ?>" <?= $th === $tahun_cuti ? 'selected' : '' ?>>Tahun <?= $th ?></option>
                                <?php endforeach; ?>
                            </select>
                        </form>
                        <div class="search-box-container">
                            <i class="bi bi-search"></i>
                            <input type="text" class="search-box" placeholder="Cari nama karyawan..."
                                data-table-search="tabelSisaCuti" onkeyup="handleTableSearch('tabelSisaCuti')">
                        </div>
                    </div>
                </div>

                <p class="text-secondary fs-7 mb-3">
                    Jatah cuti tahunan mengikuti akrual bulanan (bertambah otomatis tiap tanggal 1,
                    bukan langsung 12 hari di awal tahun) &mdash; sama seperti di halaman Pengajuan Cuti.
                    Untuk tahun <strong><?= $tahun_cuti ?></strong>, jatah saat ini
                    <strong><?= (int) $jatah_tahunan_akrual ?> hari</strong> (<?= count($sisa_cuti_karyawan) ?> karyawan).
                </p>

                <?php if (empty($sisa_cuti_karyawan)): ?>
                    <p class="text-secondary fs-7 mb-0">Belum ada data karyawan.</p>
                <?php else: ?>
                    <div class="table-responsive-custom">
                        <table class="table-custom" id="tabelSisaCuti">
                            <thead>
                                <tr>
                                    <th>Nama</th>
                                    <th>Status</th>
                                    <th class="text-center">Jatah Tahunan</th>
                                    <th class="text-center">Terpakai</th>
                                    <th class="text-center">Sisa Cuti</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($sisa_cuti_karyawan as $c): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($c['nama_lengkap']) ?></td>
                                        <td><?= htmlspecialchars($c['role']) ?></td>
                                        <td class="text-center"><?= (int) $c['jatah_tahunan'] ?> Hari</td>
                                        <td class="text-center"><?= (int) $c['terpakai'] ?> Hari</td>
                                        <td class="text-center">
                                            <span class="<?= badge_sisa_cuti((int) $c['sisa']) ?> fs-7"><?= (int) $c['sisa'] ?> Hari</span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="pagination-custom" id="pagination-tabelSisaCuti"></div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="row g-4 mb-4">
        <div class="col-12 arp-tab-panel" id="panelRekapCutiLain">
            <div class="card-box">
                <div class="table-toolbar">
                    <h5 class="table-toolbar-title fw-bold">Rekap Cuti Khusus &amp; Cuti Sakit</h5>
                    <div class="table-toolbar-actions">
                        <div class="search-box-container">
                            <i class="bi bi-search"></i>
                            <input type="text" class="search-box" placeholder="Cari nama karyawan..."
                                data-table-search="tabelRekapCutiLain" onkeyup="handleTableSearch('tabelRekapCutiLain')">
                        </div>
                    </div>
                </div>

                <p class="text-secondary fs-7 mb-3">
                    Cuti Khusus dan Cuti Sakit tidak punya jatah/saldo tahunan seperti Cuti Tahunan
                    (langsung diajukan sesuai kebutuhan), jadi direkap terpisah di sini: total pengajuan
                    yang sudah Disetujui untuk tahun <strong><?= $tahun_cuti ?></strong>.
                </p>

                <?php if (empty($rekap_cuti_khusus_sakit)): ?>
                    <p class="text-secondary fs-7 mb-0">Belum ada data karyawan.</p>
                <?php else: ?>
                    <div class="table-responsive-custom">
                        <table class="table-custom" id="tabelRekapCutiLain">
                            <thead>
                                <tr>
                                    <th>Nama</th>
                                    <th>Status</th>
                                    <th class="text-center">Cuti Khusus (Hari)</th>
                                    <th class="text-center">Cuti Khusus (Pengajuan)</th>
                                    <th class="text-center">Cuti Sakit (Hari)</th>
                                    <th class="text-center">Cuti Sakit (Pengajuan)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rekap_cuti_khusus_sakit as $r): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($r['nama_lengkap']) ?></td>
                                        <td><?= htmlspecialchars($r['role']) ?></td>
                                        <td class="text-center"><?= (int) $r['khusus_hari'] ?> Hari</td>
                                        <td class="text-center"><?= (int) $r['khusus_jumlah'] ?>x</td>
                                        <td class="text-center"><?= (int) $r['sakit_hari'] ?> Hari</td>
                                        <td class="text-center"><?= (int) $r['sakit_jumlah'] ?>x</td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="pagination-custom" id="pagination-tabelRekapCutiLain"></div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($tab_monitoring === 'kendaraan'): ?>
    <div class="row g-4 mb-4">
        <div class="col-12 arp-tab-panel" id="panelKendaraan">
            <div class="card-box">
                <h5 class="fw-bold mb-3">Status Kendaraan</h5>
                <?php if (empty($daftar_kendaraan)): ?>
                    <p class="text-secondary fs-7 mb-0">Belum ada data kendaraan.</p>
                <?php else: ?>
                    <ul class="list-unstyled mb-0">
                        <?php foreach ($daftar_kendaraan as $k): ?>
                            <li class="d-flex justify-content-between align-items-center mb-2 pb-2" style="border-bottom:1px solid var(--border-color, #eee);">
                                <span class="fs-7"><?= htmlspecialchars($k['nama_kendaraan']) ?> <span class="text-muted">(<?= htmlspecialchars($k['plat_nomor']) ?>)</span></span>
                                <span class="<?= badge_kendaraan($k['status_kendaraan']) ?> fs-7"><?= htmlspecialchars($k['status_kendaraan']) ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($tab_monitoring === 'stok'): ?>
    <div class="row g-4 mb-4">
        <div class="col-12 arp-tab-panel" id="panelStok">
            <div class="card-box">
                <h5 class="fw-bold mb-3">Stok Gudang Menipis</h5>
                <?php if (empty($stok_menipis)): ?>
                    <p class="text-secondary fs-7 mb-0">Semua stok dalam batas aman.</p>
                <?php else: ?>
                    <ul class="list-unstyled mb-0">
                        <?php foreach ($stok_menipis as $s): ?>
                            <li class="d-flex justify-content-between align-items-center mb-2 pb-2" style="border-bottom:1px solid var(--border-color, #eee);">
                                <span class="fs-7"><?= htmlspecialchars($s['nama_barang']) ?></span>
                                <span class="badge-danger fs-7"><?= (int) $s['stok_sistem'] ?> <?= htmlspecialchars($s['satuan']) ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($tab_monitoring === 'sertifikat'): ?>
    <div class="row g-4 mb-4">
        <div class="col-12 arp-tab-panel" id="panelSertifikat">
            <div class="card-box">
                <h5 class="fw-bold mb-3">Sertifikat Ahli Perlu Perhatian</h5>
                <?php if (empty($sertifikat_kritis)): ?>
                    <p class="text-secondary fs-7 mb-0">Semua sertifikat masih aktif.</p>
                <?php else: ?>
                    <ul class="list-unstyled mb-0">
                        <?php foreach ($sertifikat_kritis as $s): ?>
                            <li class="d-flex justify-content-between align-items-center mb-2 pb-2" style="border-bottom:1px solid var(--border-color, #eee);">
                                <div>
                                    <div class="fs-7 fw-semibold"><?= htmlspecialchars($s['nama_lengkap']) ?></div>
                                    <div class="fs-7 text-muted">exp. <?= date('d M Y', strtotime($s['tanggal_kedaluwarsa'])) ?></div>
                                </div>
                                <span class="<?= $s['status_realtime'] === 'Kritis-Expired' ? 'badge-danger' : 'badge-warning' ?> fs-7"><?= htmlspecialchars($s['status_realtime']) ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>
</main>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        initTablePagination('tabelRekapAbsensi', 10);
        initTablePagination('tabelSisaCuti', 10);
        initTablePagination('tabelRekapCutiLain', 10);
    });
</script>

<!-- Modal Rekap Bulanan -->
<div class="modal fade modal-custom" id="modalRekapBulanan" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form action="rekap_bulanan.php" method="GET" target="_blank">
                <div class="modal-header">
                    <h5 class="modal-title">Rekap Bulanan</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="text-secondary fs-7 mb-3">
                        Pilih jenis rekap dan bulan yang ingin dilihat. Rekap akan dibuka di tab baru,
                        bisa langsung dilihat di layar, dan tersedia tombol untuk mengunduhnya sebagai file CSV.
                    </p>

                    <label class="form-label fw-semibold fs-7 mb-2">Jenis Rekap</label>
                    <select name="jenis" class="select-custom mb-3" style="width:100%;" required>
                        <option value="absensi">Rekap Absensi</option>
                        <option value="cuti">Rekap Cuti</option>
                    </select>

                    <label class="form-label fw-semibold fs-7 mb-2">Pilih Bulan</label>
                    <input type="month" name="bulan" class="form-control-custom" style="width:100%;"
                        value="<?= date('Y-m') ?>" required>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-secondary-custom" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn-primary-custom">
                        <i class="bi bi-eye"></i> Lihat &amp; Unduh Rekap
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    function bukaModalRekapBulanan() {
        const modal = new bootstrap.Modal(document.getElementById('modalRekapBulanan'));
        modal.show();
    }
</script>

<?php
include "../includes/footer.php";
?>