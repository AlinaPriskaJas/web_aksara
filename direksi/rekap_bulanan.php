<?php
// direksi/rekap_bulanan.php
// Halaman rekap bulanan (Absensi / Cuti) untuk seluruh karyawan.
// Halaman ini bisa DILIHAT langsung di browser (tanpa sidebar/topbar, biar rapi),
// dan bisa DIUNDUH langsung sebagai file PDF (tombol "Unduh PDF") tanpa perlu dicetak
// lewat dialog print browser. PDF dibuat pakai library FPDF (../vendor_fpdf/fpdf.php).
session_start();
require_once "../config/koneksi.php";

// ================== VALIDASI INPUT ==================
$jenis = $_GET['jenis'] ?? 'absensi';
if (!in_array($jenis, ['absensi', 'cuti'], true)) {
    $jenis = 'absensi';
}

$bulan = $_GET['bulan'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $bulan)) {
    $bulan = date('Y-m');
}
[$tahun_rekap, $bulan_angka] = array_map('intval', explode('-', $bulan));

$nama_bulan_indo = [
    1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
    5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
    9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
];
$label_bulan = ($nama_bulan_indo[$bulan_angka] ?? '-') . ' ' . $tahun_rekap;

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

// ================== AMBIL DATA SESUAI JENIS ==================
if ($jenis === 'absensi') {
    $data_rekap = safe_query($conn, "
        SELECT
            u.id, u.nama_lengkap, u.role,
            SUM(CASE WHEN a.status_kehadiran = 'WFO - Kantor Utama' THEN 1 ELSE 0 END) AS wfo,
            SUM(CASE WHEN a.status_kehadiran = 'Dinas Luar / Survey Site' THEN 1 ELSE 0 END) AS dinas,
            SUM(CASE WHEN a.status_kehadiran = 'WFH / Kerja Remote' THEN 1 ELSE 0 END) AS wfh,
            SUM(CASE WHEN a.status_kehadiran = 'Sakit / Izin (Setengah Hari)' THEN 1 ELSE 0 END) AS sakit_izin,
            COUNT(a.id) AS total_hadir
        FROM Users u
        LEFT JOIN Absensi a ON a.user_id = u.id AND DATE_FORMAT(a.tanggal, '%Y-%m') = :bulan
        WHERE u.role NOT IN ('client')
        GROUP BY u.id, u.nama_lengkap, u.role
        ORDER BY u.nama_lengkap ASC
    ", ['bulan' => $bulan]);
} else {
    $data_rekap = safe_query($conn, "
        SELECT
            u.id, u.nama_lengkap, u.role,
            COALESCE(SUM(CASE WHEN c.jenis_cuti = 'Cuti Tahunan' AND c.status = 'Disetujui' THEN c.total_durasi ELSE 0 END), 0) AS tahunan_hari,
            COALESCE(SUM(CASE WHEN c.jenis_cuti = 'Cuti Khusus' AND c.status = 'Disetujui' THEN c.total_durasi ELSE 0 END), 0) AS khusus_hari,
            COALESCE(SUM(CASE WHEN c.jenis_cuti IN ('Cuti Sakit', 'Izin Sakit') AND c.status = 'Disetujui' THEN c.total_durasi ELSE 0 END), 0) AS sakit_hari,
            COALESCE(SUM(CASE WHEN c.status = 'Disetujui' THEN c.total_durasi ELSE 0 END), 0) AS total_hari,
            COALESCE(SUM(CASE WHEN c.status = 'Disetujui' THEN 1 ELSE 0 END), 0) AS total_pengajuan
        FROM Users u
        LEFT JOIN Cuti c ON c.user_id = u.id AND DATE_FORMAT(c.tgl_mulai, '%Y-%m') = :bulan
        WHERE u.role NOT IN ('client')
        GROUP BY u.id, u.nama_lengkap, u.role
        ORDER BY u.nama_lengkap ASC
    ", ['bulan' => $bulan]);
}

$judul_rekap = $jenis === 'absensi' ? 'Rekap Absensi Bulanan' : 'Rekap Cuti Bulanan';

// ================== UNDUH SEBAGAI PDF (tanpa perlu dicetak) ==================
if (($_GET['unduh'] ?? '') === '1') {
    require_once __DIR__ . '/../vendor_fpdf/fpdf.php';

    $nama_file = ($jenis === 'absensi' ? 'Rekap_Absensi_' : 'Rekap_Cuti_')
        . str_replace(' ', '_', $label_bulan) . '.pdf';

    // Helper: konversi UTF-8 -> ISO-8859-1 karena FPDF standar belum unicode
    $c = static function (?string $s): string {
        $s = (string) $s;
        return function_exists('iconv')
            ? (iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $s) ?: $s)
            : mb_convert_encoding($s, 'ISO-8859-1', 'UTF-8');
    };

    class RekapPDF extends FPDF
    {
        public string $judul = '';
        public string $periode = '';
        public int $jumlahOrang = 0;

        public function Header(): void
        {
            $this->SetFont('Arial', 'B', 12);
            $this->SetTextColor(41, 151, 117); // brand color #299775
            $this->Cell(0, 6, 'PT Aksara Riksa Perdana', 0, 1);
            $this->SetFont('Arial', '', 8);
            $this->SetTextColor(100, 116, 139);
            $this->Cell(0, 5, 'Perusahaan Jasa K3 (PJK3) - Dokumen Internal', 0, 1);
            $this->Ln(2);

            $this->SetFont('Arial', 'B', 13);
            $this->SetTextColor(30, 41, 59);
            $this->Cell(0, 7, $this->judul, 0, 1, 'C');
            $this->SetFont('Arial', '', 9);
            $this->SetTextColor(100, 116, 139);
            $this->Cell(0, 5, 'Periode: ' . $this->periode . ' - Seluruh Karyawan (' . $this->jumlahOrang . ' orang)', 0, 1, 'C');

            $this->SetDrawColor(41, 151, 117);
            $this->SetLineWidth(0.6);
            $this->Ln(2);
            $this->Line(10, $this->GetY(), $this->GetPageWidth() - 10, $this->GetY());
            $this->Ln(4);
            $this->SetTextColor(30, 41, 59);
        }

        public function Footer(): void
        {
            $this->SetY(-15);
            $this->SetFont('Arial', '', 7.5);
            $this->SetTextColor(100, 116, 139);
            $this->Cell(0, 5, 'Dokumen ini dibuat otomatis oleh sistem ARP Digital.', 0, 0, 'L');
            $this->Cell(0, 5, 'Halaman ' . $this->PageNo() . '/{nb}', 0, 0, 'R');
        }
    }

    $pdf = new RekapPDF('L', 'mm', 'A4');
    $pdf->AliasNbPages();
    $pdf->judul = $c($judul_rekap);
    $pdf->periode = $c($label_bulan);
    $pdf->jumlahOrang = count($data_rekap);
    $pdf->SetMargins(10, 12, 10);
    $pdf->SetAutoPageBreak(true, 16);
    $pdf->AddPage();

    if ($jenis === 'absensi') {
        $header = ['No', 'Nama Karyawan', 'Role', 'WFO', 'Dinas Luar', 'WFH', 'Sakit/Izin', 'Total Hadir'];
        $widths = [12, 90, 35, 25, 30, 25, 28, 30];
    } else {
        $header = ['No', 'Nama Karyawan', 'Role', 'Cuti Tahunan (Hari)', 'Cuti Khusus (Hari)', 'Cuti Sakit (Hari)', 'Total Hari Cuti', 'Jumlah Pengajuan'];
        $widths = [12, 75, 30, 35, 33, 30, 30, 32];
    }

    $pdf->SetFont('Arial', 'B', 8.5);
    $pdf->SetFillColor(248, 250, 252);
    $pdf->SetDrawColor(226, 232, 240);
    $pdf->SetTextColor(30, 41, 59);
    foreach ($header as $i => $h) {
        $align = $i === 1 ? 'L' : 'C';
        $pdf->Cell($widths[$i], 8, $c($h), 1, 0, $align, true);
    }
    $pdf->Ln();

    $pdf->SetFont('Arial', '', 8.5);
    if (empty($data_rekap)) {
        $pdf->Ln(2);
        $pdf->Cell(array_sum($widths), 10, $c('Belum ada data karyawan untuk periode ini.'), 0, 1, 'C');
    } else {
        $fill = false;
        foreach ($data_rekap as $i => $r) {
            $pdf->SetFillColor(250, 251, 252);
            if ($jenis === 'absensi') {
                $cols = [
                    $i + 1,
                    $c($r['nama_lengkap']),
                    $c(ucfirst($r['role'])),
                    (int) $r['wfo'],
                    (int) $r['dinas'],
                    (int) $r['wfh'],
                    (int) $r['sakit_izin'],
                    (int) $r['total_hadir'],
                ];
            } else {
                $cols = [
                    $i + 1,
                    $c($r['nama_lengkap']),
                    $c(ucfirst($r['role'])),
                    (int) $r['tahunan_hari'],
                    (int) $r['khusus_hari'],
                    (int) $r['sakit_hari'],
                    (int) $r['total_hari'],
                    (int) $r['total_pengajuan'] . 'x',
                ];
            }
            foreach ($cols as $ci => $val) {
                $align = $ci === 1 ? 'L' : 'C';
                $pdf->Cell($widths[$ci], 7.5, (string) $val, 1, 0, $align, $fill);
            }
            $pdf->Ln();
            $fill = !$fill;
        }
    }

    // Bersihkan output buffer supaya file PDF tidak rusak oleh whitespace/error sebelumnya
    if (ob_get_level()) {
        ob_end_clean();
    }
    $pdf->Output('D', $nama_file);
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title><?= htmlspecialchars($judul_rekap) ?> - <?= htmlspecialchars($label_bulan) ?></title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<style>
    :root {
        --primary: #299775;
        --border-color: #e2e8f0;
        --text-secondary: #64748b;
    }
    * { box-sizing: border-box; }
    body {
        font-family: 'Segoe UI', Arial, sans-serif;
        color: #1e293b;
        background: #f1f5f9;
        margin: 0;
        padding: 24px;
    }
    .lembar {
        max-width: 950px;
        margin: 0 auto;
        background: #fff;
        padding: 32px 36px;
        border-radius: 10px;
        box-shadow: 0 1px 4px rgba(0,0,0,0.08);
    }
    .toolbar-cetak {
        max-width: 950px;
        margin: 0 auto 16px auto;
        display: flex;
        justify-content: flex-end;
        gap: 10px;
    }
    .btn-cetak {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 8px 16px;
        border-radius: 8px;
        border: none;
        background: var(--primary);
        color: #fff;
        font-weight: 600;
        font-size: 0.85rem;
        cursor: pointer;
    }
    .btn-tutup {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 8px 16px;
        border-radius: 8px;
        border: 1px solid var(--border-color);
        background: #fff;
        color: #475569;
        font-weight: 600;
        font-size: 0.85rem;
        cursor: pointer;
    }
    .kop {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        border-bottom: 3px solid var(--primary);
        padding-bottom: 16px;
        margin-bottom: 20px;
    }
    .kop h1 {
        font-size: 1.05rem;
        margin: 0 0 2px 0;
        color: var(--primary);
    }
    .kop p { margin: 0; font-size: 0.78rem; color: var(--text-secondary); }
    .judul-rekap { text-align: center; margin-bottom: 24px; }
    .judul-rekap h2 { margin: 0 0 4px 0; font-size: 1.15rem; }
    .judul-rekap p { margin: 0; font-size: 0.85rem; color: var(--text-secondary); }
    table {
        width: 100%;
        border-collapse: collapse;
        font-size: 0.8rem;
    }
    thead th {
        background: #f8fafc;
        border: 1px solid var(--border-color);
        padding: 8px 10px;
        text-align: center;
        font-weight: 600;
    }
    tbody td {
        border: 1px solid var(--border-color);
        padding: 7px 10px;
    }
    tbody td.text-left { text-align: left; }
    tbody td.text-center { text-align: center; }
    tbody tr:nth-child(even) { background: #fafbfc; }
    .footer-cetak {
        margin-top: 28px;
        display: flex;
        justify-content: space-between;
        font-size: 0.75rem;
        color: var(--text-secondary);
    }
    .kosong {
        text-align: center;
        padding: 24px;
        color: var(--text-secondary);
        font-size: 0.85rem;
    }

    @media print {
        body { background: #fff; padding: 0; }
        .toolbar-cetak { display: none !important; }
        .lembar { box-shadow: none; border-radius: 0; padding: 0; max-width: 100%; }
        @page { size: A4 landscape; margin: 14mm; }
    }
</style>
</head>
<body>

    <div class="toolbar-cetak">
        <a href="?jenis=<?= urlencode($jenis) ?>&bulan=<?= urlencode($bulan) ?>&unduh=1"
            class="btn-cetak" style="text-decoration:none;">
            <i class="bi bi-download"></i> Unduh PDF
        </a>
        <button type="button" class="btn-tutup" onclick="window.close()">
            <i class="bi bi-x-lg"></i> Tutup
        </button>
    </div>

    <div class="lembar">
        <div class="kop">
            <div>
                <h1>PT Aksara Riksa Perdana</h1>
                <p>Perusahaan Jasa K3 (PJK3) &middot; Dokumen Internal</p>
            </div>
            <div style="text-align:right;">
                <p>Dilihat: <?= date('d M Y, H:i') ?> WIB</p>
            </div>
        </div>

        <div class="judul-rekap">
            <h2><?= htmlspecialchars($judul_rekap) ?></h2>
            <p>Periode: <?= htmlspecialchars($label_bulan) ?> &middot; Seluruh Karyawan (<?= count($data_rekap) ?> orang)</p>
        </div>

        <?php if (empty($data_rekap)): ?>
            <p class="kosong">Belum ada data karyawan untuk periode ini.</p>
        <?php elseif ($jenis === 'absensi'): ?>
            <table>
                <thead>
                    <tr>
                        <th style="width:34px;">No</th>
                        <th style="text-align:left;">Nama Karyawan</th>
                        <th>Role</th>
                        <th>WFO</th>
                        <th>Dinas Luar</th>
                        <th>WFH</th>
                        <th>Sakit/Izin</th>
                        <th>Total Hadir</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($data_rekap as $i => $r): ?>
                        <tr>
                            <td class="text-center"><?= $i + 1 ?></td>
                            <td class="text-left"><?= htmlspecialchars($r['nama_lengkap']) ?></td>
                            <td class="text-center"><?= htmlspecialchars(ucfirst($r['role'])) ?></td>
                            <td class="text-center"><?= (int) $r['wfo'] ?></td>
                            <td class="text-center"><?= (int) $r['dinas'] ?></td>
                            <td class="text-center"><?= (int) $r['wfh'] ?></td>
                            <td class="text-center"><?= (int) $r['sakit_izin'] ?></td>
                            <td class="text-center"><strong><?= (int) $r['total_hadir'] ?></strong></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th style="width:34px;">No</th>
                        <th style="text-align:left;">Nama Karyawan</th>
                        <th>Role</th>
                        <th>Cuti Tahunan (Hari)</th>
                        <th>Cuti Khusus (Hari)</th>
                        <th>Cuti Sakit (Hari)</th>
                        <th>Total Hari Cuti</th>
                        <th>Jumlah Pengajuan Disetujui</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($data_rekap as $i => $r): ?>
                        <tr>
                            <td class="text-center"><?= $i + 1 ?></td>
                            <td class="text-left"><?= htmlspecialchars($r['nama_lengkap']) ?></td>
                            <td class="text-center"><?= htmlspecialchars(ucfirst($r['role'])) ?></td>
                            <td class="text-center"><?= (int) $r['tahunan_hari'] ?></td>
                            <td class="text-center"><?= (int) $r['khusus_hari'] ?></td>
                            <td class="text-center"><?= (int) $r['sakit_hari'] ?></td>
                            <td class="text-center"><strong><?= (int) $r['total_hari'] ?></strong></td>
                            <td class="text-center"><?= (int) $r['total_pengajuan'] ?>x</td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <div class="footer-cetak">
            <span>Dokumen ini dibuat otomatis oleh sistem ARP Digital.</span>
            <span>Direksi PT Aksara Riksa Perdana</span>
        </div>
    </div>

</body>
</html>