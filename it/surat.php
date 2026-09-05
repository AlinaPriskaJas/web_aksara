<?php
//it/surat.php — Modul Persuratan untuk Ahli K3 (tab Surat & Buat Surat saja)
require_once "../config/koneksi.php";

if (session_status() === PHP_SESSION_NONE)
    session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'it') {
    header("Location: ../login.php");
    exit;
}

// Alias supaya kode di bawah (hasil adaptasi) tetap konsisten memakai $pdo.
$pdo = $conn;


if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}
require_once "../includes/functions.php";
require_once "../includes/drive_helper.php";
require_once "../includes/dokumen_helper.php";

$page_title = "Manajemen Surat";
$current_user_id = $_SESSION['user_id'];


// ==========================================
// [AJAX] Cari nama perusahaan dari Data_Klien untuk autocomplete
// ==========================================
if (($_GET['ajax'] ?? '') === 'cari_klien') {
    header('Content-Type: application/json');
    $q = trim($_GET['q'] ?? '');
    $hasil = [];
    if (mb_strlen($q) >= 1) {
        $stmtKlien = $pdo->prepare("
            SELECT id, kode_klien, nama_perusahaan, alamat, pic_nama
            FROM Data_Klien
            WHERE nama_perusahaan LIKE ?
            ORDER BY nama_perusahaan ASC
            LIMIT 15
        ");
        $stmtKlien->execute(['%' . $q . '%']);
        $hasil = $stmtKlien->fetchAll(PDO::FETCH_ASSOC);
    }
    echo json_encode($hasil);
    exit;
}

// ==========================================
// [AJAX] Daftar surat Invoice (untuk dropdown "Pilih Invoice Sumber" di
// form Buat Kuitansi).
// ==========================================
if (($_GET['ajax'] ?? '') === 'daftar_invoice') {
    header('Content-Type: application/json');
    echo json_encode(daftarSuratInvoice($pdo));
    exit;
}

// ==========================================
// [AJAX] Ambil data satu Invoice (Nama Perusahaan, Uraian Pembayaran,
// Nomor Pesanan, Grand Total, Terbilang) untuk auto-isi form Buat Kuitansi.
// ==========================================
if (($_GET['ajax'] ?? '') === 'data_invoice_kuitansi') {
    header('Content-Type: application/json');
    $invoiceId = (int) ($_GET['invoice_id'] ?? 0);
    $data = $invoiceId ? muatDataInvoiceUntukKuitansi($pdo, $invoiceId) : null;
    if (!$data) {
        echo json_encode(['error' => 'Invoice tidak ditemukan.']);
        exit;
    }
    echo json_encode($data);
    exit;
}

// ==========================================
// [AJAX] Cek apakah kode surat sudah ada di database.
// ==========================================
if (($_GET['ajax'] ?? '') === 'cek_kode') {
    header('Content-Type: application/json');
    $kodeCek = strtoupper(trim($_GET['kode'] ?? ''));
    $namaCek = trim($_GET['nama'] ?? '');
    $hasil = ['kode_exists' => false, 'combo_exists' => false, 'daftar_nama_lain' => []];
    if ($kodeCek !== '') {
        $stmtSemua = $pdo->prepare("SELECT nama FROM Kode_Surat WHERE kode = ?");
        $stmtSemua->execute([$kodeCek]);
        $semuaNama = $stmtSemua->fetchAll(PDO::FETCH_COLUMN);
        if (!empty($semuaNama)) {
            $hasil['kode_exists'] = true;
            foreach ($semuaNama as $n) {
                if ($namaCek !== '' && strcasecmp($n, $namaCek) === 0) {
                    $hasil['combo_exists'] = true;
                } else {
                    $hasil['daftar_nama_lain'][] = $n;
                }
            }
        }
    }
    echo json_encode($hasil);
    exit;
}

// ==========================================
// [AJAX] Ambil detail template + daftar kode yang terhubung, untuk modal Edit Template
// ==========================================
if (($_GET['ajax'] ?? '') === 'get_template') {
    header('Content-Type: application/json');
    $templateId = (int) ($_GET['id'] ?? 0);

    $stmt = $pdo->prepare("SELECT * FROM Template_Master WHERE id = ?");
    $stmt->execute([$templateId]);
    $tpl = $stmt->fetch();

    if (!$tpl) {
        echo json_encode(['error' => 'Template tidak ditemukan.']);
        exit;
    }

    $stmtKode = $pdo->prepare("
        SELECT k.id AS kode_id, k.kode, k.nama AS nama_kode, kt.id AS kode_template_id, kt.is_default
        FROM Kode_Template kt
        JOIN Kode_Surat k ON k.id = kt.kode_id
        WHERE kt.template_id = ?
        ORDER BY kt.is_default DESC, k.kode ASC
    ");
    $stmtKode->execute([$templateId]);
    $daftarKodeTerhubung = $stmtKode->fetchAll();

    $decoded = $tpl['fields_json'] ? (json_decode($tpl['fields_json'], true) ?: []) : [];

    echo json_encode([
        'template' => [
            'id' => (int) $tpl['id'],
            'nama' => $tpl['nama'],
            'deskripsi' => $tpl['deskripsi'],
            'format' => $tpl['format'],
            'drive_file_id' => $tpl['drive_file_id'],   // ⬅ tambahkan ini
            'drive_link' => $tpl['drive_link'],          // ⬅ tambahkan ini
        ],
        'kode_terhubung' => $daftarKodeTerhubung,
        'fields' => $decoded['fields'] ?? [],
        'table_fields' => $decoded['table_fields'] ?? [],
        'blocks' => $decoded['blocks'] ?? [],
    ]);
    exit;
}

if (!function_exists('buatPdfSederhanaTable')) {
    /**
     * Membuat file PDF berbentuk TABEL RAPI (garis kolom/baris, header
     * berwarna, baris selang-seling) TANPA bergantung pada LibreOffice
     * atau library composer tambahan apa pun.
     */
    function buatPdfSederhanaTable(string $judul, string $subjudul, array $headers, array $colChars, array $rows): string
    {
        $pageWidth = 841.89;   // A4 landscape (pt)
        $pageHeight = 595.28;
        $marginX = 28.0;
        $marginTop = 50.0;
        $marginBottom = 30.0;

        $fontSize = 7.2;
        $fontSizeHeader = 7.2;
        $fontSizeTitle = 13.0;
        $fontSizeSub = 8.5;
        $lineHeight = 9.2;
        $cellPadX = 4.0;
        $cellPadY = 3.0;
        $charWidthFactor = 0.6; // rasio lebar karakter Courier terhadap font size

        $colorHeaderBg = '0.80 0.85 0.92'; // biru keabuan lembut
        $colorAltRowBg = '0.96 0.97 0.98'; // abu sangat muda (baris genap)
        $colorBorder = '0.55 0.58 0.62';
        $colorTextHeader = '0.10 0.14 0.28';
        $colorText = '0.15 0.15 0.15';

        $escape = function (string $s): string {
            $s = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $s);
            $conv = @iconv('UTF-8', 'CP1252//TRANSLIT//IGNORE', $s);
            if ($conv === false) {
                $conv = preg_replace('/[^\x20-\x7E]/', '?', $s);
            }
            return $conv;
        };

        // ----- Lebar kolom proporsional dari bobot $colChars -----
        $usableWidth = $pageWidth - 2 * $marginX;
        $totalBobot = array_sum($colChars) ?: 1;
        $colWidths = [];
        foreach ($colChars as $bobot) {
            $colWidths[] = $usableWidth * ($bobot / $totalBobot);
        }
        $colX = [];
        $xJalan = $marginX;
        foreach ($colWidths as $w) {
            $colX[] = $xJalan;
            $xJalan += $w;
        }
        $tableWidth = array_sum($colWidths);

        // ----- Word-wrap berdasarkan LEBAR kolom (pt), bukan jumlah karakter tetap -----
        $wrapByWidth = function (string $text, float $colWidth, float $fs) use ($charWidthFactor, $cellPadX): array {
            $availWidth = max(14.0, $colWidth - 2 * $cellPadX);
            $maxChars = max(3, (int) floor($availWidth / ($fs * $charWidthFactor)));
            $text = trim(preg_replace('/\s+/', ' ', $text));
            if ($text === '') {
                return [''];
            }
            $words = explode(' ', $text);
            $lines = [];
            $current = '';
            foreach ($words as $word) {
                while (mb_strlen($word) > $maxChars) {
                    if ($current !== '') {
                        $lines[] = $current;
                        $current = '';
                    }
                    $lines[] = mb_substr($word, 0, $maxChars);
                    $word = mb_substr($word, $maxChars);
                }
                $candidate = $current === '' ? $word : $current . ' ' . $word;
                if (mb_strlen($candidate) > $maxChars) {
                    if ($current !== '') {
                        $lines[] = $current;
                    }
                    $current = $word;
                } else {
                    $current = $candidate;
                }
            }
            if ($current !== '') {
                $lines[] = $current;
            }
            return $lines ?: [''];
        };

        $siapkanBaris = function (array $sel, bool $isHeader) use ($wrapByWidth, $colWidths, $fontSize, $fontSizeHeader, $lineHeight, $cellPadY): array {
            $fs = $isHeader ? $fontSizeHeader : $fontSize;
            $wrapped = [];
            foreach (array_values($sel) as $i => $val) {
                $wrapped[$i] = $wrapByWidth((string) $val, $colWidths[$i] ?? 60, $fs);
            }
            $maxLines = max(1, max(array_map('count', $wrapped)));
            $tinggi = $maxLines * $lineHeight + 2 * $cellPadY;
            return ['wrapped' => $wrapped, 'tinggi' => $tinggi, 'isHeader' => $isHeader];
        };

        $baris_header = $siapkanBaris($headers, true);
        $semua_baris_data = [];
        foreach ($rows as $row) {
            $semua_baris_data[] = $siapkanBaris($row, false);
        }

        // ----- Paginasi: susun baris ke dalam beberapa halaman -----
        $usableHeightPertama = $pageHeight - $marginTop - $marginBottom - 34; // dikurangi ruang judul
        $usableHeightLain = $pageHeight - $marginTop - $marginBottom;

        $halaman = [];
        $halIni = [$baris_header];
        $tinggiTersisa = $usableHeightPertama - $baris_header['tinggi'];

        foreach ($semua_baris_data as $br) {
            if ($br['tinggi'] > $tinggiTersisa) {
                $halaman[] = $halIni;
                $halIni = [$baris_header];
                $tinggiTersisa = $usableHeightLain - $baris_header['tinggi'];
            }
            $halIni[] = $br;
            $tinggiTersisa -= $br['tinggi'];
        }
        $halaman[] = $halIni;

        // ----- Bangun content stream per halaman -----
        $contentStreams = [];
        foreach ($halaman as $idxHal => $barisHalaman) {
            $ops = "q\n";
            $yTop = $pageHeight - $marginTop;

            if ($idxHal === 0) {
                $ops .= sprintf("0 0 0 rg\nBT /F2 %.2f Tf %.2f %.2f Td (%s) Tj ET\n", $fontSizeTitle, $marginX, $yTop, $escape($judul));
                $yTop -= 16;
                $ops .= sprintf("0.35 0.35 0.35 rg\nBT /F1 %.2f Tf %.2f %.2f Td (%s) Tj ET\n", $fontSizeSub, $marginX, $yTop, $escape($subjudul));
                $yTop -= 18;
            }

            $tableTopY = $yTop;
            $yCursor = $tableTopY;

            foreach ($barisHalaman as $idxBaris => $br) {
                $tinggiBaris = $br['tinggi'];
                $yBarisAtas = $yCursor;
                $yBarisBawah = $yCursor - $tinggiBaris;

                // Latar belakang baris
                if ($br['isHeader']) {
                    $ops .= sprintf("%s rg\n%.2f %.2f %.2f %.2f re f\n", $colorHeaderBg, $marginX, $yBarisBawah, $tableWidth, $tinggiBaris);
                } elseif ($idxBaris % 2 === 0) {
                    $ops .= sprintf("%s rg\n%.2f %.2f %.2f %.2f re f\n", $colorAltRowBg, $marginX, $yBarisBawah, $tableWidth, $tinggiBaris);
                }

                // Teks per kolom
                $fs = $br['isHeader'] ? $fontSizeHeader : $fontSize;
                $font = $br['isHeader'] ? 'F2' : 'F1';
                $warnaTeks = $br['isHeader'] ? $colorTextHeader : $colorText;
                $ops .= sprintf("%s rg\n", $warnaTeks);
                foreach ($br['wrapped'] as $i => $lines) {
                    $cellX = $colX[$i] + $cellPadX;
                    $lineY = $yBarisAtas - $cellPadY - $fs * 0.85;
                    foreach ($lines as $line) {
                        $ops .= sprintf("BT /%s %.2f Tf %.2f %.2f Td (%s) Tj ET\n", $font, $fs, $cellX, $lineY, $escape($line));
                        $lineY -= $lineHeight;
                    }
                }

                $yCursor = $yBarisBawah;
            }

            $tableBottomY = $yCursor;

            // Garis horizontal (antar baris + batas atas/bawah)
            $ops .= sprintf("%s RG 0.6 w\n", $colorBorder);
            $yGaris = $tableTopY;
            $ops .= sprintf("%.2f %.2f m %.2f %.2f l S\n", $marginX, $yGaris, $marginX + $tableWidth, $yGaris);
            foreach ($barisHalaman as $br) {
                $yGaris -= $br['tinggi'];
                $ops .= sprintf("%.2f %.2f m %.2f %.2f l S\n", $marginX, $yGaris, $marginX + $tableWidth, $yGaris);
            }

            // Garis vertikal (antar kolom + batas kiri/kanan)
            $xGaris = $marginX;
            $ops .= sprintf("%.2f %.2f m %.2f %.2f l S\n", $xGaris, $tableTopY, $xGaris, $tableBottomY);
            foreach ($colWidths as $w) {
                $xGaris += $w;
                $ops .= sprintf("%.2f %.2f m %.2f %.2f l S\n", $xGaris, $tableTopY, $xGaris, $tableBottomY);
            }

            $ops .= "Q\n";
            $contentStreams[] = $ops;
        }

        // ----- Rakit objek PDF -----
        $numPages = count($contentStreams);
        $objCatalog = 1;
        $objPages = 2;
        $firstPageObj = 3;
        $firstContentObj = 3 + $numPages;
        $fontF1Obj = 3 + 2 * $numPages;
        $fontF2Obj = $fontF1Obj + 1;

        $kidsRefs = [];
        for ($i = 0; $i < $numPages; $i++) {
            $kidsRefs[] = ($firstPageObj + $i) . ' 0 R';
        }

        $objects = [];
        $objects[$objCatalog] = "<< /Type /Catalog /Pages {$objPages} 0 R >>";
        $objects[$objPages] = "<< /Type /Pages /Kids [" . implode(' ', $kidsRefs) . "] /Count {$numPages} >>";

        for ($i = 0; $i < $numPages; $i++) {
            $pageNum = $firstPageObj + $i;
            $contentNum = $firstContentObj + $i;
            $objects[$pageNum] = "<< /Type /Page /Parent {$objPages} 0 R /MediaBox [0 0 {$pageWidth} {$pageHeight}] "
                . "/Resources << /Font << /F1 {$fontF1Obj} 0 R /F2 {$fontF2Obj} 0 R >> >> /Contents {$contentNum} 0 R >>";
            $stream = $contentStreams[$i];
            $objects[$contentNum] = "<< /Length " . strlen($stream) . " >>\nstream\n{$stream}endstream";
        }

        $objects[$fontF1Obj] = "<< /Type /Font /Subtype /Type1 /BaseFont /Courier >>";
        $objects[$fontF2Obj] = "<< /Type /Font /Subtype /Type1 /BaseFont /Courier-Bold >>";

        ksort($objects);
        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $num => $body) {
            $offsets[$num] = strlen($pdf);
            $pdf .= "{$num} 0 obj\n{$body}\nendobj\n";
        }
        $xrefStart = strlen($pdf);
        $maxObjNum = max(array_keys($objects));

        $pdf .= "xref\n0 " . ($maxObjNum + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";
        for ($n = 1; $n <= $maxObjNum; $n++) {
            $pdf .= isset($offsets[$n]) ? sprintf("%010d 00000 n \n", $offsets[$n]) : "0000000000 00000 f \n";
        }
        $pdf .= "trailer\n<< /Size " . ($maxObjNum + 1) . " /Root {$objCatalog} 0 R >>\n";
        $pdf .= "startxref\n{$xrefStart}\n%%EOF";

        return $pdf;
    }
}


// ==========================================
// [EXPORT] REKAP SURAT PER PERIODE (BULAN/TAHUN) -> CSV atau PDF
// 1 baris = 1 NOMOR SURAT (family), pakai data dari REVISI TERBARU/FINAL.
// Filter periode memakai TANGGAL SURAT ASLI (bukan tanggal revisi),
// supaya 1 nomor surat tidak dobel hanya karena direvisi di bulan lain.
// ==========================================
if (($_GET['ajax'] ?? '') === 'export_rekap') {
    $bulanFilter = (int) ($_GET['bulan'] ?? 0);     // 0 = semua bulan
    $tahunFilter = (int) ($_GET['tahun'] ?? date('Y'));
    $arahFilter = in_array($_GET['arah'] ?? '', ['Keluar', 'Masuk'], true) ? $_GET['arah'] : 'Semua';
    $formatExport = in_array($_GET['format'] ?? '', ['csv', 'pdf'], true) ? $_GET['format'] : 'csv';

    $where = [];
    $params = [];
    if ($arahFilter !== 'Semua') {
        $where[] = 's.arah = ?';
        $params[] = $arahFilter;
    }
    $sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $stmtRekap = $pdo->prepare("
        SELECT s.*, k.kode AS kode_str, k.nama AS jenis_surat_nama,
               u.nama_lengkap AS pembuat_nama,
               COALESCE(s.induk_surat_id, s.id) AS root_id,
               COALESCE(rootS.tgl_dibuat, s.tgl_dibuat) AS tgl_surat_asli,
               COALESCE(rootS.nomor, s.nomor) AS nomor_family
        FROM Surat s
        JOIN Kode_Surat k ON s.kode_id = k.id
        LEFT JOIN Users u ON s.dibuat_oleh = u.id
        LEFT JOIN Surat rootS ON rootS.id = COALESCE(s.induk_surat_id, s.id)
        {$sqlWhere}
        ORDER BY root_id ASC, s.revisi_ke DESC
    ");
    $stmtRekap->execute($params);
    $semuaBaris = $stmtRekap->fetchAll();

    // Kelompokkan per root_id. Karena sudah diurutkan revisi_ke DESC,
    // baris PERTAMA tiap grup = versi final/terbaru yang berlaku sekarang.
    $family = [];
    foreach ($semuaBaris as $row) {
        $rid = $row['root_id'];
        if (!isset($family[$rid])) {
            $family[$rid] = [
                'final' => $row,
                'jumlah_revisi' => 0,
                'tgl_revisi_terakhir' => ((int) $row['revisi_ke'] > 0) ? $row['tgl_dibuat'] : null,
            ];
        } else {
            $family[$rid]['jumlah_revisi']++;
        }
    }

    // Filter periode berdasarkan tanggal surat ASLI
    $family = array_filter($family, function ($f) use ($bulanFilter, $tahunFilter) {
        $tglAsli = $f['final']['tgl_surat_asli'] ?? null;
        if (!$tglAsli)
            return false;
        $ts = strtotime($tglAsli);
        if ((int) date('Y', $ts) !== $tahunFilter)
            return false;
        if ($bulanFilter > 0 && (int) date('n', $ts) !== $bulanFilter)
            return false;
        return true;
    });

    usort($family, fn($a, $b) => strtotime($a['final']['tgl_surat_asli']) <=> strtotime($b['final']['tgl_surat_asli']));

    $namaBulanIndo = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
    $labelPeriode = ($bulanFilter > 0 ? $namaBulanIndo[$bulanFilter] . ' ' : 'Semua Bulan ') . $tahunFilter;
    $labelPeriodeFile = ($bulanFilter > 0 ? $namaBulanIndo[$bulanFilter] . '_' : '') . $tahunFilter;
    $namaFileDasar = 'Rekap_Surat_' . ($arahFilter !== 'Semua' ? $arahFilter . '_' : '') . $labelPeriodeFile;

    // ===================== FORMAT: CSV =====================
    if ($formatExport === 'csv') {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $namaFileDasar . '.csv"');

        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF"); // BOM supaya Excel baca UTF-8 dengan benar

        fputcsv($out, [
            'No',
            'Nomor Surat',
            'Tanggal Surat',
            'Arah',
            'Jenis Surat',
            'Perihal',
            'Tujuan/Pengirim',
            'Dibuat Oleh',
            'Status Saat Ini',
            'Jumlah Revisi',
            'Tanggal Revisi Terakhir',
            'Link File Final'
        ], ';');

        $no = 1;
        foreach ($family as $f) {
            $s = $f['final'];
            fputcsv($out, [
                $no++,
                $s['nomor_family'],
                !empty($s['tgl_surat_asli']) ? date('d-m-Y', strtotime($s['tgl_surat_asli'])) : '-',
                $s['arah'],
                $s['jenis_surat_nama'] . ' (' . $s['kode_str'] . ')',
                $s['perihal'],
                $s['tujuan'],
                $s['pembuat_nama'] ?? '-',
                $s['status'],
                $f['jumlah_revisi'],
                $f['tgl_revisi_terakhir'] ? date('d-m-Y', strtotime($f['tgl_revisi_terakhir'])) : '-',
                $s['drive_link'] ?? $s['file_hasil'] ?? '-',
            ], ';');
        }

        fclose($out);
        exit;
    }

    // ===================== FORMAT: PDF (dibuat langsung, TANPA LibreOffice) =====================
    $headersPdf = ['No', 'Nomor Surat', 'Tanggal', 'Arah', 'Jenis Surat', 'Perihal', 'Tujuan/Pengirim', 'Dibuat Oleh', 'Status', 'Jml Revisi', 'Revisi Terakhir'];
    $colCharsPdf = [3, 20, 10, 7, 20, 32, 24, 16, 14, 5, 10];

    $rowsPdf = [];
    $noPdf = 1;
    foreach ($family as $f) {
        $s = $f['final'];
        $rowsPdf[] = [
            (string) $noPdf++,
            (string) $s['nomor_family'],
            !empty($s['tgl_surat_asli']) ? date('d-m-Y', strtotime($s['tgl_surat_asli'])) : '-',
            (string) $s['arah'],
            $s['jenis_surat_nama'] . ' (' . $s['kode_str'] . ')',
            (string) $s['perihal'],
            (string) $s['tujuan'],
            (string) ($s['pembuat_nama'] ?? '-'),
            (string) $s['status'],
            (string) $f['jumlah_revisi'],
            $f['tgl_revisi_terakhir'] ? date('d-m-Y', strtotime($f['tgl_revisi_terakhir'])) : '-',
        ];
    }
    if (empty($rowsPdf)) {
        $rowsPdf[] = ['-', 'Tidak ada data surat pada periode ini.', '', '', '', '', '', '', '', '', ''];
    }

    $judulArahPdf = $arahFilter === 'Semua' ? 'Surat Masuk & Keluar' : 'Surat ' . $arahFilter;
    $pdfBytes = buatPdfSederhanaTable(
        'Rekap ' . $judulArahPdf,
        'Periode: ' . $labelPeriode . '  |  Dicetak: ' . date('d-m-Y H:i'),
        $headersPdf,
        $colCharsPdf,
        $rowsPdf
    );

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $namaFileDasar . '.pdf"');
    header('Content-Length: ' . strlen($pdfBytes));
    echo $pdfBytes;
    exit;
}

if (!function_exists('slugifyNamaTemplate')) {
    function slugifyNamaTemplate(string $nama): string
    {
        $nama = trim($nama);
        if (function_exists('iconv')) {
            $hasil = @iconv('UTF-8', 'ASCII//TRANSLIT', $nama);
            if ($hasil !== false) {
                $nama = $hasil;
            }
        }
        $nama = strtolower($nama);
        $nama = preg_replace('/[^a-z0-9]+/', '-', $nama);
        $nama = trim($nama, '-');
        return $nama !== '' ? $nama : 'template';
    }
}

// ==========================================
// Helper: cek nama kolom tabel item (harga / qty)
// ==========================================
if (!function_exists('isKolomHarga')) {
    function isKolomHarga(string $namaKolom): bool
    {
        return (bool) preg_match('/harga/i', $namaKolom);
    }
}
if (!function_exists('isKolomQty')) {
    function isKolomQty(string $namaKolom): bool
    {
        return (bool) preg_match('/qty|jumlah/i', $namaKolom);
    }
}

const STATUS_OPSI_KELUAR = ['Draft', 'Menunggu Persetujuan', 'Disetujui', 'Ditolak', 'Terkirim', 'Diarsipkan'];
const STATUS_OPSI_MASUK = ['Baru', 'Diproses', 'Didisposisi', 'Selesai', 'Diarsipkan'];

// Tab aktif (dipetakan ke id panel arp-tab-panel) — IT/Ahli K3 punya tab
// Surat, Surat Masuk (read-only), & Buat Surat.
$tabMap = ['surat' => 'tabPanelSurat', 'masuk' => 'tabPanelSuratMasuk', 'buat' => 'tabPanelBuatSurat', 'template' => 'tabPanelTemplate'];
$tabGet = $_GET['tab'] ?? 'surat';
$active_tab = $tabMap[$tabGet] ?? 'tabPanelSurat';

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

function suratRedirect(string $tab, array $extraQuery = []): void
{
    $query = array_merge(['tab' => $tab], $extraQuery);
    header('Location: surat.php?' . http_build_query($query));
    exit;
}

// ==========================================
// [TAB: UPLOAD TEMPLATE] UPLOAD TEMPLATE MASTER + TENTUKAN KODE SURAT
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aksi'] ?? '') === 'upload_template') {
    try {
        $pdo->beginTransaction();

        $fileUpload = $_FILES['file_template'];
        $ext = strtolower(pathinfo($fileUpload['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['docx', 'pdf'], true)) {
            throw new RuntimeException("Format file tidak didukung. Hanya .docx atau .pdf.");
        }
        if ($fileUpload['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException("Terjadi kesalahan saat upload file.");
        }
        $format = $ext === 'docx' ? 'word_pdf' : 'pdf_only';

        $namaTemplateInput = trim($_POST['nama_template'] ?? '');
        $slugNamaTemplate = slugifyNamaTemplate($namaTemplateInput);
        $namaFileDrive = $slugNamaTemplate . '.' . $ext;

        $hasilScan = ['fields' => [], 'table_fields' => [], 'blocks' => []];
        if ($format === 'word_pdf') {
            $hasilScan = scanPlaceholdersFromDocx($fileUpload['tmp_name']);
        }
        $fieldsJson = json_encode(buildFieldsWithDefaultLabels($hasilScan), JSON_UNESCAPED_UNICODE);

        $hasilDriveTemplate = arp_upload_ke_drive(
            $fileUpload['tmp_name'],
            $namaFileDrive,
            $ext === 'docx'
            ? 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
            : 'application/pdf',
            0,
            'Template_Surat'
        );
        if (!$hasilDriveTemplate || empty($hasilDriveTemplate['link'])) {
            throw new RuntimeException("Gagal mengunggah template ke Google Drive: " . arp_drive_last_error());
        }

        $deskripsiTemplateInput = trim($_POST['deskripsi'] ?? '');

        $stmt = $pdo->prepare("INSERT INTO Template_Master (nama, deskripsi, file_path, drive_file_id, drive_link, format, fields_json, diupload_oleh) VALUES (?, ?, NULL, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $namaTemplateInput,
            $deskripsiTemplateInput !== '' ? $deskripsiTemplateInput : null,
            $hasilDriveTemplate['file_id'],
            $hasilDriveTemplate['link'],
            $format,
            $fieldsJson,
            $current_user_id
        ]);
        $templateId = (int) $pdo->lastInsertId();

        $kodeInput = strtoupper(trim($_POST['kode'] ?? ''));
        if ($kodeInput === '') {
            throw new RuntimeException("Kode surat wajib diisi.");
        }
        $namaKodeInput = trim($_POST['nama_kode'] ?? '');
        $namaKodeFinal = $namaKodeInput !== '' ? $namaKodeInput : $kodeInput;

        $stmtCekKode = $pdo->prepare("SELECT id FROM Kode_Surat WHERE kode = ? AND nama = ?");
        $stmtCekKode->execute([$kodeInput, $namaKodeFinal]);
        $kodeId = (int) $stmtCekKode->fetchColumn();

        if (!$kodeId) {
            $stmtKode = $pdo->prepare("INSERT INTO Kode_Surat (kode, nama) VALUES (?, ?)");
            $stmtKode->execute([$kodeInput, $namaKodeFinal]);
            $kodeId = (int) $pdo->lastInsertId();
        }

        $cek = $pdo->prepare("SELECT COUNT(*) FROM Kode_Template WHERE kode_id = ?");
        $cek->execute([$kodeId]);
        $jadikanDefault = ((int) $cek->fetchColumn() === 0) ? 1 : 0;

        $stmtHubung = $pdo->prepare("INSERT INTO Kode_Template (kode_id, template_id, is_default) VALUES (?, ?, ?)");
        $stmtHubung->execute([$kodeId, $templateId, $jadikanDefault]);

        $pdo->commit();

        catatAudit(
            $pdo,
            'Surat',
            'Upload Template',
            "Mengupload template \"{$namaTemplateInput}\" (kode {$kodeInput})",
            null,
            ['nama' => $namaTemplateInput, 'kode' => $kodeInput, 'format' => $format]
        );

        $ringkasanField = [];
        if (!empty($hasilScan['fields'])) {
            $ringkasanField[] = count($hasilScan['fields']) . ' field: ' . implode(', ', $hasilScan['fields']);
        }
        if (!empty($hasilScan['table_fields'])) {
            $ringkasanField[] = 'tabel item dengan kolom: ' . implode(', ', $hasilScan['table_fields']);
        }
        if (!empty($hasilScan['blocks'])) {
            $ringkasanField[] = 'blok list berulang: ' . implode(', ', array_keys($hasilScan['blocks']));
        }
        $pesanField = $ringkasanField ? implode(' | ', $ringkasanField) : 'Tidak ada placeholder ${...} yang terdeteksi.';

        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Template berhasil diupload ke Google Drive dan langsung dihubungkan ke kode surat. ' . $pesanField];
    } catch (Throwable $e) {
        if ($pdo->inTransaction())
            $pdo->rollBack();
        $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Gagal menyimpan template: ' . $e->getMessage()];
    }
    suratRedirect('template');
}

// ==========================================
// [TAB: UPLOAD TEMPLATE] HAPUS TEMPLATE MASTER
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aksi'] ?? '') === 'hapus_template') {
    try {
        $templateId = (int) $_POST['template_id'];

        $stmt = $pdo->prepare("SELECT * FROM Template_Master WHERE id = ?");
        $stmt->execute([$templateId]);
        $tpl = $stmt->fetch();
        if (!$tpl) {
            throw new RuntimeException("Template tidak ditemukan.");
        }

        $cekDipakai = $pdo->prepare("SELECT COUNT(*) FROM Surat WHERE template_id = ?");
        $cekDipakai->execute([$templateId]);
        if ((int) $cekDipakai->fetchColumn() > 0) {
            throw new RuntimeException("Template \"{$tpl['nama']}\" sudah pernah dipakai untuk membuat surat, tidak bisa dihapus.");
        }

        $pdo->beginTransaction();
        $pdo->prepare("DELETE FROM Kode_Template WHERE template_id = ?")->execute([$templateId]);
        $pdo->prepare("DELETE FROM Template_Master WHERE id = ?")->execute([$templateId]);
        $pdo->commit();

        catatAudit($pdo, 'Surat', 'Hapus Template', "Menghapus template \"{$tpl['nama']}\" (#{$templateId})", $tpl, null);

        if (!empty($tpl['drive_file_id'])) {
            arp_hapus_file_drive($tpl['drive_file_id']);
        }

        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Template "' . $tpl['nama'] . '" beserta file & koneksinya berhasil dihapus.'];
    } catch (Throwable $e) {
        if ($pdo->inTransaction())
            $pdo->rollBack();
        $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Gagal menghapus template: ' . $e->getMessage()];
    }
    suratRedirect('template');
}

// ==========================================
// [TAB: UPLOAD TEMPLATE] EDIT TEMPLATE
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aksi'] ?? '') === 'edit_template') {
    try {
        $templateId = (int) ($_POST['template_id'] ?? 0);
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("SELECT * FROM Template_Master WHERE id = ?");
        $stmt->execute([$templateId]);
        $tpl = $stmt->fetch();
        if (!$tpl) {
            throw new RuntimeException("Template tidak ditemukan.");
        }

        $namaTemplateBaru = trim($_POST['nama_template'] ?? '');
        if ($namaTemplateBaru === '') {
            throw new RuntimeException("Nama template wajib diisi.");
        }
        $deskripsiBaru = trim($_POST['deskripsi'] ?? '');

        $pdo->prepare("UPDATE Template_Master SET nama = ?, deskripsi = ? WHERE id = ?")
            ->execute([$namaTemplateBaru, $deskripsiBaru !== '' ? $deskripsiBaru : null, $templateId]);

        $mappingLama = $_POST['field_lama'] ?? [];
        $mappingBaru = $_POST['field_baru'] ?? [];
        $mappingLabel = $_POST['field_label'] ?? [];

        if ($tpl['format'] === 'word_pdf' && !empty($tpl['drive_file_id'])) {
            arp_dengan_template_sementara($tpl['drive_file_id'], function ($fullPath, $mimeType) use ($pdo, $templateId, $mappingLama, $mappingBaru, $mappingLabel, $tpl) {
                $penggantianPlaceholder = [];
                foreach ($mappingLama as $i => $namaLama) {
                    $namaLama = trim((string) $namaLama);
                    $namaBaru = trim((string) ($mappingBaru[$i] ?? ''));
                    if ($namaLama === '' || $namaBaru === '' || $namaLama === $namaBaru) {
                        continue;
                    }
                    if (!preg_match('/^[a-zA-Z0-9_]+$/', $namaBaru)) {
                        throw new RuntimeException("Nama field \"{$namaBaru}\" tidak valid. Hanya boleh huruf, angka, dan underscore (_), tanpa spasi.");
                    }
                    $penggantianPlaceholder[$namaLama] = $namaBaru;
                }

                if (!empty($penggantianPlaceholder)) {
                    renamePlaceholdersInDocx($fullPath, $penggantianPlaceholder);
                }

                $hasilScanBaru = scanPlaceholdersFromDocx($fullPath);
                $fieldsBaruDenganLabel = buildFieldsWithDefaultLabels($hasilScanBaru);

                $petaLabelBaru = [];
                foreach ($mappingBaru as $i => $namaBaru) {
                    $namaBaru = trim((string) $namaBaru);
                    $labelBaru = trim((string) ($mappingLabel[$i] ?? ''));
                    if ($namaBaru !== '' && $labelBaru !== '') {
                        $petaLabelBaru[$namaBaru] = $labelBaru;
                    }
                }
                foreach ($fieldsBaruDenganLabel['fields'] as &$f) {
                    if (isset($petaLabelBaru[$f['field']])) {
                        $f['label'] = $petaLabelBaru[$f['field']];
                    }
                }
                unset($f);
                foreach ($fieldsBaruDenganLabel['table_fields'] as &$f) {
                    if (isset($petaLabelBaru[$f['field']])) {
                        $f['label'] = $petaLabelBaru[$f['field']];
                    }
                }
                unset($f);

                $pdo->prepare("UPDATE Template_Master SET fields_json = ? WHERE id = ?")
                    ->execute([json_encode($fieldsBaruDenganLabel, JSON_UNESCAPED_UNICODE), $templateId]);

                if (!empty($penggantianPlaceholder)) {
                    $berhasil = arp_timpa_konten_drive(
                        $tpl['drive_file_id'],
                        $fullPath,
                        'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
                    );
                    if (!$berhasil) {
                        throw new RuntimeException(arp_drive_last_error());
                    }
                }
            });
        }

        $kodeTemplateIds = $_POST['kode_template_id'] ?? [];
        $kodeBaruList = $_POST['kode_baru'] ?? [];
        $namaKodeBaruList = $_POST['nama_kode_baru'] ?? [];

        foreach ($kodeTemplateIds as $i => $kodeTemplateId) {
            $kodeTemplateId = (int) $kodeTemplateId;
            $kodeBaru = strtoupper(trim((string) ($kodeBaruList[$i] ?? '')));
            $namaKodeBaru = trim((string) ($namaKodeBaruList[$i] ?? ''));

            if ($kodeBaru === '' || $namaKodeBaru === '') {
                continue;
            }

            $stmtKodeId = $pdo->prepare("SELECT kode_id FROM Kode_Template WHERE id = ? AND template_id = ?");
            $stmtKodeId->execute([$kodeTemplateId, $templateId]);
            $kodeId = (int) $stmtKodeId->fetchColumn();
            if (!$kodeId) {
                continue;
            }

            $cekBentrok = $pdo->prepare("SELECT id FROM Kode_Surat WHERE kode = ? AND nama = ? AND id != ?");
            $cekBentrok->execute([$kodeBaru, $namaKodeBaru, $kodeId]);
            if ($cekBentrok->fetch()) {
                throw new RuntimeException("Kombinasi kode \"{$kodeBaru}\" & jenis surat \"{$namaKodeBaru}\" sudah dipakai baris lain.");
            }

            $pdo->prepare("UPDATE Kode_Surat SET kode = ?, nama = ? WHERE id = ?")
                ->execute([$kodeBaru, $namaKodeBaru, $kodeId]);
        }

        $pdo->commit();
        catatAudit(
            $pdo,
            'Surat',
            'Ubah Template',
            "Mengubah template \"{$namaTemplateBaru}\" (#{$templateId})",
            $tpl,
            ['nama' => $namaTemplateBaru, 'deskripsi' => $deskripsiBaru]
        );
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Template "' . $namaTemplateBaru . '" berhasil diperbarui.'];
    } catch (Throwable $e) {
        if ($pdo->inTransaction())
            $pdo->rollBack();
        $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Gagal memperbarui template: ' . $e->getMessage()];
    }
    suratRedirect('template');
}

// ==========================================
// [TAB: BUAT SURAT] GENERATE SURAT DARI TEMPLATE
// ==========================================
$errorGenerateSurat = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aksi'] ?? '') === 'generate_surat') {
    $active_tab = 'tabPanelBuatSurat';
    $isPreviewOnly = ($_POST['preview_only'] ?? '') === '1';

    if (!$isPreviewOnly) {
        $kodeIdPost = (int) ($_POST['kode_id'] ?? 0);
        $templateIdPost = (int) ($_POST['template_id'] ?? 0);
        $kodeReimburseCek = arp_muat_template_reimburse($pdo);
        if ($kodeReimburseCek && (int) $kodeReimburseCek['id'] === $kodeIdPost) {
            $hasilReim = arp_proses_pengajuan_reimburse(
                $pdo,
                $kodeReimburseCek,
                $current_user_id,
                $_POST['dinamis'] ?? [],
                $_POST['items'] ?? [],
                $_POST['no_urut_manual'] ?? null
            );
            $_SESSION['flash'] = $hasilReim['ok']
                ? ['type' => 'success', 'msg' => $hasilReim['msg']]
                : ['type' => 'error', 'msg' => $hasilReim['msg']];
            header('Location: reimburse.php');
            exit;
        }
        try {
            $stmt = $pdo->prepare("SELECT k.*, t.id AS template_id, t.drive_file_id, t.format
                                    FROM Kode_Surat k
                                    JOIN Kode_Template kt ON kt.kode_id = k.id AND kt.template_id = ?
                                    JOIN Template_Master t ON t.id = kt.template_id
                                    WHERE k.id = ?");
            $stmt->execute([$templateIdPost, $kodeIdPost]);
            $kode = $stmt->fetch();

            if (!$kode || !$kode['drive_file_id']) {
                throw new RuntimeException("Kombinasi jenis surat & template ini tidak/belum terhubung ke Google Drive.");
            }
            if ($kode['format'] !== 'word_pdf') {
                throw new RuntimeException("Template ini bukan file Word (.docx), tidak bisa digenerate otomatis lewat form ini.");
            }

            $pdo->beginTransaction();

            // Kalau template pakai ${no_surat} (format khusus JD+kode/bulan/tahun),
            // pakai format itu SEBAGAI nomor surat -- jangan generate nomor ARP
            // biasa juga, supaya tidak ada dua sistem penomoran berjalan sekaligus.
            $adaNoSuratKhususPost = arp_dengan_template_sementara($kode['drive_file_id'], function ($p) {
                return in_array('no_surat', scanAutoFieldsFromDocx($p), true);
            });

            // Kalau surat ini dibuat dari Invoice (Kuitansi otomatis), nomor urutnya
            // WAJIB mengikuti nomor urut invoice sumbernya -- bukan memakai counter
            // otomatis milik kode Kuitansi sendiri. Data invoice diambil di sini
            // (sebelum nomor dihitung) supaya nomornya bisa dipakai langsung.
            $invoiceSumberId = (int) ($_POST['invoice_sumber_id'] ?? 0);
            $ikutiNomorInvoice = isset($_POST['ikuti_nomor_invoice']);
            $dataInvoiceSumber = null;
            if ($invoiceSumberId > 0) {
                $dataInvoiceSumber = muatDataInvoiceUntukKuitansi($pdo, $invoiceSumberId);
                if (!$dataInvoiceSumber) {
                    throw new RuntimeException("Invoice sumber tidak ditemukan / tidak valid.");
                }
            }

            if ($adaNoSuratKhususPost) {
                $noSuratManualPost = trim($_POST['no_surat_manual'] ?? '');
                $nomorSurat = buatNoSuratKhusus($noSuratManualPost);
                $cekNomorDup = $pdo->prepare("SELECT id FROM Surat WHERE nomor = ?");
                $cekNomorDup->execute([$nomorSurat]);
                if ($cekNomorDup->fetch()) {
                    throw new RuntimeException("Nomor surat \"{$nomorSurat}\" sudah digunakan surat lain.");
                }
            } elseif ($dataInvoiceSumber && $ikutiNomorInvoice) {
                // HANYA jalan kalau kotak "Nomor mengikuti invoice" dicentang.
                // Surat lain yang butuh data invoice tapi punya nomor sendiri TIDAK masuk sini.
                $noUrutDariInvoice = explode('/', $dataInvoiceSumber['nomor_invoice'])[0] ?? '';
                if (!ctype_digit($noUrutDariInvoice)) {
                    $noUrutDariInvoice = '';
                }
                $nomorSurat = resolveNomorSurat($pdo, $kodeIdPost, $noUrutDariInvoice);
            } else {
                $noUrutManualPost = trim($_POST['no_urut_manual'] ?? '');
                $nomorSurat = resolveNomorSurat($pdo, $kodeIdPost, $noUrutManualPost);
            }
            $nomorAgenda = generateNomorAgenda($pdo, 'Keluar');

            $dataFormMentah = [];   // disimpan ke isi_data (tetap YYYY-MM-DD, aman untuk <input type="date">)
            $dataForm = [];         // dipakai untuk generate docx (tanggal sudah diformat Indonesia)
            foreach ($_POST['dinamis'] ?? [] as $fieldName => $fieldValue) {
                $fieldValue = trim((string) $fieldValue);
                $dataFormMentah[$fieldName] = $fieldValue;
                if (preg_match('/tanggal|tgl/i', $fieldName) && $fieldValue !== '') {
                    $fieldValue = formatTanggalIndonesia($fieldValue);
                }
                $dataForm[$fieldName] = $fieldValue;
            }

            // Nilai diskon nominal manual dari form (dikirim terpisah, bukan lewat dinamis[])
            if (isset($_POST['diskon_input'])) {
                $dataForm['diskon_input'] = trim((string) $_POST['diskon_input']);
            }
            if (isset($_POST['dp_input'])) {
                $dataForm['dp_input'] = trim((string) $_POST['dp_input']);
            }
            if (isset($_POST['no_surat_manual'])) {          // ⬅ BARU
                $dataForm['no_surat_manual'] = trim((string) $_POST['no_surat_manual']);
            }

            $items = [];
            foreach ($_POST['items'] ?? [] as $baris) {
                $baris = array_map('trim', (array) $baris);
                $adaIsi = false;
                foreach ($baris as $v) {
                    if ($v !== '') {
                        $adaIsi = true;
                        break;
                    }
                }
                if ($adaIsi) {
                    $items[] = $baris;
                }
            }

            $blocksData = [];
            foreach ($_POST['blok'] ?? [] as $namaBlok => $barisList) {
                foreach ((array) $barisList as $baris) {
                    $baris = array_map('trim', (array) $baris);
                    $adaIsi = false;
                    foreach ($baris as $v) {
                        if ($v !== '') {
                            $adaIsi = true;
                            break;
                        }
                    }
                    if ($adaIsi) {
                        $blocksData[$namaBlok][] = $baris;
                    }
                }
            }

            // ==========================================
            // FIELD ${invoice_...} DARI INVOICE SUMBER -- generik untuk semua
            // jenis surat. Tidak menimpa field milik surat itu sendiri (nama
            // field beda: invoice_grand_total vs grand_total), sehingga surat
            // yang punya tabel item + total sendiri tetap bisa dihitung normal
            // berdampingan dengan data invoice ini.
            // ==========================================
            if ($dataInvoiceSumber) {
                $fieldInvoice = mapFieldInvoiceKeTemplate($dataInvoiceSumber);
                $dataForm = array_merge($dataForm, $fieldInvoice);
                $dataFormMentah = array_merge($dataFormMentah, $fieldInvoice);
                $dataFormMentah['invoice_sumber_id'] = $invoiceSumberId;
                $dataFormMentah['invoice_sumber_nomor'] = $dataInvoiceSumber['nomor_invoice'];
                $dataFormMentah['ikuti_nomor_invoice'] = $ikutiNomorInvoice ? '1' : '0';
            }

            $ringkasanDisertakan = [
                'ppn' => isset($_POST['sertakan_ppn']),
                'pph_23' => isset($_POST['sertakan_pph23']),
                'diskon' => isset($_POST['sertakan_diskon']),
                'grand_total' => isset($_POST['sertakan_grand_total']),
                'dp' => isset($_POST['sertakan_dp']),
                'total_bayar' => isset($_POST['sertakan_total_bayar']),
                'sisa_pelunasan' => isset($_POST['sertakan_sisa_pelunasan']),
            ];


            $fileHasilRelatif = arp_dengan_template_sementara($kode['drive_file_id'], function ($pathTemplateLokal) use ($dataForm, $items, $nomorSurat, $blocksData, $kode, $ringkasanDisertakan) {
                return generateSuratDocx($pathTemplateLokal, $dataForm, $items, $nomorSurat, $blocksData, $kode['nama'], null, $ringkasanDisertakan);
            });

            // Baca perihal SEBELUM upload, karena file lokal akan dihapus setelahnya.
            $perihalDariWord = extractPerihalFromDocxText(BASE_PATH . '/' . $fileHasilRelatif);

            $hasilDriveKeluar = arp_upload_ke_drive(
                BASE_PATH . '/' . $fileHasilRelatif,
                basename($fileHasilRelatif),
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                0,
                'Surat_Keluar'
            );

            if (!$hasilDriveKeluar || empty($hasilDriveKeluar['link'])) {
                // TIDAK fallback ke storage lokal -- surat wajib ada di Drive
                // supaya bisa dilihat semua orang, bukan cuma dari server ini.
                if (is_file(BASE_PATH . '/' . $fileHasilRelatif)) {
                    @unlink(BASE_PATH . '/' . $fileHasilRelatif);
                }
                throw new RuntimeException("Gagal mengunggah surat ke Google Drive: " . arp_drive_last_error());
            }

            $fileHasilTersimpan = $hasilDriveKeluar['link'];

            // Sudah aman di Drive -- hapus salinan lokal supaya tidak menumpuk.
            if (is_file(BASE_PATH . '/' . $fileHasilRelatif)) {
                @unlink(BASE_PATH . '/' . $fileHasilRelatif);
            }

            $perihalSimpan = $perihalDariWord
                ?? $dataForm['perihal']
                ?? ($_POST['perihal'] ?? null)
                ?? ($dataInvoiceSumber['perihal_invoice'] ?? null)
                ?? ($kode['nama'] ?? '-');
            $tujuanSimpan = $dataForm['instansi_tujuan']
                ?? $dataForm['tujuan']
                ?? $dataForm['nama_perusahaan']
                ?? $dataForm['nama_perusahaan_tujuan']
                ?? $dataForm['item_nama_perusahaan']
                ?? $dataForm['nama_perusahaan_pihak_pertama']
                ?? ($dataInvoiceSumber['nama_perusahaan'] ?? null)
                ?? '-';

            $statusInput = trim($_POST['status'] ?? '') ?: 'Draft';

            $isiDataDisimpan = $dataFormMentah;
            if (!empty($items)) {
                $isiDataDisimpan['__items'] = $items;
            }
            if (!empty($blocksData)) {
                $isiDataDisimpan['__blok'] = $blocksData;
            }
            $isiDataDisimpan['__ringkasan'] = $ringkasanDisertakan;


            $insert = $pdo->prepare("INSERT INTO Surat
                (nomor_agenda, nomor, kode_id, template_id, perihal, status, arah, tujuan, dibuat_oleh, tgl_dibuat, tanggal_diterima, file_hasil, drive_file_id, drive_link, isi_data)
                VALUES (?, ?, ?, ?, ?, ?, 'Keluar', ?, ?, CURDATE(), NULL, ?, ?, ?, ?)");
            $insert->execute([
                $nomorAgenda,
                $nomorSurat,
                $kodeIdPost,
                $templateIdPost,
                $perihalSimpan,
                $statusInput,
                $tujuanSimpan,
                $current_user_id,
                $fileHasilTersimpan,
                $hasilDriveKeluar['file_id'] ?? null,
                $fileHasilTersimpan,
                json_encode($isiDataDisimpan, JSON_UNESCAPED_UNICODE),
            ]);
            $suratIdBaru = (int) $pdo->lastInsertId();

            // Arsipkan ke Dokumen Digital supaya otomatis muncul di halaman Digital Sign.
            arp_arsipkan_dokumen($pdo, [
                'nama_dokumen' => $nomorSurat . ' - ' . $perihalSimpan,
                'kategori' => 'Lainnya',
                'file_path' => $fileHasilTersimpan,
                'drive_file_id' => $hasilDriveKeluar['file_id'] ?? null,
                'drive_link' => $fileHasilTersimpan,
                'modul_sumber' => 'Surat Keluar',
                'ref_id' => $suratIdBaru,
                'visibilitas' => 'Internal',
                'diupload_oleh' => $current_user_id,
            ]);

            $pdo->commit();
            catatAudit(
                $pdo,
                'Surat',
                'Buat Surat',
                "Membuat surat {$nomorSurat} (agenda {$nomorAgenda}) - {$perihalSimpan}",
                null,
                ['nomor' => $nomorSurat, 'perihal' => $perihalSimpan, 'tujuan' => $tujuanSimpan, 'status' => $statusInput]
            );
            $_SESSION['flash'] = ['type' => 'success', 'msg' => "Surat berhasil dibuat dengan nomor {$nomorSurat} (agenda {$nomorAgenda})."];
            suratRedirect('surat');
        } catch (Throwable $e) {
            if ($pdo->inTransaction())
                $pdo->rollBack();
            $errorGenerateSurat = 'Gagal membuat surat: ' . $e->getMessage();
        }
    }
}

// ==========================================
// [TAB: SURAT] AJUKAN / PROSES APPROVAL SURAT KELUAR
// Status Disetujui/Ditolak mengikuti hasil approval di tabel Approval,
// bukan dipilih manual — konsisten dengan modul approval lain di sistem.
// ==========================================

// ----- Ajukan surat untuk disetujui (Draft -> Menunggu Persetujuan) -----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aksi'] ?? '') === 'ajukan_approval_surat') {
    try {
        $suratId = (int) $_POST['surat_id'];

        $pdo->beginTransaction();

        $cek = $pdo->prepare("SELECT * FROM Surat WHERE id = ? FOR UPDATE");
        $cek->execute([$suratId]);
        $surat = $cek->fetch();

        if (!$surat) {
            throw new RuntimeException("Surat tidak ditemukan.");
        }
        if ((int) $surat['dibuat_oleh'] !== (int) $current_user_id) {
            throw new RuntimeException("Anda hanya bisa mengelola surat yang Anda buat sendiri.");
        }
        if ($surat['arah'] !== 'Keluar') {
            throw new RuntimeException("Hanya surat keluar yang melalui alur persetujuan.");
        }
        if ($surat['status'] !== 'Draft') {
            throw new RuntimeException("Surat ini sudah diajukan/diproses sebelumnya (" . $surat['status'] . ").");
        }

        $pdo->prepare("UPDATE Surat  SET status = 'Menunggu Persetujuan' WHERE id = ?")->execute([$suratId]);

        $insertApproval = $pdo->prepare("
            INSERT INTO Approval (jenis_pengajuan, ref_id, requester_id, level, status)
            VALUES ('Surat', :ref_id, :requester_id, 1, 'Menunggu')
        ");
        $insertApproval->execute([
            ':ref_id' => $suratId,
            ':requester_id' => $surat['dibuat_oleh'],
        ]);

        // Ambil nama pembuat surat supaya notifikasi ke direksi jelas siapa pengajunya
        $stmtNamaPembuatSurat = $pdo->prepare("SELECT nama_lengkap FROM Users WHERE id = ?");
        $stmtNamaPembuatSurat->execute([$surat['dibuat_oleh']]);
        $namaPembuatSurat = $stmtNamaPembuatSurat->fetchColumn() ?: 'Tidak diketahui';

        $stmtDireksiSurat = $pdo->prepare("SELECT id FROM Users WHERE role = 'direksi' OR role = 'admin'");
        $stmtDireksiSurat->execute();
        foreach ($stmtDireksiSurat->fetchAll(PDO::FETCH_COLUMN) as $direksi_id_notif) {
            kirimNotifikasi(
                $pdo,
                (int) $direksi_id_notif,
                'Surat Menunggu Persetujuan',
                "Surat \"{$surat['perihal']}\" dari {$namaPembuatSurat} menunggu persetujuan Anda.",
                'surat',
                (int) $suratId
            );
        }

        // ================== EMAIL KE DIREKSI / ADMIN (APPROVER) ==================
        $emailApprovalSurat = array_unique(array_merge(
            getEmailByRole($pdo, 'direksi'),
            getEmailByRole($pdo, 'admin')
        ));
        if (!empty($emailApprovalSurat)) {
            $bodyApprovalSurat = templateEmailNotifikasi(
                'Surat Menunggu Persetujuan',
                "Surat \"{$surat['perihal']}\" dari {$namaPembuatSurat} menunggu persetujuan Anda.",
                ['Nomor Surat' => $surat['nomor'] ?? '-', 'Perihal' => $surat['perihal']],
            );
            kirimEmail($emailApprovalSurat, 'Surat Menunggu Persetujuan dari ' . $namaPembuatSurat, $bodyApprovalSurat);
        }

        $pdo->commit();
        catatAudit($pdo, 'Surat', 'Ajukan Approval', "Mengajukan surat #{$suratId} untuk persetujuan", ['status' => $surat['status']], ['status' => 'Menunggu Persetujuan']);
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Surat berhasil diajukan untuk persetujuan.'];
    } catch (Throwable $e) {
        if ($pdo->inTransaction())
            $pdo->rollBack();
        $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Gagal mengajukan persetujuan: ' . $e->getMessage()];
    }
    suratRedirect('surat');
}

// ----- Revisi surat yang ditolak (Ditolak -> Draft) -----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aksi'] ?? '') === 'revisi_surat') {
    try {
        $suratId = (int) $_POST['surat_id'];
        $cek = $pdo->prepare("SELECT status, dibuat_oleh FROM Surat WHERE id = ?");
        $cek->execute([$suratId]);
        $suratCek = $cek->fetch();

        if (!$suratCek) {
            throw new RuntimeException("Surat tidak ditemukan.");
        }
        if ((int) $suratCek['dibuat_oleh'] !== (int) $current_user_id) {
            throw new RuntimeException("Anda hanya bisa mengelola surat yang Anda buat sendiri.");
        }
        if ($suratCek['status'] !== 'Ditolak') {
            throw new RuntimeException("Hanya surat berstatus Ditolak yang bisa direvisi.");
        }

        $pdo->prepare("UPDATE Surat  SET status = 'Draft' WHERE id = ?")->execute([$suratId]);
        catatAudit($pdo, 'Surat', 'Revisi', "Mengembalikan surat #{$suratId} ke Draft untuk direvisi", ['status' => $suratCek['status']], ['status' => 'Draft']);
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Surat dikembalikan ke Draft untuk direvisi.'];
    } catch (Throwable $e) {
        $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Gagal merevisi surat: ' . $e->getMessage()];
    }
    suratRedirect('surat');
}

// ----- Kirim surat yang sudah disetujui ke client (Disetujui -> Terkirim) -----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aksi'] ?? '') === 'kirim_surat') {
    try {
        $suratId = (int) $_POST['surat_id'];

        $pdo->beginTransaction();

        $cek = $pdo->prepare("SELECT * FROM Surat WHERE id = ? FOR UPDATE");
        $cek->execute([$suratId]);
        $surat = $cek->fetch();

        if (!$surat) {
            throw new RuntimeException("Surat tidak ditemukan.");
        }
        if ((int) $surat['dibuat_oleh'] !== (int) $current_user_id) {
            throw new RuntimeException("Anda hanya bisa mengelola surat yang Anda buat sendiri.");
        }
        if ($surat['status'] !== 'Disetujui') {
            throw new RuntimeException("Surat harus berstatus Disetujui sebelum bisa dikirim ke client.");
        }

        $pdo->prepare("UPDATE Surat  SET status = 'Terkirim' WHERE id = ?")->execute([$suratId]);

        // Cocokkan nama tujuan surat dengan Data_Klien untuk mengirim notifikasi
        // langsung ke akun client terkait (jika akunnya terdaftar di sistem).
        $klienTerkirim = null;
        if (!empty($surat['tujuan'])) {
            $cariKlien = $pdo->prepare("
                SELECT dk.id AS klien_id, dk.nama_perusahaan, dk.user_id
                FROM Data_Klien dk
                WHERE dk.nama_perusahaan LIKE ?
                LIMIT 1
            ");
            $cariKlien->execute(['%' . $surat['tujuan'] . '%']);
            $klienTerkirim = $cariKlien->fetch();
        }

        if ($klienTerkirim && !empty($klienTerkirim['user_id'])) {
            // Notifikasi masuk ke akun client
            $pdo->prepare("
                INSERT INTO Notifikasi (user_id, judul, pesan, modul_terkait, ref_id)
                VALUES (?, 'Surat Baru Diterima', ?, 'Surat', ?)
            ")->execute([
                        $klienTerkirim['user_id'],
                        'Surat ' . $surat['nomor'] . ' perihal "' . $surat['perihal'] . '" telah dikirim untuk Anda.',
                        $suratId,
                    ]);

            // Perbarui arsip Dokumen Digital surat ini jadi visibilitas 'Client' agar
            // bisa dilihat/diunduh client (baris arsipnya dibuat otomatis saat surat
            // dibuat, lihat arp_arsipkan_dokumen() di titik "Buat Surat").
            if (!empty($surat['file_hasil'])) {
                arp_arsipkan_dokumen($pdo, [
                    'nama_dokumen' => $surat['nomor'] . ' - ' . $surat['perihal'],
                    'kategori' => 'Lainnya',
                    'file_path' => $surat['file_hasil'],
                    'modul_sumber' => 'Surat Keluar',
                    'ref_id' => $suratId,
                    'klien_id' => $klienTerkirim['klien_id'],
                    'visibilitas' => 'Client',
                    'diupload_oleh' => $current_user_id,
                ]);
            }
        }

        $pdo->commit();

        catatAudit($pdo, 'Surat', 'Kirim', "Mengirim surat #{$suratId} (" . ($surat['nomor'] ?? '') . ") ke tujuan " . ($surat['tujuan'] ?? '-'), ['status' => $surat['status']], ['status' => 'Terkirim']);


        $_SESSION['flash'] = [
            'type' => 'success',
            'msg' => $klienTerkirim && !empty($klienTerkirim['user_id'])
                ? 'Surat berhasil dikirim dan notifikasi diteruskan ke akun client "' . $klienTerkirim['nama_perusahaan'] . '".'
                : 'Surat ditandai Terkirim. Catatan: tidak ditemukan akun client yang cocok dengan tujuan surat, notifikasi tidak terkirim otomatis.',
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction())
            $pdo->rollBack();
        $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Gagal mengirim surat: ' . $e->getMessage()];
    }
    suratRedirect('surat');
}

// ----- Arsipkan surat yang sudah terkirim (Terkirim -> Diarsipkan) -----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aksi'] ?? '') === 'arsipkan_surat') {
    try {
        $suratId = (int) $_POST['surat_id'];
        $cek = $pdo->prepare("SELECT status, dibuat_oleh FROM Surat WHERE id = ?");
        $cek->execute([$suratId]);
        $suratCek = $cek->fetch();

        if (!$suratCek) {
            throw new RuntimeException("Surat tidak ditemukan.");
        }
        if ((int) $suratCek['dibuat_oleh'] !== (int) $current_user_id) {
            throw new RuntimeException("Anda hanya bisa mengelola surat yang Anda buat sendiri.");
        }
        if ($suratCek['status'] !== 'Terkirim') {
            throw new RuntimeException("Hanya surat berstatus Terkirim yang bisa diarsipkan.");
        }

        $pdo->prepare("UPDATE Surat SET status = 'Diarsipkan' WHERE id = ?")->execute([$suratId]);
        catatAudit($pdo, 'Surat', 'Arsipkan', "Mengarsipkan surat #{$suratId}", ['status' => $suratCek['status']], ['status' => 'Diarsipkan']);
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Surat berhasil diarsipkan.'];
    } catch (Throwable $e) {
        $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Gagal mengarsipkan surat: ' . $e->getMessage()];
    }
    suratRedirect('surat');
}

// ==========================================
// [TAB: SURAT] HAPUS SURAT
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aksi'] ?? '') === 'hapus_surat') {
    try {
        $suratId = (int) $_POST['surat_id'];
        $stmt = $pdo->prepare("SELECT * FROM Surat WHERE id = ?");
        $stmt->execute([$suratId]);
        $s = $stmt->fetch();
        if (!$s) {
            throw new RuntimeException("Surat tidak ditemukan.");
        }
        if ((int) $s['dibuat_oleh'] !== (int) $current_user_id) {
            throw new RuntimeException("Anda hanya bisa menghapus surat yang Anda buat sendiri.");
        }

        $pdo->prepare("DELETE FROM Surat WHERE id = ?")->execute([$suratId]);

        catatAudit($pdo, 'Surat', 'Hapus', "Menghapus surat #{$suratId} (" . ($s['nomor'] ?? '') . ")", $s, null);

        if (!empty($s['drive_file_id'])) {
            arp_hapus_file_drive($s['drive_file_id']);
        } elseif (!empty($s['file_hasil']) && stripos($s['file_hasil'], 'http') !== 0) {
            $pathLokalHapus = BASE_PATH . '/' . $s['file_hasil'];
            if (is_file($pathLokalHapus)) {
                @unlink($pathLokalHapus);
            }
        }

        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Surat berhasil dihapus.'];
    } catch (Throwable $e) {
        $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Gagal menghapus: ' . $e->getMessage()];
    }
    suratRedirect('surat');
}

if ($errorGenerateSurat) {
    $flash = ['type' => 'error', 'msg' => $errorGenerateSurat];
}


// Satu FAMILY (surat asli + semua revisinya, nomor surat sama) selalu
// ditampilkan BERDEKATAN: family diurutkan berdasarkan tanggal surat ASLI-nya,
// lalu di dalam satu family diurutkan dari revisi TERBARU ke yang lebih lama
// (revisi terbaru di atas, surat asli paling bawah di kelompoknya).
$daftar_surat = $pdo->query("
    SELECT s.*, k.kode AS kode_str, k.nama AS jenis_surat_kode,
           u.nama_lengkap AS pembuat_nama, u.role AS pembuat_role,
           COALESCE(s.induk_surat_id, s.id) AS root_id,
           COALESCE(rootS.tgl_dibuat, s.tgl_dibuat) AS root_tgl_dibuat,
           (SELECT COUNT(*) FROM Surat child WHERE child.direvisi_dari_id = s.id) AS jumlah_revisi_turunan,
           (SELECT MAX(child.revisi_ke) FROM Surat child WHERE child.direvisi_dari_id = s.id) AS revisi_terbaru_ke,
           (SELECT ap.catatan FROM Approval ap
              WHERE ap.jenis_pengajuan = 'Surat' AND ap.ref_id = s.id AND ap.status = 'Ditolak'
              ORDER BY ap.tgl_aksi DESC LIMIT 1) AS catatan_ditolak
    FROM Surat s
    JOIN Kode_Surat k ON s.kode_id = k.id
    LEFT JOIN Users u ON s.dibuat_oleh = u.id
    LEFT JOIN Surat rootS ON rootS.id = COALESCE(s.induk_surat_id, s.id)
    WHERE s.arah = 'Keluar'
    ORDER BY root_tgl_dibuat DESC, root_id DESC, s.revisi_ke DESC
")->fetchAll();

$kodeReimburseUntukFilter = arp_muat_template_reimburse($pdo);
$kodeIdReimburseDikecualikan = $kodeReimburseUntukFilter ? (int) $kodeReimburseUntukFilter['id'] : 0;

$daftar_surat = array_values(array_filter(
    $daftar_surat,
    fn($s) => $kodeIdReimburseDikecualikan === 0 || (int) $s['kode_id'] !== $kodeIdReimburseDikecualikan
));

// Surat MASUK hanya dicatat oleh Admin. Di sini sifatnya referensi bacaan
// saja untuk semua role -- tidak ada tombol "Catat Surat Masuk", tidak ada
// aksi ubah status, dan tidak ada tombol Hapus.
$daftar_surat_masuk = $pdo->query("
    SELECT s.*, k.kode AS kode_str, k.nama AS jenis_surat_kode
    FROM Surat s
    JOIN Kode_Surat k ON s.kode_id = k.id
    WHERE s.arah = 'Masuk'
    ORDER BY s.tgl_dibuat DESC, s.id DESC
")->fetchAll();

// ==========================================
// [DATA: TAB BUAT SURAT] Daftar kode surat (yang punya minimal 1 template)
// ==========================================
$daftar_kode = $pdo->query("SELECT * FROM Kode_Surat ORDER BY nama")->fetchAll();

// ==========================================
// [DATA: TAB UPLOAD TEMPLATE] Daftar template + kode yang terhubung
// ==========================================
$daftar_template = $pdo->query("SELECT * FROM Template_Master ORDER BY created_at DESC")->fetchAll();

$daftar_kode_per_template = [];
$rowsKodeTemplate = $pdo->query("SELECT kt.template_id, k.kode FROM Kode_Template kt
                                  JOIN Kode_Surat k ON k.id = kt.kode_id")->fetchAll();
foreach ($rowsKodeTemplate as $r) {
    $daftar_kode_per_template[$r['template_id']][] = $r['kode'];
}

$template_per_kode = [];
$rowsTplPerKode = $pdo->query("SELECT kt.id AS kode_template_id, kt.kode_id, kt.template_id, kt.is_default,
                                       t.nama AS nama_template, t.deskripsi, t.format
                                FROM Kode_Template kt
                                JOIN Template_Master t ON t.id = kt.template_id
                                ORDER BY kt.is_default DESC, t.nama ASC")->fetchAll();
foreach ($rowsTplPerKode as $r) {
    $template_per_kode[$r['kode_id']][] = $r;
}
$daftar_kode_dengan_template = array_values(array_filter(
    $daftar_kode,
    fn($k) => !empty($template_per_kode[$k['id']])
));

// BARU: "Permohonan Cuti Dan Pengalihan Tugas" dikecualikan dari tab "Buat
// Surat" -- surat ini WAJIB dibuat lewat pengajuan Cuti Tahunan (cuti.php),
// karena datanya (jumlah hari, saldo cuti, dst) harus divalidasi dari
// pengajuan asli dan link filenya disimpan khusus di Cuti.drive_link_surat,
// bukan di tabel Surat. Kalau dibuat manual di sini, surat jadi "nyasar":
// tidak terhubung ke pengajuan cuti mana pun.
$kodeCutiUntukFilter = null;
try {
    $stmtKodeCuti = $pdo->prepare("SELECT id FROM Kode_Surat WHERE nama = 'Permohonan Cuti Dan Pengalihan Tugas' LIMIT 1");
    $stmtKodeCuti->execute();
    $kodeCutiUntukFilter = $stmtKodeCuti->fetchColumn();
} catch (Throwable $e) {
    $kodeCutiUntukFilter = null;
}
$kodeIdCutiDikecualikan = $kodeCutiUntukFilter ? (int) $kodeCutiUntukFilter : 0;

$daftar_kode_dengan_template = array_values(array_filter(
    $daftar_kode_dengan_template,
    fn($k) => $kodeIdCutiDikecualikan === 0 || (int) $k['id'] !== $kodeIdCutiDikecualikan
));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aksi'] ?? '') === 'generate_surat') {
    $kodeIdTerpilih = (int) ($_POST['kode_id'] ?? 0);
    $templateIdTerpilih = (int) ($_POST['template_id'] ?? 0);
} else {
    $kodeIdTerpilih = (int) ($_GET['kode_id'] ?? 0);
    $templateIdTerpilih = (int) ($_GET['template_id'] ?? 0);
}

$kodeTerpilih = null;
$fields_dinamis = [];
$fields_tabel = [];
$fields_blok = [];
$fields_invoice = [];
if ($active_tab === 'tabPanelBuatSurat' && $kodeIdTerpilih && $templateIdTerpilih) {
    $stmt = $pdo->prepare("SELECT k.*, t.id AS template_id, t.nama AS nama_template, t.drive_file_id, t.drive_link, t.format, t.fields_json
                            FROM Kode_Surat k
                            JOIN Kode_Template kt ON kt.kode_id = k.id AND kt.template_id = ?
                            JOIN Template_Master t ON t.id = kt.template_id
                            WHERE k.id = ?");
    $stmt->execute([$templateIdTerpilih, $kodeIdTerpilih]);
    $kodeTerpilih = $stmt->fetch();

    if ($kodeTerpilih) {
        $hasilFields = muatFieldsTemplateLive($pdo, $kodeTerpilih);
        $fields_dinamis = $hasilFields['fields'];
        $fields_tabel = $hasilFields['table_fields'];
        $fields_blok = $hasilFields['blocks'];
        $fields_invoice = $hasilFields['invoice_fields'] ?? [];

        if (defined('FIELD_OTOMATIS_SISTEM')) {
            $fields_dinamis = array_values(array_filter(
                $fields_dinamis,
                fn($f) => !in_array(strtolower($f['field'] ?? ''), FIELD_OTOMATIS_SISTEM, true)
            ));
        }
    }
}

$file_template_hilang = $kodeTerpilih && empty($kodeTerpilih['drive_file_id']);

$auto_fields_template = ($kodeTerpilih && !$file_template_hilang && $kodeTerpilih['format'] === 'word_pdf')
    ? arp_dengan_template_sementara($kodeTerpilih['drive_file_id'], fn($p) => scanAutoFieldsFromDocx($p))
    : [];
$ada_total = in_array('total', $auto_fields_template, true);
$ada_ppn = in_array('ppn', $auto_fields_template, true);
$ada_pph23 = in_array('pph_23', $auto_fields_template, true);

$ada_total_bayar = in_array('total_bayar', $auto_fields_template, true);
$ada_sisa_pelunasan = in_array('sisa_pelunasan', $auto_fields_template, true);  // ⬅ BARU
$ada_diskon = in_array('diskon', $auto_fields_template, true);
$ada_grand_total = in_array('grand_total', $auto_fields_template, true);
$ada_dp = in_array('down_payment', $auto_fields_template, true);
$ada_total_alat = in_array('total_alat', $auto_fields_template, true);
$ada_no_surat_khusus = in_array('no_surat', $auto_fields_template, true);
$ada_ringkasan_total = $ada_total || $ada_ppn || $ada_pph23 || $ada_diskon || $ada_total_bayar || $ada_grand_total || $ada_dp || $ada_sisa_pelunasan;

$isPostBuatSurat = $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aksi'] ?? '') === 'generate_surat';
$checkedSertakanPpn = $isPostBuatSurat ? isset($_POST['sertakan_ppn']) : true;
$checkedSertakanPph23 = $isPostBuatSurat ? isset($_POST['sertakan_pph23']) : true;
$checkedSertakanDiskon = $isPostBuatSurat ? isset($_POST['sertakan_diskon']) : true;
$checkedSertakanGrandTotal = $isPostBuatSurat ? isset($_POST['sertakan_grand_total']) : true;
$checkedSertakanTotalBayar = $isPostBuatSurat ? isset($_POST['sertakan_total_bayar']) : true;  // ⬅ BARU
$checkedSertakanSisaPelunasan = $isPostBuatSurat ? isset($_POST['sertakan_sisa_pelunasan']) : true;  // ⬅ BARU
$checkedSertakanDp = $isPostBuatSurat ? isset($_POST['sertakan_dp']) : true;

$nilai_dinamis = [];
foreach ($fields_dinamis as $f) {
    $isTanggalField = (bool) preg_match('/tanggal|tgl/i', $f['field']);
    $nilai_dinamis[$f['field']] = $_POST['dinamis'][$f['field']] ?? ($isTanggalField ? date('Y-m-d') : '');
}

$nilai_items = $_POST['items'] ?? [];
if (empty($nilai_items) && !empty($fields_tabel)) {
    $barisKosong = [];
    foreach ($fields_tabel as $kolom) {
        $barisKosong[$kolom['field']] = '';
    }
    $nilai_items = [$barisKosong];
}

$nilai_blok = $_POST['blok'] ?? [];
foreach ($fields_blok as $namaBlok => $daftarFieldBlok) {
    if (empty($nilai_blok[$namaBlok])) {
        $barisKosongBlok = [];
        foreach ($daftarFieldBlok as $kolom) {
            $barisKosongBlok[$kolom['field']] = '';
        }
        $nilai_blok[$namaBlok] = [$barisKosongBlok];
    }
}

$preview_nomor = '(otomatis saat disimpan)';
if ($kodeTerpilih) {
    $tahun = (int) date('Y');
    $counterDariKodeSurat = ((int) $kodeTerpilih['tahun_counter'] === $tahun) ? (int) $kodeTerpilih['counter'] : 0;

    $stmtMaxNomor = $pdo->prepare("
        SELECT nomor FROM Surat
        WHERE kode_id = ? AND nomor LIKE ?
    ");
    $stmtMaxNomor->execute([$kodeTerpilih['id'], '%/' . $kodeTerpilih['kode'] . '/ARP/%/' . $tahun]);
    $counterDariSurat = 0;
    foreach ($stmtMaxNomor->fetchAll(PDO::FETCH_COLUMN) as $nomorLama) {
        $angkaAwal = (int) strtok($nomorLama, '/');
        if ($angkaAwal > $counterDariSurat) {
            $counterDariSurat = $angkaAwal;
        }
    }

    $counterPreview = max($counterDariKodeSurat, $counterDariSurat) + 1;

    // ⬇ Pratinjau nomor ikut invoice HANYA kalau checkbox "ikuti_nomor_invoice" dicentang.
    $invoiceSumberIdPreview = (int) ($_POST['invoice_sumber_id'] ?? 0);
    $ikutiNomorInvoicePreview = isset($_POST['ikuti_nomor_invoice']);
    if ($invoiceSumberIdPreview > 0 && $ikutiNomorInvoicePreview) {
        $stmtNomorInvoicePreview = $pdo->prepare("SELECT nomor FROM Surat WHERE id = ?");
        $stmtNomorInvoicePreview->execute([$invoiceSumberIdPreview]);
        $nomorInvoicePreview = $stmtNomorInvoicePreview->fetchColumn();
        if ($nomorInvoicePreview) {
            $noUrutInvoicePreview = explode('/', $nomorInvoicePreview)[0];
            if (ctype_digit($noUrutInvoicePreview)) {
                $counterPreview = (int) $noUrutInvoicePreview;
            }
        }
    }

    $bulanRomawi = ['I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X', 'XI', 'XII'][date('n') - 1];
    $preview_nomor = sprintf('%03d/%s/ARP/%s/%d', $counterPreview, $kodeTerpilih['kode'], $bulanRomawi, $tahun);
}
$tabel_item_punya_harga = false;
foreach ($fields_tabel as $kolom) {
    if (isKolomHarga($kolom['field'])) {
        $tabel_item_punya_harga = true;
        break;
    }
}

// ==========================================
// Helper tampilan: warna badge status & arah (mengikuti palet badge-* app_aksara)
// ==========================================
if (!function_exists('badgeArah')) {
    function badgeArah(string $arah): string
    {
        return $arah === 'Masuk' ? 'badge-warning' : 'badge-success';
    }
}
if (!function_exists('badgeStatus')) {
    function badgeStatus(string $status): string
    {
        $map = [
            'Terkirim' => 'badge-success',
            'Selesai' => 'badge-success',
            'Disetujui' => 'badge-success',
            'Diproses' => 'badge-warning',
            'Didisposisi' => 'badge-warning',
            'Menunggu Persetujuan' => 'badge-warning',
            'Baru' => 'badge-warning',
            'Ditolak' => 'badge-danger',
            'Draft' => 'badge-secondary',
            'Diarsipkan' => 'badge-secondary',
        ];
        return $map[$status] ?? 'badge-warning';
    }
}

if (!function_exists('labelRole')) {
    function labelRole(?string $role): string
    {
        $map = [
            'admin' => 'Admin',
            'ahli_k3' => 'Ahli K3',
            'it' => 'IT',
            'direksi' => 'Direksi',
            'client' => 'Client',
        ];
        return $map[$role] ?? ucfirst((string) $role);
    }
}

include "../includes/header.php";
include "../includes/sidebar.php";
include "../includes/topbar.php";
?>

<main class="main-content">

    <?php if ($flash): ?>
        <div
            class="alert alert-<?= $flash['type'] === 'success' ? 'success-custom' : 'danger-custom' ?> align-items-center">
            <i
                class="bi <?= $flash['type'] === 'success' ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill' ?> fs-5"></i>
            <div><?= e($flash['msg']) ?></div>
        </div>
    <?php endif; ?>

    <!-- Tab Navigation -->
    <div class="arp-tab-group">
        <div class="arp-tab-nav">
            <button type="button" class="arp-tab-btn<?= $active_tab === 'tabPanelSurat' ? ' active' : '' ?>"
                data-tab-target="tabPanelSuratKeluar" onclick="switchTab('tabPanelSuratKeluar', this)">
                <i class="bi bi-send-check me-1"></i> Surat Keluar
            </button>
            <button type="button" class="arp-tab-btn<?= $active_tab === 'tabPanelSuratMasuk' ? ' active' : '' ?>"
                data-tab-target="tabPanelSuratMasuk" onclick="switchTab('tabPanelSuratMasuk', this)">
                <i class="bi bi-inbox me-1"></i> Surat Masuk
            </button>
            <button type="button" class="arp-tab-btn<?= $active_tab === 'tabPanelBuatSurat' ? ' active' : '' ?>"
                data-tab-target="tabPanelBuatSurat" onclick="switchTab('tabPanelBuatSurat', this)">
                <i class="bi bi-file-earmark-plus me-1"></i> Buat Surat
            </button>
            <button type="button" class="arp-tab-btn<?= $active_tab === 'tabPanelTemplate' ? ' active' : '' ?>"
                data-tab-target="tabPanelTemplate" onclick="switchTab('tabPanelTemplate', this)">
                <i class="bi bi-file-earmark-word me-1"></i> Upload Template
            </button>
        </div>

        <!-- ============================== TAB: SURAT ============================== -->
        <div class="col-12 arp-tab-panel" id="tabPanelSurat" <?= $active_tab === 'tabPanelSurat' ? '' : 'style="display:none;"' ?>>
            <div class="card-box">
                <div class="table-toolbar">
                    <h5 class="table-toolbar-title fw-bold">Daftar Surat Keluar</h5>
                    <div class="table-toolbar-actions">
                        <div class="search-box-container">
                            <i class="bi bi-search"></i>
                            <input type="text" class="search-box" placeholder="Cari nomor surat, perihal, tujuan..."
                                data-table-search="tabelSurat" onkeyup="handleTableSearch('tabelSurat')">
                        </div>
                        <button type="button" class="btn-secondary-custom" onclick="openModal('modalExportRekap')">
                            <i class="bi bi-file-earmark-spreadsheet"></i> Export Rekap
                        </button>
                        <button class="btn-primary-custom"
                            onclick="switchTab('tabPanelBuatSurat', document.querySelector('[data-tab-target=tabPanelBuatSurat]'))">
                            <i class="bi bi-file-earmark-plus"></i>
                            Buat Surat
                        </button>
                    </div>
                </div>
                <p class="text-secondary text-xs mb-3">Semua surat keluar bisa dilihat di sini. Tombol
                    Ajukan/Revisi/Kirim/Arsipkan/Hapus hanya muncul pada surat yang <b>Anda buat sendiri</b>.</p>
                <div class="table-responsive-custom">
                    <table class="table-custom" id="tabelSurat">
                        <thead>
                            <tr>
                                <th>No</th>
                                <th>Nomor Surat</th>
                                <th>Jenis Surat</th>
                                <th>Perihal</th>
                                <th>Tujuan</th>
                                <th>Dibuat Oleh</th>
                                <th>Tanggal</th>
                                <th>Status</th>
                                <th style="text-align:center;">Tindakan</th>
                                <th class="col-aksi" style="text-align:center;">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($daftar_surat)): ?>
                                <tr>
                                    <td colspan="10" class="text-center py-4 text-muted">
                                        <i class="bi bi-envelope-x d-block mb-2" style="font-size:2rem;"></i>
                                        Belum ada data surat keluar.
                                    </td>
                                </tr>
                            <?php endif; ?>
                            <?php $no = 1; ?>
                            <?php foreach ($daftar_surat as $s): ?>
                                <?php $suratMilikSaya = ((int) $s['dibuat_oleh'] === (int) $current_user_id); ?>
                                <tr>
                                    <td><?= $no++; ?></td>
                                    <td><strong><?= e($s['nomor']) ?></strong>
                                        <!-- <?php if (!empty($s['nomor_agenda'])): ?>
                                            <br><small class="text-secondary"><?= e($s['nomor_agenda']) ?></small>
                                        <?php endif; ?> -->
                                        <?php if (!empty($s['revisi_ke']) && (int) $s['revisi_ke'] > 0): ?>
                                            <br><span class="badge-warning" style="font-size:0.65rem;">Revisi
                                                ke-<?= (int) $s['revisi_ke'] ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= e($s['jenis_surat_kode']) ?> <span
                                            class="text-secondary">(<?= e($s['kode_str']) ?>)</span></td>
                                    <td style="white-space:normal; word-break:break-word; max-width:280px;">
                                        <?= e($s['perihal']) ?>
                                    </td>
                                    <td style="white-space:normal; word-break:break-word; max-width:200px;">
                                        <?= e($s['tujuan']) ?>
                                    </td>
                                    <td>
                                        <?php if ($suratMilikSaya): ?>
                                            <span class="badge-success">Saya</span>
                                        <?php elseif (!empty($s['pembuat_nama'])): ?>
                                            <strong><?= e($s['pembuat_nama']) ?></strong>
                                            <br><small class="text-secondary"><?= e(labelRole($s['pembuat_role'])) ?></small>
                                        <?php else: ?>
                                            <span class="text-secondary">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= !empty($s['tgl_dibuat']) ? date('d-m-Y', strtotime($s['tgl_dibuat'])) : '-' ?>
                                    </td>
                                    <td>
                                        <span class="<?= badgeStatus($s['status'] ?? '') ?>"><?= e($s['status']) ?></span>
                                        <?php if ($s['status'] === 'Ditolak' && !empty($s['catatan_ditolak'])): ?>
                                            <br><small class="text-danger d-block mt-1"
                                                style="font-size:0.7rem; max-width:220px; white-space:normal;">
                                                <i class="bi bi-info-circle"></i> <?= e($s['catatan_ditolak']) ?>
                                            </small>
                                        <?php endif; ?>
                                    </td>
                                    <td style="text-align:center;">
                                        <?php if (!$suratMilikSaya): ?>
                                            <span class="text-secondary">-</span>
                                        <?php else: ?>
                                            <div class="table-actions">
                                                <?php if ($s['status'] === 'Draft'): ?>
                                                    <form method="POST" action="surat.php" class="d-inline"
                                                        onsubmit="return confirm('Ajukan surat ini untuk persetujuan?');">
                                                        <input type="hidden" name="aksi" value="ajukan_approval_surat">
                                                        <input type="hidden" name="surat_id" value="<?= (int) $s['id'] ?>">
                                                        <button type="submit" class="btn-primary-custom"
                                                            style="height:28px; padding:0 10px; font-size:0.75rem;">
                                                            <i class="bi bi-send"></i> Ajukan
                                                        </button>
                                                    </form>
                                                <?php elseif ($s['status'] === 'Menunggu Persetujuan'): ?>
                                                    <span class="text-secondary text-xs">Menunggu persetujuan</span>
                                                <?php elseif ($s['status'] === 'Ditolak'): ?>
                                                    <?php if ((int) ($s['jumlah_revisi_turunan'] ?? 0) > 0): ?>
                                                        <span class="text-secondary text-xs">
                                                            <i class="bi bi-check2-circle"></i> Direvisi
                                                            ke-<?= (int) $s['revisi_terbaru_ke'] ?>
                                                        </span>
                                                    <?php else: ?>
                                                        <a href="edit_surat.php?id=<?= (int) $s['id'] ?>&auto_revisi=1"
                                                            class="btn-secondary-custom"
                                                            data-arp-loading="Memuat halaman revisi surat..."
                                                            style="height:28px; padding:0 10px; font-size:0.75rem; display:inline-flex; align-items:center; gap:4px; text-decoration:none;">
                                                            <i class="bi bi-arrow-counterclockwise"></i> Revisi
                                                        </a>
                                                    <?php endif; ?>
                                                <?php elseif ($s['status'] === 'Disetujui'): ?>
                                                    <form method="POST" action="surat.php" class="d-inline"
                                                        onsubmit="return confirm('Kirim surat ini ke client sekarang?');">
                                                        <input type="hidden" name="aksi" value="kirim_surat">
                                                        <input type="hidden" name="surat_id" value="<?= (int) $s['id'] ?>">
                                                        <button type="submit" class="btn-primary-custom"
                                                            style="height:28px; padding:0 10px; font-size:0.75rem;">
                                                            <i class="bi bi-send-check"></i> Kirim ke Client
                                                        </button>
                                                    </form>
                                                <?php elseif ($s['status'] === 'Terkirim'): ?>
                                                    <form method="POST" action="surat.php" class="d-inline"
                                                        onsubmit="return confirm('Arsipkan surat ini?');">
                                                        <input type="hidden" name="aksi" value="arsipkan_surat">
                                                        <input type="hidden" name="surat_id" value="<?= (int) $s['id'] ?>">
                                                        <button type="submit" class="btn-secondary-custom"
                                                            style="height:28px; padding:0 10px; font-size:0.75rem;">
                                                            <i class="bi bi-archive"></i> Arsipkan
                                                        </button>
                                                    </form>
                                                <?php else: ?>
                                                    <span class="text-secondary">-</span>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="col-aksi" style="text-align:center;">
                                        <div class="table-actions">
                                            <?php if (!empty($s['file_hasil'])): ?>
                                                <a class="btn btn-outline-secondary btn-sm py-1" style="font-size:0.75rem;"
                                                    href="<?= e(hrefBerkas($s['file_hasil'])) ?>" target="_blank"
                                                    title="Lihat berkas" data-arp-loading="Membuka berkas...">
                                                    <i class="bi bi-eye"></i>
                                                </a>
                                                <?php if ($suratMilikSaya): ?>
                                                    <a class="btn btn-outline-primary btn-sm py-1" style="font-size:0.75rem;"
                                                        href="edit_surat.php?id=<?= (int) $s['id'] ?>" title="Edit Surat"
                                                        data-arp-loading="Memuat halaman edit surat...">
                                                        <i class="bi bi-pencil-square"></i>
                                                    </a>
                                                <?php endif; ?>
                                                <?php $fileIdUnduh = $s['drive_file_id'] ?? driveFileIdDariUrl($s['file_hasil'] ?? null); ?>
                                                <a class="btn btn-outline-secondary btn-sm py-1" style="font-size:0.75rem;"
                                                    href="<?= e($fileIdUnduh ? urlUnduhLangsungDrive($fileIdUnduh) : hrefBerkas($s['file_hasil'])) ?>"
                                                    title="Unduh Word (.docx)" target="_blank"
                                                    data-arp-loading="Menyiapkan unduhan...">
                                                    <i class="bi bi-download"></i>
                                                </a>
                                            <?php endif; ?>

                                            <?php if ($suratMilikSaya): ?>
                                                <form method="POST" action="surat.php" class="d-inline"
                                                    onsubmit="return confirm('Hapus surat ini? Tindakan tidak bisa dibatalkan.');">
                                                    <input type="hidden" name="aksi" value="hapus_surat">
                                                    <input type="hidden" name="surat_id" value="<?= (int) $s['id'] ?>">
                                                    <button type="submit" class="btn-danger-custom"
                                                        style="height:28px; padding:0 8px; font-size:0.75rem;">
                                                        <i class="bi bi-trash-fill"></i>
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="pagination-custom" id="pagination-tabelSurat"></div>
            </div>
        </div>

        <!-- ============================== TAB: SURAT MASUK (read-only) ============================== -->
        <div class="col-12 arp-tab-panel" id="tabPanelSuratMasuk" <?= $active_tab === 'tabPanelSuratMasuk' ? '' : 'style="display:none;"' ?>>
            <div class="card-box">
                <div class="table-toolbar">
                    <h5 class="table-toolbar-title fw-bold">Daftar Surat Masuk</h5>
                    <div class="table-toolbar-actions">
                        <div class="search-box-container">
                            <i class="bi bi-search"></i>
                            <input type="text" class="search-box" placeholder="Cari nomor surat, perihal, pengirim..."
                                data-table-search="tabelSuratMasuk" onkeyup="handleTableSearch('tabelSuratMasuk')">
                        </div>
                    </div>
                </div>
                <p class="text-secondary text-xs mb-3">Surat masuk hanya dicatat oleh Admin. Halaman ini bersifat
                    referensi bacaan saja untuk semua pengguna.</p>
                <div class="table-responsive-custom">
                    <table class="table-custom" id="tabelSuratMasuk">
                        <thead>
                            <tr>
                                <th>No</th>
                                <th>Nomor Surat</th>
                                <th>Jenis Surat</th>
                                <th>Perihal</th>
                                <th>Pengirim</th>
                                <th>Tanggal</th>
                                <th>Status</th>
                                <th class="col-aksi" style="text-align:center;">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($daftar_surat_masuk)): ?>
                                <tr>
                                    <td colspan="8" class="text-center py-4 text-muted">
                                        <i class="bi bi-envelope-x d-block mb-2" style="font-size:2rem;"></i>
                                        Belum ada data surat masuk.
                                    </td>
                                </tr>
                            <?php endif; ?>
                            <?php $no = 1; ?>
                            <?php foreach ($daftar_surat_masuk as $s): ?>
                                <tr>
                                    <td><?= $no++; ?></td>
                                    <td><strong><?= e($s['nomor']) ?></strong>
                                        <!-- <?php if (!empty($s['nomor_agenda'])): ?>
                                            <br><small class="text-secondary"><?= e($s['nomor_agenda']) ?></small>
                                        <?php endif; ?> -->
                                    </td>
                                    <td><?= e($s['jenis_surat_kode']) ?></td>
                                    <td style="white-space:normal; word-break:break-word; max-width:280px;">
                                        <?= e($s['perihal']) ?>
                                    </td>
                                    <td style="white-space:normal; word-break:break-word; max-width:200px;">
                                        <?= e($s['tujuan']) ?>
                                    </td>
                                    <td><?= !empty($s['tgl_dibuat']) ? date('d-m-Y', strtotime($s['tgl_dibuat'])) : '-' ?>
                                    </td>
                                    <td><span class="<?= badgeStatus($s['status'] ?? '') ?>"><?= e($s['status']) ?></span>
                                    </td>
                                    <td class="col-aksi" style="text-align:center;">
                                        <div class="table-actions">
                                            <?php if (!empty($s['file_hasil'])): ?>
                                                <?php $hrefMasuk = str_starts_with($s['file_hasil'], 'http') ? $s['file_hasil'] : '../' . $s['file_hasil']; ?>
                                                <a class="btn btn-outline-secondary btn-sm py-1" style="font-size:0.75rem;"
                                                    href="<?= e($hrefMasuk) ?>" target="_blank" title="Lihat / unduh berkas"
                                                    data-arp-loading="Membuka berkas...">
                                                    <i class="bi bi-eye"></i>
                                                </a>
                                                <a class="btn btn-outline-secondary btn-sm py-1" style="font-size:0.75rem;"
                                                    href="<?= e($hrefMasuk) ?>" download title="Unduh"
                                                    data-arp-loading="Menyiapkan unduhan...">
                                                    <i class="bi bi-download"></i>
                                                </a>
                                            <?php else: ?>
                                                <span class="text-secondary">-</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="pagination-custom" id="pagination-tabelSuratMasuk"></div>
            </div>
        </div>

        <!-- ============================== TAB: BUAT SURAT ============================== -->
        <div class="col-12 arp-tab-panel" id="tabPanelBuatSurat" <?= $active_tab === 'tabPanelBuatSurat' ? '' : 'style="display:none;"' ?>>
            <div class="buat-surat-grid">

                <section class="card-box">
                    <h5 class="fw-bold mb-3">Buat Surat Baru</h5>

                    <div class="row g-3 mb-2">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold mb-2">Jenis Surat</label>
                            <select id="pilih-jenis-surat" class="select-custom" <?= empty($daftar_kode_dengan_template) ? 'disabled' : '' ?>>
                                <option value="">-- Pilih jenis surat --</option>
                                <?php foreach ($daftar_kode_dengan_template as $k): ?>
                                    <option value="<?= (int) $k['id'] ?>" <?= ($kodeIdTerpilih === (int) $k['id']) ? 'selected' : '' ?>>
                                        <?= e($k['kode'] . ' (' . $k['nama'] . ')') ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold mb-2">Template</label>
                            <select id="pilih-template-surat" class="select-custom" <?= $kodeIdTerpilih ? '' : 'disabled' ?>>
                                <option value="">-- Pilih template --</option>
                                <?php if ($kodeIdTerpilih): ?>
                                    <?php foreach (($template_per_kode[$kodeIdTerpilih] ?? []) as $tpl): ?>
                                        <option value="<?= (int) $tpl['template_id'] ?>" <?= ($templateIdTerpilih === (int) $tpl['template_id']) ? 'selected' : '' ?>>
                                            <?= e($tpl['nama_template']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </select>
                        </div>
                    </div>
                    <?php if (empty($daftar_kode_dengan_template)): ?>
                        <p class="text-secondary text-xs mb-3">Belum ada jenis surat yang punya template. Silakan
                            hubungi Admin untuk mengupload template surat terlebih dahulu.</p>
                    <?php else: ?>
                        <p class="text-secondary text-xs mb-3">Belum ada jenis surat/template yang cocok? Hubungi
                            Admin untuk mengupload template baru.</p>
                    <?php endif; ?>

                    <script id="data-template-per-kode" type="application/json">
                <?php
                $dataUntukJs = [];
                foreach ($daftar_kode_dengan_template as $k) {
                    $tplTerhubung = $template_per_kode[$k['id']] ?? [];
                    $dataUntukJs[(int) $k['id']] = array_map(function ($tpl) {
                        return ['id' => (int) $tpl['template_id'], 'nama' => $tpl['nama_template']];
                    }, $tplTerhubung);
                }
                echo json_encode($dataUntukJs, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
                ?>
                    </script>
                    <script>
                        (function () {
                            var dataTemplatePerKode = JSON.parse(document.getElementById('data-template-per-kode').textContent);
                            var selectJenis = document.getElementById('pilih-jenis-surat');
                            var selectTemplate = document.getElementById('pilih-template-surat');
                            if (!selectJenis || !selectTemplate) return;

                            function pindahHalaman(kodeId, templateId) {
                                var url = 'surat.php?tab=buat';
                                if (kodeId) url += '&kode_id=' + kodeId;
                                if (templateId) url += '&template_id=' + templateId;
                                if (window.arpShowLoader) window.arpShowLoader('Memuat form surat...');
                                window.location.href = url;
                            }

                            selectJenis.addEventListener('change', function () {
                                var kodeId = this.value;
                                if (!kodeId) {
                                    if (window.arpShowLoader) window.arpShowLoader('Memuat form surat...');
                                    window.location.href = 'surat.php?tab=buat';
                                    return;
                                }
                                var daftarTemplate = dataTemplatePerKode[kodeId] || [];
                                if (daftarTemplate.length === 1) {
                                    pindahHalaman(kodeId, daftarTemplate[0].id);
                                } else {
                                    pindahHalaman(kodeId, '');
                                }
                            });

                            selectTemplate.addEventListener('change', function () {
                                var templateId = this.value;
                                if (!templateId) return;
                                pindahHalaman(selectJenis.value, templateId);
                            });
                        })();
                    </script>

                    <?php if (!$kodeTerpilih && $kodeIdTerpilih && $templateIdTerpilih): ?>
                        <div class="alert alert-danger-custom py-2 px-3 text-xs">
                            <i class="bi bi-exclamation-triangle-fill"></i>
                            <div>Kombinasi jenis surat &amp; template ini tidak ditemukan / tidak terhubung.
                                <a href="surat.php?tab=buat">Pilih ulang</a>.
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ($kodeTerpilih && $file_template_hilang): ?>
                        <div class="alert alert-danger-custom text-xs">
                            <i class="bi bi-exclamation-triangle-fill fs-5"></i>
                            <div>
                                <p class="fw-semibold mb-1">File template tidak ditemukan di storage</p>
                                <p class="mb-1">Template untuk jenis surat <b><?= e($kodeTerpilih['kode']) ?></b>
                                    (<?= e($kodeTerpilih['nama_template'] ?? '-') ?>) sudah tidak ada filenya. Silakan
                                    hubungi Admin untuk mengupload ulang.</p>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ($kodeTerpilih && $kodeTerpilih['format'] !== 'word_pdf' && !$file_template_hilang): ?>
                        <p class="text-secondary mb-0">Template ini bukan file Word (.docx), tidak bisa digenerate
                            otomatis lewat form ini.</p>
                    <?php endif; ?>

                    <?php if ($kodeTerpilih && !$file_template_hilang && $kodeTerpilih['format'] === 'word_pdf'): ?>
                        <?php
                        $butuhInvoiceSumber = !empty($fields_invoice);
                        // Supaya invoice yang sudah dipilih tidak hilang saat "Update Preview"
                        $invoiceSumberIdFormNilai = (int) ($_POST['invoice_sumber_id'] ?? 0);
                        $ikutiNomorInvoiceChecked = $isPostBuatSurat ? isset($_POST['ikuti_nomor_invoice']) : false;
                        ?>
                        <hr>
                        <form method="POST" action="surat.php" id="form-buat-surat">
                            <input type="hidden" name="aksi" value="generate_surat">
                            <input type="hidden" name="kode_id" value="<?= (int) $kodeTerpilih['id'] ?>">
                            <input type="hidden" name="template_id" value="<?= (int) $kodeTerpilih['template_id'] ?>">

                            <div class="row g-3 mb-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold mb-2">Jenis surat / Kode</label>
                                    <input type="text" class="form-control-custom field-readonly"
                                        value="<?= e($kodeTerpilih['nama']) ?> (<?= e($kodeTerpilih['kode']) ?>)" readonly>
                                </div>
                                <?php if (!$ada_no_surat_khusus): ?>
                                    <div class="col-md-6">
                                        <div id="wrapper-no-urut-manual" <?= ($butuhInvoiceSumber && $ikutiNomorInvoiceChecked) ? 'style="display:none;"' : '' ?>>
                                            <label class="form-label fw-semibold mb-2">No Urut Surat</label>
                                            <div class="nomor-surat-group">
                                                <input type="text" name="no_urut_manual" id="input-no-urut-manual"
                                                    class="form-control-custom nomor-surat-input"
                                                    value="<?= e($_POST['no_urut_manual'] ?? sprintf('%03d', $counterPreview)) ?>"
                                                    placeholder="<?= e(sprintf('%03d', $counterPreview)) ?>">
                                                <div
                                                    class="form-control-custom field-readonly text-secondary nomor-surat-suffix">
                                                    /<?= e($kodeTerpilih['kode']) ?>/ARP/<?= e($bulanRomawi) ?>/<?= e($tahun) ?>
                                                </div>
                                            </div>
                                        </div>
                                        <?php if ($butuhInvoiceSumber): ?>
                                            <div id="wrapper-nomor-ikuti-invoice" <?= $ikutiNomorInvoiceChecked ? '' : 'style="display:none;"' ?>>
                                                <label class="form-label fw-semibold mb-2">Nomor Kuitansi (ikut Invoice)</label>
                                                <input type="text" class="form-control-custom field-readonly"
                                                    id="tampilan-nomor-kuitansi" readonly
                                                    placeholder="Pilih invoice sumber dahulu...">
                                                <small class="text-secondary text-xs d-block mt-1">
                                                    Nomor ini otomatis mengikuti invoice sumber karena kotak
                                                    "mengikuti nomor urut invoice sumber" dicentang.
                                                </small>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                                <?php if ($ada_no_surat_khusus): ?>
                                    <div class="col-md-6">
                                        <label class="form-label fw-semibold mb-2">Kode Nomor Surat</label>
                                        <div class="nomor-surat-group">
                                            <div class="form-control-custom field-readonly nomor-surat-suffix">JD</div>
                                            <input type="text" name="no_surat_manual"
                                                class="form-control-custom nomor-surat-input" style="text-transform:uppercase;"
                                                value="<?= e($_POST['no_surat_manual'] ?? '') ?>" placeholder="75T4EM">
                                            <div class="form-control-custom field-readonly text-secondary nomor-surat-suffix">
                                                /<?= e(date('m')) ?>/<?= e(date('Y')) ?>
                                            </div>
                                        </div>
                                        <small class="text-secondary text-xs d-block mt-1">
                                            Format otomatis: <b>JD</b> + kode Anda + <b>/bulan/tahun</b>.
                                            Contoh: <code>JD75T4EM/<?= e(date('m')) ?>/<?= e(date('Y')) ?></code>
                                        </small>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <?php if (!empty($kodeTerpilih['deskripsi'])): ?>
                                <div class="alert alert-success-custom text-xs mb-3">
                                    <i class="bi bi-info-circle-fill"></i>
                                    <div><span class="fw-semibold">Deskripsi:</span> <?= nl2br(e($kodeTerpilih['deskripsi'])) ?>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <?php if ($butuhInvoiceSumber): ?>
                                <div class="arp-static-info-box text-xs mb-3" style="position:relative;">
                                    <i class="bi bi-link-45deg"></i>
                                    <div style="width:100%;">
                                        <label class="form-label fw-semibold mb-2 d-block">Pilih Invoice Sumber *</label>
                                        <input type="text" class="form-control-custom" id="cari-invoice-sumber"
                                            autocomplete="off" placeholder="Ketik nomor surat atau nama tujuan invoice..."
                                            required>
                                        <input type="hidden" name="invoice_sumber_id" id="input-invoice-sumber-id"
                                            value="<?= $invoiceSumberIdFormNilai > 0 ? (int) $invoiceSumberIdFormNilai : '' ?>">

                                        <?php if (!$ada_no_surat_khusus): ?>
                                            <label class="d-flex align-items-center gap-2 mt-3 text-xs fw-semibold"
                                                style="cursor:pointer;">
                                                <input type="checkbox" name="ikuti_nomor_invoice" id="checkbox-ikuti-nomor-invoice"
                                                    value="1" <?= $ikutiNomorInvoiceChecked ? 'checked' : '' ?>>
                                                Nomor surat ini mengikuti nomor urut invoice sumber
                                            </label>
                                            <small class="text-secondary text-xs d-block mt-1">
                                                Centang jika surat ini (mis. Kuitansi) harus punya nomor urut yang sama dengan
                                                invoice-nya. Biarkan kosong jika surat ini punya nomor sendiri, walau tetap
                                                butuh data invoice (total, terbilang, dst) di dalam isinya.
                                            </small>
                                        <?php endif; ?>

                                        <small class="text-secondary text-xs d-block mt-2">
                                            Field <code>${invoice_...}</code> di template (nomor, tanggal, nama perusahaan,
                                            grand total, total bayar, terbilang, dst) akan otomatis diisi dari invoice ini.
                                        </small>
                                        <div class="text-xs fw-semibold mt-2" id="info-grand-total-kuitansi"></div>
                                    </div>
                                </div>
                                <script>
                                    (function () {
                                        var inputCari = document.getElementById('cari-invoice-sumber');
                                        var hiddenId = document.getElementById('input-invoice-sumber-id');
                                        var infoTotal = document.getElementById('info-grand-total-kuitansi');
                                        if (!inputCari) return;

                                        var idTersimpan = <?= json_encode($invoiceSumberIdFormNilai) ?>;
                                        var daftarInvoice = [];
                                        var boxSaran = null;
                                        var timerCariInvoice = null;

                                        function escapeHtmlInv(str) {
                                            return String(str || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
                                        }

                                        function tutupSaranInvoice() {
                                            if (boxSaran) { boxSaran.remove(); boxSaran = null; }
                                        }

                                        function posisikanBoxInvoice(box) {
                                            var rect = inputCari.getBoundingClientRect();
                                            box.style.left = (rect.left + window.scrollX) + 'px';
                                            box.style.top = (rect.bottom + window.scrollY + 4) + 'px';
                                            box.style.width = rect.width + 'px';
                                        }

                                        function isiJikaAda(namaField, nilai) {
                                            var el = document.querySelector('[name="dinamis[' + namaField + ']"]');
                                            if (el) el.value = nilai;
                                        }

                                        // Nomor urut surat HANYA diambil dari invoice kalau checkbox
                                        // "Nomor surat ini mengikuti nomor urut invoice sumber" dicentang.
                                        // Kalau TIDAK dicentang, field No Urut Surat sama sekali tidak disentuh.
                                        window.invoiceTerpilihUntukNomor = null;
                                        window.terapkanNomorInvoicePilihan = function () {
                                            var checkbox = document.getElementById('checkbox-ikuti-nomor-invoice');
                                            var elManual = document.getElementById('input-no-urut-manual');
                                            var elTampil = document.getElementById('tampilan-nomor-kuitansi');
                                            if (!checkbox || !checkbox.checked || !elManual) return;

                                            var inv = window.invoiceTerpilihUntukNomor;
                                            if (!inv) {
                                                elManual.value = '';
                                                if (elTampil) elTampil.value = '';
                                                return;
                                            }
                                            var noUrutInvoice = String(inv.nomor || '').split('/')[0];
                                            if (!/^\d+$/.test(noUrutInvoice)) {
                                                elManual.value = '';
                                                if (elTampil) elTampil.value = '';
                                                return;
                                            }
                                            var kodeSurat = <?= json_encode($kodeTerpilih['kode'] ?? '') ?>;
                                            var bulanRomawiJs = <?= json_encode($bulanRomawi ?? '') ?>;
                                            var tahunJs = <?= json_encode((string) ($tahun ?? '')) ?>;

                                            elManual.value = String(parseInt(noUrutInvoice, 10));
                                            if (elTampil) {
                                                elTampil.value = noUrutInvoice.padStart(3, '0') + '/' + kodeSurat + '/ARP/' + bulanRomawiJs + '/' + tahunJs;
                                            }
                                        };

                                        var checkboxIkutiNomor = document.getElementById('checkbox-ikuti-nomor-invoice');
                                        var wrapperManualNomor = document.getElementById('wrapper-no-urut-manual');
                                        var wrapperNomorInvoice = document.getElementById('wrapper-nomor-ikuti-invoice');
                                        var inputManualNomor = document.getElementById('input-no-urut-manual');
                                        var nilaiManualNomorSebelumnya = inputManualNomor ? inputManualNomor.value : '';

                                        function updateTampilanNomorSurat() {
                                            if (!checkboxIkutiNomor || !inputManualNomor) return;
                                            if (checkboxIkutiNomor.checked) {
                                                if (wrapperManualNomor) wrapperManualNomor.style.display = 'none';
                                                if (wrapperNomorInvoice) wrapperNomorInvoice.style.display = '';
                                                window.terapkanNomorInvoicePilihan();
                                            } else {
                                                if (wrapperNomorInvoice) wrapperNomorInvoice.style.display = 'none';
                                                if (wrapperManualNomor) wrapperManualNomor.style.display = '';
                                                inputManualNomor.value = nilaiManualNomorSebelumnya;
                                            }
                                        }

                                        if (checkboxIkutiNomor) {
                                            checkboxIkutiNomor.addEventListener('change', function () {
                                                if (this.checked) {
                                                    nilaiManualNomorSebelumnya = inputManualNomor.value;
                                                }
                                                updateTampilanNomorSurat();
                                            });
                                        }

                                        function pilihInvoice(inv) {
                                            hiddenId.value = inv.id;
                                            inputCari.value = inv.nomor + ' — ' + (inv.tujuan || '-');
                                            inputCari.dataset.justPicked = '1';
                                            tutupSaranInvoice();

                                            window.invoiceTerpilihUntukNomor = inv;
                                            window.terapkanNomorInvoicePilihan();

                                            fetch('surat.php?ajax=data_invoice_kuitansi&invoice_id=' + encodeURIComponent(inv.id))
                                                .then(function (res) { return res.json(); })
                                                .then(function (data) {
                                                    if (data.error) { alert(data.error); return; }
                                                    if (infoTotal) {
                                                        infoTotal.textContent = 'Dari invoice ' + data.nomor_invoice
                                                            + ' — Tanggal: ' + (data.tanggal_invoice || '-')
                                                            + ' — Grand Total: ' + data.grand_total_format
                                                            + ' — Total Bayar: ' + data.total_bayar_format
                                                            + ' — Terbilang: ' + data.terbilang;
                                                    }
                                                });
                                        }

                                        function tampilkanSaranInvoice(daftar) {
                                            tutupSaranInvoice();
                                            var box = document.createElement('div');
                                            box.className = 'arp-autocomplete-box';
                                            box.style.cssText = 'position:absolute; z-index:2000; background:#fff;' +
                                                'border:1px solid var(--border-color,#e2e8f0); border-radius:8px;' +
                                                'box-shadow:0 8px 20px rgba(0,0,0,0.12);' +
                                                'max-height:220px; overflow-y:auto; font-size:0.85rem;';
                                            document.body.appendChild(box);
                                            posisikanBoxInvoice(box);
                                            boxSaran = box;

                                            if (!daftar.length) {
                                                box.innerHTML = '<div style="padding:10px 14px; color:var(--text-secondary,#64748b);">Tidak ada invoice cocok ditemukan.</div>';
                                                return;
                                            }

                                            daftar.forEach(function (inv) {
                                                var item = document.createElement('div');
                                                item.style.cssText = 'padding:10px 14px; cursor:pointer; border-bottom:1px solid #f1f5f9;';
                                                item.innerHTML = '<div style="font-weight:600; color:var(--text-primary,#1e293b); font-family:monospace;">' +
                                                    escapeHtmlInv(inv.nomor) + '</div>' +
                                                    '<div style="color:var(--text-secondary,#64748b); font-size:0.78rem;">' +
                                                    escapeHtmlInv(inv.tujuan || '-') + ' &middot; ' + escapeHtmlInv(inv.status) + '</div>';
                                                item.addEventListener('mouseenter', function () { item.style.background = '#f8fafc'; });
                                                item.addEventListener('mouseleave', function () { item.style.background = '#fff'; });
                                                item.addEventListener('mousedown', function (e) {
                                                    e.preventDefault();
                                                    pilihInvoice(inv);
                                                    inputCari.blur();
                                                });
                                                box.appendChild(item);
                                            });
                                        }

                                        function cariInvoiceLokal(kata) {
                                            kata = kata.trim().toLowerCase();
                                            if (kata === '') return daftarInvoice;
                                            return daftarInvoice.filter(function (inv) {
                                                return (inv.nomor || '').toLowerCase().indexOf(kata) !== -1 ||
                                                    (inv.tujuan || '').toLowerCase().indexOf(kata) !== -1 ||
                                                    (inv.perihal || '').toLowerCase().indexOf(kata) !== -1;
                                            });
                                        }

                                        fetch('surat.php?ajax=daftar_invoice')
                                            .then(function (res) { return res.json(); })
                                            .then(function (daftar) {
                                                daftarInvoice = daftar || [];
                                                if (idTersimpan) {
                                                    var inv = daftarInvoice.find(function (x) { return parseInt(x.id, 10) === idTersimpan; });
                                                    if (inv) {
                                                        inputCari.value = inv.nomor + ' — ' + (inv.tujuan || '-');
                                                        inputCari.dataset.justPicked = '1';
                                                        window.invoiceTerpilihUntukNomor = inv;
                                                        window.terapkanNomorInvoicePilihan();
                                                    }
                                                    fetch('surat.php?ajax=data_invoice_kuitansi&invoice_id=' + idTersimpan)
                                                        .then(function (res) { return res.json(); })
                                                        .then(function (data) {
                                                            if (data.error || !infoTotal) return;
                                                            infoTotal.textContent = 'Dari invoice ' + data.nomor_invoice + ' — Grand Total: ' + data.grand_total_format + ' — Terbilang: ' + data.terbilang;
                                                        });
                                                }
                                            });

                                        inputCari.addEventListener('input', function () {
                                            hiddenId.value = '';
                                            if (infoTotal) infoTotal.textContent = '';
                                            window.invoiceTerpilihUntukNomor = null;
                                            if (window.terapkanNomorInvoicePilihan) window.terapkanNomorInvoicePilihan();

                                            if (inputCari.dataset.justPicked === '1') {
                                                inputCari.dataset.justPicked = '0';
                                                tutupSaranInvoice();
                                                return;
                                            }
                                            clearTimeout(timerCariInvoice);

                                            var kataKunci = inputCari.value.trim();
                                            if (kataKunci === '') {
                                                tutupSaranInvoice();
                                                return;
                                            }

                                            timerCariInvoice = setTimeout(function () {
                                                tampilkanSaranInvoice(cariInvoiceLokal(kataKunci));
                                            }, 200);
                                        });

                                        inputCari.addEventListener('blur', function () {
                                            setTimeout(tutupSaranInvoice, 150);
                                        });

                                        window.addEventListener('scroll', function () {
                                            if (boxSaran) posisikanBoxInvoice(boxSaran);
                                        }, true);
                                    })();
                                </script>
                            <?php endif; ?>

                            <?php if (empty($fields_dinamis) && empty($fields_tabel) && empty($fields_blok)): ?>
                                <div class="alert alert-danger-custom text-xs">
                                    <i class="bi bi-exclamation-triangle-fill"></i>
                                    <div>Template ini belum punya placeholder <code>${...}</code> yang terbaca. Hubungi
                                        Admin untuk mengupload ulang file .docx yang sudah berisi placeholder seperti
                                        <code>${perihal}</code>.
                                    </div>
                                </div>
                            <?php endif; ?>

                            <div class="row g-3">
                                <?php foreach ($fields_dinamis as $f): ?>
                                    <?php $isTanggal = (bool) preg_match('/tanggal|tgl/i', $f['field']); ?>
                                    <div class="col-md-6">
                                        <label class="form-label fw-semibold mb-2"><?= e($f['label']) ?></label>
                                        <input type="<?= $isTanggal ? 'date' : 'text' ?>" name="dinamis[<?= e($f['field']) ?>]"
                                            class="form-control-custom" value="<?= e($nilai_dinamis[$f['field']] ?? '') ?>">
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <?php foreach ($fields_blok as $namaBlok => $daftarFieldBlok): ?>
                                <?php if (empty($daftarFieldBlok))
                                    continue; ?>
                                <div class="blok-box mt-3" data-blok-wrapper="<?= e($namaBlok) ?>">
                                    <label
                                        class="form-label fw-semibold mb-2 d-block"><?= e(labelFromFieldName($namaBlok)) ?></label>
                                    <div id="blok-<?= e($namaBlok) ?>-body">
                                        <?php foreach (($nilai_blok[$namaBlok] ?? [[]]) as $idxBarisBlok => $barisBlok): ?>
                                            <div class="blok-baris" data-baris-index="<?= (int) $idxBarisBlok ?>">
                                                <?php foreach ($daftarFieldBlok as $kolomBlok): ?>
                                                    <input type="text"
                                                        name="blok[<?= e($namaBlok) ?>][<?= (int) $idxBarisBlok ?>][<?= e($kolomBlok['field']) ?>]"
                                                        placeholder="<?= e($kolomBlok['label']) ?>"
                                                        value="<?= e($barisBlok[$kolomBlok['field']] ?? '') ?>"
                                                        class="form-control-custom">
                                                <?php endforeach; ?>
                                                <button type="button" class="btn btn-outline-danger btn-sm tombol-hapus-baris-blok">
                                                    <i class="bi bi-x-lg"></i>
                                                </button>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <button type="button" class="btn btn-outline-primary btn-sm tombol-tambah-blok"
                                        data-blok="<?= e($namaBlok) ?>">
                                        <i class="bi bi-plus-lg"></i> Tambah
                                    </button>
                                </div>
                                <script>
                                    (function () {
                                        var namaBlok = <?= json_encode($namaBlok) ?>;
                                        var fieldList = <?= json_encode(array_column($daftarFieldBlok, 'field')) ?>;
                                        var labelList = <?= json_encode(array_column($daftarFieldBlok, 'label')) ?>;
                                        var body = document.getElementById('blok-' + namaBlok + '-body');
                                        var tombolTambah = document.querySelector('.tombol-tambah-blok[data-blok="' + namaBlok + '"]');
                                        if (!body || !tombolTambah) return;

                                        var idxBerikutnya = body.querySelectorAll('.blok-baris').length;

                                        function pasangTombolHapus(barisEl) {
                                            var btn = barisEl.querySelector('.tombol-hapus-baris-blok');
                                            if (!btn) return;
                                            btn.addEventListener('click', function () {
                                                if (body.querySelectorAll('.blok-baris').length > 1) {
                                                    barisEl.remove();
                                                } else {
                                                    barisEl.querySelectorAll('input').forEach(function (inp) { inp.value = ''; });
                                                }
                                            });
                                        }

                                        body.querySelectorAll('.blok-baris').forEach(pasangTombolHapus);

                                        tombolTambah.addEventListener('click', function () {
                                            var div = document.createElement('div');
                                            div.className = 'blok-baris';
                                            div.setAttribute('data-baris-index', idxBerikutnya);
                                            fieldList.forEach(function (f, i) {
                                                var inp = document.createElement('input');
                                                inp.type = 'text';
                                                inp.name = 'blok[' + namaBlok + '][' + idxBerikutnya + '][' + f + ']';
                                                inp.placeholder = labelList[i];
                                                inp.className = 'form-control-custom';
                                                div.appendChild(inp);
                                            });
                                            var btnHapus = document.createElement('button');
                                            btnHapus.type = 'button';
                                            btnHapus.className = 'btn btn-outline-danger btn-sm tombol-hapus-baris-blok';
                                            btnHapus.innerHTML = '<i class="bi bi-x-lg"></i>';
                                            div.appendChild(btnHapus);
                                            body.appendChild(div);
                                            pasangTombolHapus(div);
                                            idxBerikutnya++;
                                        });
                                    })();
                                </script>
                            <?php endforeach; ?>

                            <?php if (in_array('diskon', $auto_fields_template, true) || $ada_dp): ?>
                                <div class="row g-3 mt-1 mb-3">
                                    <?php if (in_array('diskon', $auto_fields_template, true)): ?>
                                        <div class="col-md-6">
                                            <label class="form-label fw-semibold mb-2 d-flex align-items-center gap-2">
                                                <input type="checkbox" id="checkbox-sertakan-diskon" name="sertakan_diskon"
                                                    value="1" <?= $checkedSertakanDiskon ? 'checked' : '' ?>>
                                                Sertakan Diskon di surat
                                            </label>
                                            <div class="position-relative">
                                                <input type="text" name="diskon_input" id="input-diskon"
                                                    class="form-control-custom pe-5" placeholder="2"
                                                    value="<?= e($_POST['diskon_input'] ?? '0') ?>">
                                                <span
                                                    class="position-absolute top-50 end-0 translate-middle-y me-3 fw-semibold text-secondary">%</span>
                                            </div>
                                            <small class="text-secondary text-xs d-block mt-1">
                                                Masukkan besar diskon dalam <b>persen (%)</b> dari Total, cth "2" untuk diskon 2%.
                                                Nominal rupiahnya dihitung otomatis. Kosongkan/isi 0 jika tidak ada diskon.
                                                Jika tidak dicentang, baris Diskon akan dihapus dari dokumen Word.
                                                PPN &amp; PPH 23 akan dihitung dari Total <b>setelah dikurangi diskon</b> ini
                                                (kalau tidak ada diskon, tetap dihitung dari Total penuh).
                                            </small>
                                        </div>
                                    <?php endif; ?>

                                    <?php if ($ada_dp): ?>
                                        <div class="col-md-6">
                                            <label class="form-label fw-semibold mb-2 d-flex align-items-center gap-2">
                                                <input type="checkbox" id="checkbox-sertakan-dp" name="sertakan_dp" value="1"
                                                    <?= $checkedSertakanDp ? 'checked' : '' ?>>
                                                Sertakan DP di surat
                                            </label>
                                            <div class="position-relative">
                                                <input type="text" name="dp_input" id="input-dp" class="form-control-custom pe-5"
                                                    placeholder="2" value="<?= e($_POST['dp_input'] ?? '0') ?>">
                                                <span
                                                    class="position-absolute top-50 end-0 translate-middle-y me-3 fw-semibold text-secondary">%</span>
                                            </div>
                                            <small class="text-secondary text-xs d-block mt-1">
                                                Masukkan besar DP dalam <b>persen (%)</b> dari <b>Grand Total</b>, cth "2" untuk DP
                                                2%
                                                (boleh ditulis "2" atau "2%", sama saja). Nominal rupiahnya dihitung otomatis.
                                                Jika tidak dicentang, baris DP akan dihapus dari dokumen Word.
                                            </small>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <script>
                                    (function () {
                                        var inpDiskon = document.getElementById('input-diskon');
                                        if (inpDiskon) {
                                            var bersihkan = function (str) { return str.replace(/[^\d.,]/g, ''); };
                                            if (inpDiskon.value) inpDiskon.value = bersihkan(inpDiskon.value);
                                            inpDiskon.addEventListener('input', function () {
                                                this.value = bersihkan(this.value);
                                                if (window.hitungUlangTotalSurat) window.hitungUlangTotalSurat();
                                            });
                                        }

                                        var inpDp = document.getElementById('input-dp');
                                        if (inpDp) {
                                            var bersihkanDp = function (str) { return str.replace(/[^\d.,]/g, ''); };
                                            if (inpDp.value) inpDp.value = bersihkanDp(inpDp.value);
                                            inpDp.addEventListener('input', function () {
                                                this.value = bersihkanDp(this.value);
                                                if (window.hitungUlangTotalSurat) window.hitungUlangTotalSurat();
                                            });
                                            var cbDp = document.getElementById('checkbox-sertakan-dp');
                                            if (cbDp) cbDp.addEventListener('change', function () { if (window.hitungUlangTotalSurat) window.hitungUlangTotalSurat(); });
                                        }
                                    })();
                                </script>
                            <?php endif; ?>

                            <?php if (!empty($fields_tabel)): ?>
                                <div class="mt-3">
                                    <label class="form-label fw-semibold mb-2">Rincian Item</label>
                                    <div class="table-responsive-custom">
                                        <table class="table-custom" id="tabel-item">
                                            <thead>
                                                <tr>
                                                    <th style="width:36px;">No</th>
                                                    <?php foreach ($fields_tabel as $kolom): ?>
                                                        <th><?= e($kolom['label']) ?></th>
                                                    <?php endforeach; ?>
                                                    <?php if ($tabel_item_punya_harga): ?>
                                                        <th style="text-align:right;">Sub Total</th>
                                                    <?php endif; ?>
                                                    <th style="width:36px;"></th>
                                                </tr>
                                            </thead>
                                            <tbody id="tabel-item-body">
                                                <?php foreach ($nilai_items as $idxBaris => $baris): ?>
                                                    <tr class="baris-item" data-baris-index="<?= (int) $idxBaris ?>">
                                                        <td class="nomor-baris">1</td>
                                                        <?php foreach ($fields_tabel as $kolom): ?>
                                                            <?php
                                                            $isHarga = isKolomHarga($kolom['field']);
                                                            $isQty = isKolomQty($kolom['field']);
                                                            $placeholderKolom = $isHarga ? 'cth: 6055000' : ($isQty ? 'cth: 3 unit / 5 orang' : '');
                                                            ?>
                                                            <td>
                                                                <input type="text"
                                                                    name="items[<?= (int) $idxBaris ?>][<?= e($kolom['field']) ?>]"
                                                                    data-kolom="<?= e($kolom['field']) ?>" <?= $isHarga ? 'data-tipe="harga"' : '' ?>
                                                                    placeholder="<?= e($placeholderKolom) ?>"
                                                                    class="form-control-custom"
                                                                    value="<?= e($baris[$kolom['field']] ?? '') ?>">
                                                            </td>
                                                        <?php endforeach; ?>
                                                        <?php if ($tabel_item_punya_harga): ?>
                                                            <td class="subtotal-baris" style="text-align:right; font-family:monospace;">
                                                                -</td>
                                                        <?php endif; ?>
                                                        <td style="text-align:center;">
                                                            <button type="button"
                                                                class="btn btn-outline-danger btn-sm tombol-hapus-baris">
                                                                <i class="bi bi-x-lg"></i>
                                                            </button>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    <button type="button" id="tombol-tambah-baris" class="btn btn-outline-primary btn-sm mt-2">
                                        <i class="bi bi-plus-lg"></i> Tambah Baris
                                    </button>
                                    <?php if ($tabel_item_punya_harga): ?>
                                        <?php if ($ada_ppn || $ada_pph23 || $ada_grand_total || $ada_total_bayar): ?>
                                            <div class="d-flex flex-wrap gap-3 mt-2 mb-1">
                                                <?php if ($ada_ppn): ?>
                                                    <label class="d-flex align-items-center gap-2 text-xs fw-semibold mb-0"
                                                        style="cursor:pointer;">
                                                        <input type="checkbox" id="checkbox-sertakan-ppn" name="sertakan_ppn" value="1"
                                                            <?= $checkedSertakanPpn ? 'checked' : '' ?>>
                                                        Sertakan PPN (11%) di surat
                                                    </label>
                                                <?php endif; ?>
                                                <?php if ($ada_pph23): ?>
                                                    <label class="d-flex align-items-center gap-2 text-xs fw-semibold mb-0"
                                                        style="cursor:pointer;">
                                                        <input type="checkbox" id="checkbox-sertakan-pph23" name="sertakan_pph23" value="1"
                                                            <?= $checkedSertakanPph23 ? 'checked' : '' ?>>
                                                        Sertakan PPH 23 (2%) di surat
                                                    </label>
                                                <?php endif; ?>
                                                <?php if ($ada_total_bayar): ?> <!-- ⬅ BARU -->
                                                    <label class="d-flex align-items-center gap-2 text-xs fw-semibold mb-0"
                                                        style="cursor:pointer;">
                                                        <input type="checkbox" id="checkbox-sertakan-total-bayar"
                                                            name="sertakan_total_bayar" value="1" <?= $checkedSertakanTotalBayar ? 'checked' : '' ?>>
                                                        Sertakan Total Bayar di surat
                                                    </label>
                                                <?php endif; ?>
                                                <?php if ($ada_grand_total): ?>
                                                    <label class="d-flex align-items-center gap-2 text-xs fw-semibold mb-0"
                                                        style="cursor:pointer;">
                                                        <input type="checkbox" id="checkbox-sertakan-grand-total"
                                                            name="sertakan_grand_total" value="1" <?= $checkedSertakanGrandTotal ? 'checked' : '' ?>>
                                                        Sertakan Grand Total di surat
                                                    </label>
                                                <?php endif; ?>
                                                <?php if ($ada_sisa_pelunasan): ?>
                                                    <label class="d-flex align-items-center gap-2 text-xs fw-semibold mb-0"
                                                        style="cursor:pointer;">
                                                        <input type="checkbox" id="checkbox-sertakan-sisa-pelunasan"
                                                            name="sertakan_sisa_pelunasan" value="1" <?= $checkedSertakanSisaPelunasan ? 'checked' : '' ?>>
                                                        Sertakan Sisa Pelunasan di surat
                                                    </label>
                                                <?php endif; ?>
                                            </div>
                                            <script>
                                                (function () {
                                                    var cb = document.getElementById('checkbox-sertakan-grand-total');
                                                    if (cb) cb.addEventListener('change', function () { if (window.hitungUlangTotalSurat) window.hitungUlangTotalSurat(); });
                                                    var cb2 = document.getElementById('checkbox-sertakan-total-bayar');
                                                    if (cb2) cb2.addEventListener('change', function () { if (window.hitungUlangTotalSurat) window.hitungUlangTotalSurat(); });
                                                    var cb3 = document.getElementById('checkbox-sertakan-sisa-pelunasan');            // ⬅ BARU
                                                    if (cb3) cb3.addEventListener('change', function () { if (window.hitungUlangTotalSurat) window.hitungUlangTotalSurat(); });
                                                })();
                                            </script>
                                        <?php endif; ?>
                                        <p class="text-secondary text-xs mt-2 mb-0">Subtotal, Total, PPN &amp; PPH
                                            dihitung otomatis oleh sistem saat surat disimpan. Baris yang tidak dicentang
                                            akan otomatis dihapus dari dokumen Word.</p>
                                        <p class="text-secondary text-xs mt-2 mb-0">Grand Total = Total (setelah Diskon, jika ada) +
                                            PPN − PPH 23. Jika tidak dicentang,
                                            baris Grand Total dihapus dari dokumen Word.</p>
                                    <?php endif; ?>
                                </div>

                                <template id="template-baris-item">
                                    <tr class="baris-item" data-baris-index="__IDX__">
                                        <td class="nomor-baris">1</td>
                                        <?php foreach ($fields_tabel as $kolom): ?>
                                            <?php
                                            $isHarga = isKolomHarga($kolom['field']);
                                            $isQty = isKolomQty($kolom['field']);
                                            $placeholderKolom = $isHarga ? 'cth: 6055000' : ($isQty ? 'cth: 3 unit / 5 orang' : '');
                                            ?>
                                            <td>
                                                <input type="text" name="items[__IDX__][<?= e($kolom['field']) ?>]"
                                                    data-kolom="<?= e($kolom['field']) ?>" <?= $isHarga ? 'data-tipe="harga"' : '' ?>
                                                    placeholder="<?= e($placeholderKolom) ?>" class="form-control-custom" value="">
                                            </td>
                                        <?php endforeach; ?>
                                        <?php if ($tabel_item_punya_harga): ?>
                                            <td class="subtotal-baris" style="text-align:right; font-family:monospace;">-</td>
                                        <?php endif; ?>
                                        <td style="text-align:center;">
                                            <button type="button" class="btn btn-outline-danger btn-sm tombol-hapus-baris">
                                                <i class="bi bi-x-lg"></i>
                                            </button>
                                        </td>
                                    </tr>
                                </template>

                                <script>
                                    (function () {
                                        var tbody = document.getElementById('tabel-item-body');
                                        var tpl = document.getElementById('template-baris-item');
                                        var tombolTambah = document.getElementById('tombol-tambah-baris');
                                        if (!tbody || !tpl || !tombolTambah) return;

                                        var idxBerikutnya = tbody.querySelectorAll('tr.baris-item').length;

                                        function nomorUlangBaris() {
                                            tbody.querySelectorAll('tr.baris-item').forEach(function (tr, i) {
                                                tr.querySelector('.nomor-baris').textContent = i + 1;
                                            });
                                        }

                                        function parseAngkaJsLokal(teks) {
                                            teks = String(teks || '').trim();
                                            var m = teks.match(/-?\d[\d.,]*/);
                                            if (!m) return null;
                                            var angka = m[0];
                                            if (angka.indexOf(',') !== -1 && angka.indexOf('.') !== -1) {
                                                angka = angka.replace(/\./g, '').replace(',', '.');
                                            } else if (angka.indexOf(',') !== -1) {
                                                angka = angka.replace(',', '.');
                                            } else {
                                                var bagian = angka.split('.');
                                                if (bagian.length > 1 && bagian[bagian.length - 1].length === 3) {
                                                    angka = angka.split('.').join('');
                                                }
                                            }
                                            var hasil = parseFloat(angka);
                                            return isNaN(hasil) ? null : hasil;
                                        }

                                        function formatRupiahJsLokal(angka) {
                                            var bulat = Math.round(angka);
                                            var negatif = bulat < 0;
                                            bulat = Math.abs(bulat);
                                            var str = bulat.toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.');
                                            return (negatif ? '-' : '') + 'Rp. ' + str;
                                        }

                                        function hitungSubtotalBaris(tr) {
                                            var qty = null, harga = null;
                                            tr.querySelectorAll('input[data-kolom]').forEach(function (inp) {
                                                var nama = inp.getAttribute('data-kolom');
                                                if (qty === null && /qty|jumlah/i.test(nama)) qty = parseAngkaJsLokal(inp.value);
                                                if (harga === null && /harga/i.test(nama)) harga = parseAngkaJsLokal(inp.value);
                                            });
                                            var elSubtotal = tr.querySelector('.subtotal-baris');
                                            if (!elSubtotal) return;
                                            elSubtotal.textContent = (qty !== null && harga !== null) ? formatRupiahJsLokal(qty * harga) : '-';
                                        }

                                        function formatRibuan(str) {
                                            var angkaSaja = str.replace(/\D/g, '');
                                            if (angkaSaja === '') return '';
                                            return angkaSaja.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
                                        }

                                        function pasangAutoFormatHarga(tr) {
                                            tr.querySelectorAll('input[data-tipe="harga"]').forEach(function (inp) {
                                                if (inp.value) inp.value = formatRibuan(inp.value);
                                                inp.addEventListener('input', function () {
                                                    var posisiKursorDariBelakang = this.value.length - this.selectionStart;
                                                    this.value = formatRibuan(this.value);
                                                    var posisiBaru = this.value.length - posisiKursorDariBelakang;
                                                    this.setSelectionRange(posisiBaru, posisiBaru);
                                                });
                                            });
                                        }

                                        function pasangTombolHapus(tr) {
                                            var btn = tr.querySelector('.tombol-hapus-baris');
                                            btn.addEventListener('click', function () {
                                                if (tbody.querySelectorAll('tr.baris-item').length > 1) {
                                                    tr.remove();
                                                    nomorUlangBaris();
                                                } else {
                                                    tr.querySelectorAll('input').forEach(function (inp) { inp.value = ''; });
                                                    hitungSubtotalBaris(tr);
                                                }
                                                if (window.hitungUlangTotalSurat) window.hitungUlangTotalSurat();
                                            });
                                        }

                                        tbody.querySelectorAll('tr.baris-item').forEach(function (tr) {
                                            pasangTombolHapus(tr);
                                            pasangAutoFormatHarga(tr);
                                            hitungSubtotalBaris(tr);
                                        });

                                        tbody.addEventListener('input', function (e) {
                                            if (e.target && e.target.matches('input[data-kolom]')) {
                                                var tr = e.target.closest('tr.baris-item');
                                                if (tr) hitungSubtotalBaris(tr);
                                                if (window.hitungUlangTotalSurat) window.hitungUlangTotalSurat();
                                            }
                                        });

                                        tombolTambah.addEventListener('click', function () {
                                            var html = tpl.innerHTML.replace(/__IDX__/g, idxBerikutnya);
                                            var sementara = document.createElement('tbody');
                                            sementara.innerHTML = html;
                                            var trBaru = sementara.querySelector('tr');
                                            tbody.appendChild(trBaru);
                                            pasangTombolHapus(trBaru);
                                            pasangAutoFormatHarga(trBaru);
                                            hitungSubtotalBaris(trBaru);
                                            nomorUlangBaris();
                                            idxBerikutnya++;
                                            if (window.hitungUlangTotalSurat) window.hitungUlangTotalSurat();
                                        });
                                    })();
                                </script>
                            <?php endif; ?>

                            <div class="d-flex gap-2 mt-4">
                                <button type="submit" name="preview_only" value="1" class="btn-secondary-custom">
                                    <i class="bi bi-arrow-repeat"></i> Update Preview
                                </button>
                                <button type="submit" class="btn-primary-custom">
                                    <i class="bi bi-file-earmark-check"></i> Simpan &amp; Buat Surat
                                </button>
                            </div>
                            <p class="text-secondary text-xs mt-2">"Simpan &amp; Buat Surat" akan mengunci nomor surat,
                                menyimpan ke database, dan men-generate file .docx dari template master.</p>
                        </form>
                    <?php elseif (!$kodeIdTerpilih): ?>
                        <p class="text-secondary text-xs mt-2 mb-0">Silakan pilih jenis surat &amp; template di atas
                            untuk mulai mengisi data surat.</p>
                    <?php endif; ?>
                </section>

                <section class="card-box">
                    <span class="text-xs fw-bold text-secondary text-uppercase d-block mb-2">Pratinjau Data ke Template
                        Word</span>
                    <?php if (!$kodeTerpilih || $file_template_hilang || $kodeTerpilih['format'] !== 'word_pdf'): ?>
                        <p class="text-secondary text-xs fst-italic mb-0">Pratinjau akan muncul di sini setelah jenis
                            surat &amp; template dipilih.</p>
                    <?php else: ?>
                        <p class="text-secondary text-xs fst-italic mb-3">
                            Panel ini menampilkan nilai yang akan menggantikan setiap placeholder <code>${...}</code>
                            saat dokumen digenerate. Klik "Update Preview" untuk menyegarkan tabel item.
                            <?php if ($ada_ringkasan_total): ?>
                                Total, PPN, PPH &amp; Total Bayar adalah <b>estimasi langsung</b> dari isian tabel item.
                            <?php endif; ?>
                        </p>

                        <table class="preview-kv w-100 mb-3">
                            <?php if (!$ada_no_surat_khusus): ?>
                                <tr>
                                    <td>Nomor</td>
                                    <td style="font-family:monospace;">
                                        <?php
                                        $noUrutPreviewTampil = trim($_POST['no_urut_manual'] ?? '');
                                        if ($noUrutPreviewTampil !== '' && ctype_digit($noUrutPreviewTampil)) {
                                            echo e(sprintf('%03d/%s/ARP/%s/%d', (int) $noUrutPreviewTampil, $kodeTerpilih['kode'], $bulanRomawi, $tahun));
                                        } else {
                                            echo e($preview_nomor);
                                        }
                                        ?>
                                    </td>
                                </tr>
                            <?php endif; ?>
                            <?php if ($ada_no_surat_khusus): ?>
                                <tr>
                                    <td>No Surat</td>
                                    <td style="font-family:monospace;">
                                        <?php
                                        $kodeManualPreview = trim($_POST['no_surat_manual'] ?? '');
                                        echo $kodeManualPreview !== ''
                                            ? e('JD' . strtoupper($kodeManualPreview) . '/' . date('m') . '/' . date('Y'))
                                            : '<i>(isi kode di sebelah kiri)</i>';
                                        ?>
                                    </td>
                                </tr>
                            <?php endif; ?>
                            <?php foreach ($fields_dinamis as $f): ?>
                                <tr>
                                    <td><?= e($f['label']) ?></td>
                                    <td><?= nl2br(e($nilai_dinamis[$f['field']] ?? '')) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </table>

                        <?php if (!empty($fields_tabel)): ?>
                            <span class="text-xs fw-bold text-secondary text-uppercase d-block mb-2">Rincian Item</span>
                            <div class="table-responsive-custom mb-3">
                                <table class="table-custom" id="preview-tabel-item">
                                    <thead>
                                        <tr>
                                            <th>No</th>
                                            <?php foreach ($fields_tabel as $kolom): ?>
                                                <th><?= e($kolom['label']) ?></th>
                                            <?php endforeach; ?>
                                            <?php if ($tabel_item_punya_harga): ?>
                                                <th style="text-align:right;">Sub Total</th>
                                            <?php endif; ?>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($nilai_items as $i => $baris): ?>
                                            <?php
                                            $adaIsiPreview = false;
                                            foreach ($baris as $v) {
                                                if (trim((string) $v) !== '') {
                                                    $adaIsiPreview = true;
                                                    break;
                                                }
                                            }
                                            if (!$adaIsiPreview)
                                                continue;

                                            $qtyBarisPreview = null;
                                            $hargaBarisPreview = null;
                                            foreach ($baris as $namaKolomPreview => $nilaiKolomPreview) {
                                                if ($qtyBarisPreview === null && isKolomQty((string) $namaKolomPreview)) {
                                                    $qtyBarisPreview = parseAngka($nilaiKolomPreview);
                                                }
                                                if ($hargaBarisPreview === null && isKolomHarga((string) $namaKolomPreview)) {
                                                    $hargaBarisPreview = parseAngka($nilaiKolomPreview);
                                                }
                                            }
                                            $subTotalBarisPreview = ($qtyBarisPreview !== null && $hargaBarisPreview !== null)
                                                ? ($qtyBarisPreview * $hargaBarisPreview)
                                                : null;
                                            ?>
                                            <tr data-baris-index="<?= (int) $i ?>">
                                                <td><?= $i + 1 ?></td>
                                                <?php foreach ($fields_tabel as $kolom): ?>
                                                    <td><?= e($baris[$kolom['field']] ?? '') ?></td>
                                                <?php endforeach; ?>
                                                <?php if ($tabel_item_punya_harga): ?>
                                                    <td style="text-align:right; font-family:monospace;">
                                                        <?= $subTotalBarisPreview !== null ? e(formatRupiah($subTotalBarisPreview)) : '-' ?>
                                                    </td>
                                                <?php endif; ?>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                            <?php if ($ada_total_alat): ?>
                                <div class="ringkasan-total-row mb-3">
                                    <span>Total Alat</span>
                                    <span id="preview-total-alat" style="font-family:monospace;">-</span>
                                </div>

                                <script>
                                    (function () {
                                        var tbody = document.getElementById('tabel-item-body');
                                        var el = document.getElementById('preview-total-alat');
                                        if (!tbody || !el) return;

                                        function parseAngkaJsLokal(teks) {
                                            teks = String(teks || '').trim();
                                            var m = teks.match(/-?\d[\d.,]*/);
                                            if (!m) return null;
                                            var angka = m[0];
                                            if (angka.indexOf(',') !== -1 && angka.indexOf('.') !== -1) {
                                                angka = angka.replace(/\./g, '').replace(',', '.');
                                            } else if (angka.indexOf(',') !== -1) {
                                                angka = angka.replace(',', '.');
                                            } else {
                                                var bagian = angka.split('.');
                                                if (bagian.length > 1 && bagian[bagian.length - 1].length === 3) {
                                                    angka = angka.split('.').join('');
                                                }
                                            }
                                            var hasil = parseFloat(angka);
                                            return isNaN(hasil) ? null : hasil;
                                        }

                                        function ambilSatuanJsLokal(teks) {
                                            teks = String(teks || '').trim();
                                            var m = teks.match(/-?\d[\d.,]*/);
                                            if (!m) return null;
                                            var akhirAngka = m.index + m[0].length;
                                            var sisa = teks.substring(akhirAngka).trim();
                                            sisa = sisa.replace(/^[\s\-:]+|[\s\-:]+$/g, '');
                                            return sisa !== '' ? sisa : null;
                                        }

                                        window.hitungTotalAlatSurat = function () {
                                            var total = 0, ada = false, satuan = null;
                                            tbody.querySelectorAll('tr.baris-item').forEach(function (tr) {
                                                tr.querySelectorAll('input[data-kolom]').forEach(function (inp) {
                                                    var nama = inp.getAttribute('data-kolom');
                                                    if (/qty|jumlah/i.test(nama)) {
                                                        var v = parseAngkaJsLokal(inp.value);
                                                        if (v !== null) {
                                                            total += v;
                                                            ada = true;
                                                            if (satuan === null) satuan = ambilSatuanJsLokal(inp.value);
                                                        }
                                                    }
                                                });
                                            });
                                            if (!ada) { el.textContent = '-'; return; }
                                            var tampil = (Math.floor(total) === total)
                                                ? String(total)
                                                : total.toFixed(2).replace('.', ',');
                                            el.textContent = tampil + (satuan ? (' ' + satuan) : '');
                                        };

                                        var observer = new MutationObserver(window.hitungTotalAlatSurat);
                                        observer.observe(tbody, { childList: true });
                                        tbody.addEventListener('input', function (e) {
                                            if (e.target && e.target.matches('input[data-kolom]')) {
                                                window.hitungTotalAlatSurat();
                                            }
                                        });

                                        window.hitungTotalAlatSurat();
                                    })();
                                </script>
                            <?php endif; ?>

                            <?php if ($ada_ringkasan_total): ?>
                                <div id="blok-ringkasan-total" data-ada-pph23="<?= $ada_pph23 ? '1' : '0' ?>"
                                    data-ada-ppn="<?= $ada_ppn ? '1' : '0' ?>" data-ada-diskon="<?= $ada_diskon ? '1' : '0' ?>"
                                    data-ada-grand-total="<?= $ada_grand_total ? '1' : '0' ?>"
                                    data-ada-dp="<?= $ada_dp ? '1' : '0' ?>"
                                    data-ada-total-bayar="<?= $ada_total_bayar ? '1' : '0' ?>"
                                    data-ada-sisa-pelunasan="<?= $ada_sisa_pelunasan ? '1' : '0' ?>"> <!-- ⬅ BARU -->


                                    <?php if ($ada_total): ?>
                                        <div class="ringkasan-total-row">
                                            <span>Total</span><span id="preview-total" style="font-family:monospace;">Rp. 0</span>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($ada_diskon): ?>
                                        <div class="ringkasan-total-row">
                                            <span>Diskon</span><span id="preview-diskon" style="font-family:monospace;">Rp. 0</span>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($ada_ppn): ?>
                                        <div class="ringkasan-total-row">
                                            <span>PPN (11%)</span><span id="preview-ppn" style="font-family:monospace;">Rp. 0</span>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($ada_pph23): ?>
                                        <div class="ringkasan-total-row">
                                            <span>PPH 23 (2%)</span><span id="preview-pph" style="font-family:monospace;">Rp. 0</span>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($ada_dp): ?>
                                        <div class="ringkasan-total-row">
                                            <span>DP</span><span id="preview-dp" style="font-family:monospace;">Rp. 0</span>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($ada_sisa_pelunasan): ?> <!-- ⬅ BARU -->
                                        <div class="ringkasan-total-row">
                                            <span>Sisa Pelunasan</span><span id="preview-sisa-pelunasan"
                                                style="font-family:monospace;">Rp. 0</span>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($ada_total_bayar): ?>
                                        <div class="ringkasan-total-row total-bayar">
                                            <span>Total Bayar</span><span id="preview-total-bayar" style="font-family:monospace;">Rp.
                                                0</span>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($ada_grand_total): ?>
                                        <div class="ringkasan-total-row total-bayar">
                                            <span>Grand Total</span><span id="preview-grand-total" style="font-family:monospace;">Rp.
                                                0</span>
                                        </div>
                                    <?php endif; ?>
                                </div>


                                <script>
                                    (function () {
                                        var tbody = document.getElementById('tabel-item-body');
                                        var elTotal = document.getElementById('preview-total');
                                        var elPpn = document.getElementById('preview-ppn');
                                        var elTotalBayar = document.getElementById('preview-total-bayar');
                                        var elSisaPelunasan = document.getElementById('preview-sisa-pelunasan');   // ⬅ BARU
                                        var elGrandTotal = document.getElementById('preview-grand-total');
                                        var elDp = document.getElementById('preview-dp');
                                        var previewTabelItem = document.getElementById('preview-tabel-item');
                                        if (!tbody || !elTotal) return;

                                        function parseAngkaJs(teks) {
                                            teks = String(teks || '').trim();
                                            var m = teks.match(/-?\d[\d.,]*/);
                                            if (!m) return null;
                                            var angka = m[0];
                                            if (angka.indexOf(',') !== -1 && angka.indexOf('.') !== -1) {
                                                angka = angka.replace(/\./g, '').replace(',', '.');
                                            } else if (angka.indexOf(',') !== -1) {
                                                angka = angka.replace(',', '.');
                                            } else {
                                                var bagian = angka.split('.');
                                                if (bagian.length > 1 && bagian[bagian.length - 1].length === 3) {
                                                    angka = angka.split('.').join('');
                                                }
                                            }
                                            var hasil = parseFloat(angka);
                                            return isNaN(hasil) ? null : hasil;
                                        }

                                        function formatRupiahJs(angka) {
                                            var bulat = Math.round(angka);
                                            var negatif = bulat < 0;
                                            bulat = Math.abs(bulat);
                                            var str = bulat.toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.');
                                            return (negatif ? '-' : '') + 'Rp. ' + str;
                                        }

                                        function updateSubtotalPreview(idxBaris, nilaiSubtotal) {
                                            if (!previewTabelItem) return;
                                            var barisPreview = previewTabelItem.querySelector('tr[data-baris-index="' + idxBaris + '"]');
                                            if (!barisPreview) return;
                                            var cellSubtotal = barisPreview.children[barisPreview.children.length - 1];
                                            if (!cellSubtotal) return;
                                            cellSubtotal.textContent = nilaiSubtotal !== null ? formatRupiahJs(nilaiSubtotal) : '-';
                                        }

                                        window.hitungUlangTotalSurat = function () {
                                            var totalSemuaBaris = 0;
                                            var adaSubtotal = false;

                                            tbody.querySelectorAll('tr.baris-item').forEach(function (tr) {
                                                var qty = null, harga = null;
                                                tr.querySelectorAll('input[data-kolom]').forEach(function (inp) {
                                                    var nama = inp.getAttribute('data-kolom');
                                                    if (qty === null && /qty|jumlah/i.test(nama)) qty = parseAngkaJs(inp.value);
                                                    if (harga === null && /harga/i.test(nama)) harga = parseAngkaJs(inp.value);
                                                });
                                                var idxBaris = tr.getAttribute('data-baris-index');
                                                if (qty !== null && harga !== null) {
                                                    totalSemuaBaris += qty * harga;
                                                    adaSubtotal = true;
                                                    updateSubtotalPreview(idxBaris, qty * harga);
                                                } else {
                                                    updateSubtotalPreview(idxBaris, null);
                                                }
                                            });

                                            var blokRingkasan = document.getElementById('blok-ringkasan-total');
                                            var adaPph23 = blokRingkasan && blokRingkasan.getAttribute('data-ada-pph23') === '1';
                                            var adaDiskon = blokRingkasan && blokRingkasan.getAttribute('data-ada-diskon') === '1';
                                            var elPph = document.getElementById('preview-pph');
                                            var elDiskon = document.getElementById('preview-diskon');

                                            function ambilDiskonPersen() {
                                                var inp = document.getElementById('input-diskon');
                                                if (!inp) return 0;
                                                var v = parseAngkaJs(inp.value);
                                                return v !== null ? v : 0;
                                            }

                                            if (!adaSubtotal) {
                                                elTotal.textContent = 'Rp. 0';
                                                elPpn.textContent = 'Rp. 0';
                                                if (elPph) elPph.textContent = 'Rp. 0';
                                                if (elDiskon) elDiskon.textContent = 'Rp. 0';
                                                if (elSisaPelunasan) elSisaPelunasan.textContent = 'Rp. 0';
                                                elTotalBayar.textContent = 'Rp. 0';
                                                return;
                                            }

                                            var checkboxPpn = document.getElementById('checkbox-sertakan-ppn');
                                            var checkboxPph23 = document.getElementById('checkbox-sertakan-pph23');
                                            var checkboxDiskon = document.getElementById('checkbox-sertakan-diskon');
                                            var sertakanPpn = !checkboxPpn || checkboxPpn.checked;
                                            var sertakanPph23 = !checkboxPph23 || checkboxPph23.checked;
                                            var sertakanDiskon = !checkboxDiskon || checkboxDiskon.checked;

                                            var diskonPersen = (adaDiskon && sertakanDiskon) ? ambilDiskonPersen() : 0;
                                            var diskon = (adaDiskon && sertakanDiskon) ? Math.round(totalSemuaBaris * (diskonPersen / 100)) : 0;

                                            // DPP (dasar hitung pajak) = Total - Diskon kalau diskon disertakan & > 0,
                                            // kalau tidak basisnya tetap Total penuh.
                                            var dasarPajak = (adaDiskon && sertakanDiskon && diskon > 0) ? (totalSemuaBaris - diskon) : totalSemuaBaris;

                                            var ppn = sertakanPpn ? Math.round(dasarPajak * 0.11) : 0;
                                            var pph = (adaPph23 && sertakanPph23) ? Math.round(dasarPajak * 0.02) : 0;
                                            var grandTotal = totalSemuaBaris + ppn - pph - diskon;

                                            elTotal.textContent = formatRupiahJs(totalSemuaBaris);
                                            elPpn.textContent = sertakanPpn ? formatRupiahJs(ppn) : 'Tidak disertakan';
                                            if (elPph) elPph.textContent = (adaPph23 && sertakanPph23) ? formatRupiahJs(pph) : (adaPph23 ? 'Tidak disertakan' : elPph.textContent);
                                            if (elDiskon) elDiskon.textContent = (adaDiskon && sertakanDiskon) ? (diskonPersen + '% (' + formatRupiahJs(diskon) + ')') : (adaDiskon ? 'Tidak disertakan' : elDiskon.textContent);

                                            var blokRingkasanEl = document.getElementById('blok-ringkasan-total');
                                            var adaGrandTotal = blokRingkasanEl && blokRingkasanEl.getAttribute('data-ada-grand-total') === '1';
                                            var adaDp = blokRingkasanEl && blokRingkasanEl.getAttribute('data-ada-dp') === '1';

                                            var checkboxGrandTotal = document.getElementById('checkbox-sertakan-grand-total');
                                            var checkboxDp = document.getElementById('checkbox-sertakan-dp');
                                            var sertakanGrandTotal = !checkboxGrandTotal || checkboxGrandTotal.checked;
                                            var sertakanDpChecked = !checkboxDp || checkboxDp.checked;

                                            function ambilDpPersen() {
                                                var inp = document.getElementById('input-dp');
                                                if (!inp) return 0;
                                                var v = parseAngkaJs(inp.value);
                                                return v !== null ? v : 0;
                                            }

                                            var dpPersenNilai = (adaDp && sertakanDpChecked) ? ambilDpPersen() : 0;
                                            var dpNominal = (adaDp && sertakanDpChecked) ? Math.round(grandTotal * (dpPersenNilai / 100)) : 0;

                                            var totalBayar = (adaDp && sertakanDpChecked && dpNominal > 0) ? (grandTotal - dpNominal) : grandTotal;
                                            if (elGrandTotal) {
                                                elGrandTotal.textContent = (adaGrandTotal && sertakanGrandTotal) ? formatRupiahJs(grandTotal) : (adaGrandTotal ? 'Tidak disertakan' : elGrandTotal.textContent);
                                            }
                                            if (elDp) {
                                                elDp.textContent = (adaDp && sertakanDpChecked) ? (dpPersenNilai + '% (' + formatRupiahJs(dpNominal) + ')') : (adaDp ? 'Tidak disertakan' : elDp.textContent);
                                            }

                                            // ⬇ GANTI baris "elTotalBayar.textContent = formatRupiahJs(totalBayar);" dengan ini:
                                            var checkboxTotalBayar = document.getElementById('checkbox-sertakan-total-bayar');
                                            var sertakanTotalBayarChecked = !checkboxTotalBayar || checkboxTotalBayar.checked;
                                            elTotalBayar.textContent = sertakanTotalBayarChecked ? formatRupiahJs(totalBayar) : 'Tidak disertakan';
                                            var checkboxSisaPelunasan = document.getElementById('checkbox-sertakan-sisa-pelunasan');
                                            var sertakanSisaPelunasanChecked = !checkboxSisaPelunasan || checkboxSisaPelunasan.checked;
                                            var adaSisaPelunasan = blokRingkasanEl && blokRingkasanEl.getAttribute('data-ada-sisa-pelunasan') === '1';
                                            var sisaPelunasan = grandTotal - dpNominal;
                                            if (elSisaPelunasan) {
                                                elSisaPelunasan.textContent = (adaSisaPelunasan && sertakanSisaPelunasanChecked)
                                                    ? formatRupiahJs(sisaPelunasan)
                                                    : (adaSisaPelunasan ? 'Tidak disertakan' : elSisaPelunasan.textContent);
                                            }
                                        };

                                        var observer = new MutationObserver(window.hitungUlangTotalSurat);
                                        observer.observe(tbody, { childList: true });

                                        ['checkbox-sertakan-ppn', 'checkbox-sertakan-pph23', 'checkbox-sertakan-diskon',
                                            'checkbox-sertakan-grand-total', 'checkbox-sertakan-dp',
                                            'checkbox-sertakan-total-bayar', 'checkbox-sertakan-sisa-pelunasan'].forEach(function (id) {  // ⬅ ditambah
                                                var cb = document.getElementById(id);
                                                if (cb) cb.addEventListener('change', window.hitungUlangTotalSurat);
                                            });

                                        window.hitungUlangTotalSurat();
                                    })();
                                </script>
                            <?php endif; ?>
                        <?php endif; ?>
                    <?php endif; ?>
                </section>
            </div>
        </div>

        <!-- ============================== TAB: UPLOAD TEMPLATE ============================== -->
        <div class="col-12 arp-tab-panel" id="tabPanelTemplate" <?= $active_tab === 'tabPanelTemplate' ? '' : 'style="display:none;"' ?>>
            <div class="card-box">
                <div class="table-toolbar">
                    <h5 class="table-toolbar-title fw-bold">Daftar Template Surat</h5>
                    <div class="table-toolbar-actions">
                        <div class="search-box-container">
                            <i class="bi bi-search"></i>
                            <input type="text" class="search-box" placeholder="Cari nama template atau kode..."
                                data-table-search="tabelTemplate" onkeyup="handleTableSearch('tabelTemplate')">
                        </div>
                        <button class="btn-primary-custom" onclick="openModal('modalUploadTemplate')">
                            <i class="bi bi-cloud-upload"></i>
                            Upload Template
                        </button>
                    </div>
                </div>
                <div class="table-responsive-custom">
                    <table class="table-custom" id="tabelTemplate">
                        <thead>
                            <tr>
                                <th>No</th>
                                <th>Nama Template</th>
                                <th>Kode (Jenis)</th>
                                <th>Tanggal Upload</th>
                                <th>Format</th>
                                <th>Status</th>
                                <th class="col-aksi" style="text-align:center;">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($daftar_template)): ?>
                                <tr>
                                    <td colspan="7" class="text-center py-4 text-muted">
                                        <i class="bi bi-file-earmark-x d-block mb-2" style="font-size:2rem;"></i>
                                        Belum ada template surat.
                                    </td>
                                </tr>
                            <?php endif; ?>
                            <?php $no = 1; ?>
                            <?php foreach ($daftar_template as $t): ?>
                                <?php $fileTemplateAda = !empty($t['drive_file_id']); ?>
                                <tr>
                                    <td><?= $no++; ?></td>
                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            <i class="bi bi-file-earmark-word text-primary"></i>
                                            <div>
                                                <strong><?= e($t['nama']) ?></strong>
                                                <?php if (!empty($t['deskripsi'])): ?>
                                                    <br><small class="text-secondary"><?= e($t['deskripsi']) ?></small>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td><span
                                            class="badge-warning"><?= e(implode(', ', $daftar_kode_per_template[$t['id']] ?? ['-'])) ?></span>
                                    </td>
                                    <td><?= date('d-m-Y', strtotime($t['created_at'])) ?></td>
                                    <td><?= $t['format'] === 'word_pdf' ? 'Word' : 'PDF' ?></td>
                                    <td>
                                        <?php if (!empty($t['drive_file_id'])): ?>
                                            <span class="badge-success">Aktif</span>
                                        <?php else: ?>
                                            <span class="badge-danger" title="Belum tersimpan di Drive">Hilang</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="col-aksi" style="text-align:center;">
                                        <div class="table-actions">
                                            <button type="button" class="btn btn-outline-primary btn-sm py-1"
                                                style="font-size:0.75rem;" title="Edit Template"
                                                onclick="bukaModalEditTemplate(<?= (int) $t['id'] ?>)">
                                                <i class="bi bi-pencil-square"></i>
                                            </button>
                                            <?php if (!empty($t['drive_file_id'])): ?>
                                                <a class="btn btn-outline-secondary btn-sm py-1" style="font-size:0.75rem;"
                                                    href="https://docs.google.com/document/d/<?= e($t['drive_file_id']) ?>/edit"
                                                    target="_blank" title="Lihat &amp; Edit Word di Drive"
                                                    data-arp-loading="Membuka Word di Drive...">
                                                    <i class="bi bi-file-earmark-word"></i>
                                                </a>
                                            <?php endif; ?>
                                            <form method="POST" action="surat.php" class="d-inline"
                                                onsubmit="return confirm('Hapus template &quot;<?= e(addslashes($t['nama'])) ?>&quot;? Tindakan ini tidak bisa dibatalkan.');">
                                                <input type="hidden" name="aksi" value="hapus_template">
                                                <input type="hidden" name="template_id" value="<?= (int) $t['id'] ?>">
                                                <button type="submit" class="btn-danger-custom"
                                                    style="height:28px; padding:0 8px; font-size:0.75rem;">
                                                    <i class="bi bi-trash-fill"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="pagination-custom" id="pagination-tabelTemplate"></div>
            </div>
        </div>

    </div>

</main>

<!-- Modal: Upload Template -->
<div class="arp-modal-overlay" id="modalUploadTemplate" onclick="closeModalOutside(event,'modalUploadTemplate')">
    <div class="arp-modal-box" style="max-width:650px;">
        <div class="arp-modal-header">
            <div>
                <h5 class="fw-bold mb-0">Upload Template Surat</h5>
                <small class="text-muted">
                    Placeholder <code>${...}</code>, tabel <code>${item_...}</code>, dan blok <code>${blok_...}</code>
                    di dalam file Word akan otomatis terdeteksi.
                </small>
            </div>
            <button class="arp-modal-close" onclick="closeModal('modalUploadTemplate')">&times;</button>
        </div>
        <div class="arp-modal-body">
            <form method="POST" action="surat.php" enctype="multipart/form-data">
                <input type="hidden" name="aksi" value="upload_template">
                <div class="mb-3">
                    <label class="form-label fw-semibold mb-2">Nama Template *</label>
                    <input type="text" name="nama_template" class="form-control-custom"
                        placeholder="Contoh : Surat Tugas" required>
                </div>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold mb-2">Kode Surat *</label>
                        <input type="text" name="kode" id="input-kode" class="form-control-custom"
                            style="text-transform:uppercase;" placeholder="Contoh : ST" required>
                        <div id="status-cek-kode" class="text-xs mt-1"></div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold mb-2">Nama Jenis Surat</label>
                        <input type="text" name="nama_kode" id="input-nama-kode" class="form-control-custom"
                            placeholder="Contoh : Surat Tugas">
                    </div>
                </div>
                <div class="mt-3">
                    <label class="form-label fw-semibold mb-2">Deskripsi Template</label>
                    <input type="text" name="deskripsi" class="form-control-custom"
                        placeholder="Contoh : Template surat tugas untuk kegiatan operasional.">
                </div>
                <div class="mt-3">
                    <label class="form-label fw-semibold mb-2">File Template *</label>
                    <input type="file" name="file_template" class="form-control-custom" accept=".doc,.docx,.pdf"
                        style="padding-top:8px;" required>
                </div>
                <div class="d-flex justify-content-end gap-2 mt-4">
                    <button type="button" class="btn-secondary-custom"
                        onclick="closeModal('modalUploadTemplate')">Batal</button>
                    <button type="submit" class="btn-primary-custom">
                        <i class="bi bi-cloud-upload"></i> Upload Template
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Edit Template -->
<div class="arp-modal-overlay" id="modalEditTemplate" onclick="closeModalOutside(event,'modalEditTemplate')">
    <div class="arp-modal-box" style="max-width:750px;">
        <div class="arp-modal-header">
            <div>
                <h5 class="fw-bold mb-0">Edit Template</h5>
                <small class="text-muted">Ubah nama template, kode surat, jenis surat, dan deskripsi</small>
            </div>
            <button class="arp-modal-close" onclick="closeModal('modalEditTemplate')">&times;</button>
        </div>
        <div class="arp-modal-body">
            <div id="editTemplateLoading" class="text-center py-4 text-secondary">
                <i class="bi bi-arrow-repeat" style="font-size:1.5rem;"></i>
                <div class="mt-2 text-xs">Memuat data template...</div>
            </div>
            <div id="editTemplateError" class="alert alert-danger-custom text-xs" style="display:none;"></div>
            <form method="POST" action="surat.php" id="formEditTemplate" style="display:none;">
                <input type="hidden" name="aksi" value="edit_template">
                <input type="hidden" name="template_id" id="editTplId" value="">

                <div id="editTplBukaWordWrapper" class="mb-3" style="display:none;">
                    <a href="#" id="editTplBukaWordLink" target="_blank" class="btn-secondary-custom"
                        data-arp-loading="Membuka Word di Drive..."
                        style="display:inline-flex; align-items:center; gap:6px; text-decoration:none;">
                        <i class="bi bi-file-earmark-word"></i> Lihat &amp; Edit Word di Drive
                    </a>
                    <small class="text-secondary text-xs d-block mt-1">
                        Membuka file asli langsung di Google Docs (mode Word). Perubahan yang Anda simpan di sana
                        <b>otomatis tersimpan balik</b> ke file yang sama. Untuk mengganti nama field
                        <code>${...}</code>,
                        gunakan tabel di bawah lalu klik "Simpan Perubahan".
                    </small>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold mb-2">Nama Template *</label>
                    <input type="text" name="nama_template" id="editTplNama" class="form-control-custom" required>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold mb-2">Deskripsi Template</label>
                    <input type="text" name="deskripsi" id="editTplDeskripsi" class="form-control-custom">
                </div>
                <hr>
                <label class="form-label fw-semibold mb-2 d-block">Kode Surat &amp; Jenis Surat Terhubung</label>
                <div id="editTplKodeList" class="mb-3"></div>
                <p class="text-secondary text-xs mb-3">Mengubah kode/nama di sini akan berlaku untuk SEMUA template
                    lain yang memakai kombinasi kode ini juga (jika ada).</p>

                <div class="d-flex justify-content-end gap-2 mt-4">
                    <button type="button" class="btn-secondary-custom"
                        onclick="closeModal('modalEditTemplate')">Batal</button>
                    <button type="submit" class="btn-primary-custom">
                        <i class="bi bi-save"></i> Simpan Perubahan
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Export Rekap Surat per Periode -->
<div class="arp-modal-overlay" id="modalExportRekap" onclick="closeModalOutside(event,'modalExportRekap')">
    <div class="arp-modal-box" style="max-width:420px;">
        <div class="arp-modal-header">
            <h5 class="fw-bold mb-0">Export Rekap Surat</h5>
            <button class="arp-modal-close" onclick="closeModal('modalExportRekap')">&times;</button>
        </div>
        <div class="arp-modal-body">
            <form method="GET" action="surat.php" target="_blank">
                <input type="hidden" name="ajax" value="export_rekap">

                <div class="mb-3">
                    <label class="form-label fw-semibold mb-2">Arah Surat</label>
                    <select name="arah" class="select-custom">
                        <option value="Semua">Semua (Masuk &amp; Keluar)</option>
                        <option value="Keluar">Surat Keluar</option>
                        <option value="Masuk">Surat Masuk</option>
                    </select>
                </div>

                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold mb-2">Bulan</label>
                        <select name="bulan" class="select-custom">
                            <option value="0">Semua Bulan</option>
                            <?php
                            $namaBulanIndoForm = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
                            foreach ($namaBulanIndoForm as $i => $nb): ?>
                                <option value="<?= $i + 1 ?>" <?= ((int) date('n') === $i + 1) ? 'selected' : '' ?>>
                                    <?= e($nb) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold mb-2">Tahun</label>
                        <select name="tahun" class="select-custom">
                            <?php for ($th = (int) date('Y'); $th >= (int) date('Y') - 5; $th--): ?>
                                <option value="<?= $th ?>" <?= ($th === (int) date('Y')) ? 'selected' : '' ?>>
                                    <?= $th ?>
                                </option>
                            <?php endfor; ?>
                        </select>
                    </div>
                </div>

                <div class="mt-3">
                    <label class="form-label fw-semibold mb-2 d-block">Format Export</label>
                    <div class="d-flex gap-3">
                        <label class="d-flex align-items-center gap-2" style="cursor:pointer;">
                            <input type="radio" name="format" value="csv" checked>
                            <span>CSV <small class="text-secondary">(Excel)</small></span>
                        </label>
                        <label class="d-flex align-items-center gap-2" style="cursor:pointer;">
                            <input type="radio" name="format" value="pdf">
                            <span>PDF</span>
                        </label>
                    </div>
                </div>

                <p class="text-secondary text-xs mt-3 mb-0">
                    Satu baris = satu nomor surat (bukan per revisi). Data yang ditampilkan adalah
                    versi <b>terbaru/final</b>, plus jumlah revisinya. Filter periode memakai tanggal
                    surat pertama kali dibuat. Rekap ini mencakup surat dari semua pengguna.
                </p>

                <div class="d-flex justify-content-end gap-2 mt-4">
                    <button type="button" class="btn-secondary-custom"
                        onclick="closeModal('modalExportRekap')">Batal</button>
                    <button type="submit" class="btn-primary-custom" data-arp-loading="Menyiapkan file rekap...">
                        <i class="bi bi-download"></i> Unduh
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        initTablePagination('tabelSurat', 10);
        initTablePagination('tabelSuratMasuk', 10);
        initTablePagination('tabelTemplate', 10);
    });
</script>

<?php if ($errorGenerateSurat): ?>
    <script>document.addEventListener('DOMContentLoaded', function () { switchTab('tabPanelBuatSurat', document.querySelector('[data-tab-target=tabPanelBuatSurat]')); });</script>
<?php endif; ?>

<script>
    // ==========================================
    // AUTOCOMPLETE NAMA PERUSAHAAN (Data_Klien)
    // ==========================================
    (function () {
        let timerCari = null;
        let boxAktif = null;
        let inputAktif = null;

        function tutupSaran() {
            if (boxAktif) {
                boxAktif.remove();
                boxAktif = null;
            }
            inputAktif = null;
        }

        function buatBoxSaran(input) {
            tutupSaran();
            const box = document.createElement('div');
            box.className = 'arp-autocomplete-box';
            box.style.cssText = `
            position:absolute; z-index:2000; background:#fff;
            border:1px solid var(--border-color,#e2e8f0); border-radius:8px;
            box-shadow:0 8px 20px rgba(0,0,0,0.12);
            max-height:220px; overflow-y:auto; font-size:0.85rem;
        `;
            document.body.appendChild(box);
            posisikanBox(box, input);
            boxAktif = box;
            inputAktif = input;
            return box;
        }

        function posisikanBox(box, input) {
            const rect = input.getBoundingClientRect();
            box.style.left = (rect.left + window.scrollX) + 'px';
            box.style.top = (rect.bottom + window.scrollY + 4) + 'px';
            box.style.width = rect.width + 'px';
        }

        function tampilkanSaran(input, daftar) {
            const box = buatBoxSaran(input);
            if (!daftar.length) {
                box.innerHTML = '<div style="padding:10px 14px; color:var(--text-secondary,#64748b);">Tidak ada perusahaan cocok ditemukan.</div>';
                return;
            }
            daftar.forEach(function (klien) {
                const item = document.createElement('div');
                item.style.cssText = 'padding:10px 14px; cursor:pointer; border-bottom:1px solid #f1f5f9;';
                item.innerHTML = '<div style="font-weight:600; color:var(--text-primary,#1e293b);">' +
                    escapeHtmlAC(klien.nama_perusahaan) + '</div>' +
                    (klien.pic_nama ? '<div style="color:var(--text-secondary,#64748b); font-size:0.78rem;">PIC: ' + escapeHtmlAC(klien.pic_nama) + '</div>' : '');
                item.addEventListener('mouseenter', function () { item.style.background = '#f8fafc'; });
                item.addEventListener('mouseleave', function () { item.style.background = '#fff'; });
                item.addEventListener('mousedown', function (e) {
                    e.preventDefault();
                    clearTimeout(timerCari);

                    input.value = klien.nama_perusahaan;
                    input.dataset.acJustPicked = '1';
                    input.dispatchEvent(new Event('input', { bubbles: true }));
                    input.dispatchEvent(new Event('change', { bubbles: true }));

                    // ===== Auto-isi field Alamat Perusahaan terkait =====
                    isiAlamatOtomatis(input, klien.alamat);

                    tutupSaran();
                    input.blur();
                });
                box.appendChild(item);
            });
        }

        function escapeHtmlAC(str) {
            return String(str || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
        }

        function cariKlien(input) {
            // Kalau perubahan value ini berasal dari pilihan saran barusan, lewati
            // satu kali pencarian ini saja, lalu bersihkan tandanya.
            if (input.dataset.acJustPicked === '1') {
                input.dataset.acJustPicked = '0';
                tutupSaran();
                return;
            }

            const q = input.value.trim();
            if (q.length < 1) {
                tutupSaran();
                return;
            }
            fetch('surat.php?ajax=cari_klien&q=' + encodeURIComponent(q))
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    if (document.activeElement === input) {
                        tampilkanSaran(input, data);
                    }
                })
                .catch(function () { tutupSaran(); });
        }

        function cariFieldAlamatTerkait(inputPerusahaan) {
            // Cari wadah form/baris terdekat supaya pencarian field alamat tidak
            // "bocor" ke field perusahaan lain di form yang sama (mis. pihak
            // pertama vs pihak kedua, atau baris tabel item yang berbeda).
            const wadah =
                inputPerusahaan.closest('tr.baris-item') ||       // baris tabel Rincian Item
                inputPerusahaan.closest('.blok-baris') ||          // baris blok list berulang
                inputPerusahaan.closest('form') ||                 // fallback: seluruh form
                document;

            // Kandidat pencarian, urut prioritas:
            // 1. dinamis[...] yang mengandung kata "alamat" DAN "perusahaan"
            // 2. dinamis[...] yang mengandung kata "alamat" saja
            // 3. data-kolom yang mengandung kata "alamat" (tabel item)
            let kandidat = wadah.querySelectorAll('input[name^="dinamis["], textarea[name^="dinamis["]');
            let cocokKuat = null;
            let cocokLemah = null;

            kandidat.forEach(function (el) {
                const nama = (el.getAttribute('name') || '').toLowerCase();
                if (nama.indexOf('alamat') === -1) return;
                if (nama.indexOf('perusahaan') !== -1 && !cocokKuat) {
                    cocokKuat = el;
                } else if (!cocokLemah) {
                    cocokLemah = el;
                }
            });

            if (cocokKuat) return cocokKuat;
            if (cocokLemah) return cocokLemah;

            // Fallback: kolom tabel item beralamat
            let cocokKolom = null;
            wadah.querySelectorAll('input[data-kolom], textarea[data-kolom]').forEach(function (el) {
                const nama = (el.getAttribute('data-kolom') || '').toLowerCase();
                if (nama.indexOf('alamat') !== -1 && !cocokKolom) {
                    cocokKolom = el;
                }
            });
            return cocokKolom;
        }

        function isiAlamatOtomatis(inputPerusahaan, alamat) {
            if (!alamat) return;
            const fieldAlamat = cariFieldAlamatTerkait(inputPerusahaan);
            if (!fieldAlamat) return;

            fieldAlamat.value = alamat;
            fieldAlamat.dispatchEvent(new Event('input', { bubbles: true }));
            fieldAlamat.dispatchEvent(new Event('change', { bubbles: true }));
        }

        function pasangAutocomplete(input) {
            if (input.dataset.acTerpasang === '1') return;
            input.dataset.acTerpasang = '1';
            input.dataset.acJustPicked = '0';
            input.setAttribute('autocomplete', 'off');

            input.addEventListener('input', function () {
                clearTimeout(timerCari);
                timerCari = setTimeout(function () { cariKlien(input); }, 300);
            });
            input.addEventListener('focus', function () {
                // Jangan buka saran otomatis kalau baru saja memilih dari dropdown
                if (input.dataset.acJustPicked === '1') return;
                if (input.value.trim().length >= 1) cariKlien(input);
            });
            input.addEventListener('blur', function () {
                setTimeout(tutupSaran, 150);
            });
            window.addEventListener('scroll', function () {
                if (inputAktif === input && boxAktif) posisikanBox(boxAktif, input);
            }, true);
        }

        function pasangKeSemuaFieldPerusahaan(root) {
            root = root || document;
            root.querySelectorAll('input[name^="dinamis["]').forEach(function (input) {
                const namaField = (input.getAttribute('name') || '').toLowerCase();
                if (namaField.indexOf('perusahaan') !== -1) {
                    pasangAutocomplete(input);
                }
            });
            root.querySelectorAll('input[data-kolom]').forEach(function (input) {
                const namaKolom = (input.getAttribute('data-kolom') || '').toLowerCase();
                if (namaKolom.indexOf('perusahaan') !== -1) {
                    pasangAutocomplete(input);
                }
            });
            root.querySelectorAll('input[name="tujuan_pengirim"]').forEach(pasangAutocomplete);
            root.querySelectorAll('input[name="tujuan_manual"]').forEach(pasangAutocomplete);
        }

        document.addEventListener('DOMContentLoaded', function () {
            pasangKeSemuaFieldPerusahaan(document);
        });

        const observerFieldBaru = new MutationObserver(function (mutations) {
            mutations.forEach(function (m) {
                m.addedNodes.forEach(function (node) {
                    if (node.nodeType === 1) {
                        pasangKeSemuaFieldPerusahaan(node);
                    }
                });
            });
        });
        document.addEventListener('DOMContentLoaded', function () {
            observerFieldBaru.observe(document.body, { childList: true, subtree: true });
        });
    })();
</script>

<script>
    function bukaModalEditTemplate(templateId) {
        openModal('modalEditTemplate');
        var loadingEl = document.getElementById('editTemplateLoading');
        var errorEl = document.getElementById('editTemplateError');
        var formEl = document.getElementById('formEditTemplate');
        loadingEl.style.display = 'block';
        errorEl.style.display = 'none';
        formEl.style.display = 'none';

        fetch('surat.php?ajax=get_template&id=' + encodeURIComponent(templateId))
            .then(function (res) { return res.json(); })
            .then(function (data) {
                loadingEl.style.display = 'none';
                if (data.error) {
                    errorEl.textContent = data.error;
                    errorEl.style.display = 'block';
                    return;
                }
                document.getElementById('editTplId').value = data.template.id;
                document.getElementById('editTplNama').value = data.template.nama || '';
                document.getElementById('editTplDeskripsi').value = data.template.deskripsi || '';

                var wrapperBukaWord = document.getElementById('editTplBukaWordWrapper');
                var linkBukaWord = document.getElementById('editTplBukaWordLink');
                if (data.template.drive_file_id) {
                    linkBukaWord.href = 'https://docs.google.com/document/d/' + data.template.drive_file_id + '/edit';
                    wrapperBukaWord.style.display = 'block';
                } else {
                    wrapperBukaWord.style.display = 'none';
                }

                var kodeListEl = document.getElementById('editTplKodeList');
                kodeListEl.innerHTML = '';
                if (!data.kode_terhubung || data.kode_terhubung.length === 0) {
                    kodeListEl.innerHTML = '<p class="text-secondary text-xs">Template ini belum terhubung ke kode surat manapun.</p>';
                } else {
                    data.kode_terhubung.forEach(function (k) {
                        var row = document.createElement('div');
                        row.className = 'row g-2 mb-2 align-items-center';
                        row.innerHTML =
                            '<input type="hidden" name="kode_template_id[]" value="' + k.kode_template_id + '">' +
                            '<div class="col-md-5">' +
                            '<input type="text" name="kode_baru[]" class="form-control-custom" style="text-transform:uppercase;" ' +
                            'value="' + escapeHtmlAttr(k.kode) + '" placeholder="Kode (cth: ST)">' +
                            '</div>' +
                            '<div class="col-md-6">' +
                            '<input type="text" name="nama_kode_baru[]" class="form-control-custom" ' +
                            'value="' + escapeHtmlAttr(k.nama_kode) + '" placeholder="Nama jenis surat">' +
                            '</div>' +
                            '<div class="col-md-1 text-center">' +
                            (k.is_default == 1 ? '<span class="badge-success" title="Default">Def</span>' : '') +
                            '</div>';
                        kodeListEl.appendChild(row);
                    });
                }


                formEl.style.display = 'block';
            })
            .catch(function () {
                loadingEl.style.display = 'none';
                errorEl.textContent = 'Gagal memuat data template. Silakan coba lagi.';
                errorEl.style.display = 'block';
            });
    }

    function escapeHtmlAttr(str) {
        return String(str || '').replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }
    function escapeHtmlText(str) {
        return String(str || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }
    function labelDariNamaField(nama) {
        nama = String(nama || '').trim();
        if (nama === '') return '';
        return nama.replace(/_/g, ' ').split(' ').map(function (kata) {
            return kata.length ? kata.charAt(0).toUpperCase() + kata.slice(1) : kata;
        }).join(' ');
    }
    function pasangAutoLabelListener() {
        var baris = document.querySelectorAll('#editTplFieldBody tr');
        baris.forEach(function (tr) {
            var inputFieldBaru = tr.querySelector('.field-baru-input');
            var inputLabel = tr.querySelector('.field-label-input');
            if (!inputFieldBaru || !inputLabel) return;
            inputLabel.addEventListener('input', function () { inputLabel.setAttribute('data-label-manual', '1'); });
            inputFieldBaru.addEventListener('input', function () {
                if (inputLabel.getAttribute('data-label-manual') === '1') return;
                inputLabel.value = labelDariNamaField(inputFieldBaru.value);
            });
        });
    }
</script>

<script>
    (function () {
        var inputKode = document.getElementById('input-kode');
        var inputNamaKode = document.getElementById('input-nama-kode');
        var statusEl = document.getElementById('status-cek-kode');
        if (!inputKode || !inputNamaKode) return;
        var timer = null;

        function tampilkanStatus(pesan, jenis) {
            if (!statusEl) return;
            statusEl.textContent = pesan;
            statusEl.className = 'text-xs mt-1 ' + (jenis === 'warning' ? 'text-warning' : (jenis === 'danger' ? 'text-danger' : 'text-success'));
        }
        function cekKode() {
            var kode = inputKode.value.trim();
            var nama = inputNamaKode.value.trim();
            if (kode === '') { tampilkanStatus('', ''); return; }
            fetch('surat.php?ajax=cek_kode&kode=' + encodeURIComponent(kode) + '&nama=' + encodeURIComponent(nama))
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    if (data.combo_exists) {
                        tampilkanStatus('Kombinasi kode & jenis surat ini sudah ada — akan memakai data yang sama (bukan bikin baris baru).', 'warning');
                    } else if (data.kode_exists) {
                        var daftar = (data.daftar_nama_lain && data.daftar_nama_lain.length)
                            ? ' Jenis surat lain yang sudah pakai kode ini: ' + data.daftar_nama_lain.join(', ') + '.'
                            : '';
                        tampilkanStatus('Kode ini sudah dipakai jenis surat lain — akan dibuat jenis surat BARU dengan kode yang sama.' + daftar, 'success');
                    } else if (nama !== '') {
                        tampilkanStatus('Kode & jenis surat baru — siap dibuat.', 'success');
                    } else {
                        tampilkanStatus('', '');
                    }
                })
                .catch(function () { });
        }
        inputKode.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(cekKode, 400); });
        inputNamaKode.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(cekKode, 400); });
        inputKode.addEventListener('blur', cekKode);
        inputNamaKode.addEventListener('blur', cekKode);
    })();
</script>

<?php include "../includes/footer.php"; ?>